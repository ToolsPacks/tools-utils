# Wujie-Utils / WujieHub (无界云盘微服务接入 SDK)

## 📌 模块定位
本模块专供 **ERP、订单系统、商城** 等外部/内部业务系统调用。通过此 SDK，其他系统无需关心底层 OSS 是阿里云还是 Cloudflare，也无需关心文件大小，直接向无界主控系统发起指令，实现资产的全生命周期集中接管。

---

## 🚀 核心 API 调用范例与完整生命周期

### 0. 客户端初始化
建议将 `HubClient` 注册为单例或在基础服务中初始化。
```php
use app\utils\WujieHub\HubClient;

// 参数：网关地址、API Token、请求超时时间(秒)
$hub = new HubClient('[https://your-hub-domain.com](https://your-hub-domain.com)', 'API_TOKEN_XXXXXXXXX', 15);
```

### 1. 租户容量探针 (Quota Check)
在大批量上传前，业务系统可探测剩余配额：
```php
$profile = $hub->getProfile();
// 包含：used_space, space_limit, used_traffic, traffic_limit, max_upload_size 等参数
```

### 2. 客户端边缘直传 (Client Direct Upload) 兼容分片引擎
外部系统想要上传文件，先向无界系统申请“直传凭证”。系统会自动根据文件大小分配单体直传或并发分片策略。

```php
// 1. 申请直传通道
$res = $hub->applyInbound('md5_hash_123', 104857600, 'test_large.mp4', 0);

/*
【分支A】小于 50MB 返回单体 PUT 票据：
"route_strategy": "client_direct_upload",
"direct_ticket": { "upload_endpoint": "https://...", "method": "PUT" }

【分支B】大于 50MB 自动返回大文件并发分片票据：
"route_strategy": "client_multipart_upload",
"multipart_ticket": {
    "upload_id": "云端事务ID",
    "part_size": 5242880, // 每片 5MB
    "part_urls": ["url_1", "url_2"...]
}
*/

// 2. 外部系统前端上传完毕后，通知系统确权落盘
// 若为小文件确权：
$confirmRes = $hub->confirmDirectUpload('md5_hash_123');

// 若为大文件分片确权合并，需带上 uploadId 和前端上传收集到的 ETag 数组：
$parts = [
    ['PartNumber' => 1, 'ETag' => 'etag_xxx1'],
    ['PartNumber' => 2, 'ETag' => 'etag_xxx2']
];
$confirmRes = $hub->confirmDirectUpload('md5_hash_123', '云端事务ID', $parts);
```

### 3. 内部直连下载与文件销毁 (System I/O)
供 ERP 内部使用的直接 API，无需经过外链分享模块。
```php
// 内部直链下载 (消耗下行流量配额，自带 CDN 防盗链签名)
$downloadRes = $hub->applyOutbound('md5_hash_123');
// 返回：$downloadRes['data']['outbound_ticket_url']

// 物理销毁文件 (释放存储空间配额，联动云端双桶删除)
$deleteRes = $hub->deleteFile('md5_hash_123');
```

### 4. 委托云端跨海拉取 (Serverless Fetch)
让无界系统的 FC 云函数去跨海下载外部链接。

```php
// 1. 提交任务
$res = $hub->submitRemoteFetch('[https://video.com/a.mp4](https://video.com/a.mp4)', 'md5_hash_123');

// 2. 轮询进度 (前端每 3 秒查一次)
$progress = $hub->getSyncStatus('md5_hash_123');
```

### 5. 生成对外的分享直链与防盗链 (Public ACL)
```php
// 申请一个带密码的防盗链 (share_type = 2)
$shareRes = $hub->createShare('md5_hash_123', 2, 7, '8888');

// 访客解析分享码换取真实的带 CDN 鉴权及重命名后缀的高速下载 URL
$downloadInfo = $hub->extractShare($shareRes['data']['share_code'], '8888');
```