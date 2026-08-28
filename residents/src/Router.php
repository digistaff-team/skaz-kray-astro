<?php
declare(strict_types=1);
namespace SkazResidents;

final class Router
{
    /** @var array<int,array{method:string,regex:string,keys:array<int,string>,handler:callable}> */
    private array $routes = [];

    public function get(string $path, callable $handler): void { $this->add('GET', $path, $handler); }
    public function post(string $path, callable $handler): void { $this->add('POST', $path, $handler); }

    private function add(string $method, string $path, callable $handler): void
    {
        $keys = [];
        $regex = preg_replace_callback('#\{(\w+)\}#', function ($m) use (&$keys) {
            $keys[] = $m[1];
            return '([^/]+)';
        }, rtrim($path, '/'));
        $this->routes[] = [
            'method' => $method,
            'regex'  => '#^' . $regex . '$#',
            'keys'   => $keys,
            'handler'=> $handler,
        ];
    }

    /** @return bool true если маршрут найден и вызван. */
    public function dispatch(string $method, string $uri): bool
    {
        $path = rtrim(parse_url($uri, PHP_URL_PATH) ?: '/', '/');
        if ($path === '') { $path = '/'; }
        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) { continue; }
            if (preg_match($route['regex'], $path, $matches)) {
                array_shift($matches);
                $params = array_combine($route['keys'], $matches) ?: [];
                ($route['handler'])($params);
                return true;
            }
        }
        return false;
    }
}
