<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Tests\Http;

use Orchestra\Testbench\TestCase;
use Tag1\ScoltaLaravel\ScoltaServiceProvider;
use Tag1\ScoltaLaravel\Services\ScoltaAiService;

/**
 * Request-size caps on the public follow-up endpoint.
 *
 * The expand and summarize endpoints cap their inputs (500 / 50,000 chars);
 * the follow-up endpoint historically validated messages.*.content with no
 * max, so an anonymous client could POST arbitrarily large conversation
 * payloads straight into an LLM prompt. These tests pin the per-message cap,
 * the total-payload cap, and the message-count cap at the validation layer.
 */
class FollowUpValidationTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [ScoltaServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Stub the AI service so in-cap requests never reach a real provider.
        $this->app->instance(ScoltaAiService::class, new class(['ai_provider' => 'anthropic', 'ai_api_key' => 'test-key']) extends ScoltaAiService
        {
            /** @param array<int, array<string, string>> $messages */
            public function conversation(string $systemPrompt, array $messages, int $maxTokens = 512): string
            {
                return 'stub follow-up response';
            }
        });
    }

    public function test_rejects_message_exceeding_per_message_cap(): void
    {
        $response = $this->postJson('/api/scolta/v1/followup', [
            'messages' => [
                ['role' => 'user', 'content' => str_repeat('a', 100001)],
            ],
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['messages.0.content']);
    }

    public function test_rejects_payload_exceeding_total_cap(): void
    {
        // 21 messages of 20,000 chars each pass the per-message cap (100,000)
        // and the message-count cap (25) but sum to 420,000 — over the
        // 400,000 total cap.
        $messages = [];
        for ($i = 0; $i < 21; $i++) {
            $messages[] = [
                'role' => $i % 2 === 0 ? 'user' : 'assistant',
                'content' => str_repeat('b', 20000),
            ];
        }

        $response = $this->postJson('/api/scolta/v1/followup', ['messages' => $messages]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['messages']);
    }

    public function test_rejects_too_many_messages(): void
    {
        $messages = array_fill(0, 26, ['role' => 'user', 'content' => 'hello']);

        $response = $this->postJson('/api/scolta/v1/followup', ['messages' => $messages]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['messages']);
    }

    public function test_accepts_payload_within_caps(): void
    {
        $response = $this->postJson('/api/scolta/v1/followup', [
            'messages' => [
                ['role' => 'user', 'content' => str_repeat('a', 100000)],
            ],
        ]);

        $response->assertOk();
    }

    /**
     * Regression: the real UI seeds the conversation with the full AI-Overview
     * context as the first user turn (the same excerpts the summarize endpoint
     * accepts, which regularly exceed 10k chars), then the summary, then the
     * follow-up question. Before the per-message cap was aligned with the
     * summarize context size, this legitimate payload failed validation and the
     * UI showed "Follow-up unavailable. Please try again."
     */
    public function test_accepts_conversation_seeded_with_full_summarize_context(): void
    {
        $context = str_repeat('excerpt ', 6000); // ~48k chars, like a real AI-Overview context

        $response = $this->postJson('/api/scolta/v1/followup', [
            'messages' => [
                ['role' => 'user', 'content' => "Search query: blurred vision\n\nSearch result excerpts:\n{$context}"],
                ['role' => 'assistant', 'content' => 'A short AI overview summary.'],
                ['role' => 'user', 'content' => 'What causes the blurred vision?'],
            ],
        ]);

        $response->assertOk();
    }
}
