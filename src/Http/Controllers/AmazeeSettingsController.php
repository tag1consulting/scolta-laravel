<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Tag1\Scolta\AiProvider\Amazee\AmazeeAccountUpgrader;
use Tag1\Scolta\AiProvider\Amazee\AmazeeApiException;
use Tag1\Scolta\AiProvider\Amazee\AmazeeClient;
use Tag1\Scolta\AiProvider\Amazee\AmazeeModelResolver;
use Tag1\Scolta\AiProvider\Amazee\AmazeeTrialProvisioner;
use Tag1\Scolta\AiProvider\Amazee\KeyExpiryRecovery;
use Tag1\ScoltaLaravel\AiProvider\Amazee\LaravelConfigStorage;
use Tag1\ScoltaLaravel\Cache\LaravelCacheDriver;

/**
 * Amazee.ai settings: multi-step connection flow.
 *
 * One of the two surfaces that enable the managed Amazee.ai gateway; the other
 * is `artisan scolta:amazee:provision`. Every step here runs from an explicit
 * operator action, and no other code path establishes the connection.
 *
 *  Trial path:   email → POST trial → connected.
 *  Sign-in path: email → OTP email → enter code → select region → connected.
 *
 * In-flight flow state (email, session token) is stored in the Laravel
 * session and cleared on completion or disconnection.
 */
class AmazeeSettingsController extends Controller
{
    private const SESSION_KEY = 'scolta_amazee_flow';

    /**
     * Render the settings page.
     */
    public function show(Request $request): View
    {
        $storage = new LaravelConfigStorage;
        $creds = $storage->load();
        $flow = $request->session()->get(self::SESSION_KEY, []);
        $hasExistingProvider = $this->detectExistingProvider();

        return view('scolta::amazee-settings', [
            'connected' => $creds !== null,
            'region' => $creds['region'] ?? null,
            'step' => $this->determineStep($creds, $flow, $hasExistingProvider),
            'email' => $flow['email'] ?? '',
            'hasExistingProvider' => $hasExistingProvider,
            // True when the stored Amazee.ai credentials are no longer accepted
            // and the operator needs to re-authenticate. The view shows a
            // reconnect banner whose CTA runs the existing email-verification
            // sign-in flow.
            'upgradeNeeded' => $this->keyExpiryRecovery()->isUpgradeNeeded(),
        ]);
    }

    /**
     * POST — provision a free trial account.
     */
    public function startTrial(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|email',
        ]);

        try {
            $storage = new LaravelConfigStorage;
            $amazeeClient = new AmazeeClient;
            $provisioner = new AmazeeTrialProvisioner(
                $amazeeClient,
                $storage,
                null,
                new AmazeeModelResolver($amazeeClient),
            );
            $result = $provisioner->provision($validated['email']);

            if ($result->aiModel !== null) {
                $storage->storeModels($result->aiModel, $result->aiExpansionModel ?? '');
            }

            $request->session()->forget(self::SESSION_KEY);

            return response()->json([
                'step' => 'connected',
                'ai_model' => $result->aiModel,
                'ai_expansion_model' => $result->aiExpansionModel,
            ]);
        } catch (AmazeeApiException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * POST — send a verification code to begin the sign-in flow.
     */
    public function requestCode(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|email',
        ]);

        try {
            $upgrader = new AmazeeAccountUpgrader(new AmazeeClient, new LaravelConfigStorage);
            $upgrader->requestVerificationCode($validated['email']);
            $request->session()->put(self::SESSION_KEY, [
                'step' => 'verification',
                'email' => $validated['email'],
            ]);

            return response()->json([
                'step' => 'verification',
                'email' => $validated['email'],
            ]);
        } catch (AmazeeApiException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * POST — verify the email code and advance to region selection.
     */
    public function verifyCode(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => 'required|string',
        ]);

        $flow = $request->session()->get(self::SESSION_KEY, []);
        if (empty($flow['email'])) {
            return response()->json(['error' => 'Session expired. Please start again.'], 422);
        }

        try {
            $upgrader = new AmazeeAccountUpgrader(new AmazeeClient, new LaravelConfigStorage);
            $sessionToken = $upgrader->signIn($flow['email'], $validated['code']);
            $request->session()->put(self::SESSION_KEY, array_merge($flow, [
                'step' => 'region',
                'session_token' => $sessionToken,
            ]));

            return response()->json(['step' => 'region']);
        } catch (AmazeeApiException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * GET — list available regions for the authenticated account.
     */
    public function listRegions(Request $request): JsonResponse
    {
        $flow = $request->session()->get(self::SESSION_KEY, []);
        if (empty($flow['session_token'])) {
            return response()->json(['error' => 'Session expired. Please start again.'], 422);
        }

        try {
            $upgrader = new AmazeeAccountUpgrader(new AmazeeClient, new LaravelConfigStorage);
            $regions = $upgrader->listRegions($flow['session_token']);

            return response()->json(['regions' => $regions]);
        } catch (AmazeeApiException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * POST — create a private AI key in the selected region.
     */
    public function connect(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'region_id' => 'required|string',
        ]);

        $flow = $request->session()->get(self::SESSION_KEY, []);
        if (empty($flow['session_token'])) {
            return response()->json(['error' => 'Session expired. Please start again.'], 422);
        }

        try {
            $upgrader = new AmazeeAccountUpgrader(new AmazeeClient, new LaravelConfigStorage);
            $upgrader->upgrade($flow['session_token'], $validated['region_id']);
            $request->session()->forget(self::SESSION_KEY);

            // Fresh credentials are stored — clear the re-authentication prompt.
            // Policy: reconnection is always operator-initiated through this
            // email-verification flow; credentials are never minted automatically.
            $this->keyExpiryRecovery()->clearUpgradeNeeded();

            return response()->json(['step' => 'connected']);
        } catch (AmazeeApiException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * DELETE — switch off Amazee.ai and clear the stored connection.
     *
     * Clears the recovery markers along with the credentials. Both markers
     * describe credentials that are now gone, so leaving them set would keep
     * `/health` reporting a degraded connection and keep this page showing a
     * reconnect prompt for a connection the operator deliberately ended.
     */
    public function disconnect(Request $request): JsonResponse
    {
        (new LaravelConfigStorage)->clear();
        $request->session()->forget(self::SESSION_KEY);

        $recovery = $this->keyExpiryRecovery();
        $recovery->clearUpgradeNeeded();
        $recovery->clearAuthFailure();

        return response()->json(['step' => 'start']);
    }

    /**
     * Build the key-expiry recovery helper backed by the adapter's stores.
     *
     * Reads the persistent re-authentication marker (isUpgradeNeeded) and
     * clears it after a successful reconnect (clearUpgradeNeeded). The cache
     * is the same one the service provider and HealthController use, so the
     * settings page, /health, and the recovery wiring stay consistent.
     *
     * @since 1.0.5
     *
     * @stability experimental
     */
    private function keyExpiryRecovery(): KeyExpiryRecovery
    {
        return new KeyExpiryRecovery(
            storage: new LaravelConfigStorage,
            cache: new LaravelCacheDriver,
            logger: logger(),
        );
    }

    /**
     * Returns true when a non-Amazee AI provider is already configured.
     */
    private function detectExistingProvider(): bool
    {
        $key = config('scolta.ai_api_key', '');

        return $key !== '';
    }

    /**
     * Determine the active step based on stored credentials and flow state.
     *
     * @param  array<string, mixed>|null  $creds
     * @param  array<string, mixed>  $flow
     */
    private function determineStep(?array $creds, array $flow, bool $hasExistingProvider = false): string
    {
        if ($creds !== null) {
            return 'connected';
        }

        if ($hasExistingProvider && empty($flow['step'])) {
            return 'provider-configured';
        }

        return $flow['step'] ?? 'start';
    }
}
