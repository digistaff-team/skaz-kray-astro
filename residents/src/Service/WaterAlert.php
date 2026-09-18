<?php
declare(strict_types=1);
namespace SkazResidents\Service;

use SkazResidents\{Config, MaxBot, TelegramBot};
use SkazResidents\Repository\{WaterAlertRepository, WaterLevelRepository};

/**
 * Оповещение группы жителей о подходе воды к мосту. Вызывается часовым cron'ом
 * (bin/water-level-snapshot.php) сразу после записи замера.
 *
 * Когда пишем в группу (status — из WaterLevel::status по запасу до кромки моста):
 *   — обстановка ухудшилась (calm → watch → alert), в том числе через ступень;
 *   — тревога держится дольше REPEAT_HOURS — напоминаем;
 *   — вода отступила до calm — отбой, один раз.
 * В остальных случаях молчим: иначе сообщение уходило бы каждый час.
 *
 * Скачок уровня больше JUMP_GUARD_CM за один шаг считаем сбоем источника
 * (у AllRivers уже менялась привязка шкалы) и не оповещаем, только пишем в лог:
 * ложная тревога хуже пропущенной строки в журнале.
 */
final class WaterAlert
{
    /** Повтор сообщения, пока держится тревога, часов. */
    private const REPEAT_HOURS = 6;
    /** Недоверчивость к источнику: скачок больше этого за шаг — сбой, см. */
    private const JUMP_GUARD_CM = 300;

    /** @var (callable(string):void)|null подмена отправки для тестов */
    private $sender;

    public function __construct(
        private WaterLevel $water = new WaterLevel(),
        private WaterLevelRepository $history = new WaterLevelRepository(),
        private WaterAlertRepository $state = new WaterAlertRepository(),
        ?callable $sender = null
    ) {
        $this->sender = $sender;
    }

    /**
     * Решить и, если надо, оповестить. Возвращает отправленный текст или null,
     * когда писать не о чем (обстановка не изменилась, история пуста, сбой источника).
     */
    public function check(string $now): ?string
    {
        $row = $this->history->latest();
        if (!$row) { return null; }

        $level  = (float) $row['level_cm'];
        $status = $this->water->status($this->water->bridgeGapCm($level));
        $prev   = $this->state->state();

        if ($prev !== null && abs($level - (float) $prev['level_cm']) > self::JUMP_GUARD_CM) {
            error_log(sprintf(
                'WaterAlert: скачок уровня %.2f → %.2f см, похоже на сбой источника — не оповещаем',
                (float) $prev['level_cm'],
                $level
            ));
            // Состояние всё же обновляем, иначе на следующем шаге сравним с устаревшим уровнем.
            $this->state->remember($status, $level, $now);
            return null;
        }

        if (!$this->shouldNotify($status, $prev, $now)) { return null; }

        $text = $this->message($status, $level, (float) $row['change_24h']);
        $this->send($text);
        $this->state->remember($status, $level, $now);
        return $text;
    }

    /** @param array<string,mixed>|null $prev */
    private function shouldNotify(string $status, ?array $prev, string $now): bool
    {
        // Первый запуск: о спокойной воде сообщать не о чем, о тревожной — сразу пишем.
        if ($prev === null) { return $status !== 'calm'; }

        $was = (string) $prev['status'];
        if ($was === $status) {
            if ($status !== 'alert') { return false; }
            $age = strtotime($now) - strtotime((string) $prev['notified_at']);
            return $age >= self::REPEAT_HOURS * 3600;   // тревога затянулась — напоминаем
        }

        return true;   // и ухудшение, и отбой достойны сообщения
    }

    private function message(string $status, float $levelCm, float $changeCm): string
    {
        $gap = $this->water->bridgeGapCm($levelCm);
        $gapText = $this->water->formatDistance(abs($gap));
        $tail = 'Уровень ' . $this->water->formatMeters($this->water->bsv($levelCm), 2) . ' БСВ, '
              . $this->water->changeLabel($changeCm) . '.';

        if ($gap <= 0) {
            return "\u{1F6A8} Мост под водой: уровень выше нижней кромки на {$gapText}. {$tail}";
        }
        return match ($status) {
            'alert' => "\u{1F6A8} Вода у моста: до нижней кромки {$gapText}. {$tail}",
            'watch' => "\u{26A0}\u{FE0F} Шебш поднимается: до нижней кромки моста {$gapText}. {$tail}",
            default => "\u{2705} Вода отступила: до нижней кромки моста {$gapText}. {$tail}",
        };
    }

    /** Группа жителей в Telegram и в MAX (адреса — в config.php). */
    private function send(string $text): void
    {
        if ($this->sender !== null) { ($this->sender)($text); return; }

        $tg = Config::get('telegram');
        $tgToken = is_array($tg) ? (string) ($tg['bot_token'] ?? '') : '';
        $tgChat  = is_array($tg) ? (string) ($tg['group_chat_id'] ?? '') : '';
        if ($tgToken !== '' && $tgChat !== '') {
            TelegramBot::sendMessage($tgToken, $tgChat, $text);
        }

        $max = Config::get('max');
        $maxToken = is_array($max) ? (string) ($max['bot_token'] ?? '') : '';
        $maxChat  = is_array($max) ? (string) ($max['group_chat_id'] ?? '') : '';
        if ($maxToken !== '' && $maxChat !== '') {
            MaxBot::sendToChat($maxToken, $maxChat, $text);
        }
    }
}
