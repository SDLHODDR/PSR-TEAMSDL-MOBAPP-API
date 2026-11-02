<?php

declare(strict_types=1);

namespace SDL\Framework;

final class Router
{
    private array $routes = [];
    private array $middleware = [];

    public function middleware(MiddlewareInterface $middleware): void
    {
        $this->middleware[] = $middleware;
    }

    public function get(string $path, callable|array $handler, array $middleware = []): void
    {
        $this->add('GET', $path, $handler, $middleware);
    }
    public function post(string $path, callable|array $handler, array $middleware = []): void
    {
        $this->add('POST', $path, $handler, $middleware);
    }
    public function put(string $path, callable|array $handler, array $middleware = []): void
    {
        $this->add('PUT', $path, $handler, $middleware);
    }
    public function patch(string $path, callable|array $handler, array $middleware = []): void
    {
        $this->add('PATCH', $path, $handler, $middleware);
    }
    public function delete(string $path, callable|array $handler, array $middleware = []): void
    {
        $this->add('DELETE', $path, $handler, $middleware);
    }

    public function add(string $method, string $path, callable|array $handler, array $middleware = []): void
    {
        $path = '/' . trim($path, '/');
        if ($path === '//') {
            $path = '/';
        }

        $this->routes[] = [
            'method' => strtoupper($method),
            'path' => $path,
            'regex' => $this->compilePath($path),
            'handler' => $handler,
            'middleware' => $middleware,
        ];
    }

    public function dispatch(Request $request): Response
    {
        $pathMatched = false;
        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $request->path(), $matches)) {
                continue;
            }

            $pathMatched = true;
            if ($route['method'] !== $request->method()) {
                continue;
            }

            array_shift($matches);
            preg_match_all('/\{([A-Za-z_][A-Za-z0-9_]*)\}/', $route['path'], $keys);
            $params = [];
            foreach ($keys[1] as $index => $key) {
                $params[$key] = isset($matches[$index]) ? rawurldecode($matches[$index]) : null;
            }
            $request->setRouteParams($params);

            $destination = function (Request $req) use ($route): Response {
                $handler = $route['handler'];
                $result = is_array($handler)
                    ? (new $handler[0]())->{$handler[1]}($req)
                    : $handler($req);

                if (!$result instanceof Response) {
                    throw new \LogicException('Route handlers must return an instance of Response.');
                }
                return $result;
            };

            $stack = array_merge($this->middleware, $route['middleware']);
            foreach (array_reverse($stack) as $middleware) {
                if (!$middleware instanceof MiddlewareInterface) {
                    throw new \LogicException('Middleware must implement MiddlewareInterface.');
                }
                $next = $destination;
                $destination = fn (Request $req): Response => $middleware->process($req, $next);
            }

            return $destination($request);
        }

        if ($pathMatched) {
            throw new HttpException(405, 'HTTP method not allowed', 'method_not_allowed');
        }
        throw new HttpException(404, 'Endpoint not found', 'not_found');
    }

    private function compilePath(string $path): string
    {
        $quoted = preg_quote($path, '#');
        $pattern = preg_replace_callback(
            '/\\\\\{([A-Za-z_][A-Za-z0-9_]*)\\\\\}/',
            static fn (array $match): string => '([^/]+)',
            $quoted
        );
        return '#^' . $pattern . '/?$#';
    }
}
