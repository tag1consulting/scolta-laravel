<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Prompt;

use Illuminate\Contracts\Events\Dispatcher;
use Tag1\Scolta\Prompt\PromptEnricherInterface;
use Tag1\ScoltaLaravel\Events\PromptEnrichEvent;

/**
 * Prompt enricher that dispatches a Laravel event for listeners.
 *
 * This bridges the scolta-php PromptEnricherInterface with Laravel's event
 * system. Applications can register listeners for PromptEnrichEvent to
 * inject site-specific context into AI prompts.
 *
 * @since 0.2.0
 *
 * @stability experimental
 */
class EventDrivenEnricher implements PromptEnricherInterface
{
    public function __construct(
        private readonly Dispatcher $events,
    ) {}

    /**
     * {@inheritdoc}
     */
    public function enrich(string $resolvedPrompt, string $promptName, array $context = []): string
    {
        $event = new PromptEnrichEvent($resolvedPrompt, $promptName, $context);
        $this->events->dispatch($event);

        return $event->resolvedPrompt;
    }
}
