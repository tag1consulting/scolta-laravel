<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/**
 * Runs, and bounds, the fresh processes a memory-aborted build needs to finish.
 *
 * A corpus too large for one PHP heap yields on memory pressure and is carried
 * on in a new process. The process an operator started drives those segments in
 * the foreground: it runs each one, streams its output, and reads its exit code,
 * so `scolta:build` returns only once the chain has actually ended. That is what
 * lets the segment counter be an ordinary local variable in the driver instead
 * of something that has to ride the successor's command line.
 *
 * This class does the I/O and decides nothing: whether a segment earned a
 * successor is scolta-php's ResumeChainPolicy::failureReason().
 *
 * @since 1.4.0
 *
 * @stability experimental
 */
class ResumeChain
{
    /**
     * Environment variable carrying the parent's build-lock owner token.
     *
     * A segment that sees it runs under that lock instead of taking its own,
     * which would fail against the parent's and exit deferred.
     *
     * @since 1.4.0
     *
     * @stability experimental
     */
    public const LOCK_OWNER_ENV = 'SCOLTA_BUILD_LOCK_OWNER';

    /**
     * Environment variables added to every child segment.
     *
     * @var array<string, string>
     *
     * @since 1.4.0
     *
     * @stability experimental
     */
    public array $env = [];

    /**
     * Run one resume segment to completion in a fresh process.
     *
     * Foreground and streaming: the driver blocks here until the child exits, so
     * it sees the exit code it has to classify, and the operator sees the child's
     * progress while it happens rather than after the fact.
     *
     * `forever()` because a segment of a large corpus routinely runs past the
     * facade's 60-second default, and a driver that killed its own segment at one
     * minute would read the timeout as a failed build.
     *
     * @param  bool  $force  Whether the operator asked for a forced build.
     * @param  (callable(string): void)|null  $onOutput  Receives the child's output as it arrives.
     * @return int|null The child's exit code, or null when there is no artisan
     *                  binary to run and the operator has to resume by hand.
     *
     * @since 1.4.0
     *
     * @stability experimental
     */
    public function runSegment(?string $memoryBudget, ?string $chunkSize, bool $force, ?callable $onOutput = null): ?int
    {
        $artisan = base_path('artisan');
        if (! File::exists($artisan)) {
            return null;
        }

        // --resume is also how the child knows not to start a chain of its own:
        // it runs one segment, reports how it ended, and leaves this process to
        // decide what happens next. --indexer=php because the chain exists only
        // on the PHP indexer path, and an --indexer option on the parent must not
        // let config send the child down the Pagefind-binary pipeline instead.
        $command = [PHP_BINARY, $artisan, 'scolta:build', '--indexer=php', '--resume'];

        // --force must survive segmentation or a forced build is forced for its
        // first segment only, serving its tail out of the very token cache it was
        // told to bypass. Narrower than the sibling Drupal adapter's rationale for
        // the same line: its cached-content-reference degradation cannot happen
        // here (nothing in this package builds a CachedContentReference or reads a
        // TimestampManifest), so do not import that half.
        if ($force) {
            $command[] = '--force';
        }

        if (! empty($memoryBudget)) {
            $command[] = '--memory-budget='.$memoryBudget;
        }
        if (! empty($chunkSize)) {
            $command[] = '--chunk-size='.$chunkSize;
        }

        $result = Process::path(base_path())->env($this->env)->forever()->run(
            $command,
            function (string $type, string $buffer) use ($onOutput): void {
                if ($onOutput !== null) {
                    $onOutput($buffer);
                }
            },
        );

        // A completed run always has a code; treat an absent one as a failure
        // rather than as the 0 a cast would produce.
        return $result->exitCode() ?? 1;
    }
}
