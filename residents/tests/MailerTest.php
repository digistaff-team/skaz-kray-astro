<?php
declare(strict_types=1);
namespace SkazResidents\Tests;
use PHPUnit\Framework\TestCase;
use SkazResidents\Mailer;

final class MailerTest extends TestCase
{
    protected function tearDown(): void
    {
        Mailer::flushLater();   // очередь писем не должна переживать тест
        \SkazResidents\Config::set([]);
    }

    public function test_later_queues_instead_of_sending(): void
    {
        // Настроек SMTP нет: будь это отправка, она бы упала. Очередь — просто список.
        Mailer::later('semya@skaz-kray.ru', 'Тема', 'Тело');
        Mailer::later('ivan@mail.ru', 'Тема 2', 'Тело 2');
        $this->assertSame(['semya@skaz-kray.ru', 'ivan@mail.ru'], array_column(Mailer::queued(), 'to'));
        $this->assertSame(['Тема', 'Тема 2'], array_column(Mailer::queued(), 'subject'));
    }

    public function test_flush_drains_the_queue_and_never_throws(): void
    {
        // Почтовый сервер недоступен (закрытый порт): send() бросит — flushLater()
        // обязан это проглотить (в лог) и всё равно опустошить очередь.
        \SkazResidents\Config::set(['smtp' => [
            'host' => '127.0.0.1', 'port' => 9, 'secure' => '', 'user' => 'x', 'pass' => 'x',
            'from' => 'noreply@skaz-kray.ru', 'from_name' => 'Сказочный Край',
        ]]);
        Mailer::later('semya@skaz-kray.ru', 'Тема', 'Тело');
        Mailer::later('tg1@telegram.local', 'Тема', 'Тело');   // служебный адрес — пропускается молча

        Mailer::flushLater();
        $this->assertSame([], Mailer::queued());
    }

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

    public function test_service_addresses_of_messenger_residents_are_not_deliverable(): void
    {
        // У жителей из Telegram и MAX адрес служебный — писать на него некуда.
        $this->assertFalse(Mailer::isDeliverable('tg123456@telegram.local'));
        $this->assertFalse(Mailer::isDeliverable('max987@max.local'));
        $this->assertFalse(Mailer::isDeliverable('TG1@TELEGRAM.LOCAL'));
        $this->assertFalse(Mailer::isDeliverable('не адрес'));
        $this->assertFalse(Mailer::isDeliverable(''));

        $this->assertTrue(Mailer::isDeliverable('semya@skaz-kray.ru'));
        $this->assertTrue(Mailer::isDeliverable('ivan@mail.ru'));
    }

    public function test_send_to_service_address_returns_without_touching_smtp(): void
    {
        // Настроек SMTP в тестах нет: если бы send() пошёл дальше проверки адреса,
        // он упал бы на Config::get('smtp'). Тишина — значит, отправки не было.
        Mailer::send('tg123@telegram.local', 'Тема', 'Тело');
        $this->addToAssertionCount(1);
    }
}
