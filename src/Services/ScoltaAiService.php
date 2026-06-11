<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Services;

use Tag1\Scolta\AiProvider\Amazee\AmazeeBudgetExceededException;
use Tag1\Scolta\Config\ScoltaConfig;
use Tag1\Scolta\Service\AiServiceAdapter;

/**
 * AI service adapter for Laravel.
 *
 * Dual-path AI provider support, same pattern as WordPress:
 *   - Laravel 12+: Detects and uses the Laravel AI SDK (laravel/ai)
 *   - Laravel 11:  Falls back to scolta-php's built-in AiClient
 *
 * When Amazee.ai credentials are injected via the service provider,
 * message() / conversation() / messageForOperation() convert the
 * Amazee budget signal to AmazeeBudgetExceededException so the
 * HandleAmazeeBudgetExceeded middleware can return a proper 503.
 */
class ScoltaAiService extends AiServiceAdapter
{
    /**
     * The default AI model shipped in config/scolta.php.
     *
     * Referenced by both the published config and the service provider's
     * "is the model still the default?" check for Amazee auto-selected
     * models — a single constant so the two cannot silently diverge when
     * the default is bumped.
     *
     * @since 1.0.4
     *
     * @stability experimental
     */
    public const DEFAULT_MODEL = 'claude-sonnet-4-5-20250929';

    private bool $amazeeActive;

    public function __construct(array $configArray, bool $amazeeActive = false)
    {
        // Flatten the nested config arrays for ScoltaConfig::fromArray().
        $flat = self::flattenConfig($configArray);
        parent::__construct(ScoltaConfig::fromArray($flat));
        $this->amazeeActive = $amazeeActive;
    }

    /**
     * Whether Amazee.ai credentials are active for this instance.
     */
    public function isAmazeeActive(): bool
    {
        return $this->amazeeActive;
    }

    /**
     * {@inheritdoc}
     *
     * Convert a budget-exceeded RuntimeException to AmazeeBudgetExceededException.
     * No-op if the exception message does not contain the Amazee budget signal.
     * Invoked by the base AI methods' catch block.
     *
     * @throws AmazeeBudgetExceededException When the budget message is detected.
     */
    protected function handlePossibleBudgetException(\RuntimeException $e): void
    {
        if (! str_contains($e->getMessage(), 'Budget has been exceeded!')) {
            return;
        }
        throw new AmazeeBudgetExceededException($e);
    }

    /**
     * Check if the Laravel AI SDK is available (Laravel 12+).
     */
    public function hasLaravelAiSdk(): bool
    {
        return class_exists('\Illuminate\Support\Facades\Ai')
            || class_exists('\Laravel\Ai\Facades\Ai');
    }

    /**
     * {@inheritdoc}
     */
    protected function tryFrameworkAi(string $systemPrompt, string $userMessage, int $maxTokens): ?string
    {
        if (! $this->hasLaravelAiSdk()) {
            return null;
        }

        try {
            return $this->messageViaLaravelSdk($systemPrompt, $userMessage, $maxTokens);
        } catch (\Exception $e) {
            // SDK not configured — fall through to built-in.
            logger()->warning('[scolta] Laravel AI SDK failed, falling back to built-in', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function tryFrameworkConversation(string $systemPrompt, array $messages, int $maxTokens): ?string
    {
        if (! $this->hasLaravelAiSdk()) {
            return null;
        }

        try {
            return $this->conversationViaLaravelSdk($systemPrompt, $messages, $maxTokens);
        } catch (\Exception $e) {
            logger()->warning('[scolta] Laravel AI SDK conversation failed, falling back', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Send a message via the Laravel AI SDK.
     *
     * The Laravel AI SDK provides a clean facade-based API. The user
     * configures their provider and API key in config/ai.php, and
     * we just call the facade.
     */
    private function messageViaLaravelSdk(string $systemPrompt, string $userMessage, int $maxTokens): string
    {
        // Use the Ai facade. Laravel 12+ registers it automatically
        // when laravel/ai is installed.
        $ai = app('ai');

        $response = $ai->chat()
            ->systemPrompt($systemPrompt)
            ->maxTokens($maxTokens)
            ->send($userMessage);

        return $response->text();
    }

    /**
     * Send a conversation via the Laravel AI SDK.
     */
    private function conversationViaLaravelSdk(string $systemPrompt, array $messages, int $maxTokens): string
    {
        $ai = app('ai');

        $chat = $ai->chat()
            ->systemPrompt($systemPrompt)
            ->maxTokens($maxTokens);

        // Build the conversation from message history.
        foreach ($messages as $msg) {
            if ($msg['role'] === 'user') {
                $chat = $chat->user($msg['content']);
            } elseif ($msg['role'] === 'assistant') {
                $chat = $chat->assistant($msg['content']);
            }
        }

        $response = $chat->send();

        return $response->text();
    }

    /**
     * Flatten nested config arrays for ScoltaConfig::fromArray().
     *
     * Laravel config uses nested arrays (scoring.title_match_boost),
     * but ScoltaConfig expects flat snake_case keys. This flattens
     * one level of nesting.
     */
    public static function flattenConfig(array $config): array
    {
        $flat = [];

        foreach ($config as $key => $value) {
            if (is_array($value) && ! array_is_list($value)) {
                // Nested associative array — flatten with parent key prefix.
                foreach ($value as $subKey => $subValue) {
                    $flat[$subKey] = $subValue;
                }
            } else {
                $flat[$key] = $value;
            }
        }

        return $flat;
    }
}
