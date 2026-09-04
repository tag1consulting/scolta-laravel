<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Tests\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Tag1\Scolta\Export\ContentItem;
use Tag1\ScoltaLaravel\Searchable;

/**
 * A model whose index item id has nothing to do with its primary key.
 *
 * The trait's default toSearchableContent() spells the item id
 * "{table}-{key}", which contains the primary key and so hides the
 * id-space defect this model exists to expose: a bare tracker `content_id`
 * fed to ContentExporter::deleteById() can never match "article:{slug}",
 * in the export manifest or in the flat fallback path.
 *
 * Overriding the id like this is documented and supported — the trait's own
 * usage example does it — so nothing here is exotic.
 *
 * @property string $slug
 * @property string $title
 * @property string $body
 * @property bool $published
 */
class SluggedArticle extends Model
{
    use Searchable;

    protected $table = 'slugged_articles';

    protected $guarded = [];

    protected $casts = [
        'published' => 'boolean',
    ];

    public function toSearchableContent(): ContentItem
    {
        return new ContentItem(
            id: 'article:'.$this->slug,
            title: $this->title,
            bodyHtml: '<p>'.$this->body.'</p>',
            url: '/articles/'.$this->slug,
            date: '2026-01-01',
            siteName: 'Test Site',
        );
    }

    /**
     * @param  Builder<SluggedArticle>  $query
     * @return Builder<SluggedArticle>
     */
    public function scopeSearchable(Builder $query): Builder
    {
        return $query->where('published', true);
    }

    public function shouldBeSearchable(): bool
    {
        return $this->published;
    }
}
