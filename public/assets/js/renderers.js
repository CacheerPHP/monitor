// Render helpers for Cacheer Monitor

export function updateStatusIndicator(isOk) {
  const statusDotElement = document.getElementById('statusIndicator');
  if (!statusDotElement) {
    return;
  }
  statusDotElement.className = 'w-2.5 h-2.5 rounded-full ' + (isOk ? 'bg-emerald-500' : 'bg-rose-500');
  const lastUpdatedElement = document.getElementById('lastUpdated');
  if (lastUpdatedElement) {
    lastUpdatedElement.textContent = new Date().toLocaleTimeString();
  }
}

export function updateConfigInfo(config) {
  const eventsFileElement = document.getElementById('eventsFile');
  const originElement = document.getElementById('origin');
  if (eventsFileElement) {
    eventsFileElement.textContent = config?.events_file || '—';
  }
  if (originElement) {
    originElement.textContent = 'origin: ' + (config?.origin || 'default');
  }
}

export function updateMetricCards(metrics) {
  setTextById('hits', metrics?.hits ?? 0);
  setTextById('misses', metrics?.misses ?? 0);
  setTextById('puts', metrics?.puts ?? 0);
  setTextById('flushes', metrics?.flushes ?? 0);
  setTextById('renews', metrics?.renews ?? 0);
  setTextById('clears', metrics?.clears ?? 0);
  setTextById('errors', metrics?.errors ?? 0);
  setTextById('hit_rate', formatPercent(metrics?.hit_rate ?? 0));
  if (metrics?.since) {
    const sinceDate = new Date(metrics.since * 1000);
    setTextById('since', 'since: ' + sinceDate.toLocaleString());
  }
  const latency = metrics?.latency || {};
  const averageLatency = latency.avg_ms;
  const p95Latency = latency.p95_ms;
  const p99Latency = latency.p99_ms;
  setTextById('lat_avg', isFiniteNumber(averageLatency) ? averageLatency.toFixed(1) + ' ms' : '—');
  setTextById('lat_p95', isFiniteNumber(p95Latency) ? p95Latency.toFixed(1) + ' ms' : '—');
  setTextById('lat_p99', isFiniteNumber(p99Latency) ? p99Latency.toFixed(1) + ' ms' : '—');
}

export function renderDriversList(containerElement, driversMap, totalCount) {
  containerElement.innerHTML = '';
  const driverEntries = Object.entries(driversMap || {});
  if (driverEntries.length === 0) {
    containerElement.innerHTML = '<div class="text-xs text-slate-500">No driver data.</div>';
    return;
  }
  driverEntries.forEach(([driverName, driverCount], index) => {
    const percentage = totalCount ? Math.round((driverCount / totalCount) * 100) : 0;
    const row = document.createElement('div');
    row.className = 'text-xs';
    row.innerHTML = `
      <div class="flex items-center justify-between">
        <span class="font-medium">${escapeHtml(driverName)}</span>
        <span class="text-slate-500">${driverCount} · ${percentage}%</span>
      </div>
      <div class="w-full h-2 bg-slate-100 dark:bg-slate-700 rounded mt-1">
        <div class="h-2 rounded" style="width:${percentage}%; background:${progressColor(index)}"></div>
      </div>`;
    containerElement.appendChild(row);
  });
}

export function renderTopKeysTable(tbodyElement, topKeysMap, filterText) {
  tbodyElement.innerHTML = '';
  const keyEntries = Object.entries(topKeysMap || {});
  keyEntries
    .filter(([keyName]) => {
      if (!filterText) {
        return true;
      }
      return keyName.toLowerCase().includes(filterText.toLowerCase());
    })
    .forEach(([keyName, keyCount]) => {
      const row = document.createElement('tr');
      row.innerHTML = `<td class="py-1 pr-2 truncate max-w-[40ch]"><span class="font-mono">${escapeHtml(keyName)}</span></td><td class="py-1 text-right">${keyCount}</td>`;
      tbodyElement.appendChild(row);
    });
}

export function renderNamespacesGrid(containerElement, namespaceMap) {
  containerElement.innerHTML = '';
  const namespaceEntries = Object.entries(namespaceMap || {});
  if (namespaceEntries.length === 0) {
    containerElement.innerHTML = '<div class="text-xs text-slate-500">No namespace data.</div>';
    return;
  }
  namespaceEntries.forEach(([namespaceName, namespaceCount]) => {
    const card = document.createElement('div');
    card.className = 'p-3 rounded border border-slate-200 dark:border-slate-700';
    card.innerHTML = `<div class="flex items-center justify-between text-xs"><span class="font-mono">${escapeHtml(namespaceName)}</span><span class="text-slate-500">${namespaceCount}</span></div>`;
    containerElement.appendChild(card);
  });
}

export function renderEventsStream(containerElement, eventsList) {
  containerElement.innerHTML = '';
  const items = (eventsList || []).slice().reverse();
  for (const eventItem of items) {
    const row = document.createElement('div');
    row.className = 'text-xs border-b border-slate-100 dark:border-slate-800 py-1';
    const timestamp = new Date((eventItem.ts || 0) * 1000).toLocaleTimeString();
    const keyText = eventItem.payload?.key ? `<span class="font-mono">${escapeHtml(eventItem.payload.key)}</span>` : '';
    const namespaceText = eventItem.payload?.namespace ? `<span class="ml-1 text-slate-500">[${escapeHtml(eventItem.payload.namespace)}]</span>` : '';
    const driverText = eventItem.payload?.driver || 'unknown';
    const duration = eventItem.payload?.duration_ms;
    const durationText = isFiniteNumber(duration) ? `<span><i class=\"fa-regular fa-clock mr-1\"></i>${Number(duration).toFixed(1)} ms</span>` : '';
    const ttl = eventItem.payload?.ttl ? `<span><i class=\"fa-regular fa-hourglass-half mr-1\"></i>ttl: ${String(eventItem.payload.ttl)}</span>` : '';
    const sizeBytes = eventItem.payload?.size_bytes ? `<span><i class=\"fa-regular fa-file-lines mr-1\"></i>${String(eventItem.payload.size_bytes)} bytes</span>` : '';
    row.innerHTML = `
      <div class="flex items-center justify-between">
        <div class="flex items-center gap-2">
          <span class="${badgeClass(eventItem.type)}">${escapeHtml(eventItem.type)}</span>
          <span>${keyText}${namespaceText}</span>
        </div>
        <div class="text-slate-500">${timestamp}</div>
      </div>
      <div class="flex items-center gap-3 text-slate-500 mt-0.5">
        <span><i class="fa-solid fa-server mr-1"></i>${escapeHtml(driverText)}</span>
        ${durationText}
        ${ttl}
        ${sizeBytes}
      </div>`;
    containerElement.appendChild(row);
  }
}

function setTextById(elementId, text) {
  const element = document.getElementById(elementId);
  if (element) {
    element.textContent = String(text);
  }
}

function isFiniteNumber(value) {
  return typeof value === 'number' && Number.isFinite(value);
}

function formatPercent(value) {
  const percentage = (Number(value) || 0) * 100;
  return percentage.toFixed(1) + '%';
}

function progressColor(index) {
  const palette = ['#0ea5e9', '#22c55e', '#f59e0b', '#ef4444', '#8b5cf6', '#14b8a6', '#94a3b8'];
  return palette[index % palette.length];
}

function badgeClass(type) {
  const base = 'inline-flex items-center px-2 py-0.5 rounded text-[10px] font-medium';
  switch (type) {
    case 'hit': return `${base} bg-emerald-100 text-emerald-700`;
    case 'miss': return `${base} bg-rose-100 text-rose-700`;
    case 'put':
    case 'put_forever':
      return `${base} bg-amber-100 text-amber-700`;
    case 'flush': return `${base} bg-sky-100 text-sky-700`;
    case 'renew': return `${base} bg-teal-100 text-teal-700`;
    case 'clear': return `${base} bg-fuchsia-100 text-fuchsia-700`;
    case 'tag':
    case 'flush_tag':
      return `${base} bg-indigo-100 text-indigo-700`;
    case 'error': return `${base} bg-rose-200 text-rose-800`;
    default: return `${base} bg-slate-100 text-slate-700`;
  }
}

function escapeHtml(value) {
  return String(value)
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;');
}

