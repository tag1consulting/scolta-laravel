<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Tests\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tag1\Scolta\Index\PageTableLedger;
use Tag1\Scolta\Storage\FilesystemDriver;
use Tag1\ScoltaLaravel\Models\ScoltaTracker;
use Tag1\ScoltaLaravel\ScoltaServiceProvider;
use Tag1\ScoltaLaravel\Tests\Support\SearchablePost;

/**
 * `scolta:build` is always a full build, and `--incremental` is a no-op.
 *
 * The option is retired rather than removed, on the precedent `--sync` already
 * set in this command's own signature: it is a documented public CLI option and
 * a deploy script may still pass it, so it warns and proceeds instead of exiting
 * "unknown option". Retired rather than kept because the capability moved rather
 * than disappearing — a content save now queues an incremental update on its own
 * (see tests/Jobs/QueuedIncrementalRebuildTest.php) — and because the command
 * now matches `drush scolta:build`, which has never had the flag.
 *
 * Also covers the tracker drain on the synchronous PHP build, which was broken
 * on every default install regardless of any flag.
 */
class BuildIsAlwaysFullTest extends TestCase
{
    private string $stateDir;

    private string $outputDir;

    private string $buildDir;

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

        $this->stateDir = storage_path('framework/testing/scolta-full-build-state');
        $this->outputDir = storage_path('framework/testing/scolta-full-build-output');
        $this->buildDir = storage_path('framework/testing/scolta-full-build-html');
        File::deleteDirectory($this->stateDir);
        File::deleteDirectory($this->outputDir);
        File::deleteDirectory($this->buildDir);

        config([
            'scolta.state_dir' => $this->stateDir,
            'scolta.pagefind.output_dir' => $this->outputDir,
            'scolta.pagefind.build_dir' => $this->buildDir,
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
        File::deleteDirectory($this->buildDir);
        File::deleteDirectory(public_path('vendor/scolta'));

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // The tracker drain on the synchronous build.
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
    // The retired flag.
    // -----------------------------------------------------------------

    public function test_the_deprecated_flag_warns_and_runs_a_full_build(): void
    {
        $this->artisan('scolta:build')->assertExitCode(Command::SUCCESS);

        SearchablePost::query()
            ->whereKey($this->postIds['Beta'])
            ->update(['title' => 'Beta Revised']);
        $this->track('Beta', 'index');

        $this->artisan('scolta:build', ['--incremental' => true])
            ->expectsOutputToContain('--incremental is deprecated and does nothing')
            ->expectsOutputToContain('Index built: 3 pages')
            ->doesntExpectOutputToContain('Index updated incrementally')
            ->assertExitCode(Command::SUCCESS);

        $this->assertSame(3, $this->ledgerLiveCount());
        $this->assertSame(0, ScoltaTracker::query()->count());
    }

    public function test_the_deprecated_flag_produces_the_same_index_as_omitting_it(): void
    {
        $this->artisan('scolta:build', ['--incremental' => true])->assertExitCode(Command::SUCCESS);
        $withFlag = $this->ledgerItemIds();

        File::deleteDirectory($this->stateDir);
        File::deleteDirectory($this->outputDir);

        $this->artisan('scolta:build')->assertExitCode(Command::SUCCESS);

        $this->assertSame($withFlag, $this->ledgerItemIds());
        $this->assertCount(3, $withFlag);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function fullBuildFlags(): array
    {
        return [
            '--force' => ['force'],
            '--restart' => ['restart'],
            '--reset-ledger' => ['reset-ledger'],
        ];
    }

    /**
     * The flags `--incremental` used to be refused alongside. They all asked for
     * a full build, which is now the only thing this command does, so there is
     * nothing left to refuse and a deploy script passing the pair must not start
     * exiting 2.
     */
    #[DataProvider('fullBuildFlags')]
    public function test_the_deprecated_flag_no_longer_conflicts_with_the_full_build_flags(string $flag): void
    {
        $this->track('Alpha', 'index');

        $this->artisan('scolta:build', ['--incremental' => true, '--'.$flag => true])
            ->expectsOutputToContain('--incremental is deprecated and does nothing')
            ->expectsOutputToContain('Index built: 3 pages')
            ->assertExitCode(Command::SUCCESS);

        $this->assertSame(3, $this->ledgerLiveCount());
    }

    public function test_the_deprecated_flag_runs_a_full_export_on_the_binary_indexer(): void
    {
        $this->artisan('scolta:build', [
            '--indexer' => 'binary',
            '--skip-pagefind' => true,
            '--incremental' => true,
        ])
            ->expectsOutputToContain('--incremental is deprecated and does nothing')
            ->expectsOutputToContain('Marking all published content for reindex')
            ->assertExitCode(Command::SUCCESS);

        $this->assertCount(3, File::allFiles($this->buildDir));
    }

    public function test_the_index_is_unchanged_without_the_flag_on_the_tracker_table_being_absent(): void
    {
        Schema::dropIfExists('scolta_tracker');

        // A full build derives everything it needs from the corpus, so it has no
        // opinion about change tracking and must not start refusing to run
        // because the migration has not been applied.
        $this->artisan('scolta:build')
            ->expectsOutputToContain('Index built: 3 pages')
            ->assertExitCode(Command::SUCCESS);

        $this->assertSame(3, $this->ledgerLiveCount());
    }

    // -----------------------------------------------------------------
    // Helpers.
    // -----------------------------------------------------------------

    private function track(string $title, string $action): void
    {
        ScoltaTracker::track((string) $this->postIds[$title], SearchablePost::class, $action);
    }

    private function ledger(): PageTableLedger
    {
        return new PageTableLedger($this->stateDir, new FilesystemDriver);
    }

    private function ledgerLiveCount(): int
    {
        return $this->ledger()->liveCount();
    }

    /**
     * @return list<string>
     */
    private function ledgerItemIds(): array
    {
        $ids = [];
        foreach ($this->ledger()->rowsByOrdinal() as $row) {
            $ids[] = (string) $row['id'];
        }
        sort($ids);

        return $ids;
    }
}
