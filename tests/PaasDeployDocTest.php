<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Verifies that README.md documents the PaaS deployment requirement.
 *
 * File-inspection tests confirm the "Deploying to PaaS Platforms" section
 * exists and covers the key points: why assets are wiped, how to wire
 * vendor:publish into the build pipeline, and the root-package-only caveat.
 */
class PaasDeployDocTest extends TestCase
{
    private string $readme;

    private string $paasSection;

    protected function setUp(): void
    {
        $this->readme = file_get_contents(dirname(__DIR__).'/README.md');

        // Extract the PaaS section body (from the heading to the next ## heading).
        preg_match(
            '/## Deploying to PaaS Platforms\b(.*?)(?=\n## |\Z)/s',
            $this->readme,
            $m
        );
        $this->paasSection = $m[1] ?? '';
    }

    // -------------------------------------------------------------------
    // Section exists.
    // -------------------------------------------------------------------

    public function test_paas_section_exists(): void
    {
        $this->assertStringContainsString(
            '## Deploying to PaaS Platforms',
            $this->readme,
            'README must have a "Deploying to PaaS Platforms" section'
        );
    }

    public function test_paas_section_has_body(): void
    {
        $this->assertNotEmpty(
            $this->paasSection,
            'Could not extract "Deploying to PaaS Platforms" section body'
        );
    }

    // -------------------------------------------------------------------
    // Section explains why the problem occurs.
    // -------------------------------------------------------------------

    public function test_paas_section_explains_filesystem_rebuilt(): void
    {
        $this->assertMatchesRegularExpression(
            '/filesystem.*rebuilt|rebuilt.*filesystem|wipe[ds]?|ephemeral/i',
            $this->paasSection,
            'PaaS section must explain that the filesystem is rebuilt (and assets wiped) on each deploy'
        );
    }

    public function test_paas_section_mentions_build_pipeline(): void
    {
        $this->assertStringContainsString(
            'build pipeline',
            $this->paasSection,
            'PaaS section must state that vendor:publish must run in the build pipeline'
        );
    }

    // -------------------------------------------------------------------
    // Section documents post-autoload-dump.
    // -------------------------------------------------------------------

    public function test_paas_section_documents_post_autoload_dump(): void
    {
        $this->assertStringContainsString(
            'post-autoload-dump',
            $this->paasSection,
            'PaaS section must document the post-autoload-dump Composer script hook'
        );
    }

    public function test_paas_section_includes_force_flag(): void
    {
        $this->assertStringContainsString(
            '--force',
            $this->paasSection,
            'PaaS section must include --force flag so assets are refreshed even when destination exists'
        );
    }

    public function test_paas_section_shows_vendor_publish_command(): void
    {
        $this->assertStringContainsString(
            'vendor:publish --tag=scolta-assets',
            $this->paasSection,
            'PaaS section must show the vendor:publish --tag=scolta-assets command'
        );
    }

    // -------------------------------------------------------------------
    // Root-package-only caveat is documented.
    // -------------------------------------------------------------------

    public function test_paas_section_explains_root_package_caveat(): void
    {
        $this->assertMatchesRegularExpression(
            '/root\s+package|your\s+(own\s+|application.s\s+)?composer\.json/i',
            $this->paasSection,
            'PaaS section must explain that scripts in a dependency are not run — add to the root package'
        );
    }

    public function test_paas_section_warns_dependency_scripts_not_run(): void
    {
        $this->assertMatchesRegularExpression(
            '/not\s+executed|never\s+executed|not\s+run/i',
            $this->paasSection,
            'PaaS section must state that a dependency\'s Composer scripts are not executed for consumers'
        );
    }

    // -------------------------------------------------------------------
    // At least one concrete platform is documented.
    // -------------------------------------------------------------------

    public function test_paas_section_names_at_least_one_platform(): void
    {
        $this->assertMatchesRegularExpression(
            '/Laravel Cloud|Laravel Vapor|Laravel Forge|Vapor|Forge|Railway|Render/i',
            $this->paasSection,
            'PaaS section must name at least one concrete PaaS platform'
        );
    }

    // -------------------------------------------------------------------
    // Quick Install step links to PaaS section or doesn't say "one-time only".
    // -------------------------------------------------------------------

    public function test_quick_install_includes_vendor_publish_assets(): void
    {
        $this->assertStringContainsString(
            'vendor:publish',
            $this->readme,
            'Quick Install section must still include vendor:publish'
        );
    }
}
