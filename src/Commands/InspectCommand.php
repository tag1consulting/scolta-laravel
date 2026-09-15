<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Commands;

use FilesystemIterator;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\File;
use Tag1\ScoltaLaravel\Services\IndexLocator;

/**
 * Show what the built index holds for one record.
 *
 * A fragment is the index's own copy of a page: the URL, the indexed text,
 * the filter values and the metadata the result list renders. Reading one
 * answers "is this record in the index, and with what?" without a rebuild
 * and without the browser.
 *
 * Fragment files are named by a content hash, not by URL, so finding the one
 * for a record means decompressing fragments until its URL turns up. Fine for
 * debugging one page; slow on a six-figure corpus over NFS.
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
        {model : Model class — App\Models\Post, or Post resolved against app/Models}
        {id : Primary key}
        {--json : Emit the matched fragments as JSON instead of the human sections}';

    protected $description = 'Show what the built index holds for one record: its URL, indexed text, filters and metadata';

    public function handle(IndexLocator $locator): int
    {
        $outputDir = config('scolta.pagefind.output_dir', public_path('scolta-pagefind'));
        $location = $locator->locate($outputDir);
        if ($location === null) {
            $this->error("No built index under {$outputDir}. Run php artisan scolta:build first.");

            return self::FAILURE;
        }

        $class = $this->resolveModelClass((string) $this->argument('model'));
        if ($class === null) {
            return self::FAILURE;
        }

        $record = $class::query()->find($this->argument('id'));
        if ($record === null) {
            $this->error("No {$class} with key {$this->argument('id')}.");

            return self::FAILURE;
        }

        // method_exists() rather than class_uses_recursive(): it is the check
        // ContentSource makes before indexing a record, and a model without
        // the trait is never indexed.
        if (! method_exists($record, 'toSearchableContent')) {
            $this->error("{$class} does not use the Searchable trait, so it is never indexed.");

            return self::FAILURE;
        }

        // The URL is the only join between a record and its fragment: nothing
        // in the fragment carries the model or the key. Matching is anchored
        // on the end of the URL rather than str_contains() so that /posts/123
        // does not match /posts/1234, while a prefixed URL still does.
        $url = $record->toSearchableContent()->url;

        $matches = [];
        $entries = is_dir($location['fragmentDir'])
            ? new FilesystemIterator($location['fragmentDir'], FilesystemIterator::SKIP_DOTS)
            : [];
        foreach ($entries as $file) {
            $fragment = $this->readFragment($file->getPathname());
            if ($fragment === null) {
                continue;
            }
            $fragmentUrl = (string) ($fragment['url'] ?? '');
            if ($fragmentUrl === $url || str_ends_with($fragmentUrl, $url)) {
                $matches[$file->getFilename()] = $fragment;
            }
        }

        if ($matches === []) {
            $this->warn("Nothing in the index is indexed at {$url}. It may not be indexed, or the index may predate it.");
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
            foreach ($fragment as $key => $value) {
                $this->line("  {$key}: ".(is_scalar($value) || $value === null
                    ? var_export($value, true)
                    : (string) json_encode($value, JSON_UNESCAPED_SLASHES)));
            }
        }

        return self::SUCCESS;
    }

    /**
     * Resolve the model argument to a Searchable Eloquent class.
     *
     * A short name is resolved against app/Models, the directory
     * `scolta:discover` scans.
     *
     * @return class-string<Model>|null Null when nothing usable was named; the
     *                                  reason has already been printed.
     */
    private function resolveModelClass(string $model): ?string
    {
        $class = class_exists($model) ? $model : 'App\\Models\\'.$model;

        if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            $this->error("No such model class: {$model}.");

            return null;
        }

        return $class;
    }

    /**
     * Decode one fragment file: gzipped "pagefind_dcd" + JSON.
     *
     * @return array<string, mixed>|null The decoded fragment, or null when the
     *                                   file is not one (a stray file in the
     *                                   fragment directory, or a truncated write).
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
