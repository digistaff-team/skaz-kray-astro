<?php
declare(strict_types=1);
namespace SkazResidents\Service;

/**
 * Тонкий клиент VK API для cron-пересылки постов ВК-группы «Вече»
 * (https://vk.ru/vechesk) в Telegram-рассылку. Только нужные методы:
 * wall.get (свежие посты) и groups.getMembers (подписчики, с пагинацией).
 *
 * Токен — сервисный ключ доступа приложения VK ID (VK_VECHESK_SERVICE_TOKEN в
 * config/.env). Он статичный: не истекает и не требует refresh, поэтому вход
 * через OAuth не нужен. Ключ доступа сообщества для wall.get не подходит —
 * VK отвечает ERR 27 «method is unavailable with group auth».
 *
 * Пустой токен → исключение: cron обязан упасть заметно, а не «прогнать»
 * рассылку впустую.
 */
final class VK
{
    private const API = 'https://api.vk.com/method/';
    private const VERSION = '5.199';

    /** Сетевой вызов (только для инжекции в тесты вместо реального HTTP). */
    private $http;

    /** @param null|callable(string):?string $http */
    public function __construct(?callable $http = null)
    {
        $this->http = $http;
    }

    /** Сервисный ключ доступа из окружения; '' — не задан (вызывающий обязан это обработать). */
    public static function token(): string
    {
        return trim((string) (getenv('VK_VECHESK_SERVICE_TOKEN') ?: ''));
    }

    /**
     * Свежие посты стены группы, от старых к новым.
     *
     * @return array<int,array<string,mixed>> поля id, date, text, attachments
     * @throws \RuntimeException токен пуст или API ответил ошибкой
     */
    public function wallPosts(int $groupId, int $count = 10): array
    {
        $res = $this->call('wall.get', [
            'owner_id' => '-' . $groupId,
            'count'    => $count,
        ]);
        $items = $res['items'] ?? [];
        if (!is_array($items)) { return []; }
        // wall.get отдаёт новые первыми — нам нужен хронологический порядок.
        usort($items, static fn(array $a, array $b): int => ((int) ($a['id'] ?? 0)) <=> ((int) ($b['id'] ?? 0)));
        return $items;
    }

    /**
     * Все подписчики группы (пагинация по 1000, лимит групп — 5000 человек;
     * «Вече Сказочного Края» — маленькая группа, запас есть).
     *
     * @return array<int,array{screen_name?:string,id?:int}>
     * @throws \RuntimeException токен пуст или API ответил ошибкой
     */
    public function groupMembers(int $groupId): array
    {
        $members = [];
        for ($offset = 0; ; $offset += 1000) {
            $res = $this->call('groups.getMembers', [
                'group_id' => $groupId,
                'offset'   => $offset,
                'count'    => 1000,
                'fields'   => 'screen_name',
            ]);
            $items = $res['items'] ?? [];
            if (!is_array($items) || $items === []) { break; }
            foreach ($items as $m) { $members[] = $m; }
            $total = (int) ($res['count'] ?? 0);
            if (count($members) >= $total || count($items) < 1000) { break; }
            if ($offset >= 5000) { break; }   // предохранитель от бесконечной пагинации
        }
        return $members;
    }

    /**
     * Вызов метода API. Ошибка VK ({"response":...} отсутствует, есть "error")
     * или пустой токен — исключение, а не тихий null.
     *
     * @param array<string,scalar> $params
     * @return array<string,mixed> поле "response"
     */
    public function call(string $method, array $params): array
    {
        $token = self::token();
        if ($token === '') {
            throw new \RuntimeException('VK: не задан сервисный ключ VK_VECHESK_SERVICE_TOKEN (config/.env)');
        }

        $params += ['access_token' => $token, 'v' => self::VERSION];
        $raw = $this->httpGet(self::API . $method . '?' . http_build_query($params));
        if ($raw === null) {
            throw new \RuntimeException('VK: ' . $method . ' — сетевой сбой или пустой ответ');
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new \RuntimeException('VK: ' . $method . ' — не JSON: ' . mb_substr($raw, 0, 200));
        }
        if (isset($data['error'])) {
            $msg = (string) ($data['error']['error_msg'] ?? 'ошибка');
            $code = (int) ($data['error']['error_code'] ?? 0);
            throw new \RuntimeException('VK: ' . $method . ' ошибка ' . $code . ': ' . $msg);
        }
        if (!isset($data['response']) || !is_array($data['response'])) {
            throw new \RuntimeException('VK: ' . $method . ' — нет response: ' . mb_substr($raw, 0, 200));
        }
        return $data['response'];
    }

    /** GET с коротким таймаутом — по образцу WaterLevel::httpGet. */
    private function httpGet(string $url): ?string
    {
        if ($this->http !== null) { return ($this->http)($url); }

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 10,
                CURLOPT_FOLLOWLOCATION => true,
            ]);
            $res  = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $err  = curl_error($ch);
            curl_close($ch);
            if ($res === false) { error_log('VK: curl ошибка: ' . $err); return null; }
            if ($code >= 400)   { error_log('VK: ответ HTTP ' . $code); return null; }
            return (string) $res;
        }

        $ctx = stream_context_create(['http' => ['method' => 'GET', 'timeout' => 10, 'ignore_errors' => true]]);
        $res = @file_get_contents($url, false, $ctx);
        return $res === false ? null : (string) $res;
    }
}
