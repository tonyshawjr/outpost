# Outpost CMS — Feature Log

Maintained as features are built. Used for documentation generation.

---

## Custom Workflows (v3.2.0)

- **Custom approval stages** — Define workflows with arbitrary stages (Draft, Copy Review, Legal Review, Approved, Published, etc.). Each stage has a name, slug, color, allowed transitions, and role-based permissions.
- **Built-in defaults** — Two workflows created on first run: "Simple" (Draft → Published) for backward compatibility and "Editorial" (Draft → Review → Approved → Published) for teams with review processes.
- **Collection assignment** — Each collection can be assigned a workflow via the schema editor. Collections without a workflow use the default Simple workflow.
- **Stage transitions** — Click status badges in item lists or the editor sidebar to move content between stages. Available transitions are determined by the current stage's `can_move_to` rules and the user's role.
- **Transition history** — Every stage change is logged with who, when, from/to stage, and optional note. History is visible in the editor's History tab alongside revision history.
- **Bulk transitions** — Select multiple items and move them all to a target stage. Role and transition rules are enforced per-item.
- **Visual pipeline builder** — Create and edit workflows with a visual stage editor. Color pickers, transition checkboxes, role selectors, and a live pipeline preview.
- **Webhook event** — `workflow.transition` event dispatched on every stage change with item, collection, workflow, and user context.
- **Ranger tool** — `manage_workflows` tool supports list, create, get, update, delete, transition, and history actions via natural language.
- **Backward compatible** — Existing draft/published/scheduled/pending_review statuses continue to work. The existing `require_review` flag is preserved alongside workflow assignment.
- **Database** — Two new tables: `workflows` (stages stored as JSON) and `workflow_transitions` (audit log). New `workflow_id` column on `collections` table.
- **Files**: `php/workflows.php` (backend), `src/pages/Workflows.svelte` (UI), `src/lib/api.js` (client), `php/api.php` (routes), `php/ranger.php` (AI tool), `src/pages/CollectionItems.svelte` (status badges), `src/pages/CollectionList.svelte` (workflow selector), `src/components/RightSidebar.svelte` (editor transitions + history), `src/components/Sidebar.svelte` (nav link)

---

## Releases (v3.1.0)

- **Content releases** — Bundle multiple content changes (fields, items, pages, menus, collections) into a named release and publish them all at once. Atomic publishing via database transaction ensures all-or-nothing consistency.
- **Release lifecycle** — Draft > Published > Rolled Back. Draft releases can be edited, have changes added/removed, and deleted. Published releases can be rolled back to revert all changes in reverse order.
- **Snapshot-based rollback** — Each change captures before/after entity state at publish time. Rollback restores the exact pre-publish state for each modified entity.
- **Admin UI** — New "Releases" page in the Content sidebar section. List view with status badges, change counts, and creator info. Detail view with change list showing entity type, action (Created/Updated/Deleted), and inline management. Create/edit modal for release name and description.
- **API endpoints** — Full REST API: `GET/POST/PUT/DELETE releases`, `POST releases/publish`, `POST releases/rollback`, `POST/DELETE releases/changes`. Admin-only (`settings.*` capability).
- **Ranger integration** — `manage_releases` tool supports list, create, get, publish, rollback, delete, and add_change actions via natural language.
- **Template cache clearing** — Publish and rollback automatically clear compiled template cache to ensure changes are reflected immediately.
- **Files**: `php/releases.php` (backend), `src/pages/Releases.svelte` (UI), `src/lib/api.js` (client), `php/api.php` (routes), `php/ranger.php` (AI tool)

---

## Ranger AI Assistant (v3.0.0)

- **AI-powered assistant** — Built into the admin panel sidebar. Natural-language interface for every CMS operation. Ask Ranger to create pages, edit content, build themes, manage media, configure channels, create forms, manage users, set up webhooks, run backups, query the database, debug templates, configure email, manage navigation, adjust settings, and toggle frontend features.
- **35 tools** — Complete coverage of Outpost's API surface: `create_page`, `update_page`, `delete_page`, `list_pages`, `create_collection`, `update_collection`, `create_item`, `update_item`, `delete_item`, `list_content`, `upload_media`, `upload_media_from_url`, `create_theme_file`, `update_theme_file`, `delete_theme_file`, `read_file`, `list_theme_files`, `create_collection_field`, `update_settings`, `list_settings`, `manage_navigation`, `manage_users`, `manage_members`, `manage_forms`, `manage_channels`, `manage_webhooks`, `manage_backups`, `query_database`, `debug_template`, `configure_email`, `manage_globals`, `search_docs`, `get_system_info`, `frontend_action`, `manage_folders`.
- **3 AI providers** — Claude (Anthropic) with prompt caching, OpenAI (GPT-4o/GPT-4), Google (Gemini). Configure at Settings > Integrations. BYO API key — no Outpost account or subscription needed.
- **Streaming responses** — SSE streaming with real-time tool execution. Tool calls render as compact cards with running/success/error states and pulse animation.
- **Conversation persistence** — Auto-generated conversation titles, searchable history panel, rename and delete conversations.
- **Token usage tracking** — Per-message input/output/cache token counts with cost in USD. Visible in the chat interface.
- **Prompt caching (Claude)** — System prompt and tool definitions use `cache_control` ephemeral caching. Cached rounds cost ~90% less input tokens.
- **Dynamic tool loading** — Intent classifier (`ranger_classify_intent`) analyzes each message and sends only relevant tool subsets to minimize token usage.
- **Screenshot paste** — Paste images directly into the chat for visual context (Claude vision).
- **Output style** — Configurable: concise, detailed, casual, technical, or custom (free-text instructions).
- **Role-scoped tools** — Editors see content tools, developers see code tools, admins see everything. Server-side capability enforcement on every tool execution.
- **Security** — SSRF protection on outbound requests, path traversal prevention on file operations, CSRF on all mutations, capability enforcement per tool, XSS prevention on rendered output, SELECT-only database queries, AES-256-GCM encrypted API key storage, sanitized error responses.
- **Mobile responsive** — Full-width slide-up overlay below 768px with safe-area padding for notched phones. Smart auto-scroll, scroll-to-bottom button, full-width stop bar.
- **Accessibility** — focus-visible outlines, aria-live chat region, aria-labels on all controls, role="complementary" panel.
- **API endpoints** — `POST ranger/chat` (SSE streaming), `GET/POST/PUT/DELETE ranger/conversations` (CRUD), `GET/PUT ranger/settings` (configuration).
- **Files**: `php/ranger.php` (backend), `src/components/Ranger.svelte`, `src/components/ranger/ChatMessage.svelte`, `src/components/ranger/ToolCallCard.svelte`, `src/styles/admin.css`

---

## Forge Playground Reset (v3.0.0)

- **One-click reset** — Themes page shows a "Reset" button on themes that have a `.forge-snapshot/` backup directory. Restores the theme to its pristine pre-Forge state (removes theme.json, partials, and template tags).

---

## Template Engine: Nested Conditionals Fix (v3.0.0)

- **Nested if/else in loops** — `{% if %}...{% else %}...{% endif %}` blocks inside `{% for %}` loops no longer break the template compiler. The regex-based tag parser now correctly handles nested conditional blocks.
- **Files**: `php/template-engine.php`

---

## Form Builder & Submissions: Dark Mode Fix (v2.7.8)

- Replaced all hardcoded light-mode fallback colors with proper CSS custom properties (`--bg-primary`, `--bg-card`, `--bg-tertiary`, `--border-primary`, `--accent`, `--accent-soft`, `--danger`, etc.)
- Fixed 9 files: `FormBuilder.svelte`, `FormSubmissions.svelte`, `FormsList.svelte`, `Forms.svelte`, `FieldPalette.svelte`, `FieldList.svelte`, `FieldSettings.svelte`, `FormPreview.svelte`, `FormSettings.svelte`
- Added explicit `background` and `color` to notification email and notes inputs that were missing them

---

## Ranger AI: Production UI Polish (v2.7.7)

- **Mobile responsive** — Below 768px the panel becomes a full-width overlay with slide-up animation and rounded top corners. Close button is larger on mobile. Safe-area padding for notched phones (env(safe-area-inset-bottom/top)). Layout shift margin-right disabled on mobile.
- **Smart auto-scroll** — Only scrolls to bottom when user is near the bottom (within 80px). Shows a floating "scroll to bottom" button when user scrolls up and new messages arrive.
- **Stop button** — Full-width red bar above the input area instead of replacing the send button. More visible with pulse animation.
- **Textarea improvements** — Auto-grows up to 150px (was 120px). Shows character count above 500 chars with amber warning above 2000.
- **Typography** — Message text bumped to 14px, 1.6 line-height. Message gap increased to 16px. Header padding 16px 20px. Toolbar padding 8px 20px. Input area padding 12px 20px.
- **Tool call cards** — Compact single-line layout with inline summary. Running state has pulse animation. Shows "Creating..." / "Updating..." labels while running. Error state displays error message text in red.
- **History list** — Items show preview text, message count, subtle bottom border separator. Better empty state with icon and subtext. Hover shows rgba overlay. Focus-visible outlines.
- **Welcome screen** — Full-height centering, larger icon with subtle glow animation, wider suggestion buttons with more gap (8px), active press scale.
- **Dark mode** — Code blocks use rgba(0,0,0,0.35) background for contrast, inline code has dark border. Loading spinner uses green accent. History items use white-alpha colors.
- **Accessibility** — focus-visible outlines (2px #6FCF97) on all buttons, suggestions, history items, and links. Chat messages area is aria-live="polite" log region. All buttons have aria-labels. Panel has role="complementary".
- **Files**: `src/components/Ranger.svelte`, `src/components/ranger/ChatMessage.svelte`, `src/components/ranger/ToolCallCard.svelte`, `src/styles/admin.css`

---

## Ranger AI: Token Cost Optimization (v2.7.6)

- **Prompt caching** — System prompt and tool definitions use Claude's `cache_control` ephemeral caching. After the first request, subsequent rounds in the same conversation reuse cached prefixes, reducing input token costs by up to 90% for the stable prefix.
- **Compact system prompt** — Reduced system prompt from ~120 lines to ~30 lines (~60% smaller). Template syntax reference is now a dense single-line-per-construct format. Guidelines trimmed to essentials.
- **Dynamic tool loading** — Intent classifier (`ranger_classify_intent`) analyzes user message and sends only relevant tools: UI intents get 3 tools, build intents get 10, content intents get 13, instead of all 25. Fewer tool definitions = fewer input tokens per request.
- **Conversation trimming** — Conversations over 20 messages are trimmed: keeps first 2 (initial context) + last 16 (recent), drops the middle. Prevents runaway context growth in long sessions.
- **Tool result truncation** — `read_file` results truncated to 2000 chars, `list_content` items capped at 10, `search_docs` results capped at 500 chars each.
- **Files**: `php/ranger.php`

---

## Submissions Inbox: Mobile Responsive (v2.7.4)

- **Mobile-first inbox** — The submissions inbox now works on phones and tablets. Desktop sidebar filters collapse to a horizontal scrollable pill bar with unread badges. The list takes full width with touch-friendly item sizing. Tapping a submission shows a full-screen detail view with a back arrow. Bulk actions bar scrolls horizontally. All three panels (sidebar/list/detail) stack correctly at <=768px.
- **Files**: `src/pages/FormSubmissions.svelte`

---

## Form Builder: Notification Email (v2.7.3)

- **Per-form notification email** — Each form in the Form Builder now has a "Notification Email" field in the Settings panel. Enter one or more comma-separated email addresses. When a submission comes in, the notification is sent to these addresses first, falling back to the legacy `form_configs` per-form email, then the global `notify_email` setting.
- **Files**: `src/components/form-builder/FormSettings.svelte`, `php/form.php`

---

## Forge Form: Preserve Original CSS Classes (v2.7.2)

- **CSS class preservation** — When Forge auto-creates a form from `<form>` HTML, it now extracts and preserves CSS classes from the form element, submit button, and each field's wrapper `<div>`. The rendered `{% form %}` output includes both Outpost's own classes and the original theme classes, keeping forms styled without manual work.
- **Files**: `src/lib/forge-form-parser.js`, `src/components/ce/ForgeForm.svelte`, `php/forms-engine.php`

---

## Forge: Auto-Create Form from HTML (v2.7.0)

- **Auto-create from `<form>` HTML** — Select a `<form>` element in the Code Editor, right-click → Form. Forge parses the HTML using DOMParser, detects input fields (text, email, phone, URL, number, date, time, hidden, textarea, select, radio, checkbox), extracts labels via `<label for>` / wrapping `<label>` / `aria-label` / `placeholder` / name attr, groups radio/checkbox inputs by name, extracts `<option>` choices from selects, and derives the form name from `form.id` / `form.name` / heading / legend. Creates the form in the database via the existing Form Builder API and replaces the selection with `{% form 'slug' %}`.
- **Three-mode UI** — Mode A: create from HTML (when `<form>` selected), Mode B: pick existing form (dropdown), Mode C: manual slug entry. Seamless fallback between modes.
- **Files**: `src/lib/forge-form-parser.js` (new), `src/components/ce/ForgeForm.svelte`, `src/pages/CodeEditor.svelte`

---

## OPE Opt-In & Theme Cleanup (v2.6.9)

- **`| edit` modifier** — On-page editing is now opt-in. Append `| edit` to any template tag to enable frontend editing: `{{ headline | edit }}`, `{{ body | raw | edit }}`, `{{ hero | image | edit }}`, `{{ @site_name | edit }}`. Fields without `| edit` render normally but are only editable in the admin panel. Forge "Insert Editable" includes an "Editable on front-end" checkbox.
- **Theme page cleanup** — Deleting a theme now purges its fields, field registry, and orphaned pages from the database. Theme activation also cleans up orphans from previously deleted themes.
- **SEO analytics theme filter** — SEO report in Analytics now only audits pages belonging to the active theme, preventing ghost pages from inflating the score.
- **Files**: `php/engine.php`, `php/template-engine.php`, `php/themes.php`, `php/api.php`, `src/lib/forge-tags.js`, `src/components/ce/ForgeEditable.svelte`

---

## Custom Google Fonts (v2.6.1)

- **Manage fonts** — "Manage fonts" link in Brand > Typography opens a modal to add any Google Font by name and category. Fonts are stored in the settings table as `custom_fonts` JSON.
- **Merged font list** — Font picker dropdowns (Brand + Theme Customizer) merge the curated ~30 fonts with user-added fonts. Deduplication by name, category grouping preserved.
- **API endpoints** — `GET/PUT fonts` — retrieve and save custom Google Fonts. Validates font name and category (Sans Serif, Serif, Display, Monospace). Gated by `settings.*` capability.
- **Files**: `php/api.php`, `src/lib/api.js`, `src/lib/google-fonts.js`, `src/components/customizer/FontField.svelte`, `src/pages/Brand.svelte`

---

## Theme Management (v2.6.0)

- **Upload Theme** — Themes page now has an "Upload Theme" button. Accepts `.zip` files containing a valid `theme.json`. Validates zip structure, prevents zip-slip attacks, auto-generates slug from theme name, strips `managed` flag from uploaded themes.
- **New Theme** — "New Theme" button opens a modal with two starting points: Blank (creates minimal `index.html` + `theme.json`) or Duplicate (copies an existing theme). After creation, navigates to Code Editor for immediate editing.
- **Theme Export** — "Export" button on every theme card downloads the theme as a `.zip` file. Uses `Content-Disposition: attachment` for browser download.
- **Shipped themes trimmed** — Personal and Skeleton themes removed from distribution. Only Starter and Forge Playground ship with Outpost.
- **Setup Wizard updated** — theme picker shows only Starter and Forge Playground.
- **Content Packs updated** — all pack theme arrays reference only shipped themes.
- **Files**: `php/themes.php`, `php/api.php`, `src/pages/Themes.svelte`, `src/lib/api.js`, `src/pages/setup/WizardStepTheme.svelte`, `php/content-packs/packs.json`

---

## Brand → Customizer Integration (v2.5.3)

- **Brand as customizer baseline** — Brand settings (colors, fonts, logo, favicon) now flow through as default values for the Theme Customizer. Priority: Customizer saved value → Brand value → theme.json default. Themes opt in per-field via `brand_key` in `theme.json` (e.g. `"brand_key": "colors.accent"`). Fields without `brand_key` are unaffected (fully backward-compatible).
- **Personal theme mapped** — 8 customizer fields mapped to brand keys: accent, text, background, surface colors + heading/body fonts + logo + favicon. `color-accent-hover` and `color-muted` remain theme-only.
- **API enrichment** — `GET customizer` response now includes `brand_default` on fields with a `brand_key` mapping, so the admin UI can show the brand value as the reset target.
- **Files**: `php/customizer.php`, `php/themes/personal/theme.json`, `src/pages/ThemeCustomizer.svelte`

---

## Brand Page Redesign + Sidebar Rename (v2.5.2)

- **Sidebar labels renamed** — "Build" → "Content" (Collections, Form Builder, Channels, Folders), "Design" → "Design & Build" (Themes, Brand, Code Editor). Mobile nav mirrors sidebar labels.
- **Brand page icon** — fixed paint-palette icon (dots now solid-filled instead of hollow outlines) in sidebar, mobile nav, and page header.
- **Collection icons** — collection items in sidebar changed from folder to stacked-documents icon; Folders nav item changed from tag to folder icon for clearer differentiation.
- **Brand page redesign** — three always-open visual zones replacing accordion layout: Palette (3x2 clickable color tiles + live palette bar with WCAG contrast ratios), Typography (font selectors + live type specimen panel with heading/body/paragraph previews and computed scale table), Identity (side-by-side Logo + Favicon uploads). Responsive: all zones collapse to single column at ≤768px, color grid becomes 2x3.
- **Files**: `src/components/Sidebar.svelte`, `src/components/MobileNav.svelte`, `src/pages/Brand.svelte`

---

## Outpost Design System (v2.5.0)

- **Sidebar reorganization** — Build section split into "Build" (Collections, Form Builder, Channels, Folders) and "Design" (Themes, Brand, Code Editor). Customize and Template Reference removed from sidebar (Customize accessible from Themes page, Template Reference integrated into Code Editor).
- **Brand page** — site-wide identity settings at Design > Brand. Three sections: Colors (primary, secondary, accent, neutral, background, surface with color picker and hex input), Typography (heading + body font with Google Fonts dropdown, type scale ratio selector from Minor Second through Golden Ratio with live computed scale preview), Identity (logo + favicon upload via media picker). Stored in `content/data/brand.json`. Logo/favicon auto-sync to `{{ @site_logo }}` and `{{ @site_favicon }}` global template tags.
- **Outpost CSS Framework** — lightweight optional CSS framework at `php/framework/outpost-framework.css`. Opt-in per theme via `"framework": true` in theme.json. Provides: minimal reset, CSS custom properties for brand tokens, type scale tokens (`--text-xs` through `--text-5xl`), spacing scale, grid system (`.grid-2`, `.grid-3`, `.grid-4` with responsive breakpoints), flex utilities, button classes (`.btn`, `.btn-primary`, `.btn-secondary`, `.btn-outline`), card component, section/container/text/spacing/background utilities. Injection order: Google Fonts > Framework CSS > Brand tokens > Customizer CSS.
- **Template Reference panel** — integrated into Code Editor as a 260px toggleable right panel (book icon in toolbar). Browse Collections, Pages, Globals, and Syntax reference. Click any snippet to insert it directly at cursor position in the editor. Replaces the standalone sidebar item.
- **Component Library** — 20 HTML component snippets across 10 categories: Hero (centered, split, image-bg), Features (3-col, 4-col, icon-list), Testimonials (card-grid, single-quote), Pricing (3-col), CTA (centered, banner), Team (grid), Contact (form, split), Blog (card-grid, list), Footer (columns, simple), Navigation (simple, centered). All use framework CSS classes and Outpost template tags with wrapping defaults.
- **Component browser** — searchable categorized modal in Code Editor. Accessible via toolbar button (wrench icon) and "Insert Component" in Forge right-click menu. Click a component to fetch its HTML and insert at cursor.
- **Files**: `php/brand.php` (new), `php/framework/outpost-framework.css` (new), `php/components/` (new, 20 HTML snippets + registry), `php/engine.php`, `php/api.php`, `src/pages/Brand.svelte` (new), `src/components/ce/TemplateRefPanel.svelte` (new), `src/components/ce/ForgeComponent.svelte` (new), `src/components/ce/ForgeMenu.svelte`, `src/pages/CodeEditor.svelte`, `src/components/Sidebar.svelte`, `src/components/MobileNav.svelte`, `src/App.svelte`, `src/lib/api.js`, `scripts/package.js`

---

## Content API Security Hardening (v2.4.1)

- **Response headers** — Content API now sets `Content-Type: application/json` and `X-Content-Type-Options: nosniff` on all responses. JSON output uses `JSON_HEX_TAG | JSON_HEX_AMP` flags to prevent script injection.
- **Explicit column queries** — all `SELECT *` replaced with explicit column lists to prevent future data leakage as database schema evolves.
- **Input validation** — `content_param()` helper trims, length-caps (255 chars), and null-coalesces all query string parameters.
- **Schema visibility** — `content/schema` endpoint now excludes members-only pages.
- **Rate limit hardening** — rate limiter uses `BEGIN IMMEDIATE` transaction to prevent TOCTOU race condition on concurrent requests.
- **Labels accuracy** — `item_count` in labels endpoint now only counts published items.
- **Migration efficiency** — menus table `CREATE TABLE IF NOT EXISTS` moved to router bootstrap, no longer runs per-request.
- **Files**: `php/content-api.php`, `php/http-security.php`

---

## Content API Polish (v2.4.0)

- **Menus endpoint** — `content/menus` returns all menus (slug, name, item count); `content/menus&slug=main` returns a single menu with nested items and children. Ensures menus table exists on first call.
- **Item title & URL** — `format_item()` now includes `title` (from data or slug fallback) and `url` (built from collection's `url_pattern`) at the top level of every item response.
- **Page visibility filter** — `content/pages` now excludes members-only and paid pages (`visibility = 'public'` filter). The Content API is unauthenticated and should never expose gated content.
- **Case-insensitive orderby** — `?orderby=` (lowercase) now works alongside `?orderBy=` (camelCase) on the items endpoint.
- **Rate limiting** — 120 requests per 60 seconds per IP on all Content API endpoints. Returns HTTP 429 with `Retry-After` header on exceed. Uses the existing `outpost_ip_rate_limit()` function.
- **Folders collection filter** — `content/folders` now accepts optional `?collection=slug` to filter folders to a specific collection.
- **Headless recipe rewrite** — complete rewrite of `docs/recipes/headless-cms.html` with correct endpoint URLs (`api.php?action=content/...`), correct response shapes (`{ "data": [...], "meta": {...} }`), correct folder filtering syntax (`?{folder_slug}={label_slug}`), and updated framework examples (Astro, Next.js, Vue/Nuxt).
- **Files**: `php/content-api.php`, `php/docs/recipes/headless-cms.html`, `php/docs/api/content-api.html`, `php/docs/llms.txt`

---

## Deeper Analytics (v2.3.0)

- **Search Analytics** — track internal site searches via `outpost.trackSearch(query, resultsCount)` and `outpost.trackSearchClick(query, clickedPath)` JS API. Auto-detects `?q=`, `?search=`, `?s=` URL params. New Search tab shows total searches, unique queries, click-through rate, top queries, zero-result queries, and daily trend chart. Backend: `analytics_searches` table, `dashboard/search` API endpoint.
- **Content Performance Cohorts** — groups pages by publish date into 5 cohorts (last 7d, 30d, 90d, 6mo, older) and joins with analytics_hits for traffic distribution. New Content tab shows horizontal bar chart, expandable cohort table, and insight callout. Backend: `dashboard/cohorts` API endpoint.
- **Goal Funnels** — tracks member lifecycle (Visit → Sign Up → Login → Upgrade) as a 4-stage conversion funnel. Records signup, login, and role_change events in `member_events` table. New Funnels tab (conditionally visible when members enabled) shows CSS funnel visualization, conversion rates, drop-off percentages, and recent activity feed. Backend: `dashboard/funnels` API endpoint, `member_events` table, event recording in `member-api.php` and `api.php`.
- **Geo Enrichment** — optional country-level visitor analytics using MaxMind GeoLite2 database. Double-gated: requires both `.mmdb` file uploaded to `content/data/` AND `geo_enabled` setting toggled on. Pure-PHP MMDB reader (`mmdb-reader.php`, ~270 LOC) supports IPv4/IPv6, no Composer. Stores only 2-letter country codes — never raw IPs. Traffic tab shows top countries with flags, percentage bars, and counts. Backend: `dashboard/geo` API endpoint, `country_code` column on `analytics_hits`.
- **Geo settings UI** — Settings → Advanced section with enable toggle, mmdb file upload/replace/remove, file status indicator (size + upload date). Backend: `dashboard/geo/status`, `dashboard/geo/upload`, `dashboard/geo/delete` API endpoints.
- **Files**: `php/mmdb-reader.php` (new), `php/track.php`, `php/api.php`, `php/member-api.php`, `php/template-engine.php`, `src/lib/api.js`, `src/pages/Analytics.svelte`, `src/App.svelte`, `src/pages/settings/AdvancedSettings.svelte`, `src/components/analytics/AnalyticsSearch.svelte` (new), `src/components/analytics/AnalyticsCohorts.svelte` (new), `src/components/analytics/AnalyticsFunnels.svelte` (new), `src/components/analytics/AnalyticsTraffic.svelte`

---

## Developer Docs — Context7-Optimized Coverage (v2.2.7)

- **Architecture reference page** — explains Outpost's no-middleware/no-plugin design philosophy, request lifecycle, and four patterns for adding custom logic (standalone PHP endpoints, PHP partials, cron jobs, front-end JS)
- **Custom routing guide** — explains file-based routing with file-to-URL mapping table and explicit limitations (no multi-segment static routes, no custom handlers, no params beyond {slug})
- **Performance optimization guide** — covers HTML page cache, SQLite WAL mode, CDN/reverse proxy strategy, template warm-up, and SQLite PRAGMA tuning for high-traffic sites
- **Member auth quick start** — comprehensive PHP code example in Protecting Pages with OutpostMember::check(), ::current(), role checking, expiry checking, and all available member fields
- **Admin API create item example** — full JavaScript fetch() code with CSRF authentication, request/response bodies, error status table
- **llms.txt comprehensive update** — architecture, routing, performance, auth quick start, API examples, conditional limitations, image filter clarification, Content API error handling

---

## Forge — Visual Tag Builder (v2.2.0)

- **Forge** — a visual tag builder embedded in the code editor. Select HTML in any `.html` theme file, right-click (or press Cmd+E), and Forge wraps the selection in the correct Outpost Liquid template tag via a guided popover. No template syntax knowledge required.
- **Smart detection** — `forge-detect.js` analyzes the selected HTML and intelligently suggests the most likely action and field type. `<img>` → image, `<a href>` → link, `<h1>` → text, `<nav>` → extract partial, `<form>` → form, `<ul>` with links → menu loop, rich HTML → richtext, plain text → text/textarea. The context menu reorders to put the best match first.
- **Make Editable** — wrap any content in `{{ field }}` output tags. Configure field name (auto-slugified from selection), type (text, richtext, image, link, textarea, select, color, number, date, toggle), scope (page field or global), and optional wrapping default. "Use as default" is ON by default — original content is preserved as a fallback. Works for images and links too (`{{ field | image }}https://...{{ /field }}`).
- **Collection Loop** — wrap HTML in `{% for item in collection.slug %}...{% endfor %}`. Select collection from dropdown, set item variable name, limit, and order by. **Loop field mapper** auto-detects images, headings, paragraphs, and links in the selection and maps them to collection fields.
- **Menu Loop** — wrap navigation links in `{% for link in menu.slug %}...{% endfor %}`. Select a menu from the admin, and Forge replaces static `<a>` href/text with `{{ link.url }}` and `{{ link.label }}`.
- **Conditional** — wrap in `{% if expression %}...{% endif %}`. Supports truthy, `==`, and `!=` operators.
- **Extract Partial** — select a reusable block (nav, header, footer), name the partial, and Forge creates `partials/{name}.html` with the content and replaces the selection with `{% include 'name' %}` — all in one step. Auto-creates the `partials/` directory if needed. **Smart nav detection**: when extracting a `<nav>` with links, offers to connect to the admin menu system automatically.
- **Meta Tag** — wrap in `{{ meta.title }}...{{ /meta.title }}` or `{{ meta.description }}...{{ /meta.description }}`.
- **Form** — insert `{% form 'slug' %}` with form selection from dropdown or free-text input.
- **Forge Playground** — ships with a 6-page marketing website (`themes/forge-playground/`) as flat HTML/CSS. Users practice converting it into a theme using Forge. Includes blog cards for loop practice, nav for menu loop practice, images for editable practice.
- **Forge Theme wizard** — folders without `theme.json` show a banner and "Forge Theme..." menu item. Guided wizard creates `theme.json` with name, author, and description.
- **Forge reset** — resets the Playground to its pristine state from `.forge-snapshot/` backups. Removes `theme.json`, all partials, and all template tags.
- **Preview tabs** — single-click opens files as preview tabs (italic name); clicking another file replaces the preview. Double-click or editing pins the tab.
- **StatusBar hint** — "⌘E to tag" in status bar for HTML files.
- **Extended context endpoint** — `code/context` now returns forms, menus, and folders for Forge popover dropdowns.
- **Insert Asset (v2.6.5)** — right-click in an HTML file (even without a selection) to see "Insert Asset" in the Forge context menu. Opens a modal that auto-discovers CSS, JS, and image files from the theme's `assets/` folder. Click any file to insert the correct HTML reference tag at the cursor. New `code/assets` API endpoint.
- **Security hardening** — removed PHP from code editor extensions, sanitized all Forge tag output, hardened snapshot restore, added content size limits.
- **Files**: `src/lib/forge-detect.js`, `src/lib/forge-tags.js`, `src/components/ce/ForgeMenu.svelte`, `src/components/ce/ForgePopover.svelte`, `src/components/ce/ForgeEditable.svelte`, `src/components/ce/ForgeLoop.svelte`, `src/components/ce/ForgeConditional.svelte`, `src/components/ce/ForgePartial.svelte`, `src/components/ce/ForgeMeta.svelte`, `src/components/ce/ForgeForm.svelte`, `src/components/ce/ForgeMenuLoop.svelte`, `src/components/ce/ForgeTheme.svelte`, `src/components/ce/ForgeAsset.svelte`, `src/pages/CodeEditor.svelte`, `src/components/ce/StatusBar.svelte`, `src/components/ce/EditorTabs.svelte`, `src/components/ce/FileTree.svelte`, `src/components/ce/FindInFiles.svelte`, `src/lib/api.js`, `php/code-editor.php`, `php/themes/forge-playground/`

---

## Inline Collection Folders (v2.1.0)

- **Label sidebar** — `LabelSidebar.svelte` component shown inline on collection items page when the collection has folders. Displays "All Items" (total count), "Unfiled" (unfiled count), and all labels with per-label item counts. Modeled after MediaLibrary folder sidebar pattern.
- **Filter by label** — clicking a label filters items server-side via new `label_id` parameter on `GET items`. "Unfiled" uses a NOT IN subquery to show items with no label assignments.
- **Drag-to-label** — drag a row onto a sidebar label to assign it. Uses `items/bulk-labels` endpoint with `action: 'add'`.
- **Bulk label assignment** — when items are selected and folders exist, a "Label" dropdown appears in the bulk action bar. Clicking a label assigns all selected items.
- **Inline label creation** — "+ New Label" button in sidebar opens an inline input. Supports comma-separated names.
- **Shared folder sidebar CSS** — extracted folder sidebar styles from MediaLibrary scoped CSS to global `admin.css` for reuse across components.
- **API endpoints** — `GET items/labels-with-counts` (folder/label/count data), `POST items/bulk-labels` (bulk add/remove assignments), updated `GET items` with `label_id` filter.
- **Files**: `src/components/LabelSidebar.svelte` (new), `src/pages/CollectionItems.svelte`, `src/pages/MediaLibrary.svelte`, `src/styles/admin.css`, `src/lib/api.js`, `php/api.php`

---

## Onboarding & Setup Wizard (v2.0.0)

- **Setup wizard** — full-screen 4-step wizard (site name → theme → content pack → done) auto-appears on fresh installs. Runs after auth, uses dark `#0a0a0a` background with centered card, 4-dot progress indicator, skip/back links. Content is seeded in a single SQLite transaction.
- **Content packs** — 8 JSON content packs in `php/content-packs/` covering Blog, Portfolio, and Business for Personal, Starter, and Skeleton themes. Each pack seeds collections (with schema + items), menus, folders, labels, and globals. A "Start from scratch" option activates the theme with no content.
- **Getting Started checklist** — `GettingStarted.svelte` dashboard card with 5 items: upload logo, edit homepage, create first post, set up navigation, customize theme. Progress bar, green check circles, direct links to relevant pages. Dismiss persists in settings DB.
- **Contextual tips** — `ContextualTip.svelte` one-line dismissible tips below `.page-header` on 7 admin sections (Pages, Collections, Media, Globals, Navigation, Forms, Themes). State in localStorage under `outpost-dismissed-tips`.
- **Reusable EmptyState component** — `EmptyState.svelte` with props: icon (SVG string), title, description, ctaLabel/ctaAction, secondaryLabel/secondaryAction, searchActive. Serif 26px title, 15px muted description, centered layout. Replaces 11 inconsistent inline empty states.
- **Setup API endpoints** — `GET setup/packs` (returns available packs filtered by theme), `POST setup/apply` (applies wizard choices in transaction), `GET setup/checklist` (dynamic completion), `POST setup/checklist/dismiss`.
- **Existing site migration** — `ensure_setup_completed_setting()` auto-sets `setup_completed = '1'` if pages or collections exist, so upgraded sites skip the wizard entirely.
- **Files**: `src/components/EmptyState.svelte` (new), `src/components/ContextualTip.svelte` (new), `src/components/GettingStarted.svelte` (new), `src/pages/SetupWizard.svelte` (new), `src/pages/setup/WizardStep*.svelte` (4 new), `src/lib/tips.js` (new), `php/content-packs/*.json` (9 new), `php/api.php`, `src/App.svelte`, `src/lib/api.js`, `src/lib/stores.js`, `scripts/package.js`, + 11 migrated page files

---

## OPE & Skeleton Fixes (v1.9.5)

- **Fixed OPE image picker broken thumbnails** — `src/editor/image-picker.js` was prepending `/outpost/uploads/` to media paths that already contain that prefix from the database. Removed the duplicate prefix so thumbnails load correctly.
- **Fixed OPE item text breaking HTML attributes** — `outpost_ope_item_text()` wraps output in `<span data-ope-*>` for on-page editing, but this breaks when the field is used inside an HTML attribute (e.g. `alt="{{ post.title }}"`). The template compiler now detects attribute context (preceded by `="` or `='`) and emits plain escaped output instead of the OPE wrapper.
- **Skeleton theme responsive CSS** — added mobile breakpoint (`max-width: 768px`) with nav stacking, reduced padding, footer column layout, and smaller headings.
- **Files**: `src/editor/image-picker.js`, `php/template-engine.php`, `php/themes/skeleton/assets/style.css`

---

## Loop Compilation Fix (v1.9.4)

- **Fixed cross-loop regex matching** — when a loop without `{% else %}` (e.g. folder) was followed by a different loop with `{% else %}` (e.g. collection), the two-pass regex would incorrectly match across both loops, leaving orphan tags and breaking template compilation. Merged all two-pass "with-else" / "without-else" regex pairs into single-pass regexes with optional else groups.
- **Files**: `php/template-engine.php`

---

## Universal Loop Empty States (v1.9.3)

- **`{% else %}` on all loop types** — every `{% for %}` loop now supports `{% else %}` for empty-state fallbacks. Previously only collection and channel loops supported this. Now folder, menu, gallery, media folder, repeater, flexible content, and relationship loops all support `{% else %}` consistently.
- **Files**: `php/template-engine.php`

---

## Schema Validation & Theme Starter Kit (v1.9.2)

- **JSON Schema for theme.json** — `php/docs/schemas/theme.schema.json` validates theme metadata and customizer configuration. IDEs auto-detect the `$schema` reference and provide autocomplete + inline validation for all theme.json fields including customizer sections, field types, and CSS variable mappings.
- **Skeleton theme** — new bundled managed theme (`php/themes/skeleton/`) that serves as the definitive developer reference. Every template is heavily commented, demonstrating: output tags (`{{ field }}`, `| raw`, `| image`, `| link`, `| textarea`, `| color`, `| number`, `| date`, `| select`, `| toggle`), globals (`{{ @name }}`), meta tags, wrapping defaults, inline defaults, conditionals, collection loops with all options (`limit`, `orderby`, `paginate`, `filteredby`), single item fetch, menu loops with dropdown children, folder loops, pagination, form tag, includes, comments, SEO block, and admin check.
- **`$schema` on all bundled themes** — Personal, Starter, and Skeleton theme.json files include `"$schema": "/outpost/docs/schemas/theme.schema.json"` so developers see the pattern immediately.
- **Files**: `php/docs/schemas/theme.schema.json` (new), `php/themes/skeleton/` (new — theme.json, index.html, blog.html, post.html, contact.html, partials/head.html, partials/nav.html, partials/footer.html, assets/style.css), `php/themes/personal/theme.json`, `php/themes/starter/theme.json`

---

## Developer Documentation — Changelog & Roadmap (v1.9.1)

- **Changelog page** (`docs/changelog.html`) — every release and its changes, formatted for the docs site and updated with each version
- **Roadmap page** (`docs/roadmap.html`) — shipped features, upcoming plans, core constraints, and not-planned items
- Both pages added to the docs sidebar under a new "Project" section

---

## Developer Experience & Theme Updates (v1.9.0)

- **Theme Update System** — auto-updater now safely updates bundled "managed" themes while preserving user modifications. Hash-based conflict detection compares installed files against `.outpost-manifest.json` to decide which files to replace, skip, or flag as conflicts.
- **Managed theme flag** — bundled themes (Personal, Starter) declare `"managed": true` in `theme.json`. The updater only touches managed themes; user-created or duplicated themes are never modified.
- **Duplicate strips managed** — duplicating a managed theme removes the `managed` flag so the copy is fully user-owned.
- **Managed badge in admin** — Themes page shows "Managed by Outpost" label on managed themes and hides the delete button for them.
- **Theme update results** — Settings → Updates shows per-theme results after applying an update (installed, updated with version diff, conflicts with expandable file list).
- **Package manifest generation** — `npm run package` generates `.outpost-manifest.json` with MD5 hashes of all files in each managed theme for conflict detection.
- **New theme auto-install** — when a new theme is added to a future release, the updater installs it automatically on existing sites (with or without `theme.json`).
- **Pre-compilation tag validation** — template engine validates balanced `{% if %}` / `{% for %}` / `{% single %}` tags before compilation. Throws descriptive errors with line numbers for unclosed or mismatched tags.
- **Source line tracking** — compiled PHP templates include `/* @line:N */` markers for mapping runtime errors back to template source lines.
- **Enhanced error display** — admin-only error pages show the template filename, source line number, 5-line context window with error highlighting, and translated friendly messages for common PHP errors. Visitors see a generic error page.
- **Error message translation** — common PHP errors (undefined variable, undefined array key, syntax error, undefined function) are translated into actionable messages that reference template concepts.
- **Files**: `php/themes/personal/theme.json`, `php/themes/starter/theme.json`, `php/themes.php`, `php/api.php`, `php/template-engine.php`, `scripts/package.js`, `src/pages/Themes.svelte`, `src/pages/settings/UpdateSettings.svelte`

---

## Theme Customizer (v1.8.0)

- **Visual color editor** — change accent, text, background, surface, and muted colors with native color pickers and hex input. Changes apply to the theme's CSS custom properties.
- **Font selector** — curated list of ~30 Google Fonts organized by category (sans-serif, serif, display, monospace). Live preview of each font in the dropdown and a sample text preview below.
- **Site identity** — upload logo and favicon from a dedicated UI using the existing MediaPicker. Logo syncs to `@site_logo` global for template compatibility.
- **Live preview** — iframe loads the actual frontend site with real-time CSS variable updates via `postMessage`. No page reload needed for color and font changes.
- **Theme schema** — themes declare customizable fields in `theme.json` under a `customizer` key. The admin builds the UI dynamically from this schema. Themes without a customizer key show a "not supported" message.
- **Export/import presets** — download customizations as a JSON file, import presets from another site running the same theme.
- **Reset to defaults** — one-click revert to the theme's original values.
- **Dark mode safe** — customizer CSS uses `:root:not([data-theme="dark"])` selector so dark mode theme defaults are preserved.
- **Google Fonts injection** — engine strips hardcoded Google Fonts links from theme templates and injects the correct ones based on customizer font selections.
- **Favicon injection** — engine injects `<link rel="icon">` with correct MIME type based on uploaded favicon.
- **Storage** — per-theme customizations saved in `content/data/customizer.json`. Safe from auto-updater, included in backups.
- **New API endpoints** — `customizer` GET/PUT, `customizer/reset` POST, `customizer/export` GET, `customizer/import` POST.
- **New sidebar item** — "Customize" button in Build section below Themes.
- **Themes page integration** — "Customize" button on the active theme card.
- **Files**: `php/customizer.php` (new), `php/api.php`, `php/engine.php`, `php/themes/personal/theme.json`, `src/pages/ThemeCustomizer.svelte` (new), `src/components/customizer/ColorField.svelte` (new), `src/components/customizer/FontField.svelte` (new), `src/components/customizer/ImageField.svelte` (new), `src/components/customizer/CustomizerPreview.svelte` (new), `src/lib/google-fonts.js` (new), `src/lib/api.js`, `src/App.svelte`, `src/components/Sidebar.svelte`, `src/pages/Themes.svelte`

---

## Media Advanced (v1.7.0)

- **Multi-folder assignment** — files can belong to multiple folders. Junction table `media_folder_items` replaces single `folder_id` FK. Detail sidebar shows folder chips with remove buttons and "Add to folder" dropdown.
- **Gallery from folders** — new `{% for img in media_folder.slug %}` template tag renders all media items in a folder. Returns `url`, `alt_text`/`alt`, `width`, `height`, `focal_x`, `focal_y`, `mime_type`, `filename`.
- **Resizable detail sidebar** — drag handle on left edge of the media detail sidebar. Width clamped 220–500px, persisted in localStorage.
- **Bulk folder creation** — type comma-separated names in the folder creation input to create multiple folders at once (max 50). Hint text guides the user.
- **Right-click file context menu** — right-click any file in the media grid to see folder badges, quick "Add to folder" options, Copy Path, and Delete.
- **Role-based folder restrictions** — editors can be scoped to specific media folders in user profile. Restricted editors only see their assigned folders and cannot create/rename/delete folders.
- **Folder slugs** — media folders auto-generate slugs for template tag resolution. "Copy template tag" option in folder context menu.
- **New API endpoints** — `media/folders`, `media/assign-folders`, `media-folders/bulk`, `users/media-folder-grants` GET/PUT.
- **Database migrations** — `media_folder_items` junction table, `slug` column on `media_folders`, `user_media_folder_grants` table.
- **Files**: `php/api.php`, `php/db.php`, `php/engine.php`, `php/template-engine.php`, `php/roles.php`, `php/config.php`, `src/lib/api.js`, `src/lib/stores.js`, `src/pages/MediaLibrary.svelte`, `src/pages/UserProfile.svelte`, `src/App.svelte`

---

## Bulk Media Operations (v1.6.2)

- **Multi-select mode** — "Select" button in page header enters bulk mode with checkbox overlays on grid items. Click to toggle, shift-click for range select, Select All / Deselect All.
- **Bulk delete** — confirm dialog, then `DELETE media/bulk-delete` removes all selected files from disk and database (max 500 per request).
- **Bulk move-to-folder** — dropdown of all folders, moves selected items in one request via existing `media/move` endpoint.
- **displayName() helper** — shows actual stored filenames (with `.webp` extension after auto-conversion) instead of original upload names.
- **Files**: `php/api.php`, `src/lib/api.js`, `src/pages/MediaLibrary.svelte`

---

## Media Library Pro (v1.6.0)

- **WebP auto-conversion** — JPEG and PNG uploads automatically converted to WebP using GD at configurable quality (default 85), reducing file sizes. GIF, SVG, and already-WebP files are skipped. Savings displayed per file in upload queue.
- **Focal point picker** — click anywhere on an image preview in the media sidebar to set its focal point (stored as `focal_x`/`focal_y` 0-100). White marker shows current position, "Reset" link restores to center. New `{{ field | focal }}` template filter outputs `X% Y%` for `object-position` CSS.
- **Media folder organization** — left sidebar folder tree for organizing uploads. Create, rename, delete folders (max 3 levels deep). Right-click context menu on folders. Drag media items from grid onto folders. "All Files" and "Unfiled" virtual folders with counts. Uploads auto-assigned to active folder.
- **Bulk upload with progress** — fixed-position upload queue drawer (bottom-right) with per-file progress bars via XHR. 2 concurrent uploads. Cancel individual files. WebP savings shown per file. Auto-collapses 3 seconds after completion.
- **MediaPicker folder browsing** — folder dropdown in the image picker modal, upload respects selected folder.
- **New API endpoints** — `media-folders` CRUD, `media/move` for bulk folder assignment, `media` list accepts `folder_id` filter, `media/upload` accepts `folder_id` parameter.
- **Database migrations** — auto-adds `focal_x`, `focal_y`, `folder_id` columns to `media` table; creates `media_folders` table.
- **Config constants** — `OUTPOST_WEBP_AUTO_CONVERT`, `OUTPOST_WEBP_QUALITY` in `config.php`.
- **Files**: `php/config.php`, `php/media.php`, `php/db.php`, `php/api.php`, `php/engine.php`, `php/template-engine.php`, `src/lib/api.js`, `src/pages/MediaLibrary.svelte`, `src/components/MediaPicker.svelte`, `src/components/UploadQueue.svelte` (new)

---

## Editorial Workflow (v1.5.0)

- **Review & Approval** — per-collection `require_review` toggle; editors submit items for review instead of publishing directly; admins approve or reject via bulk actions or inline buttons
- **Pending review status** — new `pending_review` status with amber dot indicators in sidebar, item list, right sidebar, and collection editor
- **Approve / Reject API** — `PUT items/approve` and `PUT items/reject` endpoints for bulk review management (admin/super_admin only)
- **Editorial calendar** — month-grid calendar page showing scheduled and published items across all collections by date, with collection filter dropdown, prev/next/today navigation, and click-to-edit
- **Bulk scheduling** — select multiple items in the collection items list and schedule them all to a specific date/time via a datetime picker modal
- **Calendar API** — `GET calendar?start=&end=&collection=` returns items with `published_at` or `scheduled_at` within the date range
- **Collection status counts** — `handle_collections_list()` returns per-collection `draft_count`, `scheduled_count`, `published_count`, `pending_count` for accurate sidebar counts
- **Sidebar updates** — "Pending" sub-item for collections with `require_review` enabled; "Calendar" nav item in Content section
- **Webhook events** — `entry.submitted_for_review`, `entry.approved`, `entry.rejected` fire to registered webhooks
- **Database migrations** — auto-adds `reviewed_by`, `reviewed_at` columns to `collection_items`; `require_review` column to `collections`
- **Files**: `php/api.php`, `src/lib/api.js`, `src/pages/Calendar.svelte` (new), `src/pages/CollectionItems.svelte`, `src/pages/CollectionEditor.svelte`, `src/pages/CollectionList.svelte`, `src/components/RightSidebar.svelte`, `src/components/Sidebar.svelte`, `src/App.svelte`

---

## User Content Directory (v1.4.0)

- **Single `content/` directory** — all user-owned data (`data/`, `uploads/`, `themes/`, `backups/`) consolidated under `outpost/content/` for simpler backup, migration, and deployment
- **Auto-migration** — existing sites automatically move directories into `content/` on first load, with zero manual intervention
- **Symlink compatibility** — `outpost/uploads` and `outpost/themes` symlink to `content/uploads` and `content/themes`, preserving all existing media and theme asset URLs
- **Updated installer** — fresh installs create the `content/` structure with symlinks and `.htaccess` protection
- **Updated auto-updater** — skips the entire `content/` directory during updates (replaces individual skip entries for data, uploads, themes)
- **Updated packager** — `npm run package` produces the new `content/` layout with symlinks in the distribution zip
- **Files**: `php/config.php`, `php/install.php`, `php/api.php`, `php/content-api.php`, `php/front-router.php`, `php/sync-api.php`, `scripts/package.js`

---

## Backup Includes Themes (v1.3.2)

- **Themes included in backups** — `create_backup_zip()` now backs up the entire `themes/` directory alongside the database and uploads
- **Restore extracts themes** — `handle_backup_restore()` extracts `themes/` entries from the backup zip back into the themes directory
- **Refactored directory add** — shared closure `$addDir()` eliminates duplicate directory-walking code for uploads and themes
- **Files**: `php/api.php`

---

## Auto Update Check with Sidebar Notification (v1.3.1)

- **Auto update check on login** — `auth/me` response includes `update_available` and `latest_version` for admin/super_admin users, piggybacking on the existing 5-minute GitHub API cache
- **Sidebar badge** — green dot appears next to "Settings" in the desktop sidebar when an update is available
- **Mobile nav badge** — same green dot on "Settings" in the mobile drawer
- **Badge clears on update** — applying an update via Settings → Updates immediately removes the dot
- **Non-admin users excluded** — editors and developers never see update status or the badge
- **Zero additional API calls** — reuses the same `update_check_cache` as the existing Updates page; GitHub is hit at most once per 5 minutes
- **Files**: `php/api.php`, `src/lib/stores.js`, `src/App.svelte`, `src/components/Sidebar.svelte`, `src/components/MobileNav.svelte`, `src/pages/settings/UpdateSettings.svelte`

---

## Backup & Restore (v1.3.0)

- **One-click backup** — creates a zip containing the SQLite database (`cms.db`) and all uploaded media; stored in `outpost/backups/`
- **Restore from backup** — upload a backup zip to replace the current database and uploads (super_admin only); safety copy of current DB before overwrite
- **Backup history** — list all backups with filename, size, date; download or delete individual backups
- **Automatic backups** — enable daily or weekly auto-backup from the Backups page; configurable max retention (old backups pruned automatically); triggered on dashboard load
- **Admin UI** — Backups page in sidebar (admin/super_admin only) with create, download, delete, restore, and settings sections
- **Security** — `.htaccess` Deny-from-all on backups directory; filename validation prevents path traversal; restore requires `super_admin` role
- **API endpoints**: `backup/create` (POST), `backup/list` (GET), `backup/download` (GET), `backup/restore` (POST), `backup/delete` (DELETE), `backup/settings` (GET/PUT)
- **Files**: `php/api.php`, `php/config.php`, `src/pages/Backups.svelte`, `src/App.svelte`, `src/components/Sidebar.svelte`, `src/components/MobileNav.svelte`, `src/lib/api.js`

---

## Admin Role Refinement (v1.2.0)

- **Content Editor cleanup** — Channels, Form Builder, Code Editor, Settings, and Themes hidden from editors in sidebar and mobile nav; route guards redirect to AccessDenied
- **Collection-scoped editors** — admins can restrict an editor to specific collections via checkboxes on their user profile; backend enforces on list, create, update, delete, and bulk operations
- **Per-page locks** — admins toggle a "Lock page" checkbox in the PageEditor sidebar; locked pages show a banner and disabled controls for non-admin users; backend blocks updates, deletes, and field saves on locked pages
- Developer role added to user profile role selector
- `user_collection_grants` table with `(user_id, collection_id)` unique pairs; `pages.locked` column
- `GET/PUT users/grants` API endpoints; `collection_grants` in `auth/me` response
- **Files**: `php/roles.php`, `php/api.php`, `src/lib/stores.js`, `src/lib/api.js`, `src/App.svelte`, `src/components/Sidebar.svelte`, `src/components/MobileNav.svelte`, `src/pages/UserProfile.svelte`, `src/pages/PageEditor.svelte`, `src/pages/Pages.svelte`

---

## Security Hardening (v1.0.0-beta.14)

Comprehensive security audit and hardening pass across the entire PHP backend — 37 findings addressed across auth, sessions, SSRF, XSS, file operations, input validation, and rate limiting.

- **SSRF prevention** — shared `outpost_ssrf_guard()` blocks private IPs, loopback, link-local, cloud metadata (`169.254.169.254`), and non-HTTP protocols in Channels and Webhooks. `CURLOPT_PROTOCOLS` restricted on all curl handles.
- **Session cookie `secure` flag** — HTTPS-only cookies enforced in production for both admin and member sessions
- **Member session key fix** — `members.php` session keys aligned with `engine.php` reads (`outpost_member_id` etc.) so `members`/`paid` visibility gates work correctly
- **IP-based login rate limiting** — admin and member login brute-force no longer bypassable by discarding session cookies. Uses `login_rate_limits` DB table keyed by IP.
- **TOTP replay prevention** — `totp_last_code` column prevents same 6-digit code reuse within validity window
- **TOTP verify rate limit** — 5 attempts per 5 minutes per IP on public verify endpoint
- **TOTP single-use token** — pre-session token includes DB-backed nonce, cleared after use
- **Open redirect fix** — `form.php` `_redirect` field enforces relative paths only (no `//`, no absolute URLs)
- **Email injection fix** — `_notify` POST field removed from public form submissions; `mailer.php` validates email format and strips CRLF
- **DOM-based SVG sanitizer** — replaces regex check; blocks `xlink:href`, `data:` URIs, `foreignObject`, `<script>`, all `on*` event handlers
- **Richtext sanitizer** — blocks `data:` and `vbscript:` URI schemes alongside `javascript:`
- **API key O(1) lookup** — prefix-based DB query instead of O(n) bcrypt scan (prevents DoS)
- **Auto-updater URL hardening** — strict hostname + path check via `parse_url()` instead of `str_contains`
- **Zip slip prevention** — all zip entry paths validated before extraction in auto-updater
- **Channel credential masking** — `auth_config` values masked in admin API responses, preserved transparently on update
- **Rate limits on forgot/reset** — admin and member password reset endpoints capped at 5 requests per 5 minutes per IP
- **CORS origin tightened** — only exact `localhost`/`127.0.0.1` matches, not substring
- **Page delete path containment** — `realpath()` check before `unlink()`
- **Email validation** — `filter_var()` check on user create/update
- **Role read consistency** — user create/update uses `OutpostAuth::currentUser()` instead of `$_SESSION` directly
- **Member session DB revalidation** — suspended members detected within 5 minutes via periodic DB check
- **`scheduled_at` validation** — datetime format validated before storing
- **Template cache `.htaccess`** — `Deny from all` protects compiled PHP templates from direct HTTP access
- **XSS fix in form builder** — HTML field type content passed through `OutpostSanitizer::clean()`
- **Files**: `php/http-security.php` (new shared helpers), `php/api.php`, `php/auth.php`, `php/members.php`, `php/totp.php`, `php/channels.php`, `php/webhooks.php`, `php/form.php`, `php/mailer.php`, `php/forms-engine.php`, `php/media.php`, `php/sanitizer.php`, `php/member-api.php`

---

## On-Page Editing

Edit content directly on the live frontend while logged in as an admin — no round-trip to the admin panel.

- **Admin bar edit toggle**: "Edit page" button in the floating admin bar toggles on-page editing mode on/off (persisted in localStorage)
- **Field annotations**: When admin is logged in, `cms_text()`, `cms_richtext()`, `cms_textarea()`, `cms_image()`, and `cms_global()` wrap output in `data-ope-*` annotated elements for the JS editor to detect
- **Collection single pages**: Fields inside `{% single %}` blocks are annotated with item ID and collection slug for inline editing
- **Hover outlines**: Editable fields show a subtle blue outline on hover with a floating field name label
- **Text editing**: Click any text/textarea field to activate `contenteditable` — auto-save on blur, cancel with Escape, Enter saves single-line text
- **RichText editing**: Click a richtext field to activate a floating TipTap toolbar (bold, italic, strike, H2, H3, lists, blockquote, link) — saves on click-outside
- **Image editing**: Click an image field to open a media picker modal — browse existing images or upload new ones, saves immediately on selection
- **Auto-save**: Changes are debounced (400ms) and batched into a single API call. Save status shown in the admin bar (saving/saved indicators)
- **Two save paths**: Page/global fields via `PUT fields/bulk`, collection item fields via `PUT items/inline` (partial JSON merge)
- **`PUT api.php?action=items/inline`** — partial field update for collection items: accepts `id` + `fields` object, merges into existing `data` JSON, supports optimistic locking, creates revision, clears cache
- **Editor bundle**: Standalone Vite IIFE build (`on-page-editor.js` + `on-page-editor.css`) — only loaded for authenticated admins, zero impact on frontend visitors
- **No layout editing**: This is content editing, not page building — field positions are determined by the theme template

---

## Channels — External Data Sources (Phase 1: REST API + Phase 2: RSS + CSV)

Pull data from external REST APIs, RSS feeds, and CSV files into Liquid templates using the same `{% for %}` loop syntax used for collections. Collections = your content. Channels = external content that flows in.

- **Database**: `channels`, `channel_items`, `channel_sync_log` tables with full cascade deletes and indexed queries
- **Channel engine** (`channels.php`): `channel_fetch_api()` with cURL (auth types: none, API key, bearer, basic), `channel_fetch_all_pages()` with offset/cursor pagination, `channel_extract_data()` for dot-notation JSON path traversal, `channel_discover_schema()` for recursive field type detection
- **Sync engine**: `channel_sync()` fetches external API, upserts items into `channel_items` (by external ID), removes stale items, logs to `channel_sync_log` with duration/counts. Auto-sync on template render when `cache_ttl` expires
- **Template syntax**: `{% for item in channel.slug %}` loops cached items. `{% single item from channel.slug %}` resolves by slug or external ID. `{% else %}` empty-state supported. Options: `limit:N`, `orderby:field`
- **Front-end routing**: Channel URL patterns (e.g. `/listing/{slug}`) in both `front-router.php` and `test-site/index.php` — resolves to theme template `slug.html` or fallback `channel.html`
- **API endpoints**: Full CRUD (`channels`), manual sync trigger (`channels/sync`), sync history (`channels/sync-log`), schema discovery (`channels/discover`), cached item preview (`channels/items`)
- **Schema discovery**: POST a URL + auth config → fetches API → returns field tree with types/samples + auto-detected data path + first 3 sample items
- **Admin UI — Channels list** (`ChannelsList.svelte`): Card grid with name, slug, status badge, item count, last sync time, error display. Create modal with name/slug + auto-template hint
- **Admin UI — Channel Builder** (`ChannelBuilder.svelte`): 3-tab layout (Setup, Data, Sync) with 4-step wizard in Setup tab:
  - Step 1 (Connect): URL input, method selector, auth method with conditional fields, key-value editors for headers/params, test connection button
  - Step 2 (Schema): Data path input, JSON tree explorer with checkboxes, ID/slug field selectors, sample preview
  - Step 3 (Configure): Name/slug, cache TTL selector (5min to manual), max items, sort field/direction, URL pattern
  - Step 4 (Preview): Item preview table, auto-generated template code with copy button
- **Sync dashboard**: Status overview, manual sync button, sync history table (timestamp, status, added/updated/removed counts, duration, errors)
- **6 subcomponents**: `ConnectStep`, `SchemaStep`, `ConfigStep`, `PreviewStep`, `JsonTree`, `KeyValueEditor`
- **RSS channel type** (v1.1.0): `channel_fetch_rss()` parses RSS 2.0 and Atom 1.0 feeds via SimpleXML. Auto-maps standard fields: title, link, description, pubDate, author, content (content:encoded), category, guid, enclosure_url. Fallback guid from link or hash of title+pubDate. SSRF guard applied.
- **CSV channel type** (v1.1.0): `channel_fetch_csv()` downloads CSV from any URL, parses via `fgetcsv()` on `php://temp` stream (handles quoted newlines). Configurable delimiter (comma, tab, semicolon, pipe), first-row-is-headers toggle, UTF-8 BOM stripping, header name sanitization. SSRF guard applied.
- **Source type selector** (v1.1.0): Channel creation wizard shows REST API / RSS Feed / CSV pill selector. Conditional UI hides irrelevant fields per type (Method/Headers/Params for API only, delimiter/headers toggle for CSV only). Type is immutable after creation.
- **Type-dispatched discovery** (v1.1.0): `handle_channel_discover()` routes to `channel_discover_rss()` or `channel_discover_csv()` based on type. RSS discovery builds schema from actual feed fields. CSV discovery uses existing `channel_discover_schema()` on parsed rows.
- **Type badge in channel list** (v1.1.0): Cards show API/RSS/CSV label in metadata row

---

## Visual Form Builder (Phase 1)

A drag-and-drop form builder that lets admins create structured forms visually, replacing hand-coded HTML forms in themes. Forms render via the `{% form 'slug' %}` template tag.

- **Form Builder UI** (`FormBuilder.svelte`): 3-column layout — field palette (left), sortable field list (center), field settings (right). Tabs for Fields, Settings, and Preview
- **14 field types**: text, email, phone, url, number, textarea, select, radio, checkbox, date, time, hidden, html, section
- **Field settings**: Label, machine name, type, required, placeholder, description, default value, CSS classes, choices (for select/radio/checkbox), number min/max/step, textarea rows, HTML content
- **SortableJS drag reorder**: Fields can be reordered via drag handle in the field list
- **Form settings**: Submit button label, honeypot spam protection toggle, confirmation type (message or redirect URL)
- **Template tag**: `{% form 'slug' %}` compiles to `cms_form('slug')` which renders a complete HTML form with proper field types, labels, validation, and honeypot
- **Forms list** (`FormsList.svelte`): Grid of builder forms with create, edit, duplicate, delete actions
- **Enhanced submissions inbox** (`FormSubmissions.svelte`): Starring, notes, bulk actions (mark read, star, archive, delete), status filtering (active/archived/all), form-specific filters, per-form notification email config
- **Builder-aware submission handling**: `form.php` validates required fields from form schema, stores `form_id` link, checks honeypot, supports array checkbox values
- **`forms` database table**: Stores form definitions with fields JSON, settings JSON, notifications/feeds JSON (for future phases), slug, status (active/draft/inactive)
- **Enhanced `form_submissions` table**: New columns for `form_id`, `status`, `starred`, `notes`, `user_agent`
- **API endpoints**: Full CRUD for forms/builder, duplicate, enhanced submission endpoints (star, status, notes, bulk actions)
- **URL pre-population**: Form fields auto-fill from query parameters (`$_GET[$field['name']]`)
- **No JS dependencies**: Rendered forms are pure HTML — no JavaScript required for basic submission

---

## Flexible Content Field Type (ACF Pro Parity)

A layout builder field type that lets editors compose content from multiple named layout types, each with their own set of sub-fields. Equivalent to ACF Pro's Flexible Content.

- **Layout definitions**: Developers define named layouts (e.g. "Hero", "Testimonial", "CTA") in the collection schema builder, each with its own sub-field set (text, image, richtext, etc.)
- **Editor UI** (`FlexibleField.svelte`): Collapsible layout rows with type badge, sub-field rendering per layout, add/remove/reorder rows, media picker integration for image sub-fields
- **Template syntax**: `{% for block in flexible.field_name %}` — iterates layout rows. `block.layout` returns the layout type name. All sub-fields accessible as `block.field_name`
- **Engine function** (`cms_flexible_items()`): Resolves field data, returns array of associative arrays with `layout` key and escaped sub-field values
- **Template compilation**: Regex in `template-engine.php` compiles to `foreach (cms_flexible_items('name') as $block)`
- **Schema builder**: "Flexible Content" option in field type dropdown with JSON textarea for defining layouts and their sub-fields
- **Storage**: JSON array in `fields.content` (pages) or `collection_items.data` (collections), each row has `_layout` key

---

## Relationship Field Type (ACF Pro Parity)

A content relationship field that lets editors search for and select items from another collection. Links content together by storing item IDs and resolving full item data at render time.

- **Collection-scoped**: Each relationship field targets a specific collection (e.g. "Related Posts" targets the `post` collection)
- **Editor UI** (`RelationshipField.svelte`): Search input with debounced filtering, clickable result dropdown, selected items as cards with remove button, up/down reorder for multiple selections
- **Single/Multiple mode**: `multiple: false` replaces on select; `multiple: true` with optional `max` limit
- **Template syntax**: `{% for item in relationship.field_name %}` — iterates related items with all standard collection item fields (`title`, `url`, `slug`, `published_at`, etc.)
- **Engine function** (`cms_relationship_items()`): Fetches items by stored IDs from `collection_items` table, preserves stored order, builds URLs from `url_pattern`, escapes non-richtext fields
- **Template compilation**: Regex in `template-engine.php` compiles to `foreach (cms_relationship_items('name') as $item)`
- **Schema builder**: "Relationship" option in field type dropdown with collection selector, multiple toggle, and max items input

---

## Conditional Logic (ACF Pro Parity)

Show/hide collection schema fields in the editor based on other field values. Purely a UI feature — conditions only affect admin editor visibility, not template rendering.

- **Per-field conditions**: Any field in a collection schema can have conditional rules defined in the schema builder expanded options
- **Operators**: `equals` (`==`), `not equal` (`!=`), `has value` (`not_empty`), `is empty` (`empty`)
- **Multiple conditions**: Multiple rules per field, all must pass (AND logic). Add/remove condition rows in the schema builder
- **Real-time evaluation**: CollectionEditor evaluates conditions against current metaField values on every change — fields hide/show instantly without save
- **Toggle-aware**: `==` operator handles toggle truthiness (`'1'`/`'true'` mapped to `true`, `'0'`/`''`/`'false'` mapped to `false`)
- **Schema storage**: Conditions stored in the field's schema definition as `conditions: [{field, operator, value}]`
- **No backend changes**: Conditions are evaluated entirely client-side; hidden fields still save their values (they're just not visible in the editor)

---

## Mobile Admin Audit — 375px Viewport Fixes

Comprehensive mobile audit fixing every admin page for 375px and 480px viewports. Ensures no content is hidden behind the bottom tab bar, no horizontal overflow, and all actions are reachable on touch devices.

- **Critical 480px padding fix** (`admin.css`): The small-phone breakpoint was overriding `.app-content` padding without re-applying `padding-bottom` for the mobile nav height — content was hidden behind the tab bar on phones < 480px
- **Full-viewport editors** (PageEditor, CollectionEditor, CodeEditor, Forms, TemplateReference): All account for mobile nav height in their `bottom` offset or shell height calc
- **Toolbar overflow fixes** (MediaLibrary, Forms, CollectionEditor): Flex toolbars with search inputs, filter chips, and buttons now wrap gracefully at 375px
- **Table/list responsive patterns** (TeamSettings, MembersSettings, FolderLabels, Pages, CollectionItems): Fixed-width columns collapse, hover-only actions become always-visible on touch, column headers hidden on mobile
- **Analytics responsive refinements** (AnalyticsTraffic, AnalyticsEvents): Funnel wraps, stat numbers scale down, table headers hidden at 600px
- **Settings pages** (IntegrationsSettings, EmailSettings): Deliveries table gets scroll wrapper, event groups and form rows stack to single column
- **Minor fixes** (Dashboard, UserProfile): Flex-wrap on action bars and profile headers

---

## Mobile Navigation — Bottom Tabs + More Drawer

Replaced the hamburger-toggled sidebar with a native mobile navigation pattern: a fixed bottom tab bar with primary actions and a slide-up "More" drawer for everything else.

- **Bottom tab bar** (`MobileNav.svelte`): Fixed to bottom on mobile (≤768px), 5 tabs — Dashboard, Pages, Media, Content (first collection), More. Dark sidebar theme, safe-area padding for notched phones, active state highlighting
- **More drawer**: Bottom-sheet slide-up with semi-transparent backdrop, rounded top corners, max-height 75vh, scrollable. Contains all remaining nav items organized into Content, Collections, Build, and Account sections. Respects permission flags (`showSettings`, `showCode`)
- **Desktop sidebar preserved**: Sidebar uses `display: none` on mobile instead of transform/position tricks. Desktop layout unchanged
- **Hamburger removed**: TopBar sidebar toggle hidden on mobile — no longer needed
- **Safe-area spacing**: Toast container, save bars, and app content all account for bottom nav height + safe-area inset

---

## TOTP Two-Factor Authentication

Optional per-user TOTP 2FA. Users enable it from their profile. On login, if 2FA is enabled, a 6-digit code from an authenticator app (or a one-time backup recovery code) is required after the password step. Pure PHP implementation — no Composer dependencies.

- **TOTP library** (`php/totp.php`): Self-contained `OutpostTOTP` class — Base32 encode/decode, RFC 6238 code generation/verification (±1 time window), `otpauth://` URI builder, 8 backup codes with bcrypt hashing, HMAC-signed temporary tokens (5min TTL) for the 2FA handshake
- **Login flow**: `handle_login()` verifies password first. If `totp_enabled=1`, returns `{ requires_2fa, totp_token }` instead of a session. Frontend shows a TOTP challenge screen; on code entry, `auth/totp/verify` validates the signed token + TOTP code (or backup code) and creates the session
- **Profile Security section**: QR code setup via `qrcode` npm package, 6-digit verify-to-enable, 8 backup codes displayed in a 2-column grid with copy + "I have saved these" confirmation, disable (password required), regenerate backup codes (password required)
- **Backup codes**: 8 codes, 10 chars each (no ambiguous characters), bcrypt-hashed, consumed on use
- **API endpoints**: `auth/totp/verify` (public), `auth/totp/setup`, `auth/totp/enable`, `auth/totp/disable`, `auth/totp/backup-codes`, `auth/totp/status` (authenticated)
- **Migration**: Adds `totp_secret`, `totp_enabled`, `backup_codes` columns to `users` table
- **API key auth**: Unaffected — 2FA only applies to browser session login

---

## Revision History — Field-Level Diffs

The History tab now shows exactly what changed in each save instead of a generic field count.

- **Accurate change count**: Each revision shows only the number of fields that actually changed, computed by diffing consecutive snapshots
- **Expandable diff panel**: Click any revision row to see a table of changes — field name on the left, old → new values on the right
- **Smart type handling**: Richtext shows "content changed" (no raw HTML), image/gallery fields show type-specific labels, text fields show actual old → new values
- **New API endpoint**: `revisions/diff` returns field-level diffs for any revision on demand

---

## Collection Editor — Sidebar History Tab

Moved revision history from a collapsible topbar drawer to a proper "History" tab in the right sidebar, matching the PageEditor's tab pattern.

- **RightSidebar**: Added third tab (Post / SEO / History) with `RevisionList` component
- **Reload signal**: `editorReloadSignal` store lets the sidebar tell the CollectionEditor to refresh after a revision restore
- **Removed**: History toggle button from topbar + drawer markup + dead CSS from CollectionEditor
- **Consistent UX**: Both PageEditor and CollectionEditor now access revision history via sidebar tabs

---

## Optimistic Locking (Concurrent Edit Protection)

Prevents two admins from silently overwriting each other's changes. Uses `updated_at` timestamps as version tokens — the frontend sends its last-known timestamp with every save, and the backend rejects saves where the timestamp has changed (meaning someone else saved in between).

- **Backend** (`api.php`): `handle_page_update()`, `handle_fields_bulk_update()`, and `handle_item_update()` accept optional `_version` / `_page_versions` params. When present, the UPDATE includes a `WHERE updated_at = ?` check; mismatches return HTTP 409 with `server_updated_at`. When absent (API keys, scripts), existing last-write-wins behavior is preserved for backward compatibility.
- **Backend** (`api.php`): `handle_globals_get()` now returns `page_id` and `updated_at` in the response so the Globals editor can participate in locking.
- **Backend** (`api.php`): `handle_fields_bulk_update()` now bumps `pages.updated_at` after field writes so future saves detect the change.
- **API client** (`src/lib/api.js`): New `ConflictError` class (extends `Error`) thrown on HTTP 409 responses. `fields.bulkUpdate()` accepts optional `pageVersions` map.
- **PageEditor**: Tracks `pageVersion` from load, sends with both field bulk updates and page meta saves, shows conflict banner on `ConflictError`.
- **CollectionEditor**: Tracks `itemVersion` from load, sends `_version` with save/publish/unpublish, shows conflict banner on `ConflictError`.
- **Globals**: Tracks `globalVersion` + `globalPageId` from load, sends `_page_versions` with field bulk updates, shows conflict banner on `ConflictError`.
- **Conflict UI**: Amber warning banner with "Reload" (discards local changes, fetches latest) and "Save anyway" (clears version to force overwrite). Dark mode variant included.

---

## Mobile Admin Responsiveness

The entire admin panel is now fully responsive and usable on phones and tablets. Modals slide up as bottom sheets, the sidebar has a tap-to-dismiss backdrop, touch targets meet 44px minimum, and every page layout stacks properly on small screens.

### App shell
- **Sidebar backdrop** (`App.svelte`): Semi-transparent overlay with blur appears behind the sidebar on mobile — tap outside to close
- **Sidebar**: Slides in with `cubic-bezier` easing, capped at `85vw` width so it never covers the full screen
- **Topbar**: Tighter padding, username/role hidden, "View site" becomes icon-only
- **Touch targets**: All buttons enforce `min-height: 40px`, sidebar items `44px`

### Global CSS (`admin.css`)
- **Modals**: Slide up from bottom as sheets (`border-radius` top only), full-width, `92vh` max-height, safe-area padding for notched phones
- **Forms**: All inputs `max-width: 100%`, schema fields wrap, repeater rows stack vertically
- **Tables**: `overflow-x: auto` for horizontal scroll
- **Rich text toolbar**: Bigger touch targets (34px buttons)
- **Save bar / toasts**: Full-width, safe-area-aware bottom padding
- **Cards**: Reduced padding and radius
- **480px breakpoint**: Stat cards single-column, page header icon hidden, tighter content padding

### Per-page fixes
- **PageEditor**: Editor + sidebar stack vertically, sidebar full-width with `50vh` cap, title font shrinks
- **CollectionEditor**: Padding reduced, add-block dropdown constrained, image URL input full-width
- **Navigation**: Two-column layout stacks, menu sidebar becomes horizontal wrap, column headers hidden, picker modal constrained
- **MediaLibrary**: Inline flex layout replaced with `.media-layout` class, stacks vertically on mobile
- **TaxonomyManager**: Create form fields stack, fixed flex-basis removed, breakpoint at 768px
- **Themes**: Grid goes single-column
- **CollectionList**: Inline grid replaced with `.coll-grid` class, single-column on mobile
- **Forms**: Left list + detail stacks vertically, list capped at `40vh`
- **Pages**: Search input goes full-width, subtitle `max-width` removed
- **CollectionItems**: Search and header actions wrap, subtitle unconstrained
- **SearchModal**: Full-screen takeover on mobile (no border-radius, fills viewport)

---

## Inline Form Validation

Client-side field-level validation with inline error messages below inputs. Errors show on blur and clear on input, with full validation on submit to prevent bad data.

- **Shared validation module** (`src/lib/validation.js`): Reusable rules — `required`, `email`, `minLength`, `slug`, `match`
- **UserProfile**: Username required, email format, password min 8 chars, password confirmation match
- **Login (reset form)**: Password min 8 chars, password confirmation match
- **TaxonomyEdit**: Name required, slug required + format (lowercase, hyphens, underscores)
- **TaxonomyTermEdit**: Name required, slug required + format
- **UX pattern**: Error clears on input (immediate feedback), validates on blur (non-intrusive), full validation on submit (safety net)
- **CSS**: `.input-error` (red border) + `.field-error` (12px danger text below input) in `admin.css`

---

## Auto-Cleanup of Stale Template Fields

When template fields are removed from `.html` theme files, their registry and stub entries are now automatically pruned during theme activation.

- **Registry cleanup**: `page_field_registry` entries for removed fields are deleted when `outpost_scan_theme_templates()` runs
- **Empty stub cleanup**: `fields` rows with no user content (`''` or `'[]'`) are deleted for removed fields
- **User data preserved**: Fields with actual content are never deleted — only hidden from the editor via API filtering
- **Global field registry**: `@global` fields are now tracked in `page_field_registry` (path `__global__`), enabling the same cleanup for globals
- **Defense-in-depth**: `handle_page_get()` and `handle_globals_get()` filter responses against the registry, hiding stale fields even if DB cleanup hasn't run yet

---

## cPanel Deployment Guide

Deploy docs now lead with cPanel shared hosting as the primary deployment target.

- **`.htaccess` included**: A ready-to-use Apache rewrite file ships in the dist — cPanel users upload it alongside `index.php` and `outpost/`
- **Step-by-step cPanel walkthrough**: Upload files, check PHP version via MultiPHP Manager, verify extensions via MultiPHP INI Editor, set directory permissions, and go live
- **SSL via AutoSSL**: Covers cPanel's built-in Let's Encrypt integration instead of Certbot CLI
- **Subdirectory installs**: Works the same in a subfolder — no extra config needed
- **VPS sections preserved**: Apache virtual host and Nginx server block configs remain for non-cPanel deployments

---

## Template Error Handling

Template compile and runtime errors now show a friendly 503 page instead of a white screen.

- **Compile errors**: Bad Liquid syntax or invalid template constructs are caught during compilation — visitors see "Something went wrong" instead of a blank page
- **Runtime errors**: PHP errors in compiled template output (e.g. undefined function calls) are caught and the bad cache file is removed so the next request recompiles
- **Admin detail**: Logged-in admins see the error message and stack trace on the error page for debugging
- **Partial errors**: Partial compile/runtime errors are caught individually — the rest of the page still renders, with an HTML comment marking the failed partial
- **Logging**: All template errors are sent to `error_log()` for server-side diagnosis

---

## Wrapping-Tag Default Syntax

A new recommended syntax for setting default values on template fields. Instead of inline quoted strings, defaults are now placed between opening and closing tags — more readable, supports multiline content and HTML in defaults.

- **Wrapping syntax**: `{{ field }}Default text{{ /field }}` — closing tag uses field name prefixed with `/`
- **Filtered fields**: `{{ field | raw }}<p>Rich HTML</p>{{ /field }}` — closing tag uses field name only, not the filter
- **Meta tags**: `{{ meta.title }}My Site{{ /meta.title }}` and `{{ meta.description }}Description{{ /meta.description }}`
- **Backwards compatible**: Inline syntax `{{ field "Default" }}` still works — wrapping is the recommended approach
- **Compile-time only**: No changes to engine.php — the template compiler produces identical PHP output
- **Personal theme migrated**: All 11 inline defaults across 4 templates converted to wrapping syntax

---

## Template-Derived Schema Fields

The `content/schema` API endpoint now returns only fields that actually exist in the active theme's Liquid templates, instead of stale entries from the `fields` database table.

- **Template parsing**: `extractTemplateFields()` reads `.html` templates and extracts `{{ field_name }}`, `{{ field_name | type }}`, and `{% if field_name %}` references via regex
- **Partial resolution**: Follows `{% include 'name' %}` directives to also extract fields from `partials/*.html` (one level deep)
- **Global extraction**: `extractAllGlobalFields()` scans all theme templates + partials for `{{ @name }}` and `{% if @name %}` references
- **Page-to-template mapping**: `resolveTemplateFile()` maps page paths (`/`, `/about`, `/blog`) to theme template files (`index.html`, `about.html`, `blog.html`)
- **Exclusions**: Globals (`@name`), meta (`meta.title`), loop variables (`item.field`), and system built-ins (`id`, `path`, `title`, `meta_title`, `meta_description`) are correctly excluded from page fields
- **Result**: Template Reference page shows only fields the theme actually uses (e.g., Home page shows 1 field instead of 33 stale ones)

### Full Template References Per Page

Each page in Template Reference now shows **all** references its template uses — not just page-level fields.

- **`extractAllTemplateReferences()`**: New comprehensive extraction function in `content-api.php` that returns 8 reference types: `fields`, `globals`, `collections`, `singles`, `galleries`, `repeaters`, `menus`, `folders`
- **Schema endpoint**: Each page in `content/schema` response now includes all 8 reference arrays (spread from `extractAllTemplateReferences()`)
- **Template Reference UI**: Column 2 shows grouped sections (FIELDS, GLOBALS, COLLECTIONS, SINGLES, GALLERIES, REPEATERS, MENUS, FOLDERS) — each only when non-empty, with copyable syntax snippets
- **Syntax Preview**: `generateTemplate()` for pages now builds a rich multi-section preview combining all reference types
- **Regex patterns**: Collections with options (`limit:3 orderby:field`), singles (`{% single %}`), galleries, repeaters, menus, and folders all extracted from template source + resolved partials

---

## Settings Hub Redesign

Consolidated all admin/configuration screens into a single Settings page with an internal left sidebar nav (Ghost/Linear pattern). Reduces sidebar clutter from 4 admin items to 1.

- **Settings hub**: Single page at `#/settings` with 7 internal sections: General, Team, Members, Email, Integrations, Import, Advanced
- **Internal nav**: 200px sticky left sidebar with plain text items, Ghost-style active states. Grid layout (`200px 1fr`) with responsive stack at 768px
- **Sections absorbed**: Users (now Team), Members, Import, and Webhooks are no longer standalone sidebar items — all live inside Settings
- **Sidebar cleanup**: Build section lost Import and Webhooks; Admin section lost Users and Members. Settings and Log out remain
- **Routing**: URL format `#/settings/section=team`. New `currentSettingsSection` store. Old URLs (`#/users`, `#/members`, `#/import`, `#/webhooks`) auto-redirect to the correct Settings section
- **Sub-components**: Each section is its own Svelte component in `src/pages/settings/` — GeneralSettings, TeamSettings, MembersSettings, EmailSettings, IntegrationsSettings, ImportSettings, AdvancedSettings
- **Integrations section**: Combines API keys CRUD, Webhooks CRUD, and reCAPTCHA keys into one panel with divider-separated blocks
- **Advanced section**: Combines cache toggle/clear, Sync & Deploy keys, and Scheduled Publishing (auto-cron, no server config needed)
- **Save bar**: Shared save bar appears at bottom only when key-value settings are dirty (General, Email, Members verification toggle, Integrations reCAPTCHA, Advanced cache toggle)
- **UserProfile**: Back button now returns to Settings > Team instead of standalone Users page

---

## API Key Authentication for Headless Use

Bearer token authentication for the admin API — enables CI/CD pipelines, static site generators, and external tools to use the full admin API without browser sessions.

- **Bearer token auth**: Send `Authorization: Bearer op_<key>` header instead of session cookies + CSRF tokens.
- **Key management UI**: Settings > API Keys — create named keys, view prefix + last-used date, revoke with confirmation.
- **Database**: `api_keys` table with `key_hash` (bcrypt), `key_prefix` (first 11 chars for display), `user_id` (inherits role/permissions), `last_used_at`, `created_at`.
- **Security**: Keys are bcrypt-hashed (only shown once on creation). CSRF validation is skipped for API key requests (CSRF only protects browser sessions). IP-based rate limiting for API key auth (no session).
- **API endpoints**:
  - `GET api.php?action=apikeys` — list all keys (name, prefix, user, timestamps)
  - `POST api.php?action=apikeys` with `{name}` — create a new key (returns full key once)
  - `DELETE api.php?action=apikeys&id=N` — revoke a key
- **Permissions**: API key endpoints require `settings.*` capability. The key inherits its linked user's role for all other endpoints.
- **Usage**: `curl -H "Authorization: Bearer op_..." "https://example.com/outpost/api.php?action=pages"`

---

## Content Revision History & Rollback

Automatic version history for pages and collection items — every save snapshots the current content before overwriting, so previous versions can be browsed and restored.

- **Automatic snapshots**: On every save of a page or collection item, the current content is snapshotted into a `revisions` table before the new content is written. No manual "save version" step needed.
- **Retention**: Last 25 revisions per entity. Older revisions are pruned automatically on each save.
- **Database**: `revisions` table with `entity_type` (page/item), `entity_id`, `data` (JSON field snapshot), `meta` (JSON meta snapshot), `created_by`, `created_at`.
- **API endpoints**:
  - `GET api.php?action=revisions&entity_type=page&entity_id=N` — list revisions (newest first)
  - `POST api.php?action=revisions/restore` with `{entity_type, entity_id, revision_id}` — restore a revision (snapshots current state first, so restore is undoable)
- **PageEditor**: "History" tab in the sidebar alongside Page and SEO. Shows scrollable revision list with relative timestamps, user who saved, and field count. One-click restore with confirmation.
- **CollectionEditor**: "History" button in the top bar. Opens an inline drawer with the same revision list. Restore reloads the item editor.
- **Shared component**: `RevisionList.svelte` reused by both editors. Props: `entityType`, `entityId`, `onRestore` callback.
- **JS API client**: `revisions.list(entityType, entityId)` and `revisions.restore(entityType, entityId, revisionId)` added to `src/lib/api.js`.

---

## Media Editing (Alt Text, Resize, Crop)

Edit images directly in the media library sidebar without leaving the admin.

- **Alt text**: Editable text input in the detail sidebar; saved via `PUT api.php?action=media&id=`. Press Enter or click Save.
- **Resize**: Width/height inputs with aspect-ratio lock toggle. Accepts one or both dimensions; the other is calculated automatically. Max 4800px per side.
- **Crop**: Numeric X, Y, W, H inputs defining the crop region in pixels. Values are clamped to image bounds server-side.
- **Backend**: `media/transform` POST endpoint uses PHP GD to resize or crop raster images (JPEG, PNG, GIF, WebP). SVGs are excluded. Overwrites original file and regenerates thumbnail.
- **Public methods**: `OutpostMedia::getAbsolutePath()`, `getRelativePath()`, `regenerateThumbnail()` exposed as public wrappers for the transform handler.
- **API client**: `media.update(id, data)` and `media.transform(data)` added to `src/lib/api.js`.
- **UI**: Detail sidebar in MediaLibrary.svelte now shows alt text field, resize controls (with lock icon), and crop panel for raster images.

---

## Delete Page from Page Editor

Delete pages directly from the admin UI instead of requiring the code editor.

- **Delete button**: In the PageEditor sidebar (Page tab), below Visibility — small danger-colored text link
- **Confirm step**: First click shows "Delete this page and its template file?" with Delete/Cancel buttons
- **Backend**: `DELETE api.php?action=pages&id=` — removes DB row (fields cascade), `page_field_registry` entries, and the Liquid `.html` template file from the active theme
- **Homepage protection**: The `/` page (index.html) cannot be deleted — button is hidden entirely; backend also guards against it
- **Globals protection**: The `__global__` pseudo-page is guarded server-side
- **After delete**: Toast confirmation, navigates back to pages list, template cache cleared

---

## Global Content Search (⌘K Modal)

Command-palette style modal for searching across all content from anywhere in the admin.

- **Trigger**: `⌘K` (or `Ctrl+K`) from anywhere, or click the "Search... ⌘K" button in the sidebar
- **Scope**: pages, collection items, and media — searched server-side via `api.php?action=search/content`
- **Results grouped** by type: Pages → Collection Items → Media
- **Keyboard navigation**: Arrow keys move selection, Enter navigates, Escape closes
- **Debounced** 300ms — fires when query is ≥ 2 characters
- **Navigation**: Page results → `page-editor`, item results → `collection-editor`, media → `media`
- **Files changed**: `php/api.php` (route + `handle_search_content()`), `src/lib/api.js` (`search.content`), `src/lib/stores.js` (`searchOpen`), `src/components/Sidebar.svelte` (trigger button), `src/App.svelte` (keyboard handler + modal mount), `src/components/SearchModal.svelte` (new)

---

## Item Preview Before Publish

Preview draft or scheduled collection items on the live site before publishing, using secure per-item tokens.

- **Preview button**: In CollectionEditor — for published items opens URL directly; for drafts/scheduled, generates a one-time preview token and appends `?preview=TOKEN` to the URL
- **Token-authenticated routing**: Both `front-router.php` and `test-site/index.php` check `?preview=TOKEN` against the item's stored `preview_token` — bypasses the `status = 'published'` filter
- **No cache pollution**: Preview requests skip both cache read and cache write (`OUTPOST_PREVIEW_MODE` constant)
- **Preview banner**: Fixed bottom bar injected in preview mode — "Preview Mode — This page is not yet published" with a Close link
- **API endpoint**: `items/preview-token` (POST, body: `{id}`) — generates a 64-char hex token, stores on the item, returns it
- **Database**: `preview_token` column on `collection_items` (auto-migrated on first use)
- **Security**: Tokens are cryptographically random (`bin2hex(random_bytes(32))`), regenerated on each preview click, and only valid for the specific item+slug combination

---

## Media Library Search & Filter

Client-side search, type filtering, and sort controls for the media library grid.

- **Search**: Borderless text input filters items by filename in real-time (case-insensitive)
- **Type chips**: All / Images / Videos / Docs — plain text toggles filter by `mime_type` group
- **Sort dropdown**: Newest, Oldest, Name A–Z, Name Z–A, Largest, Smallest
- **Item count**: Muted 11px uppercase label shows filtered vs total count (e.g. "12 of 42 files")
- **Empty state**: "No files match your search" with a "Clear filters" link when filters produce zero results
- **Implementation**: Uses `$derived.by()` multi-step filtering — same pattern as Pages and CollectionItems
- **No backend changes**: All filtering is client-side on the already-fetched media list

---

## Bulk Operations on Collection Items

Multi-select and bulk actions for collection item lists — publish, unpublish, or delete multiple items at once.

- **Checkboxes**: Each item row has a checkbox; header row has select-all toggle
- **Bulk action bar**: Appears when items are selected — shows count, Publish / Unpublish / Delete buttons, and a clear (X) button
- **Publish/Unpublish**: Calls `items/bulk-status` PUT endpoint — updates status for all selected IDs in a single query; preserves existing `published_at` timestamps
- **Bulk delete**: Calls `items/bulk-delete` DELETE endpoint — confirmation dialog before deleting; removes items from list instantly
- **Selection UX**: Checkboxes are subtle (35% opacity) until row hover or checked; selected rows get a subtle background highlight; selection clears on collection/filter change
- **API endpoints**: `items/bulk-status` (PUT, body: `{ids, status}`) and `items/bulk-delete` (DELETE, body: `{ids}`) — both log activity with item count
- **Rate limited**: Both endpoints are mutations, so they go through the existing 60/min authenticated rate limiter

---

## Database Indexes for Hot Query Paths

Added missing indexes on frequently queried columns to improve page render and collection loop performance.

- `idx_fields_page_theme` on `fields(page_id, theme)` — hit on every page render to load field data
- `idx_collection_items_coll_status` on `collection_items(collection_id, status)` — hit on every `{% for item in collection.* %}` loop
- Added to `createSchema()` in `db.php` for fresh installs
- Added `ensure_indexes()` migration in `engine.php` called from `outpost_init()` for existing databases — uses `CREATE INDEX IF NOT EXISTS` (idempotent, metadata-only check)

---

## Email Verification on Member Registration

Optional email verification for new member registrations, gated behind an admin setting so existing sites aren't affected.

- **Admin setting**: "Require Email Verification" toggle in Settings > Members card — stored as `require_email_verification` ('0'/'1')
- **Registration flow**: When enabled, new members receive a verification email with a 24-hour token link instead of auto-login. When disabled, members auto-verify and auto-login as before.
- **Login guard**: Unverified members (with a pending token) are blocked from signing in with a "verify your email" message and a resend button. Grandfathered members (created before the feature — both fields NULL) pass through.
- **Verification page**: `member-pages/verify-email.php` — validates token, marks email as verified, clears token
- **Resend flow**: Available from the login page and via `member-api.php?action=resend-verify` POST route (60s rate limit)
- **Admin panel**: Members list shows "unverified" label next to unverified emails, Unverified stat counter, and a checkmark button (on hover) to manually verify
- **Database**: 3 new columns on `users` table — `email_verified`, `verify_token`, `verify_token_expires`
- **Email**: Uses existing `OutpostMailer::fromSettings()` pattern, same HTML styling as password reset emails

---

## API Rate Limiting — Authenticated Mutations

Added rate limiting to all authenticated admin API mutation endpoints (POST/PUT/DELETE). A stolen session cookie can no longer hammer the API indefinitely.

- **60 mutations per 60-second window** (configurable via `OUTPOST_API_RATE_LIMIT` and `OUTPOST_API_RATE_LIMIT_WINDOW` in `config.php`)
- Returns HTTP `429 Too Many Requests` with a `Retry-After` header when the limit is exceeded
- Session-based tracking — uses `$_SESSION['outpost_api_mutations']` timestamps array, same pattern as login rate limiting
- Applies to all authenticated POST/PUT/DELETE routes (item create/delete, user create/delete, collection delete, media upload, settings update, etc.)
- Login already had separate rate limiting (5 attempts/60s) — this covers the rest of the authenticated surface

---

## Template Reference — Cleanup & Dynamic Globals

Stripped dead code and hardcoded data from the Template Reference page. Net ~270 lines removed.

- **Removed Saved Loops** — dead feature (never fully wired). Deleted `loops.php`, removed API routes and client exports
- **Removed mock renderer** — regex-based renderer produced inaccurate output; removed "Rendered Output" panel entirely
- **Scratchpad → read-only Syntax Preview** — CodeMirror editor is now non-editable, shows generated example code for the selected item
- **Fixed field type detection** — `schemaTypeMap` was keyed by `field.label` but data keys use `field.name`; image fields now show as IMAGE not TEXT
- **Dynamic globals** — Globals section now pulls real fields from the `__global__` page via `content/schema` API instead of showing 6 hardcoded mock items
- **Field clicks** — now copy snippets to clipboard only (no injection into read-only editor)

---

## Developer Docs — Template Syntax Completeness Pass

Filled gaps in the template syntax reference docs so every working feature is documented.

- **`{% seo %}` tag** — added to the All Tags quick-reference table (was only on the features/seo.html detail page)
- **`item.status`** — added to All Tags output table, output-tags.html loop variables table, and collection-loops.html Available Fields table
- **`item.id`, `item.created_at`, `item.updated_at`** — added to All Tags output table (were already on detail pages)
- **`item.updated_at`** — added to output-tags.html loop variables table
- **`published_at` description** — corrected from "ISO 8601" to "formatted date" (engine outputs `date('F j, Y')`)

---

## Template Reference — Syntax Audit & Fix

Comprehensive audit of the admin Template Reference page against the actual template engine. Fixed all broken syntax, added all missing features.

### Bug fixes (broken syntax that produced wrong output)
- **Page field syntax** — reference generated `{{ page.home.field_name }}` which doesn't exist in the engine; fixed to `{{ field_name }}` (page fields are scoped automatically)
- **Folder loop syntax** — reference generated `{% for term in item.terms.slug %}` which doesn't compile (three-segment path); fixed to `{% for label in folder.slug %}`
- **Image field access** — reference generated `{{ item.featured_image.url }}` but collection images are plain URL strings; fixed to `{{ item.featured_image }}`
- **Toggle description** — described as "Toggle (boolean)" implying output; clarified to "registers field type; use {% if %} for logic"
- **Conditional description** — `{% if field_name %}` described as "True when a toggle field is on"; fixed to "True when any field has a non-empty value"
- **Mock renderer** — updated folder loop regex from `item.terms.slug` to `folder.slug`; added page field resolution for bare field names

### Missing features added to syntax reference API
- **Folder Loops** — `{% for label in folder.slug %}` with `label.name`, `label.slug`, `label.count`
- **Menu Loops** — `{% for item in menu.slug %}` with `item.label`, `item.url`, `item.target`, nested `{% for child in item.children %}`
- **Gallery Loops** — `{% for img in gallery.field_name %}` with `img.url`
- **Repeater Loops** — `{% for row in repeater.field_name %}` with `row.sub_field`
- **Collection options** — `orderby:field`, `paginate:N`, `filteredby:param`, `related:var`
- **Pagination tag** — `{% pagination %}` after paginate loops
- **Admin conditional** — `{% if admin %}` for admin-only content
- **or_body filter** — `{{ field_name | or_body }}` for excerpt-with-body-fallback

### Files changed
- `src/pages/TemplateReference.svelte` — fixed page/folder/image snippet generation, mock renderer
- `php/content-api.php` — expanded `handle_content_syntax()` from 6 to 9 groups with all missing tags

---

## Personal Blog Theme — Design Fixes & Convention Polish (v2.1)

A round of targeted fixes and polish to the personal blog theme following the v2.0 audit rewrite.

### Bug fixes
- **Global field conditional mismatch** — `{% if bio_short %}` (page field) was checking the wrong scope while `{{ @bio_short }}` (global) was outputting correctly; fixed both to `{% if @bio_short %}`
- **Folder slug mismatch** — `folder.category` (singular) did not match the actual DB slug `categories` (plural); fixed in `blog.html` and `post.html` sidebar; categories now appear correctly
- **Blog card structure broken** — previous audit agent had replaced the card structure with different CSS classes (`article-card-image`, `article-card-body`, `article-card-meta`); restored to match homepage card layout
- **View All Posts arrow** — `.view-all-link` CSS was missing entirely; SVG rendered at full browser default size; fixed with `inline-flex` layout and `width/height: 14px` on the SVG

### Design corrections
- **Pill nav centering** — `.nav-actions` changed from `flex: 1` to `position: absolute; right: 0` (mirroring `.nav-brand { position: absolute; left: 0 }`) so the pill nav truly centres
- **Nav brand** — removed text fallback; brand link only renders when `{% if @site_logo %}` — no `.nav-brand` element at all when no logo is set
- **Footer simplified** — two-column layout: nav links (left) + copyright text (right); brand and social link sections removed from footer
- **Container width** — `--container-max` changed from `1280px` to `1216px`
- **Admin edit bars removed** — all `{% if admin %}` edit-bar blocks removed from `index.html`, `post.html`, `about.html`; the CMS already provides inline edit affordances

### New features
- **Work/Resume sidebar** — homepage right sidebar now includes a Work card using `{% for job in collection.work limit:4 orderby:start_date %}` with company initial avatar, role, dates, and an optional CV download link (`{% if @cv_url %}`)
- **Repeater demo** — `about.html` skills section replaced `| textarea` field with `{% for item in repeater.skills %}` loop demonstrating the repeater field type (`item.skill`, `item.level`)
- **Front matter convention** — all 9 theme templates now open with a `{#--- ... ---#}` YAML-style front matter block documenting: Template, Route, Title, Page fields, Global fields, Loops, and Features demonstrated. Stripped at compile time — zero runtime cost.
- **Front matter docs** — "Front Matter Convention" section added to `php/docs/template-syntax/includes-comments.html` with annotated example, rationale, and fields reference table

### CSS additions (`assets/style.css`)
- `.work-card`, `.work-card-header`, `.work-card-icon` — card layout and heading row
- `.work-avatar`, `.work-avatar-letter` — company initial avatar (uses `font-size:0` on parent + `::first-letter` to extract first character)
- `.work-item`, `.work-item-info`, `.work-company`, `.work-role`, `.work-dates` — work history row layout
- `.btn-cv` — ghost download button for CV link
- `.view-all-link` — inline-flex link with right-arrow SVG clamped to 14×14px
- `.skills-list`, `.skill-row`, `.skill-name`, `.skill-level` — repeater skills table layout

---

## Personal Blog Theme — Reference Implementation Audit (v2.0)

Complete rewrite of `php/themes/personal/` to serve as a perfect reference implementation demonstrating all Outpost template features.

### New files
- `404.html` — custom 404 error page with recent posts fallback

### Updated files
- `partials/head.html` — added GA4 conditional block, OG image from `@site_logo`, Twitter Card tags, proper `@site_name` default in meta.title
- `partials/nav.html` — added site logo/name brand link (`@site_logo | image`, `@site_name`), full dropdown support (`{% if item.children %}{% for child in item.children %}`), accessible ARIA attributes, mobile close button, mobile subnav for children
- `partials/footer.html` — replaced hardcoded links with `{% for item in menu.footer %}` loop; added social link conditionals for all global fields (`@twitter_url`, `@github_url`, `@linkedin_url`, `@instagram_url`, `@contact_email`); added `@site_logo`, `@site_tagline` globals
- `index.html` — fixed social links to use globals (`@twitter_url | link`), fixed bio to use `@bio_short` global, added folder loop sidebar (`{% for label in folder.category %}`), added `article-card-image` conditional, full `{# comments #}` throughout
- `blog.html` — corrected folder slug to `folder.category`, added `label.count` badges, improved comments
- `post.html` — added `post.slug`, `post.id`, `post.created_at` usage; added `{% if admin %}` edit link with item ID; fixed `post.featured_image` display; added sidebar author bio from globals; full comments throughout
- `about.html` — added skills textarea field, social links section with global conditionals, `{% if admin %}` hint, full comments
- `assets/style.css` — added ~600 lines of new component CSS: nav brand/dropdown, buttons (`.btn`, `.btn-primary`, `.btn-secondary`), admin bar, 404 page, updated footer layout, sidebar author/categories/bio, article card image, empty state, not-found block, mobile nav overlay inner layout
- `assets/main.js` — added mobile close button handler, `aria-hidden` management, dropdown keyboard toggle for mobile widths

### Template features now demonstrated
All 22 required syntax features confirmed present: includes, basic output, `| raw`, `| image`, `| link`, global fields, global image, meta.title, meta.description, limit loop, paginated loop + `{% pagination %}`, filteredby loop, single item with else fallback, taxonomy loop, menu loop with dropdowns, item.children dropdown, field conditionals, global conditionals, `{% if admin %}`, `| or_body`, `{# comments #}`, and all standard item fields (`url`, `title`, `slug`, `id`, `published_at`, `created_at`).

---

## SEO Score Panel

Rule-based SEO analysis in the sidebar SEO tab for Pages and all Collection editors (posts, services, etc.).

- **Score gauge** — SVG semicircle arc showing 0–100, color-coded: Excellent (green), Good (amber), Needs Work (clay), Poor (red)
- **Word count** — displayed alongside the gauge for collection items with body content
- **Focus keyword** — user types a keyword; checks against title, meta description, URL slug, and body content in real-time
- **Grouped checks** with pass/fail dot indicators:
  - Title: meta title set, length ≤ 60, keyword in title, keyword at start
  - Meta: description set, 50–160 chars, keyword in description
  - URL: keyword in slug (keyword-dependent)
  - Content: word count ≥ 300, keyword in first paragraph, keyword in body, featured image set (collection items only)
- **Zero AI** — all checks are client-side string comparisons and length checks
- Keyword-dependent checks only appear when a focus keyword is typed
- Content checks only appear when the item has a body (collections); pages show Title + Meta checks only
- Component: `src/components/SeoScore.svelte` — reusable, drop into any editor sidebar

---

## Code Editor — Full IDE Upgrade

The Code Editor is now a VS Code–style IDE with multi-tab editing, file management, smart autocomplete, find in files, a command palette, and a status bar.

### Multi-Tab Editing
- Tab bar above the editor: open multiple files simultaneously as tabs
- Dirty dot (•) marks unsaved changes; × closes a tab
- Tab switching preserves cursor position, scroll, and full undo history (via `EditorState` save/restore)
- **⌘W** close active tab · **⌘⇧]** / **⌘⇧[** cycle tabs
- Auto-closes the oldest clean tab when 20 tabs are open

### File Management
- **Hover actions** on every tree item: `+file`, `+folder`, rename, delete icons appear on hover
- **Right-click context menu**: New File, New Folder, Rename, Delete, Copy Path
- **Inline input**: create/rename in place in the tree; Enter commits, Esc cancels
- **Delete confirm modal** with file path and "cannot be undone" warning
- New PHP endpoints: `code/create`, `code/rename`, `code/delete`

### Autocomplete
- HTML tag/attribute completions (via `@codemirror/lang-html` built-in)
- CSS property/value completions (via `@codemirror/lang-css` built-in)
- **Outpost Liquid completions** in `.html` files: `{{ @global }}`, `{% for %}`, `{% if %}`, `{% single %}`, `{% include %}`, `{{ item.field }}`, `{{ meta.title }}`, filter variants (`| raw`, `| image`, etc.)
- Dynamic suggestions loaded from `code/context` API (globals, collections, fields)

### Find in File (⌘F)
- Opens CodeMirror's built-in search panel (regex, case-sensitive, whole-word)
- Highlights all matches; Enter/Shift+Enter cycles results

### Find in Files (⌘⇧F)
- Bottom drawer panel with full-text search across all theme files
- Results grouped by file with line numbers and preview; click to open at line
- PHP endpoint: `code/search` (reads all files, max 200 results)

### Command Palette (⌘P)
- Fuzzy-search all files in the tree by path
- Arrow keys to navigate; Enter opens; Esc dismisses
- File type badge next to each result

### Status Bar
- Always-visible bottom bar: `Ln X, Col Y` (live cursor position) · file path · language · file size · encoding

---

## Page Editor — Two-Column UI Redesign

Complete redesign of the Page Editor (`PageEditor.svelte`) with a Ghost/Linear-inspired two-column layout:

- **Left column** (flex: 1, independent scroll): back button, page title (Fraunces 32px, borderless editable input), path, content fields via FieldRenderer, Cmd+S keyboard hint
- **Right sidebar** (320px, independent scroll): three sections — Status & Actions (status dropdown + Save button + unsaved indicator), SEO (collapsible, Meta Title + Meta Description with character counts + Google search preview), Visibility (radio group: Public / Members only / Paid members)
- `meta_title` field type filtered from left column — no longer shown twice
- Global RightSidebar hidden for `page-editor` route; PageEditor manages its own sidebar
- Full dark mode and light mode support via design tokens only
- Uses `position: absolute; inset: 0` to fill `app-content` with zero padding (`editor-active` class)

---

## Per-Item SEO Fields

Collection items now have dedicated **Meta Title** and **Meta Description** fields accessible via a tabbed sidebar in the item editor.

### Admin UI
- Right sidebar has **Post** / **SEO** tab switcher when editing a collection item
- **Post tab**: all existing fields (status, slug, excerpt, featured image, date, folders, details, delete)
- **SEO tab**: meta title input with 60-char counter, meta description textarea with 160-char counter, live Google search preview
- Character counts turn warning color when exceeding recommended limits
- SEO fields stored in the item's JSON `data` column (no database migration needed)

### Theme Integration

No extra template work is needed if your theme already uses `{{ meta.title }}` and `{{ meta.description }}` in its `<head>`. On collection single pages (e.g. `/post/my-slug`), these tags automatically pull from the item's SEO fields.

**Fallback chain for `{{ meta.title "Default" }}`:**
1. Item's `meta_title` (set in the SEO tab)
2. Item's `title`
3. The default string you provide in the template

**Fallback chain for `{{ meta.description "Default" }}`:**
1. Item's `meta_description` (set in the SEO tab)
2. Item's `excerpt`
3. Auto-excerpt from the item's `body` (first ~30 words, HTML stripped)
4. The default string you provide in the template

**Minimal theme setup** — add this to your `partials/head.html`:

```html
<title>{{ meta.title "My Site" }}</title>
<meta name="description" content="{{ meta.description "" }}">

<!-- Open Graph (optional but recommended) -->
<meta property="og:title"       content="{{ meta.title "My Site" }}">
<meta property="og:description" content="{{ meta.description "" }}">
<meta property="og:type"        content="website">
```

This works for both regular pages and collection single pages — the engine detects which context it's in and pulls from the right source.

**How it works under the hood**: When a visitor hits a collection URL (e.g. `/post/hello-world`), the front-router pre-loads the item data before template rendering begins. This means `{{ meta.title }}` in the `<head>` partial has access to the item's SEO fields even though the `{% single %}` block hasn't been processed yet.

### Personal Theme

The `personal` theme's `partials/head.html` already includes `{{ meta.title "My Blog" }}` and `{{ meta.description "" }}` with OG tags — no changes needed. All blog posts will use per-item SEO fields automatically.

### Files Changed
- `src/components/RightSidebar.svelte` — tab switcher + SEO panel
- `src/pages/CollectionEditor.svelte` — `buildData()` merges `meta_title` / `meta_description` from store
- `php/engine.php` — `cms_meta_title()` / `cms_meta_description()` check `$_outpost_current_item`
- `php/front-router.php` + `test-site/index.php` — pre-load item data on collection URL match

---

## Automatic XML Sitemap

Outpost now generates `/sitemap.xml` automatically from the database — no configuration needed.

- Lists all CMS pages (excluding internal `__global__` and `/outpost/` admin paths)
- Lists all published collection items with correct URLs built from each collection's `url_pattern`
- Includes `<lastmod>` dates from the database `updated_at` timestamps
- Auto-detects base URL from the HTTP request (works on localhost, production, and Builder)
- Properly escapes XML entities in URLs
- Handled in both `front-router.php` (dev server) and `index.php` (Apache/Nginx)
- Generation logic in `php/sitemap.php`

---

## Layout Token System

Added layout tokens to the Trailhead design system for consistent page widths:

- `--content-width: 900px` — standard list/settings pages (Posts, Pages, Globals, Navigation, Taxonomies)
- `--content-width-wide: 1100px` — dashboards, grids, media (Dashboard, Analytics, Media, Themes)
- `--content-width-narrow: 680px` — editors, forms, single-item views (PageEditor, CollectionEditor, Import, Settings, UserProfile)
- `--page-padding: 40px 48px 80px` — standard page padding

All admin pages now reference these tokens instead of hardcoded pixel values. Changing a token in `:root` updates every page at once.

Also standardized page headers across all pages to use the shared `.page-header` component (icon + serif title + subtitle + actions) from admin.css, and replaced one-off save buttons with the standard `btn btn-primary` class.

---

## ⚠ Architecture — How Themes Work (Read Before Building)

Outpost uses a **theme-based architecture**. All front-end pages are rendered through a theme's Liquid `.html` template files. This is the only correct approach. Do not put `cms_text()` PHP files in the website root — that is an old, deprecated flat-file pattern that is incompatible with the theme system.

### Correct Site Structure

```
your-site/
  outpost/                      ← entire CMS lives here, untouched by theme work
    themes/
      my-theme/
        index.html              ← homepage  (/ route)
        about.html              ← /about
        blog.html               ← /blog listing
        post.html               ← /posts/{slug}  collection single
        partials/
          head.html
          nav.html
          footer.html
        assets/
          style.css
        theme.json
    front-router.php            ← routes every request to the template engine
```

The website root has **no PHP page files of its own**. `front-router.php` intercepts all requests and routes them to the correct template via `OutpostTemplate::render()`.

### What Claude Code Builds

The `.html` files inside `outpost/themes/[name]/`. These are plain text files using Liquid syntax — readable and editable like any other file.

**Editable content fields** — client fills values through the Outpost admin:
```html
<h1>{{ hero_title "Welcome" }}</h1>
<p>{{ intro | textarea }}</p>
<div>{{ body | raw }}</div>
<img src="{{ hero_image | image }}" alt="">
```

**Collection loops** — dynamic, pulls all published items automatically:
```html
{% for post in collection.posts %}
  <h2>{{ post.title }}</h2>
  <p>{{ post.excerpt }}</p>
  <a href="{{ post.url }}">Read more</a>
{% endfor %}
```

**Conditionals and globals**:
```html
{% if show_banner %}
  <div class="banner">{{ banner_text }}</div>
{% endif %}

<title>{{ @site_name }}</title>
```

**Partials**:
```html
{% include 'head' %}
{% include 'nav' %}
{% include 'footer' %}
```

### Division of Responsibility

| | |
|---|---|
| **Claude Code** | Builds `.html` template files — HTML structure, layout, which fields exist |
| **Client (Outpost admin)** | Fills in content values — text, images, rich text, toggles |
| **Collections** | Stores repeating dynamic items (posts, products) — templates loop over them automatically |

The client never touches code. Claude Code never touches content values. Fields register themselves the first time a page is visited.

### How Routing Works

`front-router.php` intercepts every request to the site:
- `/outpost/` paths → admin panel, served directly
- Static files (css, js, images, fonts) → served directly
- Everything else → `OutpostTemplate::render()` maps the URL path to a template file

URL-to-template mapping (handled by the template engine):
- `/` → `index.html`
- `/about` → `about.html`
- `/blog` → `blog.html`
- `/posts/my-post-slug` → `post.html` with the matching collection item in scope

### Template Syntax Quick Reference

#### Field Output

| Syntax | What it does |
|---|---|
| `{{ field_name }}` | Editable text field (auto-escaped) |
| `{{ field_name "Default" }}` | Text field with default value |
| `{{ field_name \| raw }}` | Rich text — outputs HTML as-is |
| `{{ field_name \| raw "Default" }}` | Rich text with default |
| `{{ field_name \| textarea }}` | Multi-line text (auto-escaped + nl2br) |
| `{{ field_name \| image }}` | Image URL field |
| `{{ field_name \| link }}` | Link URL field |
| `{{ field_name \| color }}` | Color picker field |
| `{{ field_name \| number }}` | Numeric field |
| `{{ field_name \| date }}` | Date field |
| `{{ field_name \| select }}` | Select/dropdown field |
| `{{ field_name \| filter "Default" }}` | Any filter above accepts an optional default |

#### Global Fields (site-wide)

| Syntax | What it does |
|---|---|
| `{{ @global_name }}` | Site-wide global text field |
| `{{ @global_name \| raw }}` | Site-wide global rich text |
| `{{ @global_name \| image }}` | Site-wide global image URL |
| `{{ @global_name \| link }}` | Site-wide global link |

#### Meta Tags

| Syntax | What it does |
|---|---|
| `{{ meta.title "Default" }}` | Page `<title>` — reads from CMS settings, fallback to field |
| `{{ meta.description "Default" }}` | Page meta description |

#### Collection Loops

| Syntax | What it does |
|---|---|
| `{% for item in collection.slug %}` | Loop over all published items in a collection |
| `{% for item in collection.slug limit:5 %}` | Loop with item limit |
| `{% endfor %}` | End loop |
| `{% for item in collection.slug %}...{% else %}...{% endfor %}` | Empty-state fallback |
| `{{ item.field }}` | Collection item field (auto-escaped) |
| `{{ item.field \| raw }}` | Collection item rich text |
| `{{ item.url }}` | Item URL built from collection's `url_pattern` |
| `{{ item.published_at }}` | Publish date formatted as `April 7, 2026` |
| `{{ item.slug }}` | Item slug |
| `{{ item.status }}` | Item status (`published`) |

#### Gallery & Repeater Loops

| Syntax | What it does |
|---|---|
| `{% for item in gallery.field_name %}` | Loop over gallery images; each `item` has `{{ item.url }}` |
| `{% for item in repeater.field_name key:type,... %}` | Loop over repeater rows; schema defined inline (`url:image,caption:text`); each `item` has its own fields |

#### Navigation / Menu Loops

| Syntax | What it does |
|---|---|
| `{% for item in menu.slug %}` | Loop over items in a named menu (e.g. `menu.main`, `menu.footer`); each `item` has `label`, `url`, `target` |
| `{% for child in item.children %}` | Loop over sub-items nested under a parent menu item; same fields as parent |
| `{% if item.children %}` | Check whether a menu item has sub-items |

Template example:
```html
<nav>
  {% for item in menu.main %}
    <a href="{{ item.url }}" target="{{ item.target }}">{{ item.label }}</a>
    {% if item.children %}
    <ul>
      {% for child in item.children %}
        <li><a href="{{ child.url }}" target="{{ child.target }}">{{ child.label }}</a></li>
      {% endfor %}
    </ul>
    {% endif %}
  {% endfor %}
</nav>
```

#### Single Item

| Syntax | What it does |
|---|---|
| `{% single var from collection.slug %}` | Fetch one item matching `?slug=` in the URL |
| `{% endsingle %}` | End single block |
| `{% single var from collection.slug %}...{% else %}...{% endsingle %}` | With not-found fallback |

#### Conditionals

| Syntax | What it does |
|---|---|
| `{% if field_name %}` | True if field is non-empty (works for any field type) |
| `{% if @global_name %}` | True if global field is non-empty |
| `{% if item.field %}` | True if loop/single variable field is non-empty |
| `{% if item.field == "value" %}` | Strict string equality |
| `{% if item.field != "value" %}` | String inequality |
| `{% else %}` | Else branch (works with all `{% if %}` and `{% single %}`) |
| `{% endif %}` | End conditional |

#### Partials & Comments

| Syntax | What it does |
|---|---|
| `{% include 'partial-name' %}` | Render `partials/partial-name.html` |
| `{# comment #}` | Template comment — stripped at compile time |

#### Standard Partial Names (by convention)

| File | Purpose |
|---|---|
| `partials/head.html` | DOCTYPE through `<body>` — meta, CSS links |
| `partials/nav.html` | Site navigation |
| `partials/footer.html` | Footer content, closing `</body></html>` |

For the full interactive syntax reference with live data shapes, open **Template Reference** in the Outpost admin (developer role required).

### What NOT to Do

- **Do not** create PHP files with `cms_text()`, `cms_richtext()` etc. at the site root or anywhere outside `outpost/` — that is the old flat-file approach
- **Do not** create a page-specific PHP file alongside theme templates — all routing is handled by `front-router.php`
- **Do not** hardcode content strings in templates — use `{{ field "Default" }}` so everything stays editable

---

## Core Engine
- **Page auto-discovery**: PHP engine crawls site pages, discovers CMS tag functions, registers fields automatically
- **Tag functions**: `cms_text()`, `cms_richtext()`, `cms_image()`, `cms_textarea()`, `cms_toggle()`, `cms_select()`, `cms_color()`, `cms_link()`, `cms_number()`, `cms_date()`, `cms_repeater_items()`, `cms_gallery_items()`, `cms_field_truthy()`, `cms_menu_items()` — all called internally by the template engine; use Liquid syntax in templates instead of calling these directly
- **Page caching**: Optional output cache with cache-busting on content save
- **Session auth + CSRF**: Secure admin API with session-based authentication and CSRF token validation

## Admin Panel (Svelte 5 SPA)
- **Dashboard**: Ghost/Linear visual hierarchy — hero stat (56px Fraunces 700, raw typography, no card) shows total members if any exist, else total collection items; inline trend badge at baseline; Chart.js growth chart directly below hero in same visual zone (no border, Y-axis guide lines only); 3fr/2fr split below with Recent Content table (left) and secondary stat rows (right, `border-left` divider); activity feed with border-top separator; generous 32px gaps; no card borders anywhere; dark mode via CSS vars; `no-right-sidebar` layout
- **Pages**: List view with search, status badges; Ghost-style inline editor with serif title, borderless field inputs, collapsible SEO section with Google search preview, Cmd+S save
- **Globals**: Dedicated admin page for site-wide `@field` values (profile photo, social links, site name, etc.) — separate from page fields; Cmd+S save; empty state explains `{{ @field_name }}` syntax
- **Navigation**: Menu manager — create multiple named menus (main, footer, etc.); per-item label + URL + external link toggle; nested sub-items (one level); page picker popover to select from known pages; Cmd+S save; slug badge shown for template reference
- **Media Library**: Grid view, drag-and-drop upload, file detail sidebar, search/filter, delete; supported formats: jpg, png, gif, webp, avif, svg, pdf, mp4, webm
- **Dark mode**: Toggle in sidebar footer, persists to localStorage

## Collections (Custom Post Types)
- **Collection CRUD**: Create/edit/delete collections with name, slug, description
- **Schema builder**: Define typed fields per collection (text, textarea, richtext, image, number, toggle, select, color, link, date, repeater, gallery) with labels, placeholders, defaults, required flag
- **Collection items**: List view with status filters (all/draft/published/scheduled), search, bulk actions
- **Delete items**: Trash icon on each list row (hover-reveal) and in the editor top bar — confirmation dialog, then deletes and returns to the list
- **Block editor**: Ghost-style content editor with inline title, "+" button to add blocks (Text, Image, Markdown, HTML, Divider), TipTap richtext, drag-reorder via SortableJS
- **Item metadata**: Right sidebar with status, slug editing, excerpt, featured image, folder label assignment, scheduling
- **Scheduling**: Set future publish date; items auto-publish when their scheduled time passes. Auto-cron runs on every front-end page load (throttled to once per 60 seconds via file lock) — no server crontab required. Also fires on dashboard load, collection list loads, and collection page views in templates. A manual cron endpoint (`api.php?action=cron&key=SECRET`) is still available for advanced use.
- **Status workflow**: Draft → Published / Scheduled → Published

## Folders (formerly Taxonomies)
- **Folder definitions (ACF-like)**: Top-level management page to create/edit/delete folders with name, singular name, slug, type (flat/hierarchical), description, collection assignment
- **Custom label fields**: Schema builder on folders — define extra fields (text, textarea, richtext, image, number, toggle, select, color, link) that appear on label create/edit forms
- **Label management (WordPress-style)**: Per-folder page with split layout — create form on left, label list on right; supports hierarchical parent selection
- **Label editing**: Dedicated edit page per label with all custom fields from folder schema
- **Label assignment**: Assign labels to collection items via right sidebar checkboxes
- **Sidebar integration**: Folder names appear under their collection in sidebar navigation

## Roles & Permissions
- **6 roles**: super_admin, admin, developer, editor, free_member, paid_member
- **Capability-based authorization**: Permission map per role (e.g. `settings.*`, `users.*`, `code.*`, `cache.*`, `members.*`)
- **Permission pre-flight**: API checks capabilities before routing — developers can't access settings/users, editors can't access code/settings/users
- **Internal vs external roles**: Admin panel restricted to internal roles (super_admin, admin, developer, editor); member roles (free_member, paid_member) blocked from admin login and API
- **Role enforcement on user CRUD**: Only admin+ can create/delete users or change roles; only admin+ can assign admin/developer roles
- **Frontend role gates**: Sidebar nav items conditionally shown by role; route guards show AccessDenied page for unauthorized access
- **Role badge**: Displayed next to username in top bar

## Users
- **User list**: Avatar initials, username, email, role, last login display
- **Invite system**: Modal to create new users with username, email, role (all 6 roles in grouped optgroups: Internal/External)
- **User profiles**: Edit own profile (username, display name, email, bio, avatar, password change)
- **Admin gates**: Invite and delete buttons only visible to admin+

## Template Reference (Developer Loop Builder)
- **3-column layout**: 220px navigator + 300px fields panel + flex content area (full width, no right sidebar via `no-right-sidebar` layout class)
- **Content types**: Collections, Pages, Globals, Syntax — selected from left nav with back navigation
- **CodeMirror 6 editor**: Liquid-syntax-highlighted loop builder in dark panel with custom StreamLanguage tokenizer; cursor auto-placed inside loop on load/reset
- **Rendered Output panel**: Client-side mock render of the loop template against real Data Shape — substitutes `{{ tokens }}`, executes nested folder `{% for label %}` loops with real label objects, strips comments/conditionals, syntax-highlights resolved vs unresolved tokens
- **Data Shape panel**: Live JSON from Content API (`content/items?limit=1` or `content/pages?path=X`) showing exact data the template receives; `url` computed client-side from `url_pattern` since API doesn't return it
- **Field injection**: Click any field/built-in/folder row in Column 2 to inject the snippet at the editor cursor and copy to clipboard simultaneously
- **Data-driven Column 2**: All field rows derived entirely from the loaded `dataShape` JSON — no hardcoded lists. `col2Fields` from `dataShape.fields`, `col2BuiltIns` from top-level keys (excluding `fields`/`labels`), `col2PageBuiltIns` from top-level page keys, folder label props from `Object.keys(dataShape.labels[slug][0])`. Falls back to schema data before dataShape loads
- **Folder loop injection**: Clicking a folder row injects the `{% for label %}...{% endfor %}` shell with cursor placed INSIDE, ready for label property clicks
- **Label property rows**: Indented sub-rows under each folder showing all keys on the actual label object (`id`, `name`, `slug`, `parent_id`, any extras) — dynamically derived from real API data
- **Content API labels**: Returns full label objects `{id, name, slug, parent_id}` per folder — not just slugs — so mockRender can execute loops with real data
- **Reset button**: Restores the outer `{% for item in collection.X %}` wrapper with a blank line inside (cursor ready), clearing inner content only
- **Saved Loops**: Database-backed loop storage (`saved_loops` SQLite table); max 20, oldest auto-deleted; accessible via API endpoints `loops` (GET/POST/DELETE); shown in Column 1 below navigation; gated by `code.*` capability
- **Saved Loops API**: `php/loops.php` — `ensure_loops_table()`, `handle_loops_list()`, `handle_loops_save()`, `handle_loops_delete()`; routes in `api.php`; client methods in `api.js loops.list/save/delete`
- **Syntax cheat sheet**: Backend-driven tag reference fetched from `content/syntax` — add new template engine features in `content-api.php → handle_content_syntax()` without a rebuild
- **Dark mode**: All colors use CSS design system variables (`--bg-primary`, `--bg-hover`, `--bg-active`, `--bg-tertiary`) — auto-adapts to `:root.dark` class
- **Permission gated**: Requires `code.*` capability (admin and developer roles only)

## UI/Layout Patterns
- **`no-right-sidebar` layout class**: Adding this class to `.app-layout` collapses the right column to 0 and hides `.right-sidebar` — used for Template Reference and Code Editor routes where content needs full width

## Code Editor (Shopify-style)
- **File tree sidebar**: 250px collapsible directory tree scoped to `outpost/themes/`
- **CodeMirror 6**: Syntax highlighting for PHP, HTML, CSS, JS, JSON with dark/light theme matching CMS
- **File operations**: Browse, read, and write theme files with path traversal prevention (realpath checks)
- **Developer features**: Cmd+S save, dirty indicator (amber dot), file path breadcrumb, extension-based file icons
- **Security**: Extension whitelist (php, html, css, js, json, xml, svg, txt, md, yml, yaml), 1MB size cap, `..` rejection, LOCK_EX writes
- **Cache integration**: Clears page cache after file saves
- **Permission gated**: Requires `code.*` capability (admin and developer only)

## Member System (Front-end)
- **Separate session**: `outpost_member` cookie, 30-day lifetime, SameSite=Lax — independent from admin session
- **Registration**: Creates free_member account, validates uniqueness, auto-login after registration
- **Login**: By username or email, rate limited (5 attempts/60s), checks suspended status
- **Member API**: Separate entry point at `member-api.php` — register, login, logout, me, profile update, password change
- **Front-end pages**: Minimal styled login, register, profile, and logout templates in `member-pages/`
- **Template helpers**: `cms_is_member()`, `cms_is_paid_member()`, `cms_require_member()`, `cms_require_paid_member()`, `cms_current_member()` — called in PHP theme templates to gate content
- **Admin member management**: Members page with list of free/paid users, role change (free/paid), suspend/activate, delete
- **DB columns**: member_since, member_expires, member_status added via migration

## Local Development (Outpost Builder)
- **Front router** (`php/front-router.php`): PHP built-in server router script — serves static assets directly, passes `/outpost/` paths through, routes all other requests to `OutpostTemplate::render()` with the active theme; enables clean URLs (`/about`, `/blog`, `/posts/slug`) locally without Apache/Nginx
- **Theme path fix**: Builder stores pulled theme files under `filesDir/outpost/themes/` (matching `OUTPOST_THEMES_DIR`) — previously incorrectly placed under `filesDir/themes/`
- **Router script wiring**: `PhpServer.js` passes `outpost/front-router.php` as the 4th arg to `php -S` when the file exists, activating clean URL routing for all local sites

## Theme System (Shopify-style)
- **Theme directory**: Themes stored as folders inside `outpost/themes/`, each with a `theme.json` manifest (name, version, author, description, screenshot)
- **Active theme**: One published theme at a time, stored in settings table — instant swap with cache clear
- **Theme management API**: List, get details, activate, duplicate, delete — all gated by `settings.*` capability
- **Duplicate themes**: Recursive copy with updated manifest (new name, version reset to 0.0.1)
- **Delete protection**: Cannot delete the active theme; path validation prevents traversal attacks
- **Admin UI (Themes page)**: Shopify-style layout — live theme shown prominently at top, draft themes in responsive grid below with publish/duplicate/delete actions
- **Starter theme**: Included default theme with Liquid-style `{{ }}` template syntax — index, about, blog listing, blog single, contact pages + header/footer partials + CSS
- **Sidebar integration**: Themes nav item visible to admin+ roles, uses layers icon

## Personal Blog Theme (`themes/personal/`)

Full personal blog theme built entirely with Liquid `.html` templates — the correct approach, zero PHP function calls in theme files.

- **Pages**: `index.html` (home + recent posts), `blog.html` (full listing), `post.html` (collection single item), `about.html`, `contact.html`
- **Partials**: `head.html` (DOCTYPE → `<body>`), `nav.html`, `footer.html` (`</body></html>`)
- **Collection**: `post` (singular slug) with fields `title`, `excerpt`, `featured_image`, `body` (richtext), `author`; URL pattern `/post/{slug}`; template `post.html` uses `{% single post from collection.post %}`
- **Global fields**: `site_name`, `author_name`, `site_tagline`, `profile_photo`, `bio_short`, `twitter_url`, `github_url`, `linkedin_url`, `contact_email`, `ga4_id`, `footer_text`
- **GA4 analytics**: `{% if @ga4_id %}` block in `head.html` conditionally outputs the GA4 script — only appears when the global is set
- **Outpost analytics**: Privacy-first tracker auto-injected before `</body>` by the template engine's output buffer
- **Design (Spotlight-inspired)**: Zinc palette (`--zinc-50` → `--zinc-950`) + teal accent (`#14B8A6`); Fraunces 700 display headings + Inter body; `site-outer` (1280px max-width) + `site-surface` (white, 1px shadow) wrapper pattern; dark mode via `data-theme="dark"` attribute (not media query) persisted to localStorage — prevents flash with inline `<script>` in `<head>` before paint
- **Frosted glass nav**: Fixed `.nav-pill` with `backdrop-filter: blur(20px)` + semi-transparent background; contains 4 page links + dark mode toggle (sun/moon SVG swap) + hamburger for mobile; `.mobile-overlay` full-screen menu
- **Photo gallery bleed**: `.photo-gallery` spans `100vw` with `margin-left: calc(50% - 50vw)`; 5 `.photo-card` elements at `299px` each (total 1575px) intentionally overflow the viewport and are clipped by `overflow: hidden` — creates edge-to-edge visual bleed
- **Fade animations**: IntersectionObserver in `main.js` adds `.visible` class when `.fade-up` elements enter viewport; CSS `opacity` + `transform: translateY()` transition
- **`main.js`**: New standalone JS file loaded before `</body>` — dark mode toggle, hamburger/overlay menu, fade-up IntersectionObserver; no framework dependency

## Analytics

### Privacy-First Tracking Layer
- **Pageview tracker** (`php/track.php`): Lightweight GET endpoint returning a 1×1 GIF; accepts `path`, `ref`, `w` params; respects `DNT: 1` header; bot detection via 20+ UA pattern signatures; device detection (mobile/tablet/desktop); anonymous daily session hash `sha256(ip + ua + date + salt)` — raw IPs never stored
- **`analytics_hits` table**: Auto-created via `ensure_analytics_tables()` — stores `path`, `referrer`, `referrer_domain`, `user_agent`, `device_type`, `session_id`, `is_bot`, `created_at`; indexes on path, created_at, session_id
- **Rate limiting**: `tracker_rate_limits` table; 10 hits/IP/60s window; IP stored as SHA-256 hash only
- **Analytics salt**: Auto-generated `random_bytes(16)` salt stored in settings table — makes session hashes unlinkable across sites
- **Data retention**: 1% chance per hit to prune `analytics_hits` older than 13 months and stale rate limit rows
- **JS tracker snippet**: Injected before `</body>` by template engine via `ob_start()`/`ob_get_clean()` output buffering + `str_ireplace`; respects DNT client-side too; uses `sendBeacon` with `fetch` fallback; path built from `getTrackerSnippet()` method
- **Bug fix**: `track.php` now accepts both GET and POST — `navigator.sendBeacon` sends POST by default; previously all beacon hits were silently dropped (204) causing zero analytics data

### Analytics Page (CMS Admin)
- **Traffic Overview**: 4 hero stats (pageviews, unique visitors, avg session duration, bounce rate) with period-over-period trend badges; Chart.js line chart with toggleable series (pageviews/visitors) via legend chips; period selector (7d / 30d / 90d / 12mo)
- **Traffic Sources & Devices**: 3fr/2fr split — referrers table with favicon icons (google.com/favicon.ico pattern) and hit counts; device breakdown bar chart (desktop/tablet/mobile)
- **Top Pages**: Sortable table (by views/uniques/bounces/duration) with client-side pagination; path + view count + unique visitors + avg duration + bounce rate per row
- **SEO Health**: PHP-side scan of pages + fields + collection_items tables; score (0–100, deducted per issue type); grouped into Critical / Warnings / Passing with expand/collapse; checks include: missing meta description, missing OG image, duplicate descriptions, title too long/short, empty content, missing alt text
- **Content Performance** (if collections exist): Weekly publishing cadence chart; top content table sorted by views (joined to analytics_hits by path); gap message when no analytics data yet
- **Member Metrics** (if members exist): MRR, total/free/paid counts with trends; visitor → free → paid conversion funnel; latest activity stat list; growth chart
- **Loading**: `Promise.allSettled` parallel fetch of all 4 endpoints — each section handles its own failure gracefully
- **API endpoints**: `dashboard/analytics?period=X` (traffic), `dashboard/seo` (SEO health), `dashboard/content?period=X` (content performance), `dashboard/members?period=X` (member metrics)
- **Permission gated**: Requires `code.*` capability (admin and developer roles only)

### Custom Events & Goal Tracking
- **JS API**: `outpost.track('event_name', {props})` — theme developers can fire custom events from any template; exposed as `window.outpost.track()` global
- **Auto-tracking**: Outbound link clicks (`outbound_click`), file downloads (`file_download` — pdf/zip/doc/xls/csv/mp3/mp4/dmg/exe), and form submissions (`form_submit`) tracked automatically via event delegation — no code needed
- **`analytics_events` table**: Stores event `name`, optional `properties` (JSON, max 2KB), `path`, `session_id`, `is_bot`, `created_at`; indexed on name, created_at, session_id
- **Goals/Conversions**: Admin-defined goals that trigger on either a page visit (URL path match against `analytics_hits`) or a custom event name (match against `analytics_events`); stored in `analytics_goals` table with `name`, `type` (pagevisit/event), `target`, `active` flag
- **Goals CRUD API**: `GET/POST/PUT/DELETE goals` endpoints for create, list, update, delete
- **Events analytics API**: `dashboard/events?period=X` (totals, chart, top events, auto vs custom counts, recent events), `dashboard/events/detail?name=X&period=X` (single event breakdown with top pages and property values)
- **Goals analytics API**: `dashboard/goals?period=X` — conversion counts, unique conversions, conversion rate (unique conversions / total unique visitors), period-over-period trends, daily conversion chart per goal
- **Tabbed analytics UI**: Analytics page restructured with Traffic / Events / Goals tab bar; each tab is a separate route (`analytics`, `analytics-events`, `analytics-goals`) rendered within a shared shell
- **Events dashboard**: Period selector, hero stats (total events, unique sessions, auto-tracked, custom), Chart.js line chart, top events table with expandable detail panel (mini chart, top pages, top property values), recent events list
- **Goals dashboard**: Create/edit inline form (name, type toggle, target input), goals list with conversion stats and trend badges, active toggle, two-step delete confirmation
- **Rate limit bumped**: 15 hits/min per IP (up from 10) to accommodate events + pageviews sharing the pool
- **Data retention**: Events pruned alongside hits — older than 13 months deleted during periodic cleanup

## Theme-Scoped Content

Content is isolated per active theme — each theme maintains its own field values for every page path. Switching themes shows that theme's content; switching back restores the original content.

- **`fields.theme` column**: SQLite migration adds a `theme TEXT DEFAULT ''` column + changes `UNIQUE(page_id, field_name)` → `UNIQUE(page_id, theme, field_name)`. Existing content migrates to `theme = ''` (legacy/unscoped); new theme-scoped content uses the active theme slug. Migration is idempotent and runs automatically on first request and on admin API init.
- **`page_field_registry` table**: Stores field schema (`theme, path, field_name, field_type, default_value, sort_order`) discovered from template scanning. `UNIQUE(theme, path, field_name)` — upsert on every scan so schema stays in sync with templates.
- **Template scanning** (`outpost_scan_theme_templates()`): Runs on theme activation and auto-rescans on each frontend request when template files have been modified since the last scan. Stores `last_template_scan_<theme>` timestamp in settings and compares against `filemtime()` of all theme `.html` files (top-level + partials). Scans all top-level `.html` files in the theme directory, parses `{{ field }}`, `{{ field | type }}`, `{{ field | type }}Default{{ /field }}`, and `{{ field }}Default{{ /field }}` closing-tag defaults, creates page stubs (if path not yet in `pages` table) and field stubs (`INSERT OR IGNORE` — never overwrites saved content), and upserts into `page_field_registry`.
- **Auto-bootstrap**: If the active theme has zero fields in the DB, the engine auto-runs the template scan on the first page request — so fresh installs populate the admin immediately without requiring a manual re-activation.
- **Auto-rescan on template change**: Editing templates via the code editor or direct file access triggers an automatic re-scan on the next frontend request — no theme re-activation needed. The scanner compares max `filemtime()` of all `.html` files against the stored `last_template_scan` timestamp.
- **Admin scoping**: `handle_pages_list()` returns only pages with fields for the active theme (falls back to showing all pages if no scoped fields exist). `handle_page_get()` returns fields filtered to the active theme, falling back to legacy unscoped fields for pre-migration sites. `handle_fields_list()` also filters by active theme.
- **`@field` = always global**: `{{ @field_name }}` in any template (page or partial) always registers as a global field — never a page field. This is the theme author's explicit declaration of intent. If a field should belong to a specific page, use `{{ field_name }}` (no `@`). Global fields are edited on the dedicated Globals admin page.
- **Global fields unscoped**: `{{ @global_name }}` fields always use `theme = ''` — shared site-wide across all themes (site name, author, social links, etc.).
- **Template default values**: Both inline and closing-tag defaults are supported. Inline: `{{ field "Default" }}` and `{{ field | type "Default" }}`. Closing-tag: `{{ field }}Default{{ /field }}` and `{{ field | raw }}Default HTML{{ /field }}`. The default is passed as the second argument to the cms function AND used as the `default_value` in field stubs and `page_field_registry`. The site renders the default until the client saves a value. The admin page editor pre-fills fields (including WYSIWYG richtext) with `default_value` when content is empty.

## Template Engine (Liquid-style)
- **Compile-once-and-cache**: Liquid-style syntax compiled to PHP, cached in `cache/templates/` — only recompiles when source file changes
- **Variable output**: `{{ field }}` → `cms_text()`, `{{ field "Default" }}` → `cms_text()` with default, `{{ field | raw }}` → `cms_richtext()`, `{{ field | raw "Default" }}` → richtext with default, `{{ @global }}` → `cms_global()`, `{{ meta.title "Default" }}` → meta fields with defaults. Default values render until the client saves content; scanner uses them to populate field stubs.
- **Collection loops**: `{% for item in collection.slug %}` / `{% for item in collection.slug limit:N %}` → `cms_collection_list()` closure; `{{ item.field }}` auto-escaped, `{{ item.field | raw }}` for richtext; `{% for %}...{% else %}...{% endfor %}` pattern renders the `{% else %}` block when the collection is empty (compiled with a `$_outpost_found_*` boolean flag)
- **Single item fetch**: `{% single var from collection.slug %}...{% else %}...{% endsingle %}` → `cms_collection_single()` by `?slug=` URL param; full `{% if %}`/`{% else %}` support inside
- **Conditionals**: `{% if toggle_field %}` (toggle), `{% if item.field %}` (non-empty check), `{% if @global_name %}` (non-empty global), `{% if item.field == "value" %}` / `{% if item.field != "value" %}` (string comparison); `{% else %}` and `{% endif %}` for all forms
- **Partials**: `{% include 'header' %}` compiles and renders partials from `partials/` directory, scope-aware compilation
- **Comments**: `{# comment #}` stripped at compile time
- **Tracker**: Privacy-first analytics snippet hardcoded to `/outpost/track.php` — injected before `</body>` via output buffer
- **`cms_global_get()`**: Returns global field value as string (no echo) — used internally by `{% if @global %}` conditionals; available in PHP templates too
- **`outpost_init()` guard**: Static `$initialized` flag prevents double-initialization if called from multiple entry points

## Forms System

Outpost has a built-in form handling pipeline. Developers write plain HTML forms — no builder, no shortcodes, no plugin. Outpost handles the server side: storing submissions in the database and emailing the notification address.

### How It Works

1. **Developer writes a standard HTML `<form>`** pointing at `form.php`. One hidden field (`_form`) names the form. Everything else is data.
2. **Outpost catches the submission**: validates, rate-limits, optionally verifies reCAPTCHA, stores in the DB, sends an email notification.
3. **Admin views submissions** in the Forms inbox (admin sidebar → Forms).

### Writing a Form (Theme Developer)

Minimal example — a contact form:

```html
<form action="/outpost/form.php" method="post">
  <input type="hidden" name="_form" value="contact">
  <input type="hidden" name="_redirect" value="/thanks">

  <label>Name</label>
  <input type="text" name="name" required>

  <label>Email</label>
  <input type="email" name="email" required>

  <label>Message</label>
  <textarea name="message" required></textarea>

  <button type="submit">Send</button>
</form>
```

Newsletter signup form:

```html
<form action="/outpost/form.php" method="post">
  <input type="hidden" name="_form" value="newsletter">
  <input type="hidden" name="_redirect" value="/">

  <input type="email" name="email" placeholder="Your email" required>
  <button type="submit">Subscribe</button>
</form>
```

### Hidden Control Fields

| Field | Required | Purpose |
|---|---|---|
| `_form` | **Yes** | Identifies the form. Used as the inbox label. Must be `[a-zA-Z0-9_-]` only. |
| `_redirect` | No | URL to redirect to after submission. Defaults to the page the form was on (`HTTP_REFERER`). |
| `_notify` | No | Override the notification email for this specific form only. Falls back to the site-wide Notification Address in Settings. |

All other `<input>`, `<select>`, and `<textarea>` fields are captured as submission data and stored verbatim.

### Redirect Behavior

After submission, Outpost appends a query param to the redirect URL:

- **Success**: `_redirect?submitted=1` → use this to show a thank-you message
- **Error**: `_redirect?form_error=rate_limited` or `?form_error=captcha_failed`

Example: showing a thank-you message with CSS/JS:

```html
<div id="thanks" style="display:none">Thanks! We'll be in touch.</div>
<script>
  if (new URLSearchParams(location.search).get('submitted')) {
    document.getElementById('thanks').style.display = 'block';
  }
</script>
```

Or redirect to a dedicated `/thanks` page — set `_redirect` to `/thanks` and create that page in Outpost.

### Spam Protection

- **IP rate limiting**: Max 5 submissions per IP per 60 seconds. Returns `form_error=rate_limited` if exceeded.
- **reCAPTCHA v2** (optional): Add your keys in Settings → reCAPTCHA. Then add the widget to your form:

```html
<form action="/outpost/form.php" method="post">
  <input type="hidden" name="_form" value="contact">
  <!-- ... your fields ... -->

  <!-- Load reCAPTCHA script in <head> or before </body>: -->
  <!-- <script src="https://www.google.com/recaptcha/api.js"></script> -->

  <div class="g-recaptcha" data-sitekey="{{ @recaptcha_site_key }}"></div>
  <button type="submit">Send</button>
</form>
```

Outpost verifies the token server-side. If verification fails, returns `form_error=captcha_failed`. If reCAPTCHA keys are not configured in Settings, the widget is ignored — no changes needed to the form HTML.

### Notification Emails

Outpost sends an HTML + plain-text email to the Notification Address (Settings → Email) or to `_notify` if provided. The email subject is `[form-name] New form submission`. The body shows every submitted field in a clean table.

Configure SMTP in **Settings → Email**. If SMTP is not configured, Outpost falls back to PHP's built-in `mail()`. Use **Send Test Email** in Settings to verify delivery before launch.

Email failures never block the submission — the data is already saved in the DB.

### Admin Submissions Inbox

**Admin sidebar → Forms**

- Left panel: list of all forms that have received submissions, with total count and unread badge.
- Right panel: submissions for the selected form, newest first, paginated (25 per page).
- Click any row to expand it and read all field values. Rows auto-mark as read on open.
- Delete individual submissions with the trash button inside the expanded row.

### SMTP Settings (Settings → Email)

| Setting | Description |
|---|---|
| Notification Address | Default email for all form submission alerts |
| From Name | Sender display name |
| From Email | Sender address |
| SMTP Host | e.g. `smtp.mailgun.org`, `smtp.gmail.com`, `smtp.sendgrid.net` |
| Port | 587 for STARTTLS (recommended), 465 for SSL, 25 for plain |
| Encryption | TLS (STARTTLS), SSL, or None |
| SMTP Username | Usually the full email or `apikey` (Mailgun) |
| SMTP Password | SMTP password or API key |
| Send Test Email | Sends a test message to the Notification Address using current (unsaved) settings |

### Implementation Notes

- `php/form.php` — public POST handler (accessible directly, not via admin API). Only processes `POST` requests.
- `php/mailer.php` — `OutpostMailer` class. No Composer dependencies. Pure PHP sockets, handles SSL (port 465), STARTTLS (port 587), plain (port 25), AUTH LOGIN. Falls back to `mail()` if SMTP host is not set.
- `form_submissions` SQLite table: `id`, `form_name`, `data` (JSON), `ip`, `read_at`, `created_at`.
- Submission data keys are HTML-entity-sanitized; values are stored as raw strings.

---

## Settings
- **Site name**: Configurable site name
- **Cache toggle**: Enable/disable page output cache
- **Email (SMTP)**: Notification address, from name/email, SMTP host/port/encryption/username/password. "Send Test Email" button verifies delivery using current (unsaved) config. Backs the Forms email notification pipeline.
- **reCAPTCHA**: Optional v2 site key + secret key. Configured here, applied automatically to any form submission where the widget is present.
- **Sync & Deploy**: API key management card — masked key display, one-click copy, two-click regenerate (with cancel) that reveals the new key once for copying; shows last pull/push timestamps; gated by `settings.*` capability
- **Scheduled Publishing**: Automatic — `outpost_maybe_auto_publish()` runs on every front-end page load with a 60-second file-based throttle (`cache/cron_last_run.txt`). No server crontab needed. The manual cron endpoint (`api.php?action=cron&key=SECRET`) still works for advanced users who want precise timing via external cron.

## Auto-Cron (Scheduled Publishing)
- **Mechanism**: `outpost_maybe_auto_publish()` called in both `front-router.php` and `index.php` after `outpost_init()`. Checks a file lock (`cache/cron_last_run.txt`) — if less than 60 seconds since last run, returns immediately. Otherwise writes current timestamp and calls `outpost_auto_publish_scheduled()`.
- **Effect**: Queries `collection_items` for `status = 'scheduled' AND scheduled_at <= now`, updates matching rows to `status = 'published'`, clears template cache
- **Fallback cron endpoint**: `api.php?action=cron&key=SECRET` — public, key-authenticated via `hash_equals()`, also processes webhook retries and delivery cleanup

## Sync API (`sync-api.php`)
- **Authentication**: `X-Outpost-Key` header with `hash_equals()` constant-time comparison; rate limiting (5 attempts/hour via `sync_rate_limits` SQLite table, 1-hour lockout)
- **HTTPS enforcement**: Rejects non-HTTPS requests except from localhost
- **Pull endpoint**: Returns full theme file tree (base64), uploads list, db_snapshot (settings + pages + collections + items + terms), active theme, site URL — never exposes the API key itself
- **Push endpoint**: Validates file extensions (whitelist) and size (2MB cap), prevents path traversal via manual normalization (works for new files that don't exist yet), auto-creates local backup before applying, writes files atomically with `LOCK_EX`
- **Backup endpoints**: `backup/list` (with 30-day auto-pruning) and `backup/restore` (by backup ID)
- **Key management in `api.php`**: `sync/key` GET auto-generates key on first access (`random_bytes(32) → bin2hex`), returns masked version (first 8 + bullets + last 4); `sync/key/regenerate` POST returns full key once then permanently masks

## Outpost Builder (Electron app at `starter/builder/`)
- **Sites dashboard** (`ui/index.html`): Sites grid with server status dots (running/stopped), last pull timestamp, Open (start server + launch browser) and Manage actions; Add Site modal with URL + API key; Remove confirmation; PHP availability notice
- **Site view** (`ui/site.html`): Per-site management — server toggle, pull with progress display, push with diff preview (colored added/modified/deleted file list), push-content toggle for syncing DB, local/remote backup tabs with restore
- **SyncEngine** (`engine/SyncEngine.js`): Native Node `http`/`https` client, `X-Outpost-Key` auth, pull/push/getDiff/getRemoteBackups/restoreRemoteBackup; `_walkDir` with extension whitelist
- **UrlReplacer** (`engine/UrlReplacer.js`): Bidirectional live↔local URL replacement — `onPull` writes to disk, `onPush` operates in-memory on base64 file objects to avoid modifying local files
- **PhpServer** (`engine/PhpServer.js`): Manages `php -S localhost:PORT` processes per site, auto port assignment (8081–8200), `stopAll()` on app quit
- **BackupManager** (`engine/BackupManager.js`): Timestamped backup directories with `manifest.json`, pre-restore backup, 30-day retention pruning
- **ClaudeMd** (`engine/ClaudeMd.js`): Auto-generates `CLAUDE.md` in site files dir on every pull with live URL, local URL, file structure, template engine reference, and Content API examples
- **Site registry**: JSON file at `~/Library/Application Support/outpost-builder/sites.json` — no native module compilation required
- **Design**: Trailhead tokens matching Outpost admin (Inter + Fraunces fonts, forest green accent, warm earth palette), Mac traffic-light-aware titlebar

## Public Content API (Headless)
- **Read-only REST API**: All endpoints at `api.php?action=content/...` — no auth, no CSRF, wide-open CORS
- **Rate limiting**: 120 requests per 60 seconds per IP. Returns 429 with `Retry-After` header on exceed. (v2.4.0)
- **Schema introspection**: `content/schema` returns full type graph — collections with typed fields, folders with label fields, pages with field types
- **Pages**: `content/pages` (all public with fields) or `content/pages&path=/about` (single by path). Members-only and paid pages excluded. (v2.4.0)
- **Collections**: `content/collections` (list with field/item counts) or `content/collections&slug=blog` (single with full schema)
- **Items**: `content/items&collection=blog` — published-only, paginated (`limit`, `offset`), sortable (`orderby` or `orderBy`, `order`), filterable by folder label (`{folder_slug}={label_slug}`). Each item includes `title` and `url` at top level. (v2.4.0)
- **Menus**: `content/menus` (list all menus) or `content/menus&slug=main` (single menu with nested items/children). (v2.4.0)
- **Folders & labels**: `content/folders` (all with label counts, optional `?collection=` filter), `content/labels&folder=categories` (labels with item counts and custom field data). (v2.4.0)
- **Media**: `content/media` — paginated public media listing
- **Published-only filter**: Items endpoint strictly returns `status = 'published' AND published_at <= now` — no drafts, no scheduled
- **Response envelope**: `{ "data": ..., "meta": { "total", "limit", "offset" } }` for lists, `{ "data": { ... } }` for singles

## Navigation / Menu Management
- **Menu builder admin UI** (`Navigation.svelte`): Two-pane layout — menu list sidebar + item editor. Create/rename/delete named menus.
- **Items**: Inline label + URL editing, up/down reorder buttons, add/delete top-level items and sub-items (one level deep).
- **Page picker**: Click the page icon on any URL field to open a searchable page picker overlay — auto-fills URL from selected page path.
- **Open in new tab**: Toggle per item (`target="_blank"`), shown as a mini toggle in the row.
- **Multiple menus**: Unlimited named menus (e.g. `main`, `footer`) each identified by a unique slug.
- **Template syntax**: `{% for item in menu.main %}` — loops over items; `{% for child in item.children %}` — loops over dropdown children. Both supported by template engine.
- **Dropdown support**: `{% if item.children %}` works in templates; personal theme nav updated with `.nav-dropdown` wrapper for styling.
- **Mobile nav**: Mobile overlay also uses `{% for item in menu.main %}` with children flattened as `.mobile-child-link`.
- **API**: Full CRUD at `api.php?action=menus` (GET list, GET single, POST create, PUT update, DELETE).
- **Engine**: `cms_menu_items(string $slug): array` returns sanitized item array (label, url, target, children).

## Folder Template Loops
- **`{% for label in folder.slug %}`**: Template syntax to loop over all published labels in a named folder. Each `label` exposes `label.name`, `label.slug`, and `label.count` (number of published items tagged with that label). Old syntax `{% for term in taxonomy.slug %}` still works for backward compatibility.
- **`cms_folder_labels(string $slug): array`**: Engine function backing the loop. Joins `labels → folders → item_labels → collection_items` with a published-only filter and grouped count. `cms_taxonomy_terms()` kept as alias.
- **`folder` and `taxonomy` namespaces excluded** from the generic `item.field` loop rule in the template compiler so they route to their dedicated handler.

## Post Page Sidebar (Personal Theme)
- **Two-column layout** (`post-layout`): Article content on the left, sticky sidebar on the right. Collapses to single column on mobile (<900px).
- **Newsletter** widget reuses `.newsletter-card` component.
- **Recent posts**: `{% for recent in collection.post limit:5 %}` — linked list of 5 latest posts with title + date.
- **Categories**: `{% for label in folder.categories %}` — pill tags with post counts linking to `/blog?category=slug`.

## Admin Edit Link (Templates)
- **`outpost_is_admin(): bool`** in `engine.php` — reads the active Outpost admin session (same cookie as the admin SPA). Returns true if the visitor is a logged-in admin.
- **`{% if admin %}` template tag** — compiles to `<?php if (outpost_is_admin()): ?>`. Placed before the generic `{% if field %}` rule so it matches first.
- **Edit link in `post.html`** — appears below the post body only for logged-in admins. Links directly to `/outpost/#/collection-editor/collection=post/itemId={{ post.id }}` using the item's actual database ID (already available in `$post['id']` from `cms_collection_single()`).

## WordPress Import
- **`php/import.php`** — parses a WordPress WXR 1.x export with `DOMDocument`/`DOMXPath`. Builds an attachment map (`wp:post_id` → `wp:attachment_url`) for featured image resolution. Imports `post` type items; handles categories as labels in a `categories` folder (auto-created if absent).
- **API endpoint**: `POST api.php?action=import/wordpress` — multipart file upload. Options: `collection_slug`, `status_filter` (publish|all), `on_duplicate` (skip|overwrite). Returns `{imported, skipped, overwritten, errors}`.
- **`src/pages/Import.svelte`** — drag-and-drop file zone, options for status filter and duplicate handling, result summary with warning list.
- **Sidebar link** under Settings, visible to `canManageSettings` users. Route: `import`.

## System-Injected Admin Bar
- **Auto-injected** before `</body>` by `outpost_cache_output()` for any logged-in admin — no theme changes required.
- **Dynamic "Edit in Outpost" link** — if viewing a collection single item, links directly to `/outpost/#/collection-editor/collection=SLUG/itemId=ID`; if viewing a regular page, links to `/outpost/#/page-editor/pageId=ID`; fallback to `/outpost/`.
- **Floating cache-clear button** beside the edit link — one click, clears cache, reloads page. Uses session CSRF token injected at render time.
- **Admins bypass cache** — `outpost_init()` skips cache read for admins so they always see fresh content. Their page views are never written to cache.
- **`$_outpost_current_item` / `$_outpost_current_collection`** globals — set by `cms_collection_single()` when an item is resolved, used by the admin bar to build the correct edit URL.

## System-Injected GA4
- **Auto-injected** before `</head>` from the `ga4_id` global field — any theme that invokes Outpost gets GA4 automatically.
- Removed `{% if @ga4_id %}` block from `themes/personal/partials/head.html`; themes no longer need to handle analytics.
- Cached in HTML cache (same for all visitors) but excluded from admin views (admins bypass cache).

## Collection Loop Improvements
- **Fixed Pass 2 bug** — `{% for item in collection.slug limit:N %}` (without `{% else %}`) previously dropped the `limit` option; rewritten as a full-block capture so all options are preserved.
- **`orderby:field` option** — `{% for post in collection.post orderby:published_at %}` compiles to `ORDER BY published_at DESC`.
- **`related:varname` option** — `{% for related in collection.post related:post limit:3 %}` fetches posts sharing folder labels with the named variable's item, falls back to recent posts if no labels.
- **Personal theme updated**: homepage posts `limit:3 orderby:published_at`; blog page `orderby:published_at`; post sidebar uses `related:post limit:3 orderby:published_at` and is titled "Related posts".

## Version Number Pill
- Version pill appears as an absolute-positioned badge overlapping the bottom-right corner of the faded Outpost logo in the admin footer. Reads the current version dynamically from the API.

## Auto Cache Invalidation
- **Post save** (`handle_item_update`): calls `outpost_clear_cache()` (full HTML cache) after every save — listing pages like `/blog` also reflect the change immediately.
- **Page save** (`handle_page_update`): calls `outpost_clear_cache($path)` to invalidate that specific page's cache.
- **Page fields save** (`handle_fields_bulk_update`): fixed — when any global field is in the batch, clears the entire HTML cache (globals affect all pages); otherwise clears only affected page paths.

## TopBar — View Site + Clear Cache
- **"View site"** link always visible in the top bar — opens `/` in a new tab.
- **"Clear cache"** button always visible — calls `POST cache/clear`, spins while clearing, shows toast on completion.

## Version Number
- `OUTPOST_VERSION` (`config.php`) is returned in `auth/me` response as `version` field.
- Displayed as `vX.Y.Z` in the admin footer watermark alongside the Outpost logo.

## Category Filtering on Blog Page
- Template tag: `{% for post in collection.post filteredby:category %}` — reads `$_GET['category']` and filters by folder label slug using a JOIN query
- `parseCollOpts` in template-engine.php parses `filteredby:param` → `'filter_param' => 'param'`
- `cms_collection_list()` in engine.php handles `filter_param` with a INNER JOIN on `item_labels` + `labels` tables, filtering by label slug
- `blog.html` updated: category filter bar at top (All + each label), active state highlighted via JS reading `?category=` param
- **Bug fix**: Page HTML cache keyed only on path — `?category=bricks` served same cached HTML as `/blog`. Fixed `outpost_cache_path()` to include sorted query string in the cache key hash.

## Blog Pagination
- Template tag: `{% for post in collection.post paginate:10 %}` — replaces `limit:N` for paginated lists; reads `$_GET['page']` for offset
- `{% pagination %}` tag — renders `<nav class="pagination">` with numbered page links, Prev/Next, ellipsis for large page counts
- `cms_collection_list()` counts total matching items (with filter if active) and stores state in `OutpostPagination` class
- `cms_pagination()` renders links, preserves all existing query params (e.g. `?category=coding&page=2`)
- Category filter and pagination compose correctly — switching category resets to page 1 (category links never include `?page=`)

## Password Reset

Full forgot-password / reset-password flow for both admin users and members.

**Admin users** (role: super_admin, admin, developer, editor):
- Login page: "Forgot password?" link switches to the forgot view (no page reload — SPA)
- Forgot view: email input → `POST auth/forgot` → always shows "check inbox" (no email enumeration)
- Email contains a reset link: `/outpost/?reset_token=TOKEN`
- On page load `Login.svelte` reads `?reset_token=` via `URLSearchParams`, switches to the reset view, and strips the token from the URL with `history.replaceState`
- Reset view: new password + confirm → `POST auth/reset` → on success returns to login with "Password updated" message

**Members** (role: free_member, paid_member):
- `/outpost/member-pages/login.php` — "Forgot password?" link added
- `/outpost/member-pages/forgot-password.php` — email form, generates token, sends email, always shows "check inbox"
- `/outpost/member-pages/reset-password.php` — validates `?token=` on load, shows password form, updates on POST

**Implementation details:**
- Tokens: `bin2hex(random_bytes(32))` — 64-char hex, 1-hour expiry stored in `users.reset_token` + `users.reset_token_expires`
- Migration: `ensure_users_columns()` adds the two columns via `PRAGMA table_info` if not present
- Token is NULLed after use — single-use only
- Reset emails sent via `OutpostMailer::fromSettings()` with HTML + plain-text body

## Developer Documentation (`/outpost/docs/`)

Standalone documentation site at `/outpost/docs/index.html` and LLM-readable plain-text reference at `/outpost/docs/llms.txt`.

- Fixed sidebar+content layout (dark sidebar, scrollable main content)
- Linked from admin UI watermark footer ("Developer Docs →")
- `llms.txt` follows the llmstxt.org standard — index at top with `llms-full` content below

**Corrections applied after multi-agent audit:**
- Database filename: `outpost.db` → `cms.db`
- Admin roles: corrected to `super_admin / admin / developer / editor` (removed non-existent `viewer`)
- Form hidden fields: `form_name` → `_form`, `redirect` → `_redirect`; added `_notify` override; noted reCAPTCHA support
- API table: removed `fields GET/PUT` (only `fields/bulk PUT` exists); removed duplicate `stats GET`; added 12 missing endpoints (`loops`, `forms/config`, `forms/test-smtp`, `forms/export`, `code/files/read/write`, `dashboard/activity/seo/content/members`, `import/wordpress`, `sync/key/regenerate`)
- Added undocumented template filters: `| color`, `| number`, `| date`, `| toggle`, `| or_body`
- Added undocumented template loops: `{% for x in menu.slug %}`, `{% for x in gallery.field %}`, `{% for x in repeater.field %}`
- Added `{% if admin %}` conditional, `term.count`, `item.id/created_at/updated_at`
- Noted `orderby` is always DESC; noted `meta.title/description` smart fallback on single pages
- Added `member-pages/logout.php` and `profile.php`; member API `password PUT`; member suspension feature
- Fixed `OutpostAuth::verifyPassword()` — doesn't exist; PHP's `password_verify()` is used directly

## Bug Fixes
- **Analytics SQL crash**: `handle_analytics()` crashed with "misuse of aggregate function MAX()" on the per-page session duration query — `AVG()` was wrapping `MAX()`/`MIN()` aggregates directly; fixed with a subquery. Analytics page was returning HTTP 500 and showing all zeros.
- **SEO page count**: SEO health checker was counting 6 pages when user only had 4 — `/sync-api` (system endpoint) and `/post` (bare collection slug, no template) were incorrectly included. Filter now excludes system paths starting with `/sync`/`/outpost` and the exact collection base slug.
- **Dashboard chart with 1 day of data**: Chart required ≥2 days of analytics activity before rendering; lowered threshold to ≥1 day so early-stage sites see a chart immediately.
- **Right sidebar Quick Actions removed**: "Clear Cache" and all Quick Actions panels removed from sidebar — cache clear is in the top bar header. "View Site" removed from left sidebar nav.
- **`@global` field content migration**: `outpost_scan_theme_templates()` now detects when an `@field` (global) has content stored as a page-level field (e.g. from a previous template version), migrates that content to the global row before deleting the page-level copy. Prevents data loss when templates change `{{ field }}` → `{{ @field }}`.
- **Gallery images in personal theme**: Photo gallery strip on the homepage now uses `{% for img in gallery.gallery %}` instead of 5 hardcoded empty `<div class="photo-card"></div>` elements — images uploaded via the Gallery field editor now display.
- **`cms_gallery_items()` double-escaping**: Function now returns raw (unescaped) URLs so `{{ img.url }}` in templates escapes once, correctly.

## Page Draft / Publish Status

Pages now have a `status` field (`published` | `draft`). All existing pages default to `published` — no site disruption. Draft pages return a 404 to visitors.

### How It Works

- A `status` column (`DEFAULT 'published'`) and `published_at` column are added to the `pages` table via a migration that runs automatically on every API request.
- The front router checks the page status before rendering. If status is `draft`, it returns a 404 (using the theme's `404.html` if available).
- Draft status only applies to direct page matches (`/`, `/about`, `/contact`). Collection item templates (e.g. `/post/slug`) use collection item status, not page status.

### Admin UI

**Pages list** — each row shows a clickable status badge. Click to toggle without entering the editor:
- Green `published` badge → click to set draft
- Amber `draft` badge → click to publish

**Page editor** — the status toggle sits in the save bar next to the Save button:
- Shows `Published` (green) or `Draft` (amber)
- Click to immediately toggle — no save required for status changes
- The Save button only saves content fields and SEO meta; status is independent

### Priority: Default to Published

Unlike collection items (which default to `draft`), pages default to `published`. This is intentional — pages are discovered by the CMS when a visitor first loads the page. Defaulting to published means the discovery visit itself doesn't immediately hide the page.

To create a page in draft mode:
1. Visit the page URL once to let Outpost discover it (it will be live briefly)
2. Go to Pages in admin, click the status badge to set it to Draft
3. The page now returns 404 to visitors

### Implementation Notes

| File | Change |
|------|--------|
| `php/api.php` | `ensure_pages_status_column()` migration; `handle_page_update()` now accepts `status` field |
| `php/front-router.php` | Draft check after direct page match — returns 404 if `status = 'draft'` |
| `src/pages/Pages.svelte` | Status badge in list rows with click-to-toggle |
| `src/pages/PageEditor.svelte` | Status toggle button in save bar |

## Forms — Notification Routing & Inbox Actions

Extended the forms system with per-form notification email configuration, a Reply action, and CSV export.

### Per-Form Notification Email

Each form can have its own notification email address, set from the admin without touching theme code. Priority order:

1. `_notify` hidden field in the HTML form (developer override)
2. Per-form email set in the admin inbox
3. Global `notify_email` from Settings → Email

**Setting it in the admin:**
- Open Forms in the admin
- Select a form filter (e.g. `contact`)
- The notification bar below the toolbar shows the current email or a warning if none is set
- Click **Edit** / **Set one** → type an address → Save (or press Enter)

**Setting it in the HTML form (developer override):**
```html
<input type="hidden" name="_notify" value="sales@example.com">
```

**`form_configs` table** stores per-form settings. The migration runs automatically.

### Reply Action

When you open a submission that contains an `email` field, a **Reply** button appears in the detail panel header. Clicking it opens your mail client (`mailto:`) pre-filled with the sender's address and the subject `Re: {form_name} form`. No email is sent through Outpost — it hands off to your mail client.

### Export CSV

The **Export** button in the forms toolbar downloads a `.csv` file of all submissions in the current view:
- If a form filter is active (e.g. viewing just `contact`), exports only that form's submissions
- If viewing All, exports every submission across all forms
- Columns: `id`, `form`, `date`, `ip`, `read`, then one column per field across all submissions

The download uses a direct browser navigation to `api.php?action=forms/export` — no JavaScript fetch required, so the browser handles it as a file download natively.

### Implementation Notes

| File | Change |
|------|--------|
| `php/api.php` | `GET/PUT forms/config` endpoints; `GET forms/export` CSV stream; `ensure_form_configs_table()` migration |
| `php/form.php` | `get_form_notify()` looks up per-form email from `form_configs` table |
| `src/lib/api.js` | `forms.getConfig()`, `forms.setConfig()`, `forms.exportUrl()` |
| `src/pages/Forms.svelte` | Notification bar, Reply button, Export button |

## UI/UX
- **3-column layout**: Sidebar (240px) + Content + Right sidebar (320px), responsive collapse
- **Ghost/Linear minimal aesthetic**: Borderless inputs, tiny uppercase labels, no card wrappers, minimal separators
- **Sidebar navigation**: Dark sidebar with collection expand/collapse, status sub-filters with counts, folder links, top-level nav items
- **Right sidebar**: Contextual widgets per route (item metadata, page help, folder help)
- **Watermark footer**: "Handcrafted with love in Wilmington, NC" with subtle logo

---

## Page Visibility — Members-Only Gating

Pages can now be marked as **Public**, **Members only**, or **Paid members** directly from the admin UI — no code changes required.

- **Database**: `visibility` column on `pages` table (`public`, `members`, `paid`) with auto-migration
- **PageEditor**: Three-option selector in the SEO section — radio-style buttons with lock icon for restricted options
- **Pages list**: Lock icon shown inline next to the title for non-public pages
- **Front-end enforcement**: `front-router.php` and `index.php` look up the page's visibility before rendering; calls `cms_require_member()` or `cms_require_paid_member()` to redirect unauthorized visitors
- **Validation**: API rejects invalid visibility values; defaults to `public`

## Security — Media Filename Sanitization (Allowlist)

`sanitizeFilename()` in `media.php` now uses an **extension allowlist** instead of a blocklist. Previously it only stripped `.php`, leaving `.phtml`, `.php7`, `.phar`, `.inc`, and other executable extensions unblocked.

**New behaviour**: after stripping unsafe characters, the extension is extracted and compared against `OUTPOST_ALLOWED_EXTENSIONS` (`jpg`, `jpeg`, `png`, `gif`, `webp`, `avif`, `svg`, `pdf`, `mp4`, `webm`). Any extension not on the list is silently discarded — the file is saved without an extension rather than with a dangerous one.

| File | Change |
|------|--------|
| `php/media.php` | `sanitizeFilename()` — blocklist replaced with allowlist via `OUTPOST_ALLOWED_EXTENSIONS` |

---

## Webhook System

Send HTTP POST notifications to external URLs when content changes — integrates with Zapier, Netlify deploy hooks, Slack, and custom workflows.

- **Events**: `entry.created`, `entry.updated`, `entry.published`, `entry.unpublished`, `entry.deleted`, `page.updated`, `page.published`, `page.unpublished`, `page.deleted`, `media.created`, `media.deleted`, `member.created`, `member.updated`, `member.deleted`, `form.submitted`, `cache.cleared`. Wildcard `*` subscribes to all events.
- **Payload**: JSON body with `event`, `timestamp`, and `data` object. Signed with `X-Outpost-Signature: sha256=<HMAC-SHA256>` using a per-webhook secret.
- **Delivery**: Immediate attempt with 5s timeout. Failed deliveries retry up to 5 times on an escalating schedule (1m, 5m, 30m, 2h, 12h) via the cron endpoint. Successful deliveries auto-cleanup after 7 days, failed after 30 days.
- **Admin UI**: Webhooks page under Settings with create/edit modal, event checkboxes grouped by category, custom headers, active toggle, test button, and per-webhook delivery log.
- **Secret management**: Auto-generated `bin2hex(random_bytes(32))` on creation (shown once), masked afterward, regenerate with confirmation.
- **API endpoints**:
  - `GET api.php?action=webhooks` — list webhooks
  - `GET api.php?action=webhooks&id=N` — get webhook details
  - `POST api.php?action=webhooks` — create webhook (returns secret)
  - `PUT api.php?action=webhooks&id=N` — update webhook
  - `DELETE api.php?action=webhooks&id=N` — delete webhook
  - `POST api.php?action=webhooks/regenerate-secret&id=N` — regenerate signing secret
  - `GET api.php?action=webhooks/deliveries&id=N` — delivery log (last 50)
  - `POST api.php?action=webhooks/test&id=N` — send test event
- **Permissions**: `settings.*` capability (admin and super_admin only).
- **Files**: `php/webhooks.php` (engine), `src/pages/Webhooks.svelte` (admin UI), dispatch calls in `api.php`, `form.php`, `member-api.php`.
