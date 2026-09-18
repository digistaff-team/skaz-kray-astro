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
 * Скачок уровня больше JUMP_GUARD_CM за один шаг — повод не верить источнику
 * на слово (у AllRivers уже менялась привязка шкалы). Но замалчивать такой скачок
 * нельзя: ливневый паводок в горах поднимает воду быстро, и это ровно тот случай,
 * ради которого всё затевалось. Поэтому при скачке к опасной обстановке пишем в
 * группу с пометкой «проверьте лично», а при скачке к спокойной — молчим: ложный
 * отбой хуже лишней строки в логе.
 *
 * Состояние запоминается на каждом замере, даже когда в группу не пишем, — иначе
 * сравнивать было бы не с чем до первой тревоги.
 */
final class WaterAlert
{
    /** Повтор сообщения, пока держится тревога, часов. */
    private const REPEAT_HOURS = 6;
    /** Скачок больше этого за шаг — верим с оговоркой (см. докблок), см. */
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

        $jumped = $prev !== null && abs($level - (float) $prev['level_cm']) > self::JUMP_GUARD_CM;
        if ($jumped) {
            error_log(sprintf(
                'WaterAlert: скачок уровня %.2f → %.2f см — %s',
                (float) $prev['level_cm'],
                $level,
                $status === 'calm' ? 'молчим, похоже на сбой источника' : 'оповещаем с оговоркой'
            ));
            // Скачок «улучшил» картину — отбой по такому замеру не даём.
            if ($status === 'calm') {
                $this->state->remember($status, $level);
                return null;
            }
        }

        if (!$this->shouldNotify($status, $prev, $now)) {
            $this->state->remember($status, $level);
            return null;
        }

        $text = $this->message($status, $level, (float) $row['change_24h'], $jumped);
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
            $lastSent = (string) ($prev['notified_at'] ?? '');
            if ($lastSent === '') { return true; }      // состояние знали, а написать не успели
            $age = strtotime($now) - strtotime($lastSent);
            return $age >= self::REPEAT_HOURS * 3600;   // тревога затянулась — напоминаем
        }

        return true;   // и ухудшение, и отбой достойны сообщения
    }

    private function message(string $status, float $levelCm, float $changeCm, bool $suspect = false): string
    {
        $gap = $this->water->bridgeGapCm($levelCm);
        $gapText = $this->water->formatDistance(abs($gap));
        $tail = 'Уровень ' . $this->water->formatMeters($this->water->bsv($levelCm), 2) . ' БСВ, '
              . $this->water->changeLabel($changeCm) . '.';

        // Замер после резкого скачка мог прийти и от сбоя источника — просим проверить.
        if ($suspect) {
            $tail .= ' Уровень изменился скачком — проверьте обстановку лично.';
        }

        if ($gap <= 0) {
            return "\u{1F6A8} Мост под водой: уровень выше нижней кромки на {$gapText}. {$tail}";
        }
        return match ($status) {
            'alert' => "\u{1F6A8} Вода у моста: до нижней кромки {$gapText}. {$tail}",
            'watch' => "\u{26A0}\u{FE0F} Шебш поднимается: до нижней кромки моста {$gapText}. {$tail}",
            default => "\u{2705} Вода отступила: до нижней кромки моста {$gapText}. {$tail}",
        };
    }

    /**
     * Канал оповещений в Telegram и группа в MAX (адреса — в config.php).
     * Отдельный ключ telegram.water_alert_chat_id: про воду пишем в свой канал,
     * а не в общий чат жителей. Не задан — падаем обратно на общий чат.
     */
    private function send(string $text): void
    {
        if ($this->sender !== null) { ($this->sender)($text); return; }

        $tg = Config::get('telegram');
        $tgToken = is_array($tg) ? (string) ($tg['bot_token'] ?? '') : '';
        $tgChat  = is_array($tg) ? (string) ($tg['water_alert_chat_id'] ?? '') : '';
        if ($tgChat === '') {
            $tgChat = is_array($tg) ? (string) ($tg['group_chat_id'] ?? '') : '';
        }
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
