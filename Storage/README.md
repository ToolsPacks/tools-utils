# Wujie-Utils/Storage (云原生存储直连引擎)

## 🌟 核心特性
本模块不仅是一个 S3 兼容客户端，它是为**双桶虚拟化**和**客户端直传**量身定制的存储核心。支持统一的 URL 预签名、连通性探测、以及无内存损耗的流式物理操作。

## 🚀 关键接口与 AI 调用范例

### 1. 万能预签名授权生成 (支持双桶分离域名)
用于给前端或外部系统颁发限时直传/下载凭证。支持自动处理 `write_domain` (上传专线) 与 `read_domain` (CDN分发) 的跨域签名映射。

```php
use app\utils\Storage\OssClient;

$config = [
    'type' => 'cloudflare_r2', // 支持 s3, aliyun, tencent, cloudflare_r2, feiniuos, qiniu
    'access_key' => 'AK...',
    'secret_key' => 'SK...',
    'bucket' => 'your-bucket',
    'endpoint' => 'xxxx.r2.cloudflarestorage.com',
    'write_domain' => 'upload.your-domain.com' // 会自动替换签名 Host
];

// 生成有效时间为 3600 秒的 PUT 直传链接
$url = OssClient::getPresignedUrl($config, 'PUT', 'user/2026/demo.mp4', 3600);
```

### 2. 流式推流与防 OOM 处理 (S3Helper)
在处理超过 100MB 的文件时，**严禁使用** `putObject` 读入内存。必须使用 `putObjectFromFile` 利用底层 `fopen` 句柄进行推流。

```php
use app\utils\Storage\S3Helper;

// 内部采用 UNSIGNED-PAYLOAD 绕过大文件哈希计算，降低 CPU 消耗
$url = S3Helper::putObjectFromFile($config, 'backup/db.sql', '/tmp/local_db.sql');
```

### 3. 云厂商物理连通性与探测
用于管理后台新增节点时的握手校验。

```php
// 测试连通性 (自动发送带有 max-keys=1 的 HEAD/GET 请求)
OssClient::checkConnection($config);

// 提取远程对象的真实物理大小 (返回 bytes)
$size = OssClient::getObjectSize($config, 'user/2026/demo.mp4');
```

### 4. 彻底的物理清剿 (DeleteObject)
用于 GC 守护进程 (`LifecycleCleaner`)。由于配置了防盗链和 CDN，删除后会自动联动缓存驱逐逻辑。

```php
OssClient::deleteObject($config, 'user/2026/demo.mp4');
```