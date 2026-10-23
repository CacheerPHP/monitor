import test from "node:test";
import assert from "node:assert/strict";
import {
  failoverWindowsHtml,
  keyPatternRowsHtml,
  stampedeListHtml,
  timeSavedNote,
} from "../../public/assets/js/renderers.js";

test("no outage explains what failover reporting needs", () => {
  const html = failoverWindowsHtml({ windows: [] });

  assert.match(html, /No outage recorded/);
  assert.match(html, /CacheerPHP 6\.1 or later and a resilient cache/);
});

test("an outage window shows its extent and escapes the recorded error", () => {
  const html = failoverWindowsHtml({
    windows: [
      {
        start: 1_700_000_000,
        end: 1_700_000_155,
        duration_seconds: 155,
        fallback_served: 1204,
        processes: 3,
        error: "<script>alert(1)</script> Connection refused",
        recovered: true,
      },
    ],
  });

  assert.match(html, /2m 35s · 1,204 served by fallback · 3 processes/);
  assert.match(html, /&lt;script&gt;alert\(1\)&lt;\/script&gt; Connection refused/);
  assert.doesNotMatch(html, /<script>/);
  assert.match(html, /Recovery recorded/);
});

test("a window without a recorded recovery says so rather than claiming an ongoing outage", () => {
  const html = failoverWindowsHtml({
    windows: [{ start: 1_700_000_000, duration_seconds: 0.2, fallback_served: 1, processes: 1, recovered: false }],
  });

  assert.match(html, /under a second · 1 served by fallback · 1 process</);
  assert.match(html, /No recovery recorded yet/);
});

test("key pattern rows escape patterns and example keys", () => {
  const html = keyPatternRowsHtml(
    [
      {
        pattern: "user:{id}<img src=x>",
        driver: "RedisStore",
        namespace: "",
        keys: 1204,
        examples: ["user:1", "user:<b>2</b>"],
        lookups: 30,
        hits: 6,
        misses: 24,
        hit_rate: 0.2,
        latency: { avg_ms: 1.25, p95_ms: 3.5 },
        latency_samples: 30,
      },
    ],
    "hit_rate",
  );

  assert.match(html, /user:\{id\}&lt;img src=x&gt;/);
  assert.match(html, /RedisStore · default/);
  assert.match(html, /e\.g\. user:1, user:&lt;b&gt;2&lt;\/b&gt;/);
  assert.match(html, /<td>1,204<\/td><td>30<\/td><td>20\.0%<\/td><td>24<\/td>/);
  assert.match(html, /1\.3 \/ 3\.5 ms/);
  assert.doesNotMatch(html, /<img/);
});

test("an empty hit-rate ranking explains the lookup minimum", () => {
  assert.match(keyPatternRowsHtml([], "hit_rate", 20), /No key pattern has 20 or more lookups yet/);
  assert.match(keyPatternRowsHtml([], "latency", 20), /No recorded timed operations for matching keys/);
});

test("an unreported namespace is labeled, not shown as the default", () => {
  const html = keyPatternRowsHtml([
    {
      pattern: "k:{id}",
      driver: "FileStore",
      namespace: null,
      keys: 1,
      examples: ["k:1"],
      lookups: 1,
      hits: 1,
      misses: 0,
      hit_rate: 1,
      latency: {},
      latency_samples: 0,
    },
  ]);

  assert.match(html, /FileStore · namespace unreported/);
  assert.match(html, /<td>—<small/);
});

test("time saved is stated as an estimate with its loader runs", () => {
  assert.equal(
    timeSavedNote({ estimated_ms: 15_120_000, patterns: 4, hits: 9000, loader_runs: 312 }),
    "≈ 4.2 h of loader time saved (estimate from 312 loader runs).",
  );
  assert.equal(
    timeSavedNote({ estimated_ms: 980, loader_runs: 1 }),
    "≈ 980 ms of loader time saved (estimate from 1 loader run).",
  );
  assert.equal(timeSavedNote({ estimated_ms: null, loader_runs: 0 }), null);
  assert.equal(timeSavedNote(undefined), null);
});

test("a pattern row shows time saved, or that no loader timings were recorded", () => {
  const base = {
    driver: "RedisStore",
    namespace: "",
    keys: 2,
    examples: ["report:1"],
    lookups: 12,
    hits: 10,
    misses: 2,
    hit_rate: 10 / 12,
    latency: { avg_ms: 2, p95_ms: 2 },
    latency_samples: 12,
  };

  assert.match(
    keyPatternRowsHtml(
      [{ ...base, pattern: "report:{id}", loader_runs: 2, loader_avg_ms: 400, time_saved_ms: 3980 }],
      "time_saved",
    ),
    /<td>4\.0 s<small class="key-context">2 loader runs · avg 400 ms<\/small><\/td>/,
  );
  assert.match(
    keyPatternRowsHtml([{ ...base, pattern: "config:{id}", loader_runs: 0, loader_avg_ms: null, time_saved_ms: null }]),
    /<td>—<small class="key-context">no loader timings<\/small><\/td>/,
  );
  assert.match(
    keyPatternRowsHtml([], "time_saved"),
    /needs CacheerPHP 6\.1 or later and values cached with remember\(\) or flexible\(\)/,
  );
  assert.match(keyPatternRowsHtml([], "misses"), /colspan="8"/);
});

test("a pattern row shows its wasted writes against the writes with a known outcome", () => {
  const base = {
    pattern: "report:{id}",
    driver: "RedisStore",
    namespace: "",
    keys: 2,
    examples: ["report:1"],
    lookups: 0,
    hits: 0,
    misses: 0,
    hit_rate: null,
    latency: {},
    latency_samples: 0,
    loader_runs: 0,
    time_saved_ms: null,
  };

  assert.match(
    keyPatternRowsHtml([{ ...base, wasted_writes: 3, evaluated_writes: 5 }], "wasted_writes"),
    /<td>3 of 5<small class="key-context">ended before any read<\/small><\/td><\/tr>/,
  );
  assert.match(
    keyPatternRowsHtml([{ ...base, wasted_writes: 0, evaluated_writes: 0 }]),
    /<td>—<small class="key-context">no known outcome<\/small><\/td><\/tr>/,
  );
  assert.match(keyPatternRowsHtml([], "wasted_writes"), /every write with a known outcome was read/);
});

test("a stampede lists its key escaped, its burst, and the work it repeated", () => {
  const html = stampedeListHtml({
    recent: [
      {
        key: "report:<1>",
        driver: "RedisStore",
        namespace: "",
        start: 1_700_000_000,
        duration_seconds: 0.3,
        misses: 4,
        processes: 3,
        loader_runs: 3,
      },
      {
        key: "legacy:1",
        driver: "FileStore",
        namespace: null,
        start: 1_700_000_100,
        duration_seconds: 1.5,
        misses: 3,
        processes: 2,
        loader_runs: 0,
      },
    ],
  });

  assert.match(html, /<strong>report:&lt;1&gt;<\/strong>/);
  assert.match(html, /4 misses in under a second · 3 processes · 3 loader runs/);
  assert.match(html, /Work repeated/);
  assert.match(html, /FileStore · namespace unreported/);
  assert.match(html, /3 misses in 2s · 2 processes · loader runs not reported/);
  assert.match(stampedeListHtml({ recent: [] }), /No stampede recorded/);
});
