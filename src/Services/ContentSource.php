<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Services;

use Generator;
use Tag1\Scolta\Content\ContentSourceInterface;
use Tag1\Scolta\Export\ContentItem;
use Tag1\ScoltaLaravel\Models\ScoltaTracker;

/**
 * Laravel content source for Scolta indexing.
 *
 * Implements ContentSourceInterface from scolta-core for cross-platform
 * consistency. Uses Eloquent ORM for content discovery and rendering.
 */
class ContentSource implements ContentSourceInterface
{
    /**
     * Yield all published content as ContentItem objects.
     *
     * The $options parameter satisfies the interface but is ignored —
     * Laravel uses config('scolta.models') to determine what to index.
     *
     * @return Generator<ContentItem>
     */
    public function getPublishedContent(array $options = []): Generator
    {
        $models = config('scolta.models', []);

        foreach ($models as $modelClass) {
            if (!class_exists($modelClass)) {
                logger()->warning("[scolta] Model class not found: {$modelClass}. Check config/scolta.php 'models' array.");
                continue;
            }

            if (!in_array(\Tag1\ScoltaLaravel\Searchable::class, class_uses_recursive($modelClass))) {
                logger()->warning("[scolta] Model {$modelClass} does not use the Searchable trait. It will not be indexed.");
                continue;
            }

            $model = new $modelClass();

            $query = method_exists($model, 'scopeSearchable')
                ? $modelClass::searchable()
                : $modelClass::query();

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
     * @return Generator<ContentItem>
     */
    public function getChangedContent(): Generator
    {
        $pending = ScoltaTracker::getPending('index');

        $grouped = $pending->groupBy('content_type');

        foreach ($grouped as $contentType => $records) {
            if (!class_exists($contentType)) {
                continue;
            }

            $ids = $records->pluck('content_id')->all();

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
     * Clear the change tracker after a successful build.
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
     * Get count of pending changes in the tracker.
     */
    public function getPendingCount(): int
    {
        return ScoltaTracker::getPendingCount();
    }
}
