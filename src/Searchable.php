<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Tag1\Scolta\Export\ContentItem;

/**
 * Trait for Eloquent models that should be indexed by Scolta.
 *
 * This is the developer-facing API for scolta-laravel. Add this trait
 * to any Eloquent model and it is automatically tracked and indexed.
 *
 * A default toSearchableContent() is provided that reads common column
 * names (title, body, etc.). Override it for full control.
 *
 * Laravel developers will recognize this pattern from Laravel Scout —
 * a trait on the model that defines how it maps to the search engine.
 * We follow the same convention because it's what Laravel developers expect.
 *
 * The default toSearchableContent() writes the model's morph class
 * ($this->getMorphClass(): the FQCN, or its Relation::morphMap() alias)
 * into the item's metadata as `type`, so a per-model ranking boost has a
 * key that never collides across models: `scoring.metadata_boosts` =>
 * ['type' => ['post' => 1.4]]. An override that wants type boosts carries
 * the key forward, as the examples below do.
 *
 * Usage (minimal — defaults apply):
 *
 *     class Post extends Model
 *     {
 *         use \Tag1\ScoltaLaravel\Searchable;
 *     }
 *
 * Usage (recommended — explicit mapping):
 *
 *     class Post extends Model
 *     {
 *         use \Tag1\ScoltaLaravel\Searchable;
 *
 *         public function toSearchableContent(): ContentItem
 *         {
 *             return new ContentItem(
 *                 id: "post-{$this->id}",
 *                 title: $this->title,
 *                 bodyHtml: $this->rendered_content,
 *                 url: route('posts.show', $this),
 *                 date: $this->updated_at->format('Y-m-d'),
 *                 siteName: config('scolta.site_name'),
 *                 metadata: ['type' => $this->getMorphClass()],
 *             );
 *         }
 *     }
 */
trait Searchable
{
    /**
     * Convert this model instance to a ContentItem for indexing.
     *
     * The default implementation reads common column names — override it
     * in your model for precise control over what gets indexed.
     *
     * Default column resolution order:
     *   title    → title, name, subject (first non-null)
     *   body     → body, content, description (first non-null, treated as PLAIN TEXT)
     *
     *   WARNING: If your model stores HTML in body/content/description columns,
     *   you MUST override this method. The default escapes HTML entities, which
     *   will produce garbled search index content. See the usage example above.
     *
     *   url      → /models/{primary-key}  (always override this)
     *   date     → updated_at, created_at, published_at (first non-null date column)
     *   siteName → config('scolta.site_name') or config('app.name')
     *   metadata → ['type' => $this->getMorphClass()], the key
     *              scoring.metadata_boosts boosts on
     *
     * Override this in any model where the defaults do not apply:
     *
     *     public function toSearchableContent(): ContentItem
     *     {
     *         return new ContentItem(
     *             id: "post-{$this->id}",
     *             title: $this->title,
     *             bodyHtml: $this->rendered_content,
     *             url: route('posts.show', $this),
     *             date: $this->updated_at->format('Y-m-d'),
     *             siteName: config('scolta.site_name', config('app.name')),
     *             metadata: ['type' => $this->getMorphClass()],
     *         );
     *     }
     *
     * @since 0.3.0
     *
     * @stability experimental
     */
    public function toSearchableContent(): ContentItem
    {
        $title = $this->title ?? $this->name ?? $this->subject ?? '';

        $bodyText = $this->body ?? $this->content ?? $this->description ?? '';
        $bodyHtml = $bodyText !== '' ? '<p>'.strip_tags((string) $bodyText).'</p>' : ''; // strip_tags appropriate: plain-text column → safe HTML wrapper

        $pk = $this->getKey();
        $table = $this->getTable();
        $url = '/'.$table.'/'.$pk;

        $dateColumn = $this->updated_at ?? $this->created_at ?? $this->published_at ?? null;
        $date = $dateColumn ? $dateColumn->format('Y-m-d') : date('Y-m-d');

        $siteName = config('scolta.site_name', config('app.name', ''));

        return new ContentItem(
            id: $table.'-'.$pk,
            title: (string) $title,
            bodyHtml: $bodyHtml,
            url: $url,
            date: $date,
            siteName: (string) $siteName,
            metadata: ['type' => $this->getMorphClass()],
        );
    }

    /**
     * Scope: only records that should appear in search.
     *
     * Override this in your model to customize which records are indexed.
     * Default: all records (assumes the model only contains publishable content).
     *
     * Examples:
     *   - Posts: ->where('status', 'published')
     *   - Products: ->where('active', true)->where('visible', true)
     *   - Pages: ->whereNotNull('published_at')
     *
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    public function scopeSearchable(Builder $query)
    {
        // Default: include all records. Override in your model for
        // status filtering, visibility checks, etc.
        return $query;
    }

    /**
     * Get the content type identifier for the tracker.
     *
     * The morph class by default — the same string Laravel stores in a
     * polymorphic type column: the FQCN, or the Relation::morphMap() alias
     * when one is registered — and so the same value the default
     * toSearchableContent() writes as the `type` meta. ContentSource resolves
     * it back to the class through the morph map, so an override may return
     * either form; any other string cannot be resolved, and rows tracked
     * under it are reported as unresolved.
     */
    public function getSearchableType(): string
    {
        return $this->getMorphClass();
    }

    /**
     * Determine if this model instance should be indexed right now.
     *
     * Called by the observer before tracking a change. Override to
     * add conditions — e.g., skip draft posts, unpublished products.
     *
     * When this returns false for an existing indexed item, the observer
     * records a 'delete' action to remove it from the index.
     */
    public function shouldBeSearchable(): bool
    {
        return true;
    }
}
