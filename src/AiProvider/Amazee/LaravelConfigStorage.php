<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\AiProvider\Amazee;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tag1\Scolta\AiProvider\Amazee\ConfigStorageInterface;

/**
 * Laravel config storage for Amazee.ai credentials.
 *
 * Persists the LiteLLM token (encrypted), API URL, and region to the
 * scolta_config database table. The token is wrapped with Laravel's
 * Crypt facade (AES-256-CBC via APP_KEY) so it is never stored in plain
 * text.
 *
 * The scolta_config table is a generic key/value store — other Scolta
 * subsystems can use it without additional migrations.
 */
class LaravelConfigStorage implements ConfigStorageInterface
{
    private const KEY = 'amazee_credentials';

    /**
     * {@inheritdoc}
     */
    public function store(string $litellmToken, string $litellmApiUrl, string $region): void
    {
        $payload = json_encode([
            'litellm_token' => Crypt::encryptString($litellmToken),
            'litellm_api_url' => $litellmApiUrl,
            'region' => $region,
        ]);

        DB::table('scolta_config')->upsert(
            [['key' => self::KEY, 'value' => $payload, 'updated_at' => now()]],
            ['key'],
            ['value', 'updated_at'],
        );
    }

    /**
     * {@inheritdoc}
     */
    public function load(): ?array
    {
        $row = DB::table('scolta_config')->where('key', self::KEY)->first();
        if ($row === null) {
            return null;
        }

        $data = json_decode($row->value, true);
        if (! is_array($data) || empty($data['litellm_token'])) {
            return null;
        }

        try {
            $token = Crypt::decryptString($data['litellm_token']);
        } catch (\Exception) {
            return null;
        }

        return [
            'litellm_token' => $token,
            'litellm_api_url' => $data['litellm_api_url'] ?? '',
            'region' => $data['region'] ?? '',
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function clear(): void
    {
        DB::table('scolta_config')->where('key', self::KEY)->delete();
    }
}
