<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Commands;

use Illuminate\Cache\Lock;
use Illuminate\Console\Command;
use Illuminate\Log\Logger;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Tag1\Scolta\Config\MemoryBudgetConfig;
use Tag1\Scolta\Export\ContentExporter;
use Tag1\Scolta\Index\BuildIntentFactory;
use Tag1\Scolta\Index\BuildState;
use Tag1\Scolta\Index\IndexBuildOrchestrator;
use Tag1\Scolta\Index\MemoryBudget;
use Tag1\Scolta\Index\ResumeChainPolicy;
use Tag1\ScoltaLaravel\Jobs\FinalizeIndex;
use Tag1\ScoltaLaravel\Progress\ArtisanProgressReporter;
use Tag1\ScoltaLaravel\Services\AssetStatus;
use Tag1\ScoltaLaravel\Services\ContentSource;
use Tag1\ScoltaLaravel\Services\QueueRebuildDispatcher;
use Tag1\ScoltaLaravel\Services\ResumeChain;
use Tag1\ScoltaLaravel\Support\HmacSecret;

/**
 * Build or rebuild the Scolta search index.
 *
 * This is the Artisan equivalent of `wp scolta build` (WordPress) and
 * `drush scolta:build` (Drupal). It gathers content directly from Eloquent
 * models, feeds it through scolta-php's PHP indexer, and writes a
 * Pagefind-compatible index. There is no other pipeline: the Pagefind binary
 * indexer and its HTML export were removed in 2.0.0.
 *
 * This command is always a **full** build, matching `drush scolta:build`.
 * Incremental updates are not something an operator asks for here: a content
 * save queues one automatically (ScoltaObserver -> TriggerRebuild ->
 * QueueRebuildDispatcher -> IncrementalIndexUpdate), which is where the cost of
 * an edit belongs. --incremental is a deprecated no-op, kept because it is a
 * documented public option that deploy scripts pass.
 *
 * Laravel's command system is beautifully expressive. The $signature
 * string declares options with types and defaults — the framework
 * handles parsing, validation, and help text generation. Compare this
 * to WordPress's WP-CLI where you manually extract flags from $assoc_args.
 *
 * @since 0.2.0
 *
 * @stability experimental
 */
class BuildCommand extends Command
{
    protected $signature = 'scolta:build
        {--incremental : Deprecated no-op: content edits now update the index incrementally on their own, and this command is always a full build. Accepted for backward compatibility}
        {--force : Skip fingerprint check and force a full rebuild}
        {--queue : Dispatch the build to the queue instead of building inline (the index is not built until a worker drains the chain)}
        {--sync : Deprecated no-op: synchronous building is now the default. Accepted for backward compatibility}
        {--memory-budget= : Memory profile or byte value (conservative, 256M, 1G…). Default: from config.}
        {--chunk-size= : Pages per chunk. Overrides profile default and config setting.}
        {--resume : Resume a previously interrupted PHP index build}
        {--restart : Discard interrupted state and restart the PHP index build. Also discards the page-table ledger, renumbering every page from zero}
        {--reset-ledger : Discard the page-table ledger under a plain build (inline or --queue), renumbering every page from zero. Escape hatch for a corrupt page table (a duplicate page ordinal at the merge) without a full --restart. Cannot be combined with --resume}';

    protected $description = 'Build or rebuild the Scolta search index';

    protected $help = <<<'HELP'
Always a full build. Content edits reach the index on their own: a model save
queues an incremental update, so there is nothing to pass here to reflect a
few edited records. Run this for the first index, on deploy, and as a slow
scheduled backstop for changes that bypass Eloquent events.

Synchronous and verified by default: the command blocks until the index is
built and exits 0 only once a usable index is live on disk. Exit codes:

  0  index built and published
  1  build failed; the previous index is still serving
  2  contradictory options (for example --reset-ledger with --resume)
  3  deferred: the build or its finalize step is queued and the index is not
     published until a worker (php artisan queue:work) drains it

Deploy scripts that gate on this command must treat any non-zero exit as
"not built". Do not pass --queue in a deploy step unless a worker finishes
before traffic is served.
HELP;

    /**
     * Exit code for a build that was dispatched to an asynchronous queue and
     * therefore is NOT yet built on disk.
     *
     * Distinct from SUCCESS (0): the index is only enqueued and will not be
     * published until a worker drains the chain. Distinct from FAILURE (1):
     * nothing went wrong — the operator opted into deferred indexing via
     * --queue. Deploy tooling that requires a live index before routing
     * traffic MUST treat any non-zero exit (including this one) as "not built".
     *
     * @since 1.0.4
     *
     * @stability experimental
     */
    public const DEFERRED = 3;

    /**
     * Owner token of the build lock this run holds or inherited; handed to
     * resume segments so they run under it.
     */
    private ?string $lockOwner = null;

    public function handle(ContentSource $source): int
    {
        $outputDir = config('scolta.pagefind.output_dir', public_path('scolta-pagefind'));

        // Announced before anything else runs: the option
        // used to change what this command did, so a script still passing it is
        // getting a different build than it asked for and has to be told once,
        // out loud, rather than by reading the changelog. Same treatment as
        // --sync, which is the repo's precedent for retiring a public option.
        if ($this->option('incremental')) {
            $this->warn(
                '--incremental is deprecated and does nothing: this command is always a full build. '
                .'Content edits are applied to the index incrementally on their own, through the queued '
                .'rebuild the model observer schedules. Drop the flag.'
            );
        }

        // Deploy-safe default: build inline and verify before reporting
        // success, so `scolta:build` exiting 0 always means "the index is
        // built and live". Asynchronous indexing is now opt-in via --queue
        // (the content-edit observer keeps using the queue independently).
        //
        // --sync is the former opt-in for this synchronous path; it is now
        // the default, so it is accepted as a no-op alias. The two options
        // are contradictory, so reject the combination rather than guess.
        if ($this->option('queue') && $this->option('sync')) {
            $this->error('--queue and --sync are mutually exclusive. Synchronous building is the default; pass --queue only to defer to the queue.');

            return self::INVALID;
        }

        if ($this->option('queue')) {
            return $this->dispatchToQueue($source, $outputDir);
        }

        return $this->buildWithPhpIndexer($source, $outputDir);
    }

    /**
     * The memory budget for this run, from --memory-budget/--chunk-size or config.
     *
     * One reader for both paths, inline and queued, so a build and the queued
     * rebuild of the same corpus compress their artifacts alike.
     */
    private function memoryBudget(): MemoryBudget
    {
        return MemoryBudgetConfig::fromCliAndConfig(
            $this->option('memory-budget'),
            $this->option('chunk-size'),
            fn () => [
                'profile' => config('scolta.memory_budget.profile', 'conservative'),
                'chunk_size' => config('scolta.memory_budget.chunk_size'),
            ],
        );
    }

    /**
     * Build the search index using the pure-PHP indexer via IndexBuildOrchestrator.
     *
     * Content is streamed through ContentSource::getPublishedContent() so the
     * documented publish filters (scopeSearchable + shouldBeSearchable) apply,
     * exactly as on the queue path.
     *
     * Returns INVALID when the resume/restart/reset-ledger flags contradict
     * each other; BuildIntentFactory owns that rule and its message.
     *
     * @since 0.2.0 (rewritten 0.3.0; --reset-ledger handling added in 1.4.0)
     *
     * @stability experimental
     */
    private function buildWithPhpIndexer(ContentSource $source, string $outputDir): int
    {
        // A segment spawned by a resume chain runs under the lock its parent
        // holds (the CLI chain or the queued TriggerRebuild) and must not take
        // or release one of its own: it would find the lock held and exit
        // deferred without building anything.
        $inherited = getenv(ResumeChain::LOCK_OWNER_ENV);
        if (is_string($inherited) && $inherited !== '') {
            $lock = Cache::restoreLock(QueueRebuildDispatcher::BUILD_LOCK, $inherited);
            if ($lock instanceof Lock && ! $lock->isOwnedByCurrentProcess()) {
                $this->error(sprintf(
                    'The build lock this segment was spawned under is no longer held (it expires after %d seconds). '
                    .'The index has not been republished; re-run `php artisan scolta:build --resume`.',
                    QueueRebuildDispatcher::BUILD_LOCK_TTL,
                ));

                return self::FAILURE;
            }
            $this->lockOwner = $inherited;

            return $this->buildWithPhpIndexerLocked($source, $outputDir);
        }

        // The lock the queued chain holds until FinalizeIndex: both paths
        // allocate from the shared ledger, and BuildState's flock is dropped
        // between chunk jobs.
        $lock = Cache::lock(QueueRebuildDispatcher::BUILD_LOCK, QueueRebuildDispatcher::BUILD_LOCK_TTL);
        if (! $lock->get()) {
            $this->warn(
                'Index NOT built by this command: a queued rebuild is already in progress and owns the '
                .'page-table ledger. Wait for it to finish, then re-run.'
            );

            return self::DEFERRED;
        }

        try {
            $this->lockOwner = $lock->owner();

            return $this->buildWithPhpIndexerLocked($source, $outputDir);
        } finally {
            $lock->release();
        }
    }

    /**
     * The synchronous PHP build proper, under the cross-process build lock.
     */
    private function buildWithPhpIndexerLocked(ContentSource $source, string $outputDir): int
    {
        $stateDir = config('scolta.state_dir', storage_path('app/scolta'));
        // An app that has not run key:generate has APP_KEY='', not null, and
        // forwarding that into the indexer used to abort the build inside
        // hash_init(). Skip integrity tagging instead, and say so: the operator
        // running this command is the one who can fix it.
        $hmacSecret = HmacSecret::normalize(config('app.key'));
        if ($hmacSecret === null) {
            foreach (HmacSecret::EMPTY_APP_KEY_WARNING_LINES as $line) {
                $this->warn($line);
            }
        }
        $language = config('scolta.ai_languages.0', 'en');
        $budget = $this->memoryBudget();

        $totalCount = $source->getTotalCount();

        // Built before the empty-corpus early return so contradictory flags are
        // reported as such rather than exiting 0 with "nothing to index".
        // $partial is deliberately unwired, and the named argument below skips
        // it: scolta:build has no scope filter and always streams the whole
        // corpus, so partial: true would stop the merge pruning and publishing.
        try {
            $intent = BuildIntentFactory::fromFlags(
                (bool) $this->option('resume'),
                (bool) $this->option('restart'),
                $totalCount,
                $budget,
                resetLedger: (bool) $this->option('reset-ledger'),
            );
        } catch (\LogicException $e) {
            // The library's message explains the refusal (--reset-ledger with
            // --resume, or with a partial scope) and what to run instead.
            $this->error($e->getMessage());

            return self::INVALID;
        }

        if ($totalCount === 0) {
            $this->warn('No searchable content found. Check scolta.models config.');

            return self::SUCCESS;
        }

        // Read before the corpus is gathered, so the drain below covers exactly
        // the changes this build could have seen. See ContentSource::clearTracker().
        $watermark = $source->pendingWatermark();

        // Stream content one record at a time — no full pre-load into RAM.
        $items = (new ContentExporter)->filterItems($source->getPublishedContent());

        // Floor for the progress check on a memory abort below. A fresh build
        // (including --restart) has prepare() reset the manifest, so reading the
        // file here would pick up the *previous* build's total and make a
        // legitimately progressing first segment look stalled.
        $pagesBefore = $intent->isFresh() ? 0 : $this->pagesCommitted($stateDir);

        $reporter = new ArtisanProgressReporter($this);
        $logger = new Logger(app('log')->driver(), app('events'));
        $orchestrator = new IndexBuildOrchestrator($stateDir, $outputDir, $hmacSecret, $language);
        $report = $orchestrator->build($intent, $items, $logger, $reporter, force: (bool) $this->option('force'));

        if (! $report->success) {
            if ($report->isMemoryAbort()) {
                return $this->answerMemoryAbort(
                    $stateDir,
                    $outputDir,
                    $pagesBefore,
                    $budget->profile(),
                    $this->option('chunk-size'),
                );
            }

            if ($report->error === 'index_only_complete') {
                // All pages indexed but the merge could not run in this process
                // (heap too fragmented). Finalize must run in a fresh worker
                // with a clean heap.
                $connection = (string) config('queue.default');

                if ($connection === 'sync') {
                    // No async worker exists on the sync connection — dispatching
                    // would merely re-run finalize inline in this same stressed
                    // process. Surface it as a failure with actionable guidance
                    // rather than silently reporting a half-built index.
                    $this->error(sprintf(
                        'All %d pages indexed (%d chunks) but the merge could not complete in this process '
                        .'(insufficient memory) and the queue connection is "sync" (no background worker). '
                        .'Increase the memory budget (--memory-budget) or configure an async queue so finalize '
                        .'can run in a fresh worker.',
                        $report->pagesProcessed,
                        $report->chunksWritten,
                    ));

                    return self::FAILURE;
                }

                // Async connection: hand finalize to a worker with a clean heap.
                // The index is NOT published until that worker runs, so this is
                // a deferred build, not a success.
                $this->line(sprintf(
                    'All %d pages indexed (%d chunks). Dispatching finalize to the "%s" queue...',
                    $report->pagesProcessed,
                    $report->chunksWritten,
                    $connection,
                ));
                FinalizeIndex::dispatch(
                    $stateDir,
                    $outputDir,
                    $hmacSecret,
                    $language,
                    $budget->profile(),
                    null,
                    $watermark,
                );

                $this->warn(sprintf(
                    'Index NOT yet published: the finalize job is queued on "%s" and must be drained by a worker '
                    .'(php artisan queue:work) before search reflects this build.',
                    $connection,
                ));

                $this->publishAssets();

                return self::DEFERRED;
            }

            $this->error('Index build failed: '.($report->error ?? 'Unknown error'));

            return self::FAILURE;
        }

        // A full build covers every tracked change recorded before it gathered.
        // Leaving the rows behind is what made scolta:status and
        // /api/scolta/v1/health report a pending_index backlog no build could
        // drain.
        $source->clearTracker($watermark);

        Cache::increment('scolta_expand_generation');
        $this->info(sprintf(
            'Index built: %d pages in %.3fs (%s peak RAM)',
            $report->pagesProcessed,
            $report->durationSeconds,
            $report->peakMemoryMb(),
        ));

        $this->publishAssets();

        return self::SUCCESS;
    }

    /**
     * Decide what a memory-yielding build in this process does next.
     *
     * Two callers reach this and must not do the same thing. A build an operator
     * started drives the chain that finishes it. A process invoked with --resume
     * is one segment of a chain some other process is already driving: the
     * orchestrator has recorded how this segment ended, so the segment reports
     * and returns, and the driver decides. --resume is the whole signal; nesting
     * a chain inside every segment would keep one bootstrapped Laravel kernel
     * alive per segment, which is exactly the memory the segmenting is buying
     * back, and it would put the segment cap under a counter that resets in
     * every child.
     *
     * @param  int  $pagesBefore  Pages the manifest credited before this process ran.
     *
     * @since 1.4.0
     *
     * @stability experimental
     */
    private function answerMemoryAbort(
        string $stateDir,
        string $outputDir,
        int $pagesBefore,
        ?string $memoryBudget,
        ?string $chunkSize,
    ): int {
        if ($this->option('resume')) {
            $this->error(sprintf(
                'Memory limit reached after %d pages committed. The build is incomplete and the index has not '
                .'been republished. Re-run `php artisan scolta:build --resume` to continue, or raise PHP '
                .'memory_limit (currently %s).',
                $this->pagesCommitted($stateDir),
                ini_get('memory_limit') ?: 'unknown',
            ));

            return self::FAILURE;
        }

        return $this->runResumeChain($stateDir, $outputDir, $pagesBefore, $memoryBudget, $chunkSize);
    }

    /**
     * Drive a memory-aborted build to its end in fresh processes, or fail.
     *
     * One process owns the chain: this one. It runs each segment in the
     * foreground, streams the child's output so an operator watches the build
     * rather than a log file, and reads the exit code — so `scolta:build` returns
     * only when the chain has actually ended, and returns SUCCESS only when an
     * index is published and verified on disk. The detached predecessor of this
     * method returned SUCCESS the moment it had launched a successor, which is
     * the same false success as calling a queued build built.
     *
     * The bounds are unchanged: a segment that commits nothing gets no successor,
     * and no build may use more than ResumeChainPolicy::DEFAULT_MAX_SEGMENTS processes. Because
     * the driver stays alive, the segment counter is a local variable here rather
     * than a flag on the successor's command line.
     *
     * @param  int  $pagesBefore  Pages the manifest credited before this process ran.
     *
     * @since 1.0.0 (bounded and made foreground in 1.4.0)
     *
     * @stability experimental
     */
    private function runResumeChain(
        string $stateDir,
        string $outputDir,
        int $pagesBefore,
        ?string $memoryBudget,
        ?string $chunkSize,
    ): int {
        // Resolved through the container so a test can substitute the boundary
        // that runs a child and drive the real loop against it.
        /** @var ResumeChain $chain */
        $chain = $this->laravel->make(ResumeChain::class);
        if ($this->lockOwner !== null) {
            $chain->env = [ResumeChain::LOCK_OWNER_ENV => $this->lockOwner];
        }
        $policy = new ResumeChainPolicy(ini_get('memory_limit') ?: null);

        $force = (bool) $this->option('force');
        $segment = 0;
        $pagesNow = $this->pagesCommitted($stateDir);
        // Segment 0 is the build this process just ran, and its outcome is the
        // StatusReport in hand: a memory abort. Only progress decides for it.
        $outcome = null;

        while (true) {
            $reason = $policy->failureReason($outcome, $pagesNow, $pagesBefore, $segment);
            if ($reason !== null) {
                $this->error($reason);

                return self::FAILURE;
            }

            $this->line(sprintf(
                'Memory pressure at %d pages committed (%d this segment). Continuing in a fresh process (segment %d of at most %d)...',
                $pagesNow,
                $pagesNow - $pagesBefore,
                $segment + 1,
                ResumeChainPolicy::DEFAULT_MAX_SEGMENTS,
            ));

            $pagesBefore = $pagesNow;
            $segment++;

            // Cleared before the segment starts, so a missing file after it exits
            // reads as "it died without reporting" rather than as the previous
            // segment's verdict.
            $this->clearSegmentOutcome($stateDir);

            $exitCode = $chain->runSegment($memoryBudget, $chunkSize, $force, function (string $buffer): void {
                $this->output->write($buffer);
            });

            if ($exitCode === null) {
                $this->error('Cannot auto-resume: artisan not found. The index has not been republished. Run: php artisan scolta:build --resume');

                return self::FAILURE;
            }

            if ($exitCode === self::SUCCESS) {
                // The segment that finished the build published the index and
                // bumped the expand generation itself. Verify rather than trust:
                // SUCCESS from here has to mean the index is live.
                try {
                    IndexBuildOrchestrator::verifyIndexComplete($outputDir);
                } catch (\Throwable $e) {
                    $this->error('Resume segment '.$segment.' reported success but no usable index was published: '.$e->getMessage());

                    return self::FAILURE;
                }

                $this->info(sprintf('Index built across %d resume segment(s).', $segment));

                return self::SUCCESS;
            }

            if ($exitCode === self::DEFERRED) {
                // The segment indexed every page but had to hand the merge to a
                // queue worker. The chain is over — nothing is left for another
                // segment to carry forward — but the index is not published until
                // that job runs, so this is deferred, not success and not failure.
                $this->warn(sprintf(
                    'Index NOT yet published: resume segment %d handed finalize to the queue and a worker '
                    .'(php artisan queue:work) must drain it before search reflects this build.',
                    $segment,
                ));

                return self::DEFERRED;
            }

            $outcome = $this->segmentOutcome($stateDir);
            $pagesNow = $this->pagesCommitted($stateDir);
        }
    }

    /**
     * How the segment that just exited reported it ended, or null if it never did.
     *
     * @return array<string, mixed>|null
     */
    private function segmentOutcome(string $stateDir): ?array
    {
        try {
            return (new BuildState($stateDir))->readOutcome();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Drop any outcome on disk so the next segment's silence reads as silence.
     */
    private function clearSegmentOutcome(string $stateDir): void
    {
        try {
            (new BuildState($stateDir))->clearOutcome();
        } catch (\Throwable) {
            // A state dir this cannot open is one the segment will fail on anyway.
        }
    }

    /**
     * Pages the shared build manifest records as committed so far.
     *
     * Read from BuildState rather than StatusReport::pagesProcessed, which is
     * cumulative on the memory-abort path and per-run elsewhere. That ambiguity
     * is what the unbounded chain was built on; the manifest has one meaning.
     */
    private function pagesCommitted(string $stateDir): int
    {
        try {
            return (new BuildState($stateDir))->getPagesProcessed();
        } catch (\Throwable) {
            // A state directory this cannot open is one the build failed on anyway.
            return 0;
        }
    }

    /**
     * Dispatch index build to the queue (opt-in via --queue).
     *
     * Delegates to QueueRebuildDispatcher — the same path the observer-driven
     * TriggerRebuild job uses — which streams content through ContentSource,
     * writes chunk payload files, checks the fingerprint, and dispatches
     * ProcessIndexChunk + FinalizeIndex jobs via Bus::chain().
     *
     * Without the dispatcher's $incremental: this command is a full build on
     * every path it has, and an operator who asked for one through the queue
     * asked for the same thing as one who asked for it inline.
     *
     * Honesty about whether the index is actually built depends on the
     * effective queue connection:
     *
     *  - On the `sync` connection the chain executes inline during dispatch(),
     *    so the index is built by the time this returns — verify it and report
     *    SUCCESS (or FAILURE if the inline merge failed).
     *  - On any asynchronous connection the jobs are only enqueued; the index
     *    is NOT built until a worker drains the chain. Returning SUCCESS here
     *    is the deploy-time false-success defect, so report DEFERRED with loud
     *    guidance instead.
     *
     * @since 0.2.0 (made connection-aware in 1.0.4)
     *
     * @stability experimental
     */
    private function dispatchToQueue(ContentSource $source, string $outputDir): int
    {
        $connection = (string) config('queue.default');
        $ranInline = $connection === 'sync';

        $this->info('Dispatching index build to queue...');

        $budget = $this->memoryBudget();

        try {
            // dispatch() initialises the build manifest under the cross-process
            // build lock, so a worker that drains the chain actually produces an
            // index — uniformly on every entry point (the lock makes the
            // manifest init safe against concurrent dispatch).
            $result = (new QueueRebuildDispatcher($source))->dispatch(
                $budget,
                (bool) $this->option('force'),
                resetLedger: (bool) $this->option('reset-ledger') || (bool) $this->option('restart'),
            );
        } catch (\Throwable $e) {
            // On the sync connection the chain runs inline during dispatch(); a
            // chunk/merge/finalize failure surfaces here. Treat it as a hard
            // build failure rather than letting it bubble out uncaught.
            $this->error('Index build failed: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($result['status'] === QueueRebuildDispatcher::STATUS_EMPTY) {
            $this->warn('No searchable content found. Check scolta.models config.');

            return self::SUCCESS;
        }

        if ($result['status'] === QueueRebuildDispatcher::STATUS_IN_PROGRESS) {
            // Another rebuild chain holds the cross-process build lock, so this
            // invocation built nothing. Report DEFERRED, not SUCCESS — a deploy
            // must not read "a build is happening elsewhere" as "built and live".
            $this->warn(
                'Index NOT built by this command: another rebuild is already in progress. '
                .'Wait for it to finish (or run `scolta:build` without --queue for a synchronous build).'
            );

            return self::DEFERRED;
        }

        $this->info('  Found '.$result['items'].' content items.');

        if ($result['status'] === QueueRebuildDispatcher::STATUS_UNCHANGED) {
            $this->info('Content unchanged. Index is up to date (use --force to rebuild).');

            return self::SUCCESS;
        }

        if ($ranInline) {
            // The chain executed inline on the sync connection: the index is
            // built. Verify it before claiming success.
            try {
                IndexBuildOrchestrator::verifyIndexComplete($outputDir);
            } catch (\Throwable $e) {
                $this->error('Index build failed: '.$e->getMessage());

                return self::FAILURE;
            }

            // The tracker was drained by FinalizeIndex, which ran inline as the
            // last job of the chain and is the one place that sees a queued build
            // land — on the sync connection and on an asynchronous one alike.

            // FinalizeIndex (which just ran inline) already bumped the expand
            // generation counter, so no increment is needed here.
            $this->info(sprintf(
                'Index built inline on the sync queue: %d item(s) (%d chunk(s)).',
                $result['items'],
                $result['chunks'],
            ));

            $this->publishAssets();

            return self::SUCCESS;
        }

        // Asynchronous connection: the jobs are only enqueued. The index is NOT
        // built yet, so this is NOT a success — return DEFERRED so deploy
        // pipelines never mistake "enqueued" for "built and live".
        $this->warn(sprintf(
            'Index NOT yet built: %d chunk(s) + finalize enqueued on the "%s" queue. '
            .'A worker (php artisan queue:work) must drain these jobs before search reflects this build. '
            .'For deploys that need a live index immediately, run `scolta:build` without --queue (synchronous, the default).',
            $result['chunks'],
            $connection,
        ));

        // Publish the front-end assets regardless — they are independent of the
        // index and the runtime endpoints need them whenever the index lands.
        $this->publishAssets();

        return self::DEFERRED;
    }

    private function publishAssets(): void
    {
        // Skip the publish when the published JS already matches the
        // package checksum — no point force-rewriting identical assets
        // (and bumping their mtime-based cache busters) on every build.
        if ((new AssetStatus)->areCurrent() === true) {
            $this->info('Scolta assets already current; skipping publish.');

            return;
        }

        $this->info('Publishing scolta assets...');
        $exitCode = Artisan::call('vendor:publish', [
            '--tag' => 'scolta-assets',
            '--force' => true,
        ]);
        if ($exitCode === 0) {
            $this->info('Assets published successfully.');
        } else {
            $this->warn('Asset publishing returned exit code '.$exitCode);
        }
    }
}
