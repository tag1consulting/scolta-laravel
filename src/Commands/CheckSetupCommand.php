<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Commands;

use Illuminate\Console\Command;
use Tag1\Scolta\SetupCheck;

/**
 * Verify Scolta dependencies and configuration.
 *
 * Checks PHP, FFI, Extism, WASM binary, Pagefind, and AI key
 * in one pass so developers can see exactly what's missing.
 */
class CheckSetupCommand extends Command
{
    protected $signature = 'scolta:check-setup';

    protected $description = 'Verify Scolta dependencies and configuration';

    public function handle(): int
    {
        $results = SetupCheck::run(
            configuredBinaryPath: config('scolta.pagefind.binary'),
            projectDir: base_path(),
            aiApiKey: config('scolta.ai_api_key'),
        );

        foreach ($results as $r) {
            $icon = match ($r['status']) {
                'pass' => '<fg=green>✓</>',
                'warn' => '<fg=yellow>!</>',
                'fail' => '<fg=red>✗</>',
            };
            $this->line("{$icon} {$r['name']}: {$r['message']}");
        }

        $exit = SetupCheck::exitCode($results);
        if ($exit === 0) {
            $this->newLine();
            $this->info('All critical checks passed.');
        } else {
            $this->newLine();
            $this->error('One or more critical checks failed.');
        }

        return $exit;
    }
}
