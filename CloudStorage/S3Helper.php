<?php
declare(strict_types=1);

namespace app\utils\CloudStorage;


use Exception;

/**
 * 纯原生 AWS S3 Signature V4 签名上传引擎
 * 完美兼容 Cloudflare R2 / 飞牛OS MinIO / 腾讯云 COS 等
 * 零第三方 SDK 依赖，极低内存消耗
 */
class S3Helper
{
    public static function putObject(array $config, string $path, string $content, string $contentType = 'application/octet-stream'): string
    {
        $ak = $config['access_key'] ?? '';
        $sk = $config['secret_key'] ?? '';
        $bucket = $config['bucket'] ?? '';
        $endpoint = rtrim($config['endpoint'] ?? '', '/');
        $region = $config['region'] ?? 'auto';
        $usePathStyle = isset($config['path_style']) ? (bool)$config['path_style'] : true;

        $parsedUrl = parse_url($endpoint);
        $scheme = $parsedUrl['scheme'] ?? 'https';
        $host = $parsedUrl['host'] ?? '';

        if ($usePathStyle) {
            $url = "{$scheme}://{$host}/{$bucket}/" . ltrim($path, '/');
            $requestPath = "/{$bucket}/" . ltrim($path, '/');
        } else {
            if (strpos($host, $bucket) === false) {
                $host = $bucket . '.' . $host;
            }
            $url = "{$scheme}://{$host}/" . ltrim($path, '/');
            $requestPath = "/" . ltrim($path, '/');
        }

        $amzDate = gmdate('Ymd\THis\Z');
        $dateStamp = gmdate('Ymd');
        $payloadHash = hash('sha256', $content);

        $headers = [
            'Host'                 => $host,
            'x-amz-content-sha256' => $payloadHash,
            'x-amz-date'           => $amzDate,
            'Content-Type'         => $contentType,
            'Content-Length'       => (string)strlen($content)
        ];

        ksort($headers);
        $canonicalHeaders = "";
        $signedHeadersArr = [];
        foreach ($headers as $k => $v) {
            $lowerKey = strtolower((string)$k);
            $canonicalHeaders .= "{$lowerKey}:" . trim((string)$v) . "\n";
            $signedHeadersArr[] = $lowerKey;
        }
        $signedHeaders = implode(';', $signedHeadersArr);

        $canonicalRequest = "PUT\n{$requestPath}\n\n{$canonicalHeaders}\n{$signedHeaders}\n{$payloadHash}";

        $algorithm = "AWS4-HMAC-SHA256";
        $credentialScope = "{$dateStamp}/{$region}/s3/aws4_request";
        $stringToSign = "{$algorithm}\n{$amzDate}\n{$credentialScope}\n" . hash('sha256', $canonicalRequest);

        $kSecret = "AWS4" . $sk;
        $kDate = hash_hmac('sha256', $dateStamp, $kSecret, true);
        $kRegion = hash_hmac('sha256', $region, $kDate, true);
        $kService = hash_hmac('sha256', 's3', $kRegion, true);
        $kSigning = hash_hmac('sha256', "aws4_request", $kService, true);
        $signature = hash_hmac('sha256', $stringToSign, $kSigning);

        $authorizationHeader = "{$algorithm} Credential={$ak}/{$credentialScope}, SignedHeaders={$signedHeaders}, Signature={$signature}";

        $curlHeaders = [];
        foreach ($headers as $k => $v) {
            $curlHeaders[] = "{$k}: {$v}";
        }
        $curlHeaders[] = "Authorization: " . $authorizationHeader;

        // 使用原生 cURL 替代 GuzzleHttp，断绝臃肿依赖
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
        curl_setopt($ch, CURLOPT_POSTFIELDS, $content);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $curlHeaders);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errNo = curl_errno($ch);
        $errMsg = curl_error($ch);
        curl_close($ch);

        if ($errNo) {
            throw new Exception("cURL Error ({$errNo}): {$errMsg}");
        }

        if ($httpCode >= 200 && $httpCode < 300) {
            $domain = $config['domain'] ?? '';
            if (!empty($domain)) {
                return rtrim($domain, '/') . '/' . ltrim($path, '/');
            }
            return $url;
        }

        throw new Exception("HTTP Code: {$httpCode}, Response: {$response}");
    }

    /**
     * 【第4次审计新增】纯物理流式大文件推流 (零内存损耗)
     * 利用 CURLOPT_INFILE 句柄实现边读边传，防 OOM 爆栈
     * 利用 UNSIGNED-PAYLOAD 避免大文件 Hash 计算导致的 CPU 阻塞
     */
    public static function putObjectFromFile(array $config, string $path, string $localFilePath, string $contentType = 'application/octet-stream'): string
    {
        if (!file_exists($localFilePath)) {
            throw new Exception("流式推流失败：本地源文件已丢失 {$localFilePath}");
        }

        $fileSize = filesize($localFilePath);
        $fileStream = fopen($localFilePath, 'rb');

        $ak = $config['access_key'] ?? '';
        $sk = $config['secret_key'] ?? '';
        $bucket = $config['bucket'] ?? '';
        $endpoint = rtrim($config['endpoint'] ?? '', '/');
        $region = $config['region'] ?? 'auto';
        $usePathStyle = isset($config['path_style']) ? (bool)$config['path_style'] : true;

        $parsedUrl = parse_url($endpoint);
        $scheme = $parsedUrl['scheme'] ?? 'https';
        $host = $parsedUrl['host'] ?? '';

        if ($usePathStyle) {
            $url = "{$scheme}://{$host}/{$bucket}/" . ltrim($path, '/');
            $requestPath = "/{$bucket}/" . ltrim($path, '/');
        } else {
            if (strpos($host, $bucket) === false) {
                $host = $bucket . '.' . $host;
            }
            $url = "{$scheme}://{$host}/" . ltrim($path, '/');
            $requestPath = "/" . ltrim($path, '/');
        }

        $amzDate = gmdate('Ymd\THis\Z');
        $dateStamp = gmdate('Ymd');
        $payloadHash = 'UNSIGNED-PAYLOAD';

        $headers = [
            'Host'                 => $host,
            'x-amz-content-sha256' => $payloadHash,
            'x-amz-date'           => $amzDate,
            'Content-Type'         => $contentType,
            'Content-Length'       => (string)$fileSize
        ];

        ksort($headers);
        $canonicalHeaders = "";
        $signedHeadersArr = [];
        foreach ($headers as $k => $v) {
            $lowerKey = strtolower((string)$k);
            $canonicalHeaders .= "{$lowerKey}:" . trim((string)$v) . "\n";
            $signedHeadersArr[] = $lowerKey;
        }
        $signedHeaders = implode(';', $signedHeadersArr);

        $canonicalRequest = "PUT\n{$requestPath}\n\n{$canonicalHeaders}\n{$signedHeaders}\n{$payloadHash}";

        $algorithm = "AWS4-HMAC-SHA256";
        $credentialScope = "{$dateStamp}/{$region}/s3/aws4_request";
        $stringToSign = "{$algorithm}\n{$amzDate}\n{$credentialScope}\n" . hash('sha256', $canonicalRequest);

        $kSecret = "AWS4" . $sk;
        $kDate = hash_hmac('sha256', $dateStamp, $kSecret, true);
        $kRegion = hash_hmac('sha256', $region, $kDate, true);
        $kService = hash_hmac('sha256', 's3', $kRegion, true);
        $kSigning = hash_hmac('sha256', "aws4_request", $kService, true);
        $signature = hash_hmac('sha256', $stringToSign, $kSigning);

        $authorizationHeader = "{$algorithm} Credential={$ak}/{$credentialScope}, SignedHeaders={$signedHeaders}, Signature={$signature}";

        $curlHeaders = [];
        foreach ($headers as $k => $v) {
            $curlHeaders[] = "{$k}: {$v}";
        }
        $curlHeaders[] = "Authorization: " . $authorizationHeader;

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_PUT, true);
        curl_setopt($ch, CURLOPT_INFILE, $fileStream);
        curl_setopt($ch, CURLOPT_INFILESIZE, $fileSize);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $curlHeaders);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 86400);

        try {
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $errNo = curl_errno($ch);
            $errMsg = curl_error($ch);
        } finally {
            // 【安全修复】保证网络异常或程序崩溃时强制关闭文件句柄和 curl 连接，防句柄泄露宕机
            curl_close($ch);
            if (is_resource($fileStream)) {
                fclose($fileStream);
            }
        }

        if ($errNo) {
            throw new Exception("流式 cURL 通信崩溃 ({$errNo}): {$errMsg}");
        }

        if ($httpCode >= 200 && $httpCode < 300) {
            $domain = $config['domain'] ?? '';
            if (!empty($domain)) {
                return rtrim($domain, '/') . '/' . ltrim($path, '/');
            }
            return $url;
        }

        throw new Exception("HTTP Code: {$httpCode}, Response: {$response}");
    }

}