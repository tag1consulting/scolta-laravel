<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Tests\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Tag1\ScoltaLaravel\Searchable;

/**
 * Eloquent test model exercising the documented publish filters.
 *
 * - scopeSearchable() filters on the 'published' column.
 * - shouldBeSearchable() filters on the 'unlisted' column.
 *
 * Used by the content-gathering regression tests to assert that every
 * index path (sync PHP indexer, queue dispatch) excludes records that
 * either filter rejects.
 */
class SearchablePost extends Model
{
    use Searchable;

    protected $table = 'searchable_posts';

    protected $guarded = [];

    protected $casts = [
        'published' => 'boolean',
        'unlisted' => 'boolean',
    ];

    public function scopeSearchable(Builder $query): Builder
    {
        return $query->where('published', true);
    }

    public function shouldBeSearchable(): bool
    {
        return ! $this->unlisted;
    }
}
