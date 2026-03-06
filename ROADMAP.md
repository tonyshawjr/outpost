# Outpost CMS — Product Roadmap

> Outpost is a zero-config, drop-in CMS. No Composer, no build step, no managed infrastructure. It runs wherever PHP 8.x runs, backs up to a single file, and ships with a complete admin, Liquid templating, built-in analytics, and a member system out of the box.

---

## Shipped in Beta

Everything below is already built and in the current beta (v1.0.0-beta.15).

- Content management (pages, collections, globals, folders, menus, navigation)
- **Flexible Content field type** — named layout blocks with sub-fields (ACF Pro parity)
- **Relationship field type** — link content to items from other collections (ACF Pro parity)
- **Conditional Logic** — show/hide schema fields based on other field values (ACF Pro parity)
- Block editor (TipTap richtext, image, markdown, HTML, divider blocks)
- Media library with search, filter, crop, resize, alt text
- Liquid template engine with partials, conditionals, loops, globals, meta tags
- Built-in analytics (pageviews, referrers, top pages — zero external dependencies)
- Member system (registration, login, email verification, paid tiers, visibility gates)
- **Visual form builder** — 14 field types, drag-and-drop, `{% form 'slug' %}` template tag, enhanced submissions inbox
- **Channels (Phase 1: REST API + Phase 2: RSS + CSV)** — connect to external APIs, RSS feeds, and CSV files, cache data in SQLite, render in templates via `{% for item in channel.slug %}`
- **On-page editing** — edit text, richtext, textarea, and image fields directly on the live frontend (admin-only, with floating TipTap toolbar, media picker, auto-save)
- Forms with submissions, email notifications, reCAPTCHA
- Code editor (VS Code-style IDE with tabs, file tree, autocomplete, find in files)
- Revision history with field-level diffs and one-click rollback
- SEO score panel, per-item meta fields, automatic XML sitemap
- TOTP two-factor authentication with backup codes
- Optimistic locking (concurrent edit protection)
- API key authentication for headless use
- Webhooks, scheduled publishing, bulk operations
- WordPress import
- 6 roles with capability-based permissions
- Mobile-responsive admin with bottom tab navigation
- Dark mode
- Template error handling, database indexes, rate limiting
- cPanel deployment guide, developer docs (53 pages)
- **Security hardening** (v1.0.0-beta.14) — SSRF prevention, session fixes, TOTP hardening, SVG DOM sanitizer, API key O(1) lookup, zip slip prevention, rate limits on all public endpoints, credential masking, open redirect fix, email injection fix

---

## Next: v1.0.0 — Stable Release

Freeze the feature set. Ship after beta feedback stabilizes.

**Release criteria:**
- ~~Zero known security issues~~ — **Done** (v1.0.0-beta.14: 37 findings addressed across 8 batches)
- ~~All docs complete and accurate~~ — **Done** (v1.0.0-beta.15: auto-updater, security, 28 API endpoints documented, all sidebar navs updated)
- ~~Upgrade path documented~~ — **Done** (v1.0.0-beta.15: beta-to-stable migration notes in Deploy guide)
- ~~Fresh install tested on cPanel, Apache, and Nginx~~ — **Done**
- ~~Personal theme passes full QA as reference implementation~~ — **Done**

**v1.0.0 shipped on 2026-03-06.**

---

## v1.1 — Channels (External Data Sources)

**Turn Outpost from a content CMS into a site engine.** Channels bring external data into your templates with the same Liquid syntax developers already use for collections. If Collections are content you create, Channels are content that flows in from somewhere else.

> Full design document: [`CHANNELS.md`](CHANNELS.md)

### Phase 1: REST API — SHIPPED (v1.0.0-beta.6)

- Connect to any JSON API with auth (API key, bearer, basic), custom headers, query params
- Offset and cursor-based pagination support
- Dot-notation data path extraction (e.g. `data.listings`)
- Schema discovery with field type detection and sample data preview
- SQLite caching with configurable TTL (5min to manual-only)
- Auto-sync on template render when cache expires
- `{% for item in channel.slug %}` loop and `{% single item from channel.slug %}` tag
- Channel URL pattern routing for single-item pages (e.g. `/listing/{slug}`)
- Admin UI: Channel list, 4-step builder wizard (Connect → Schema → Configure → Preview), sync dashboard

### Phase 2: RSS + CSV — SHIPPED (v1.1.0)

- RSS 2.0 and Atom 1.0 feed parsing via SimpleXML (title, link, description, pubDate, author, content, category, guid, enclosure)
- CSV file parsing from any URL (Google Sheets export, hosted CSVs) with configurable delimiter and header detection
- Source type selector in channel wizard (REST API / RSS Feed / CSV)
- Type-dispatched schema discovery and sync pipeline
- SSRF protection on all channel types

---

## v1.2 — Admin Role Refinement — SHIPPED (v1.2.0)

- **Content Editor cleanup** — sidebar and mobile nav hide Channels, Form Builder, Code Editor, Settings, and Themes for editors; route guards block direct URL access
- **Collection-scoped editors** — restrict editors to specific collections via per-user grants; backend enforces on all item CRUD and collection list
- **Per-page locks** — admins can lock pages; locked pages prevent edits by non-admin roles (including field saves and deletes)
- Developer role option added to user profile selector

---

### Remaining phases

- **Phase 3:** Inbound webhooks
- **Phase 4:** Outbound + advanced (filtering, pagination, channel-to-channel piping)

### Forms + Channels Cross-Pollination

Both systems use HTTP requests, JSON handling, and similar admin UI patterns. As they mature, shared infrastructure will be extracted and new integrations unlocked:

- **Shared HTTP utility** — extract `channel_fetch_api()` cURL wrapper into `php/http-client.php` for use by both Channels (inbound API) and Form Feeds (outbound webhooks). Trigger: when Form Feeds ship.
- **Channel data → Form selects** — populate a form dropdown from a channel (e.g., "Pick a property" from MLS API, "Select a department" from HR system)
- **Form submission → Channel push** — POST form data to an external API using the shared HTTP utility (form feeds as outbound channels)
- **Schema discovery for Forms** — reuse `channel_discover_schema()` to auto-generate form fields from an API response schema
- **Shared `KeyValueEditor` / `JsonTree` components** — already built for Channels, reuse in Forms for webhook header config and validation rule visualization
- **Unified audit log** — replace `channel_sync_log` and form submission tracking with a shared audit table for all entity events

**Why:** Every small business site eventually needs external data. Today that means custom PHP or plugins. Channels make it declarative: connect, map fields, loop in a template. No plugins, no Composer, no build step. This is what makes Outpost more than a CMS — it's a site engine.

---

## ~~v1.2 — On-Page Editing~~ SHIPPED (v1.0.0-beta.9)

Edit content directly on the live site while logged in as an admin — no round-trips to the admin panel for simple text, image, and richtext changes.

- Admin detection → inject editor JS/CSS only for authenticated admins
- Field annotations (`data-ope-*`) on text, richtext, textarea, image, and global fields
- Collection single-page item fields annotated inside `{% single %}` blocks
- Click-to-edit: contenteditable for text/textarea, floating TipTap toolbar for richtext, media picker for images
- Auto-save with debounced batching (400ms), optimistic locking, visual status indicators
- `PUT items/inline` API for partial collection item field updates
- Edit mode toggle in admin bar (persisted in localStorage)
- Standalone Vite IIFE bundle — zero frontend impact for visitors

---

## v1.3 — Backup & Restore — SHIPPED (v1.3.0)

- **One-click backup** — single button exports the SQLite database + all uploaded media as a `.zip`
- **Restore from backup** — upload a `.zip` to roll back the entire site (super_admin only, with safety copy)
- **Automatic backups** — optional daily/weekly auto-backup with configurable max retention
- **Backup history** — list of recent backups with download links, sizes, and timestamps
- **Backups page** in admin sidebar with create, download, delete, restore, and schedule settings

---

## v1.3.2 — Backup Includes Themes — SHIPPED (v1.3.2)

- Backups now include the `themes/` directory (user templates, CSS, assets) alongside db + uploads
- Restore extracts themes from the backup zip

---

## v1.3.1 — Auto Update Notification — SHIPPED (v1.3.1)

- **Auto update check on login** — backend includes update status in `auth/me` for admins
- **Sidebar badge** — green dot on Settings when an update is available (desktop + mobile)
- Zero additional API calls — shares the existing 5-minute GitHub cache

---

## v1.4 — Editorial Workflow

Scheduled publishing works, but team content workflows need more structure:

- **Review-required flag per collection** — items stay draft until explicitly approved by an admin or editor
- **Approval notifications** — email or in-admin notification when content is submitted for review
- **Bulk scheduling** — select multiple items, apply a publish date to all
- **Editorial calendar view** — month/week view of scheduled and published content
- **Webhook events** — `entry.submitted`, `entry.approved`, `entry.scheduled` fire to registered webhooks

**Why:** Any team with more than one person editing content needs an approval step between drafting and publishing.

---

## v1.5 — Media Library Pro

The current library handles basics. Real sites need more:

- **Bulk upload** with per-file progress bars and drag-and-drop queue
- **WebP auto-conversion** with quality slider (one-click optimize for existing images)
- **Folder organization** for large media archives
- **Focal point** — click to set the focal point on an image; CSS `object-position` in templates uses it
- **CDN integration** — optional S3, Cloudflare R2, or Bunny CDN as storage backend (local remains default)

**Why:** Media is where non-technical users spend the most time. Friction here costs the most.

---

## v1.6 — Internationalization

Not full multi-language — just the groundwork to store and serve localized content:

- **Locale tag** on pages and collection items (`en`, `es`, `fr`, etc.)
- **Content API locale filter**: `?locale=es` returns only matching items
- **One-click "duplicate as locale"** from the editor
- **`{{ @locale }}` global** for rendering a language selector in themes
- **URL prefix routing**: `/es/about` serves the Spanish version of the about page

**What this is not:** Auto-translation, regional CDNs, or multi-domain routing. Just the CMS layer to organize locale-tagged content.

---

## v1.7 — Developer Experience

Polish the theme development loop:

- **Outpost CLI** — `outpost pull | push | sync | backup` for local theme development without the Builder app
- **Better Liquid error messages** — line/column in compile errors with suggested fixes for common mistakes
- **Schema validation files** — JSON Schema per collection for static site generators (Astro, Next.js) to type-check content at build time
- **VS Code extension** — Liquid syntax highlighting and Outpost-specific autocomplete (separate repo)
- **Theme starter kit** — `outpost new-theme` scaffolds a minimal theme with standard partials and example loops

---

## Long-Term / Vision (v2.0+)

### v2.0 — Headless-First

Position Outpost explicitly as the zero-config headless CMS alongside the traditional themed approach:

- **GraphQL API** alongside REST — read-only to start; mutations later based on adoption
- **Content webhooks v2** — structured payloads with full content diffs, not just event names
- **Static site preview** — trigger a preview build on content change via webhook (for Astro, Next, Hugo)
- **Real-time content events** — SSE or WebSocket channel for live content updates (opt-in)

**Why:** Strapi and Payload own headless but require Node.js and a database server. Outpost's gap is "headless + zero config, runs on a $5/month host."

---

### v2.1 — Deeper Analytics

Extend beyond pageviews into audience behavior:

- Content performance cohorts (heavy readers vs. casual visitors)
- Goal funnels (visitor → member → paid → churned)
- Search analytics — what are visitors searching for on the site
- Optional geo enrichment via MaxMind GeoLite2 (off by default, privacy-first)

---

### v2.2 — Commerce

Lightweight digital product sales via Stripe:

- Sell downloads, courses, and one-time purchases
- Member tiers with Stripe Checkout and Customer Portal
- License key generation for digital products
- Purchase and MRR analytics in the dashboard

**What this is not:** Physical inventory, shipping, or marketplace features. Just Stripe checkout + access gates.

---

### v2.3 — Collaborative Editing

Real-time multi-user editing on the same page or collection item:

- Presence indicators (who's viewing this page right now)
- Live cursor positions via WebSocket
- Operational transform or CRDT for conflict-free concurrent edits
- Activity feed ("Tony updated the hero title 2 minutes ago")

This depends on v1.2 (on-page editing) being mature and v2.0 (real-time events) being in place.

---

## Not Planned

These are explicitly out of scope. Acknowledging them keeps the codebase focused.

| Feature | Why Not |
|---|---|
| Plugin ecosystem | Requires a package manager (Composer/npm) — violates the zero-config promise |
| Managed SaaS hosting | A business model, not a product feature — can be built on top of Outpost separately |
| Native mobile apps | Web admin + responsive design is sufficient; unnecessary release cycle overhead |
| Visual drag-and-drop page builder | On-page editing (v1.2) handles content; layout changes belong in theme templates |
| Built-in AI content generation | External API dependencies, ongoing costs, and key management complexity |
| MySQL/Postgres support | SQLite is the constraint that enables "backup = one file" and shared-host compatibility |
| LDAP/SAML enterprise SSO | Out of scope for indie/small-team target market; can be bolted on via custom `auth.php` |
| Full multi-tenancy | Instance isolation per customer is a hosting infrastructure problem, not a CMS feature |

---

## Core Constraints (Non-Negotiable)

These define what Outpost is. Breaking them makes it something else.

1. **Zero server-side dependencies** — No Composer, no npm on the server, no build step to deploy
2. **SQLite only** — Single-file database enables trivial backups and shared hosting compatibility
3. **Liquid templates only** — No PHP in theme files; content/presentation separation is enforced, not optional
4. **Drop-in install** — Copy files, visit `/outpost/`, done. No CLI setup, no environment variables required

---

## Release Cadence

| Version | Target | Theme |
|---|---|---|
| 1.0.0 | Q1 2026 | Stable release — beta hardening complete |
| ~~1.1~~ | ~~Q1 2026~~ | ~~Channels Phase 2: RSS + CSV~~ **Shipped** |
| ~~1.2~~ | ~~Q3 2026~~ | ~~On-page editing~~ **Shipped in beta.9** |
| ~~1.3~~ | ~~Q4 2026~~ | ~~Backup & restore~~ **Shipped** |
| 1.4 | Q4 2026 | Editorial workflow |
| 1.5 | Q1 2027 | Media library pro |
| 1.6 | Q1 2027 | Internationalization |
| 1.7 | Q2 2027 | Developer experience |
| 2.0 | Q3 2027 | Headless-first |
