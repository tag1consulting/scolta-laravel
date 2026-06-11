<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;
use Tag1\ScoltaLaravel\ScoltaServiceProvider;
use Tag1\ScoltaLaravel\Tests\Support\SearchablePost;

/**
 * End-to-end regression: the synchronous PHP-indexer path applies the
 * documented publish filters.
 *
 * Before the content-gathering unification, `scolta:build --sync` gathered
 * via Model::cursor() — bypassing scopeSearchable() and shouldBeSearchable()
 * — so draft/hidden content landed in the public search index. The build
 * must now index only the publishable record.
 */
class SyncBuildFilteringTest extends TestCase
{
    private string $stateDir;

    private string $outputDir;

    protected function getPackageProviders($app): array
    {
        return [ScoltaServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->stateDir = storage_path('framework/testing/scolta-sync-state');
        $this->outputDir = storage_path('framework/testing/scolta-sync-output');
        File::deleteDirectory($this->stateDir);
        File::deleteDirectory($this->outputDir);

        config([
            'scolta.state_dir' => $this->stateDir,
            'scolta.pagefind.output_dir' => $this->outputDir,
        ]);

        Schema::create('searchable_posts', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('body');
            $table->boolean('published')->default(true);
            $table->boolean('unlisted')->default(false);
            $table->timestamps();
        });

        // Set after boot so the observer is not registered for this test.
        config(['scolta.models' => [SearchablePost::class]]);

        $body = str_repeat('Plenty of searchable body text. ', 20);
        SearchablePost::create(['title' => 'Visible', 'body' => $body, 'published' => true, 'unlisted' => false]);
        SearchablePost::create(['title' => 'Draft', 'body' => $body, 'published' => false, 'unlisted' => false]);
        SearchablePost::create(['title' => 'Hidden', 'body' => $body, 'published' => true, 'unlisted' => true]);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('searchable_posts');
        File::deleteDirectory($this->stateDir);
        File::deleteDirectory($this->outputDir);
        File::deleteDirectory(public_path('vendor/scolta'));

        parent::tearDown();
    }

    public function test_sync_build_indexes_only_publishable_content(): void
    {
        $this->artisan('scolta:build', ['--sync' => true, '--force' => true])
            ->expectsOutputToContain('Index built: 1 pages')
            ->assertExitCode(0);

        $this->assertFileExists($this->outputDir.'/pagefind/pagefind-entry.json',
            'The sync build must publish a Pagefind-compatible index.');
    }
}
