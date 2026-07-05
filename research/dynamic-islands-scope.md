# Dynamic Islands (#11) — Scope

**Status:** Scoping / decision needed. No code yet.
**Date:** 2026-07-05
**Source item:** Instatic borrow spec §5a #11 + §3.5 — "static baked page + lazy-loaded dynamic fragments." The most North-Star-aligned remaining item ("static except the holes, deploys like WordPress").

---

## The problem

**Today's render/cache model** (`php/front-router.php` + `php/boost.php`):
1. Cache miss → the template engine renders the whole page, filling every `data-outpost` hole and `<outpost-each>` loop from the DB **per request**.
2. Output is stored as one full-page `.html` (`boost_cache_path()`, hash of URL, TTL 3600s).
3. Cache hit → `boost_serve_cached_page()` serves that whole file directly (fast).
4. A content change → `boost_clear_page_cache()` (coarse — clears broadly).

**The three limits this creates:**
- **Coarse invalidation.** Editing one field or adding one post throws away whole cached pages; they re-render fully on the next hit.
- **No per-fragment freshness.** You can't have a mostly-static page with one always-current loop ("latest 3 posts") without either serving stale or disabling the page cache entirely.
- **Personalized / member content can't be cached.** Member- or paid-gated pages bypass the full-page cache → full server-render on every hit.

"Static except the holes" wants the inverse: the page is **static and cacheable forever**, and only **the holes** carry the dynamic cost.

---

## Three approaches (ranked)

### B — Incremental Static Regeneration (ISR): regenerate the static file on content change  ⭐ recommended default
- Build a **dependency graph**: page → the fields/collections it renders. (Partial pieces already exist: `page_field_registry`, collection `url_pattern`, the node-tree bake.)
- On a field/item **save**, regenerate **only the affected pages'** static `.html` (sync or queued) — never the whole cache.
- Serve the static `.html` **directly** (skip per-request template processing entirely).
- **Pros:** genuinely static serving; SEO-perfect (fully-rendered HTML, no flash, no layout shift); **no client JS needed for content**; "deploys like WordPress" (static files on disk/CDN); fine-grained invalidation (edit one post → regenerate the few pages that show it).
- **Cons:** needs the dependency graph + a regenerate-on-save step; a "live" feed that must change per-view (not per-edit) still isn't per-request fresh; personalized/member content still can't be statically baked (needs approach A for those).
- **Effort:** L.

### A — Client-side island hydration (the literal Astro/Instatic "islands")
- Bake the static page with **last-known values** (so SEO + no-JS see real content). Tag each hole as an island (`data-outpost` already marks them).
- A tiny runtime fetches the page's current hole values in **one JSON call** (+ renders `<outpost-each>` loops client-side) and swaps them into the islands.
- Serve the static base page from cache/CDN forever; only the small island-data endpoint is dynamic (and itself cacheable per-collection).
- **Pros:** base page infinitely cacheable/CDN-able; edits appear without regenerating pages; **per-fragment freshness** (one loop can be live while the rest is frozen); **member content loads as an authed island** so the base page stays public-cacheable.
- **Cons:** brief content flash / layout-shift risk (mitigated by baking last-known values so an island only repaints if it actually changed); freshest content depends on JS (baked defaults are the no-JS/crawler fallback); loops need a client render path (an HTML-fragment endpoint, or client-render the node subtree).
- **Effort:** L.

### C — Edge / SSI hole includes
- Bake a static shell with include markers; an edge worker / SSI fills holes via sub-requests.
- **Pros:** static shell + dynamic holes, no client JS.
- **Cons:** requires edge/SSI infra (Cloudflare Workers, nginx SSI) — **breaks the "any shared host / deploys like WordPress" thesis**. Most complex.
- **Effort:** XL, host-dependent. **Not recommended** for the portability goal.

---

## Recommendation — hybrid: B as default, A as an opt-in layer

- **B (ISR) for ordinary content holes** — the common case. Most pages become real static files, regenerated only when their content changes. This is the biggest, most on-thesis win and the cleanest evolution of the existing bake + cache: it keeps SEO, no-JS, and shared-host deploy intact while killing coarse invalidation.
- **A (islands) for the cases B can't serve** — member/paid-gated fragments (authed island keeps the base page public-cacheable) and any hole explicitly flagged "always live" (a real-time feed). Opt-in per hole/loop, not the default.
- **Skip C** unless a specific edge deployment demands it.

Net: "static except the holes" becomes true — static files for the 95%, islands for the personalized/live 5%.

---

## Phased build (once a direction is chosen)

1. **Dependency graph** — record, per page, which fields + collections it renders (extend `page_field_registry` + a collection-usage table). The enabling data for B.
2. **Regenerate-on-save (B)** — on field/item save, look up affected pages, re-bake their static `.html`. Serve those files directly (front-router short-circuit before the template engine).
3. **Island runtime (A)** — a `data-island` marker + a `content/islands?page=…` JSON endpoint + a tiny hydration script (same shipping pattern as `outpost-motion.js`); bake last-known values as the fallback.
4. **Member/live islands (A)** — authed island fetch for gated fragments; an "always live" flag on a loop.
5. **Docs + security audit + release** — cache-poisoning review on the regen path, authz review on the island endpoint (must re-check member/paid gates server-side), SEO/no-JS verification.

**Risks to watch:** regeneration storms (one collection change → many pages; needs batching/debounce); dependency-graph completeness (a missed edge = stale page); the island endpoint must re-enforce member/paid authz server-side (never trust the client); and always-bake-last-known-values so no-JS/crawlers never see empty holes.

---

## The decision needed (before building)

Which model to build first:
- **(B) ISR default** — recommended; static/SEO/no-JS/WordPress-like, fine-grained invalidation. Best single first step.
- **(A) Islands** — if per-view freshness + member-as-island is the priority over SEO-simplicity.
- **(Hybrid)** — B now, A layered after (the recommendation).

Everything downstream (dependency graph vs client runtime first) branches on this.
