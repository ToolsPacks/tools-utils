<?php
declare(strict_types=1);

namespace app\utils\Sls;

use Exception;

/**
 * 纯原生阿里云 SLS (Log Service) 客户端 (V3.5 Deflate 高压缩版)
 * 零第三方 SDK 依赖，采用 JSON 协议批量直发，内置 Aliyun V1 HMAC-SHA1 签名引擎
 */
class SlsClient
{
    // 默认备用，通常使用后台动态传入的 Endpoint
    public const DEFAULT_ENDPOINT = 'cn-shenzhen.log.aliyuncs.com';

    public static function putLogsBatch(array $config, array $logs, string $topic = 'wujie-tp8'): bool
    {
        if (empty($logs)) {
            return true;
        }

        $project = $config['project'] ?? '';
        $logstore = $config['logstore'] ?? '';
        $endpoint = rtrim($config['endpoint'] ?? self::DEFAULT_ENDPOINT, '/');

        $ak = $config['access_key'] ?? '';
        $sk = $config['secret_key'] ?? '';

        if (empty($ak) || empty($sk) || empty($project) || empty($logstore)) {
            throw new Exception("SLS Client 异常：缺乏必要的 AK, SK 或 Project 鉴权凭证配置。");
        }

        $endpointDomain = preg_replace('/^https?:\/\//', '', $endpoint);
        $host = "{$project}.{$endpointDomain}";
        $path = "/logstores/{$logstore}/shards/lb";
        $url = "http://{$host}{$path}";

        // 1. 组装符合 SLS JSON 协议的数据载荷
        $payload = [
            '__topic__'  => $topic,
            '__source__' => gethostname() ?: 'wujie-server',
            '__logs__'   => []
        ];

        foreach ($logs as $log) {
            // 【核心修复】：SLS API 强制要求时间戳键名必须为 __time__
            $logItem = [
                '__time__' => isset($log['time']) ? (int)$log['time'] : time()
            ];

            // 剔除自定义传入的 time 字段，防止与 __time__ 冲突冗余
            if (isset($log['time'])) {
                unset($log['time']);
            }

            // 【核心修复】：取消 contents 的嵌套，直接将所有底层驱动推过来的数组（如 level, uri, msg）平铺映射
            foreach ($log as $k => $v) {
                $logItem[(string)$k] = is_string($v) ? $v : json_encode($v, JSON_UNESCAPED_UNICODE);
            }

            $payload['__logs__'][] = $logItem;
        }

        $body = json_encode($payload, JSON_UNESCAPED_UNICODE);

        // 2. 启用 Deflate 算法，极大地削减公网通信带宽开销
        $compressedBody = gzcompress($body);
        $contentMd5 = strtoupper(md5($compressedBody));
        $date = gmdate('D, d M Y H:i:s \G\M\T');

        // 3. 构造 SLS 规范请求头
        $headers = [
            'x-log-apiversion'      => '0.6.0',
            'x-log-signaturemethod' => 'hmac-sha1',
            'x-log-bodyrawsize'     => (string)strlen($body), // 必须是压缩前的原始大小
            'x-log-compresstype'    => 'deflate', // 告知阿里云服务端进行反解压
            'Date'                  => $date,
            'Content-MD5'           => $contentMd5,
            'Content-Type'          => 'application/json',
            'Host'                  => $host
        ];

        // 4. 提取头进行排序并计算签名
        $logHeaders = [];
        foreach ($headers as $k => $v) {
            if (strpos(strtolower($k), 'x-log-') === 0) {
                $logHeaders[strtolower($k)] = $v;
            }
        }
        ksort($logHeaders);
        $canonicalizedLOGHeaders = '';
        foreach ($logHeaders as $k => $v) {
            $canonicalizedLOGHeaders .= "{$k}:{$v}\n";
        }

        $canonicalizedResource = $path;
        $stringToSign = "POST\n{$contentMd5}\napplication/json\n{$date}\n{$canonicalizedLOGHeaders}{$canonicalizedResource}";
        $signature = base64_encode(hash_hmac('sha1', $stringToSign, $sk, true));

        // 5. 注入最终鉴权头
        $headers['Authorization'] = "LOG {$ak}:{$signature}";

        $curlHeaders = [];
        foreach ($headers as $k => $v) {
            $curlHeaders[] = "{$k}: {$v}";
        }

        // 6. 执行底层 Curl 通信
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $compressedBody); // 发送压缩后的数据体
        curl_setopt($ch, CURLOPT_HTTPHEADER, $curlHeaders);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        // 返回 200 即为投递成功
        if ($httpCode >= 200 && $httpCode < 300) {
            return true;
        }

        throw new Exception("SLS 通信被拒 HTTP {$httpCode} - {$response} - {$error}");
    }
}