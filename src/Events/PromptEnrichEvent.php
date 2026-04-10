<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Dispatched before an AI prompt is sent to the LLM provider.
 *
 * Listen for this event to inject site-specific context into prompts.
 * The listener can modify the resolvedPrompt property to change the
 * prompt text sent to the AI provider.
 *
 * @since 0.2.0
 * @stability experimental
 */
class PromptEnrichEvent
{
    use Dispatchable;

    /**
     * @param string $resolvedPrompt The prompt text after template resolution.
     * @param string $promptName     The prompt identifier ('expand_query', 'summarize', or 'follow_up').
     * @param array  $context        Additional context (e.g., query, search results, messages).
     */
    public function __construct(
        public string $resolvedPrompt,
        public readonly string $promptName,
        public readonly array $context = [],
    ) {}
}
