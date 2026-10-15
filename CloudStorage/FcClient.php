<?php
declare(strict_types=1);

namespace app\utils\CloudStorage;


use app\utils\Network\HttpHelper;
use Exception;

/**
 * 阿里云 Serverless 函数计算 (FC) 离线转储引擎
 * 作用：将下载与直传任务外包给 FC，实现 ERP 零带宽、零内存消耗
 */
class FcClient
{
    /**
     * 触发 FC 进行离线文件拉取与 OSS 内网直传
     * * @param string $fcEndpoint 云函数触发器 URL (例如: https://xxx.cn-hangzhou.fcapp.run)
     * @param string $sourceUrl  需要被抓取的源文件 URL (如美客多附件API)
     * @param string $savePath   期望存入 OSS 的目标路径 (如 attachments/claims/xxx.jpg)
     * @param array  $headers    请求源文件时需要携带的请求头 (如 Authorization: Bearer token)
     * @param string $bucket     (可选) 覆盖默认的 OSS Bucket
     * @param string $region     (可选) 覆盖默认的 OSS Region
     * @param int    $timeout    等待 FC 处理的超时时间(秒)
     * @return string            返回成功落地的 OSS 公网/CDN 链接
     * @throws Exception
     */
    public static function transfer(string $fcEndpoint, string $sourceUrl, string $savePath, array $headers = [], string $bucket = '', string $region = '', int $timeout = 30): string
    {
        $payload = [
            'sourceUrl' => $sourceUrl,
            'savePath'  => $savePath,
            'headers'   => $headers
        ];

        if (!empty($bucket)) {
            $payload['bucket'] = $bucket;
        }
        if (!empty($region)) {
            $payload['region'] = $region;
        }

        // 向 FC 发起指令，强制携带异步触发头，防止阻塞主进程
        $asyncHeaders = [
            'X-Fc-Invocation-Type: Async', // 阿里云 FC 异步调用头
            'X-SCF-Async: 1'               // 腾讯云 SCF 异步调用头
        ];

        // 传递 customHeaders 进行请求
        $response = HttpHelper::postJson($fcEndpoint, $payload, $timeout, $asyncHeaders);
        $res = json_decode((string)$response, true);

        // 【致命假死欺骗漏洞修复】必须明确判断 $response !== false，因为当 HttpHelper 底层因网络不通抛出 false 时，
        // PHP的 empty(false) 竟然等于 true！会导致任务明明失败却蒙混过关返回 success！
        if ($response !== false && (empty($response) || trim((string)$response) === 'Accepted' || (isset($res['code']) && in_array($res['code'], [200, 202])))) {
            return 'async_dispatched_successfully';
        }

        throw new Exception("FC Serverless 异步跨海拉取指令下发失败或网络中断: " . (string)$response);
    }
}