# PushMaster SDK 接入文档 (V1.2)

`PushMaster` 是 Wujie 项目群的统一消息推送中枢。该 SDK (`PushClient`) 用于业务系统快速对接网关，实现消息的异步调度与多渠道分发。

## 🛠️ 核心逻辑说明

1. **配置优先级**：SDK 调用时传入的 `options` 参数具有 **最高优先级**。如果 `options` 中缺少某个配置（如 Webhook URL 或接收人 OpenID），系统将自动降级使用用户在控制台设置的默认配置。
2. **强制校验机制**：所有通道必须在后台“启用”且具备有效的“接收地址/凭证”后方可发送。若检测到配置缺失，API 将同步拦截并返回 `400` 错误。
3. **【重要】空通道拦截规则**：新注册的账号**初始默认推送通道为“无”**。如果您在调用 SDK 且未明确指定 `$channel` 参数，并且后台也未设置默认通道，服务端将直接拦截并抛出 `400` 报错。
4. **异步分发防阻塞**：SDK 仅负责将请求提交至网关，默认超时 5 秒。后端 Dispatcher 引擎会处理复杂的网络 IO 和最多 3 次的错误重试，不会拖垮前端业务。

---

## 📦 客户端初始化

```php
use app\utils\PushMaster\PushClient;

// 建议从配置文件中读取
$endpoint = '[https://push.your-domain.com](https://push.your-domain.com)'; 
$sendKey  = 'Push_xxxxxxxxxxxx'; 

// 第三参数为可选超时时间 (默认5秒)
$client = new PushClient($endpoint, $sendKey, 5);
```

---

## 💡 典型调用场景

### 1. 极简调用 (依赖后台配置)
```php
$client->send('系统日报', "### 今日概览\n- 新增用户: 120");
```

### 2. 动态发送微信服务号模板消息 (新增特性)
可以动态指定接收人的 OpenID，以及点击模板后跳转的 URL，甚至自定义模板渲染数据。
```php
$client->send('订单发货通知', '您的订单已发货，请注意查收。', 'wechat_official', [
    'touser'   => 'oABCDxxxxxx_Specific_OpenID', // 动态覆盖默认接收人
    'jump_url' => '[https://mall.example.com/order/123](https://mall.example.com/order/123)',
    'data'     => [
        'first'    => ['value' => '您的订单已发货！', 'color' => '#173177'],
        'keyword1' => ['value' => '顺丰速运', 'color' => '#333333'],
        'keyword2' => ['value' => 'SF123456789', 'color' => '#333333'],
        'remark'   => ['value' => '点击查看物流详情', 'color' => '#666666']
    ]
]);
```

### 3. 动态发送微信小程序订阅消息 (新增特性)
```php
$client->send('活动中奖通知', '恭喜您获得一等奖！', 'wechat_mini', [
    'touser'            => 'oMINIxxxxxx_Specific_OpenID',
    'page'              => 'pages/index/index?id=123',
    'miniprogram_state' => 'formal', // formal:正式版 trial:体验版 developer:开发版
    'data'              => [
        'thing1' => ['value' => '年终大促抽奖'],
        'thing2' => ['value' => 'iPhone 15 Pro Max'],
        'time3'  => ['value' => date('Y-m-d H:i:s')]
    ]
]);
```

---

## 🗂️ 通道字典 (Channel Slugs)

| 标识符 | 通道名称 | `options` 可选覆盖项 (高优) |
| :--- | :--- | :--- |
| `wecom` | 企业微信自建应用 | `corpid`, `corpsecret`, `agentid`, `touser` |
| `wecom_bot` | 企微群机器人 | `webhook_url` |
| `dingtalk` | 钉钉群机器人 | `webhook_url`, `secret` |
| `feishu` | 飞书群机器人 | `webhook_url`, `secret` |
| `email` | SMTP 邮件 | `smtp_host`, `smtp_user`, `smtp_pass`, `to_email`, `from_email` |
| `webhook` | 自定义通用 | `webhook_url`, `headers` (数组) |
| `wechat_official` | 微信服务号 | `app_id`, `app_secret`, `template_id`, `touser`, `jump_url`, `data`(数组) |
| `wechat_mini` | 微信小程序 | `app_id`, `app_secret`, `template_id`, `touser`, `page`, `miniprogram_state`, `data`(数组) |