<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Tests\Jobs;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;
use Tag1\Scolta\Config\MemoryBudgetConfig;
use Tag1\Scolta\Index\MemoryBudget;
use Tag1\ScoltaLaravel\Jobs\FinalizeIndex;
use Tag1\ScoltaLaravel\Jobs\ProcessIndexChunk;
use Tag1\ScoltaLaravel\Jobs\TriggerRebuild;
use Tag1\ScoltaLaravel\ScoltaServiceProvider;
use Tag1\ScoltaLaravel\Services\QueueRebuildDispatcher;
use Tag1\ScoltaLaravel\Tests\Support\SearchablePost;

/**
 * The queued rebuild chain must actually publish an index.
 *
 * The defect this pins: the default queue paths (first-run auto-build and the
 * content-edit observer, both via `TriggerRebuild`) dispatched the
 * `ProcessIndexChunk… → FinalizeIndex` chain without initialising the build
 * manifest, so `recordChunk()` had nothing to record into, `chunkFiles()`
 * returned empty, and `FinalizeIndex` failed with "No chunk files found" — the
 * chain dispatched but produced **no index**. The existing
 * `QueueRebuildDispatchTest` faked the bus and only asserted the chain was
 * *dispatched*; that blind spot is what shipped a dead default path.
 *
 * These tests drive the chain end-to-end on the `sync` queue connection (so
 * `Bus::chain()->dispatch()` runs inline) and assert a real, non-empty index
 * lands on disk — and that two overlapping dispatches do not corrupt state.
 */
class QueuedRebuildProducesIndexTest extends TestCase
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

        $this->stateDir = storage_path('framework/testing/scolta-queued-state');
        $this->outputDir = storage_path('framework/testing/scolta-queued-output');
        File::deleteDirectory($this->stateDir);
        File::deleteDirectory($this->outputDir);

        config([
            'scolta.state_dir' => $this->stateDir,
            'scolta.pagefind.output_dir' => $this->outputDir,
        ]);

        // Start every test from a released build lock so a held lock from a
        // prior test (e.g. a faked chain that never released it) can't leak in.
        Cache::lock(QueueRebuildDispatcher::BUILD_LOCK)->forceRelease();

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
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('searchable_posts');
        File::deleteDirectory($this->stateDir);
        File::deleteDirectory($this->outputDir);
        Cache::lock(QueueRebuildDispatcher::BUILD_LOCK)->forceRelease();

        parent::tearDown();
    }

    private function entryPath(): string
    {
        return $this->outputDir.'/pagefind/pagefind-entry.json';
    }

    private function createPost(string $title): void
    {
        SearchablePost::create([
            'title' => $title,
            'body' => str_repeat('Plenty of searchable body text for '.$title.'. ', 20),
        ]);
    }

    private function conservativeBudget(): MemoryBudget
    {
        return MemoryBudgetConfig::fromCliAndConfig(
            null,
            null,
            fn () => ['profile' => 'conservative', 'chunk_size' => null],
        );
    }

    // -------------------------------------------------------------------
    // (1) A drained queue chain publishes a NON-EMPTY index.
    //
    // This is the test that would have caught the dead path: the previous
    // code dispatched the chain but FinalizeIndex found no committed chunks
    // (no manifest), so no index was ever written.
    // -------------------------------------------------------------------

    public function test_queued_rebuild_produces_a_nonempty_published_index(): void
    {
        // The sync connection runs Bus::chain() inline, so dispatching the
        // observer/first-run job drives the whole chain to completion here.
        config(['queue.default' => 'sync']);

        $this->createPost('Visible');

        $this->assertFileDoesNotExist($this->entryPath(), 'Precondition: no index yet.');

        // TriggerRebuild is the exact job the observer and the first-run
        // auto-build dispatch — the default path, with no prepareBuildState
        // opt-in. With the cross-process build lock in place, it must now
        // initialise the manifest and produce a real index.
        (new TriggerRebuild(force: true))->handle(app(QueueRebuildDispatcher::class));

        $this->assertFileExists(
            $this->entryPath(),
            'A drained queue rebuild must publish pagefind-entry.json — the chain initialises the build manifest now.',
        );

        $fragments = File::glob($this->outputDir.'/pagefind/fragment/*.pf_fragment');
        $this->assertNotEmpty(
            $fragments,
            'The published index must contain page fragments — FinalizeIndex found and merged the committed chunks.',
        );

        // The chain ended cleanly: the build lock is free and the state dir was
        // reset, so the next rebuild can proceed.
        $lock = Cache::lock(QueueRebuildDispatcher::BUILD_LOCK);
        $this->assertTrue($lock->get(), 'The cross-process build lock must be released when the chain completes.');
        $lock->release();

        $this->assertFileDoesNotExist(
            $this->stateDir.'/manifest.json',
            'A completed build resets its state — no lingering manifest blocking the next rebuild.',
        );
    }

    // -------------------------------------------------------------------
    // (2) First-run auto-build: no index + auto_rebuild + a draining worker
    //     yields an index; a worker-less async connection stays honest.
    // -------------------------------------------------------------------

    public function test_first_run_build_yields_an_index_on_a_draining_worker(): void
    {
        config(['queue.default' => 'sync', 'scolta.auto_rebuild' => true]);

        $this->createPost('Visible');

        // No prior index — the first-run condition. The dispatcher gathers,
        // initialises the manifest under the lock, and the sync chain finalises.
        $result = app(QueueRebuildDispatcher::class)->dispatch($this->conservativeBudget(), force: true);

        $this->assertSame(QueueRebuildDispatcher::STATUS_DISPATCHED, $result['status']);
        $this->assertFileExists(
            $this->entryPath(),
            'First-run auto-build on a draining (sync) worker must produce an index.',
        );
    }

    public function test_first_run_on_workerless_async_connection_defers_without_an_index(): void
    {
        // #96's deferred-not-success contract: on a worker-less async connection
        // the chain is only enqueued — it must NOT claim a built index.
        config(['queue.default' => 'database']);
        Bus::fake();

        $this->createPost('Visible');

        $result = app(QueueRebuildDispatcher::class)->dispatch($this->conservativeBudget(), force: true);

        $this->assertSame(QueueRebuildDispatcher::STATUS_DISPATCHED, $result['status']);
        Bus::assertChained([ProcessIndexChunk::class, FinalizeIndex::class]);
        $this->assertFileDoesNotExist(
            $this->entryPath(),
            'An enqueued-but-undrained chain must not have produced an index.',
        );
    }

    // -------------------------------------------------------------------
    // (3) Concurrency: a second overlapping dispatch no-ops and does not
    //     corrupt the in-flight build's chunk state; the manifest ends clean.
    // -------------------------------------------------------------------

    public function test_overlapping_dispatch_noops_while_a_build_is_in_progress(): void
    {
        config(['queue.default' => 'database']);
        Bus::fake();

        $this->createPost('Visible');

        $dispatcher = app(QueueRebuildDispatcher::class);

        // First dispatch acquires the lock, initialises the manifest, and
        // enqueues the chain (faked — FinalizeIndex never runs, so the lock
        // stays held, modelling an in-flight chain).
        $first = $dispatcher->dispatch($this->conservativeBudget(), force: true);
        $this->assertSame(QueueRebuildDispatcher::STATUS_DISPATCHED, $first['status']);

        $manifestBefore = File::get($this->stateDir.'/manifest.json');

        // A second dispatch arriving mid-chain must find the lock held and
        // no-op — it must NOT re-run prepare()/cleanup() and wipe the in-flight
        // build's manifest or chunk files.
        $second = $dispatcher->dispatch($this->conservativeBudget(), force: true);

        $this->assertSame(QueueRebuildDispatcher::STATUS_IN_PROGRESS, $second['status']);
        $this->assertSame(0, $second['chunks'], 'A no-op dispatch enqueues nothing.');
        $this->assertSame(
            $manifestBefore,
            File::get($this->stateDir.'/manifest.json'),
            'The second dispatch must not clobber the in-flight build manifest.',
        );

        // Only the first chain was ever enqueued.
        Bus::assertChained([ProcessIndexChunk::class, FinalizeIndex::class]);
    }

    public function test_lock_released_after_a_build_lets_the_next_rebuild_proceed(): void
    {
        config(['queue.default' => 'sync']);

        $this->createPost('Visible');

        // A full sync build releases the lock at FinalizeIndex, so a subsequent
        // forced rebuild is not blocked as "in progress".
        $first = app(QueueRebuildDispatcher::class)->dispatch($this->conservativeBudget(), force: true);
        $this->assertSame(QueueRebuildDispatcher::STATUS_DISPATCHED, $first['status']);

        $second = app(QueueRebuildDispatcher::class)->dispatch($this->conservativeBudget(), force: true);
        $this->assertSame(
            QueueRebuildDispatcher::STATUS_DISPATCHED,
            $second['status'],
            'After a completed chain releases the lock, the next rebuild must run — not report IN_PROGRESS.',
        );
        $this->assertFileExists($this->entryPath());
    }
}
