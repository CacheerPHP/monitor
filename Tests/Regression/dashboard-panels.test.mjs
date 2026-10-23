import test from "node:test";
import assert from "node:assert/strict";
import { failoverWindowsHtml, keyPatternRowsHtml } from "../../public/assets/js/renderers.js";

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
