<?php
declare(strict_types=1);

namespace app\utils;

use think\facade\Log;

/**
 * 系统配置密钥字段加解密工具
 *
 * 设计要点：
 *  1. 采用 AES-256-CBC 对称加密，主密钥不落库，仅从部署环境读取；
 *  2. 每个密钥字段独立生成随机 IV，避免相同明文产生相同密文；
 *  3. 存储格式固定为 encrypted:{base64(iv)}:{base64(ciphertext)}，便于识别与兼容历史明文；
 *  4. 解密失败时返回空字符串并告警，绝不把密文当作明文抛给业务层使用。
 */
class ConfigEncryption
{
    /** 密文前缀标识，用于区分历史明文数据 */
    private const PREFIX = 'encrypted';

    /** 分隔符：base64 字符集不包含冒号，可安全用于拼接 */
    private const SEPARATOR = ':';

    /** 加密算法与 IV 长度（AES-256-CBC 固定 16 字节 IV） */
    private const CIPHER = 'aes-256-cbc';
    private const IV_LENGTH = 16;

    /**
     * 加密明文
     *
     * @param string $plaintext 待加密内容
     * @return string 形如 encrypted:{iv}:{ciphertext} 的密文；入参为空时原样返回
     */
    public static function encrypt(string $plaintext): string
    {
        if ($plaintext === '') {
            return '';
        }

        try {
            $iv = random_bytes(self::IV_LENGTH);
            $ciphertext = openssl_encrypt(
                $plaintext,
                self::CIPHER,
                self::masterKey(),
                OPENSSL_RAW_DATA,
                $iv
            );

            if ($ciphertext === false) {
                Log::error('配置密钥加密失败：openssl_encrypt 返回 false');
                return '';
            }

            return self::PREFIX . self::SEPARATOR
                . base64_encode($iv) . self::SEPARATOR
                . base64_encode($ciphertext);
        } catch (\Throwable $e) {
            Log::error('配置密钥加密异常: ' . $e->getMessage());
            return '';
        }
    }

    /**
     * 解密密文
     *
     * 兼容历史明文：若入参不是本工具生成的密文格式，则原样返回，保证平滑迁移。
     *
     * @param string $value 密文或历史明文
     * @return string 解密后的明文；解密失败返回空字符串
     */
    public static function decrypt(string $value): string
    {
        if ($value === '' || !self::isEncrypted($value)) {
            return $value;
        }

        $parts = explode(self::SEPARATOR, $value);
        if (count($parts) !== 3) {
            Log::warning('配置密钥解密失败：密文格式不正确');
            return '';
        }

        [, $ivEncoded, $cipherEncoded] = $parts;

        $iv = base64_decode($ivEncoded, true);
        $ciphertext = base64_decode($cipherEncoded, true);

        if ($iv === false || $ciphertext === false || strlen($iv) !== self::IV_LENGTH) {
            Log::warning('配置密钥解密失败：IV 或密文解码异常');
            return '';
        }

        try {
            $plaintext = openssl_decrypt(
                $ciphertext,
                self::CIPHER,
                self::masterKey(),
                OPENSSL_RAW_DATA,
                $iv
            );
        } catch (\Throwable $e) {
            // 保持本类的既有契约：解密失败返回空字符串并告警，不向业务层抛异常
            Log::error('配置密钥解密异常: ' . $e->getMessage());
            return '';
        }

        if ($plaintext === false) {
            Log::error('配置密钥解密失败：请确认 CONFIG_ENCRYPT_KEY 与加密时保持一致');
            return '';
        }

        return $plaintext;
    }

    /**
     * 判断给定值是否为本工具生成的密文
     */
    public static function isEncrypted(string $value): bool
    {
        return str_starts_with($value, self::PREFIX . self::SEPARATOR);
    }

    /**
     * 解析主密钥：优先使用专用配置 CONFIG_ENCRYPT_KEY，其次复用 JWT_SECRET。
     * 统一做 SHA-256 摘要，确保长度稳定为 32 字节，符合 AES-256 要求。
     *
     * 两个来源都缺失时抛异常而不降级：源码内置的默认密钥对任何拿到源码的人都是已知的，
     * 静默降级等于把密文交给公开密钥。
     *
     * @throws \RuntimeException 两个密钥来源都未配置
     */
    private static function masterKey(): string
    {
        $rawKey = (string)(env('CONFIG_ENCRYPT_KEY') ?: env('JWT_SECRET'));
        if ($rawKey === '') {
            throw new \RuntimeException('未配置 CONFIG_ENCRYPT_KEY 或 JWT_SECRET，已拒绝加解密');
        }

        return hash('sha256', $rawKey, true);
    }
}