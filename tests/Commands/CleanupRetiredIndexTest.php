<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Tests\Commands;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Mockery;
use Orchestra\Testbench\TestCase;
use Tag1\Scolta\Index\RetiredIndexTrash;
use Tag1\ScoltaLaravel\ScoltaServiceProvider;

/**
 * `scolta:cleanup` against the retired-index trash left by a build.
 *
 * What is pinned here: trash is deleted, a `.scolta-old` corpse from an
 * interrupted swap is retired and deleted with it, `--dry-run` changes nothing
 * at all (not even the rename), the live index and the two in-flight staging
 * directories are never touched, and a misconfigured output directory is
 * logged rather than silently ignored — the command is meant to be scheduled,
 * and a scheduled task's stdout goes nowhere.
 */
class CleanupRetiredIndexTest extends TestCase
{
    private string $stateDir;

    private string $outputDir;

    protected function getPackageProviders($app): array
    {
        return [ScoltaServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->stateDir = storage_path('framework/testing/scolta-cleanup-state');
        $this->outputDir = storage_path('framework/testing/scolta-cleanup-output');
        File::deleteDirectory($this->stateDir);
        File::deleteDirectory($this->outputDir);
        File::makeDirectory($this->outputDir, 0755, true);

        config([
            'scolta.state_dir' => $this->stateDir,
            'scolta.pagefind.output_dir' => $this->outputDir,
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->stateDir);
        File::deleteDirectory($this->outputDir);

        parent::tearDown();
    }

    /**
     * Create a directory holding one file, so deletion has to recurse.
     */
    private function makeTree(string $path): void
    {
        File::makeDirectory($path.'/fragment', 0755, true);
        File::put($path.'/fragment/en_abc.pf_fragment', 'x');
    }

    private function trashPath(string $suffix): string
    {
        return $this->outputDir.'/'.RetiredIndexTrash::PREFIX.$suffix;
    }

    public function test_it_deletes_retired_index_directories(): void
    {
        $this->makeTree($this->trashPath('one'));
        $this->makeTree($this->trashPath('two'));

        $this->artisan('scolta:cleanup')
            ->assertExitCode(0)
            ->run();

        $this->assertDirectoryDoesNotExist($this->trashPath('one'));
        $this->assertDirectoryDoesNotExist($this->trashPath('two'));
    }

    public function test_it_retires_and_deletes_a_stale_scolta_old_directory(): void
    {
        $this->makeTree($this->outputDir.'/.scolta-old');

        $this->artisan('scolta:cleanup')
            ->assertExitCode(0)
            ->run();

        $this->assertDirectoryDoesNotExist($this->outputDir.'/.scolta-old');
        $this->assertSame([], glob($this->trashPath('*')) ?: []);
    }

    public function test_dry_run_deletes_nothing_and_renames_nothing(): void
    {
        $this->makeTree($this->trashPath('one'));
        $this->makeTree($this->outputDir.'/.scolta-old');

        $this->artisan('scolta:cleanup', ['--dry-run' => true])
            ->assertExitCode(0)
            ->run();

        $this->assertFileExists($this->trashPath('one').'/fragment/en_abc.pf_fragment');
        // Still .scolta-old: a dry run must not even perform the rename that
        // hands the directory to the sweep.
        $this->assertDirectoryExists($this->outputDir.'/.scolta-old');
        $this->assertCount(1, glob($this->trashPath('*')) ?: []);
    }

    public function test_it_leaves_the_live_index_and_in_flight_staging_alone(): void
    {
        $this->makeTree($this->outputDir.'/pagefind');
        File::put($this->outputDir.'/pagefind/pagefind.js', 'live');
        // A build may be using these right now.
        $this->makeTree($this->outputDir.'/.scolta-new');
        $this->makeTree($this->outputDir.'/.scolta-building');
        $this->makeTree($this->trashPath('one'));

        $this->artisan('scolta:cleanup')
            ->assertExitCode(0)
            ->run();

        $this->assertFileExists($this->outputDir.'/pagefind/pagefind.js');
        $this->assertFileExists($this->outputDir.'/pagefind/fragment/en_abc.pf_fragment');
        $this->assertDirectoryExists($this->outputDir.'/.scolta-new');
        $this->assertDirectoryExists($this->outputDir.'/.scolta-building');
        $this->assertDirectoryDoesNotExist($this->trashPath('one'));
    }

    /**
     * Trash sits one level above a configured value that already ends in
     * `/pagefind`; cleanup must mirror that or it sweeps the wrong place.
     */
    public function test_it_normalizes_an_output_dir_that_already_ends_in_pagefind(): void
    {
        config(['scolta.pagefind.output_dir' => $this->outputDir.'/pagefind']);
        $this->makeTree($this->trashPath('one'));

        $this->artisan('scolta:cleanup')
            ->assertExitCode(0)
            ->run();

        $this->assertDirectoryDoesNotExist($this->trashPath('one'));
    }

    public function test_an_unresolvable_output_dir_is_logged_and_fails(): void
    {
        $log = Log::spy();
        config(['scolta.pagefind.output_dir' => '']);

        $this->artisan('scolta:cleanup')
            ->assertExitCode(1)
            ->run();

        $log->shouldHaveReceived('warning', [
            Mockery::on(fn (mixed $message): bool => is_string($message) && str_contains($message, 'pagefind.output_dir')),
        ]);
    }

    /**
     * Not an error, but it has to say so somewhere that outlives the
     * discarded stdout of a scheduled run.
     */
    public function test_a_missing_output_dir_is_logged_rather_than_silently_ignored(): void
    {
        $log = Log::spy();
        config(['scolta.pagefind.output_dir' => $this->outputDir.'/does-not-exist']);

        $this->artisan('scolta:cleanup')
            ->assertExitCode(0)
            ->run();

        $log->shouldHaveReceived('info', [
            Mockery::on(fn (mixed $message): bool => is_string($message) && str_contains($message, 'no Pagefind output directory')),
            Mockery::type('array'),
        ]);
    }

    public function test_a_non_numeric_max_seconds_is_rejected_before_anything_is_deleted(): void
    {
        $this->makeTree($this->trashPath('one'));

        $this->artisan('scolta:cleanup', ['--max-seconds' => 'soon'])
            ->assertExitCode(2)
            ->run();

        $this->assertDirectoryExists($this->trashPath('one'));
    }

    public function test_a_zero_budget_means_no_limit_and_still_sweeps(): void
    {
        $this->makeTree($this->trashPath('one'));

        $this->artisan('scolta:cleanup', ['--max-seconds' => '0'])
            ->assertExitCode(0)
            ->run();

        $this->assertDirectoryDoesNotExist($this->trashPath('one'));
    }

    /**
     * The build lock survives a full run however old it looks.
     *
     * BuildState owns that file and keeps it at a stable path on purpose: it
     * is flock()ed, so unlinking it hands the same path to a second holder.
     * The mtime is not evidence of staleness either — the heartbeat is written
     * per committed chunk, so a long merge leaves an hours-old file behind a
     * running build. An age well past the hour this command used to delete at
     * is the case that has to stay untouched.
     *
     * The summary count has to stay truthful with it: nothing was removed, so
     * the command must say nothing was removed, and the dry run must agree.
     */
    public function test_it_leaves_the_build_lock_alone(): void
    {
        File::makeDirectory($this->stateDir, 0755, true);
        File::put($this->stateDir.'/lock', '');
        touch($this->stateDir.'/lock', time() - 7200);

        $this->artisan('scolta:cleanup', ['--dry-run' => true])
            ->assertExitCode(0)
            ->expectsOutputToContain('Dry run: would remove 0 stale file(s).')
            ->doesntExpectOutputToContain('lock')
            ->run();

        $this->assertFileExists($this->stateDir.'/lock');

        $this->artisan('scolta:cleanup')
            ->assertExitCode(0)
            ->expectsOutputToContain('Cleaned 0 stale file(s).')
            ->doesntExpectOutputToContain('lock')
            ->run();

        $this->assertFileExists($this->stateDir.'/lock');
    }

    /**
     * The control for the --retired-only test below.
     *
     * That test proves the fragment pass is skipped by showing an orphaned
     * fragment survives, which means nothing unless a full run would have
     * removed it. A fragment is orphaned when the pagefind entry file is
     * missing beside it — a partially built index.
     */
    public function test_a_full_run_removes_an_orphaned_fragment_file(): void
    {
        File::makeDirectory($this->outputDir.'/pagefind/fragment', 0755, true);
        File::put($this->outputDir.'/pagefind/fragment/en_abc.pf_fragment', 'x');

        $this->artisan('scolta:cleanup')
            ->assertExitCode(0)
            ->expectsOutputToContain('Cleaned 1 stale file(s).')
            ->run();

        $this->assertFileDoesNotExist($this->outputDir.'/pagefind/fragment/en_abc.pf_fragment');
    }

    /**
     * --retired-only is how the scheduled sweep runs: trash goes, the
     * orphaned-fragment pass does not run. The fragment is the discriminator
     * — a full run removes it, as the test above pins — and the lock file
     * survives here for the same reason it survives everywhere else.
     */
    public function test_retired_only_sweeps_trash_without_the_fragment_pass(): void
    {
        File::makeDirectory($this->stateDir, 0755, true);
        File::put($this->stateDir.'/lock', '');
        touch($this->stateDir.'/lock', time() - 7200);
        File::makeDirectory($this->outputDir.'/pagefind/fragment', 0755, true);
        File::put($this->outputDir.'/pagefind/fragment/en_abc.pf_fragment', 'x');
        $this->makeTree($this->trashPath('one'));

        $this->artisan('scolta:cleanup', ['--retired-only' => true])
            ->assertExitCode(0)
            ->run();

        $this->assertDirectoryDoesNotExist($this->trashPath('one'));
        $this->assertFileExists($this->stateDir.'/lock');
        $this->assertFileExists($this->outputDir.'/pagefind/fragment/en_abc.pf_fragment');
    }
}
