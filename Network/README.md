# toolspacks/utils / Network (底层网络通信引擎)

## 模块定位
为 toolspacks/utils 提供最底层的网络请求能力。

## 核心 API 调用范例

### 1. 极致轻量化的 `HttpHelper`
专门用来调用 JSON RESTful API，免去了 Guzzle 的笨重实例化过程。

```php
use app\utils\Network\HttpHelper;

$url = 'https://api.example.com/data';
$payload = ['key' => 'value'];
$customHeaders = [
    'Authorization: Bearer Token_123',
    'X-Custom-Req: 1'
];

try {
    // 参数：URL, 数据, 超时秒数, 自定义 Header, 是否校验证书
    // 对接公网 HTTPS 端点时最后一个参数必须传 true
    $response = HttpHelper::postJson($url, $payload, 10, $customHeaders, true);
    // 成功返回 string，失败抛出 Exception
} catch (\Exception $e) {
    echo "请求崩溃: " . $e->getMessage();
}
```

### 2. 纯 Socket 发信 `SmtpHelper`
不需要安装 PHPMailer，直接和 SMTP 服务器进行 TCP/IP 对话。

```php
use app\utils\Network\SmtpHelper;

try {
    SmtpHelper::send(
        'smtp.gmail.com', 465, // Host, Port
        'user@gmail.com', 'app_password', // 账号, 密码
        'user@gmail.com', 'client@test.com', // 发件人, 收件人
        '这是测试邮件标题',
        '<h1>这是 HTML 邮件正文</h1>'
    );
} catch (\Exception $e) {
    Log::error("邮件发送异常：" . $e->getMessage());
}
```

## 注意事项

证书校验：`HttpHelper` 的 `verifySsl` 参数默认 `false`，用于兼容内网自签证书或证书已过期的加速节点，这是历史默认值，未传参的调用方行为不变。连接公网 HTTPS 端点时必须显式传 `true`，否则请求可被中间人攻击。

编码：`SmtpHelper` 内部已按 `=?UTF-8?B?...?=` 标准编码邮件标题。调用端直接传中文即可，不要自行做 base64 转换，否则会引发二次乱码。