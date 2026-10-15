<?php
declare(strict_types=1);

namespace app\utils;

class StringHelper
{
    /**
     * 生成安全的随机字符串
     */
    public static function generateRandomCode(int $length = 6): string
    {
        $chars = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
        $code = '';
        $max = strlen($chars) - 1;
        for ($i = 0; $i < $length; $i++) {
            $code .= $chars[random_int(0, $max)];
        }
        return $code;
    }
}