// Cacheer Monitor — application entry point
import { icon } from "./icons.js";
import { HealthRules, normalizeRules } from "./health-rules.js";
import { fetchConfig, fetchSnapshot, fetchKeyInspect, clearEventsFile, cleanupRotated, buildExportUrl } from "./api.js";
import { createDriversDoughnutChart, createLineChart, createBarChart } from "./charts.js";
import {
  updateStatusIndicator,
  updateConfigInfo,
  updateMetricCards,
  renderHealthWarnings,
  renderLifecycle,
  updateCoverage,
  renderDriversList,
  renderProblemKeysTable,
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
  timeWindowMinutes: null,
  inspectorKey: null,
  inspectorNamespace: null,
  inspectorDriver: null,
  inspectorLoading: false,
  healthRules: new HealthRules(),
  lastMetrics: null,
  lastEvents: [],
  lastTimeline: null,
  snapshotRequest: 0,
  connected: false,
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
    renderMetrics(AppState.lastMetrics, false);
    updateTimelines(AppState.lastTimeline);
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
  AppState.timeWindowMinutes = windowMinutes;
  AppState.healthRules.reset();
  updateTimeBounds();

  document.querySelectorAll("[data-time-range]").forEach((btn) => {
    const isActive = btn.dataset.timeRange === String(windowMinutes);
    btn.classList.toggle("active", isActive);
    btn.setAttribute("aria-pressed", String(isActive));
  });

  loadAndRenderSnapshot();
}

function updateTimeBounds() {
  if (AppState.timeWindowMinutes === null) {
    AppState.timeFrom = null;
    AppState.timeUntil = null;
  } else {
    const now = Date.now() / 1000;
    AppState.timeFrom = now - AppState.timeWindowMinutes * 60;
    AppState.timeUntil = now;
  }
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
  const hasMetadata = Number(metrics?.ttl_samples || 0) > 0;
  el("ttlEmpty")?.classList.toggle("hidden", hasMetadata);
  canvas.parentElement.classList.toggle("hidden", !hasMetadata);
  canvas.setAttribute(
    "aria-label",
    "Write expiry distribution: " + labels.map((label, index) => `${label}: ${values[index]}`).join(", "),
  );
  AppState.ttlChartInstance?.destroy();
  AppState.ttlChartInstance = createBarChart(canvas.getContext("2d"), labels, values);
}

function renderMetrics(metrics, evaluateHealth = true) {
  AppState.lastMetrics = metrics;
  updateMetricCards(metrics);
  if (evaluateHealth && AppState.connected) {
    renderHealthWarnings(AppState.healthRules.evaluate(metrics), (rule) => {
      AppState.healthRules.dismiss(rule);
      renderMetrics(AppState.lastMetrics);
      el("healthRuleSummary")?.focus();
    });
  }
  renderLifecycle(metrics?.lifecycle || {});
  renderDrivers(metrics);
  renderTtlChart(metrics);

  const nsListEl = el("namespacesList");
  if (nsListEl) {
    renderNamespacesGrid(
      nsListEl,
      metrics?.namespaces || {},
      metrics?.namespace_samples || 0,
      metrics?.total_events || 0,
    );
  }

  const keysEl = el("topKeysBody");
  if (keysEl) {
    const ranking = String(el("keyRanking")?.value || "misses");
    renderProblemKeysTable(keysEl, metrics?.problem_keys?.[ranking] || [], openKeyInspector, ranking);
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
}

// Single request that refreshes both metrics and the event stream from one
// events-file read. Used by the auto-refresh tick, manual refresh, filters,
// and (debounced) the SSE stream.
async function loadAndRenderSnapshot() {
  const request = ++AppState.snapshotRequest;
  updateTimeBounds();
  try {
    const limit = Number(el("eventLimit")?.value || 500);
    const { metrics, events, coverage, timeline } = await fetchSnapshot(
      getNamespaceFilter(),
      limit,
      AppState.timeFrom,
      AppState.timeUntil,
      String(el("filterKey")?.value || ""),
      String(el("typeFilter")?.value || ""),
    );
    if (request !== AppState.snapshotRequest) {
      return;
    }
    AppState.lastTimeline = timeline;
    AppState.connected = true;
    renderMetrics(metrics);
    renderEvents(events);
    updateCoverage(coverage);
    updateTimelines(timeline);
    updateStatusIndicator(true);
    hideLoading();
  } catch (_) {
    if (request !== AppState.snapshotRequest) {
      return;
    }
    updateStatusIndicator(false);
    AppState.connected = false;
    el("healthRuleStatus").textContent = "Waiting for a successful refresh";
    AppState.healthRules.reset();
    el("healthWarnings").replaceChildren();
    delete el("healthWarnings").dataset.signature;
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

function updateTimelines(timeline) {
  if (!timeline) {
    return;
  }
  try {
    const buckets = timeline.buckets || [];
    const labels = buckets.map((bucket) =>
      new Date(bucket.ts * 1000).toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" }),
    );
    const hits = buckets.map((bucket) => bucket.hits);
    const misses = buckets.map((bucket) => bucket.misses);
    const avgLatency = buckets.map((bucket) => bucket.avg_ms);
    const rangeLabel =
      AppState.timeWindowMinutes === null ? "Last 10 minutes" : `Last ${AppState.timeWindowMinutes} minutes`;
    el("timelineCaption").textContent = `${rangeLabel} · all recorded events in range`;
    el("chartHitsMisses").setAttribute("aria-label", `Cache hits and misses: ${rangeLabel}`);
    el("chartLatency").setAttribute("aria-label", `Average cache operation latency: ${rangeLabel}`);

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

async function openKeyInspector(key, namespace = null, driver = null) {
  return loadKeyInspector(key, namespace, false, driver);
}

async function loadKeyInspector(key, namespace = null, forceLive = false, driver = null) {
  const request = ++AppState.inspectorRequest;
  AppState.inspectorKey = key;
  AppState.inspectorNamespace = namespace;
  AppState.inspectorDriver = driver;
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
    const data = await fetchKeyInspect(key, namespace, 100, forceLive, driver);
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
  AppState.inspectorDriver = null;
  AppState.inspectorLoading = false;
}

// [ export ]

function triggerExport(format) {
  updateTimeBounds();
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
  el("typeFilter")?.addEventListener("change", () => loadAndRenderSnapshot());
  el("eventLimit")?.addEventListener("change", () => loadAndRenderSnapshot());
  el("nsFilter")?.addEventListener("input", () => {
    AppState.healthRules.reset();
    loadAndRenderSnapshot();
  });
  el("keyRanking")?.addEventListener("change", () => {
    if (AppState.lastMetrics) {
      renderMetrics(AppState.lastMetrics, false);
    }
  });
  document.querySelectorAll("[data-lifecycle]").forEach((button) => {
    button.addEventListener("click", () => {
      el("typeFilter").value = button.dataset.lifecycle;
      loadAndRenderSnapshot();
      el("eventsSection").scrollIntoView({ behavior: "smooth", block: "start" });
      el("typeFilter").focus({ preventScroll: true });
    });
  });

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

  setupHealthRules();

  // [ inspector ]
  el("btnCloseInspector")?.addEventListener("click", closeKeyInspector);
  el("btnRefreshInspector")?.addEventListener("click", () => {
    if (!AppState.inspectorKey || AppState.inspectorLoading) {
      return;
    }
    loadKeyInspector(AppState.inspectorKey, AppState.inspectorNamespace, true, AppState.inspectorDriver);
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
  try {
    AppState.healthRules.configure(normalizeRules(JSON.parse(localStorage.getItem("cacheer-health-rules") || "{}")));
  } catch (_) {}
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
      // EventSource reconnects automatically after timeout or network errors.
      // Catch up on every connection because the stream starts at the file tail.
      sse.onopen = () => scheduleSnapshotRefresh();
      sse.onerror = () => {
        el("streamStatus").textContent = "Stream reconnecting";
      };
      sse.addEventListener("open", () => {
        el("streamStatus").textContent = "Stream connected";
      });
    } catch (_) {}
  }
}

bootstrap();

function setupHealthRules() {
  const fields = {
    hitRateEnabled: "ruleHitRate",
    hitRateThreshold: "hitRateThreshold",
    errorsEnabled: "ruleErrors",
    errorCount: "ruleErrorCount",
    latencyEnabled: "ruleLatency",
    latencyMs: "ruleLatencyMs",
    minSamples: "ruleMinSamples",
    holdSeconds: "ruleHoldSeconds",
    cooldownSeconds: "ruleCooldownSeconds",
  };
  function syncFields() {
    for (const [key, id] of Object.entries(fields)) {
      const field = el(id);
      if (field.type === "checkbox") {
        field.checked = AppState.healthRules.settings[key];
      } else {
        field.value = String(AppState.healthRules.settings[key]);
      }
    }
  }
  syncFields();
  for (const id of Object.values(fields)) {
    el(id).addEventListener("change", () => {
      const settings = Object.fromEntries(
        Object.entries(fields).map(([key, fieldId]) => {
          const field = el(fieldId);
          return [key, field.type === "checkbox" ? field.checked : field.value === "" ? NaN : Number(field.value)];
        }),
      );
      AppState.healthRules.configure(settings);
      syncFields();
      try {
        localStorage.setItem("cacheer-health-rules", JSON.stringify(AppState.healthRules.settings));
      } catch (_) {}
      if (AppState.lastMetrics) {
        renderMetrics(AppState.lastMetrics);
      }
    });
  }
}
