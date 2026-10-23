# RFC-001: Make Monitor cheap, honest, and useful

- Status: Implemented in PR-1 to PR-7. Monitor: PR-1 and PR-3 to PR-7 are
  committed separately on branch `rfc-001/pr-7-streaming-snapshot`.
  CacheerPHP: branch
  `rfc-monitor-001/pr-2-telemetry-context`.
- Proposed: 2026-10-22
- Scope: `cacheerphp/monitor` 2.x, plus one CacheerPHP 6.1 change (PR-2)
- Machine: Apple M4, 10 cores, 16 GB, macOS 27.0, PHP 8.5.11 CLI (OPcache off),
  CacheerPHP 6.0.0, Monitor `2.x` @ 2f15fbb

## Motivation

Monitor is the visible difference CacheerPHP can offer outside Laravel, and the
first thing a new user installs next to it. Three problems stop it from playing
that role:

1. **It slows down the application it watches** — enough that it is not safe to
   leave on outside local development.
2. **Several panels can never show data**, because CacheerPHP does not report
   what they display.
3. **It counts what other tools already count** (hits, misses, latency), and
   does not answer the questions people open a cache dashboard with: which cache
   is not paying off, and does failover work.

This RFC fixes them in that order, in small PRs.

## Findings

### M1 — Every cache operation pays for a locked file append

`JsonlReporter::event()` takes an exclusive `flock` on a shared lock file, stats
the events file for rotation, and opens, appends to, and closes the events file
— for every cache operation. Workers sharing the file queue on the one lock.

`Cacheer::inMemory()->get()` of a hit, value capture off (the default), median
of 50,000 calls:

| | Per `get()` |
| --- | --- |
| Monitor not installed | 0.64 µs |
| Monitor installed, 1 process | 30.6 µs (≈ 48×) |
| Monitor installed, 8 processes at once | 259 µs each |

A Redis read costs about 88 µs on loopback, so eight busy workers roughly triple
cache latency. The dashboard does not show this cost: operation durations are
measured inside the store, before the reporter runs. No events were lost under
concurrency (176,008 of 176,000 expected lines, the extra 8 being warm-up writes).

### M2 — Panels with no data source

CacheerPHP 6.0's `CacheEvent` carries no scope and no TTL, so:

- the namespace panels and the namespace filter only ever see "not reported";
- the TTL distribution is always empty;
- `renew` is never emitted: `touch()` reports as a write. **Renews** is always 0.

Several operations also collapse into one type, so the dashboard cannot tell them
apart: `touch()`, `increment()`, `compareAndSwap()`, and `tag()` all report as
`put`; a tag flush reports as `prune`.

(`clear()` and scoped clears do report, so **Flushes** works; an earlier
walkthrough showing 0 had simply not cleared anything.)

### M3 — Failover is invisible

`ResilientStore` emits nothing when its circuit opens, when it serves from the
fallback, or when it reconciles after recovery. Reads served by the fallback
look like ordinary hits from the fallback's driver at best. The feature
CacheerPHP promotes most cannot be seen.

### M4 — No answers beyond counts

Hit rate per individual key is noisy (`user:1`, `user:2`, …), and nothing shows
what the cache saved, which writes were wasted, or when a stampede happened.

### M5 — Every refresh re-reads the whole log

Each snapshot scans and aggregates the full current log (rotated at 10 MB).
Acceptable for local use; it bounds history and does not scale to long
retention. Not addressed here beyond keeping the reader correct.

## Principles

1. Every PR names the finding it fixes, adds tests for the behavior it must not
   change, and reports before/after numbers from the same script where it claims
   a performance change.
2. Monitor must keep working, and stay honest, with CacheerPHP 6.0: a field an
   older core does not send stays unknown — never zero, never inferred.
3. Telemetry must not change application results or throw into the application
   (CacheerPHP's `SafeDispatch` contract and Monitor's quiet bootstrap).
4. No new runtime dependencies, frameworks, or build steps for the dashboard.

## Plan

| PR | Repo | Fixes | Change | Acceptance |
| --- | --- | --- | --- | --- |
| **PR-1** | monitor | M1 | `JsonlReporter` buffers encoded lines in memory and appends them under one lock per flush. It flushes at 256 events, at 1 second since the last flush (checked on the next event), on `flush()`, at shutdown (including after a fatal error), and on destruction. Feeds and key history sort by timestamp, since processes now interleave by batch. | 1 process ≤ 3 µs per operation; 8 processes ≤ 5 µs each; no events lost on normal exit or fatal error; while a process keeps using the cache, events reach the file within 1 s; existing reporter, store, and API tests pass. |
| **PR-2** | CacheerPHP 6.1 | M2, M3, M4 (data) | `CacheEvent` gains optional `scope`, `ttl` (seconds, `null` = forever, absent = unknown), and `operation` (`touch`, `increment`, `tag`, `clearTag`, …). New event types: circuit opened / closed, served from fallback, reconciled; and `computed` with the loader's duration for `remember()` / `flexible()` misses. Additive and backward compatible. | Observability, resilience, and telemetry-tap tests cover every new field and type; `SafeDispatch` unchanged; instrumented hot path within ~0.1 µs of 6.0. |
| **PR-3** | monitor | M2, M3 | Consume PR-2: namespace and TTL panels fed; `renew` from `operation: touch`; distinct counters for increments and tag flushes; a failover timeline (outage start/end, fallback-served counts, reconciliation). Panels show "not reported by this CacheerPHP version" when the core is 6.0. | Dashboard and API regression tests for both 6.0 and 6.1 event shapes. |
| **PR-4** | monitor | M4 | Key patterns: normalize keys (`user:123` → `user:{id}`, UUIDs, hashes) and rank patterns by hit rate, misses, and latency. | Deterministic normalization tests; patterns grouped per driver and namespace. |
| **PR-5** | monitor | M4 | Time saved: sum of `computed` durations avoided by hits, per pattern, from PR-2 data. | Only shown with `computed` samples; sample counts displayed. |
| **PR-6** | monitor | M4 | Wasted writes (written, then expired or overwritten unread) and stampedes (bursts of misses on one key, with lock contention). | Thresholds documented; no false positives in the regression fixtures. |

PR-1 is independent. PR-3 and PR-5 depend on PR-2; PR-4 and PR-6 can start any time.

## Outcomes (same machine, same script)

| PR | Result | Target met? |
| --- | --- | --- |
| PR-1 | Per `get()` with Monitor installed: 1 process 30.6 → 2.5 µs (0.64 µs without Monitor); 8 processes 259 → 4.1 µs each. 176,008 of 176,008 lines written, none malformed. Buffered events survive a true fatal error (memory exhaustion), where destructors do not run. | Yes |
| PR-2 | Events carry `scope`, `ttl` (a `Ttl`, null = unknown), and `operation`; new `Computed`, `CircuitOpened`, `CircuitClosed`, `FallbackServed`, `Reconciled`. Found and fixed: lifecycle events named the store `InstrumentedStore` under telemetry, so they could never be matched to their keys' hits. Instrumented in-memory `get()` with a no-op listener: 1.29 → 1.39 µs (a first version that copied each event cost +0.39 µs and was reworked). Core suite 562 passed / 5 skipped; Redis integration 66 passed (run with the suite's temporary skip removed, then restored). | Yes, with the cost stated |
| PR-3 | The listener records `namespace`, `ttl`, and `operation` when the core sends them and maps `touch`/`tag`/`increment`/`clearTag` to `renew`/`tag`/`increment`/`flush_tag`; new types pass through. The aggregator adds increments, tag flushes, loader runs (kept out of cache latency), and a failover summary with outage windows built from fallback activity. The dashboard adds a Failover panel and the new counters, filters, and badges. Verified against the local 6.1 core and against 6.0 (6.1-only tests skip); real-listener sample traffic checked in the browser, light/dark and 390 px. | Yes |
| PR-4 | `KeyPattern` replaces standalone identifiers (`{id}`, `{uuid}`, `{ulid}`, `{date}`, `{email}`, `{hash}`) and leaves numbers inside words alone. Patterns are grouped per driver and namespace with distinct-key counts and three example keys, ranked by lowest hit rate (20+ lookups only), misses, lookups, and p95 latency; key search narrows them. New "Key patterns" panel in the cache explorer. `summarize()` on 50,000 events with 5,000 keys: 50 → 62 ms against `2.x` (PR-3 and PR-4 together). | Yes |
| PR-5 | Per pattern, time saved = hits × max(0, average loader time − average hit time), from `computed` records; `null` (unknown) without loader runs, and such patterns are left out of the total. Shown as "≈ … of loader time saved (estimate from N loader runs)" in the efficiency card, as a column, and as a "Most time saved" ranking. End to end with the 6.1 core: a 25 ms loader reused 294 times saved ≈ 7.3 s; a `set()`-filled key got no estimate. | Yes |
| PR-6 | Wasted writes (value writes overwritten, deleted, or expired with a reported TTL before any read; unknown outcomes not counted) and stampedes (3+ misses on one key within 2 s from 2+ processes, with the loader runs in the burst). `ArrayStore` is judged per process: an end-to-end run first produced false "overwritten" writes and a false stampede from four processes' private caches, which this fixed. New "Wasted work" panel, a "Wasted writes" column and ranking; year-month keys (`2026-10`) now normalize to `{date}`. Four real processes on a shared store without locking produced one stampede (4 misses, 4 loader runs) and 80 of 80 short-TTL promo writes expired unread. | Yes |

Refresh cost after PR-1 to PR-6, read and summarize together, against `2.x`: a
7.7 MB log (50,000 events) 92 → 132 ms and 76 → 90 MB peak; a full 10 MB log
(64,662 events) 94 → 111 MB peak, under PHP's default 128 MB limit but with half
the headroom. Captured value previews make events larger and the margin smaller.
Most of the cost is decoding the whole log into arrays before aggregating, which
`2.x` already does; **streaming aggregation** (summarizing while reading lines)
is the recommended next performance change, ahead of the deferred rollups.

| PR | Result | Target met? |
| --- | --- | --- |
| PR-7 (added after PR-6, from the finding above) | The snapshot streams the log once: `EventStore::stream()` yields decoded, filtered events; the summary, a new incremental `Timeline`, and a `FeedWindow` all consume that single pass, so the decoded log is never held in memory. The feed now keeps the newest N matches **by timestamp** — before, the snapshot feed took the last N in file order, which PR-1's batched writes made inexact. Full 10 MB log (64,662 events) through the real `snapshot()`: peak memory 112 MB (PR-6) / 94 MB (`2.x`) → **37 MB**; time ~190–240 ms against ~185–210 ms for PR-6 (noisy). | Yes |

PR-1 limits, documented in the [configuration guide](https://cacheerphp.com/docs/v6/en/cacheer-monitor/configuration/):

- A long-running worker that goes idle writes its last events at its next cache
  operation or when it exits; `JsonlReporter::flush()` writes them sooner. A
  later Laravel-driver change could flush at the end of each Octane request.
- Feeds and key history are ordered by timestamp. The unfiltered "latest N"
  fast path reads only the file's last N lines, so near its edge it can miss an
  event from a later batch with an earlier timestamp (at most one flush interval).
- A process killed with SIGKILL loses its unwritten batch (at most 256 events
  or about one second of activity).

PR-3 notes:

- A window's "No recovery recorded yet" means only that no circuit close or
  reconciliation followed it; under PHP-FPM a recovered primary often reports
  neither, because new processes simply succeed.
- The Laravel driver's `StoreFactory` now passes the telemetry tap when it
  builds `ResilientStore`, so Laravel apps can report failover with core 6.1.

PR-2 notes for PR-3:

- Monitor 2.x's listener maps types with an exhaustive `match`. Against core
  6.1 the five new types throw inside the listener; `SafeDispatch` keeps that
  from the application, but those events are lost. PR-3 must handle them, and
  Monitor should ship it before or with core 6.1.
- `ResilientStore` built directly (not through `Cacheer::resilient()`) reports
  nothing unless given a dispatcher. The Laravel driver's `StoreFactory`
  now passes the telemetry tap.

## Deferred

- **Rollups** (per-minute summaries) for longer history — after PR-6, if the
  full-log scan becomes the bottleneck.
- **Request context** (route, job, command) — easiest from the Laravel driver.
- **Multiple servers** — aggregating across hosts makes Monitor a hosted
  product. A strategy decision, not a fix.
- **Sampling** — only if buffered writes are still too expensive for busy
  production traffic; sampling changes counts and must be labeled.

## Rejected alternatives

- **A background thread or process to write events** — PHP has no portable
  threads, and a sidecar adds deployment burden to a zero-setup tool.
- **Lock-free `O_APPEND` writes per event** — removes the lock contention but
  keeps an open/write/close per operation, and multi-kilobyte value previews can
  exceed the size at which concurrent appends stay unbroken.
- **Writing to Redis or a database** — a dependency the local, file-based design
  deliberately avoids.
