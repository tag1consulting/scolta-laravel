<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Commands;

use Illuminate\Console\Command;
use Tag1\Scolta\Index\MemoryBudgetSuggestion;

/**
 * Interactive Artisan command for viewing and adjusting the memory budget.
 *
 * Usage:
 *   artisan scolta:memory-budget          — show current setting and suggestion
 *   artisan scolta:memory-budget --set=balanced  — write to .env / display instruction
 */
class MemoryBudgetCommand extends Command
{
    protected $signature = 'scolta:memory-budget
        {--set= : Set the memory budget profile (conservative, balanced, aggressive)}';

    protected $description = 'Show or set the Scolta PHP indexer memory budget profile';

    public function handle(): int
    {
        $hint = MemoryBudgetSuggestion::suggest();
        $current = config('scolta.memory_budget.profile', 'conservative');

        if ($set = $this->option('set')) {
            $valid = ['conservative', 'balanced', 'aggressive'];
            if (! in_array($set, $valid, true)) {
                $this->error(sprintf('Invalid profile "%s". Must be one of: %s.', $set, implode(', ', $valid)));

                return self::FAILURE;
            }
            $this->info("Set SCOLTA_MEMORY_BUDGET={$set} in your .env file.");
            $this->line('Then run <comment>php artisan config:clear</comment> to apply the change.');

            return self::SUCCESS;
        }

        $this->table(
            ['Setting', 'Value'],
            [
                ['Current profile', $current],
                ['Suggested profile', $hint['profile']],
                ['Reason', wordwrap($hint['reason'], 60)],
                ['Detected memory_limit', $hint['detected_limit_bytes'] !== null
                    ? round($hint['detected_limit_bytes'] / 1_048_576).' MB'
                    : 'unlimited or unknown'],
                ['Confidence', $hint['confidence']],
            ]
        );

        $this->line('');
        $this->line('Profiles:');
        $this->line('  <info>conservative</info> — peak ≤ 96 MB (chunk: 50 pages, default)');
        $this->line('  <info>balanced</info>     — peak ~384 MB (chunk: 200 pages)');
        $this->line('  <info>aggressive</info>   — peak ~1 GB (chunk: 500 pages)');
        $this->line('');
        $this->line('To change: <comment>artisan scolta:memory-budget --set=balanced</comment>');

        return self::SUCCESS;
    }
}
