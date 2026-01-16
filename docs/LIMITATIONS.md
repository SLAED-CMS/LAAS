# Known Limitations

**Note:** These are architectural constraints for extreme horizontal scaling scenarios (dozens of servers). LAAS CMS handles high traffic well on single or few servers. Limitations become relevant only when scaling across many parallel application servers.

---

- No horizontal session scaling
  - Sessions are file-based by default.
  - Becomes a problem with multiple app servers.
  - Workaround: shared session storage or sticky sessions.
- No async jobs/queue
  - All tasks run synchronously.
  - Becomes a problem for heavy background work.
  - Workaround: external queue/worker system.
- Thumbnails are pre-generated only
  - No on-the-fly transforms.
  - Becomes a problem for large variant sets.
  - Workaround: run `media:thumbs:sync` regularly.
- No built-in WAF
  - Application-layer protection only.
  - Becomes a problem under active attack.
  - Workaround: use a reverse proxy WAF/CDN.
- No zero-downtime migrations
  - Migrations may lock tables or block writes.
  - Becomes a problem for strict uptime requirements.
  - Workaround: maintenance window and read-only mode.
- No distributed rate limiting
  - Rate limits are per-server (in-memory buckets).
  - Becomes a problem with multiple app servers.
  - Workaround: external rate limiter (Redis) or load balancer rules.
- Changelog GitHub API limits
  - Subject to GitHub rate limits (60/hour unauthenticated, 5000/hour with token).
  - Becomes a problem with frequent cache refreshes.
  - Workaround: use local git provider or increase cache TTL.
- AI Assistant UI is Read-Only
  - No "Apply" button in Admin UI for proposals.
  - Becomes a problem for UI-only workflows.
  - Workaround: Use CLI `ai:proposal:apply <id>` to apply changes.
- AI Context Limits
  - Context is truncated to fit provider window.
  - Becomes a problem for large codebases or complex tasks.
  - Workaround: Use focused prompts and smaller contexts.

**Last updated:** January 2026
