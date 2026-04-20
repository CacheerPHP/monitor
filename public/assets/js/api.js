// API client for Cacheer Monitor

// [ config ]

const NO_STORE = { cache: "no-store" };

export async function fetchConfig() {
  const res = await fetch("/api/config", NO_STORE);
  if (!res.ok) {
    throw new Error("Failed to load config");
  }
  return res.json();
}

export async function fetchMetrics(namespaceFilter = "", limit = 1000, from = null, until = null) {
  const params = new URLSearchParams();
  if (namespaceFilter) {
    params.set("namespace", namespaceFilter);
  }
  if (limit > 0) {
    params.set("limit", String(limit));
  }
  if (from !== null) {
    params.set("from", String(from));
  }
  if (until !== null) {
    params.set("until", String(until));
  }

  const qs = params.toString();
  const res = await fetch(`/api/metrics${qs ? `?${qs}` : ""}`, NO_STORE);
  if (!res.ok) {
    throw new Error("Failed to load metrics");
  }
  return res.json();
}

export async function fetchEvents(limit = 200, namespaceFilter = "", from = null, until = null) {
  const params = new URLSearchParams({ limit: String(limit) });
  if (namespaceFilter) {
    params.set("namespace", namespaceFilter);
  }
  if (from !== null) {
    params.set("from", String(from));
  }
  if (until !== null) {
    params.set("until", String(until));
  }

  const res = await fetch(`/api/events?${params}`, NO_STORE);
  if (!res.ok) {
    throw new Error("Failed to load events");
  }
  return res.json();
}

export async function fetchKeyInspect(key, namespace = null, limit = 100, forceLive = false) {
  const params = new URLSearchParams({ key, limit: String(limit) });
  if (namespace) {
    params.set("namespace", namespace);
  }
  if (forceLive) {
    params.set("live", "1");
  }

  const res = await fetch(`/api/keys/inspect?${params}`, NO_STORE);
  if (!res.ok) {
    throw new Error("Failed to inspect key");
  }
  return res.json();
}

// [ actions ]

export async function clearEventsFile() {
  const headers = {};
  try {
    const saved = localStorage.getItem("cacheer-token");
    if (saved) {
      headers["X-Monitor-Token"] = saved;
    }
  } catch (_) {}

  let res = await fetch("/api/events/clear", { method: "POST", headers });

  if (res.status === 401) {
    const token = prompt("Enter monitor token to clear events (set CACHEER_MONITOR_TOKEN in .env):");
    if (!token) {
      return false;
    }
    try {
      localStorage.setItem("cacheer-token", token);
    } catch (_) {}
    res = await fetch("/api/events/clear", { method: "POST", headers: { "X-Monitor-Token": token } });
  }

  if (!res.ok) {
    return false;
  }
  try {
    const data = await res.json();
    return Boolean(data?.ok);
  } catch (_) {
    return false;
  }
}

export async function cleanupRotated(maxAgeDays = 7) {
  const res = await fetch("/api/events/cleanup-rotated", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ max_age_days: maxAgeDays }),
  });
  if (!res.ok) {
    return { ok: false, deleted: 0 };
  }
  return res.json();
}

export function buildExportUrl(format = "json", limit = 0, namespaceFilter = "", from = null, until = null) {
  const params = new URLSearchParams({ format });
  if (limit > 0) {
    params.set("limit", String(limit));
  }
  if (namespaceFilter) {
    params.set("namespace", namespaceFilter);
  }
  if (from !== null) {
    params.set("from", String(from));
  }
  if (until !== null) {
    params.set("until", String(until));
  }
  return `/api/events/export?${params}`;
}
