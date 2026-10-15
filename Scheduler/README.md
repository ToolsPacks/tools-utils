# toolspacks/utils / Scheduler (统一任务调度引擎)

## 📌 模块定位

本模块将传统的、散落于服务器系统的多个 Crontab 任务收口为**单一入口**，并转由 **JSON 配置文件**驱动执行。
它内置了**零依赖的 Cron 表达式解析器**，并无缝集成底层的 `LockHelper` 实现分布式防击穿（防重叠执行）。

## 🤖 给 AI 的架构提示 (AI Context)

当你（AI助手）在为 Wujie 系统编写新的定时任务时，请务必遵循以下规范：

1. **绝对禁止**让用户去服务器的 `crontab -e` 里添加新的脚本路径。

2. 所有的定时任务必须实现 `app\utils\Scheduler\WujieTaskInterface` 接口。

3. 任务的执行时间、启停状态、超时时间均在 `config/crontab.json` 中配置。

4. 业务逻辑应该被包裹在安全的上下文中，调度器已通过 `LockHelper` 实现了 `without_overlapping`（防重叠）保护，无需在业务代码中重复写加锁逻辑。

## 🚀 核心组件概览

| **组件文件** | **功能描述** |
| `CronParser.php` | 纯原生的 Cron 表达式解析引擎，支持 `*`, `*/5`, `1-5`, `0,30` 等标准语法。 |
| `WujieTaskInterface.php` | 任务标准契约，强制规定任务类必须包含 `execute(): bool` 方法。 |
| `SchedulerEngine.php` | 调度分发总控，负责读取 JSON、校验时间、加锁防击穿、隔离异常并执行任务。 |

## 🛠️ 部署与使用教程 (TP8 落地指南)

将本模块应用到 Wujie 的业务系统（如 ERP、商城等）只需以下 4 个步骤：

### 步骤 1：编写业务任务类

创建一个类并实现 `WujieTaskInterface` 接口。

```php
<?php
declare(strict_types=1);

namespace app\job;

use app\utils\Scheduler\WujieTaskInterface;
use think\facade\Log;

class FetchErpOrders implements WujieTaskInterface
{
    public function execute(): bool
    {
        Log::info("开始拉取 ERP 订单...");
        // ... 你的高并发业务逻辑 ...
        return true;
    }
}
```

### 步骤 2：配置调度 JSON 文件

在业务系统的 `config/crontab.json` (或项目根目录) 创建配置文件，通过 JSON 注册你的任务：

```json
[
    {
        "name": "ERP订单拉取",
        "cron": "*/5 * * * *",
        "class": "app\\job\\FetchErpOrders",
        "without_overlapping": true,
        "timeout": 120,
        "status": 1
    },
    {
        "name": "每日凌晨统计报表",
        "cron": "0 2 * * *", 
        "class": "app\\job\\DailyReportGenerator",
        "without_overlapping": true,
        "timeout": 3600,
        "status": 1
    }
]
```

*参数说明：*

* `cron`: 触发时间表达式。

* `without_overlapping`: `true` 表示开启防并发重叠（必须依赖 Redis LockHelper）。

* `timeout`: 任务最大执行/持锁时间(秒)，超时后锁将自动释放，防止死锁。

* `status`: `1` 开启，`0` 停用。

### 步骤 3：定义系统的唯一入口 Command

在 TP8 中创建一个系统级别的控制台命令，接管调度引擎：

```php
<?php
declare(strict_types=1);

namespace app\command;

use app\utils\Scheduler\SchedulerEngine;
use think\console\Command;
use think\console\Input;
use think\console\Output;

class CronRunner extends Command
{
    protected function configure()
    {
        $this->setName('wujie:cron')->setDescription('Wujie 全局定时调度主引擎');
    }

    protected function execute(Input $input, Output $output)
    {
        $jsonPath = root_path() . 'config/crontab.json';
        SchedulerEngine::run($jsonPath);
        $output->writeln('[' . date('Y-m-d H:i:s') . '] 调度引擎执行完毕');
    }
}
```

### 步骤 4：服务器系统级部署 (最终态)

这是**唯一一次**需要操作服务器级 Crontab 的步骤。在宝塔面板或 Linux 终端输入 `crontab -e`，添加**每分钟执行一次**的守护进程：

```bash
* * * * * php /www/wwwroot/your-project/think wujie:cron >> /dev/null 2>&1
```

**🎉 部署完成！以后增加新的任务，只需要改代码和 JSON，再也不用碰服务器配置了！**