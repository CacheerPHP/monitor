<?php

declare(strict_types=1);

namespace Cacheer\Monitor\Http;

/**
 * Kernel wires Router and Controller, handling requests and responses.
 */
final class Kernel
{
    private Router $router;

    /**
     * @param string $publicDir Directory containing index.html and static assets
     */
    public function __construct(private readonly string $publicDir)
    {
        $controller = ApiController::fromConfig();
        $this->router = new Router($controller, $publicDir);
    }

    /**
     * Handle the current request lifecycle.
     *
     * @return void
     */
    public function handle(): void
    {
        $this->sendNoCacheHeaders();
        $request = Request::fromGlobals();
        $response = $this->router->dispatch($request);
        $response->send();
    }

    /**
     * Emit no-cache headers for dynamic responses.
     *
     * @return void
     */
    private function sendNoCacheHeaders(): void
    {
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
    }
}
