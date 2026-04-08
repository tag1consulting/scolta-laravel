<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Tag1\Scolta\Content\ContentSourceInterface;
use Tag1\ScoltaLaravel\Services\ContentSource;

/**
 * Test ContentSource contract: interface implementation and method signatures.
 */
class ContentSourceValidationTest extends TestCase
{
    // -------------------------------------------------------------------
    // ContentSource implements ContentSourceInterface
    // -------------------------------------------------------------------

    public function test_content_source_implements_interface(): void
    {
        $ref = new ReflectionClass(ContentSource::class);
        $this->assertTrue(
            $ref->implementsInterface(ContentSourceInterface::class),
            'ContentSource must implement ContentSourceInterface.'
        );
    }

    // -------------------------------------------------------------------
    // All interface methods are present
    // -------------------------------------------------------------------

    /**
     * @var string[]
     */
    private const INTERFACE_METHODS = [
        'getPublishedContent',
        'getChangedContent',
        'getDeletedIds',
        'clearTracker',
        'getTotalCount',
        'getPendingCount',
    ];

    public function test_all_interface_methods_exist(): void
    {
        $ref = new ReflectionClass(ContentSource::class);

        foreach (self::INTERFACE_METHODS as $methodName) {
            $this->assertTrue(
                $ref->hasMethod($methodName),
                "ContentSource is missing method: {$methodName}."
            );
        }
    }

    // -------------------------------------------------------------------
    // Method return types match interface
    // -------------------------------------------------------------------

    public function test_get_published_content_return_type(): void
    {
        $method = new ReflectionMethod(ContentSource::class, 'getPublishedContent');
        $returnType = $method->getReturnType();

        $this->assertNotNull($returnType, 'getPublishedContent() should have a return type.');
        // ContentSource returns Generator which satisfies iterable.
        $this->assertEquals('Generator', $returnType->getName());
    }

    public function test_get_changed_content_return_type(): void
    {
        $method = new ReflectionMethod(ContentSource::class, 'getChangedContent');
        $returnType = $method->getReturnType();

        $this->assertNotNull($returnType, 'getChangedContent() should have a return type.');
        $this->assertEquals('Generator', $returnType->getName());
    }

    public function test_get_deleted_ids_return_type(): void
    {
        $method = new ReflectionMethod(ContentSource::class, 'getDeletedIds');
        $returnType = $method->getReturnType();

        $this->assertNotNull($returnType, 'getDeletedIds() should have a return type.');
        $this->assertEquals('array', (string) $returnType);
    }

    public function test_clear_tracker_return_type(): void
    {
        $method = new ReflectionMethod(ContentSource::class, 'clearTracker');
        $returnType = $method->getReturnType();

        $this->assertNotNull($returnType, 'clearTracker() should have a return type.');
        $this->assertEquals('void', (string) $returnType);
    }

    public function test_get_total_count_return_type(): void
    {
        $method = new ReflectionMethod(ContentSource::class, 'getTotalCount');
        $returnType = $method->getReturnType();

        $this->assertNotNull($returnType, 'getTotalCount() should have a return type.');
        $this->assertEquals('int', (string) $returnType);
    }

    public function test_get_pending_count_return_type(): void
    {
        $method = new ReflectionMethod(ContentSource::class, 'getPendingCount');
        $returnType = $method->getReturnType();

        $this->assertNotNull($returnType, 'getPendingCount() should have a return type.');
        $this->assertEquals('int', (string) $returnType);
    }

    // -------------------------------------------------------------------
    // getPublishedContent accepts options array parameter
    // -------------------------------------------------------------------

    public function test_get_published_content_accepts_options(): void
    {
        $method = new ReflectionMethod(ContentSource::class, 'getPublishedContent');
        $params = $method->getParameters();

        $this->assertGreaterThanOrEqual(1, count($params),
            'getPublishedContent() should accept an options parameter.');

        $firstParam = $params[0];
        $this->assertEquals('options', $firstParam->getName());
        $this->assertTrue($firstParam->isDefaultValueAvailable(),
            'options parameter should have a default value.');
        $this->assertEquals([], $firstParam->getDefaultValue(),
            'options parameter should default to empty array.');
    }

    // -------------------------------------------------------------------
    // getTotalCount accepts options array parameter
    // -------------------------------------------------------------------

    public function test_get_total_count_accepts_options(): void
    {
        $method = new ReflectionMethod(ContentSource::class, 'getTotalCount');
        $params = $method->getParameters();

        $this->assertGreaterThanOrEqual(1, count($params),
            'getTotalCount() should accept an options parameter.');

        $firstParam = $params[0];
        $this->assertEquals('options', $firstParam->getName());
        $this->assertTrue($firstParam->isDefaultValueAvailable());
        $this->assertEquals([], $firstParam->getDefaultValue());
    }

    // -------------------------------------------------------------------
    // ContentSource is a concrete class (not abstract)
    // -------------------------------------------------------------------

    public function test_content_source_is_concrete(): void
    {
        $ref = new ReflectionClass(ContentSource::class);
        $this->assertFalse($ref->isAbstract(), 'ContentSource should be a concrete class.');
        $this->assertFalse($ref->isInterface(), 'ContentSource should not be an interface.');
    }
}
