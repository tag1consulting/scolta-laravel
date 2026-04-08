<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Tag1\Scolta\Export\ContentItem;
use Tag1\ScoltaLaravel\Searchable;

/**
 * Test the Searchable trait contract: abstract methods, defaults, concrete usage.
 */
class SearchableTraitTest extends TestCase
{
    // -------------------------------------------------------------------
    // Trait existence and type
    // -------------------------------------------------------------------

    public function test_searchable_is_a_trait(): void
    {
        $ref = new ReflectionClass(Searchable::class);
        $this->assertTrue($ref->isTrait(), 'Searchable should be a trait.');
    }

    // -------------------------------------------------------------------
    // toSearchableContent() is abstract
    // -------------------------------------------------------------------

    public function test_to_searchable_content_is_abstract(): void
    {
        $method = new ReflectionMethod(Searchable::class, 'toSearchableContent');
        $this->assertTrue($method->isAbstract(),
            'toSearchableContent() should be declared abstract.');
    }

    public function test_to_searchable_content_returns_content_item(): void
    {
        $method = new ReflectionMethod(Searchable::class, 'toSearchableContent');
        $returnType = $method->getReturnType();

        $this->assertNotNull($returnType,
            'toSearchableContent() should have a return type.');
        $this->assertEquals(
            ContentItem::class,
            $returnType->getName(),
            'toSearchableContent() should return ContentItem.'
        );
    }

    // -------------------------------------------------------------------
    // scopeSearchable() has a default implementation
    // -------------------------------------------------------------------

    public function test_scope_searchable_exists(): void
    {
        $ref = new ReflectionClass(Searchable::class);
        $this->assertTrue(
            $ref->hasMethod('scopeSearchable'),
            'Searchable trait should define scopeSearchable().'
        );
    }

    public function test_scope_searchable_is_not_abstract(): void
    {
        $method = new ReflectionMethod(Searchable::class, 'scopeSearchable');
        $this->assertFalse($method->isAbstract(),
            'scopeSearchable() should have a default implementation (not abstract).');
    }

    public function test_scope_searchable_accepts_query_parameter(): void
    {
        $method = new ReflectionMethod(Searchable::class, 'scopeSearchable');
        $params = $method->getParameters();

        $this->assertCount(1, $params,
            'scopeSearchable() should accept one parameter.');
        $this->assertEquals('query', $params[0]->getName());
    }

    // -------------------------------------------------------------------
    // getSearchableType() defaults to class name
    // -------------------------------------------------------------------

    public function test_get_searchable_type_exists(): void
    {
        $ref = new ReflectionClass(Searchable::class);
        $this->assertTrue(
            $ref->hasMethod('getSearchableType'),
            'Searchable trait should define getSearchableType().'
        );
    }

    public function test_get_searchable_type_is_not_abstract(): void
    {
        $method = new ReflectionMethod(Searchable::class, 'getSearchableType');
        $this->assertFalse($method->isAbstract(),
            'getSearchableType() should have a default implementation.');
    }

    public function test_get_searchable_type_returns_string(): void
    {
        $method = new ReflectionMethod(Searchable::class, 'getSearchableType');
        $returnType = $method->getReturnType();

        $this->assertNotNull($returnType);
        $this->assertEquals('string', (string) $returnType);
    }

    public function test_get_searchable_type_uses_static_class(): void
    {
        $source = file_get_contents(dirname(__DIR__).'/src/Searchable.php');

        // Verify the default implementation uses static::class.
        preg_match('/function getSearchableType\(\).*?\{(.*?)\}/s', $source, $match);
        $this->assertNotEmpty($match, 'Could not extract getSearchableType() body.');
        $this->assertStringContainsString('static::class', $match[1],
            'getSearchableType() should default to static::class.');
    }

    // -------------------------------------------------------------------
    // shouldBeSearchable() defaults to true
    // -------------------------------------------------------------------

    public function test_should_be_searchable_exists(): void
    {
        $ref = new ReflectionClass(Searchable::class);
        $this->assertTrue(
            $ref->hasMethod('shouldBeSearchable'),
            'Searchable trait should define shouldBeSearchable().'
        );
    }

    public function test_should_be_searchable_is_not_abstract(): void
    {
        $method = new ReflectionMethod(Searchable::class, 'shouldBeSearchable');
        $this->assertFalse($method->isAbstract(),
            'shouldBeSearchable() should have a default implementation.');
    }

    public function test_should_be_searchable_returns_bool(): void
    {
        $method = new ReflectionMethod(Searchable::class, 'shouldBeSearchable');
        $returnType = $method->getReturnType();

        $this->assertNotNull($returnType);
        $this->assertEquals('bool', (string) $returnType);
    }

    public function test_should_be_searchable_defaults_to_true(): void
    {
        $source = file_get_contents(dirname(__DIR__).'/src/Searchable.php');

        preg_match('/function shouldBeSearchable\(\).*?\{(.*?)\}/s', $source, $match);
        $this->assertNotEmpty($match, 'Could not extract shouldBeSearchable() body.');
        $this->assertStringContainsString('return true', $match[1],
            'shouldBeSearchable() should default to returning true.');
    }

    // -------------------------------------------------------------------
    // Concrete test model using the trait
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

    // -------------------------------------------------------------------
    // Trait defines exactly 4 methods
    // -------------------------------------------------------------------

    public function test_trait_defines_expected_methods(): void
    {
        $ref = new ReflectionClass(Searchable::class);
        $methods = $ref->getMethods();
        $methodNames = array_map(fn (ReflectionMethod $m) => $m->getName(), $methods);

        $expected = [
            'toSearchableContent',
            'scopeSearchable',
            'getSearchableType',
            'shouldBeSearchable',
        ];

        foreach ($expected as $name) {
            $this->assertContains($name, $methodNames,
                "Searchable trait should define {$name}().");
        }
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
