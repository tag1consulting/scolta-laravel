<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Tag1\ScoltaLaravel\Models\ScoltaTracker;
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

    public function handle(): int
    {
        $buildDir = config('scolta.pagefind.build_dir', storage_path('scolta/build'));
        $outputDir = config('scolta.pagefind.output_dir', public_path('scolta-pagefind'));
        $binary = config('scolta.pagefind.binary', 'pagefind');

        $rows = [];

        // Tracker status.
        $this->info('--- Tracker ---');
        if (!Schema::hasTable('scolta_tracker')) {
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
            $source = new ContentSource();
            $total = $source->getTotalCount();
            $modelNames = array_map(fn($m) => class_basename($m), $models);
            $this->line("  Models:    " . implode(', ', $modelNames));
            $this->line("  Published: {$total}");
        }

        // Build directory.
        $this->info('--- Build Directory ---');
        if (is_dir($buildDir)) {
            $htmlCount = count(glob($buildDir . '/*.html') ?: []);
            $this->line("  Path:       {$buildDir}");
            $this->line("  HTML files: {$htmlCount}");
        } else {
            $this->line("  Path: {$buildDir} (does not exist)");
        }

        // Pagefind index.
        $this->info('--- Pagefind Index ---');
        $indexFile = $outputDir . '/pagefind.js';
        if (file_exists($indexFile)) {
            $fragmentCount = count(glob($outputDir . '/fragment/*') ?: []);
            $mtime = filemtime($indexFile);
            $this->line("  Path:       {$outputDir}");
            $this->line("  Fragments:  {$fragmentCount}");
            $this->line("  Last built: " . ($mtime ? date('Y-m-d H:i:s', $mtime) : 'unknown'));
        } else {
            $this->line("  Path: {$outputDir} (no index built yet)");
        }

        // Pagefind binary.
        $this->info('--- Pagefind Binary ---');
        $result = Process::timeout(5)->run(escapeshellcmd($binary) . ' --version');
        if ($result->successful()) {
            $this->line("  Binary:  {$binary}");
            $this->line("  Version: " . trim($result->output()));
        } else {
            $this->warn("Pagefind binary not found at: {$binary}");
            $this->line("  Install: npm install -g pagefind");
            $this->line("  Or:      php artisan scolta:download-pagefind");
        }

        // AI provider.
        $this->info('--- AI Provider ---');
        $ai = app(ScoltaAiService::class);
        if ($ai->hasLaravelAiSdk()) {
            $this->line("  Provider: Laravel AI SDK (laravel/ai)");
        } else {
            $provider = config('scolta.ai_provider', 'anthropic');
            $hasKey = !empty(config('scolta.ai_api_key'));
            $this->line("  Provider: {$provider} (built-in)");
            $this->line("  API key:  " . ($hasKey ? 'configured' : 'NOT SET'));
        }

        return self::SUCCESS;
    }
}
