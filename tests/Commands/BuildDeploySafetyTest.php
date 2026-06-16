<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Tests\Commands;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;
use Tag1\ScoltaLaravel\Commands\BuildCommand;
use Tag1\ScoltaLaravel\Jobs\FinalizeIndex;
use Tag1\ScoltaLaravel\Jobs\ProcessIndexChunk;
use Tag1\ScoltaLaravel\ScoltaServiceProvider;
use Tag1\ScoltaLaravel\Tests\Support\SearchablePost;

/**
 * Deploy-safety regression tests for `scolta:build`.
 *
 * The defect these pin: the PHP-indexer build used to dispatch to the queue
 * by default and return SUCCESS unconditionally — so on an async queue
 * connection with no worker running, `scolta:build` exited 0 ("dispatched to
 * queue") while the chunk + finalize jobs sat in the queue forever and the
 * atomic swap never ran. A deploy/initContainer/CI read that exit 0 as a
 * built, live index when search was actually stale (redeploy) or absent
 * (first deploy).
 *
 * The contract now: `scolta:build` builds synchronously by default and never
 * reports success for an index that was only enqueued. Async dispatch is
 * opt-in via --queue, and on a worker-less async connection it returns the
 * distinct DEFERRED exit code, not SUCCESS.
 */
class BuildDeploySafetyTest extends TestCase
{
    private string $stateDir;

    private string $outputDir;

    protected function getPackageProviders($app): array
    {
        return [ScoltaServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->stateDir = storage_path('framework/testing/scolta-deploy-state');
        $this->outputDir = storage_path('framework/testing/scolta-deploy-output');
        File::deleteDirectory($this->stateDir);
        File::deleteDirectory($this->outputDir);

        config([
            'scolta.state_dir' => $this->stateDir,
            'scolta.pagefind.output_dir' => $this->outputDir,
        ]);

        Schema::create('searchable_posts', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('body');
            $table->boolean('published')->default(true);
            $table->boolean('unlisted')->default(false);
            $table->timestamps();
        });

        // Set after boot so the observer is not registered for these tests.
        config(['scolta.models' => [SearchablePost::class]]);

        $body = str_repeat('Plenty of searchable body text for the index. ', 20);
        SearchablePost::create(['title' => 'Visible', 'body' => $body]);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('searchable_posts');
        File::deleteDirectory($this->stateDir);
        File::deleteDirectory($this->outputDir);
        File::deleteDirectory(public_path('vendor/scolta'));

        parent::tearDown();
    }

    private function entryPath(): string
    {
        return $this->outputDir.'/pagefind/pagefind-entry.json';
    }

    // -------------------------------------------------------------------
    // No false success on a worker-less async connection.
    // -------------------------------------------------------------------

    public function test_queue_on_async_connection_does_not_report_a_built_index(): void
    {
        // Async connection + faked bus: the chain is enqueued but nothing
        // drains it — exactly the worker-less deploy scenario.
        config(['queue.default' => 'database']);
        Bus::fake();

        $this->artisan('scolta:build', ['--queue' => true, '--force' => true])
            ->expectsOutputToContain('Index NOT yet built')
            ->assertExitCode(BuildCommand::DEFERRED);

        // The work was enqueued (so a worker could eventually build it)...
        Bus::assertChained([ProcessIndexChunk::class, FinalizeIndex::class]);

        // ...but the index does NOT exist: a deploy must not have read the
        // exit code as "built and live".
        $this->assertFileDoesNotExist(
            $this->entryPath(),
            'A --queue build on a worker-less async connection must not have produced an index.',
        );
        $this->assertNotSame(
            BuildCommand::SUCCESS,
            BuildCommand::DEFERRED,
            'DEFERRED must be a distinct, non-success exit code.',
        );
    }

    // -------------------------------------------------------------------
    // The default CLI path builds synchronously and verifies before SUCCESS.
    // -------------------------------------------------------------------

    public function test_default_build_is_synchronous_and_verified(): void
    {
        // No --queue, no --sync: the deploy-safe default. Exit 0 must mean the
        // index is actually built and live on disk.
        $this->artisan('scolta:build', ['--force' => true])
            ->expectsOutputToContain('Index built')
            ->assertExitCode(BuildCommand::SUCCESS);

        $this->assertFileExists(
            $this->entryPath(),
            'The default synchronous build must publish a usable Pagefind index before exiting 0.',
        );
    }

    // -------------------------------------------------------------------
    // --queue on the sync connection runs inline and reports an honest build.
    // -------------------------------------------------------------------

    public function test_queue_on_sync_connection_builds_inline_and_succeeds(): void
    {
        // On the sync connection the chain executes inline during dispatch(),
        // so the index is genuinely built by the time the command returns —
        // SUCCESS here is honest, unlike SUCCESS on an async connection.
        config(['queue.default' => 'sync']);

        $this->artisan('scolta:build', ['--queue' => true, '--force' => true])
            ->expectsOutputToContain('Index built inline on the sync queue')
            ->assertExitCode(BuildCommand::SUCCESS);

        $this->assertFileExists(
            $this->entryPath(),
            'A --queue build on the sync connection runs inline and must publish the index.',
        );
    }

    // -------------------------------------------------------------------
    // --sync (deprecated alias) and --queue cannot be combined.
    // -------------------------------------------------------------------

    public function test_queue_and_sync_are_mutually_exclusive(): void
    {
        $this->artisan('scolta:build', ['--queue' => true, '--sync' => true, '--force' => true])
            ->expectsOutputToContain('mutually exclusive')
            ->assertExitCode(BuildCommand::INVALID);

        $this->assertFileDoesNotExist(
            $this->entryPath(),
            'A rejected option combination must not build anything.',
        );
    }

    // -------------------------------------------------------------------
    // An interrupted finalize degrades to stale, never to empty.
    // -------------------------------------------------------------------

    public function test_failed_finalize_leaves_the_prior_index_intact(): void
    {
        // Publish a real index first (the synchronous default path).
        $this->artisan('scolta:build', ['--force' => true])->assertExitCode(BuildCommand::SUCCESS);
        $this->assertFileExists($this->entryPath());

        $before = File::get($this->entryPath());

        // Drive an interrupted rebuild: finalize against a state directory with
        // no committed chunk files. The orchestrator's merge/atomicSwap never
        // runs, so FinalizeIndex must fail loudly rather than swap in an empty
        // index. The atomicSwap guarantee is that the live index is only
        // retired *after* a new one is staged — so it stays put on failure.
        $emptyState = storage_path('framework/testing/scolta-empty-state');
        File::deleteDirectory($emptyState);
        File::ensureDirectoryExists($emptyState);

        $threw = false;
        try {
            (new FinalizeIndex($emptyState, $this->outputDir, config('app.key'), 'en', 'conservative'))->handle();
        } catch (\RuntimeException $e) {
            $threw = true;
        }

        $this->assertTrue(
            $threw,
            'A finalize with no committed chunks must fail loudly (so failed_jobs/Horizon surface it), not succeed.',
        );

        // The previously-published index is still present and byte-identical:
        // an interrupted rebuild degrades to stale, never to empty.
        $this->assertFileExists(
            $this->entryPath(),
            'A failed finalize must leave the previously-published index serving (stale, not empty).',
        );
        $this->assertSame(
            $before,
            File::get($this->entryPath()),
            'The prior index must be untouched by a failed finalize — no partial swap.',
        );

        File::deleteDirectory($emptyState);
    }
}
