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
     * BuildCommand::updateIncrementally() for why neither adapter uses it.
     *
     * Three outcomes per row:
     *
     *  - `upserts` — the record exists and both publish filters keep it. Unlike
     *    getChangedContent() this applies scopeSearchable() as well as
     *    shouldBeSearchable(), so a record sent to draft under a scope predicate
     *    leaves the index instead of entering it.
     *  - `deletes` — a publish filter now rejects the record, or it was tracked
     *    for deletion and is still readable (a soft delete). Its item id is exact.
     *  - `unresolved` — the record is gone from the database, so nothing can say
     *    which item ids it owned. The caller must run a full build, which derives
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
            $contentType = (string) $contentType;
            $ids = array_map(strval(...), $rows->pluck('content_id')->all());

            if (! $this->isIndexableModel($contentType)) {
                foreach ($ids as $id) {
                    $unresolved[] = $contentType.':'.$id;
                }

                continue;
            }

            /** @var Model $model */
            $model = new $contentType;
            $keyName = $model->getKeyName();
            $query = $model->newQuery()->whereIn($keyName, $ids);

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
                $deletes[] = $item->id;
            }

            foreach ($ids as $id) {
                if (! isset($seen[$id])) {
                    // A hard delete: the only description of the pages it owned
                    // is gone with the row.
                    $unresolved[] = $contentType.':'.$id;
                }
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
     * Get content IDs that have been deleted.
     *
     * These are Eloquent primary keys — the id space the binary path's HTML
     * export manifest uses, NOT the ContentItem id space the PHP index is keyed
     * by. See getTrackedChanges() for that mapping.
     *
     * @return string[] Content IDs to remove from the index.
     */
    public function getDeletedIds(): array
    {
        if (! $this->trackerAvailable()) {
            return [];
        }

        return ScoltaTracker::getPending('delete')
            ->pluck('content_id')
            ->all();
    }

    /**
     * Mark all tracked changes as processed after a successful build.
     *
     * Known gap, and a divergence from scolta-drupal: this drains the table,
     * where the Drupal worker deletes exactly the queue items it claimed before
     * gathering — "an item that arrived after collectChangeSet() claimed its
     * batch describes a change this build did not see, and deleting it would drop
     * that edit permanently" (ScoltaRebuildWorker::deleteClaimed()). A record
     * edited while a build is running therefore has its tracker row cleared here
     * without that edit having been indexed. The drain predates the PHP build
     * paths that now call this and affects the binary and export paths equally,
     * so it is not changed alongside them; the fix is to clear only rows whose
     * changed_at precedes the gather, in every caller at once.
     */
    public function clearTracker(): void
    {
        if (! $this->trackerAvailable()) {
            return;
        }

        ScoltaTracker::clearAll();
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
