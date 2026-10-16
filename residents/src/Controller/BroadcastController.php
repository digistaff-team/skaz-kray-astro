<?php
declare(strict_types=1);
namespace SkazResidents\Controller;

use SkazResidents\{Auth, Config, Csrf, Flash, TelegramBot, View};
use SkazResidents\Repository\FamilyRepository;
use SkazResidents\Service\BotNotify;

/**
 * Массовая рассылка жителям в Telegram (@SkazKray_bot) — только админ сайта.
 * Текст уходит как есть (без parse_mode): так спецсимволы HTML/Markdown не
 * ломают сообщение. Отправка — после ответа браузеру (BotNotify::afterResponse),
 * с паузой между сообщениями, чтобы не упереться в лимиты Bot API (~30/сек).
 */
final class BroadcastController
{
    private const TARGET_ALL_TG = 'all_tg';   // все семьи с привязанным Telegram
    private const TARGET_ALL    = 'all';      // то же — совпадает с all_tg (лимит бота общий)
    private const TARGET_FAMILY = 'family';   // одна конкретная семья

    public function __construct(
        private FamilyRepository $families = new FamilyRepository()
    ) {}

    /** Страница с формой рассылки. */
    public function index(): void
    {
        Auth::requireAdmin();
        View::render('broadcast/index', [
            'targets' => $this->families->listWithTelegram(),
            'errors'  => [],
            'old'     => [],
        ], 'Рассылка в Telegram');
    }

    /** Отправка: валидация → Flash+редирект → отправка после ответа. */
    public function send(): void
    {
        Auth::requireAdmin();
        Csrf::guard();

        $text   = trim((string) ($_POST['text'] ?? ''));
        $target = (string) ($_POST['target'] ?? self::TARGET_ALL_TG);
        $family = (int) ($_POST['family_id'] ?? 0);

        $errors = [];
        if ($text === '') {
            $errors['text'] = 'Текст рассылки не может быть пустым.';
        } elseif (mb_strlen($text) > 4000) {
            $errors['text'] = 'Текст длиннее 4000 символов — Telegram не примет такое сообщение.';
        }
        if (!in_array($target, [self::TARGET_ALL_TG, self::TARGET_ALL, self::TARGET_FAMILY], true)) {
            $errors['target'] = 'Неизвестный получатель рассылки.';
        }
        $recipients = [];
        if (!$errors) {
            $recipients = $this->resolveRecipients($target, $family);
            if ($recipients === []) {
                $errors['target'] = 'У выбранного получателя нет привязанного Telegram.';
            }
        }
        if ($errors) {
            View::render('broadcast/index', [
                'targets' => $this->families->listWithTelegram(),
                'errors'  => $errors,
                'old'     => ['text' => $text, 'target' => $target, 'family_id' => $family],
            ], 'Рассылка в Telegram');
            return;
        }

        Flash::set('success', 'Рассылка запущена: сообщения уйдут ' . count($recipients) . ' адресатам.');
        header('Location: /poselenie/moderation/rassylka');
        $this->deliver($recipients, $text);
    }

    /** @return array<int,array<string,mixed>> */
    private function resolveRecipients(string $target, int $familyId): array
    {
        if ($target === self::TARGET_FAMILY) {
            $family = $familyId > 0 ? $this->families->findById($familyId) : null;
            if (!$family || $family['status'] !== 'active' || ($family['telegram_id'] ?? null) === null) {
                return [];
            }
            return [$family];
        }
        return $this->families->listWithTelegram();
    }

    /**
     * Отправка после ответа браузеру. Пауза 30 мс между сообщениями + жёсткий
     * лимит 2500 за запуск — страховка от долгого прогона и лимитов Bot API.
     * Ошибки отдельных адресатов (заблокировали бота) — только в error_log.
     *
     * @param array<int,array<string,mixed>> $recipients
     */
    private function deliver(array $recipients, string $text): void
    {
        $token = (string) (Config::get('telegram')['bot_token'] ?? '');
        BotNotify::afterResponse(static function () use ($recipients, $text, $token): void {
            $sent = 0;
            foreach ($recipients as $r) {
                if ($sent >= 2500) {
                    error_log('Broadcast: достигнут лимит 2500 сообщений за один запуск');
                    break;
                }
                $chatId = (string) ($r['telegram_id'] ?? '');
                if ($chatId === '') { continue; }
                if (TelegramBot::sendMessage($token, $chatId, $text)) {
                    $sent++;
                } else {
                    error_log('Broadcast: сообщение не ушло семье ' . (int) $r['id'] . ' (tg ' . $chatId . ')');
                }
                usleep(30_000);
            }
            error_log('Broadcast: отправлено ' . $sent . ' из ' . count($recipients) . ' адресатов');
        });
    }
}