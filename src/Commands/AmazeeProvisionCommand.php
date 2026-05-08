<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Commands;

use Illuminate\Console\Command;
use Tag1\Scolta\AiProvider\Amazee\AmazeeApiException;
use Tag1\Scolta\AiProvider\Amazee\AmazeeClient;
use Tag1\Scolta\AiProvider\Amazee\AmazeeTrialProvisioner;
use Tag1\ScoltaLaravel\AiProvider\Amazee\LaravelConfigStorage;

/**
 * Provision an Amazee.ai free trial account from the command line.
 *
 * Equivalent to clicking "Start free trial" on the settings page.
 * Useful for automated provisioning in CI/CD pipelines or Kubernetes
 * init containers where there is no browser session.
 */
class AmazeeProvisionCommand extends Command
{
    protected $signature = 'scolta:amazee:provision
                            {email : Email address for the Amazee.ai trial account}';

    protected $description = 'Provision a free Amazee.ai trial account and store credentials';

    public function handle(): int
    {
        $email = $this->argument('email');

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error("Invalid email address: {$email}");

            return self::FAILURE;
        }

        $this->line("Provisioning Amazee.ai trial for {$email}…");

        try {
            $storage = new LaravelConfigStorage;
            $provisioner = new AmazeeTrialProvisioner(new AmazeeClient, $storage);
            $provisioner->provision($email);
        } catch (AmazeeApiException $e) {
            $this->error('Provisioning failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $creds = (new LaravelConfigStorage)->load();
        $region = $creds['region'] ?? 'unknown';

        $this->info("Connected to Amazee.ai (region: {$region}).");
        $this->line('Run <info>php artisan scolta:status</info> to verify the AI provider.');

        return self::SUCCESS;
    }
}
