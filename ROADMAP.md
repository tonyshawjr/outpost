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
- **Outpost-managed vs. user themes** — `"managed": true` flag in `theme.json` distinguishes bundled themes from user-created themes
- **Theme version comparison** — compare `theme.json` version of installed theme against the version in the update package
- **Safe theme updates** — hash-based conflict detection via `.outpost-manifest.json`; user-modified files preserved, flagged as conflicts
- **Theme update UI** — Settings → Updates shows per-theme results after applying an update
- **Update includes new themes** — new managed themes in future releases install automatically

### Developer Tools — Partially Shipped
- ~~**Better Liquid error messages**~~ — **SHIPPED**: pre-compilation tag validation, source line tracking, enhanced error display with friendly messages
- **Outpost CLI** — deferred to separate repo
- **Schema validation files** — deferred
- **VS Code extension** — deferred to separate repo
- **Theme starter kit** — deferred

---

## v2.0 — Onboarding & Setup Wizard

**First impressions matter.** Right now a fresh install drops you into a blank admin. v2.0 fixes that with a guided setup that gets any user — technical or not — to a working site in under 5 minutes.

- **Setup wizard** — runs on first visit: site name, choose a theme, set admin credentials, pick starter content
- **Starter content packs** — pre-built content for common use cases (blog, portfolio, business, documentation) that populate pages, collections, globals, and sample media
- **Getting started dashboard** — replaces the default dashboard for new sites with a checklist: "Add your logo", "Edit your homepage", "Create your first blog post", "Connect a domain"
- **Contextual tips** — subtle inline hints on first use of each admin section (dismissible, never repeated)
- **Empty states** — every list page (pages, collections, media, forms) has a helpful empty state with a clear call-to-action instead of a blank table

**Docs:** "Your first 10 minutes with Outpost" quickstart guide, aimed at someone who has never used a CMS.

---

## v2.1 — Collection Folders

**Bring the media folder system to collections.** Users already organize media files with folders (v1.6). Now apply the same concept to collection items — posts, products, case studies — so content creators can group and filter items the way they think about them.

- **Folder sidebar for collections** — same Happy Files-style folder panel that media already has, scoped per collection
- **Drag-to-folder** — drag collection items into folders from the item list
- **Filter by folder** — click a folder in the sidebar to filter the item list instantly
- **Multi-folder assignment** — items can belong to multiple folders (same as media)
- **Folder template loops** — `{% for item in collection_folder.slug %}` to render items by folder in templates
- **Bulk folder operations** — bulk move/assign items to folders from the item list

**Why:** Folders (categories) already exist for collection items via the Folders system, but the UX is separate from the item list. This brings folder management inline — the same workflow media uses — so organizing posts feels as natural as organizing photos.

**Docs:** Collection folders user guide, template loop reference for folder-filtered content.

---

## v2.2 — Theme Gallery

**More starting points, less blank-page anxiety.** Ship 4-5 polished themes so users can pick one that fits and customize it with the v1.8 customizer.

- **Business** — homepage with services, team, testimonials, contact form
- **Portfolio** — grid gallery, project case studies, about page
- **Blog** — clean reading experience, categories, author pages, newsletter signup
- **Documentation** — sidebar navigation, search, code blocks, versioned sections
- **Landing page** — single-page marketing site with sections, CTA blocks, pricing table

All themes ship with full `customizer` config (v1.8), responsive design, dark mode support, and accessibility basics (ARIA, semantic HTML, skip links).

**Docs:** Theme gallery showcase page, "Choosing the right theme" guide, per-theme customization reference.

---

## v2.3 — Headless-First

Position Outpost as the zero-config headless CMS alongside the traditional themed approach:

- **GraphQL API** alongside REST — read-only to start; mutations later based on adoption
- **Content webhooks v2** — structured payloads with full content diffs, not just event names
- **Static site preview** — trigger a preview build on content change via webhook (for Astro, Next, Hugo)
- **Real-time content events** — SSE or WebSocket channel for live content updates (opt-in)

**Why:** Strapi and Payload own headless but require Node.js and a database server. Outpost's gap is "headless + zero config, runs on a $5/month host."

**Docs:** Headless quickstart, GraphQL schema reference, webhook payload reference, framework integration guides (Astro, Next.js, Hugo).

---

## v2.4 — Deeper Analytics

Extend beyond pageviews into audience behavior — actionable insights without external tools:

- Content performance cohorts (heavy readers vs. casual visitors)
- Goal funnels (visitor → member → paid → churned)
- Search analytics — what are visitors searching for on the site
- Optional geo enrichment via MaxMind GeoLite2 (off by default, privacy-first)

**Docs:** Analytics dashboard guide, goal funnel setup, privacy configuration reference.

---

## v2.5 — Commerce

Lightweight digital product sales via Stripe:

- Sell downloads, courses, and one-time purchases
- Member tiers with Stripe Checkout and Customer Portal
- License key generation for digital products
- Purchase and MRR analytics in the dashboard

**What this is not:** Physical inventory, shipping, or marketplace features. Just Stripe checkout + access gates.

**Docs:** Stripe integration setup, product creation guide, member tier configuration, commerce template tags reference.

---

## v2.6 — Collaborative Editing

Real-time multi-user editing on the same page or collection item:

- Presence indicators (who's viewing this page right now)
- Live cursor positions via WebSocket
- Operational transform or CRDT for conflict-free concurrent edits
- Activity feed ("Tony updated the hero title 2 minutes ago")

This depends on on-page editing being mature and v2.2 (real-time events) being in place.

**Docs:** Collaboration setup guide, real-time editing user guide.

---

## Long-Term / v3.x+

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
| 1.9 | Q2 2027 | Developer Experience & Theme Updates | Developers / Everyone |
| 2.0 | Q3 2027 | Onboarding & Setup Wizard | Everyone |
| 2.1 | Q3 2027 | Collection Folders — Happy Files for posts | Everyone |
| 2.2 | Q4 2027 | Theme Gallery — 4-5 polished starter themes | Everyone |
| 2.3 | Q4 2027 | Headless-First — GraphQL, webhooks v2 | Developers |
| 2.4 | Q1 2028 | Deeper Analytics — funnels, search, cohorts | Everyone |
| 2.5 | Q1 2028 | Commerce — Stripe, digital products | Everyone |
| 2.6 | Q2 2028 | Collaborative Editing — real-time multi-user | Everyone |
| 3.x | TBD | Internationalization, Theme Marketplace | Everyone |
