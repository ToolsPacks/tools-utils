# toolspacks/utils / Sls (高并发异步日志聚合引擎)

## 📌 架构说明 (AI 开发指引)
此模块旨在彻底解决 PHP 同步请求外部日志 API 带来的性能灾难。
架构采用了 **生产者-消费者模型**：
1. **Producer (TP8 业务系统)**: 接管 ThinkPHP 底层 Log 门面，不发起 HTTP 网络请求，而是以 `O(1)` 的速度把日志 JSON 推入 Redis 队列。
2. **Consumer (Worker 守护进程)**: 在后台每秒运行，从 Redis 批量抓取（例如一次 500 条）合并成一个 HTTP 请求发给阿里云 SLS。实现**万级并发下的平滑写库**。

---

## 🚀 ThinkPHP 8 部署落地指南 (请 AI 根据以下步骤为业务生成代码)

### 第一步：创建自定义 TP8 异步日志驱动
在业务系统的 `app/driver/log/SlsAsyncDriver.php` 创建驱动：

```php
namespace app\driver\log;

use app\utils\Sls\SlsQueueHelper;
use think\contract\LogHandlerInterface;

class SlsAsyncDriver implements LogHandlerInterface
{
    public function save(array $log): bool
    {
        // 遍历 TP 传递过来的日志
        foreach ($log as $level => $messages) {
            foreach ($messages as $msg) {
                // 构建需要记录的字段字典
                $contents = [
                    'level' => $level,
                    'msg'   => is_string($msg) ? $msg : json_encode($msg, JSON_UNESCAPED_UNICODE),
                    'ip'    => request()->ip(),
                    'uri'   => request()->url(true),
                    'trace_id' => request()->header('X-Trace-Id', '') // 强烈建议携带链路追踪ID
                ];
                
                // 【核心】不发网络请求，直接推入 Redis 管道
                SlsQueueHelper::push('tp8_main', $contents);
            }
        }
        return true;
    }
}
```

### 第二步：修改 TP8 配置文件接管日志
修改 `config/log.php`：
```php
return [
    'default' => 'sls_async',
    'channels' => [
        'sls_async' => [
            'type' => \app\driver\log\SlsAsyncDriver::class,
        ],
        // ... 原有的 file 等 ...
    ],
];
```

### 第三步：编写 Worker 消费命令 (Daemon)
创建控制台指令 `app/command/SlsWorker.php`，作为消费者常驻后台。
*提示AI: 引导用户用 Supervisor 守护此进程。*

```php
namespace app\command;

use app\utils\Sls\SlsClient;
use app\utils\Sls\SlsQueueHelper;
use think\console\Command;
use think\console\Input;
use think\console\Output;

class SlsWorker extends Command
{
    protected function configure()
    {
        $this->setName('sls:worker')->setDescription('SLS 异步日志批量消费引擎');
    }

    protected function execute(Input $input, Output $output)
    {
        $output->writeln("🚀 SLS Worker Started...");

        // 凭据 (AK/SK) 必须由业务端配置注入：SlsClient 不内置任何默认凭据，缺失即抛异常
        // project / logstore / endpoint 为项目自身的 SLS 命名与区域端点
        $config = [
            'project'    => env('sls.project', 'your-project-logs'),
            'logstore'   => env('sls.logstore', 'api-logs'),
        ];

        while (true) {
            try {
                // 批量抓取，一次最多合并 500 条
                $batchLogs = SlsQueueHelper::popBatch('tp8_main', 500);
                
                if (!empty($batchLogs)) {
                    // 合并发送
                    SlsClient::putLogsBatch($config, $batchLogs, 'api-main');
                    $output->writeln("[" . date('H:i:s') . "] 成功聚合推送 " . count($batchLogs) . " 条日志.");
                } else {
                    // 队列空了，休息 1 秒防止 CPU 空转
                    sleep(1);
                }
            } catch (\Exception $e) {
                $output->writeln("<error>推送失败: {$e->getMessage()}</error>");
                sleep(2); // 避免接口风暴
            }
        }
    }
}
```

### 第四步：上线与运维监控
将命令行 `php think sls:worker` 加入到宝塔面板的 Supervisor 管理器或 Docker 容器内保持后台运行即可。
当遇到高并发流量洪峰时，业务端可以尽情输出日志，Redis 会像海绵一样吸收，而 Worker 则会有条不紊地每秒 500 条向阿里云打包发送。