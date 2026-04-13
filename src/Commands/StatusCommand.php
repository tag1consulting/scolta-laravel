<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Tag1\Scolta\Binary\PagefindBinary;
use Tag1\ScoltaLaravel\Models\ScoltaTracker;
use Tag1\ScoltaLaravel\Searchable;
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
            $htmlCount = count(glob($buildDir.'/*.html') ?: []);
            $this->line("  Path:       {$buildDir}");
            $this->line("  HTML files: {$htmlCount}");
        } else {
            $this->line("  Path: {$buildDir} (does not exist)");
        }

        // Pagefind index.
        $this->info('--- Pagefind Index ---');
        $indexFile = $outputDir.'/pagefind.js';
        if (file_exists($indexFile)) {
            $fragmentCount = count(glob($outputDir.'/fragment/*') ?: []);
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
            $activeIndexer = $binaryStatus['available'] ? 'binary (auto-detected)' : 'php (binary not found)';
        }
        $this->line("  Active indexer: {$activeIndexer}");
        if ($binaryStatus['available']) {
            $this->line("  Binary:         {$binaryStatus['message']}");
        } else {
            $this->warn('  Binary:         NOT AVAILABLE');
            $this->line("  {$binaryStatus['message']}");
            if ($activeIndexer !== 'php (forced)') {
                $this->warn('  To upgrade: npm install -g pagefind  OR  php artisan scolta:download-pagefind');
            }
        }

        // AI provider.
        $this->info('--- AI Provider ---');
        if ($ai->hasLaravelAiSdk()) {
            $this->line('  Provider: Laravel AI SDK (laravel/ai)');
        } else {
            $provider = $ai->getConfig()->aiProvider ?: 'anthropic';
            $hasKey = ! empty($ai->getConfig()->aiApiKey);
            $this->line("  Provider: {$provider} (built-in)");
            $this->line('  API key:  '.($hasKey ? 'configured' : 'NOT SET'));
        }

        // Assets published check.
        $this->info('--- Assets ---');
        $assetsPublished = file_exists(public_path('vendor/scolta/scolta.js'));
        $this->line('  Published: '.($assetsPublished ? 'yes' : 'no'));
        if (! $assetsPublished) {
            $this->warn('  Run: php artisan vendor:publish --tag=scolta-assets');
        }

        return self::SUCCESS;
    }
}
