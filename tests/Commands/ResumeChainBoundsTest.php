<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Tests\Commands;

use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Orchestra\Testbench\TestCase;
use ReflectionMethod;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tag1\Scolta\Index\StatusReport;
use Tag1\ScoltaLaravel\Commands\BuildCommand;
use Tag1\ScoltaLaravel\ScoltaServiceProvider;
use Tag1\ScoltaLaravel\Services\ResumeChain;

/**
 * A memory-aborted build may continue itself, in the foreground, but not forever.
 *
 * Two defects are pinned here. The chain used to be unbounded: the only thing
 * that ended it was a segment reporting `chunksWritten === 0`, which on a resume
 * cannot happen — `IndexBuildOrchestrator` fills that field from the chunk files
 * on disk, which are cumulative for the whole build and are exactly what resume
 * relies on persisting. And the chain used to be detached: `scolta:build`
 * launched its successor in the background and returned SUCCESS, so it exited 0
 * while the index was demonstrably not built — the false success the DEFERRED
 * exit code exists to avoid everywhere else in this command.
 *
 * One process now owns the chain. It runs each segment in the foreground and
 * reads its exit code, so the tests below drive the real loop: they substitute
 * only `ResumeChain::runSegment()` — the boundary that starts a child — with a
 * scripted segment that writes the build manifest and the outcome file a real
 * one would leave behind, then assert on where the parent stopped and why.
 *
 * None of them reads the source file: an assertion that BuildCommand.php
 * contains the string "chunksWritten > 0" passed while the chain was unbounded,
 * and would pass again with the guard inverted.
 */
class ResumeChainBoundsTest extends TestCase
{
    private string $stateDir;

    private string $outputDir;

    private ChainRecorder $recorder;

    protected function getPackageProviders($app): array
    {
        return [ScoltaServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->stateDir = storage_path('framework/testing/scolta-resume-chain-state');
        $this->outputDir = storage_path('framework/testing/scolta-resume-chain-output');
        File::deleteDirectory($this->stateDir);
        File::deleteDirectory($this->outputDir);
        File::ensureDirectoryExists($this->stateDir);
        config(['scolta.state_dir' => $this->stateDir]);

        $this->recorder = new ChainRecorder($this->stateDir);

        // The child-run boundary, substituted. ScriptedResumeChain keeps the real
        // failureReason() — the decision under test — and replaces only the one
        // method that starts a process.
        $recorder = $this->recorder;
        $this->app->bind(
            ResumeChain::class,
            fn ($app, array $parameters) => new ScriptedResumeChain(
                $parameters['memoryLimit'] ?? null,
                $recorder,
            ),
        );
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->stateDir);
        File::deleteDirectory($this->outputDir);

        parent::tearDown();
    }

    // -------------------------------------------------------------------
    // The reproducer: a segment that committed nothing.
    // -------------------------------------------------------------------

    public function test_a_build_that_committed_nothing_does_not_run_a_successor(): void
    {
        // The exact shape the old guard let through: seven chunk files on disk
        // from earlier work, so the report's cumulative chunksWritten is 7,
        // while the page count moved not at all.
        $this->recorder->commit(4200);

        $exit = $this->answerMemoryAbort(pagesBefore: 4200);

        $this->assertSame(Command::FAILURE, $exit, 'A stalled build must fail, not report success');
        $this->assertSame([], $this->recorder->segments, 'A build that committed nothing must run no successor');
    }

    public function test_the_stall_message_names_the_limit_and_the_way_out(): void
    {
        $this->recorder->commit(4200);
        $this->answerMemoryAbort(pagesBefore: 4200);
        $message = $this->consoleOutput();

        $this->assertStringContainsString('stalled at 4200 pages', $message);
        $this->assertStringContainsString('this build', $message);
        $this->assertStringContainsString('has not been republished', $message);
        $this->assertStringContainsString('memory_limit', $message);
        $this->assertStringContainsString('--memory-budget', $message);
        $this->assertStringContainsString('--restart', $message);
    }

    public function test_a_child_segment_that_commits_nothing_ends_the_chain(): void
    {
        // Segment 0 (this process) made progress, so segment 1 runs — and stalls.
        $this->recorder->commit(100);
        $this->recorder->behaviour = function (int $segment): int {
            $this->recorder->commit(100);          // unchanged: no progress
            $this->recorder->recordOutcome(StatusReport::MEMORY_ABORT);

            return Command::FAILURE;
        };

        $exit = $this->answerMemoryAbort(pagesBefore: 0);

        $this->assertSame(Command::FAILURE, $exit);
        $this->assertSame([1], $this->recorder->segments, 'Exactly one successor, and no successor for it');
        $this->assertStringContainsString('resume segment 1', $this->consoleOutput());
        $this->assertStringContainsString('stalled at 100 pages', $this->consoleOutput());
    }

    // -------------------------------------------------------------------
    // The cap, driven as an actual chain.
    // -------------------------------------------------------------------

    public function test_a_chain_that_keeps_progressing_still_stops_at_the_segment_cap(): void
    {
        // Progress every segment, which is the case the progress check alone
        // cannot end: a corpus gaining a hundred pages per process would satisfy
        // it indefinitely. Nothing in this test counts segments — the loop is the
        // command's, and it terminates only when the command stops running them.
        $this->recorder->commit(100);
        $this->recorder->behaviour = function (int $segment): int {
            $this->recorder->commit(100 + $segment * 100);
            $this->recorder->recordOutcome(StatusReport::MEMORY_ABORT);

            return Command::FAILURE;
        };

        $exit = $this->answerMemoryAbort(pagesBefore: 0);

        $this->assertSame(Command::FAILURE, $exit, 'An exhausted chain has not built an index');
        $this->assertSame(
            range(1, ResumeChain::MAX_SEGMENTS),
            $this->recorder->segments,
            'A build must use at most MAX_SEGMENTS fresh processes, each once and consecutively',
        );

        $message = $this->consoleOutput();
        $this->assertStringContainsString('did not complete within '.ResumeChain::MAX_SEGMENTS.' resume segments', $message);
        $this->assertStringContainsString(
            (100 + ResumeChain::MAX_SEGMENTS * 100).' pages committed',
            $message,
            'The operator has to be told how far the build actually got',
        );
        $this->assertStringContainsString('has not been republished', $message);
    }

    // -------------------------------------------------------------------
    // Classification: what the segment recorded, not what it exited with.
    // -------------------------------------------------------------------

    public function test_a_recorded_non_memory_error_stops_the_chain_immediately(): void
    {
        // This segment progressed, so the progress check would happily run
        // another. It also found the page table corrupt, and every later segment
        // would re-walk the corpus to reach the same merge failure.
        $this->recorder->commit(100);
        $this->recorder->behaviour = function (int $segment): int {
            $this->recorder->commit(100 + $segment * 100);
            $this->recorder->recordOutcome('Duplicate page ordinal 41 across chunks');

            return Command::FAILURE;
        };

        $exit = $this->answerMemoryAbort(pagesBefore: 0);

        $this->assertSame(Command::FAILURE, $exit);
        $this->assertSame([1], $this->recorder->segments, 'A broken build must not be resumed');

        $message = $this->consoleOutput();
        $this->assertStringContainsString('failed in resume segment 1', $message);
        $this->assertStringContainsString('Duplicate page ordinal 41 across chunks', $message);
        $this->assertStringContainsString('has not been republished', $message);
    }

    public function test_a_segment_that_recorded_success_and_exited_non_zero_stops_the_chain(): void
    {
        // Its build returned, then publishing or verification failed. Nothing is
        // left for a resume to carry forward.
        $this->recorder->commit(100);
        $this->recorder->behaviour = function (int $segment): int {
            $this->recorder->commit(100 + $segment * 100);
            $this->recorder->recordOutcome(null, success: true);

            return Command::FAILURE;
        };

        $exit = $this->answerMemoryAbort(pagesBefore: 0);

        $this->assertSame(Command::FAILURE, $exit);
        $this->assertSame([1], $this->recorder->segments);
        $this->assertStringContainsString('exited non-zero', $this->consoleOutput());
    }

    public function test_the_outcome_file_is_cleared_before_every_segment(): void
    {
        // A stale verdict from an earlier build is on disk, and the segments that
        // follow are killed outright (an OOM kill, a fatal) so they record
        // nothing of their own. Read uncleared, that stale error would stop the
        // chain at segment 1 and report a failure that never happened.
        File::put($this->stateDir.'/segment-outcome.json', (string) json_encode([
            'success' => false,
            'error' => 'Duplicate page ordinal 41 across chunks',
            'pages_processed' => 12,
        ]));

        $this->recorder->commit(100);
        $this->recorder->behaviour = function (int $segment): int {
            $this->recorder->commit(100 + $segment * 100);

            return Command::FAILURE;      // killed: records nothing
        };

        $this->answerMemoryAbort(pagesBefore: 0);

        $this->assertSame(
            range(1, ResumeChain::MAX_SEGMENTS),
            $this->recorder->segments,
            'A stale outcome must not be mistaken for this segment\'s verdict',
        );
        $this->assertStringNotContainsString('Duplicate page ordinal', $this->consoleOutput());
        $this->assertSame(
            array_fill(0, ResumeChain::MAX_SEGMENTS, null),
            $this->recorder->outcomeSeenAtStart,
            'Every segment must start with no outcome on disk',
        );
    }

    // -------------------------------------------------------------------
    // Exit codes have to tell the truth about the index.
    // -------------------------------------------------------------------

    public function test_a_segment_that_finishes_the_build_ends_the_chain_with_success(): void
    {
        $this->recorder->commit(100);
        $this->recorder->behaviour = function (int $segment): int {
            $this->recorder->commit(200);
            $this->publishIndex();

            return Command::SUCCESS;
        };

        $exit = $this->answerMemoryAbort(pagesBefore: 0);

        $this->assertSame(Command::SUCCESS, $exit);
        $this->assertSame([1], $this->recorder->segments, 'A finished build must not run another segment');
        $this->assertStringContainsString('Index built across 1 resume segment', $this->consoleOutput());
    }

    public function test_a_segment_that_exits_zero_without_publishing_is_not_a_success(): void
    {
        // SUCCESS from the driver has to mean the index is live, so the child's
        // word for it is verified against what is on disk.
        $this->recorder->commit(100);
        $this->recorder->behaviour = function (int $segment): int {
            $this->recorder->commit(200);

            return Command::SUCCESS;
        };

        $exit = $this->answerMemoryAbort(pagesBefore: 0);

        $this->assertSame(Command::FAILURE, $exit);
        $this->assertStringContainsString('no usable index was published', $this->consoleOutput());
    }

    public function test_a_segment_that_defers_finalize_propagates_deferred(): void
    {
        // Every page indexed, the merge handed to a queue worker: the chain is
        // over and nothing failed, but the index is not published — which is
        // precisely what DEFERRED means everywhere else in this command.
        $this->recorder->commit(100);
        $this->recorder->behaviour = function (int $segment): int {
            $this->recorder->commit(200);

            return BuildCommand::DEFERRED;
        };

        $exit = $this->answerMemoryAbort(pagesBefore: 0);

        $this->assertSame(BuildCommand::DEFERRED, $exit);
        $this->assertSame([1], $this->recorder->segments);
        $this->assertStringContainsString('Index NOT yet published', $this->consoleOutput());
    }

    public function test_a_chain_with_no_artisan_binary_fails_rather_than_reporting_success(): void
    {
        $this->recorder->commit(100);
        $this->recorder->behaviour = fn (int $segment): ?int => null;

        $exit = $this->answerMemoryAbort(pagesBefore: 0);

        $this->assertSame(Command::FAILURE, $exit);
        $this->assertStringContainsString('artisan not found', $this->consoleOutput());
    }

    // -------------------------------------------------------------------
    // A segment must not start a chain of its own.
    // -------------------------------------------------------------------

    public function test_a_resume_segment_reports_and_returns_instead_of_nesting_a_chain(): void
    {
        // Invoked with --resume, this process is a segment of a chain another
        // process is driving. It progressed, so nothing here bars a successor
        // except the rule that the driver owns them.
        $this->recorder->commit(900);

        $exit = $this->answerMemoryAbort(pagesBefore: 0, resume: true);

        $this->assertSame(Command::FAILURE, $exit, 'A segment reports its yield; it is not a success');
        $this->assertSame([], $this->recorder->segments, 'A segment must not run segments of its own');
        $this->assertStringContainsString('Memory limit reached after 900 pages', $this->consoleOutput());
        $this->assertStringContainsString('--resume', $this->consoleOutput());
    }

    // -------------------------------------------------------------------
    // What the real boundary puts on the child's command line.
    // -------------------------------------------------------------------

    public function test_the_real_segment_runs_a_fresh_resume_child(): void
    {
        Process::fake();

        $exit = (new ResumeChain('512M'))->runSegment('conservative', '25', force: false);

        $this->assertNotNull($exit, 'Testbench ships an artisan binary, so the segment must run');
        $child = $this->childCommand();

        $this->assertStringContainsString(PHP_BINARY, $child, 'The segment needs its own process and a clean heap');
        $this->assertStringContainsString('scolta:build', $child);
        $this->assertStringContainsString('--resume', $child);
        $this->assertStringContainsString('--indexer=php', $child);
        $this->assertStringContainsString('--memory-budget=conservative', $child);
        $this->assertStringContainsString('--chunk-size=25', $child);
    }

    public function test_an_unforced_build_does_not_force_its_segments(): void
    {
        Process::fake();

        (new ResumeChain('512M'))->runSegment('conservative', null, force: false);

        $this->assertStringNotContainsString('--force', $this->childCommand(),
            'A build nobody forced must not gain --force by being segmented');
    }

    public function test_a_forced_build_forces_every_later_segment(): void
    {
        // --force is what makes IndexBuildOrchestrator skip its token-cache
        // lookup and re-tokenize from source. Dropped at the hand-off, a forced
        // build big enough to segment re-tokenizes its head and serves its tail
        // from the cache it was told to bypass.
        Process::fake();

        (new ResumeChain('512M'))->runSegment('conservative', null, force: true);

        $this->assertStringContainsString('--force', $this->childCommand());
    }

    public function test_the_command_hands_its_own_flags_to_every_segment(): void
    {
        // The plumbing between the operator's flags and the arguments above.
        $this->recorder->commit(100);
        $this->recorder->behaviour = function (int $segment): int {
            $this->recorder->commit(100 + $segment * 100);

            return $segment < 3 ? Command::FAILURE : BuildCommand::DEFERRED;
        };

        $this->answerMemoryAbort(pagesBefore: 0, force: true);

        $this->assertSame([true, true, true], $this->recorder->forced,
            'Every segment of a forced build must be forced');
        $this->assertSame(
            [['conservative', '25'], ['conservative', '25'], ['conservative', '25']],
            $this->recorder->budgets,
            'The memory budget and chunk size must travel with every segment',
        );
    }

    // -------------------------------------------------------------------
    // Harness.
    // -------------------------------------------------------------------

    private BufferedOutput $buffer;

    /**
     * Drive BuildCommand's own answer to a memory abort in this process.
     *
     * Everything downstream — the segment counter, the progress comparison, the
     * outcome clearing and reading, the loop itself — is the production code's.
     * The only substitution is the process the parent would have started.
     */
    private function answerMemoryAbort(int $pagesBefore, bool $force = false, bool $resume = false): int
    {
        $command = new BuildCommand;
        $command->setLaravel($this->app);

        $input = new ArrayInput(
            ($force ? ['--force' => true] : []) + ($resume ? ['--resume' => true] : []),
            $command->getDefinition(),
        );
        $this->buffer = new BufferedOutput;
        $style = new OutputStyle($input, $this->buffer);

        (function () use ($input, $style) {
            $this->input = $input;
            $this->output = $style;
        })->call($command);

        $method = new ReflectionMethod($command, 'answerMemoryAbort');

        return (int) $method->invoke(
            $command,
            $this->stateDir,
            $this->outputDir,
            $pagesBefore,
            'conservative',
            '25',
        );
    }

    /**
     * Put a usable Pagefind index where a finished chain must have published one.
     */
    private function publishIndex(): void
    {
        File::ensureDirectoryExists($this->outputDir.'/pagefind');
        File::put(
            $this->outputDir.'/pagefind/pagefind-entry.json',
            (string) json_encode(['version' => '1.0.0', 'languages' => ['en' => []]]),
        );
    }

    private string $console = '';

    /**
     * Everything the command printed. fetch() drains, so it is accumulated here
     * and the same text is returned however often a test asks for it.
     */
    private function consoleOutput(): string
    {
        $this->console .= $this->buffer->fetch();

        return $this->console;
    }

    /**
     * The command line of the last process the real ResumeChain ran.
     */
    private function childCommand(): string
    {
        $captured = null;
        Process::assertRan(function ($process) use (&$captured) {
            $captured = is_array($process->command)
                ? implode(' ', $process->command)
                : (string) $process->command;

            return true;
        });

        return (string) $captured;
    }
}

/**
 * The scripted child process, and the record of what the driver asked of it.
 */
class ChainRecorder
{
    /** @var list<int> Position of each segment the driver ran, in order. */
    public array $segments = [];

    /** @var list<bool> Whether each of those segments was asked to force. */
    public array $forced = [];

    /** @var list<array{0: string|null, 1: string|null}> The budget flags each segment carried. */
    public array $budgets = [];

    /** @var list<array<string, mixed>|null> The outcome on disk when each segment started. */
    public array $outcomeSeenAtStart = [];

    /** @var (callable(int): (int|null))|null What the scripted segment does and returns. */
    public $behaviour = null;

    public function __construct(private readonly string $stateDir) {}

    /**
     * Stand in for the child process the driver would have started.
     */
    public function runSegment(?string $memoryBudget, ?string $chunkSize, bool $force): ?int
    {
        $segment = count($this->segments) + 1;
        if ($segment > ResumeChain::MAX_SEGMENTS + 10) {
            // A driver with no working bound would otherwise hang the suite
            // instead of failing it.
            throw new \RuntimeException('The chain ran away: more segments than any bound allows.');
        }

        $this->segments[] = $segment;
        $this->forced[] = $force;
        $this->budgets[] = [$memoryBudget, $chunkSize];
        $this->outcomeSeenAtStart[] = $this->outcomeOnDisk();

        return $this->behaviour === null ? 1 : ($this->behaviour)($segment);
    }

    /**
     * Write the build manifest a segment that committed $pages would leave.
     */
    public function commit(int $pages): void
    {
        File::put($this->stateDir.'/manifest.json', (string) json_encode([
            'version' => '1.0.0',
            'status' => 'building',
            'pages_processed' => $pages,
            // Cumulative across the whole build, which is the field the removed
            // guard mistook for per-segment progress.
            'chunks_written' => 7,
        ]));
    }

    /**
     * Write the outcome file a segment reporting $error would leave.
     */
    public function recordOutcome(?string $error, bool $success = false): void
    {
        File::put($this->stateDir.'/segment-outcome.json', (string) json_encode([
            'success' => $success,
            'error' => $error,
            'pages_processed' => 0,
        ]));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function outcomeOnDisk(): ?array
    {
        $path = $this->stateDir.'/segment-outcome.json';
        if (! File::exists($path)) {
            return null;
        }

        $decoded = json_decode(File::get($path), true);

        return is_array($decoded) ? $decoded : null;
    }
}

/**
 * A ResumeChain whose child process is scripted instead of started.
 *
 * failureReason() is inherited untouched: the decision is what is under test.
 */
class ScriptedResumeChain extends ResumeChain
{
    public function __construct(?string $memoryLimit, private readonly ChainRecorder $recorder)
    {
        parent::__construct($memoryLimit ?? '128M');
    }

    public function runSegment(?string $memoryBudget, ?string $chunkSize, bool $force, ?callable $onOutput = null): ?int
    {
        return $this->recorder->runSegment($memoryBudget, $chunkSize, $force);
    }
}
