<?php

namespace Neuedaten\FreezedDesk\Web;

/**
 * Pattern routes: "/types/{type}/{id}" with {name} segments and a trailing
 * {path...} that swallows the rest.
 */
final class Router
{
    /** @var array<int, array{method: string, pattern: string, regex: string, handler: array{0: class-string, 1: string}}> */
    private array $routes = [];

    /** @param array{0: class-string, 1: string} $handler */
    public function add(string $method, string $pattern, array $handler): void
    {
        $regex = preg_replace_callback('/\{([a-z]+)(\.\.\.)?\}/i', static function (array $m): string {
            return isset($m[2]) && $m[2] === '...' ? '(?P<' . $m[1] . '>.+)' : '(?P<' . $m[1] . '>[^/]+)';
        }, $pattern);

        $this->routes[] = [
            'method' => strtoupper($method),
            'pattern' => $pattern,
            'regex' => '#^' . $regex . '$#',
            'handler' => $handler,
        ];
    }

    /**
     * @return array{handler: array{0: class-string, 1: string}, params: array<string, string>}|null
     */
    public function match(string $method, string $path): ?array
    {
        $methodMatched = false;
        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $path, $m)) {
                continue;
            }
            if ($route['method'] !== strtoupper($method)) {
                $methodMatched = true;
                continue;
            }
            $params = [];
            foreach ($m as $key => $value) {
                if (is_string($key)) {
                    $params[$key] = $value;
                }
            }

            return ['handler' => $route['handler'], 'params' => $params];
        }

        return $methodMatched ? ['handler' => [App::class, 'methodNotAllowed'], 'params' => []] : null;
    }
}
