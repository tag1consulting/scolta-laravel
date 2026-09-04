<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Tests\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tag1\Scolta\Index\IndexBuildOrchestrator;
use Tag1\Scolta\Index\PageTableLedger;
use Tag1\Scolta\Storage\FilesystemDriver;
use Tag1\ScoltaLaravel\Models\ScoltaTracker;
use Tag1\ScoltaLaravel\ScoltaServiceProvider;
use Tag1\ScoltaLaravel\Tests\Support\SearchablePost;

/**
 * `scolta:build --incremental` on the PHP indexer, end to end against a real
 * index on disk.
 *
 * The defect these cover: --incremental was read only inside the binary
 * pipeline. The PHP indexer is what `indexer=auto` resolves to and therefore
 * what every default install runs, and there the flag streamed the whole corpus
 * through the full build and reported success — a rebuild wearing the word
 * "incremental" — while the tracker rows it had just satisfied were never
 * cleared, so `scolta:status` and /api/scolta/v1/health reported a pending_index
 * backlog that no build could drain.
 *
 * Everything here drives the Artisan command against a SQLite site and asserts
 * on the published index and the tracker table, never on the text of the
 * source: an assertion that greps BuildCommand.php for '--incremental' passed
 * throughout the entire life of the defect.
 */
class IncrementalPhpIndexerTest extends TestCase
{
    private string $stateDir;

    private string $outputDir;

    /** @var array<string, int> */
    private array $postIds = [];

    protected function getPackageProviders($app): array
    {
        return [ScoltaServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->stateDir = storage_path('framework/testing/scolta-incremental-state');
        $this->outputDir = storage_path('framework/testing/scolta-incremental-output');
        File::deleteDirectory($this->stateDir);
        File::deleteDirectory($this->outputDir);

        config([
            'scolta.state_dir' => $this->stateDir,
            'scolta.pagefind.output_dir' => $this->outputDir,
        ]);

        $this->loadMigrationsFrom(dirname(__DIR__, 2).'/database/migrations');

        Schema::create('searchable_posts', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('body');
            $table->boolean('published')->default(true);
            $table->boolean('unlisted')->default(false);
            $table->timestamps();
        });

        // Set after boot so the observer is not registered: every test here
        // writes the tracker rows it wants explicitly, so the change set under
        // test is the one the test describes.
        config(['scolta.models' => [SearchablePost::class]]);

        foreach (['Alpha', 'Beta', 'Gamma'] as $title) {
            $post = SearchablePost::create([
                'title' => $title,
                'body' => "Body of {$title}. ".str_repeat('Plenty of searchable body text. ', 20),
                'published' => true,
                'unlisted' => false,
            ]);
            $this->postIds[$title] = (int) $post->getKey();
        }
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('searchable_posts');
        File::deleteDirectory($this->stateDir);
        File::deleteDirectory($this->outputDir);
        File::deleteDirectory(public_path('vendor/scolta'));

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // The tracker, which is a smaller question than --incremental and was
    // broken on every default install regardless of the flag.
    // -----------------------------------------------------------------

    public function test_a_plain_php_build_clears_the_tracker(): void
    {
        $this->track('Alpha', 'index');
        $this->assertSame(1, ScoltaTracker::query()->count());

        $this->artisan('scolta:build')
            ->expectsOutputToContain('Index built: 3 pages')
            ->assertExitCode(Command::SUCCESS);

        $this->assertSame(0, ScoltaTracker::query()->count(),
            'A full PHP build covers every tracked change, so it must leave no pending rows behind.');
    }

    public function test_a_failed_php_build_leaves_the_tracker_alone(): void
    {
        // No models configured: the build exits on the empty-corpus warning
        // without publishing anything, so the pending row is still pending.
        config(['scolta.models' => []]);
        $this->track('Alpha', 'index');

        $this->artisan('scolta:build')
            ->expectsOutputToContain('No searchable content found.')
            ->assertExitCode(Command::SUCCESS);

        $this->assertSame(1, ScoltaTracker::query()->count(),
            'A build that indexed nothing has satisfied nothing; the tracker must survive it.');
    }

    // -----------------------------------------------------------------
    // --incremental proper.
    // -----------------------------------------------------------------

    public function test_incremental_updates_the_published_index_without_dropping_the_rest_of_it(): void
    {
        $this->artisan('scolta:build')->assertExitCode(Command::SUCCESS);
        $this->assertSame(3, $this->ledgerLiveCount());
        $betaItemId = 'searchable_posts-'.$this->postIds['Beta'];
        $hashBefore = $this->ledgerContentHash($betaItemId);

        SearchablePost::query()
            ->whereKey($this->postIds['Beta'])
            ->update(['title' => 'Beta Revised']);
        $this->track('Beta', 'index');

        $this->artisan('scolta:build', ['--incremental' => true])
            ->expectsOutputToContain('Index updated incrementally: 1 page(s) updated, 0 deleted')
            ->doesntExpectOutputToContain('Index built:')
            ->assertExitCode(Command::SUCCESS);

        // The partial-scope trap: handing a filtered stream to the full build
        // path publishes an index holding only the changed page, because the
        // merge reads "never yielded" as "no longer in the source". The other
        // two pages must still be in the page table.
        $this->assertSame(3, $this->ledgerLiveCount(),
            'An incremental update of one page must not evict the pages it did not touch.');
        $this->assertSame(
            ['searchable_posts-'.$this->postIds['Alpha'], 'searchable_posts-'.$this->postIds['Beta'], 'searchable_posts-'.$this->postIds['Gamma']],
            $this->ledgerItemIds(),
        );
        $this->assertSame(0, ScoltaTracker::query()->count());

        $this->assertNotSame($hashBefore, $this->ledgerContentHash($betaItemId),
            'The edited page must actually have been rewritten, not merely reported as rewritten.');

        // The published index has to stay loadable: an update that rewrites
        // chunks and fragments in place can leave a dangling reference the full
        // build never could, because there is no directory swap to sweep it up.
        try {
            IndexBuildOrchestrator::verifyIndexComplete($this->outputDir);
        } catch (\Throwable $e) {
            $this->fail('The index must still verify after an incremental update: '.$e->getMessage());
        }
    }

    public function test_incremental_removes_a_record_the_searchable_scope_now_rejects(): void
    {
        $this->artisan('scolta:build')->assertExitCode(Command::SUCCESS);

        // scopeSearchable() filters on `published`; shouldBeSearchable() filters
        // on `unlisted`. The observer only consults the second, so unpublishing
        // arrives as an 'index' row and the change set has to notice that the
        // scope no longer keeps the record. Missing this leaves a draft in the
        // public index, or worse re-indexes it.
        SearchablePost::query()
            ->whereKey($this->postIds['Gamma'])
            ->update(['published' => false]);
        $this->track('Gamma', 'index');

        $this->artisan('scolta:build', ['--incremental' => true])
            ->expectsOutputToContain('Index updated incrementally: 0 page(s) updated, 1 deleted')
            ->assertExitCode(Command::SUCCESS);

        $this->assertSame(2, $this->ledgerLiveCount());
        $this->assertNotContains('searchable_posts-'.$this->postIds['Gamma'], $this->ledgerItemIds(),
            'An unpublished record must leave the index, not stay in it.');
    }

    public function test_incremental_reports_an_up_to_date_index_when_nothing_is_pending(): void
    {
        $this->artisan('scolta:build')->assertExitCode(Command::SUCCESS);

        $this->artisan('scolta:build', ['--incremental' => true])
            ->expectsOutputToContain('No changes pending. Index is up to date.')
            ->doesntExpectOutputToContain('Index built:')
            ->assertExitCode(Command::SUCCESS);
    }

    // -----------------------------------------------------------------
    // The refusals. Each one ends in a full build rather than in a lie.
    // -----------------------------------------------------------------

    public function test_incremental_without_a_published_index_falls_back_to_a_full_build(): void
    {
        $this->track('Alpha', 'index');

        $this->artisan('scolta:build', ['--incremental' => true])
            ->expectsOutputToContain('No page-table ledger for the existing index')
            ->expectsOutputToContain('Index built: 3 pages')
            ->assertExitCode(Command::SUCCESS);

        $this->assertSame(3, $this->ledgerLiveCount());
        $this->assertSame(0, ScoltaTracker::query()->count());
    }

    public function test_a_hard_deleted_record_falls_back_to_a_full_build(): void
    {
        $this->artisan('scolta:build')->assertExitCode(Command::SUCCESS);

        // The tracker holds an Eloquent key; the index is keyed by the id
        // toSearchableContent() invented. With the record gone nothing maps
        // between them, and IncrementalIndexUpdater::stageDelete() answers an
        // id it does not hold by doing nothing at all — so guessing here would
        // report a deletion that never happened.
        $gammaId = $this->postIds['Gamma'];
        $this->track('Gamma', 'delete');
        SearchablePost::query()->whereKey($gammaId)->delete();

        $this->artisan('scolta:build', ['--incremental' => true])
            ->expectsOutputToContain('no longer readable')
            ->expectsOutputToContain('Index built: 2 pages')
            ->assertExitCode(Command::SUCCESS);

        $this->assertSame(2, $this->ledgerLiveCount());
        $this->assertNotContains('searchable_posts-'.$gammaId, $this->ledgerItemIds(),
            'The full-build fallback is what actually removes the deleted page.');
        $this->assertSame(0, ScoltaTracker::query()->count());
    }

    public function test_a_change_set_over_the_threshold_falls_back_to_a_full_build(): void
    {
        $this->artisan('scolta:build')->assertExitCode(Command::SUCCESS);

        config(['scolta.incremental.max_changed_items' => 2]);
        foreach (['Alpha', 'Beta', 'Gamma'] as $title) {
            $this->track($title, 'index');
        }

        $this->artisan('scolta:build', ['--incremental' => true])
            ->expectsOutputToContain('Change set of 3 items exceeds the incremental threshold of 2')
            ->expectsOutputToContain('Index built: 3 pages')
            ->assertExitCode(Command::SUCCESS);

        $this->assertSame(0, ScoltaTracker::query()->count());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function conflictingFlags(): array
    {
        return [
            '--force' => ['force'],
            '--resume' => ['resume'],
            '--restart' => ['restart'],
            '--reset-ledger' => ['reset-ledger'],
            '--queue' => ['queue'],
        ];
    }

    #[DataProvider('conflictingFlags')]
    public function test_incremental_refuses_the_flags_that_mean_full_build(string $flag): void
    {
        $this->track('Alpha', 'index');

        $this->artisan('scolta:build', ['--incremental' => true, '--'.$flag => true])
            ->expectsOutputToContain('--incremental cannot be combined with --'.$flag)
            ->doesntExpectOutputToContain('Index built:')
            ->assertExitCode(Command::INVALID);

        $this->assertSame(1, ScoltaTracker::query()->count(),
            'A rejected invocation must not clear the tracker.');
    }

    // -----------------------------------------------------------------
    // Helpers.
    // -----------------------------------------------------------------

    private function track(string $title, string $action): void
    {
        ScoltaTracker::track((string) $this->postIds[$title], SearchablePost::class, $action);
    }

    private function ledgerContentHash(string $itemId): string
    {
        return (new PageTableLedger($this->stateDir, new FilesystemDriver))->contentHashFor($itemId);
    }

    private function ledgerLiveCount(): int
    {
        return (new PageTableLedger($this->stateDir, new FilesystemDriver))->liveCount();
    }

    /**
     * @return list<string>
     */
    private function ledgerItemIds(): array
    {
        $ids = [];
        foreach ((new PageTableLedger($this->stateDir, new FilesystemDriver))->rowsByOrdinal() as $row) {
            $ids[] = (string) $row['id'];
        }
        sort($ids);

        return $ids;
    }

    public function test_incremental_without_the_tracker_table_says_so_rather_than_up_to_date(): void
    {
        Schema::dropIfExists('scolta_tracker');

        $this->artisan('scolta:build', ['--incremental' => true])
            ->expectsOutputToContain('Change tracking is unavailable')
            ->assertExitCode(Command::FAILURE);
    }
}
