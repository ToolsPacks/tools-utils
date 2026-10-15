<?php
declare(strict_types=1);

namespace app\utils\Concurrency;

use think\facade\Cache;

final class LockHelper
{
    public static function lock(string $key, int $ttl, string $token): bool
    {
        $store = Cache::store('redis');
        $redis = $store->handler();
        $prefix = (string)config('cache.stores.redis.prefix', 'cfdi_cache:');
        $result = $redis->set($prefix . 'lock:' . $key, $token, 'EX', max(1, $ttl), 'NX');
        return $result === true || $result === 'OK';
    }

    public static function unlock(string $key, string $token): void
    {
        $store = Cache::store('redis');
        $redis = $store->handler();
        $prefix = (string)config('cache.stores.redis.prefix', 'cfdi_cache:');
        $script = "if redis.call('get', KEYS[1]) == ARGV[1] then return redis.call('del', KEYS[1]) else return 0 end";
        $redis->eval($script, 1, $prefix . 'lock:' . $key, $token);
    }
}
