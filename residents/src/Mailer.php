<?php
declare(strict_types=1);
namespace SkazResidents;

final class Mailer
{
    /** Собирает RFC-822 сообщение (заголовки + тело). Отдельно для тестируемости. */
    public static function buildMessage(
        string $from, string $fromName, string $to, string $subject, string $body
    ): string {
        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $encodedName    = '=?UTF-8?B?' . base64_encode($fromName) . '?=';
        $headers = [
            'From: ' . $encodedName . ' <' . $from . '>',
            'To: ' . $to,
            'Subject: ' . $encodedSubject,
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
        ];
        return implode("\r\n", $headers) . "\r\n\r\n" . $body;
    }

    /** Отправка через SMTP. Бросает RuntimeException при сбое; вызывающий ловит (fail-open). */
    public static function send(string $to, string $subject, string $body): void
    {
        $cfg = Config::get('smtp');
        $message = self::buildMessage($cfg['from'], $cfg['from_name'], $to, $subject, $body);

        $transport = ($cfg['secure'] === 'ssl' ? 'ssl://' : '') . $cfg['host'];
        $fp = @stream_socket_client(
            $transport . ':' . $cfg['port'], $errno, $errstr, 15
        );
        if (!$fp) {
            throw new \RuntimeException("SMTP connect failed: $errstr ($errno)");
        }

        $expect = function (string $code) use ($fp) {
            $line = '';
            while (($l = fgets($fp, 515)) !== false) {
                $line = $l;
                if (isset($l[3]) && $l[3] === ' ') break;
            }
            if (strncmp($line, $code, 3) !== 0) {
                throw new \RuntimeException("SMTP unexpected: $line");
            }
        };
        $cmd = function (string $c) use ($fp) { fwrite($fp, $c . "\r\n"); };

        $expect('220');
        $cmd('EHLO skaz-kray.ru'); $expect('250');
        if ($cfg['secure'] === 'tls') {
            $cmd('STARTTLS'); $expect('220');
            stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            $cmd('EHLO skaz-kray.ru'); $expect('250');
        }
        $cmd('AUTH LOGIN'); $expect('334');
        $cmd(base64_encode($cfg['user'])); $expect('334');
        $cmd(base64_encode($cfg['pass'])); $expect('235');
        $cmd('MAIL FROM:<' . $cfg['from'] . '>'); $expect('250');
        $cmd('RCPT TO:<' . $to . '>'); $expect('250');
        $cmd('DATA'); $expect('354');
        fwrite($fp, $message . "\r\n.\r\n"); $expect('250');
        $cmd('QUIT');
        fclose($fp);
    }
}
