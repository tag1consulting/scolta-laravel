<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

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
 *
 * @property string $content_id Eloquent primary key of the changed record.
 * @property string $content_type Model class name (or getSearchableType()).
 * @property string|null $item_id ContentItem::$id, recorded when a deletion was tracked.
 * @property string $action Either 'index' or 'delete'.
 */
class ScoltaTracker extends Model
{
    protected $table = 'scolta_tracker';

    public $timestamps = false;

    protected $fillable = [
        'content_id',
        'content_type',
        'item_id',
        'action',
        'changed_at',
    ];

    /**
     * Memoised answer to "has this install run the item_id migration?".
     *
     * Asked once per process, not once per tracked change: the observer calls
     * track() on every save, and a schema round-trip there would add a query to
     * every write on every model the site indexes.
     */
    private static ?bool $hasItemIdColumn = null;

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
     *
     * @param  string  $contentId  The record's Eloquent primary key.
     * @param  string  $contentType  The model class name.
     * @param  string  $action  Either 'index' or 'delete'.
     * @param  string|null  $itemId  ContentItem::$id — the id the index is keyed by.
     *                               Pass it whenever the model is in hand and the
     *                               row records a deletion; see the item_id migration.
     */
    public static function track(string $contentId, string $contentType, string $action = 'index', ?string $itemId = null): self
    {
        $values = [
            'action' => $action,
            'changed_at' => now(),
        ];

        // $itemId is ContentItem::$id, not the Eloquent key in $contentId. The
        // observer supplies it on a deletion, because a deleted record cannot be
        // asked for it later. Absent before the item_id migration, and on an
        // 'index' row, where the record is still there to ask.
        if (static::hasItemIdColumn()) {
            $values['item_id'] = $itemId;
        }

        return static::updateOrCreate(
            [
                'content_id' => $contentId,
                'content_type' => $contentType,
            ],
            $values
        );
    }

    /**
     * Whether the scolta_tracker table carries the item_id column.
     *
     * The column arrived in a migration of its own, so an install that upgraded
     * the package but has not run `artisan migrate` must still track changes:
     * writing item_id there would fail every insert, and reading it back yields
     * null, which falls through to reloading the record.
     *
     * @since 1.4.0
     *
     * @stability experimental
     */
    public static function hasItemIdColumn(): bool
    {
        return self::$hasItemIdColumn ??= Schema::hasColumn('scolta_tracker', 'item_id');
    }

    /**
     * Forget the memoised schema answer.
     *
     * For tests and callers that migrate mid-process.
     *
     * @since 1.4.0
     *
     * @stability internal
     */
    public static function flushSchemaCache(): void
    {
        self::$hasItemIdColumn = null;
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
     * @return Collection<int, ScoltaTracker>
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
