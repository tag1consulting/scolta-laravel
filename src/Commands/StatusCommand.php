<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tag1\Scolta\AiProvider\Amazee\KeyExpiryRecovery;
use Tag1\Scolta\Binary\PagefindBinary;
use Tag1\ScoltaLaravel\AiProvider\Amazee\LaravelConfigStorage;
use Tag1\ScoltaLaravel\Cache\LaravelCacheDriver;
use Tag1\ScoltaLaravel\Models\ScoltaTracker;
use Tag1\ScoltaLaravel\Searchable;
use Tag1\ScoltaLaravel\Services\AssetStatus;
use Tag1\ScoltaLaravel\Services\ContentSource;
use Tag1\ScoltaLaravel\Services\ScoltaAiService;

/**
 * Show Scolta index status.
 *
 * Equivalent to `wp scolta status` (WordPress) and `drush scolta:status` (Drupal).
 * Uses Laravel's command table output for clean formatting — one of the
 * small touches that makes Artisan commands pleasant to work with.
 */
class StatusCommand extends Command
{
    protected $signature = 'scolta:status';

    protected $description = 'Show Scolta index status, tracker state, and configuration';

    public function handle(ScoltaAiService $ai, ContentSource $source): int
    {
        $buildDir = config('scolta.pagefind.build_dir', storage_path('scolta/build'));
        $outputDir = config('scolta.pagefind.output_dir', public_path('scolta-pagefind'));

        // Tracker status.
        $this->info('--- Tracker ---');
        if (! Schema::hasTable('scolta_tracker')) {
            $this->warn('Tracker table does not exist. Run: php artisan migrate');
        } else {
            $pendingIndex = ScoltaTracker::getPendingCount('index');
            $pendingDelete = ScoltaTracker::getPendingCount('delete');
            $this->line("  Pending index:  {$pendingIndex}");
            $this->line("  Pending delete: {$pendingDelete}");
        }

        // Content counts.
        $this->info('--- Content ---');
        $models = config('scolta.models', []);
        if (empty($models)) {
            $this->warn('  No models configured. Add model classes to config/scolta.php');
        } else {
            $total = $source->getTotalCount();
            $modelNames = array_map(fn ($m) => class_basename($m), $models);
            $this->line('  Models:    '.implode(', ', $modelNames));
            $this->line("  Published: {$total}");

            // Model validation: check each model uses the Searchable trait.
            foreach ($models as $modelClass) {
                if (class_exists($modelClass) && ! in_array(Searchable::class, class_uses_recursive($modelClass), true)) {
                    $this->warn("  Warning: {$modelClass} does not use the Searchable trait.");
                }
            }
        }

        // Build directory.
        $this->info('--- Build Directory ---');
        if (is_dir($buildDir)) {
            $htmlCount = count(File::glob($buildDir.'/*.html') ?: []);
            $this->line("  Path:       {$buildDir}");
            $this->line("  HTML files: {$htmlCount}");
        } else {
            $this->line("  Path: {$buildDir} (does not exist)");
        }

        // Pagefind index.
        $this->info('--- Pagefind Index ---');
        $indexFile = $outputDir.'/pagefind/pagefind.js';
        if (file_exists($indexFile)) {
            $fragmentCount = count(File::glob($outputDir.'/pagefind/fragment/*') ?: []);
            $mtime = filemtime($indexFile);
            $this->line("  Path:       {$outputDir}");
            $this->line("  Fragments:  {$fragmentCount}");
            $this->line('  Last built: '.($mtime ? date('Y-m-d H:i:s', $mtime) : 'unknown'));
        } else {
            $this->line("  Path: {$outputDir} (no index built yet)");
        }

        // Pagefind binary / active indexer.
        $this->info('--- Indexer ---');
        $resolver = new PagefindBinary(
            configuredPath: config('scolta.pagefind.binary'),
            projectDir: base_path(),
        );
        $binaryStatus = $resolver->status();
        $indexerSetting = config('scolta.indexer', 'auto');
        if ($indexerSetting === 'php') {
            $activeIndexer = 'php (forced)';
        } elseif ($indexerSetting === 'binary') {
            $activeIndexer = $binaryStatus['available'] ? 'binary' : 'binary (not found — check path)';
        } else {
            // auto: always PHP regardless of binary availability.
            $activeIndexer = 'php (recommended)';
        }
        $this->line("  Active indexer: {$activeIndexer}");
        if ($indexerSetting === 'binary') {
            if ($binaryStatus['available']) {
                $this->line("  Binary:         {$binaryStatus['message']}");
            } else {
                $this->warn('  Binary:         NOT AVAILABLE');
                $this->line("  {$binaryStatus['message']}");
                $this->warn('  To fix: npm install -g pagefind  OR  php artisan scolta:download-pagefind');
            }
        }

        // AI provider.
        $this->info('--- AI Provider ---');
        if ($ai->hasLaravelAiSdk()) {
            $this->line('  Provider: Laravel AI SDK (laravel/ai)');
        } elseif ($ai->isAmazeeActive()) {
            $this->line('  Provider: Amazee.ai (managed gateway)');
            $this->line('  API key:  configured (Amazee.ai credentials)');
            $this->reportAmazeeConnectionState();
        } else {
            // No coalescing to a provider nobody chose: an empty value means
            // AI is off, and a status command has to report that rather than
            // name Anthropic.
            $provider = $ai->getConfig()->aiProvider;
            $hasKey = ! empty($ai->getConfig()->aiApiKey);
            if ($provider === '') {
                $this->warn('  Provider: none selected — AI features are off (search is unaffected)');
                $this->line('  Options:  Set SCOLTA_AI_PROVIDER in .env, or run:');
                $this->line('              php artisan scolta:amazee:provision  (free demo, no email)');
            } else {
                $this->line("  Provider: {$provider} (built-in)");
                if ($hasKey) {
                    $this->line('  API key:  configured');
                } else {
                    $this->warn('  API key:  NOT SET');
                    $this->line('  Options:  Set SCOLTA_API_KEY in .env, or run:');
                    $this->line('              php artisan scolta:amazee:provision  (free demo, no email)');
                }
            }
        }

        // Assets published check.
        $this->info('--- Assets ---');
        $assetStatus = new AssetStatus;
        if (! $assetStatus->arePublished()) {
            $this->line('  Published: no');
            $this->warn('  Run: php artisan vendor:publish --tag=scolta-assets');
        } else {
            $current = $assetStatus->areCurrent();
            if ($current === true) {
                $this->line('  Published: yes (current)');
            } elseif ($current === false) {
                $this->line('  Published: yes (STALE)');
                $this->warn('  Run: php artisan vendor:publish --tag=scolta-assets --force');
            } else {
                $this->line('  Published: yes');
            }
        }

        return self::SUCCESS;
    }

    /**
     * Report whether the stored Amazee.ai credentials still authenticate.
     *
     * When the credentials are no longer accepted the persistent
     * re-authentication marker is set (see KeyExpiryRecovery); surface it here
     * with the reconnect path so an operator running `scolta:status` is not
     * left guessing why AI is degraded.
     *
     * @since 1.0.5
     *
     * @stability experimental
     */
    private function reportAmazeeConnectionState(): void
    {
        $recovery = new KeyExpiryRecovery(
            storage: new LaravelConfigStorage,
            cache: new LaravelCacheDriver,
            logger: logger(),
        );

        if ($recovery->isUpgradeNeeded()) {
            $this->warn('  Status:   NEEDS RE-AUTHENTICATION');
            $this->line('  The Amazee.ai connection is no longer accepted. AI search features');
            $this->line('  are degraded until you reconnect. To re-authenticate:');
            $this->line('    - Open the Scolta Amazee.ai settings page and continue with Amazee.ai, or');
            $this->line('    - Run: php artisan scolta:amazee:provision <email>');
        } elseif ($recovery->isAuthFailing()) {
            $this->warn('  Status:   recent authentication failure (retrying)');
        } else {
            $this->line('  Status:   connected');
        }
    }
}
