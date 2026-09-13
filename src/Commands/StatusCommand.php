<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tag1\Scolta\AiProvider\Amazee\KeyExpiryRecovery;
use Tag1\ScoltaLaravel\AiProvider\Amazee\LaravelConfigStorage;
use Tag1\ScoltaLaravel\Cache\LaravelCacheDriver;
use Tag1\ScoltaLaravel\Jobs\TriggerRebuild;
use Tag1\ScoltaLaravel\Models\ScoltaTracker;
use Tag1\ScoltaLaravel\Searchable;
use Tag1\ScoltaLaravel\Services\AssetStatus;
use Tag1\ScoltaLaravel\Services\ContentSource;
use Tag1\ScoltaLaravel\Services\IndexLocator;
use Tag1\ScoltaLaravel\Services\ScoltaAiService;

/**
 * Show Scolta index status.
 *
 * Equivalent to `wp scolta status` (WordPress) and `drush scolta:status` (Drupal).
 * Uses Laravel's command table output for clean formatting — one of the
 * small touches that makes Artisan commands pleasant to work with.
 *
 * The report is gathered once into a nested array, then rendered either as the
 * human sections or, with --json, as one JSON document on stdout.
 *
 * The section names and their fields match `drush scolta:status` wherever both
 * adapters report the same thing (`pagefind_index`, `ai_provider`), so one
 * script can read either. The
 * serialization deliberately differs: scolta-drupal hardcodes `Yaml::dump()`
 * with no `--format` option of its own, and JSON is valid YAML, so a consumer
 * of both parses this document with the same YAML parser it already needs for
 * Drush — while `--json | jq` also works, which is what a Laravel operator
 * reaches for. The reverse choice would have served neither.
 */
class StatusCommand extends Command
{
    protected $signature = 'scolta:status
        {--json : Emit the report as one JSON document on stdout instead of the human sections}';

    protected $description = 'Show Scolta index status, tracker state, and configuration';

    public function handle(ScoltaAiService $ai, ContentSource $source): int
    {
        $status = $this->gather($ai, $source);

        if ($this->option('json')) {
            // Nothing else may reach stdout on this path: the point is that
            // `php artisan scolta:status --json | jq` gets one clean document.
            $this->line((string) json_encode(
                $status,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            ));

            return self::SUCCESS;
        }

        $this->render($status);

        return self::SUCCESS;
    }

    /**
     * Collect the whole status report as a nested array.
     *
     * @return array<string, mixed>
     */
    private function gather(ScoltaAiService $ai, ContentSource $source): array
    {
        $outputDir = config('scolta.pagefind.output_dir', public_path('scolta-pagefind'));

        return [
            'tracker' => $this->gatherTracker(),
            'build' => ['queued_items' => Queue::size(TriggerRebuild::QUEUE_NAME)],
            'content' => $this->gatherContent($source),
            'pagefind_index' => $this->gatherIndex($outputDir),
            'ai_provider' => $this->gatherAiProvider($ai),
            'assets' => $this->gatherAssets(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function gatherTracker(): array
    {
        if (! Schema::hasTable('scolta_tracker')) {
            return ['available' => false];
        }

        return [
            'available' => true,
            'pending_index' => ScoltaTracker::getPendingCount('index'),
            'pending_delete' => ScoltaTracker::getPendingCount('delete'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function gatherContent(ContentSource $source): array
    {
        $models = config('scolta.models', []);
        if (empty($models)) {
            return ['models' => [], 'published_count' => null, 'models_without_trait' => []];
        }

        // Model validation: check each model uses the Searchable trait.
        $withoutTrait = [];
        foreach ($models as $modelClass) {
            if (class_exists($modelClass) && ! in_array(Searchable::class, class_uses_recursive($modelClass), true)) {
                $withoutTrait[] = $modelClass;
            }
        }

        return [
            'models' => array_values(array_map(fn ($m) => class_basename($m), $models)),
            'published_count' => $source->getTotalCount(),
            'models_without_trait' => $withoutTrait,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function gatherIndex(string $outputDir): array
    {
        $locator = new IndexLocator;
        $location = $locator->locate($outputDir);

        if ($location === null) {
            return ['path' => $outputDir, 'built' => false];
        }

        // The count comes from pagefind-entry.json, not from a listing of one
        // fragment file per indexed page, which is minutes-slow on a large
        // corpus over NFS.
        $mtime = filemtime($location['indexFile']);

        return [
            'path' => $outputDir,
            'built' => true,
            'pages' => $locator->indexedPageCount($location),
            'last_built' => $mtime ? date('Y-m-d H:i:s', $mtime) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function gatherAiProvider(ScoltaAiService $ai): array
    {
        // Diverges from scolta-drupal's `ai_provider` by necessity: the
        // provider sets do not overlap (laravel/ai and Amazee.ai here, the
        // Drupal AI module there), so `kind` discriminates them as a
        // machine-readable key where Drush emits display prose in `routing`.
        // Drupal's richer `api_key: {source, description}` comes from a key
        // resolution (scolta-php#252) this adapter has not ported; until it
        // does, `api_key_configured` is the whole of what it can honestly say.

        if ($ai->hasLaravelAiSdk()) {
            return ['kind' => 'laravel_sdk', 'provider' => 'laravel-sdk', 'api_key_configured' => true];
        }

        if ($ai->isAmazeeActive()) {
            return [
                'kind' => 'amazee',
                'provider' => 'amazee',
                'api_key_configured' => true,
                'connection' => $this->amazeeConnectionState(),
            ];
        }

        // No coalescing to a provider nobody chose: an empty value means
        // AI is off, and a status command has to report that rather than
        // name Anthropic.
        $provider = $ai->getConfig()->aiProvider;
        if ($provider === '') {
            return ['kind' => 'none', 'provider' => null, 'api_key_configured' => false];
        }

        return [
            'kind' => 'builtin',
            'provider' => $provider,
            'api_key_configured' => ! empty($ai->getConfig()->aiApiKey),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function gatherAssets(): array
    {
        $assetStatus = new AssetStatus;

        if (! $assetStatus->arePublished()) {
            return ['published' => false, 'current' => null];
        }

        return ['published' => true, 'current' => $assetStatus->areCurrent()];
    }

    /**
     * Print the human report — the default output, unchanged.
     *
     * @param  array<string, mixed>  $status
     */
    private function render(array $status): void
    {
        $this->info('--- Tracker ---');
        if (! $status['tracker']['available']) {
            $this->warn('Tracker table does not exist. Run: php artisan migrate');
        } else {
            $this->line("  Pending index:  {$status['tracker']['pending_index']}");
            $this->line("  Pending delete: {$status['tracker']['pending_delete']}");
        }

        $this->info('--- Build ---');
        $this->line("  Queued items: {$status['build']['queued_items']}");
        if ($status['build']['queued_items'] > 0) {
            $this->line('  A worker must listen to the `'.TriggerRebuild::QUEUE_NAME.'` queue: php artisan queue:work --queue='.TriggerRebuild::QUEUE_NAME);
        }

        $this->info('--- Content ---');
        if ($status['content']['models'] === []) {
            $this->warn('  No models configured. Add model classes to config/scolta.php');
        } else {
            $this->line('  Models:    '.implode(', ', $status['content']['models']));
            $this->line("  Published: {$status['content']['published_count']}");
            foreach ($status['content']['models_without_trait'] as $modelClass) {
                $this->warn("  Warning: {$modelClass} does not use the Searchable trait.");
            }
        }

        $this->info('--- Pagefind Index ---');
        if ($status['pagefind_index']['built']) {
            $this->line("  Path:       {$status['pagefind_index']['path']}");
            $this->line("  Pages:      {$status['pagefind_index']['pages']}");
            $this->line('  Last built: '.($status['pagefind_index']['last_built'] ?? 'unknown'));
        } else {
            $this->line("  Path: {$status['pagefind_index']['path']} (no index built yet)");
        }

        $this->info('--- AI Provider ---');
        $this->renderAiProvider($status['ai_provider']);

        $this->info('--- Assets ---');
        $this->renderAssets($status['assets']);
    }

    /**
     * @param  array<string, mixed>  $provider
     */
    private function renderAiProvider(array $provider): void
    {
        switch ($provider['kind']) {
            case 'laravel_sdk':
                $this->line('  Provider: Laravel AI SDK (laravel/ai)');
                break;

            case 'amazee':
                $this->line('  Provider: Amazee.ai (managed gateway)');
                $this->line('  API key:  configured (Amazee.ai credentials)');
                $this->renderAmazeeConnectionState($provider['connection']);
                break;

            case 'none':
                $this->warn('  Provider: none selected — AI features are off (search is unaffected)');
                $this->line('  Options:  Set SCOLTA_AI_PROVIDER in .env, or run:');
                $this->line('              php artisan scolta:amazee:provision  (free demo, no email)');
                break;

            default:
                $this->line("  Provider: {$provider['provider']} (built-in)");
                if ($provider['api_key_configured']) {
                    $this->line('  API key:  configured');
                } else {
                    $this->warn('  API key:  NOT SET');
                    $this->line('  Options:  Set SCOLTA_API_KEY in .env, or run:');
                    $this->line('              php artisan scolta:amazee:provision  (free demo, no email)');
                }
        }
    }

    /**
     * @param  array<string, mixed>  $assets
     */
    private function renderAssets(array $assets): void
    {
        if (! $assets['published']) {
            $this->line('  Published: no');
            $this->warn('  Run: php artisan vendor:publish --tag=scolta-assets');

            return;
        }

        if ($assets['current'] === true) {
            $this->line('  Published: yes (current)');
        } elseif ($assets['current'] === false) {
            $this->line('  Published: yes (STALE)');
            $this->warn('  Run: php artisan vendor:publish --tag=scolta-assets --force');
        } else {
            $this->line('  Published: yes');
        }
    }

    /**
     * Whether the stored Amazee.ai credentials still authenticate.
     *
     * When they are no longer accepted the persistent re-authentication
     * marker is set (see KeyExpiryRecovery); the report surfaces it with the
     * reconnect path so an operator running `scolta:status` is not left
     * guessing why AI is degraded.
     *
     * @since 1.0.5
     *
     * @stability experimental
     */
    private function amazeeConnectionState(): string
    {
        $recovery = new KeyExpiryRecovery(
            storage: new LaravelConfigStorage,
            cache: new LaravelCacheDriver,
            logger: logger(),
        );

        if ($recovery->isUpgradeNeeded()) {
            return 'needs_reauthentication';
        }

        if ($recovery->isAuthFailing()) {
            return 'auth_failing';
        }

        return 'connected';
    }

    private function renderAmazeeConnectionState(string $state): void
    {
        if ($state === 'needs_reauthentication') {
            $this->warn('  Status:   NEEDS RE-AUTHENTICATION');
            $this->line('  The Amazee.ai connection is no longer accepted. AI search features');
            $this->line('  are degraded until you reconnect. To re-authenticate:');
            $this->line('    - Open the Scolta Amazee.ai settings page and continue with Amazee.ai, or');
            $this->line('    - Run: php artisan scolta:amazee:provision <email>');
        } elseif ($state === 'auth_failing') {
            $this->warn('  Status:   recent authentication failure (retrying)');
        } else {
            $this->line('  Status:   connected');
        }
    }
}
