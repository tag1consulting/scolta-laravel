<?php

declare(strict_types=1);

namespace Workbench\Database\Seeders;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Database\Seeder;
use RuntimeException;
use Workbench\App\Models\Recipe;

/**
 * Seeds the 20 recipe fixtures from scolta-php's test suite.
 *
 * The fixtures live in vendor/tag1/scolta-php/tests/fixtures/recipes/.
 * They are only present when scolta-php is installed from source — this
 * repo's composer.json sets preferred-install to "source" for that package,
 * because the dist archive export-ignores /tests.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $dir = base_path('vendor/tag1/scolta-php/tests/fixtures/recipes');

        if (! is_dir($dir)) {
            throw new RuntimeException(
                "Recipe fixtures not found at {$dir}. scolta-php must be installed ".
                'from source (composer.json sets preferred-install for it). Run: '.
                'composer reinstall tag1/scolta-php'
            );
        }

        Recipe::query()->delete();

        foreach (glob($dir.'/*.html') as $file) {
            Recipe::create($this->parseFixture($file));
        }
    }

    /**
     * Extract model attributes from one fixture HTML page.
     *
     * The fixtures carry their own metadata as data-pagefind-meta and
     * data-pagefind-filter paragraphs; those become columns, and the
     * remaining body markup becomes body_html.
     *
     * @return array<string, mixed>
     */
    private function parseFixture(string $file): array
    {
        $doc = new DOMDocument;
        $doc->loadHTMLFile($file, LIBXML_NOERROR);
        $xpath = new DOMXPath($doc);

        $title = trim($xpath->evaluate('string(//title)'));

        $meta = [];
        foreach ($xpath->query('//*[@data-pagefind-meta]') as $node) {
            [$key, $value] = explode(':', $node->getAttribute('data-pagefind-meta'), 2);
            $meta[$key] = $value;
        }

        $filters = [];
        foreach ($xpath->query('//*[@data-pagefind-filter]') as $node) {
            [$key, $value] = explode(':', $node->getAttribute('data-pagefind-filter'), 2);
            $filters[$key] = $value;
        }

        // Body: everything inside <body> except the h1 (rendered by the view
        // from the title column) and the hidden meta/filter paragraphs.
        $body = $xpath->query('//body')->item(0);
        if ($body === null) {
            throw new RuntimeException("Fixture {$file} has no <body> element.");
        }
        $html = '';
        foreach (iterator_to_array($body->childNodes) as $child) {
            if ($child instanceof DOMElement && (
                $child->tagName === 'h1'
                || $child->hasAttribute('data-pagefind-meta')
                || $child->hasAttribute('data-pagefind-filter')
            )) {
                continue;
            }
            $html .= $doc->saveHTML($child);
        }

        $url = $meta['url'] ?? '';
        $slug = basename($url) !== '' ? basename($url) : pathinfo($file, PATHINFO_FILENAME);

        return [
            'title' => $title,
            'slug' => $slug,
            'body_html' => trim($html),
            'cuisine' => $filters['cuisine'] ?? null,
            'diet' => $filters['diet'] ?? null,
            'cook_time' => isset($meta['cook_time']) ? (int) $meta['cook_time'] : null,
            'cook_time_bucket' => $filters['cook_time_bucket'] ?? null,
            'published_at' => $meta['date'] ?? '2024-01-01',
        ];
    }
}
