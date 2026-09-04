<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Tests;

use Illuminate\Console\Command;
use Orchestra\Testbench\TestCase;
use Tag1\ScoltaLaravel\ScoltaServiceProvider;

/**
 * `scolta:build --reset-ledger` reaches BuildIntentFactory, and a flag pair it
 * refuses exits INVALID instead of throwing.
 *
 * The page-table ledger is what hands each page its ordinal, and a duplicate
 * ordinal in it fails the merge on every subsequent run. --restart now discards
 * it (scolta-php 1.5.0); --reset-ledger is the same escape hatch on a build
 * that is not a restart. BuildIntentFactory::fromFlags() throws LogicException
 * for the combinations that cannot mean anything, and an uncaught one would
 * reach the operator as a stack trace.
 */
class BuildResetLedgerFlagTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [ScoltaServiceProvider::class];
    }

    public function test_reset_ledger_with_resume_is_rejected_as_invalid(): void
    {
        $this->artisan('scolta:build', ['--resume' => true, '--reset-ledger' => true])
            ->expectsOutputToContain('Cannot reset the page-table ledger on a resumed build')
            ->assertExitCode(Command::INVALID);
    }

    public function test_reset_ledger_alone_is_accepted(): void
    {
        // No models are configured in the test app, so the build reaches the
        // empty-corpus warning. Getting there proves the intent was built.
        $this->artisan('scolta:build', ['--reset-ledger' => true])
            ->expectsOutputToContain('No searchable content found.')
            ->assertExitCode(Command::SUCCESS);
    }

    public function test_reset_ledger_with_restart_is_accepted_as_redundant(): void
    {
        // --restart already resets the ledger, so asking for both is a no-op
        // rather than a contradiction.
        $this->artisan('scolta:build', ['--restart' => true, '--reset-ledger' => true])
            ->expectsOutputToContain('No searchable content found.')
            ->assertExitCode(Command::SUCCESS);
    }

    public function test_contradictory_flags_are_rejected_before_the_empty_corpus_exit(): void
    {
        // The rejection must not be reachable only on a site that has content:
        // an operator who mistypes the flags gets INVALID either way, never a
        // success exit that says there was nothing to index.
        $this->artisan('scolta:build', ['--resume' => true, '--reset-ledger' => true])
            ->doesntExpectOutputToContain('No searchable content found.')
            ->assertExitCode(Command::INVALID);
    }

    public function test_help_says_restart_discards_the_page_table_ledger(): void
    {
        // --restart resets the ledger as a side effect (scolta-php 1.5.0). An
        // operator choosing between it and --reset-ledger has only the help
        // text to go on, so the help text has to say so.
        $this->artisan('help', ['command_name' => 'scolta:build'])
            ->expectsOutputToContain('Also discards the page-table ledger')
            ->assertExitCode(Command::SUCCESS);
    }
}
