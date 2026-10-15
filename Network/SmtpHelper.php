<?php
declare(strict_types=1);

namespace app\utils\Network;

use Exception;

class SmtpHelper
{
    /**
     * 原生 Socket SMTP 邮件发送 (极简、无第三方依赖)
     */
    // 【核心修复】分离发件人昵称和发件人邮箱，确保协议的强验证规范
    public static function send(string $host, int $port, string $user, string $pass, string $fromName, string $fromEmail, string $to, string $subject, string $body): bool
    {
        $isSsl = ($port === 465 || $port === 994);
        $protocol = $isSsl ? 'ssl://' : '';

        // 设置 15 秒超时防阻塞
        $socket = @fsockopen($protocol . $host, $port, $errno, $errstr, 15);
        if (!$socket) {
            throw new Exception("SMTP Connection Failed: {$errstr} ({$errno})");
        }

        stream_set_timeout($socket, 15);

        // 内部读取函数
        $recv = function() use ($socket) {
            $data = '';
            while ($str = fgets($socket, 515)) {
                $data .= $str;
                if (substr($str, 3, 1) === ' ') break;
            }
            return $data;
        };

        // 内部写入函数
        $send = function($cmd) use ($socket) {
            fputs($socket, $cmd . "\r\n");
        };

        $recv(); // 读取欢迎语
        $send("EHLO wujie"); $recv();
        $send("AUTH LOGIN"); $recv();
        $send(base64_encode($user)); $recv();
        $send(base64_encode($pass)); $recv();
        // 【核心修复】MAIL FROM 必须是带有 @ 的标准发信邮箱地址
        $send("MAIL FROM: <{$fromEmail}>"); $recv();
        $send("RCPT TO: <{$to}>"); $recv();
        $send("DATA"); $recv();

        // 构造标准化邮件头，防止中文乱码
        $subjectBase64 = "=?UTF-8?B?" . base64_encode($subject) . "?=";
        // 【核心修复】摘除“视频同步助手”脏代码，使用动态传入的署名
        $headers = "From: =?UTF-8?B?" . base64_encode($fromName) . "?= <{$fromEmail}>\r\n" .
            "To: <{$to}>\r\n" .
            "Subject: {$subjectBase64}\r\n" .
            "MIME-Version: 1.0\r\n" .
            "Content-Type: text/html; charset=UTF-8\r\n\r\n";

        $send($headers . $body . "\r\n.");
        $recv();
        $send("QUIT");
        $recv();

        fclose($socket);
        return true;
    }
}