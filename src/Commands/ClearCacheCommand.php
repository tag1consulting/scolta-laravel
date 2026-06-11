<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Clear all Scolta caches (expansion and summary).
 *
 * Increments the generation counter, which invalidates all cached
 * expansion and summarization responses without needing to enumerate
 * or flush individual cache keys.
 */
class ClearCacheCommand extends Command
{
    protected $signature = 'scolta:clear-cache';

    protected $description = 'Clear all Scolta AI response caches';

    public function handle(): int
    {
        // Atomically increment the generation counter — all existing cache
        // keys reference the old generation and will be treated as misses.
        // (A get + put pair raced with concurrent build finishes.)
        Cache::increment('scolta_expand_generation');

        $this->info('Scolta caches cleared (generation counter incremented).');

        return self::SUCCESS;
    }
}
