<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Tests;

use Carbon\Carbon;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\TestCase;
use Tag1\Scolta\Export\ContentItem;
use Tag1\ScoltaLaravel\Searchable;

/**
 * Functional tests for the Searchable trait's default toSearchableContent().
 *
 * These tests exercise the column-heuristic logic introduced in 0.3.0:
 * title → name → subject, body → content → description, date fallbacks.
 *
 * Each test uses a FakeModel stub that simulates Eloquent property access
 * without requiring a real database connection.
 */
class SearchableDefaultsTest extends TestCase
{
    protected function setUp(): void
    {
        // Minimal Laravel container so config() helper resolves.
        $app = new Application(dirname(__DIR__));
        Container::setInstance($app);
        $app->instance('config', new ConfigRepository([
            'scolta' => ['site_name' => 'Scolta Test'],
            'app' => ['name' => 'Laravel Test App'],
        ]));
    }

    protected function tearDown(): void
    {
        Container::setInstance(null);
    }

    // -------------------------------------------------------------------
    // Title column resolution: title → name → subject → ''
    // -------------------------------------------------------------------

    public function test_title_column_used_when_present(): void
    {
        $model = new FakeSearchableModel(['title' => 'My Post Title']);
        $item = $model->toSearchableContent();

        $this->assertInstanceOf(ContentItem::class, $item);
        $this->assertEquals('My Post Title', $item->title);
    }

    public function test_name_column_used_when_title_absent(): void
    {
        $model = new FakeSearchableModel(['name' => 'Product Name']);
        $item = $model->toSearchableContent();

        $this->assertEquals('Product Name', $item->title);
    }

    public function test_subject_column_used_when_title_and_name_absent(): void
    {
        $model = new FakeSearchableModel(['subject' => 'Email Subject']);
        $item = $model->toSearchableContent();

        $this->assertEquals('Email Subject', $item->title);
    }

    public function test_title_takes_precedence_over_name(): void
    {
        $model = new FakeSearchableModel(['title' => 'The Title', 'name' => 'The Name']);
        $item = $model->toSearchableContent();

        $this->assertEquals('The Title', $item->title);
    }

    public function test_name_takes_precedence_over_subject(): void
    {
        $model = new FakeSearchableModel(['name' => 'Named', 'subject' => 'Subjected']);
        $item = $model->toSearchableContent();

        $this->assertEquals('Named', $item->title);
    }

    public function test_empty_title_when_no_title_columns(): void
    {
        $model = new FakeSearchableModel(['slug' => 'my-post', 'status' => 'published']);
        $item = $model->toSearchableContent();

        $this->assertEquals('', $item->title);
    }

    // -------------------------------------------------------------------
    // Body column resolution: body → content → description → ''
    // -------------------------------------------------------------------

    public function test_body_column_wrapped_in_paragraph_tag(): void
    {
        $model = new FakeSearchableModel(['title' => 'T', 'body' => 'Body text here.']);
        $item = $model->toSearchableContent();

        $this->assertStringContainsString('Body text here.', $item->bodyHtml);
        $this->assertStringStartsWith('<p>', $item->bodyHtml);
        $this->assertStringEndsWith('</p>', $item->bodyHtml);
    }

    public function test_content_column_used_when_body_absent(): void
    {
        $model = new FakeSearchableModel(['content' => 'Content text.']);
        $item = $model->toSearchableContent();

        $this->assertStringContainsString('Content text.', $item->bodyHtml);
    }

    public function test_description_column_used_when_body_and_content_absent(): void
    {
        $model = new FakeSearchableModel(['description' => 'A description.']);
        $item = $model->toSearchableContent();

        $this->assertStringContainsString('A description.', $item->bodyHtml);
    }

    public function test_empty_body_html_when_no_body_columns(): void
    {
        $model = new FakeSearchableModel(['title' => 'Title Only']);
        $item = $model->toSearchableContent();

        $this->assertEquals('', $item->bodyHtml);
    }

    public function test_body_column_html_special_chars_escaped(): void
    {
        $model = new FakeSearchableModel(['body' => '<script>alert("xss")</script>']);
        $item = $model->toSearchableContent();

        $this->assertStringNotContainsString('<script>', $item->bodyHtml);
        $this->assertStringContainsString('&lt;script&gt;', $item->bodyHtml);
    }

    public function test_body_ampersand_escaped(): void
    {
        $model = new FakeSearchableModel(['body' => 'Cats & Dogs']);
        $item = $model->toSearchableContent();

        $this->assertStringContainsString('Cats &amp; Dogs', $item->bodyHtml);
    }

    // -------------------------------------------------------------------
    // URL generation: /table/id
    // -------------------------------------------------------------------

    public function test_url_uses_table_and_primary_key(): void
    {
        $model = new FakeSearchableModel([], 42, 'posts');
        $item = $model->toSearchableContent();

        $this->assertEquals('/posts/42', $item->url);
    }

    public function test_url_uses_string_key(): void
    {
        $model = new FakeSearchableModel([], 'abc-slug', 'pages');
        $item = $model->toSearchableContent();

        $this->assertEquals('/pages/abc-slug', $item->url);
    }

    public function test_id_uses_table_prefix(): void
    {
        $model = new FakeSearchableModel([], 7, 'articles');
        $item = $model->toSearchableContent();

        $this->assertEquals('articles-7', $item->id);
    }

    // -------------------------------------------------------------------
    // Date resolution: updated_at → created_at → published_at → today
    // -------------------------------------------------------------------

    public function test_updated_at_used_as_date(): void
    {
        $model = new FakeSearchableModel([
            'updated_at' => Carbon::parse('2025-06-15'),
            'created_at' => Carbon::parse('2025-01-01'),
        ]);
        $item = $model->toSearchableContent();

        $this->assertEquals('2025-06-15', $item->date);
    }

    public function test_created_at_used_when_updated_at_absent(): void
    {
        $model = new FakeSearchableModel([
            'created_at' => Carbon::parse('2024-03-20'),
        ]);
        $item = $model->toSearchableContent();

        $this->assertEquals('2024-03-20', $item->date);
    }

    public function test_published_at_used_when_no_standard_timestamps(): void
    {
        $model = new FakeSearchableModel([
            'published_at' => Carbon::parse('2023-11-01'),
        ]);
        $item = $model->toSearchableContent();

        $this->assertEquals('2023-11-01', $item->date);
    }

    public function test_today_used_when_no_date_columns(): void
    {
        $model = new FakeSearchableModel(['title' => 'No Date']);
        $item = $model->toSearchableContent();

        // Date should be a valid YYYY-MM-DD string.
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}$/',
            $item->date,
            'Date should be formatted as YYYY-MM-DD when no date column is present.'
        );
    }

    // -------------------------------------------------------------------
    // Site name resolution: config('scolta.site_name')
    // -------------------------------------------------------------------

    public function test_site_name_from_config(): void
    {
        $model = new FakeSearchableModel([]);
        $item = $model->toSearchableContent();

        $this->assertEquals('Scolta Test', $item->siteName);
    }

    public function test_site_name_falls_back_to_app_name(): void
    {
        // Reconfigure with no scolta.site_name.
        $app = Container::getInstance();
        $app->instance('config', new ConfigRepository([
            'scolta' => [],
            'app' => ['name' => 'My App'],
        ]));

        $model = new FakeSearchableModel([]);
        $item = $model->toSearchableContent();

        $this->assertEquals('My App', $item->siteName);
    }

    // -------------------------------------------------------------------
    // shouldBeSearchable() default returns true
    // -------------------------------------------------------------------

    public function test_should_be_searchable_defaults_to_true(): void
    {
        $model = new FakeSearchableModel([]);
        $this->assertTrue($model->shouldBeSearchable());
    }

    // -------------------------------------------------------------------
    // getSearchableType() default returns class name
    // -------------------------------------------------------------------

    public function test_get_searchable_type_returns_class_name(): void
    {
        $model = new FakeSearchableModel([]);
        $this->assertEquals(FakeSearchableModel::class, $model->getSearchableType());
    }

    // -------------------------------------------------------------------
    // Override toSearchableContent() wins over defaults
    // -------------------------------------------------------------------

    public function test_override_replaces_defaults(): void
    {
        $model = new FakeSearchableModelWithOverride;
        $item = $model->toSearchableContent();

        $this->assertEquals('custom-1', $item->id);
        $this->assertEquals('Custom Title', $item->title);
    }
}

// ---------------------------------------------------------------------------
// Test fixtures
// ---------------------------------------------------------------------------

/**
 * Minimal Eloquent-like model stub for testing Searchable defaults.
 *
 * Provides magic property access and the Eloquent methods that
 * toSearchableContent() needs — without a real database connection.
 */
class FakeSearchableModel
{
    use Searchable;

    private array $attributes;

    private int|string $primaryKey;

    private string $tableName;

    public function __construct(
        array $attributes = [],
        int|string $primaryKey = 1,
        string $table = 'items'
    ) {
        $this->attributes = $attributes;
        $this->primaryKey = $primaryKey;
        $this->tableName = $table;
    }

    /** Simulates Eloquent magic property access. */
    public function __get(string $name): mixed
    {
        return $this->attributes[$name] ?? null;
    }

    /** Simulates Eloquent getKey(). */
    public function getKey(): mixed
    {
        return $this->primaryKey;
    }

    /** Simulates Eloquent getTable(). */
    public function getTable(): string
    {
        return $this->tableName;
    }
}

/**
 * A stub that overrides toSearchableContent() to verify overrides win.
 */
class FakeSearchableModelWithOverride
{
    use Searchable;

    public function toSearchableContent(): ContentItem
    {
        return new ContentItem(
            id: 'custom-1',
            title: 'Custom Title',
            bodyHtml: '<p>Custom body</p>',
            url: '/custom',
            date: '2026-01-01',
            siteName: 'Custom Site',
        );
    }
}
