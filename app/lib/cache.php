<?php
declare(strict_types=1);

/**
 * Small Redis adapter with a no-op fallback.
 * Redis is an optimization here, never a source of truth.
 */
function dumpRedis(): ?object {
    static $attempted = false;
    static $redis = null;

    if ($attempted) return $redis;
    $attempted = true;

    if (!env_bool('REDIS_ENABLED', true) || !class_exists('Redis')) return null;

    try {
        $client = new Redis();
        $host = env('REDIS_HOST', '127.0.0.1');
        $port = (int)env('REDIS_PORT', '6379');
        $timeout = max(0.05, (float)env('REDIS_TIMEOUT', '0.2'));

        if (!@$client->connect($host, $port, $timeout)) return null;

        $password = env('REDIS_PASSWORD', '');
        if ($password !== '' && !@$client->auth($password)) return null;

        $database = (int)env('REDIS_DATABASE', '0');
        if ($database > 0 && !@$client->select($database)) return null;
        if (defined('Redis::OPT_READ_TIMEOUT')) {
            @$client->setOption(Redis::OPT_READ_TIMEOUT, $timeout);
        }

        $redis = $client;
    } catch (Throwable $e) {
        $redis = null;
    }

    return $redis;
}

function dumpRedisKey(string $key): string {
    return env('REDIS_PREFIX', 'dump:') . $key;
}

function dumpCacheGet(string $key): ?string {
    $redis = dumpRedis();
    if (!$redis) return null;

    try {
        $value = @$redis->get(dumpRedisKey($key));
        return is_string($value) ? $value : null;
    } catch (Throwable $e) {
        return null;
    }
}

function dumpCacheSet(string $key, string $value, int $ttl): bool {
    $redis = dumpRedis();
    if (!$redis || $ttl < 1) return false;

    try {
        return (bool)@$redis->setex(dumpRedisKey($key), $ttl, $value);
    } catch (Throwable $e) {
        return false;
    }
}

function dumpCacheDelete(string ...$keys): void {
    $redis = dumpRedis();
    if (!$redis || !$keys) return;

    try {
        $redisKeys = array_map('dumpRedisKey', $keys);
        @$redis->del($redisKeys);
    } catch (Throwable $e) {
        // Cache failures must never affect the request.
    }
}

function dumpCacheGetJson(string $key): ?array {
    $value = dumpCacheGet($key);
    if ($value === null || $value === '') return null;

    $decoded = json_decode($value, true);
    return is_array($decoded) ? $decoded : null;
}

function dumpCacheSetJson(string $key, array $value, int $ttl): bool {
    $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($encoded === false) return false;
    return dumpCacheSet($key, $encoded, $ttl);
}

function dumpCacheRememberJson(string $key, int $ttl, callable $resolver): array {
    $cached = dumpCacheGetJson($key);
    if ($cached !== null) return $cached;

    $value = $resolver();
    if (!is_array($value)) $value = [];
    dumpCacheSetJson($key, $value, $ttl);
    return $value;
}

/**
 * Returns null when Redis is unavailable so callers can use their old fallback.
 */
function dumpRedisRateLimit(string $key, int $max, int $windowSec): ?bool {
    $redis = dumpRedis();
    if (!$redis) return null;

    try {
        $redisKey = dumpRedisKey('rate:' . $key);
        $count = (int)@$redis->incr($redisKey);
        if ($count === 1) @$redis->expire($redisKey, $windowSec);
        return $count <= $max;
    } catch (Throwable $e) {
        return null;
    }
}

function dumpSessionCacheKey(string $token): string {
    return 'session:' . hash('sha256', $token);
}
