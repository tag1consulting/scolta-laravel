<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Tests\Http;

use Illuminate\Support\Facades\File;
use Orchestra\Testbench\TestCase;
use Tag1\Scolta\Index\BuildState;
use Tag1\ScoltaLaravel\Http\Controllers\ProgressController;
use Tag1\ScoltaLaravel\ScoltaServiceProvider;

/**
 * What GET /build-progress says about a build, phase by phase.
 */
class ProgressControllerResponseTest extends TestCase
{
    private string $stateDir;

    protected function getPackageProviders($app): array
    {
        return [ScoltaServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->stateDir = storage_path('framework/testing/scolta-progress-state');
        File::deleteDirectory($this->stateDir);
        config(['scolta.state_dir' => $this->stateDir]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->stateDir);
        parent::tearDown();
    }

    /**
     * @return array<string, mixed>
     */
    private function progress(): array
    {
        return (new ProgressController)()->getData(true);
    }

    public function test_no_build_is_idle(): void
    {
        $this->assertSame('idle', $this->progress()['status']);
    }

    public function test_a_gathering_build_reports_its_progress(): void
    {
        $state = new BuildState($this->stateDir);
        $this->assertTrue($state->initiateBuild(['total_pages' => 1000, 'chunk_size' => 100, 'chunks_written' => 4, 'pages_processed' => 400]));

        $json = $this->progress();
        $this->assertSame('building', $json['status']);
        $this->assertSame('gathering', $json['phase']);
        $this->assertSame(0.4, $json['progress']);
        $this->assertSame(400, $json['pages_processed']);

        $state->releaseLock();
    }

    public function test_a_merging_build_names_the_phase_and_drops_progress(): void
    {
        $state = new BuildState($this->stateDir);
        $this->assertTrue($state->initiateBuild(['total_pages' => 1000]));
        $state->enterPhase(BuildState::PHASE_MERGING);

        $json = $this->progress();
        $this->assertSame('building', $json['status']);
        $this->assertSame('merging', $json['phase']);
        $this->assertArrayNotHasKey('progress', $json,
            'The gather ratio over-estimates the chunk count, so it says nothing about the merge.');

        $state->releaseLock();
    }
}
