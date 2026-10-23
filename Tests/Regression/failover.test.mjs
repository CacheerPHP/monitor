import test from "node:test";
import assert from "node:assert/strict";
import { failoverWindowsHtml } from "../../public/assets/js/renderers.js";

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
