// Orchestrator for Cacheer Monitor UI
import { fetchConfig, fetchMetrics, fetchEvents, clearEventsFile } from './api.js';
import { createDriversDoughnutChart, createLineChart } from './charts.js';
import { updateStatusIndicator, updateConfigInfo, updateMetricCards, renderDriversList, renderTopKeysTable, renderNamespacesGrid, renderEventsStream } from './renderers.js';

const AppState = {
  refreshIntervalMs: 2000,
  refreshTimerId: null,
  driversChartInstance: null,
  timeline: {
    hitsMissesChart: null,
    latencyChart: null,
  },
  isFirstLoad: true,
};

function getElementById(id) {
  return document.getElementById(id);
}

function getNamespaceFilter() {
  const input = getElementById('nsFilter');
  return input ? String(input.value || '').trim() : '';
}

// --- Theme toggle ---
// Theme class is set synchronously via inline <script> in <head> to avoid FOUC.
// This module only handles toggling + icon sync.

function syncThemeIcon() {
  const icon = getElementById('themeIcon');
  if (!icon) return;
  const isDark = document.documentElement.classList.contains('dark');
  icon.className = isDark ? 'fa-solid fa-sun text-xs' : 'fa-solid fa-moon text-xs';
}

function toggleTheme() {
  const html = document.documentElement;
  if (html.classList.contains('dark')) {
    html.classList.remove('dark');
    html.style.colorScheme = 'light';
    try { localStorage.setItem('cacheer-theme', 'light'); } catch (e) { /* ignore */ }
  } else {
    html.classList.add('dark');
    html.style.colorScheme = 'dark';
    try { localStorage.setItem('cacheer-theme', 'dark'); } catch (e) { /* ignore */ }
  }
  syncThemeIcon();
}

// --- Loading state ---

function showLoading() {
  const loading = getElementById('loadingState');
  const stats = getElementById('statsGrid');
  if (loading && stats && AppState.isFirstLoad) {
    loading.classList.remove('hidden');
    stats.classList.add('hidden');
  }
}

function hideLoading() {
  const loading = getElementById('loadingState');
  const stats = getElementById('statsGrid');
  if (loading && stats) {
    loading.classList.add('hidden');
    stats.classList.remove('hidden');
    AppState.isFirstLoad = false;
  }
}

// --- Data loading ---

async function loadAndRenderConfig() {
  try {
    const config = await fetchConfig();
    updateConfigInfo(config);
  } catch (error) {
    // Silent fail for config
  }
}

function renderDrivers(metrics) {
  const driversMap = metrics?.drivers || {};
  const totalEvents = Object.values(driversMap).reduce((sum, count) => sum + Number(count), 0);
  const driversListContainer = getElementById('driversList');
  if (driversListContainer) {
    renderDriversList(driversListContainer, driversMap, totalEvents);
  }
  const totalEventsElement = getElementById('totalEvents');
  if (totalEventsElement) {
    const totalFromStats = metrics?.total_events ?? totalEvents;
    totalEventsElement.textContent = Number(totalFromStats).toLocaleString('en-US') + ' events';
  }
  const canvas = document.getElementById('driversChart');
  if (canvas && canvas.getContext) {
    if (AppState.driversChartInstance) {
      AppState.driversChartInstance.destroy();
    }
    const labels = Object.keys(driversMap);
    const values = Object.values(driversMap);
    AppState.driversChartInstance = createDriversDoughnutChart(canvas.getContext('2d'), labels, values);
  }
}

async function loadAndRenderMetrics() {
  try {
    const namespaceFilter = getNamespaceFilter();
    const limitSelect = getElementById('eventLimit');
    const metricsLimit = limitSelect ? Number(limitSelect.value || 500) : 500;
    const metrics = await fetchMetrics(namespaceFilter, metricsLimit);
    updateMetricCards(metrics);
    renderDrivers(metrics);
    const namespacesListContainer = getElementById('namespacesList');
    if (namespacesListContainer) {
      renderNamespacesGrid(namespacesListContainer, metrics?.namespaces || {});
    }
    const topKeysTableBody = getElementById('topKeysBody');
    if (topKeysTableBody) {
      const filterInput = getElementById('filterKey');
      const filterText = filterInput ? String(filterInput.value || '') : '';
      renderTopKeysTable(topKeysTableBody, metrics?.top_keys || {}, filterText);
    }
    updateStatusIndicator(true);
    hideLoading();
  } catch (error) {
    updateStatusIndicator(false);
    hideLoading();
  }
}

async function loadAndRenderEvents() {
  try {
    const limitSelect = getElementById('eventLimit');
    const limit = limitSelect ? Number(limitSelect.value || 200) : 200;
    const namespaceFilter = getNamespaceFilter();
    const events = await fetchEvents(limit, namespaceFilter);
    const typeFilterSelect = getElementById('typeFilter');
    const selectedType = typeFilterSelect ? String(typeFilterSelect.value || '') : '';
    const filterInput = getElementById('filterKey');
    const filterText = filterInput ? String(filterInput.value || '').toLowerCase() : '';
    const filteredEvents = events
      .filter((ev) => !selectedType || ev.type === selectedType)
      .filter((ev) => {
        const key = String(ev?.payload?.key || '').toLowerCase();
        return !filterText || key.includes(filterText);
      });
    const eventsContainer = getElementById('events');
    if (eventsContainer) {
      renderEventsStream(eventsContainer, filteredEvents);
    }
    updateTimelines(events);
  } catch (error) {
    // Ignore transient fetch errors
  }
}

function bucketize(events, windowMinutes = 10, bucketSeconds = 30) {
  const now = Math.floor(Date.now() / 1000);
  const start = now - windowMinutes * 60;
  const bucketCount = Math.ceil(windowMinutes * 60 / bucketSeconds);
  const labels = [];
  const hits = new Array(bucketCount).fill(0);
  const misses = new Array(bucketCount).fill(0);
  const latencies = new Array(bucketCount).fill(null);
  const latencyCounts = new Array(bucketCount).fill(0);

  for (let i = 0; i < bucketCount; i++) {
    const t = start + i * bucketSeconds;
    labels.push(new Date(t * 1000).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }));
  }
  for (const ev of events) {
    const ts = Math.floor((ev.ts || 0));
    if (ts < start) continue;
    const idx = Math.min(labels.length - 1, Math.max(0, Math.floor((ts - start) / bucketSeconds)));
    if (ev.type === 'hit') hits[idx] += 1;
    if (ev.type === 'miss') misses[idx] += 1;
    const d = ev?.payload?.duration_ms;
    if (typeof d === 'number' && Number.isFinite(d)) {
      latencies[idx] = (latencies[idx] ?? 0) + d;
      latencyCounts[idx] += 1;
    }
  }
  const avgLatency = latencies.map((sum, i) => {
    if (sum === null || latencyCounts[i] === 0) return null;
    return sum / latencyCounts[i];
  });
  return { labels, hits, misses, avgLatency };
}

function updateTimelines(allEvents) {
  try {
    const { labels, hits, misses, avgLatency } = bucketize(allEvents);
    const ctx1 = document.getElementById('chartHitsMisses');
    if (ctx1 && ctx1.getContext) {
      if (AppState.timeline.hitsMissesChart) AppState.timeline.hitsMissesChart.destroy();
      AppState.timeline.hitsMissesChart = createLineChart(ctx1.getContext('2d'), labels, [
        { label: 'Hits', data: hits, borderColor: '#10b981', backgroundColor: 'rgba(16,185,129,0.1)', fill: true, tension: 0.3, spanGaps: true, pointRadius: 0, pointHoverRadius: 4 },
        { label: 'Misses', data: misses, borderColor: '#ef4444', backgroundColor: 'rgba(239,68,68,0.1)', fill: true, tension: 0.3, spanGaps: true, pointRadius: 0, pointHoverRadius: 4 },
      ]);
    }
    const ctx2 = document.getElementById('chartLatency');
    if (ctx2 && ctx2.getContext) {
      if (AppState.timeline.latencyChart) AppState.timeline.latencyChart.destroy();
      AppState.timeline.latencyChart = createLineChart(ctx2.getContext('2d'), labels, [
        { label: 'Avg Latency (ms)', data: avgLatency, borderColor: '#8b5cf6', backgroundColor: 'rgba(139,92,246,0.1)', fill: true, tension: 0.3, spanGaps: true, pointRadius: 0, pointHoverRadius: 4 },
      ], { scales: { y: { beginAtZero: true } } });
    }
  } catch (e) { /* noop */ }
}

function setupEventListeners() {
  // Theme toggle
  const themeBtn = getElementById('btnThemeToggle');
  if (themeBtn) {
    themeBtn.addEventListener('click', toggleTheme);
  }

  // Refresh button with spin animation
  const refreshButton = getElementById('btnRefresh');
  if (refreshButton) {
    refreshButton.addEventListener('click', () => {
      const icon = getElementById('refreshIcon');
      if (icon) {
        icon.classList.add('fa-spin');
        setTimeout(() => icon.classList.remove('fa-spin'), 800);
      }
      void loadAndRenderMetrics();
      void loadAndRenderEvents();
    });
  }

  const clearButton = getElementById('btnClear');
  if (clearButton) {
    clearButton.addEventListener('click', async () => {
      const confirmed = confirm('Clear events file? This will rotate the current log.');
      if (!confirmed) return;
      const cleared = await clearEventsFile();
      if (cleared) {
        await loadAndRenderMetrics();
        await loadAndRenderEvents();
      }
    });
  }

  const refreshRateSelect = getElementById('refreshRate');
  if (refreshRateSelect) {
    try {
      const saved = localStorage.getItem('cacheer-refresh-rate');
      if (saved) refreshRateSelect.value = String(saved);
    } catch (error) { /* ignore */ }

    const restartTimer = () => {
      if (AppState.refreshTimerId) clearInterval(AppState.refreshTimerId);
      const val = String(refreshRateSelect.value || '2000');
      try { localStorage.setItem('cacheer-refresh-rate', val); } catch (e) { /* ignore */ }
      if (val === 'off') {
        AppState.refreshIntervalMs = 0;
        AppState.refreshTimerId = null;
        return;
      }
      const ms = Number(val);
      AppState.refreshIntervalMs = ms > 0 ? ms : 2000;
      AppState.refreshTimerId = setInterval(() => {
        void loadAndRenderMetrics();
        void loadAndRenderEvents();
      }, AppState.refreshIntervalMs);
    };
    refreshRateSelect.addEventListener('change', restartTimer);
    restartTimer();
  }

  const filterKeyInput = getElementById('filterKey');
  if (filterKeyInput) {
    filterKeyInput.addEventListener('input', () => {
      void loadAndRenderMetrics();
      void loadAndRenderEvents();
    });
  }

  const clearFilterButton = getElementById('clearFilter');
  if (clearFilterButton) {
    clearFilterButton.addEventListener('click', () => {
      const input = getElementById('filterKey');
      if (input) input.value = '';
      void loadAndRenderMetrics();
      void loadAndRenderEvents();
    });
  }

  const typeFilterSelect = getElementById('typeFilter');
  if (typeFilterSelect) {
    typeFilterSelect.addEventListener('change', () => void loadAndRenderEvents());
  }

  const eventLimitSelect = getElementById('eventLimit');
  if (eventLimitSelect) {
    eventLimitSelect.addEventListener('change', () => void loadAndRenderEvents());
  }

  const namespaceFilterInput = getElementById('nsFilter');
  if (namespaceFilterInput) {
    namespaceFilterInput.addEventListener('input', () => {
      void loadAndRenderMetrics();
      void loadAndRenderEvents();
    });
  }

  const copyPathButton = getElementById('copyPath');
  if (copyPathButton) {
    copyPathButton.addEventListener('click', async () => {
      const pathElement = getElementById('eventsFile');
      const filePath = pathElement ? pathElement.textContent : '';
      try {
        await navigator.clipboard.writeText(filePath || '');
        copyPathButton.innerHTML = '<i class="fa-solid fa-check"></i> Copied';
        setTimeout(() => { copyPathButton.innerHTML = '<i class="fa-regular fa-copy"></i> Copy'; }, 1500);
      } catch (error) { /* ignore */ }
    });
  }
}

async function bootstrap() {
  syncThemeIcon();
  showLoading();
  await loadAndRenderConfig();
  await loadAndRenderMetrics();
  await loadAndRenderEvents();
  setupEventListeners();

  // SSE live updates
  try {
    if ('EventSource' in window) {
      const es = new EventSource('/api/events/stream');
      es.onmessage = function () { void loadAndRenderEvents(); void loadAndRenderMetrics(); };
      es.addEventListener('ping', function () { /* heartbeat */ });
      es.onerror = function () { es.close(); };
    }
  } catch (e) { /* noop */ }
}

bootstrap();
