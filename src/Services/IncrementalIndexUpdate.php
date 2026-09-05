<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Services;

use Illuminate\Log\Logger;
use Illuminate\Support\Facades\Cache;
use Tag1\Scolta\Index\BuildState;
use Tag1\Scolta\Index\IncrementalIndexUpdater;
use Tag1\Scolta\Index\IncrementalUpdateUnavailable;
use Tag1\Scolta\Index\MemoryBudget;

/**
 * Apply the tracked changes to the published index without rebuilding it.
 *
 * A different entry point from the full build, not a mode of it:
 * IncrementalIndexUpdater rewrites the fragments and chunks the changed pages
 * touch, reusing the ordinals the page-table ledger already assigned. Feeding a
 * filtered item stream to IndexBuildOrchestrator::build() would NOT be the same
 * thing: several stages there read "the build never yielded this page" as "the
 * source no longer has this page", so a full-scope build handed only the changed
 * pages publishes an index containing only those pages.
 *
 * ## Where this runs
 *
 * On the queued rebuild path, and nowhere else. A content save writes a
 * `scolta_tracker` row, `ScoltaObserver` debounces a `TriggerRebuild`, and
 * {@see QueueRebuildDispatcher::dispatch()} calls this before it considers
 * streaming the corpus — so the operation that fires on every edit is the cheap
 * one. That is scolta-drupal's split exactly: its entity hook queues a payload
 * that `ScoltaRebuildWorker::processItem()` answers with
 * `tryIncrementalUpdate()`, while `drush scolta:build` is always a full build.
 * `scolta:build` matches it; `--incremental` is a deprecated no-op.
 *
 * ## What it refuses, and what that costs
 *
 * Every refusal returns `applied: false` with a reason, and the caller runs a
 * full build: change tracking not installed, nothing pending, a change set too
 * large, no index to update against, an unresolvable tracked row, or the
 * library's own checks inside commit(). Same set, same order and near enough the
 * same wording as scolta-drupal's ScoltaRebuildWorker::tryIncrementalUpdate(),
 * which is the sibling implementation of this class. A fallback is correct but
 * slow — never wrong.
 *
 * ## Why the tracker, and not ChangeSetPlanner
 *
 * scolta-php ships ChangeSetPlanner, which derives a change set by comparing a
 * TimestampManifest against the page-table ledger. Nothing calls it — neither
 * this package nor scolta-drupal nor scolta-php itself. Both adapters answer
 * "what changed?" from the platform's own change events instead: here an
 * Eloquent observer writes a scolta_tracker row, there an entity hook queues a
 * payload carrying the entity id and the item ids it owns
 * (scolta.module::_scolta_auto_rebuild_check()). The tracker is therefore the
 * consistent design, not a divergence from one.
 *
 * The manifest could not stand in for it anyway. In scolta-drupal a
 * TimestampManifest is a *full build* cache — the gatherer replays unchanged
 * entities as CachedContentReference instead of loading their bodies — and the
 * incremental path there deliberately passes no manifest at all. Planning from
 * one also costs a scan of every published record on every run, where a change
 * event costs a row. What this package does not have is that full-build cache:
 * getPublishedContent() reloads every record on every full build. That is a real
 * gap, and a separate one.
 *
 * @since 1.4.0
 *
 * @stability experimental
 */
class IncrementalIndexUpdate
{
    public function __construct(private readonly ContentSource $source) {}

    /**
     * Try to apply the pending tracker rows to the published index.
     *
     * On success the rows this covered are drained — through the watermark read
     * before the change set was resolved, so an edit landing mid-update survives
     * for the next run — and the query-expansion generation counter is bumped,
     * exactly as a full build does when it lands.
     *
     * Nothing is published on the assets side and no chunk jobs are dispatched:
     * the update rewrites the live index in place. The caller is responsible for
     * holding whatever lock keeps a full build from running underneath it;
     * {@see QueueRebuildDispatcher} calls this inside its cross-process build
     * lock.
     *
     * `applied: false` always carries a `reason` and always means "run a full
     * build"; `applied: true` always carries a `summary` fit for the log.
     *
     * @param  MemoryBudget  $budget  Supplies the gzip level for the artifacts the
     *                                update rewrites, so an update and the full
     *                                build that wrote them compress alike.
     * @return array{applied: bool, reason: string|null, summary: string|null}
     *
     * @since 1.4.0
     *
     * @stability experimental
     */
    public function attempt(MemoryBudget $budget): array
    {
        // scolta-drupal has the same switch (scolta.settings:incremental.enabled)
        // and for the same reason: this path now runs unattended on every content
        // save, so an operator needs a way to turn it off without turning
        // auto-rebuild off.
        if (! config('scolta.incremental.enabled', true)) {
            return self::decline('Incremental updates are disabled (scolta.incremental.enabled).');
        }

        // An un-migrated app reads as "no pending changes" through every tracker
        // method, so say which of the two it is: an index nothing is recording
        // changes against is not up to date, it is unmonitored. The CLI answered
        // this by failing; an unattended rebuild cannot, because refusing to
        // build is how a site ends up with no index at all — so it says so and
        // rebuilds the whole corpus, which needs no change set.
        if (! $this->source->trackerAvailable()) {
            return self::decline(
                'Change tracking is unavailable: the scolta_tracker table does not exist, so no change set '
                .'can be derived. Rebuilding the whole corpus instead. Run php artisan migrate to get '
                .'incremental updates.'
            );
        }

        $pending = $this->source->getPendingCount();
        if ($pending === 0) {
            // Not "up to date": ScoltaObserver::afterBulkUpdate() and the
            // first-run auto-build both dispatch a rebuild with nothing tracked,
            // and both mean a full build. The full path's fingerprint check is
            // what decides whether that build changes anything.
            return self::decline('No tracked changes to apply.');
        }

        // Counted before the change set is resolved, because resolving it loads
        // every tracked record. Above the threshold a full build is cheaper and
        // is the only path with a bounded memory profile.
        //
        // scolta-drupal counts item ids and this counts tracker rows, which are
        // the same number here: toSearchableContent() returns one ContentItem per
        // record, where a Drupal node yields one page per translation.
        $threshold = (int) config('scolta.incremental.max_changed_items', 100);
        if ($threshold > 0 && $pending > $threshold) {
            return self::decline(sprintf(
                'Change set of %d items exceeds the incremental threshold of %d; falling back to a full rebuild.',
                $pending,
                $threshold,
            ));
        }

        $stateDir = config('scolta.state_dir', storage_path('app/scolta'));
        $outputDir = config('scolta.pagefind.output_dir', public_path('scolta-pagefind'));
        $language = config('scolta.ai_languages.0', 'en');

        // A manifest still 'building' is a full build that never finished. It
        // checkpointed hashes for pages the published index does not hold, and
        // the updater would trust them; the full build supersedes its journal.
        if ((new BuildState($stateDir))->shouldResume() !== null) {
            return self::decline(
                'A previous full build did not finish, so the page-table ledger may describe pages the '
                .'published index does not hold; rebuilding the whole corpus instead.'
            );
        }

        $logger = new Logger(app('log')->driver(), app('events'));
        $updater = new IncrementalIndexUpdater($stateDir, $outputDir, $language, null, $logger, $budget);

        if (! $updater->isAvailable()) {
            return self::decline(
                'No page-table ledger for the existing index; falling back to a full rebuild. '
                .'Incremental updates apply to an index, they do not create one.'
            );
        }

        // Read before the change set, so a row written while this runs carries a
        // later stamp and outlives the drain below.
        $watermark = $this->source->pendingWatermark();

        $changes = $this->source->getTrackedChanges();

        if ($changes['unresolved'] !== []) {
            return self::decline(sprintf(
                '%d tracked change(s) name records that are no longer readable (%s%s), so the index item ids '
                .'they own cannot be derived; falling back to a full rebuild. A full rebuild derives deletions '
                .'from the page-table ledger and does not need that mapping.',
                count($changes['unresolved']),
                implode(', ', array_slice($changes['unresolved'], 0, 3)),
                count($changes['unresolved']) > 3 ? ', …' : '',
            ));
        }

        foreach ($changes['upserts'] as $item) {
            $updater->stageUpsert($item);
        }
        foreach ($changes['deletes'] as $id) {
            $updater->stageDelete($id);
        }

        try {
            $result = $updater->commit();
        } catch (IncrementalUpdateUnavailable $e) {
            return self::decline('Incremental index update unavailable ('.$e->getMessage().'); falling back to a full rebuild.');
        }

        // Only now: the index that satisfies these tracker rows is on disk.
        $this->source->clearTracker($watermark);
        Cache::increment('scolta_expand_generation');

        return [
            'applied' => true,
            'reason' => null,
            'summary' => sprintf(
                'Index updated incrementally: %d page(s) updated, %d deleted, %d fragment(s) and %d chunk(s) '
                .'rewritten in %.3fs (tombstones %.1f%%).',
                $result->pagesUpdated,
                $result->pagesDeleted,
                $result->fragmentsWritten,
                $result->chunksRewritten,
                $result->durationSeconds,
                $result->tombstoneRatio * 100,
            ),
        ];
    }

    /**
     * @return array{applied: bool, reason: string|null, summary: string|null}
     */
    private static function decline(string $reason): array
    {
        return ['applied' => false, 'reason' => $reason, 'summary' => null];
    }
}
