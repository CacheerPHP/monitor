# Copilot — Cacheer Monitor

Read the root [AGENTS.md](../AGENTS.md) before editing or reviewing this repository.
It contains the full telemetry, dashboard, previews, security, and testing guidance.
Read linked source and tests for the behavior you are changing.

- Target `2.x` or a task branch based on it; preserve local changes and user services.
- This is `cacheerphp/monitor`, namespace `Cacheer\Monitor\`, for PHP 8.3+
  and CacheerPHP 6. Keep the Composer library/CLI and plain HTML/CSS/ES module UI.
- Keep the autoload bridge quiet and idempotent; report status through `doctor`.
  Preserve event mapping, microseconds-to-milliseconds conversion, and log locking.
- Current-log metrics cover matching records independently of the feed limit.
  Missing namespace/TTL metadata stays unknown; lifecycle markers are not latency samples.
- Value capture stays opt-in and redacted. Live lookup requires a shared backend
  and matching settings; another process's ArrayStore cannot be inspected live.
- Preserve loopback binding, destructive-action tokens, no-store headers, text
  escaping, archive cleanup boundaries, and accessible light/dark dashboard states.
- Prefer focused fixes and regression tests; avoid rewrites, benchmarks and unrelated dependencies.
- For code changes use `composer test`, `npm run test:ui`, and `npm run lint`
  as relevant. Node regressions do not replace visual browser checks for UI changes.
  For prose-only changes, verify references and `git diff --check`.
- Do not patch installed `vendor/`, force-add ignored logs, alter sibling repos
  outside scope, or commit/push/publish without a request.
- Never add AI author notes, assistant signatures, or AI co-author trailers.

For reviews, prioritize incorrect metrics, data loss, exposure of credentials,
ambiguous previews, duplicated events, and accessibility regressions.
