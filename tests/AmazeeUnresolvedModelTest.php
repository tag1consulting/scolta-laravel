<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Tests;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Tag1\Scolta\Exception\ApiKeyMissingException;
use Tag1\ScoltaLaravel\ScoltaServiceProvider;
use Tag1\ScoltaLaravel\Services\ScoltaAiService;

/**
 * A stored connection whose model names never resolved must degrade, not 400.
 *
 * Enabling the managed Amazee.ai gateway stores credentials and resolves model
 * names in two steps. When the model-resolution step fails, credentials are
 * stored with no resolved model, and config still carries the shipped dated
 * default (claude-sonnet-4-5-20250929) — which the Amazee gateway rejects with
 * HTTP 400, so summarize silently returned nothing and expand ran unexpanded.
 *
 * The ScoltaAiService factory therefore injects the stored key only once a real
 * model name is stored, and otherwise builds a key-less client: that throws
 * ApiKeyMissingException before any HTTP, which the AI controllers degrade to
 * an unexpanded, no-summary HTTP 200. Nothing re-resolves the models from a
 * request path; the operator re-runs an explicit enable (the settings page or
 * `artisan scolta:amazee:provision`), which stores them.
 *
 * The empty models store is the signal, and this file pins the predicate that
 * reads it. The end-to-end resolution, including the stored-but-unresolved
 * state, is covered in AiServiceOptInResolutionTest.
 */
class AmazeeUnresolvedModelTest extends TestCase
{
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
    // Degrade: a key-less service throws ApiKeyMissingException (HTTP 200
    // path) rather than sending the gateway the dated default.
    // -------------------------------------------------------------------

    public function test_degraded_service_throws_instead_of_calling_gateway(): void
    {
        // What the factory builds in the unresolved state: amazeeActive true
        // (credentials stored) but no key injected. The first AI call throws
        // ApiKeyMissingException before any HTTP — the dated default never
        // reaches the gateway.
        $service = new ScoltaAiService(['ai_provider' => 'anthropic', 'ai_api_key' => ''], true);

        $this->assertTrue($service->isAmazeeActive());
        $this->expectException(ApiKeyMissingException::class);
        $service->message('system', 'user');
    }

    // -------------------------------------------------------------------
    // Structural: the factory gates the key on the predicate.
    // -------------------------------------------------------------------

    public function test_factory_injects_the_key_only_when_models_are_resolved(): void
    {
        $src = file_get_contents(dirname(__DIR__).'/src/ScoltaServiceProvider.php');

        // The key is injected only inside the resolved branch.
        $this->assertStringContainsString('if (self::modelsAreResolved($storage->loadModels())) {', $src);
        $this->assertStringContainsString('self::modelsAreResolved(', $src);
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
}
