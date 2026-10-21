# PushMaster 客户端

`PushMaster` 是内部统一的推送网关。`PushClient` 封装网关的开放接口，供业务系统投递消息、查询任务、管理结果回调。

## 安装与初始化

```php
use app\utils\PushMaster\PushClient;

$client = new PushClient('https://push.example.com', 'Push_xxxxxxxxxxxx');
```

构造函数参数：

| 参数 | 类型 | 默认值 | 说明 |
| --- | --- | --- | --- |
| `$endpoint` | string | 无 | 网关地址，末尾斜杠会被裁掉 |
| `$sendKey` | string | 无 | 控制台生成的 SendKey |
| `$timeout` | int | 5 | 单次请求超时（秒） |
| `$verifySsl` | bool | true | 是否校验 HTTPS 证书，仅在对接自签证书的内网网关时传 false |

除单条推送外，其余接口都用 `X-Send-Key` 请求头传凭证；单条推送的 SendKey 还必须出现在路径段中，这是服务端路由的定义，客户端已经处理。

## 返回值

所有方法返回同一结构：

```php
[
    'code'     => 0,      // HTTP 数值，仅作兼容字段
    'biz_code' => 0,      // 业务码，判断结果只看这个
    'msg'      => 'Accepted',
    'data'     => [],     // 业务数据
]
```

`biz_code` 说明：

| biz_code | 含义 |
| --- | --- |
| 0 | 成功受理 |
| -1 | SDK 侧未拿到有效响应：网络失败、超时或响应不是 JSON |
| 1000 | 请求无效 |
| 1001 | 未携带 SendKey |
| 1002 | SendKey 无效或账号禁用 |
| 1003 | 触发限流 |
| 1004 | 正文为空 |
| 1005 | 未指定通道且账号没有默认通道 |
| 1006 | 通道未配置或已禁用 |
| 1007 | 通道凭证或接收人缺失 |
| 1008 | 任务不存在或无权查看 |
| 1009 | 接口路径不存在或方法不匹配 |
| 2000 | 服务端内部错误 |

## 单条推送

```php
$result = $client->send('订单发货通知', '您的订单已发货', 'wechat_official_subscribe', [
    'touser'   => 'oABCDEFG',
    'scene'    => 'order_shipped',
    'jump_url' => 'https://mall.example.com/order/123',
    'data'     => [
        'thing1'            => ['value' => '顺丰速运'],
        'character_string2' => ['value' => 'SF123456789'],
    ],
]);

if ($result['biz_code'] === 0) {
    $taskId = $result['data']['task_id'];
}
```

- `$channel` 留空时使用账号的默认通道；新账号默认通道为空，此时服务端返回 1005。
- `$options` 的优先级高于控制台保存的通道配置，可用于动态指定接收人、跳转地址等。
- 正文超过 10000 字符会被 SDK 截断并追加提示，避免触发网关请求体上限。

### 幂等

`$options` 支持两个幂等键：

| 键 | 说明 |
| --- | --- |
| `idempotent_key` | 显式幂等键，命中时返回原任务，`data.duplicated` 为 true |
| `idempotent_ttl` | 幂等窗口（秒），上限 30 天；不传则显式键永久去重 |

不传 `idempotent_key` 时，网关按账号、通道、标题、正文、scene、touser 生成键，且只拦截进行中的任务。

## 批量推送

```php
$result = $client->batchSend(
    [
        'oAAAA',
        ['touser' => 'oBBBB', 'desp' => '您的订单已出库'],
        ['touser' => 'oCCCC', 'data' => ['thing1' => ['value' => '已送达']]],
    ],
    '订单提醒',
    '订单状态已更新',
    'wechat_official_subscribe',
    ['scene' => 'order_updated']
);
```

单次最多 200 个接收人。每个接收人独立建任务、独立重试，任意一条校验失败时整批拒绝。同批次内按 `touser` 去重。

响应中的 `data` 含 `batch_id`、`total`、`accepted`、`queued_failed` 与逐接收人的 `task_id`。

## 任务查询与重试

```php
$status = $client->status($taskId);

$retried = $client->retry($taskId);
```

状态取值：`pending`、`sending`、`retrying`、`success`、`failed`、`uncertain`。终态为 `success`、`failed`、`uncertain`。

只有 `failed` 与 `uncertain` 可以重试。重试生成新任务，原任务保留，新任务的 `data.retried_from` 指向来源任务。

## 订阅额度

```php
$quota = $client->subscriptions(['oAAAA', 'oBBBB'], 'wx1234567890', 'TMPL_001');
```

单次最多 100 个 openid。响应包含 `list`（逐条订阅记录）、`by_openid`（按接收人分组）、`missing`（没有任何记录的人）。

一次性订阅看 `remaining`，长期订阅的 `remaining` 为 null。

## 模板元数据

```php
$meta = $client->templates('wechat_official_subscribe', 'wx1234567890');
```

`data.default` 是默认模板，`data.scenes` 是各场景模板，每项包含 `template_id`、`type`（2 一次性订阅、3 长期订阅、null 模板消息）与 `fields`（字段名、类型、长度上限）。

该接口只读网关本地配置，不发起微信调用，可用于发送前校验字段长度。

## 微信通道指标

```php
$stats = $client->wechatStats(7, 'wechat_official_subscribe');
```

返回 `channels`（成功率、耗时）、`errors`（失败码分布）、`receipts`（下发回执）、`quota`（订阅额度汇总）。`days` 取值 1 到 30。

## 结果回调

```php
$client->saveWebhook('https://biz.example.com/push/callback');
$config = $client->webhook();
$logs   = $client->webhookDeliveries($taskId, 50);
```

首次保存未传 `secret` 时网关自动生成，并只在本次响应中返回一次，后续无法读取。修改地址不传 `secret` 会沿用原密钥。

回调请求带以下头：

```text
X-Push-Event: task.finished
X-Push-Timestamp: 1761097800
X-Push-Signature: sha256=...
```

签名算法：`sha256=` 加 `hash_hmac('sha256', timestamp . '.' . body, secret)`，`body` 为原始请求体。接收方应校验时间戳偏差（建议 5 分钟）并使用恒定时间比较。

投递失败按 30、120、300 秒退避重试，重试用尽只记日志，不影响任务状态。

## 通道标识

| 标识 | 通道 | 可覆盖的 options |
| --- | --- | --- |
| `wecom` | 企业微信自建应用 | `corpid`、`corpsecret`、`agentid`、`touser` |
| `wecom_bot` | 企业微信群机器人 | `webhook_url` |
| `dingtalk` | 钉钉群机器人 | `webhook_url`、`secret` |
| `feishu` | 飞书群机器人 | `webhook_url`、`secret` |
| `email` | SMTP 邮件 | `smtp_host`、`smtp_port`、`smtp_user`、`smtp_pass`、`from_email`、`to_email` |
| `webhook` | 自定义 Webhook | `webhook_url`、`headers` |
| `wechat_official` | 微信服务号模板消息 | `app_id`、`app_secret`、`template_id`、`touser`、`jump_url`、`data`、`miniprogram`、`client_msg_id` |
| `wechat_official_subscribe` | 微信服务号订阅通知 | 同上，另支持 `scene`、`skip_subscription_check` |
| `wechat_mini` | 微信小程序订阅消息 | `app_id`、`app_secret`、`template_id`、`touser`、`page`、`miniprogram_state`、`lang`、`data`、`client_msg_id` |

键名包含 `secret`、`pass`、`token`、`api_key`、`encoding_aes_key` 的值不会落库，只用于本次投递。

## 升级说明

1.4.0 起：

- 新增任务查询、任务重试、批量推送、订阅额度、模板元数据、微信指标、回调配置与投递记录方法；
- 所有方法返回体统一补 `biz_code`，SDK 侧传输失败返回 -1；
- HTTPS 证书校验默认开启，`HttpHelper::request` / `get` / `postJson` 的 `$verifySsl` 默认值同步改为 true。

对接自签证书网关的调用方需要显式传 `$verifySsl = false`，或继续使用 1.3.x。
