<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Support;

use Illuminate\Console\Command;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * PSR-3 logger that writes to an Artisan command's output and the app log.
 *
 * scolta-php services take a `LoggerInterface`, and `RetiredIndexTrash::sweep()`
 * announces a deletion that can take minutes on NFS precisely so the wait is
 * not mistaken for a hang. `storage/logs/` alone hides that from whoever is
 * watching the terminal; console output alone loses it on a scheduled run,
 * whose stdout goes nowhere. This writes both. `debug` is log-only.
 *
 * @since 1.4.0
 *
 * @stability experimental
 */
final class CommandLogger extends AbstractLogger
{
    /**
     * @param  Command  $command  Receives notice/info/warning/error lines.
     * @param  LoggerInterface  $appLog  Receives everything, at every level.
     *
     * @since 1.4.0
     *
     * @stability experimental
     */
    public function __construct(
        private readonly Command $command,
        private readonly LoggerInterface $appLog,
    ) {}

    /**
     * Record one message on both destinations.
     *
     * @param  mixed  $level  A PSR-3 log level.
     * @param  array<string, mixed>  $context
     *
     * @since 1.4.0
     *
     * @stability experimental
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $message = (string) $message;

        $this->appLog->log($level, $message, $context);

        $rendered = self::interpolate($message, $context);

        match ((string) $level) {
            LogLevel::EMERGENCY,
            LogLevel::ALERT,
            LogLevel::CRITICAL,
            LogLevel::ERROR => $this->command->error($rendered),
            LogLevel::WARNING => $this->command->warn($rendered),
            LogLevel::DEBUG => null,
            default => $this->command->line($rendered),
        };
    }

    /**
     * Substitute `{placeholder}` tokens with their context values.
     *
     * The PSR-3 reference interpolation: only stringable values are
     * substituted, and an unmatched placeholder is left alone rather than
     * blanked.
     *
     * @param  array<string, mixed>  $context
     */
    private static function interpolate(string $message, array $context): string
    {
        $replacements = [];

        foreach ($context as $key => $value) {
            if ($value === null || is_scalar($value) || $value instanceof \Stringable) {
                $replacements['{'.$key.'}'] = (string) $value;
            }
        }

        return strtr($message, $replacements);
    }
}
