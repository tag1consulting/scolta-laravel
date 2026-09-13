<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Tests\Commands;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;
use Tag1\ScoltaLaravel\ScoltaServiceProvider;
use Tag1\ScoltaLaravel\Tests\Support\SearchablePost;

/**
 * `scolta:status`: what it reports, where the numbers come from, and --json.
 *
 * Two defects are pinned here: the page count read from a fragment-directory
 * listing, and the report being human prose only.
 */
class StatusCommandTest extends TestCase
{
    private string $outputDir;

    private string $stateDir;

    protected function getPackageProviders($app): array
    {
        return [ScoltaServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->outputDir = storage_path('framework/testing/scolta-status-output');
        $this->stateDir = storage_path('framework/testing/scolta-status-state');
        File::deleteDirectory($this->outputDir);
        File::deleteDirectory($this->stateDir);

        config([
            'scolta.pagefind.output_dir' => $this->outputDir,
            'scolta.state_dir' => $this->stateDir,
            'scolta.models' => [],
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->outputDir);
        File::deleteDirectory($this->stateDir);

        parent::tearDown();
    }

    /**
     * Write a built index with an entry file claiming $pages indexed pages.
     */
    private function buildIndex(int $pages, int $fragmentFiles = 0): void
    {
        File::ensureDirectoryExists($this->outputDir.'/pagefind/fragment');
        File::put($this->outputDir.'/pagefind/pagefind.js', '// pagefind');
        File::put($this->outputDir.'/pagefind/pagefind-entry.json', (string) json_encode([
            'languages' => ['en' => ['page_count' => $pages]],
        ]));
        for ($i = 0; $i < $fragmentFiles; $i++) {
            File::put($this->outputDir."/pagefind/fragment/en_{$i}.pf_fragment", 'data');
        }
    }

    private function runStatus(bool $json = false): string
    {
        Artisan::call('scolta:status', $json ? ['--json' => true] : []);

        return Artisan::output();
    }

    // -------------------------------------------------------------------
    // The page count comes from pagefind-entry.json, not a directory listing.
    // -------------------------------------------------------------------

    public function test_page_count_comes_from_the_entry_file(): void
    {
        // 96000 indexed pages, one fragment file actually on disk: only the
        // entry file can produce the reported number.
        $this->buildIndex(pages: 96000, fragmentFiles: 1);

        $status = json_decode($this->runStatus(json: true), true);

        $this->assertTrue($status['pagefind_index']['built']);
        $this->assertSame(96000, $status['pagefind_index']['pages']);
        $this->assertStringContainsString('96000', $this->runStatus());
    }

    public function test_page_count_falls_back_to_the_fragment_listing(): void
    {
        // A build that wrote no entry file still has to report a number.
        File::ensureDirectoryExists($this->outputDir.'/pagefind/fragment');
        File::put($this->outputDir.'/pagefind/pagefind.js', '// pagefind');
        foreach (['a', 'b'] as $name) {
            File::put($this->outputDir."/pagefind/fragment/en_{$name}.pf_fragment", 'data');
        }

        $status = json_decode($this->runStatus(json: true), true);

        $this->assertSame(2, $status['pagefind_index']['pages']);
    }

    public function test_an_unbuilt_index_is_reported_as_such(): void
    {
        $status = json_decode($this->runStatus(json: true), true);

        $this->assertFalse($status['pagefind_index']['built']);
        $this->assertStringContainsString('no index built yet', $this->runStatus());
    }

    public function test_a_flat_index_is_found(): void
    {
        // The flat layout a pre-2.0 binary build or the Cloud flatten step writes.
        // Status used to call it "no index built yet".
        File::ensureDirectoryExists($this->outputDir);
        File::put($this->outputDir.'/pagefind.js', '// pagefind');
        File::put($this->outputDir.'/pagefind-entry.json', (string) json_encode([
            'languages' => ['en' => ['page_count' => 5]],
        ]));

        $status = json_decode($this->runStatus(json: true), true);

        $this->assertTrue($status['pagefind_index']['built']);
        $this->assertSame(5, $status['pagefind_index']['pages']);
    }

    // -------------------------------------------------------------------
    // --json: one document, on stdout, nothing else.
    // -------------------------------------------------------------------

    public function test_json_output_is_one_parseable_document(): void
    {
        $this->buildIndex(pages: 7);

        $output = $this->runStatus(json: true);
        $status = json_decode($output, true);

        $this->assertIsArray($status, "scolta:status --json must emit parseable JSON. Got:\n{$output}");
        $this->assertSame(JSON_ERROR_NONE, json_last_error());
        foreach (['tracker', 'content', 'pagefind_index', 'ai_provider', 'assets'] as $section) {
            $this->assertArrayHasKey($section, $status, "The JSON report must carry the {$section} section.");
        }
        $this->assertStringStartsWith('{', trim($output),
            'Nothing may precede the document on stdout, or `scolta:status --json | jq` breaks.');
    }

    public function test_content_counts_reach_both_reports(): void
    {
        Schema::create('searchable_posts', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('body');
            $table->boolean('published')->default(true);
            $table->boolean('unlisted')->default(false);
            $table->timestamps();
        });

        try {
            SearchablePost::create(['title' => 'Visible', 'body' => 'Body text.']);
            config(['scolta.models' => [SearchablePost::class]]);

            $status = json_decode($this->runStatus(json: true), true);

            $this->assertSame(['SearchablePost'], $status['content']['models']);
            $this->assertSame(1, $status['content']['published_count']);
            $this->assertSame([], $status['content']['models_without_trait']);
            $this->assertStringContainsString('  Models:    SearchablePost', $this->runStatus());
            $this->assertStringContainsString('  Published: 1', $this->runStatus());
        } finally {
            Schema::dropIfExists('searchable_posts');
        }
    }

    public function test_json_output_suppresses_the_human_report(): void
    {
        $output = $this->runStatus(json: true);

        foreach (['--- Tracker ---', '--- Pagefind Index ---', '--- AI Provider ---'] as $heading) {
            $this->assertStringNotContainsString($heading, $output);
        }
        // Warnings are decorative output too, and would break the parse.
        $this->assertStringNotContainsString('Tracker table does not exist', $output);
    }

    public function test_human_output_keeps_its_sections(): void
    {
        $this->buildIndex(pages: 7);

        $output = $this->runStatus();

        foreach ([
            '--- Tracker ---',
            '--- Content ---',
            '--- Pagefind Index ---',
            '--- AI Provider ---',
            '--- Assets ---',
        ] as $heading) {
            $this->assertStringContainsString($heading, $output);
        }
        $this->assertStringContainsString('Pages:      7', $output);
        $this->assertStringNotContainsString('--- Indexer ---', $output,
            'The indexer section went with the binary pipeline in 2.0.0; there is nothing to choose.');
    }

    // -------------------------------------------------------------------
    // The build section: what an operator watching a long build can read.
    // -------------------------------------------------------------------

    public function test_an_interrupted_build_is_reported(): void
    {
        // A manifest an interrupted build left behind, and the outcome its
        // last segment recorded before yielding on memory pressure.
        File::ensureDirectoryExists($this->stateDir);
        File::put($this->stateDir.'/manifest.json', (string) json_encode([
            'status' => 'building',
            'segment' => 3,
            'total_pages' => 1000,
            'chunk_size' => 100,
            'chunks_written' => 4,
            'pages_processed' => 400,
            'started_at' => '2026-09-12T10:00:00+00:00',
        ]));
        File::put($this->stateDir.'/segment-outcome.json', (string) json_encode([
            'success' => false,
            'error' => 'memory_abort',
            'pages_processed' => 400,
            'pid' => 4242,
            'recorded_at' => '2026-09-12T10:05:00+00:00',
        ]));

        $build = json_decode($this->runStatus(json: true), true)['build'];

        $this->assertSame(3, $build['segment']);
        $this->assertSame(400, $build['pages_processed']);
        $this->assertSame('40%', $build['progress']);
        $this->assertFalse($build['running'],
            'No process holds the lock, so the build is interrupted, not running.');
        $this->assertSame('2026-09-12T10:00:00+00:00', $build['started']);
        $this->assertSame('memory_abort', $build['last_segment']['error']);
        $this->assertFalse($build['last_segment']['success']);
        $this->assertSame(400, $build['last_segment']['pages_processed']);
        $this->assertSame('2026-09-12T10:05:00+00:00', $build['last_segment']['recorded_at']);

        $human = $this->runStatus();
        $this->assertStringContainsString('--- Build ---', $human);
        $this->assertStringContainsString('Segment:           3', $human);
        $this->assertStringContainsString('400 (40%)', $human);
        $this->assertStringContainsString('yielded on memory pressure', $human);
    }

    public function test_status_does_not_create_the_state_directory(): void
    {
        // BuildState's constructor mkdirs; reading status must not.
        $build = json_decode($this->runStatus(json: true), true)['build'];

        $this->assertDirectoryDoesNotExist($this->stateDir);
        $this->assertFalse($build['rebuild_requested']);
        $this->assertArrayNotHasKey('running', $build,
            'With no manifest on disk there is no in-flight build to describe.');
        $this->assertStringContainsString('In flight:         no', $this->runStatus());
    }

    public function test_json_option_is_declared_on_the_command(): void
    {
        $definition = Artisan::all()['scolta:status']->getDefinition();

        $this->assertTrue($definition->hasOption('json'));
        $this->assertFalse($definition->getOption('json')->acceptValue(),
            '--json is a flag, not a format argument.');
    }
}
