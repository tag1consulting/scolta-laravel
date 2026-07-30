<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;
use Tag1\Scolta\Config\MemoryBudgetConfig;
use Tag1\Scolta\Index\MemoryBudget;
use Tag1\ScoltaLaravel\Jobs\FinalizeIndex;
use Tag1\ScoltaLaravel\Jobs\ProcessIndexChunk;
use Tag1\ScoltaLaravel\ScoltaServiceProvider;
use Tag1\ScoltaLaravel\Services\QueueRebuildDispatcher;
use Tag1\ScoltaLaravel\Support\HmacSecret;
use Tag1\ScoltaLaravel\Tests\Support\SearchablePost;

/**
 * An empty APP_KEY skips integrity tagging and says so; it does not abort.
 *
 * `config('app.key')` on an app that has not run `php artisan key:generate` is
 * `string(0) ""`, and both call sites forwarded it unguarded into the PHP
 * indexer, where `hash_init('sha256', HASH_HMAC, '')` throws. So the first
 * command a new adopter runs died with
 * "Index build failed: hash_init(): Argument #3 ($key) must not be empty when
 * HMAC is requested", naming neither the setting nor the remedy.
 *
 * These tests cover both call sites. The queued path was missing from the
 * original report: a fix touching only BuildCommand leaves the observer-driven
 * auto-rebuild crashing inside a worker, where nobody sees it.
 */
class EmptyAppKeyBuildTest extends TestCase
{
    private string $stateDir;

    private string $outputDir;

    protected function getPackageProviders($app): array
    {
        return [ScoltaServiceProvider::class];
    }

    /**
     * The reproducer condition: an app that has never run key:generate.
     *
     * Testbench sets a working key by default, so the unconfigured state has
     * to be asked for explicitly.
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', '');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->stateDir = storage_path('framework/testing/scolta-emptykey-state');
        $this->outputDir = storage_path('framework/testing/scolta-emptykey-output');
        File::deleteDirectory($this->stateDir);
        File::deleteDirectory($this->outputDir);

        // Tests that fake the bus never run FinalizeIndex, so the cross-process
        // build lock is never released. Start from a released lock.
        Cache::lock(QueueRebuildDispatcher::BUILD_LOCK)->forceRelease();

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

        SearchablePost::create([
            'title' => 'Visible',
            'body' => str_repeat('Plenty of searchable body text. ', 20),
            'published' => true,
            'unlisted' => false,
        ]);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('searchable_posts');
        File::deleteDirectory($this->stateDir);
        File::deleteDirectory($this->outputDir);
        File::deleteDirectory(public_path('vendor/scolta'));

        parent::tearDown();
    }

    private function budget(): MemoryBudget
    {
        return MemoryBudgetConfig::fromCliAndConfig(
            null,
            null,
            fn () => ['profile' => 'conservative', 'chunk_size' => null],
        );
    }

    // -------------------------------------------------------------------
    // Synchronous path: BuildCommand::buildWithPhpIndexer().
    // -------------------------------------------------------------------

    /**
     * The reported failure, end to end. This is the whole bug: the command
     * must produce an index rather than an exception.
     */
    public function test_sync_build_completes_with_empty_app_key(): void
    {
        $this->artisan('scolta:build', ['--sync' => true, '--force' => true])
            ->expectsOutputToContain('Index built: 1 pages')
            ->assertExitCode(0);

        $this->assertFileExists(
            $this->outputDir.'/pagefind/pagefind-entry.json',
            'An index without an integrity tag is still an index, and must still be published.',
        );
    }

    /**
     * The warning has to be actionable on its own: the operator should not
     * have to know that APP_KEY and HMAC tagging are related.
     *
     * Asserted against the message constant rather than through the console,
     * because `expectsOutputToContain()` becomes one Mockery expectation on
     * `doWrite()` per substring, and a single output line satisfies only the
     * first expectation it matches. Two substrings from the same line therefore
     * cannot both be asserted that way, so content is checked here and
     * emission is checked below with one substring per printed line.
     */
    public function test_warning_names_app_key_and_the_remedy(): void
    {
        $warning = HmacSecret::emptyAppKeyWarning();

        $this->assertStringContainsString('APP_KEY', $warning);
        $this->assertStringContainsString('php artisan key:generate', $warning);
        $this->assertStringContainsString('integrity tag', $warning,
            'The operator has to be told what is switched off, not just what to run.');
        $this->assertStringContainsString('CRC32', $warning,
            'Corruption detection is unaffected, and saying so is what keeps this a warning rather than an alarm.');
    }

    /**
     * One substring per printed line, for the Mockery reason above: all three
     * warning lines have to reach the console, not just the first.
     */
    public function test_sync_build_emits_the_warning(): void
    {
        $this->artisan('scolta:build', ['--sync' => true, '--force' => true])
            ->expectsOutputToContain('APP_KEY')
            ->expectsOutputToContain('key:generate')
            ->expectsOutputToContain('CRC32')
            ->assertExitCode(0);
    }

    public function test_sync_build_emits_no_warning_when_app_key_is_set(): void
    {
        config(['app.key' => 'base64:'.base64_encode(str_repeat('a', 32))]);

        $this->artisan('scolta:build', ['--sync' => true, '--force' => true])
            ->doesntExpectOutputToContain('APP_KEY')
            ->doesntExpectOutputToContain('php artisan key:generate')
            ->assertExitCode(0);
    }

    /**
     * A whitespace-only APP_KEY is an accident, not a key. Honouring it would
     * write a tag only a caller reproducing the same whitespace could verify.
     */
    public function test_sync_build_treats_whitespace_only_app_key_as_unset(): void
    {
        config(['app.key' => '   ']);

        $this->artisan('scolta:build', ['--sync' => true, '--force' => true])
            ->expectsOutputToContain('APP_KEY')
            ->assertExitCode(0);
    }

    // -------------------------------------------------------------------
    // Queued path: QueueRebuildDispatcher::dispatch().
    // -------------------------------------------------------------------

    /**
     * The call site the original report missed. Without the coercion here the
     * chain dispatches and then every ProcessIndexChunk worker throws.
     */
    public function test_queued_dispatch_completes_with_empty_app_key(): void
    {
        Bus::fake();

        $result = app(QueueRebuildDispatcher::class)->dispatch($this->budget(), force: true);

        $this->assertSame(QueueRebuildDispatcher::STATUS_DISPATCHED, $result['status']);
        $this->assertSame(1, $result['items']);
    }

    /**
     * The jobs must carry null, not ''. They run in separate worker processes
     * and construct their own BuildCoordinator from this value, so an empty
     * string reaching them is the crash, just deferred and out of sight.
     *
     * Only the head of a Bus::chain() counts as dispatched, so FinalizeIndex is
     * inspected through the chained payload the head carries.
     */
    public function test_queued_jobs_carry_null_hmac_secret_when_app_key_is_empty(): void
    {
        Bus::fake();

        app(QueueRebuildDispatcher::class)->dispatch($this->budget(), force: true);

        Bus::assertChained([ProcessIndexChunk::class, FinalizeIndex::class]);
        Bus::assertDispatched(
            ProcessIndexChunk::class,
            fn (ProcessIndexChunk $job) => $job->hmacSecret === null,
        );

        $this->assertNull(
            $this->chainedFinalizeIndex()->hmacSecret,
            'FinalizeIndex runs in its own worker and builds its own BuildCoordinator, '
            .'so an empty string reaching it is the same crash one job later.',
        );
    }

    /**
     * Pull the chained FinalizeIndex back out of the dispatched head job.
     */
    private function chainedFinalizeIndex(): FinalizeIndex
    {
        $head = null;
        Bus::assertDispatched(ProcessIndexChunk::class, function (ProcessIndexChunk $job) use (&$head) {
            $head = $job;

            return true;
        });

        foreach ($head->chained as $serialized) {
            // allowed_classes is pinned to the two job classes this chain can
            // legitimately contain. The payload is Laravel's own, but an
            // unbounded unserialize() in a test is still a bad pattern to leave
            // lying around for the next person to copy.
            $job = unserialize($serialized, [
                'allowed_classes' => [FinalizeIndex::class, ProcessIndexChunk::class],
            ]);
            if ($job instanceof FinalizeIndex) {
                return $job;
            }
        }

        $this->fail('The dispatched chain carried no FinalizeIndex job.');
    }

    /**
     * The queued path has no console, so the warning goes to the log. Captured
     * through the MessageLogged event rather than a facade spy: the event is a
     * real typed contract, and it records the level alongside the message.
     */
    public function test_queued_dispatch_logs_the_warning_when_app_key_is_empty(): void
    {
        Bus::fake();

        /** @var list<array{level: string, message: string}> $logged */
        $logged = [];
        Event::listen(function (MessageLogged $event) use (&$logged): void {
            $logged[] = ['level' => $event->level, 'message' => $event->message];
        });

        app(QueueRebuildDispatcher::class)->dispatch($this->budget(), force: true);

        $warnings = array_values(array_filter(
            $logged,
            fn (array $entry) => $entry['level'] === 'warning' && str_contains($entry['message'], 'APP_KEY'),
        ));

        $this->assertCount(1, $warnings, 'The queued path must warn exactly once about the empty APP_KEY.');
        $this->assertStringContainsString('php artisan key:generate', $warnings[0]['message']);
        $this->assertStringContainsString('CRC32', $warnings[0]['message']);
    }

    public function test_queued_dispatch_logs_no_warning_when_app_key_is_set(): void
    {
        Bus::fake();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('a', 32))]);

        /** @var list<string> $logged */
        $logged = [];
        Event::listen(function (MessageLogged $event) use (&$logged): void {
            $logged[] = $event->message;
        });

        app(QueueRebuildDispatcher::class)->dispatch($this->budget(), force: true);

        $mentioningAppKey = array_filter($logged, fn (string $m) => str_contains($m, 'APP_KEY'));

        $this->assertSame([], array_values($mentioningAppKey),
            'A configured APP_KEY is the normal case and must not log anything.');
    }

    /**
     * A configured key must still reach the workers, or this fix would have
     * silently disabled integrity tagging for everyone.
     */
    public function test_queued_jobs_carry_a_configured_app_key(): void
    {
        Bus::fake();
        $key = 'base64:'.base64_encode(str_repeat('a', 32));
        config(['app.key' => $key]);

        app(QueueRebuildDispatcher::class)->dispatch($this->budget(), force: true);

        Bus::assertDispatched(
            ProcessIndexChunk::class,
            fn (ProcessIndexChunk $job) => $job->hmacSecret === $key,
        );
    }

    // -------------------------------------------------------------------
    // The shared coercion.
    // -------------------------------------------------------------------

    public function test_normalize_reduces_unset_spellings_to_null(): void
    {
        $this->assertNull(HmacSecret::normalize(null));
        $this->assertNull(HmacSecret::normalize(''));
        $this->assertNull(HmacSecret::normalize(' '));
        $this->assertNull(HmacSecret::normalize("  \t\n  "));
        $this->assertNull(HmacSecret::normalize([]), 'A mis-typed config value is not key material.');
    }

    public function test_normalize_returns_real_keys_verbatim(): void
    {
        $this->assertSame('base64:abc', HmacSecret::normalize('base64:abc'));
        $this->assertSame(' padded ', HmacSecret::normalize(' padded '),
            'Trimming would stop an index built under a padded key from verifying.');
        $this->assertSame('0', HmacSecret::normalize('0'),
            '"0" is falsy in PHP, so `?: null` would have dropped real key material.');
    }
}
