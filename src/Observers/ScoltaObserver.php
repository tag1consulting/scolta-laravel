<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Observers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
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
     */
    public function deleted(Model $model): void
    {
        ScoltaTracker::track(
            (string) $model->getKey(),
            $this->getContentType($model),
            'delete'
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
     */
    public function forceDeleted(Model $model): void
    {
        ScoltaTracker::track(
            (string) $model->getKey(),
            $this->getContentType($model),
            'delete'
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
            $shouldIndex ? 'index' : 'delete'
        );

        $this->maybeDispatchRebuild();
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
        if (! config('scolta.auto_rebuild', false)) {
            return;
        }

        $delay = (int) config('scolta.auto_rebuild_delay', 300);

        // Use cache to debounce — if a rebuild is already scheduled, skip.
        $cacheKey = 'scolta_rebuild_scheduled';
        if (Cache::has($cacheKey)) {
            return;
        }

        Cache::put($cacheKey, true, $delay);

        TriggerRebuild::dispatch()
            ->delay(now()->addSeconds($delay));
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
