<?php
declare(strict_types=1);
namespace SkazResidents\Service;

use SkazResidents\{Config, TelegramBot, MaxBot};
use SkazResidents\Repository\CouncilMemberRepository;

/**
 * Личное сообщение новому Дежурному председателю в момент ротации — понедельник
 * 21:00, сразу после состоявшейся встречи (bin/council-meeting-advance.php).
 *
 * Пишем только тому, кто стал дежурным: остальные члены совета узнают дежурного
 * из карточки встречи и из общей рассылки в день встречи
 * (bin/council-meeting-notify.php). Канал — тот же @SkazKray_bot, в Telegram и/или
 * MAX, смотря что привязано у члена совета. Ошибки не роняют ротацию: она уже
 * произошла, и сообщение — дело десятое.
 */
final class CouncilDutyNotify
{
    /** Подпись кнопки-подтверждения под сообщением. */
    public const ACK_LABEL = 'Дежурство принял';

    /** Текст сообщения. Отдельно от отправки — чтобы его можно было проверить тестом и --dry-run. */
    public static function text(string $chair, string $when, string $place, string $link): string
    {
        $first = self::firstName($chair);
        $lines = [
            ($first !== '' ? $first . ', ' : '') . 'Вы — дежурный председатель следующей встречи Попечительского совета',
            $when !== '' ? $when : 'Дата встречи уточняется',
        ];
        if ($place !== '') { $lines[] = $place; }
        $lines[] = '';
        $lines[] = 'Обязанности Дежурного председателя смотрите в Положении о ПС.';
        $lines[] = '';
        $lines[] = 'Повестка встречи здесь:';
        $lines[] = $link;
        return implode("\n", $lines);
    }

    /**
     * Кнопка «Дежурство принял» под сообщением. В callback_data — позиция ротации,
     * чтобы кнопка из прошлой недели не подтверждала дежурство нового председателя.
     */
    public static function ackKeyboard(int $rotationIndex): string
    {
        return (string) json_encode([
            'inline_keyboard' => [[['text' => '✅ ' . self::ACK_LABEL, 'callback_data' => 'd:ack:' . $rotationIndex]]],
        ], JSON_UNESCAPED_UNICODE);
    }

    /** «Сергей Шубин» → «Сергей»: в личном сообщении обращаемся по имени. */
    private static function firstName(string $name): string
    {
        $name = trim($name);
        return $name === '' ? '' : (string) (preg_split('~\s+~u', $name)[0] ?? '');
    }

    /**
     * Отправить новому дежурному. Возвращает true, если сообщение ушло хотя бы
     * в один канал (в лог пишем в любом случае — cron-скрипт его покажет).
     */
    public static function newChair(string $chair, string $when, string $place, int $rotationIndex = 0): bool
    {
        $member = (new CouncilMemberRepository())->findByName($chair);
        if (!$member) {
            error_log('CouncilDutyNotify: не нашли в совете «' . $chair . '» — уведомление не отправлено');
            return false;
        }

        $tgToken = (string) (Config::get('telegram')['bot_token'] ?? '');
        $maxCfg  = Config::get('max');
        $maxToken = is_array($maxCfg) ? (string) ($maxCfg['bot_token'] ?? '') : '';
        if ($maxToken === '') { $maxToken = (string) (getenv('SKAZKRAY_MAX_BOT_TOKEN') ?: ''); }

        $sent = false;
        if ($tgToken !== '' && !empty($member['telegram_id'])) {
            $text = self::text($chair, $when, $place, self::link(self::base('tg')));
            $keyboard = self::ackKeyboard($rotationIndex);
            if (TelegramBot::sendMessage($tgToken, (string) $member['telegram_id'], $text, null, $keyboard)) {
                $sent = true;
            } else {
                error_log('CouncilDutyNotify: не доставлено в Telegram — ' . $chair);
            }
        }
        // В MAX кнопок под сообщением не шлём — там только текст со ссылкой.
        if ($maxToken !== '' && !empty($member['max_user_id'])) {
            $text = self::text($chair, $when, $place, self::link(self::base('max')));
            if (MaxBot::sendMessage($maxToken, (string) $member['max_user_id'], $text)) {
                $sent = true;
            } else {
                error_log('CouncilDutyNotify: не доставлено в MAX — ' . $chair);
            }
        }
        return $sent;
    }

    /** Диплинк мини-приложения Совета на карточку встречи. */
    public static function link(string $base): string
    {
        return BotNotify::deepLink($base, '/sovet');
    }

    /** Ссылка бота Совета своей платформы (как в bin/council-meeting-notify.php). */
    public static function base(string $platform): string
    {
        if ($platform === 'max') {
            $max = Config::get('max');
            $link = is_array($max) ? (string) ($max['app_link'] ?? '') : '';
            return $link !== '' ? $link : 'https://max.ru/id643900558807_2_bot?startapp';
        }
        return (string) (Config::get('council_app_link', 'https://t.me/SkazKray_bot/sovet')
            ?: 'https://t.me/SkazKray_bot/sovet');
    }
}
