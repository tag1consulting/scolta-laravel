<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Tests\Progress;

use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Tag1\ScoltaLaravel\Progress\LoggingProgressReporter;

class LoggingProgressReporterTest extends TestCase
{
    public function test_logs_one_notice_per_callback(): void
    {
        $logger = new class extends AbstractLogger
        {
            /** @var list<string> */
            public array $lines = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->lines[] = "{$level}: {$message}";
            }
        };

        $reporter = new LoggingProgressReporter($logger);
        $reporter->start(400, 'Indexing');
        $reporter->advance(212);
        $reporter->advance(1, 'Chunk 213 (21300 pages)');
        $reporter->finish('40000 pages indexed');
        $reporter->finish();

        $this->assertSame([
            'notice: Indexing: 400 chunks to build.',
            'notice: Progress 212/400 chunks (53%)',
            'notice: Progress 213/400 chunks (53%) Chunk 213 (21300 pages)',
            'notice: Finished: 40000 pages indexed',
        ], $logger->lines);
    }
}
