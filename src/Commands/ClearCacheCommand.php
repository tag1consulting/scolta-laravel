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
        // Increment the generation counter — all existing cache keys
        // reference the old generation and will be treated as misses.
        $generation = Cache::get('scolta_expand_generation', 0);
        Cache::put('scolta_expand_generation', $generation + 1);

        $this->info('Scolta caches cleared (generation counter incremented).');

        return self::SUCCESS;
    }
}
