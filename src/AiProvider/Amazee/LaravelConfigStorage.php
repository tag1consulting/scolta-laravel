<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\AiProvider\Amazee;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tag1\Scolta\AiProvider\Amazee\AmazeeConnectionSource;
use Tag1\Scolta\AiProvider\Amazee\ProvenanceAwareConfigStorageInterface;

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
 *
 * Provenance-aware: it also records which operator action established the
 * connection, so no surface has to guess between the free demo and an
 * operator's own amazee.ai account.
 */
class LaravelConfigStorage implements ProvenanceAwareConfigStorageInterface
{
    private const KEY = 'amazee_credentials';

    /**
     * Row key for the recorded connection source.
     *
     * Kept beside the credentials rather than inside them so an existing
     * credential payload does not change shape, and so a connection made
     * before this row existed reads as "not recorded" rather than as a
     * default.
     */
    private const SOURCE_KEY = 'amazee_connection_source';

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
        // The provenance goes with the credentials it describes. Left behind,
        // it would be paired with whatever connection comes next, which is a
        // guess wearing a recorded fact's clothes.
        DB::table('scolta_config')->whereIn('key', [self::KEY, self::SOURCE_KEY])->delete();
    }

    /**
     * {@inheritdoc}
     */
    public function storeConnectionSource(AmazeeConnectionSource $source): void
    {
        DB::table('scolta_config')->upsert(
            [['key' => self::SOURCE_KEY, 'value' => $source->value, 'updated_at' => now()]],
            ['key'],
            ['value', 'updated_at'],
        );
    }

    /**
     * {@inheritdoc}
     */
    public function loadConnectionSource(): ?AmazeeConnectionSource
    {
        $row = DB::table('scolta_config')->where('key', self::SOURCE_KEY)->first();

        // NULL is the right answer for a connection made before provenance was
        // recorded. It must read as "not recorded", never as a default.
        return is_object($row) && is_string($row->value ?? null)
            ? AmazeeConnectionSource::tryFrom($row->value)
            : null;
    }

    /**
     * Persist auto-selected model names to the database.
     *
     * Only called after a successful trial provision. Stored separately
     * from credentials so a disconnect doesn't wipe the model preference.
     */
    public function storeModels(string $aiModel, string $aiExpansionModel): void
    {
        $payload = json_encode([
            'ai_model' => $aiModel,
            'ai_expansion_model' => $aiExpansionModel,
        ]);

        DB::table('scolta_config')->upsert(
            [['key' => 'amazee_models', 'value' => $payload, 'updated_at' => now()]],
            ['key'],
            ['value', 'updated_at'],
        );
    }

    /**
     * Load previously auto-selected model names.
     *
     * @return array{ai_model: string, ai_expansion_model: string}|null
     */
    public function loadModels(): ?array
    {
        $row = DB::table('scolta_config')->where('key', 'amazee_models')->first();
        if ($row === null) {
            return null;
        }

        $data = json_decode($row->value, true);
        if (! is_array($data)) {
            return null;
        }

        return [
            'ai_model' => $data['ai_model'] ?? '',
            'ai_expansion_model' => $data['ai_expansion_model'] ?? '',
        ];
    }
}
