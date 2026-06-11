<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Services;

use Tag1\Scolta\Export\ContentItem;

/**
 * JSON round-trip for ContentItem arrays.
 *
 * Used by the queue rebuild pipeline to persist chunk payloads as files
 * in the state directory instead of embedding the full corpus in queue
 * job payloads (which blows up RAM on dispatch and exceeds queue-driver
 * payload caps such as SQS's 256 KB).
 *
 * @since 1.0.4
 *
 * @stability experimental
 */
final class ContentItemCodec
{
    /**
     * Encode ContentItems as a JSON document.
     *
     * @param  ContentItem[]  $items
     *
     * @since 1.0.4
     *
     * @stability experimental
     */
    public static function encode(array $items): string
    {
        $rows = array_map(fn (ContentItem $item): array => [
            'id' => $item->id,
            'title' => $item->title,
            'bodyHtml' => $item->bodyHtml,
            'url' => $item->url,
            'date' => $item->date,
            'siteName' => $item->siteName,
            'language' => $item->language,
            'filters' => $item->filters,
            'metadata' => $item->metadata,
            'sortable' => $item->sortable,
        ], $items);

        return json_encode($rows, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Decode a JSON document produced by encode() back into ContentItems.
     *
     * @return ContentItem[]
     *
     * @since 1.0.4
     *
     * @stability experimental
     */
    public static function decode(string $json): array
    {
        $rows = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return array_map(fn (array $row): ContentItem => new ContentItem(
            id: (string) $row['id'],
            title: (string) $row['title'],
            bodyHtml: (string) $row['bodyHtml'],
            url: (string) $row['url'],
            date: (string) $row['date'],
            siteName: (string) ($row['siteName'] ?? ''),
            language: (string) ($row['language'] ?? 'en'),
            filters: (array) ($row['filters'] ?? []),
            metadata: (array) ($row['metadata'] ?? []),
            sortable: (array) ($row['sortable'] ?? []),
        ), $rows);
    }
}
