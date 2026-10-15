<?php

declare(strict_types=1);

namespace app\utils\Scheduler;

interface WujieTaskInterface
{
    /**
     * 任务执行主入口
     * @return bool 成功返回 true，失败抛出异常或返回 false
     */
    public function execute(): bool;
}