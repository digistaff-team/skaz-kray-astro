<?php
declare(strict_types=1);
namespace SkazResidents\Tests;
use PHPUnit\Framework\TestCase;
use SkazResidents\Service\ModerationNotify;

/**
 * Уведомление модераторов о новом на проверке: когда слать, кому и что.
 * Саму отправку (бот, почта) не тестируем — как и у остальных уведомлений.
 */
final class ModerationNotifyTest extends TestCase
{
    public function test_new_public_item_enters_the_queue(): void
    {
        $this->assertTrue(ModerationNotify::entersQueue(null, 'public'));
    }

    public function test_editing_an_item_already_waiting_does_not_notify_again(): void
    {
        // Модератор о нём уже знает.
        $this->assertFalse(ModerationNotify::entersQueue('pending', 'public'));
    }

    public function test_editing_a_published_or_rejected_item_puts_it_back_in_the_queue(): void
    {
        $this->assertTrue(ModerationNotify::entersQueue('published', 'public'));
        $this->assertTrue(ModerationNotify::entersQueue('rejected', 'public'));
    }

    public function test_items_not_for_the_site_skip_moderation(): void
    {
        foreach (['residents', 'private'] as $visibility) {
            $this->assertFalse(ModerationNotify::entersQueue(null, $visibility), $visibility);
            $this->assertFalse(ModerationNotify::entersQueue('rejected', $visibility), $visibility);
        }
    }

    public function test_author_is_not_notified_about_own_item(): void
    {
        $mods = [['id' => 3, 'name' => 'Редактор'], ['id' => 7, 'name' => 'Админ']];
        $this->assertSame([7], array_column(ModerationNotify::recipients($mods, 3), 'id'));
        $this->assertSame([3, 7], array_column(ModerationNotify::recipients($mods, 99), 'id'));
        $this->assertSame([3, 7], array_column(ModerationNotify::recipients($mods, null), 'id'));
    }

    public function test_message_lines(): void
    {
        $this->assertSame(
            ['🛡 На проверку: объявление', '', '«Мёд»', 'От: Семья Шубиных'],
            ModerationNotify::lines('объявление', 'Мёд', 'Семья Шубиных')
        );
    }

    public function test_message_without_author_has_no_from_line(): void
    {
        // Заявка на вход: почту заявителя в Telegram не кладём, название семьи — это и есть «кто».
        $this->assertSame(
            ['🛡 На проверку: заявка на вход', '', '«Поместье Ивановых»'],
            ModerationNotify::lines('заявка на вход', 'Поместье Ивановых', '')
        );
    }
}
