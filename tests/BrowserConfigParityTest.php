<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Tests;

use Composer\InstalledVersions;
use Illuminate\Support\Facades\Blade;
use Orchestra\Testbench\TestCase;
use Tag1\ScoltaLaravel\ScoltaServiceProvider;

/**
 * Stay-in-sync guard between what the browser reads and what this package emits.
 *
 * Parses the `instanceConfig.<key>` reads out of the installed scolta-php
 * bundle (`vendor/tag1/scolta-php/assets/js/scolta.js` — the same bytes
 * `vendor:publish --tag=scolta-assets` copies into the application's public
 * directory) and diffs them, both directions, against the `window.scolta`
 * payload the search Blade component renders. A key the bundle reads but the
 * component never emits is a feature dead on arrival; a key emitted but never
 * read is dead weight in every page payload.
 *
 * It recurses one level into `scoring` and `endpoints` because those are
 * objects: a top-level presence check passes while a scoring sub-key is
 * missing, which is how three scoring keys hid in scolta-php.
 *
 * Bundle comments are deliberately NOT stripped before matching — cutting `//`
 * to end of line would corrupt every line carrying an `https://` URL and could
 * drop a real key — and the reverse direction uses strict set membership rather
 * than a substring search, which over a bundle of several thousand lines would
 * match almost any plausible camelCase name. The extraction tripwires assert
 * before any diff runs, so a reformat of scolta.js that breaks the parse fails
 * loudly instead of passing while asserting nothing.
 *
 * Extends Testbench because only a render gives the real emitted config, which
 * needs config(), asset(), url(), the `scolta::` view namespace and
 * ScoltaAiService resolvable. The rest of the suite is plain PHPUnit and should
 * stay that way.
 */
class BrowserConfigParityTest extends TestCase
{
    /**
     * Keys scolta.js reads that the component deliberately does not emit.
     *
     * Subtracts from the extracted set, so it may only ever contain keys the
     * bundle actually reads.
     *
     * @var list<string>
     */
    private const FORWARD_ALLOWLIST = [
        // Emitted by no adapter; supplied only by a direct createInstance()
        // caller and forwarded straight to WASM match_priority_pages(). Note
        // the snake_case name, unlike every other top-level key.
        'priority_pages',
        // Deliberately one entry shorter than the Drupal guard's
        // FORWARD_ALLOWLIST, which also carries `labels`: that block does not
        // emit the per-site label overrides yet and this component does, so
        // here the key is covered by the forward assertion rather than
        // excused from it. `priority_pages` and the reverse `container` match
        // entry for entry across both.
    ];

    /**
     * Keys the component emits that scolta.js does not read off the instance
     * config.
     *
     * Subtracts from the emitted set, so it may only ever contain keys this
     * package actually emits.
     *
     * @var list<string>
     */
    private const REVERSE_ALLOWLIST = [
        // Read only by autoInit() off the global window.scolta, never off the
        // instance config, so it is correctly absent from the extracted set.
        'container',
    ];

    /**
     * Absolute path to the Pagefind output dir the component probes.
     */
    private string $outputDir;

    /**
     * {@inheritdoc}
     */
    protected function getPackageProviders($app): array
    {
        return [ScoltaServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->outputDir = public_path('scolta-pagefind');

        config([
            'scolta.pagefind.output_dir' => $this->outputDir,
            'scolta.route_prefix' => 'api/scolta/v1',
        ]);

        // Without an index the component renders the "not built yet" warning
        // and emits no window.scolta at all.
        $this->writeFile($this->outputDir.'/pagefind/pagefind-entry.json', '{}');
    }

    protected function tearDown(): void
    {
        $this->removeDir(public_path('scolta-pagefind'));

        parent::tearDown();
    }

    /**
     * Every top-level key the browser reads must be emitted, and none be dead.
     *
     * Both directions share one method because each needs a render.
     */
    public function test_emitted_browser_config_matches_what_scolta_js_reads(): void
    {
        $emitted = $this->renderAndParseConfig();
        $source = $this->bundleSource();

        // Forward: everything the browser reads must be emitted.
        $read = $this->extractTopLevelKeys($source);
        foreach (array_diff($read, self::FORWARD_ALLOWLIST) as $key) {
            $this->assertArrayHasKey(
                $key,
                $emitted,
                sprintf(
                    'scolta.js reads instanceConfig.%s but the search Blade component does not '
                    .'emit it, so the feature behind it is unreachable on Laravel. The component '
                    .'hand-builds its config array, so every browser key has to be added there by '
                    .'hand. Either emit the key or add it to %s::FORWARD_ALLOWLIST with a written '
                    .'justification.',
                    $key,
                    __CLASS__
                )
            );
        }

        // Reverse: nothing emitted should be dead weight.
        foreach (array_diff(array_keys($emitted), self::REVERSE_ALLOWLIST) as $key) {
            $this->assertContains(
                $key,
                $read,
                sprintf(
                    'The search Blade component emits %s but scolta.js never reads it off the '
                    .'instance config, so it is dead weight in every page payload. Either drop it '
                    .'or add it to %s::REVERSE_ALLOWLIST with a written justification.',
                    $key,
                    __CLASS__
                )
            );
        }
    }

    /**
     * Every scoring and endpoint sub-key the browser reads must be emitted.
     */
    public function test_emitted_scoring_and_endpoints_match_what_scolta_js_reads(): void
    {
        $emitted = $this->renderAndParseConfig();
        $source = $this->bundleSource();

        $this->assertArrayHasKey('scoring', $emitted, 'No scoring array was emitted at all.');
        foreach ($this->extractScoringKeys($source) as $key) {
            $this->assertArrayHasKey(
                $key,
                $emitted['scoring'],
                sprintf(
                    'scolta.js reads scoring key %s but it is absent from the emitted scoring '
                    .'array, so it can only ever take its hardcoded JS fallback. The array comes '
                    .'from ScoltaConfig::toJsScoringConfig(); check that the key is present in '
                    .'config/scolta.php and survives ScoltaAiService::flattenConfig().',
                    $key
                )
            );
        }

        $this->assertArrayHasKey('endpoints', $emitted, 'No endpoints array was emitted at all.');
        foreach ($this->extractEndpointKeys($source) as $key) {
            $this->assertArrayHasKey(
                $key,
                $emitted['endpoints'],
                sprintf('scolta.js reads endpoint %s but it is absent from the emitted endpoints array.', $key)
            );
        }
    }

    /**
     * The installed scolta-php browser bundle as text.
     *
     * Read from the composer install path: this package commits no bundle, it
     * publishes scolta-php's out of vendor, so vendor is the authority.
     */
    private function bundleSource(): string
    {
        $path = InstalledVersions::getInstallPath('tag1/scolta-php').'/assets/js/scolta.js';
        $this->assertFileExists($path, "The scolta-php browser bundle is missing at {$path}.");
        $source = file_get_contents($path);
        $this->assertNotFalse($source, "Unable to read the scolta-php bundle at {$path}");

        return $source;
    }

    /**
     * Distinct top-level keys read as `instanceConfig.<key>`.
     *
     * @return list<string>
     */
    private function extractTopLevelKeys(string $source): array
    {
        preg_match_all('/instanceConfig\.([A-Za-z_][A-Za-z0-9_]*)/', $source, $matches);
        $keys = array_values(array_unique($matches[1]));

        // Floor of 20 against the 23 the bundle reads today. Deliberately
        // higher than the Drupal guard's 11, which predates a dozen of those
        // keys: a tripwire exists to catch a parse that half-breaks, and 11
        // would pass on less than half the set. Lower it only alongside a
        // bundle that genuinely reads fewer keys.
        $this->assertGreaterThanOrEqual(
            20,
            count($keys),
            'Parsed too few top-level config reads from the scolta-php bundle — it may have been '
            .'reformatted so `instanceConfig.<key>` no longer matches. Update the parser in '
            .__CLASS__.' so the guard keeps working.'
        );

        return $keys;
    }

    /**
     * Distinct scoring keys read as `KEY: s.KEY ??` in the config return literals.
     *
     * The regex matches the getConfig() and getInstanceConfig() return
     * literals; their union is the full set only because the former's keys are
     * a strict subset of the latter's. Parsing the literals rather than
     * grepping use sites is deliberate: several keys are forwarded to WASM
     * wholesale and never named at a use site.
     *
     * @return list<string>
     */
    private function extractScoringKeys(string $source): array
    {
        preg_match_all('/^\s*([A-Z][A-Z0-9_]*):\s*s\.\1\s*\?\?/m', $source, $matches);
        $keys = array_values(array_unique($matches[1]));

        $this->assertGreaterThanOrEqual(
            40,
            count($keys),
            'Parsed too few scoring keys from the scolta-php bundle — the getInstanceConfig() '
            .'return literal may have been reformatted so `KEY: s.KEY ??` no longer matches. '
            .'Update the parser in '.__CLASS__.' so the guard keeps working.'
        );

        return $keys;
    }

    /**
     * Distinct endpoint keys read as `key: e.key ||`.
     *
     * @return list<string>
     */
    private function extractEndpointKeys(string $source): array
    {
        preg_match_all('/^\s*([a-z]+):\s*e\.\1\s*\|\|/m', $source, $matches);
        $keys = array_values(array_unique($matches[1]));

        $this->assertCount(
            3,
            $keys,
            'Expected exactly 3 endpoint keys in the scolta-php bundle (expand, summarize, '
            .'followup) but parsed '.count($keys).'. Either an endpoint was added or the bundle '
            .'was reformatted so `key: e.key ||` no longer matches. Update the parser in '
            .__CLASS__.' so the guard keeps working.'
        );

        return $keys;
    }

    /**
     * Render the component and return the decoded window.scolta payload.
     *
     * @return array<string, mixed>
     */
    private function renderAndParseConfig(): array
    {
        // Render via the component tag (not view()->render()) so the Blade
        // component's $attributes bag is bound.
        $html = Blade::render('<x-scolta::search />');

        // Greedy and line-scoped (no /s): `@json` emits the whole payload on
        // one line, so the last `};` on that line is the end of the object. A
        // non-greedy match would stop at the first `};` inside a config
        // string — a disclaimer or a label is free to contain one — and hand
        // json_decode() a truncated object.
        $this->assertSame(
            1,
            preg_match('/window\.scolta\s*=\s*(\{.*\});/', $html, $m),
            'Rendered HTML must assign a JSON object to window.scolta. If the component rendered '
            .'the "not built yet" warning instead, the Pagefind fixture in setUp() did not take.'
        );

        $config = json_decode($m[1], true);
        $this->assertIsArray($config, 'window.scolta payload must decode to an array.');

        return $config;
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
