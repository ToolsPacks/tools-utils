# Wujie-Utils / Network (底层网络通信引擎)

## 📌 模块定位
为 Wujie-Utils 提供最底层的网络请求能力。

---

## 🚀 核心 API 调用范例

### 1. 极致轻量化的 `HttpHelper`
专门用来调用 JSON RESTful API，免去了 Guzzle 的笨重实例化过程。

```php
use app\utils\Network\HttpHelper;

$url = '[https://api.example.com/data](https://api.example.com/data)';
$payload = ['key' => 'value'];
$customHeaders = [
    'Authorization: Bearer Token_123',
    'X-Custom-Req: 1'
];

try {
    // 参数：URL, 数据, 超时秒数, 自定义 Header
    $response = HttpHelper::postJson($url, $payload, 10, $customHeaders);
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

## ⚠️ 开发注意事项 (AI 避坑指南)
1. **证书阻断处理**：`HttpHelper` 默认配置了 `CURLOPT_SSL_VERIFYPEER = false`。由于云盘经常要对接一些使用内网自签证书、或过期证书的加速节点，这一配置**绝对不允许修改**为 `true`，否则会导致底层大面积通信阻断。
2. **编码问题**：`SmtpHelper` 内部已经对标题进行了 `=?UTF-8?B?...?=` 的标准编码。调用端直接传中文即可，**不要自己做 base64 转换**，否则会引发二次乱码。