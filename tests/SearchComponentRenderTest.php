<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Tests;

use Illuminate\Support\Facades\Blade;
use Orchestra\Testbench\TestCase;
use Tag1\ScoltaLaravel\ScoltaServiceProvider;

/**
 * Renders the search Blade component in a booted app and asserts the
 * actual emitted window.scolta URL values.
 *
 * BladeComponentTest only file_get_contents() the template and grep()s for
 * substrings like 'pagefindPath' — it never renders, so it cannot catch a
 * template that keeps the right variable names but emits a wrong URL. This
 * test boots the package (so config(), asset(), url(), the scolta:: view
 * namespace and ScoltaAiService all resolve), renders the component against
 * both Pagefind layouts, parses the JSON out of the window.scolta = {…}
 * script block, and asserts the emitted values — the per-adapter path
 * convention (Laravel serves the index from /scolta-pagefind/…) as a
 * behavioral contract rather than a source-text match.
 */
class SearchComponentRenderTest extends TestCase
{
    /**
     * Absolute path to the Pagefind output dir the component probes.
     * Equals public_path('scolta-pagefind') once the app is booted.
     */
    private string $outputDir;

    protected function getPackageProviders($app): array
    {
        return [ScoltaServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->outputDir = public_path('scolta-pagefind');

        // Pin the path/route config so the assertions don't depend on the
        // testbench skeleton's env defaults.
        config([
            'scolta.pagefind.output_dir' => $this->outputDir,
            'scolta.route_prefix' => 'api/scolta/v1',
        ]);

        // Published front-end assets the script/link tags are file_exists-guarded
        // on. Empty files are enough — asset()/filemtime() only need them to exist.
        $this->writeFile(public_path('vendor/scolta/scolta.js'), '');
        $this->writeFile(public_path('vendor/scolta/scolta.css'), '');
        $this->writeFile(public_path('vendor/scolta/wasm/scolta_core.js'), '');
    }

    protected function tearDown(): void
    {
        $this->removeDir(public_path('scolta-pagefind'));
        $this->removeDir(public_path('vendor/scolta'));

        parent::tearDown();
    }

    // -------------------------------------------------------------------
    // Nested layout: {output_dir}/pagefind/pagefind-entry.json
    //   → index served from /scolta-pagefind/pagefind
    // -------------------------------------------------------------------

    public function test_nested_layout_emits_expected_urls(): void
    {
        $this->writeFile($this->outputDir.'/pagefind/pagefind-entry.json', '{}');

        $config = $this->renderAndParseConfig();

        $this->assertStringEndsWith(
            '/scolta-pagefind/pagefind/pagefind.js',
            $config['pagefindPath'],
            'Nested layout must emit pagefindPath under /scolta-pagefind/pagefind/.'
        );
        $this->assertCommonValues($config);
    }

    // -------------------------------------------------------------------
    // Flat layout: {output_dir}/pagefind-entry.json
    //   → index served from /scolta-pagefind
    // -------------------------------------------------------------------

    public function test_flat_layout_emits_expected_urls(): void
    {
        $this->writeFile($this->outputDir.'/pagefind-entry.json', '{}');

        $config = $this->renderAndParseConfig();

        $this->assertStringEndsWith(
            '/scolta-pagefind/pagefind.js',
            $config['pagefindPath'],
            'Flat layout must emit pagefindPath at the /scolta-pagefind root.'
        );
        $this->assertCommonValues($config);
    }

    // -------------------------------------------------------------------
    // No index anywhere → warning, and no window.scolta config
    // -------------------------------------------------------------------

    public function test_not_built_shows_warning_and_no_config(): void
    {
        // No pagefind-entry.json fixture written in either location.
        $html = $this->render();

        $this->assertStringContainsString(
            'Search index has not been built yet',
            $html,
            'With no index, the component must render the build warning.'
        );
        $this->assertStringNotContainsString(
            'window.scolta =',
            $html,
            'With no index, the component must not emit a window.scolta config.'
        );
    }

    // -------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------

    /**
     * Assertions that hold for both layouts: endpoint URLs, wasm path,
     * container id, and the presence of the container div.
     *
     * @param  array<string, mixed>  $config
     */
    private function assertCommonValues(array $config): void
    {
        $this->assertStringEndsWith(
            '/vendor/scolta/wasm/scolta_core.js',
            $config['wasmPath'],
            'wasmPath must point at the published vendor wasm loader.'
        );
        $this->assertStringEndsWith('/api/scolta/v1/expand-query', $config['endpoints']['expand']);
        $this->assertStringEndsWith('/api/scolta/v1/summarize', $config['endpoints']['summarize']);
        $this->assertStringEndsWith('/api/scolta/v1/followup', $config['endpoints']['followup']);
        $this->assertSame('#scolta-search', $config['container']);
    }

    /**
     * Render the component, assert the container div is present, parse the
     * window.scolta config object out of the script block, and return it.
     *
     * @return array<string, mixed>
     */
    private function renderAndParseConfig(): array
    {
        $html = $this->render();

        $this->assertStringContainsString(
            '<div id="scolta-search"',
            $html,
            'Rendered HTML must contain the scolta-search container div.'
        );

        // Capture the JSON object assigned to window.scolta and decode it, so
        // this is a value assertion rather than another substring match.
        $this->assertMatchesRegularExpression(
            '/window\.scolta\s*=\s*(\{.*?\});/s',
            $html,
            'Rendered HTML must assign a JSON object to window.scolta.'
        );
        preg_match('/window\.scolta\s*=\s*(\{.*?\});/s', $html, $m);

        $config = json_decode($m[1], true);
        $this->assertIsArray($config, 'window.scolta payload must decode to an array.');

        return $config;
    }

    private function render(): string
    {
        // Render via the component tag (not view()->render()) so the Blade
        // component's $attributes bag is bound — the template emits it on the
        // container div.
        return Blade::render('<x-scolta::search />');
    }

    private function writeFile(string $path, string $contents): void
    {
        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($path, $contents);
    }

    private function removeDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
