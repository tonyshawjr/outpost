# ▶ RESUME HERE — Outpost CMS

**Last touched:** 2026-07-06
**Say "let's keep going" and I'll pick up exactly here.**

---

## Where we are

- **Shipped through `v6.0.0-beta.44`** — tree clean, everything committed & released.
- The whole Mosaic + Instatic borrow batch is **done** (beta.34 → beta.44): loop element, motion system, orientation overlay, "made it yours" recap, section gallery, preview toggle, template archive, style guide, whole-site zip import, floating mode island + per-node action bar, code view, and more.

## The ONE thing waiting to be built

**#11 — Dynamic Islands (ISR model).** You chose **B: ISR**. It is **fully scoped and implementation-mapped** — no code written yet.

- Full plan: **`research/dynamic-islands-scope.md`** → see the section **"DECISION (2026-07-05): B — ISR. Concrete implementation map"** at the bottom.
- It's a 4-step build (Phase 1):
  1. Path-addressable cache filenames — `boost_cache_path()` (`php/boost.php:114`)
  2. Targeted `boost_clear_page_cache(?string $path)` — glob + unlink just that page
  3. `outpost_clear_cache($path)` passes the path through (`php/engine.php:1762`)
  4. `page_collection_deps` table auto-tracked in `cms_collection_list()` (`php/engine.php:972`); collection saves clear only dependent pages (replaces coarse clear-all at api.php:2032/3096/3351/3981/4077)

## ⚠️ Why it's its own pass, not a quick add

Cache invalidation is **high-blast-radius** — a path-normalization mismatch silently serves **stale content to live visitors**. This one needs a dedicated **cache-correctness audit** as the main event: field edit clears only its page (sibling survives), collection edit hits exactly its dependents, member/paid pages unaffected. That verification *is* the work. Start it fresh, not at the tail of a long session.

## When we resume — the checklist

1. Build ISR Phase 1 (steps 1–4 above).
2. Verify live (Python urllib driver): edit field → only that page's cache clears; edit collection item → only dependent pages clear.
3. Cache-correctness security/audit pass.
4. Ship `v6.0.0-beta.45` (bump `package.json` + `php/config.php`, changelog HTML + MD, roadmap, FEATURES.md, `npm run build` + `npm run package`, commit, tag, `gh release create`).

## Deploy reminders (don't forget)

- Build: `npm run build` → `php/admin/`; then `rm -rf test-site/outpost/admin && cp -R php/admin` + copy changed `.php`.
- Preserve `test-site/outpost/{data,uploads,cache}`.
- Every release needs a GitHub Release or the auto-updater can't see it.
- Login: tonyshawjr / Outpost123!
