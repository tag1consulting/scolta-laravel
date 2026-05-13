<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Verify the config file is compatible with config:cache.
 */
class ConfigCacheTest extends TestCase
{
    public function test_config_has_no_nested_env_calls(): void
    {
        $configPath = dirname(__DIR__).'/config/scolta.php';
        $content = file_get_contents($configPath);

        $this->assertDoesNotMatchRegularExpression(
            '/env\([^)]*env\(/',
            $content,
            'config/scolta.php contains nested env() calls — these evaluate the inner env() unconditionally'
        );
    }

    public function test_config_ai_api_key_uses_single_env(): void
    {
        $configPath = dirname(__DIR__).'/config/scolta.php';
        $content = file_get_contents($configPath);

        $this->assertMatchesRegularExpression(
            "/['\"]ai_api_key['\"].*env\s*\(\s*['\"]SCOLTA_API_KEY['\"]/",
            $content,
            'ai_api_key should read from SCOLTA_API_KEY env var'
        );
    }
}
