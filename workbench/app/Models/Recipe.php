<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;
use Tag1\Scolta\Export\ContentItem;
use Tag1\ScoltaLaravel\Searchable;

/**
 * A recipe page in the workbench testing site.
 *
 * Seeded from scolta-php's tests/fixtures/recipes corpus, so the content
 * this adapter indexes is byte-comparable with what scolta-php's own test
 * suite indexes.
 */
class Recipe extends Model
{
    use Searchable;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'published_at' => 'date',
        ];
    }

    public function toSearchableContent(): ContentItem
    {
        $filters = array_filter([
            'cuisine' => $this->cuisine,
            'diet' => $this->diet,
            'cook_time_bucket' => $this->cook_time_bucket,
        ]);

        return new ContentItem(
            id: 'recipe-'.$this->id,
            title: $this->title,
            bodyHtml: $this->body_html,
            url: '/recipes/'.$this->slug,
            date: $this->published_at->format('Y-m-d'),
            siteName: (string) config('scolta.site_name', config('app.name', '')),
            filters: $filters,
            metadata: ['cook_time' => (string) $this->cook_time],
        );
    }
}
