<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Tests\Jobs;

use Illuminate\Console\Command;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;
use Tag1\Scolta\Config\MemoryBudgetConfig;
use Tag1\Scolta\Export\ContentExporter;
use Tag1\Scolta\Index\IndexBuildOrchestrator;
use Tag1\Scolta\Index\MemoryBudget;
use Tag1\Scolta\Index\PageTableLedger;
use Tag1\Scolta\Storage\FilesystemDriver;
use Tag1\ScoltaLaravel\Jobs\FinalizeIndex;
use Tag1\ScoltaLaravel\Jobs\ProcessIndexChunk;
use Tag1\ScoltaLaravel\Jobs\TriggerRebuild;
use Tag1\ScoltaLaravel\Models\ScoltaTracker;
use Tag1\ScoltaLaravel\Observers\ScoltaObserver;
use Tag1\ScoltaLaravel\ScoltaServiceProvider;
use Tag1\ScoltaLaravel\Services\ContentSource;
use Tag1\ScoltaLaravel\Services\QueueRebuildDispatcher;
use Tag1\ScoltaLaravel\Tests\Support\SearchablePost;

/**
 * The rebuild a content save queues is incremental, and it drains the tracker.
 *
 * Two defects, one root. The automatic path — model save, `ScoltaObserver`,
 * `TriggerRebuild`, `QueueRebuildDispatcher` — streamed
 * `ContentSource::getPublishedContent()`, the entire corpus, on every edit,
 * while the cheap in-place update was reachable only by typing
 * `scolta:build --incremental`. So the operation that fires on every content
 * edit was the most expensive one the package has, and the one an operator had
 * to remember was the cheap one. scolta-drupal has never had it that way round:
 * its queue worker tries an incremental update and `drush scolta:build` is
 * always full.
 *
 * And nothing on that path ever called `clearTracker()`, so `scolta_tracker`
 * grew forever on the most common auto-rebuild configuration and
 * `scolta:status` and `/api/scolta/v1/health` reported a `pending_index`
 * backlog no rebuild could drain.
 *
 * Everything here drives the real entry points — a model save with the observer
 * attached, or `TriggerRebuild`/`QueueRebuildDispatcher` directly — and asserts
 * on the published index, the page-table ledger, the tracker table and the jobs
 * actually dispatched. Never on the text of the source: an assertion that
 * grepped `TriggerRebuild.php` for "incremental" would have passed throughout
 * the entire life of both defects.
 */
class QueuedIncrementalRebuildTest extends TestCase
{
    private string $stateDir;

    private string $outputDir;

    /** @var array<string, int> */
    private array $postIds = [];

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

        $this->stateDir = storage_path('framework/testing/scolta-queued-incremental-state');
        $this->outputDir = storage_path('framework/testing/scolta-queued-incremental-output');
        File::deleteDirectory($this->stateDir);
        File::deleteDirectory($this->outputDir);

        config([
            'scolta.state_dir' => $this->stateDir,
            'scolta.pagefind.output_dir' => $this->outputDir,
            // Seeding must not queue rebuilds of its own; each test turns this
            // on when it wants the automatic path.
            'scolta.auto_rebuild' => false,
            // The sync connection runs Bus::chain() inline, so a fallback to the
            // full build actually publishes an index inside the test.
            'queue.default' => 'sync',
        ]);

        Cache::lock(QueueRebuildDispatcher::BUILD_LOCK)->forceRelease();
        Cache::forget('scolta_rebuild_scheduled');

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

        // Registered by hand: the provider wires observers at boot and the model
        // config is only set afterwards here. The tests that drive the automatic
        // path want the real observer, because what it records is half of it.
        SearchablePost::flushEventListeners();
        SearchablePost::observe(ScoltaObserver::class);

        foreach (['Alpha', 'Beta', 'Gamma'] as $title) {
            $post = SearchablePost::create([
                'title' => $title,
                'body' => "Body of {$title}. ".str_repeat('Plenty of searchable body text. ', 20),
                'published' => true,
                'unlisted' => false,
            ]);
            $this->postIds[$title] = (int) $post->getKey();
        }

        ScoltaTracker::clearAll();
    }

    protected function tearDown(): void
    {
        SearchablePost::flushEventListeners();
        Schema::dropIfExists('searchable_posts');
        File::deleteDirectory($this->stateDir);
        File::deleteDirectory($this->outputDir);
        File::deleteDirectory(public_path('vendor/scolta'));
        Cache::lock(QueueRebuildDispatcher::BUILD_LOCK)->forceRelease();
        ScoltaTracker::flushSchemaCache();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // The automatic path, end to end from a model save.
    // -----------------------------------------------------------------

    public function test_a_content_edit_updates_the_index_in_place_instead_of_rebuilding_it(): void
    {
        $this->publishInitialIndex();

        $alphaHash = $this->ledgerContentHash($this->itemId('Alpha'));
        $betaHash = $this->ledgerContentHash($this->itemId('Beta'));

        // Only the chain jobs are faked, so TriggerRebuild still dispatches and
        // runs for real on the sync connection. A full rebuild would show up as
        // a dispatched ProcessIndexChunk and would leave the ledger untouched
        // (the faked chain writes nothing); an in-place update dispatches no job
        // and rewrites the edited page itself. The two are not confusable.
        Bus::fake([ProcessIndexChunk::class, FinalizeIndex::class]);
        config(['scolta.auto_rebuild' => true]);

        SearchablePost::query()->findOrFail($this->postIds['Beta'])->update(['title' => 'Beta Revised']);

        // A content edit must not stream the whole corpus through the chain.
        Bus::assertNotDispatched(ProcessIndexChunk::class);
        Bus::assertNotDispatched(FinalizeIndex::class);

        $this->assertNotSame($betaHash, $this->ledgerContentHash($this->itemId('Beta')),
            'The edited page must actually have been rewritten by the queued update.');
        $this->assertSame($alphaHash, $this->ledgerContentHash($this->itemId('Alpha')),
            'and no page it did not touch.');

        // The partial-scope trap: an update that fed the changed page to the
        // full build path would publish an index holding only that page.
        $this->assertSame(3, $this->ledgerLiveCount());
        $this->assertIndexVerifies();
    }

    public function test_a_content_edit_drains_the_tracker_rows_it_covered(): void
    {
        $this->publishInitialIndex();

        config(['scolta.auto_rebuild' => true]);

        SearchablePost::query()->findOrFail($this->postIds['Beta'])->update(['title' => 'Beta Revised']);

        $this->assertSame(0, ScoltaTracker::query()->count(),
            'The queued path never cleared the tracker, so pending_index grew forever on the most '
            .'common auto-rebuild configuration.');
    }

    public function test_an_unpublished_record_leaves_the_index_on_the_automatic_path(): void
    {
        $this->publishInitialIndex();

        config(['scolta.auto_rebuild' => true]);

        // shouldBeSearchable() filters on `unlisted`, so the observer records a
        // delete with the item id in hand. The queued update has to apply it.
        SearchablePost::query()->findOrFail($this->postIds['Gamma'])->update(['unlisted' => true]);

        $this->assertSame(2, $this->ledgerLiveCount());
        $this->assertNotContains($this->itemId('Gamma'), $this->ledgerItemIds());
        $this->assertSame(0, ScoltaTracker::query()->count());
        $this->assertIndexVerifies();
    }

    public function test_a_forced_rebuild_skips_the_update_and_streams_the_corpus(): void
    {
        $this->publishInitialIndex();

        $this->track('Beta', 'index');

        // POST /api/scolta/v1/rebuild-now with force=true is a request for the
        // rebuild, not for the cheapest way to the same state.
        (new TriggerRebuild(force: true))->handle(app(QueueRebuildDispatcher::class));

        $this->assertSame(3, $this->publishedPageCount());
        $this->assertSame(0, ScoltaTracker::query()->count(),
            'A forced full rebuild covers every tracked change and must drain them too.');
        $this->assertIndexVerifies();
    }

    // -----------------------------------------------------------------
    // The full queued rebuild, which is where every fallback lands.
    // -----------------------------------------------------------------

    public function test_a_full_queued_rebuild_drains_the_tracker(): void
    {
        $this->publishInitialIndex();

        $this->track('Beta', 'index');
        $this->assertSame(1, ScoltaTracker::query()->count());

        $result = $this->rebuild(force: true, incremental: false);

        $this->assertSame(QueueRebuildDispatcher::STATUS_DISPATCHED, $result['status']);
        $this->assertSame(0, ScoltaTracker::query()->count(),
            'FinalizeIndex is the one job that sees a queued build land, so it is where the drain belongs.');
    }

    public function test_a_failed_queued_rebuild_leaves_the_tracker_alone(): void
    {
        $this->publishInitialIndex();
        $this->track('Beta', 'index');

        // A finalize that never publishes an index has satisfied nothing. Run
        // the job with no committed chunks: it throws, and the rows must survive.
        $watermark = app(ContentSource::class)->pendingWatermark();
        $this->assertNotNull($watermark);

        try {
            (new FinalizeIndex(
                $this->stateDir.'/nonexistent',
                $this->outputDir,
                null,
                'en',
                'conservative',
                null,
                $watermark,
            ))->handle();
            $this->fail('A finalize with no chunks must fail rather than report a build.');
        } catch (\Throwable) {
            // Expected.
        }

        $this->assertSame(1, ScoltaTracker::query()->count());
    }

    public function test_an_unchanged_corpus_still_drains_the_rows_that_asked_for_the_rebuild(): void
    {
        $this->publishInitialIndex();

        // Nothing on the orchestrator path writes `.scolta-state` — only the
        // older PhpIndexer does — so the fingerprint file has to be laid down
        // here, through the dispatcher's own public fingerprint helpers rather
        // than a second copy of the rule. That is also the finding: this branch
        // is currently unreachable from a build, and the drain is here so that
        // stops being true the moment the state file comes back.
        $this->writeFingerprintState();

        // A save that changed nothing the index holds: the fingerprint matches,
        // no chain is dispatched, and the row is satisfied all the same. Left
        // behind it would be permanent, because every later rebuild takes this
        // same branch.
        $this->track('Beta', 'index');

        $result = $this->rebuild(force: false, incremental: false);

        $this->assertSame(QueueRebuildDispatcher::STATUS_UNCHANGED, $result['status']);
        $this->assertSame(0, ScoltaTracker::query()->count());
    }

    // -----------------------------------------------------------------
    // The refusals. Each one ends in a full rebuild rather than in a lie.
    // -----------------------------------------------------------------

    public function test_without_a_published_index_it_falls_back_to_a_full_rebuild(): void
    {
        // No prior build: the first-run condition, and the one an incremental
        // update cannot serve. Updates apply to an index, they do not create one.
        $this->track('Alpha', 'index');

        $result = $this->rebuild();

        $this->assertSame(QueueRebuildDispatcher::STATUS_DISPATCHED, $result['status']);
        $this->assertStringContainsString('No page-table ledger', $this->declineReason());
        $this->assertSame(3, $this->publishedPageCount());
        $this->assertIndexVerifies();
        $this->assertSame(0, ScoltaTracker::query()->count());
    }

    public function test_a_change_set_over_the_threshold_falls_back_to_a_full_rebuild(): void
    {
        $this->publishInitialIndex();

        config(['scolta.incremental.max_changed_items' => 2]);
        foreach (['Alpha', 'Beta', 'Gamma'] as $title) {
            $this->track($title, 'index');
        }

        $result = $this->rebuild();

        $this->assertSame(QueueRebuildDispatcher::STATUS_DISPATCHED, $result['status']);
        $this->assertStringContainsString(
            'Change set of 3 items exceeds the incremental threshold of 2',
            $this->declineReason(),
        );
        $this->assertSame(3, $this->publishedPageCount());
        $this->assertSame(0, ScoltaTracker::query()->count());
    }

    public function test_an_unresolvable_tracked_row_falls_back_to_a_full_rebuild(): void
    {
        $this->publishInitialIndex();

        // The tracker holds an Eloquent key; the index is keyed by the id
        // toSearchableContent() invented. With the record gone and no item id
        // recorded, nothing maps between them, and stageDelete() answers an id it
        // does not hold by doing nothing at all — so guessing would report a
        // deletion that never happened. A full rebuild derives deletions from the
        // ledger and needs no mapping.
        $gamma = $this->postIds['Gamma'];
        $this->track('Gamma', 'delete');
        SearchablePost::withoutEvents(function () use ($gamma) {
            SearchablePost::query()->whereKey($gamma)->delete();
        });

        $result = $this->rebuild();

        $this->assertSame(QueueRebuildDispatcher::STATUS_DISPATCHED, $result['status']);
        $this->assertStringContainsString('no longer readable', $this->declineReason());
        $this->assertSame(2, $this->publishedPageCount(),
            'The full-rebuild fallback is what actually removes the deleted page — and it has to get '
            .'there, not throw. The chunked chain does not maintain the page-table ledger, so a ledger '
            .'left over from an earlier build made this exact case fail the integrity check.');
        $this->assertIndexVerifies();
        $this->assertSame(0, ScoltaTracker::query()->count());
    }

    public function test_an_updater_refusal_falls_back_to_a_full_rebuild(): void
    {
        $this->publishInitialIndex();

        // The updater's own check: without the previous token data it cannot
        // locate an edited page's stale postings, and the merge resolves a
        // duplicate ordinal by last-write-wins — so leaving them would corrupt
        // the index silently rather than fail. Deleting the token cache is what
        // an over-eager `scolta:cleanup` or a wiped state dir looks like.
        File::delete($this->stateDir.'/token-cache-manifest.php');
        File::deleteDirectory($this->stateDir.'/token-cache');

        SearchablePost::withoutEvents(function () {
            SearchablePost::query()->whereKey($this->postIds['Beta'])->update(['title' => 'Beta Revised']);
        });
        $this->track('Beta', 'index');

        $result = $this->rebuild();

        $this->assertSame(QueueRebuildDispatcher::STATUS_DISPATCHED, $result['status']);
        $this->assertStringContainsString('Incremental index update unavailable', $this->declineReason());
        $this->assertSame(3, $this->publishedPageCount());
        $this->assertIndexVerifies();
        $this->assertSame(0, ScoltaTracker::query()->count());
    }

    public function test_without_the_tracker_table_it_says_so_and_rebuilds(): void
    {
        $this->publishInitialIndex();

        Schema::dropIfExists('scolta_tracker');

        $result = $this->rebuild();

        // An un-migrated app reads as "no pending changes" through every tracker
        // method. The CLI answers that by failing; an unattended rebuild cannot,
        // because refusing to build is how a site ends up with no index — so it
        // rebuilds the corpus, which needs no change set, and says why in the log.
        $this->assertSame(QueueRebuildDispatcher::STATUS_DISPATCHED, $result['status']);
        $this->assertStringContainsString(
            'the scolta_tracker table does not exist',
            $this->declineReason(),
            'A rebuild that silently forgot change tracking was installed would be the wrong kind of quiet.',
        );
        $this->assertIndexVerifies();
    }

    public function test_the_config_switch_turns_the_update_off(): void
    {
        $this->publishInitialIndex();

        config(['scolta.incremental.enabled' => false]);
        $this->track('Beta', 'index');

        $result = $this->rebuild();

        $this->assertSame(QueueRebuildDispatcher::STATUS_DISPATCHED, $result['status']);
        $this->assertStringContainsString('Incremental updates are disabled', $this->declineReason());
        $this->assertSame(0, ScoltaTracker::query()->count());
    }

    public function test_nothing_pending_still_rebuilds_rather_than_reporting_up_to_date(): void
    {
        $this->publishInitialIndex();

        // ScoltaObserver::afterBulkUpdate() dispatches a rebuild with nothing
        // tracked, because a query-builder mass update fires no model events.
        // Reading an empty change set as "up to date" there would make the one
        // documented escape hatch for bulk edits do nothing at all.
        $result = $this->rebuild();

        $this->assertNotSame(QueueRebuildDispatcher::STATUS_UPDATED, $result['status']);
        $this->assertStringContainsString('No tracked changes to apply', $this->declineReason());
    }

    // -----------------------------------------------------------------
    // The drain window.
    // -----------------------------------------------------------------

    public function test_a_change_recorded_after_the_watermark_survives_the_drain(): void
    {
        $source = app(ContentSource::class);

        $this->track('Alpha', 'index');
        $watermark = $source->pendingWatermark();
        $this->assertNotNull($watermark);

        // The row a build never saw. Draining the whole table — which is what
        // every drain in this package did before — would delete this edit's only
        // record of itself, and nothing would ever index it.
        ScoltaTracker::query()->create([
            'content_id' => (string) $this->postIds['Beta'],
            'content_type' => SearchablePost::class,
            'action' => 'index',
            'changed_at' => now()->addMinute(),
        ]);

        $source->clearTracker($watermark);

        $this->assertSame(
            [(string) $this->postIds['Beta']],
            ScoltaTracker::query()->pluck('content_id')->all(),
        );
    }

    // -----------------------------------------------------------------
    // Helpers.
    // -----------------------------------------------------------------

    /**
     * Publish a full index the way an operator would, and start from a clean
     * tracker so each test's change set is the one it describes.
     */
    private function publishInitialIndex(): void
    {
        $this->artisan('scolta:build')->assertExitCode(Command::SUCCESS);
        $this->assertSame(3, $this->ledgerLiveCount());
        ScoltaTracker::clearAll();
    }

    /**
     * @return array{status: string, items: int, chunks: int}
     */
    private function rebuild(bool $force = false, bool $incremental = true): array
    {
        $this->captureLog();

        return app(QueueRebuildDispatcher::class)->dispatch($this->budget(), $force, $incremental);
    }

    /** @var list<string> */
    private array $logged = [];

    private function captureLog(): void
    {
        $this->logged = [];
        Log::listen(function (object $event): void {
            if (property_exists($event, 'message')) {
                $this->logged[] = (string) $event->message;
            }
        });
    }

    /**
     * The reason the dispatcher logged for falling through to a full rebuild.
     */
    private function declineReason(): string
    {
        return implode("\n", $this->logged);
    }

    private function budget(): MemoryBudget
    {
        return MemoryBudgetConfig::fromCliAndConfig(
            null,
            null,
            fn () => ['profile' => 'conservative', 'chunk_size' => null],
        );
    }

    private function track(string $title, string $action): void
    {
        ScoltaTracker::track((string) $this->postIds[$title], SearchablePost::class, $action);
    }

    private function itemId(string $title): string
    {
        return 'searchable_posts-'.$this->postIds[$title];
    }

    /**
     * Pages in the published index, counted from the fragments on disk.
     *
     * The page-table ledger cannot answer this after a queued rebuild: the
     * chunked chain discards it, because it does not maintain one.
     */
    private function publishedPageCount(): int
    {
        return count(File::glob($this->outputDir.'/pagefind/fragment/*.pf_fragment'));
    }

    /**
     * Lay down the corpus fingerprint the dispatcher compares against.
     */
    private function writeFingerprintState(): void
    {
        $entries = [];
        foreach ((new ContentExporter($this->outputDir))->filterItems(app(ContentSource::class)->getPublishedContent()) as $item) {
            $entries[] = QueueRebuildDispatcher::fingerprintEntry($item);
        }

        File::put($this->outputDir.'/.scolta-state', QueueRebuildDispatcher::fingerprintFromEntries($entries));
    }

    private function assertIndexVerifies(): void
    {
        try {
            IndexBuildOrchestrator::verifyIndexComplete($this->outputDir);
        } catch (\Throwable $e) {
            $this->fail('The published index must still verify: '.$e->getMessage());
        }
    }

    private function ledger(): PageTableLedger
    {
        return new PageTableLedger($this->stateDir, new FilesystemDriver);
    }

    private function ledgerContentHash(string $itemId): string
    {
        return $this->ledger()->contentHashFor($itemId);
    }

    private function ledgerLiveCount(): int
    {
        return $this->ledger()->liveCount();
    }

    /**
     * @return list<string>
     */
    private function ledgerItemIds(): array
    {
        $ids = [];
        foreach ($this->ledger()->rowsByOrdinal() as $row) {
            $ids[] = (string) $row['id'];
        }
        sort($ids);

        return $ids;
    }
}
