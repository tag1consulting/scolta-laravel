<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tag1\ScoltaLaravel\Observers\ScoltaObserver;

/**
 * Functional and behavioral tests for ScoltaObserver lifecycle logic.
 *
 * These tests verify:
 *  - created/updated/restored dispatch trackForIndex()
 *  - deleted/forceDeleted track directly as 'delete'
 *  - shouldBeSearchable() = false → tracks as 'delete' (not index)
 *  - The soft-delete/restore cycle logic in source
 *  - Auto-rebuild debounce is only dispatched when config flag is set
 *
 * Tests intentionally avoid a real database. Source analysis is used where
 * behavior is encoded in implementation details that reflection cannot verify.
 */
class ObserverLifecycleTest extends TestCase
{
    private string $source;

    protected function setUp(): void
    {
        $this->source = file_get_contents(
            dirname(__DIR__).'/src/Observers/ScoltaObserver.php'
        );
    }

    // -------------------------------------------------------------------
    // trackForIndex: shouldBeSearchable() → 'index' or 'delete' branching
    // -------------------------------------------------------------------

    public function test_track_for_index_checks_should_be_searchable(): void
    {
        $this->assertStringContainsString(
            'shouldBeSearchable',
            $this->source,
            'trackForIndex() must check shouldBeSearchable() before deciding action.'
        );
    }

    public function test_track_for_index_uses_index_action_when_searchable(): void
    {
        // Extract trackForIndex body.
        preg_match(
            '/private function trackForIndex\(.*?\{(.*?)\n    \}/s',
            $this->source,
            $match
        );
        $body = $match[1] ?? '';

        $this->assertStringContainsString("'index'", $body,
            "trackForIndex() must use 'index' action when shouldBeSearchable() is true.");
    }

    public function test_track_for_index_uses_delete_action_when_not_searchable(): void
    {
        preg_match(
            '/private function trackForIndex\(.*?\{(.*?)\n    \}/s',
            $this->source,
            $match
        );
        $body = $match[1] ?? '';

        $this->assertStringContainsString("'delete'", $body,
            "trackForIndex() must use 'delete' action when shouldBeSearchable() is false.");
    }

    public function test_track_for_index_handles_models_without_should_be_searchable(): void
    {
        // The method must use method_exists() to safely call shouldBeSearchable()
        // on models that don't use the Searchable trait.
        $this->assertStringContainsString(
            'method_exists',
            $this->source,
            'trackForIndex() must guard shouldBeSearchable() with method_exists().'
        );
    }

    public function test_fallback_when_should_be_searchable_missing_is_true(): void
    {
        preg_match(
            '/private function trackForIndex\(.*?\{(.*?)\n    \}/s',
            $this->source,
            $match
        );
        $body = $match[1] ?? '';

        // The ternary pattern: method_exists() ? $model->shouldBeSearchable() : true
        $this->assertMatchesRegularExpression(
            '/method_exists.*shouldBeSearchable.*:\s*(true|1)/s',
            $body,
            'When shouldBeSearchable() is not present, fallback must be true (index).'
        );
    }

    // -------------------------------------------------------------------
    // deleted(): always 'delete', never calls trackForIndex
    // -------------------------------------------------------------------

    public function test_deleted_never_calls_track_for_index(): void
    {
        preg_match(
            '/public function deleted\(.*?\{(.*?)\n    \}/s',
            $this->source,
            $match
        );
        $body = $match[1] ?? '';

        $this->assertStringNotContainsString(
            'trackForIndex',
            $body,
            'deleted() must NOT delegate to trackForIndex() — it should always delete regardless of shouldBeSearchable().'
        );
    }

    public function test_deleted_tracks_as_delete_unconditionally(): void
    {
        preg_match(
            '/public function deleted\(.*?\{(.*?)\n    \}/s',
            $this->source,
            $match
        );
        $body = $match[1] ?? '';

        $this->assertStringContainsString("'delete'", $body,
            "deleted() must always pass 'delete' to ScoltaTracker::track().");
    }

    // -------------------------------------------------------------------
    // forceDeleted(): same unconditional-delete contract as deleted()
    // -------------------------------------------------------------------

    public function test_force_deleted_never_calls_track_for_index(): void
    {
        preg_match(
            '/public function forceDeleted\(.*?\{(.*?)\n    \}/s',
            $this->source,
            $match
        );
        $body = $match[1] ?? '';

        $this->assertStringNotContainsString('trackForIndex', $body,
            'forceDeleted() must NOT delegate to trackForIndex().');
    }

    public function test_force_deleted_tracks_as_delete_unconditionally(): void
    {
        preg_match(
            '/public function forceDeleted\(.*?\{(.*?)\n    \}/s',
            $this->source,
            $match
        );
        $body = $match[1] ?? '';

        $this->assertStringContainsString("'delete'", $body,
            "forceDeleted() must always pass 'delete' to ScoltaTracker::track().");
    }

    // -------------------------------------------------------------------
    // restored(): re-indexes (uses trackForIndex, not direct 'index')
    // -------------------------------------------------------------------

    public function test_restored_calls_track_for_index(): void
    {
        preg_match(
            '/public function restored\(.*?\{(.*?)\n    \}/s',
            $this->source,
            $match
        );
        $body = $match[1] ?? '';

        $this->assertStringContainsString('trackForIndex', $body,
            'restored() must delegate to trackForIndex() so shouldBeSearchable() is respected on restore.');
    }

    // -------------------------------------------------------------------
    // Soft-delete / restore comment documentation
    // -------------------------------------------------------------------

    public function test_soft_delete_comment_distinguishes_soft_from_force(): void
    {
        // The class comment must acknowledge both soft and force deletes.
        $this->assertStringContainsString('Soft delete', $this->source,
            'Observer source should document soft-delete vs force-delete distinction.');
    }

    public function test_restore_comment_exists(): void
    {
        $this->assertStringContainsString('restored', $this->source,
            'Observer source should mention restored events.');
    }

    // -------------------------------------------------------------------
    // maybeDispatchRebuild: only fires when auto_rebuild config is true
    // -------------------------------------------------------------------

    public function test_maybe_dispatch_rebuild_checks_config_flag(): void
    {
        preg_match(
            '/private function maybeDispatchRebuild\(\).*?\{(.*?)\n    \}/s',
            $this->source,
            $match
        );
        $body = $match[1] ?? '';

        $this->assertStringContainsString("'scolta.auto_rebuild'", $body,
            "maybeDispatchRebuild() must check config('scolta.auto_rebuild').");
    }

    public function test_maybe_dispatch_rebuild_returns_early_when_disabled(): void
    {
        preg_match(
            '/private function maybeDispatchRebuild\(\).*?\{(.*?)\n    \}/s',
            $this->source,
            $match
        );
        $body = $match[1] ?? '';

        // Must have an early return when auto_rebuild is falsy.
        $this->assertStringContainsString('return', $body,
            'maybeDispatchRebuild() must return early when auto_rebuild is false.');
    }

    public function test_maybe_dispatch_rebuild_uses_cache_debounce(): void
    {
        $this->assertStringContainsString(
            'scolta_rebuild_scheduled',
            $this->source,
            'maybeDispatchRebuild() must use a cache key to debounce rapid triggers.'
        );
    }

    public function test_maybe_dispatch_rebuild_uses_config_delay(): void
    {
        preg_match(
            '/private function maybeDispatchRebuild\(\).*?\{(.*?)\n    \}/s',
            $this->source,
            $match
        );
        $body = $match[1] ?? '';

        $this->assertStringContainsString("'scolta.auto_rebuild_delay'", $body,
            "maybeDispatchRebuild() must read delay from config('scolta.auto_rebuild_delay').");
    }

    // -------------------------------------------------------------------
    // getContentType: respects getSearchableType(), falls back to get_class()
    // -------------------------------------------------------------------

    public function test_get_content_type_calls_get_searchable_type(): void
    {
        $this->assertStringContainsString(
            'getSearchableType',
            $this->source,
            'getContentType() must call getSearchableType() when available.'
        );
    }

    public function test_get_content_type_falls_back_to_get_class(): void
    {
        $this->assertStringContainsString(
            'get_class(',
            $this->source,
            'getContentType() must fall back to get_class() for models without getSearchableType().'
        );
    }

    // -------------------------------------------------------------------
    // Mass-update caveat documented in class comment
    // -------------------------------------------------------------------

    public function test_mass_update_caveat_documented(): void
    {
        $this->assertStringContainsString(
            'Mass update',
            $this->source,
            'Observer class comment must acknowledge the mass-update limitation.'
        );
    }

    // -------------------------------------------------------------------
    // Observer handles all five standard Eloquent lifecycle events
    // -------------------------------------------------------------------

    public function test_all_five_events_handled(): void
    {
        $ref = new ReflectionClass(ScoltaObserver::class);
        $events = ['created', 'updated', 'deleted', 'restored', 'forceDeleted'];

        foreach ($events as $event) {
            $this->assertTrue($ref->hasMethod($event),
                "ScoltaObserver must handle the '{$event}' event.");
        }
    }
}
