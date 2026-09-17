<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\File;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tag1\Scolta\Index\CborDecoder;
use Tag1\Scolta\Index\PageTableLedger;
use Tag1\Scolta\Storage\FilesystemDriver;
use Tag1\ScoltaLaravel\Services\IndexLocator;

/**
 * Show what the built index holds for one record, by URL or by model and key.
 *
 * A fragment is the index's own copy of a page: the URL, the indexed text,
 * the filter values and the metadata the result list renders. Reading one
 * answers "is this record in the index, and with what?" without a rebuild
 * and without the browser.
 *
 * A fragment file is named by a content hash, so nothing on disk maps a
 * record to one. The join is made through two tables the build already
 * keeps: the page-table ledger in the state directory (item ID → ordinal)
 * and the pf_meta page table (ordinal → fragment hash). Three file reads,
 * whatever the corpus size; an earlier draft gunzipped every fragment.
 *
 * The Laravel spelling of `drush scolta:inspect`; `--json` rather than Drush's
 * `--format=`, matching `scolta:status`.
 *
 * @since 2.0.0
 *
 * @stability experimental
 */
class InspectCommand extends Command
{
    protected $signature = 'scolta:inspect
        {url? : Path of the record, e.g. /posts/123; the leading slash is optional. Omit to use --model and --id}
        {--model= : Model class — App\Models\Post, or Post resolved against app/Models. Requires --id}
        {--id= : Primary key. Requires --model}
        {--json : Emit the matched fragment as JSON instead of the human sections}';

    protected $description = 'Show what the built index holds for one record: its URL, indexed text, filters and metadata';

    public function handle(IndexLocator $locator, Router $router): int
    {
        $outputDir = config('scolta.pagefind.output_dir', public_path('scolta-pagefind'));
        $location = $locator->locate($outputDir);
        if ($location === null) {
            $this->error("No built index under {$outputDir}. Run php artisan scolta:build first.");

            return self::FAILURE;
        }

        $url = (string) $this->argument('url');
        $model = (string) $this->option('model');
        $id = (string) $this->option('id');
        if ($url !== '' && ($model !== '' || $id !== '')) {
            $this->error('Pass a URL or --model and --id, not both.');

            return self::FAILURE;
        }
        $record = $url !== '' ? $this->recordFromUrl($router, $url) : $this->recordFromId($model, $id);
        if ($record === null) {
            return self::FAILURE;
        }

        // method_exists() rather than class_uses_recursive(): it is the check
        // ContentSource makes before indexing a record, and a model without
        // the trait is never indexed.
        if (! method_exists($record, 'toSearchableContent')) {
            $this->error($record::class.' does not use the Searchable trait, so it is never indexed.');

            return self::FAILURE;
        }

        $stateDir = config('scolta.state_dir', storage_path('app/scolta'));
        $ledger = new PageTableLedger($stateDir, new FilesystemDriver);
        if ($ledger->isEmpty()) {
            $this->error("No page-table ledger under {$stateDir}. Run php artisan scolta:build first.");

            return self::FAILURE;
        }
        $metaFiles = File::glob(dirname($location['indexFile']).'/pagefind.*.pf_meta');
        if ($metaFiles === []) {
            $this->error('No pf_meta in the index. Run php artisan scolta:build first.');

            return self::FAILURE;
        }
        $pageTable = CborDecoder::decodeArtifact($metaFiles[0])[1];

        // The item ID is whatever the model's toSearchableContent() returns
        // (`table-pk` by default); the ledger is keyed by it.
        $key = $record->toSearchableContent()->id;
        $matches = [];
        $ordinal = $ledger->ordinalFor($key);
        if ($ordinal !== null) {
            $hash = (string) ($pageTable[$ordinal][0] ?? '');
            $fragment = $hash === '' ? null : $this->readFragment($location['fragmentDir']."/{$hash}.pf_fragment");
            if ($fragment === null) {
                // The ledger says the page is indexed but the index disagrees:
                // the two are out of step, which a plain "not indexed" would hide.
                $this->warn("{$key} is at ordinal {$ordinal} in the page-table ledger but its fragment ("
                    .($hash === '' ? 'no hash in pf_meta' : $hash)
                    .') is missing or unreadable. Run php artisan scolta:build.');
            } else {
                $matches[$key] = $fragment;
            }
        }

        if ($matches === []) {
            $this->warn("Nothing in the index is indexed for {$key}. It may not be indexed, or the index may predate it.");
        }

        if ($this->option('json')) {
            $this->line((string) json_encode(
                $matches,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            ));

            return self::SUCCESS;
        }

        foreach ($matches as $name => $fragment) {
            $this->info("--- {$name} ---");
            foreach ($fragment as $field => $value) {
                $this->line("  {$field}: ".(is_scalar($value) || $value === null
                    ? var_export($value, true)
                    : (string) json_encode($value, JSON_UNESCAPED_SLASHES)));
            }
        }

        return self::SUCCESS;
    }

    /**
     * The record a path routes to: the bound Model among its route parameters.
     *
     * Null when the path routes nowhere, or to a route without a Model
     * parameter; the reason has already been printed.
     */
    private function recordFromUrl(Router $router, string $path): ?Model
    {
        $request = Request::create('/'.ltrim($path, '/'));
        try {
            $route = $router->getRoutes()->match($request)->bind($request);
            $router->substituteBindings($route);
            $router->substituteImplicitBindings($route);
        } catch (HttpException|ModelNotFoundException) {
            $this->error("{$path} does not route to anything.");

            return null;
        }

        foreach ($route->parameters() as $parameter) {
            if ($parameter instanceof Model) {
                return $parameter;
            }
        }
        $this->error("{$path} does not route to a model.");

        return null;
    }

    /**
     * The record of a model class with a primary key.
     *
     * A short class name is resolved against app/Models, the directory
     * `scolta:discover` scans. Null when nothing usable was named; the reason
     * has already been printed.
     */
    private function recordFromId(string $model, string $id): ?Model
    {
        if ($model === '' || $id === '') {
            $this->error('Pass a URL, or both --model and --id.');

            return null;
        }

        $class = class_exists($model) ? $model : 'App\\Models\\'.$model;
        if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            $this->error("No such model class: {$model}.");

            return null;
        }

        $record = $class::query()->find($id);
        if ($record === null) {
            $this->error("No {$class} with key {$id}.");

            return null;
        }

        return $record;
    }

    /**
     * Decode one fragment file: gzipped "pagefind_dcd" + JSON.
     *
     * @return array<string, mixed>|null The decoded fragment, or null when the
     *                                   file is missing or is not one.
     */
    private function readFragment(string $file): ?array
    {
        try {
            $raw = @gzdecode(File::get($file));
        } catch (\Throwable) {
            return null;
        }

        if ($raw === false || ! str_starts_with($raw, 'pagefind_dcd')) {
            return null;
        }

        $decoded = json_decode(substr($raw, strlen('pagefind_dcd')), true);

        return is_array($decoded) ? $decoded : null;
    }
}
