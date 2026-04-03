# Cacheer Monitor

Real-time dashboard and telemetry for [CacheerPHP](https://github.com/cacheerphp/CacheerPHP). Instruments your cache layer automatically — no code changes required.

![PHP](https://img.shields.io/badge/PHP-8.1%2B-777BB4?style=flat-square&logo=php)
![License](https://img.shields.io/badge/license-MIT-green?style=flat-square)
[![CacheerPHP](https://img.shields.io/badge/CacheerPHP-%5E5.0-blue?style=flat-square)](https://github.com/cacheerphp/CacheerPHP)

---

## Requirements

- PHP 8.1+
- CacheerPHP `^5.0`

---

## Installation

```bash
composer require cacheerphp/monitor
```

That's it. The package self-registers via Composer's `autoload.files` — every cache operation on any `Cacheer` instance is instrumented automatically as soon as `vendor/autoload.php` is loaded. No code changes required.

---

## Start the Dashboard

```bash
vendor/bin/cacheer-monitor serve --port=9966
```

Open [http://127.0.0.1:9966](http://127.0.0.1:9966) in your browser.

---

## How It Works

On install, `src/Boot/bootstrap.php` is registered in Composer's autoloader. When your app loads `vendor/autoload.php`, the bootstrap runs and calls:

```php
Cacheer::addListener(new CacheerMonitorListener(new JsonlReporter()));
```

CacheerPHP's built-in event dispatcher fires after every cache operation (put, get, flush, increment, etc.) and the listener writes structured JSONL records to disk. The dashboard server reads those records in real time.

---

## Custom Events File Path

By default, events are written to the path resolved in this order:

1. `CACHEER_MONITOR_EVENTS` environment variable
2. `.env` file in the project root
3. System temp dir (`sys_get_temp_dir() . '/cacheer-monitor.jsonl'`)

To use a custom path, override after `autoload.php` is loaded:

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
CACHEER_MONITOR_EVENTS=/var/log/myapp/cacheer-events.jsonl \
  vendor/bin/cacheer-monitor serve --port=9966
```

---

## Dashboard Features

- **Hit / Miss rate** — real-time ratio across all operations
- **Operation breakdown** — puts, gets, flushes, increments
- **Top keys** — most-accessed cache keys
- **Event stream** — live feed of recent cache events
- **Driver & namespace view** — filter metrics by driver or namespace

---

## REST API

| Method | Endpoint | Description |
|---|---|---|
| `GET` | `/api/health` | Server health check |
| `GET` | `/api/config` | Active configuration |
| `GET` | `/api/metrics` | Aggregated cache metrics |
| `GET` | `/api/events` | Paginated event log |
| `DELETE` | `/api/events/clear` | Clear all recorded events |
| `GET` | `/api/events/stream` | SSE stream of live events |

Full API documentation: [CacheerPHP - API Reference](https://cacheerphp.com/docs/en/api/)

---

## CLI Reference

| Flag | Default | Description |
|---|---|---|
| `--port` | `9966` | Port to listen on |
| `--host` | `127.0.0.1` | Host to bind to |
| `--quiet` | — | Suppress request logging |

---

## Security Note

The dashboard binds to `127.0.0.1` by default and is intended for **local development only**. Do not expose it on a public interface without adding authentication.

---

## Documentation

Full documentation: [CacheerPHP](https://cacheerphp.com/docs/en/cacheer-monitor/)

---

## License

MIT — see [LICENSE](LICENSE).
