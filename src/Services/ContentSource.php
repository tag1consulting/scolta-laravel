<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Services;

use Generator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Schema;
use Tag1\Scolta\Content\ContentSourceInterface;
use Tag1\Scolta\Export\ContentItem;
use Tag1\ScoltaLaravel\Models\ScoltaTracker;
use Tag1\ScoltaLaravel\Searchable;

/**
 * Laravel content source for Scolta indexing.
 *
 * This is where Laravel's Eloquent ORM shines. Content discovery is
 * a matter of querying models — the ORM handles relationships, scopes,
 * eager loading, and chunked iteration automatically.
 *
 * Compared to WordPress:
 *   - WP: WP_Query with post_type filter, apply_filters('the_content')
 *   - Laravel: Eloquent model with Searchable trait, toSearchableContent()
 *
 * The developer has full control over content rendering through the
 * toSearchableContent() method on their model. They can use Blade views,
 * markdown parsers, or raw HTML — whatever produces the best content
 * for search indexing.
 *
 * Memory management: We use generators (yield) and Eloquent's chunk()
 * method to keep memory flat. Same principle as WordPress's paginated
 * WP_Query, but with Laravel's cleaner API.
 */
class ContentSource implements ContentSourceInterface
{
    /**
     * Yield all published content as ContentItem objects.
     *
     * Iterates through all configured models, applying the searchable
     * scope, the per-record shouldBeSearchable() check, and converting
     * each to a ContentItem via the trait method.
     *
     * This is the single content-gathering path for ALL index builds —
     * the binary pipeline, the synchronous PHP indexer, and the queue
     * dispatch path all consume this generator, so the documented publish
     * filters (scopeSearchable + shouldBeSearchable) apply everywhere.
     *
     * @param  array<string, mixed>  $options
     * @return Generator<ContentItem>
     */
    public function getPublishedContent(array $options = []): Generator
    {
        $models = config('scolta.models', []);

        foreach ($models as $modelClass) {
            if (! class_exists($modelClass)) {
                logger()->warning("[scolta] Configured model class not found: {$modelClass}, skipping.");

                continue;
            }

            // Validate that the model uses the Searchable trait.
            if (! in_array(Searchable::class, class_uses_recursive($modelClass), true)) {
                logger()->warning("[scolta] Model {$modelClass} does not use the Searchable trait, skipping.");

                continue;
            }

            $model = new $modelClass;

            // Use the Searchable scope if available.
            $query = method_exists($model, 'scopeSearchable')
                ? $modelClass::searchable()
                : $modelClass::query();

            // lazy() keeps memory flat: it pages through the table 100
            // records at a time behind a generator, the equivalent of
            // WordPress's paginated WP_Query, freeing memory as it goes.
            foreach ($query->lazy(100) as $record) {
                if (! method_exists($record, 'toSearchableContent')) {
                    continue;
                }

                if (method_exists($record, 'shouldBeSearchable') && ! $record->shouldBeSearchable()) {
                    continue;
                }

                $item = $record->toSearchableContent();
                if ($item instanceof ContentItem) {
                    yield $item;
                }
            }
        }
    }

    /**
     * Yield changed content items from the tracker.
     *
     * Only processes items marked as 'index'. Deletions are handled
     * separately by getDeletedIds().
     *
     * Applies shouldBeSearchable() but not the scopeSearchable() query scope, so
     * a record the scope rejects still yields. getTrackedChanges() applies both.
     *
     * @return Generator<ContentItem>
     */
    public function getChangedContent(): Generator
    {
        if (! $this->trackerAvailable()) {
            return;
        }

        $pending = ScoltaTracker::getPending('index');

        // Group by content type for efficient querying.
        $grouped = $pending->groupBy('content_type');

        foreach ($grouped as $contentType => $records) {
            if (! class_exists($contentType)) {
                continue;
            }

            $ids = $records->pluck('content_id')->all();

            // Use lazy() for memory-efficient iteration with generators.
            // Can't yield from within a closure (->each()), so we iterate
            // with foreach instead — same efficiency, proper generator support.
            foreach ($contentType::whereIn((new $contentType)->getKeyName(), $ids)->lazy(100) as $record) {
                if (! method_exists($record, 'toSearchableContent')) {
                    continue;
                }

                if (method_exists($record, 'shouldBeSearchable') && ! $record->shouldBeSearchable()) {
                    continue;
                }

                $item = $record->toSearchableContent();
                if ($item instanceof ContentItem) {
                    yield $item;
                }
            }
        }
    }

    /**
     * Resolve the tracker's pending rows into index item ids.
     *
     * The tracker records an Eloquent primary key; the index is keyed by
     * ContentItem::$id. Two id spaces, and only the model instance maps between
     * them — IncrementalIndexUpdater::stageDelete() silently does nothing when
     * handed a tracker id, so every tracked row is turned back into a record and
     * asked. scolta-drupal solves the same problem by deriving the item ids in
     * the entity hook, from the one gatherer method that owns the rule
     * (ScoltaContentGatherer::itemIdsFor()); here the rule lives in application
     * code, inside the model's own toSearchableContent(), so the record itself is
     * the only thing that can be asked.
     *
     * scolta-php's ChangeSetPlanner is not the alternative it looks like. See
     * IncrementalIndexUpdate for why neither adapter uses it.
     *
     * Three outcomes per row:
     *
     *  - `upserts` — the record exists and both publish filters keep it. Unlike
     *    getChangedContent() this applies scopeSearchable() as well as
     *    shouldBeSearchable(), so a record sent to draft under a scope predicate
     *    leaves the index instead of entering it.
     *  - `deletes` — a publish filter now rejects the record, it was tracked for
     *    deletion and is still readable (a soft delete), or the observer recorded
     *    its item id before it went. Exact in every one of those cases.
     *  - `unresolved` — the record is gone and no item id was recorded: a row
     *    written before the item_id migration, or one whose toSearchableContent()
     *    threw mid-delete. The caller must run a full build, which derives
     *    deletions from the ledger and does not need this mapping.
     *
     * The whole change set is materialised, which is safe only because callers
     * gate on getPendingCount() against the incremental threshold first.
     *
     * @return array{upserts: list<ContentItem>, deletes: list<string>, unresolved: list<string>}
     *
     * @since 1.4.0
     *
     * @stability experimental
     */
    public function getTrackedChanges(): array
    {
        $upserts = [];
        $deletes = [];
        $unresolved = [];

        if (! $this->trackerAvailable()) {
            return ['upserts' => $upserts, 'deletes' => $deletes, 'unresolved' => $unresolved];
        }

        foreach (ScoltaTracker::getPending('index')->groupBy('content_type') as $contentType => $rows) {
            $contentType = (string) $contentType;
            $ids = array_map(strval(...), $rows->pluck('content_id')->all());

            if (! $this->isIndexableModel($contentType)) {
                // Nothing can map these to item ids, and a model dropped from
                // config or stripped of the trait may still own pages.
                foreach ($ids as $id) {
                    $unresolved[] = $contentType.':'.$id;
                }

                continue;
            }

            /** @var Model $model */
            $model = new $contentType;
            $keyName = $model->getKeyName();

            // Asked once rather than per record: the scope is a query predicate
            // and cannot be evaluated against a loaded model.
            $scoped = method_exists($model, 'scopeSearchable')
                ? $contentType::searchable()->whereIn($keyName, $ids)->pluck($keyName)->all()
                : $ids;
            $scoped = array_flip(array_map(strval(...), $scoped));

            $seen = [];

            foreach ($model->newQuery()->whereIn($keyName, $ids)->lazy(100) as $record) {
                if (! method_exists($record, 'toSearchableContent')) {
                    continue;
                }

                $item = $record->toSearchableContent();
                if (! $item instanceof ContentItem) {
                    continue;
                }

                $seen[(string) $record->getKey()] = true;

                $publishable = isset($scoped[(string) $record->getKey()])
                    && (! method_exists($record, 'shouldBeSearchable') || $record->shouldBeSearchable());

                if ($publishable) {
                    $upserts[] = $item;
                } else {
                    $deletes[] = $item->id;
                }
            }

            foreach ($ids as $id) {
                if (! isset($seen[$id])) {
                    $unresolved[] = $contentType.':'.$id;
                }
            }
        }

        foreach (ScoltaTracker::getPending('delete')->groupBy('content_type') as $contentType => $rows) {
            $resolved = $this->resolveDeletedRows((string) $contentType, $rows);

            foreach ($resolved['ids'] as $id) {
                $deletes[] = $id;
            }
            foreach ($resolved['unresolved'] as $reference) {
                $unresolved[] = $reference;
            }
        }

        return [
            'upserts' => $upserts,
            'deletes' => array_values(array_unique($deletes)),
            'unresolved' => $unresolved,
        ];
    }

    /**
     * Whether a tracked class name is still a model this package can index.
     *
     * The same two conditions getPublishedContent() logs and skips on. Here a
     * failure means a full build, not a dropped row.
     */
    private function isIndexableModel(string $contentType): bool
    {
        return class_exists($contentType)
            && in_array(Searchable::class, class_uses_recursive($contentType), true);
    }

    /**
     * Get the index item ids of content that has been deleted.
     *
     * The interface asks for "content IDs to remove from the index", and the
     * index — export manifest, exported HTML, page-table ledger — is keyed by
     * ContentItem::$id. So that is what this returns, not the Eloquent primary
     * keys the tracker stores in content_id and that this used to return.
     *
     * Rows this cannot map are dropped from the result. Use getDeletedItemIds()
     * to see them; every caller in this package does.
     *
     * Rows this cannot map are dropped from the result. Use
     * getDeletedItemIds() to see them: every caller in this package does,
     * because a deletion that cannot be located is exactly the thing that must
     * not pass silently.
     *
     * @return string[] Item IDs to remove from the index.
     */
    public function getDeletedIds(): array
    {
        return $this->getDeletedItemIds()['ids'];
    }

    /**
     * Resolve the pending delete rows into index item ids, and say what failed.
     *
     * Three sources of an answer, tried in order:
     *
     *  - `item_id` on the tracker row, written by ScoltaObserver when it recorded
     *    the deletion. The only exact answer for a hard delete, because it was
     *    taken while the record still existed.
     *  - the record itself, reloaded with the soft-delete scope lifted, since a
     *    trashed record still knows the item id it published under. Covers rows
     *    predating the item_id migration and unpublish transitions.
     *  - nothing: the record is gone and no id was recorded. Reported in
     *    `unresolved` as "Class:key", never guessed at — a bare primary key
     *    handed to deleteById() or stageDelete() matches nothing and is answered
     *    by doing nothing, which is how this stayed invisible.
     *
     * An unresolved row is a one-shot warning, not a retry: the run that reports
     * it goes on to clear the tracker, so only a full build removes the page.
     *
     * @return array{ids: list<string>, unresolved: list<string>}
     *
     * @since 1.4.0
     *
     * @stability experimental
     */
    public function getDeletedItemIds(): array
    {
        $ids = [];
        $unresolved = [];

        if (! $this->trackerAvailable()) {
            return ['ids' => $ids, 'unresolved' => $unresolved];
        }

        foreach (ScoltaTracker::getPending('delete')->groupBy('content_type') as $contentType => $rows) {
            $resolved = $this->resolveDeletedRows((string) $contentType, $rows);

            foreach ($resolved['ids'] as $id) {
                $ids[] = $id;
            }
            foreach ($resolved['unresolved'] as $reference) {
                $unresolved[] = $reference;
            }
        }

        return ['ids' => array_values(array_unique($ids)), 'unresolved' => $unresolved];
    }

    /**
     * Map one content type's pending delete rows onto item ids.
     *
     * The single implementation of the tracker-key → item-id mapping for
     * deletions. getDeletedItemIds() and getTrackedChanges() both go through it,
     * so the binary export path and the PHP incremental path cannot disagree.
     *
     * @param  iterable<ScoltaTracker>  $rows
     * @return array{ids: list<string>, unresolved: list<string>}
     */
    private function resolveDeletedRows(string $contentType, iterable $rows): array
    {
        $ids = [];
        $unresolved = [];
        $needsLookup = [];

        foreach ($rows as $row) {
            $recorded = $row->item_id;

            if (is_string($recorded) && $recorded !== '') {
                $ids[] = $recorded;

                continue;
            }

            $needsLookup[] = (string) $row->content_id;
        }

        if ($needsLookup === []) {
            return ['ids' => $ids, 'unresolved' => $unresolved];
        }

        if (! $this->isIndexableModel($contentType)) {
            // Nothing left to ask, and a model dropped from config or stripped
            // of the trait may still own exported pages. Reported, not skipped.
            foreach ($needsLookup as $key) {
                $unresolved[] = $contentType.':'.$key;
            }

            return ['ids' => $ids, 'unresolved' => $unresolved];
        }

        /** @var Model $model */
        $model = new $contentType;
        $keyName = $model->getKeyName();
        $query = $model->newQuery()->whereIn($keyName, $needsLookup);

        // A soft-deleted row is still readable, and the trashed record still
        // knows the item id it published under.
        if (in_array(SoftDeletes::class, class_uses_recursive($contentType), true)) {
            $query = $query->withoutGlobalScope(SoftDeletingScope::class);
        }

        $seen = [];

        foreach ($query->lazy(100) as $record) {
            if (! method_exists($record, 'toSearchableContent')) {
                continue;
            }

            $item = $record->toSearchableContent();
            if (! $item instanceof ContentItem) {
                continue;
            }

            $seen[(string) $record->getKey()] = true;
            $ids[] = $item->id;
        }

        foreach ($needsLookup as $key) {
            if (! isset($seen[$key])) {
                // A hard delete recorded before the item_id column existed: the
                // only description of the pages it owned went with the row.
                $unresolved[] = $contentType.':'.$key;
            }
        }

        return ['ids' => $ids, 'unresolved' => $unresolved];
    }

    /**
     * The `changed_at` of the newest pending tracker row, or null when none are.
     *
     * Read *before* a build gathers its content and handed back to
     * {@see clearTracker()} when that build lands, so the drain covers exactly
     * the rows the build could have seen. A row written while the build ran
     * carries a later `changed_at` — `ScoltaTracker::track()` upserts, so even
     * re-editing a record already in the change set moves its stamp forward —
     * and survives the drain to be picked up by the next run.
     *
     * This is the Laravel spelling of scolta-drupal's
     * `ScoltaRebuildWorker::deleteClaimed()`: "an item that arrived after
     * collectChangeSet() claimed its batch describes a change this build did not
     * see, and deleting it would drop that edit permanently". A watermark rather
     * than a claimed-id list because the queued path has to carry it through a
     * job payload, and an id list there is the payload-cap hazard
     * {@see QueueRebuildDispatcher} exists to avoid.
     *
     * The remaining window is the timestamp's own resolution: an edit landing in
     * the same second as the newest row the build saw is drained with it. That is
     * seconds where the un-watermarked drain was the whole length of the build.
     *
     * @since 1.4.0
     *
     * @stability experimental
     */
    public function pendingWatermark(): ?string
    {
        if (! $this->trackerAvailable()) {
            return null;
        }

        $watermark = ScoltaTracker::query()->max('changed_at');

        return is_scalar($watermark) ? (string) $watermark : null;
    }

    /**
     * Mark tracked changes as processed after a successful build.
     *
     * @param  string|null  $through  Drain only rows stamped at or before this
     *                                {@see pendingWatermark()}. Null drains the
     *                                whole table, which is what the binary build
     *                                and `scolta:export` still do: a record edited
     *                                while one of those runs has its row cleared
     *                                without the edit having been indexed. Those
     *                                paths are unchanged here, not fixed; every
     *                                path that gained a drain in 1.4.0 passes a
     *                                watermark.
     */
    public function clearTracker(?string $through = null): void
    {
        if (! $this->trackerAvailable()) {
            return;
        }

        if ($through === null) {
            ScoltaTracker::clearAll();

            return;
        }

        ScoltaTracker::clearThrough($through);
    }

    /**
     * Whether the scolta_tracker table exists.
     *
     * Public so a caller can tell "nothing changed" from "nothing is being
     * recorded": every read below returns an empty change set when the table
     * is absent, and reporting that as an up-to-date index would be a lie.
     *
     * Change tracking is optional plumbing installed by a migration, so every
     * tracker read and write goes through this and an un-migrated app gets an
     * empty change set rather than a failed build. Same guard StatusCommand and
     * HealthController already apply.
     */
    public function trackerAvailable(): bool
    {
        return Schema::hasTable('scolta_tracker');
    }

    /**
     * Get total published content count across all configured models.
     *
     * @param  array<string, mixed>  $options
     */
    public function getTotalCount(array $options = []): int
    {
        $models = config('scolta.models', []);
        $count = 0;

        foreach ($models as $modelClass) {
            if (! class_exists($modelClass)) {
                continue;
            }

            $model = new $modelClass;
            $query = method_exists($model, 'scopeSearchable')
                ? $modelClass::searchable()
                : $modelClass::query();

            $count += $query->count();
        }

        return $count;
    }

    /**
     * Get the count of items pending reindexing.
     */
    public function getPendingCount(): int
    {
        if (! $this->trackerAvailable()) {
            return 0;
        }

        return ScoltaTracker::getPendingCount();
    }
}
