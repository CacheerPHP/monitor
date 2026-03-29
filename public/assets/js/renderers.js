// Render helpers for Cacheer Monitor

export function updateStatusIndicator(isOk) {
  const dot = document.getElementById('statusIndicator');
  if (dot) {
    dot.className = 'w-2 h-2 rounded-full status-pulse ' + (isOk ? 'bg-emerald-500 live' : 'bg-rose-500');
  }
  const ts = document.getElementById('lastUpdated');
  if (ts) {
    ts.textContent = new Date().toLocaleTimeString();
  }
}

export function updateConfigInfo(config) {
  const el = document.getElementById('eventsFile');
  if (el) el.textContent = config?.events_file || '--';
  const origin = document.getElementById('origin');
  if (origin) origin.textContent = 'origin: ' + (config?.origin || 'default');
}

export function updateMetricCards(metrics) {
  animateMetric('hits', formatNumber(metrics?.hits ?? 0));
  animateMetric('misses', formatNumber(metrics?.misses ?? 0));
  animateMetric('puts', formatNumber(metrics?.puts ?? 0));
  animateMetric('flushes', formatNumber(metrics?.flushes ?? 0));
  animateMetric('renews', formatNumber(metrics?.renews ?? 0));
  animateMetric('clears', formatNumber(metrics?.clears ?? 0));
  animateMetric('errors', formatNumber(metrics?.errors ?? 0));
  animateMetric('hit_rate', formatPercent(metrics?.hit_rate ?? 0));

  if (metrics?.since) {
    const sinceDate = new Date(metrics.since * 1000);
    setTextById('since', 'since: ' + sinceDate.toLocaleString());
  }

  const latency = metrics?.latency || {};
  animateMetric('lat_avg', isFiniteNumber(latency.avg_ms) ? latency.avg_ms.toFixed(1) + ' ms' : '--');
  setTextById('lat_p95', isFiniteNumber(latency.p95_ms) ? latency.p95_ms.toFixed(1) + ' ms' : '--');
  setTextById('lat_p99', isFiniteNumber(latency.p99_ms) ? latency.p99_ms.toFixed(1) + ' ms' : '--');
}

export function renderDriversList(containerElement, driversMap, totalCount) {
  containerElement.innerHTML = '';
  const entries = Object.entries(driversMap || {});
  if (entries.length === 0) {
    containerElement.innerHTML = '<div class="text-xs text-slate-400 dark:text-slate-500 text-center py-4"><i class="fa-solid fa-server mr-1"></i> No driver data</div>';
    return;
  }
  entries.forEach(([name, count], index) => {
    const pct = totalCount ? Math.round((count / totalCount) * 100) : 0;
    const row = document.createElement('div');
    row.className = 'text-xs';
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

export function renderTopKeysTable(tbodyElement, topKeysMap, filterText) {
  tbodyElement.innerHTML = '';
  const entries = Object.entries(topKeysMap || {});
  const filtered = entries.filter(([key]) => {
    if (!filterText) return true;
    return key.toLowerCase().includes(filterText.toLowerCase());
  });
  if (filtered.length === 0) {
    const row = document.createElement('tr');
    row.innerHTML = '<td colspan="2" class="py-4 text-center text-xs text-slate-400 dark:text-slate-500"><i class="fa-solid fa-key mr-1"></i> No keys found</td>';
    tbodyElement.appendChild(row);
    return;
  }
  filtered.forEach(([key, count]) => {
    const row = document.createElement('tr');
    row.className = 'border-b border-slate-50 dark:border-slate-700/40 hover:bg-slate-50 dark:hover:bg-slate-700/30 transition-colors';
    row.innerHTML = `<td class="py-1.5 pr-2 truncate max-w-[40ch]"><span class="font-mono text-xs">${escapeHtml(key)}</span></td><td class="py-1.5 text-right tabular-nums font-medium">${formatNumber(count)}</td>`;
    tbodyElement.appendChild(row);
  });
}

export function renderNamespacesGrid(containerElement, namespaceMap) {
  containerElement.innerHTML = '';
  const entries = Object.entries(namespaceMap || {});
  if (entries.length === 0) {
    containerElement.innerHTML = '<div class="text-xs text-slate-400 dark:text-slate-500 text-center py-4 col-span-full"><i class="fa-regular fa-folder-open mr-1"></i> No namespace data</div>';
    return;
  }
  entries.forEach(([name, count]) => {
    const card = document.createElement('div');
    card.className = 'p-3 rounded-lg border border-slate-100 dark:border-slate-700/60 bg-white dark:bg-slate-800/60 hover:border-slate-300 dark:hover:border-slate-600 transition-colors';
    card.innerHTML = `<div class="flex items-center justify-between text-xs"><span class="font-mono font-medium text-slate-700 dark:text-slate-200">${escapeHtml(name || '(default)')}</span><span class="tabular-nums text-slate-400 font-semibold">${formatNumber(count)}</span></div>`;
    containerElement.appendChild(card);
  });
}

export function renderEventsStream(containerElement, eventsList) {
  const emptyState = document.getElementById('eventsEmpty');
  const items = (eventsList || []).slice().reverse();

  if (items.length === 0) {
    containerElement.innerHTML = '';
    if (emptyState) {
      emptyState.classList.remove('hidden');
      containerElement.appendChild(emptyState);
    }
    return;
  }

  containerElement.innerHTML = '';
  if (emptyState) emptyState.classList.add('hidden');

  for (const ev of items) {
    const row = document.createElement('div');
    row.className = 'event-row text-xs px-5 py-2.5 border-b border-slate-50 dark:border-slate-700/40';
    const ts = new Date((ev.ts || 0) * 1000).toLocaleTimeString();
    const key = ev.payload?.key ? `<span class="font-mono font-medium text-slate-700 dark:text-slate-200">${escapeHtml(ev.payload.key)}</span>` : '';
    const ns = ev.payload?.namespace ? `<span class="ml-1.5 px-1.5 py-0.5 rounded bg-slate-100 dark:bg-slate-700/60 text-slate-500 dark:text-slate-400 text-[10px]">${escapeHtml(ev.payload.namespace)}</span>` : '';
    const driver = ev.payload?.driver || 'unknown';
    const dur = ev.payload?.duration_ms;
    const durText = isFiniteNumber(dur) ? `<span class="inline-flex items-center gap-1"><i class="fa-regular fa-clock"></i>${Number(dur).toFixed(1)} ms</span>` : '';
    const ttl = ev.payload?.ttl ? `<span class="inline-flex items-center gap-1"><i class="fa-regular fa-hourglass-half"></i>ttl: ${String(ev.payload.ttl)}</span>` : '';
    const size = ev.payload?.size_bytes ? `<span class="inline-flex items-center gap-1"><i class="fa-regular fa-file-lines"></i>${formatNumber(ev.payload.size_bytes)} B</span>` : '';
    row.innerHTML = `
      <div class="flex items-center justify-between gap-2">
        <div class="flex items-center gap-2 min-w-0">
          <span class="${badgeClass(ev.type)}">${escapeHtml(ev.type)}</span>
          <span class="truncate">${key}${ns}</span>
        </div>
        <span class="text-slate-400 dark:text-slate-500 tabular-nums whitespace-nowrap">${ts}</span>
      </div>
      <div class="flex items-center gap-3 text-slate-400 dark:text-slate-500 mt-1">
        <span class="inline-flex items-center gap-1"><i class="fa-solid fa-server"></i>${escapeHtml(driver)}</span>
        ${durText}${ttl}${size}
      </div>`;
    containerElement.appendChild(row);
  }
}

function animateMetric(id, newValue) {
  const el = document.getElementById(id);
  if (!el) return;
  const current = el.textContent;
  if (current === String(newValue)) return;
  el.classList.add('updating');
  el.textContent = String(newValue);
  requestAnimationFrame(() => {
    requestAnimationFrame(() => {
      el.classList.remove('updating');
    });
  });
}

function setTextById(id, text) {
  const el = document.getElementById(id);
  if (el) el.textContent = String(text);
}

function formatNumber(val) {
  const n = Number(val) || 0;
  if (n >= 1_000_000) return (n / 1_000_000).toFixed(1) + 'M';
  if (n >= 10_000) return (n / 1_000).toFixed(1) + 'K';
  if (n >= 1_000) return n.toLocaleString('en-US');
  return String(n);
}

function isFiniteNumber(value) {
  return typeof value === 'number' && Number.isFinite(value);
}

function formatPercent(value) {
  const pct = (Number(value) || 0) * 100;
  return pct.toFixed(1) + '%';
}

function progressColor(index) {
  const palette = ['#0ea5e9', '#22c55e', '#f59e0b', '#ef4444', '#8b5cf6', '#14b8a6', '#94a3b8'];
  return palette[index % palette.length];
}

function badgeClass(type) {
  const base = 'inline-flex items-center px-2 py-0.5 rounded-md text-[10px] font-semibold tracking-wide';
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
    error: `${base} bg-rose-200 text-rose-800 dark:bg-rose-900/60 dark:text-rose-200`,
  };
  return map[type] || `${base} bg-slate-100 text-slate-700 dark:bg-slate-700/40 dark:text-slate-300`;
}

function escapeHtml(value) {
  return String(value)
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;');
}
