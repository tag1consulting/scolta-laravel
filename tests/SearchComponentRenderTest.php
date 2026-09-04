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

    public function test_facet_mode_is_emitted_eager_by_default(): void
    {
        $this->writeFile($this->outputDir.'/pagefind/pagefind-entry.json', '{}');

        $config = $this->renderAndParseConfig();

        $this->assertArrayHasKey('facetMode', $config);
        $this->assertSame('eager', $config['facetMode']);
    }

    /**
     * The non-default modes are the load-bearing direction: the browser treats
     * an absent key as 'eager', so a component that dropped the value entirely
     * would still look correct in the default state while making the setting
     * unreachable.
     */
    public function test_facet_mode_non_default_values_are_emitted(): void
    {
        foreach (['deferred', 'disabled'] as $mode) {
            $this->writeFile($this->outputDir.'/pagefind/pagefind-entry.json', '{}');
            $this->setScoltaConfig(['scolta.facet_mode' => $mode]);

            $this->assertSame($mode, $this->renderAndParseConfig()['facetMode']);
        }
    }

    /**
     * An unrecognized configured mode must reach the browser as 'eager'.
     *
     * The bundle clamps too, but a value the package will not vouch for should
     * not be put on the page — and clamping toward the full-featured default is
     * what keeps a typo in a .env from quietly costing a site its facets.
     */
    public function test_unrecognized_facet_mode_is_emitted_as_eager(): void
    {
        $this->writeFile($this->outputDir.'/pagefind/pagefind-entry.json', '{}');
        $this->setScoltaConfig(['scolta.facet_mode' => 'defered']);

        $this->assertSame('eager', $this->renderAndParseConfig()['facetMode']);
    }

    public function test_labels_are_emitted_empty_by_default(): void
    {
        $this->writeFile($this->outputDir.'/pagefind/pagefind-entry.json', '{}');

        $config = $this->renderAndParseConfig();

        $this->assertArrayHasKey('labels', $config);
        $this->assertSame([], $config['labels']);
    }

    public function test_configured_labels_are_emitted(): void
    {
        $this->writeFile($this->outputDir.'/pagefind/pagefind-entry.json', '{}');
        $this->setScoltaConfig(['scolta.labels' => ['expandedTerms' => 'Related searches:']]);

        $this->assertSame(
            ['expandedTerms' => 'Related searches:'],
            $this->renderAndParseConfig()['labels']
        );
    }

    /**
     * A broken config value must not reach the page: the bundle falls back to
     * its default for an absent key, and a filtered key is the same as absent.
     */
    public function test_non_string_and_empty_labels_are_dropped(): void
    {
        $this->writeFile($this->outputDir.'/pagefind/pagefind-entry.json', '{}');
        $this->setScoltaConfig(['scolta.labels' => [
            'expandedTerms' => 'Related searches:',
            'aiOverview' => '',
            'blank' => null,
            'number' => 3,
        ]]);

        $this->assertSame(
            ['expandedTerms' => 'Related searches:'],
            $this->renderAndParseConfig()['labels']
        );
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
    // Search as you type
    // -------------------------------------------------------------------

    /**
     * The ten emitted keys and the defaults the browser bundle falls back to.
     *
     * @return array<string, bool|int|string>
     */
    private function saytBrowserDefaults(): array
    {
        return [
            'saytEnabled' => true,
            'saytMinChars' => 2,
            'saytDebounceMs' => 150,
            'saytMaxSuggestions' => 6,
            'saytRecentSearches' => true,
            'saytMaxRecent' => 3,
            'saytExpand' => true,
            'saytExpandPerMinute' => 6,
            'saytExpansionDelayMs' => 500,
            'saytSuggestionAction' => 'navigate',
        ];
    }

    public function test_every_sayt_key_is_emitted_with_its_default(): void
    {
        $this->writeFile($this->outputDir.'/pagefind/pagefind-entry.json', '{}');

        $config = $this->renderAndParseConfig();

        foreach ($this->saytBrowserDefaults() as $key => $expected) {
            $this->assertArrayHasKey(
                $key,
                $config,
                "$key must be emitted; the component hand-builds its config array, so every "
                .'scolta-php browser key has to be added to it by hand.'
            );
            $this->assertSame($expected, $config[$key], "$key must be emitted as its documented default.");
        }
    }

    /**
     * The off direction is the load-bearing one, exactly as with
     * hideEmptyFacets: SAYT is on by default in the bundle too, so a component
     * that dropped the value entirely would still look correct in the on state.
     *
     * This goes through config() rather than a constructed ScoltaConfig, so it
     * covers the whole published-config path: config value -> flattenConfig() ->
     * ScoltaConfig::fromArray() -> the component's emitted window.scolta.
     */
    public function test_a_published_config_disabling_sayt_reaches_the_browser(): void
    {
        $this->writeFile($this->outputDir.'/pagefind/pagefind-entry.json', '{}');
        $this->setScoltaConfig(['scolta.sayt_enabled' => false]);

        $this->assertFalse($this->renderAndParseConfig()['saytEnabled']);
    }

    /**
     * And every other key overridden through the same path, since a default
     * that happens to match proves nothing about the bridge.
     */
    public function test_published_sayt_overrides_reach_the_browser(): void
    {
        $this->writeFile($this->outputDir.'/pagefind/pagefind-entry.json', '{}');
        $this->setScoltaConfig([
            'scolta.sayt_min_chars' => 1,
            'scolta.sayt_debounce_ms' => 400,
            'scolta.sayt_max_suggestions' => 10,
            'scolta.sayt_recent_searches' => false,
            'scolta.sayt_max_recent' => 5,
            'scolta.sayt_expand' => false,
            'scolta.sayt_expand_per_minute' => 2,
            'scolta.sayt_expansion_delay_ms' => 800,
            'scolta.sayt_suggestion_action' => 'search',
        ]);

        $config = $this->renderAndParseConfig();

        $this->assertSame(1, $config['saytMinChars']);
        $this->assertSame(400, $config['saytDebounceMs']);
        $this->assertSame(10, $config['saytMaxSuggestions']);
        $this->assertFalse($config['saytRecentSearches']);
        $this->assertSame(5, $config['saytMaxRecent']);
        $this->assertFalse($config['saytExpand']);
        $this->assertSame(2, $config['saytExpandPerMinute']);
        $this->assertSame(800, $config['saytExpansionDelayMs']);
        $this->assertSame('search', $config['saytSuggestionAction']);
    }

    public function test_an_unknown_suggestion_action_is_emitted_as_navigate(): void
    {
        $this->writeFile($this->outputDir.'/pagefind/pagefind-entry.json', '{}');
        $this->setScoltaConfig(['scolta.sayt_suggestion_action' => 'teleport']);

        $this->assertSame('navigate', $this->renderAndParseConfig()['saytSuggestionAction']);
    }

    // -------------------------------------------------------------------
    // Per-visitor expansion toggle
    // -------------------------------------------------------------------

    /**
     * scolta-php 1.4.0 added ScoltaConfig::$expansionToggle, which the browser
     * bundle reads as scoring.EXPANSION_TOGGLE to decide whether the results
     * header carries the visitor-facing "disable expanded terms" switch. The
     * published config had no key that reached it, so the setting was
     * unreachable on Laravel.
     *
     * Asserted through config() rather than a constructed ScoltaConfig, so this
     * covers the whole bridge: config/scolta.php -> flattenConfig() ->
     * ScoltaConfig::fromArray() -> toJsScoringConfig() -> window.scolta.scoring.
     */
    public function test_expansion_toggle_is_emitted_as_its_default(): void
    {
        $this->writeFile($this->outputDir.'/pagefind/pagefind-entry.json', '{}');

        $scoring = $this->renderAndParseConfig()['scoring'];

        $this->assertArrayHasKey(
            'EXPANSION_TOGGLE',
            $scoring,
            'window.scolta.scoring must carry EXPANSION_TOGGLE; the bundle renders the '
            .'per-visitor expansion switch from it.'
        );
        $this->assertTrue($scoring['EXPANSION_TOGGLE']);
    }

    /**
     * The off direction, which is the one worth pinning: the property defaults
     * to true inside scolta-php, so a config file missing the key entirely
     * still emits true and looks correct.
     */
    public function test_a_published_config_suppressing_the_expansion_toggle_reaches_the_browser(): void
    {
        $this->writeFile($this->outputDir.'/pagefind/pagefind-entry.json', '{}');
        $this->setScoltaConfig(['scolta.expansion_toggle' => false]);

        $this->assertFalse($this->renderAndParseConfig()['scoring']['EXPANSION_TOGGLE']);
    }

    // -------------------------------------------------------------------
    // Browser-config parity
    // -------------------------------------------------------------------

    /**
     * Every key ScoltaConfig::toBrowserConfig() emits must also be emitted by
     * the Blade component, which hand-builds its config array instead of
     * calling toBrowserConfig().
     *
     * This is the scolta-php-facing half of the browser-config parity guard.
     * Diffing against toBrowserConfig() catches the class of bug it was added
     * for: the component was missing hideEmptyFacets and
     * filterFieldDescriptions, both of which scolta-php emits and the browser
     * reads, so both features were dead on this platform. What it cannot catch
     * is a key the browser reads that scolta-php's config object does not emit;
     * BrowserConfigParityTest covers that direction by parsing the installed
     * scolta-php's assets/js/scolta.js out of vendor.
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
            // Emitted by this adapter, clamped locally from platform config
            // rather than read through ScoltaConfig, so faceting works against
            // any scolta-php in the supported range. $facetMode landed in
            // 1.2.1; the shipped floor now covers it, so toBrowserConfig() does
            // emit it and this entry is inert — kept so the local-read pattern
            // survives a floor that moves backwards as well as forwards.
            'facetMode',
            // Same pattern, and likewise now covered by the floor:
            // ScoltaConfig::$labels landed in scolta-php 1.5.0.
            'labels',
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
