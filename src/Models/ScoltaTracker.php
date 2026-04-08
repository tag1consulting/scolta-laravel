<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent model for the scolta_tracker table.
 *
 * This is intentionally a simple model — it's internal plumbing, not
 * something users interact with directly. The observer writes to it,
 * the build command reads from it.
 *
 * Using Eloquent here (instead of raw DB queries) keeps us in the
 * Laravel ecosystem: model events, scopes, and all the tooling work.
 * It also means the tracker respects whatever database driver the
 * app uses — MySQL, PostgreSQL, SQLite, whatever.
 */
class ScoltaTracker extends Model
{
    protected $table = 'scolta_tracker';

    public $timestamps = false;

    protected $fillable = [
        'content_id',
        'content_type',
        'action',
        'changed_at',
    ];

    protected function casts(): array
    {
        return [
            'changed_at' => 'datetime',
        ];
    }

    /**
     * Track a content change.
     *
     * Uses updateOrCreate — the Eloquent equivalent of an upsert.
     * If there's already a tracker entry for this content, it's updated.
     * This prevents duplicate entries when content is saved multiple times
     * between builds.
     */
    public static function track(string $contentId, string $contentType, string $action = 'index'): self
    {
        return static::updateOrCreate(
            [
                'content_id' => $contentId,
                'content_type' => $contentType,
            ],
            [
                'action' => $action,
                'changed_at' => now(),
            ]
        );
    }

    /**
     * Get the count of pending changes, optionally filtered by action.
     */
    public static function getPendingCount(?string $action = null): int
    {
        $query = static::query();

        if ($action !== null) {
            $query->where('action', $action);
        }

        return $query->count();
    }

    /**
     * Get all pending records for a given action.
     *
     * @return Collection<int, static>
     */
    public static function getPending(string $action): Collection
    {
        return static::where('action', $action)->get();
    }

    /**
     * Clear all tracker entries after a successful build.
     */
    public static function clearAll(): int
    {
        return static::query()->delete();
    }

    /**
     * Mark all published content from configured models for reindex.
     *
     * This is the full-rebuild path. We query each configured model
     * for its published/active records and insert tracker entries.
     *
     * Laravel's chunk() method keeps memory flat — same principle as
     * WordPress's paginated WP_Query in scolta-wp.
     */
    public static function markAllForReindex(): int
    {
        $models = config('scolta.models', []);
        $count = 0;

        foreach ($models as $modelClass) {
            if (! class_exists($modelClass)) {
                continue;
            }

            $model = new $modelClass;
            $contentType = get_class($model);

            // Use the Searchable trait's scope if available, otherwise query all.
            $query = method_exists($model, 'scopeSearchable')
                ? $modelClass::searchable()
                : $modelClass::query();

            $query->chunk(200, function ($records) use ($contentType, &$count) {
                foreach ($records as $record) {
                    static::track(
                        (string) $record->getKey(),
                        $contentType,
                        'index'
                    );
                    $count++;
                }
            });
        }

        return $count;
    }
}
