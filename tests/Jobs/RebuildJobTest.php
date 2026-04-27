<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Tag1\ScoltaLaravel\Jobs\FinalizeIndex;
use Tag1\ScoltaLaravel\Jobs\ProcessIndexChunk;
use Tag1\ScoltaLaravel\Jobs\TriggerRebuild;

/**
 * Structural tests for TriggerRebuild, ProcessIndexChunk, and FinalizeIndex.
 *
 * Verifies that the job classes are wired correctly — they implement
 * ShouldQueue, their constructors have the right signatures, and the
 * observer dispatches the right job when auto_rebuild is enabled.
 *
 * Full integration tests (Bus::fake, RefreshDatabase) require a running
 * Laravel application; these tests cover the structural contracts that
 * can be verified without a framework bootstrap.
 */
class RebuildJobTest extends TestCase
{
    // -------------------------------------------------------------------
    // TriggerRebuild
    // -------------------------------------------------------------------

    public function test_trigger_rebuild_implements_should_queue(): void
    {
        $reflection = new ReflectionClass(TriggerRebuild::class);

        $interfaces = $reflection->getInterfaceNames();
        $this->assertContains(
            'Illuminate\Contracts\Queue\ShouldQueue',
            $interfaces,
            'TriggerRebuild must implement ShouldQueue'
        );
    }

    public function test_trigger_rebuild_constructor_accepts_force_flag(): void
    {
        $reflection = new ReflectionClass(TriggerRebuild::class);
        $constructor = $reflection->getConstructor();

        $this->assertNotNull($constructor, 'TriggerRebuild must have a constructor');

        $params = $constructor->getParameters();
        $paramNames = array_map(fn ($p) => $p->getName(), $params);

        $this->assertContains('force', $paramNames, 'TriggerRebuild constructor must accept $force parameter');
    }

    public function test_trigger_rebuild_force_defaults_to_false(): void
    {
        $reflection = new ReflectionClass(TriggerRebuild::class);
        $constructor = $reflection->getConstructor();
        $params = $constructor->getParameters();

        foreach ($params as $param) {
            if ($param->getName() === 'force') {
                $this->assertTrue($param->isOptional(), '$force must be optional');
                $this->assertFalse($param->getDefaultValue(), '$force must default to false');

                return;
            }
        }

        $this->fail('TriggerRebuild must have a $force parameter');
    }

    // -------------------------------------------------------------------
    // ProcessIndexChunk
    // -------------------------------------------------------------------

    public function test_process_index_chunk_implements_should_queue(): void
    {
        $reflection = new ReflectionClass(ProcessIndexChunk::class);

        $this->assertContains(
            'Illuminate\Contracts\Queue\ShouldQueue',
            $reflection->getInterfaceNames(),
            'ProcessIndexChunk must implement ShouldQueue'
        );
    }

    // -------------------------------------------------------------------
    // FinalizeIndex
    // -------------------------------------------------------------------

    public function test_finalize_index_implements_should_queue(): void
    {
        $reflection = new ReflectionClass(FinalizeIndex::class);

        $this->assertContains(
            'Illuminate\Contracts\Queue\ShouldQueue',
            $reflection->getInterfaceNames(),
            'FinalizeIndex must implement ShouldQueue'
        );
    }

    public function test_finalize_index_default_memory_budget(): void
    {
        $job = new FinalizeIndex('/tmp/state', '/tmp/output');
        $reflection = new ReflectionProperty($job, 'memoryBudget');
        $this->assertSame('conservative', $reflection->getValue($job));
    }

    public function test_trigger_rebuild_does_not_pass_fingerprint_to_finalize_index(): void
    {
        $source = file_get_contents(__DIR__.'/../../src/Jobs/TriggerRebuild.php');
        preg_match('/new FinalizeIndex\s*\(([^;]+)\)/s', $source, $matches);
        if (! empty($matches[1])) {
            $this->assertStringNotContainsString(
                '$fingerprint',
                $matches[1],
                'TriggerRebuild must not pass $fingerprint to FinalizeIndex constructor.'
            );
        }
    }

    // -------------------------------------------------------------------
    // ScoltaObserver dispatches TriggerRebuild
    // -------------------------------------------------------------------

    public function test_observer_source_dispatches_trigger_rebuild(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 2).'/src/Observers/ScoltaObserver.php'
        );

        $this->assertStringContainsString(
            'TriggerRebuild',
            $source,
            'ScoltaObserver must dispatch TriggerRebuild when auto_rebuild is enabled'
        );
    }

    public function test_observer_source_guards_with_auto_rebuild_config(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 2).'/src/Observers/ScoltaObserver.php'
        );

        $this->assertStringContainsString(
            "config('scolta.auto_rebuild'",
            $source,
            'ScoltaObserver must check config(scolta.auto_rebuild) before dispatching'
        );
    }

    public function test_observer_uses_cache_based_debounce(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 2).'/src/Observers/ScoltaObserver.php'
        );

        $this->assertStringContainsString(
            'scolta_rebuild_scheduled',
            $source,
            'ScoltaObserver must use cache key scolta_rebuild_scheduled for debouncing'
        );
    }

    // -------------------------------------------------------------------
    // ScoltaServiceProvider registers TriggerRebuild dispatch
    // -------------------------------------------------------------------

    public function test_service_provider_dispatches_trigger_rebuild_on_first_run(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 2).'/src/ScoltaServiceProvider.php'
        );

        $this->assertStringContainsString(
            'TriggerRebuild::dispatch()',
            $source,
            'ScoltaServiceProvider must dispatch TriggerRebuild for first-run auto-build'
        );
    }
}
