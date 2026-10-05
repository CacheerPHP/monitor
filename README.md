# Cacheer Monitor

<p align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="public/assets/art/logo.svg">
    <source media="(prefers-color-scheme: light)" srcset="public/assets/art/logo-light.svg">
    <img src="public/assets/art/logo-light.svg" width="300" alt="Cacheer Monitor">
  </picture>
</p>

<p align="center">A clear view of your cache.</p>

<p align="center">
  <strong>Real-time dashboard and telemetry for <a href="https://github.com/cacheerphp/CacheerPHP">CacheerPHP</a>. Instruments your cache layer automatically — no code changes required.</strong>
</p>

<p align="center">
  <a href="https://github.com/cacheerphp/monitor/releases"><img src="https://img.shields.io/github/release/cacheerphp/monitor.svg?style=for-the-badge&color=315dc9" alt="Latest Version"/></a>
  <img src="https://img.shields.io/packagist/dependency-v/cacheerphp/monitor/PHP?style=for-the-badge&color=315dc9" alt="PHP Version"/>
  <img src="https://img.shields.io/packagist/dt/cacheerphp/monitor?style=for-the-badge&color=315dc9" alt="Downloads"/>
  <a href="https://github.com/cacheerphp/monitor"><img src="https://img.shields.io/badge/license-MIT-green?style=for-the-badge" alt="License"/></a>
  <a href="https://github.com/cacheerphp/CacheerPHP"><img src="https://img.shields.io/badge/CacheerPHP-%5E6.0-315dc9?style=for-the-badge" alt="CacheerPHP"/></a>
</p>

---

## Why Cacheer Monitor?

Caching makes apps fast — but _blind_ caching causes stale data, wasted memory, and hard-to-trace bugs. Cacheer Monitor gives you **real-time visibility** into every cache operation with zero code changes:

- **Zero-config setup** — installs via Composer, auto-registers via `autoload.files`
- **Live dashboard** — focused telemetry workspace with responsive navigation and dark/light themes
- **Cache efficiency** — hit rate, lookup count, and an accessible horizontal meter
- **Timeline insights** — hits vs misses and latency across the selected time range
- **Cache lifecycle** — stale responses, refreshes, promotions, and lock contention
- **Problem keys** — rank keys by misses, errors, hits, or p95 operation latency
- **Health rules** — persistent dashboard settings, sample limits, sustained conditions, and snooze
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

| Feature                  | Description                                                                                         |
| ------------------------ | --------------------------------------------------------------------------------------------------- |
| **Metric cards**         | Hits, misses, puts, flushes, renews, clears, errors, avg latency (p95/p99)                          |
| **Cache efficiency**     | Hit rate, lookup count, and a horizontal meter; no lookups is shown explicitly                      |
| **Health rules**         | Hit-rate, error-count, and p95 latency warnings; minimum samples, hold period, and snooze cooldown   |
| **Hits vs Misses chart** | Selected rolling time range in 20 buckets; All time shows the last 10 minutes                         |
| **Latency chart**        | Avg latency over time                                                                               |
| **TTL distribution**     | Expiry buckets when events report TTL; missing metadata is shown explicitly                        |
| **Drivers doughnut**     | Event count breakdown by driver                                                                     |
| **Problem keys**         | Top 10 matching keys per ranking, grouped by driver and namespace; hits, misses, errors, writes, and latency |
| **Cache lifecycle**      | Stale served, refreshes, promotions, and lock contention; select a signal to filter events            |
| **Namespaces grid**      | Counts for reported namespaces; unavailable metadata is explained                                  |
| **Event stream**         | Live feed with type badges, key, driver, duration, TTL, size, value type                            |
| **Key Inspector**        | Driver-specific history, lifecycle counts, timestamps, value preview, and an event timeline         |
| **Value preview**        | Captured or live-resolved cache values with automatic sensitive field redaction                     |
| **Export**               | Download events as JSON or CSV                                                                      |
| **SSE real-time**        | Server-Sent Events push updates to the dashboard                                                    |
| **Auto-refresh**         | Configurable: off, 1s, 2s, 5s, 10s — persisted to localStorage                                      |
| **Time-range filter**    | 5m, 15m, 1h, 6h, 24h, All                                                                           |
| **Dark / Light theme**   | Toggle with FOUC prevention — persisted to localStorage                                             |
| **Activity indicator**   | Subtle progress line while requests are in flight; respects reduced motion                          |
| **Workspace navigation** | Persistent desktop rail, compact mobile navigation, and active section highlighting                 |
| **Local assets**         | Bundled fonts, SVG icons, and Chart.js; the dashboard needs no CDN access                           |
| **Keyboard access**      | Focus indicators, accessible key buttons, and an inspector with focus trapping and Escape dismissal |

### Understanding the recorded data

Dashboard metrics and charts use all matching events in the **current log**,
independently of the event-feed limit. Rotated logs are not included. “All time”
means all activity retained in that current file, not the lifetime of your app.
Time windows advance on each refresh. Key search filters the event feed and
rankings; the operation filter affects the feed. Neither changes overview metrics
or health rules. Feed filters are applied before its limit.

Latency measures recorded **cache operations**, not application requests, loaders,
or database queries. Untimed lifecycle markers are excluded. Key rankings show
the number of timed samples alongside average and p95 latency; a small sample
should not be treated as a stable estimate.

Cacheer 6's current typed events do not include namespace or TTL fields. Monitor
shows that metadata as unavailable instead of assuming an unscoped key or a
forever TTL. Those views remain available for older or custom records that
explicitly provide the fields. An explicitly reported `ttl: null` means forever;
an absent `ttl` means unknown.

### Health rule settings

Open **Health rules** below the lifecycle panel. Settings are saved in this
browser. Warnings are evaluated on successful refreshes while the dashboard is
open; there is no background notification service.

- Hit-rate warnings are enabled by default, using **Alert below** (50%).
- Error-count and p95 latency warnings are opt-in, defaulting to 5 errors and 10 ms.
- Minimum samples default to 10: lookups for hit rate, timed operations for latency,
  and recorded events for error count.
- A condition must remain observed for 30 seconds before warning.
- **Snooze** suppresses a warning for the 60-second cooldown. Active warnings stay
  visible until recovery or snooze; recovered conditions also respect the cooldown
  before warning again.
- Changing rule settings, the time range, or namespace starts a fresh evaluation.
  Connection failures reset evaluation. Reloading the page starts a new evaluation;
  settings remain saved.

---

## Configuration

All configuration is via **environment variables** (OS env or `.env` file in your project root):

| Variable                         | Default                                    | Description                                                                                     |
| -------------------------------- | ------------------------------------------ | ----------------------------------------------------------------------------------------------- |
| `CACHEER_MONITOR_EVENTS`         | `sys_get_temp_dir()/cacheer-monitor.jsonl` | Path to the JSONL events file                                                                   |
| `CACHEER_MONITOR_TOKEN`          | _(none)_                                   | If set, required via `X-Monitor-Token` header to clear events                                   |
| `CACHEER_MONITOR_CAPTURE_VALUES` | `false`                                    | Enable value preview capture in events                                                          |
| `CACHEER_MONITOR_AUTO_REGISTER`  | `true`                                     | Set to `false` to stop the autoload bridge registering a listener, so you can wire one yourself |
| `CACHEER_MONITOR_STREAM_TIMEOUT` | `30`                                       | SSE stream connection timeout (seconds)                                                         |
| `CACHEER_MONITOR_WORKERS`        | `4`                                        | Server worker processes; the live stream holds one for its whole timeout (Unix only)            |
| `CACHEER_MONITOR_PREVIEW_BYTES`  | `2048`                                     | Max bytes for value preview JSON                                                                |
| `CACHEER_MONITOR_REDACT_KEYS`    | _(empty)_                                  | Comma-separated list of additional keys to redact in previews                                   |

### Built-in redacted keys

The following keys are **always masked** in value previews, regardless of configuration:

`password`, `passwd`, `pwd`, `secret`, `token`, `access_token`, `refresh_token`, `authorization`, `api_key`, `apikey`, `private_key`, `client_secret`, `cookie`, `session`

---

## CLI Reference

```bash
vendor/bin/cacheer-monitor serve [options]
```

| Flag         | Default           | Description                                                                                       |
| ------------ | ----------------- | ------------------------------------------------------------------------------------------------- |
| `--host=`    | `127.0.0.1`       | Host to bind to                                                                                   |
| `--port=`    | `9966`            | Port to listen on                                                                                 |
| `--events=`  | _(auto-resolved)_ | Explicit path to the JSONL events file                                                            |
| `--workers=` | `4`               | Server worker processes; keeps the dashboard responsive while the live stream is open (Unix only) |
| `--quiet`    | —                 | Suppress request logging                                                                          |

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

| Method | Endpoint                      | Description                                       |
| ------ | ----------------------------- | ------------------------------------------------- |
| `GET`  | `/api/health`                 | Server health check                               |
| `GET`  | `/api/config`                 | Active configuration (events file path, origin)   |
| `GET`  | `/api/metrics`                | Aggregated metrics with filtering                 |
| `GET`  | `/api/snapshot`               | Current-log metrics, limited event feed, coverage, and timeline |
| `GET`  | `/api/events`                 | Latest matching events                            |
| `POST` | `/api/events/clear`           | Rotate and clear events file (token-protected)    |
| `GET`  | `/api/events/stream`          | SSE stream of live events                         |
| `GET`  | `/api/events/export`          | Export events as JSON or CSV                      |
| `POST` | `/api/events/cleanup-rotated` | Delete rotated log files older than N days        |
| `GET`  | `/api/keys/inspect`           | Key inspector: history, stats, live value preview |

### Query parameters

Most read endpoints accept:

| Param       | Type     | Description                          |
| ----------- | -------- | ------------------------------------ |
| `limit`     | `int`    | Max events to return                 |
| `namespace` | `string` | Filter by namespace                  |
| `from`      | `float`  | Unix timestamp — start of time range |
| `until`     | `float`  | Unix timestamp — end of time range   |

For `/api/snapshot`, `limit` applies only to the event feed. Its metrics and
timeline cover all matching records in the current log. `key_filter` searches
keys in the feed and rankings, and `type` filters the feed by event type. The
response includes `coverage` (matching and shown counts), `timeline` (20 buckets),
and metrics containing `lifecycle`, `problem_keys`, and metadata/sample counts.
For `/api/metrics`, an explicit/default limit still bounds the records aggregated;
use `limit=0` for all matching records.

**Key inspector** — `/api/keys/inspect`:

| Param       | Type     | Description                         |
| ----------- | -------- | ----------------------------------- |
| `key`       | `string` | **(required)** Cache key to inspect |
| `namespace` | `string` | Namespace filter                    |
| `limit`     | `int`    | Max events for this key             |
| `live`      | `bool`   | Force live cache lookup             |
| `driver`    | `string` | Restrict history to one recorded driver |
| `namespace_missing` | `bool` | Restrict history to records without namespace metadata |

**Export** — `/api/events/export`:

| Param    | Type     | Description     |
| -------- | -------- | --------------- |
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

Full documentation: [cacheerphp.com/docs/v6/en/cacheer-monitor/](https://cacheerphp.com/docs/v6/en/cacheer-monitor/)

---

## Contributing

Contributions are welcome! Please open an issue or submit a pull request.

Run PHP checks with `composer test`, dashboard rule checks with `npm run test:ui`,
and JavaScript lint with `npm run lint`. Focused diagnostics regression tests are
tracked in `Tests/Regression`.

---

## License

MIT — see [LICENSE](LICENSE).
