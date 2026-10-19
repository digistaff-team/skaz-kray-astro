<?php
declare(strict_types=1);
namespace SkazResidents\Tests;

use PHPUnit\Framework\TestCase;
use SkazResidents\Service\VK;

/**
 * Клиент VK API (wall.get / groups.getMembers): разбор ответа, хронологический
 * порядок постов, пагинация подписчиков, ошибки API и отсутствие токена.
 * Сеть подменена колбэком $http; токен — переменная окружения.
 */
final class VKTest extends TestCase
{
    /** @var string|false сохранённое значение токена окружения */
    private $saved;

    protected function setUp(): void
    {
        $this->saved = getenv('VECHESK_GROUP_VK_TOKEN');
        putenv('VECHESK_GROUP_VK_TOKEN=test-token');
    }

    protected function tearDown(): void
    {
        if ($this->saved === false) {
            putenv('VECHESK_GROUP_VK_TOKEN');   // убрать
        } else {
            putenv('VECHESK_GROUP_VK_TOKEN=' . $this->saved);
        }
    }

    /** wall.get: посты отсортированы от старых к новым (курсор моста опирается на порядок). */
    public function test_wall_posts_sorted_ascending(): void
    {
        $vk = new VK(static fn(string $url): string => json_encode([
            'response' => [
                'count' => 3,
                'items' => [
                    ['id' => 30, 'date' => 3, 'text' => 'новее'],
                    ['id' => 10, 'date' => 1, 'text' => 'старее'],
                    ['id' => 20, 'date' => 2, 'text' => 'середина'],
                ],
            ],
        ], JSON_UNESCAPED_UNICODE));

        $posts = $vk->wallPosts(63502598, 10);
        $this->assertSame([10, 20, 30], array_column($posts, 'id'));
    }

    public function test_wall_posts_calls_wall_get_with_negative_owner(): void
    {
        $url = null;
        $vk = new VK(static function (string $u) use (&$url): string {
            $url = $u;
            return '{"response":{"count":0,"items":[]}}';
        });
        $vk->wallPosts(63502598, 5);
        $this->assertIsString($url);
        $this->assertStringContainsString('method/wall.get', $url);
        $this->assertStringContainsString('owner_id=-63502598', $url);
        $this->assertStringContainsString('count=5', $url);
        $this->assertStringContainsString('access_token=test-token', $url);
    }

    /** Пагинация groups.getMembers: страницы склеиваются, стоп по count/пустой. */
    public function test_group_members_paginates(): void
    {
        $page = 0;
        $vk = new VK(static function (string $u) use (&$page): string {
            $page++;
            if ($page === 1) {
                $items = [];
                for ($i = 0; $i < 1000; $i++) { $items[] = ['id' => $i, 'screen_name' => 'u' . $i]; }
                return json_encode(['response' => ['count' => 1200, 'items' => $items]]);
            }
            return json_encode(['response' => ['count' => 1200, 'items' => [
                ['id' => 1001, 'screen_name' => 'last'],
            ]]]);
        });

        $members = $vk->groupMembers(63502598);
        $this->assertCount(1001, $members);
        $this->assertSame('last', $members[1000]['screen_name']);
        $this->assertSame(2, $page);   // две страницы
    }

    /** Ошибка VK API → исключение, а не тихий пустой список. */
    public function test_api_error_throws(): void
    {
        $vk = new VK(static fn(string $url): string => json_encode([
            'error' => ['error_code' => 5, 'error_msg' => 'User authorization failed'],
        ]));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ошибка 5');
        $vk->wallPosts(1);
    }

    /** Пустой токен → исключение ещё до сетевого вызова. */
    public function test_empty_token_throws_without_http(): void
    {
        putenv('VECHESK_GROUP_VK_TOKEN');
        putenv('SKAZKRAY_VK_TOKEN');   // и фолбэк-имя тоже пусто
        $called = false;
        $vk = new VK(static function (string $u) use (&$called): string {
            $called = true;
            return '{}';
        });
        try {
            $vk->wallPosts(1);
            $this->fail('ожидали исключение');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('VECHESK_GROUP_VK_TOKEN', $e->getMessage());
        }
        $this->assertFalse($called, 'HTTP не должен вызываться без токена');
    }

    /** Не-JSON ответ → исключение. */
    public function test_non_json_throws(): void
    {
        $vk = new VK(static fn(string $url): string => '<html>502</html>');
        $this->expectException(\RuntimeException::class);
        $vk->wallPosts(1);
    }

    /** Токен: основное имя env имеет приоритет над фолбэком. */
    public function test_token_prefers_new_env_name(): void
    {
        putenv('SKAZKRAY_VK_TOKEN=old');
        $this->assertSame('test-token', VK::token());
        putenv('VECHESK_GROUP_VK_TOKEN');
        $this->assertSame('old', VK::token());
        putenv('SKAZKRAY_VK_TOKEN');
        $this->assertSame('', VK::token());
    }

    /**
     * Фолбэк при ERR 27: wall.get отвечает ошибкой 27 → клиент переходит на
     * wall.getById (посты читаются по известным id, стена идёт подряд с 1).
     */
    public function test_wall_posts_fallback_to_wall_get_by_id_on_err27(): void
    {
        $urls = [];
        $vk = new VK(static function (string $u) use (&$urls): string {
            $urls[] = $u;
            if (str_contains($u, 'method/wall.get?')) {
                return json_encode(['error' => ['error_code' => 27, 'error_msg' => 'Access to group wall denied']]);
            }
            // wall.getById: отдаём два поста (id 8 и 9 — «свежие» в окне).
            return json_encode(['response' => [
                'count' => 2,
                'items' => [
                    ['id' => 8, 'date' => 80, 'text' => 'старее'],
                    ['id' => 9, 'date' => 90, 'text' => 'новее'],
                ],
            ]]);
        });

        $posts = $vk->wallPosts(63502598, 2);
        $this->assertSame([8, 9], array_column($posts, 'id'), 'фолбэк отдаёт посты по id, от старых к новым');

        $joined = implode("\n", $urls);
        $this->assertStringContainsString('method/wall.get?', $joined, 'сначала пробуем wall.get');
        $this->assertStringContainsString('method/wall.getById?', $joined, 'при ERR 27 — переходим на wall.getById');
        $this->assertStringContainsString('63502598_', $joined, 'wall.getById получает id вида <gid>_<id>');
    }
}