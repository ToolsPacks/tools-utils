<?php
declare(strict_types=1);

namespace app\utils\WujieHub;

use app\utils\Network\HttpHelper;
use Exception;

/**
 * 无界云盘 (无界储存桶分布式系统) 核心接入 SDK
 * 定位：供 ERP、订单系统等外部系统跨域调用无界中枢，实现资产集中化管理
 */
class HubClient
{
    private string $endpoint;
    private string $apiToken;
    private int $timeout;

    public function __construct(string $endpoint, string $apiToken, int $timeout = 10)
    {
        $this->endpoint = rtrim($endpoint, '/');
        $this->apiToken = $apiToken;
        $this->timeout = $timeout;
    }

    private function request(string $uri, array $data = []): array
    {
        $url = $this->endpoint . $uri;
        $headers = ['Api-Token: ' . $this->apiToken];

        try {
            $jsonResp = HttpHelper::postJson($url, $data, $this->timeout, $headers);
            if ($jsonResp === false) {
                throw new Exception("无法连接到无界云盘核心网关");
            }
            $res = json_decode((string)$jsonResp, true);
            if (!$res || !isset($res['code'])) {
                throw new Exception("无界网关响应了异常的非 JSON 数据");
            }
            return $res;
        } catch (\Throwable $e) {
            return ['code' => 500, 'msg' => 'Wujie Hub SDK 内部异常: ' . $e->getMessage()];
        }
    }

    // ==============================================================================
    // 1. 上行数据流 (入网/上传)
    // ==============================================================================

    /**
     * 申请普通直传通道 (获取直传边缘节点的 Presigned URL 或 分片上传 Ticket)
     */
    public function applyInbound(string $fileHash, int $fileSize, string $fileName, int $expireDays = 0): array
    {
        return $this->request('/api/control_hub/applyInbound', [
            'file_hash'   => $fileHash,
            'file_size'   => $fileSize,
            'file_name'   => $fileName,
            'expire_days' => $expireDays
        ]);
    }

    /**
     * 确认前端物理直传完毕，通知总控进行落盘确权 (兼容分片合并)
     */
    public function confirmDirectUpload(string $fileHash, string $uploadId = '', array $parts = []): array
    {
        $payload = ['file_hash' => $fileHash];
        if (!empty($uploadId) && !empty($parts)) {
            $payload['upload_id'] = $uploadId;
            $payload['parts']     = $parts;
        }
        return $this->request('/api/control_hub/confirmDirectUpload', $payload);
    }

    /**
     * 提交外部资源拉取指令 (委托无界云盘触发 FC 代为跨海下载)
     */
    public function submitRemoteFetch(string $sourceUrl, string $fileHash, int $fileSize = 0, int $expireDays = 0): array
    {
        return $this->request('/api/control_hub/submitRemoteFetchTask', [
            'source_url'  => $sourceUrl,
            'file_hash'   => $fileHash,
            'file_size'   => $fileSize,
            'expire_days' => $expireDays
        ]);
    }

    /**
     * 查询云端异步双桶同步的实时进度
     */
    public function getSyncStatus(string $fileHash): array
    {
        return $this->request('/api/control_hub/getSyncStatus', [
            'file_hash' => $fileHash
        ]);
    }

    // ==============================================================================
    // 2. 下行数据流与资产控制 (出网/下载/销毁) - 【新增补全模块】
    // ==============================================================================

    /**
     * 【新增】：直接获取文件的底层高速下载链接 (系统内部调用，不生成分享码)
     * 注意：底层路由已升级为 POST 保证安全性
     */
    public function applyOutbound(string $fileHash): array
    {
        return $this->request('/api/control_hub/applyOutbound', [
            'file_hash' => $fileHash
        ]);
    }

    /**
     * 【新增】：物理销毁指定的云端文件，并回收配额
     */
    public function deleteFile(string $fileHash): array
    {
        return $this->request('/api/user_hub/deleteMyFile', [
            'file_hash' => $fileHash
        ]);
    }

    /**
     * 【新增】：获取当前租户的套餐配额、可用容量与流量限额探针
     */
    public function getProfile(): array
    {
        return $this->request('/api/user_hub/myProfile');
    }

    // ==============================================================================
    // 3. 外部防盗链与共享 (ACL)
    // ==============================================================================

    /**
     * 申请生成外部防盗链/直发链接
     */
    public function createShare(string $fileHash, int $shareType = 2, int $expireDays = 7, string $extractCode = ''): array
    {
        return $this->request('/api/share_hub/createShare', [
            'file_hash'    => $fileHash,
            'share_type'   => $shareType,
            'expire_days'  => $expireDays,
            'extract_code' => $extractCode
        ]);
    }

    /**
     * 消费分享链接，换取底层文件的真实高速下载 URL
     */
    public function extractShare(string $shareCode, string $extractCode = ''): array
    {
        return $this->request('/api/share_hub/extractShare', [
            'share_code'   => $shareCode,
            'extract_code' => $extractCode
        ]);
    }
}