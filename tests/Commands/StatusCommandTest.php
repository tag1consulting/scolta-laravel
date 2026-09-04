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
 * Three defects are pinned here: the page count read from a fragment-directory
 * listing, the Pagefind binary probed even under the PHP indexer, and the
 * report being human prose only.
 */
class StatusCommandTest extends TestCase
{
    private string $outputDir;

    private string $buildDir;

    protected function getPackageProviders($app): array
    {
        return [ScoltaServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->outputDir = storage_path('framework/testing/scolta-status-output');
        $this->buildDir = storage_path('framework/testing/scolta-status-build');
        File::deleteDirectory($this->outputDir);
        File::deleteDirectory($this->buildDir);

        config([
            'scolta.pagefind.output_dir' => $this->outputDir,
            'scolta.pagefind.build_dir' => $this->buildDir,
            'scolta.models' => [],
            'scolta.indexer' => 'php',
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->outputDir);
        File::deleteDirectory($this->buildDir);

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
        // The flat layout the binary pipeline and Cloud flatten step write.
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
    // The binary is probed only when it is the active indexer. A hang or a
    // binary section in the report is the failure these tests catch; the
    // indexer=binary path is left unexercised because it would shell out in CI.
    // -------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $indexer  The `indexer` section of the JSON report.
     * @param  string  $output  The human report for the same configuration.
     */
    private function assertReportsNoBinary(array $indexer, string $output): void
    {
        $this->assertSame('php', $indexer['active']);
        $this->assertArrayNotHasKey('binary', $indexer,
            'Nothing may probe the Pagefind binary when the PHP indexer is active.');
        foreach (['Binary:', 'NOT AVAILABLE', 'npm install -g pagefind'] as $line) {
            $this->assertStringNotContainsString($line, $output,
                'The human report must carry no binary section under the PHP indexer.');
        }
    }

    public function test_no_binary_probe_under_the_php_indexer(): void
    {
        config(['scolta.indexer' => 'php']);

        $this->assertReportsNoBinary(
            json_decode($this->runStatus(json: true), true)['indexer'],
            $this->runStatus(),
        );
    }

    public function test_no_binary_probe_under_the_auto_indexer(): void
    {
        // Unset, as a site that never chose an indexer has it: auto, which
        // resolves to php exactly as scolta:build resolves it.
        config(['scolta.indexer' => null]);

        $status = json_decode($this->runStatus(json: true), true);

        $this->assertSame('auto', $status['indexer']['configured']);
        $this->assertReportsNoBinary($status['indexer'], $this->runStatus());
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
        foreach (['tracker', 'content', 'build_directory', 'pagefind_index', 'indexer', 'ai_provider', 'assets'] as $section) {
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
            '--- Build Directory ---',
            '--- Pagefind Index ---',
            '--- Indexer ---',
            '--- AI Provider ---',
            '--- Assets ---',
        ] as $heading) {
            $this->assertStringContainsString($heading, $output);
        }
        $this->assertStringContainsString('Pages:      7', $output);
        $this->assertStringContainsString('Active indexer: php (forced)', $output);
    }

    public function test_json_option_is_declared_on_the_command(): void
    {
        $definition = Artisan::all()['scolta:status']->getDefinition();

        $this->assertTrue($definition->hasOption('json'));
        $this->assertFalse($definition->getOption('json')->acceptValue(),
            '--json is a flag, not a format argument.');
    }
}
