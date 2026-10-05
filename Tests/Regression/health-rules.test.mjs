import test from "node:test";
import assert from "node:assert/strict";
import { HealthRules, normalizeRules, DEFAULT_RULES } from "../../public/assets/js/health-rules.js";

const metrics = {
  hits: 2,
  misses: 18,
  errors: 5,
  total_events: 40,
  latency: { p95_ms: 25 },
  latency_samples: 20,
};

test("low hit-rate warnings require samples and a sustained condition", () => {
  const rules = new HealthRules({ holdSeconds: 30 });
  assert.equal(rules.evaluate({ hits: 0, misses: 1 }, 0).pending, 0);
  assert.equal(rules.evaluate(metrics, 10).pending, 1);
  assert.equal(rules.evaluate(metrics, 39).warnings.length, 0);
  assert.equal(rules.evaluate(metrics, 40).warnings[0].id, "hit_rate");
  assert.equal(rules.evaluate(metrics, 41).warnings.length, 1);
  assert.equal(rules.evaluate({ ...metrics, hits: 20, misses: 0 }, 42).warnings.length, 0);
  // Recovery resets the hold period and repeat cooldown remains in effect.
  assert.equal(rules.evaluate(metrics, 43).warnings.length, 0);
  assert.equal(rules.evaluate(metrics, 100).warnings.length, 1);
});

test("snoozing suppresses a persistent error warning until the cooldown expires", () => {
  const rules = new HealthRules({ hitRateEnabled: false, errorsEnabled: true, holdSeconds: 0, cooldownSeconds: 60 });
  assert.equal(rules.evaluate(metrics, 100).warnings[0].id, "errors");
  rules.dismiss("errors", 105);
  assert.equal(rules.evaluate(metrics, 164).warnings.length, 0);
  assert.equal(rules.evaluate(metrics, 165).warnings[0].id, "errors");
});

test("latency rules use timed samples, not the number of lifecycle events", () => {
  const rules = new HealthRules({ hitRateEnabled: false, latencyEnabled: true, holdSeconds: 0 });
  assert.equal(rules.evaluate({ ...metrics, total_events: 1000, latency_samples: 1 }, 0).warnings.length, 0);
  assert.equal(rules.evaluate(metrics, 1).warnings[0].id, "latency");
  assert.equal(rules.evaluate({ ...metrics, latency: { p95_ms: 5 } }, 2).warnings.length, 0);
  assert.equal(rules.evaluate({ ...metrics, latency: { p95_ms: NaN } }, 3).warnings.length, 0);
});

test("changing settings or filter context clears prior pending conditions", () => {
  const rules = new HealthRules({ holdSeconds: 10 });
  rules.evaluate(metrics, 1);
  rules.reset();
  assert.equal(rules.evaluate(metrics, 11).warnings.length, 0);
  rules.configure({ hitRateEnabled: false, holdSeconds: 0 });
  assert.equal(rules.evaluate(metrics, 100).warnings.length, 0);
});

test("disabled rules and empty activity do not report a problem", () => {
  const rules = new HealthRules({ hitRateEnabled: false, errorsEnabled: false, latencyEnabled: false, holdSeconds: 0 });
  assert.deepEqual(rules.evaluate(metrics, 0), { warnings: [], pending: 0 });
  assert.deepEqual(new HealthRules({ holdSeconds: 0 }).evaluate({}, 0), { warnings: [], pending: 0 });
});

test("invalid persisted preferences fall back to bounded, typed defaults", () => {
  assert.deepEqual(normalizeRules(null), DEFAULT_RULES);
  const rules = normalizeRules({
    hitRateThreshold: -1,
    latencyMs: Infinity,
    minSamples: 0,
    errorsEnabled: "true",
    cooldownSeconds: -5,
  });
  assert.equal(rules.hitRateThreshold, 0);
  assert.equal(rules.latencyMs, 10);
  assert.equal(rules.minSamples, 1);
  assert.equal(rules.errorsEnabled, false);
  assert.equal(rules.cooldownSeconds, 1);
});
