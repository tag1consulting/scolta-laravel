<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Support;

/**
 * Resolves which indexer backend is in effect.
 *
 * One place for the rule, so `scolta:build` and `scolta:status` cannot name
 * different backends.
 *
 * @since 1.4.0
 *
 * @stability experimental
 */
final class IndexerResolver
{
    /**
     * Resolve the effective indexer backend.
     *
     * Priority: the `--indexer` CLI option > `config('scolta.indexer')` >
     * `'auto'`, where `auto` means the PHP indexer — it needs no exec() or
     * Node.js. Any other value is returned as-is so the caller can reject it
     * with an explicit error rather than silently select a different pipeline.
     *
     * @param  string|null  $option  The `--indexer` CLI option, if any.
     * @param  string|null  $configured  The configured value, if any.
     *
     * @since 1.4.0
     *
     * @stability experimental
     */
    public static function resolve(?string $option = null, ?string $configured = null): string
    {
        $indexer = $option;
        if (empty($indexer)) {
            $indexer = config('scolta.indexer', $configured);
        }

        if (empty($indexer)) {
            $indexer = 'auto';
        }

        if ($indexer === 'auto') {
            return 'php';
        }

        return (string) $indexer;
    }
}
