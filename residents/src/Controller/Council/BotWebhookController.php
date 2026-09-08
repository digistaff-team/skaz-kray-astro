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

        if (!preg_match('/^t:(\d+):(take|decline)$/', $data, $mm)) {
            TelegramBot::answerCallback($token, $callbackId);
            return;
        }
        $taskId = (int) $mm[1];
        $action = $mm[2];

        $tasks = new CouncilTaskRepository();
        $task = $tasks->find($taskId);
        if (!$task) {
            TelegramBot::answerCallback($token, $callbackId, 'Задача не найдена');
            if ($chatId !== '' && $msgId) { TelegramBot::editMessageText($token, $chatId, $msgId, $msgText . "\n\n⚠️ Задача уже удалена."); }
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
        if ($action === 'take') {
            $tasks->updateFields($taskId, ['status' => 'в работе'], $now);
            $result = '✅ Вы взяли задачу в работу';
        } else {
            $tasks->updateFields($taskId, ['assignee' => '', 'status' => 'новая'], $now);
            $result = '🚫 Вы отказались от задачи';
        }

        TelegramBot::answerCallback($token, $callbackId, $result);
        if ($chatId !== '' && $msgId) {
            // reply_markup не передаём → кнопки исчезают; результат дописываем в конец.
            TelegramBot::editMessageText($token, $chatId, $msgId, $msgText . "\n\n" . $result);
        }
    }
}
