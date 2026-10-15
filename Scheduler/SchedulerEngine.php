<?php
declare(strict_types=1);

namespace app\utils\Scheduler;

use app\utils\Concurrency\LockHelper;
use think\facade\Console;
use think\facade\Queue;
use think\facade\Log;
use Exception;

class SchedulerEngine
{
    /**
     * 运行去中心化调度器
     */
    public static function run(string $jsonConfigPath): void
    {
        if (!file_exists($jsonConfigPath)) {
            throw new Exception("调度配置文件丢失: {$jsonConfigPath}");
        }

        $config = json_decode(file_get_contents($jsonConfigPath), true);
        if (!$config || !is_array($config)) {
            // 【交互性增强】提取底层解析错误原因，精确定位反馈给运维人员，秒级排错
            $jsonErrorMsg = json_last_error_msg();
            throw new Exception("调度器读取失败，[crontab.json] 存在语法错误: {$jsonErrorMsg}。请检查是否多写了尾部逗号或使用了单引号。");
        }

        foreach ($config as $task) {
            // 1. 状态与时间表达式校验
            if (isset($task['status']) && $task['status'] === 0) continue;

            $cronExpr = $task['cron'] ?? '* * * * *';
            if (!CronParser::isDue($cronExpr)) continue;

            $taskName = $task['name'] ?? 'UnknownTask';
            $isWithoutOverlapping = $task['without_overlapping'] ?? true;
            $timeout = $task['timeout'] ?? 300;

            // 使用配置特征生成唯一锁，防分布式节点对冲
            $lockKey = 'wujie_cron_' . md5(json_encode($task));
            $lockToken = uniqid('', true);

            // 2. 防击穿抢锁
            if ($isWithoutOverlapping) {
                if (!LockHelper::lock($lockKey, $timeout, $lockToken)) {
                    continue; // 锁被占用，说明上个周期的任务还没跑完，跳过本周期
                }
            }

            // 3. 执行任务路由分发
            try {
                // 模式 A: 原生 TP8 控制台指令直调 (取代低效危险的 exec)
                if (!empty($task['command'])) {
                    Console::call($task['command']);
                }
                // 模式 B: 投递到后端消息队列
                elseif (!empty($task['job'])) {
                    $queue = $task['queue'] ?? 'erp_default';
                    $data = $task['data'] ?? [];
                    Queue::push($task['job'], $data, $queue);
                }
                // 模式 C: 实现 WujieTaskInterface 的纯净类
                elseif (!empty($task['class']) && class_exists($task['class'])) {
                    $className = $task['class'];
                    if (is_subclass_of($className, WujieTaskInterface::class)) {
                        // 交由容器实例化，保证构造函数依赖（如统一账本服务）能被自动注入
                        $instance = \think\Container::getInstance()->make($className, [], true);
                        $instance->execute();
                    }
                }
            } catch (\Throwable $e) {
                Log::error("调度器任务 [{$taskName}] 执行异常: " . $e->getMessage());
            } finally {
                // 4. 无论成功失败，原子级释放排他锁
                if ($isWithoutOverlapping) {
                    LockHelper::unlock($lockKey, $lockToken);
                }
            }
        }
    }
}