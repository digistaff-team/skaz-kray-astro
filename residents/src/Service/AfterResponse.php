<?php
declare(strict_types=1);
namespace SkazResidents\Service;

/**
 * Работа, которую делаем ПОСЛЕ того, как ответ ушёл пользователю: отправка
 * сообщений ботом и загрузка фотографий в Telegram-хранилище. Фото трёх штук с
 * телефона едут 10–15 секунд, и всё это время браузер ждал редиректа — житель
 * решал, что форма не сработала, и жал кнопку ещё раз (так на Ярмарке из одного
 * товара вышли четыре карточки и три анонса в общем чате).
 *
 * Сессию закрываем первой: иначе следующий запрос жителя ждал бы снятия
 * блокировки файла сессии. ignore_user_abort — чтобы обрыв связи не убил работу
 * на полпути. Ошибки только в лог: запись в БД уже прошла, ронять её нельзя.
 *
 * Важно: $_FILES и их временные файлы живы до самого конца процесса, поэтому
 * загружать снимки отсюда можно. А вот Flash::set после закрытия сессии уже
 * никуда не попадёт — о проблемах здесь сообщаем только в error_log.
 */
final class AfterResponse
{
    public static function run(callable $fn, string $tag = 'AfterResponse'): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
        if (function_exists('fastcgi_finish_request')) { @fastcgi_finish_request(); }
        ignore_user_abort(true);
        try {
            $fn();
        } catch (\Throwable $e) {
            error_log($tag . ': ' . $e->getMessage());
        }
    }
}
