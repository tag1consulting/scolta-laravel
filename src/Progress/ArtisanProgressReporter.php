<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Progress;

use Illuminate\Console\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Tag1\Scolta\Index\ProgressReporterInterface;

/**
 * Routes IndexBuildOrchestrator progress callbacks to an Artisan progress bar.
 */
final class ArtisanProgressReporter implements ProgressReporterInterface
{
    private ?ProgressBar $bar = null;

    public function __construct(private readonly Command $command) {}

    public function start(int $totalSteps, string $label): void
    {
        $this->command->info($label.'...');
        $this->bar = $this->command->getOutput()->createProgressBar($totalSteps);
        $this->bar->start();
    }

    public function advance(int $steps = 1, ?string $detail = null): void
    {
        if ($detail !== null && $this->bar !== null) {
            $this->bar->setMessage($detail);
        }
        $this->bar?->advance($steps);
    }

    public function finish(?string $summary = null): void
    {
        $this->bar?->finish();
        $this->command->newLine();
        if ($summary !== null) {
            $this->command->info('  '.$summary);
        }
        $this->bar = null;
    }
}
