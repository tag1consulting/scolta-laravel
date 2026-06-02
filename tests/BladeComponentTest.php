<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Test the search Blade component: file existence and content validation.
 */
class BladeComponentTest extends TestCase
{
    private string $root;

    private string $templatePath;

    private string $templateContent;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__);
        $this->templatePath = $this->root.'/resources/views/components/search.blade.php';

        $this->assertFileExists($this->templatePath,
            'Blade template must exist before tests run.');
        $this->templateContent = file_get_contents($this->templatePath);
    }

    // -------------------------------------------------------------------
    // Template file exists
    // -------------------------------------------------------------------

    public function test_blade_template_exists(): void
    {
        $this->assertFileExists($this->templatePath,
            'Blade search component should exist at resources/views/components/search.blade.php.');
    }

    // -------------------------------------------------------------------
    // Contains scolta-search container div
    // -------------------------------------------------------------------

    public function test_contains_scolta_search_container(): void
    {
        $this->assertStringContainsString('scolta-search', $this->templateContent,
            'Blade template should contain the scolta-search container.');
    }

    public function test_container_is_a_div(): void
    {
        $this->assertMatchesRegularExpression(
            '/<div\s[^>]*id=["\']scolta-search["\']/',
            $this->templateContent,
            'The scolta-search container should be a div element with id attribute.'
        );
    }

    // -------------------------------------------------------------------
    // References window.scolta config
    // -------------------------------------------------------------------

    public function test_sets_window_scolta_config(): void
    {
        $this->assertStringContainsString('window.scolta', $this->templateContent,
            'Blade template should set window.scolta configuration.');
    }

    public function test_config_includes_scoring(): void
    {
        $this->assertStringContainsString('scoring', $this->templateContent,
            'Window config should include scoring configuration.');
    }

    public function test_config_includes_endpoints(): void
    {
        $this->assertStringContainsString('endpoints', $this->templateContent,
            'Window config should include endpoint URLs.');
    }

    public function test_config_includes_pagefind_path(): void
    {
        $this->assertStringContainsString('pagefindPath', $this->templateContent,
            'Window config should include the Pagefind path.');
    }

    public function test_config_includes_site_name(): void
    {
        $this->assertStringContainsString('siteName', $this->templateContent,
            'Window config should include the site name.');
    }

    // -------------------------------------------------------------------
    // Includes scolta.js reference
    // -------------------------------------------------------------------

    public function test_includes_scolta_js_reference(): void
    {
        $this->assertStringContainsString('scolta.js', $this->templateContent,
            'Blade template should reference scolta.js.');
    }

    public function test_scolta_js_loaded_with_defer(): void
    {
        $this->assertStringContainsString('defer', $this->templateContent,
            'scolta.js should be loaded with defer attribute.');
    }

    public function test_scolta_js_from_vendor_path(): void
    {
        $this->assertStringContainsString('vendor/scolta/scolta.js', $this->templateContent,
            'scolta.js should be loaded from vendor/scolta/ path.');
    }

    // -------------------------------------------------------------------
    // Asset cache-busting — the asset URL must carry a content-changing
    // ?v= token. asset() alone emits no version, so a deploy that replaces
    // the published file would otherwise keep serving stale JS/CSS.
    // -------------------------------------------------------------------

    public function test_scolta_js_url_has_filemtime_cache_token(): void
    {
        $this->assertMatchesRegularExpression(
            "/asset\('vendor\/scolta\/scolta\.js'\)\s*\.\s*'\?v='\s*\.\s*filemtime\(public_path\('vendor\/scolta\/scolta\.js'\)\)/",
            $this->templateContent,
            'scolta.js src must append ?v=filemtime(public_path(...)) so a normal reload picks up fresh JS after a deploy.'
        );
    }

    public function test_scolta_css_url_has_filemtime_cache_token(): void
    {
        $this->assertMatchesRegularExpression(
            "/asset\('vendor\/scolta\/scolta\.css'\)\s*\.\s*'\?v='\s*\.\s*filemtime\(public_path\('vendor\/scolta\/scolta\.css'\)\)/",
            $this->templateContent,
            'scolta.css href must append ?v=filemtime(public_path(...)) so a normal reload picks up fresh CSS after a deploy.'
        );
    }

    // -------------------------------------------------------------------
    // Includes scolta.css reference
    // -------------------------------------------------------------------

    public function test_includes_scolta_css_reference(): void
    {
        $this->assertStringContainsString('scolta.css', $this->templateContent,
            'Blade template should reference scolta.css.');
    }

    public function test_scolta_css_from_vendor_path(): void
    {
        $this->assertStringContainsString('vendor/scolta/scolta.css', $this->templateContent,
            'scolta.css should be loaded from vendor/scolta/ path.');
    }

    // -------------------------------------------------------------------
    // Template uses @json directive for config
    // -------------------------------------------------------------------

    public function test_uses_json_blade_directive(): void
    {
        $this->assertStringContainsString('@json', $this->templateContent,
            'Template should use @json directive for config serialization.');
    }

    // -------------------------------------------------------------------
    // Template includes endpoint URLs
    // -------------------------------------------------------------------

    public function test_endpoint_includes_expand(): void
    {
        $this->assertStringContainsString('expand', $this->templateContent,
            'Endpoints config should include expand-query URL.');
    }

    public function test_endpoint_includes_summarize(): void
    {
        $this->assertStringContainsString('summarize', $this->templateContent,
            'Endpoints config should include summarize URL.');
    }

    public function test_endpoint_includes_followup(): void
    {
        $this->assertStringContainsString('followup', $this->templateContent,
            'Endpoints config should include followup URL.');
    }

    // -------------------------------------------------------------------
    // Template includes script tag
    // -------------------------------------------------------------------

    public function test_includes_script_tag(): void
    {
        $this->assertStringContainsString('<script', $this->templateContent,
            'Template should contain script tags.');
    }

    // -------------------------------------------------------------------
    // Template includes link tag for CSS
    // -------------------------------------------------------------------

    public function test_includes_link_tag(): void
    {
        $this->assertStringContainsString('<link', $this->templateContent,
            'Template should contain link tags for CSS.');
    }

    // -------------------------------------------------------------------
    // Template handles missing assets gracefully
    // -------------------------------------------------------------------

    public function test_handles_missing_js_gracefully(): void
    {
        $this->assertStringContainsString('file_exists', $this->templateContent,
            'Template should check if files exist before including them.');
    }

    public function test_includes_fallback_comment_for_missing_js(): void
    {
        $this->assertStringContainsString('vendor:publish', $this->templateContent,
            'Template should include a fallback comment about vendor:publish.');
    }

    // -------------------------------------------------------------------
    // Index existence check supports both nested and flat layouts
    // -------------------------------------------------------------------

    public function test_index_exists_check_uses_nested_pagefind_subdir(): void
    {
        $this->assertStringContainsString("'/pagefind/pagefind-entry.json'", $this->templateContent,
            'Index existence check must check the nested pagefind/ subdirectory path.');
    }

    public function test_index_exists_check_uses_flat_path_as_fallback(): void
    {
        $this->assertStringContainsString("'/pagefind-entry.json'", $this->templateContent,
            'Index existence check must also check the flat (root-level) path as a fallback.');
    }

    public function test_nested_path_checked_before_flat_path(): void
    {
        $nestedPos = strpos($this->templateContent, "'/pagefind/pagefind-entry.json'");
        $flatPos = strpos($this->templateContent, "'/pagefind-entry.json'");

        $this->assertNotFalse($nestedPos, 'Nested path check must be present.');
        $this->assertNotFalse($flatPos, 'Flat path check must be present.');
        $this->assertLessThan($flatPos, $nestedPos,
            'Nested pagefind/ path must be checked before flat path (prefer nested layout).');
    }

    public function test_index_dir_set_for_nested_layout(): void
    {
        $this->assertMatchesRegularExpression(
            '/\$indexDir\s*=\s*\$outputDir\s*\.\s*\'\/pagefind\'/',
            $this->templateContent,
            'When nested layout detected, $indexDir must be set to $outputDir/pagefind.'
        );
    }

    public function test_index_dir_set_for_flat_layout(): void
    {
        $this->assertMatchesRegularExpression(
            '/\$indexDir\s*=\s*\$outputDir;/',
            $this->templateContent,
            'When flat layout detected, $indexDir must be set to $outputDir.'
        );
    }

    public function test_no_index_shows_warning(): void
    {
        $this->assertStringContainsString('Search index has not been built yet', $this->templateContent,
            'Template must show a build warning when no index is found in either location.');
    }

    // -------------------------------------------------------------------
    // pagefindPath URL derives from detected $indexDir
    // -------------------------------------------------------------------

    public function test_pagefind_path_url_uses_index_url(): void
    {
        $this->assertStringContainsString("'/pagefind.js'", $this->templateContent,
            'pagefindPath asset URL must reference pagefind.js.');
    }

    public function test_pagefind_path_uses_index_url_variable(): void
    {
        $this->assertStringContainsString('$indexUrl', $this->templateContent,
            'pagefindPath must be derived from $indexUrl (which follows the detected layout).');
    }

    // -------------------------------------------------------------------
    // Warning shown to all visitors, not gated behind @auth
    // -------------------------------------------------------------------

    public function test_index_missing_warning_not_gated_by_auth(): void
    {
        $this->assertStringNotContainsString('@auth', $this->templateContent,
            'The "index not built" warning must not be wrapped in @auth — anonymous visitors should also see it.');
    }

    // -------------------------------------------------------------------
    // Attribution markup — opt-in, guarded by $config->showAttribution
    // -------------------------------------------------------------------

    public function test_attribution_markup_is_present(): void
    {
        $this->assertStringContainsString('scolta-attribution', $this->templateContent,
            'Blade template must contain the scolta-attribution element for when attribution is enabled.');
    }

    public function test_attribution_text_is_powered_by_scolta(): void
    {
        $this->assertStringContainsString('Powered by Scolta', $this->templateContent,
            'Attribution text must read "Powered by Scolta".');
    }

    public function test_attribution_is_gated_by_show_attribution_flag(): void
    {
        $this->assertStringContainsString('showAttribution', $this->templateContent,
            'Attribution must be conditional on $config->showAttribution.');
    }

    public function test_attribution_uses_blade_if_directive(): void
    {
        $this->assertMatchesRegularExpression(
            '/@if\s*\(\s*\$config->showAttribution\s*\)/',
            $this->templateContent,
            'Attribution block must use @if($config->showAttribution) to guard the markup.'
        );
    }
}
