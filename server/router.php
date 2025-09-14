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
    $namespace = isset($_GET['namespace']) ? (string) $_GET['namespace'] : null;
    echo json_encode(aggregateMetrics(readEvents($namespace)), JSON_UNESCAPED_SLASHES);
    return true;
}

if ($path === '/api/events') {
    header('Content-Type: application/json');
    $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 200;
    $namespace = isset($_GET['namespace']) ? (string) $_GET['namespace'] : null;
    $events = readEvents($namespace);
    if ($limit > 0) {
        $events = array_slice($events, -$limit);
    }
    echo json_encode($events, JSON_UNESCAPED_SLASHES);
    return true;
}

if ($path === '/api/events/clear') {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        http_response_code(405);
        header('Allow: POST');
        echo json_encode(['ok' => false, 'error' => 'Method Not Allowed']);
        return true;
    }
    header('Content-Type: application/json');
    $ok = clearEventsFile();
    echo json_encode(['ok' => $ok]);
    return true;
}

if ($path === '/api/config') {
    header('Content-Type: application/json');
    $file = eventsFile($origin);
    echo json_encode(['events_file' => $file, 'origin' => $origin], JSON_UNESCAPED_SLASHES);
    return true;
}

if ($path === '/api/health') {
    header('Content-Type: application/json');
    echo json_encode(['ok' => true]);
    return true;
}

// Default: index.html
readfile($publicDir . '/index.html');
return true;

function eventsFile(?string &$origin = null): string {
    $env = getenv('CACHEER_MONITOR_EVENTS');
    if ($env) { $origin = 'env'; return resolvePath($env); }
    $dotenv = loadEnvVarFromDotEnv('CACHEER_MONITOR_EVENTS');
    if ($dotenv) { $origin = 'dotenv'; return resolvePath($dotenv); }
    $origin = 'default';
    return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cacheer-monitor.jsonl';
}

function readEvents(?string $namespace = null): array {
    $file = eventsFile($ignored);
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
        if (!is_array($decoded)) { continue; }
        if ($namespace !== null && $namespace !== '') {
            $ns = $decoded['payload']['namespace'] ?? '';
            if ($ns !== $namespace) continue;
        }
        $events[] = $decoded;
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
        'namespaces' => [],
        'types' => [],
        'since' => null,
        'total_events' => count($events),
        'latency' => [ 'avg_ms' => 0.0, 'p95_ms' => 0.0, 'p99_ms' => 0.0 ],
    ];
    $latencies = [];

    foreach ($events as $e) {
        $type = $e['type'] ?? 'unknown';
        $payload = $e['payload'] ?? [];
        $driver = $payload['driver'] ?? 'unknown';
        $ts = $e['ts'] ?? null;
        if ($stats['since'] === null || ($ts && $ts < $stats['since'])) {
            $stats['since'] = $ts;
        }

        $stats['drivers'][$driver] = ($stats['drivers'][$driver] ?? 0) + 1;
        $stats['types'][$type] = ($stats['types'][$type] ?? 0) + 1;
        $ns = $payload['namespace'] ?? '';
        if ($ns === '') { $ns = '(default)'; }
        $stats['namespaces'][$ns] = ($stats['namespaces'][$ns] ?? 0) + 1;

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
    arsort($stats['namespaces']);
    arsort($stats['types']);

    // Latency metrics
    foreach ($events as $e) {
        $d = $e['payload']['duration_ms'] ?? null;
        if (is_numeric($d)) { $latencies[] = (float) $d; }
    }
    if (!empty($latencies)) {
        sort($latencies);
        $n = count($latencies);
        $avg = array_sum($latencies) / $n;
        $p = function(float $q) use ($latencies, $n): float {
            $idx = (int) floor(($n-1) * $q);
            return $latencies[$idx] ?? 0.0;
        };
        $stats['latency'] = [
            'avg_ms' => round($avg, 2),
            'p95_ms' => round($p(0.95), 2),
            'p99_ms' => round($p(0.99), 2),
        ];
    }

    return $stats;
}

function loadEnvVarFromDotEnv(string $key): ?string {
    $envPath = __DIR__ . '/../.env';
    if (!is_file($envPath)) { return null; }
    $lines = @file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!$lines) { return null; }
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) continue;
        $pos = strpos($line, '=');
        if ($pos === false) continue;
        $k = trim(substr($line, 0, $pos));
        $v = trim(substr($line, $pos + 1));
        if (($v[0] ?? '') === '"' && str_ends_with($v, '"')) { $v = substr($v, 1, -1); }
        if (($v[0] ?? '') === "'" && str_ends_with($v, "'")) { $v = substr($v, 1, -1); }
        if ($k === $key) return $v !== '' ? $v : null;
    }
    return null;
}

function resolvePath(string $path): string {
    $isAbsolute = (bool) preg_match('#^([A-Za-z]:\\\\|/|\\\\\\\\)#', $path);
    return $isAbsolute ? $path : (realpath(__DIR__ . '/..') . DIRECTORY_SEPARATOR . ltrim($path, '/\\'));
}

function clearEventsFile(): bool {
    $file = eventsFile($ignored);
    if (!is_file($file)) { return (bool) @touch($file); }
    $ok = @rename($file, $file . '.' . date('Ymd_His') . '.bak');
    if (!$ok) { return (bool) @file_put_contents($file, ''); }
    return (bool) @file_put_contents($file, '');
}
