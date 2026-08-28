<?php
declare(strict_types=1);
namespace SkazResidents\Tests;
use PHPUnit\Framework\TestCase;
use SkazResidents\Mailer;

final class MailerTest extends TestCase
{
    public function test_build_message_has_utf8_subject_and_body(): void
    {
        $msg = Mailer::buildMessage(
            'noreply@skaz-kray.ru', 'Сказочный Край',
            'semya@skaz-kray.ru', 'Заявка одобрена', "Здравствуйте!\nВаш аккаунт активен."
        );
        $this->assertStringContainsString('To: semya@skaz-kray.ru', $msg);
        $this->assertStringContainsString('Content-Type: text/plain; charset=UTF-8', $msg);
        $this->assertStringContainsString('=?UTF-8?B?', $msg); // MIME-кодированная тема
        $this->assertStringContainsString('Ваш аккаунт активен', $msg);
    }
}
