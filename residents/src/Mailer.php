<?php
declare(strict_types=1);
namespace SkazResidents;

final class Mailer
{
    /** @var array<int,array{to:string,subject:string,body:string}> письма до конца запроса */
    private static array $later = [];
    private static bool $flushRegistered = false;

    /**
     * Отправить письмо после ответа пользователю. SMTP (таймаут 15 с) больше не
     * держит нажатую кнопку, а у восстановления пароля пропадает разница во
     * времени ответа, по которой было видно, что адрес зарегистрирован.
     *
     * Письмо встаёт в очередь; её выгружает flushLater() в конце запроса. Места
     * вызова можно не переставлять: в отличие от AfterResponse, ответ тут не
     * завершается сразу, и звать later() можно и до header().
     */
    public static function later(string $to, string $subject, string $body): void
    {
        self::$later[] = ['to' => $to, 'subject' => $subject, 'body' => $body];
        if (!self::$flushRegistered) {
            self::$flushRegistered = true;
            register_shutdown_function([self::class, 'flushLater']);
        }
    }

    /** @return array<int,array{to:string,subject:string,body:string}> письма в очереди (для тестов) */
    public static function queued(): array
    {
        return self::$later;
    }

    /**
     * Выгрузить очередь: сохранить сессию (флеш не потеряется, пока браузер идёт
     * по редиректу), отдать ответ и только потом слать. Сбой одного письма — в
     * лог, остальные уходят. Никогда не бросает: зовётся из shutdown-функции.
     */
    public static function flushLater(): void
    {
        if (!self::$later) { return; }
        $queue = self::$later;
        self::$later = [];
        if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
        if (function_exists('fastcgi_finish_request')) { @fastcgi_finish_request(); }   // в CLI его нет
        ignore_user_abort(true);
        foreach ($queue as $m) {
            try {
                self::send($m['to'], $m['subject'], $m['body']);
            } catch (\Throwable $e) {
                error_log('Mailer::later: письмо «' . $m['subject'] . '» на ' . $m['to'] . ' не ушло: ' . $e->getMessage());
            }
        }
    }

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

    /**
     * Можно ли на этот адрес вообще что-то доставить. У жителей, вошедших через
     * Telegram или MAX, адрес служебный — tg<id>@telegram.local / max<id>@max.local
     * (FamilyRepository::createTelegramFamily): колонка обязательная, а почты у
     * них нет. Такие адреса нельзя ни отправлять, ни показывать как «контакт».
     */
    public static function isDeliverable(string $to): bool
    {
        return filter_var($to, FILTER_VALIDATE_EMAIL) !== false
            && !preg_match('~@(telegram|max)\.local$~i', $to);
    }

    /**
     * Отправка через SMTP. Бросает RuntimeException при сбое; вызывающий ловит (fail-open).
     * На служебный адрес не отправляет вовсе: иначе это был бы SMTP-запрос, который
     * заставляет жителя ждать ответа, а письмо всё равно никуда не придёт.
     */
    public static function send(string $to, string $subject, string $body): void
    {
        if (!self::isDeliverable($to)) { return; }
        $cfg = Config::get('smtp');
        if (!is_array($cfg)) { throw new \RuntimeException('SMTP не настроен: нет раздела smtp в config.php'); }
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
