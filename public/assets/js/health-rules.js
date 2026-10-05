// Health rules run only on dashboard refreshes; no background service is required.
export const DEFAULT_RULES = Object.freeze({
  hitRateEnabled: true,
  hitRateThreshold: 50,
  errorsEnabled: false,
  errorCount: 5,
  latencyEnabled: false,
  latencyMs: 10,
  minSamples: 10,
  holdSeconds: 30,
  cooldownSeconds: 60,
});

export function normalizeRules(input = {}) {
  const rules = { ...DEFAULT_RULES };
  if (!input || typeof input !== "object") {
    return rules;
  }
  for (const key of ["hitRateEnabled", "errorsEnabled", "latencyEnabled"]) {
    if (typeof input[key] === "boolean") {
      rules[key] = input[key];
    }
  }
  for (const [key, min, max] of [
    ["hitRateThreshold", 0, 100],
    ["errorCount", 1, 1000000],
    ["latencyMs", 0.01, 1000000],
    ["minSamples", 1, 1000000],
    ["holdSeconds", 0, 86400],
    ["cooldownSeconds", 1, 86400],
  ]) {
    if (typeof input[key] === "number" && Number.isFinite(input[key])) {
      rules[key] = Math.min(max, Math.max(min, input[key]));
    }
  }
  return rules;
}

export class HealthRules {
  constructor(settings = {}) {
    this.settings = normalizeRules(settings);
    this.reset();
  }

  reset() {
    this.states = new Map();
    this.lastWarnings = new Map();
  }

  configure(settings) {
    this.settings = normalizeRules(settings);
    this.reset();
  }

  dismiss(rule, now = Date.now() / 1000) {
    const state = this.states.get(rule);
    if (state) {
      state.active = false;
      this.lastWarnings.set(rule, now);
    }
  }

  evaluate(metrics, now = Date.now() / 1000) {
    const settings = this.settings;
    const lookups = Number(metrics?.hits || 0) + Number(metrics?.misses || 0);
    const hitRate = lookups > 0 ? (Number(metrics?.hits || 0) / lookups) * 100 : null;
    const p95 = metrics?.latency?.p95_ms;
    const errors = Number(metrics?.errors || 0);
    const rules = [
      {
        id: "hit_rate",
        breached: settings.hitRateEnabled && lookups >= settings.minSamples && hitRate < settings.hitRateThreshold,
        title: "Low cache hit rate",
        detail: `${hitRate?.toFixed(1) ?? "—"}% from ${lookups} lookups; threshold ${settings.hitRateThreshold}%.`,
      },
      {
        id: "errors",
        breached:
          settings.errorsEnabled &&
          Number(metrics?.total_events || 0) >= settings.minSamples &&
          errors >= settings.errorCount,
        title: "Cache errors detected",
        detail: `${errors} recorded errors; threshold ${settings.errorCount} in the current range.`,
      },
      {
        id: "latency",
        breached:
          settings.latencyEnabled &&
          Number(metrics?.latency_samples || 0) >= settings.minSamples &&
          typeof p95 === "number" &&
          Number.isFinite(p95) &&
          p95 > settings.latencyMs,
        title: "High cache operation latency",
        detail: `p95 ${typeof p95 === "number" ? p95.toFixed(1) : "—"} ms; threshold ${settings.latencyMs} ms.`,
      },
    ];
    const warnings = [];
    let pending = 0;
    for (const rule of rules) {
      if (!rule.breached) {
        this.states.delete(rule.id);
        continue;
      }
      let state = this.states.get(rule.id);
      if (!state) {
        state = { since: now, active: false };
        this.states.set(rule.id, state);
      }
      const lastWarning = this.lastWarnings.get(rule.id) ?? -Infinity;
      if (now - state.since >= settings.holdSeconds && now - lastWarning >= settings.cooldownSeconds && !state.active) {
        state.active = true;
        this.lastWarnings.set(rule.id, now);
      }
      if (state.active) {
        warnings.push({ id: rule.id, title: rule.title, detail: rule.detail });
      } else {
        pending++;
      }
    }
    return { warnings, pending };
  }
}
