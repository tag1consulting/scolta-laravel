<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Tests\Commands;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;
use Tag1\ScoltaLaravel\ScoltaServiceProvider;
use Tag1\ScoltaLaravel\Tests\Support\SearchablePost;

/**
 * `scolta:inspect` reads back the fragment the index holds for a record.
 *
 * Fragment files are named by a content hash, so the command finds a record's
 * page by decoding fragments and matching their URL. Two things can go wrong
 * quietly: the gzip + "pagefind_dcd" envelope can be mis-stripped, leaving
 * nothing decodable, and matching /searchable_posts/1 loosely drags in
 * /searchable_posts/12. Both are asserted here, alongside the warning a record
 * that is in the database but not in the index has to produce.
 */
class InspectCommandTest extends TestCase
{
    private string $outputDir;

    protected function getPackageProviders($app): array
    {
        return [ScoltaServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->outputDir = storage_path('framework/testing/scolta-inspect-output');
        File::deleteDirectory($this->outputDir);
        File::ensureDirectoryExists($this->outputDir.'/pagefind/fragment');
        File::put($this->outputDir.'/pagefind/pagefind.js', '// pagefind');
        config(['scolta.pagefind.output_dir' => $this->outputDir]);

        Schema::create('searchable_posts', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('body');
            $table->boolean('published')->default(true);
            $table->boolean('unlisted')->default(false);
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('searchable_posts');
        File::deleteDirectory($this->outputDir);

        parent::tearDown();
    }

    /**
     * Write one fragment file the way the Pagefind format writer writes it.
     */
    private function writeFragment(string $name, string $url, string $content): void
    {
        $json = (string) json_encode([
            'url' => $url,
            'content' => $content,
            'filters' => ['subject' => ['Math']],
            'meta' => ['title' => $content],
        ], JSON_UNESCAPED_SLASHES);

        File::put(
            $this->outputDir.'/pagefind/fragment/'.$name.'.pf_fragment',
            (string) gzencode('pagefind_dcd'.$json, 9)
        );
    }

    private function runInspect(int $id, bool $json = false): string
    {
        Artisan::call('scolta:inspect', [
            'model' => SearchablePost::class,
            'id' => (string) $id,
        ] + ($json ? ['--json' => true] : []));

        return Artisan::output();
    }

    public function test_the_records_own_fragment_comes_back_and_nothing_adjacent(): void
    {
        $post = SearchablePost::create(['title' => 'Indexed', 'body' => 'body']);
        $url = $post->toSearchableContent()->url;

        $this->writeFragment('aaa', $url, 'the indexed post');
        // /searchable_posts/1 must not drag in /searchable_posts/12 ...
        $this->writeFragment('bbb', $url.'2', 'a different post');
        // ... while a prefixed URL for the same page still matches.
        $this->writeFragment('ccc', 'https://example.com'.$url, 'the same post, absolute');
        $this->writeFragment('ddd', '/lessons/photosynthesis', 'a lesson');

        $matches = json_decode($this->runInspect($post->id, json: true), true);

        $this->assertEqualsCanonicalizing(
            ['aaa.pf_fragment', 'ccc.pf_fragment'],
            array_keys((array) $matches)
        );
        $this->assertSame($url, $matches['aaa.pf_fragment']['url']);
        $this->assertSame('the indexed post', $matches['aaa.pf_fragment']['content']);
        $this->assertSame(['subject' => ['Math']], $matches['aaa.pf_fragment']['filters']);
    }

    public function test_a_record_absent_from_the_index_warns_rather_than_failing(): void
    {
        $post = SearchablePost::create(['title' => 'Not indexed', 'body' => 'body']);
        $this->writeFragment('aaa', '/lessons/photosynthesis', 'a lesson');

        $exit = Artisan::call('scolta:inspect', [
            'model' => SearchablePost::class,
            'id' => (string) $post->id,
        ]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString(
            'Nothing in the index is indexed at '.$post->toSearchableContent()->url,
            Artisan::output()
        );
    }

    public function test_no_built_index_is_an_error(): void
    {
        File::delete($this->outputDir.'/pagefind/pagefind.js');
        $post = SearchablePost::create(['title' => 'Indexed', 'body' => 'body']);

        $exit = Artisan::call('scolta:inspect', [
            'model' => SearchablePost::class,
            'id' => (string) $post->id,
        ]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('No built index', Artisan::output());
    }

    public function test_an_unknown_model_and_a_missing_record_are_errors(): void
    {
        $this->assertSame(1, Artisan::call('scolta:inspect', ['model' => 'NoSuchModel', 'id' => '1']));
        $this->assertStringContainsString('No such model class', Artisan::output());

        $this->assertSame(1, Artisan::call('scolta:inspect', [
            'model' => SearchablePost::class,
            'id' => '99999',
        ]));
        $this->assertStringContainsString('No '.SearchablePost::class, Artisan::output());
    }
}
