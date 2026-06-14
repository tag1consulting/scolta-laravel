<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Tag1\Scolta\AiProvider\Amazee\AmazeeClient;
use Tag1\Scolta\AiProvider\Amazee\AutoProvisioner;
use Tag1\Scolta\AiProvider\Amazee\ConfigStorageInterface;
use Tag1\Scolta\Exception\ApiKeyMissingException;
use Tag1\ScoltaLaravel\ScoltaServiceProvider;
use Tag1\ScoltaLaravel\Services\ScoltaAiService;

/**
 * Behavioral coverage for the Amazee model-resolution self-heal adoption.
 *
 * The Amazee provisioner stores credentials and resolves model names in two
 * steps. When the /model/info step fails, credentials are stored with no
 * resolved model: AutoProvisioner::ensureAiAvailable() no-opped forever on the
 * stored creds, the ScoltaAiService factory built the client with the shipped
 * dated default (claude-sonnet-4-5-20250929), and the Amazee gateway rejects
 * that with HTTP 400 — summarize silently returned nothing, expand ran
 * unexpanded.
 *
 * ScoltaServiceProvider now passes scolta-php's hasResolvedModels predicate (so
 * the library re-resolves against the stored key — never a fresh trial), keeps
 * a half-provisioned state out of the once-per-install cache flag (so the heal
 * actually runs), and degrades the client to key-less (HTTP 200) rather than
 * sending the gateway the dated default.
 */
class ModelResolutionSelfHealTest extends TestCase
{
    private const MODEL_INFO_RESPONSE = '{"data": [{"model_name": "claude-sonnet-4-5"}, {"model_name": "claude-haiku-4-5"}]}';

    private const CREDS = [
        'litellm_token' => 'sk-stored-token',
        'litellm_api_url' => 'https://llm.test.amazee.ai',
        'region' => 'test-region',
    ];

    protected function setUp(): void
    {
        $app = new Application(dirname(__DIR__));
        Container::setInstance($app);
        Cache::swap(new Repository(new ArrayStore));
    }

    protected function tearDown(): void
    {
        Cache::clearResolvedInstances();
        Container::setInstance(null);
    }

    // -------------------------------------------------------------------
    // The predicate: empty models store => unresolved (the clean signal).
    // -------------------------------------------------------------------

    public function test_predicate_reports_unresolved_for_empty_store(): void
    {
        $this->assertFalse($this->invokePredicate(null), 'No stored models => unresolved');
        $this->assertFalse(
            $this->invokePredicate(['ai_model' => '', 'ai_expansion_model' => '']),
            'A stored-but-empty model row => unresolved'
        );
    }

    public function test_predicate_reports_resolved_for_real_model_name(): void
    {
        $this->assertTrue(
            $this->invokePredicate(['ai_model' => 'claude-sonnet-4-5', 'ai_expansion_model' => 'claude-haiku-4-5'])
        );
    }

    // -------------------------------------------------------------------
    // Self-heal: the real AutoProvisioner, driven by the actual predicate.
    // -------------------------------------------------------------------

    public function test_stored_creds_without_models_self_heal(): void
    {
        // Half-provisioned: credentials stored, no resolved models.
        $storage = new InMemoryModelStorage(self::CREDS);

        // Only /model/info is queued — provisioning a NEW trial would throw,
        // proving the heal re-resolves against the stored key, not a fresh trial.
        $client = $this->makeAmazeeClient([new Response(200, [], self::MODEL_INFO_RESPONSE)]);

        $provisioned = AutoProvisioner::ensureAiAvailable(
            $storage,
            hasExplicitApiKey: false,
            onModelsResolved: function (string $aiModel, string $aiExpansionModel) use ($storage): void {
                $storage->storeModels($aiModel, $aiExpansionModel);
            },
            client: $client,
            // The EXACT predicate ScoltaServiceProvider wires.
            hasResolvedModels: fn (): bool => $this->invokePredicate($storage->loadModels()),
        );

        $this->assertFalse($provisioned, 'A model-only heal is not a fresh-trial provision');
        $this->assertSame(
            ['ai_model' => 'claude-sonnet-4-5', 'ai_expansion_model' => 'claude-haiku-4-5'],
            $storage->loadModels(),
            'The stored credentials must self-heal to resolved model names'
        );
    }

    public function test_naive_creds_present_predicate_would_not_heal(): void
    {
        // The trap is the legacy no-op itself: a predicate that reports "resolved"
        // because credentials are stored (rather than because MODELS are stored)
        // keeps ensureAiAvailable a no-op and the bug ships.
        $storage = new InMemoryModelStorage(self::CREDS);

        // No responses queued: any Amazee call would throw.
        $client = $this->makeAmazeeClient([]);

        AutoProvisioner::ensureAiAvailable(
            $storage,
            hasExplicitApiKey: false,
            onModelsResolved: function (string $aiModel, string $aiExpansionModel) use ($storage): void {
                $storage->storeModels($aiModel, $aiExpansionModel);
            },
            client: $client,
            hasResolvedModels: fn (): bool => $storage->load() !== null, // wrong signal
        );

        $this->assertNull(
            $storage->loadModels(),
            'A creds-present predicate leaves the store unresolved (the bug)'
        );
    }

    // -------------------------------------------------------------------
    // Degrade: a key-less service throws ApiKeyMissingException (HTTP 200
    // path) rather than sending the gateway the dated default.
    // -------------------------------------------------------------------

    public function test_degraded_service_throws_instead_of_calling_gateway(): void
    {
        // What the factory builds in the half-provisioned state: amazeeActive
        // true (credentials stored) but no Amazee key injected. The first AI
        // call throws ApiKeyMissingException before any HTTP — the dated default
        // never reaches the gateway.
        $service = new ScoltaAiService(['ai_provider' => 'anthropic', 'ai_api_key' => ''], true);

        $this->assertTrue($service->isAmazeeActive());
        $this->expectException(ApiKeyMissingException::class);
        $service->message('system', 'user');
    }

    // -------------------------------------------------------------------
    // Structural: the wiring is present at both points.
    // -------------------------------------------------------------------

    public function test_provisioning_passes_resolved_models_predicate(): void
    {
        $src = $this->source();
        $this->assertStringContainsString('hasResolvedModels:', $src);
        $this->assertStringContainsString('self::modelsAreResolved(', $src);
    }

    public function test_factory_degrades_when_models_unresolved(): void
    {
        $src = $this->source();
        // The factory injects the key only inside the resolved branch.
        $this->assertStringContainsString('if (self::modelsAreResolved($storage->loadModels())) {', $src);
        // A half-provisioned state is kept out of the once-per-install flag.
        $this->assertStringContainsString('amazeeHalfProvisioned(', $src);
    }

    // -------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------

    /**
     * @param  array{ai_model: string, ai_expansion_model: string}|null  $models
     */
    private function invokePredicate(?array $models): bool
    {
        // Reflection ignores visibility since PHP 8.1; the static needs no instance.
        $method = new ReflectionMethod(ScoltaServiceProvider::class, 'modelsAreResolved');

        return $method->invoke(null, $models);
    }

    private function source(): string
    {
        return file_get_contents(dirname(__DIR__).'/src/ScoltaServiceProvider.php');
    }

    /**
     * @param  array<int, Response>  $responses
     */
    private function makeAmazeeClient(array $responses): AmazeeClient
    {
        return new AmazeeClient(
            'https://api.amazee.ai',
            new Client(['handler' => HandlerStack::create(new MockHandler($responses))]),
        );
    }
}

/**
 * In-memory ConfigStorageInterface that also carries the Amazee models store,
 * mirroring LaravelConfigStorage without its DB + Crypt dependencies.
 */
class InMemoryModelStorage implements ConfigStorageInterface
{
    /** @var array{ai_model: string, ai_expansion_model: string}|null */
    private ?array $models = null;

    /** @param array{litellm_token: string, litellm_api_url: string, region: string}|null $stored */
    public function __construct(private ?array $stored = null) {}

    public function store(string $litellmToken, string $litellmApiUrl, string $region): void
    {
        $this->stored = [
            'litellm_token' => $litellmToken,
            'litellm_api_url' => $litellmApiUrl,
            'region' => $region,
        ];
    }

    public function load(): ?array
    {
        return $this->stored;
    }

    public function clear(): void
    {
        $this->stored = null;
        $this->models = null;
    }

    public function storeModels(string $aiModel, string $aiExpansionModel): void
    {
        $this->models = ['ai_model' => $aiModel, 'ai_expansion_model' => $aiExpansionModel];
    }

    /** @return array{ai_model: string, ai_expansion_model: string}|null */
    public function loadModels(): ?array
    {
        return $this->models;
    }
}
