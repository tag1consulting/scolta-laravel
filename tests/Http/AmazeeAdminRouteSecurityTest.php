<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Tests\Http;

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use Orchestra\Testbench\TestCase;
use Tag1\ScoltaLaravel\ScoltaServiceProvider;
use Tag1\ScoltaLaravel\Tests\Http\Support\DenyAllMiddleware;
use Tag1\ScoltaLaravel\Tests\Http\Support\PassThroughMiddleware;

/**
 * Secure-by-default behavior of the Amazee.ai admin settings routes.
 *
 * The admin routes can wipe stored AI credentials (DELETE disconnect) and
 * bind a trial to an arbitrary email (POST trial), so with the shipped
 * default config ('amazee_middleware' => ['web']) they must not be
 * registered at all — anonymous requests get 404. Configuring middleware
 * beyond the bare ['web'] group registers the routes behind it.
 */
class AmazeeAdminRouteSecurityTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [ScoltaServiceProvider::class];
    }

    /** @param Application $app */
    protected function defineEnvironment($app): void
    {
        // The 'web' middleware group encrypts cookies, which needs a key.
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
    }

    /** @param Application $app */
    protected function guardedMiddleware($app): void
    {
        $app['config']->set('scolta.amazee_middleware', ['web', DenyAllMiddleware::class]);
    }

    /** @param Application $app */
    protected function satisfiedGuardMiddleware($app): void
    {
        $app['config']->set('scolta.amazee_middleware', ['web', PassThroughMiddleware::class]);
    }

    /** @param Application $app */
    protected function bareWebMiddleware($app): void
    {
        $app['config']->set('scolta.amazee_middleware', ['web']);
    }

    // -------------------------------------------------------------------
    // Default config: routes do not exist.
    // -------------------------------------------------------------------

    public function test_admin_routes_are_not_registered_by_default(): void
    {
        $this->assertFalse(Route::has('scolta.amazee.trial'));
        $this->assertFalse(Route::has('scolta.amazee.disconnect'));
        $this->assertFalse(Route::has('scolta.amazee.show'));

        $this->postJson('/scolta/amazee/trial', ['email' => 'attacker@example.com'])->assertNotFound();
        $this->deleteJson('/scolta/amazee/disconnect')->assertNotFound();
        $this->getJson('/scolta/amazee')->assertNotFound();
    }

    #[DefineEnvironment('bareWebMiddleware')]
    public function test_explicit_bare_web_middleware_keeps_routes_disabled(): void
    {
        $this->assertFalse(Route::has('scolta.amazee.trial'));
        $this->postJson('/scolta/amazee/trial', ['email' => 'attacker@example.com'])->assertNotFound();
        $this->deleteJson('/scolta/amazee/disconnect')->assertNotFound();
    }

    // -------------------------------------------------------------------
    // Configured middleware: routes exist and the guard applies.
    // -------------------------------------------------------------------

    #[DefineEnvironment('guardedMiddleware')]
    public function test_configured_guard_middleware_blocks_anonymous_requests(): void
    {
        $this->assertTrue(Route::has('scolta.amazee.trial'));
        $this->assertTrue(Route::has('scolta.amazee.disconnect'));

        $this->postJson('/scolta/amazee/trial', ['email' => 'attacker@example.com'])->assertForbidden();
        $this->deleteJson('/scolta/amazee/disconnect')->assertForbidden();
        $this->getJson('/scolta/amazee')->assertForbidden();
    }

    #[DefineEnvironment('satisfiedGuardMiddleware')]
    public function test_satisfied_guard_middleware_restores_access(): void
    {
        $this->assertTrue(Route::has('scolta.amazee.trial'));

        // The request passes the guard and reaches the controller. Reaching it
        // is the whole assertion: what matters here is that a satisfied guard
        // does not turn the route into a 403 or a 404. It used to be shown by a
        // 422 from the controller's own email validation, but the demo takes no
        // email now, so an empty payload is a valid request rather than an
        // invalid one — the response is whatever the (unreachable, unmigrated)
        // Amazee control plane produces, and only the two guard statuses are
        // ruled out.
        $status = $this->postJson('/scolta/amazee/trial', [])->getStatusCode();

        $this->assertNotSame(403, $status, 'a satisfied guard must not forbid the request');
        $this->assertNotSame(404, $status, 'the route must exist when the guard is configured');
    }
}
