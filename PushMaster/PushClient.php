<?php
declare(strict_types=1);

namespace app\utils\PushMaster;

use app\utils\Network\HttpHelper;

/**
 * PushMaster 统一推送客户端 SDK
 * 供 Wujie 内部其它业务系统或脚本通用的发信工具，通过 API 将请求压入 PushMaster 中枢队列
 */
class PushClient
{
    protected string $endpoint;
    protected string $sendKey;
    protected int $timeout; // 【新增】超时控制属性

    /**
     * 构造函数
     * @param string $endpoint PushMaster中心网关地址，如 https://push.your-domain.com
     * @param string $sendKey  用户后台生成的专属 SendKey
     * @param int $timeout     请求超时时间(秒)，默认 5 秒，防止 PushMaster 网络异常拖垮调用方业务
     */
    public function __construct(string $endpoint, string $sendKey, int $timeout = 5)
    {
        $this->endpoint = rtrim($endpoint, '/');
        $this->sendKey = $sendKey;
        $this->timeout = $timeout;
    }

    /**
     * 发起推送请求 (异步进入 PushMaster 队列处理)
     *
     * @param string $title   消息标题
     * @param string $content 消息正文(支持 Markdown)
     * @param string $channel 强制指定使用的通道(留空则使用用户在后台设置的默认通道)
     * @param array  $options 动态扩展参数(优先级最高，可用于动态覆盖后台填写的默认收件人、Webhook地址等)
     * @return array 返回标准化结果 ['code' => 0, 'msg' => '...', 'data' => [...]]
     */
    public function send(string $title, string $content, string $channel = '', array $options = []): array
    {
        if (empty($this->endpoint) || empty($this->sendKey)) {
            return ['code' => 400, 'msg' => 'PushMaster Endpoint or SendKey is missing.'];
        }

        $apiUrl = $this->endpoint . '/api/v1/send/' . $this->sendKey;

        // 【健壮性增强】超长恶意/异常文本熔断裁剪保护。
        // 防止由于日志堆栈或大段报文超过 Nginx/API 网关单次最大负载（如触发 413 错误），导致无法通知到开发者
        if (mb_strlen($content, 'UTF-8') > 10000) {
            $content = mb_substr($content, 0, 10000, 'UTF-8') . "\n\n...\n\n> ⚠️ [系统警告：源正文过长 (超1万字)，为了保证网关通讯安全，已被 SDK 客户端自动裁剪。]";
        }

        // 组装标准基础载荷
        $payload = [
            'title'   => $title,
            'desp'    => $content,
        ];

        // 如果业务代码指定了通道，则压入载荷
        if (!empty($channel)) {
            $payload['channel'] = $channel;
        }

        // 核心：将业务代码动态传入的 options 打包发送，供服务端的 Push.php 进行高优先级合并校验
        if (!empty($options)) {
            $payload['options'] = $options;
        }

        try {
            // 【优化】传入设定的超时时间
            $jsonResponse = HttpHelper::postJson($apiUrl, $payload, $this->timeout);

            if ($jsonResponse === false) {
                return ['code' => 500, 'msg' => 'Network error: Failed to reach PushMaster Server.'];
            }

            $result = json_decode($jsonResponse, true);
            return $result ?? ['code' => 500, 'msg' => 'Invalid JSON response from PushMaster API.'];

        } catch (\Throwable $e) {
            // 【优化】升级为 Throwable，能够拦截 PHP8 的 TypeErrors 等致命错误，避免 SDK 崩溃影响主业务
            return ['code' => 500, 'msg' => 'PushClient Exception: ' . $e->getMessage()];
        }
    }
}