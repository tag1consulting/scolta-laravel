<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Cache;

use Illuminate\Support\Facades\Cache;
use Tag1\Scolta\Cache\CacheDriverInterface;

/**
 * Laravel Cache facade adapter for AiEndpointHandler.
 *
 * @since 0.2.0
 *
 * @stability experimental
 */
class LaravelCacheDriver implements CacheDriverInterface
{
    public function get(string $key): mixed
    {
        return Cache::get($key);
    }

    public function set(string $key, mixed $value, int $ttlSeconds): void
    {
        Cache::put($key, $value, $ttlSeconds);
    }
}
