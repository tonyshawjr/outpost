# Outpost CMS — Product Roadmap

> Outpost is a powerful, zero-config CMS for everyone — from freelancers building client sites to developers who want full control. No Composer, no build step, no managed infrastructure. It runs wherever PHP 8.x runs, backs up to a single file, and ships with a complete admin, visual customization, Liquid templating, built-in analytics, a member system, and an AI assistant out of the box.

### Documentation Standard

Every shipped feature includes thorough, beginner-friendly documentation:
- **Developer docs** (`php/docs/`) — HTML reference pages with examples, organized by topic
- **`llms.txt`** — plain-text mirror of all docs for LLM-assisted development
- **In-admin help** — contextual guidance where users need it, not buried in external docs
- Documentation is written for the person who has never used a CMS before, with enough depth for the person who has used ten.

---

## Shipped

Everything below is already built and released.

### v1.0.0 — Stable Release (2026-03-06)
- Content management (pages, collections, globals, folders, menus, navigation)
- Flexible Content, Relationship fields, Conditional Logic (ACF Pro parity)
- Block editor (TipTap richtext, image, markdown, HTML, divider blocks)
- Media library with search, filter, crop, resize, alt text
- Liquid template engine with partials, conditionals, loops, globals, meta tags
- Built-in analytics (pageviews, referrers, top pages — zero external dependencies)
- Member system (registration, login, email verification, paid tiers, visibility gates)
- Visual form builder with submissions, email notifications, reCAPTCHA
- Channels Phase 1 & 2 (REST API, RSS, CSV — external data in templates)
- On-page editing (`| edit` modifier for frontend content editing)
- Code editor (VS Code-style IDE with tabs, file tree, autocomplete, find in files)
- Revision history with field-level diffs and one-click rollback
- SEO score panel, per-item meta fields, automatic XML sitemap
- TOTP two-factor authentication with backup codes
- Optimistic locking, API key auth, webhooks, scheduled publishing, bulk operations
- WordPress import, 6 roles with capability-based permissions
- Mobile-responsive admin with dark mode

### v1.2 — Admin Role Refinement
- Content Editor sidebar cleanup, collection-scoped editors, per-page locks

### v1.3 — Backup & Restore
- One-click backup (DB + media + themes), restore, auto-schedule, backup history

### v1.4 — Content Directory
- All user data under `outpost/content/`, auto-migration, symlinks for URL preservation

### v1.5 — Editorial Workflow
- Review & approval gate, pending review status, editorial calendar, bulk scheduling

### v1.6–1.7 — Media Library Pro & Advanced
- WebP auto-conversion, focal point picker, media folders, bulk upload, multi-folder assignment, gallery from folders, role-based folder restrictions

### v1.8 — Theme Customizer
- Color palette editor, Google Fonts selector, logo/favicon upload, layout toggles, live preview, export/import presets

### v1.9 — Developer Experience & Theme Updates
- Safe theme updates with hash-based conflict detection, better Liquid error messages, JSON Schema for theme.json, skeleton starter theme

### v2.0 — Onboarding & Setup Wizard
- 4-step setup wizard, 8 content packs, getting started checklist, contextual tips, empty states

### v2.1 — Collection Folders
- Label sidebar for collections, drag-to-label, filter by label, bulk label assignment

### v2.2 — Forge (Visual Tag Builder)
- HTML-to-theme conversion: select content → click "Make Editable" → auto-wraps in Liquid tags
- Collection loops, conditionals, includes, meta tags — all via right-click context menu
- Forge Playground (6-page demo theme), smart extract partial, loop field mapper

### v2.3 — Deeper Analytics
- Content performance cohorts, goal funnels, search analytics, optional geo enrichment

### v2.4 — Content API Polish
- Menus endpoint, item title/url, page visibility filtering, rate limiting, complete headless recipe docs

### v2.5 — Outpost Design System
- ~~Sidebar reorganization~~ **Shipped v4.10.0**, Brand page (colors, typography, logo), CSS Framework, 20 HTML components, Template Reference panel

### v4.12.0 — Lodge Custom Pages (2026-03-24)
- ~~Lodge custom login/register/forgot pages~~ **Shipped** — Page selector dropdowns in Lodge settings, front-router redirects to custom pages

### v4.11.1 — Environment Indicator (2026-03-24)
- ~~Environment pill in top bar~~ **Shipped** — Auto-detected Local/Staging/Production indicator, color-coded, zero config

### v4.11 — SEO & Discovery Suite (2026-03-24)
- ~~RSS feed generator~~ **Shipped** — Site-wide and per-collection RSS 2.0 feeds with auto-discovery tags
- ~~Site search~~ **Shipped** — `<outpost-search>` template tag, public API, search index, JS client, analytics logging
- ~~Sitemap enhancement~~ **Shipped** — Per-collection toggle, visibility filtering, changefreq/priority, auto robots.txt

### v4.10 — URL Redirects & Sidebar Overhaul (2026-03-23)
- ~~URL redirect manager~~ **Shipped** — 301/302/307, wildcard, regex, hit tracking, CSV import, URL tester
- ~~Sidebar reorganization~~ **Shipped** — Content/Site/Members/Build/Tools groups, avatar dropdown, pinned favorites
- ~~Avatar menu~~ **Shipped** — Profile, settings, backups, calendar, dark mode, logout in top bar dropdown

### v3.0 — Ranger AI Assistant (2026-03-19)
- 35 tools covering every CMS operation (content, themes, files, media, channels, forms, users, members, webhooks, backups, database queries, template debugging, email config, navigation, settings, frontend actions)
- 3 AI providers: Claude (with prompt caching), OpenAI, Gemini — bring your own API key
- Streaming SSE responses with real-time tool execution feedback
- Conversation persistence with searchable history
- Token usage tracking with cost-per-conversation display
- Screenshot paste support for visual context
- Configurable output style (concise, detailed, casual, technical, custom)
- Role-scoped tools (editors → content, developers → code, admins → everything)
- Security audited: SSRF, path traversal, CSRF, capability enforcement, XSS, SQL restrictions, AES-256-GCM encrypted API keys
- Template engine fix: if/else inside for loops
- Form Builder + Inbox dark mode fixed (9 components)
- Forge Playground reset button

---

## v3.1 — Releases — SHIPPED (v3.1.0)

**Bundle multiple content changes and publish them all at once.** Like git commits for content — perfect for campaign launches, site redesigns, and coordinated updates.

- Create named releases (e.g., "Spring Campaign Launch") with descriptions
- Stage changes — page edits, new collection items, global updates, menu changes tagged to a release instead of going live
- One-click publish — all changes go live simultaneously in a database transaction
- One-click rollback — revert the entire release, all changes undo at once
- Release history with status tracking (Draft, Published, Rolled Back)
- Ranger integration — manage releases via AI

---

## v3.2 — Custom Workflows — SHIPPED (v3.2.0)

**Define any content workflow beyond draft/published.** Different content types can have different approval processes — a blog post might need only editor review, while a legal page needs legal team sign-off.

- Custom workflow stages — create any stages: Draft → Copy Review → Design Review → Legal → Approved → Published
- Per-collection workflows — assign different workflows to different collections
- Stage-based permissions — control who can move content to each stage
- Visual workflow builder — inline stage editor with color-coded stages
- Webhook events on every stage transition
- Audit trail — full history of who moved what to which stage and when
- Bulk stage transitions — move multiple items to the next stage at once
- Two default workflows: Simple (draft → published) and Editorial (draft → review → approved → published)
- Ranger integration — manage workflows and transitions via AI

---

## v3.3 — Collaboration & Client Review — SHIPPED (v3.3.0–v3.3.3)

**Team communication directly on content + client feedback on the live site.** No more Slack threads about "which heading are we talking about?"

- Comment threads on collection items and pages
- @mention team members with email notifications
- Email notifications on replies
- Resolve/unresolve comment threads
- Comment count badges on collection items and review tokens
- Activity feed API for recent comments across all content
- Client Review Links — shareable URLs for external feedback without admin accounts
- Review overlay — lightweight vanilla JS script on the frontend with element-pinned commenting, dark panel, numbered pins
- Admin feedback inbox with resolve/delete/filter (All/Open/Resolved)
- Admin email alerts when clients leave review feedback
- Ranger integration — manage comments and review links via AI

---

## v3.4 — Theme Gallery + New Themes

**More starting points, less blank-page anxiety.** Ship 4–5 polished, production-ready themes with full customizer support, content packs, and documentation.

- **Theme gallery overhaul** — WordPress-style cards with screenshots, live preview, theme details panel
- **Device preview** — fullscreen overlay with desktop/tablet/mobile toggle to see how themes respond
- **Business theme** — homepage with hero, services grid, team section, testimonials carousel, contact form, about page, blog
- **Portfolio theme** — masonry project grid, case study pages with image galleries, about page with skills/experience, filterable categories
- **Blog theme** — clean reading experience, category pages, author pages, related posts, newsletter signup
- **Documentation theme** — sidebar navigation with search, code blocks with syntax highlighting, versioned sections, breadcrumbs
- **Landing page theme** — single-page marketing site with hero, feature sections, pricing table, CTA blocks, FAQ accordion, footer
- All themes use the Design System framework, ship with content packs and Unsplash images
- Each theme includes a setup guide and customizer reference

---

## v3.5 — Multi-Language (i18n)

**Field-level translations for international sites.** Huge for agencies with clients in multiple markets — manage all languages from one admin panel.

- **Locale system** — define languages (English, Spanish, French, etc.) in Settings
- **Field-level translations** — every text/richtext/textarea field gets a language tab. Edit the English version, switch to Spanish, edit that version.
- **Locale selector in editor** — dropdown in the page/item editor to switch between languages
- **"Duplicate as locale" one-click** — copy an entire page or item as a new language version with all fields pre-filled for translation
- **URL prefix routing** — `/about` (English), `/es/about` (Spanish), `/fr/about` (French). Automatic.
- **`{{ @locale }}` global** — use in templates for language switcher dropdowns
- **Content API locale filter** — `?locale=es` returns only Spanish content for headless use
- **Translation status tracking** — see which items are translated, which are pending, which are outdated (source changed after translation)
- **Per-language publishing** — publish the English version now, publish Spanish when the translation is ready
- **Ranger integration** — "Translate this page to Spanish" triggers AI translation and stores it as a locale variant

---

## v3.6 — Headless-First

**Position Outpost as the zero-config headless CMS.** Strapi and Payload own headless but require Node.js and a database server. Outpost's gap is "headless + zero config, runs on a $5/month host."

- ~~**JWT Bearer Token Auth**~~ — SHIPPED (v4.4.0). Stateless HS256 JWT for mobile apps and headless clients. Member token endpoints (`token`, `token/register`, `token/refresh`, `token/me`). Configurable CORS origins via `api_cors_origins` setting.
- ~~**GraphQL API**~~ — **Shipped** (v4.6.14). Full GraphQL endpoint with public read queries and authenticated CRUD mutations. Auto-generated schema, introspection, field selection, aliases, variables, fragments. Interactive GraphiQL playground. Zero dependencies.
- **Content webhooks v2** — structured payloads with full content diffs (before/after JSON), not just event names. Know exactly what changed.
- **Static site preview** — trigger a preview build on content change via webhook (for Astro, Next.js, Hugo, Eleventy). One-click "Preview in Vercel/Netlify."
- **Real-time content events** — SSE endpoint that streams content changes as they happen. Subscribe from any frontend framework for live updates.
- **Framework integration guides** — step-by-step docs for Astro, Next.js, Nuxt, SvelteKit, Remix, Hugo. Each with a starter template.
- **SDK packages** — lightweight JavaScript SDK for fetching Outpost content (`@outpost/client`). TypeScript types auto-generated from collection schemas.

---

## v3.7 — Collaborative Editing

**Real-time multi-user editing on the same page or collection item.** See who's editing what, watch changes appear live, never overwrite someone's work.

- **Presence indicators** — see avatar badges showing who's currently viewing/editing each page or item
- **Live cursor positions** — see other users' cursors in real-time in richtext fields (like Google Docs)
- **Conflict-free concurrent edits** — CRDT (Conflict-free Replicated Data Type) or Operational Transform ensures two people editing the same field never lose work
- **Field-level locking** — when someone is actively typing in a field, it shows as "being edited by Tony" to others
- **Real-time activity feed** — "Tony updated the hero title 2 minutes ago" appears instantly for all connected users
- **WebSocket infrastructure** — lightweight PHP WebSocket server for real-time communication
- **Graceful degradation** — if WebSocket isn't available (shared hosting), falls back to polling with optimistic locking (existing behavior)

---

## v4.0 — Smart Forge + Frontend Drawer — SHIPPED (v4.0.0)

**The product reinvention.** Drop in HTML, everything is automatically editable, all editing moves to the frontend. This is what makes Outpost the only CMS where the workflow is: Claude Code builds it → drop it in → done.

- **Smart Forge** — one "Make Editable" button scans an entire HTML file and auto-converts ALL content elements to Liquid fields. Detects `<h1>`, `<p>`, `<img>`, `<a>`, `<button>`, `<nav>` — groups related elements into sections (hero title + subtitle + button = one section). What takes 20 right-clicks today takes 1 click.
- **Frontend editing drawer** — when logged in, the existing "Edit in Outpost" button at the bottom opens a Storyblok-style drawer from the right. Every editable field on the current page is listed in the drawer, organized by section. Text fields, image pickers, button labels + URLs, toggles, repeaters. Save at top, changes apply instantly.
- **Pages section removed from admin** — no more backend page editing. All page content editing moves to the frontend drawer. Backend stays for collections, media, settings, code.
- **Drawer design** — Storyblok-level polish. Section grouping (Hero, About, CTA, Footer). Repeater fields expand/collapse inline. Rich text editor in the drawer. Image picker with media library integration.
- **Right-click context menu stays** — make partials, wrap in loops, manual field control still works in the Code Editor for developers
- **Ranger integration** — "Make this page editable" triggers Smart Forge. AI + visual editing in one product.

---

## v4.1 — Template Engine v2 — SHIPPED (v4.1.0)

**Data Attribute Architecture.** A new template engine that uses HTML `data-outpost` attributes and custom `<outpost-*>` elements instead of Liquid-style syntax. Zero CMS fingerprints on the live site.

- **Data attribute syntax** — `data-outpost` attributes replace `{{ }}` tags, keeping templates as valid HTML
- **Custom elements** — `<outpost-each>` for loops, `<outpost-*>` elements for CMS constructs
- **Block grouping** — HTML comments define editor sections for organized content management
- **Block settings** — CSS custom properties configure field behavior
- **Global fields** — `data-scope="global"` attribute for site-wide content
- **Auto-hide empty fields** — fields with no content are automatically hidden on the live site
- **`data-bind` attribute** — set HTML attributes from item data dynamically
- **Gallery loops** — `<outpost-each gallery="name">` for image galleries
- **Clean public output** — all CMS attributes stripped in production, zero fingerprints
- **Editor mode** — data attributes preserved for click-to-edit functionality
- **Auto engine detection** — v1/v2 detection in front router, both engines coexist
- **v2 field scanner** — automatic field registration from template attributes
- **Personal theme rewritten** — flagship theme converted to v2 data-attribute syntax

---

## v4.2 — Click-to-Edit Bridge, EditDrawer & Skeleton Theme — SHIPPED (v4.2.0)

**Click any element to edit it, Storyblok-style editing drawer, and a full v2 reference theme.** The visual editor is now complete end-to-end.

- **Click-to-Edit Bridge** — Bridge JS scans the preview iframe for `[data-outpost]` elements, adds hover outlines and field name labels, handles click-to-field mapping via postMessage
- **Block-level targeting** — click a block background to see all its fields; click an element to jump to that specific field
- **Editor mode rendering** — v2 engine keeps `data-outpost` attributes in output when loaded with `?_outpost_editor=1`
- **Block attribute injection** — engine adds `data-outpost-block="name"` to block wrappers in editor mode
- **Reverse highlighting** — sidebar field hover highlights the corresponding element in the preview
- **Storyblok-style EditDrawer** — three-level drill-down sidebar (section list → section detail with General/Style tabs → field detail), breadcrumb navigation, bridge click integration jumps to matching section/field
- **Skeleton theme v2 showcase** — complete theme rewrite demonstrating all v2 features: 7 homepage blocks with outpost-settings (colors, layout variants, ranges, toggles), repeaters (features, testimonials, team, skills), collection loops with pagination and filtering, folder taxonomy loops, single items with related posts, global nav/footer blocks, conditionals, and responsive CSS using `var()` and `[data-layout]` attribute selectors

---

## v4.6 — Lodge (Member Portal) & Feature Toggles — SHIPPED (v4.6.0)

**Member-owned content from the front-end.** Lodge lets members log in and manage their own collection items — perfect for directories, marketplaces, job boards, and membership platforms. Plus admin-controlled feature toggles and a restructured sidebar.

- **Lodge** — first-class member portal. Enable per-collection with granular config: allow create/edit/delete, require approval, max items per member, editable/readonly field whitelists, tier-based access gating
- **Lodge API** — 8 JWT-authenticated endpoints: dashboard, items CRUD, profile get/update, file upload. All scoped to `owner_member_id` with rate limiting and atomic max-items enforcement
- **Lodge Template Tags** — `<outpost-lodge-dashboard>`, `<outpost-lodge-items>`, `<outpost-lodge-form>` custom elements for theme developers
- **Lodge Routing** — configurable URL slug, auto-redirects to member login, maps to theme templates
- **Lodge Starter Templates** — 4 templates in Personal theme (dashboard, edit, create, profile)
- **Lodge Approval Workflow** — pending review queue, webhooks on create/update
- **Lodge Tier Gating** — `required_tiers` config, `tier` and `meta` columns on members
- **Feature Toggles** — 14 toggleable sidebar features in Settings > Features (Collections, Channels, Forms, Members, Lodge, Analytics, Media, Code Editor, Navigation, Releases, Workflows, Review Links, Backups, Ranger)
- **Sidebar Accordion Groups** — 5 collapsible groups (Content, Site, Members, Build, Insights) with localStorage persistence
- **Security hardened** — atomic transactions for limits, rate limiting on all mutations, MIME-based file extension mapping, lodge slug sanitization, XSS protection on member profile data
- **Full-page Collection Schema Editor** (v4.6.10–v4.6.13) — dedicated full-page route replaces inline modal. Drag-sortable field builder with all 15 field types, visual repeater sub-field builder (with JSON toggle), type-specific options (select choices, relationship picker, flexible layouts, repeater sub-fields), conditional logic on any field, Lodge settings panel, require review toggle, and Cmd+S save shortcut

---

## v4.8 — Compass (Smart Filtering & Search) — SHIPPED (v4.8.0, v2 in v4.8.4)

**Data-attribute-driven filtering and search for any collection.** Write your own HTML, add `data-compass` attributes, and Compass handles the rest. No wrapper divs, no forced classes, no locked-in layout.

- ~~**Data-attribute architecture (v2)**~~ — `data-compass="search|dropdown|checkbox|results|pager|reset|sort|..."` on native HTML elements — **Shipped v4.8.4**
- ~~**Backward compatible**~~ — `<outpost-compass>` tags compile to the same data-attribute HTML — **Shipped v4.8.4**
- ~~**Auto-population**~~ — empty selects/containers fetch options from API automatically — **Shipped v4.8.4**
- ~~**Submit mode**~~ — `<button data-compass="submit">` for form-style filtering — **Shipped v4.8.4**
- ~~**12 filter types**~~ — search, dropdown, checkbox, radio, range, A-Z, toggle, proximity, pager, sort — **Shipped**
- ~~**Results container**~~ — `<div data-compass="results">` with any layout — **Shipped**
- ~~**Helper tags**~~ — result count, reset, active filter pills — **Shipped**
- ~~**URL state sync**~~ — all filters reflected in shareable URLs — **Shipped**
- ~~**Indexed search**~~ — SQLite-based index with incremental updates, sub-50ms queries — **Shipped**
- ~~**Proximity search**~~ — geolocation-based distance filtering with browser Geolocation API — **Shipped**
- ~~**Minimal CSS**~~ — ~95 lines, only auto-generated content styled — **Shipped v4.8.4**

---

## v4.9.0 — Shield (Security Suite) — SHIPPED (v4.9.0)

**Comprehensive security hardening with admin settings panel.** Protects Outpost sites from brute force, injection attacks, and file tampering — all configurable from Settings > Shield.

- ~~**Login protection**~~ — lockout after N failed attempts, configurable duration, auto-permanent-block after repeated lockouts — **Shipped**
- ~~**IP blocklist**~~ — manual and automatic blocking with optional expiry — **Shipped**
- ~~**WAF-lite firewall**~~ — blocks SQL injection, XSS, path traversal, PHP injection, null byte attacks. Block or log-only mode — **Shipped**
- ~~**File integrity monitoring**~~ — MD5 hash verification of core PHP files with on-demand checks — **Shipped**
- ~~**Security headers**~~ — X-Content-Type-Options, X-Frame-Options, X-XSS-Protection, Referrer-Policy, Permissions-Policy — **Shipped**
- ~~**Traffic logging**~~ — last 1,000 requests for security monitoring — **Shipped**
- ~~**Security event log**~~ — all blocked/logged threats with threat classification — **Shipped**
- ~~**Email notifications**~~ — optional alerts for lockouts, blocked attacks, file changes — **Shipped**
- ~~**Admin UI**~~ — status dashboard, configuration, blocked IPs management, security log, traffic monitor — **Shipped**

---

## v4.9.0 — Boost (Performance Suite) — SHIPPED (v4.9.0)

**Comprehensive performance optimization with admin settings panel.** Makes Outpost sites fast with caching, compression, and minification — all configurable from Settings > Boost.

- ~~**Page caching**~~ — full-page HTML cache for anonymous visitors with configurable TTL and path exclusions — **Shipped**
- ~~**Cache preloading**~~ — crawls all page and collection item URLs to warm the cache — **Shipped**
- ~~**Browser cache headers**~~ — Cache-Control, ETag, Expires, Last-Modified, 304 Not Modified for static assets — **Shipped**
- ~~**GZIP compression**~~ — compresses HTML responses, 60-80% size reduction — **Shipped**
- ~~**HTML minification**~~ — strips whitespace and comments, preserves pre/code/script/style content — **Shipped**
- ~~**Lazy loading**~~ — auto-adds loading="lazy" to images and iframes, configurable skip count — **Shipped**
- ~~**Database optimization**~~ — cleanup stale data + VACUUM to reclaim space — **Shipped**
- ~~**Developer Mode**~~ — single toggle disables all caching and optimization — **Shipped**
- ~~**Performance dashboard**~~ — cache hit rate, page count, template cache, database size — **Shipped**
- ~~**Admin UI**~~ — Settings > Boost with full configuration and real-time stats — **Shipped**

---

## v5.0 — Commerce / Payments

**Lightweight digital product sales via Stripe.** Not a full eCommerce platform — just clean checkout + access gates for digital products, courses, and memberships.

- **Stripe integration** — connect your Stripe account in Settings, configure products and prices
- **Product collection** — special collection type for sellable items (name, price, description, download file, access level)
- **Stripe Checkout** — one-click purchase flow. Customer enters payment on Stripe's hosted page, redirects back to your site.
- **Stripe Customer Portal** — members manage their own subscriptions, payment methods, and invoices
- **Member tiers with payments** — Free, Basic ($9/mo), Pro ($29/mo). Paid tiers gate content via `{% if member.plan == "pro" %}`
- **License key generation** — auto-generate unique license keys for digital product purchases
- **Download protection** — purchased files served via expiring signed URLs, not direct links
- **Purchase analytics** — revenue dashboard, MRR tracking, purchase history, refund tracking
- **Webhook events** — `purchase.completed`, `subscription.created`, `subscription.cancelled` for external integrations
- **Receipt emails** — automatic purchase confirmation emails with download links

---

## Long-Term (v5.x+)

### Outpost CLI
A standalone CLI tool (separate repo) for theme scaffolding, development server, and deployment:
- `outpost new my-theme` — scaffold a theme from Skeleton with prompts for name, author, collections
- `outpost dev` — local dev server with live reload on template changes
- `outpost validate` — lint templates and theme.json against the JSON Schema
- `outpost package` — zip a theme for distribution

### VS Code Extension
A VS Code extension (separate repo) for Outpost Liquid template authoring:
- ~~Syntax highlighting for `{{ }}` and `{% %}` tags inside HTML~~ (shipped in-app v2.7.0; v2 data-attribute highlighting shipped v4.1.1)
- Autocomplete for template tags, filters, globals, and collection fields
- Hover documentation for each tag and filter
- JSON Schema integration for `theme.json` autocomplete

### Theme Marketplace
Browse, preview, and install themes from within the admin panel:
- Curated and community-submitted themes
- One-click install from marketplace
- Theme ratings, screenshots, compatibility info
- Revenue model for premium themes

### Channels — Advanced Phases
- **Phase 3:** Inbound webhooks — receive data pushes from external services
- **Phase 4:** Outbound + advanced — filtering, pagination, channel-to-channel piping

### A/B Testing & Personalization
- Content variant testing with statistical significance
- Audience segmentation and personalized content delivery
- Integration with analytics goals for conversion tracking

---

## Not Planned

These are explicitly out of scope. Acknowledging them keeps the codebase focused.

| Feature | Why Not |
|---|---|
| Plugin ecosystem | Requires a package manager (Composer/npm) — violates the zero-config promise |
| Managed SaaS hosting | A business model, not a product feature — can be built on top of Outpost separately |
| Native mobile apps | Web admin + responsive design is sufficient; unnecessary release cycle overhead |
| MySQL/Postgres support | SQLite is the constraint that enables "backup = one file" and shared-host compatibility |
| LDAP/SAML enterprise SSO | Out of scope for indie/small-team target market; can be bolted on via custom `auth.php` |
| Full multi-tenancy | Instance isolation per customer is a hosting infrastructure problem, not a CMS feature |

---

## Core Constraints (Non-Negotiable)

These define what Outpost is. Breaking them makes it something else.

1. **Zero server-side dependencies** — No Composer, no npm on the server, no build step to deploy
2. **SQLite only** — Single-file database enables trivial backups and shared hosting compatibility
3. **Liquid templates** — No PHP in theme files; content/presentation separation is enforced (Smart Forge in v4.0 makes Liquid optional for basic content)
4. **Drop-in install** — Copy files, visit `/outpost/`, done. No CLI setup, no environment variables required

---

## Release Cadence

| Version | Theme | Status |
|---|---|---|
| ~~1.0.0~~ | Stable release | **Shipped** |
| ~~1.1–1.9~~ | Channels, Roles, Backup, Content Dir, Workflow, Media, Customizer, DX | **Shipped** |
| ~~2.0–2.5~~ | Onboarding, Folders, Forge, Analytics, API, Design System | **Shipped** |
| ~~3.0~~ | Ranger AI Assistant | **Shipped** |
| ~~3.1~~ | Releases (bundle & publish) | **Shipped** |
| ~~3.2~~ | Custom Workflows | **Shipped** |
| ~~3.3~~ | Collaboration (Comments & Review) | **Shipped** |
| 3.4 | Theme Gallery + 5 Themes | Planned |
| 3.5 | Multi-Language (i18n) | Planned |
| ~~3.6~~ | ~~Headless-First (GraphQL)~~ | **Shipped** |
| 3.7 | Collaborative Editing | Planned |
| ~~4.0~~ | Smart Forge + Frontend Drawer | **Shipped** |
| ~~4.1~~ | Template Engine v2 — Data Attribute Architecture | **Shipped** |
| ~~4.5~~ | Smart Forge AI — AI-powered HTML annotation | **Shipped** |
| ~~4.8~~ | Compass — Smart Filtering & Search | **Shipped** |
| 5.0 | Commerce / Payments (Stripe) | Planned |
| 5.x+ | CLI, VS Code Extension, Marketplace, Channels 3&4, A/B Testing | Future |
