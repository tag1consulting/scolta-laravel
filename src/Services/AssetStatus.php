<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Services;

use Illuminate\Support\Facades\File;
use ReflectionClass;
use ReflectionException;
use Tag1\Scolta\Config\ScoltaConfig;

/**
 * Published front-end asset status.
 *
 * Single source of truth for "are the scolta.js/scolta.css assets
 * published, and do they match the installed scolta-php version?" —
 * previously triplicated (with drift) across HealthController,
 * StatusCommand, and the service provider's publishable registration.
 *
 * @since 1.0.4
 *
 * @stability experimental
 */
class AssetStatus
{
    /**
     * Absolute path of the published scolta.js.
     *
     * @since 1.0.4
     *
     * @stability experimental
     */
    public function publishedJsPath(): string
    {
        return public_path('vendor/scolta/scolta.js');
    }

    /**
     * Whether the front-end assets have been published.
     *
     * @since 1.0.4
     *
     * @stability experimental
     */
    public function arePublished(): bool
    {
        return file_exists($this->publishedJsPath());
    }

    /**
     * Path to the installed scolta-php package's assets directory.
     *
     * Resolved via reflection on a scolta-php class so it works for both
     * monorepo path installs and standard vendor installs. Null when
     * scolta-php is not installed or ships no assets directory.
     *
     * @since 1.0.4
     *
     * @stability experimental
     */
    public function packageAssetsPath(): ?string
    {
        try {
            $coreRef = new ReflectionClass(ScoltaConfig::class);
            $path = dirname((string) $coreRef->getFileName(), 3).'/assets';
        } catch (ReflectionException) {
            return null;
        }

        return is_dir($path) ? $path : null;
    }

    /**
     * Whether the published scolta.js matches the package's checksum.
     *
     * Returns true when current, false when stale, and null when it cannot
     * be determined (assets not published, scolta-php missing, or no
     * checksum file shipped).
     *
     * @since 1.0.4
     *
     * @stability experimental
     */
    public function areCurrent(): ?bool
    {
        if (! $this->arePublished()) {
            return null;
        }

        $assetsPath = $this->packageAssetsPath();
        if ($assetsPath === null) {
            return null;
        }

        $checksumFile = $assetsPath.'/js/scolta.js.sha256';
        if (! file_exists($checksumFile)) {
            return null;
        }

        $expectedHash = trim(File::get($checksumFile));

        return hash_file('sha256', $this->publishedJsPath()) === $expectedHash;
    }
}
