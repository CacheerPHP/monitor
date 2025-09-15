<?php

declare(strict_types=1);

namespace Cacheer\Monitor\Http;

/**
 * Minimal router mapping paths to controller methods.
 */
final class Router
{
    public function __construct(private readonly ApiController $controller, private readonly string $publicDir)
    {
    }

    /**
     * Route the current request and return a response.
     *
     * @param Request $request
     * @return Response
     */
    public function dispatch(Request $request): Response
    {
        $path = $request->path;
        if ($path === '/api/health') {
            return $this->controller->health();
        }
        if ($path === '/api/config') {
            return $this->controller->config();
        }
        if ($path === '/api/metrics') {
            return $this->controller->metrics($request);
        }
        if ($path === '/api/events') {
            return $this->controller->events($request);
        }
        if ($path === '/api/events/clear') {
            return $this->controller->clear($request);
        }
        // default: return index.html
        return new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], (string) @file_get_contents($this->publicDir . '/index.html'));
    }
}
