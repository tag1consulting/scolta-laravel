<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Tests;

use Illuminate\Support\Facades\File;
use Orchestra\Testbench\TestCase;
use Tag1\ScoltaLaravel\ScoltaServiceProvider;
use Tag1\ScoltaLaravel\Services\IndexLocator;

/**
 * The one place that finds a built index and counts what is in it.
 *
 * Pins both layouts — the PHP indexer's nested `{output_dir}/pagefind/` and the
 * binary/Cloud pipeline's flat `{output_dir}/`, nested winning when both exist
 * — and that the page count comes from `pagefind-entry.json`, not a listing.
 */
class IndexLocatorTest extends TestCase
{
    private string $dir;

    protected function getPackageProviders($app): array
    {
        return [ScoltaServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = storage_path('framework/testing/scolta-locator');
        File::deleteDirectory($this->dir);
        File::ensureDirectoryExists($this->dir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);

        parent::tearDown();
    }

    public function test_locate_returns_null_when_no_index_is_built(): void
    {
        $this->assertNull((new IndexLocator)->locate($this->dir));
    }

    public function test_locate_finds_the_nested_php_indexer_layout(): void
    {
        File::ensureDirectoryExists($this->dir.'/pagefind');
        File::put($this->dir.'/pagefind/pagefind.js', '// pagefind');

        $location = (new IndexLocator)->locate($this->dir);

        $this->assertNotNull($location);
        $this->assertSame($this->dir.'/pagefind', $location['indexDir']);
        $this->assertSame($this->dir.'/pagefind/pagefind-entry.json', $location['entryFile']);
    }

    public function test_locate_finds_the_flat_binary_layout(): void
    {
        File::put($this->dir.'/pagefind.js', '// pagefind');

        $location = (new IndexLocator)->locate($this->dir);

        $this->assertNotNull($location);
        $this->assertSame($this->dir, $location['indexDir'],
            'A flat index — what the binary pipeline and the Cloud flatten step write — must be found.');
    }

    public function test_locate_prefers_the_nested_layout(): void
    {
        File::ensureDirectoryExists($this->dir.'/pagefind');
        File::put($this->dir.'/pagefind/pagefind.js', '// nested');
        File::put($this->dir.'/pagefind.js', '// flat');

        $location = (new IndexLocator)->locate($this->dir);

        $this->assertNotNull($location);
        $this->assertSame($this->dir.'/pagefind', $location['indexDir']);
    }

    public function test_page_count_sums_across_languages(): void
    {
        File::ensureDirectoryExists($this->dir.'/pagefind');
        File::put($this->dir.'/pagefind/pagefind.js', '// pagefind');
        File::put($this->dir.'/pagefind/pagefind-entry.json', (string) json_encode([
            'languages' => [
                'en' => ['page_count' => 40],
                'de' => ['page_count' => 2],
            ],
        ]));

        $locator = new IndexLocator;
        $location = $locator->locate($this->dir);

        $this->assertNotNull($location);
        $this->assertSame(42, $locator->pageCount($location));
    }

    public function test_page_count_does_not_read_the_fragment_directory(): void
    {
        File::ensureDirectoryExists($this->dir.'/pagefind/fragment');
        File::put($this->dir.'/pagefind/pagefind.js', '// pagefind');
        File::put($this->dir.'/pagefind/fragment/en_a.pf_fragment', 'data');
        File::put($this->dir.'/pagefind/pagefind-entry.json', (string) json_encode([
            'languages' => ['en' => ['page_count' => 96000]],
        ]));

        $locator = new IndexLocator;
        $location = $locator->locate($this->dir);

        $this->assertNotNull($location);
        $this->assertSame(96000, $locator->indexedPageCount($location),
            'The count must come from pagefind-entry.json, not from the one fragment file on disk.');
    }

    public function test_page_count_is_null_without_a_readable_entry_file(): void
    {
        File::ensureDirectoryExists($this->dir.'/pagefind');
        File::put($this->dir.'/pagefind/pagefind.js', '// pagefind');

        $locator = new IndexLocator;
        $location = $locator->locate($this->dir);

        $this->assertNotNull($location);
        $this->assertNull($locator->pageCount($location));

        File::put($this->dir.'/pagefind/pagefind-entry.json', 'not json at all');
        $this->assertNull($locator->pageCount($location));
    }

    public function test_indexed_page_count_falls_back_to_the_fragment_listing(): void
    {
        // A build that never wrote an entry file still has to report a number.
        File::ensureDirectoryExists($this->dir.'/pagefind/fragment');
        File::put($this->dir.'/pagefind/pagefind.js', '// pagefind');
        foreach (['a', 'b', 'c'] as $name) {
            File::put($this->dir."/pagefind/fragment/en_{$name}.pf_fragment", 'data');
        }

        $locator = new IndexLocator;
        $location = $locator->locate($this->dir);

        $this->assertNotNull($location);
        $this->assertSame(3, $locator->indexedPageCount($location));
    }

    public function test_first_fragment_returns_one_path_or_null(): void
    {
        File::ensureDirectoryExists($this->dir.'/pagefind');
        File::put($this->dir.'/pagefind/pagefind.js', '// pagefind');

        $locator = new IndexLocator;
        $location = $locator->locate($this->dir);

        $this->assertNotNull($location);
        $this->assertNull($locator->firstFragment($location),
            'No fragment directory means no fragment to spot-check.');

        File::ensureDirectoryExists($this->dir.'/pagefind/fragment');
        File::put($this->dir.'/pagefind/fragment/en_a.pf_fragment', 'data');

        $this->assertSame(
            $this->dir.'/pagefind/fragment/en_a.pf_fragment',
            $locator->firstFragment($location)
        );
    }
}
