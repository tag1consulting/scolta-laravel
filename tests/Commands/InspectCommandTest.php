<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Tests\Commands;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;
use Tag1\ScoltaLaravel\ScoltaServiceProvider;
use Tag1\ScoltaLaravel\Tests\Support\SearchablePost;

/**
 * `scolta:inspect` reads back the fragment the index holds for a record.
 *
 * Fragment files are named by a content hash, so the command joins record to
 * fragment through the page-table ledger and the pf_meta page table. Both come
 * from a real build here, because hand-written fixtures would only prove the
 * command agrees with itself about their layout.
 */
class InspectCommandTest extends TestCase
{
    private string $stateDir;

    private string $outputDir;

    /** @var list<SearchablePost> */
    private array $posts = [];

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

        $this->stateDir = storage_path('framework/testing/scolta-inspect-state');
        $this->outputDir = storage_path('framework/testing/scolta-inspect-output');
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
        Route::get('/searchable_posts/{post}', fn (SearchablePost $post) => $post->getKey());
        Route::get('/about', fn () => 'about');

        // Set after boot so the observer is not registered for this test.
        config(['scolta.models' => [SearchablePost::class]]);
        foreach (['zebras', 'axolotls'] as $animal) {
            $this->posts[] = SearchablePost::create([
                'title' => "About {$animal}",
                'body' => str_repeat("Seeded body text about {$animal}. ", 20),
            ]);
        }
        // Artisan::call() rather than $this->artisan(): on Laravel 11 the
        // PendingCommand leaves its OutputStyle mock bound, and every later
        // command's output lands in the mock instead of Artisan::output().
        $this->assertSame(0, Artisan::call('scolta:build', ['--sync' => true, '--force' => true]));
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('searchable_posts');
        File::deleteDirectory($this->stateDir);
        File::deleteDirectory($this->outputDir);
        File::deleteDirectory(public_path('vendor/scolta'));

        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, array<string, mixed>>
     */
    private function inspectJson(array $arguments): array
    {
        $this->assertSame(0, Artisan::call('scolta:inspect', $arguments + ['--json' => true]));

        return (array) json_decode(Artisan::output(), true);
    }

    public function test_a_url_resolves_to_its_record_and_returns_that_fragment_only(): void
    {
        $post = $this->posts[1];
        $key = $post->toSearchableContent()->id;

        $matches = $this->inspectJson(['url' => "searchable_posts/{$post->id}"]);

        $this->assertSame([$key], array_keys($matches));
        $this->assertSame($post->toSearchableContent()->url, $matches[$key]['url']);
        $this->assertStringContainsString('axolotls', $matches[$key]['content']);
        $this->assertStringNotContainsString('zebras', $matches[$key]['content']);
    }

    public function test_model_and_id_name_the_same_record_the_url_does(): void
    {
        $post = $this->posts[0];
        $key = $post->toSearchableContent()->id;

        $matches = $this->inspectJson(['--model' => SearchablePost::class, '--id' => (string) $post->id]);

        $this->assertSame([$key], array_keys($matches));
        $this->assertStringContainsString('zebras', $matches[$key]['content']);
    }

    public function test_a_record_absent_from_the_index_warns_rather_than_failing(): void
    {
        $post = SearchablePost::create(['title' => 'Not indexed', 'body' => 'body']);

        $exit = Artisan::call('scolta:inspect', ['--model' => SearchablePost::class, '--id' => (string) $post->id]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString(
            'Nothing in the index is indexed for '.$post->toSearchableContent()->id,
            Artisan::output()
        );
    }

    public function test_a_ledger_row_whose_fragment_is_gone_warns_naming_it(): void
    {
        $post = $this->posts[0];
        File::cleanDirectory($this->outputDir.'/pagefind/fragment');

        $exit = Artisan::call('scolta:inspect', ['--model' => SearchablePost::class, '--id' => (string) $post->id]);

        $this->assertSame(0, $exit);
        $output = Artisan::output();
        $this->assertStringContainsString($post->toSearchableContent()->id.' is at ordinal', $output);
        $this->assertStringContainsString('missing or unreadable', $output);
    }

    public function test_no_built_index_is_an_error(): void
    {
        File::delete($this->outputDir.'/pagefind/pagefind.js');

        $this->assertSame(1, Artisan::call('scolta:inspect', ['url' => '/searchable_posts/1']));
        $this->assertStringContainsString('No built index', Artisan::output());
    }

    public function test_bad_selectors_are_errors(): void
    {
        foreach ([
            [[], 'Pass a URL, or both'],
            [['--model' => SearchablePost::class], 'Pass a URL, or both'],
            [['url' => '/searchable_posts/1', '--model' => SearchablePost::class, '--id' => '1'], 'not both'],
            [['--model' => 'NoSuchModel', '--id' => '1'], 'No such model class'],
            [['--model' => SearchablePost::class, '--id' => '99999'], 'No '.SearchablePost::class],
            [['url' => '/nowhere'], 'does not route to anything'],
            [['url' => '/searchable_posts/99999'], 'does not route to anything'],
            [['url' => 'about'], 'does not route to a model'],
        ] as [$arguments, $message]) {
            $this->assertSame(1, Artisan::call('scolta:inspect', $arguments), $message);
            $this->assertStringContainsString($message, Artisan::output());
        }
    }
}
