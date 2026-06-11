<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;
use Tag1\ScoltaLaravel\ScoltaServiceProvider;
use Tag1\ScoltaLaravel\Services\ContentSource;
use Tag1\ScoltaLaravel\Tests\Support\SearchablePost;

/**
 * ContentSource applies BOTH documented publish filters.
 *
 * Regression for the content-gathering unification: scopeSearchable()
 * (query-level) and shouldBeSearchable() (record-level) must each exclude
 * records from getPublishedContent(). Every index path now consumes this
 * generator, so this is the single behavioral pin for publish filtering.
 */
class ContentSourceFilteringTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [ScoltaServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('searchable_posts', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('body');
            $table->boolean('published')->default(true);
            $table->boolean('unlisted')->default(false);
            $table->timestamps();
        });

        // Set after boot so the observer is not registered — these tests
        // exercise gathering, not change tracking.
        config(['scolta.models' => [SearchablePost::class]]);

        $body = str_repeat('Plenty of searchable body text. ', 20);
        SearchablePost::create(['title' => 'Visible', 'body' => $body, 'published' => true, 'unlisted' => false]);
        SearchablePost::create(['title' => 'Draft', 'body' => $body, 'published' => false, 'unlisted' => false]);
        SearchablePost::create(['title' => 'Hidden', 'body' => $body, 'published' => true, 'unlisted' => true]);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('searchable_posts');

        parent::tearDown();
    }

    public function test_scope_searchable_excludes_unpublished_records(): void
    {
        $titles = $this->gatheredTitles();

        $this->assertNotContains('Draft', $titles,
            'A record filtered out by scopeSearchable() must not be gathered.');
    }

    public function test_should_be_searchable_excludes_hidden_records(): void
    {
        $titles = $this->gatheredTitles();

        $this->assertNotContains('Hidden', $titles,
            'A record whose shouldBeSearchable() returns false must not be gathered.');
    }

    public function test_publishable_record_is_gathered(): void
    {
        $this->assertSame(['Visible'], $this->gatheredTitles());
    }

    public function test_total_count_applies_searchable_scope(): void
    {
        // Two records pass the scope (Visible + Hidden); the count is a
        // scope-level progress estimate and does not run per-record checks.
        $this->assertSame(2, (new ContentSource)->getTotalCount());
    }

    /**
     * @return string[]
     */
    private function gatheredTitles(): array
    {
        $titles = [];
        foreach ((new ContentSource)->getPublishedContent() as $item) {
            $titles[] = $item->title;
        }

        return $titles;
    }
}
