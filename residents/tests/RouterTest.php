<?php
declare(strict_types=1);
namespace SkazResidents\Tests;
use PHPUnit\Framework\TestCase;
use SkazResidents\Router;

final class RouterTest extends TestCase
{
    public function test_matches_static_route(): void
    {
        $r = new Router();
        $hit = false;
        $r->get('/poselenie/vhod', function () use (&$hit) { $hit = true; });
        $r->dispatch('GET', '/poselenie/vhod');
        $this->assertTrue($hit);
    }

    public function test_matches_param_route(): void
    {
        $r = new Router();
        $captured = null;
        $r->get('/yarmarka/{id}', function ($params) use (&$captured) { $captured = $params['id']; });
        $r->dispatch('GET', '/yarmarka/42');
        $this->assertSame('42', $captured);
    }

    public function test_returns_false_on_no_match(): void
    {
        $r = new Router();
        $r->get('/a', fn() => null);
        $this->assertFalse($r->dispatch('GET', '/nope'));
    }
}
