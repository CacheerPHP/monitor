// API client for Cacheer Monitor

export async function fetchConfig() {
  const response = await fetch('/api/config', { cache: 'no-store' });
  if (!response.ok) {
    throw new Error('Failed to load config');
  }
  return await response.json();
}

export async function fetchMetrics(namespaceFilter = '') {
  const query = namespaceFilter ? `?namespace=${encodeURIComponent(namespaceFilter)}` : '';
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
  const response = await fetch('/api/events/clear', { method: 'POST' });
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

