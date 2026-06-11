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
                ['role' => 'user', 'content' => str_repeat('a', 10001)],
            ],
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['messages.0.content']);
    }

    public function test_rejects_payload_exceeding_total_cap(): void
    {
        // Six messages of 9,000 chars each pass the per-message cap but sum
        // to 54,000 — over the 50,000 total cap.
        $messages = [];
        for ($i = 0; $i < 6; $i++) {
            $messages[] = [
                'role' => $i % 2 === 0 ? 'user' : 'assistant',
                'content' => str_repeat('b', 9000),
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
                ['role' => 'user', 'content' => str_repeat('a', 10000)],
            ],
        ]);

        $response->assertOk();
    }
}
