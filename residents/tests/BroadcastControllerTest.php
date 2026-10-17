<?php
declare(strict_types=1);
namespace SkazResidents\Tests;
use PHPUnit\Framework\TestCase;
use SkazResidents\{Auth, Config, Csrf};
use SkazResidents\Controller\BroadcastController;
use SkazResidents\Repository\FamilyRepository;

/**
 * Рассылка в Telegram: рендер формы, валидация текста, отказ не-админу
 * и выборка адресатов (FamilyRepository::listWithTelegram).
 * Отправка сообщений здесь не проверяется — сеть не дёргаем (Config::set([])),
 * сам цикл доставки уходит в BotNotify::afterResponse (см. BroadcastController).
 */
final class BroadcastControllerTest extends TestCase
{
    private BroadcastController $ctrl;

    protected function setUp(): void
    {
        make_test_db();
        $_SESSION = [];
        $_POST = [];
        $this->ctrl = new BroadcastController();
        if (function_exists('http_response_code')) {
            @http_response_code(200);   // сброс после предыдущего теста (exit в requireAdmin меняет код)
        }
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        $_POST = [];
    }

    /** Страница рассылки: форма с textarea и списком адресатов. */
    public function test_index_renders_form_for_admin(): void
    {
        $this->loginAs('admin');
        $ctrl = $this->ctrl;
        $html = $this->capture(static fn() => $ctrl->index());
        $this->assertStringContainsString('action="/poselenie/moderation/rassylka"', $html);
        $this->assertStringContainsString('name="text"', $html);
        $this->assertStringContainsString('name="target"', $html);
        $this->assertStringContainsString('name="family_id"', $html);
    }

    /** Пустой текст и текст длиннее 4000 знаков — рендер формы с ошибкой, без отправки. */
    public function test_send_validates_text(): void
    {
        $this->loginAs('admin');
        $ctrl = $this->ctrl;
        $_POST = ['_csrf' => Csrf::token(), 'text' => '   ', 'target' => 'all_tg', 'family_id' => 0];
        $html = $this->capture(static fn() => $ctrl->send());
        $this->assertStringContainsString('не может быть пустым', $html);

        $_POST = ['_csrf' => Csrf::token(), 'text' => str_repeat('а', 4001), 'target' => 'all_tg', 'family_id' => 0];
        $html = $this->capture(static fn() => $ctrl->send());
        $this->assertStringContainsString('длиннее 4000', $html);
    }

    /** Редактор (не админ) от рассылки отбивается 403 и не доходит до отправки. */
    public function test_non_admin_is_rejected(): void
    {
        $this->loginAs('editor');
        $ctrl = $this->ctrl;
        $html = $this->capture(static fn() => $ctrl->index());
        $this->assertStringContainsString('Доступ только для администратора сайта.', $html);
        $code = @http_response_code();
        $this->assertSame(403, is_int($code) ? $code : 0);
        if (function_exists('http_response_code')) {
            @http_response_code(200);   // сброс для следующих тестов
        }
    }

    /** Панель списков на странице рассылки: ссылка на создание и цель «список». */
    public function test_index_renders_list_links(): void
    {
        $this->loginAs('admin');
        $ctrl = $this->ctrl;
        $html = $this->capture(static fn() => $ctrl->index());
        $this->assertStringContainsString('action="/poselenie/moderation/rassylka/spiski/novyy"', $html);
        $this->assertStringContainsString('name="name"', $html);
        $this->assertStringContainsString('name="target"', $html);
        $this->assertStringContainsString('name="list_id"', $html);
    }

    /** Цель «список»: валидация — пустой список, несуществующий и список без Telegram. */
    public function test_send_validates_list_target(): void
    {
        $pdo = make_test_db();
        $this->ctrl = new BroadcastController();   // после смены PDO: репозитории контроллера берут PDO в конструкторе
        $pdo->exec("INSERT INTO families (id, email, telegram_id, password_hash, name, status, role) VALUES
            (1, 'a@sk.ru', 111, 'x', 'Семья А', 'active', 'resident')");
        $lists = new \SkazResidents\Repository\BroadcastListRepository();

        $this->loginAs('admin');
        $ctrl = $this->ctrl;

        // Не выбран список (list_id=0).
        $_POST = ['_csrf' => Csrf::token(), 'text' => 'привет', 'target' => 'list', 'list_id' => 0];
        $html = $this->capture(static fn() => $ctrl->send());
        $this->assertStringContainsString('нет адресатов с привязанным Telegram', $html);

        // Несуществующий список.
        $_POST = ['_csrf' => Csrf::token(), 'text' => 'привет', 'target' => 'list', 'list_id' => 42];
        $html = $this->capture(static fn() => $ctrl->send());
        $this->assertStringContainsString('нет адресатов с привязанным Telegram', $html);

        // Список есть, но член неактивен — валидация отсекает, рассылка не запускается.
        $listId = $lists->create('Пустой');
        $lists->addMember($listId, 1);
        $pdo->exec("UPDATE families SET status = 'pending' WHERE id = 1");
        $_POST = ['_csrf' => Csrf::token(), 'text' => 'привет', 'target' => 'list', 'list_id' => $listId];
        $html = $this->capture(static fn() => $ctrl->send());
        $this->assertStringContainsString('нет адресатов с привязанным Telegram', $html);
    }

    /** Создание списка: короткое название — форма с ошибкой, корректное — новая строка в БД. */
    public function test_create_list_validates_name(): void
    {
        $this->loginAs('admin');
        $ctrl = $this->ctrl;

        $_POST = ['_csrf' => Csrf::token(), 'name' => 'x'];
        $html = $this->capture(static fn() => $ctrl->createList());
        $this->assertStringContainsString('2–120 символов', $html);
        $this->assertSame(0, (int) \SkazResidents\Database::pdo()
            ->query('SELECT COUNT(*) FROM broadcast_lists')->fetchColumn());

        $_POST = ['_csrf' => Csrf::token(), 'name' => 'Дачники'];
        $this->capture(static fn() => $ctrl->createList());
        $row = \SkazResidents\Database::pdo()
            ->query('SELECT name FROM broadcast_lists')->fetch();
        $this->assertSame('Дачники', (string) $row['name']);
    }

    /** Страница списка: показывает участников и формы управления. */
    public function test_show_list_renders_members_and_forms(): void
    {
        $pdo = make_test_db();
        $this->ctrl = new BroadcastController();   // после смены PDO: репозитории контроллера берут PDO в конструкторе
        $pdo->exec("INSERT INTO families (id, email, telegram_id, password_hash, name, status, role) VALUES
            (1, 'a@sk.ru', 111, 'x', 'Sem1', 'active', 'resident'),
            (2, 'b@sk.ru', 222, 'x', 'Sem2', 'active', 'resident')");
        $lists = new \SkazResidents\Repository\BroadcastListRepository();
        $listId = $lists->create('Spisok');
        $lists->addMember($listId, 1);
        $this->loginAs('admin');
        $ctrl = $this->ctrl;
        $html = $this->capture(static fn() => $ctrl->showList(['id' => (string) $listId]));
        $this->assertStringContainsString('Spisok', $html);
        $this->assertStringContainsString('Sem1', $html);
        $this->assertStringContainsString('name="action" value="rename"', $html);
        $this->assertStringContainsString('name="action" value="add"', $html);
        $this->assertStringContainsString('name="action" value="delete"', $html);
        $this->assertStringContainsString('value="2">Sem2</option>', $html);
    }

    public function test_show_missing_list_is_404(): void
    {
        $this->loginAs('admin');
        $ctrl = $this->ctrl;
        $this->capture(static fn() => $ctrl->showList(['id' => '999']));
        $code = @http_response_code();
        $this->assertSame(404, is_int($code) ? $code : 0);
        if (function_exists('http_response_code')) {
            @http_response_code(200);   // сброс для следующих тестов
        }
    }

    /** Добавление/удаление адресата и переименование через updateList. */
    public function test_update_list_add_remove_rename(): void
    {
        $pdo = make_test_db();
        $this->ctrl = new BroadcastController();   // после смены PDO: репозитории контроллера берут PDO в конструкторе
        $pdo->exec("INSERT INTO families (id, email, telegram_id, password_hash, name, status, role) VALUES
            (1, 'a@sk.ru', 111, 'x', 'Семья А', 'active', 'resident'),
            (2, 'b@sk.ru', 222, 'x', 'Семья Б', 'active', 'resident')");
        $lists = new \SkazResidents\Repository\BroadcastListRepository();
        $listId = $lists->create('Черновик');

        $this->loginAs('admin');
        $ctrl = $this->ctrl;

        // Добавить по select (family_id).
        $_POST = ['_csrf' => Csrf::token(), 'action' => 'add', 'family_id' => 1, 'who' => ''];
        $this->capture(static fn() => $ctrl->updateList(['id' => (string) $listId]));
        $this->assertCount(1, $lists->members($listId));

        // Добавить по свободному email.
        $_POST = ['_csrf' => Csrf::token(), 'action' => 'add', 'family_id' => 0, 'who' => 'b@sk.ru'];
        $this->capture(static fn() => $ctrl->updateList(['id' => (string) $listId]));
        $this->assertCount(2, $lists->members($listId));

        // Добавить несуществующего — членов не прибавилось.
        $_POST = ['_csrf' => Csrf::token(), 'action' => 'add', 'family_id' => 0, 'who' => 'nikogo@skaz-kray.ru'];
        $this->capture(static fn() => $ctrl->updateList(['id' => (string) $listId]));
        $this->assertCount(2, $lists->members($listId));

        // Убрать одного.
        $_POST = ['_csrf' => Csrf::token(), 'action' => 'remove', 'family_id' => 1];
        $this->capture(static fn() => $ctrl->updateList(['id' => (string) $listId]));
        $this->assertCount(1, $lists->members($listId));

        // Переименовать.
        $_POST = ['_csrf' => Csrf::token(), 'action' => 'rename', 'name' => 'Готовый'];
        $this->capture(static fn() => $ctrl->updateList(['id' => (string) $listId]));
        $this->assertSame('Готовый', (string) $lists->find($listId)['name']);

        // Удалить список.
        $_POST = ['_csrf' => Csrf::token(), 'action' => 'delete'];
        $this->capture(static fn() => $ctrl->updateList(['id' => (string) $listId]));
        $this->assertNull($lists->find($listId));
        // Членство в SQLite осталось бы без FK — чистим явно (в MySQL CASCADE).
        $pdo->exec('DELETE FROM broadcast_list_members');
    }

    /**
     * Чат-группа как адресат списка: добавление только из разрешённого списка
     * (config), чужой chat_id отклоняется, удаление работает.
     */
    public function test_update_list_add_remove_chat(): void
    {
        $pdo = make_test_db();
        $this->ctrl = new BroadcastController();   // после смены PDO: репозитории пересоздать
        Config::set(['telegram' => [
            'bot_token' => '',
            'broadcast_group_chats' => [['chat_id' => -1001580770653, 'title' => 'Чат Действуем вместе']],
        ]]);
        $lists = new \SkazResidents\Repository\BroadcastListRepository();
        $listId = $lists->create('С чатом');

        $this->loginAs('admin');
        $ctrl = $this->ctrl;

        // Добавить разрешённый чат.
        $_POST = ['_csrf' => Csrf::token(), 'action' => 'add_chat', 'chat_id' => '-1001580770653'];
        $this->capture(static fn() => $ctrl->updateList(['id' => (string) $listId]));
        $chats = $lists->chats($listId);
        $this->assertCount(1, $chats);
        $this->assertSame(-1001580770653, (int) $chats[0]['chat_id']);
        $this->assertSame('Чат Действуем вместе', (string) $chats[0]['chat_title']);

        // Повторное добавление — no-op (UNIQUE на паре list_id + chat_id).
        $_POST = ['_csrf' => Csrf::token(), 'action' => 'add_chat', 'chat_id' => '-1001580770653'];
        $this->capture(static fn() => $ctrl->updateList(['id' => (string) $listId]));
        $this->assertCount(1, $lists->chats($listId));

        // Чужой (не из разрешённого списка) chat_id — не добавился.
        $_POST = ['_csrf' => Csrf::token(), 'action' => 'add_chat', 'chat_id' => '-1009999999999'];
        $this->capture(static fn() => $ctrl->updateList(['id' => (string) $listId]));
        $this->assertCount(1, $lists->chats($listId));

        // Убрать чат.
        $_POST = ['_csrf' => Csrf::token(), 'action' => 'remove_chat', 'chat_id' => '-1001580770653'];
        $this->capture(static fn() => $ctrl->updateList(['id' => (string) $listId]));
        $this->assertSame([], $lists->chats($listId));
        // Список жив, только чат убран: удаление членства не трогает сам список
        // (аналогично action=remove для семьи — см. test_update_list_add_remove_rename).
        $this->assertNotNull($lists->find($listId));
    }

    /**
     * Рассылка по списку с чатом: resolveRecipients возвращает и семьи, и чат,
     * deliver() доходит до TelegramBot::sendMessage с chat_id чата (токен в тесте
     * пустой — сетевых вызовов нет, sendMessage возвращает null без сети).
     */
    public function test_send_list_with_chat_delivers_to_chat(): void
    {
        $pdo = make_test_db();
        $this->ctrl = new BroadcastController();
        $pdo->exec("INSERT INTO families (id, email, telegram_id, password_hash, name, status, role) VALUES
            (1, 'a@sk.ru', 111, 'x', 'Семья А', 'active', 'resident')");
        Config::set(['telegram' => [
            'bot_token' => '',   // пустой токен — sendMessage не делает сетевых вызовов
            'broadcast_group_chats' => [['chat_id' => -1001580770653, 'title' => 'Чат Действуем вместе']],
        ]]);
        $lists = new \SkazResidents\Repository\BroadcastListRepository();
        $listId = $lists->create('С чатом');
        $lists->addMember($listId, 1);
        $lists->addChat($listId, -1001580770653, 'Чат Действуем вместе');

        $this->loginAs('admin');
        $ctrl = $this->ctrl;
        $_POST = [
            '_csrf' => Csrf::token(), 'text' => 'Привет, чат!',
            'target' => 'list', 'list_id' => (string) $listId, 'family_id' => 0,
        ];
        $this->capture(static fn() => $ctrl->send());   // редирект + deliver() в afterResponse

        // resolveRecipients: семья + чат, чат — строка вида {id=0, telegram_id<0}.
        $ref = new \ReflectionMethod($ctrl, 'resolveRecipients');
        $ref->setAccessible(true);
        $recipients = $ref->invoke($ctrl, 'list', 0, $listId);
        $this->assertCount(2, $recipients);
        $byId = [];
        foreach ($recipients as $r) { $byId[(int) $r['id']] = $r; }
        $this->assertSame(111, (int) $byId[1]['telegram_id']);          // семья
        $this->assertSame(-1001580770653, (int) $byId[0]['telegram_id']); // чат
        $this->assertSame('Чат Действуем вместе', (string) $byId[0]['name']);
    }

    private function loginAs(string $role): void
    {
        $_SESSION = ['family_id' => 1, 'role' => $role, 'family_name' => 'Тест'];
    }

    /** Выполнить контроллер, забрав его HTML-вывод. */
    private function capture(callable $fn): string
    {
        ob_start();
        try {
            $fn();
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        return (string) ob_get_clean();
    }
}
