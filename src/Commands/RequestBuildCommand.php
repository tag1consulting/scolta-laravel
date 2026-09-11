<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Tag1\ScoltaLaravel\Jobs\TriggerRebuild;

/**
 * Queue one index rebuild request for the queue worker to act on.
 *
 * The counterpart of `drush scolta:request-build`: nothing is built here.
 * The request is the same TriggerRebuild job a content save schedules, so it
 * applies the tracked changes incrementally when it can, streams the corpus
 * when it cannot, and continues an interrupted build first if one is on disk.
 * A request already waiting (the observer's debounce key) means nothing is
 * added.
 *
 * @since 1.4.0
 *
 * @stability experimental
 */
class RequestBuildCommand extends Command
{
    protected $signature = 'scolta:request-build';

    protected $description = 'Queue one index rebuild request for the queue worker (builds nothing itself)';

    /**
     * Dispatch the request unless one is already waiting.
     *
     * @since 1.4.0
     *
     * @stability experimental
     */
    public function handle(): int
    {
        $delay = (int) config('scolta.auto_rebuild_delay', 300);
        if (! Cache::add(TriggerRebuild::DEBOUNCE_KEY, true, $delay)) {
            $this->info('A rebuild is already requested; nothing added.');

            return self::SUCCESS;
        }

        TriggerRebuild::dispatch();

        $connection = (string) config('queue.default');
        $this->info($connection === 'sync'
            ? 'Rebuild ran inline on the sync queue connection.'
            : sprintf('Rebuild requested on the "%s" queue; a worker (php artisan queue:work) runs it.', $connection));

        return self::SUCCESS;
    }
}
