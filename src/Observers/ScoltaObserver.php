<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Observers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Tag1\Scolta\Export\ContentItem;
use Tag1\ScoltaLaravel\Jobs\TriggerRebuild;
use Tag1\ScoltaLaravel\Models\ScoltaTracker;

/**
 * Eloquent model observer for Scolta change tracking.
 *
 * This is the Laravel equivalent of WordPress's save_post and
 * before_delete_post hooks, or Drupal's Search API tracker hooks.
 * Eloquent's observer pattern is cleaner than either — it's a dedicated
 * class with typed methods for each lifecycle event.
 *
 * The observer is attached to every model listed in config('scolta.models')
 * by the service provider. When a model is created, updated, or deleted,
 * the observer writes a tracker record. The build command later consumes
 * these records for incremental indexing.
 *
 * Laravel's observer system ensures we catch ALL persistence events:
 *   - Direct saves ($post->save())
 *   - Mass updates (Post::where(...)->update([...]) — see note below)
 *   - Soft deletes (if the model uses SoftDeletes)
 *   - Force deletes
 *   - Restorations (undelete)
 *
 * Note: Mass updates via query builder (Post::where()->update()) do NOT
 * fire model events. This is a known Laravel behavior. For mass operations,
 * developers should run `artisan scolta:build` afterward. This matches
 * WordPress's pattern — bulk edits via raw SQL need a manual reindex too.
 */
class ScoltaObserver
{
    /**
     * Handle the "created" event.
     */
    public function created(Model $model): void
    {
        $this->trackForIndex($model);
    }

    /**
     * Handle the "updated" event.
     */
    public function updated(Model $model): void
    {
        $this->trackForIndex($model);
    }

    /**
     * Handle the "deleted" event (including soft deletes).
     *
     * The item id is captured here because this is the last moment the record
     * can be asked. See resolveItemId().
     */
    public function deleted(Model $model): void
    {
        ScoltaTracker::track(
            (string) $model->getKey(),
            $this->getContentType($model),
            'delete',
            $this->resolveItemId($model)
        );

        $this->maybeDispatchRebuild();
    }

    /**
     * Handle the "restored" event (soft delete undo).
     *
     * When a soft-deleted model is restored, re-index it. This is a
     * Laravel-specific lifecycle event that WordPress and Drupal don't
     * have — one of Laravel's advantages for content management.
     */
    public function restored(Model $model): void
    {
        $this->trackForIndex($model);
    }

    /**
     * Handle the "force deleted" event.
     *
     * Permanent deletion — model is gone from the database.
     * Track as delete regardless of shouldBeSearchable().
     *
     * The case the item id has to be recorded for: after a force delete there is
     * no row left to reconstruct it from.
     */
    public function forceDeleted(Model $model): void
    {
        ScoltaTracker::track(
            (string) $model->getKey(),
            $this->getContentType($model),
            'delete',
            $this->resolveItemId($model)
        );

        $this->maybeDispatchRebuild();
    }

    /**
     * Track a model for indexing, respecting shouldBeSearchable().
     *
     * If the model says it shouldn't be searchable (e.g., it was just
     * changed to draft status), track as 'delete' instead. This is the
     * same pattern WordPress uses with publish/unpublish transitions.
     */
    private function trackForIndex(Model $model): void
    {
        $shouldIndex = method_exists($model, 'shouldBeSearchable')
            ? $model->shouldBeSearchable()
            : true;

        ScoltaTracker::track(
            (string) $model->getKey(),
            $this->getContentType($model),
            $shouldIndex ? 'index' : 'delete',
            // Delete branch only: resolving an item id renders the model's
            // searchable content, which on an 'index' row the build is about to
            // redo anyway, and there the record is still around to ask.
            $shouldIndex ? null : $this->resolveItemId($model)
        );

        $this->maybeDispatchRebuild();
    }

    /**
     * The id the index knows this model by, asked while the model still exists.
     *
     * The tracker's content_id is an Eloquent primary key; the export manifest,
     * the exported files and the index are keyed by ContentItem::$id. Nothing
     * maps one to the other except a live model instance, and a pending delete
     * is the row whose model is about to stop existing.
     *
     * Returns null rather than throwing: the delete is already in flight, and a
     * toSearchableContent() reaching for a cascaded-away relation must not take
     * it down. Null lands as an unrecorded item id, which
     * ContentSource::getDeletedItemIds() reports rather than guesses at, and
     * which escalates the next incremental run to a full one.
     */
    private function resolveItemId(Model $model): ?string
    {
        if (! method_exists($model, 'toSearchableContent')) {
            return null;
        }

        try {
            $item = $model->toSearchableContent();
        } catch (\Throwable $e) {
            logger()->warning(sprintf(
                '[scolta] Could not derive the index item id for %s #%s while tracking its deletion: %s. '
                .'Its exported page can only be removed by a full rebuild.',
                get_class($model),
                (string) $model->getKey(),
                $e->getMessage(),
            ));

            return null;
        }

        return $item instanceof ContentItem && $item->id !== '' ? $item->id : null;
    }

    /**
     * Mark that a bulk update occurred and a rebuild is needed.
     *
     * Call this after query builder mass updates that bypass Eloquent events:
     *
     *     Post::where('category', 'news')->update(['featured' => true]);
     *     ScoltaObserver::afterBulkUpdate();
     *
     * This is a convenience wrapper — it's equivalent to running
     * `artisan scolta:build`, but can be called from application code.
     *
     * @since 0.2.0
     */
    public static function afterBulkUpdate(): void
    {
        (new self)->maybeDispatchRebuild();
    }

    /**
     * Dispatch a debounced rebuild if auto-rebuild is enabled.
     *
     * Uses cache-based debouncing so that multiple content changes
     * within the delay window result in a single rebuild. The delay
     * defaults to 300 seconds (5 minutes), configurable via
     * config('scolta.auto_rebuild_delay').
     *
     * @since 0.2.0
     *
     * @stability experimental
     */
    private function maybeDispatchRebuild(): void
    {
        // Fallback default matches config/scolta.php ('auto_rebuild' => true)
        // so behavior is identical whether or not the config file is merged.
        if (! config('scolta.auto_rebuild', true)) {
            return;
        }

        $delay = (int) config('scolta.auto_rebuild_delay', 300);

        // Use atomic Cache::add() for debounce — sets and dispatches only if
        // the key was not already present, eliminating the TOCTOU race between
        // a Cache::has() check and a subsequent Cache::put().
        $cacheKey = 'scolta_rebuild_scheduled';

        if (Cache::add($cacheKey, true, $delay)) {
            TriggerRebuild::dispatch()
                ->delay(now()->addSeconds($delay));
        }
    }

    /**
     * Get the content type identifier for a model.
     */
    private function getContentType(Model $model): string
    {
        return method_exists($model, 'getSearchableType')
            ? $model->getSearchableType()
            : get_class($model);
    }
}
