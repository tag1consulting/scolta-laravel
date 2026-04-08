<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Tag1\ScoltaLaravel\Models\ScoltaTracker;

/**
 * Test ScoltaTracker model structure: fillable, timestamps, helpers.
 */
class TrackerModelTest extends TestCase
{
    // -------------------------------------------------------------------
    // Model class exists and extends Eloquent Model
    // -------------------------------------------------------------------

    public function test_model_class_exists(): void
    {
        $this->assertTrue(class_exists(ScoltaTracker::class),
            'ScoltaTracker model class should exist.');
    }

    public function test_model_extends_eloquent_model(): void
    {
        $ref = new ReflectionClass(ScoltaTracker::class);
        $this->assertTrue(
            $ref->isSubclassOf(\Illuminate\Database\Eloquent\Model::class),
            'ScoltaTracker should extend Eloquent Model.'
        );
    }

    // -------------------------------------------------------------------
    // Fillable properties
    // -------------------------------------------------------------------

    public function test_fillable_properties(): void
    {
        $ref = new ReflectionClass(ScoltaTracker::class);
        $prop = $ref->getProperty('fillable');


        $fillable = $prop->getDefaultValue();

        $this->assertIsArray($fillable);
        $this->assertContains('content_id', $fillable,
            'Fillable should include content_id.');
        $this->assertContains('content_type', $fillable,
            'Fillable should include content_type.');
        $this->assertContains('action', $fillable,
            'Fillable should include action.');
        $this->assertContains('changed_at', $fillable,
            'Fillable should include changed_at.');
    }

    public function test_fillable_has_exactly_four_fields(): void
    {
        $ref = new ReflectionClass(ScoltaTracker::class);
        $prop = $ref->getProperty('fillable');


        $fillable = $prop->getDefaultValue();
        $this->assertCount(4, $fillable,
            'ScoltaTracker should have exactly 4 fillable fields.');
    }

    // -------------------------------------------------------------------
    // No timestamps
    // -------------------------------------------------------------------

    public function test_timestamps_disabled(): void
    {
        $ref = new ReflectionClass(ScoltaTracker::class);
        $prop = $ref->getProperty('timestamps');


        $this->assertFalse($prop->getDefaultValue(),
            'ScoltaTracker should have timestamps disabled.');
    }

    // -------------------------------------------------------------------
    // Table name
    // -------------------------------------------------------------------

    public function test_table_name(): void
    {
        $ref = new ReflectionClass(ScoltaTracker::class);
        $prop = $ref->getProperty('table');


        $this->assertEquals('scolta_tracker', $prop->getDefaultValue(),
            'Table name should be scolta_tracker.');
    }

    // -------------------------------------------------------------------
    // Static helper methods exist
    // -------------------------------------------------------------------

    public function test_track_method_exists(): void
    {
        $ref = new ReflectionClass(ScoltaTracker::class);
        $this->assertTrue($ref->hasMethod('track'),
            'ScoltaTracker should have a track() static method.');
    }

    public function test_track_is_static(): void
    {
        $method = new ReflectionMethod(ScoltaTracker::class, 'track');
        $this->assertTrue($method->isStatic(), 'track() should be static.');
        $this->assertTrue($method->isPublic(), 'track() should be public.');
    }

    public function test_track_parameters(): void
    {
        $method = new ReflectionMethod(ScoltaTracker::class, 'track');
        $params = $method->getParameters();

        $this->assertGreaterThanOrEqual(2, count($params),
            'track() should accept at least contentId and contentType.');

        $this->assertEquals('contentId', $params[0]->getName());
        $this->assertEquals('string', (string) $params[0]->getType());

        $this->assertEquals('contentType', $params[1]->getName());
        $this->assertEquals('string', (string) $params[1]->getType());

        // Third parameter is optional action with default.
        if (count($params) >= 3) {
            $this->assertEquals('action', $params[2]->getName());
            $this->assertTrue($params[2]->isDefaultValueAvailable());
            $this->assertEquals('index', $params[2]->getDefaultValue());
        }
    }

    public function test_get_pending_method_exists(): void
    {
        $ref = new ReflectionClass(ScoltaTracker::class);
        $this->assertTrue($ref->hasMethod('getPending'),
            'ScoltaTracker should have a getPending() static method.');
    }

    public function test_get_pending_is_static(): void
    {
        $method = new ReflectionMethod(ScoltaTracker::class, 'getPending');
        $this->assertTrue($method->isStatic(), 'getPending() should be static.');
        $this->assertTrue($method->isPublic(), 'getPending() should be public.');
    }

    public function test_get_pending_count_method_exists(): void
    {
        $ref = new ReflectionClass(ScoltaTracker::class);
        $this->assertTrue($ref->hasMethod('getPendingCount'),
            'ScoltaTracker should have a getPendingCount() static method.');
    }

    public function test_get_pending_count_is_static(): void
    {
        $method = new ReflectionMethod(ScoltaTracker::class, 'getPendingCount');
        $this->assertTrue($method->isStatic(), 'getPendingCount() should be static.');
    }

    public function test_get_pending_count_returns_int(): void
    {
        $method = new ReflectionMethod(ScoltaTracker::class, 'getPendingCount');
        $returnType = $method->getReturnType();

        $this->assertNotNull($returnType);
        $this->assertEquals('int', (string) $returnType);
    }

    public function test_clear_all_method_exists(): void
    {
        $ref = new ReflectionClass(ScoltaTracker::class);
        $this->assertTrue($ref->hasMethod('clearAll'),
            'ScoltaTracker should have a clearAll() static method.');
    }

    public function test_clear_all_is_static(): void
    {
        $method = new ReflectionMethod(ScoltaTracker::class, 'clearAll');
        $this->assertTrue($method->isStatic(), 'clearAll() should be static.');
        $this->assertTrue($method->isPublic(), 'clearAll() should be public.');
    }

    public function test_clear_all_returns_int(): void
    {
        $method = new ReflectionMethod(ScoltaTracker::class, 'clearAll');
        $returnType = $method->getReturnType();

        $this->assertNotNull($returnType);
        $this->assertEquals('int', (string) $returnType);
    }

    public function test_mark_all_for_reindex_method_exists(): void
    {
        $ref = new ReflectionClass(ScoltaTracker::class);
        $this->assertTrue($ref->hasMethod('markAllForReindex'),
            'ScoltaTracker should have a markAllForReindex() static method.');
    }

    public function test_mark_all_for_reindex_is_static(): void
    {
        $method = new ReflectionMethod(ScoltaTracker::class, 'markAllForReindex');
        $this->assertTrue($method->isStatic(), 'markAllForReindex() should be static.');
        $this->assertTrue($method->isPublic(), 'markAllForReindex() should be public.');
    }

    public function test_mark_all_for_reindex_returns_int(): void
    {
        $method = new ReflectionMethod(ScoltaTracker::class, 'markAllForReindex');
        $returnType = $method->getReturnType();

        $this->assertNotNull($returnType);
        $this->assertEquals('int', (string) $returnType);
    }

    // -------------------------------------------------------------------
    // Casts
    // -------------------------------------------------------------------

    public function test_casts_method_exists(): void
    {
        $ref = new ReflectionClass(ScoltaTracker::class);
        $this->assertTrue($ref->hasMethod('casts'),
            'ScoltaTracker should define a casts() method.');
    }
}
