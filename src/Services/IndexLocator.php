<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Services;

use FilesystemIterator;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * Locates a built Pagefind index and answers "how many pages are in it?".
 *
 * Handles both layouts — the PHP indexer's nested `{output_dir}/pagefind/` and
 * the binary/Cloud pipeline's flat `{output_dir}/`; callers that assumed nested
 * reported a flat index as not built. The page count comes from
 * `pagefind-entry.json` rather than a listing of the fragment directory, which
 * holds one file per indexed page and is minutes-slow on a large corpus.
 *
 * @since 1.4.0
 *
 * @stability experimental
 */
class IndexLocator
{
    /**
     * Locate a built index under an output directory.
     *
     * Nested layout first, then flat, keyed off `pagefind.js` — the same
     * signal scolta-php's HealthChecker uses for `index_exists`.
     *
     * @return array{indexDir: string, indexFile: string, entryFile: string, fragmentDir: string}|null
     *                                                                                                 Null when no index is built under either layout.
     *
     * @since 1.4.0
     *
     * @stability experimental
     */
    public function locate(string $outputDir): ?array
    {
        foreach ([$outputDir.'/pagefind', $outputDir] as $dir) {
            if (File::exists($dir.'/pagefind.js')) {
                return [
                    'indexDir' => $dir,
                    'indexFile' => $dir.'/pagefind.js',
                    'entryFile' => $dir.'/pagefind-entry.json',
                    'fragmentDir' => $dir.'/fragment',
                ];
            }
        }

        return null;
    }

    /**
     * Read the indexed page count from pagefind-entry.json.
     *
     * @param  array{indexDir: string, indexFile: string, entryFile: string, fragmentDir: string}  $location  A location returned by locate().
     * @return int|null The total across languages, or null when the entry file
     *                  is missing, unreadable, or does not carry the counts.
     *
     * @since 1.4.0
     *
     * @stability experimental
     */
    public function pageCount(array $location): ?int
    {
        if (! File::exists($location['entryFile'])) {
            return null;
        }

        try {
            $data = json_decode(File::get($location['entryFile']), true);
        } catch (Throwable) {
            return null;
        }

        if (! is_array($data) || ! is_array($data['languages'] ?? null)) {
            return null;
        }

        $total = 0;
        foreach ($data['languages'] as $language) {
            $total += (int) (is_array($language) ? ($language['page_count'] ?? 0) : 0);
        }

        return $total;
    }

    /**
     * Count fragment files by listing the fragment directory.
     *
     * The slow fallback, for an index whose build wrote no entry file.
     *
     * @param  array{indexDir: string, indexFile: string, entryFile: string, fragmentDir: string}  $location  A location returned by locate().
     *
     * @since 1.4.0
     *
     * @stability experimental
     */
    public function countFragments(array $location): int
    {
        return count(File::glob($location['fragmentDir'].'/*') ?: []);
    }

    /**
     * The indexed page count, from the entry file where possible.
     *
     * @param  array{indexDir: string, indexFile: string, entryFile: string, fragmentDir: string}  $location  A location returned by locate().
     *
     * @since 1.4.0
     *
     * @stability experimental
     */
    public function indexedPageCount(array $location): int
    {
        return $this->pageCount($location) ?? $this->countFragments($location);
    }

    /**
     * Path of one fragment file, for a spot-check of index integrity.
     *
     * Reads a single directory entry rather than listing them all.
     *
     * @param  array{indexDir: string, indexFile: string, entryFile: string, fragmentDir: string}  $location  A location returned by locate().
     * @return string|null Null when the fragment directory is absent or empty.
     *
     * @since 1.4.0
     *
     * @stability experimental
     */
    public function firstFragment(array $location): ?string
    {
        if (! File::isDirectory($location['fragmentDir'])) {
            return null;
        }

        foreach (new FilesystemIterator($location['fragmentDir'], FilesystemIterator::SKIP_DOTS) as $file) {
            return $file->getPathname();
        }

        return null;
    }
}
