<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Tests;

use Illuminate\Support\Facades\Blade;
use Orchestra\Testbench\TestCase;
use Tag1\Scolta\Config\ScoltaConfig;
use Tag1\ScoltaLaravel\ScoltaServiceProvider;
use Tag1\ScoltaLaravel\Services\ScoltaAiService;

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
    // Facet visibility and filter descriptions
    // -------------------------------------------------------------------

    public function test_hide_empty_facets_is_emitted_true_by_default(): void
    {
        $this->writeFile($this->outputDir.'/pagefind/pagefind-entry.json', '{}');

        $config = $this->renderAndParseConfig();

        $this->assertArrayHasKey(
            'hideEmptyFacets',
            $config,
            'hideEmptyFacets must be emitted. The browser reads an absent key as "hide" '
            .'(only a literal false disables it), so omitting it makes the config opt-out '
            .'unreachable rather than merely defaulted.'
        );
        $this->assertTrue($config['hideEmptyFacets']);
    }

    /**
     * The false direction is the load-bearing one: the browser default is to
     * hide, so a component that dropped the value entirely would still look
     * correct in the on state.
     */
    public function test_hide_empty_facets_opt_out_is_emitted_as_false(): void
    {
        $this->writeFile($this->outputDir.'/pagefind/pagefind-entry.json', '{}');
        $this->setScoltaConfig(['scolta.hide_empty_facets' => false]);

        $this->assertFalse($this->renderAndParseConfig()['hideEmptyFacets']);
    }

    public function test_filter_field_descriptions_are_emitted(): void
    {
        $this->writeFile($this->outputDir.'/pagefind/pagefind-entry.json', '{}');

        $config = $this->renderAndParseConfig();

        $this->assertArrayHasKey(
            'filterFieldDescriptions',
            $config,
            'filterFieldDescriptions must be emitted; scolta.js reads it to label filter groups.'
        );
    }

    /**
     * A configured description map must reach the browser. An empty default is
     * indistinguishable from a missing bridge, so this needs a non-empty value.
     */
    public function test_configured_filter_field_descriptions_reach_the_browser(): void
    {
        $this->writeFile($this->outputDir.'/pagefind/pagefind-entry.json', '{}');
        $this->setScoltaConfig([
            'scolta.filter_field_descriptions' => [
                'topic' => 'Subject area',
                'era' => 'Historical period',
            ],
        ]);

        $this->assertSame(
            ['topic' => 'Subject area', 'era' => 'Historical period'],
            $this->renderAndParseConfig()['filterFieldDescriptions']
        );
    }

    // -------------------------------------------------------------------
    // Browser-config parity
    // -------------------------------------------------------------------

    /**
     * Every key ScoltaConfig::toBrowserConfig() emits must also be emitted by
     * the Blade component, which hand-builds its config array instead of
     * calling toBrowserConfig().
     *
     * This is the Laravel-flavoured form of the browser-config parity guard the
     * other five packages carry. The JS-extraction form used there is not
     * possible here: this package ships no committed scolta.js. It publishes the
     * bundle out of the composer-installed scolta-php into the app's public
     * directory at install time, so there is no in-repo artifact to parse and no
     * guarantee one exists at test time.
     *
     * Diffing against toBrowserConfig() instead still catches the class of bug
     * this replaces: the component was missing hideEmptyFacets and
     * filterFieldDescriptions, both of which scolta-php emits and the browser
     * reads, so both features were dead on this platform. What it cannot catch
     * is a key the browser reads that scolta-php itself does not emit; that gap
     * is covered upstream by scolta-php's own parity test.
     */
    public function test_component_emits_every_key_scolta_php_emits(): void
    {
        $this->writeFile($this->outputDir.'/pagefind/pagefind-entry.json', '{}');

        $emitted = $this->renderAndParseConfig();

        foreach (array_keys((new ScoltaConfig)->toBrowserConfig()) as $key) {
            $this->assertArrayHasKey(
                $key,
                $emitted,
                sprintf(
                    'ScoltaConfig::toBrowserConfig() emits %s but the Blade component does not, '
                    .'so the feature behind it is unreachable on Laravel. The component '
                    .'hand-builds its config array, so every new scolta-php browser key has to '
                    .'be added there by hand.',
                    $key
                )
            );
        }
    }

    /**
     * Reverse direction: nothing the component emits should be absent from what
     * scolta-php emits, apart from documented platform additions.
     */
    public function test_component_emits_nothing_beyond_scolta_php_except_platform_keys(): void
    {
        $this->writeFile($this->outputDir.'/pagefind/pagefind-entry.json', '{}');

        $reverseAllowlist = [
            // Read only by autoInit() off the global window.scolta, never off the
            // instance config, so scolta-php's toBrowserConfig() has no reason to
            // emit it and every adapter adds it.
            'container',
            // Supplied by the platform's locale layer, not by the config object.
            'currentLanguage',
            // Caller-supplied in scolta-php; the adapter passes empty values.
            'allowedLinkDomains',
            'disclaimer',
        ];

        $fromPhp = array_keys((new ScoltaConfig)->toBrowserConfig());

        foreach (array_keys($this->renderAndParseConfig()) as $key) {
            if (in_array($key, $reverseAllowlist, true)) {
                continue;
            }
            $this->assertContains(
                $key,
                $fromPhp,
                sprintf(
                    'The Blade component emits %s but ScoltaConfig::toBrowserConfig() does not, '
                    .'and it is not a documented platform addition. Either drop it or add it to '
                    .'the reverse allowlist in this test with a justification.',
                    $key
                )
            );
        }
    }

    /**
     * Set Scolta config and drop the resolved service so the change is seen.
     *
     * ScoltaAiService is bound as a container singleton in the provider's
     * register(), and it builds its ScoltaConfig once. Changing config after the
     * singleton has resolved would otherwise leave the component rendering a
     * stale config, and the assertion would silently test nothing.
     *
     * @param  array<string, mixed>  $values
     */
    private function setScoltaConfig(array $values): void
    {
        config($values);
        $this->app->forgetInstance(ScoltaAiService::class);
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
