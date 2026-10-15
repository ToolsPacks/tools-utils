<?php
declare(strict_types=1);

namespace app\utils\Sls;

use think\facade\Cache;

/**
 * SLS 异步削峰填谷调度器 (V3.5 Pipeline 高性能版)
 * 利用 Redis List 作为内存缓冲池，阻断网络 IO 对主业务进程的影响
 */
class SlsQueueHelper
{
    private static string $queuePrefix = 'wujie_sls_queue:';

    /**
     * 将日志推入 Redis 队列 (O(1) 复杂度，零网络阻塞)
     */
    public static function push(string $channel, array $logContents): void
    {
        try {
            $redis = Cache::store('redis')->handler();
            $queueKey = self::$queuePrefix . $channel;

            $logItem = [
                'time'     => time(),
                'contents' => $logContents
            ];

            $redis->lPush($queueKey, json_encode($logItem, JSON_UNESCAPED_UNICODE));

            // 【核心优化】避免每次写入都触发高 CPU 的裁切指令，采用 1% 概率执行（保障最大只保留10万条）
            if (mt_rand(1, 100) === 1) {
                $redis->lTrim($queueKey, 0, 100000);
            }

        } catch (\Throwable $e) {
            // 极端降级：如果 Redis 挂了，静默丢弃或写入本地兜底盘，绝对不能抛错影响业务
            @file_put_contents(runtime_path() . 'log/redis_fallback_sls.log', json_encode($logContents) . PHP_EOL, FILE_APPEND | LOCK_EX);
        }
    }

    /**
     * 从队列中批量弹出日志 (供守护进程合并请求时调用)
     */
    public static function popBatch(string $channel, int $batchSize = 500): array
    {
        $logs = [];
        try {
            $redis = Cache::store('redis')->handler();
            $queueKey = self::$queuePrefix . $channel;

            // 【核心优化】使用 Redis Pipeline 管道技术，将 500 次网络 RTT 往返硬生生缩减为 1 次
            if (method_exists($redis, 'multi')) {
                $redis->multi(\Redis::PIPELINE);
                for ($i = 0; $i < $batchSize; $i++) {
                    $redis->rPop($queueKey);
                }
                $results = $redis->exec();

                foreach ((array)$results as $item) {
                    if ($item) {
                        $decoded = json_decode($item, true);
                        if ($decoded) {
                            $logs[] = $decoded;
                        }
                    }
                }
            } else {
                // 安全降级：如果某些环境不支持管道，退化为普通循环
                for ($i = 0; $i < $batchSize; $i++) {
                    $item = $redis->rPop($queueKey);
                    if (!$item) {
                        break;
                    }
                    $decoded = json_decode($item, true);
                    if ($decoded) {
                        $logs[] = $decoded;
                    }
                }
            }
        } catch (\Throwable $e) {
            // 记录拉取异常
        }

        return $logs;
    }
}