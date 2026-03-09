# Changelog

All notable changes to Outpost CMS are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

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
