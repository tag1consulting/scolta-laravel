<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Services;

use Tag1\Scolta\AiClient;
use Tag1\Scolta\Config\ScoltaConfig;
use Tag1\Scolta\Prompt\DefaultPrompts;

/**
 * AI service adapter for Laravel.
 *
 * Dual-path AI provider support:
 *   - Laravel 12+: Detects and uses the Laravel AI SDK (laravel/ai)
 *   - Laravel 11:  Falls back to scolta-core's built-in AiClient
 *
 * The service is bound as a singleton in the provider — config is read
 * once, instance reused across all API calls in a request.
 */
class ScoltaAiService
{
    private ScoltaConfig $config;
    private ?AiClient $client = null;

    public function __construct(array $configArray)
    {
        $flat = self::flattenConfig($configArray);
        $this->config = ScoltaConfig::fromArray($flat);
    }

    public function getConfig(): ScoltaConfig
    {
        return $this->config;
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
     * Send a single-turn AI message.
     *
     * Tries Laravel AI SDK first (if available), then falls back to
     * scolta-core's built-in AiClient.
     */
    public function message(string $systemPrompt, string $userMessage, int $maxTokens = 512): string
    {
        // Path 1: Laravel AI SDK.
        if ($this->hasLaravelAiSdk()) {
            try {
                return $this->messageViaLaravelSdk($systemPrompt, $userMessage, $maxTokens);
            } catch (\Exception $e) {
                logger()->warning('[scolta] Laravel AI SDK failed, falling back to built-in', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Path 2: Built-in AiClient from scolta-core.
        return $this->getClient()->message($systemPrompt, $userMessage, $maxTokens);
    }

    /**
     * Send a multi-turn conversation.
     */
    public function conversation(string $systemPrompt, array $messages, int $maxTokens = 512): string
    {
        // Path 1: Laravel AI SDK.
        if ($this->hasLaravelAiSdk()) {
            try {
                return $this->conversationViaLaravelSdk($systemPrompt, $messages, $maxTokens);
            } catch (\Exception $e) {
                logger()->warning('[scolta] Laravel AI SDK conversation failed, falling back', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Path 2: Built-in.
        return $this->getClient()->conversation($systemPrompt, $messages, $maxTokens);
    }

    /**
     * Get the expand-query system prompt.
     */
    public function getExpandPrompt(): string
    {
        if (!empty($this->config->promptExpandQuery)) {
            return $this->config->promptExpandQuery;
        }

        return DefaultPrompts::resolve(
            DefaultPrompts::EXPAND_QUERY,
            $this->config->siteName,
            $this->config->siteDescription,
        );
    }

    /**
     * Get the summarize system prompt.
     */
    public function getSummarizePrompt(): string
    {
        if (!empty($this->config->promptSummarize)) {
            return $this->config->promptSummarize;
        }

        return DefaultPrompts::resolve(
            DefaultPrompts::SUMMARIZE,
            $this->config->siteName,
            $this->config->siteDescription,
        );
    }

    /**
     * Get the follow-up system prompt.
     */
    public function getFollowUpPrompt(): string
    {
        if (!empty($this->config->promptFollowUp)) {
            return $this->config->promptFollowUp;
        }

        return DefaultPrompts::resolve(
            DefaultPrompts::FOLLOW_UP,
            $this->config->siteName,
            $this->config->siteDescription,
        );
    }

    /**
     * Get the built-in AiClient (lazily instantiated).
     */
    private function getClient(): AiClient
    {
        if ($this->client === null) {
            $this->client = new AiClient($this->config->toAiClientConfig());
        }

        return $this->client;
    }

    /**
     * Send a message via the Laravel AI SDK.
     */
    private function messageViaLaravelSdk(string $systemPrompt, string $userMessage, int $maxTokens): string
    {
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
            if (is_array($value) && !array_is_list($value)) {
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
