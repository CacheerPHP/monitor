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

export async function fetchSnapshot(
  namespaceFilter = "",
  limit = 1000,
  from = null,
  until = null,
  keyFilter = "",
  eventType = "",
) {
  const params = new URLSearchParams();
  if (keyFilter) {
    params.set("key_filter", keyFilter);
  }
  if (eventType) {
    params.set("type", eventType);
  }
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
  const res = await fetch(`/api/snapshot${qs ? `?${qs}` : ""}`, NO_STORE);
  if (!res.ok) {
    throw new Error("Failed to load snapshot");
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

export async function fetchKeyInspect(key, namespace = null, limit = 100, forceLive = false, driver = null) {
  const params = new URLSearchParams({ key, limit: String(limit) });
  if (driver) {
    params.set("driver", driver);
    if (namespace === null) {
      params.set("namespace_missing", "1");
    }
  }
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

async function authenticatedPost(path, options = {}) {
  const headers = { ...options.headers };
  try {
    const saved = localStorage.getItem("cacheer-token");
    if (saved) {
      headers["X-Monitor-Token"] = saved;
    }
  } catch (_) {}

  let res = await fetch(path, { ...options, method: "POST", headers });

  if (res.status === 401) {
    const token = prompt("Enter monitor token (set CACHEER_MONITOR_TOKEN in .env):");
    if (!token) {
      return null;
    }
    headers["X-Monitor-Token"] = token;
    res = await fetch(path, { ...options, method: "POST", headers });
    if (res.ok) {
      try {
        localStorage.setItem("cacheer-token", token);
      } catch (_) {}
    }
  }

  return res;
}

export async function clearEventsFile() {
  try {
    const res = await authenticatedPost("/api/events/clear");
    if (!res?.ok) {
      return false;
    }
    const data = await res.json();
    return Boolean(data?.ok);
  } catch (_) {
    return false;
  }
}

export async function cleanupRotated(maxAgeDays = 7) {
  try {
    const res = await authenticatedPost("/api/events/cleanup-rotated", {
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ max_age_days: maxAgeDays }),
    });
    if (res === null) {
      return { ok: false, deleted: 0, cancelled: true };
    }
    if (!res.ok) {
      return {
        ok: false,
        deleted: 0,
        error:
          res.status === 401
            ? "Authentication failed. Check your monitor token."
            : `Cleanup failed (HTTP ${res.status}).`,
      };
    }
    return await res.json();
  } catch (_) {
    return { ok: false, deleted: 0, error: "Cleanup failed. Check the monitor connection and try again." };
  }
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
