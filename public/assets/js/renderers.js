// Render helpers for Cacheer Monitor

// [ constants ]

const LARGE_VALUE_THRESHOLD = 10 * 1024; // 10 KB

export function updateStatusIndicator(isOk) {
  const dot = document.getElementById("statusIndicator");
  if (dot) {
    dot.className = "w-2 h-2 rounded-full status-pulse " + (isOk ? "bg-emerald-500 live" : "bg-rose-500");
  }
  const ts = document.getElementById("lastUpdated");
  if (ts) {
    ts.textContent = new Date().toLocaleTimeString();
  }
}

export function updateConfigInfo(config) {
  const file = document.getElementById("eventsFile");
  const origin = document.getElementById("origin");
  if (file) {
    file.textContent = config?.events_file || "--";
  }
  if (origin) {
    origin.textContent = "origin: " + (config?.origin || "default");
  }
}

export function updateMetricCards(metrics) {
  const latency = metrics?.latency || {};

  animateMetric("hits", formatNumber(metrics?.hits ?? 0));
  animateMetric("misses", formatNumber(metrics?.misses ?? 0));
  animateMetric("puts", formatNumber(metrics?.puts ?? 0));
  animateMetric("flushes", formatNumber(metrics?.flushes ?? 0));
  animateMetric("renews", formatNumber(metrics?.renews ?? 0));
  animateMetric("clears", formatNumber(metrics?.clears ?? 0));
  animateMetric("errors", formatNumber(metrics?.errors ?? 0));
  animateMetric("hit_rate", formatPercent(metrics?.hit_rate ?? 0));
  animateMetric("lat_avg", isFiniteNumber(latency.avg_ms) ? latency.avg_ms.toFixed(1) + " ms" : "--");

  setTextById("lat_p95", isFiniteNumber(latency.p95_ms) ? latency.p95_ms.toFixed(1) + " ms" : "--");
  setTextById("lat_p99", isFiniteNumber(latency.p99_ms) ? latency.p99_ms.toFixed(1) + " ms" : "--");

  if (metrics?.since) {
    setTextById("since", "since: " + new Date(metrics.since * 1000).toLocaleString());
  }
}

export function updateHitRateAlert(metrics, threshold = 0.5) {
  const banner = document.getElementById("hitRateAlert");
  if (!banner) {
    return;
  }

  const hitRate = metrics?.hit_rate ?? null;
  const lookups = (metrics?.hits ?? 0) + (metrics?.misses ?? 0);

  if (hitRate !== null && lookups >= 10 && hitRate < threshold) {
    const pct = (hitRate * 100).toFixed(1);
    const thresholdPct = (threshold * 100).toFixed(0);
    banner.innerHTML = `<i class="fa-solid fa-triangle-exclamation"></i> Hit rate is <strong>${pct}%</strong> — below the ${thresholdPct}% threshold. Check for cache misconfigurations or cold start issues.`;
    banner.classList.remove("hidden");
  } else {
    banner.classList.add("hidden");
  }
}

export function renderDriversList(containerElement, driversMap, totalCount) {
  containerElement.innerHTML = "";
  const entries = Object.entries(driversMap || {});

  if (entries.length === 0) {
    containerElement.innerHTML =
      '<div class="text-xs text-slate-400 dark:text-slate-500 text-center py-4"><i class="fa-solid fa-server mr-1"></i> No driver data</div>';
    return;
  }

  entries.forEach(([name, count], index) => {
    const pct = totalCount ? Math.round((count / totalCount) * 100) : 0;
    const row = document.createElement("div");
    row.className = "text-xs";
    row.innerHTML = `
      <div class="flex items-center justify-between mb-1">
        <span class="font-medium text-slate-700 dark:text-slate-200">${escapeHtml(name)}</span>
        <span class="text-slate-400 tabular-nums">${formatNumber(count)} &middot; ${pct}%</span>
      </div>
      <div class="w-full h-1.5 bg-slate-100 dark:bg-slate-700 rounded-full overflow-hidden">
        <div class="h-full rounded-full transition-all duration-500" style="width:${pct}%; background:${progressColor(index)}"></div>
      </div>`;
    containerElement.appendChild(row);
  });
}

export function renderTopKeysTable(tbodyElement, topKeysMap, filterText, onKeyClick) {
  tbodyElement.innerHTML = "";

  const entries = Object.entries(topKeysMap || {});
  const filtered = filterText
    ? entries.filter(([key]) => key.toLowerCase().includes(filterText.toLowerCase()))
    : entries;

  if (filtered.length === 0) {
    const row = document.createElement("tr");
    row.innerHTML =
      '<td colspan="2" class="py-4 text-center text-xs text-slate-400 dark:text-slate-500"><i class="fa-solid fa-key mr-1"></i> No keys found</td>';
    tbodyElement.appendChild(row);
    return;
  }

  filtered.forEach(([key, count]) => {
    const row = document.createElement("tr");
    row.className =
      "border-b border-slate-50 dark:border-slate-700/40 hover:bg-slate-50 dark:hover:bg-slate-700/30 transition-colors cursor-pointer group";
    row.title = "Click to inspect this key";
    row.innerHTML = `
      <td class="py-1.5 pr-2 truncate max-w-[40ch]">
        <span class="font-mono text-xs">${escapeHtml(key)}</span>
        <span class="ml-1.5 opacity-0 group-hover:opacity-100 transition-opacity text-blue-500 text-[10px]"><i class="fa-solid fa-magnifying-glass"></i></span>
      </td>
      <td class="py-1.5 text-right tabular-nums font-medium">${formatNumber(count)}</td>`;
    if (typeof onKeyClick === "function") {
      row.addEventListener("click", () => onKeyClick(key));
    }
    tbodyElement.appendChild(row);
  });
}

export function renderNamespacesGrid(containerElement, namespaceMap) {
  containerElement.innerHTML = "";
  const entries = Object.entries(namespaceMap || {});

  if (entries.length === 0) {
    containerElement.innerHTML =
      '<div class="text-xs text-slate-400 dark:text-slate-500 text-center py-4 col-span-full"><i class="fa-regular fa-folder-open mr-1"></i> No namespace data</div>';
    return;
  }

  entries.forEach(([name, count]) => {
    const card = document.createElement("div");
    card.className =
      "p-3 rounded-lg border border-slate-100 dark:border-slate-700/60 bg-white dark:bg-slate-800/60 hover:border-slate-300 dark:hover:border-slate-600 transition-colors";
    card.innerHTML = `
      <div class="flex items-center justify-between text-xs">
        <span class="font-mono font-medium text-slate-700 dark:text-slate-200">${escapeHtml(name || "(default)")}</span>
        <span class="tabular-nums text-slate-400 font-semibold">${formatNumber(count)}</span>
      </div>`;
    containerElement.appendChild(card);
  });
}

// [ events stream ]

export function renderEventsStream(containerElement, eventsList, onKeyClick, options = {}) {
  const emptyState = document.getElementById("eventsEmpty");
  const items = (eventsList || []).slice().reverse();

  if (items.length === 0) {
    containerElement.innerHTML = "";
    if (emptyState) {
      const titleEl = emptyState.querySelector("[data-empty-title]");
      const detailEl = emptyState.querySelector("[data-empty-detail]");
      if (titleEl) {
        titleEl.textContent = options.emptyTitle || "No events yet";
      }
      if (detailEl) {
        detailEl.textContent = options.emptyDetail || "Events will appear here as they stream in";
      }
      emptyState.classList.remove("hidden");
      containerElement.appendChild(emptyState);
    }
    return;
  }

  containerElement.innerHTML = "";
  if (emptyState) {
    emptyState.classList.add("hidden");
  }

  for (const ev of items) {
    containerElement.appendChild(buildEventRow(ev, onKeyClick));
  }
}

function buildEventRow(ev, onKeyClick) {
  const row = document.createElement("div");
  row.className = "event-row text-xs px-5 py-2.5 border-b border-slate-50 dark:border-slate-700/40";

  const ts = new Date((ev.ts || 0) * 1000).toLocaleTimeString();
  const payload = ev.payload || {};
  const driver = payload.driver || "unknown";

  const keyHtml = payload.key
    ? `<span class="font-mono font-medium text-slate-700 dark:text-slate-200 hover:text-blue-500 dark:hover:text-blue-400 cursor-pointer key-link">${escapeHtml(payload.key)}</span>`
    : "";
  const nsHtml = payload.namespace
    ? `<span class="ml-1.5 px-1.5 py-0.5 rounded bg-slate-100 dark:bg-slate-700/60 text-slate-500 dark:text-slate-400 text-[10px]">${escapeHtml(payload.namespace)}</span>`
    : "";
  const durHtml = isFiniteNumber(payload.duration_ms)
    ? `<span class="inline-flex items-center gap-1"><i class="fa-regular fa-clock"></i>${Number(payload.duration_ms).toFixed(1)} ms</span>`
    : "";
  const ttlHtml =
    payload.ttl != null
      ? `<span class="inline-flex items-center gap-1"><i class="fa-regular fa-hourglass-half"></i>TTL: ${formatTtl(payload.ttl)}</span>`
      : "";
  const sizeHtml = isFiniteNumber(payload.size_bytes)
    ? `<span class="inline-flex items-center gap-1"><i class="fa-regular fa-file-lines"></i>${formatBytes(payload.size_bytes)}</span>`
    : "";
  const largeHtml =
    isFiniteNumber(payload.size_bytes) && payload.size_bytes > LARGE_VALUE_THRESHOLD
      ? `<span class="inline-flex items-center gap-1 text-amber-500 dark:text-amber-400 font-semibold"><i class="fa-solid fa-weight-hanging"></i>large value</span>`
      : "";
  const typeHtml = payload.value_type
    ? `<span class="inline-flex items-center gap-1 text-indigo-500 dark:text-indigo-400"><i class="fa-solid fa-tag"></i>${escapeHtml(payload.value_type)}</span>`
    : "";

  row.innerHTML = `
    <div class="flex items-center justify-between gap-2">
      <div class="flex items-center gap-2 min-w-0">
        <span class="${badgeClass(ev.type)}">${escapeHtml(ev.type)}</span>
        <span class="truncate">${keyHtml}${nsHtml}</span>
      </div>
      <span class="text-slate-400 dark:text-slate-500 tabular-nums whitespace-nowrap">${ts}</span>
    </div>
    <div class="flex items-center gap-3 text-slate-400 dark:text-slate-500 mt-1 flex-wrap">
      <span class="inline-flex items-center gap-1"><i class="fa-solid fa-server"></i>${escapeHtml(driver)}</span>
      ${durHtml}${ttlHtml}${sizeHtml}${typeHtml}${largeHtml}
    </div>`;

  if (payload.key && typeof onKeyClick === "function") {
    const keyEl = row.querySelector(".key-link");
    if (keyEl) {
      keyEl.addEventListener("click", (e) => {
        e.stopPropagation();
        onKeyClick(payload.key, payload.namespace || null);
      });
    }
  }

  return row;
}

// [ inspector ]

export function renderKeyInspector(panelEl, data) {
  if (!panelEl || !data) {
    return;
  }

  const summary = data.summary || {};
  const events = data.events || [];

  const isLive = summary.preview_source === "live";
  const hitRate = summary.hit_rate != null ? (summary.hit_rate * 100).toFixed(1) + "%" : "--";
  const lastPut = summary.last_put_at ? new Date(summary.last_put_at * 1000).toLocaleString() : "--";
  const lastHit = summary.last_hit_at ? new Date(summary.last_hit_at * 1000).toLocaleString() : "--";
  const lastMiss = summary.last_miss_at ? new Date(summary.last_miss_at * 1000).toLocaleString() : "--";
  const sizeStr = isFiniteNumber(summary.last_size_bytes) ? formatBytes(summary.last_size_bytes) : "--";
  const ttlStr = summary.last_ttl != null ? formatTtl(summary.last_ttl) : "--";
  const typeStr = summary.last_value_type || "--";

  const captureEnabled = Boolean(summary.capture_values_enabled);
  const previewSource = isLive ? "Live Cache Value" : "Value Preview";
  const previewHint = isLive
    ? "(resolved from current cache contents; sensitive fields masked)"
    : "(captured from cache events; sensitive fields masked)";

  const nsBadges = Object.keys(summary.namespaces || {})
    .map(
      (ns) =>
        `<span class="px-1.5 py-0.5 rounded bg-slate-100 dark:bg-slate-700/60 text-[10px] font-mono">${escapeHtml(ns)}</span>`,
    )
    .join(" ");

  const previewHtml = summary.last_value_preview
    ? `<div class="mt-4">
        <div class="text-xs font-semibold text-slate-500 dark:text-slate-400 mb-1 flex items-center gap-1.5">
          <i class="fa-solid fa-eye text-indigo-400"></i> ${previewSource}
          <span class="font-normal text-[10px] text-slate-400">${previewHint}</span>
        </div>
        <pre class="text-[11px] bg-slate-50 dark:bg-slate-900/60 border border-slate-200 dark:border-slate-700/60 rounded-lg p-3 overflow-auto max-h-48 font-mono text-slate-700 dark:text-slate-200 whitespace-pre-wrap break-all">${escapeHtml(formatJsonPreview(summary.last_value_preview))}</pre>
      </div>`
    : `<div class="mt-4 p-3 rounded-lg border border-dashed border-slate-200 dark:border-slate-700/60 text-center text-xs text-slate-400">
        <i class="fa-solid fa-eye-slash mr-1"></i> ${
          captureEnabled
            ? "Value preview is enabled, but this key has no captured preview yet and a live lookup was not available."
            : "Value preview not available. Set CACHEER_MONITOR_CAPTURE_VALUES=true in your .env to enable it."
        }
      </div>`;

  const recentEventsHtml = events
    .slice(0, 15)
    .map((ev) => {
      const t = new Date((ev.ts || 0) * 1000).toLocaleTimeString();
      return `
      <div class="flex items-center gap-2 text-xs py-1 border-b border-slate-50 dark:border-slate-700/40">
        <span class="${badgeClass(ev.type)}">${escapeHtml(ev.type)}</span>
        <span class="text-slate-400 tabular-nums ml-auto">${t}</span>
      </div>`;
    })
    .join("");

  panelEl.innerHTML = `
    <div class="space-y-4">
      <div>
        <div class="flex items-start justify-between gap-3 mb-1">
          <div class="flex items-center gap-2 min-w-0">
            <i class="fa-solid fa-key text-amber-400 mt-0.5"></i>
            <span class="font-mono font-bold text-sm break-all">${escapeHtml(summary.key || "")}</span>
          </div>
          <span class="shrink-0 inline-flex items-center gap-1 rounded-full px-2 py-1 text-[10px] font-semibold ${isLive ? "bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300" : "bg-indigo-100 text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-300"}">
            ${isLive ? '<i class="fa-solid fa-bolt"></i> LIVE' : '<i class="fa-regular fa-clock"></i> EVENT'}
          </span>
        </div>
        <div class="flex flex-wrap gap-1 text-[11px] text-slate-500">${nsBadges || '<span class="italic">no namespace</span>'}</div>
      </div>

      <div class="grid grid-cols-2 gap-2">
        ${inspectorStat("Hits", formatNumber(summary.hits ?? 0), "fa-circle-check", "emerald")}
        ${inspectorStat("Misses", formatNumber(summary.misses ?? 0), "fa-circle-xmark", "rose")}
        ${inspectorStat("Writes", formatNumber(summary.puts ?? 0), "fa-box-archive", "amber")}
        ${inspectorStat("Hit Rate", hitRate, "fa-bullseye", "indigo")}
      </div>

      <div class="rounded-lg border border-slate-100 dark:border-slate-700/60 divide-y divide-slate-100 dark:divide-slate-700/60 text-xs">
        ${metaRow("Last written", lastPut)}
        ${metaRow("Last hit", lastHit)}
        ${metaRow("Last miss", lastMiss)}
        ${metaRow("Size", sizeStr)}
        ${metaRow("TTL", ttlStr)}
        ${metaRow("Value type", typeStr)}
      </div>

      ${previewHtml}

      <div>
        <div class="text-xs font-semibold text-slate-500 dark:text-slate-400 mb-2 flex items-center gap-1.5">
          <i class="fa-solid fa-list-ul text-blue-400"></i> Recent Events
          <span class="font-normal text-[10px]">(up to 15)</span>
        </div>
        <div class="rounded-lg border border-slate-100 dark:border-slate-700/60 overflow-hidden">
          ${recentEventsHtml || '<div class="text-center text-slate-400 py-4 text-xs">No events found</div>'}
        </div>
      </div>
    </div>`;
}

// [ ttl distribution ]

export function ttlDistributionChartData(ttlDistribution) {
  const labels = ["≤1 min", ">1 min", ">5 min", ">1 hour", ">1 day", "Forever"];
  const values = [
    ttlDistribution?.lte_1min ?? 0,
    ttlDistribution?.gt_1min ?? 0,
    ttlDistribution?.gt_5min ?? 0,
    ttlDistribution?.gt_1hour ?? 0,
    ttlDistribution?.gt_1day ?? 0,
    ttlDistribution?.forever ?? 0,
  ];
  return { labels, values };
}

// [ private helpers ]

function animateMetric(id, newValue) {
  const el = document.getElementById(id);
  if (!el) {
    return;
  }
  if (el.textContent === String(newValue)) {
    return;
  }
  el.classList.add("updating");
  el.textContent = String(newValue);
  requestAnimationFrame(() => requestAnimationFrame(() => el.classList.remove("updating")));
}

function setTextById(id, text) {
  const el = document.getElementById(id);
  if (el) {
    el.textContent = String(text);
  }
}

function formatNumber(val) {
  const n = Number(val) || 0;
  if (n >= 1_000_000) {
    return (n / 1_000_000).toFixed(1) + "M";
  }
  if (n >= 10_000) {
    return (n / 1_000).toFixed(1) + "K";
  }
  if (n >= 1_000) {
    return n.toLocaleString("en-US");
  }
  return String(n);
}

function formatBytes(bytes) {
  const b = Number(bytes) || 0;
  if (b >= 1_048_576) {
    return (b / 1_048_576).toFixed(1) + " MB";
  }
  if (b >= 1_024) {
    return (b / 1_024).toFixed(1) + " KB";
  }
  return b + " B";
}

function formatTtl(ttl) {
  const seconds = Number(ttl);
  if (!isFinite(seconds) || seconds >= Number.MAX_SAFE_INTEGER / 2) {
    return "Forever";
  }

  const units = [
    { label: "Year", secs: 31536000 },
    { label: "Month", secs: 2592000 },
    { label: "Week", secs: 604800 },
    { label: "Day", secs: 86400 },
    { label: "Hour", secs: 3600 },
    { label: "Minute", secs: 60 },
    { label: "Second", secs: 1 },
  ];

  for (const { label, secs } of units) {
    if (seconds >= secs) {
      const count = Math.round(seconds / secs);
      return `${count} ${label}${count !== 1 ? "s" : ""}`;
    }
  }

  return `${seconds} Seconds`;
}

function formatJsonPreview(raw) {
  try {
    return JSON.stringify(JSON.parse(raw), null, 2);
  } catch (_) {
    return raw;
  }
}

function isFiniteNumber(value) {
  return typeof value === "number" && Number.isFinite(value);
}

function formatPercent(value) {
  return ((Number(value) || 0) * 100).toFixed(1) + "%";
}

function progressColor(index) {
  const palette = ["#0ea5e9", "#22c55e", "#f59e0b", "#ef4444", "#8b5cf6", "#14b8a6", "#94a3b8"];
  return palette[index % palette.length];
}

function badgeClass(type) {
  const base = "inline-flex items-center px-2 py-0.5 rounded-md text-[10px] font-semibold tracking-wide";
  const map = {
    hit: `${base} bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300`,
    miss: `${base} bg-rose-100 text-rose-700 dark:bg-rose-900/40 dark:text-rose-300`,
    put: `${base} bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300`,
    put_forever: `${base} bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300`,
    flush: `${base} bg-sky-100 text-sky-700 dark:bg-sky-900/40 dark:text-sky-300`,
    renew: `${base} bg-teal-100 text-teal-700 dark:bg-teal-900/40 dark:text-teal-300`,
    clear: `${base} bg-fuchsia-100 text-fuchsia-700 dark:bg-fuchsia-900/40 dark:text-fuchsia-300`,
    tag: `${base} bg-indigo-100 text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-300`,
    flush_tag: `${base} bg-indigo-100 text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-300`,
    add: `${base} bg-lime-100 text-lime-700 dark:bg-lime-900/40 dark:text-lime-300`,
    error: `${base} bg-rose-200 text-rose-800 dark:bg-rose-900/60 dark:text-rose-200`,
  };
  return map[type] ?? `${base} bg-slate-100 text-slate-700 dark:bg-slate-700/40 dark:text-slate-300`;
}

function escapeHtml(value) {
  return String(value)
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#039;");
}

function inspectorStat(label, value, icon, color) {
  const colorMap = {
    emerald: "text-emerald-600 dark:text-emerald-400",
    rose: "text-rose-600 dark:text-rose-400",
    amber: "text-amber-600 dark:text-amber-400",
    indigo: "text-indigo-600 dark:text-indigo-400",
  };
  return `
    <div class="p-2.5 rounded-lg border border-slate-100 dark:border-slate-700/60 bg-white dark:bg-slate-800/60">
      <div class="text-[10px] text-slate-400 flex items-center gap-1">
        <i class="fa-solid ${icon} ${colorMap[color] || ""}"></i> ${label}
      </div>
      <div class="text-lg font-bold tabular-nums mt-0.5">${value}</div>
    </div>`;
}

function metaRow(label, value) {
  return `
    <div class="flex items-center justify-between px-3 py-2">
      <span class="text-slate-400 dark:text-slate-500">${label}</span>
      <span class="font-medium tabular-nums text-right max-w-[60%] truncate" title="${escapeHtml(value)}">${escapeHtml(value)}</span>
    </div>`;
}
