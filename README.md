# toolspacks/utils

无界系项目共用的 PHP 工具包，覆盖云存储直传、底层网络传输、并发原子锁、日志投递、任务调度、推送投递与开票对接等基础能力。可用版本见本仓库的 git tag。

## 命名空间契约

本包使用宿主应用的 `app\utils\` 命名空间，这是刻意选择而非疏漏：它使本包可以直接替代各项目里原先手写的 `app/utils` 目录。项目把该目录换成 composer 依赖后，业务代码中的 `use app\utils\...` 无需任何改动。

Composer 的 PSR-4 按最长前缀匹配，`app\utils\` 优先于项目自身的 `app\`，两级映射可以共存，`app\AppService` 这类项目内类不受影响。

同一项目内不得再保留一份本地 `app/utils` 目录。若两者同时存在，同名类会出现歧义，在 `--classmap-authoritative` 等优化模式下 composer 会报 Ambiguous class resolution。

## 依赖

运行时依赖 ThinkPHP 8 的 facade（Log、Cache、Console、Queue）、容器，以及 `env()`、`config()`、`runtime_path()` 等框架全局函数，因此本包不是框架无关包。并发锁与日志队列模块还需要 Redis 扩展，不用这两个模块时可不必安装。

## 传输层安全

网络传输默认关闭 TLS 证书校验，用于对接内网自签证书节点。这是既有行为，为不破坏已有调用方而保持不变。对接公网 HTTPS 端点时必须显式开启校验，否则存在中间人攻击风险：

- `HttpHelper::request` / `get` / `postJson` 传入 `verifySsl = true`；
- 云存储配置数组传入 `'verify_ssl' => true`。

## 模块内容

- `Network`：原生 cURL 多功能请求引擎，以及纯 Socket 的 SMTP 发信。
- `CloudStorage`：S3/阿里云/腾讯云/Cloudflare R2 等对象存储的预签名直传、大文件分片断点续传与流式推流。
- `Concurrency`：基于 Redis 的分布式原子锁。
- `Sls`：阿里云日志服务批量直发，以及基于 Redis 列表的削峰队列。
- `Scheduler`：Cron 表达式解析与去中心化调度引擎。
- `PushMaster`：向 PushMaster 中枢投递推送任务的客户端。
- `WujieHub`：无界云盘接入 SDK。
- `Invoice`：开票系统对接客户端。
- 根目录：`ArrayHelper`、`StringHelper`、`ConfigEncryption`、`WujieClient` 门面。
