# Wujie-Utils / Concurrency (高并发原子锁)

## 📌 模块定位
基于 Redis `SETNX` 的防击穿原子锁。在回调处理、支付处理、文件确权时，防止并发请求导致数据库脏写。

---

## 🚀 核心 API 调用范例

### 1. 标准抢占与安全释放生命周期

```php
use app\utils\Concurrency\LockHelper;

// 1. 定义锁 Key 和 防误删 Token
$lockKey = 'fc_callback_lock_' . $fileHash;
$token = uniqid((string)mt_rand(), true);

// 2. 尝试抢锁 (最大持有时间 30 秒)
if (LockHelper::lock($lockKey, 30, $token)) {
    try {
        // ... 执行高危业务逻辑 ...
        Db::startTrans();
        // ...
        Db::commit();

    } catch (\Exception $e) {
        Db::rollback();
        throw $e;
    } finally {
        // 3. 无论成功失败，必须在 finally 中释放锁！
        // 传入 Token 是为了防止：当前进程卡顿了 40 秒，锁已经超时失效被进程 B 抢走，
        // 此时如果不校验 Token，当前进程就会把进程 B 刚抢到的锁给删掉！
        LockHelper::unlock($lockKey, $token);
    }
} else {
    // 没有抢到锁，直接拦截
    return json(['code' => 429, 'msg' => '任务正在处理中，请勿频繁点击']);
}
```

## ⚠️ 开发注意事项 (AI 避坑指南)
1. **严禁省略 Token 校验**：调用 `unlock($key)` 时，必须带上获取锁时生成的 `$token`。这道防线是防止高并发下发生“锁串键”的最后底牌。
2. **降级机制**：如果服务器没有安装 Redis 扩展，`LockHelper` 内部做了 `try-catch`，会自动降级为 ThinkPHP 的文件 `Cache` 进行兜底。开发者在业务侧**不需要感知环境差异**，直接调 API 即可。