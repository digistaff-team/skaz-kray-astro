<?php
declare(strict_types=1);
namespace SkazResidents\Service;

use SkazResidents\{Config, Mailer};
use SkazResidents\Repository\FamilyRepository;

/**
 * Модераторам — о новом на проверке: объявления ярмарки и записи дневника
 * «на сайте», заявки на вход по почте. Лично каждому активному редактору и
 * админу, ботом и письмом, в момент, когда вещь встала в очередь.
 * Спека: docs/superpowers/specs/2026-09-24-moderatoram-o-novom-design.md.
 *
 * Звать ТОЛЬКО после Flash::set() и header('Location: …'): отправка идёт через
 * AfterResponse, который сразу завершает ответ.
 */
final class ModerationNotify
{
    private const PATH = '/poselenie/moderation';

    /**
     * Вещь встала в очередь: всем модераторам, кроме автора, — бот и письмо
     * (на служебные адреса жителей из мессенджеров Mailer не шлёт). Сбой
     * отправки одному модератору — в лог, остальные получают.
     */
    public static function queued(string $what, string $title, string $author, ?int $authorId): void
    {
        BotNotify::afterResponse(static function () use ($what, $title, $author, $authorId): void {
            $lines = self::lines($what, $title, $author);
            foreach (self::recipients((new FamilyRepository())->listModerators(), $authorId) as $m) {
                try {
                    BotNotify::toFamily((int) $m['id'], static fn(string $base): string
                        => BotNotify::personalText($lines, 'Проверить: ', $base, self::PATH));
                } catch (\Throwable $e) {
                    error_log('ModerationNotify: бот не дошёл до модератора ' . $m['id'] . ': ' . $e->getMessage());
                }
                try {
                    Mailer::send((string) $m['email'], 'На проверку: ' . $what . ' — Сказочный Край',
                        "Здравствуйте!\n\n" . implode("\n", $lines)
                        . "\n\nПроверить: " . Config::get('base_url') . self::PATH);
                } catch (\Throwable $e) {
                    error_log('ModerationNotify: письмо не ушло модератору ' . $m['id'] . ': ' . $e->getMessage());
                }
            }
        });
    }

    /**
     * Встала ли вещь в очередь: она «на сайте» и до этого не ждала проверки.
     * Правка того, что уже ждёт, повторно не уведомляет — модератор о нём знает.
     * У новой вещи прежнего статуса нет — null.
     */
    public static function entersQueue(?string $prevStatus, string $visibility): bool
    {
        return $visibility === 'public' && $prevStatus !== 'pending';
    }

    /**
     * Модераторы без автора: о своей публикации сообщение не нужно.
     *
     * @param array<int,array<string,mixed>> $moderators
     * @return array<int,array<string,mixed>>
     */
    public static function recipients(array $moderators, ?int $authorId): array
    {
        return array_values(array_filter($moderators,
            static fn(array $m): bool => (int) $m['id'] !== $authorId));
    }

    /**
     * Текст сообщения. Пустой автор — без строки «От:»: у заявки на вход «кто» —
     * это и есть название семьи, а почту заявителя в Telegram не кладём.
     *
     * @return array<int,string>
     */
    public static function lines(string $what, string $title, string $author): array
    {
        $lines = ['🛡 На проверку: ' . $what, '', '«' . $title . '»'];
        if ($author !== '') { $lines[] = 'От: ' . $author; }
        return $lines;
    }
}
