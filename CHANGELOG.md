# Changelog

All notable changes to Outpost CMS are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [5.1.3] — 2026-04-13

### Fixed
- **v5 migration crash on sites with themed fields** — DELETE duplicates before UPDATE to avoid UNIQUE constraint violation on `fields` and `page_field_registry` tables. Fixes upgrade path from 4.x to 5.x.

---

## [5.1.2] — 2026-04-13

### Security
- **[CRITICAL] Sync API: removed PHP from allowed file extensions** — prevents remote code execution via sync file upload
- **[CRITICAL] Settings API: added denylist** — blocks mass assignment of security-critical keys (`sync_api_key`, `shield_config`, etc.)
- **[CRITICAL] Sync rate limiting: uses REMOTE_ADDR only** — prevents rate limit bypass via X-Forwarded-For spoofing
- **[CRITICAL] Member login: fixed open redirect** — validates redirect URL is a relative path
- Sync API key now stored as bcrypt hash (previously plaintext) — database leak no longer exposes sync credentials
- JWT signing secret now randomly generated at install — no longer derived from predictable filesystem paths
- GraphQL authentication now populates session role — mutations enforce capability-based authorization
- Admin panel now sends full security headers: CSP (with nonce), X-Frame-Options: DENY, HSTS, nosniff
- Shield security headers now include Content-Security-Policy and Strict-Transport-Security
- Non-toggleable baseline security headers (nosniff, X-Frame-Options, HSTS) always applied even if Shield is disabled
- Front-end rendered pages now receive security headers via Shield
- Sync API supports optional IP allowlisting (`sync_allowed_ips` setting)
- Role escalation prevention: only `super_admin` can assign the `super_admin` role
- GraphQL CORS restricted: wildcard origin no longer allows Authorization header
- CSRF protection now required on backup/restore and WordPress import endpoints
- Database wrapper validates table and column names against `[a-zA-Z_][a-zA-Z0-9_]*` pattern
- GraphQL introspection requires authentication
- Ranger encryption key now randomly generated (previously derived from filesystem path)
- TOTP 2FA secrets encrypted at rest using AES-256-GCM
- Webhook signing secrets encrypted at rest
- SMTP credentials encrypted at rest
- Channel API credentials encrypted at rest
- Backward-compatible decryption: existing plaintext values still work after upgrade
- Session ID regenerated on API key authentication (prevents session fixation)
- SSRF guard returns resolved IP for DNS pinning (prevents DNS rebinding attacks)
- Member registration meta fields validated against configurable allowlist
- Password complexity requirements: uppercase, lowercase, number, 8+ characters
- Database directory protected with PHP guard and .htaccess for non-Apache servers
- Member login rate limit window increased from 60s to 300s
- SSL verification enabled in cache preloader (previously disabled)
- Default "admin" username removed from installer form
- File integrity monitoring upgraded from MD5 to SHA-256
- SMTP test errors redacted from API response (logged server-side)
- All API endpoints (MCP, Sync, Member, Admin) now send nosniff and Cache-Control: no-store headers
- Static file responses now include X-Content-Type-Options: nosniff

---

## [5.1.1] — 2026-04-01

### Added
- **MCP Server** — Model Context Protocol server (`mcp.php`) lets AI tools like Claude Desktop and ChatGPT manage site content directly via JSON-RPC 2.0 over HTTP. 15 tools for collections, items, pages, globals, media, and search. 4 resources for site schema context. Uses existing API keys for authentication.
- **MCP connection info panel** — Settings → Integrations shows MCP endpoint URL and Claude Desktop config snippet with copy buttons.

### Security
- 1 MB payload size limit prevents memory exhaustion
- Rate limiting on all mutation tools via existing API rate limiter
- LIKE injection prevention in search and media queries
- Richtext fields sanitized through collection schema detection
- Error messages redacted to prevent path disclosure (full errors logged server-side)
- Origin header sanitized against newline/null byte injection
- DELETE endpoint requires authentication
- Non-string field values coerced before DB storage

---

## [5.1.0] — 2026-03-29

### Added
- **Forge Site Analysis** — One-click analysis of an entire HTML site. Cross-file diffs to extract shared partials (nav, footer, head), auto-creates navigation menus from nav links, detects editable sections and fields, annotates HTML with `data-outpost` attributes, and registers pages/fields/menus/globals in the database. No AI required — pure algorithmic using DOM tree comparison, frequency counting, and heuristic section detection.
- **Cross-file partial extraction** — Uses Site Style Trees approach: structural hashing of DOM nodes, 80% frequency threshold for shared block detection. Extracts `partials/head.html`, `partials/nav.html`, `partials/footer.html` automatically.
- **Nav auto-detection** — Parses `<nav>` element, detects dropdown hierarchies (ul/li nesting, div wrappers), builds parent-child menu items, creates menu in database.
- **Head classification** — Splits `<head>` into shared elements (fonts, CSS, GA4, favicons) vs per-page elements (title, meta description, OG tags, inline styles). Replaces with `<outpost-meta>` and `<outpost-seo>`.
- **Global field detection** — Scans extracted nav and footer partials for editable content (logo, copyright, address, phone, social links) and registers as global fields with `data-scope="global"`.
- **Section detection heuristics** — Priority: `<!-- section:name -->` comments → class names (`.hero`, `.about`, `.cta`) → landmark elements → h2 headings → default. Developer contract: use section comments for best results.
- **Preview before apply** — Analysis returns a full preview (pages, fields, partials, menus, globals, warnings) before writing any files. User can skip individual files.
- **Automatic backup** — Creates `.forge-backup/{timestamp}/` with all originals before modifying files.
- **Admin UI** — "Analyze Site" button in Code Editor with modal showing analysis preview, menu tree view, per-page field counts, warnings, and apply button.

### Security
- Path traversal protection on all file read/write operations
- PHP code injection prevention (strips `<?php` tags from rewritten templates)
- File count limit (100 files) and size limit (2MB per file) for DoS prevention
- Partial name sanitization prevents directory traversal
- All database queries parameterized

---

## [5.0.0] — 2026-03-29

### Changed
- **BREAKING: Removed theme layer** — Site root is now the content source. Outpost is just a `/outpost/` folder alongside your HTML files. No more `content/themes/`, no `active_theme` setting, no theme switching.
- **Code editor scoped to site root** — Shows all files at site root, excludes `outpost/` engine directory.
- **Forge scans site root** — Smart Forge operates on HTML files at site root instead of theme directories.
- **Updater simplified** — Updates only touch `outpost/` core files. User site files are never modified by the updater.
- **Sync API v2** — Pull/push operates on site root files. Paths are relative to root (e.g., `index.html` not `themes/slug/index.html`).
- **Installer writes `.htaccess`** — Rewrite rules route HTML through Outpost while serving static assets directly.
- **Auto-migration** — Existing v4 sites automatically migrate: active theme files copied to site root, field data unified, `.htaccess` created.

### Removed
- **Themes page** — No theme management UI (create, duplicate, upload, activate, delete, export).
- **Customizer** — Removed entirely. CSS variables managed by developers directly.
- **Theme Gallery** — Removed from roadmap.
- **Managed theme updates** — No `.outpost-manifest.json` conflict detection.

### Security
- Block sensitive outpost paths on PHP dev server (database file disclosure)
- Case-insensitive filesystem bypass protection for `/outpost/` routing
- Null byte injection protection in code editor and Ranger path validation
- Precise prefix matching prevents false positives on `outpost-*` sibling directories
- Dot-segment bypass protection for `outpost/` checks
- Empty active_theme guard in v5 migration

---

## [4.13.8] — 2026-03-29

### Changed
- **Ship only Forge Playground theme** — Removed Starter and Personal themes from the distribution. Forge Playground is now the only bundled theme.
- **Updated default theme fallback** — All PHP files that defaulted to `'starter'` or `'personal'` now default to `'forge-playground'`.

---

## [4.13.7] — 2026-03-29

### Added
- **Auto-inject client assets** -- Compass, Search, and Auth CSS/JS are automatically injected into rendered HTML when their data attributes are detected. Themes no longer need manual `<script>`/`<link>` tags.
- **Member auth conditionals** -- `<outpost-if member="logged-in">` and `<outpost-if member="logged-out">` show/hide template content based on member login state.

### Security
- **Fix duplicate asset injection** -- `str_ireplace` replaced all `</body>` occurrences causing duplicate script tags. Now uses single-pass `strripos` injection targeting the last tag only.
- **Fail-closed member conditionals** -- Invalid `member` attribute values (e.g. `member="paid"`) now hide content instead of showing it to everyone.
- **Attribute-context detection** -- Asset injection now checks for data attributes in HTML attribute context (not plain text), preventing user content from triggering unwanted script loads.
- **Buffer cleanup on error** -- Catch block now cleans all output buffers on error instead of just one, preventing partial HTML leaks before the 503 page.
- **Remove error suppression** -- Removed `@` on `require_once` for members.php to surface errors during development.

---

## [4.13.6] — 2026-03-29

### Fixed
- **Compass template destroyed by Liquid compilation** — Fixed Liquid engine corrupting Compass templates during render.

---

## [4.13.5] — 2026-03-29

### Fixed
- **Builder push file corruption** — Fixed file corruption during Builder push operations, preserved active_theme setting.

---

## [4.13.4] — 2026-03-29

### Fixed
- **index.php front controller for v2 template engine** — Fixed front controller routing for v2 data-attribute templates.

---

## [4.13.3] — 2026-03-28

### Fixed
- **Sync path validation stripping leading slash** — Fixed path validation that incorrectly stripped the leading slash from sync paths.

---

## [4.13.2] — 2026-03-28

### Changed
- **Sync API allows images and fonts** — Sync API now accepts image and font file types. Builder walks all asset types.

---

## [4.13.1] — 2026-03-24

### Added
- **Register API custom meta fields** -- `POST register` now accepts `display_name` (from first/last name) and `meta` object for custom profile fields (e.g. `is_military`, `is_first_responder`). Stored in the users table `meta` JSON column.
- **Auth client meta collection** -- `data-auth-meta="key"` attribute on checkboxes/inputs in register forms. Values collected and sent as `meta` object on registration.

### Fixed
- **Compass template fallback** -- If the `<template>` DOM caching fails, Compass now extracts the template from the original HTML string as a fallback. Fixes "0 results" when items exist.

---

## [4.13.0] — 2026-03-24

### Added
- **Auth client JS** -- New `auth-client.js` auto-discovers forms with `data-outpost-auth="login|register|forgot-password"` and wires them to the member API. Session-based login with rate limiting, CSRF tokens, error/success handling, and redirect support. Auto-injected when `data-outpost-auth` is detected on the page.
- **Theme auth forms pattern** -- Developers can build custom login/register/forgot-password pages in their theme using standard HTML forms with `data-outpost-auth` attribute. No form builder needed. Documented in developer docs.

---

## [4.12.3] — 2026-03-24

### Changed
- **Lodge page selectors scan theme files** -- Dropdowns now show only flat HTML pages from the active theme (root-level .html files), excluding collection templates, partials, index, and 404. No more listing every page in the database.

---

## [4.12.2] — 2026-03-24

### Fixed
- **Lodge page dropdowns empty** -- Pages API now supports `all=1` parameter to return all pages without theme-field filtering. Lodge page selectors show login, register, forgot-password and all other pages.
- **Compass template caching** -- Improved `<template>` content extraction with try/catch and childNodes fallback. Fixes search results showing "0 results" when items exist.

---

## [4.12.1] — 2026-03-24

### Changed
- **Lodge config moved to Lodge page** — URL slug, login page, register page, and forgot password page selectors moved from Settings > Features to the Lodge admin page under Members. Features page now only has the on/off toggle.
- **Fixed Unicode encoding in client JS/CSS** — Replaced fancy Unicode characters (em dashes, box-drawing) with ASCII equivalents to prevent garbled text when served without UTF-8 headers.

---

## [4.12.0] — 2026-03-24

### Added
- **Lodge custom pages** — Configure custom login, register, and forgot password pages in Settings > Features > Lodge Configuration. Dropdown selects from your theme's pages. When set, Lodge redirects unauthenticated users to your custom page instead of the built-in Outpost login. Leave blank to use the default.

### Fixed
- **Compass client-side template rendering** — Fixed `<template>` content extraction using `DocumentFragment.cloneNode()` instead of `.innerHTML` which returns empty for `<template>` elements in the browser DOM.

---

## [4.11.5] — 2026-03-24

### Fixed
- **Compass URL state restore** — Visiting a page with filter params in the URL (e.g. `?q=woodland`) now auto-triggers the search on page load. Previously required clicking the submit button manually. Fires `compass:stateChange` event so page-level scripts (carousel/results toggles) react immediately.
- **Compass auto-populate timing** — URL state is now restored after dropdown auto-populate completes, preventing race conditions where filters were applied before options were loaded.

### Changed
- **Compass asset cache-busting** — `compass-client.js` and `compass-client.css` are now injected with `?v=` version query string to prevent stale browser cache after updates.

---

## [4.11.4] — 2026-03-24

### Fixed
- **Compass search Enter key** — Pressing Enter in a search input with a submit button now triggers the search instead of doing nothing
- **Compass client-side rendering** — When the API returns JSON items but no server HTML, Compass now renders results using a `<template data-compass-template>` tag in the results container. Template is cached on init so it survives innerHTML replacement.
- **Compass dropdown counts** — Counts no longer shown in dropdown options by default. Add `data-show-counts="true"` to opt-in. Keeps dropdowns clean.

---

## [4.11.3] — 2026-03-24

### Added
- **Compass searchable dropdowns** — Dropdowns with 15+ options are automatically upgraded to a searchable dropdown with a type-to-filter input, scrollable option list, and keyboard support. Theme developers control this with `data-searchable="true"` (force on), `data-searchable="false"` (force off), or no attribute (auto at 15+ options). Custom threshold via `data-searchable-threshold="20"`. No extra markup needed — Compass enhances the standard `<select>`.

---

## [4.11.2] — 2026-03-24

### Fixed
- **Compass auto-populate broken** — Dropdown and checkbox filters were sending `source: "folder:categories"` to the API instead of `facet: "categories"`. The `compass-client.js` auto-populate function now strips the `folder:`/`field:`/`label:` prefix and uses the correct `facet` parameter name. This caused empty dropdowns on any page using Compass with `data-source` prefixes.

---

## [4.11.1] — 2026-03-24

### Added
- **Environment indicator** — Top bar now shows a color-coded pill (Local/Staging/Production) auto-detected from hostname. Blue for localhost, yellow for staging subdomains, green for production. Zero configuration.

---

## [4.11.0] — 2026-03-24

### Added
- **RSS Feed Generator** — Auto-generated RSS 2.0 feeds for collections. Site-wide feed at `/feed.xml` and per-collection feeds at `/{collection}/feed`. Per-collection `feed_enabled` toggle. Auto-discovery `<link>` tags injected in `<head>` via `cms_seo()`. Handles excerpt generation, XML escaping, missing fields gracefully.
- **Site Search** — Full-text site-wide search across all pages and collection items. New `<outpost-search>` template tag compiles to a search widget with debounced input, AJAX results, and URL state sync. Public API at `search/site` with rate limiting (30/min). Search index with incremental updates on publish. Results ranked by title relevance with highlighted excerpts. Search queries logged to analytics. Vanilla JS client (134 lines, no dependencies) with minimal CSS.
- **Sitemap Enhancement** — Per-collection `sitemap_enabled` toggle to exclude collections from sitemap.xml. Draft pages and member-only/paid-only pages now properly excluded. Added `<changefreq>` and `<priority>` hints. Auto-generated `/robots.txt` with sitemap reference and admin path blocking.

### Security
- **Search excerpt XSS prevention** — HTML-escapes excerpt text before injecting `<mark>` highlight tags
- **Feed open redirect protection** — Collection slug sanitized to alphanumeric/hyphens only

---

## [4.10.0] — 2026-03-23

### Added
- **URL Redirects** — Full redirect manager with 301/302/307 support. Exact path matching, wildcard patterns (/old/*), and regex matching (~^/post/(\d+)$). Hit tracking with counters, active/inactive toggle, CSV import, URL tester, and admin UI at Redirects page. Integrated into front-router.php early in the request lifecycle before theme routing.
- **Avatar Menu** — User account dropdown in the top bar with profile, settings, backups, calendar, dark mode toggle, and logout. Replaces user-related items that were previously in the sidebar.

### Changed
- **Sidebar reorganization** — Restructured sidebar into five groups: Content (collections + media), Site (globals, navigation, themes, brand), Build (code editor, forms, channels, collections management, folders), Tools (analytics, redirects, shield, boost, review links, releases, workflows), and Members (members, lodge). Pinned/favorites system with localStorage persistence. Ranger moved to bottom of sidebar. Settings, backups, calendar, profile, dark mode, and logout moved to the avatar dropdown menu.

---

## [4.9.0] — 2026-03-23

### Added
- **Boost Performance Suite** — Comprehensive performance optimization system with admin settings panel. Includes configurable page caching with TTL and path exclusions, browser cache headers (Cache-Control, ETag, Expires, 304 Not Modified) for static assets, GZIP compression for HTML responses, HTML minification (preserves pre/code/script/style), automatic lazy loading for images and iframes, database optimization (VACUUM + stale data cleanup), cache preloading (warms all page and collection item URLs), and a real-time performance dashboard with cache hit rate, cache size, and database size. Developer Mode toggle disables all caching and optimization with one click.
- **Shield Security Suite** — Comprehensive security hardening system with admin settings panel at Settings > Shield. Login lockout protection (configurable attempts and duration with auto-IP-blocking), WAF-lite firewall (blocks SQL injection, XSS, path traversal, PHP injection, null byte attacks in block or log-only mode), IP blocklist (manual and automatic with expiring bans), file integrity monitoring (MD5 hash verification of core PHP files with on-demand checks), security headers (X-Content-Type-Options, X-Frame-Options, X-XSS-Protection, Referrer-Policy, Permissions-Policy), live traffic logging (last 1,000 requests), security event log with threat indicators, and optional email notifications for lockouts, blocked attacks, and file changes. All configurable via a clean tabbed UI with status dashboard, blocked IPs management, security log viewer, and traffic monitor.

---

## [4.8.4] — 2026-03-23

### Changed
- **Compass v2 — Data-attribute architecture** — Rebuilt the entire Compass filtering system to use `data-compass` attributes on native HTML elements instead of custom `<outpost-compass>` wrapper elements. Developers write their own HTML and add data attributes to make elements smart. No generated wrapper divs, no forced classes, no locked-in layout. The `<outpost-compass>` tag syntax still works for backward compatibility — it now compiles to the same clean data-attribute HTML.
- **Compass client JS rewrite** — New controller-per-collection architecture. Elements are discovered by `[data-compass]` attribute and grouped by `data-collection`. Supports instant filtering and submit-button mode. Auto-populates empty dropdowns, checkboxes, and radio containers from the API.
- **Compass CSS minimal** — Reduced from 362 lines to ~95 lines. Only styles auto-generated content (checkbox labels, pager buttons, A-Z buttons, selection pills, loading state). Theme-owned elements (inputs, selects, buttons) are never styled by Compass.

---

## [4.8.0] — 2026-03-22

### Added
- **Compass** — Smart filtering and search system for any collection. Template-driven faceted navigation with 12 filter types: search, dropdown, checkbox, radio, range, A-Z, toggle, proximity, hierarchy, time-since, pager, and sort. URL state sync, automatic mobile flyout, indexed search with incremental updates, and CSS custom properties for theming. Zero PHP or JavaScript required.

---

## [4.6.14] — 2026-03-22

### Added
- **GraphQL API** — Full GraphQL endpoint at `/outpost/graphql.php` with public read queries and authenticated CRUD mutations. Zero dependencies — pure PHP recursive descent parser with support for field selection, aliases, variables, fragments, and full introspection. Works with GraphiQL, Apollo Studio, and any GraphQL client.
- **GraphQL Playground** — Interactive GraphiQL explorer at `/outpost/graphql-playground.html` for testing queries and mutations.
- **GraphQL mutations** — 12 write operations (createItem, updateItem, deleteItem, updatePage, updateGlobals, deleteMedia, assignLabels, removeLabels, createCollection, updateCollection, createFolder, createLabel) secured with Bearer API key auth.

---

## [4.6.13] — 2026-03-22

### Fixed
- **Repeater loops inside collection singles** — Repeater fields inside `<outpost-single>` blocks now render correctly instead of being silently skipped

---

## [4.6.12] — 2026-03-22

### Added
- **Repeater sub-field editor** — Visual sub-field builder for repeater fields in the schema editor with name, type, and label per sub-field
- **Negate conditional support** — Conditional logic now supports negation (NOT operator) for "show when field is NOT value" rules

---

## [4.6.11] — 2026-03-22

### Fixed
- **RepeaterField component** — Proper labels on repeater rows, select dropdowns render correctly, toggle fields work as expected

---

## [4.6.10] — 2026-03-21

### Added
- **Repeater + Gallery field types** — Schema builder type dropdown now includes Repeater and Gallery as selectable field types

---

## [4.6.9] — 2026-03-21

### Fixed
- **Repeater field rendering** — Repeater fields (hours, skills) in collection editor now render with proper add/remove row UI instead of showing gallery "Add Photo" button
- **Label checkbox overflow** — Label checkboxes in editor right sidebar now scroll at 200px max-height instead of overflowing
- **Redundant schema fields removed** — Removed redundant tags/status text fields from collection schemas (handled by folder labels and DB columns)

---

## [4.6.8] — 2026-03-21

### Fixed
- **Repeater field prop mismatch** — Repeater field component in collection editor was receiving wrong prop name (`fields` → `schema`) and array-format sub-field definitions needed conversion to flat map

---

## [4.6.7] — 2026-03-21

### Added
- **Collection editor field controls** — Collection editor now renders proper UI controls for all field types: select (dropdown), image (picker + preview), richtext (TipTap editor), color (native picker), repeater (add/remove rows), gallery (sortable image grid)

### Fixed
- **Schema normalization** — Schema normalization on collection create/update API — auto-converts `{fields:[{name,type}]}` array format to flat `{fieldName:{type}}` map the admin UI expects

---

## [4.6.6] — 2026-03-21

### Fixed
- **Collection sort_field validation** — API now rejects invalid sort fields (like `title`) that crash SQL queries, silently defaults to `created_at`
- **Sort field validation scope** — Sort field validation also applied to items list query and front-end engine collection loop

---

## [4.6.5] — 2026-03-21

### Fixed
- **Orphan closing tag cleanup** — V2 template engine orphan closing tag cleanup — `</outpost-include>`, `</outpost-meta>`, `</outpost-seo>`, `</outpost-pagination>`, `</outpost-form>` no longer leak into rendered HTML
- **Include tag regex** — `outpost-include` regex now consumes optional `</outpost-include>` closing tag
- **Package script fix** — Package script variable reference fix (`phpFiles` → `coreFiles`)

---

## [4.6.4] — 2026-03-21

### Added
- **Browse/Edit mode toggle** — On-page editor no longer intercepts link clicks by default. Site works like a normal website until the Edit drawer is opened, then click-to-edit activates. Close the drawer to browse again.
- **`<outpost-form>` support in v2 template engine** — Forms now render correctly in v2 data-attribute themes.
- **Sidebar collapse/expand all** — Button in the logo bar to collapse or expand all sidebar accordion groups at once.

### Fixed
- **Partial cache invalidation** — v2 template engine now checks partial file mtimes, so adding or editing partials triggers automatic recompilation.
- **Smart Forge invalid data-type generation** — AI prompt restricts `data-type` to 9 valid values. Added post-processing sanitizer. Fixed v1→v2 filter mapping.
- **Builder symlinks** — Builder now creates `outpost/themes` and `outpost/uploads` symlinks on pull so the PHP dev server can serve theme assets.
- **Builder CLAUDE.md** — Complete rewrite: explicitly says "never build in forge-playground", includes full v2 template syntax, valid data-types, partials structure, collection/page data from snapshot.

### Changed
- **Developer docs overhaul** — Content API (Pages vs Items, error handling, edge cases), routing (full algorithm, standalone PHP bootstrap), Lodge (corrected routing to match sub-router), headless CMS recipe. All reflected in `llms.txt`.
- **Sidebar group label padding** — Group labels now align with sidebar items below them.

---

## [4.6.2] — 2026-03-20

### Added
- **`<outpost-form>` support in v2 template engine** — Forms now render correctly in v2 data-attribute themes. Previously only the v1 Liquid engine handled `{% form %}` tags.

### Fixed
- **Partial cache invalidation** — v2 template engine now checks partial file mtimes when determining whether to recompile. Adding or editing partials triggers automatic recompilation instead of requiring a manual cache clear.
- **Smart Forge invalid data-type generation** — AI prompt now restricts `data-type` to the 9 valid values (richtext, image, link, textarea, select, toggle, color, number, date). Added post-processing sanitizer that strips invalid types. Fixed v1→v2 conversion mapping filters to correct data-types instead of using literal filter names.

### Changed
- **Developer docs overhaul** — Comprehensive updates to Content API docs (Pages vs Items guide, error handling, edge cases), routing docs (full algorithm, standalone PHP bootstrap), Lodge docs (corrected routing to match actual sub-router), and headless CMS recipe. All changes reflected in `llms.txt`.

---

## [4.6.1] — 2026-03-20

### Added
- **Lodge admin page** — Dedicated Lodge page in the admin sidebar with pending review queue (approve/reject member submissions) and Lodge-enabled collections overview with config summary.

---

## [4.6.0] — 2026-03-20

### Added
- **Lodge — Member-Owned Content Portal** — First-class member portal feature. Members log in and manage their own collection items from the front-end. Supports directories, marketplaces, job boards, membership platforms — any site where members create content.
- **Lodge API** — 8 new endpoints on `member-api.php`: `lodge/dashboard`, `lodge/items` (GET/POST/PUT/DELETE), `lodge/profile` (GET/PUT), `lodge/upload`. All scoped to the authenticated member's own items via `owner_member_id`.
- **Lodge collection config** — Per-collection Lodge settings: enable/disable, allow create/edit/delete, require approval, max items per member, editable fields whitelist, read-only fields, required tiers.
- **Lodge template tags** — Three new custom elements for themes: `<outpost-lodge-dashboard>`, `<outpost-lodge-items>`, `<outpost-lodge-form>` (auto-generates form from collection schema).
- **Lodge routing** — Configurable URL slug (default `/lodge`). Maps to `lodge/dashboard.html`, `lodge/edit.html`, `lodge/create.html`, `lodge/profile.html` in theme. Auto-redirects to login for unauthenticated visitors.
- **Lodge starter templates** — 4 ready-to-use templates in the Personal theme: dashboard, edit, create, and profile pages.
- **Lodge approval workflow** — Items created with `require_approval` go to `pending_review`. Admin review queue via `lodge/pending` API endpoint.
- **Lodge webhooks** — `lodge.item_created` and `lodge.item_updated` webhook events.
- **Lodge tier gating** — `required_tiers` config restricts Lodge access by member tier (free, paid, custom).
- **Feature toggles** — Settings > Features tab with toggles for 14 admin sidebar features: Collections, Channels, Forms, Members, Lodge, Analytics, Media, Code Editor, Navigation, Releases, Workflows, Review Links, Backups, Ranger. Disabled features hide from sidebar but data is preserved.
- **Sidebar accordion groups** — Sidebar restructured into 5 collapsible groups: Content, Site, Members, Build, Insights. Collapsed state persisted to localStorage. Groups with zero visible items auto-hide.
- **Member tier and meta columns** — `tier` (TEXT, default 'free') and `meta` (JSON) columns on users table for flexible membership tiers and site-specific data.
- **Item ownership column** — `owner_member_id` on `collection_items` for Lodge item ownership tracking.

---

## [4.5.0] — 2026-03-20

### Added
- **Smart Forge AI** — When an AI API key is configured (Claude, OpenAI, or Gemini via Ranger settings), Smart Forge uses AI instead of the PHP scanner for dramatically better HTML annotation results. AI understands semantic context, names fields intelligently, and detects section boundaries more accurately.
- **API: `forge/ai-scan`** — POST endpoint that sends raw HTML to the configured AI provider with a detailed annotation prompt, returns fully annotated HTML with data-outpost attributes and section comments.
- **API: `forge/ai-status`** — GET endpoint that returns whether AI is available and which provider is configured.
- **Automatic fallback** — If no AI key is configured, Smart Forge falls back to the existing PHP scanner seamlessly. The button label changes between "Smart Forge AI" and "Smart Forge" based on availability.

---

## [4.4.0] — 2026-03-20

### Added
- **JWT Bearer Token Auth** — Stateless HS256 JWT authentication for mobile apps and headless clients. New `php/jwt.php` with pure-PHP implementation (no external libraries). Tokens include user ID, type, issued-at, and expiry claims.
- **Member Token API** — Four new endpoints on `member-api.php`: `POST token` (exchange credentials for JWT), `POST token/register` (register and get JWT), `POST token/refresh` (refresh expiring token), `GET token/me` (get profile via bearer token).
- **CORS Configuration** — `api_cors_origins` setting in the settings table controls allowed origins for cross-origin requests. Supports `*` (wide open), comma-separated origin list, or unset (localhost only). Applied to both `api.php` and `member-api.php`.
- **Bearer Auth on Member API** — All authenticated `member-api.php` endpoints now accept `Authorization: Bearer <jwt>` as an alternative to session cookies. CSRF checks are skipped for token-authenticated requests.

---

## [4.3.0] — 2026-03-20

### Added
- **Gallery Field Editor** — EditDrawer now supports `gallery` type fields with a 2-column thumbnail grid, add/remove/reorder controls, and an inline media picker modal.
- **Image Alt Text from Media Library** — Image fields in the EditDrawer now show an "Alt text" input below the image that reads/writes the media record's `alt_text` column directly, with debounced auto-save.
- **Editor Media Picker** — Shared media picker modal for the on-page editor, with image grid, upload support, and selection — used by both gallery and image fields.
- **API: `editor/media-lookup`** — GET endpoint to look up a media record by file path, returning alt_text and metadata.
- **API: `editor/media-alt`** — POST endpoint to update a media record's alt_text by file path.

---

## [4.2.0] — 2026-03-20

### Added
- **Click-to-Edit Bridge** — Click any element in the editor preview to jump to its field in the sidebar. Hover shows dotted outlines and field name labels. Click a block background to see all fields in that block.
- **Editor mode rendering** — v2 engine keeps `data-outpost` attributes in output when page is loaded in editor iframe (`?_outpost_editor=1`), enabling bridge JS communication.
- **Block attribute injection** — Engine adds `data-outpost-block="name"` to block wrapper elements in editor mode for block-level click targeting.
- **Reverse highlighting** — Sidebar can highlight elements on the preview page when hovering over fields.
- **Storyblok-style EditDrawer** — Three-level drill-down sidebar: section list → section detail with General/Style tabs → field detail. Breadcrumb navigation. Bridge click integration jumps to matching section/field.
- **Skeleton theme v2 showcase** — Complete theme rewrite demonstrating all v2 features: 7 homepage blocks with outpost-settings (colors, layout variants, ranges, toggles), repeaters (features, testimonials, team, skills), collection loops with pagination and filtering, folder taxonomy loops, single items with related posts, global nav/footer blocks, conditionals, and complete responsive CSS using var() and [data-layout] attribute selectors.

---

## [4.1.1] — 2026-03-20

### Added
- **Code Editor: v2 syntax highlighting** — High-contrast, colorblind-accessible highlighting for Outpost v2 template syntax in the code editor. `data-outpost` attributes (gold), `data-type`/`data-bind` (cyan), `data-scope` (magenta), `<outpost-*>` custom elements (green), `<!-- outpost: -->` block comments (amber background), `<!-- outpost-settings: -->` (blue background). All bold weight for maximum visibility.
- **Code Editor: v2 autocomplete** — Typing `data-` suggests `data-outpost`, `data-type`, `data-scope`, `data-bind` with type values. Typing `<outpost-` suggests all custom elements. Typing `<!-- outpost` suggests block comment patterns.

---

## [4.1.0] — 2026-03-20

### Added
- **Template Engine v2 (Data Attribute Architecture)** — New template engine that uses HTML data attributes (`data-outpost="field"`) and custom elements (`<outpost-each>`, `<outpost-single>`, `<outpost-if>`, `<outpost-menu>`, `<outpost-include>`, `<outpost-meta>`, `<outpost-seo>`, `<outpost-pagination>`) instead of Liquid-style `{{ }}` / `{% %}` syntax.
- **Block grouping** via HTML comments (`<!-- outpost:hero -->...<!-- /outpost:hero -->`) for editor section navigation.
- **Block settings** via `<!-- outpost-settings: -->` comments that output CSS custom properties and data attributes.
- **Global field support** via `data-scope="global"` attribute on any element.
- **Auto-hide** — elements with empty field values are automatically removed from output (no explicit conditionals needed).
- **`data-bind` attribute** for setting HTML attributes from item data in loops (e.g., `data-bind="datetime:published_at"`).
- **Gallery loop** support via `<outpost-each gallery="name">`.
- **Clean public output** — all Outpost syntax stripped from live site HTML; zero CMS fingerprints.
- **Editor mode** — data attributes preserved in editor preview for click-to-edit bridge.
- **Automatic engine detection** — themes using `data-outpost` attributes are auto-detected and compiled with v2 engine; v1 Liquid themes continue working unchanged.
- **v2 field scanner** — template scanner detects `data-outpost` fields and `data-scope="global"` globals for automatic field registration.

### Changed
- **Personal theme** rewritten to use v2 data-attribute syntax across all 9 template files (index, blog, post, about, contact, 404, head, nav, footer partials).
- **Front router** updated to auto-detect engine version and load appropriate template engine.

---

## [4.0.0] — 2026-03-20

### Added
- Frontend visual editor with top bar, icon rail, and drawer panels
- Smart Forge HTML scanner for auto-detecting editable fields
- Theme manifest system for field/global/collection discovery per template
- Editor overlay with page scaling and live preview

---

## [3.3.3] — 2026-03-19

### Added
- **Comment email notifications** — @mentioned users and reply thread participants receive email notifications. External review comments notify admins. Failures are logged but never block comment creation.
- **Comment count badges** — Collection item lists show a small comment bubble with count for items with open discussions. Review Links page shows open comment counts per token.

---

## [3.3.2] — 2026-03-19

### Changed
- **Review overlay rewrite** — Complete rewrite of the client-facing review overlay with dark panel (#3D3530), friendly element descriptions instead of raw CSS selectors, proper pin positioning, element highlighting on hover in comment mode, name/email modal on first use, and namespaced CSS classes to avoid host page conflicts.

### Fixed
- **Update check "Check again" button** — Now forces a fresh check by clearing the cache, instead of returning stale cached results.

---

## [3.3.0] — 2026-03-19

### Added
- **Collaboration & Comments** — Team members can leave threaded comments on collection items directly in the editor. Comments appear in a new "Comments" tab in the right sidebar with open/resolved filtering, @mention autocomplete, and reply threads.
- **Client Review Links** — Generate shareable review URLs that inject a lightweight feedback overlay onto the live site. External reviewers click any element to leave comments without needing an admin account. Token-based authentication with optional page restriction and expiry.
- **Review overlay** — Vanilla JS script (<10KB) injected via `?review=TOKEN` query parameter. Features: floating "Leave Feedback" button, element click-to-comment, numbered comment pins, slide-out feedback panel, localStorage-persisted reviewer identity.
- **Review Links admin page** — New page under Settings in the sidebar for creating, managing, and sharing review links. Copy URL, activate/deactivate, and delete tokens.
- **Comment activity feed** — API endpoint for recent comments across all content, powering future dashboard widgets.
- **@mention support** — Type `@username` in comments to mention team members. Mention records stored in `comment_mentions` table for future notification support.
- **Ranger tool** — `manage_comments` tool added to Ranger AI assistant for listing, creating, resolving, and deleting comments, plus creating and listing review links via natural language.

---

## [3.2.0] — 2026-03-19

### Added
- **Custom Workflows** — Define custom approval stages per collection. Create workflows with arbitrary stages (e.g., Draft → Copy Review → Approved → Published), each with configurable colors, role-based permissions, and allowed transitions.
- **Two built-in workflows** — "Simple" (Draft → Published) and "Editorial" (Draft → Review → Approved → Published) created automatically on first run. Collections without an assigned workflow use the Simple default.
- **Workflow transitions** — Click status badges in the item list or editor sidebar to move content through workflow stages. Role-based enforcement ensures only authorized users can advance content.
- **Transition history** — Every stage change is recorded with user, timestamp, and optional note. Visible in the editor's History tab.
- **Bulk workflow transitions** — Select multiple items and move them to any stage in one action.
- **Workflow assignment** — Assign workflows to collections via the collection schema editor. Each collection can have its own approval process.
- **Workflows admin page** — New page under Content in the sidebar for creating, editing, and deleting custom workflows with a visual stage pipeline builder.
- **Ranger integration** — `manage_workflows` tool added to Ranger AI assistant for managing workflows via natural language.
- **Webhook event** — `workflow.transition` event fired on every stage change with full context.

---

## [3.1.0] — 2026-03-19

### Added
- **Releases** — Bundle multiple content changes into a named release and publish them all at once. Create draft releases, add changes (fields, items, pages, menus, collections), publish atomically in a single transaction, and roll back published releases to revert all changes. Full API with list, create, update, delete, publish, rollback, and change management endpoints.
- **Releases UI** — New admin page accessible from the Content sidebar section. List view with status badges (Draft/Published/Rolled Back), change counts, and creation info. Detail view shows all bundled changes with type/action labels and inline add/remove. Create/edit modal for release metadata.
- **Releases Ranger tool** — `manage_releases` tool added to Ranger AI assistant for creating, publishing, rolling back, and managing releases via natural language.

---

## [3.0.0] — 2026-03-19

### Added
- **Ranger AI Assistant** — AI-powered assistant built into the admin panel sidebar. 35 tools covering every CMS operation: content management, theme building, file operations, media upload from URLs, channel configuration, form management, user/member management, webhooks, backups, database queries, template debugging, email config, navigation, settings, and frontend actions (dark mode, navigation toggle).
- **Multi-provider AI** — 3 AI providers supported: Claude (Anthropic), OpenAI (GPT-4o/GPT-4), and Google (Gemini). Configure via Settings > Integrations with your own API key.
- **Streaming responses** — Real-time SSE streaming with live tool execution display. Tool calls show as compact cards with status, pulse animation while running, and results on completion.
- **Conversation history** — Persistent conversation history with auto-generated titles, searchable history panel, and conversation management (rename, delete).
- **Token usage tracking** — Per-message token counts with cost display in USD. Tracks input, output, and cache tokens across all providers.
- **Prompt caching** — Claude provider uses `cache_control` ephemeral caching on system prompt and tool definitions. After the first message, subsequent rounds reuse cached prefixes for up to 90% input token cost reduction.
- **Dynamic tool loading** — Intent classifier analyzes each message and sends only relevant tools (UI intents get 3 tools, build intents get 10, content intents get 13) to minimize token usage.
- **Screenshot paste support** — Paste screenshots directly into the chat input for visual context (Claude provider).
- **Configurable output style** — Choose between concise, detailed, casual, technical, or custom output styles. Custom style accepts free-text instructions.
- **Role-scoped tools** — Editors get content tools, developers get code tools, admins get everything. Tool availability enforced server-side via capability checks.
- **Deep product expertise** — System prompt includes comprehensive Outpost template syntax, field types, and CMS architecture knowledge for accurate assistance.
- **Forge Playground reset** — One-click reset button on the Themes page for themes with `.forge-snapshot` backup directories.

### Changed
- **Ranger UI: Production polish** — Mobile responsive (full-width overlay with slide-up animation below 768px, safe-area padding for notched phones), smart auto-scroll with scroll-to-bottom button, full-width stop button bar, textarea auto-grows to 150px with character count, refined typography and compact tool call cards, focus-visible outlines, aria-live chat region.
- **Ranger: Token cost optimization** — Compact system prompt (~60% smaller), conversation history trimming (keeps first 2 + last 16 messages), tool result truncation for large responses.

### Fixed
- **Template engine: nested conditionals in loops** — `{% if %}...{% else %}...{% endif %}` blocks inside `{% for %}` loops no longer break compilation.
- **Form Builder: dark mode styling** — Replaced all hardcoded light-mode colors with CSS custom properties across 9 form-related components (FormBuilder, FormSubmissions, FormsList, Forms, FieldPalette, FieldList, FieldSettings, FormPreview, FormSettings).

### Security
- **SSRF protection** — All outbound HTTP requests from Ranger tools validate against private IP ranges and internal hostnames.
- **Path traversal prevention** — File operation tools enforce theme directory boundaries and block `../` traversal.
- **CSRF enforcement** — All Ranger mutation endpoints require valid CSRF tokens.
- **Capability enforcement** — Every tool checks the user's role capabilities before execution.
- **XSS prevention** — All tool outputs are sanitized before rendering in the chat interface.
- **SQL restrictions** — Database query tool allows only SELECT statements; no mutations.
- **Encrypted API keys** — Provider API keys stored using AES-256-GCM encryption at rest.
- **Error sanitization** — Internal error details are never exposed to the client; generic messages with logging instead.

---

## [2.7.8] — 2026-03-19

### Fixed
- **Form Builder & Submissions: Dark mode styling** — Replaced all hardcoded light-mode colors (backgrounds, borders, inputs, status badges) with CSS custom properties across FormBuilder, FormSubmissions, FormsList, Forms, and all form-builder sub-components (FieldPalette, FieldList, FieldSettings, FormPreview, FormSettings). All elements now properly respond to dark mode.

---

## [2.7.7] — 2026-03-19

### Changed
- **Ranger AI: Production-quality UI polish** — Mobile responsive (full-width overlay with slide-up animation below 768px, safe-area padding for notched phones), smart auto-scroll that only triggers near bottom with scroll-to-bottom button, full-width stop button bar, textarea auto-grows to 150px with character count for long messages, refined typography (14px body, 1.6 line-height, 16px message gap), compact uniform tool call cards with pulse animation and error messages, focus-visible outlines on all interactive elements, aria-live chat region, history list with preview text and hover effects, welcome screen vertical centering with subtle icon glow.

---

## [2.7.6] — 2026-03-19

### Changed
- **Ranger AI: Token cost optimization** — Prompt caching on system prompt and tools (biggest win — cached rounds cost 90% less input tokens), compact system prompt (~60% smaller), dynamic tool loading by intent classification (UI/build/content tasks send fewer tools), conversation history trimming (keeps first 2 + last 16 messages), and tool result truncation for read_file and list_content responses.

---

## [2.7.5] — 2026-03-11

### Fixed
- **Session expired "Log in" button not working** — The session-expired modal's "Log in" button cleared internal state but never navigated to the login screen. Now triggers a full page reload, which cleanly resets all SPA state and shows the login form.

---

## [2.7.4] — 2026-03-11

### Fixed
- **Submissions inbox: mobile responsive** — Complete mobile redesign of the form submissions inbox. Sidebar filters collapse to a horizontal scrollable pill bar, list items take full width with larger touch targets, tapping a submission shows a full-screen detail view with a back button. All three panels stack correctly on phones and tablets.

---

## [2.7.3] — 2026-03-11

### Added
- **Form Builder: Notification Email** — Each form now has a "Notification Email" setting in the Form Builder settings panel. Supports comma-separated addresses for multiple recipients. Falls back to the global notify email if blank. Builder form notification emails take priority over legacy form configs.

---

## [2.7.2] — 2026-03-11

### Added
- **Forge Form: Preserve original CSS classes** — When auto-creating a form from HTML, Forge now extracts CSS classes from the `<form>` element, submit button, and field wrapper `<div>`s and stores them alongside the form data. Rendered forms include both Outpost classes and the original theme classes, so forms remain styled without manual re-classing.

---

## [2.7.1] — 2026-03-11

### Fixed
- **Forge Form popover styling** — Fixed incorrect CSS variable reference (`--border-color` → `--border`) in the detected fields list that broke the border styling in the Form action popover.

---

## [2.7.0] — 2026-03-11

### Added
- **Forge: Auto-Create Form from HTML** — Select a `<form>` element in the Code Editor, right-click → Form, and Forge parses the HTML to detect fields, labels, types, and choices. Creates the form in the database and replaces the selection with `{% form 'slug' %}` in one click. Supports text, email, phone, URL, number, date, time, textarea, select (with choices), radio groups, checkbox groups, and hidden fields. Falls back to existing pick-from-dropdown or manual slug entry when no `<form>` is selected.

---

## [2.6.9] — 2026-03-11

### Added
- **OPE opt-in (`| edit`)** — On-page editing is now opt-in per field. Add `| edit` to any template tag (e.g. `{{ headline | edit }}`, `{{ body | raw | edit }}`, `{{ hero | image | edit }}`) to enable frontend editing. Fields without `| edit` are admin-panel only. Forge UI includes an "Editable on front-end" checkbox.

### Fixed
- **Theme page cleanup** — Deleting a theme now removes its orphaned pages, fields, and field registry entries from the database. Switching themes also cleans up orphaned pages from previously deleted themes.
- **SEO analytics ghost pages** — Analytics SEO report now filters pages by active theme, preventing orphaned pages from inflating the page count and SEO score.

---

## [2.6.8] — 2026-03-11

### Fixed
- **"no such table: item_terms" crash** — Template engine still referenced the old `item_terms` and `terms` table names after the folders/labels rename. Updated all SQL queries in `engine.php` to use `item_labels` and `labels`. Fixes collection filter, related posts, and pagination queries.

---

## [2.6.7] — 2026-03-10

### Fixed
- **Insert Asset path** — Asset references now use `/outpost/content/themes/` instead of `/outpost/themes/` (symlink path), fixing broken asset URLs on cPanel hosts with `FollowSymLinks` disabled.

---

## [2.6.6] — 2026-03-10

### Security
- **Symlink protection** — `code_build_tree()` and `code_search_dir()` now skip symlinks to prevent symlink-following attacks in the Code Editor file tree and search.
- **Asset scan limits** — `code_scan_assets()` enforces a 500-file cap and 5-level depth limit to prevent DoS via deeply nested or oversized asset directories.

### Fixed
- **Forge docs** — Updated Requirements section to reflect cursor-only mode (right-click without selection shows Insert Asset + Insert Component).

---

## [2.6.5] — 2026-03-10

### Added
- **Forge: Insert Asset** — Right-click in the Code Editor (even without a selection) to see "Insert Asset" in the Forge menu. Opens a modal that auto-discovers CSS, JS, and image files from the theme's `assets/` folder. Click any file to insert the correct HTML tag (`<link>`, `<script>`, or `<img>`) at the cursor. New `code/assets` API endpoint scans asset directories including image files.

---

## [2.6.4] — 2026-03-10

### Fixed
- **Code Editor "Forbidden" on cPanel** — ModSecurity/WAF on shared hosts blocks JSON bodies containing HTML/script content. Code Editor now base64-encodes file content before sending, bypassing WAF content inspection entirely.

---

## [2.6.3] — 2026-03-10

### Fixed
- **Code Editor save method** — Changed `code/write` from PUT to POST for broader cPanel compatibility.

---

## [2.6.2] — 2026-03-10

### Fixed
- **"Theme not configured" on cPanel** — root `index.php` used symlink path (`outpost/themes/`) which fails when Apache `FollowSymLinks` is disabled. Now uses `OUTPOST_THEMES_DIR` constant to resolve `content/themes/` directly.
- **Updater now deploys root `index.php`** — the auto-updater previously only updated files inside `outpost/`. Now it also copies the root front-controller so future `index.php` fixes deploy automatically.

---

## [2.6.1] — 2026-03-10

### Added
- **Custom Google Fonts** — Brand > Typography now has a "Manage fonts" button to add any Google Font by name. Custom fonts are stored in settings and appear in all font picker dropdowns (Brand + Theme Customizer). No code or config file edits needed.

---

## [2.6.0] — 2026-03-10

### Added
- **Theme Upload** — install themes from ZIP files via Themes page. Validates theme.json, zip-slip prevention, auto-generates slug from theme name.
- **New Theme** — create blank themes or duplicate existing ones from the Themes page. Blank themes get a minimal index.html + theme.json scaffold. After creation, navigates to Code Editor.
- **Theme Export** — download any theme as a ZIP file from the Themes page.

### Changed
- **Shipped themes** — removed Personal and Skeleton themes from the distribution. Only Starter and Forge Playground ship with Outpost.
- **Setup Wizard** — theme picker updated to show Starter and Forge Playground only.
- **Content Packs** — updated to reference only the two shipped themes.

---

## [2.5.3] — 2026-03-10

### Added
- **Brand → Customizer integration** — Brand settings now serve as baseline defaults for the Theme Customizer. Value priority: Customizer saved value → Brand value → theme.json default. Uses `brand_key` field mapping in theme.json (fully backward-compatible).

### Security
- **Tightened color regex** in customizer CSS injection — now only accepts valid CSS hex lengths (3, 4, 6, or 8 digits), matching the strict pattern used in brand validation
- **Font name validation** in framework CSS injection — output-layer regex check added before injecting brand font names into CSS tokens (defense-in-depth)

---

## [2.5.2] — 2026-03-10

### Changed
- **Sidebar labels renamed** — "Build" → "Content", "Design" → "Design & Build" for clearer navigation grouping
- **Brand page icon** — fixed paint-palette icon (dots now render as solid fills instead of hollow outlines); consistent across sidebar, mobile nav, and page header
- **Collection item icons** — changed from folder icon to stacked-documents icon to distinguish from Folders nav item
- **Folders icon** — changed from tag icon to folder icon (Folders manages taxonomies/categories, folder icon is more intuitive)
- **Brand page redesign** — replaced accordion-form layout with three always-open visual zones (Palette, Typography, Identity), each with two-column grid; added 3x2 clickable color tile swatches, live palette bar with contrast ratio readability checks, type specimen panel with heading/body/paragraph previews, side-by-side Logo + Favicon uploads; all zones collapse to single column on mobile

---

## [2.5.1] — 2026-03-10

### Fixed
- **Critical: Brand + Components API crash** — `outpost_json()`, `outpost_error()`, `outpost_require_auth()` were undefined; replaced with `json_response()`, `json_error()`, and capability pre-flight guard
- **CSS class mismatch** — component snippets used Tailwind-style classes (`font-bold`, `mb-4`, `gap-3`, `mx-auto`) not defined in framework; added Tailwind-compatible utility aliases
- **`--color-primary` → `--brand-primary`** in pricing and CTA banner components
- **Empty `alt` attributes** on team, testimonial, and blog images now use name/title template tags

### Security
- **Output-side validation** in engine.php — colors re-validated with hex regex, font names stripped of quotes, Google Fonts URL HTML-escaped with `htmlspecialchars()`
- **Identity field path validation** — logo/favicon values must be empty or match `uploads/` path pattern
- **Logo/favicon clearing** — removing brand logo now deletes stale global field from DB instead of leaving orphaned value
- **Brand/components added to `$cap_map`** — pre-flight capability check (`settings.*` / `code.*`)
- **Color regex tightened** — now only accepts valid CSS hex lengths (3, 4, 6, 8 digits)
- **Theme slug validation** — `outpost_framework_css()` validates slug with alphanumeric regex
- **Division-by-zero guard** — corrupted type scale ratio defaults to 1.25
- **Defensive returns** after `json_error()` calls in component handlers
- **`file_put_contents` error check** — brand save returns 500 on write failure

### Changed
- Framework CSS now includes `:focus-visible` styles (WCAG 2.4.7), responsive typography for mobile, and `.sr-only` utility
- Framework CSS link includes cache-busting `?v=` query param
- `insertAtCursor` in Code Editor now replaces selection instead of inserting before it
- Removed dead code: `currentScaleLabel` (Brand.svelte), `schemaTypeMap` (TemplateRefPanel.svelte)
- Added `aria-expanded` to Brand page section toggle buttons

## [2.5.0] — 2026-03-10

### Added
- **Admin sidebar reorganization** — split Build into Build (Collections, Form Builder, Channels, Folders) + Design (Themes, Brand, Code Editor); removed redundant Customize and Template Ref sidebar items
- **Brand page** — site-wide identity settings (colors, typography with 8 type scale ratios, logo/favicon) at Design > Brand; stored in `content/data/brand.json`; logo/favicon sync to global template tags
- **Outpost CSS Framework** — lightweight optional CSS framework (`php/framework/outpost-framework.css`) loaded when theme.json has `"framework": true`; includes reset, grid system, flex utilities, buttons, cards, sections, containers, spacing/text/background utilities, and responsive breakpoints
- **Brand token injection** — framework-enabled themes receive dynamic CSS custom properties from Brand settings (colors, fonts, type scale) + automatic Google Fonts loading
- **Template Reference panel** — integrated into Code Editor as a toggleable right sidebar; click snippets to insert directly at cursor position (replaces standalone page)
- **Component Library** — 20 pre-built HTML components across 10 categories (Hero, Features, Testimonials, Pricing, CTA, Team, Contact, Blog, Footer, Navigation) using framework CSS classes and Outpost template tags
- **Component browser** — searchable modal in Code Editor (toolbar button + right-click menu "Insert Component"); fetches and inserts component HTML at cursor
- **Components API** — `GET components` (list registry) and `GET components?file=` (read snippet HTML) endpoints

### Changed
- Template Reference accessible inside Code Editor instead of as a standalone sidebar item
- Auto-updater now copies `framework/` and `components/` directories during updates

---

## [2.4.1] — 2026-03-10

### Security
- Content API responses now set `Content-Type: application/json` and `X-Content-Type-Options: nosniff` to prevent MIME-sniffing attacks
- All JSON output uses `JSON_HEX_TAG | JSON_HEX_AMP` to prevent `</script>` injection in embedded contexts
- Replace all `SELECT *` queries with explicit column lists to prevent future data leakage as schema evolves
- Schema endpoint no longer leaks members-only page metadata (visibility filter applied)
- Rate limiter wraps check-and-update in `BEGIN IMMEDIATE` transaction to prevent TOCTOU race condition
- All query string parameters validated and length-capped (255 chars) via `content_param()` helper
- Labels `item_count` now only counts published items (previously included drafts)
- Menus table migration moved from per-request path to router bootstrap

## [2.4.0] — 2026-03-10

### Added
- `content/menus` endpoint — list all menus or fetch a single menu by slug with nested items and children
- `title` and `url` top-level fields on every item response in the Content API
- `?collection=` filter on `content/folders` endpoint to scope folders to a specific collection
- IP-based rate limiting on all Content API endpoints (120 req/60s per IP, returns 429)

### Fixed
- `content/pages` now excludes members-only and paid pages — the unauthenticated API should never expose gated content
- `?orderby=` (lowercase) now works alongside `?orderBy=` (camelCase) on the items endpoint

### Changed
- Complete rewrite of headless recipe docs with correct endpoint URLs, response shapes, folder filtering syntax, and framework examples
- Updated Content API reference docs with menus endpoint, rate limiting, visibility filtering, and orderby case note
- Updated `llms.txt` with all Content API changes

## [2.3.1] — 2026-03-10

### Security
- MMDB reader: add recursion depth limit (max 16) to prevent stack overflow from malicious pointer loops
- MMDB reader: cap map/array decode size to 512 entries to prevent memory exhaustion
- Remove member email from funnels recent_events API response (privacy)
- Normalize stored event properties JSON (re-encode after decode to strip injection payloads)

### Fixed
- Funnels recent_events query now filters by selected period instead of returning all-time events
- Funnels upgrade detection uses exact `paid_member` match instead of fragile `LIKE '%paid%'`
- Duplicate `$effect` in AnalyticsTraffic.svelte consolidated — chart no longer builds twice on load
- MMDB reader uint64 decoding now handles 32-bit PHP gracefully
- Funnels activity feed timestamp parsed as UTC to prevent timezone offset errors
- Missing `country_code` index on `analytics_hits` added for geo query performance

### Changed
- Updated developer docs: analytics.html (all 6 tabs + v2.3 features), admin-api.html (7 new endpoints), database-schema.html (2 new tables + column), migrations.html (v2.3 migrations)
- Fixed llms.txt: `outpost.trackEvent` → `outpost.track`, corrected search auto-detection description

## [2.3.0] — 2026-03-09

### Added
- **Search Analytics** — track what visitors search for on your site with `outpost.trackSearch()` JS API and automatic URL param detection (`?q=`, `?search=`, `?s=`); new Search tab in Analytics with top queries, zero-result queries, click-through rates, and daily trend chart
- **Content Performance Cohorts** — new Content tab in Analytics groups pages by publish date into cohorts and shows traffic distribution, views per page, and top performers for each age bracket
- **Goal Funnels** — new Funnels tab in Analytics (visible when members are enabled) tracks the visitor lifecycle (Visit → Sign Up → Login → Upgrade) with conversion rates, drop-off percentages, and a recent member activity feed
- **Geo Enrichment** — optional country-level visitor analytics using MaxMind GeoLite2 database; double-gated (requires mmdb file upload + setting enabled), stores only 2-letter country codes, pure-PHP MMDB reader with zero dependencies
- **Geo settings UI** — Settings → Advanced now has a Geo Analytics section with toggle, mmdb file upload/replace/remove, and file status indicator
- **Member event tracking** — signup, login, and role change events recorded automatically for funnel analytics

## [2.2.8] — 2026-03-09

### Added
- **Intercepting API requests guide** — 4 concrete approaches for intercepting/modifying `api.php` requests (PHP proxy wrapper, output buffering wrapper, Apache/Nginx rewrite rules, standalone endpoints) with full code examples and a decision matrix
- Updated `llms.txt` with all API interception patterns

## [2.2.7] — 2026-03-09

### Added
- **Architecture docs** — new Reference page explaining Outpost's no-middleware/no-plugin design philosophy, request lifecycle, and how to add custom logic (standalone PHP, partials, cron, JS)
- **Custom routing guide** in Routing docs — explains file-based routing IS the routing system, with file-to-URL mapping table and limitations
- **Performance optimization guide** in Caching docs — HTML page cache, SQLite WAL mode, CDN strategy, template warm-up, and SQLite PRAGMA tuning
- **Member auth quick start** in Protecting Pages docs — comprehensive PHP code example with all OutpostMember methods and available fields
- **Admin API create item example** — full JavaScript fetch example with CSRF auth, request/response bodies, and error handling table
- **Architecture link** added to docs sidebar
- Updated `llms.txt` with architecture, custom routing, performance optimization, member auth, API examples, conditional limitations, image filter clarification, and Content API error handling

## [2.2.6] — 2026-03-09

### Added
- **Common Gotchas guide** in Create a Theme docs — covers route-based links vs file paths, absolute asset paths, collection setup workflow, and `{% seo %}` recommendation
- **Writing Links in Templates** section in Routing docs — explicit guidance with correct/wrong examples
- **Form rendered HTML output** in Forms docs — full example of what `{% form %}` generates plus starter CSS
- Updated `llms.txt` with all new documentation for AI/LLM consumers

## [2.2.5] — 2026-03-09

### Fixed
- Auto-updater now installs new themes from the update package even if they lack a `theme.json` (previously only themes with `theme.json` were installed on fresh sites)

### Added
- Forge Playground theme now ships with a `theme.json` for proper version tracking

## [2.2.4] — 2026-03-09

### Fixed
- Forge Playground theme uses absolute paths for CSS and navigation links so pages render correctly when served through the front-router

## [2.2.3] — 2026-03-09

### Added
- **Use Existing Partial** — when right-clicking a `<nav>`, `<header>`, or `<footer>` and a matching partial already exists, Forge shows "Use Partial: name" at the top of the menu for instant replacement with `{% include %}` tag
- **Apply partial to all pages** — after extracting a partial, Forge scans other theme files for the same content and offers to replace it in all matching pages at once

## [2.2.2] — 2026-03-09

### Fixed
- Preview tabs: clicking a different file now correctly updates the editor content (was showing stale content when replacing the active preview tab)

## [2.2.1] — 2026-03-09

### Added
- **Forge Playground theme** — a 6-page demo website (index, features, blog, gallery, about, contact) shipped as flat HTML/CSS in `themes/forge-playground/`. Users open it in the Code Editor and practice converting static HTML into a fully tagged Outpost theme using Forge.
- **Menu Loop action** — new 7th Forge action wraps navigation links in `{% for link in menu.slug %}...{% endfor %}`. Auto-detects `<ul>`/`<ol>` with 2+ links and offers to replace static links with `{{ link.url }}` and `{{ link.label }}` template tags.
- **Smart Extract Partial** — when extracting a `<nav>` as a partial, Forge now offers a "Connect to admin menu" checkbox. When enabled, it wraps the navigation links in a menu loop before saving the partial file.
- **Loop field mapper** — Collection Loop popover detects images, headings, paragraphs, and links in the selected HTML and shows a field mapper UI. Auto-guesses which collection fields match each content element.
- **Forge Theme wizard** — banner and context menu item for folders without `theme.json`. Guided wizard creates `theme.json` with name, author, and description.
- **Forge reset** — reset button restores the Playground to its pristine state via `.forge-snapshot/` backups, removing `theme.json` and all partials.
- **Preview tabs** — single-clicking a file in the Code Editor file tree opens it as a preview tab (italic name). Clicking another file replaces the preview. Double-click or editing pins the tab. Matches VS Code behavior.
- **Wrapping defaults on by default** — "Use as default" is now checked by default when Forge detects selected text. Extended to image and link types so original URLs are preserved as fallback values.

### Fixed
- **FindInFiles XSS** — search result previews now HTML-escape file content before rendering via `{@html}`, preventing script injection from theme files.

### Security
- **Removed PHP from code editor extensions** — `.php` files can no longer be created or edited via the code editor, preventing remote code execution.
- **Template injection prevention** — all Forge tag functions now sanitize field names, slugs, and variables, stripping unsafe characters.
- **Snapshot restore hardening** — `code_restore_snapshot()` now skips symlinks and validates file extensions before restoring.
- **Content size limit** — `code/write` and `code/create` endpoints now enforce a 1 MB content limit.
- **Reset endpoint fix** — `handle_code_reset()` now uses `get_json_body()` for proper JSON validation.
- **Conditional value escaping** — double quotes in conditional comparison values are now escaped.

---

## [2.2.0] — 2026-03-09

### Added
- **Forge (Visual Tag Builder)** — select HTML in the code editor, right-click or press Cmd+E, and wrap it in the correct Outpost Liquid template tag via a guided popover
- **Smart detection engine** — Forge analyzes the selected HTML (img, link, heading, nav, form, etc.) and suggests the most likely tag type, reordering the context menu and pre-selecting the field type
- **6 tag actions** — Make Editable (all field types + page/global scope + wrapping defaults), Collection Loop, Conditional, Extract Partial (auto-creates file), Meta Tag, Form
- **Extract Partial** — select a reusable HTML block, name it, and Forge creates the partial file and replaces the selection with `{% include %}` in one step
- **StatusBar hint** — "⌘E to tag" appears in the status bar when editing HTML files
- **Extended code/context endpoint** — now returns forms, menus, and folders for Forge popover dropdowns
- **code/create accepts content** — partial extraction creates files with content in a single API call

---

## [2.1.5] — 2026-03-09

### Changed
- **Roadmap reordered** — v2.2 Visual Tag Builder, v2.3 Deeper Analytics, v2.4 Theme Gallery, v2.5 Headless-First, v2.6 Collaborative Editing, v3.0 Commerce
- **Docs roadmap updated** — v2.0 and v2.1 moved to "What's Shipped", future items reordered to match
- **Docs sidebar accordion navigation** — section labels are now collapsible; clicking a section opens it and closes others; active section auto-opens on page load

---

## [2.1.4] — 2026-03-09

### Fixed
- **Docs changelog** — added missing v2.0.1, v2.1.0, v2.1.1, v2.1.2, v2.1.3 entries to `php/docs/changelog.html`
- **CLAUDE.md** — added `php/docs/changelog.html` as a mandatory update step for every release

---

## [2.1.3] — 2026-03-09

### Changed
- **Theme updater no longer requires `managed` flag** — any theme included in the update zip is automatically updated; pre-existing themes that predate the flag will now receive updates with full conflict detection

---

## [2.1.2] — 2026-03-09

### Fixed
- **Update auto-reload** — clicking "Update now" always auto-refreshes the admin panel again (3s delay when theme updates are shown, 1.5s otherwise); removed the confusing manual "Reload admin" button

---

## [2.1.1] — 2026-03-08

### Security
- **Bulk labels fatal error** — `OutpostDB::execute()` does not exist; replaced with `OutpostDB::query()` (would have caused 500 on any bulk label operation)
- **Item ID validation** — `item_ids` array elements now cast to integers and filtered for positive values before use in SQL
- **Label-to-collection validation** — `handle_items_list()` now verifies the requested `label_id` belongs to the target collection before filtering
- **Collection membership check on remove** — bulk label remove now validates items belong to the label's collection (previously only add was validated)

### Fixed
- **Event listener cleanup** — removed `setTimeout` wrapper in label dropdown `$effect()` to prevent listener pile-up on rapid open/close
- **parseInt radix** — drag-to-label now uses `parseInt(str, 10)` with `Number.isInteger()` check
- **Empty slug guard** — label creation rejects names that produce empty slugs (e.g., all special characters)
- **Affected count accuracy** — bulk label remove now returns actual `rowCount()` instead of assuming all items had the label

---

## [2.1.0] — 2026-03-08

### Added
- **Inline label sidebar** — collection items page now shows a folder/label sidebar when the collection has folders, matching the Media Library pattern
- **Filter by label** — click any label in the sidebar to filter items; "Unfiled" shows items with no label assignments
- **Drag-to-label** — drag an item row onto a label in the sidebar to assign it instantly
- **Bulk label assignment** — select multiple items and use the "Label" dropdown in the bulk action bar to assign labels in batch
- **Label counts** — sidebar shows item count per label, total count, and unfiled count
- **Inline label creation** — create new labels directly from the sidebar without leaving the page

### Changed
- **Folder sidebar CSS shared** — extracted `.folder-sidebar`, `.folder-item`, and related styles from MediaLibrary into global `admin.css` for reuse
- **Items API** — `GET items` now accepts optional `label_id` parameter for server-side filtering

---

## [2.0.1] — 2026-03-08

### Security
- **Setup endpoint authorization** — setup routes now require `settings.*` capability (admin/super_admin only); previously any authenticated user could run the wizard
- **Path traversal prevention** — theme slug and content pack ID are validated against `[a-zA-Z0-9_-]` pattern before filesystem operations
- **Setup idempotency guard** — `setup/apply` now rejects requests if setup has already been completed, preventing re-configuration
- **Error message sanitization** — setup failure responses no longer expose internal exception details

### Fixed
- **Double-click race condition** — wizard "Continue" button on step 3 now guards against duplicate `setup/apply` calls
- **Accessibility improvements** — added ARIA roles, labels, and attributes to wizard step inputs, theme/pack radio groups, and progress bar
- **Old admin assets** — removed stale hashed JS/CSS files from test-site

---

## [2.0.0] — 2026-03-08

### Added
- **Setup wizard** — guided first-run experience: site name, theme selection, and content pack seeding in a full-screen 4-step wizard that auto-appears on fresh installs
- **Content packs** — 8 pre-built JSON content packs (Blog, Portfolio, Business) for all 3 bundled themes, seeding collections, items, menus, globals, and folders in a single transaction
- **Getting Started checklist** — dashboard card with 5 interactive items (upload logo, edit homepage, create post, set up navigation, customize theme) with progress bar and dismiss
- **Contextual tips** — dismissible one-line tips below page headers on 7 admin sections (Pages, Collections, Media, Globals, Navigation, Forms, Themes), persisted via localStorage
- **Reusable EmptyState component** — consistent empty states across all list pages with icon, title, description, CTA button, and search-aware variant
- **Setup API endpoints** — `setup/packs`, `setup/apply`, `setup/checklist`, `setup/checklist/dismiss` for wizard and onboarding flows
- **Existing site detection** — migration auto-sets `setup_completed` if content exists, so upgraded sites never see the wizard

### Changed
- **Dashboard** — Getting Started checklist appears above hero zone for new sites until dismissed or completed
- **11 admin pages** migrated to shared EmptyState component (Pages, CollectionList, CollectionItems, MediaLibrary, Themes, FormsList, ChannelsList, Navigation, FolderManager, Dashboard, Calendar)

---

## [1.9.5] — 2026-03-08

### Fixed
- **OPE image picker broken thumbnails** — media paths already include `/outpost/uploads/` prefix; the picker was prepending it again, creating double paths that 404'd. Thumbnails now load correctly.
- **OPE item text breaking HTML attributes** — `{{ post.title }}` inside an `alt="..."` attribute was compiled using the OPE `<span>` wrapper, whose double-quotes broke the attribute and spilled visible text. The compiler now detects attribute context (`="` or `='` before the tag) and uses plain escaped output instead of the OPE wrapper.

### Added
- **Skeleton theme responsive CSS** — added `@media (max-width: 768px)` rules for navigation stacking, reduced padding, footer column layout, and smaller headings on mobile.

---

## [1.9.4] — 2026-03-08

### Fixed
- **Loop compilation bug** — fixed regex cross-matching where a loop without `{% else %}` followed by a different loop with `{% else %}` would cause both to be incorrectly compiled, leaving orphan tags. Merged two-pass regex (with-else + without-else) into single-pass with optional else group for all 10 loop types.

---

## [1.9.3] — 2026-03-08

### Added
- **`{% else %}` support on all loop types** — folder, menu, gallery, media folder, repeater, flexible content, and relationship loops now support `{% else %}` for empty-state fallbacks (previously only collection and channel loops supported it)

---

## [1.9.2] — 2026-03-08

### Added
- **JSON Schema for theme.json** — `php/docs/schemas/theme.schema.json` provides IDE autocomplete and validation for theme configuration files
- **Skeleton theme** — developer reference theme with heavily commented templates demonstrating every Outpost template tag (managed, ships with every install)
- `$schema` reference added to all three bundled theme.json files (Personal, Starter, Skeleton) for IDE integration

---

## [1.9.1] — 2026-03-08

### Added
- Changelog page in developer documentation (`docs/changelog.html`)
- Roadmap page in developer documentation (`docs/roadmap.html`)
- "Project" nav section in docs sidebar linking to both new pages

---

## [1.9.0] — 2026-03-08

### Added
- **Theme Update System** — auto-updater safely updates bundled managed themes while preserving user modifications via hash-based conflict detection
- Managed theme flag (`"managed": true` in `theme.json`) for Personal and Starter themes
- `.outpost-manifest.json` generation in packaging script for file-level conflict detection
- Theme update results display in Settings → Updates (installed, updated, conflicts)
- "Managed by Outpost" label on managed themes in the Themes page
- Delete protection for managed themes (button hidden in admin UI)
- New theme auto-install during updates (new managed themes in future releases install automatically)
- **Pre-compilation tag validation** — balanced tag checking with line numbers for `{% if %}`, `{% for %}`, `{% single %}`
- **Source line tracking** — `/* @line:N */` markers in compiled templates for source mapping
- **Enhanced error display** — admin-only diagnostics with source context, line highlighting, and friendly error messages
- Error message translation for common PHP errors (undefined variable, array key, syntax error, undefined function)

### Changed
- Duplicated themes have `managed` flag stripped — copies are fully user-owned
- Themes API now includes `managed` boolean in list response
- `OutpostTemplate::compile()` accepts optional filename parameter for source tracking
- `OutpostTemplate::renderError()` shows source context and translated messages for admins

---

## [1.8.2] — 2026-03-08

### Fixed
- Body font no longer overrides heading font — engine and preview now pin non-customized font vars to their defaults, breaking CSS variable cascade (e.g. `--font-display: var(--font-sans)`)
- Live preview now updates in real-time — fixed `postMessage` failing silently on Svelte 5 proxy objects (same `structuredClone` issue)
- "System Default" font choice now properly outputs `system-ui` stack instead of being silently ignored

---

## [1.8.1] — 2026-03-08

### Fixed
- Customizer sidebar icon replaced with paintbrush (was misaligned palette icon)
- Calendar page header icon now uses standard `.page-header-icon` wrapper (was oversized)
- `structuredClone` crash on customizer page — Svelte 5 proxies replaced with `JSON.parse(JSON.stringify())`

### Security
- CSS injection hardening — type-specific validation for color (hex regex) and font (alphanumeric) values in engine CSS output
- Added `postMessage` origin check in customizer preview iframe
- Sanitized theme slug in customizer export filename to prevent header injection
- Preview script validates CSS variable names and values before applying
- Font field `<link>` element cleanup on component destroy (memory leak fix)
- Added ARIA labels to color picker, font selector, and reset buttons

---

## [1.8.0] — 2026-03-08

### Added
- **Theme Customizer** — visual editor for theme colors, fonts, logo, and favicon without touching code
- Color palette editor with native color pickers and hex input (6 fields: accent, accent hover, text, background, surface, muted)
- Font selector with curated Google Fonts library (~30 fonts across 4 categories) and live preview
- Logo and favicon upload via dedicated Site Identity section
- Live preview iframe with real-time CSS variable updates via `postMessage` — no page reload
- Theme schema system — themes declare customizable fields in `theme.json` under a `customizer` key
- Export/import customization presets as JSON files
- One-click reset to theme defaults
- Engine CSS injection via `outpost_cache_output()` — injects `<style>` tag with CSS custom properties and Google Fonts links
- Favicon injection with automatic MIME type detection
- Dark mode safe — customizer CSS scoped to `:root:not([data-theme="dark"])`
- "Customize" button in sidebar Build section and on active theme card in Themes page
- `customizer` GET/PUT, `customizer/reset` POST, `customizer/export` GET, `customizer/import` POST API endpoints
- `php/customizer.php` — new backend module for customizer data management
- `src/lib/google-fonts.js` — curated font list utility
- Personal theme `theme.json` extended with customizer schema (colors, typography, identity sections)

---

## [1.7.0] — 2026-03-07

### Added
- **Multi-folder assignment** — files can belong to multiple folders via junction table (`media_folder_items`), replacing single `folder_id` FK
- **Gallery from folders** — new `{% for img in media_folder.slug %}` template tag renders all media in a folder with `url`, `alt_text`, `width`, `height`, `focal_x`, `focal_y`, `mime_type`, `filename` fields
- **Resizable detail sidebar** — drag handle on left edge, width persisted to localStorage (220–500px range)
- **Bulk folder creation** — type comma-separated names in folder creation input to create multiple folders at once (max 50)
- **Right-click file context menu** — shows folder badges, quick "Add to folder" submenu, Copy Path, Delete
- **Role-based folder restrictions** — editors can be scoped to specific media folders (same pattern as collection grants)
- **Folder slugs** — `media_folders` table now includes auto-generated `slug` column for template tag resolution
- `media/folders` API endpoint (GET, returns folder IDs for a media item)
- `media/assign-folders` API endpoint (PUT, set all folder assignments for one item)
- `media-folders/bulk` API endpoint (POST, create up to 50 folders at once)
- `users/media-folder-grants` GET/PUT endpoints for managing editor folder access
- `cms_media_folder_items()` engine function for front-end template rendering
- "Copy template tag" option in folder context menu
- Media Folder Access checklist in user profile for editors

### Changed
- Drag-to-folder now adds to folder instead of moving (multi-folder support)
- Bulk "Move to" dropdown relabeled to "Add to folder"
- `media.moveToFolder()` API accepts optional `action` param: `set`, `add`, `remove`
- Folder file counts use junction table instead of `folder_id` column
- Unfiled media query uses `NOT IN (SELECT media_id FROM media_folder_items)`

---

## [1.6.2] — 2026-03-07

### Added
- **Bulk media operations** — "Select" mode with multi-select, shift-click range select, bulk delete, and bulk move-to-folder
- `media/bulk-delete` API endpoint (DELETE, accepts `{ ids: [...] }`, max 500 items)
- `displayName()` helper shows actual stored filenames (with `.webp` extension) instead of original upload names

---

## [1.6.1] — 2026-03-07

### Fixed
- Upload queue fired multiple times per file due to Svelte `$effect` reactive loop — single file uploads now correctly create one queue entry

---

## [1.6.0] — 2026-03-07

### Added
- **WebP auto-conversion** — JPEG and PNG uploads automatically converted to WebP, reducing file sizes with configurable quality
- **Focal point picker** — click image preview to set focal point, auto-saves; new `{{ field | focal }}` template filter for `object-position`
- **Media folder organization** — folder sidebar with tree navigation, create/rename/delete folders, drag-to-folder, max 3 levels deep
- **Bulk upload with progress** — upload queue drawer with per-file XHR progress bars, 2 concurrent uploads, cancel support, WebP savings display
- **MediaPicker folder browsing** — folder dropdown in image picker modal
- **5 new API endpoints** — `media-folders` CRUD + `media/move` for bulk folder assignment
- `OUTPOST_WEBP_AUTO_CONVERT` and `OUTPOST_WEBP_QUALITY` config constants

### Changed
- Media library restructured to 3-column layout (folder sidebar | grid | detail sidebar)
- `media.list()` and `media.upload()` API functions now accept optional `folderId` parameter

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
