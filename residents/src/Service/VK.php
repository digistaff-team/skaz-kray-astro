<?php
declare(strict_types=1);
namespace SkazResidents\Service;

/**
 * Тонкий клиент VK API для cron-пересылки постов ВК-группы «Вече»
 * (https://vk.ru/vechesk) в Telegram-рассылку. Только нужные методы:
 * wall.get (свежие посты) и groups.getMembers (подписчики, с пагинацией).
 *
 * Токен — из окружения (config/.env: VECHESK_GROUP_VK_TOKEN, читается в bootstrap
 * через Env::load). Пустой токен → исключение: вызывающий cron-скрипт сам
 * решает, что делать (см. bin/vk-vechesk-sync.php — там dry-run с ошибкой).
 * Токен доступен для wall.get и groups.getMembers даже у обычного участника
 * группы — отдельных прав не требуем.
 *
 * ВАЖНО (сентябрь 2026): у фактического токена в prod нет прав на wall.get
 * (ERR 27 Access to group wall denied), при этом wall.getById работает.
 * wallPosts() при ERR 27 автоматически переходит на wall.getById (см. ниже).
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

    /** Токен VK из окружения; '' — не задан (вызывающий обязан это обработать). */
    public static function token(): string
    {
        $t = trim((string) (getenv('VECHESK_GROUP_VK_TOKEN') ?: ''));
        if ($t !== '') { return $t; }
        // Совместимость со старым именем, если уже где-то задано.
        return trim((string) (getenv('SKAZKRAY_VK_TOKEN') ?: ''));
    }

    /**
     * Свежие посты стены группы, от старых к новым (сортировка wall.get по умолчанию).
     *
     * Если wall.get отвечает ERR 27 (нет прав на стену — так и есть у нашего
     * токена), переходим на wall.getById: берём известный опорный id (высокий,
     * ниже любого реально существующего — стена идёт подряд с 1), читаем окно
     * постов и расширяем окно назад, пока не наберём $count.
     *
     * @return array<int,array<string,mixed>> поля id, date, text, attachments
     * @throws \RuntimeException токен пуст или API ответил ошибкой (не ERR 27)
     */
    public function wallPosts(int $groupId, int $count = 10): array
    {
        try {
            $res = $this->call('wall.get', [
                'owner_id' => '-' . $groupId,
                'count'    => $count,
            ]);
        } catch (\RuntimeException $e) {
            if (!preg_match('/ошибка 27:/', $e->getMessage())) {
                throw $e;
            }
            return $this->wallPostsById($groupId, $count);
        }
        $items = $res['items'] ?? [];
        if (!is_array($items)) { return []; }
        // wall.get отдаёт новые первыми — нам нужен хронологический порядок.
        usort($items, static fn(array $a, array $b): int => ((int) ($a['id'] ?? 0)) <=> ((int) ($b['id'] ?? 0)));
        return $items;
    }

    /**
     * Фолбэк wallPosts() при ERR 27: чтение через wall.getById по известным id.
     *
     * Стена группы — монотонный ряд id от 1..N. Начинаем с высокого опорного id
     * (100000 — выше любого реального поста в маленькой группе) и расширяем
     * окно назад на 100, пока не наберём $count постов. Пропуски (удалённые
     * посты) перекрываются за счёт расширения окна.
     *
     * @return array<int,array<string,mixed>> от старых к новым
     * @throws \RuntimeException токен пуст или wall.getById ответил ошибкой
     */
    private function wallPostsById(int $groupId, int $count): array
    {
        $start = 100000;                       // опорный id (выше любого реального)
        $width = max(100, $count * 10);        // первоначальное окно
        $posts = [];

        for ($attempt = 0; $attempt < 6; $attempt++) {
            // Запрашиваем окно [start - width + 1 .. start] одним вызовом
            // (wall.getById принимает список id через запятую).
            $ids = [];
            for ($id = $start - $width + 1; $id <= $start; $id++) {
                $ids[] = $groupId . '_' . $id;
            }
            $res = $this->call('wall.getById', [
                'posts' => implode(',', $ids),
            ]);
            $items = $res['items'] ?? [];
            if (is_array($items)) {
                foreach ($items as $it) { $posts[(int) ($it['id'] ?? 0)] = $it; }
            }
            ksort($posts);                     // от старых к новым
            if (count($posts) >= $count) {
                break;
            }
            // Не хватает — расширяем окно назад и повторяем.
            $start -= $width;
            $width *= 2;
            if ($start <= 0) { break; }        // дошли до начала стены (id=1)
        }

        // Берём последние $count (свежие) и отдаём от старых к новым.
        $posts = array_slice($posts, -$count);
        return array_values($posts);
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
     * или пустой токен — исключение, а не тихий null: cron обязан упасть
     * заметно, а не «прогнать» рассылку впустую.
     *
     * @param array<string,scalar> $params
     * @return array<string,mixed> поле "response"
     */
    public function call(string $method, array $params): array
    {
        $token = self::token();
        if ($token === '') {
            throw new \RuntimeException('VK: не задан токен VECHESK_GROUP_VK_TOKEN (config/.env)');
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