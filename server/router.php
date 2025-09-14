<?php

declare(strict_types=1);

// Router for PHP's built-in server with a small API for metrics.

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

// Serve existing static files directly
$publicDir = __DIR__ . '/../public';
$staticFile = realpath($publicDir . $path);
if ($path !== '/' && $staticFile && str_starts_with($staticFile, realpath($publicDir)) && is_file($staticFile)) {
    return false;
}

header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

if ($path === '/api/metrics') {
    header('Content-Type: application/json');
    echo json_encode(aggregateMetrics(readEvents()), JSON_UNESCAPED_SLASHES);
    return true;
}

if ($path === '/api/events') {
    header('Content-Type: application/json');
    $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 200;
    $events = readEvents();
    if ($limit > 0) {
        $events = array_slice($events, -$limit);
    }
    echo json_encode($events, JSON_UNESCAPED_SLASHES);
    return true;
}

// Default: index.html
readfile($publicDir . '/index.html');
return true;

function eventsFile(): string {
    $env = getenv('CACHEER_MONITOR_EVENTS') ?: null;
    return $env ?: (sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cacheer-monitor.jsonl');
}

function readEvents(): array {
    $file = eventsFile();
    if (!is_file($file)) {
        return [];
    }
    $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!$lines) {
        return [];
    }
    $events = [];
    foreach ($lines as $line) {
        $decoded = json_decode($line, true);
        if (is_array($decoded)) {
            $events[] = $decoded;
        }
    }
    return $events;
}

function aggregateMetrics(array $events): array {
    $stats = [
        'hits' => 0,
        'misses' => 0,
        'puts' => 0,
        'put_many' => 0,
        'clears' => 0,
        'flushes' => 0,
        'renews' => 0,
        'tags' => 0,
        'errors' => 0,
        'drivers' => [],
        'top_keys' => [],
        'since' => null,
        'total_events' => count($events),
    ];

    foreach ($events as $e) {
        $type = $e['type'] ?? 'unknown';
        $payload = $e['payload'] ?? [];
        $driver = $payload['driver'] ?? 'unknown';
        $ts = $e['ts'] ?? null;
        if ($stats['since'] === null || ($ts && $ts < $stats['since'])) {
            $stats['since'] = $ts;
        }

        $stats['drivers'][$driver] = ($stats['drivers'][$driver] ?? 0) + 1;

        switch ($type) {
            case 'hit':
                $stats['hits']++;
                $key = $payload['key'] ?? null;
                if ($key) { $stats['top_keys'][$key] = ($stats['top_keys'][$key] ?? 0) + 1; }
                break;
            case 'miss':
                $stats['misses']++;
                break;
            case 'put':
            case 'put_forever':
                $stats['puts']++;
                break;
            case 'put_many':
                $stats['put_many']++;
                break;
            case 'clear':
                $stats['clears']++;
                break;
            case 'flush':
                $stats['flushes']++;
                break;
            case 'renew':
                $stats['renews']++;
                break;
            case 'tag':
            case 'flush_tag':
                $stats['tags']++;
                break;
            case 'error':
                $stats['errors']++;
                break;
        }
    }

    // Compute hit rate
    $totalLookups = $stats['hits'] + $stats['misses'];
    $stats['hit_rate'] = $totalLookups > 0 ? ($stats['hits'] / $totalLookups) : 0.0;

    // Reduce top_keys to top 10
    arsort($stats['top_keys']);
    $stats['top_keys'] = array_slice($stats['top_keys'], 0, 10, true);

    // Sort drivers by count
    arsort($stats['drivers']);

    return $stats;
}

