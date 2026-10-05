import { icon } from "./icons.js";
import { chartPalette } from "./charts.js";

const LARGE_VALUE_THRESHOLD = 10 * 1024;
let eventsEmptyState = null;

export function updateStatusIndicator(isOk) {
  const dot = document.getElementById("statusIndicator");
  if (dot) {
    dot.className = "status-dot " + (isOk ? "live" : "offline");
  }
  setTextById("connectionLabel", isOk ? "Connected" : "Connection lost");
  document.querySelector(".live-badge .status-dot")?.classList.toggle("live", isOk);
  if (isOk) {
    setTextById("lastUpdated", new Date().toLocaleTimeString());
  }
}

export function updateConfigInfo(config) {
  setTextById("eventsFile", config?.events_file || "—");
  document.getElementById("eventsFile")?.setAttribute("title", config?.events_file || "");
  setTextById("origin", "Source: " + (config?.origin || "default"));
}

export function updateMetricCards(metrics) {
  const latency = metrics?.latency || {};
  for (const name of ["hits", "misses", "puts", "flushes", "renews", "clears", "errors"]) {
    setTextById(name, formatNumber(metrics?.[name] ?? 0));
  }
  const lookups = Number(metrics?.hits ?? 0) + Number(metrics?.misses ?? 0);
  const percent = Math.min(100, Math.max(0, (Number(metrics?.hit_rate) || 0) * 100));
  setTextById("hit_rate", lookups ? formatPercent(metrics.hit_rate) : "—");
  setTextById("lookupCount", lookups ? `${formatNumber(lookups)} lookups` : "No lookups recorded");
  const fill = document.getElementById("hitRateFill");
  if (fill) {
    fill.style.width = `${percent}%`;
    fill.parentElement.setAttribute("aria-valuenow", String(percent));
    fill.parentElement.setAttribute(
      "aria-valuetext",
      lookups ? `${percent.toFixed(1)}% cache hits` : "No lookups recorded",
    );
  }
  const hasEvents = Number(metrics?.total_events ?? 0) > 0;
  for (const [id, value] of [
    ["lat_avg", latency.avg_ms],
    ["lat_p95", latency.p95_ms],
    ["lat_p99", latency.p99_ms],
  ]) {
    setTextById(id, hasEvents && isFiniteNumber(value) ? value.toFixed(1) + " ms" : "—");
  }
  setTextById(
    "since",
    metrics?.since ? "Recorded since " + new Date(metrics.since * 1000).toLocaleString() : "No recorded activity",
  );
  document.querySelector(".error-stat")?.classList.toggle("has-errors", Number(metrics?.errors ?? 0) > 0);
}

export function updateHitRateAlert(metrics, threshold = 0.5) {
  const banner = document.getElementById("hitRateAlert");
  const hitRate = metrics?.hit_rate ?? null;
  const lookups = Number(metrics?.hits ?? 0) + Number(metrics?.misses ?? 0);
  const isLow = hitRate !== null && lookups >= 10 && hitRate < threshold;
  setTextById(
    "efficiencyNote",
    !lookups
      ? "Waiting for your application's activity."
      : isLow
        ? "Hit rate is below your alert threshold."
        : "Lookups served directly from your cache.",
  );
  if (!banner) {
    return;
  }
  if (isLow) {
    banner.innerHTML = `${icon("alert")}<span>Hit rate is <strong>${formatPercent(hitRate)}</strong>, below your ${(threshold * 100).toFixed(0)}% threshold. Check for cold starts or cache configuration issues.</span>`;
  }
  banner.classList.toggle("hidden", !isLow);
}

export function renderDriversList(containerElement, driversMap, totalCount) {
  containerElement.replaceChildren();
  const entries = Object.entries(driversMap || {});
  if (!entries.length) {
    containerElement.innerHTML = '<div class="empty-inline">No driver activity recorded.</div>';
    return;
  }
  entries.forEach(([name, count], index) => {
    const pct = totalCount ? Math.round((count / totalCount) * 100) : 0;
    const row = document.createElement("div");
    row.className = "driver-row";
    row.innerHTML = `<div><span>${escapeHtml(name)}</span><span>${formatNumber(count)} · ${pct}%</span></div><div class="driver-track"><span style="width:${pct}%;background:${chartPalette()[index % chartPalette().length]}"></span></div>`;
    containerElement.appendChild(row);
  });
}

export function renderTopKeysTable(tbodyElement, topKeysMap, filterText, onKeyClick) {
  tbodyElement.replaceChildren();
  const entries = Object.entries(topKeysMap || {}).filter(
    ([key]) => !filterText || key.toLowerCase().includes(filterText.toLowerCase()),
  );
  if (!entries.length) {
    tbodyElement.innerHTML = '<tr><td colspan="2"><div class="empty-inline">No matching keys recorded.</div></td></tr>';
    return;
  }
  for (const [key, count] of entries) {
    const row = document.createElement("tr");
    row.innerHTML = `<td><button class="key-link" type="button" title="Inspect ${escapeHtml(key)}"><span>${escapeHtml(key)}</span>${icon("arrow")}</button></td><td>${formatNumber(count)}</td>`;
    row.querySelector("button").addEventListener("click", () => onKeyClick?.(key));
    tbodyElement.appendChild(row);
  }
}

export function renderNamespacesGrid(containerElement, namespaceMap) {
  containerElement.replaceChildren();
  const entries = Object.entries(namespaceMap || {});
  if (!entries.length) {
    containerElement.innerHTML = '<div class="empty-inline">No namespace activity recorded.</div>';
    return;
  }
  for (const [name, count] of entries) {
    const card = document.createElement("div");
    card.className = "namespace-card";
    card.innerHTML = `<span title="${escapeHtml(name || "(default)")}">${escapeHtml(name || "(default)")}</span><span>${formatNumber(count)}</span>`;
    containerElement.appendChild(card);
  }
}

export function renderEventsStream(containerElement, eventsList, onKeyClick, options = {}) {
  eventsEmptyState ??= document.getElementById("eventsEmpty");
  const items = (eventsList || []).slice().reverse();
  containerElement.replaceChildren();
  setTextById("eventCount", `${formatNumber(items.length)} event${items.length === 1 ? "" : "s"} shown`);
  if (!items.length) {
    if (eventsEmptyState) {
      eventsEmptyState.querySelector("[data-empty-title]").textContent = options.emptyTitle || "No events yet";
      eventsEmptyState.querySelector("[data-empty-detail]").textContent =
        options.emptyDetail || "Run your application to see cache operations here.";
      containerElement.appendChild(eventsEmptyState);
    }
    return;
  }
  for (const event of items) {
    containerElement.appendChild(buildEventRow(event, onKeyClick));
  }
}

function buildEventRow(event, onKeyClick) {
  const row = document.createElement("div");
  row.className = "event-row";
  const timestamp = new Date((event.ts || 0) * 1000).toLocaleTimeString();
  const payload = event.payload || {};
  const context = [escapeHtml(payload.driver || "unknown")];
  if (payload.namespace) {
    context.push(escapeHtml(payload.namespace));
  }
  if (payload.ttl != null) {
    context.push("TTL " + formatTtl(payload.ttl));
  }
  if (isFiniteNumber(payload.size_bytes)) {
    context.push(formatBytes(payload.size_bytes));
  }
  if (payload.value_type) {
    context.push(escapeHtml(payload.value_type));
  }
  if (payload.size_bytes > LARGE_VALUE_THRESHOLD) {
    context.push('<span class="large-value">Large value</span>');
  }
  const key = payload.key
    ? `<button class="key-link" type="button" title="Inspect ${escapeHtml(payload.key)}"><span>${escapeHtml(payload.key)}</span>${icon("arrow")}</button>`
    : '<span class="muted">Scope operation</span>';
  row.innerHTML = `<time class="event-time" datetime="${new Date((event.ts || 0) * 1000).toISOString()}">${timestamp}</time><div><span class="${badgeClass(event.type)}">${escapeHtml(event.type)}</span></div><div class="event-context">${key}<div class="event-meta">${context.map((item) => `<span>${item}</span>`).join("")}</div></div><span class="event-duration">${isFiniteNumber(payload.duration_ms) ? payload.duration_ms.toFixed(1) + " ms" : "—"}</span>`;
  row.querySelector("button")?.addEventListener("click", () => onKeyClick?.(payload.key, payload.namespace || null));
  return row;
}

export function renderKeyInspector(panelElement, data) {
  if (!panelElement || !data) {
    return;
  }
  const summary = data.summary || {};
  const isLive = summary.preview_source === "live";
  const namespaces = Object.keys(summary.namespaces || {})
    .map(escapeHtml)
    .join(" · ");
  const preview =
    summary.last_value_preview != null && summary.last_value_preview !== ""
      ? `<pre class="value-preview" tabindex="0" role="region" aria-label="Redacted value preview">${escapeHtml(formatJsonPreview(summary.last_value_preview))}</pre>`
      : `<div class="preview-unavailable">${summary.capture_values_enabled ? "No captured preview is available for this key, and a live lookup was not available." : "Value capture is disabled. Enable CACHEER_MONITOR_CAPTURE_VALUES in your application to record previews."}</div>`;
  const recentEvents = (data.events || [])
    .slice(0, 15)
    .map(
      (event) =>
        `<div class="inspector-event"><span class="${badgeClass(event.type)}">${escapeHtml(event.type)}</span><time>${new Date((event.ts || 0) * 1000).toLocaleTimeString()}</time></div>`,
    )
    .join("");
  panelElement.innerHTML = `
    <div class="inspector-summary"><div><strong>${escapeHtml(summary.key || "")}</strong><div class="inspector-namespaces">${namespaces || "Default namespace"}</div></div><span class="event-badge ${isLive ? "hit" : "tag"}">${isLive ? "Live value" : "Event history"}</span></div>
    <div class="inspector-stats">${inspectorStat("Hits", formatNumber(summary.hits ?? 0))}${inspectorStat("Misses", formatNumber(summary.misses ?? 0))}${inspectorStat("Writes", formatNumber(summary.puts ?? 0))}${inspectorStat("Hit rate", summary.hit_rate != null ? formatPercent(summary.hit_rate) : "—")}</div>
    <div class="inspector-metadata">${metaRow("Last written", formatTimestamp(summary.last_put_at))}${metaRow("Last hit", formatTimestamp(summary.last_hit_at))}${metaRow("Last miss", formatTimestamp(summary.last_miss_at))}${metaRow("Size", isFiniteNumber(summary.last_size_bytes) ? formatBytes(summary.last_size_bytes) : "—")}${metaRow("TTL", summary.last_ttl != null ? formatTtl(summary.last_ttl) : "—")}${metaRow("Value type", summary.last_value_type || "—")}</div>
    <section class="inspector-section"><h3>${icon("eye")}${isLive ? "Live cache value" : "Value preview"}</h3><p>${isLive ? "Resolved from current cache contents." : "Captured from recorded cache events."} Sensitive fields are masked.</p>${preview}</section>
    <section class="inspector-section"><h3>${icon("events")}Recent events</h3><p>Up to 15 recorded operations for this key.</p>${recentEvents || '<div class="empty-inline">No events found.</div>'}</section>`;
}

export function ttlDistributionChartData(distribution) {
  return {
    labels: ["≤1 min", ">1 min", ">5 min", ">1 hour", ">1 day", "Forever"],
    values: ["lte_1min", "gt_1min", "gt_5min", "gt_1hour", "gt_1day", "forever"].map((key) => distribution?.[key] ?? 0),
  };
}

function setTextById(id, text) {
  const element = document.getElementById(id);
  if (element) {
    element.textContent = String(text);
  }
}
function formatNumber(value) {
  const number = Number(value) || 0;
  if (number >= 1_000_000) {
    return (number / 1_000_000).toFixed(1) + "M";
  }
  if (number >= 10_000) {
    return (number / 1_000).toFixed(1) + "K";
  }
  return number.toLocaleString("en-US");
}
function formatBytes(value) {
  const bytes = Number(value) || 0;
  if (bytes >= 1_048_576) {
    return (bytes / 1_048_576).toFixed(1) + " MB";
  }
  if (bytes >= 1_024) {
    return (bytes / 1_024).toFixed(1) + " KB";
  }
  return bytes + " B";
}
function formatTtl(value) {
  const seconds = Number(value);
  if (!Number.isFinite(seconds) || seconds >= Number.MAX_SAFE_INTEGER / 2) {
    return "Forever";
  }
  for (const [label, unit] of [
    ["year", 31536000],
    ["month", 2592000],
    ["week", 604800],
    ["day", 86400],
    ["hour", 3600],
    ["minute", 60],
    ["second", 1],
  ]) {
    if (seconds >= unit) {
      const count = Math.round(seconds / unit);
      return `${count} ${label}${count !== 1 ? "s" : ""}`;
    }
  }
  return `${seconds} seconds`;
}
function formatJsonPreview(raw) {
  try {
    return JSON.stringify(JSON.parse(raw), null, 2);
  } catch (_) {
    return raw;
  }
}
function formatTimestamp(value) {
  return value ? new Date(value * 1000).toLocaleString() : "—";
}
function isFiniteNumber(value) {
  return typeof value === "number" && Number.isFinite(value);
}
function formatPercent(value) {
  return ((Number(value) || 0) * 100).toFixed(1) + "%";
}
function badgeClass(type) {
  const known = [
    "hit",
    "miss",
    "put",
    "put_forever",
    "put_many",
    "flush",
    "renew",
    "clear",
    "tag",
    "flush_tag",
    "add",
    "error",
  ];
  return "event-badge" + (known.includes(type) ? " " + type : "");
}
function escapeHtml(value) {
  return String(value)
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#039;");
}
function inspectorStat(label, value) {
  return `<div class="inspector-stat"><span>${label}</span><strong>${value}</strong></div>`;
}
function metaRow(label, value) {
  return `<div class="meta-row"><span>${label}</span><span>${escapeHtml(value)}</span></div>`;
}
