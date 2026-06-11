<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Tests\Jobs;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Orchestra\Testbench\TestCase;
use Tag1\ScoltaLaravel\Jobs\FinalizeIndex;
use Tag1\ScoltaLaravel\ScoltaServiceProvider;

/**
 * FinalizeIndex must fail loudly when finalization fails.
 *
 * Regression: a failed finalize report was silently discarded, so the
 * whole job chain looked successful while no index was published. The
 * job must throw so the failure lands in failed_jobs / Horizon.
 */
class FinalizeIndexFailureTest extends TestCase
{
    private string $stateDir;

    private string $outputDir;

    protected function getPackageProviders($app): array
    {
        return [ScoltaServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->stateDir = storage_path('framework/testing/scolta-finalize-state');
        $this->outputDir = storage_path('framework/testing/scolta-finalize-output');
        File::deleteDirectory($this->stateDir);
        File::deleteDirectory($this->outputDir);
        File::ensureDirectoryExists($this->stateDir);
        File::ensureDirectoryExists($this->outputDir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->stateDir);
        File::deleteDirectory($this->outputDir);

        parent::tearDown();
    }

    public function test_failed_finalize_throws_instead_of_swallowing(): void
    {
        // An empty state directory has no committed chunks, so finalize
        // reports success=false ('No chunk files found in state directory.').
        $job = new FinalizeIndex($this->stateDir, $this->outputDir);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Scolta index finalize failed');

        $job->handle();
    }

    public function test_failed_finalize_does_not_bump_cache_generation(): void
    {
        Cache::put('scolta_expand_generation', 7);

        try {
            (new FinalizeIndex($this->stateDir, $this->outputDir))->handle();
            $this->fail('Expected RuntimeException from failed finalize.');
        } catch (\RuntimeException) {
            // Expected.
        }

        $this->assertSame(7, Cache::get('scolta_expand_generation'),
            'A failed finalize must not invalidate AI caches.');
    }
}
