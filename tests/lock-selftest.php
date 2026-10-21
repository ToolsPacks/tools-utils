<?php
declare(strict_types=1);

/**
 * 在线自检：在目标站点自己的 Redis 配置上验证加锁行为。
 *
 * 用法（在服务器上，用站点运行时的 PHP）：
 *   php vendor/toolspacks/utils/tests/lock-selftest.php /www/wwwroot/<站点目录>
 *
 * 覆盖：抢锁成功、重复抢锁失败、错 token 不释放、对 token 释放后重新可抢。
 * 会写入一个 selftest 前缀的短 TTL 键并自行删除，不触碰业务数据。
 */

use app\utils\Concurrency\LockHelper;

$root = $argv[1] ?? '';

if ($root === '' || !is_file($root . '/vendor/autoload.php')) {
    fwrite(STDERR, "用法: php tests/lock-selftest.php <站点根目录>\n");
    exit(2);
}

require $root . '/vendor/autoload.php';

try {
    (new \think\App($root))->initialize();
} catch (\Throwable $e) {
    fwrite(STDERR, '应用初始化失败: ' . $e->getMessage() . PHP_EOL);
    exit(2);
}

$key    = 'selftest:' . gethostname() . ':' . getmypid();
$checks = [];

try {
    $checks['acquire']           = LockHelper::lock($key, 5, 'token-a') === true;
    $checks['contend']           = LockHelper::lock($key, 5, 'token-b') === false;
    LockHelper::unlock($key, 'token-b');
    $checks['wrong_token_keeps'] = LockHelper::lock($key, 5, 'token-c') === false;
    LockHelper::unlock($key, 'token-a');
    $checks['released']          = LockHelper::lock($key, 5, 'token-d') === true;
    LockHelper::unlock($key, 'token-d');
} catch (\Throwable $e) {
    fwrite(STDERR, '执行异常: ' . get_class($e) . ' ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

foreach ($checks as $name => $pass) {
    printf("%-22s %s\n", $name, $pass ? 'PASS' : 'FAIL');
}

$failed = in_array(false, $checks, true);

if ($failed) {
    fwrite(STDERR, PHP_EOL . '存在失败项，停止发布' . PHP_EOL);
}

exit($failed ? 1 : 0);
