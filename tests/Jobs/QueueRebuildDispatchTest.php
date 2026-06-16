<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Tests\Jobs;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;
use Tag1\Scolta\Config\MemoryBudgetConfig;
use Tag1\Scolta\Export\ContentItem;
use Tag1\Scolta\Index\PhpIndexer;
use Tag1\ScoltaLaravel\Jobs\FinalizeIndex;
use Tag1\ScoltaLaravel\Jobs\ProcessIndexChunk;
use Tag1\ScoltaLaravel\Jobs\TriggerRebuild;
use Tag1\ScoltaLaravel\ScoltaServiceProvider;
use Tag1\ScoltaLaravel\Services\ContentItemCodec;
use Tag1\ScoltaLaravel\Services\ContentSource;
use Tag1\ScoltaLaravel\Services\QueueRebuildDispatcher;
use Tag1\ScoltaLaravel\Tests\Support\SearchablePost;

/**
 * Behavioral tests for the unified queue dispatch path.
 *
 * Pins the three defects fixed by QueueRebuildDispatcher:
 *  - the observer path (TriggerRebuild) applies the publish filters,
 *  - job payloads carry state-dir file references, not the corpus,
 *  - the configured memory budget reaches both dispatcher chunking and
 *    the jobs (no more hardcoded chunk size 50 / default-profile offsets).
 */
class QueueRebuildDispatchTest extends TestCase
{
    private string $stateDir;

    private string $outputDir;

    protected function getPackageProviders($app): array
    {
        return [ScoltaServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->stateDir = storage_path('framework/testing/scolta-state');
        $this->outputDir = storage_path('framework/testing/scolta-output');
        File::deleteDirectory($this->stateDir);
        File::deleteDirectory($this->outputDir);

        // These tests fake the bus, so the chain's FinalizeIndex never runs to
        // release the cross-process build lock. Start each test from a released
        // lock so a held lock from a prior dispatch can't leak in.
        Cache::lock(QueueRebuildDispatcher::BUILD_LOCK)->forceRelease();

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

        // Set after boot so the observer is not registered for these tests.
        config(['scolta.models' => [SearchablePost::class]]);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('searchable_posts');
        File::deleteDirectory($this->stateDir);
        File::deleteDirectory($this->outputDir);

        parent::tearDown();
    }

    private function createPost(string $title, bool $published = true, bool $unlisted = false): void
    {
        SearchablePost::create([
            'title' => $title,
            'body' => str_repeat('Plenty of searchable body text for '.$title.'. ', 20),
            'published' => $published,
            'unlisted' => $unlisted,
        ]);
    }

    // -------------------------------------------------------------------
    // Publish filters apply on the observer (TriggerRebuild) path.
    // -------------------------------------------------------------------

    public function test_trigger_rebuild_excludes_filtered_records(): void
    {
        Bus::fake();

        $this->createPost('Visible');
        $this->createPost('Draft', published: false);
        $this->createPost('Shy', unlisted: true);

        (new TriggerRebuild(force: true))->handle(app(QueueRebuildDispatcher::class));

        $chunkFiles = File::glob($this->stateDir.'/queue-payload/chunk-*.json');
        $this->assertCount(1, $chunkFiles, 'Expected a single chunk payload file.');

        $titles = array_map(
            fn (ContentItem $item) => $item->title,
            ContentItemCodec::decode(File::get($chunkFiles[0])),
        );
        $this->assertSame(['Visible'], $titles,
            'The queue path must exclude records rejected by scopeSearchable() or shouldBeSearchable().');
    }

    // -------------------------------------------------------------------
    // Jobs carry file references, not the corpus.
    // -------------------------------------------------------------------

    public function test_chunk_jobs_reference_payload_files(): void
    {
        Bus::fake();

        $this->createPost('Visible');

        (new TriggerRebuild(force: true))->handle(app(QueueRebuildDispatcher::class));

        Bus::assertChained([ProcessIndexChunk::class, FinalizeIndex::class]);
        Bus::assertDispatched(ProcessIndexChunk::class, function (ProcessIndexChunk $job) {
            return str_contains($job->itemsFile, '/queue-payload/chunk-')
                && File::exists($job->itemsFile);
        });
    }

    // -------------------------------------------------------------------
    // Configured memory budget reaches dispatcher chunking and the jobs.
    // -------------------------------------------------------------------

    public function test_non_default_memory_budget_propagates_to_jobs(): void
    {
        Bus::fake();
        config(['scolta.memory_budget.profile' => 'balanced']);

        $this->createPost('Visible');

        (new TriggerRebuild(force: true))->handle(app(QueueRebuildDispatcher::class));

        Bus::assertDispatched(ProcessIndexChunk::class, function (ProcessIndexChunk $job) {
            // The balanced profile's chunk size is 200 — both the job's
            // offset inputs and the dispatcher's chunking derive from it.
            return $job->memoryBudget === 'balanced' && $job->chunkSize === 200;
        });
    }

    // -------------------------------------------------------------------
    // Unchanged content dispatches nothing and leaves no payload files.
    // -------------------------------------------------------------------

    public function test_unchanged_content_dispatches_nothing(): void
    {
        Bus::fake();

        $this->createPost('Visible');

        // Prime the stored fingerprint to match the current corpus.
        $budget = MemoryBudgetConfig::fromCliAndConfig(null, null, fn () => ['profile' => 'conservative', 'chunk_size' => null]);
        $dispatcher = app(QueueRebuildDispatcher::class);
        $first = $dispatcher->dispatch($budget, force: true);
        $this->assertSame(QueueRebuildDispatcher::STATUS_DISPATCHED, $first['status']);

        // The first dispatch handed the cross-process build lock to the (faked,
        // so never-run) FinalizeIndex. Simulate that chain finishing — release
        // the lock — so the second dispatch represents a later, separate build
        // rather than a concurrent one (which would return STATUS_IN_PROGRESS).
        Cache::lock(QueueRebuildDispatcher::BUILD_LOCK)->forceRelease();

        $entries = [];
        foreach ((new ContentSource)->getPublishedContent() as $item) {
            $entries[] = QueueRebuildDispatcher::fingerprintEntry($item);
        }
        File::ensureDirectoryExists($this->outputDir);
        File::put($this->outputDir.'/.scolta-state', QueueRebuildDispatcher::fingerprintFromEntries($entries));

        $second = $dispatcher->dispatch($budget, force: false);

        $this->assertSame(QueueRebuildDispatcher::STATUS_UNCHANGED, $second['status']);
        $this->assertDirectoryDoesNotExist($this->stateDir.'/queue-payload',
            'An unchanged dispatch must clean up its payload files.');
    }

    // -------------------------------------------------------------------
    // Streaming fingerprint is byte-identical to scolta-php's.
    // -------------------------------------------------------------------

    public function test_streaming_fingerprint_matches_php_indexer(): void
    {
        $items = [
            new ContentItem(id: 'b-2', title: 'B', bodyHtml: '<p>Second body</p>', url: '/b', date: '2026-01-02'),
            new ContentItem(id: 'a-1', title: 'A', bodyHtml: '<p>First body</p>', url: '/a', date: '2026-01-01'),
            new ContentItem(id: 'c-3', title: 'C', bodyHtml: '<p>Third body</p>', url: '/c', date: '2026-01-03'),
        ];

        $entries = array_map(
            fn (ContentItem $item) => QueueRebuildDispatcher::fingerprintEntry($item),
            $items,
        );

        $this->assertSame(
            PhpIndexer::computeFingerprint($items),
            QueueRebuildDispatcher::fingerprintFromEntries($entries),
            'The dispatcher\'s streaming fingerprint must stay byte-identical to PhpIndexer::computeFingerprint().'
        );
    }

    // -------------------------------------------------------------------
    // ContentItem JSON codec round-trips all fields.
    // -------------------------------------------------------------------

    public function test_content_item_codec_round_trips(): void
    {
        $item = new ContentItem(
            id: 'post-1',
            title: 'Tïtle — unicode',
            bodyHtml: '<p>Body & entities</p>',
            url: 'https://example.com/post/1?x=1#frag',
            date: '2026-06-10',
            siteName: 'Site',
            language: 'de',
            filters: ['topic' => ['Science', 'History']],
            metadata: ['price' => '29.99'],
            sortable: ['rating' => '4.5'],
        );

        [$decoded] = ContentItemCodec::decode(ContentItemCodec::encode([$item]));

        $this->assertSame($item->id, $decoded->id);
        $this->assertSame($item->title, $decoded->title);
        $this->assertSame($item->bodyHtml, $decoded->bodyHtml);
        $this->assertSame($item->url, $decoded->url, 'URL must survive (already stripped to a relative path).');
        $this->assertSame($item->date, $decoded->date);
        $this->assertSame($item->siteName, $decoded->siteName);
        $this->assertSame($item->language, $decoded->language);
        $this->assertSame($item->filters, $decoded->filters);
        $this->assertSame($item->metadata, $decoded->metadata);
        $this->assertSame($item->sortable, $decoded->sortable);
    }
}
