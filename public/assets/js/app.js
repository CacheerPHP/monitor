// Cacheer Monitor — application entry point
import {
  fetchConfig,
  fetchMetrics,
  fetchEvents,
  fetchKeyInspect,
  clearEventsFile,
  cleanupRotated,
  buildExportUrl,
} from "./api.js";
import { createDriversDoughnutChart, createLineChart, createBarChart } from "./charts.js";
import {
  updateStatusIndicator,
  updateConfigInfo,
  updateMetricCards,
  updateHitRateAlert,
  renderDriversList,
  renderTopKeysTable,
  renderNamespacesGrid,
  renderEventsStream,
  renderKeyInspector,
  ttlDistributionChartData,
} from "./renderers.js";

// [ state ]

const AppState = {
  refreshIntervalMs: 2000,
  refreshTimerId: null,
  driversChartInstance: null,
  ttlChartInstance: null,
  timeline: {
    hitsMissesChart: null,
    latencyChart: null,
  },
  isFirstLoad: true,
  timeFrom: null, // unix timestamp or null (no filter)
  timeUntil: null,
  inspectorKey: null,
  inspectorNamespace: null,
  inspectorLoading: false,
  hitRateThreshold: 0.5, // alert when hit rate drops below this
};

const el = (id) => document.getElementById(id);

function getNamespaceFilter() {
  return String(el("nsFilter")?.value || "").trim();
}

// [ theme ]

function syncThemeIcon() {
  const icon = el("themeIcon");
  const isDark = document.documentElement.classList.contains("dark");
  if (icon) {
    icon.className = isDark ? "fa-solid fa-sun text-xs" : "fa-solid fa-moon text-xs";
  }
}

function toggleTheme() {
  const html = document.documentElement;
  const toDark = !html.classList.contains("dark");
  html.classList.toggle("dark", toDark);
  html.style.colorScheme = toDark ? "dark" : "light";
  try {
    localStorage.setItem("cacheer-theme", toDark ? "dark" : "light");
  } catch (_) {}
  syncThemeIcon();
}

// [ loading ]

function showLoading() {
  if (!AppState.isFirstLoad) {
    return;
  }
  el("loadingState")?.classList.remove("hidden");
  el("statsGrid")?.classList.add("hidden");
}

function hideLoading() {
  el("loadingState")?.classList.add("hidden");
  el("statsGrid")?.classList.remove("hidden");
  AppState.isFirstLoad = false;
}

// [ time range ]

function setTimeRange(windowMinutes) {
  if (windowMinutes === null) {
    AppState.timeFrom = null;
    AppState.timeUntil = null;
  } else {
    const now = Date.now() / 1000;
    AppState.timeFrom = now - windowMinutes * 60;
    AppState.timeUntil = now;
  }

  document.querySelectorAll("[data-time-range]").forEach((btn) => {
    const isActive = btn.dataset.timeRange === String(windowMinutes);
    // Support both legacy class-toggle and new "active" pill approach
    btn.classList.toggle("active", isActive);
    btn.classList.toggle("bg-blue-600", isActive);
    btn.classList.toggle("text-white", isActive);
    btn.classList.toggle("border-blue-600", isActive);
    btn.classList.toggle("bg-white", !isActive);
    btn.classList.toggle("dark:bg-slate-800", !isActive);
    btn.classList.toggle("text-slate-600", !isActive);
    btn.classList.toggle("dark:text-slate-300", !isActive);
  });

  loadAndRenderMetrics();
  loadAndRenderEvents();
}

// [ data ]

async function loadAndRenderConfig() {
  try {
    updateConfigInfo(await fetchConfig());
  } catch (_) {}
}

function renderDrivers(metrics) {
  const driversMap = metrics?.drivers || {};
  const totalEvents = Object.values(driversMap).reduce((sum, n) => sum + Number(n), 0);

  const listEl = el("driversList");
  if (listEl) {
    renderDriversList(listEl, driversMap, totalEvents);
  }

  const totalEl = el("totalEvents");
  if (totalEl) {
    const total = metrics?.total_events ?? totalEvents;
    totalEl.textContent = Number(total).toLocaleString("en-US") + " events";
  }

  const canvas = el("driversChart");
  if (canvas?.getContext) {
    AppState.driversChartInstance?.destroy();
    AppState.driversChartInstance = createDriversDoughnutChart(
      canvas.getContext("2d"),
      Object.keys(driversMap),
      Object.values(driversMap),
    );
  }
}

function renderTtlChart(metrics) {
  const canvas = el("chartTtl");
  if (!canvas?.getContext) {
    return;
  }

  const { labels, values } = ttlDistributionChartData(metrics?.ttl_distribution || {});
  if (values.reduce((s, v) => s + v, 0) === 0) {
    return;
  }

  AppState.ttlChartInstance?.destroy();
  AppState.ttlChartInstance = createBarChart(canvas.getContext("2d"), labels, values, "#8b5cf6");
}

async function loadAndRenderMetrics() {
  try {
    const limit = Number(el("eventLimit")?.value || 500);
    const metrics = await fetchMetrics(getNamespaceFilter(), limit, AppState.timeFrom, AppState.timeUntil);

    updateMetricCards(metrics);
    updateHitRateAlert(metrics, AppState.hitRateThreshold);
    renderDrivers(metrics);
    renderTtlChart(metrics);

    const nsListEl = el("namespacesList");
    if (nsListEl) {
      renderNamespacesGrid(nsListEl, metrics?.namespaces || {});
    }

    const keysEl = el("topKeysBody");
    if (keysEl) {
      const filterText = String(el("filterKey")?.value || "");
      renderTopKeysTable(keysEl, metrics?.top_keys || {}, filterText, openKeyInspector);
    }

    updateStatusIndicator(true);
    hideLoading();
  } catch (_) {
    updateStatusIndicator(false);
    hideLoading();
  }
}

async function loadAndRenderEvents() {
  try {
    const limit = Number(el("eventLimit")?.value || 200);
    const namespaceFilter = getNamespaceFilter();
    const events = await fetchEvents(limit, namespaceFilter, AppState.timeFrom, AppState.timeUntil);

    const selectedType = String(el("typeFilter")?.value || "");
    const filterText = String(el("filterKey")?.value || "").toLowerCase();

    const filtered = events
      .filter((ev) => !selectedType || ev.type === selectedType)
      .filter(
        (ev) =>
          !filterText ||
          String(ev?.payload?.key || "")
            .toLowerCase()
            .includes(filterText),
      );

    const eventsEl = el("events");
    if (eventsEl) {
      const hasFilters = Boolean(selectedType || filterText || namespaceFilter || AppState.timeFrom !== null);
      const emptyTitle =
        events.length === 0 ? "No events yet" : hasFilters ? "No events match current filters" : "No events available";
      const emptyDetail =
        events.length === 0
          ? "Events will appear here as they stream in"
          : hasFilters
            ? "Try clearing key, type, namespace, or time-range filters."
            : "No recent events were returned for the selected limit.";
      renderEventsStream(eventsEl, filtered, openKeyInspector, { emptyTitle, emptyDetail });
    }

    updateTimelines(events);
  } catch (_) {}
}

// [ timelines ]

function bucketize(events, windowMinutes = 10, bucketSeconds = 30) {
  const now = Math.floor(Date.now() / 1000);
  const start = now - windowMinutes * 60;
  const bucketCount = Math.ceil((windowMinutes * 60) / bucketSeconds);

  const labels = [];
  const hits = new Array(bucketCount).fill(0);
  const misses = new Array(bucketCount).fill(0);
  const latSums = new Array(bucketCount).fill(null);
  const latCounts = new Array(bucketCount).fill(0);

  for (let i = 0; i < bucketCount; i++) {
    const t = start + i * bucketSeconds;
    labels.push(new Date(t * 1000).toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" }));
  }

  for (const ev of events) {
    const ts = Math.floor(ev.ts || 0);
    if (ts < start) {
      continue;
    }
    const idx = Math.min(bucketCount - 1, Math.max(0, Math.floor((ts - start) / bucketSeconds)));

    if (ev.type === "hit") {
      hits[idx]++;
    }
    if (ev.type === "miss") {
      misses[idx]++;
    }

    const d = ev?.payload?.duration_ms;
    if (typeof d === "number" && Number.isFinite(d)) {
      latSums[idx] = (latSums[idx] ?? 0) + d;
      latCounts[idx] += 1;
    }
  }

  const avgLatency = latSums.map((sum, i) => (sum === null || latCounts[i] === 0 ? null : sum / latCounts[i]));

  return { labels, hits, misses, avgLatency };
}

function updateTimelines(allEvents) {
  try {
    const { labels, hits, misses, avgLatency } = bucketize(allEvents);

    const ctx1 = el("chartHitsMisses");
    if (ctx1?.getContext) {
      AppState.timeline.hitsMissesChart?.destroy();
      AppState.timeline.hitsMissesChart = createLineChart(ctx1.getContext("2d"), labels, [
        {
          label: "Hits",
          data: hits,
          borderColor: "#10b981",
          backgroundColor: "rgba(16,185,129,0.1)",
          fill: true,
          tension: 0.3,
          spanGaps: true,
          pointRadius: 0,
          pointHoverRadius: 4,
        },
        {
          label: "Misses",
          data: misses,
          borderColor: "#ef4444",
          backgroundColor: "rgba(239,68,68,0.1)",
          fill: true,
          tension: 0.3,
          spanGaps: true,
          pointRadius: 0,
          pointHoverRadius: 4,
        },
      ]);
    }

    const ctx2 = el("chartLatency");
    if (ctx2?.getContext) {
      AppState.timeline.latencyChart?.destroy();
      AppState.timeline.latencyChart = createLineChart(
        ctx2.getContext("2d"),
        labels,
        [
          {
            label: "Avg Latency (ms)",
            data: avgLatency,
            borderColor: "#8b5cf6",
            backgroundColor: "rgba(139,92,246,0.1)",
            fill: true,
            tension: 0.3,
            spanGaps: true,
            pointRadius: 0,
            pointHoverRadius: 4,
          },
        ],
        { scales: { y: { beginAtZero: true } } },
      );
    }
  } catch (_) {}
}

// [ inspector ]

async function openKeyInspector(key, namespace = null) {
  return loadKeyInspector(key, namespace, false);
}

async function loadKeyInspector(key, namespace = null, forceLive = false) {
  AppState.inspectorKey = key;
  AppState.inspectorNamespace = namespace;
  AppState.inspectorLoading = true;

  const panel = el("inspectorPanel");
  const body = el("inspectorBody");
  const loader = el("inspectorLoader");
  const refreshBtn = el("btnRefreshInspector");
  const refreshIcon = el("refreshInspectorIcon");

  if (!panel) {
    return;
  }

  panel.classList.remove("translate-x-full");
  panel.classList.add("translate-x-0");

  const titleEl = el("inspectorTitle");
  if (titleEl) {
    titleEl.textContent = key;
  }
  if (body && !forceLive) {
    body.innerHTML = "";
  }
  loader?.classList.remove("hidden");
  if (refreshBtn) {
    refreshBtn.disabled = true;
  }
  refreshIcon?.classList.add("fa-spin");

  try {
    const data = await fetchKeyInspect(key, namespace, 100, forceLive);
    loader?.classList.add("hidden");
    if (body) {
      renderKeyInspector(body, data);
    }
  } catch (_) {
    loader?.classList.add("hidden");
    if (body) {
      body.innerHTML =
        '<div class="text-center text-rose-500 text-sm py-8"><i class="fa-solid fa-triangle-exclamation mr-2"></i>Failed to load key data</div>';
    }
  } finally {
    AppState.inspectorLoading = false;
    if (refreshBtn) {
      refreshBtn.disabled = false;
    }
    refreshIcon?.classList.remove("fa-spin");
  }
}

function closeKeyInspector() {
  const panel = el("inspectorPanel");
  if (panel) {
    panel.classList.add("translate-x-full");
    panel.classList.remove("translate-x-0");
  }
  AppState.inspectorKey = null;
  AppState.inspectorNamespace = null;
  AppState.inspectorLoading = false;
}

// [ export ]

function triggerExport(format) {
  const limit = Number(el("eventLimit")?.value || 0);
  const url = buildExportUrl(format, limit, getNamespaceFilter(), AppState.timeFrom, AppState.timeUntil);
  const a = document.createElement("a");
  a.href = url;
  a.download = `cacheer-events.${format}`;
  document.body.appendChild(a);
  a.click();
  document.body.removeChild(a);
}

// [ auto-refresh ]

function startAutoRefresh(select) {
  if (AppState.refreshTimerId) {
    clearInterval(AppState.refreshTimerId);
  }

  const val = String(select.value || "2000");
  try {
    localStorage.setItem("cacheer-refresh-rate", val);
  } catch (_) {}

  if (val === "off") {
    AppState.refreshIntervalMs = 0;
    AppState.refreshTimerId = null;
    return;
  }

  const ms = Number(val);
  AppState.refreshIntervalMs = ms > 0 ? ms : 2000;
  AppState.refreshTimerId = setInterval(() => {
    loadAndRenderMetrics();
    loadAndRenderEvents();
  }, AppState.refreshIntervalMs);
}

// [ listeners ]

function setupEventListeners() {
  // [ theme ]
  el("btnThemeToggle")?.addEventListener("click", toggleTheme);

  // [ refresh ]
  el("btnRefresh")?.addEventListener("click", () => {
    const icon = el("refreshIcon");
    icon?.classList.add("fa-spin");
    setTimeout(() => icon?.classList.remove("fa-spin"), 800);
    loadAndRenderMetrics();
    loadAndRenderEvents();
  });

  // [ clear + cleanup ]
  el("btnClear")?.addEventListener("click", async () => {
    if (!confirm("Clear events file? This will rotate the current log.")) {
      return;
    }
    const cleared = await clearEventsFile();
    if (cleared) {
      await loadAndRenderMetrics();
      await loadAndRenderEvents();
    }
  });
  el("btnCleanupRotated")?.addEventListener("click", async () => {
    const result = await cleanupRotated(7);
    const n = result?.deleted ?? 0;
    alert(`Cleanup complete: ${n} rotated file${n !== 1 ? "s" : ""} deleted (older than 7 days).`);
  });

  // [ auto-refresh rate ]
  const refreshRateSelect = el("refreshRate");
  if (refreshRateSelect) {
    try {
      const saved = localStorage.getItem("cacheer-refresh-rate");
      if (saved) {
        refreshRateSelect.value = saved;
      }
    } catch (_) {}
    refreshRateSelect.addEventListener("change", () => startAutoRefresh(refreshRateSelect));
    startAutoRefresh(refreshRateSelect);
  }

  // [ filters ]
  el("filterKey")?.addEventListener("input", () => {
    loadAndRenderMetrics();
    loadAndRenderEvents();
  });
  el("clearFilter")?.addEventListener("click", () => {
    const input = el("filterKey");
    if (input) {
      input.value = "";
    }
    loadAndRenderMetrics();
    loadAndRenderEvents();
  });
  el("typeFilter")?.addEventListener("change", () => loadAndRenderEvents());
  el("eventLimit")?.addEventListener("change", () => {
    loadAndRenderMetrics();
    loadAndRenderEvents();
  });
  el("nsFilter")?.addEventListener("input", () => {
    loadAndRenderMetrics();
    loadAndRenderEvents();
  });

  // [ copy path ]
  el("copyPath")?.addEventListener("click", async () => {
    const path = el("eventsFile")?.textContent || "";
    try {
      await navigator.clipboard.writeText(path);
      el("copyPath").innerHTML = '<i class="fa-solid fa-check"></i> Copied';
      setTimeout(() => {
        el("copyPath").innerHTML = '<i class="fa-regular fa-copy"></i> Copy';
      }, 1500);
    } catch (_) {}
  });

  // [ time range ]
  document.querySelectorAll("[data-time-range]").forEach((btn) => {
    btn.addEventListener("click", () => {
      const val = btn.dataset.timeRange;
      setTimeRange(val === "null" ? null : Number(val));
    });
  });

  // [ export ]
  el("btnExportJson")?.addEventListener("click", () => triggerExport("json"));
  el("btnExportCsv")?.addEventListener("click", () => triggerExport("csv"));

  // [ hit-rate threshold ]
  const thresholdInput = el("hitRateThreshold");
  if (thresholdInput) {
    thresholdInput.value = String(Math.round(AppState.hitRateThreshold * 100));
    thresholdInput.addEventListener("change", () => {
      const v = Math.min(100, Math.max(0, Number(thresholdInput.value) || 50));
      AppState.hitRateThreshold = v / 100;
      loadAndRenderMetrics();
    });
  }

  // [ inspector ]
  el("btnCloseInspector")?.addEventListener("click", closeKeyInspector);
  el("btnRefreshInspector")?.addEventListener("click", () => {
    if (!AppState.inspectorKey || AppState.inspectorLoading) {
      return;
    }
    loadKeyInspector(AppState.inspectorKey, AppState.inspectorNamespace, true);
  });
  el("inspectorBackdrop")?.addEventListener("click", closeKeyInspector);
  document.addEventListener("keydown", (e) => {
    if (e.key === "Escape" && AppState.inspectorKey !== null) {
      closeKeyInspector();
    }
  });
}

// [ bootstrap ]

async function bootstrap() {
  syncThemeIcon();
  showLoading();
  await loadAndRenderConfig();
  await loadAndRenderMetrics();
  await loadAndRenderEvents();
  setupEventListeners();

  if ("EventSource" in window) {
    try {
      const sse = new EventSource("/api/events/stream");
      sse.onmessage = () => {
        loadAndRenderEvents();
        loadAndRenderMetrics();
      };
      sse.addEventListener("ping", () => {});
      sse.onerror = () => sse.close();
    } catch (_) {}
  }
}

bootstrap();
