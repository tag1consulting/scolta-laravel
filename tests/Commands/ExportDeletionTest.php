<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Tests\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;
use Tag1\Scolta\Index\PageTableLedger;
use Tag1\Scolta\Storage\FilesystemDriver;
use Tag1\ScoltaLaravel\Models\ScoltaTracker;
use Tag1\ScoltaLaravel\Observers\ScoltaObserver;
use Tag1\ScoltaLaravel\ScoltaServiceProvider;
use Tag1\ScoltaLaravel\Tests\Support\SluggedArticle;

/**
 * Deleting content must delete its exported HTML, on both export pipelines.
 *
 * The defect: `scolta_tracker` rows hold an Eloquent primary key, while the
 * exported files, the export manifest, the ledger and the index are all keyed
 * by ContentItem::$id — whatever the model's toSearchableContent() invents.
 * `ContentSource::getDeletedIds()` returned the first and both call sites fed
 * it straight to `ContentExporter::deleteById()`, which looks up the second.
 * It matched nothing, returned false, and both `scolta:export` and
 * `scolta:build` discarded the return value while printing a count of rows
 * attempted as if it were a count of files removed. On the binary pipeline the
 * orphaned HTML is then re-indexed by Pagefind, so unpublished and deleted
 * content stays searchable indefinitely.
 *
 * Every assertion here is about a file on disk after a real Artisan command
 * ran. SluggedArticle publishes under "article:{slug}", which shares nothing
 * with its primary key: a model whose item id happened to contain its key
 * would let the defect pass.
 */
class ExportDeletionTest extends TestCase
{
    private string $buildDir;

    private string $stateDir;

    private string $outputDir;

    /** @var array<string, int> */
    private array $articleIds = [];

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

        $this->buildDir = storage_path('framework/testing/scolta-export-deletion');
        $this->stateDir = storage_path('framework/testing/scolta-export-deletion-state');
        $this->outputDir = storage_path('framework/testing/scolta-export-deletion-output');
        File::deleteDirectory($this->buildDir);
        File::deleteDirectory($this->stateDir);
        File::deleteDirectory($this->outputDir);

        config([
            'scolta.pagefind.build_dir' => $this->buildDir,
            'scolta.state_dir' => $this->stateDir,
            'scolta.pagefind.output_dir' => $this->outputDir,
            // The observer would otherwise queue a rebuild off every write.
            'scolta.auto_rebuild' => false,
        ]);

        $this->loadMigrationsFrom(dirname(__DIR__, 2).'/database/migrations');

        // The item_id column is discovered once per process and memoised; the
        // database is rebuilt per test, so the memo has to go with it.
        ScoltaTracker::flushSchemaCache();

        Schema::create('slugged_articles', function (Blueprint $table) {
            $table->id();
            $table->string('slug');
            $table->string('title');
            $table->text('body');
            $table->boolean('published')->default(true);
            $table->timestamps();
        });

        config(['scolta.models' => [SluggedArticle::class]]);

        // Registered by hand: the provider wires observers at boot, and the
        // model config is only set afterwards here. These tests want the real
        // observer, because what it records at delete time is half the fix.
        SluggedArticle::flushEventListeners();
        SluggedArticle::observe(ScoltaObserver::class);

        foreach (['alpha', 'beta'] as $slug) {
            $article = SluggedArticle::create([
                'slug' => $slug,
                'title' => ucfirst($slug),
                'body' => "The body of {$slug}. ".str_repeat('Plenty of searchable body text. ', 10),
                'published' => true,
            ]);
            $this->articleIds[$slug] = (int) $article->getKey();
        }

        ScoltaTracker::clearAll();
    }

    protected function tearDown(): void
    {
        SluggedArticle::flushEventListeners();
        Schema::dropIfExists('slugged_articles');
        File::deleteDirectory($this->buildDir);
        File::deleteDirectory($this->stateDir);
        File::deleteDirectory($this->outputDir);
        File::deleteDirectory(public_path('vendor/scolta'));
        ScoltaTracker::flushSchemaCache();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // What the observer records, which is the only moment the item id of
    // a hard-deleted record is knowable.
    // -----------------------------------------------------------------

    public function test_the_observer_records_the_index_item_id_when_a_record_is_deleted(): void
    {
        SluggedArticle::query()->whereKey($this->articleIds['alpha'])->first()?->delete();

        $row = ScoltaTracker::query()->where('action', 'delete')->firstOrFail();

        $this->assertSame((string) $this->articleIds['alpha'], $row->content_id);
        $this->assertSame('article:alpha', $row->item_id,
            'The item id has to be taken while the record still exists; afterwards nothing can derive it.');
    }

    public function test_an_ordinary_save_does_not_pay_for_an_item_id(): void
    {
        SluggedArticle::query()->whereKey($this->articleIds['alpha'])->first()?->update(['title' => 'Alpha Revised']);

        $row = ScoltaTracker::query()->where('action', 'index')->firstOrFail();

        $this->assertNull($row->item_id,
            'An index row describes a record still in the database; resolving its item id means rendering '
            .'its searchable content, which the build is about to do anyway.');
    }

    // -----------------------------------------------------------------
    // scolta:export
    // -----------------------------------------------------------------

    public function test_incremental_export_deletes_the_file_of_a_hard_deleted_record(): void
    {
        $this->fullExport();
        $this->assertFileExists($this->exportPath('alpha'));

        SluggedArticle::query()->whereKey($this->articleIds['alpha'])->first()?->delete();

        $this->artisan('scolta:export', ['--incremental' => true])
            ->expectsOutputToContain('Removed 1 deleted item(s) from the export.')
            ->assertExitCode(Command::SUCCESS);

        $this->assertFileDoesNotExist($this->exportPath('alpha'),
            'A deleted record must not leave its exported HTML behind for Pagefind to re-index.');
        $this->assertFileExists($this->exportPath('beta'),
            'Deleting one item must not disturb the rest of the export.');
    }

    public function test_incremental_export_deletes_the_file_of_an_unpublished_record(): void
    {
        $this->fullExport();
        $this->assertFileExists($this->exportPath('beta'));

        // The headline symptom. Unpublishing is an update, so the observer
        // sees shouldBeSearchable() turn false and records a delete — with the
        // model still in hand, so with an exact item id. The file has to go, or
        // Pagefind indexes the draft on the next run and it stays searchable.
        SluggedArticle::query()->whereKey($this->articleIds['beta'])->first()?->update(['published' => false]);

        $this->artisan('scolta:export', ['--incremental' => true])
            ->expectsOutputToContain('Removed 1 deleted item(s) from the export.')
            ->assertExitCode(Command::SUCCESS);

        $this->assertFileDoesNotExist($this->exportPath('beta'));
        $this->assertFileExists($this->exportPath('alpha'));
    }

    public function test_a_tracker_row_written_before_the_item_id_migration_still_resolves(): void
    {
        $this->fullExport();

        // No item_id: the shape of every delete row on an install that upgraded
        // the package before running the migration. The record is still
        // readable, so the id can be reconstructed from it.
        ScoltaTracker::query()->delete();
        ScoltaTracker::query()->create([
            'content_id' => (string) $this->articleIds['alpha'],
            'content_type' => SluggedArticle::class,
            'action' => 'delete',
            'changed_at' => now(),
        ]);

        $this->artisan('scolta:export', ['--incremental' => true])
            ->expectsOutputToContain('Removed 1 deleted item(s) from the export.')
            ->assertExitCode(Command::SUCCESS);

        $this->assertFileDoesNotExist($this->exportPath('alpha'));
    }

    public function test_a_deletion_that_cannot_be_resolved_escalates_to_a_full_export(): void
    {
        $this->fullExport();

        // A row with no recorded item id whose record has since left the
        // database: nothing maps it to a file, so the incremental sweep leaves
        // the page on disk for Pagefind to index again. The run falls back to a
        // full export instead, which removes it by not writing it again — the
        // rule scolta-drupal applies to a queue payload that does not name what
        // changed, and the one the PHP indexer path already applies here.
        ScoltaTracker::query()->delete();
        ScoltaTracker::query()->create([
            'content_id' => (string) $this->articleIds['alpha'],
            'content_type' => SluggedArticle::class,
            'action' => 'delete',
            'changed_at' => now(),
        ]);
        SluggedArticle::withoutEvents(function () {
            SluggedArticle::query()->whereKey($this->articleIds['alpha'])->delete();
        });

        $this->artisan('scolta:export', ['--incremental' => true])
            ->expectsOutputToContain('carry no recorded item id')
            ->expectsOutputToContain('Falling back to a full run')
            ->expectsOutputToContain('Marking all published content for export')
            ->assertExitCode(Command::SUCCESS);

        $this->assertFileDoesNotExist($this->exportPath('alpha'),
            'The full export re-creates the directory without the deleted page.');
        $this->assertFileExists($this->exportPath('beta'),
            'and with every page that is still published.');
    }

    public function test_a_deletion_with_no_exported_file_is_reported(): void
    {
        $this->fullExport();

        // Exported, then deleted from disk by something other than this
        // package. deleteById() returns false; that false must reach the
        // console, because the same false is what an unmatched id returns.
        File::delete($this->exportPath('alpha'));
        SluggedArticle::query()->whereKey($this->articleIds['alpha'])->first()?->delete();

        $this->artisan('scolta:export', ['--incremental' => true])
            ->expectsOutputToContain('no exported file to remove')
            ->doesntExpectOutputToContain('Removed 1 deleted item(s)')
            ->assertExitCode(Command::SUCCESS);
    }

    public function test_a_full_export_does_not_report_deletions_it_did_not_need_to_make(): void
    {
        SluggedArticle::query()->whereKey($this->articleIds['alpha'])->first()?->delete();

        // prepareOutputDir() empties the directory first, so a full export
        // removes deleted content by not re-exporting it. Sweeping there would
        // report every pending row as a file it could not find.
        $this->artisan('scolta:export')
            ->doesntExpectOutputToContain('no exported file to remove')
            ->doesntExpectOutputToContain('carry no recorded item id')
            ->assertExitCode(Command::SUCCESS);

        $this->assertFileDoesNotExist($this->exportPath('alpha'));
        $this->assertFileExists($this->exportPath('beta'));
    }

    // -----------------------------------------------------------------
    // scolta:build --indexer=binary, which is always a full run and
    // therefore removes a deleted page by not exporting it again.
    // -----------------------------------------------------------------

    public function test_a_binary_build_removes_a_deleted_record_by_re_exporting_without_it(): void
    {
        $this->artisan('scolta:build', ['--indexer' => 'binary', '--skip-pagefind' => true])
            ->assertExitCode(Command::SUCCESS);
        $this->assertFileExists($this->exportPath('alpha'));

        SluggedArticle::query()->whereKey($this->articleIds['alpha'])->first()?->delete();

        // prepareOutputDir() empties the build directory first, so there is
        // nothing for ExportDeletions to sweep and nothing it could fail to
        // resolve. That is why this command no longer runs the sweep at all —
        // and why an unresolvable row cannot strand a page here.
        $this->artisan('scolta:build', ['--indexer' => 'binary', '--skip-pagefind' => true])
            ->doesntExpectOutputToContain('no exported file to remove')
            ->doesntExpectOutputToContain('carry no recorded item id')
            ->assertExitCode(Command::SUCCESS);

        $this->assertFileDoesNotExist($this->exportPath('alpha'));
        $this->assertFileExists($this->exportPath('beta'));
    }

    public function test_the_export_manifest_survives_an_incremental_run(): void
    {
        $this->fullExport();

        // writeManifest() serialises only what the running process exported.
        // Written after an incremental run it would leave the manifest naming
        // one item, and every other page would become undeletable.
        SluggedArticle::query()->whereKey($this->articleIds['alpha'])->first()?->update(['title' => 'Alpha Revised']);
        $this->artisan('scolta:export', ['--incremental' => true])->assertExitCode(Command::SUCCESS);

        SluggedArticle::query()->whereKey($this->articleIds['beta'])->first()?->delete();
        $this->artisan('scolta:export', ['--incremental' => true])
            ->expectsOutputToContain('Removed 1 deleted item(s) from the export.')
            ->assertExitCode(Command::SUCCESS);

        $this->assertFileDoesNotExist($this->exportPath('beta'));
    }

    // -----------------------------------------------------------------
    // The PHP indexer, which resolves the same mapping for its own ledger
    // -----------------------------------------------------------------

    public function test_a_recorded_item_id_lets_the_queued_update_apply_a_hard_delete(): void
    {
        $this->artisan('scolta:build')->assertExitCode(Command::SUCCESS);
        $this->assertSame(2, $this->ledgerLiveCount());
        ScoltaTracker::clearAll();

        config(['scolta.auto_rebuild' => true, 'queue.default' => 'sync']);

        SluggedArticle::query()->whereKey($this->articleIds['alpha'])->first()?->delete();

        // Without a recorded item id this row is unresolvable and the queued
        // rebuild falls back to a full build — correct, but it rebuilds the
        // corpus to remove one page. The id the observer captured while the
        // record still existed makes the removal exact, and the ordinals of the
        // pages it did not touch survive.
        $this->assertSame(1, $this->ledgerLiveCount());
        $this->assertNotContains('article:alpha', $this->ledgerItemIds());
        $this->assertSame(0, ScoltaTracker::query()->count());
    }

    // -----------------------------------------------------------------
    // Helpers.
    // -----------------------------------------------------------------

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

        return $ids;
    }

    private function fullExport(): void
    {
        $this->artisan('scolta:export')->assertExitCode(Command::SUCCESS);
    }

    private function exportPath(string $slug): string
    {
        return $this->buildDir.'/articles/'.$slug.'/index.html';
    }
}
