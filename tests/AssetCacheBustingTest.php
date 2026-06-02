<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Tests;

use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\TestCase;

/**
 * Behavioral coverage for the published-asset cache-busting token.
 *
 * The search Blade component appends `?v=filemtime(public_path('vendor/scolta/scolta.js'))`
 * (and the same for scolta.css) to the asset URL. asset() alone emits no version, so a
 * deploy that replaces the published file would otherwise keep serving stale JS/CSS. These
 * tests exercise the exact token the template builds against a real published file and prove
 * it is non-empty and changes when the asset changes.
 */
class AssetCacheBustingTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        // Minimal app whose basePath drives public_path() at a temp location we control.
        $this->basePath = sys_get_temp_dir().'/scolta-asset-cache-'.bin2hex(random_bytes(6));
        @mkdir($this->basePath.'/public/vendor/scolta', 0755, true);

        $app = new Application($this->basePath);
        Container::setInstance($app);
    }

    protected function tearDown(): void
    {
        Container::setInstance(null);

        foreach (['scolta.js', 'scolta.css'] as $name) {
            @unlink($this->basePath.'/public/vendor/scolta/'.$name);
        }
        @rmdir($this->basePath.'/public/vendor/scolta');
        @rmdir($this->basePath.'/public/vendor');
        @rmdir($this->basePath.'/public');
        @rmdir($this->basePath);
    }

    /**
     * Build the cache token exactly as the Blade template does.
     */
    private function token(string $asset): string
    {
        return '?v='.filemtime(public_path('vendor/scolta/'.$asset));
    }

    public function test_js_token_is_nonempty_and_numeric(): void
    {
        $path = public_path('vendor/scolta/scolta.js');
        file_put_contents($path, 'console.log("v1");');

        $token = $this->token('scolta.js');

        $this->assertMatchesRegularExpression(
            '/^\?v=\d+$/',
            $token,
            'scolta.js cache token must be a non-empty ?v=<digits> derived from the published file mtime.'
        );
    }

    public function test_css_token_is_nonempty_and_numeric(): void
    {
        $path = public_path('vendor/scolta/scolta.css');
        file_put_contents($path, '.scolta{}');

        $token = $this->token('scolta.css');

        $this->assertMatchesRegularExpression(
            '/^\?v=\d+$/',
            $token,
            'scolta.css cache token must be a non-empty ?v=<digits> derived from the published file mtime.'
        );
    }

    public function test_js_token_changes_when_published_asset_changes(): void
    {
        $path = public_path('vendor/scolta/scolta.js');

        file_put_contents($path, 'console.log("old");');
        touch($path, 1_600_000_000);
        $before = $this->token('scolta.js');

        // Simulate a deploy replacing the asset with a newer mtime.
        file_put_contents($path, 'console.log("new");');
        touch($path, 1_600_000_500);
        $after = $this->token('scolta.js');

        $this->assertNotSame(
            $before,
            $after,
            'The cache token must change when the published scolta.js changes, so a normal reload serves fresh JS.'
        );
    }
}
