// Orchestrator for Cacheer Monitor UI
import { fetchConfig, fetchMetrics, fetchEvents, clearEventsFile } from './api.js';
import { createDriversDoughnutChart } from './charts.js';
import { updateStatusIndicator, updateConfigInfo, updateMetricCards, renderDriversList, renderTopKeysTable, renderNamespacesGrid, renderEventsStream } from './renderers.js';

const AppState = {
  refreshIntervalMs: 2000,
  refreshTimerId: null,
  driversChartInstance: null,
};

function getElementById(id) {
  return document.getElementById(id);
}

function getNamespaceFilter() {
  const input = getElementById('nsFilter');
  return input ? String(input.value || '').trim() : '';
}

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
    totalEventsElement.textContent = 'events: ' + String(totalFromStats);
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
    const metrics = await fetchMetrics(namespaceFilter);
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
  } catch (error) {
    updateStatusIndicator(false);
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
      .filter((eventItem) => {
        if (!selectedType) {
          return true;
        }
        return eventItem.type === selectedType;
      })
      .filter((eventItem) => {
        const keyValue = String(eventItem?.payload?.key || '').toLowerCase();
        return !filterText || keyValue.includes(filterText);
      });
    const eventsContainer = getElementById('events');
    if (eventsContainer) {
      renderEventsStream(eventsContainer, filteredEvents);
    }
  } catch (error) {
    // Ignore transient fetch errors
  }
}

function setupEventListeners() {
  const refreshButton = getElementById('btnRefresh');
  if (refreshButton) {
    refreshButton.addEventListener('click', () => {
      void loadAndRenderMetrics();
      void loadAndRenderEvents();
    });
  }
  const clearButton = getElementById('btnClear');
  if (clearButton) {
    clearButton.addEventListener('click', async () => {
      const confirmed = confirm('Clear events file? This will rotate the current log.');
      if (!confirmed) {
        return;
      }
      const cleared = await clearEventsFile();
      if (cleared) {
        await loadAndRenderMetrics();
        await loadAndRenderEvents();
      }
    });
  }
  const refreshRateSelect = getElementById('refreshRate');
  if (refreshRateSelect) {
    // Load saved refresh rate preference
    try {
      const savedRefreshRate = localStorage.getItem('cacheer-refresh-rate');
      if (savedRefreshRate) {
        refreshRateSelect.value = String(savedRefreshRate);
      }
    } catch (error) {
      // ignore read errors
    }

    const restartTimer = () => {
      if (AppState.refreshTimerId) {
        clearInterval(AppState.refreshTimerId);
      }
      const selectedValue = String(refreshRateSelect.value || '2000');
      // Persist preference
      try {
        localStorage.setItem('cacheer-refresh-rate', selectedValue);
      } catch (error) {
        // ignore write errors
      }
      if (selectedValue === 'off') {
        AppState.refreshIntervalMs = 0;
        AppState.refreshTimerId = null;
        return;
      }
      const intervalMs = Number(selectedValue);
      AppState.refreshIntervalMs = intervalMs > 0 ? intervalMs : 2000;
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
      if (input) {
        input.value = '';
      }
      void loadAndRenderMetrics();
      void loadAndRenderEvents();
    });
  }
  const typeFilterSelect = getElementById('typeFilter');
  if (typeFilterSelect) {
    typeFilterSelect.addEventListener('change', () => {
      void loadAndRenderEvents();
    });
  }
  const eventLimitSelect = getElementById('eventLimit');
  if (eventLimitSelect) {
    eventLimitSelect.addEventListener('change', () => {
      void loadAndRenderEvents();
    });
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
      } catch (error) {
        // ignore copy failures
      }
    });
  }
  const toggleThemeButton = getElementById('toggleTheme');
  if (toggleThemeButton) {
    const prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
    const savedTheme = localStorage.getItem('cacheer-theme');
    if (savedTheme === 'dark' || (!savedTheme && prefersDark)) {
      document.documentElement.classList.add('dark');
    }
    toggleThemeButton.addEventListener('click', () => {
      document.documentElement.classList.toggle('dark');
      const isDark = document.documentElement.classList.contains('dark');
      localStorage.setItem('cacheer-theme', isDark ? 'dark' : 'light');
    });
  }
}

async function bootstrap() {
  await loadAndRenderConfig();
  await loadAndRenderMetrics();
  await loadAndRenderEvents();
  setupEventListeners();
}

bootstrap();
