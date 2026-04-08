<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Tests;

use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Tag1\ScoltaLaravel\Observers\ScoltaObserver;

/**
 * Test ScoltaObserver structure: event handlers, parameter types, tracking logic.
 */
class ObserverTest extends TestCase
{
    private const ELOQUENT_EVENTS = [
        'created',
        'updated',
        'deleted',
        'restored',
        'forceDeleted',
    ];

    // -------------------------------------------------------------------
    // Observer handles all Eloquent events
    // -------------------------------------------------------------------

    #[DataProvider('eventProvider')]
    public function test_observer_handles_event(string $event): void
    {
        $ref = new ReflectionClass(ScoltaObserver::class);
        $this->assertTrue(
            $ref->hasMethod($event),
            "ScoltaObserver should handle the '{$event}' event."
        );
    }

    public static function eventProvider(): array
    {
        $data = [];
        foreach (['created', 'updated', 'deleted', 'restored', 'forceDeleted'] as $event) {
            $data[$event] = [$event];
        }

        return $data;
    }

    // -------------------------------------------------------------------
    // Event methods accept Model parameter
    // -------------------------------------------------------------------

    #[DataProvider('eventProvider')]
    public function test_event_method_accepts_model(string $event): void
    {
        $method = new ReflectionMethod(ScoltaObserver::class, $event);
        $params = $method->getParameters();

        $this->assertCount(1, $params,
            "ScoltaObserver::{$event}() should accept exactly one parameter.");

        $type = $params[0]->getType();
        $this->assertNotNull($type,
            "ScoltaObserver::{$event}() parameter should be typed.");
        $this->assertInstanceOf(ReflectionNamedType::class, $type);
        $this->assertEquals(
            Model::class,
            $type->getName(),
            "ScoltaObserver::{$event}() should accept a Model parameter."
        );
    }

    // -------------------------------------------------------------------
    // Event methods return void
    // -------------------------------------------------------------------

    #[DataProvider('eventProvider')]
    public function test_event_method_returns_void(string $event): void
    {
        $method = new ReflectionMethod(ScoltaObserver::class, $event);
        $returnType = $method->getReturnType();

        $this->assertNotNull($returnType,
            "ScoltaObserver::{$event}() should have a return type.");
        $this->assertEquals('void', (string) $returnType,
            "ScoltaObserver::{$event}() should return void.");
    }

    // -------------------------------------------------------------------
    // trackForIndex method checks shouldBeSearchable
    // -------------------------------------------------------------------

    public function test_track_for_index_method_exists(): void
    {
        $ref = new ReflectionClass(ScoltaObserver::class);
        $this->assertTrue(
            $ref->hasMethod('trackForIndex'),
            'ScoltaObserver should have a trackForIndex() method.'
        );
    }

    public function test_track_for_index_is_private(): void
    {
        $method = new ReflectionMethod(ScoltaObserver::class, 'trackForIndex');
        $this->assertTrue($method->isPrivate(),
            'trackForIndex() should be private.');
    }

    public function test_track_for_index_checks_should_be_searchable(): void
    {
        $source = file_get_contents(
            dirname(__DIR__).'/src/Observers/ScoltaObserver.php'
        );

        $this->assertStringContainsString('shouldBeSearchable', $source,
            'ScoltaObserver should check shouldBeSearchable() before tracking.');
    }

    // -------------------------------------------------------------------
    // created and updated delegate to trackForIndex
    // -------------------------------------------------------------------

    public function test_created_delegates_to_track_for_index(): void
    {
        $source = file_get_contents(
            dirname(__DIR__).'/src/Observers/ScoltaObserver.php'
        );

        // Verify created() calls trackForIndex.
        preg_match('/public function created\(.*?\{(.*?)\}/s', $source, $match);
        $this->assertNotEmpty($match, 'Could not find created() method body.');
        $this->assertStringContainsString('trackForIndex', $match[1],
            'created() should delegate to trackForIndex().');
    }

    public function test_updated_delegates_to_track_for_index(): void
    {
        $source = file_get_contents(
            dirname(__DIR__).'/src/Observers/ScoltaObserver.php'
        );

        preg_match('/public function updated\(.*?\{(.*?)\}/s', $source, $match);
        $this->assertNotEmpty($match, 'Could not find updated() method body.');
        $this->assertStringContainsString('trackForIndex', $match[1],
            'updated() should delegate to trackForIndex().');
    }

    public function test_restored_delegates_to_track_for_index(): void
    {
        $source = file_get_contents(
            dirname(__DIR__).'/src/Observers/ScoltaObserver.php'
        );

        preg_match('/public function restored\(.*?\{(.*?)\}/s', $source, $match);
        $this->assertNotEmpty($match, 'Could not find restored() method body.');
        $this->assertStringContainsString('trackForIndex', $match[1],
            'restored() should delegate to trackForIndex().');
    }

    // -------------------------------------------------------------------
    // deleted and forceDeleted track as delete directly
    // -------------------------------------------------------------------

    public function test_deleted_tracks_as_delete(): void
    {
        $source = file_get_contents(
            dirname(__DIR__).'/src/Observers/ScoltaObserver.php'
        );

        preg_match('/public function deleted\(.*?\{(.*?)\}/s', $source, $match);
        $this->assertNotEmpty($match, 'Could not find deleted() method body.');
        $this->assertStringContainsString("'delete'", $match[1],
            "deleted() should track as 'delete'.");
    }

    public function test_force_deleted_tracks_as_delete(): void
    {
        $source = file_get_contents(
            dirname(__DIR__).'/src/Observers/ScoltaObserver.php'
        );

        preg_match('/public function forceDeleted\(.*?\{(.*?)\}/s', $source, $match);
        $this->assertNotEmpty($match, 'Could not find forceDeleted() method body.');
        $this->assertStringContainsString("'delete'", $match[1],
            "forceDeleted() should track as 'delete'.");
    }

    // -------------------------------------------------------------------
    // getContentType helper exists
    // -------------------------------------------------------------------

    public function test_get_content_type_method_exists(): void
    {
        $ref = new ReflectionClass(ScoltaObserver::class);
        $this->assertTrue(
            $ref->hasMethod('getContentType'),
            'ScoltaObserver should have a getContentType() method.'
        );
    }

    public function test_get_content_type_is_private(): void
    {
        $method = new ReflectionMethod(ScoltaObserver::class, 'getContentType');
        $this->assertTrue($method->isPrivate(),
            'getContentType() should be private.');
    }
}
