# Outpost Side-Features Roadmap

Curated third-party integrations layered onto Outpost. Scouting source: the `cporter202/api-mega-list` directory (saved memory). All keys live in **Settings → Integrations**, BYO, same encrypted pattern as the Ranger provider keys (`ranger_api_key_*` → `ranger_decrypt`). Each feature adds one key row + one settings field — no new key infrastructure.

**Pricing honesty:** figures below are as of the last check and must be re-verified before committing (many "free" API-list entries are actually paid). Never call a paid tier without an exact cost projection + explicit approval.

Ordered by synergy with what already shipped (AI section generation + import) first, heaviest/most-standalone last.

---

## 1. Stock image search — Pexels  ·  effort: M  ·  **first**
- **What:** Search + insert stock photos without leaving the builder. Panel on the image-node inspector and in the Media library; "Save to media" pulls the file into `uploads/` so the baked page serves a local asset, not a hotlink.
- **Why first:** Completes the loop we just built — AI-generated sections come back with placeholder image `src`. This fills them. Also upgrades every manual image node and imported section.
- **API / pricing:** Pexels API — free, API key, ~200 req/hr default, attribution appreciated (not strictly required). Genuinely free.
- **Where it lives:** `NodeBuilder` image inspector + Media library. Reuses the existing media upload/optimize pipeline for "save to media."
- **Deps / gotchas:** Store the downloaded asset locally (don't bake hotlinks). Respect rate limits with debounced search. Cache results per query.

## 2. Unsplash as a second provider  ·  effort: S  ·  add-on to #1
- **What:** Same search panel, second source toggle (Pexels / Unsplash).
- **API / pricing:** Free, but **production requires Unsplash app review**, and their guidelines require per-photo attribution + a download-trigger ping. More hoops than Pexels.
- **Deps:** Build the panel provider-agnostic in #1 so this is a drop-in. Ship only if the extra library is worth the compliance overhead.

## 3. oEmbed / link unfurl  ·  effort: M
- **What:** Paste a URL → rich embed card (YouTube, Vimeo, tweets, etc.). In the visual builder it rides the existing **passthrough node**; in the richtext/post editor it's an inline embed.
- **API / pricing:** Per-provider oEmbed endpoints are free (YouTube, Vimeo, etc.) but you maintain a provider allow-list. A universal unfurler (Iframely ~free to 1k/mo then paid; Embedly paid) removes the list but costs money — **verify before using**.
- **Where it lives:** New builder node type or passthrough helper + TipTap embed extension.
- **Deps / gotchas:** Static-baked output — embeds must be self-contained iframe/script the baker can emit safely. Sanitize/allow-list embed HTML (reuse the Shield/sanitizer posture). Start with free per-provider oEmbed; only reach for a paid unfurler if coverage demands it.

## 4. Grammar & readability — LanguageTool  ·  effort: M
- **What:** Inline grammar/spelling/readability suggestions as you draft.
- **API / pricing:** LanguageTool public API free (rate-limited, ~20 req/min, 20k chars/req); self-host via Docker is free and unlimited; Premium API is paid. Default to self-host or public free tier.
- **Where it lives:** The **richtext/post editor (TipTap)** — NOT the visual builder. TipTap extension that debounces text to the API and renders underlines + a suggestions popover.
- **Deps / gotchas:** Debounce hard (don't hammer the free tier). Offer a self-host URL field so heavy users point at their own instance.

## 5. Newsletter send — Resend  ·  effort: L  ·  **its own track, not a side-feature**
- **What:** Push a published post/page to subscribers. Turns Outpost from a CMS into a publishing platform.
- **API / pricing:** Resend free tier ~3,000 emails/mo, 100/day, 1 verified domain. Verify current limits before relying on them.
- **Where it lives:** New subscriber model (or reuse **Lodge members** as the list), a compose/preview UI, an email template, double-opt-in + one-click unsubscribe, send-status tracking.
- **Deps / gotchas:** This is a genuine feature area, not a small integration — sequence it as its own project. Legal: unsubscribe + sender identity (CAN-SPAM). Domain DNS (SPF/DKIM) setup. Decide subscriber source (Lodge vs standalone list) before building.

---

## Dropped / deferred
- **Akismet comment-spam filtering — N/A for now.** Outpost has no public post comments; the `comments` system is internal (team collaboration + token-based client review, `php/comments.php`). Nothing to filter. Revisit only if/when public post comments become a feature — which is its own decision, and Akismet is commercial-paid for business use.

## Shared foundation (do implicitly with #1)
- **Integrations key storage** already exists (encrypted settings + BYO pattern). Each feature = one key + one settings field.
- **Attribution / local-asset discipline:** anything that pulls remote media (Pexels/Unsplash) saves locally so baked pages don't hotlink or break offline.
- **Sanitize remote HTML** (oEmbed) through the existing sanitizer/Shield posture before it reaches a baked page.

## Notes
- The `api-mega-list` is a scouting resource for *future* data-feed features (weather, maps, sports, currency) — those largely belong in **Channels** (Outpost's external-feed plugin), not here.
