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
use Tag1\Scolta\AiProvider\Amazee\AmazeeTrialProvisioner;
use Tag1\ScoltaLaravel\AiProvider\Amazee\LaravelConfigStorage;

/**
 * Amazee.ai settings: multi-step connection flow.
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
            $provisioner = new AmazeeTrialProvisioner(new AmazeeClient, $storage);
            $provisioner->provision($validated['email']);
            $request->session()->forget(self::SESSION_KEY);

            return response()->json(['step' => 'connected']);
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

            return response()->json(['step' => 'connected']);
        } catch (AmazeeApiException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * DELETE — disconnect from Amazee.ai and clear stored credentials.
     */
    public function disconnect(Request $request): JsonResponse
    {
        (new LaravelConfigStorage)->clear();
        $request->session()->forget(self::SESSION_KEY);

        return response()->json(['step' => 'start']);
    }

    /**
     * Returns true when a non-Amazee AI provider is already configured.
     */
    private function detectExistingProvider(): bool
    {
        $key = config('scolta.ai_api_key', '') ?: env('SCOLTA_API_KEY', '');

        return $key !== '';
    }

    /**
     * Determine the active step based on stored credentials and flow state.
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
