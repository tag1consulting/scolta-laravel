<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Services;

use Illuminate\Support\Facades\Cache;
use Tag1\Scolta\AiProvider\Amazee\AmazeeBudgetExceededException;
use Tag1\Scolta\AiProvider\Amazee\KeyExpiryRecovery;
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

    /**
     * @param  array<string, mixed>  $configArray
     */
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
     * Cache key for the "operator has been told AI is degraded" breadcrumb.
     *
     * A short TTL collapses the warning to once per window instead of one log
     * line per failing request. Public so tests and adapters reference one
     * definition.
     *
     * @since 1.0.5
     *
     * @stability experimental
     */
    public const REAUTH_NOTICE_KEY = 'scolta_amazee_reauth_notified';

    /**
     * How long the degraded-AI warning stays suppressed, in seconds.
     */
    private const REAUTH_NOTICE_TTL = 3600;

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
        if (str_contains($e->getMessage(), 'Budget has been exceeded!')) {
            throw new AmazeeBudgetExceededException($e);
        }

        // Amazee.ai path only: when the stored credentials are no longer
        // accepted, the persistent state and the /health degrade are recorded
        // by KeyExpiryRecovery (wired in the service provider, invoked right
        // after this hook). Emit a single operator-facing warning per window —
        // naming where to reconnect — rather than a line on every request.
        if ($this->amazeeActive && KeyExpiryRecovery::isAuthFailure($e)) {
            $this->noticeReauthNeeded();
        }
    }

    /**
     * Log, once per window, that AI is degraded until the operator reconnects.
     *
     * The persistent state and health degrade are handled elsewhere; this is
     * an operator breadcrumb only. It never mints or re-requests credentials —
     * reconnection is always an explicit step from the settings page or the
     * `scolta:amazee:provision` command.
     */
    private function noticeReauthNeeded(): void
    {
        if (Cache::add(self::REAUTH_NOTICE_KEY, true, self::REAUTH_NOTICE_TTL)) {
            logger()->warning(
                '[scolta] Amazee.ai credentials are no longer accepted; AI search features '
                .'are degraded until you reconnect. Reconnect from the Scolta Amazee.ai settings '
                .'page or run: php artisan scolta:amazee:provision <email>.'
            );
        }
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
     *
     * @param  array<int, array<string, string>>  $messages
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
     *
     * @param  array<int, array<string, string>>  $messages
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
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
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
