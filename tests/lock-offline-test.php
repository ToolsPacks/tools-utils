<?php
declare(strict_types=1);

/**
 * 离线自检：用假的 Redis 句柄验证 LockHelper 的兼容性问题是否修好。
 *
 * 不依赖 Redis 服务、不加载 think 框架，直接执行：
 *   php tests/lock-offline-test.php
 *
 * 覆盖面：phpredis 的 options 数组写法、Predis 的 eval 参数顺序、
 * 两种客户端的 key 前缀策略、默认参数、token 校验。
 */

namespace Predis {
    class Client
    {
    }
}

namespace think\facade {
    class Cache
    {
        public static mixed $handler = null;

        public static function store(string $name): object
        {
            return new class {
                public function handler(): mixed
                {
                    return \think\facade\Cache::$handler;
                }
            };
        }
    }
}

namespace {

    use app\utils\Concurrency\LockHelper;

    require __DIR__ . '/../Concurrency/LockHelper.php';

    $GLOBALS['__config'] = ['cache.stores.redis.prefix' => 'tkgmv:'];

    if (!function_exists('config')) {
        function config(string $name = '', mixed $default = null): mixed
        {
            return $GLOBALS['__config'][$name] ?? $default;
        }
    }

    /** phpredis 的 Redis::set 只有 (key, value, options) 三个参数 */
    final class FakePhpRedis
    {
        public array $store = [];
        public array $calls = [];

        public function set(string $key, string $value, array $options = []): bool
        {
            $this->calls[] = ['set', $key, $options];

            if (in_array('nx', $options, true) && array_key_exists($key, $this->store)) {
                return false;
            }

            $this->store[$key] = $value;

            return true;
        }

        public function eval(string $script, array $args, int $numKeys): int
        {
            $this->calls[] = ['eval', $args, $numKeys];

            [$key, $token] = $args;

            if (($this->store[$key] ?? null) === $token) {
                unset($this->store[$key]);

                return 1;
            }

            return 0;
        }
    }

    /** Predis 的 eval 是 (script, numKeys, ...args)，set 成功返回 OK */
    final class FakePredis extends \Predis\Client
    {
        public array $store = [];
        public array $calls = [];

        public function set(string $key, string $value, array $options = []): string|false
        {
            $this->calls[] = ['set', $key, $options];

            if (in_array('nx', $options, true) && array_key_exists($key, $this->store)) {
                return false;
            }

            $this->store[$key] = $value;

            return 'OK';
        }

        public function eval(string $script, int $numKeys, ...$args): int
        {
            $this->calls[] = ['eval', $args, $numKeys];

            [$key, $token] = $args;

            if (($this->store[$key] ?? null) === $token) {
                unset($this->store[$key]);

                return 1;
            }

            return 0;
        }
    }

    $failures = 0;
    $check = function (string $name, bool $ok) use (&$failures): void {
        printf("%-44s %s\n", $name, $ok ? 'PASS' : 'FAIL');
        $failures += $ok ? 0 : 1;
    };

    $lastCall = static function (array $calls): array {
        return $calls[count($calls) - 1] ?? [];
    };

    // phpredis：key 带宿主前缀，选项走数组形式
    $redis = new FakePhpRedis();
    \think\facade\Cache::$handler = $redis;

    $check('phpredis 首次加锁成功', LockHelper::lock('order:1', 5, 'token-a') === true);
    $set = $lastCall($redis->calls);
    $check('phpredis set 为 options 数组', $set[0] === 'set' && in_array('nx', $set[2], true) && ($set[2]['ex'] ?? null) === 5);
    $check('phpredis 锁键带宿主前缀', $set[1] === 'tkgmv:lock:order:1');

    $check('phpredis 二次加锁失败', LockHelper::lock('order:1', 5, 'token-b') === false);

    LockHelper::unlock('order:1', 'token-b');
    $check('phpredis 错 token 不释放', LockHelper::lock('order:1', 5, 'token-c') === false);

    LockHelper::unlock('order:1', 'token-a');
    $check('phpredis 正确 token 释放', LockHelper::lock('order:1', 5, 'token-d') === true);
    LockHelper::unlock('order:1', 'token-d');

    // 默认参数：现网存在 lock($key) / lock($key, $ttl) / unlock($key) 的调用
    $check('lock 单参调用不报错', LockHelper::lock('order:2') === true);
    $defaults = $lastCall($redis->calls);
    $check('默认 ttl=60 token=1', ($defaults[2]['ex'] ?? null) === 60 && $redis->store['tkgmv:lock:order:2'] === '1');
    LockHelper::unlock('order:2');
    $check('unlock 单参调用生效', LockHelper::lock('order:2', 5, 'x') === true);

    LockHelper::unlock('order:2', 'x');

    // Predis：客户端已加前缀，这里不重复拼接；eval 参数顺序为 (script, numKeys, ...args)
    $predis = new FakePredis();
    \think\facade\Cache::$handler = $predis;

    $check('predis 加锁成功（返回 OK）', LockHelper::lock('order:3', 5, 'token-a') === true);
    $set = $lastCall($predis->calls);
    $check('predis 锁键不重复拼前缀', $set[1] === 'lock:order:3');

    LockHelper::unlock('order:3', 'token-a');
    $eval = $lastCall($predis->calls);
    $check('predis eval 参数顺序正确', $eval[0] === 'eval' && $eval[1] === ['lock:order:3', 'token-a'] && $eval[2] === 1);

    echo PHP_EOL, $failures === 0 ? "全部通过\n" : "失败 {$failures} 项\n";
    exit($failures === 0 ? 0 : 1);
}
