// API client for Cacheer Monitor

export async function fetchConfig() {
  const response = await fetch('/api/config', { cache: 'no-store' });
  if (!response.ok) {
    throw new Error('Failed to load config');
  }
  return await response.json();
}

export async function fetchMetrics(namespaceFilter = '', limit = 1000) {
  const params = new URLSearchParams();
  if (namespaceFilter) {
    params.set('namespace', namespaceFilter);
  }
  if (typeof limit === 'number' && isFinite(limit) && limit > 0) {
    params.set('limit', String(limit));
  }
  const query = params.toString() ? `?${params.toString()}` : '';
  const response = await fetch(`/api/metrics${query}`, { cache: 'no-store' });
  if (!response.ok) {
    throw new Error('Failed to load metrics');
  }
  return await response.json();
}

export async function fetchEvents(limit = 200, namespaceFilter = '') {
  const params = new URLSearchParams();
  params.set('limit', String(limit));
  if (namespaceFilter) {
    params.set('namespace', namespaceFilter);
  }
  const response = await fetch(`/api/events?${params.toString()}`, { cache: 'no-store' });
  if (!response.ok) {
    throw new Error('Failed to load events');
  }
  return await response.json();
}

export async function clearEventsFile() {
  const headers = {};
  try {
    const savedToken = localStorage.getItem('cacheer-token');
    if (savedToken) {
      headers['X-Monitor-Token'] = savedToken;
    }
  } catch (e) { /* noop */ }
  let response = await fetch('/api/events/clear', { method: 'POST', headers });
  if (response.status === 401) {
    const token = prompt('Enter monitor token to clear events (set CACHEER_MONITOR_TOKEN in .env):');
    if (token) {
      try { localStorage.setItem('cacheer-token', token); } catch (e) { /* noop */ }
      response = await fetch('/api/events/clear', { method: 'POST', headers: { 'X-Monitor-Token': token } });
    }
  }
  if (!response.ok) {
    return false;
  }
  try {
    const data = await response.json();
    return Boolean(data?.ok);
  } catch (_) {
    return false;
  }
}
