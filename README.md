# Cacheer Monitor

<p align="center">
  <strong>Real-time dashboard and telemetry for <a href="https://github.com/cacheerphp/CacheerPHP">CacheerPHP</a>. Instruments your cache layer automatically — no code changes required.</strong>
</p>

<p align="center">
  <a href="https://github.com/cacheerphp/monitor/releases"><img src="https://img.shields.io/github/release/cacheerphp/monitor.svg?style=for-the-badge&color=blue" alt="Latest Version"/></a>
  <img src="https://img.shields.io/packagist/dependency-v/cacheerphp/monitor/PHP?style=for-the-badge&color=blue" alt="PHP Version"/>
  <img src="https://img.shields.io/packagist/dt/cacheerphp/monitor?style=for-the-badge&color=blue" alt="Downloads"/>
  <a href="https://github.com/cacheerphp/monitor"><img src="https://img.shields.io/badge/license-MIT-green?style=for-the-badge" alt="License"/></a>
  <a href="https://github.com/cacheerphp/CacheerPHP"><img src="https://img.shields.io/badge/CacheerPHP-%5E4.7%20%7C%7C%20%5E5.0-blue?style=for-the-badge" alt="CacheerPHP"/></a>
</p>

---

## Why Cacheer Monitor?

Caching makes apps fast — but _blind_ caching causes stale data, wasted memory, and hard-to-trace bugs. Cacheer Monitor gives you **real-time visibility** into every cache operation with zero code changes:

- **Zero-config setup** — installs via Composer, auto-registers via `autoload.files`
- **Live dashboard** — glassmorphic UI with animated charts, dark/light theme
- **Hit-rate gauge** — hero metric with circular SVG ring, instant health check
- **Timeline insights** — hits vs misses, latency trends, TTL distribution
- **Key inspector** — drill into any key: history, stats, live value preview
- **SSE streaming** — events push to the dashboard in real time
- **Export** — download events as JSON or CSV
- **Token-based auth** — protect destructive actions with `CACHEER_MONITOR_TOKEN`
- **Sensitive data redaction** — passwords, tokens, and API keys are auto-masked in previews

---

## Requirements

- PHP 8.1+
- CacheerPHP `^4.7 || ^5.0`

---

## Quick Start

### 1. Install

```bash
composer require cacheerphp/monitor
```

That's it. The package self-registers via Composer's `autoload.files` — every cache operation on any `Cacheer` instance is instrumented automatically as soon as `vendor/autoload.php` is loaded.

### 2. Launch the dashboard

```bash
vendor/bin/cacheer-monitor serve --port=9966
```

### 3. Open your browser

Navigate to [http://127.0.0.1:9966](http://127.0.0.1:9966) — events will appear as your app runs.

---

## Table of Contents

- [How It Works](#how-it-works)
- [Dashboard Features](#dashboard-features)
- [Configuration](#configuration)
- [CLI Reference](#cli-reference)
- [REST API](#rest-api)
- [Custom Events File Path](#custom-events-file-path)
- [Security](#security)
- [Documentation](#documentation)
- [Contributing](#contributing)
- [License](#license)

---

## How It Works

On install, `src/Boot/bootstrap.php` is registered in Composer's autoloader. When your app loads `vendor/autoload.php`, the bootstrap runs and calls:

```php
Cacheer::addListener(new CacheerMonitorListener(new JsonlReporter()));
```

CacheerPHP's built-in event dispatcher fires after every cache operation (`put`, `get`, `flush`, `clear`, `renew`, `tag`, etc.) and the listener writes structured JSONL records to disk. The dashboard server reads those records in real time.

### The JSONL Reporter

The `JsonlReporter` is built for production:

- **File locking** — concurrent writes are safe via `.lock` files
- **Auto-rotation** — rotates at 10 MB to prevent unbounded growth
- **Instance IDs** — each reporter instance tags events for multi-process identification

### Alternative: InstrumentedCacheer

If you prefer explicit instrumentation over auto-registration:

```php
use Cacheer\Monitor\InstrumentedCacheer;

$cache = InstrumentedCacheer::wrap($originalCacheer);
```

All cache calls are proxied through the monitor transparently.

---

## Dashboard Features

| Feature | Description |
|---|---|
| **Metric cards** | Hits, misses, puts, flushes, renews, clears, errors, avg latency (p95/p99) |
| **Hit-rate gauge** | Animated SVG circular ring — the hero metric |
| **Hit-rate alert** | Configurable threshold banner — warns when hit rate drops below N% |
| **Hits vs Misses chart** | 10-minute rolling timeline with 30s buckets |
| **Latency chart** | Avg latency over time |
| **TTL distribution** | Bar chart: ≤1min, >1min, >5min, >1hr, >1day, forever |
| **Drivers doughnut** | Event count breakdown by driver |
| **Top keys** | 10 most-accessed keys with search filter |
| **Namespaces grid** | Event counts per namespace |
| **Event stream** | Live feed with type badges, key, driver, duration, TTL, size, value type |
| **Key Inspector** | Slide-in panel: hit/miss/write stats, timestamps, live value preview, recent events |
| **Value preview** | Captured or live-resolved cache values with automatic sensitive field redaction |
| **Export** | Download events as JSON or CSV |
| **SSE real-time** | Server-Sent Events push updates to the dashboard |
| **Auto-refresh** | Configurable: off, 1s, 2s, 5s, 10s — persisted to localStorage |
| **Time-range filter** | 5m, 15m, 1h, 6h, 24h, All |
| **Dark / Light theme** | Toggle with FOUC prevention — persisted to localStorage |
| **Activity pulse** | Animated top bar fires on every API fetch |
| **Floating nav** | Desktop sidebar with IntersectionObserver-based section highlighting |

---

## Configuration

All configuration is via **environment variables** (OS env or `.env` file in your project root):

| Variable | Default | Description |
|---|---|---|
| `CACHEER_MONITOR_EVENTS` | `sys_get_temp_dir()/cacheer-monitor.jsonl` | Path to the JSONL events file |
| `CACHEER_MONITOR_TOKEN` | *(none)* | If set, required via `X-Monitor-Token` header to clear events |
| `CACHEER_MONITOR_CAPTURE_VALUES` | `false` | Enable value preview capture in events |
| `CACHEER_MONITOR_STREAM_TIMEOUT` | `30` | SSE stream connection timeout (seconds) |
| `CACHEER_MONITOR_PREVIEW_BYTES` | `2048` | Max bytes for value preview JSON |
| `CACHEER_MONITOR_REDACT_KEYS` | *(empty)* | Comma-separated list of additional keys to redact in previews |

### Built-in redacted keys

The following keys are **always masked** in value previews, regardless of configuration:

`password`, `passwd`, `pwd`, `secret`, `token`, `access_token`, `refresh_token`, `authorization`, `api_key`, `apikey`, `private_key`, `client_secret`, `cookie`, `session`

---

## CLI Reference

```bash
vendor/bin/cacheer-monitor serve [options]
```

| Flag | Default | Description |
|---|---|---|
| `--host=` | `127.0.0.1` | Host to bind to |
| `--port=` | `9966` | Port to listen on |
| `--events=` | *(auto-resolved)* | Explicit path to the JSONL events file |
| `--quiet` | — | Suppress request logging |

```bash
vendor/bin/cacheer-monitor help
```

---

## REST API

| Method | Endpoint | Description |
|---|---|---|
| `GET` | `/api/health` | Server health check |
| `GET` | `/api/config` | Active configuration (events file path, origin) |
| `GET` | `/api/metrics` | Aggregated metrics with filtering |
| `GET` | `/api/events` | Paginated event log |
| `POST` | `/api/events/clear` | Rotate and clear events file (token-protected) |
| `GET` | `/api/events/stream` | SSE stream of live events |
| `GET` | `/api/events/export` | Export events as JSON or CSV |
| `POST` | `/api/events/cleanup-rotated` | Delete rotated log files older than N days |
| `GET` | `/api/keys/inspect` | Key inspector: history, stats, live value preview |

### Query parameters

Most read endpoints accept:

| Param | Type | Description |
|---|---|---|
| `limit` | `int` | Max events to return |
| `namespace` | `string` | Filter by namespace |
| `from` | `float` | Unix timestamp — start of time range |
| `until` | `float` | Unix timestamp — end of time range |

**Key inspector** — `/api/keys/inspect`:

| Param | Type | Description |
|---|---|---|
| `key` | `string` | **(required)** Cache key to inspect |
| `namespace` | `string` | Namespace filter |
| `limit` | `int` | Max events for this key |
| `live` | `bool` | Force live cache lookup |

**Export** — `/api/events/export`:

| Param | Type | Description |
|---|---|---|
| `format` | `string` | `json` or `csv` |

**Cleanup** — `/api/events/cleanup-rotated` (POST body):

```json
{ "max_age_days": 7 }
```

---

## Custom Events File Path

By default, events are written to the path resolved in this order:

1. `CACHEER_MONITOR_EVENTS` environment variable
2. `.env` file in the project root
3. System temp dir (`sys_get_temp_dir() . '/cacheer-monitor.jsonl'`)

Relative paths are always resolved from the consuming project root, not from `vendor/cacheerphp/monitor`.

To use a custom path:

```php
use Silviooosilva\CacheerPhp\Cacheer;
use Cacheer\Monitor\CacheerMonitorListener;
use Cacheer\Monitor\Reporter\JsonlReporter;

Cacheer::removeListeners();
Cacheer::addListener(new CacheerMonitorListener(
    new JsonlReporter('/var/log/myapp/cacheer-events.jsonl')
));
```

Start the server pointing to the same file:

```bash
vendor/bin/cacheer-monitor serve --events=/var/log/myapp/cacheer-events.jsonl
```

---

## Security

- **Local by default** — binds to `127.0.0.1`; not exposed to the network
- **Token protection** — set `CACHEER_MONITOR_TOKEN` to require authentication on destructive actions
- **Sensitive data redaction** — passwords, tokens, and API keys are auto-masked in value previews
- **No-cache headers** — all API responses include `Cache-Control: no-store`
- **CORS** — `Access-Control-Allow-Origin: *` for local development

> **Warning:** Do not expose the dashboard on a public interface without adding authentication.

---

## Documentation

Full documentation: [cacheerphp.com/docs/en/cacheer-monitor/](https://cacheerphp.com/docs/v5/en/cacheer-monitor/quick-start/)

---

## Contributing

Contributions are welcome! Please open an issue or submit a pull request.

---

## License

MIT — see [LICENSE](LICENSE).
