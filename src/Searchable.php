<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel;

use Tag1\Scolta\Export\ContentItem;

/**
 * Trait for Eloquent models that should be indexed by Scolta.
 *
 * This is the developer-facing API for scolta-laravel. Add this trait
 * to any Eloquent model, implement toSearchableContent(), and the model
 * is automatically tracked and indexed.
 *
 * Laravel developers will recognize this pattern from Laravel Scout —
 * a trait on the model that defines how it maps to the search engine.
 * We follow the same convention because it's what Laravel developers expect.
 *
 * Usage:
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
 *             );
 *         }
 *     }
 */
trait Searchable
{
    /**
     * Convert this model instance to a ContentItem for indexing.
     *
     * Subclasses MUST implement this. It defines how the model's data
     * maps to the search index — title, body HTML, URL, date.
     *
     * This is where Laravel shines: the developer has full control over
     * content rendering. Use Blade views, markdown parsing, accessor
     * methods — whatever produces the best HTML for search.
     */
    abstract public function toSearchableContent(): ContentItem;

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
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeSearchable($query)
    {
        // Default: include all records. Override in your model for
        // status filtering, visibility checks, etc.
        return $query;
    }

    /**
     * Get the content type identifier for the tracker.
     *
     * Uses the fully-qualified class name by default. Override if you
     * want shorter identifiers (e.g., 'post' instead of 'App\Models\Post').
     */
    public function getSearchableType(): string
    {
        return static::class;
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
