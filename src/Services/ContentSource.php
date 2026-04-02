<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Services;

use Generator;
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
     * scope and converting each to a ContentItem via the trait method.
     *
     * @return Generator<ContentItem>
     */
    public function getPublishedContent(array $options = []): Generator
    {
        $models = config('scolta.models', []);

        foreach ($models as $modelClass) {
            if (!class_exists($modelClass)) {
                continue;
            }

            // Validate that the model uses the Searchable trait.
            if (!in_array(Searchable::class, class_uses_recursive($modelClass), true)) {
                logger()->warning("[scolta] Model {$modelClass} does not use the Searchable trait, skipping.");
                continue;
            }

            $model = new $modelClass();

            // Use the Searchable scope if available.
            $query = method_exists($model, 'scopeSearchable')
                ? $modelClass::searchable()
                : $modelClass::query();

            // Chunk to keep memory flat. Laravel's chunk() method is the
            // equivalent of WordPress's paginated WP_Query — it processes
            // N records at a time, freeing memory between chunks.
            foreach ($query->lazy(100) as $record) {
                if (!method_exists($record, 'toSearchableContent')) {
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
     * @return Generator<ContentItem>
     */
    public function getChangedContent(): Generator
    {
        $pending = ScoltaTracker::getPending('index');

        // Group by content type for efficient querying.
        $grouped = $pending->groupBy('content_type');

        foreach ($grouped as $contentType => $records) {
            if (!class_exists($contentType)) {
                continue;
            }

            $ids = $records->pluck('content_id')->all();

            // Use lazy() for memory-efficient iteration with generators.
            // Can't yield from within a closure (->each()), so we iterate
            // with foreach instead — same efficiency, proper generator support.
            foreach ($contentType::whereIn((new $contentType())->getKeyName(), $ids)->lazy(100) as $record) {
                if (!method_exists($record, 'toSearchableContent')) {
                    continue;
                }

                if (method_exists($record, 'shouldBeSearchable') && !$record->shouldBeSearchable()) {
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
     * Get content IDs that have been deleted.
     *
     * @return string[] Content IDs to remove from the index.
     */
    public function getDeletedIds(): array
    {
        return ScoltaTracker::getPending('delete')
            ->pluck('content_id')
            ->all();
    }

    /**
     * Mark all tracked changes as processed after a successful build.
     */
    public function clearTracker(): void
    {
        ScoltaTracker::clearAll();
    }

    /**
     * Get total published content count across all configured models.
     */
    public function getTotalCount(array $options = []): int
    {
        $models = config('scolta.models', []);
        $count = 0;

        foreach ($models as $modelClass) {
            if (!class_exists($modelClass)) {
                continue;
            }

            $model = new $modelClass();
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
        return ScoltaTracker::getPendingCount();
    }
}
