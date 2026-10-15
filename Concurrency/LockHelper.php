<?php
declare(strict_types=1);

namespace app\utils\Concurrency;

use think\facade\Cache;

/**
 * 分布式高并发原子锁引擎
 * [核心修复] 彻底移除非 Redis 下的文件 Cache 兜底逻辑，强制阻断在高并发下无原子性的伪降级，防止系统击穿脏写。
 */
class LockHelper
{
    /**
     * 获取原子锁
     * @param string $key 锁的唯一标识
     * @param int $ttl 锁的超时时间(秒)
     * @param string $token 锁的持有者凭证(防误删)
     * @return bool 是否成功获取到锁
     */
    public static function lock(string $key, int $ttl = 60, string $token = '1'): bool
    {
        try {
            $redis = Cache::store('redis')->handler();
            if (method_exists($redis, 'set')) {
                return $redis->set($key, $token, ['nx', 'ex' => $ttl]) ? true : false;
            }
        } catch (\Throwable $e) {}

        // [核心修复] 如果系统未配置 Redis 或 Redis 宕机，直接抛错阻断，严禁降级使用不安全的普通缓存
        throw new \Exception('LockHelper 强依赖 Redis 环境，当前 Redis 不可用，为防止并发击穿脏写，已拒绝本次排他锁分配。');
    }

    /**
     * 释放原子锁 (支持 Token 安全校验，防止 A 进程超时释放了 B 进程的锁)
     */
    public static function unlock(string $key, string $token = '1'): void
    {
        try {
            $redis = Cache::store('redis')->handler();
            if (method_exists($redis, 'get') && $redis->get($key) === $token) {
                $redis->del($key);
            }
        } catch (\Throwable $e) {}

        // 移除了 Cache 的 unlock 降级操作
    }
}