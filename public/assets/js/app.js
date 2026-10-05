// Cacheer Monitor — application entry point
import { icon } from "./icons.js";
import {
  fetchConfig,
  fetchMetrics,
  fetchEvents,
  fetchSnapshot,
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
  snapshotRefreshTimerId: null, // coalesces bursts of SSE events into one refresh
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
  lastMetrics: null,
  lastEvents: [],
  inspectorRequest: 0,
  inspectorReturnFocus: null,
};

const el = (id) => document.getElementById(id);

function getNamespaceFilter() {
  return String(el("nsFilter")?.value || "").trim();
}

// [ theme ]

function syncThemeIcon() {
  const themeIcon = el("themeIcon");
  const isDark = document.documentElement.classList.contains("dark");
  if (themeIcon) {
    themeIcon.innerHTML = icon(isDark ? "sun" : "moon");
  }
  el("btnThemeToggle")?.setAttribute("aria-label", isDark ? "Switch to light theme" : "Switch to dark theme");
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
  if (AppState.lastMetrics) {
    renderMetrics(AppState.lastMetrics);
    updateTimelines(AppState.lastEvents);
  }
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
    btn.classList.toggle("active", isActive);
    btn.setAttribute("aria-pressed", String(isActive));
  });

  loadAndRenderSnapshot();
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
    canvas.setAttribute(
      "aria-label",
      Object.keys(driversMap).length
        ? "Events by storage driver: " +
            Object.entries(driversMap)
              .map(([name, count]) => `${name}: ${count}`)
              .join(", ")
        : "No storage driver activity recorded",
    );
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
  canvas.setAttribute(
    "aria-label",
    "Write expiry distribution: " + labels.map((label, index) => `${label}: ${values[index]}`).join(", "),
  );
  AppState.ttlChartInstance?.destroy();
  AppState.ttlChartInstance = createBarChart(canvas.getContext("2d"), labels, values);
}

function renderMetrics(metrics) {
  AppState.lastMetrics = metrics;
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
}

function renderEvents(events) {
  AppState.lastEvents = events;
  const namespaceFilter = getNamespaceFilter();
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
    const emptyTitle = hasFilters ? "No events match current filters" : "No events yet";
    const emptyDetail = hasFilters
      ? "Try clearing key, type, namespace, or time-range filters."
      : "Run your application to see cache operations here. Check the event source below if you expected activity.";
    renderEventsStream(eventsEl, filtered, openKeyInspector, { emptyTitle, emptyDetail });
  }

  updateTimelines(events);
}

async function loadAndRenderMetrics() {
  try {
    const limit = Number(el("eventLimit")?.value || 500);
    const metrics = await fetchMetrics(getNamespaceFilter(), limit, AppState.timeFrom, AppState.timeUntil);
    renderMetrics(metrics);
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
    const events = await fetchEvents(limit, getNamespaceFilter(), AppState.timeFrom, AppState.timeUntil);
    renderEvents(events);
  } catch (_) {}
}

// Single request that refreshes both metrics and the event stream from one
// events-file read. Used by the auto-refresh tick, manual refresh, filters,
// and (debounced) the SSE stream.
async function loadAndRenderSnapshot() {
  try {
    const limit = Number(el("eventLimit")?.value || 500);
    const { metrics, events } = await fetchSnapshot(getNamespaceFilter(), limit, AppState.timeFrom, AppState.timeUntil);
    renderMetrics(metrics);
    renderEvents(events);
    updateStatusIndicator(true);
    hideLoading();
  } catch (_) {
    updateStatusIndicator(false);
    hideLoading();
  }
}

// Coalesce a burst of stream events into at most one refresh per window, so a
// high-throughput cache can't trigger a fetch storm on the dashboard.
function scheduleSnapshotRefresh(delayMs = 300) {
  if (AppState.snapshotRefreshTimerId) {
    return;
  }
  AppState.snapshotRefreshTimerId = setTimeout(() => {
    AppState.snapshotRefreshTimerId = null;
    loadAndRenderSnapshot();
  }, delayMs);
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
        },
        {
          label: "Misses",
          data: misses,
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
  const request = ++AppState.inspectorRequest;
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

  if (!panel.classList.contains("is-open")) {
    AppState.inspectorReturnFocus = document.activeElement;
  }
  panel.inert = false;
  panel.setAttribute("aria-hidden", "false");
  panel.classList.add("is-open");
  el("inspectorBackdrop")?.classList.remove("hidden");
  document.body.classList.add("inspector-open");
  requestAnimationFrame(() => {
    if (request === AppState.inspectorRequest && panel.classList.contains("is-open")) {
      el("btnCloseInspector")?.focus();
    }
  });
  document.querySelector(".workspace").inert = true;
  document.querySelector(".sidebar").inert = true;

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
  refreshIcon?.classList.add("spinning");

  try {
    const data = await fetchKeyInspect(key, namespace, 100, forceLive);
    if (request !== AppState.inspectorRequest) {
      return;
    }
    loader?.classList.add("hidden");
    if (body) {
      renderKeyInspector(body, data);
    }
  } catch (_) {
    if (request !== AppState.inspectorRequest) {
      return;
    }
    loader?.classList.add("hidden");
    if (body) {
      body.innerHTML = `<div class="inspector-error">${icon("alert")} Unable to load key details. Try refreshing the inspector.</div>`;
    }
  } finally {
    if (request === AppState.inspectorRequest) {
      AppState.inspectorLoading = false;
      if (refreshBtn) {
        refreshBtn.disabled = false;
      }
      refreshIcon?.classList.remove("spinning");
    }
  }
}

function closeKeyInspector() {
  const panel = el("inspectorPanel");
  if (panel) {
    panel.classList.remove("is-open");
    panel.inert = true;
    panel.setAttribute("aria-hidden", "true");
  }
  el("inspectorBackdrop")?.classList.add("hidden");
  document.body.classList.remove("inspector-open");
  document.querySelector(".workspace").inert = false;
  document.querySelector(".sidebar").inert = false;
  if (AppState.inspectorReturnFocus?.isConnected) {
    AppState.inspectorReturnFocus.focus();
  } else {
    el("mainContent")?.focus();
  }
  AppState.inspectorRequest++;
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
    loadAndRenderSnapshot();
  }, AppState.refreshIntervalMs);
}

// [ listeners ]

function setupEventListeners() {
  // [ theme ]
  el("btnThemeToggle")?.addEventListener("click", toggleTheme);

  // [ refresh ]
  el("btnRefresh")?.addEventListener("click", () => {
    const icon = el("refreshIcon");
    icon?.classList.add("spinning");
    setTimeout(() => icon?.classList.remove("spinning"), 800);
    loadAndRenderSnapshot();
  });

  // [ clear + cleanup ]
  el("btnClear")?.addEventListener("click", async () => {
    if (!confirm("Clear events file? This will rotate the current log.")) {
      return;
    }
    const cleared = await clearEventsFile();
    if (cleared) {
      await loadAndRenderSnapshot();
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
  el("filterKey")?.addEventListener("input", () => loadAndRenderSnapshot());
  el("clearFilter")?.addEventListener("click", () => {
    const input = el("filterKey");
    if (input) {
      input.value = "";
    }
    loadAndRenderSnapshot();
  });
  el("typeFilter")?.addEventListener("change", () => loadAndRenderEvents());
  el("eventLimit")?.addEventListener("change", () => loadAndRenderSnapshot());
  el("nsFilter")?.addEventListener("input", () => loadAndRenderSnapshot());

  // [ copy path ]
  el("copyPath")?.addEventListener("click", async () => {
    const path = el("eventsFile")?.textContent || "";
    try {
      await navigator.clipboard.writeText(path);
      el("copyPath").innerHTML = icon("check") + " Copied";
      setTimeout(() => {
        el("copyPath").innerHTML = icon("copy") + " Copy";
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
      const entered = Number(thresholdInput.value);
      const v = Math.min(100, Math.max(0, Number.isFinite(entered) ? entered : 50));
      thresholdInput.value = String(v);
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
    if (e.key === "Tab" && AppState.inspectorKey !== null) {
      const controls = [
        ...el("inspectorPanel").querySelectorAll("button:not(:disabled), a[href], input, select, [tabindex='0']"),
      ];
      const first = controls[0];
      const last = controls[controls.length - 1];
      if (!el("inspectorPanel").contains(document.activeElement)) {
        e.preventDefault();
        first?.focus();
      } else if (e.shiftKey && document.activeElement === first) {
        e.preventDefault();
        last?.focus();
      } else if (!e.shiftKey && document.activeElement === last) {
        e.preventDefault();
        first?.focus();
      }
    }
  });
}

// [ bootstrap ]

async function bootstrap() {
  syncThemeIcon();
  showLoading();
  await loadAndRenderConfig();
  await loadAndRenderSnapshot();
  setupEventListeners();

  if ("EventSource" in window) {
    try {
      const sse = new EventSource("/api/events/stream");
      // The stream pushes one message per new event line; debounce so a burst
      // collapses into a single refresh instead of one fetch per event.
      sse.onmessage = () => scheduleSnapshotRefresh();
      sse.addEventListener("ping", () => {});
      sse.onerror = () => sse.close();
    } catch (_) {}
  }
}

bootstrap();
