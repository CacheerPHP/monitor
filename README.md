# Cacheer Monitor

<p align="center">
  <strong>Real-time dashboard and telemetry for <a href="https://github.com/cacheerphp/CacheerPHP">CacheerPHP</a>. Instruments your cache layer automatically — no code changes required.</strong>
</p>

<p align="center">
  <a href="https://github.com/cacheerphp/monitor/releases"><img src="https://img.shields.io/github/release/cacheerphp/monitor.svg?style=for-the-badge&color=f2b93c" alt="Latest Version"/></a>
  <img src="https://img.shields.io/packagist/dependency-v/cacheerphp/monitor/PHP?style=for-the-badge&color=f2b93c" alt="PHP Version"/>
  <img src="https://img.shields.io/packagist/dt/cacheerphp/monitor?style=for-the-badge&color=f2b93c" alt="Downloads"/>
  <a href="https://github.com/cacheerphp/monitor"><img src="https://img.shields.io/badge/license-MIT-green?style=for-the-badge" alt="License"/></a>
  <a href="https://github.com/cacheerphp/CacheerPHP"><img src="https://img.shields.io/badge/CacheerPHP-%5E6.0-f2b93c?style=for-the-badge" alt="CacheerPHP"/></a>
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

- PHP 8.3+
- CacheerPHP 6

> **On the version constraint.** The monitor hooks CacheerPHP 6's
> `Observability\Telemetry` tap, which does not exist in v4/v5. While 6.0 is a
> pre-release the requirement is `^6.0@RC`, which pins to the tagged
> `6.0.0-RC1` — a plain `^6.0` silently fails `minimum-stability: stable` and
> leaves you on an old release with no tap to hook, which looks exactly like a
> broken monitor. Run `vendor/bin/cacheer-monitor doctor` if in doubt.

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

On install, `src/Boot/bootstrap.php` is registered in Composer's autoloader. When your app loads `vendor/autoload.php`, the bootstrap runs and registers a listener on CacheerPHP 6's global telemetry tap:

```php
Telemetry::listen((new CacheerMonitorListener(new JsonlReporter()))->dispatch(...));
```

From then on **every** cache reports, however it was built — `Cacheer::file()` and the other named constructors, `Cacheer::build()`, a plain `new Cacheer($store)`, and the `tiered()` / `resilient()` decorators. Scoped and policy-bound views inherit it, and capability operations (`increment`, `decrement`, `touch`, `tag`, `flushTag`) are reported too.

The listener translates each typed `CacheEvent` into a flat record and writes structured JSONL to disk; the dashboard server reads those records in real time.

With no listener registered the tap is dormant, so CacheerPHP itself pays nothing for supporting this.

### When the dashboard shows nothing

The bridge runs at autoload on every request, so it can never warn or throw — silence is its only safe failure mode. That is what `doctor` is for:

```bash
vendor/bin/cacheer-monitor doctor
```

```
  [ok]  CacheerPHP telemetry tap Observability\Telemetry found
  [ok]  Autoload bridge       active
  [ok]  Listener registered   caches will report
  [ok]  Events file           /tmp/cacheer-monitor.jsonl
```

It reports which step failed and what to do about it. The most common cause is the events file: with no `CACHEER_MONITOR_EVENTS` set it defaults to the system temp directory, so "nothing is reported" is often "the dashboard is reading a different file".

### The JSONL Reporter

The `JsonlReporter` is built for production:

- **File locking** — concurrent writes are safe via `.lock` files
- **Auto-rotation** — rotates at 10 MB to prevent unbounded growth
- **Instance IDs** — each reporter instance tags events for multi-process identification

### Instrumenting one cache only

The bridge is all-or-nothing by design. To instrument a single cache instead,
turn auto-registration off and wire that one explicitly with CacheerPHP's own
`instrumented()` constructor:

```php
# .env
CACHEER_MONITOR_AUTO_REGISTER=false
```

```php
use Cacheer\Monitor\CacheerMonitorListener;
use Cacheer\Monitor\Reporter\JsonlReporter;
use Silviooosilva\CacheerPhp\Cacheer;
use Silviooosilva\CacheerPhp\Observability\EventBus;

$events = new EventBus();
$events->listen((new CacheerMonitorListener(new JsonlReporter()))->dispatch(...));

$cache = Cacheer::instrumented($store, $events);   // only this one reports
```

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
| `CACHEER_MONITOR_AUTO_REGISTER` | `true` | Set to `false` to stop the autoload bridge registering a listener, so you can wire one yourself |
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
vendor/bin/cacheer-monitor doctor
```

Checks the autoload bridge end to end — is a CacheerPHP with the telemetry tap
installed, did Composer run the bootstrap, is a listener registered, and is the
events file writable. Exits non-zero when something needs attention, so it works
in CI.

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

Set it in the environment, which needs no code at all:

```bash
CACHEER_MONITOR_EVENTS=/var/log/myapp/cacheer-events.jsonl
```

Or register the listener yourself. Turn the bridge off first
(`CACHEER_MONITOR_AUTO_REGISTER=false`) so you do not get two listeners:

```php
use Cacheer\Monitor\CacheerMonitorListener;
use Cacheer\Monitor\Reporter\JsonlReporter;
use Silviooosilva\CacheerPhp\Observability\Telemetry;

Telemetry::listen(
    (new CacheerMonitorListener(new JsonlReporter('/var/log/myapp/cacheer-events.jsonl')))->dispatch(...)
);
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
