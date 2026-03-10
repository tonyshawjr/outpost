# Outpost CMS — Product Roadmap

> Outpost is a powerful, zero-config CMS for everyone — from freelancers building client sites to developers who want full control. No Composer, no build step, no managed infrastructure. It runs wherever PHP 8.x runs, backs up to a single file, and ships with a complete admin, visual customization, Liquid templating, built-in analytics, and a member system out of the box.

### Documentation Standard

Every shipped feature includes thorough, beginner-friendly documentation:
- **Developer docs** (`php/docs/`) — HTML reference pages with examples, organized by topic
- **`llms.txt`** — plain-text mirror of all docs for LLM-assisted development
- **In-admin help** — contextual guidance where users need it, not buried in external docs
- Documentation is written for the person who has never used a CMS before, with enough depth for the person who has used ten.

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

## v1.4 — User Content Directory (`content/`) — SHIPPED (v1.4.0)

- All user-owned data consolidated under `outpost/content/` (`data/`, `uploads/`, `themes/`, `backups/`)
- Auto-migration on first load for existing sites (detects old layout, moves dirs, creates symlinks)
- Symlinks at `outpost/uploads` and `outpost/themes` preserve all existing URLs
- Auto-updater skips `content/` entirely — one directory the engine never touches
- Fresh installs create the full `content/` structure automatically

---

## v1.5 — Editorial Workflow — SHIPPED (v1.5.0)

- **Review & approval gate** — per-collection `require_review` toggle; editors submit for review, admins approve or reject
- **Pending review status** — new `pending_review` status with amber indicators, sidebar counts, bulk approve/reject
- **Editorial calendar** — month-grid calendar view of scheduled and published items across collections
- **Bulk scheduling** — select multiple items, apply a publish date/time to all at once
- **Webhook events** — `entry.submitted_for_review`, `entry.approved`, `entry.rejected`, `entry.scheduled`

---

## v1.6 — Media Library Pro — SHIPPED (v1.6.0)

- **WebP auto-conversion** — JPEG/PNG uploads automatically converted to WebP via GD
- **Focal point picker** — click-to-set focal point on images; `{{ field | focal }}` template filter
- **Media folder organization** — folder sidebar, create/rename/delete, drag-to-folder, 3 levels max
- **Bulk upload with progress** — XHR upload queue, per-file progress bars, 2 concurrent, cancel support
- **MediaPicker folder browsing** — folder dropdown in image picker modal
- **Bulk media operations** — multi-select mode with shift-click range, bulk delete, bulk move-to-folder (v1.6.2)

---

## v1.7 — Media Advanced — SHIPPED (v1.7.0)

- **Multi-folder assignment** — files in multiple folders via junction table
- **Gallery from folders** — `{% for img in media_folder.slug %}` template tag
- **Resizable detail sidebar** — drag handle, 220–500px range, localStorage persistence
- **Role-based folder restrictions** — editors scoped to specific media folders
- **Bulk folder creation** — comma-separated names, max 50 at once
- **Right-click file context menu** — folder badges, quick add-to-folder, copy path, delete
- **Folder slugs** — auto-generated for template tag resolution

**Excluded:** CDN integration (deferred to future version)

---

## v1.8 — Theme Customizer — SHIPPED (v1.8.0)

**Make every site feel custom without touching code.** The single biggest unlock for non-developers. A freelancer picks a theme, changes the colors to match their brand, swaps the font, uploads a logo — all from a visual UI, zero code required.

- **Color palette editor** — primary, secondary, accent, background, text colors mapped to CSS custom properties in `theme.json`
- **Font selector** — curated Google Fonts library with preview; set heading and body fonts independently
- **Logo & favicon upload** — dedicated UI in the customizer (not buried in globals)
- **Layout options** — theme-defined toggles (e.g. sidebar position, header style, footer columns) declared in `theme.json`
- **Live preview** — iframe preview updates in real-time as you adjust settings
- **Export/import** — save a customization preset as JSON, share it, apply it to another site running the same theme

**How it works:** Themes declare customizable variables in `theme.json` under a `customizer` key. The admin reads that schema and builds the UI dynamically. Saved values write to `content/data/customizer.json` and inject as CSS custom properties at render time. Zero database changes — it's just a JSON file.

**Docs:** Full customizer guide for site owners ("How to change your site's look") + theme developer reference ("How to make your theme customizable").

---

## v1.9 — Developer Experience & Theme Updates — SHIPPED (v1.9.0)

### Theme Update System — SHIPPED
- **Shipped-in-zip = managed** — any theme included in the update zip is automatically updated; no `"managed": true` flag required (v2.1.3)
- **Theme version comparison** — compare `theme.json` version of installed theme against the version in the update package
- **Safe theme updates** — hash-based conflict detection via `.outpost-manifest.json`; user-modified files preserved, flagged as conflicts
- **Theme update UI** — Settings → Updates shows per-theme results after applying an update
- **Update includes new themes** — new themes in future releases install automatically

### Developer Tools — Shipped
- ~~**Better Liquid error messages**~~ — **SHIPPED** (v1.9.0): pre-compilation tag validation, source line tracking, enhanced error display with friendly messages
- ~~**Schema validation files**~~ — **SHIPPED** (v1.9.2): JSON Schema for `theme.json` with IDE autocomplete; `$schema` added to all bundled themes
- ~~**Theme starter kit**~~ — **SHIPPED** (v1.9.2): Skeleton theme — heavily commented, demonstrates every template tag, ships as managed
- **Outpost CLI** — Future (separate repo)
- **VS Code extension** — Future (separate repo)

---

## v2.0 — Onboarding & Setup Wizard — SHIPPED (v2.0.0)

**First impressions matter.** A fresh install now launches a guided setup that gets any user — technical or not — to a working site in under 5 minutes.

- **Setup wizard** — full-screen 4-step flow: site name, choose a theme, pick a content pack, done. Auto-appears on fresh installs, skipped for existing sites via migration detection
- **Content packs** — 8 pre-built JSON packs (Blog, Portfolio, Business) for all 3 bundled themes, seeding collections, items, menus, globals, and folders in a single transaction
- **Getting Started checklist** — dashboard card with 5 interactive items (upload logo, edit homepage, create post, set up navigation, customize theme) with progress bar and dismiss
- **Contextual tips** — dismissible one-line hints on 7 admin sections (Pages, Collections, Media, Globals, Navigation, Forms, Themes), persisted in localStorage
- **Reusable EmptyState component** — consistent empty states across 11 admin pages with icon, title, description, CTA button, and search-aware variant

---

## v2.1 — Collection Folders — SHIPPED (v2.1.0)

**Brought the media folder system to collections.** The label sidebar from Media Library now appears inline on collection items pages when folders exist.

- **Label sidebar for collections** — same folder panel that media has, showing labels with per-label item counts
- **Drag-to-label** — drag collection items onto labels in the sidebar to assign instantly
- **Filter by label** — click a label in the sidebar to filter the item list server-side; "Unfiled" shows unassigned items
- **Bulk label assignment** — select items and use "Label" dropdown in bulk action bar
- **Inline label creation** — create new labels directly from the sidebar with comma-separated support
- **API endpoints** — `GET items/labels-with-counts`, `POST items/bulk-labels`, updated `GET items` with `label_id` filter
- **Shared CSS** — folder sidebar styles extracted from MediaLibrary to global `admin.css` for reuse

---

## v2.2 — Forge (Visual Tag Builder) — SHIPPED (v2.2.0)

**Turn any static HTML into an Outpost theme without writing a single template tag.** Import your HTML, select content, click "Make Editable" — Outpost wraps the selection in the correct `{{ }}` or `{% %}` tags for you.

### The workflow

1. Paste or import flat HTML files into a theme directory via the code editor
2. Open a file — it's just plain HTML, no template tags yet
3. Select a piece of content (a heading, a paragraph, an `<img>` src, a `<section>`)
4. Click **"Make Editable"** in the toolbar or right-click context menu
5. A compact popover asks:
   - **Field name** — what this field is called in the admin (e.g. `hero_title`)
   - **Type** — text, richtext, image, textarea, link, toggle, select, color, date, number
   - **Scope** — page field (editable per-page) or global (shared across all pages via `@`)
6. Outpost wraps the selection in the correct tag automatically:
   - Selected `"Welcome to My Site"` → `{{ hero_title }}Welcome to My Site{{ /hero_title }}`
   - Selected an `<img>` src → `{{ hero_image | image }}`
   - Selected a block of HTML → `{{ hero_content | raw }}<p>...</p>{{ /hero_content }}`
   - Chose "global" scope → `{{ @site_name }}`
7. Repeat until the page is fully tagged — you've just built a theme

### Advanced tagging

- **Collection loops** — select a repeating HTML pattern (e.g., a blog card), choose "Collection Loop", pick or create the collection, name the item variable → wraps in `{% for post in collection.blog %}...{% endfor %}`
- **Conditionals** — select a section, choose "Conditional" → wraps in `{% if field_name %}...{% endif %}`
- **Includes** — select a `<header>` or `<footer>`, choose "Extract Partial" → moves the HTML to `partials/header.html` and replaces it with `{% include 'header' %}`
- **Meta tags** — select a `<title>` or `<meta>` description, choose "Meta" → converts to `{{ meta.title }}` / `{{ meta.description }}`

### Forge Playground — SHIPPED (v2.2.1)

- **6-page demo theme** — ships as flat HTML/CSS in `themes/forge-playground/` for hands-on practice
- **Menu Loop** — 7th action, wraps nav links in `{% for link in menu.slug %}` with smart link replacement
- **Smart Extract Partial** — nav detection with "Connect to admin menu" option
- **Loop field mapper** — auto-detects content elements and maps them to collection fields
- **Forge Theme wizard** — guided `theme.json` creation for flat HTML folders
- **Forge reset** — one-click restore to pristine state from `.forge-snapshot/` backups
- **Preview tabs** — single-click preview, double-click to pin (VS Code behavior)
- **Wrapping defaults** — ON by default for all field types including images and links
- **Security hardening** — removed PHP from code editor, input sanitization, content size limits

### Code editor enhancements (ships with this release)

- **Autocomplete inline descriptions** — `{{ }}` and `{% %}` autocomplete shows short descriptions of each tag/filter, not just the syntax
- **Tag preview** — hover any template tag to see what it compiles to and what it does

### Why this matters

Freelancers and agencies already have HTML sites — from Webflow exports, Tailwind templates, or hand-coded prototypes. Today, converting that HTML to an Outpost theme means reading the template reference and manually typing every `{{ }}` tag. The visual tag builder makes the conversion interactive and guided. You don't need to know the template syntax — you just point at content and tell Outpost what it is.

**Docs:** "Convert an HTML site to an Outpost theme" walkthrough, visual tag builder reference, autocomplete description reference.

---

## v2.3 — Deeper Analytics — SHIPPED (v2.3.0)

Extend beyond pageviews into audience behavior — actionable insights without external tools:

- ~~Content performance cohorts~~ — **Shipped**: groups pages by publish date into 5 age brackets, shows traffic distribution and top performers per cohort
- ~~Goal funnels~~ — **Shipped**: 4-stage member lifecycle funnel (Visit → Sign Up → Login → Upgrade) with conversion rates and activity feed
- ~~Search analytics~~ — **Shipped**: `outpost.trackSearch()` JS API + auto URL param detection, top queries, zero-result queries, click-through rates
- ~~Optional geo enrichment~~ — **Shipped**: pure-PHP MaxMind MMDB reader, double-gated (file + setting), stores only 2-letter country codes

**Docs:** Analytics dashboard guide, goal funnel setup, privacy configuration reference.

---

## v2.4 — Theme Gallery

**More starting points, less blank-page anxiety.** Ship 4-5 polished themes so users can pick one that fits and customize it with the v1.8 customizer.

- **Business** — homepage with services, team, testimonials, contact form
- **Portfolio** — grid gallery, project case studies, about page
- **Blog** — clean reading experience, categories, author pages, newsletter signup
- **Documentation** — sidebar navigation, search, code blocks, versioned sections
- **Landing page** — single-page marketing site with sections, CTA blocks, pricing table

All themes ship with full `customizer` config (v1.8), responsive design, dark mode support, and accessibility basics (ARIA, semantic HTML, skip links).

**Docs:** Theme gallery showcase page, "Choosing the right theme" guide, per-theme customization reference.

---

## v2.4 — Content API Polish (SHIPPED)

Making the existing Content API reliable: menus endpoint, item title/url, page visibility filtering, orderby case fix, rate limiting, folders collection filter, complete headless recipe docs rewrite.

---

## v2.5 — Headless-First

Position Outpost as the zero-config headless CMS alongside the traditional themed approach:

- **GraphQL API** alongside REST — read-only to start; mutations later based on adoption
- **Content webhooks v2** — structured payloads with full content diffs, not just event names
- **Static site preview** — trigger a preview build on content change via webhook (for Astro, Next, Hugo)
- **Real-time content events** — SSE or WebSocket channel for live content updates (opt-in)

**Why:** Strapi and Payload own headless but require Node.js and a database server. Outpost's gap is "headless + zero config, runs on a $5/month host."

**Docs:** Headless quickstart, GraphQL schema reference, webhook payload reference, framework integration guides (Astro, Next.js, Hugo).

---

## v2.6 — Collaborative Editing

Real-time multi-user editing on the same page or collection item:

- Presence indicators (who's viewing this page right now)
- Live cursor positions via WebSocket
- Operational transform or CRDT for conflict-free concurrent edits
- Activity feed ("Tony updated the hero title 2 minutes ago")

This depends on on-page editing being mature and v2.5 (real-time events) being in place.

**Docs:** Collaboration setup guide, real-time editing user guide.

---

## v3.0 — Commerce

Lightweight digital product sales via Stripe:

- Sell downloads, courses, and one-time purchases
- Member tiers with Stripe Checkout and Customer Portal
- License key generation for digital products
- Purchase and MRR analytics in the dashboard

**What this is not:** Physical inventory, shipping, or marketplace features. Just Stripe checkout + access gates.

**Docs:** Stripe integration setup, product creation guide, member tier configuration, commerce template tags reference.

---

## Long-Term / v3.x+

### Outpost CLI

A standalone CLI tool (separate repo, own release cycle) for theme scaffolding, development server, and deployment:

- `outpost new my-theme` — scaffold a theme from Skeleton with prompts for name, author, collections
- `outpost dev` — local dev server with live reload on template changes
- `outpost validate` — lint templates and theme.json against the JSON Schema
- `outpost package` — zip a theme for distribution

### VS Code Extension

A VS Code extension (separate repo, own release cycle) for Outpost Liquid template authoring:

- Syntax highlighting for `{{ }}` and `{% %}` tags inside HTML
- Autocomplete for template tags, filters, globals, and collection fields
- Hover documentation for each tag and filter
- JSON Schema integration for `theme.json` autocomplete (uses the shipped schema)

### Internationalization

Multi-language content support — deferred until core CMS is mature and user demand is validated:

- Locale tag on pages and collection items
- Content API locale filter
- One-click "duplicate as locale" from the editor
- `{{ @locale }}` global for language selectors
- URL prefix routing (`/es/about`)

### Theme Marketplace

A centralized place for users to browse, preview, and install themes directly from the admin panel:

- Browse curated and community-submitted themes from within Admin → Themes
- One-click install — download theme zip from marketplace, extract to `content/themes/`
- Theme ratings, screenshots, and compatibility info
- Revenue model for premium themes (if applicable)
- Dependency: Theme Update System (v1.9) for keeping marketplace themes current

### Channels — Advanced Phases

- **Phase 3:** Inbound webhooks
- **Phase 4:** Outbound + advanced (filtering, pagination, channel-to-channel piping)

---

## Not Planned

These are explicitly out of scope. Acknowledging them keeps the codebase focused.

| Feature | Why Not |
|---|---|
| Plugin ecosystem | Requires a package manager (Composer/npm) — violates the zero-config promise |
| Managed SaaS hosting | A business model, not a product feature — can be built on top of Outpost separately |
| Native mobile apps | Web admin + responsive design is sufficient; unnecessary release cycle overhead |
| Visual drag-and-drop page builder | Theme Customizer (v1.8) handles colors/fonts/logo; on-page editing handles content; structural layout belongs in theme templates |
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

| Version | Target | Theme | Audience |
|---|---|---|---|
| 1.0.0 | Q1 2026 | Stable release — beta hardening complete | — |
| ~~1.1~~ | ~~Q1 2026~~ | ~~Channels Phase 2: RSS + CSV~~ **Shipped** | — |
| ~~1.2~~ | ~~Q3 2026~~ | ~~On-page editing~~ **Shipped in beta.9** | — |
| ~~1.3~~ | ~~Q4 2026~~ | ~~Backup & restore~~ **Shipped** | — |
| ~~1.4~~ | ~~Q2 2026~~ | ~~User content directory (`content/`)~~ **Shipped** | — |
| ~~1.5~~ | ~~Q4 2026~~ | ~~Editorial workflow~~ **Shipped** | — |
| ~~1.6~~ | ~~Q1 2027~~ | ~~Media library pro~~ **Shipped** | — |
| ~~1.7~~ | ~~Q1 2027~~ | ~~Media advanced~~ **Shipped** | — |
| ~~1.8~~ | ~~Q1 2027~~ | ~~Theme Customizer — visual colors, fonts, logo~~ **Shipped** | Everyone |
| ~~1.9~~ | ~~Q2 2027~~ | ~~Developer Experience & Theme Updates~~ **Shipped** | Developers / Everyone |
| ~~2.0~~ | ~~Q3 2027~~ | ~~Onboarding & Setup Wizard~~ **Shipped** | Everyone |
| ~~2.1~~ | ~~Q3 2027~~ | ~~Collection Folders — Inline label sidebar~~ **Shipped** | Everyone |
| ~~2.2~~ | ~~Q4 2027~~ | ~~Forge — visual HTML-to-theme conversion~~ **Shipped** | Developers / Everyone |
| ~~2.3~~ | ~~Q4 2027~~ | ~~Deeper Analytics — funnels, search, cohorts~~ **Shipped** | Everyone |
| 2.4 | Q1 2028 | Theme Gallery — 4-5 polished starter themes | Everyone |
| 2.5 | Q1 2028 | Headless-First — GraphQL, webhooks v2 | Developers |
| 2.6 | Q2 2028 | Collaborative Editing — real-time multi-user | Everyone |
| 3.0 | Q3 2028 | Commerce — Stripe, digital products | Everyone |
| 3.x+ | TBD | CLI, VS Code Extension, Internationalization, Theme Marketplace | Developers / Everyone |
