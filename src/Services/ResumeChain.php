<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tag1\Scolta\Index\StatusReport;

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
 * The two halves are deliberately separate. failureReason() decides and touches
 * nothing, which is what makes the bounds testable without a large corpus;
 * runSegment() does the I/O and decides nothing.
 *
 * @since 1.4.0
 *
 * @stability experimental
 */
class ResumeChain
{
    /**
     * How many fresh processes one build may use to get through the corpus.
     *
     * A bound, not a target: every segment has to commit pages the previous one
     * did not, so a build that is genuinely progressing finishes well inside it.
     *
     * @since 1.4.0
     *
     * @stability experimental
     */
    public const MAX_SEGMENTS = 50;

    /**
     * @param  string|null  $memoryLimit  The PHP memory_limit to quote in remediation,
     *                                    or null when it cannot be read.
     */
    public function __construct(private readonly ?string $memoryLimit = null) {}

    /**
     * Why the chain must stop after this segment, or null to run another.
     *
     * Only called for a segment that ended badly: segment 0 is the build in the
     * driver's own process, which reaches here on a memory abort, and every
     * later segment is a child that exited non-zero.
     *
     * A foreground driver sees only an exit code, and every failure exits
     * non-zero, so a voluntary memory yield that wants another segment and a
     * merge that found the index corrupt look identical from there. The segment
     * records which it was via BuildState::recordOutcome(); this turns that
     * record into the one decision the driver has to make.
     *
     * @param  array<string, mixed>|null  $outcome  What the segment recorded on its way out
     *                                              (BuildState::readOutcome()), or null when it recorded nothing — an
     *                                              OOM kill, a fatal, a signal.
     * @param  int  $pagesCommitted  Pages the shared build manifest shows committed now.
     * @param  int  $pagesBefore  Pages it showed before this segment ran.
     * @param  int  $segment  The segment's position in the chain: 0 for the build an
     *                        operator started, N for the Nth process it ran.
     * @return string|null The failure to report, or null when this segment made
     *                     progress and another one is worth running.
     *
     * @since 1.4.0
     *
     * @stability experimental
     */
    public function failureReason(?array $outcome, int $pagesCommitted, int $pagesBefore, int $segment): ?string
    {
        // A segment that recorded anything other than a memory yield has decided
        // this build is broken. Resuming re-walks the whole corpus to reach the
        // same error, so the chain stops here and reports what actually failed.
        if ($outcome !== null && ($outcome['error'] ?? null) !== StatusReport::MEMORY_ABORT) {
            $error = $outcome['error'] ?? null;

            return sprintf(
                'The build failed in resume segment %d and the index has not been republished: %s',
                $segment,
                // A segment that recorded success and still exited non-zero
                // failed after its build returned — publishing, verifying,
                // shutting down. Its own output is above, in this console.
                is_string($error) && $error !== ''
                    ? $error
                    : 'the segment reported a successful build and then exited non-zero; see its output above.',
            );
        }

        // Either the segment yielded for memory or it died without recording
        // anything. Both leave progress as the only evidence, and the old
        // terminator — chunksWritten === 0 — cannot supply it: chunksWritten
        // counts the chunk files on disk, cumulative for the whole build and
        // exactly what resume relies on persisting. Only progress since this
        // segment started tells carrying forward from repeating.
        if ($pagesCommitted <= $pagesBefore) {
            return sprintf(
                'The build stalled at %d pages committed: %s hit the memory limit without committing a page, '
                .'so another resume would repeat it. The index has not been republished. Raise PHP memory_limit '
                .'(currently %s) or lower the per-chunk footprint with --memory-budget=conservative / --chunk-size, '
                .'then re-run with --restart.',
                $pagesCommitted,
                $segment === 0 ? 'this build' : sprintf('resume segment %d', $segment),
                $this->memoryLimit ?? 'unknown',
            );
        }

        // Progress alone is not a licence to run segments forever: a few pages
        // per segment would satisfy the check above indefinitely.
        if ($segment >= self::MAX_SEGMENTS) {
            return sprintf(
                'The build did not complete within %d resume segments (%d pages committed). The index has not '
                .'been republished. Raise PHP memory_limit (currently %s) or the memory budget so fewer segments '
                .'are needed, then re-run with --restart.',
                self::MAX_SEGMENTS,
                $pagesCommitted,
                $this->memoryLimit ?? 'unknown',
            );
        }

        return null;
    }

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

        $result = Process::path(base_path())->forever()->run(
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
