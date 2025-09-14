<?php

declare(strict_types=1);

namespace Cacheer\Monitor\Http;

final class Kernel
{
    private Router $router;

    public function __construct(private readonly string $publicDir)
    {
        $controller = ApiController::fromConfig();
        $this->router = new Router($controller, $publicDir);
    }

    public function handle(): void
    {
        $this->sendNoCacheHeaders();
        $request = Request::fromGlobals();
        $response = $this->router->dispatch($request);
        $response->send();
    }

    private function sendNoCacheHeaders(): void
    {
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
    }
}

