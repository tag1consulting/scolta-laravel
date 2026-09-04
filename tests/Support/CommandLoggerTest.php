<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Tests\Support;

use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tag1\ScoltaLaravel\Support\CommandLogger;

/**
 * CommandLogger fans one message out to the terminal and the app log.
 *
 * `RetiredIndexTrash::sweep()` announces a deletion that can run for minutes
 * before it starts, so the wait is not read as a hang — worthless in
 * `storage/logs/` alone, and worthless on a scheduled run's discarded stdout.
 * Plain PHPUnit: only a Command with an output attached is needed.
 */
class CommandLoggerTest extends TestCase
{
    private function makeCommand(BufferedOutput $buffer): Command
    {
        $command = new class extends Command
        {
            protected $signature = 'scolta:test-logger';
        };

        $command->setOutput(new OutputStyle(new ArrayInput([]), $buffer));

        return $command;
    }

    /**
     * @return array{0: CommandLogger, 1: BufferedOutput, 2: object}
     */
    private function makeLogger(): array
    {
        $buffer = new BufferedOutput;

        $recorder = new class extends AbstractLogger
        {
            /** @var list<array{level: mixed, message: string, context: array<string, mixed>}> */
            public array $records = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
            }
        };

        return [new CommandLogger($this->makeCommand($buffer), $recorder), $buffer, $recorder];
    }

    public function test_it_writes_to_both_destinations(): void
    {
        [$logger, $buffer, $recorder] = $this->makeLogger();

        $logger->notice('[scolta] Deleting retired index directories.');

        $this->assertStringContainsString('Deleting retired index directories.', $buffer->fetch());
        $this->assertCount(1, $recorder->records);
        $this->assertSame(LogLevel::NOTICE, $recorder->records[0]['level']);
    }

    /**
     * The console copy is rendered; the log copy keeps the template and the
     * context, which is what structured log handlers want.
     */
    public function test_it_interpolates_placeholders_for_the_console_only(): void
    {
        [$logger, $buffer, $recorder] = $this->makeLogger();

        $logger->warning('Could not delete {dir}; retrying on the next sweep.', ['dir' => '/tmp/.scolta-trash-1']);

        $this->assertStringContainsString('Could not delete /tmp/.scolta-trash-1;', $buffer->fetch());
        $this->assertSame('Could not delete {dir}; retrying on the next sweep.', $recorder->records[0]['message']);
        $this->assertSame(['dir' => '/tmp/.scolta-trash-1'], $recorder->records[0]['context']);
    }

    public function test_debug_is_log_only(): void
    {
        [$logger, $buffer, $recorder] = $this->makeLogger();

        $logger->debug('Internal detail nobody asked for.');

        $this->assertSame('', $buffer->fetch());
        $this->assertCount(1, $recorder->records);
    }
}
