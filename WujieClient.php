<?php
declare(strict_types=1);

namespace app\utils;

use app\utils\CloudStorage\OssClient;
use app\utils\WujieHub\HubClient;
use app\utils\PushMaster\PushClient;

/**
 * 无界通用 SDK 核心门面 (Wujie SDK Facade)
 * 目标：实现一键初始化，清晰隔离物理云驱动与内部微服务系统
 */
class WujieClient
{
    /**
     * 实例化无界云盘接入引擎 (直连系统业务网关)
     * * @param string $endpoint 无界系统网关 (如 https://your-hub-domain.com)
     * @param string $apiToken 后台分配的通信密钥
     */
    public static function hub(string $endpoint, string $apiToken, int $timeout = 10): HubClient
    {
        return new HubClient($endpoint, $apiToken, $timeout);
    }

    /**
     * 实例化云原生底层物理驱动 (直连官方 OSS/COS/R2)
     */
    public static function cloudStorage(): OssClient
    {
        return new OssClient();
    }

    /**
     * 实例化消息推送中枢
     */
    public static function pushMaster(string $endpoint, string $sendKey, int $timeout = 5): PushClient
    {
        return new PushClient($endpoint, $sendKey, $timeout);
    }
}