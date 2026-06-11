<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Tests\Http;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Orchestra\Testbench\TestCase;
use Tag1\ScoltaLaravel\Http\Controllers\RebuildNowController;
use Tag1\ScoltaLaravel\Jobs\TriggerRebuild;
use Tag1\ScoltaLaravel\ScoltaServiceProvider;

/**
 * RebuildNowController behavior (formerly a route closure).
 *
 * The closure acquired the build lock, released it immediately, and then
 * dispatched — so two concurrent requests could both dispatch. The
 * controller must hold the lock through the dispatch and report 409 when
 * the lock is held elsewhere.
 */
class RebuildNowControllerTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [ScoltaServiceProvider::class];
    }

    public function test_dispatches_rebuild_and_returns_message(): void
    {
        Bus::fake();

        $response = (new RebuildNowController)(Request::create('/rebuild-now', 'POST'));

        $this->assertSame(200, $response->getStatusCode());
        Bus::assertDispatched(TriggerRebuild::class, fn (TriggerRebuild $job) => $job->force === false);
    }

    public function test_force_flag_is_passed_to_the_job(): void
    {
        Bus::fake();

        $response = (new RebuildNowController)(Request::create('/rebuild-now', 'POST', ['force' => '1']));

        $this->assertSame(200, $response->getStatusCode());
        Bus::assertDispatched(TriggerRebuild::class, fn (TriggerRebuild $job) => $job->force === true);
    }

    public function test_returns_409_when_build_lock_is_held(): void
    {
        Bus::fake();

        // Another process holds the build lock.
        $held = Cache::lock('scolta_build', 3600);
        $this->assertTrue($held->get());

        $response = (new RebuildNowController)(Request::create('/rebuild-now', 'POST'));

        $this->assertSame(409, $response->getStatusCode());
        Bus::assertNotDispatched(TriggerRebuild::class);

        $held->release();
    }

    public function test_lock_is_released_after_dispatch(): void
    {
        Bus::fake();

        (new RebuildNowController)(Request::create('/rebuild-now', 'POST'));

        // The controller's lock must not linger after the response.
        $lock = Cache::lock('scolta_build', 3600);
        $this->assertTrue($lock->get(), 'The build lock must be released once the dispatch completes.');
        $lock->release();
    }
}
