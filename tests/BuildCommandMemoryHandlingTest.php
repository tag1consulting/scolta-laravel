<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tag1\ScoltaLaravel\Commands\BuildCommand;
use Tag1\ScoltaLaravel\Jobs\FinalizeIndex;

/**
 * Structural tests for memory_abort and index_only_complete handling in BuildCommand.
 *
 * Behavioral testing of spawnResumeProcess() and FinalizeIndex dispatch is
 * impractical without a full Laravel application and queue worker. These
 * structural tests read the source file and use reflection to verify that
 * each branch exists, is guarded correctly, and routes to the right mechanism.
 * They guard against accidental regression of the conditions that make
 * auto-resume work after scolta-php#107.
 */
class BuildCommandMemoryHandlingTest extends TestCase
{
    private string $source;

    protected function setUp(): void
    {
        $this->source = file_get_contents(dirname(__DIR__).'/src/Commands/BuildCommand.php');
    }

    // -------------------------------------------------------------------
    // memory_abort branch
    // -------------------------------------------------------------------

    public function test_build_command_handles_memory_abort(): void
    {
        $this->assertStringContainsString(
            "=== 'memory_abort'",
            $this->source,
            'buildWithPhpIndexer() must have a memory_abort branch to avoid FAILURE on memory pressure'
        );
    }

    public function test_memory_abort_with_chunks_spawns_resume(): void
    {
        $this->assertStringContainsString(
            'spawnResumeProcess',
            $this->source,
            'memory_abort handler must call spawnResumeProcess() when chunks are committed'
        );
    }

    public function test_memory_abort_checks_chunks_written(): void
    {
        $this->assertStringContainsString(
            'chunksWritten > 0',
            $this->source,
            'memory_abort handler must guard on chunksWritten > 0 before spawning resume'
        );
    }

    public function test_memory_abort_with_no_chunks_emits_error(): void
    {
        $this->assertStringContainsString(
            'Memory limit hit before any chunks were committed',
            $this->source,
            'When memory_abort occurs with 0 committed chunks, a helpful error must be emitted'
        );
    }

    // -------------------------------------------------------------------
    // index_only_complete branch
    // -------------------------------------------------------------------

    public function test_build_command_handles_index_only_complete(): void
    {
        $this->assertStringContainsString(
            "=== 'index_only_complete'",
            $this->source,
            'buildWithPhpIndexer() must have an index_only_complete branch for finalization'
        );
    }

    public function test_index_only_complete_dispatches_finalize_job(): void
    {
        $this->assertStringContainsString(
            'FinalizeIndex::dispatch(',
            $this->source,
            'index_only_complete must dispatch FinalizeIndex so merge runs in a fresh queue worker'
        );
    }

    public function test_finalize_index_job_class_exists(): void
    {
        $this->assertTrue(
            class_exists(FinalizeIndex::class),
            'FinalizeIndex job class must exist'
        );
    }

    // -------------------------------------------------------------------
    // spawnResumeProcess()
    // -------------------------------------------------------------------

    public function test_spawn_resume_process_method_exists(): void
    {
        $ref = new ReflectionClass(BuildCommand::class);
        $this->assertTrue(
            $ref->hasMethod('spawnResumeProcess'),
            'BuildCommand must have a spawnResumeProcess() method'
        );
    }

    public function test_spawn_resume_process_uses_sync_flag(): void
    {
        // --sync forces inline execution so the child process controls its own
        // memory lifecycle. Without it, artisan dispatches to the queue and
        // the background pattern doesn't govern the child's heap.
        $this->assertStringContainsString(
            '--sync',
            $this->source,
            'spawnResumeProcess() must pass --sync to force inline execution in the child process'
        );
    }

    public function test_spawn_resume_process_passes_resume_flag(): void
    {
        $this->assertStringContainsString(
            '--resume',
            $this->source,
            'spawnResumeProcess() must pass --resume to continue from the last committed chunk'
        );
    }

    public function test_spawn_resume_process_logs_log_file_path(): void
    {
        $this->assertStringContainsString(
            'scolta-resume.log',
            $this->source,
            'spawnResumeProcess() must log the path to the resume log file'
        );
    }

    // -------------------------------------------------------------------
    // Non-regression: success path still increments generation
    // -------------------------------------------------------------------

    public function test_success_path_increments_generation(): void
    {
        $this->assertStringContainsString(
            "Cache::increment('scolta_expand_generation')",
            $this->source,
            'Success path must still increment scolta_expand_generation'
        );
    }

    // -------------------------------------------------------------------
    // Non-regression: unknown errors still return FAILURE
    // -------------------------------------------------------------------

    public function test_unknown_errors_return_failure(): void
    {
        $this->assertStringContainsString(
            'Unknown error',
            $this->source,
            'buildWithPhpIndexer() must have a fallback that returns FAILURE for unrecognised errors'
        );
    }

    // -------------------------------------------------------------------
    // Return-code contract: auto-resume paths are not failures
    // -------------------------------------------------------------------

    public function test_memory_abort_branch_returns_result_of_spawn(): void
    {
        $this->assertStringContainsString(
            'return $this->spawnResumeProcess(',
            $this->source,
            'memory_abort with chunks > 0 must return the result of spawnResumeProcess() (which is SUCCESS)'
        );
    }

    public function test_index_only_complete_returns_success(): void
    {
        // After dispatching FinalizeIndex, the command must return SUCCESS
        // rather than leaking through to the generic error handler.
        $this->assertStringContainsString(
            'return self::SUCCESS;',
            $this->source,
            'index_only_complete path must return SUCCESS after dispatching FinalizeIndex'
        );
    }
}
