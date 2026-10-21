<?php
declare(strict_types=1);

/**
 * PushClient 离线自检：不发起网络请求，只校验请求组装与响应归一化。
 *
 * 用法：
 *   在包仓库内：php tests/pushclient-selftest.php
 *   在宿主项目：php vendor/toolspacks/utils/tests/pushclient-selftest.php /www/wwwroot/<站点目录>
 */

use app\utils\Network\HttpHelper;
use app\utils\PushMaster\PushClient;

$root = $argv[1] ?? '';

if ($root !== '' && is_file($root . '/vendor/autoload.php')) {
    require $root . '/vendor/autoload.php';
} else {
    spl_autoload_register(static function (string $class): void {
        $prefix = 'app\\utils\\';
        if (!str_starts_with($class, $prefix)) {
            return;
        }
        $file = dirname(__DIR__) . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    });
}

/**
 * 录制请求并返回预设响应的替身客户端
 */
final class RecordingPushClient extends PushClient
{
    /** @var array<int,array{method:string,url:string,body:?array,headers:array}> */
    public array $calls = [];

    /** @var array<int,array{status:int,body:string}> */
    public array $responses = [];

    protected function transport(string $method, string $url, ?array $body, array $headers): array
    {
        $this->calls[] = ['method' => $method, 'url' => $url, 'body' => $body, 'headers' => $headers];

        return array_shift($this->responses) ?? [
            'status' => 200,
            'body'   => json_encode(['code' => 0, 'biz_code' => 0, 'msg' => 'ok', 'data' => []]),
        ];
    }
}

$checks = [];

$client = new RecordingPushClient('https://push.example.com/', 'Push_KEY');

// 单条推送：路径段、请求头、载荷
$client->send('标题', '正文', 'wechat_mini', ['touser' => 'oA', 'page' => 'p/1']);
$call = $client->calls[0];
$checks['send_path']    = $call['method'] === 'POST' && $call['url'] === 'https://push.example.com/api/v1/send/Push_KEY';
$checks['send_header']  = in_array('X-Send-Key: Push_KEY', $call['headers'], true);
$checks['send_payload'] = $call['body'] === [
    'desp'    => '正文',
    'title'   => '标题',
    'channel' => 'wechat_mini',
    'options' => ['touser' => 'oA', 'page' => 'p/1'],
];

// 超长正文截断
$client->calls = [];
$client->send('标题', str_repeat('字', 10050));
$truncated = (string) ($client->calls[0]['body']['desp'] ?? '');
$checks['truncate'] = mb_strlen($truncated, 'UTF-8') > 10000
    && str_contains($truncated, '已由 SDK 截断')
    && mb_strlen($truncated, 'UTF-8') < 10120;

// 任务查询与重试
$client->calls = [];
$client->status('TASK_20261022_ABC');
$client->retry('TASK_20261022_ABC');
$checks['status_path'] = $client->calls[0]['method'] === 'GET'
    && $client->calls[0]['url'] === 'https://push.example.com/api/v1/tasks/TASK_20261022_ABC';
$checks['retry_path'] = $client->calls[1]['method'] === 'POST'
    && $client->calls[1]['url'] === 'https://push.example.com/api/v1/tasks/TASK_20261022_ABC/retry';

// 批量推送
$client->calls = [];
$client->batchSend([['touser' => 'oA'], 'oB'], '批量', '正文', '', []);
$checks['batch_payload'] = $client->calls[0]['url'] === 'https://push.example.com/api/v1/batch/send'
    && $client->calls[0]['body']['recipients'] === [['touser' => 'oA'], 'oB']
    && $client->calls[0]['body']['title'] === '批量';

$client->calls = [];
$empty = $client->batchSend([]);
$checks['batch_empty'] = $empty['biz_code'] === 1000 && $client->calls === [];

// 订阅额度
$client->calls = [];
$client->subscriptions(['oA', ' oB ', 'oA'], 'wx1', 'T1');
parse_str((string) parse_url($client->calls[0]['url'], PHP_URL_QUERY), $query);
$checks['subscriptions_query'] = $query === [
    'openids'     => 'oA,oB',
    'app_id'      => 'wx1',
    'template_id' => 'T1',
];

// 模板元数据
$client->calls = [];
$client->templates('wechat_official_subscribe', 'wx1');
parse_str((string) parse_url($client->calls[0]['url'], PHP_URL_QUERY), $query);
$checks['templates_query'] = ($query['channel'] ?? '') === 'wechat_official_subscribe'
    && ($query['app_id'] ?? '') === 'wx1';

// 微信指标
$client->calls = [];
$client->wechatStats(14, 'wechat_mini');
parse_str((string) parse_url($client->calls[0]['url'], PHP_URL_QUERY), $query);
$checks['stats_query'] = ($query['days'] ?? '') === '14' && ($query['channel'] ?? '') === 'wechat_mini';

// 回调配置
$client->calls = [];
$client->saveWebhook('https://biz.example.com/cb', '', false);
$checks['webhook_save'] = $client->calls[0]['body'] === [
    'url'       => 'https://biz.example.com/cb',
    'is_active' => 0,
];

$client->calls = [];
$client->webhookDeliveries('TASK_1', 50);
parse_str((string) parse_url($client->calls[0]['url'], PHP_URL_QUERY), $query);
$checks['deliveries_query'] = ($query['task_id'] ?? '') === 'TASK_1' && ($query['limit'] ?? '') === '50';

// 传输异常归一化
$failing = new RecordingPushClient('https://push.example.com', 'Push_KEY');
$failing->responses = [['status' => 0, 'body' => '']];
$failed = $failing->status('TASK_1');
$checks['transport_error'] = $failed['biz_code'] === -1 && $failed['code'] === 500;

// 服务端业务码透传
$rejecting = new RecordingPushClient('https://push.example.com', 'Push_KEY');
$rejecting->responses = [[
    'status' => 400,
    'body'   => json_encode(['code' => 400, 'biz_code' => 1002, 'msg' => 'Invalid SendKey', 'data' => []]),
]];
$rejected = $rejecting->send('标题', '正文');
$checks['biz_code_passthrough'] = $rejected['biz_code'] === 1002 && $rejected['msg'] === 'Invalid SendKey';

// 缺凭证时短路，不发起请求
$noKey = new RecordingPushClient('https://push.example.com', '');
$missing = $noKey->send('标题', '正文');
$checks['missing_key'] = $missing['biz_code'] === 1001 && $noKey->calls === [];

// TLS 默认值
$request = new ReflectionMethod(HttpHelper::class, 'request');
$params  = $request->getParameters();
$checks['tls_default'] = ($params[5]->getDefaultValue() ?? null) === true;

$constructor = new ReflectionMethod(PushClient::class, '__construct');
$ctorParams  = $constructor->getParameters();
$checks['client_tls_default'] = ($ctorParams[3]->getDefaultValue() ?? null) === true;

$failedChecks = array_keys(array_filter($checks, static fn (bool $ok): bool => !$ok));

foreach ($checks as $name => $ok) {
    echo ($ok ? '通过' : '失败') . '  ' . $name . PHP_EOL;
}

if (!empty($failedChecks)) {
    fwrite(STDERR, '自检失败：' . implode(', ', $failedChecks) . PHP_EOL);
    exit(1);
}

echo '全部通过，共 ' . count($checks) . ' 项' . PHP_EOL;
exit(0);
