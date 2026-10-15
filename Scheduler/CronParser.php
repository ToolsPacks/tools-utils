<?php
declare(strict_types=1);

namespace app\utils\Scheduler;

class CronParser
{
    /**
     * 判断当前时间是否满足 Cron 表达式
     * 支持语法: *, *\5, 1-5, 0,30, 15
*/
    public static function isDue(string $expression, int $time = null): bool
    {
        $time = $time ?: time();
        $parts = preg_split('/\s+/', trim($expression));

        if (count($parts) < 5) {
            return false;
        }

        $date = getdate($time);

        return self::matchPart($parts[0], $date['minutes']) &&
            self::matchPart($parts[1], $date['hours']) &&
            self::matchPart($parts[2], $date['mday']) &&
            self::matchPart($parts[3], $date['mon']) &&
            self::matchPart($parts[4], $date['wday']);
    }

    private static function matchPart(string $part, int $value): bool
    {
        if ($part === '*') return true;

        // 处理步长: */5
        if (strpos($part, '/') !== false) {
            [$range, $step] = explode('/', $part);
            return $value % (int)$step === 0;
        }

        // 处理区间: 1-5
        if (strpos($part, '-') !== false) {
            [$min, $max] = explode('-', $part);
            return $value >= (int)$min && $value <= (int)$max;
        }

        // 处理列表: 0,30
        if (strpos($part, ',') !== false) {
            return in_array($value, explode(',', $part));
        }

        // 处理固定值: 15
        return (int)$part === $value;
    }
}

