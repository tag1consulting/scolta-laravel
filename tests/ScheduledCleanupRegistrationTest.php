<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Tests;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use Orchestra\Testbench\TestCase;
use Tag1\ScoltaLaravel\ScoltaServiceProvider;

/**
 * The retired-index sweep is on the application's scheduler by default.
 *
 * The Drupal adapter sweeps from `hook_cron()`, so a Drupal site gets the
 * backstop for free; this pins the Laravel equivalent so the two adapters do
 * not drift back apart. `scolta.cleanup.cron_seconds` is the same switch by
 * the same name: 0 disables it, and any other value becomes the run's
 * wall-clock budget.
 */
class ScheduledCleanupRegistrationTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [ScoltaServiceProvider::class];
    }

    protected function disabled(Application $app): void
    {
        $app['config']->set('scolta.cleanup.cron_seconds', 0);
    }

    private function cleanupEntry(): ?string
    {
        foreach ($this->app->make(Schedule::class)->events() as $event) {
            $command = (string) ($event->command ?? '');
            if (str_contains($command, 'scolta:cleanup')) {
                return $command;
            }
        }

        return null;
    }

    public function test_the_sweep_is_scheduled_by_default(): void
    {
        $entry = $this->cleanupEntry();

        $this->assertNotNull($entry, 'scolta:cleanup is not on the schedule.');
        // --retired-only, because an unattended run must not touch the build
        // state directory; see CleanupCommand::sweepStaleFiles().
        $this->assertStringContainsString('--retired-only', $entry);
        // The configured budget, from scolta.cleanup.cron_seconds.
        $this->assertStringContainsString('--max-seconds=180', $entry);
    }

    #[DefineEnvironment('disabled')]
    public function test_zero_disables_the_scheduled_sweep_entirely(): void
    {
        $this->assertNull($this->cleanupEntry(), 'A disabled sweep must register no event at all.');
    }
}
