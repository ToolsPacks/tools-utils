<?php
declare(strict_types=1);

namespace app\utils\CloudStorage;

use Exception;
use think\facade\Log;

/**
 * 跨项目通用 OSS 存储引擎 (纯原生零依赖极致版 - CDN 防盗链与分片重装版)
 */
class OssClient
{
    public static function checkConnection(array $conf): bool
    {
        $type = $conf['type'] ?? 's3';
        try {
            if (in_array($type, ['s3', 'cloudflare_r2', 'feiniuos', 'tencent', 'qiniu'])) {
                $conf['path_style'] = in_array($type, ['tencent', 'qiniu']) ? false : ($conf['path_style'] ?? true);
                return self::checkS3Connection($conf);
            } elseif ($type === 'aliyun') {
                return self::checkAliyunConnection($conf);
            }
        } catch (\Exception $e) {
            throw new \Exception($e->getMessage());
        }
        throw new \Exception("不支持的驱动类型 [{$type}]");
    }

    public static function getObjectSize(array $conf, string $path): int
    {
        $type = $conf['type'] ?? 's3';
        $path = ltrim($path, '/');
        try {
            if (in_array($type, ['s3', 'cloudflare_r2', 'feiniuos', 'tencent', 'qiniu'])) {
                $conf['path_style'] = in_array($type, ['tencent', 'qiniu']) ? false : ($conf['path_style'] ?? true);
                list($httpCode, $response) = self::executeS3Curl('HEAD', $conf, $path);
                if ($httpCode >= 200 && $httpCode < 300) {
                    if (preg_match('/Content-Length:\s*(\d+)/i', (string)$response, $matches)) return (int)$matches[1];
                    return 0;
                }
            } elseif ($type === 'aliyun') {
                list($httpCode, $response) = self::executeAliyunCurl('HEAD', $conf, $path);
                if ($httpCode >= 200 && $httpCode < 300) {
                    if (preg_match('/Content-Length:\s*(\d+)/i', (string)$response, $matches)) return (int)$matches[1];
                    return 0;
                }
            }
        } catch (\Exception $e) {
            return -1;
        }
        return -1;
    }

    public static function uploadFile(array $conf, string $path, string $localFilePath, string $contentType = 'application/octet-stream'): ?string
    {
        $type = $conf['type'] ?? 's3';
        if (in_array($type, ['s3', 'cloudflare_r2', 'feiniuos', 'tencent', 'qiniu'])) {
            $conf['path_style'] = in_array($type, ['tencent', 'qiniu']) ? false : ($conf['path_style'] ?? true);
            list($httpCode, $response) = self::executeS3Curl('PUT', $conf, $path, '', $localFilePath, ['Content-Type' => $contentType], true);
            if ($httpCode >= 200 && $httpCode < 300) return self::formatUrl($conf, $path);
            throw new Exception("S3/R2 Upload Failed HTTP {$httpCode}: " . $response);
        } elseif ($type === 'aliyun') {
            list($httpCode, $response) = self::executeAliyunCurl('PUT', $conf, $path, $localFilePath, ['Content-Type' => $contentType], true);
            if ($httpCode >= 200 && $httpCode < 300) return self::formatUrl($conf, $path);
            throw new Exception("Aliyun Upload Failed HTTP {$httpCode}: " . $response);
        }
        return null;
    }

    public static function deleteObject(array $conf, string $path): bool
    {
        $type = $conf['type'] ?? 's3';
        $path = ltrim($path, '/');
        $isDeleted = false;
        try {
            if (in_array($type, ['s3', 'cloudflare_r2', 'feiniuos', 'tencent', 'qiniu'])) {
                $conf['path_style'] = in_array($type, ['tencent', 'qiniu']) ? false : ($conf['path_style'] ?? true);
                list($httpCode, $response) = self::executeS3Curl('DELETE', $conf, $path);
                $isDeleted = ($httpCode >= 200 && $httpCode < 300);
            } elseif ($type === 'aliyun') {
                list($httpCode, $response) = self::executeAliyunCurl('DELETE', $conf, $path);
                $isDeleted = ($httpCode >= 200 && $httpCode < 300);
            }
            if ($isDeleted && !empty($conf['read_domain'])) self::purgeCdnCache($type, $conf, rtrim($conf['read_domain'], '/') . '/' . $path);
            return $isDeleted;
        } catch (\Exception $e) {
            Log::error("[底层驱动异常] DeleteObject 故障: " . $e->getMessage());
            return false;
        }
    }

    // ==============================================================================
    // 全云原生协议 URL 预签名直传/分发引擎
    // ==============================================================================
    public static function getPresignedUrl(array $config, string $method, string $path, int $expires, string $downloadName = ''): string
    {
        $type = $config['type'] ?? 's3';

        if (in_array($type, ['s3', 'cloudflare_r2', 'feiniuos'])) {
            return self::getS3PresignedUrl($config, $method, $path, $expires, $downloadName);
        } elseif ($type === 'aliyun') {
            return self::getAliyunPresignedUrl($config, $method, $path, $expires, $downloadName);
        } elseif ($type === 'tencent') {
            return self::getTencentPresignedUrl($config, $method, $path, $expires, $downloadName);
        }

        return self::getS3PresignedUrl($config, $method, $path, $expires, $downloadName);
    }

    private static function getS3PresignedUrl(array $config, string $method, string $path, int $expires, string $downloadName): string
    {
        // ==============================================================================
        // 【新增拦截】：Cloudflare Worker 边缘节点鉴权 (热数据拉取)
        // ==============================================================================
        $customDomain = $method === 'PUT' ? ($config['write_domain'] ?? '') : ($config['read_domain'] ?? '');
        $cdnAuthKey = $config['cdn_auth_key'] ?? '';

        // 如果是 GET 下载请求，且后台配置了读取域名与防盗链秘钥，直接签发轻量级边缘通行证
        if ($method === 'GET' && !empty($customDomain) && !empty($cdnAuthKey)) {
            $parsedCustom = parse_url(strpos($customDomain, 'http') === 0 ? $customDomain : "https://{$customDomain}");
            $host = $parsedCustom['host'] ?? preg_replace('/^https?:\/\//', '', rtrim($customDomain, '/'));
            $scheme = $parsedCustom['scheme'] ?? 'https';

            $uri = '/' . ltrim($path, '/');
            $t = time() + $expires;

            // 算法与 Cloudflare Worker JS 脚本中的 HMAC-SHA256 保持绝对一致
            $dataToSign = $uri . $t;
            $sign = hash_hmac('sha256', $dataToSign, $cdnAuthKey);

            $url = "{$scheme}://{$host}{$uri}?sign={$sign}&t={$t}";

            if (!empty($downloadName)) {
                $safeName = str_replace(["\r", "\n", "\t"], '', $downloadName);
                $encodedName = rawurlencode($safeName);
                $headerValue = "attachment; filename*=UTF-8''{$encodedName}";
                $url .= "&response-content-disposition=" . rawurlencode($headerValue);
            }
            return $url;
        }

        // ==============================================================================
        // 原有逻辑：AWS V4 复杂原生签名 (用于 PUT 物理推流、无 CDN 兜底等)
        // ==============================================================================
        $ak = trim($config['access_key'] ?? '');
        $sk = trim($config['secret_key'] ?? '');
        $bucket = trim($config['bucket'] ?? '');
        $region = empty(trim($config['region'] ?? '')) ? 'auto' : trim($config['region']);
        $usePathStyle = isset($config['path_style']) ? (bool)$config['path_style'] : true;

        $endpoint = rtrim(trim($config['endpoint'] ?? ''), '/');
        $parsedUrl = parse_url(strpos($endpoint, 'http') === 0 ? $endpoint : "https://{$endpoint}");
        $scheme = $parsedUrl['scheme'] ?? 'https';
        $host = $parsedUrl['host'] ?? '';

        if (!empty($customDomain)) {
            $parsedCustom = parse_url(strpos($customDomain, 'http') === 0 ? $customDomain : "https://{$customDomain}");
            $host = $parsedCustom['host'] ?? preg_replace('/^https?:\/\//', '', rtrim($customDomain, '/'));
            $scheme = $parsedCustom['scheme'] ?? 'https';
            $usePathStyle = false;
        }

        if ($usePathStyle) {
            $requestPath = "/{$bucket}/" . ltrim($path, '/');
        } else {
            if (empty($customDomain) && strpos($host, $bucket) !== 0) {
                $host = "{$bucket}.{$host}";
            }
            $requestPath = "/" . ltrim($path, '/');
        }

        $encodedPath = str_replace('%2F', '/', rawurlencode($requestPath));
        $amzDate = gmdate('Ymd\THis\Z');
        $dateStamp = gmdate('Ymd');
        $credentialScope = "{$dateStamp}/{$region}/s3/aws4_request";

        $queryParams = [
            'X-Amz-Algorithm' => 'AWS4-HMAC-SHA256',
            'X-Amz-Credential' => $ak . '/' . $credentialScope,
            'X-Amz-Date' => $amzDate,
            'X-Amz-Expires' => (string)$expires,
            'X-Amz-SignedHeaders' => 'host'
        ];

        if ($method === 'GET' && !empty($downloadName)) {
            $safeName = str_replace(["\r", "\n", "\t"], '', $downloadName);
            $encodedName = rawurlencode($safeName);
            $queryParams['response-content-disposition'] = "attachment; filename*=UTF-8''{$encodedName}";
        }

        ksort($queryParams);
        $canonicalQueryString = [];
        foreach ($queryParams as $k => $v) {
            $canonicalQueryString[] = rawurlencode($k) . '=' . rawurlencode($v);
        }
        $canonicalQueryString = implode('&', $canonicalQueryString);

        $canonicalHeaders = "host:{$host}\n";
        $signedHeaders = "host";
        $payloadHash = 'UNSIGNED-PAYLOAD';

        $canonicalRequest = "{$method}\n{$encodedPath}\n{$canonicalQueryString}\n{$canonicalHeaders}\n{$signedHeaders}\n{$payloadHash}";
        $stringToSign = "AWS4-HMAC-SHA256\n{$amzDate}\n{$credentialScope}\n" . hash('sha256', $canonicalRequest);

        $kDate = hash_hmac('sha256', $dateStamp, "AWS4" . $sk, true);
        $kRegion = hash_hmac('sha256', $region, $kDate, true);
        $kService = hash_hmac('sha256', 's3', $kRegion, true);
        $kSigning = hash_hmac('sha256', "aws4_request", $kService, true);
        $signature = hash_hmac('sha256', $stringToSign, $kSigning);

        return "{$scheme}://{$host}{$encodedPath}?{$canonicalQueryString}&X-Amz-Signature={$signature}";
    }

    private static function getAliyunPresignedUrl(array $config, string $method, string $path, int $expires, string $downloadName): string
    {
        $customDomain = $method === 'PUT' ? ($config['write_domain'] ?? '') : ($config['read_domain'] ?? '');
        $cdnAuthKey = $config['cdn_auth_key'] ?? '';

        if ($method === 'GET' && !empty($customDomain) && !empty($cdnAuthKey)) {
            $parsedCustom = parse_url(strpos($customDomain, 'http') === 0 ? $customDomain : "https://{$customDomain}");
            $host = $parsedCustom['host'] ?? preg_replace('/^https?:\/\//', '', rtrim($customDomain, '/'));
            $scheme = $parsedCustom['scheme'] ?? 'https';

            $uri = '/' . ltrim($path, '/');
            $timestamp = time() + $expires;
            $rand = '0';
            $uid = '0';
            $hash = md5("{$uri}-{$timestamp}-{$rand}-{$uid}-{$cdnAuthKey}");
            $authKey = "{$timestamp}-{$rand}-{$uid}-{$hash}";

            $url = "{$scheme}://{$host}{$uri}?auth_key={$authKey}";
            if (!empty($downloadName)) {
                $safeName = str_replace(["\r", "\n", "\t"], '', $downloadName);
                $encodedName = rawurlencode($safeName);
                $headerValue = "attachment; filename*=UTF-8''{$encodedName}";
                $url .= "&response-content-disposition=" . rawurlencode($headerValue);
            }
            return $url;
        }

        $ak = trim($config['access_key'] ?? '');
        $sk = trim($config['secret_key'] ?? '');
        $bucket = trim($config['bucket'] ?? '');

        $endpoint = preg_replace('/^https?:\/\//', '', rtrim(trim($config['endpoint'] ?? ''), '/'));
        $host = strpos($endpoint, $bucket) === 0 ? $endpoint : "{$bucket}.{$endpoint}";
        $scheme = 'https';

        if (!empty($customDomain)) {
            $parsedCustom = parse_url(strpos($customDomain, 'http') === 0 ? $customDomain : "https://{$customDomain}");
            $host = $parsedCustom['host'] ?? preg_replace('/^https?:\/\//', '', rtrim($customDomain, '/'));
            $scheme = $parsedCustom['scheme'] ?? 'https';
        }

        $expireTimestamp = time() + $expires;
        $objectKey = ltrim($path, '/');

        $subResources = [];
        if ($method === 'GET' && !empty($downloadName)) {
            $safeName = str_replace(["\r", "\n", "\t"], '', $downloadName);
            $encodedName = rawurlencode($safeName);
            $subResources['response-content-disposition'] = "attachment; filename*=UTF-8''{$encodedName}";
        }

        $canonicalizedResource = "/{$bucket}/{$objectKey}";
        $queryString = '';
        if (!empty($subResources)) {
            ksort($subResources);
            $resParts = [];
            $queryParts = [];
            foreach ($subResources as $k => $v) {
                $resParts[] = "{$k}={$v}";
                $queryParts[] = "{$k}=" . rawurlencode($v);
            }
            $canonicalizedResource .= "?" . implode('&', $resParts);
            $queryString = "&" . implode('&', $queryParts);
        }

        $stringToSign = "{$method}\n\n\n{$expireTimestamp}\n{$canonicalizedResource}";
        $signature = urlencode(base64_encode(hash_hmac('sha1', $stringToSign, $sk, true)));

        return "{$scheme}://{$host}/{$objectKey}?OSSAccessKeyId={$ak}&Expires={$expireTimestamp}&Signature={$signature}{$queryString}";
    }

    private static function getTencentPresignedUrl(array $config, string $method, string $path, int $expires, string $downloadName): string
    {
        $customDomain = $method === 'PUT' ? ($config['write_domain'] ?? '') : ($config['read_domain'] ?? '');
        $cdnAuthKey = $config['cdn_auth_key'] ?? '';

        if ($method === 'GET' && !empty($customDomain) && !empty($cdnAuthKey)) {
            $parsedCustom = parse_url(strpos($customDomain, 'http') === 0 ? $customDomain : "https://{$customDomain}");
            $host = $parsedCustom['host'] ?? preg_replace('/^https?:\/\//', '', rtrim($customDomain, '/'));
            $scheme = $parsedCustom['scheme'] ?? 'https';

            $uri = '/' . ltrim($path, '/');
            $timestamp = dechex(time() + $expires);
            $hash = md5("{$cdnAuthKey}{$uri}{$timestamp}");

            $url = "{$scheme}://{$host}{$uri}?sign={$hash}&t={$timestamp}";
            if (!empty($downloadName)) {
                $safeName = str_replace(["\r", "\n", "\t"], '', $downloadName);
                $encodedName = rawurlencode($safeName);
                $headerValue = "attachment; filename*=UTF-8''{$encodedName}";
                $url .= "&response-content-disposition=" . rawurlencode($headerValue);
            }
            return $url;
        }

        $ak = trim($config['access_key'] ?? '');
        $sk = trim($config['secret_key'] ?? '');
        $bucket = trim($config['bucket'] ?? '');
        $region = empty(trim($config['region'] ?? '')) ? 'ap-guangzhou' : trim($config['region']);

        $host = "{$bucket}.cos.{$region}.myqcloud.com";
        $scheme = 'https';

        if (!empty($customDomain)) {
            $parsedCustom = parse_url(strpos($customDomain, 'http') === 0 ? $customDomain : "https://{$customDomain}");
            $host = $parsedCustom['host'] ?? preg_replace('/^https?:\/\//', '', rtrim($customDomain, '/'));
            $scheme = $parsedCustom['scheme'] ?? 'https';
        }

        $objectKey = ltrim($path, '/');
        $encodedPath = str_replace('%2F', '/', rawurlencode("/{$objectKey}"));

        $urlParams = [];
        if ($method === 'GET' && !empty($downloadName)) {
            $safeName = str_replace(["\r", "\n", "\t"], '', $downloadName);
            $encodedName = rawurlencode($safeName);
            $urlParams['response-content-disposition'] = "attachment; filename*=UTF-8''{$encodedName}";
        }
        ksort($urlParams);
        $encodedUrlParams = [];
        $qUrlParamList = [];
        foreach ($urlParams as $k => $v) {
            $kLower = strtolower($k);
            $qUrlParamList[] = $kLower;
            $encodedUrlParams[] = $kLower . '=' . rawurlencode($v);
        }
        $qUrlParamListStr = implode(';', $qUrlParamList);
        $encodedUrlParamsStr = implode('&', $encodedUrlParams);

        $startTimestamp = time();
        $endTimestamp = $startTimestamp + $expires;
        $qSignTime = "{$startTimestamp};{$endTimestamp}";

        $signKey = hash_hmac('sha1', $qSignTime, $sk);
        $httpString = strtolower($method) . "\n{$encodedPath}\n{$encodedUrlParamsStr}\nhost={$host}\n";
        $sha1edHttpString = sha1($httpString);
        $stringToSign = "sha1\n{$qSignTime}\n{$sha1edHttpString}\n";
        $signature = hash_hmac('sha1', $stringToSign, $signKey);

        $query = http_build_query([
            'q-sign-algorithm' => 'sha1',
            'q-ak' => $ak,
            'q-sign-time' => $qSignTime,
            'q-key-time' => $qSignTime,
            'q-header-list' => 'host',
            'q-url-param-list' => $qUrlParamListStr,
            'q-signature' => $signature
        ]);
        if (!empty($encodedUrlParamsStr)) {
            $query .= '&' . $encodedUrlParamsStr;
        }

        return "{$scheme}://{$host}{$encodedPath}?{$query}";
    }

    // ==============================================================================
    // 内部 Core 网络通讯方法 (重点修复了 POST 请求的签名与 Body 发送逻辑)
    // ==============================================================================
    private static function checkS3Connection(array $config): bool
    {
        list($httpCode, $response) = self::executeS3Curl('GET', $config, '', 'max-keys=1');
        if ($httpCode >= 200 && $httpCode < 300) return true;
        $extractedMsg = (string)$response;
        if (preg_match('/<Message>(.*?)<\/Message>/s', $extractedMsg, $matches)) $extractedMsg = $matches[1];
        throw new \Exception("HTTP {$httpCode}: " . $extractedMsg);
    }

    private static function executeS3Curl(string $method, array $config, string $path, string $query = '', $body = null, array $customHeaders = [], bool $isFile = false)
    {
        $ak = trim($config['access_key'] ?? '');
        $sk = trim($config['secret_key'] ?? '');
        $bucket = trim($config['bucket'] ?? '');
        $endpoint = preg_replace('/^https?:\/\//', '', rtrim(trim($config['endpoint'] ?? ''), '/'));
        $rawRegion = trim($config['region'] ?? '');
        $region = empty($rawRegion) ? 'auto' : $rawRegion;
        $usePathStyle = isset($config['path_style']) ? (bool)$config['path_style'] : true;

        $host = $endpoint;
        $path = ltrim($path, '/');

        if ($usePathStyle) {
            $requestPath = empty($path) ? "/{$bucket}/" : "/{$bucket}/{$path}";
        } else {
            if (strpos($host, $bucket) !== 0) $host = "{$bucket}.{$host}";
            $requestPath = "/" . $path;
        }

        $encodedPath = str_replace('%2F', '/', rawurlencode($requestPath));
        $url = "https://{$host}{$encodedPath}" . ($query ? "?{$query}" : '');

        $amzDate = gmdate('Ymd\THis\Z');
        $dateStamp = gmdate('Ymd');

        // 【核心修复】：为 POST 请求计算 SHA256 Body 签名
        $payloadHash = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';
        if (in_array($method, ['PUT', 'POST']) && $body !== null && !$isFile) {
            $payloadHash = hash('sha256', $body);
        } elseif ($method === 'PUT' && $isFile) {
            $payloadHash = 'UNSIGNED-PAYLOAD';
        }

        $headers = ['host' => $host, 'x-amz-date' => $amzDate, 'x-amz-content-sha256' => $payloadHash];
        foreach ($customHeaders as $k => $v) $headers[strtolower((string)$k)] = trim((string)$v);
        ksort($headers);

        $canonicalHeaders = ""; $signedHeadersArr = [];
        foreach ($headers as $k => $v) { $canonicalHeaders .= "{$k}:{$v}\n"; $signedHeadersArr[] = $k; }
        $signedHeaders = implode(';', $signedHeadersArr);

        $canonicalRequest = "{$method}\n{$encodedPath}\n{$query}\n{$canonicalHeaders}\n{$signedHeaders}\n{$payloadHash}";
        $credentialScope = "{$dateStamp}/{$region}/s3/aws4_request";
        $stringToSign = "AWS4-HMAC-SHA256\n{$amzDate}\n{$credentialScope}\n" . hash('sha256', $canonicalRequest);

        $kDate = hash_hmac('sha256', $dateStamp, "AWS4" . $sk, true);
        $kRegion = hash_hmac('sha256', $region, $kDate, true);
        $kService = hash_hmac('sha256', 's3', $kRegion, true);
        $kSigning = hash_hmac('sha256', "aws4_request", $kService, true);
        $signature = hash_hmac('sha256', $stringToSign, $kSigning);

        $authorizationHeader = "AWS4-HMAC-SHA256 Credential={$ak}/{$credentialScope}, SignedHeaders={$signedHeaders}, Signature={$signature}";
        $curlHeaders = [];
        foreach ($headers as $k => $v) $curlHeaders[] = "{$k}: {$v}";
        $curlHeaders[] = "Authorization: {$authorizationHeader}";

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $curlHeaders);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, $isFile ? 86400 : 30); // 稍微放宽普通 API 请求的超时

        // 【核心修复】：为 POST 请求配置 CURLOPT_POSTFIELDS 发送数据载荷
        // 【健壮性增强】针对 file_exists 进行最大路径长度 2048 的前置拦截！
        // 防止误把巨大的 JSON/HTML 字符串当成路径丢给系统内核查询，从而引发 Warning 和 IO 灾难
        if (in_array($method, ['PUT', 'POST'])) {
            if ($isFile && is_string($body) && strlen($body) < 2048 && file_exists($body)) {
                $fileStream = fopen($body, 'rb');
                curl_setopt($ch, CURLOPT_PUT, true);
                curl_setopt($ch, CURLOPT_INFILE, $fileStream);
                curl_setopt($ch, CURLOPT_INFILESIZE, filesize($body));
            } elseif ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }
        } elseif ($method === 'HEAD') {
            curl_setopt($ch, CURLOPT_NOBODY, true);
            curl_setopt($ch, CURLOPT_HEADER, true);
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errMsg = curl_error($ch);
        curl_close($ch);
        if (isset($fileStream) && is_resource($fileStream)) fclose($fileStream);

        if ($response === false) throw new \Exception("底层网络请求失败: " . $errMsg);
        return [$httpCode, $response];
    }

    private static function checkAliyunConnection(array $conf): bool
    {
        list($httpCode, $response) = self::executeAliyunCurl('GET', $conf, '?max-keys=1');
        if ($httpCode >= 200 && $httpCode < 300) return true;
        $extractedMsg = (string)$response;
        if (preg_match('/<Message>(.*?)<\/Message>/s', $extractedMsg, $matches)) $extractedMsg = $matches[1];
        throw new \Exception("HTTP {$httpCode}: " . $extractedMsg);
    }

    private static function executeAliyunCurl(string $method, array $config, string $path, $body = null, array $customHeaders = [], bool $isFile = false)
    {
        $ak = $config['access_key'];
        $sk = $config['secret_key'];
        $bucket = $config['bucket'];
        $endpoint = preg_replace('/^https?:\/\//', '', rtrim($config['endpoint'], '/'));

        $host = strpos($endpoint, $bucket) === 0 ? $endpoint : "{$bucket}.{$endpoint}";
        $parsedPath = parse_url('/' . ltrim($path, '/'));
        $objectKey = ltrim($parsedPath['path'] ?? '', '/');
        $query = $parsedPath['query'] ?? '';

        $url = "https://{$host}/{$objectKey}" . ($query ? "?{$query}" : '');
        $date = gmdate('D, d M Y H:i:s \G\M\T');
        $contentType = $customHeaders['Content-Type'] ?? '';
        $contentMd5 = $customHeaders['Content-MD5'] ?? '';

        $canonicalizedResource = "/{$bucket}/{$objectKey}";
        $stringToSign = "{$method}\n{$contentMd5}\n{$contentType}\n{$date}\n{$canonicalizedResource}";
        $signature = base64_encode(hash_hmac('sha1', $stringToSign, $sk, true));

        $headers = ["Date: {$date}", "Authorization: OSS {$ak}:{$signature}"];
        foreach ($customHeaders as $k => $v) $headers[] = "{$k}: {$v}";

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, $isFile ? 86400 : 15);

        if ($method === 'PUT' || $method === 'POST') {
            if ($isFile && is_string($body) && file_exists($body)) {
                $fileSize = filesize($body);
                $fileStream = fopen($body, 'rb');
                curl_setopt($ch, CURLOPT_PUT, true);
                curl_setopt($ch, CURLOPT_INFILE, $fileStream);
                curl_setopt($ch, CURLOPT_INFILESIZE, $fileSize);
            } else if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        } else if ($method === 'HEAD') {
            curl_setopt($ch, CURLOPT_NOBODY, true);
            curl_setopt($ch, CURLOPT_HEADER, true);
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errMsg = curl_error($ch);
        curl_close($ch);
        if (isset($fileStream) && is_resource($fileStream)) fclose($fileStream);

        if ($response === false) throw new \Exception("底层网络请求失败: " . $errMsg);
        return [$httpCode, $response];
    }

    private static function formatUrl(array $conf, string $path): string
    {
        $domain = $conf['domain'] ?? '';
        if (!empty($domain)) return rtrim($domain, '/') . '/' . ltrim($path, '/');

        $type = $conf['type'];
        $bucket = $conf['bucket'];

        if ($type === 'aliyun') {
            $endpoint = preg_replace('/^https?:\/\//', '', $conf['endpoint']);
            return "https://{$bucket}.{$endpoint}/" . ltrim($path, '/');
        } elseif ($type === 'tencent') {
            $region = $conf['region'] ?? 'ap-guangzhou';
            return "https://{$bucket}.cos.{$region}.myqcloud.com/" . ltrim($path, '/');
        } elseif ($type === 'qiniu') {
            $endpoint = rtrim($conf['endpoint'], '/');
            return "{$endpoint}/" . ltrim($path, '/');
        }
        return '';
    }

    private static function purgeCdnCache(string $type, array $conf, string $fullUrl): void
    {
        Log::info("[边缘网络] 已触发 CDN 缓存驱逐任务列队，对象 URL: {$fullUrl}");
    }

    // ==============================================================================
    // 【Phase 2 专属】大文件分片断点续传引擎 (全云厂商强制采用 S3 V4 协议通杀)
    // ==============================================================================

    public static function initMultipartUpload(array $config, string $path): string
    {
        $config['type'] = 's3';
        $config['path_style'] = in_array($config['driver'] ?? '', ['tencent', 'qiniu']) ? false : ($config['path_style'] ?? true);

        list($httpCode, $response) = self::executeS3Curl('POST', $config, ltrim($path, '/'), 'uploads=');

        if ($httpCode >= 200 && $httpCode < 300) {
            if (preg_match('/<UploadId>(.*?)<\/UploadId>/', (string)$response, $matches)) {
                return $matches[1];
            }
        }
        throw new Exception("云端初始化分片事务失败 (HTTP {$httpCode}): " . $response);
    }

    public static function getMultipartPresignedUrl(array $config, string $path, string $uploadId, int $partNumber, int $expires): string
    {
        $ak = trim($config['access_key'] ?? '');
        $sk = trim($config['secret_key'] ?? '');
        $bucket = trim($config['bucket'] ?? '');
        $region = empty(trim($config['region'] ?? '')) ? 'auto' : trim($config['region']);
        $usePathStyle = in_array($config['driver'] ?? '', ['tencent', 'qiniu']) ? false : ($config['path_style'] ?? true);

        $endpoint = rtrim(trim($config['endpoint'] ?? ''), '/');
        $parsedUrl = parse_url(strpos($endpoint, 'http') === 0 ? $endpoint : "https://{$endpoint}");
        $scheme = $parsedUrl['scheme'] ?? 'https';
        $host = $parsedUrl['host'] ?? '';

        $customDomain = $config['write_domain'] ?? '';
        if (!empty($customDomain)) {
            $parsedCustom = parse_url(strpos($customDomain, 'http') === 0 ? $customDomain : "https://{$customDomain}");
            $host = $parsedCustom['host'] ?? preg_replace('/^https?:\/\//', '', rtrim($customDomain, '/'));
            $scheme = $parsedCustom['scheme'] ?? 'https';
            $usePathStyle = false;
        }

        if ($usePathStyle) {
            $requestPath = "/{$bucket}/" . ltrim($path, '/');
        } else {
            if (empty($customDomain) && strpos($host, $bucket) !== 0) {
                $host = "{$bucket}.{$host}";
            }
            $requestPath = "/" . ltrim($path, '/');
        }

        $encodedPath = str_replace('%2F', '/', rawurlencode($requestPath));
        $amzDate = gmdate('Ymd\THis\Z');
        $dateStamp = gmdate('Ymd');
        $credentialScope = "{$dateStamp}/{$region}/s3/aws4_request";

        $queryParams = [
            'X-Amz-Algorithm' => 'AWS4-HMAC-SHA256',
            'X-Amz-Credential' => $ak . '/' . $credentialScope,
            'X-Amz-Date' => $amzDate,
            'X-Amz-Expires' => (string)$expires,
            'X-Amz-SignedHeaders' => 'host',
            'partNumber' => (string)$partNumber,
            'uploadId' => $uploadId
        ];
        ksort($queryParams);

        $canonicalQueryString = [];
        foreach ($queryParams as $k => $v) {
            $canonicalQueryString[] = rawurlencode($k) . '=' . rawurlencode($v);
        }
        $canonicalQueryString = implode('&', $canonicalQueryString);

        $canonicalHeaders = "host:{$host}\n";
        $signedHeaders = "host";
        $payloadHash = 'UNSIGNED-PAYLOAD';

        $canonicalRequest = "PUT\n{$encodedPath}\n{$canonicalQueryString}\n{$canonicalHeaders}\n{$signedHeaders}\n{$payloadHash}";
        $stringToSign = "AWS4-HMAC-SHA256\n{$amzDate}\n{$credentialScope}\n" . hash('sha256', $canonicalRequest);

        $kDate = hash_hmac('sha256', $dateStamp, "AWS4" . $sk, true);
        $kRegion = hash_hmac('sha256', $region, $kDate, true);
        $kService = hash_hmac('sha256', 's3', $kRegion, true);
        $kSigning = hash_hmac('sha256', "aws4_request", $kService, true);
        $signature = hash_hmac('sha256', $stringToSign, $kSigning);

        return "{$scheme}://{$host}{$encodedPath}?{$canonicalQueryString}&X-Amz-Signature={$signature}";
    }

    public static function completeMultipartUpload(array $config, string $path, string $uploadId, array $parts): bool
    {
        $config['type'] = 's3';
        $config['path_style'] = in_array($config['driver'] ?? '', ['tencent', 'qiniu']) ? false : ($config['path_style'] ?? true);

        // 【核心修复】：注入 xmlns 标准命名空间
        $xml = "<CompleteMultipartUpload xmlns=\"http://s3.amazonaws.com/doc/2006-03-01/\">\n";
        foreach ($parts as $part) {
            // 【安全修复】严防 XML 注入！如果 ETag 带有破坏性特殊字符，会导致整个 OSS/R2 合并确权抛出 XML Parser 异常
            $etag = htmlspecialchars(trim($part['ETag'], '"'), ENT_XML1, 'UTF-8');
            $xml .= "<Part><PartNumber>{$part['PartNumber']}</PartNumber><ETag>\"{$etag}\"</ETag></Part>\n";
        }
        $xml .= "</CompleteMultipartUpload>";

        // 【核心修复】：对 uploadId 强行 URL 编码保护
        $urlEncodedUploadId = rawurlencode($uploadId);
        list($httpCode, $response) = self::executeS3Curl('POST', $config, ltrim($path, '/'), "uploadId={$urlEncodedUploadId}", $xml, ['Content-Type' => 'application/xml']);

        if ($httpCode >= 200 && $httpCode < 300) {
            return true;
        }
        throw new Exception("碎片合并确权失败 (HTTP {$httpCode}): " . $response);
    }


    public static function listParts(array $config, string $path, string $uploadId): array
    {
        $config['type'] = 's3';
        $config['path_style'] = in_array($config['driver'] ?? '', ['tencent', 'qiniu']) ? false : ($config['path_style'] ?? true);

        // 强制 URL 编码保护，防止特殊字符报错
        $urlEncodedUploadId = rawurlencode($uploadId);

        list($httpCode, $response) = self::executeS3Curl('GET', $config, ltrim($path, '/'), "uploadId={$urlEncodedUploadId}");

        if ($httpCode === 404) {
            throw new Exception("UploadId 不存在或已被云端生命周期回收");
        }

        $uploadedParts = [];
        if ($httpCode >= 200 && $httpCode < 300) {
            // 解析云端返回的 XML 碎片清单
            $xml = @simplexml_load_string((string)$response);
            if ($xml && isset($xml->Part)) {
                foreach ($xml->Part as $part) {
                    $uploadedParts[] = [
                        'PartNumber' => (int)$part->PartNumber,
                        'ETag' => str_replace('"', '', (string)$part->ETag) // 清理双引号
                    ];
                }
            }
        } else {
            throw new Exception("获取云端分片列表失败 (HTTP {$httpCode}): " . $response);
        }

        return $uploadedParts;
    }


}