<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Tag1\Scolta\Binary\PagefindBinary;

/**
 * Download the Pagefind binary for the current platform.
 *
 * Equivalent to `wp scolta download-pagefind` (WordPress). For hosts
 * without npm/Node.js — downloads the pre-built binary directly from
 * GitHub releases.
 *
 * Uses Laravel's HTTP client (built on Guzzle) for the API call and
 * download — much cleaner than WordPress's wp_remote_get().
 */
class DownloadPagefindCommand extends Command
{
    protected $signature = 'scolta:download-pagefind
        {--path= : Custom install path (default: storage/scolta/bin)}';

    protected $description = 'Download the Pagefind binary for the current platform';

    public function handle(): int
    {
        $resolver = new PagefindBinary(projectDir: base_path());
        $defaultTarget = $resolver->downloadTargetDir();
        $targetDir = $this->option('path') ?: $defaultTarget;

        if (! is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }

        // Detect platform.
        $os = PHP_OS_FAMILY;
        $arch = php_uname('m');

        $platform = match (true) {
            $os === 'Linux' && $arch === 'x86_64' => 'x86_64-unknown-linux-musl',
            $os === 'Linux' && str_contains($arch, 'aarch64') => 'aarch64-unknown-linux-musl',
            $os === 'Darwin' && str_contains($arch, 'arm') => 'aarch64-apple-darwin',
            $os === 'Darwin' => 'x86_64-apple-darwin',
            default => null,
        };

        if ($platform === null) {
            $this->error("Unsupported platform: {$os} {$arch}. Install Pagefind via npm instead.");

            return self::FAILURE;
        }

        // Fetch latest release version from GitHub API.
        // Laravel's HTTP client is expressive and handles errors gracefully.
        $this->info('Checking latest Pagefind version...');

        $response = Http::timeout(15)
            ->withHeaders(['User-Agent' => 'scolta-laravel'])
            ->get('https://api.github.com/repos/CloudCannon/pagefind/releases/latest');

        if ($response->failed()) {
            $this->error('Failed to check latest Pagefind version: '.$response->status());

            return self::FAILURE;
        }

        $version = ltrim($response->json('tag_name', ''), 'v');
        if (empty($version)) {
            $this->error('Could not determine latest Pagefind version.');

            return self::FAILURE;
        }

        $filename = "pagefind-v{$version}-{$platform}.tar.gz";
        $downloadUrl = "https://github.com/CloudCannon/pagefind/releases/download/v{$version}/{$filename}";

        $this->info("Downloading Pagefind v{$version} for {$platform}...");
        $this->line("  URL: {$downloadUrl}");

        // Download to temp file.
        $tmpFile = tempnam(sys_get_temp_dir(), 'pagefind_');
        $downloadResponse = Http::timeout(60)
            ->withOptions(['sink' => $tmpFile])
            ->get($downloadUrl);

        if ($downloadResponse->failed()) {
            $this->error('Download failed: HTTP '.$downloadResponse->status());
            // Suppress: best-effort cleanup of partial download, file may already be removed.
            @unlink($tmpFile);

            return self::FAILURE;
        }

        // Extract the binary.
        $targetBinary = $targetDir.'/pagefind';

        $result = Process::run(
            'tar -xzf '.escapeshellarg($tmpFile)
            .' -C '.escapeshellarg($targetDir)
            .' pagefind'
        );

        // Suppress: best-effort cleanup of partial download, file may already be removed.
        @unlink($tmpFile);

        if (! file_exists($targetBinary)) {
            $this->error("Extraction failed. Binary not found at {$targetBinary}");
            if ($result->errorOutput()) {
                $this->line($result->errorOutput());
            }

            return self::FAILURE;
        }

        chmod($targetBinary, 0755);

        $this->info("Pagefind v{$version} installed to {$targetBinary}");

        // Auto-update .env with the binary path.
        $envPath = base_path('.env');
        if (file_exists($envPath)) {
            $env = file_get_contents($envPath);
            if (str_contains($env, 'SCOLTA_PAGEFIND_BINARY=')) {
                $env = preg_replace(
                    '/^SCOLTA_PAGEFIND_BINARY=.*/m',
                    "SCOLTA_PAGEFIND_BINARY={$targetBinary}",
                    $env,
                );
            } else {
                $env .= "\nSCOLTA_PAGEFIND_BINARY={$targetBinary}\n";
            }
            if (file_put_contents($envPath, $env) === false) {
                $this->error("Failed to update .env at {$envPath}");

                return self::FAILURE;
            }
            $this->info("Updated .env: SCOLTA_PAGEFIND_BINARY={$targetBinary}");
        } else {
            $this->warn("Add to your .env: SCOLTA_PAGEFIND_BINARY={$targetBinary}");
        }

        return self::SUCCESS;
    }
}
