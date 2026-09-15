<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Tests\Jobs;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;
use Psr\Log\LoggerInterface;
use Tag1\Scolta\Config\MemoryBudgetConfig;
use Tag1\Scolta\Index\BuildCoordinator;
use Tag1\Scolta\Index\BuildIntent;
use Tag1\Scolta\Index\BuildState;
use Tag1\Scolta\Index\IndexBuildOrchestrator;
use Tag1\Scolta\Index\MemoryBudget;
use Tag1\Scolta\Index\PageTableLedger;
use Tag1\Scolta\Index\ResumeChainPolicy;
use Tag1\Scolta\Index\StatusReport;
use Tag1\Scolta\Storage\FilesystemDriver;
use Tag1\ScoltaLaravel\Jobs\ProcessIndexChunk;
use Tag1\ScoltaLaravel\Jobs\TriggerRebuild;
use Tag1\ScoltaLaravel\Models\ScoltaTracker;
use Tag1\ScoltaLaravel\ScoltaServiceProvider;
use Tag1\ScoltaLaravel\Services\ContentSource;
use Tag1\ScoltaLaravel\Services\QueueRebuildDispatcher;
use Tag1\ScoltaLaravel\Services\ResumeChain;
use Tag1\ScoltaLaravel\Tests\Support\SearchablePost;

/**
 * The queued rebuild request continues an interrupted build instead of
 * restarting it, and the failure modes land where Laravel puts them.
 *
 * A kill is modelled the only way a test can: the chunk chain is enqueued on
 * a faked bus, one chunk job is run by hand, and the build lock is released
 * as its TTL would have. What is left on disk is exactly what a worker killed
 * after its first chunk leaves: a `building` manifest, one committed chunk,
 * and no recorded outcome.
 */
class TriggerRebuildResumeTest extends TestCase
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

        $this->stateDir = storage_path('framework/testing/scolta-resume-job-state');
        $this->outputDir = storage_path('framework/testing/scolta-resume-job-output');
        File::deleteDirectory($this->stateDir);
        File::deleteDirectory($this->outputDir);

        config([
            'scolta.state_dir' => $this->stateDir,
            'scolta.pagefind.output_dir' => $this->outputDir,
            'scolta.auto_rebuild' => false,
            // One page per chunk, so a three-post corpus is a three-chunk chain.
            'scolta.memory_budget.chunk_size' => 1,
            'queue.default' => 'database',
        ]);

        Cache::lock(QueueRebuildDispatcher::BUILD_LOCK)->forceRelease();
        Cache::forget(TriggerRebuild::DEBOUNCE_KEY);
        Cache::forget(TriggerRebuild::FAILURE_COUNT_KEY);

        $this->loadMigrationsFrom(dirname(__DIR__, 2).'/database/migrations');
        ScoltaTracker::flushSchemaCache();

        Schema::create('searchable_posts', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('body');
            $table->boolean('published')->default(true);
            $table->boolean('unlisted')->default(false);
            $table->timestamps();
        });
        config(['scolta.models' => [SearchablePost::class]]);
        SearchablePost::flushEventListeners();

        foreach (['Alpha', 'Beta', 'Gamma'] as $title) {
            SearchablePost::create([
                'title' => $title,
                'body' => "Body of {$title}. ".str_repeat('Plenty of searchable body text. ', 20),
            ]);
        }
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('searchable_posts');
        File::deleteDirectory($this->stateDir);
        File::deleteDirectory($this->outputDir);
        File::deleteDirectory(public_path('vendor/scolta'));
        Cache::lock(QueueRebuildDispatcher::BUILD_LOCK)->forceRelease();
        ScoltaTracker::flushSchemaCache();
        parent::tearDown();
    }

    public function test_a_request_after_a_killed_chain_resumes_and_publishes_the_index(): void
    {
        Bus::fake();
        $result = app(QueueRebuildDispatcher::class)->dispatch($this->budget(), force: true);
        $this->assertSame(QueueRebuildDispatcher::STATUS_DISPATCHED, $result['status']);
        $this->assertSame(3, $result['chunks']);

        // The worker ran the first chunk and was killed; its lock lapsed.
        $first = Bus::dispatched(ProcessIndexChunk::class)->first();
        $this->assertInstanceOf(ProcessIndexChunk::class, $first);
        $first->handle();
        Cache::lock(QueueRebuildDispatcher::BUILD_LOCK)->forceRelease();

        $this->assertSame(1, $this->buildState()->getChunksWritten(), 'Precondition: one chunk committed.');
        $this->assertNull($this->buildState()->readOutcome(), 'Precondition: a kill records nothing.');

        (new TriggerRebuild)->handle(app(QueueRebuildDispatcher::class));

        $this->assertFileExists($this->outputDir.'/pagefind/pagefind-entry.json');
        IndexBuildOrchestrator::verifyIndexComplete($this->outputDir);
        $this->assertSame(3, $this->ledgerLiveCount(), 'Every page is in the finished index, none twice.');
        $this->assertFileDoesNotExist($this->stateDir.'/manifest.json', 'A completed build resets its state.');
        $this->assertTrue(Cache::lock(QueueRebuildDispatcher::BUILD_LOCK)->get(), 'The lock is released with the build.');

        // The standing copy outlives the segment: had this worker died too, it
        // would have been the request that finished the build.
        Bus::assertDispatched(TriggerRebuild::class, fn (TriggerRebuild $job) => $job->resumeOnly && $job->delay !== null);
    }

    public function test_a_segment_that_yields_with_no_child_to_spawn_re_dispatches_itself(): void
    {
        Bus::fake();
        $this->leaveInterruptedBuild();
        $this->app->bind(ResumeChain::class, fn () => new NoArtisanResumeChain);

        // Yield after the first committed page: progress, so not a stall.
        $calls = 0;
        $job = YieldingTriggerRebuild::probing(function () use (&$calls): bool {
            return ++$calls > 1;
        });
        $job->handle(app(QueueRebuildDispatcher::class));

        // The runner clears the recorded outcome before trying a child, so what
        // is left reads as a killed segment: resumable either way.
        $this->assertTrue(ResumeChainPolicy::resumable($this->buildState()), 'The build stays on disk for the next run.');
        $this->assertSame(2, $this->buildState()->getPagesProcessed(), 'The yielded segment kept its committed pages.');
        Bus::assertDispatched(TriggerRebuild::class, fn (TriggerRebuild $job) => ! $job->resumeOnly && $job->delay !== null);
        $this->assertFileDoesNotExist($this->outputDir.'/pagefind/pagefind-entry.json', 'Nothing is published mid-build.');
    }

    public function test_a_failure_resuming_cannot_fix_fails_the_job(): void
    {
        Bus::fake();
        $this->leaveInterruptedBuild();
        $this->app->bind(ContentSource::class, fn () => new BrokenContentSource);

        try {
            (new TriggerRebuild)->handle(app(QueueRebuildDispatcher::class));
            $this->fail('A broken build must fail the job, not return as if it were done.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Scolta index rebuild failed', $e->getMessage());
        }

        $this->assertTrue(Cache::lock(QueueRebuildDispatcher::BUILD_LOCK)->get(), 'A failed segment releases the lock.');
    }

    public function test_a_segment_that_dies_without_recording_is_retried_a_bounded_number_of_times(): void
    {
        Bus::fake();
        $this->leaveInterruptedBuild();

        // A worker the OOM killer took, or a fault before the orchestrator
        // could record one: the build stays resumable and the copy queued
        // before the segment comes back to try it again.
        for ($attempt = 1; $attempt <= TriggerRebuild::MAX_SEGMENT_FAILURES; $attempt++) {
            Bus::fake();
            try {
                (new KilledTriggerRebuild(resumeOnly: true))->handle(app(QueueRebuildDispatcher::class));
                $this->fail("Attempt {$attempt} must fail the job.");
            } catch (\RuntimeException $e) {
                $this->assertSame('the worker died mid-segment', $e->getMessage());
            }

            $this->assertTrue(ResumeChainPolicy::resumable($this->buildState()), 'The build is still there to retry.');
            $this->assertSame($attempt, Cache::get(TriggerRebuild::FAILURE_COUNT_KEY));
            // A request stands to retry the segment.
            Bus::assertDispatched(TriggerRebuild::class, fn (TriggerRebuild $job) => $job->resumeOnly);
        }

        // The copy queued before the last segment finds the budget spent: it
        // runs nothing and leaves nothing behind, which is what stops the loop.
        Bus::fake();
        (new KilledTriggerRebuild(resumeOnly: true))->handle(app(QueueRebuildDispatcher::class));

        Bus::assertNothingDispatched();
        $this->assertTrue(Cache::lock(QueueRebuildDispatcher::BUILD_LOCK)->get(), 'The exhausted build never took the lock.');
        Cache::lock(QueueRebuildDispatcher::BUILD_LOCK)->forceRelease();

        // A request that is not the standing copy starts the budget over.
        try {
            (new KilledTriggerRebuild)->handle(app(QueueRebuildDispatcher::class));
            $this->fail('The new request runs a segment, which dies as before.');
        } catch (\RuntimeException) {
        }
        $this->assertSame(1, Cache::get(TriggerRebuild::FAILURE_COUNT_KEY), 'The spent budget did not carry into a new request.');
    }

    public function test_a_completed_build_clears_the_retry_budget(): void
    {
        Bus::fake();
        $this->leaveInterruptedBuild();
        Cache::forever(TriggerRebuild::FAILURE_COUNT_KEY, TriggerRebuild::MAX_SEGMENT_FAILURES - 1);

        (new TriggerRebuild(resumeOnly: true))->handle(app(QueueRebuildDispatcher::class));

        $this->assertFileExists($this->outputDir.'/pagefind/pagefind-entry.json');
        $this->assertNull(Cache::get(TriggerRebuild::FAILURE_COUNT_KEY));
    }

    public function test_the_standing_copy_does_nothing_when_there_is_nothing_to_resume(): void
    {
        Bus::fake();

        (new TriggerRebuild(resumeOnly: true))->handle(app(QueueRebuildDispatcher::class));

        Bus::assertNothingDispatched();
        $this->assertFileDoesNotExist($this->stateDir.'/manifest.json');
        $this->assertFileDoesNotExist($this->outputDir.'/pagefind/pagefind-entry.json');
    }

    public function test_a_request_that_finds_a_build_running_waits_instead_of_dropping(): void
    {
        Bus::fake();
        $held = Cache::lock(QueueRebuildDispatcher::BUILD_LOCK, 60);
        $this->assertTrue($held->get());

        try {
            (new TriggerRebuild)->handle(app(QueueRebuildDispatcher::class));
        } finally {
            $held->release();
        }

        Bus::assertDispatched(TriggerRebuild::class, fn (TriggerRebuild $job) => ! $job->resumeOnly && $job->delay !== null);
    }

    public function test_request_build_queues_one_request_and_no_duplicate(): void
    {
        Bus::fake();

        $this->artisan('scolta:request-build')->assertSuccessful();
        $this->artisan('scolta:request-build')
            ->expectsOutputToContain('already requested')
            ->assertSuccessful();

        Bus::assertDispatchedTimes(TriggerRebuild::class, 1);
    }

    // -------------------------------------------------------------------

    /**
     * A `building` manifest with nothing committed and no recorded outcome.
     */
    private function leaveInterruptedBuild(): void
    {
        $coordinator = new BuildCoordinator($this->stateDir, null);
        $coordinator->prepare(BuildIntent::fresh(3, $this->budget()));
        $coordinator->releaseLockOnly();
    }

    private function budget(): MemoryBudget
    {
        return MemoryBudgetConfig::fromCliAndConfig(null, null, fn () => ['profile' => 'conservative', 'chunk_size' => 1]);
    }

    private function buildState(): BuildState
    {
        return new BuildState($this->stateDir);
    }

    private function ledgerLiveCount(): int
    {
        return (new PageTableLedger($this->stateDir, new FilesystemDriver))->liveCount();
    }
}

class YieldingTriggerRebuild extends TriggerRebuild
{
    private ?\Closure $probe = null;

    public static function probing(\Closure $probe): self
    {
        $job = new self;
        $job->probe = $probe;

        return $job;
    }

    protected function orchestrator(string $stateDir, string $outputDir): IndexBuildOrchestrator
    {
        return new IndexBuildOrchestrator($stateDir, $outputDir, null, 'en', null, $this->probe);
    }
}

/**
 * A segment that takes the process with it: nothing recorded, build resumable.
 */
class KilledTriggerRebuild extends TriggerRebuild
{
    protected function runSegment(IndexBuildOrchestrator $orchestrator, BuildIntent $intent, ContentSource $source, string $outputDir, LoggerInterface $logger): StatusReport
    {
        throw new \RuntimeException('the worker died mid-segment');
    }
}

class NoArtisanResumeChain extends ResumeChain
{
    public function runSegment(?string $memoryBudget, ?string $chunkSize, bool $force, ?callable $onOutput = null): ?int
    {
        return null;
    }
}

class BrokenContentSource extends ContentSource
{
    public function __construct() {}

    public function getPublishedContent(array $options = []): \Generator
    {
        yield from $this->broken();
    }

    /** @return iterable<int, never> */
    private function broken(): iterable
    {
        throw new \RuntimeException('the content source is broken');
    }

    public function pendingWatermark(): ?string
    {
        return null;
    }
}
