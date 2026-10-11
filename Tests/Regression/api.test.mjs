import test from "node:test";
import assert from "node:assert/strict";
import { cleanupRotated, clearEventsFile } from "../../public/assets/js/api.js";

function browserMocks(t, { token = null, enteredToken = null, respond } = {}) {
  const originals = new Map(["fetch", "localStorage", "prompt"].map((key) => [key, globalThis[key]]));
  const requests = [];
  const savedTokens = [];
  let prompts = 0;
  globalThis.localStorage = {
    getItem: () => token,
    setItem: (key, value) => savedTokens.push([key, value]),
  };
  globalThis.prompt = () => {
    prompts++;
    return enteredToken;
  };
  globalThis.fetch = async (url, options) => {
    requests.push({ url, ...options, headers: { ...options.headers } });
    return respond(requests.length);
  };
  t.after(() => {
    for (const [key, value] of originals) {
      if (value === undefined) {
        delete globalThis[key];
      } else {
        globalThis[key] = value;
      }
    }
  });
  return { requests, savedTokens, promptCount: () => prompts };
}

test("cleanup sends the saved token together with the retention settings", async (t) => {
  const mocks = browserMocks(t, { token: "saved-token", respond: () => Response.json({ ok: true, deleted: 3 }) });

  assert.deepEqual(await cleanupRotated(14), { ok: true, deleted: 3 });
  assert.equal(mocks.requests[0].url, "/api/events/cleanup-rotated");
  assert.equal(mocks.requests[0].method, "POST");
  assert.equal(mocks.requests[0].headers["X-Monitor-Token"], "saved-token");
  assert.equal(mocks.requests[0].headers["Content-Type"], "application/json");
  assert.deepEqual(JSON.parse(mocks.requests[0].body), { max_age_days: 14 });
  assert.equal(mocks.promptCount(), 0);
});

test("cleanup retries authentication without losing its JSON body", async (t) => {
  const mocks = browserMocks(t, {
    token: "old-token",
    enteredToken: "new-token",
    respond: (attempt) =>
      Response.json(attempt === 1 ? {} : { ok: true, deleted: 1 }, { status: attempt === 1 ? 401 : 200 }),
  });

  assert.equal((await cleanupRotated()).ok, true);
  assert.equal(mocks.requests.length, 2);
  assert.equal(mocks.requests[0].headers["X-Monitor-Token"], "old-token");
  assert.equal(mocks.requests[1].headers["X-Monitor-Token"], "new-token");
  assert.equal(mocks.requests[1].headers["Content-Type"], "application/json");
  assert.equal(mocks.requests[1].body, mocks.requests[0].body);
  assert.deepEqual(mocks.savedTokens, [["cacheer-token", "new-token"]]);
});

test("a rejected token reports failure and is not saved", async (t) => {
  const mocks = browserMocks(t, { enteredToken: "wrong-token", respond: () => Response.json({}, { status: 401 }) });

  const result = await cleanupRotated();
  assert.equal(result.ok, false);
  assert.match(result.error, /Authentication failed/);
  assert.equal(mocks.requests.length, 2);
  assert.deepEqual(mocks.savedTokens, []);
});

test("cancelling authentication stops cleanup without a success result", async (t) => {
  const mocks = browserMocks(t, { respond: () => Response.json({}, { status: 401 }) });

  assert.deepEqual(await cleanupRotated(), { ok: false, deleted: 0, cancelled: true });
  assert.equal(mocks.requests.length, 1);
});

test("HTTP failures retain a useful cleanup error", async (t) => {
  browserMocks(t, { respond: () => Response.json({}, { status: 500 }) });

  const result = await cleanupRotated();
  assert.equal(result.ok, false);
  assert.match(result.error, /HTTP 500/);
});

test("network failures report failed cleanup instead of success", async (t) => {
  browserMocks(t, {
    respond: () => {
      throw new TypeError("fetch failed");
    },
  });

  const result = await cleanupRotated();
  assert.equal(result.ok, false);
  assert.match(result.error, /connection/);
});

test("invalid server JSON reports failed cleanup", async (t) => {
  browserMocks(t, { respond: () => new Response("not-json") });

  assert.equal((await cleanupRotated()).ok, false);
});

test("clear events retains its boolean result and saved-token authentication", async (t) => {
  const mocks = browserMocks(t, { token: "saved-token", respond: () => Response.json({ ok: true }) });

  assert.equal(await clearEventsFile(), true);
  assert.equal(mocks.requests[0].url, "/api/events/clear");
  assert.equal(mocks.requests[0].headers["X-Monitor-Token"], "saved-token");
});
