# toolspacks/utils / CloudStorage (云原生存储底层驱动)

## 模块定位
直接对各大厂商（Aliyun, Tencent, Cloudflare R2, AWS S3）的 Bucket 发起原生 HTTP 指令，不引入任何第三方云存储 SDK。异常日志依赖框架的 `Log` facade，除日志外本模块不涉及 ThinkPHP 专属逻辑。

## 核心 API 调用范例

### 0. 标准化 Config 字典
所有的类方法都需要传入这个标准数组：
```php
$config = [
    'type'         => 'cloudflare_r2', // 支持 aliyun, tencent, s3, cloudflare_r2, qiniu, feiniuos
    'access_key'   => 'AK_xxxx',
    'secret_key'   => 'SK_xxxx',
    'bucket'       => 'your-bucket',
    'endpoint'     => 'xxxx.r2.cloudflarestorage.com',
    'region'       => 'auto', 
    'write_domain' => 'upload.example.com', 
    'read_domain'  => 'cdn.example.com',
    'cdn_auth_key' => 'TypeA_or_TypeD_Secret', // CDN防盗链秘钥
    'verify_ssl'   => true // 对接公网端点必须为 true；内网自签证书节点可省略（默认 false）
];
```

### 1. 预签名引擎 (Presigned URL)
内置智能边缘域名映射、CDN 鉴权 (阿里TypeA / 腾讯TypeD) 与 `Content-Disposition` 强行重命名机制。

```php
use app\utils\CloudStorage\OssClient;

// 签发 PUT 直传链接
$putUrl = OssClient::getPresignedUrl($config, 'PUT', '2026/demo.zip', 3600);

// 签发 GET 下载链接 (强制重命名为 原名.zip)
$getUrl = OssClient::getPresignedUrl($config, 'GET', '2026/demo.zip', 14400, '原名.zip');
```

### 2. S3 流式推流防 OOM (S3Helper)
最高安全红线：将服务器本地大文件上传至 OSS，必须使用此方法，它利用 CURLOPT_INFILE 实现无内存损耗推流。

```php
use app\utils\CloudStorage\S3Helper;
$url = S3Helper::putObjectFromFile($config, 'backup/db.sql', '/tmp/local.sql', 'application/sql');
```

### 3. 大文件并发分片断点续传引擎 (Multipart Upload)
强制使用 AWS S3 V4 原生分片协议，通杀所有云厂商，支持断点续传与 GB 级超大文件。

```php
// 步骤 1：向云端申请全局唯一事务 ID
$uploadId = OssClient::initMultipartUpload($config, 'video/huge.mp4');

// 步骤 2：为每个 5MB 的碎片签发单独的直传 URL (供前端并发执行)
$partUrl1 = OssClient::getMultipartPresignedUrl($config, 'video/huge.mp4', $uploadId, 1, 3600);
$partUrl2 = OssClient::getMultipartPresignedUrl($config, 'video/huge.mp4', $uploadId, 2, 3600);

// 步骤 3：收集前端上传成功后的 ETag，提交 XML 合并确权
$parts = [
    ['PartNumber' => 1, 'ETag' => 'xxx1'],
    ['PartNumber' => 2, 'ETag' => 'xxx2']
];
OssClient::completeMultipartUpload($config, 'video/huge.mp4', $uploadId, $parts);
```

### 4. 对象管理与连通性探测
```php
$size = OssClient::getObjectSize($config, '2026/demo.zip');
$deleted = OssClient::deleteObject($config, '2026/demo.zip');
OssClient::checkConnection($config);
```

## 注意事项

纯粹性：严禁在此模块内引入 `aws/aws-sdk-php` 或 `guzzlehttp/guzzle`，所有握手都走原生 `cURL`。

CORS：使用大文件分片引擎合并时，必须在云存储 CORS 配置中暴露 `ETag`（Expose-Headers: ETag），否则前端拿不到 ETag，合并会失败。