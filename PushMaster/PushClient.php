<?php
declare(strict_types=1);

namespace app\utils\PushMaster;

use app\utils\Network\HttpHelper;

/**
 * PushMaster 推送网关客户端。
 *
 * 覆盖开放接口的推送、任务、订阅、模板、指标与回调端点。
 *
 * 约定：
 *  - 单条推送的 SendKey 必须出现在路径段中（服务端路由如此定义），其余接口只用 X-Send-Key 请求头；
 *  - 返回体统一为 ['code' => int, 'biz_code' => int, 'msg' => string, 'data' => array]；
 *  - biz_code 取自服务端，SDK 自身未拿到响应时返回 BIZ_TRANSPORT_ERROR；
 *  - 业务结果只按 biz_code 判断，code 仅为 HTTP 数值兼容字段。
 */
class PushClient
{
    /** SDK 侧错误：网络失败、超时或响应不是合法 JSON */
    public const BIZ_TRANSPORT_ERROR = -1;

    /** 服务端业务码：请求无效 */
    public const BIZ_INVALID_REQUEST = 1000;

    /** 服务端业务码：未携带 SendKey */
    public const BIZ_MISSING_SEND_KEY = 1001;

    /** 批量推送单次接收人上限，与服务端一致 */
    public const MAX_BATCH_RECIPIENTS = 200;

    /** 单次订阅查询 openid 上限，与服务端一致 */
    public const MAX_SUBSCRIPTION_OPENIDS = 100;

    /** 正文长度上限，超出部分截断后发送，避免触发网关请求体限制 */
    private const MAX_CONTENT_LENGTH = 10000;

    protected string $endpoint;
    protected string $sendKey;
    protected int $timeout;
    protected bool $verifySsl;

    /**
     * @param string $endpoint  PushMaster 网关地址，例如 https://push.example.com
     * @param string $sendKey   控制台生成的 SendKey
     * @param int    $timeout   单次请求超时（秒），默认 5，避免网关异常拖垮调用方
     * @param bool   $verifySsl 是否校验 HTTPS 证书，默认 true；仅在对接自签证书的内网网关时关闭
     */
    public function __construct(string $endpoint, string $sendKey, int $timeout = 5, bool $verifySsl = true)
    {
        $this->endpoint  = rtrim($endpoint, '/');
        $this->sendKey   = $sendKey;
        $this->timeout   = max(1, $timeout);
        $this->verifySsl = $verifySsl;
    }

    /**
     * 单条推送，入队后立即返回。
     *
     * @param string $title   标题，空串按服务端默认值处理
     * @param string $content 正文
     * @param string $channel 通道标识，空串使用账号默认通道
     * @param array  $options 通道参数，优先级高于控制台配置
     */
    public function send(string $title, string $content, string $channel = '', array $options = []): array
    {
        if ($this->endpoint === '') {
            return $this->fail(self::BIZ_INVALID_REQUEST, 'PushMaster Endpoint is missing.', 400);
        }

        if ($this->sendKey === '') {
            return $this->fail(self::BIZ_MISSING_SEND_KEY, 'PushMaster SendKey is missing.', 400);
        }

        $payload = ['desp' => $this->truncate($content)];

        if ($title !== '') {
            $payload['title'] = $title;
        }
        if ($channel !== '') {
            $payload['channel'] = $channel;
        }
        if (!empty($options)) {
            $payload['options'] = $options;
        }

        return $this->request('POST', '/api/v1/send/' . rawurlencode($this->sendKey), [], $payload);
    }

    /**
     * 批量推送，每条接收人独立任务、独立重试。
     *
     * @param array<int,string|array> $recipients 接收人列表，元素为接收人标识或含 touser/title/desp/data/options 的数组
     * @param string                  $title      公共标题
     * @param string                  $content    公共正文，接收人未单独提供正文时使用
     * @param string                  $channel    通道标识
     * @param array                   $options    公共通道参数
     */
    public function batchSend(array $recipients, string $title = '', string $content = '', string $channel = '', array $options = []): array
    {
        if (empty($recipients)) {
            return $this->fail(self::BIZ_INVALID_REQUEST, 'recipients 不能为空', 400);
        }

        if (count($recipients) > self::MAX_BATCH_RECIPIENTS) {
            return $this->fail(
                self::BIZ_INVALID_REQUEST,
                '单次批量最多 ' . self::MAX_BATCH_RECIPIENTS . ' 个接收人',
                400
            );
        }

        $payload = ['recipients' => array_values($recipients)];

        if ($title !== '') {
            $payload['title'] = $title;
        }
        if ($content !== '') {
            $payload['desp'] = $this->truncate($content);
        }
        if ($channel !== '') {
            $payload['channel'] = $channel;
        }
        if (!empty($options)) {
            $payload['options'] = $options;
        }

        return $this->request('POST', '/api/v1/batch/send', [], $payload);
    }

    /**
     * 查询任务状态。
     */
    public function status(string $taskId): array
    {
        $taskId = trim($taskId);
        if ($taskId === '') {
            return $this->fail(self::BIZ_INVALID_REQUEST, 'task_id 不能为空', 400);
        }

        return $this->request('GET', '/api/v1/tasks/' . rawurlencode($taskId));
    }

    /**
     * 人工重试，仅 failed / uncertain 状态可重试。
     */
    public function retry(string $taskId): array
    {
        $taskId = trim($taskId);
        if ($taskId === '') {
            return $this->fail(self::BIZ_INVALID_REQUEST, 'task_id 不能为空', 400);
        }

        return $this->request('POST', '/api/v1/tasks/' . rawurlencode($taskId) . '/retry');
    }

    /**
     * 查询订阅额度。
     *
     * @param array<int,string>|string $openids 接收人，数组或逗号分隔字符串
     */
    public function subscriptions(array|string $openids, string $appId = '', string $templateId = ''): array
    {
        $list = is_array($openids) ? $openids : explode(',', $openids);
        $list = array_values(array_unique(array_filter(
            array_map(static fn ($item) => trim((string) $item), $list),
            static fn ($item) => $item !== ''
        )));

        if (empty($list)) {
            return $this->fail(self::BIZ_INVALID_REQUEST, 'openid 不能为空', 400);
        }

        if (count($list) > self::MAX_SUBSCRIPTION_OPENIDS) {
            return $this->fail(
                self::BIZ_INVALID_REQUEST,
                '单次最多查询 ' . self::MAX_SUBSCRIPTION_OPENIDS . ' 个 openid',
                400
            );
        }

        $query = ['openids' => implode(',', $list)];
        if ($appId !== '') {
            $query['app_id'] = $appId;
        }
        if ($templateId !== '') {
            $query['template_id'] = $templateId;
        }

        return $this->request('GET', '/api/v1/subscriptions', $query);
    }

    /**
     * 查询模板元数据。
     *
     * @param string $channel wechat_official / wechat_official_subscribe / wechat_mini
     */
    public function templates(string $channel = 'wechat_official_subscribe', string $appId = ''): array
    {
        $query = ['channel' => $channel];
        if ($appId !== '') {
            $query['app_id'] = $appId;
        }

        return $this->request('GET', '/api/v1/templates', $query);
    }

    /**
     * 查询微信通道运行指标。
     *
     * @param int $days 统计窗口天数，服务端限制 1 到 30
     */
    public function wechatStats(int $days = 7, string $channel = ''): array
    {
        $query = ['days' => $days];
        if ($channel !== '') {
            $query['channel'] = $channel;
        }

        return $this->request('GET', '/api/v1/wechat/stats', $query);
    }

    /**
     * 查询结果回调配置，密钥不返回。
     */
    public function webhook(): array
    {
        return $this->request('GET', '/api/v1/webhook');
    }

    /**
     * 保存结果回调配置。
     *
     * 首次保存未提供 secret 时服务端自动生成，并只在本次响应中返回一次。
     */
    public function saveWebhook(string $url, string $secret = '', bool $active = true): array
    {
        $url = trim($url);
        if ($url === '') {
            return $this->fail(self::BIZ_INVALID_REQUEST, '回调 url 不能为空', 400);
        }

        $payload = [
            'url'       => $url,
            'is_active' => $active ? 1 : 0,
        ];
        if ($secret !== '') {
            $payload['secret'] = $secret;
        }

        return $this->request('POST', '/api/v1/webhook', [], $payload);
    }

    /**
     * 查询回调投递记录。
     *
     * @param string $taskId 按任务过滤，空串表示不过滤
     * @param int    $limit  返回条数，服务端限制 1 到 100
     */
    public function webhookDeliveries(string $taskId = '', int $limit = 20): array
    {
        $query = ['limit' => $limit];
        if ($taskId !== '') {
            $query['task_id'] = $taskId;
        }

        return $this->request('GET', '/api/v1/webhook/deliveries', $query);
    }

    /**
     * 生成微信扫码绑定二维码。
     *
     * @param string $externalType 外部对象类型，如 tenant/member
     * @param string $externalId   外部对象标识
     * @param string $externalName 展示名，可为空
     * @param string $appId        指定公众号，空串使用账号下启用的订阅通知通道
     * @param int    $expireSeconds 有效期（60-2592000）
     */
    public function bindQr(
        string $externalType,
        string $externalId,
        string $externalName = '',
        string $appId = '',
        int $expireSeconds = 600
    ): array {
        $externalType = trim($externalType);
        $externalId   = trim($externalId);

        if ($externalType === '' || $externalId === '') {
            return $this->fail(self::BIZ_INVALID_REQUEST, 'external_type 与 external_id 不能为空', 400);
        }

        $payload = [
            'external_type'  => $externalType,
            'external_id'    => $externalId,
            'expire_seconds' => max(60, min(2592000, $expireSeconds)),
        ];
        if ($externalName !== '') {
            $payload['external_name'] = $externalName;
        }
        if ($appId !== '') {
            $payload['app_id'] = $appId;
        }

        return $this->request('POST', '/api/v1/bind/qrcode', [], $payload);
    }

    /** 查询扫码进度：waiting / bound / expired / invalid。 */
    public function bindStatus(string $scene): array
    {
        $scene = trim($scene);
        if ($scene === '') {
            return $this->fail(self::BIZ_INVALID_REQUEST, 'scene 不能为空', 400);
        }

        return $this->request('GET', '/api/v1/bind/status', ['scene' => $scene]);
    }

    /**
     * 查询绑定关系列表。
     *
     * @param array{external_type?:string,external_id?:string,status?:string,page?:int,limit?:int} $filters
     */
    public function bindList(array $filters = []): array
    {
        return $this->request('GET', '/api/v1/bind/list', $filters);
    }

    /** 解绑一条绑定关系。 */
    public function bindUnbind(int $id): array
    {
        if ($id <= 0) {
            return $this->fail(self::BIZ_INVALID_REQUEST, '绑定记录 id 不能为空', 400);
        }

        return $this->request('POST', '/api/v1/bind/unbind', [], ['id' => $id]);
    }

    /**
     * 按外部对象反查可用 openid。
     *
     * @return array{count:int,openids:array}
     */
    public function bindResolve(string $externalType, string $externalId): array
    {
        $externalType = trim($externalType);
        $externalId   = trim($externalId);

        if ($externalType === '' || $externalId === '') {
            return $this->fail(self::BIZ_INVALID_REQUEST, 'external_type 与 external_id 不能为空', 400);
        }

        return $this->request('POST', '/api/v1/bind/resolve', [], [
            'external_type' => $externalType,
            'external_id'   => $externalId,
        ]);
    }

    /**
     * 组装并发送请求，把响应归一化为固定结构
     */
    protected function request(string $method, string $path, array $query = [], ?array $body = null): array
    {
        if ($this->endpoint === '') {
            return $this->fail(self::BIZ_INVALID_REQUEST, 'PushMaster Endpoint is missing.', 400);
        }

        if ($this->sendKey === '') {
            return $this->fail(self::BIZ_MISSING_SEND_KEY, 'PushMaster SendKey is missing.', 400);
        }

        $url = $this->endpoint . $path;
        if (!empty($query)) {
            $url .= '?' . http_build_query($query);
        }

        try {
            $response = $this->transport($method, $url, $body, ['X-Send-Key: ' . $this->sendKey]);
        } catch (\Throwable $e) {
            return $this->fail(self::BIZ_TRANSPORT_ERROR, 'PushMaster transport error: ' . $e->getMessage());
        }

        $decoded = json_decode((string) ($response['body'] ?? ''), true);
        if (!is_array($decoded)) {
            $status = (int) ($response['status'] ?? 0);
            return $this->fail(self::BIZ_TRANSPORT_ERROR, 'Invalid JSON response (HTTP ' . $status . ')');
        }

        $httpStatus = (int) ($response['status'] ?? 0);
        $bizCode    = $decoded['biz_code'] ?? ($httpStatus >= 200 && $httpStatus < 300 ? 0 : self::BIZ_TRANSPORT_ERROR);

        return [
            'code'     => (int) ($decoded['code'] ?? $httpStatus),
            'biz_code' => (int) $bizCode,
            'msg'      => (string) ($decoded['msg'] ?? ''),
            'data'     => is_array($decoded['data'] ?? null) ? $decoded['data'] : [],
        ];
    }

    /**
     * 传输层实现，独立成方法便于测试替换
     *
     * @return array{status:int,body:string,headers:array}
     */
    protected function transport(string $method, string $url, ?array $body, array $headers): array
    {
        return HttpHelper::request($method, $url, $body, $this->timeout, $headers, $this->verifySsl);
    }

    /**
     * 构造失败结果，HTTP 状态仅用于对齐服务端返回习惯
     */
    private function fail(int $bizCode, string $msg, int $httpStatus = 500): array
    {
        return [
            'code'     => $httpStatus,
            'biz_code' => $bizCode,
            'msg'      => $msg,
            'data'     => [],
        ];
    }

    private function truncate(string $content): string
    {
        if (mb_strlen($content, 'UTF-8') <= self::MAX_CONTENT_LENGTH) {
            return $content;
        }

        return mb_substr($content, 0, self::MAX_CONTENT_LENGTH, 'UTF-8')
            . "\n\n[内容超过 " . self::MAX_CONTENT_LENGTH . " 字符上限，已由 SDK 截断]";
    }
}
