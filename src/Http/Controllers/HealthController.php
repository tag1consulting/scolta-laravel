<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Tag1\Scolta\Health\HealthChecker;
use Tag1\ScoltaLaravel\Cache\LaravelCacheDriver;
use Tag1\ScoltaLaravel\Models\ScoltaTracker;
use Tag1\ScoltaLaravel\Services\AssetStatus;
use Tag1\ScoltaLaravel\Services\IndexLocator;
use Tag1\ScoltaLaravel\Services\ScoltaAiService;

/**
 * GET /api/scolta/v1/health
 *
 * Returns JSON status for monitoring tools, load balancers, and dashboards.
 *
 * Anonymous callers receive only the overall status — enough for uptime
 * monitors. The full diagnostic payload (provider, index integrity, tracker
 * counts, asset staleness) requires the 'scolta.health-detail' Gate, which
 * defaults to any authenticated user and can be redefined by the host app.
 *
 * @since 0.1.0
 *
 * @stability experimental
 */
class HealthController extends Controller
{
    /**
     * Fault key for an index that exists but fails this adapter's integrity
     * spot check (empty pagefind.js, empty/corrupt or absent fragment).
     *
     * Named for the `index.integrity.valid` field it derives from, in
     * scolta-php's `index_*` namespace for index faults, alongside
     * `index_missing` and `index_stale_artifact_urls`.
     *
     * scolta-drupal's HealthController runs the same spot check on the same
     * field and still assigns `degraded` over the status; this key and
     * `degradeFor()` are meant to be transcribed there rather than invented a
     * second time.
     *
     * @since 1.4.0
     *
     * @stability experimental
     */
    public const REASON_INDEX_INTEGRITY_INVALID = 'index_integrity_invalid';

    /**
     * @since 0.1.0
     *
     * @stability experimental
     */
    public function __invoke(ScoltaAiService $ai): JsonResponse
    {
        $config = $ai->getConfig();
        $outputDir = config('scolta.pagefind.output_dir', public_path('scolta-pagefind'));

        $checker = new HealthChecker(
            config: $config,
            indexOutputDir: $outputDir,
            pagefindBinaryPath: config('scolta.pagefind.binary'),
            projectDir: base_path(),
            // Same cache ScoltaAiService records recovery markers in, so
            // `ai_usable` reflects whether the stored key still authenticates
            // (a cached marker, never a live API call per health request).
            cache: new LaravelCacheDriver,
        );

        $result = $checker->check();

        // Laravel-specific: override AI provider when Laravel AI SDK is active.
        if ($ai->hasLaravelAiSdk()) {
            $result['ai_provider'] = 'laravel-sdk';
            $result['ai_configured'] = true;
        }

        // Laravel-specific: index detail enrichment.
        $locator = new IndexLocator;
        $location = $locator->locate($outputDir);
        if ($result['index_exists'] && $location !== null) {
            $indexFile = $location['indexFile'];
            $mtime = filemtime($indexFile);
            // `fragments` is the indexed page count — one fragment per page —
            // read from pagefind-entry.json rather than from a directory
            // listing that scales with the corpus on an endpoint monitors poll.
            $result['index'] = [
                'built' => true,
                'fragments' => $locator->indexedPageCount($location),
                'last_build' => $mtime ? date('c', $mtime) : null,
            ];

            // Validate index integrity: check that pagefind.js is non-empty
            // and at least one fragment file exists and is readable.
            $integrity = ['valid' => true, 'issues' => []];

            $jsSize = filesize($indexFile);
            if ($jsSize === false || $jsSize === 0) {
                $integrity['valid'] = false;
                $integrity['issues'][] = 'pagefind.js is empty or unreadable';
            }

            // Spot-check one fragment is readable and non-empty; the locator
            // reads a single directory entry rather than listing them all.
            $firstFragment = $locator->firstFragment($location);
            if ($firstFragment !== null) {
                $fragSize = filesize($firstFragment);
                if ($fragSize === false || $fragSize === 0) {
                    $integrity['valid'] = false;
                    $integrity['issues'][] = 'Fragment file is empty or corrupt';
                }
            } else {
                $integrity['valid'] = false;
                $integrity['issues'][] = 'No fragment files found';
            }

            $result['index']['integrity'] = $integrity;

            if (! $integrity['valid']) {
                $result = self::degradeFor($result, self::REASON_INDEX_INTEGRITY_INVALID);
            }
        } else {
            $result['index'] = ['built' => false];
        }

        // Laravel-specific: tracker status.
        $tracker = ['available' => Schema::hasTable('scolta_tracker')];
        if ($tracker['available']) {
            $tracker['pending_index'] = ScoltaTracker::getPendingCount('index');
            $tracker['pending_delete'] = ScoltaTracker::getPendingCount('delete');
        }
        $result['tracker'] = $tracker;

        $assetStatus = new AssetStatus;
        $result['assets_published'] = $assetStatus->arePublished();

        $assetsCurrent = $assetStatus->areCurrent();
        if ($assetsCurrent !== null) {
            $result['assets_current'] = $assetsCurrent;
            if (! $assetsCurrent) {
                $result['assets_warning'] = 'Published JS does not match package version. Run: php artisan vendor:publish --tag=scolta-assets --force';
            }
        }

        return response()->json(
            self::shapePayload($result, Gate::allows('scolta.health-detail'))
        );
    }

    /**
     * Record an adapter-detected fault on a payload from `HealthChecker::check()`.
     *
     * `status_reasons` — the checker's list of fault keys, empty exactly when
     * the status is `ok`, from scolta-php 1.5.0 — is appended to, never
     * created: the adapter cannot invent a reason vocabulary for a library
     * that reports none, so against an older scolta-php this sets the status
     * and nothing else, exactly as the code it replaces did.
     *
     * The status is raised only from `ok`. Assigning `degraded` outright reads
     * as a raise only while `degraded` is the worst value there is; the moment
     * the checker gains a more severe one, that assignment demotes it and the
     * endpoint reports a healthier site than the check found. Any other value
     * is left standing, with the reason still recorded.
     *
     * @param  array<string, mixed>  $result  Payload from `HealthChecker::check()`, possibly already enriched.
     * @param  string  $reason  Machine-readable fault key, e.g. self::REASON_INDEX_INTEGRITY_INVALID.
     * @return array<string, mixed>
     *
     * @since 1.4.0
     *
     * @stability experimental
     */
    public static function degradeFor(array $result, string $reason): array
    {
        if (array_key_exists('status_reasons', $result) && is_array($result['status_reasons'])) {
            if (! in_array($reason, $result['status_reasons'], true)) {
                $result['status_reasons'][] = $reason;
            }
        }

        if (($result['status'] ?? null) === 'ok') {
            $result['status'] = 'degraded';
        }

        return $result;
    }

    /**
     * Shape the health payload for the caller's authorization level.
     *
     * The full report is always computed first so the trimmed status still
     * reflects integrity degradation; unauthorized callers then get exactly
     * ['status' => ...] and nothing else.
     *
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     *
     * @since 1.0.4
     *
     * @stability experimental
     */
    public static function shapePayload(array $result, bool $detail): array
    {
        return $detail ? $result : ['status' => $result['status']];
    }
}
