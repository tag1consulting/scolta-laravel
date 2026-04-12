<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Tag1\Scolta\Export\ContentExporter;
use Tag1\Scolta\Export\ContentItem;
use Tag1\Scolta\Index\PhpIndexer;

/**
 * Trigger a full index rebuild from the queue.
 *
 * Dispatched by ScoltaObserver when auto-rebuild is enabled and content
 * changes. Uses cache-based debouncing to avoid rebuilding on every
 * single save — multiple edits within the delay window are batched
 * into a single rebuild.
 *
 * The job gathers content from all configured models, checks the
 * fingerprint to avoid unnecessary rebuilds, then dispatches a chain
 * of ProcessIndexChunk + FinalizeIndex jobs.
 *
 * @since 0.2.0
 *
 * @stability experimental
 */
class TriggerRebuild implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue;

    /**
     * Whether to force a full rebuild, bypassing the fingerprint check.
     *
     * @since 0.2.0
     *
     * @stability experimental
     */
    public bool $force;

    /**
     * Create a new TriggerRebuild job instance.
     *
     * @param  bool  $force  Skip fingerprint check and force a full rebuild.
     *
     * @since 0.2.0
     *
     * @stability experimental
     */
    public function __construct(bool $force = false)
    {
        $this->force = $force;
    }

    /**
     * Execute the rebuild.
     *
     * Clears the debounce flag, gathers content, checks fingerprint,
     * and dispatches chunk processing jobs followed by finalization.
     *
     * @since 0.2.0
     *
     * @stability experimental
     */
    public function handle(): void
    {
        // Clear debounce flag so future changes can schedule new rebuilds.
        Cache::forget('scolta_rebuild_scheduled');

        $stateDir = storage_path('scolta/state');
        $outputDir = config('scolta.pagefind.output_dir', public_path('scolta-pagefind'));
        $hmacSecret = config('app.key');
        $language = config('scolta.ai_languages.0', 'en');

        // Gather content from models.
        $items = $this->gatherItems();
        if (empty($items)) {
            return;
        }

        // Filter items by minimum content length.
        $exporter = new ContentExporter($outputDir);
        $items = $exporter->exportToItems($items);
        if (empty($items)) {
            return;
        }

        // Check fingerprint — skip if nothing changed (unless forced).
        $indexer = new PhpIndexer($stateDir, $outputDir, $hmacSecret, $language);
        $fingerprint = $indexer->shouldBuild($items);
        if ($fingerprint === null && !$this->force) {
            return;
        }
        // When forced but fingerprint is null, compute one for finalization.
        if ($fingerprint === null) {
            $fingerprint = md5(serialize($items));
        }

        // Chunk and dispatch.
        $chunkSize = 50;
        $chunks = array_chunk($items, $chunkSize);
        $jobs = [];

        foreach ($chunks as $idx => $chunk) {
            $jobs[] = new ProcessIndexChunk(
                $idx,
                $chunk,
                count($items),
                $stateDir,
                $outputDir,
                $hmacSecret,
                $language,
            );
        }

        $jobs[] = new FinalizeIndex(
            $stateDir,
            $outputDir,
            $hmacSecret,
            $language,
            $fingerprint,
        );

        Bus::chain($jobs)->dispatch();
    }

    /**
     * Gather content items from all configured Eloquent models.
     *
     * @return ContentItem[]
     *
     * @since 0.2.0
     *
     * @stability experimental
     */
    private function gatherItems(): array
    {
        $models = config('scolta.models', []);
        $siteName = config('scolta.site_name', config('app.name'));
        $items = [];

        foreach ($models as $modelClass) {
            if (! class_exists($modelClass)) {
                continue;
            }

            foreach ($modelClass::all() as $model) {
                if (! method_exists($model, 'toSearchableContent')) {
                    continue;
                }

                $content = $model->toSearchableContent();

                if ($content instanceof ContentItem) {
                    $items[] = $content;
                } elseif (is_array($content)) {
                    $items[] = new ContentItem(
                        id: $modelClass.'-'.$model->getKey(),
                        title: $content['title'] ?? '',
                        bodyHtml: $content['body'] ?? '',
                        url: $content['url'] ?? '',
                        date: $model->updated_at?->format('Y-m-d') ?? '',
                        siteName: $siteName,
                    );
                }
            }
        }

        return $items;
    }
}
