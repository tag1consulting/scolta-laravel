<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Commands;

use Illuminate\Console\Command;
use Tag1\Scolta\AiProvider\Amazee\AmazeeApiException;
use Tag1\Scolta\AiProvider\Amazee\AmazeeClient;
use Tag1\Scolta\AiProvider\Amazee\AmazeeModelResolver;
use Tag1\Scolta\AiProvider\Amazee\AmazeeTrialProvisioner;
use Tag1\Scolta\AiProvider\Amazee\KeyExpiryRecovery;
use Tag1\Scolta\AiProvider\Amazee\ProvisioningResult;
use Tag1\ScoltaLaravel\AiProvider\Amazee\LaravelConfigStorage;
use Tag1\ScoltaLaravel\Cache\LaravelCacheDriver;

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
                            {email : Email address for the Amazee.ai trial account}
                            {--force : Provision even if an AI provider is already configured}';

    protected $description = 'Provision a free Amazee.ai trial account and store credentials';

    public function handle(): int
    {
        $email = $this->argument('email');

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error("Invalid email address: {$email}");

            return self::FAILURE;
        }

        // If the stored credentials are no longer accepted, tell the operator
        // why AI is degraded and how to reconnect before continuing. Running
        // this command with an email re-establishes the Amazee.ai connection.
        $recovery = new KeyExpiryRecovery(
            storage: new LaravelConfigStorage,
            cache: new LaravelCacheDriver,
            logger: logger(),
        );
        if ($recovery->isUpgradeNeeded()) {
            $this->warn('The current Amazee.ai connection is no longer accepted and needs re-authentication.');
            $this->line('AI search features are degraded until you reconnect. This command will re-establish the connection.');
            $this->line('You can also reconnect from the Scolta Amazee.ai settings page.');
        }

        $this->line("Connecting to Amazee.ai for {$email}…");

        $hasExistingProvider = $this->option('force')
            ? null
            : function (): bool {
                $key = config('scolta.ai_api_key', '');

                return $key !== '';
            };

        try {
            $storage = new LaravelConfigStorage;
            $amazeeClient = new AmazeeClient;
            $provisioner = new AmazeeTrialProvisioner(
                $amazeeClient,
                $storage,
                $hasExistingProvider,
                new AmazeeModelResolver($amazeeClient),
            );
            $result = $provisioner->provision($email);
        } catch (AmazeeApiException $e) {
            $this->error('Provisioning failed: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($result->status === ProvisioningResult::STATUS_SKIPPED_EXISTING_PROVIDER) {
            $this->info('AI provider already configured. Skipped Amazee provisioning.');
            $this->line('Use <info>--force</info> to provision anyway.');

            return self::SUCCESS;
        }

        $region = $result->region ?: 'unknown';
        $this->info("Connected to Amazee.ai (region: {$region}).");

        if ($result->aiModel !== null) {
            $storage = new LaravelConfigStorage;
            $storage->storeModels($result->aiModel, $result->aiExpansionModel ?? '');
            $this->line("  AI model:           {$result->aiModel}");
            if ($result->aiExpansionModel !== null) {
                $this->line("  AI expansion model: {$result->aiExpansionModel}");
            }
        }

        $this->line('Run <info>php artisan scolta:status</info> to verify the AI provider.');

        return self::SUCCESS;
    }
}
