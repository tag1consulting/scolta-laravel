<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Services;

use Illuminate\Console\Command;
use Tag1\Scolta\Export\ContentExporter;

/**
 * Remove the exported HTML of tracked deletions, and say what could not be.
 *
 * The one implementation of the deletion sweep on the HTML-export pipeline;
 * `scolta:export` and `scolta:build --indexer=binary` each used to run their own
 * copy that discarded `deleteById()`'s return value and printed rows attempted
 * as files removed. On the binary pipeline a file left behind is a file Pagefind
 * re-indexes on the next run, so every failure is reported instead.
 *
 * @since 1.4.0
 *
 * @stability experimental
 */
class ExportDeletions
{
    public function __construct(private readonly ContentSource $source) {}

    /**
     * Delete the exported file for every pending deletion, reporting failures.
     *
     * Call only on an incremental run: a full build calls prepareOutputDir()
     * first, so there is nothing left to delete and sweeping would report every
     * pending row as a file it could not find.
     *
     * Two kinds of failure, both printed as warnings:
     *
     *  - `unresolved` — the tracker row cannot be mapped to an item id at all.
     *    See ContentSource::getDeletedItemIds(). The caller must treat a
     *    non-empty list as a reason to run the whole export instead: the page it
     *    names is still on disk, and Pagefind will index it again. This is the
     *    rule scolta-drupal applies to a queue payload that does not name what
     *    changed ("correct but slow — never wrong") and the one
     *    BuildCommand::updateIncrementally() applies on the PHP indexer.
     *  - `missing` — the id resolved, but neither the export manifest nor the
     *    legacy flat `{id}.html` path names a file that exists. Usually benign,
     *    and not distinguishable from the case that is not, so it is reported and
     *    not escalated.
     *
     * @return array{removed: int, missing: list<string>, unresolved: list<string>}
     *
     * @since 1.4.0
     *
     * @stability experimental
     */
    public function sweep(ContentExporter $exporter, Command $command): array
    {
        $deleted = $this->source->getDeletedItemIds();

        $removed = 0;
        $missing = [];

        foreach ($deleted['ids'] as $id) {
            // The return value is the whole point of this class: deleteById()
            // looks the id up in a manifest keyed by ContentItem::$id, and the
            // bare Eloquent key this used to be handed never matched.
            if ($exporter->deleteById($id)) {
                $removed++;
            } else {
                $missing[] = $id;
            }
        }

        if ($removed > 0) {
            $command->info(sprintf('  Removed %d deleted item(s) from the export.', $removed));
        }

        if ($missing !== []) {
            $message = sprintf(
                '%d tracked deletion(s) resolved to an item id with no exported file to remove (%s). '
                .'Either they were never exported, or the export manifest no longer names them; '
                .'anything still on disk for them will be re-indexed.',
                count($missing),
                self::sample($missing),
            );
            $command->warn('  '.$message);
            // Also to the application log: these commands run from a scheduler
            // as often as from a terminal, and there the console is discarded.
            // Same reason CommandLogger writes to both.
            logger()->warning('[scolta] '.$message);
        }

        if ($deleted['unresolved'] !== []) {
            $message = sprintf(
                '%d tracked deletion(s) name records that are gone and carry no recorded item id (%s), '
                .'so the exported page each one owns cannot be located and would be indexed again. '
                .'Falling back to a full run.',
                count($deleted['unresolved']),
                self::sample($deleted['unresolved']),
            );
            $command->warn('  '.$message);
            logger()->warning('[scolta] '.$message);
            // Its own line, short enough to survive console wrapping: this is
            // the sentence that tells the operator the run changed shape.
            $command->warn('  Falling back to a full run; those pages go by not being exported again.');
        }

        return [
            'removed' => $removed,
            'missing' => $missing,
            'unresolved' => $deleted['unresolved'],
        ];
    }

    /**
     * The first few of a list, for a console message that must stay one line.
     *
     * @param  list<string>  $values
     */
    private static function sample(array $values): string
    {
        return implode(', ', array_slice($values, 0, 3)).(count($values) > 3 ? ', …' : '');
    }
}
