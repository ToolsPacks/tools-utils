# toolspacks/utils

无界系项目共用的 PHP 工具包，覆盖云存储直传、底层网络传输、并发原子锁、日志投递、任务调度、推送投递与开票对接等基础能力。可用版本见本仓库的 git tag。

## 命名空间契约

本包使用宿主应用的 `app\utils\` 命名空间，这是刻意选择而非疏漏：它使本包可以直接替代各项目里原先手写的 `app/utils` 目录。项目把该目录换成 composer 依赖后，业务代码中的 `use app\utils\...` 无需任何改动。

Composer 的 PSR-4 按最长前缀匹配，`app\utils\` 优先于项目自身的 `app\`，两级映射可以共存，`app\AppService` 这类项目内类不受影响。

同一项目内不得再保留一份本地 `app/utils` 目录。若两者同时存在，同名类会出现歧义，在 `--classmap-authoritative` 等优化模式下 composer 会报 Ambiguous class resolution。

## 依赖

运行时依赖 ThinkPHP 8 的 facade（Log、Cache、Console、Queue）、容器，以及 `env()`、`config()`、`runtime_path()` 等框架全局函数，因此本包不是框架无关包。并发锁与日志队列模块还需要 Redis 扩展，不用这两个模块时可不必安装。

## 传输层安全

网络传输默认校验 TLS 证书。1.4.0 之前默认为关闭，用于对接内网自签证书节点；该默认值会把所有公网调用暴露在中间人攻击下，因此已改为默认开启。

对接自签证书网关时显式关闭校验：

- `HttpHelper::request` / `get` / `postJson` 传入 `verifySsl = false`；
- 云存储配置数组传入 `'verify_ssl' => false`；
- `PushMaster\PushClient` 构造时传入 `$verifySsl = false`。

## 并发锁语义

`Concurrency\LockHelper` 同时支持 phpredis 5.x 与 Predis 两种客户端。锁键为 `{cache.stores.redis.prefix}lock:{key}`；Predis 侧前缀由客户端自动追加，包内不再重复拼接。

抢占失败返回 `false`，调用方按普通分支处理；Redis 连接不可用时抛出异常，不退回文件缓存——降级路径没有原子性，会在并发下击穿。解锁走 Lua 校验 token，只有持有者本人能释放。

## 模块内容

- `Network`：原生 cURL 多功能请求引擎，以及纯 Socket 的 SMTP 发信。
- `CloudStorage`：S3/阿里云/腾讯云/Cloudflare R2 等对象存储的预签名直传、大文件分片断点续传与流式推流。
- `Concurrency`：基于 Redis 的分布式原子锁。
- `Sls`：阿里云日志服务批量直发，以及基于 Redis 列表的削峰队列。
- `Scheduler`：Cron 表达式解析与去中心化调度引擎。
- `PushMaster`：推送网关客户端，覆盖推送、任务、订阅、模板、指标与结果回调。
- `WujieHub`：无界云盘接入 SDK。
- `Invoice`：开票系统对接客户端。
- 根目录：`ArrayHelper`、`StringHelper`、`ConfigEncryption`、`WujieClient` 门面。
