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
 * Establish the free Amazee.ai demo connection from the command line.
 *
 * Equivalent to clicking "Try the demo" on the settings page, and one of the
 * two surfaces that establish a connection. Both are explicit operator actions;
 * nothing on a request path establishes one. Useful in CI/CD pipelines or
 * Kubernetes init containers where there is no browser session.
 *
 * The email argument is optional, because the demo needs none — that is the
 * point of it. Attaching a real amazee.ai account is the email → verification
 * code → region flow, which needs a browser and lives on the settings page.
 */
class AmazeeProvisionCommand extends Command
{
    /**
     * The offer this command acts on.
     *
     * The settings page states it in the same words. A test asserts the view
     * carries this exact sentence, so the two enable surfaces cannot drift
     * into describing the same offer differently.
     *
     * @since 1.1.0
     *
     * @stability experimental
     */
    public const OFFER_LINE = 'Try Amazee.ai for AI-powered search with a free demo, no email required; sign in with your email to set up an account and keep it when the demo credit runs out.';

    protected $signature = 'scolta:amazee:provision
                            {email? : Optional email to bind the demo to. The demo needs none; omit it.}
                            {--force : Provision even if an AI provider is already configured}';

    protected $description = 'Start the free Amazee.ai demo for AI-powered search and store the connection';

    public function handle(): int
    {
        // Optional on purpose. Trying the demo must cost an operator no input
        // at all; an address is only validated when one is actually supplied.
        $email = (string) ($this->argument('email') ?? '');

        if ($email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error("Invalid email address: {$email}");

            return self::FAILURE;
        }

        $this->line(self::OFFER_LINE);

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
            $this->line('AI search features are degraded until you reconnect.');
            // The demo is one-time per site, so it is usually not the way back:
            // point at the path that is.
            $this->line('The free demo can only be used once per site. If it has been used, reconnect from the');
            $this->line('Scolta Amazee.ai settings page under "Enter your Amazee credentials", which signs you in');
            $this->line('with your email address and sets up your account.');
        }

        $this->line($email === ''
            ? 'Starting the free Amazee.ai demo…'
            : "Starting the free Amazee.ai demo for {$email}…");

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
            $this->error('Could not start the demo: '.$e->getMessage());
            $this->line('The free demo can only be used once per site. If this site has already used it, open the');
            $this->line('Scolta Amazee.ai settings page and sign in with your email address to set up an account.');

            return self::FAILURE;
        }

        if ($result->status === ProvisioningResult::STATUS_SKIPPED_EXISTING_PROVIDER) {
            $this->info('AI provider already configured. Skipped Amazee provisioning.');
            $this->line('Use <info>--force</info> to provision anyway.');

            return self::SUCCESS;
        }

        $region = $result->region ?: 'unknown';
        $this->info("Connected to Amazee.ai using the free demo (region: {$region}).");

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
