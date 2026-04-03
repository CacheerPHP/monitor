<?php

declare(strict_types=1);

use Cacheer\Monitor\Http\Kernel;

// Serve static assets directly when they exist
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$publicDir = __DIR__ . '/../public';
$staticFile = realpath($publicDir . $path);
if ($path !== '/' && $staticFile && str_starts_with($staticFile, realpath($publicDir)) && is_file($staticFile)) {
    return false;
}

$autoloadCandidates = [
    __DIR__ . '/../vendor/autoload.php',  
    __DIR__ . '/../../../../autoload.php',
];

foreach ($autoloadCandidates as $autoload) {
    if (file_exists($autoload)) {
        require_once $autoload;
        break;
    }
}

$kernel = new Kernel($publicDir);
$kernel->handle();
