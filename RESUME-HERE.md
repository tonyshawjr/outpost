# ▶ RESUME HERE — Outpost CMS

**Last touched:** 2026-07-07
**Say "let's keep going" and I'll pick up exactly here.**

---

## Where we are

- **Shipped through `v6.0.0-beta.45`** — tree clean, everything committed & released.
- **#11 Dynamic Islands (ISR model) is DONE** and shipped in beta.45. Targeted cache invalidation: a field/item edit clears only the affected page(s), not the whole cache. Path-addressable cache files, a collection→page dependency map (`page_collection_deps`) that self-populates, item single-page + listing-page clears, and slug-rename clears the old URL. Verified live end-to-end + a 15-check correctness harness; security-audited (two issues found & fixed before release: slug-rename stale regression + path-normalization divergence).

## The ONE thing waiting to be built

**Nothing scoped is pending.** Phase 1 (ISR) is complete. The deferred follow-on, if/when wanted:

- **Approach A — client-hydrated "islands"** for the cases ISR can't serve: member/paid-gated fragments (an authed island keeps the base page publicly cacheable) and any hole explicitly flagged "always live" (a real-time feed). This is the "5%" layer; ISR already covers the static 95%. Spec: `research/dynamic-islands-scope.md` §A + the "Phased build" steps 3–4.

## Deploy reminders (don't forget)

- Build: `npm run build` → `php/admin/`; then `rm -rf test-site/outpost/admin && cp -R php/admin test-site/outpost/admin` + copy changed `.php`.
- Preserve `test-site/outpost/{data,uploads,cache}`.
- Every release needs a GitHub Release or the auto-updater can't see it.
- Local test server: `http://localhost:8099` (docroot `test-site/`). Login: tonyshawjr / Outpost123!
- Cache-correctness harness: `OP_DIR="$(pwd)/test-site/outpost" php <scratchpad>/cache_audit.php` (re-creatable from the ISR verification if needed).
