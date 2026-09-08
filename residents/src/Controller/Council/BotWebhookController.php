<?php
declare(strict_types=1);
namespace SkazResidents\Controller\Council;

use SkazResidents\{Config, TelegramBot};
use SkazResidents\Repository\{CouncilTaskRepository, CouncilMemberRepository};

/**
 * Webhook @SkazKray_bot — обрабатывает нажатия inline-кнопок под уведомлением о
 * задаче («Взял в работу» / «Отказался»). Проверка подлинности — секрет-заголовок
 * X-Telegram-Bot-Api-Secret-Token (задаётся при setWebhook). Публичный роут.
 *
 * ВНИМАНИЕ: webhook у бота один. Раньше был занят ProTalk — при включении наших
 * кнопок ProTalk-интеграция бота отключается (см. bin/council-set-webhook.php).
 */
final class BotWebhookController
{
    public function handle(): void
    {
        $tg = Config::get('telegram');
        $secret = (string) ($tg['webhook_secret'] ?? '');
        $got = (string) ($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '');
        if ($secret === '' || !hash_equals($secret, $got)) {
            http_response_code(403);
            return;
        }

        $update = json_decode((string) file_get_contents('php://input'), true);

        // Отвечаем Telegram сразу, обработку (медленные вызовы API) — после ответа.
        http_response_code(200);
        echo 'ok';
        if (function_exists('fastcgi_finish_request')) { @fastcgi_finish_request(); }

        if (!is_array($update) || empty($update['callback_query'])) { return; }
        $this->handleCallback($update['callback_query'], (string) ($tg['bot_token'] ?? ''));
    }

    /** @param array<string,mixed> $cq */
    private function handleCallback(array $cq, string $token): void
    {
        $callbackId = (string) ($cq['id'] ?? '');
        $data       = (string) ($cq['data'] ?? '');
        $fromId     = (int) ($cq['from']['id'] ?? 0);
        $msg        = is_array($cq['message'] ?? null) ? $cq['message'] : [];
        $chatId     = (string) ($msg['chat']['id'] ?? '');
        $msgId      = (int) ($msg['message_id'] ?? 0);
        $msgText    = (string) ($msg['text'] ?? '');

        if (!preg_match('/^t:(\d+):(take|decline|p20|p50|p80|done)$/', $data, $mm)) {
            TelegramBot::answerCallback($token, $callbackId);
            return;
        }
        $taskId = (int) $mm[1];
        $action = $mm[2];

        $tasks = new CouncilTaskRepository();
        $task = $tasks->find($taskId);
        if (!$task) {
            TelegramBot::answerCallback($token, $callbackId, 'Задача не найдена');
            self::renderEdit($token, $chatId, $msgId, $msgText . "\n\n⚠️ Задача уже удалена.", null);
            return;
        }

        // Действие доступно только исполнителю (тому, кому назначена задача).
        $member = (new CouncilMemberRepository())->findByTelegramId($fromId);
        $name = $member ? (string) $member['name'] : '';
        if ($name === '' || $name !== (string) ($task['assignee'] ?? '')) {
            TelegramBot::answerCallback($token, $callbackId, 'Эта задача назначена не вам');
            return;
        }

        $now = date('Y-m-d H:i:s');
        switch ($action) {
            case 'take':
                $tasks->updateFields($taskId, ['status' => 'в работе'], $now);
                TelegramBot::answerCallback($token, $callbackId, '✅ Взято в работу');
                // Убираем стартовые кнопки, дописываем результат и показываем кнопки прогресса.
                self::renderEdit($token, $chatId, $msgId, $msgText . "\n\n✅ Вы взяли задачу в работу", self::progressKeyboard($taskId));
                return;

            case 'decline':
                $tasks->updateFields($taskId, ['assignee' => '', 'status' => 'новая'], $now);
                TelegramBot::answerCallback($token, $callbackId, '🚫 Вы отказались');
                self::renderEdit($token, $chatId, $msgId, $msgText . "\n\n🚫 Вы отказались от задачи", null);
                return;

            case 'p20':
            case 'p50':
            case 'p80':
                $pct = (int) substr($action, 1);
                $tasks->updateFields($taskId, ['progress' => $pct], $now);
                TelegramBot::answerCallback($token, $callbackId, "Прогресс: {$pct}%");
                return; // сообщение и кнопки прогресса не трогаем

            case 'done':
                $tasks->updateFields($taskId, ['status' => 'выполнена', 'progress' => 100], $now);
                TelegramBot::answerCallback($token, $callbackId, '🎉 Задача выполнена');
                $plain = strpos($msgText, '✅ Вы взяли задачу в работу') !== false
                    ? str_replace('✅ Вы взяли задачу в работу', '🎉 Вы выполнили эту задачу, большое спасибо!', $msgText)
                    : $msgText . "\n\n🎉 Вы выполнили эту задачу, большое спасибо!";
                self::renderEdit($token, $chatId, $msgId, $plain, null);
                return;
        }
    }

    /** Клавиатура прогресса, появляется после «Взял в работу». */
    private static function progressKeyboard(int $id): string
    {
        return (string) json_encode(['inline_keyboard' => [
            [
                ['text' => '20%', 'callback_data' => "t:{$id}:p20"],
                ['text' => '50%', 'callback_data' => "t:{$id}:p50"],
                ['text' => '80%', 'callback_data' => "t:{$id}:p80"],
            ],
            [
                ['text' => 'Выполнена', 'callback_data' => "t:{$id}:done"],
            ],
        ]], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Переписать сообщение целиком: $plainText — итоговый текст (plain), пересобираем
     * как HTML с сохранением ссылки в «Подробнее». $keyboard=null → кнопки убираются.
     */
    private static function renderEdit(string $token, string $chatId, int $msgId, string $plainText, ?string $keyboard): void
    {
        if ($token === '' || $chatId === '' || !$msgId) { return; }

        $base = (string) (Config::get('council_app_link', 'https://t.me/SkazKray_bot/sovet') ?: 'https://t.me/SkazKray_bot/sovet');
        $link = $base . '?startapp=' . rtrim(strtr(base64_encode('/sovet/zadachi'), '+/', '-_'), '=');
        $e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $html = [];
        foreach (explode("\n", $plainText) as $line) {
            $html[] = trim($line) === 'Подробнее'
                ? '<a href="' . $e($link) . '">Подробнее</a>'
                : $e($line);
        }
        TelegramBot::editMessageText($token, $chatId, $msgId, implode("\n", $html), 'HTML', $keyboard);
    }
}
