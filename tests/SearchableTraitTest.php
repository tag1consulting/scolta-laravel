<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Tests;

use PHPUnit\Framework\TestCase;
use Tag1\Scolta\Export\ContentItem;
use Tag1\ScoltaLaravel\Searchable;

/**
 * Test the Searchable trait contract: abstract methods, defaults, concrete usage.
 */
class SearchableTraitTest extends TestCase
{
    // -------------------------------------------------------------------
    // Concrete test model using the trait — verifies default implementations
    // -------------------------------------------------------------------

    public function test_concrete_model_can_use_trait(): void
    {
        // A concrete class using the Searchable trait must implement
        // toSearchableContent(). We verify it can be instantiated.
        $model = new ConcreteSearchableModel;

        $this->assertInstanceOf(ConcreteSearchableModel::class, $model);
    }

    public function test_concrete_model_should_be_searchable(): void
    {
        $model = new ConcreteSearchableModel;
        $this->assertTrue($model->shouldBeSearchable());
    }

    public function test_concrete_model_get_searchable_type(): void
    {
        $model = new ConcreteSearchableModel;
        $this->assertEquals(ConcreteSearchableModel::class, $model->getSearchableType());
    }

    public function test_concrete_model_to_searchable_content(): void
    {
        $model = new ConcreteSearchableModel;
        $item = $model->toSearchableContent();

        $this->assertInstanceOf(ContentItem::class, $item);
        $this->assertEquals('test-1', $item->id);
        $this->assertEquals('Test Title', $item->title);
    }
}

/**
 * Concrete test model that uses the Searchable trait.
 *
 * This is NOT an Eloquent model — it just uses the trait to verify
 * the trait's contract can be satisfied without a real database.
 */
class ConcreteSearchableModel
{
    use Searchable;

    public function toSearchableContent(): ContentItem
    {
        return new ContentItem(
            id: 'test-1',
            title: 'Test Title',
            bodyHtml: '<p>Test body</p>',
            url: '/test',
            date: '2026-01-01',
            siteName: 'Test Site',
        );
    }
}
