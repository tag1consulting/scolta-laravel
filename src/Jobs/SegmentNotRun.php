<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Jobs;

/**
 * Thrown out of the resume chain's child runner when no segment ran here.
 *
 * The two expected cases — no artisan binary to spawn, or a child that
 * handed finalize to the queue — are control flow, not failures, and are the
 * only exceptions TriggerRebuild::chain() catches. Anything else is a bug and
 * fails the job.
 *
 * @since 1.4.0
 *
 * @stability experimental
 */
final class SegmentNotRun extends \RuntimeException {}
