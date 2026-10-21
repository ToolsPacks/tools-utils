<?php
declare(strict_types=1);

namespace app\utils\Concurrency;

use think\facade\Cache;

/**
 * 基于 Redis 的分布式锁。
 *
 * 同时兼容 phpredis 5.x 与 Predis：两者的 set() 选项形式、eval() 参数顺序不同，
 * 这里按客户端类型分派。锁键按宿主缓存前缀隔离；Predis 由客户端自动加前缀，
 * 此处不再重复拼接（think 的 Redis 驱动只在 getCacheKey() 加前缀，
 * handler() 返回的 phpredis 连接没有设置 OPT_PREFIX）。
 *
 * Redis 不可用时抛出异常，不做文件缓存降级：降级路径没有原子性，会击穿并发写。
 */
final class LockHelper
{
    private const UNLOCK_SCRIPT = "if redis.call('GET', KEYS[1]) == ARGV[1] then return redis.call('DEL', KEYS[1]) else return 0 end";

    /**
     * 抢占锁。返回 false 表示已被他人持有；Redis 连接异常向上抛出。
     */
    public static function lock(string $key, int $ttl = 60, string $token = '1'): bool
    {
        $redis  = self::handler();
        $result = $redis->set(self::redisKey($redis, $key), $token, ['nx', 'ex' => max(1, $ttl)]);

        return $result === true || $result === 'OK';
    }

    /**
     * 释放锁。只有 token 匹配才删除，避免误删他人持有的锁。
     */
    public static function unlock(string $key, string $token = '1'): void
    {
        $redis    = self::handler();
        $redisKey = self::redisKey($redis, $key);

        if (self::isPredis($redis)) {
            $redis->eval(self::UNLOCK_SCRIPT, 1, $redisKey, $token);

            return;
        }

        $redis->eval(self::UNLOCK_SCRIPT, [$redisKey, $token], 1);
    }

    private static function handler(): object
    {
        return Cache::store('redis')->handler();
    }

    private static function isPredis(object $redis): bool
    {
        return class_exists('\Predis\Client', false) && $redis instanceof \Predis\Client;
    }

    private static function redisKey(object $redis, string $key): string
    {
        $prefix = self::isPredis($redis) ? '' : (string) config('cache.stores.redis.prefix', '');

        return $prefix . 'lock:' . $key;
    }
}
