<?php
declare(strict_types=1);
namespace SkazResidents\Tests;

use PHPUnit\Framework\TestCase;
use SkazResidents\Service\VK;

/**
 * Клиент VK API (wall.get / groups.getMembers): разбор ответа, хронологический
 * порядок постов, пагинация подписчиков, ошибки API и отсутствие ключа.
 * Сеть подменена колбэком $http; сервисный ключ — переменная окружения.
 */
final class VKTest extends TestCase
{
    /** @var string|false сохранённое значение ключа окружения */
    private $saved;

    protected function setUp(): void
    {
        $this->saved = getenv('VK_VECHESK_SERVICE_TOKEN');
        putenv('VK_VECHESK_SERVICE_TOKEN=test-token');
    }

    protected function tearDown(): void
    {
        if ($this->saved === false) {
            putenv('VK_VECHESK_SERVICE_TOKEN');   // убрать
        } else {
            putenv('VK_VECHESK_SERVICE_TOKEN=' . $this->saved);
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

    /**
     * Ключ доступа сообщества стену не читает (ERR 27). Фолбэка на wall.getById
     * нет: он упирается в ту же ошибку, поэтому пробрасываем её как есть.
     */
    public function test_group_auth_err27_throws_without_fallback(): void
    {
        $urls = [];
        $vk = new VK(static function (string $u) use (&$urls): string {
            $urls[] = $u;
            return json_encode(['error' => [
                'error_code' => 27,
                'error_msg'  => 'Group authorization failed: method is unavailable with group auth.',
            ]]);
        });

        try {
            $vk->wallPosts(63502598, 2);
            $this->fail('ожидали исключение');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('ошибка 27', $e->getMessage());
        }
        $this->assertCount(1, $urls, 'только один вызов — wall.getById больше не пробуем');
        $this->assertStringContainsString('method/wall.get?', $urls[0]);
    }

    /** Пустой ключ → исключение ещё до сетевого вызова. */
    public function test_empty_token_throws_without_http(): void
    {
        putenv('VK_VECHESK_SERVICE_TOKEN');
        $called = false;
        $vk = new VK(static function (string $u) use (&$called): string {
            $called = true;
            return '{}';
        });
        try {
            $vk->wallPosts(1);
            $this->fail('ожидали исключение');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('VK_VECHESK_SERVICE_TOKEN', $e->getMessage());
        }
        $this->assertFalse($called, 'HTTP не должен вызываться без ключа');
    }

    /** Не-JSON ответ → исключение. */
    public function test_non_json_throws(): void
    {
        $vk = new VK(static fn(string $url): string => '<html>502</html>');
        $this->expectException(\RuntimeException::class);
        $vk->wallPosts(1);
    }

    /** token(): значение из окружения без хвостовых пробелов, пусто → ''. */
    public function test_token_reads_env(): void
    {
        putenv('VK_VECHESK_SERVICE_TOKEN= abc123 ');
        $this->assertSame('abc123', VK::token());
        putenv('VK_VECHESK_SERVICE_TOKEN');
        $this->assertSame('', VK::token());
    }
}
