import test from "node:test";
import assert from "node:assert/strict";
import { renderKeyInspector } from "../../public/assets/js/renderers.js";

test("unavailable previews display and escape the live lookup reason", () => {
  const panel = { innerHTML: "" };
  renderKeyInspector(panel, {
    summary: { key: "key", capture_values_enabled: true, live_lookup_reason: "Missing <cache directory>" },
  });

  assert.match(panel.innerHTML, /Missing &lt;cache directory&gt;/);
  assert.doesNotMatch(panel.innerHTML, /No captured preview is available/);
});

test("failed live refresh keeps the event preview and explains the fallback", () => {
  const panel = { innerHTML: "" };
  renderKeyInspector(panel, {
    summary: {
      key: "key",
      capture_values_enabled: true,
      preview_source: "event",
      last_value_preview: '{"name":"Captured value"}',
      live_lookup_reason: "The key has expired.",
    },
  });

  assert.match(panel.innerHTML, /Captured value/);
  assert.match(panel.innerHTML, /The key has expired\. Showing the last captured preview\./);
});
