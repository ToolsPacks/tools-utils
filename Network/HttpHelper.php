<?php
declare(strict_types=1);

namespace app\utils\Network;

class HttpHelper
{
    /**
     * 统一多功能原生 cURL 请求引擎 (GET/POST/PUT/DELETE 兼支持 Multipart 文件上传)
     * 完全替代臃肿的 GuzzleHttp，杜绝外部依赖
     */
    public static function request(string $method, string $url, array|string|object|null $data = null, int $timeout = 10, array $customHeaders = [], bool $verifySsl = true): array
    {
        $ch = curl_init($url);
        $method = strtoupper($method);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true); // 获取响应头 (用于截获 x-version)
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $verifySsl);
        // 与 VERIFYPEER 保持一致：关闭校验时不再比对证书主机名
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $verifySsl ? 2 : 0);

        if ($data !== null) {
            // 智能判定是否为 Multipart 文件上传模式
            if (is_array($data) && isset($data['multipart_file'])) {
                $postFields = $data['fields'] ?? [];
                if (isset($data['multipart_file']['path']) && file_exists($data['multipart_file']['path'])) {
                    $fileParamName = $data['multipart_file']['name'] ?? 'file';
                    $postFields[$fileParamName] = new \CURLFile(
                        $data['multipart_file']['path'],
                        mime_content_type($data['multipart_file']['path']) ?: 'application/octet-stream',
                        $data['multipart_file']['filename'] ?? basename($data['multipart_file']['path'])
                    );
                }
                curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
                // 移除自带的 Content-Type，让 cURL 自动生成带有 boundary 的 multipart/form-data 头
            } else {
                // 处理 JSON 或原生 x-www-form-urlencoded 字符串
                // 兼容 object，让 stdClass 等对象也被安全地 json_encode (例如空对象变 "{}")
                $payload = (is_array($data) || is_object($data)) ? json_encode($data, JSON_UNESCAPED_UNICODE) : $data;
                curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
                $customHeaders[] = 'Content-Length: ' . strlen((string)$payload);
                if (is_array($data) || is_object($data)) {
                    $customHeaders[] = 'Content-Type: application/json; charset=utf-8';
                }
            }
        }

        if (!empty($customHeaders)) {
            $headerMap = [];
            foreach ($customHeaders as $h) {
                $parts = explode(':', $h, 2);
                if (count($parts) === 2) {
                    $headerMap[trim(strtolower($parts[0]))] = trim($parts[1]);
                } else {
                    $headerMap[] = $h;
                }
            }
            $finalHeaders = [];
            foreach ($headerMap as $k => $v) {
                $finalHeaders[] = is_string($k) ? "{$k}: {$v}" : $v;
            }
            curl_setopt($ch, CURLOPT_HTTPHEADER, $finalHeaders);
        }

        $response = curl_exec($ch);
        $errNo = curl_errno($ch);
        $errMsg = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);

        curl_close($ch);

        if ($response === false) {
            throw new \Exception("cURL Error ({$errNo}): {$errMsg}");
        }

        $headerStr = substr($response, 0, $headerSize);
        $body = substr($response, $headerSize);

        $headers = [];
        // 【健壮性增强】修复多重报文头BUG。当遇到 HTTP 100 Continue 或 301/302 代理重定向时，cURL 会返回多个头部块
        // 我们必须以 \r\n\r\n 分隔，并且只取最后一个有效区块进行解析，否则会导致提取到虚假的 Header 数据
        $headerBlocks = explode("\r\n\r\n", trim($headerStr));
        $lastHeaderBlock = array_pop($headerBlocks);
        // 如果最后一个区块是空的（极端情况），往前再找一个
        if (empty($lastHeaderBlock) && !empty($headerBlocks)) {
            $lastHeaderBlock = array_pop($headerBlocks);
        }

        foreach (explode("\r\n", $lastHeaderBlock) as $line) {
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
        }

        return [
            'status'  => $httpCode,
            'body'    => $body,
            'headers' => $headers
        ];
    }

    public static function get(string $url, array $headers = [], bool $verifySsl = true, int $timeout = 10): string
    {
        $res = self::request('GET', $url, null, $timeout, $headers, $verifySsl);
        return $res['body'];
    }

    public static function postJson(string $url, array $data, int $timeout = 10, array $customHeaders = [], bool $verifySsl = true): string|bool
    {
        try {
            $res = self::request('POST', $url, $data, $timeout, $customHeaders, $verifySsl);
            return $res['body'];
        } catch (\Exception $e) {
            return false;
        }
    }
}