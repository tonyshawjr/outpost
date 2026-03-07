# Changelog

All notable changes to Outpost CMS are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [1.5.2] — 2026-03-07

### Fixed
- Update checker now shows combined release notes for all versions between the installed version and the latest — previously only showed notes for the single latest release
- "What's new" section opens by default instead of collapsed

---

## [1.5.1] — 2026-03-07

### Fixed
- Update notification dot on Settings icon rendered as a horizontal oval instead of a circle — replaced with a positioned green dot on the gear icon corner (like an avatar badge)
- Docs sidebar refactored to single shared `sidebar.html` loaded via JS — adding a nav link now requires editing one file instead of 69

---

## [1.5.0] — 2026-03-06

### Added
- **Review & Approval workflow** — per-collection `require_review` toggle; editors submit items for review instead of publishing directly; admins approve or reject
- **Pending review status** — new `pending_review` item status with amber indicators throughout the admin UI (sidebar, item list, editor, right sidebar)
- **Approve / Reject endpoints** — `PUT items/approve` and `PUT items/reject` for bulk review actions (admin+ only)
- **Editorial calendar** — month-grid calendar view showing scheduled and published items across collections, with collection filter and click-to-edit navigation
- **Bulk scheduling** — select multiple items and schedule them all to a specific date/time via a datetime picker modal
- **Calendar API endpoint** — `GET calendar?start=&end=&collection=` returns items within a date range
- **Collection status counts** — collections list now returns `draft_count`, `scheduled_count`, `published_count`, `pending_count` for accurate sidebar counts
- **Webhook events** — `entry.submitted_for_review`, `entry.approved`, `entry.rejected`, `entry.scheduled` fire to registered webhooks

---

## [1.4.0] — 2026-03-06

### Added
- **User content directory** — all user-owned data consolidated under `outpost/content/` (`data/`, `uploads/`, `themes/`, `backups/`)
- Auto-migration moves existing directories into `content/` on first load with symlinks for URL compatibility
- Fresh installs create the `content/` structure automatically

### Changed
- Path constants in `config.php` now point to `content/` subdirectories
- Auto-updater skip list simplified from `['data', 'uploads', 'cache', 'themes']` to `['content', 'cache']`
- Package script produces `content/` layout with symlinks in distribution zip
- Hardcoded theme/backup paths replaced with constants in `content-api.php`, `front-router.php`, `sync-api.php`

### Security
- Backup restore now validates zip entry paths against path traversal (zip slip prevention)
- Backup restore uses `OUTPOST_UPLOADS_DIR` / `OUTPOST_THEMES_DIR` constants instead of `OUTPOST_DIR` concatenation
- `outpost_rmdir_recursive()` no longer follows symlinks — removes the symlink itself instead of recursing into the target
- `content/.htaccess` disables directory indexing

---

## [1.3.2] — 2026-03-06

### Fixed
- **Backups now include themes** — the `themes/` directory (user-customized templates, CSS, assets) is now included in backup zips alongside the database and uploads
- Restore also extracts `themes/` entries from the backup

---

## [1.3.1] — 2026-03-06

### Added
- **Auto update check** — `auth/me` includes `update_available` and `latest_version` for admin/super_admin users on every page load, using the existing 5-minute GitHub API cache
- **Sidebar update badge** — green dot on "Settings" in desktop sidebar and mobile nav when an update is available
- Badge clears immediately after applying an update via Settings → Updates

---

## [1.3.0] — 2026-03-06

### Added
- **Backup & Restore** — one-click backup of SQLite database + uploaded media as a downloadable zip
- **Restore from backup** — upload a backup zip to roll back the entire site (super_admin only)
- **Backup history** — list of recent backups with download and delete actions
- **Automatic backups** — optional daily/weekly auto-backup triggered on dashboard load with configurable max backup retention
- **Backups page** in admin sidebar (admin/super_admin only)
- 6 new API endpoints: `backup/create`, `backup/list`, `backup/download`, `backup/restore`, `backup/delete`, `backup/settings`
- `.htaccess` protection on backup directory to prevent direct web access
- Safety net on restore: current database is backed up before overwrite; restored on failure

---

## [1.2.0] — 2026-03-06

### Added
- **Content Editor cleanup** — hide Channels, Form Builder, Code Editor, Settings, and Themes from sidebar/mobile nav for editor role users
- **Collection-scoped editors** — restrict editors to specific collections via per-user grants (Settings → Team → user profile)
- **Per-page locks** — admins can lock pages to prevent editors from editing; locked pages show banner and disabled controls for non-admins
- Route guards for channels and form-builder pages (AccessDenied for unauthorized roles)
- Developer role option in user profile role selector
- `user_collection_grants` table for collection access control
- `pages.locked` column for page lock state
- `GET/PUT users/grants` API endpoints for managing editor collection grants
- `collection_grants` field in `auth/me` response for editor users
- Backend enforcement: collection list filtering, item CRUD gating, and page lock checks

---

## [1.1.1] — 2026-03-06

### Changed
- Reduce update check cache from 1 hour to 5 minutes so new releases appear faster in Settings → Updates

---

## [1.1.0] — 2026-03-06

### Added
- **RSS channel type** — connect to any RSS 2.0 or Atom 1.0 feed, auto-maps standard fields (title, link, description, pubDate, author, content, category, guid, enclosure)
- **CSV channel type** — connect to any CSV file URL (including Google Sheets exports), configurable delimiter (comma, tab, semicolon, pipe), header row detection, BOM stripping
- Source type selector in channel creation wizard (REST API / RSS Feed / CSV)
- Type badge displayed on channel cards in the channels list
- Smart defaults for RSS channels (guid as ID field, title as slug field)
- Type-dispatched schema discovery for RSS and CSV sources
- SSRF protection applied to all new channel types

### Changed
- Channel wizard Connect step now shows/hides fields based on source type (Method, Headers, Params hidden for RSS/CSV)
- Schema step hides Data Path field for non-API channels
- Channel list empty state updated to mention RSS and CSV alongside APIs

---

## [1.0.0] — 2026-03-06

First stable release. Feature-complete, security-audited, fully documented.

### Changed
- Version `1.0.0` — all release criteria met

---

## [1.0.0-beta.16] — 2026-03-06

### Fixed
- Dashboard empty state now only shows when there are truly zero pages (was triggering with 1 page)
- Empty state CTA changed from "Create your first page" to "Choose a theme" since pages are auto-discovered from templates

---

## [1.0.0-beta.15] — 2026-03-06

### Added
- Auto-Updater documentation page (`docs/features/auto-updater.html`) — full workflow, API endpoints, security, manual fallback
- Security reference page (`docs/reference/security.html`) — authentication, rate limiting, SSRF prevention, file upload security, HTML sanitization, CORS, access control
- Upgrade path documentation in Deploy guide — beta-to-stable migration notes with auto-updater and manual options
- 28 previously undocumented API endpoints added to Admin API docs — code editor CRUD, updates, cron, goals, dashboard events, revisions diff
- Auto-Updater and Security nav links added to all 67 doc page sidebars
- `llms.txt` updated with auto-updater, security, and upgrade sections

---

## [1.0.0-beta.14] — 2026-03-06

### Security
- SSRF hardening — block private IPs, loopback, cloud metadata, and non-HTTP protocols in Channels and Webhooks
- Session cookie `secure` flag — enforce HTTPS-only cookies in production for both admin and member sessions
- Fix member session key mismatch — page visibility gates (`members`/`paid`) now work correctly
- IP-based login rate limiting — admin and member login brute-force no longer bypassable by discarding session cookies
- TOTP replay prevention — same 6-digit code cannot be reused within its validity window
- TOTP verify endpoint rate limited — 5 attempts per 5 minutes per IP
- TOTP pre-session token made single-use via nonce
- Open redirect fix — `form.php` `_redirect` field now enforces relative paths only
- Email injection fix — `_notify` POST field removed from public form submissions; mailer validates email format
- DOM-based SVG sanitizer replaces regex check — blocks `xlink:href`, `data:` URIs, `foreignObject`, event handlers
- Richtext sanitizer now blocks `data:` and `vbscript:` URI schemes alongside `javascript:`
- API key auth optimized — prefix-based O(1) lookup instead of O(n) bcrypt scan (prevents DoS)
- Auto-updater URL validation hardened — strict hostname + path check via `parse_url()` instead of `str_contains`
- Zip slip prevention — validate all zip entry paths before extraction in auto-updater
- Channel API credentials masked in admin API responses
- Rate limits added to forgot/reset password endpoints (admin and member)
- CORS origin check tightened — only exact `localhost`/`127.0.0.1` matches, not substring
- Page delete path containment — `realpath()` check before `unlink()`
- Email validation added to user create/update
- Role read consistency — user create/update now uses `OutpostAuth::currentUser()` instead of `$_SESSION` directly
- Member session DB revalidation — suspended members detected within 5 minutes
- `scheduled_at` datetime format validation
- Template cache directory protected with `.htaccess` deny
- XSS fix in form builder HTML field type — now passed through sanitizer

---

## [1.0.0-beta.11] — 2026-03-06

### Added
- Built-in auto-updater — check for and apply core updates from the admin Settings panel
- `GET api.php?action=updates/check` — compares installed version against latest GitHub Release (cached 1 hour)
- `POST api.php?action=updates/apply` — downloads release zip, extracts core files (PHP, admin SPA, docs, member-pages), skips themes/data/uploads/cache
- Updates section in Settings nav with version display, release notes, and one-click update button
- Package script now produces a `dist/outpost-vX.X.X.zip` release archive for GitHub Releases
- Git workflow documentation added to CLAUDE.md

---

## [1.0.0-beta.10] — 2026-03-06

### Fixed
- Template scanner now auto-rescans when theme `.html` files are modified (no longer requires theme re-activation or zero-field state)
- Template scanner now parses closing-tag default syntax (`{{ field | raw }}Default HTML{{ /field }}` and `{{ field }}Default{{ /field }}`) — richtext and text fields with wrapping defaults now populate `page_field_registry.default_value` correctly
- Admin page editor WYSIWYG fields now show template defaults when content is empty (previously showed blank for closing-tag defaults)

---

## [1.0.0-beta.9] — 2026-03-06

### Added
- On-page editing — edit text, richtext, textarea, and image fields directly on the live frontend
- Admin bar "Edit page" toggle for enabling/disabling inline edit mode (persisted in localStorage)
- Field annotations: `cms_text/richtext/textarea/image/global()` wrap output in `data-ope-*` elements for admin users
- Collection single-page editing: `{% single %}` block fields annotated with item ID for inline updates
- Floating TipTap toolbar for richtext fields (bold, italic, headings, lists, links)
- Media picker modal for image fields (browse library, upload, select)
- Auto-save with debounced batching and visual status indicators (saving/saved)
- `PUT api.php?action=items/inline` endpoint for partial collection item field updates
- Standalone editor bundle (`on-page-editor.js` + CSS) — only loaded for authenticated admins

---

## [1.0.0-beta.8] — 2026-03-06

### Added
- `PUT api.php?action=items/inline` endpoint for partial collection item field updates (on-page editing groundwork)

---

## [1.0.0-beta.7] — 2026-03-05

### Changed
- Renamed "Taxonomies" to "Folders" and "Terms" to "Labels" system-wide — database tables, PHP backend, API endpoints, Svelte frontend, template syntax, theme templates, and documentation
- New template syntax: `{% for label in folder.slug %}` (old `{% for term in taxonomy.slug %}` still works)
- New API endpoints: `action=folders`, `action=labels`, `action=item-labels` (old endpoints aliased)
- New engine function: `cms_folder_labels()` (`cms_taxonomy_terms()` kept as alias)
- Database tables renamed: `taxonomies` → `folders`, `terms` → `labels`, `item_terms` → `item_labels` (auto-migrated on upgrade)
- Admin pages renamed: FolderManager, FolderEdit, FolderLabels, FolderLabelEdit
- All documentation updated to use folder/label terminology

---

## [1.0.0-beta.6] — 2026-03-05

### Added
- Channels (external data sources) — Phase 1: REST API channels
- `channels`, `channel_items`, `channel_sync_log` database tables with indexed queries
- Channel engine (`channels.php`) with cURL-based API fetching, pagination (offset + cursor), auth (API key, bearer, basic), dot-notation data extraction, and recursive schema discovery
- Sync engine with upsert, stale item removal, sync logging, and auto-sync on template render when cache TTL expires
- Template syntax: `{% for item in channel.slug %}` and `{% single item from channel.slug %}` with `{% else %}` support and `limit:N` / `orderby:field` options
- Channel URL pattern routing in `front-router.php` and `test-site/index.php`
- 9 API endpoints: channel CRUD, sync trigger, sync log, schema discovery, cached items preview
- Channels list page with card grid, create modal, status badges, and error display
- Channel Builder wizard with 4-step setup (Connect, Schema, Configure, Preview), Data tab, and Sync dashboard
- 6 builder subcomponents: ConnectStep, SchemaStep, ConfigStep, PreviewStep, JsonTree, KeyValueEditor
- Sidebar navigation entry for Channels under Build section

---

## [1.0.0-beta.5] — 2026-03-05

### Added
- Visual form builder with 14 field types, drag-and-drop reorder, and live preview
- `{% form 'slug' %}` template tag for rendering builder-defined forms
- Forms list page with create, edit, duplicate, and delete actions
- Enhanced submissions inbox with starring, notes, bulk actions, and status filtering
- Honeypot spam protection (auto-injected hidden field)
- Builder-aware form submission validation (required fields, schema linking)
- `forms` database table for storing form definitions
- Enhanced `form_submissions` table with form_id, status, starred, notes, user_agent columns
- Form builder API endpoints (CRUD, duplicate, star, status, notes, bulk)

---

## [1.0.0-beta.4] — 2026-03-05

### Fixed
- 480px breakpoint in `admin.css` was stripping mobile nav padding — content hidden behind bottom tab bar on small phones
- PageEditor absolute-positioned container now offsets `bottom` for mobile nav height
- CollectionEditor shell height accounts for mobile nav; topbar buttons collapse to icon-only on mobile
- MediaLibrary toolbar wraps search, type chips, and sort controls at 375px
- CodeEditor hides file tree on mobile, command palette constrained to viewport width, root height accounts for mobile nav
- Forms shell height subtracts mobile nav; toolbar and notify bar wrap properly; notify input goes full-width
- TemplateReference three-column layout stacks vertically on mobile with proper height calc
- TeamSettings and MembersSettings table headers hidden on mobile; rows wrap with always-visible action buttons
- UserProfile header wraps save actions below title on narrow viewports
- TaxonomyTerms row actions always visible on touch; count column hidden on mobile
- AnalyticsTraffic funnel wraps at 600px; stat numbers scale down; referrer/pages table columns tightened
- AnalyticsEvents table header hidden at 600px; stat numbers scale down
- IntegrationsSettings deliveries table gets horizontal scroll wrapper; event groups collapse to single column
- Dashboard action buttons wrap gracefully on mobile
- Pages and CollectionItems list rows wrap metadata below title; column headers hidden on mobile
- EmailSettings form rows stack on small phones (480px)

---

## [1.0.0-beta.3] — 2026-03-05

### Added
- **Flexible Content field type** — ACF Pro-style layout builder for collections and pages. Define named layouts (e.g. Hero, CTA, Testimonial) each with their own sub-fields. Editors add, remove, and reorder layout blocks. Template syntax: `{% for block in flexible.field_name %}` with `block.layout` to identify the layout type.
- **Relationship field type** — link content to items from another collection. Search and select items with reorder support. Stores item IDs, resolves full item data at render time. Template syntax: `{% for item in relationship.field_name %}` with all standard item fields available.
- **Conditional Logic** — show/hide collection schema fields based on other field values. Operators: equals, not equal, has value, is empty. Conditions are evaluated in real-time in the editor — hidden fields are simply not rendered. Conditions are defined per-field in the schema builder.
- `FlexibleField.svelte` component with collapsible layout rows, sub-field rendering, media picker integration
- `RelationshipField.svelte` component with collection search, multi-select, reorder controls
- Template engine regex patterns for `flexible` and `relationship` loop compilation
- `cms_flexible_items()` and `cms_relationship_items()` engine functions
- Template field extraction for flexible and relationship types in `content-api.php`
- New field types in collection schema builder dropdown with expanded options

---

## [1.0.0-beta.2] — 2026-03-05

### Changed
- Mobile admin navigation replaced with bottom tab bar + slide-up "More" drawer instead of hamburger sidebar
- Sidebar hidden via `display: none` on mobile (no more transform/position tricks)
- Hamburger toggle removed from TopBar on mobile
- Toast container and save bars now sit above the mobile bottom nav
- `sidebarOpen` store reverted to `writable(true)` (desktop-only concern now)

### Added
- `MobileNav.svelte` component with 5-tab bottom bar (Dashboard, Pages, Media, Content, More) and full More drawer with all nav items

---

## [1.0.0-beta.1] — 2026-03-05

Initial public beta release. Feature-complete across all core subsystems.

### Added

#### Content Management
- Page auto-discovery: engine crawls theme templates and auto-registers editable fields
- Page editor with dynamic field rendering, SEO meta, and visibility control (public/members/paid)
- Page delete from admin UI with cascade to fields and cache clear
- Collections CRUD with JSON schema builder and typed field definitions
- Collection items with status filters (all/draft/scheduled/published), search, and bulk operations
- Block-based collection editor with TipTap richtext, image, markdown, HTML, and divider blocks
- Scheduled publishing with automatic cron (file-based throttle, no server config required)
- Content revision history with auto-snapshots, 25-version limit per entity, and one-click restore
- Global fields scoped per active theme
- Navigation menu builder with multi-level nesting and drag reorder
- Taxonomies and terms with full CRUD, hierarchical support, and custom term field schemas
- Term assignment to collection items via junction table
- WordPress WXR import with configurable duplicate handling

#### Media
- Media library with drag-and-drop upload, grid view, search, and type filtering
- Image resize with aspect-ratio lock and crop tools
- Alt text editing per media item
- Thumbnail generation after upload, resize, and crop
- SVG upload with script/event-attribute sanitization

#### Theme System & Templates
- Liquid-style template compiler with compiled PHP caching
- Full tag support: field output, globals, meta, collection loops, single-item fetch, pagination, taxonomy loops, menu loops, gallery loops, repeater loops, conditionals, partials, SEO block, admin conditionals, comments
- All field filters: `raw`, `image`, `link`, `textarea`, `select`, `color`, `number`, `date`, `toggle`, `or_body`
- Collection loop options: `limit`, `orderby`, `paginate`, `filteredby`, `related`
- Wrapping-tag defaults syntax: `{{ field }}Fallback{{ /field }}`
- Template compile error handling: try-catch with clean 503; bad cache deleted and recompiled on next request
- Admins see stack trace on template errors; visitors see a generic message
- Theme-scoped content isolation (field values stored per active theme)
- Theme activation, duplication, and deletion with active-theme protection
- Personal blog theme — complete reference implementation (6 routes, 3 partials)
- Starter theme — minimal starting point

#### Admin UI
- Svelte 5 SPA with 22 fully implemented routes
- Settings Hub consolidating General, Email, reCAPTCHA, Sync, Integrations, Members, and Team sections
- Global content search modal (⌘K) across pages, items, and media with keyboard navigation
- Revision history sidebar tab in Page Editor
- Revision history drawer in Collection Editor with restore callback
- SEO score panel with meta title/description preview, character counters, and keyword analysis
- Right sidebar context panels per route (page info, collection schema, item meta)
- Dark mode with system preference detection and localStorage toggle
- Mobile-responsive admin with collapsible sidebar, bottom-sheet modals, and 44px touch targets
- Consistent layout token system (`--content-width`, `--content-width-wide`, `--content-width-narrow`)
- Standardized page headers, button classes, loading states, and toast feedback throughout

#### Code Editor
- VS Code-style IDE with CodeMirror 6 and multi-tab editing
- Syntax highlighting for HTML, CSS, JS, PHP, JSON, Markdown, YAML, SVG
- File tree with inline create, rename, and delete
- Command palette (⌘P) for file search
- Find in file (⌘F) and find in files (⌘⇧F) with full-text search
- Autocomplete for Liquid template syntax (globals, collections, filters)
- Status bar showing line/column, language, file size, and encoding
- All operations scoped to `themes/` directory with path traversal prevention

#### Analytics
- Privacy-first tracking: DNT header respected, bots excluded, IP never stored (daily hash with rotating salt)
- Session tracking with anonymous unlinkable session IDs
- Rate limiting: 10 hits per IP per 60 seconds
- Traffic dashboard: pageviews, unique visitors, bounce rate, device breakdown
- Top pages table sortable by views, uniques, duration, and bounce rate
- Referrers list with favicons
- SEO health checklist with critical/warning/passing groupings
- Content activity chart with weekly publishing cadence
- Member growth chart with free and paid tiers overlaid
- Custom event tracking for outbound clicks, downloads, and form submissions
- Goals dashboard with conversion tracking
- 13-month data retention with automatic pruning

#### Forms
- HTML form handling via `POST /outpost/form.php` — no builder or shortcodes required
- Submissions stored in SQLite with form name, field data, IP, and timestamp
- Admin inbox with read/unread state and per-submission detail view
- Configurable notification email per form
- SMTP email delivery with fallback to `mail()`; failures are logged and never block submission
- IP-based rate limiting: 5 submissions per 60 seconds
- reCAPTCHA v2 integration (optional, only validated when widget is present)
- CSV export of all submissions per form

#### Users & Members
- 6-role capability-based system: super_admin, admin, developer, editor, free_member, paid_member
- Admin user management: invite, create, role assignment, and delete
- Member management: list, role change, suspend/activate, delete
- Member registration and login with 24-hour email verification token
- Resend verification email flow
- Password reset for both admin and member accounts (token-based, 24-hour expiry)
- Member-only and paid-only page visibility gating enforced in router

#### API & Integrations
- REST API at `api.php?action=<endpoint>` with session auth and CSRF validation
- API key authentication with Bearer tokens for CI/CD and headless use
- API key management: create, revoke, usage tracking
- Rate limiting: 60 mutations per 60 seconds per key
- Public Content API: schema, pages, collections, items, taxonomies, terms, media — no auth, open CORS
- Webhook system: HMAC-signed outgoing webhooks with delivery queue, retry logic, and delivery history UI
- Sync API for Electron Builder with file/database sync and 30-day backup retention
- Template Reference with live Liquid rendering and saved loops database (max 20 per site)

#### Infrastructure
- SQLite with PDO, WAL journal mode, and foreign key enforcement
- Idempotent schema migrations via `PRAGMA table_info` + `ALTER TABLE`
- Database indexes on hot query paths: `fields(page_id, theme)`, `collection_items(collection_id, status)`, `revisions(entity_type, entity_id, created_at)`
- Output page cache with automatic invalidation on every content save
- Compiled template cache with file-modification-time busting
- XML sitemap auto-generated from all public pages and published collection items
- Installer wizard with PHP/PDO/GD pre-flight checks, admin user creation, and install lock
- Packaging script (`npm run package`) producing distributable `dist/outpost/` directory
- No Composer dependencies — drop-in install, runs on any shared PHP 8.x host

### Security
- Session-based authentication with CSRF token validation on all mutations
- Bcrypt password hashing (cost 12) for admin and member accounts
- API key hashing with bcrypt; plaintext shown once on creation
- Rate limiting on login (5/60s), form submissions (5/60s), and API mutations (60/60s)
- Path traversal prevention with `realpath()` scoping in code editor and media upload
- File upload allowlist by extension and MIME type; SVG sanitized before storage
- `.htaccess` protections auto-created in `data/`, `cache/`, and `uploads/` by installer
- `display_errors` disabled; all errors written to PHP error log
- Sync API enforces HTTPS except on localhost

---

## Upcoming

### [1.0.0] — Planned
- Stable release following beta testing period
- Upgrade path from beta
