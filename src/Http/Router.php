<?php

declare(strict_types=1);

namespace Cacheer\Monitor\Http;

final class Router
{
    /** @var array<string, callable(Request):Response> */
    private array $routes;

    /**
     * Initializes the Router with the given ApiController and public directory.
     *
     * @param ApiController $controller The controller handling API requests
     * @param string $publicDir The directory where static assets are located
     */
    public function __construct(private readonly ApiController $controller, private readonly string $publicDir)
    {
        $this->routes = [
            '/api/health' => fn(Request $request): Response => $this->controller->health(),
            '/api/config' => fn(Request $request): Response => $this->controller->config(),
            '/api/metrics' => fn(Request $request): Response => $this->controller->metrics($request),
            '/api/events' => fn(Request $request): Response => $this->controller->events($request),
            '/api/events/clear' => fn(Request $request): Response => $this->controller->clear($request),
            '/api/events/stream' => function (Request $request): Response {
                $this->controller->stream($request);

                return new Response(200, [], '');
            },
            '/api/events/export' => fn(Request $request): Response => $this->controller->export($request),
            '/api/events/cleanup-rotated' => fn(Request $request): Response => $this->controller->cleanupRotated($request),
            '/api/keys/inspect' => fn(Request $request): Response => $this->controller->keyInspect($request),
        ];
    }

    /**
     * Dispatches the incoming request to the appropriate handler based on the path.
     *
     * @param Request $request
     * @return Response
     */
    public function dispatch(Request $request): Response
    {
        $handler = $this->routes[$request->path] ?? null;

        if ($handler !== null) {
            return $handler($request);
        }

        return $this->indexResponse();
    }

    /**
     * Generates a response for the index page.
     *
     * @return Response
     */
    private function indexResponse(): Response
    {
        return new Response(
            200,
            ['Content-Type' => 'text/html; charset=utf-8'],
            (string) @file_get_contents($this->publicDir . '/index.html')
        );
    }
}
