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

    public function test_process_index_chunk_uses_queueable_trait(): void
    {
        // Bus::chain() calls $firstJob->chain() which is provided by the
        // Queueable trait. Without it, dispatching a chain throws
        // "Call to undefined method ProcessIndexChunk::chain()".
        $traits = class_uses_recursive(ProcessIndexChunk::class);
        $this->assertArrayHasKey(
            'Illuminate\Bus\Queueable',
            $traits,
            'ProcessIndexChunk must use the Queueable trait so Bus::chain() can call chain() on it'
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

    public function test_finalize_index_uses_queueable_trait(): void
    {
        // Same requirement as ProcessIndexChunk — Bus::chain() needs chain()
        // from Queueable on all jobs in the chain, including the last one.
        $traits = class_uses_recursive(FinalizeIndex::class);
        $this->assertArrayHasKey(
            'Illuminate\Bus\Queueable',
            $traits,
            'FinalizeIndex must use the Queueable trait so Bus::chain() can call chain() on it'
        );
    }

    public function test_finalize_index_default_memory_budget(): void
    {
        $job = new FinalizeIndex('/tmp/state', '/tmp/output');
        $reflection = new ReflectionProperty($job, 'memoryBudget');
        $this->assertSame('conservative', $reflection->getValue($job));
    }

    public function test_trigger_rebuild_does_not_construct_jobs_directly(): void
    {
        // Job construction (and the old $fingerprint-passing bug) lives in
        // QueueRebuildDispatcher now — TriggerRebuild only delegates.
        $source = file_get_contents(__DIR__.'/../../src/Jobs/TriggerRebuild.php');

        $this->assertStringNotContainsString(
            'new FinalizeIndex',
            $source,
            'TriggerRebuild must not construct FinalizeIndex — QueueRebuildDispatcher owns the job chain.'
        );
        $this->assertStringNotContainsString(
            'new ProcessIndexChunk',
            $source,
            'TriggerRebuild must not construct ProcessIndexChunk — QueueRebuildDispatcher owns the job chain.'
        );
    }

    public function test_trigger_rebuild_uses_queueable_trait(): void
    {
        // Queueable is needed for consistency with ProcessIndexChunk and FinalizeIndex,
        // and is required if TriggerRebuild is ever used in a chain.
        $traits = class_uses_recursive(TriggerRebuild::class);
        $this->assertArrayHasKey(
            'Illuminate\Bus\Queueable',
            $traits,
            'TriggerRebuild must use the Queueable trait, matching the standard Laravel job template'
        );
    }

    // -------------------------------------------------------------------
    // Bus::chain() dispatch pattern — prevent regression
    //
    // The crash "Call to undefined method ProcessIndexChunk::chain()" happened
    // because the Queueable trait was missing. These source-analysis tests
    // verify that the correct dispatch pattern (Bus::chain($array)->dispatch())
    // is used in every place that builds a job chain, so a future accidental
    // revert of the Queueable trait would be caught immediately.
    // -------------------------------------------------------------------

    public function test_queue_dispatcher_uses_bus_chain_facade_not_static_job_method(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2).'/src/Services/QueueRebuildDispatcher.php');

        $this->assertStringContainsString(
            'Bus::chain(',
            $source,
            'QueueRebuildDispatcher must use Bus::chain() to build the job chain.'
        );
        $this->assertStringNotContainsString(
            'ProcessIndexChunk::chain(',
            $source,
            'QueueRebuildDispatcher must not call chain() as a static method on ProcessIndexChunk.'
        );
        $this->assertStringNotContainsString(
            'FinalizeIndex::chain(',
            $source,
            'QueueRebuildDispatcher must not call chain() as a static method on FinalizeIndex.'
        );
    }

    public function test_queue_dispatcher_dispatches_chain(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2).'/src/Services/QueueRebuildDispatcher.php');

        $this->assertMatchesRegularExpression(
            '/Bus::chain\(\$jobs\)\s*->\s*dispatch\(\)/',
            $source,
            'QueueRebuildDispatcher must call ->dispatch() on the Bus::chain() result.'
        );
    }

    public function test_build_command_and_trigger_rebuild_delegate_to_queue_dispatcher(): void
    {
        // Both queue-dispatch entry points must share QueueRebuildDispatcher
        // so the publish filters, chunk-file payloads, and memory budget
        // handling cannot diverge again.
        $buildCommand = file_get_contents(dirname(__DIR__, 2).'/src/Commands/BuildCommand.php');
        $triggerRebuild = file_get_contents(dirname(__DIR__, 2).'/src/Jobs/TriggerRebuild.php');

        $this->assertStringContainsString(
            'QueueRebuildDispatcher',
            $buildCommand,
            'BuildCommand must delegate queue dispatch to QueueRebuildDispatcher.'
        );
        $this->assertStringContainsString(
            'QueueRebuildDispatcher',
            $triggerRebuild,
            'TriggerRebuild must delegate queue dispatch to QueueRebuildDispatcher.'
        );
        // Match actual call sites (line starts with code, not a docblock).
        $this->assertDoesNotMatchRegularExpression(
            '/^\s*Bus::chain\(/m',
            $buildCommand,
            'BuildCommand must not build its own job chain — that lives in QueueRebuildDispatcher.'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/^\s*Bus::chain\(/m',
            $triggerRebuild,
            'TriggerRebuild must not build its own job chain — that lives in QueueRebuildDispatcher.'
        );
    }

    // -------------------------------------------------------------------
    // --sync flag regression guard
    // -------------------------------------------------------------------

    public function test_build_command_has_sync_option(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2).'/src/Commands/BuildCommand.php');

        $this->assertStringContainsString(
            '--sync',
            $source,
            'BuildCommand must define a --sync option so the synchronous path remains available.'
        );
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
