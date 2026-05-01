# Outpost v6 — Build Plan

**Status:** Active build
**Source of vision:** "Outpost v3 Vision" room, The Table, 2026-05-01 (Sites + Claude with Tony directing)
**Where work lands:** This repo (`tonyshawjr/outpost`). Sites is design source-of-truth; Outpost is the working repo.
**Authorization:** Tony explicitly authorized "go build" 2026-05-01.

---

## Ground rules

1. **No feature removal.** Every existing Outpost feature stays — channels, Lodge, GraphQL, Workflows, Comments, Forms, Boost, Compass, Shield, Releases, Webhooks, Redirects, Track, MCP. The simplification comes from a *content-mode admin layer* that hides developer surfaces from non-technical users, not from cutting capability.
2. **Backwards compatibility.** Existing Outpost themes (`forge-playground`, etc.) keep working through v6. The v5 → v6 upgrade does not break live sites.
3. **Sites is design source.** Sites' Ghost-inspired admin polish, Design panel, and page-builder UX become Outpost's. Sites itself stays running for Kenii unchanged during the v6 build. Eventually Sites becomes the Institution Pack (a distribution on top of v6) — that migration happens *after* v6 ships and is trusted, not as part of this build.
4. **Editorial AI as a real pillar.** Scheduled AI content jobs, not just an assistant in a sidebar. MCP + Ranger + cron = Ranger-as-team-member.
5. **AI is summoned, not ambient.** AI runs when explicitly invoked or scheduled, not on every save. This is the cost-control answer to Tony's concern about API spend.

---

## Six tracks

| # | Track | Lead | Independent? |
|---|-------|------|--------------|
| 1 | Admin shell port (Sidebar, TopBar, MobileNav, AvatarMenu, design tokens, base components) | Sites | Foundation; unblocks Tracks 2, 5 |
| 2 | Design panel + theming (Design.svelte, customizer/, theme schema reconciliation) | Sites | Depends on Track 1 design tokens |
| 3 | MCP introspection + composition tools | Outpost | Independent |
| 4 | Template hierarchy convention | Outpost | Independent |
| 5 | Outpost-only admin surface redesign in new language | Outpost | Depends on Track 1 |
| 6 | Forge code-editor UX (selection-aware, multi-element auto-wrap) | Sites | Depends on Tracks 1-5 |

---

## Schema reconciliation (Track 2 prerequisite — done here)

### Current state

**Outpost `theme.json`** uses formal JSON Schema (Draft-07) at `php/docs/schemas/theme.schema.json`. Shape:

```json
{
  "name": "...",
  "version": "...",
  "author": "...",
  "description": "...",
  "screenshot": "...",
  "managed": false,
  "customizer": {
    "sections": [
      {
        "id": "colors",
        "label": "Colors",
        "fields": [
          { "key": "color-accent", "label": "Accent", "type": "color", "default": "#7C3AED", "var": "--color-accent" }
        ]
      }
    ]
  }
}
```

Field types in v5: `color | font | image | text | toggle`.

**Sites `blueprint.json`** uses an informal flat-settings shape:

```json
{
  "name": "Starter",
  "version": "1.0.0",
  "author": "Kenii",
  "brand": { "accent_color": "#7C3AED", "heading_font": "Inter", "body_font": "Inter" },
  "settings": {
    "nav_layout": { "label": "Navigation layout", "type": "select", "options": ["logo-left", "logo-center", "logo-right"], "default": "logo-left" },
    "header_text": { "label": "Header text", "type": "text", "default": "", "placeholder": "Defaults to site description" },
    "site_bg_color": { "label": "Site background", "type": "color", "default": "#ffffff" }
  },
  "templates": [
    { "slug": "page", "name": "Full Width" },
    { "slug": "post", "name": "Blog Post" }
  ],
  "blocks": ["hero", "content-section", "two-column", "cta-band", "stats-row"]
}
```

### Differences

| Concern | Outpost v5 | Sites | v6 resolution |
|---|---|---|---|
| File name | `theme.json` | `blueprint.json` | **Keep `theme.json`.** Outpost's name wins; Sites migrates. |
| Top-level brand defaults | None | `brand: { accent_color, heading_font, body_font }` | **Adopt:** add optional `brand` block at top level. Used as fallback for customizer fields that bind to brand keys. |
| Settings structure | `customizer.sections[].fields[]` (grouped) | `settings: {}` (flat) | **Outpost wins.** Sites migrates flat `settings` into a single `customizer.sections[]` entry. Tooling auto-converts. |
| Field type: `select` | Not defined | Yes (with `options`) | **Adopt:** add `select` to enum, plus `options` array on field. |
| Field type: `font` | Yes | Implicit (via `heading_font`/`body_font`) | **Outpost wins.** Sites' brand fields become `font` typed customizer fields. |
| Field type: `image` | Yes | Not defined | **Outpost wins.** Available for future Sites use. |
| Field type: `toggle` | Yes | Yes | Same |
| Field type: `color` | Yes | Yes | Same |
| Field type: `text` | Yes | Yes (with `placeholder`) | **Adopt placeholder:** add optional `placeholder` to all string-input field types. |
| `templates[]` array | Not declared | Yes | **Adopt:** add optional `templates` array — `[{ slug, name, description? }]`. Used by the page builder template picker. Falls back to filesystem scan if absent. |
| `blocks[]` array (whitelist) | Not declared | Yes | **Adopt:** add optional `blocks` array of slugs. Used by the content-mode block library to restrict picker. Absent = all blocks in `themes/{theme}/blocks/` available. |
| `screenshot` | Yes | Not used | Keep optional. |
| `managed` flag (auto-update) | Yes | Not used | Keep. |

### Unified v6 schema (proposal)

```json
{
  "$schema": "/outpost/docs/schemas/theme.schema.json",
  "name": "Starter",
  "version": "1.0.0",
  "author": "Outpost",
  "description": "Default starter theme",
  "screenshot": "screenshot.png",
  "managed": true,
  "brand": {
    "accent_color": "#7C3AED",
    "heading_font": "Inter",
    "body_font": "Inter"
  },
  "templates": [
    { "slug": "page", "name": "Full Width" },
    { "slug": "post", "name": "Blog Post" },
    { "slug": "landing", "name": "Landing Page" }
  ],
  "blocks": ["hero", "content-section", "two-column", "cta-band"],
  "customizer": {
    "sections": [
      {
        "id": "brand",
        "label": "Brand",
        "fields": [
          { "key": "accent_color", "label": "Accent color", "type": "color", "default": "#7C3AED", "var": "--color-accent" },
          { "key": "heading_font", "label": "Heading font", "type": "font", "default": "Inter", "var": "--font-heading" },
          { "key": "body_font", "label": "Body font", "type": "font", "default": "Inter", "var": "--font-body" }
        ]
      },
      {
        "id": "layout",
        "label": "Layout",
        "fields": [
          { "key": "nav_layout", "label": "Navigation", "type": "select", "options": ["logo-left", "logo-center", "logo-right"], "default": "logo-left" },
          { "key": "site_bg_color", "label": "Site background", "type": "color", "default": "#ffffff", "var": "--color-bg" },
          { "key": "header_text", "label": "Header text", "type": "text", "default": "", "placeholder": "Defaults to site description" }
        ]
      }
    ]
  }
}
```

### Field types — v6 union

```
color | font | image | text | toggle | select
```

All fields support: `key`, `label`, `type`, `default`, `var` (CSS custom property), `placeholder` (string-input only), `options` (`select` only — required), `description` (optional helper text).

### Migration

- **Outpost v5 themes:** No change required. Existing `theme.json` files validate cleanly against v6 schema (all v5 fields are still supported).
- **Sites blueprint.json:** Auto-converted on theme install or first load. Mapping:
  - `brand.accent_color` / `brand.heading_font` / `brand.body_font` → preserved at top-level `brand`, plus exposed as `customizer.sections[id=brand].fields[]`.
  - `settings.{key}` → `customizer.sections[id=general].fields[key]`. Multiple section grouping only available in v6 native authoring.
  - `templates[]`, `blocks[]` → preserved as-is.

A migration utility (`php/scripts/migrate-blueprint-to-theme.php`) handles the conversion. Idempotent.

---

## Track 3: MCP introspection + composition tools — **first slice landed**

**Status:** `list_blocks` and `get_block_schema` shipped (commit `351190f`, 2026-05-01). Outpost-ization of blocks.php complete.

**What shipped:**
- `php/blocks.php` Outpost-ized: functions renamed `kenii_*` → `outpost_*`, constant swapped to `OUTPOST_THEMES_DIR`. `kenii_*` aliases preserved for backwards compatibility (remove in v7).
- `php/mcp.php` requires `blocks.php`.
- New MCP tool `list_blocks` — returns all blocks in active theme with slug, name, description, category, icon, fields. No input args.
- New MCP tool `get_block_schema` — returns full schema for one block by slug. Validates slug pattern. Clean error on missing block.
- Total MCP tools: 15 → 17.
- Both files lint clean (`php -l`).

**What's left in Track 3 (still spec'd, not yet built):**
- `list_channels` — enumerate custom post types
- `get_channel_schema` — detailed channel field schema
- `list_templates` — templates available in active theme
- `compose_page` — create a page with blocks pre-populated
- `add_block_to_page` — insert block into existing page
- `set_block_field` — update one field on one block
- `list_theme_files` — enumerate template + partial files

The unblock for "Claude Code, build me a page" workflow happens when `compose_page` lands. Until then, AI clients can *introspect* (know what blocks/channels exist) but not *compose* (build pages from blocks).

---

### Original Track 3 prerequisite notes (now resolved)

- It used `KENII_CONTENT_DIR` (undefined in Outpost — Outpost defines `OUTPOST_CONTENT_DIR` and `OUTPOST_THEMES_DIR` in `config.php`).
- It used `kenii_*` function names.
- It was not `require_once`'d anywhere.

Resolved in commit `351190f`: file Outpost-ized, `kenii_*` aliases preserved for BC, wired into `mcp.php`. Engine.php integration deferred until pages need server-side block rendering (blocks.php is currently called only from MCP).



Current MCP tools (15 total): `list_collections`, `get_collection`, `list_items`, `get_item`, `create_item`, `update_item`, `delete_item`, `list_pages`, `get_page_fields`, `update_page_fields`, `get_globals`, `update_globals`, `list_media`, `search_content`, `get_schema`.

**Missing for "Claude Code, build me a page" workflow:**

| Tool | Purpose | Inputs | Returns |
|---|---|---|---|
| `list_blocks` | Enumerate blocks available in active theme | (none, or `theme` slug) | `[{ slug, name, description, category, icon, fields: [...] }]` |
| `get_block_schema` | Detailed block field schema | `slug` | Full block.json equivalent |
| `list_channels` | Enumerate custom post types (channels) | (none) | `[{ slug, name, fields: [...], single_template?, archive_template? }]` |
| `get_channel_schema` | Detailed channel field schema | `slug` | Full channel definition |
| `list_templates` | Templates available in active theme | (none) | `[{ slug, name, description? }]` |
| `compose_page` | Create a page with a sequence of blocks pre-populated | `{ title, slug?, template?, blocks: [{ slug, fields: {...} }] }` | New page with blocks in order |
| `add_block_to_page` | Insert a block into existing page at position | `{ page_id, block_slug, position?, fields }` | Block ID |
| `set_block_field` | Update one field on one block | `{ page_id, block_id, field_key, value }` | OK |
| `list_theme_files` | Enumerate template + partial files in active theme | (none) | `[{ path, type, kind: template|partial|block|asset }]` |

Implementation note: all of these read from existing PHP functions (`kenii_scan_blocks` / Outpost equivalent, channel definitions, template scanner). The work is wiring them up as JSON-RPC tools in `mcp.php`.

---

## Track 4: Template hierarchy convention — **first slice landed**

**Status:** Single-template hierarchy implemented in `php/front-router.php` (2026-05-01).

**What shipped:**
- New helper `outpost_resolve_single_template($themeDir, $type)` walks the chain `single-{type}.html` → `{type}.html` → `single.html` → `post.html`. Returns the first existing path or `null`.
- Collection-item routing (around line 357 of front-router.php) now uses the helper.
- Channel-item routing (around line 380) now uses the helper, with `channel.html` as an additional final fallback to preserve existing channel-specific templates.
- PHP syntax check passes (`php -l`).
- Backwards-compatible: existing themes using `{type}.html` keep working unchanged.

**What's deferred:**
- Archive hierarchy (`archive-{type}.html` etc) — Outpost doesn't have explicit archive routing today; archives are rendered via static pages that use `<outpost-each>` for iteration. Adding archive-as-its-own-template-kind is a larger change. Defer until there's demand.
- Channel scaffolder that auto-writes starter `single-{slug}.html` when a new channel is created — admin-side work, deferred to Track 5 (channels admin redesign).
- `theme.json` declaring per-channel template overrides — possible future addition.

**For theme authors:**
- New convention: `single-{slug}.html` for a per-channel/collection override (most specific).
- New convention: `single.html` as a generic single-item fallback.
- Old convention: `{slug}.html` and `post.html` continue to work.



Adopt WordPress-style template hierarchy as the default routing convention. Themes can override any layer; engine falls back through the chain.

```
For a single item from channel "programs":
  themes/{active}/single-programs.html  ← most specific
  themes/{active}/single.html           ← channel default
  themes/{active}/page.html             ← generic fallback

For an archive listing of channel "programs":
  themes/{active}/archive-programs.html
  themes/{active}/archive.html
  themes/{active}/page.html

For a static page:
  themes/{active}/templates/{template_slug}.html
  themes/{active}/page.html

Site chrome (every template includes via <outpost-include>):
  themes/{active}/partials/header.html
  themes/{active}/partials/footer.html
```

When a developer creates a new channel via the admin UI, the channel scaffolder writes `single-{slug}.html` and `archive-{slug}.html` starter files into the active theme directory if they don't exist. The starter content is minimal — `<outpost-include partial="header" />`, the body, `<outpost-include partial="footer" />`.

The routing layer in `front-router.php` resolves the template by walking the chain. Implementation: ~150 LOC change to the existing router.

---

## Track 5: Outpost admin surfaces to redesign in new language

Each surface keeps full feature parity. Redesign is visual + interaction — bring the Sites design language. Feature inventory per surface (each item must remain functional after redesign):

### Channels admin
- Create / list / edit / delete channels (custom post types)
- Field schema builder (text, richtext, image, select, repeater, relationship, etc.)
- Per-channel: slug, label (singular + plural), icon, description
- Custom item template assignment
- Items list per channel (search, filter, sort, bulk actions)

### Lodge admin (member portal)
- Member list, search, filter
- Manual member create / edit / delete / suspend
- Tier management (Free / Paid + custom)
- Subscription / billing integration touch points
- Member-only content gating rules
- Email templates for member events (welcome, password reset, expiry)

### Workflows admin
- Workflow create / list / edit / delete
- Trigger types (event-based, schedule-based, manual)
- Action types (create item, update field, send email, webhook, AI prompt, etc.)
- Conditional steps / branching
- Run history per workflow
- Retry policy + error notification config

### GraphQL Playground
- Query editor with syntax highlighting (CodeMirror)
- Schema introspection panel
- Saved queries
- Auth / API key selector

### Releases admin
- Release create / list / edit
- Bundle changes from Drafts into a Release
- Schedule / publish a Release atomically
- Rollback a Release

### Webhooks admin
- Webhook create / list / edit / delete
- Event subscription (item.created, item.updated, etc.)
- Delivery log per webhook with retry
- Secret + signing config

### Comments admin
- Comment moderation queue
- Approve / reject / spam
- Per-channel comment settings
- Threading / nesting view

### Forms admin (forms-engine)
- Form builder (fields, validation, conditional logic)
- Form submissions list per form
- Email notifications on submission
- Webhook on submission
- CSV export

### Compass admin (smart filtering)
- Compass index status
- Reindex trigger
- Search test interface

### Shield admin (security)
- IP allow/deny lists
- Login attempt log
- Rate-limit config
- 2FA enforcement settings

### Redirects admin
- Redirect create / list / edit / delete
- Source / destination / status (301/302)
- Bulk import via CSV

### Track admin (analytics)
- Page views, top pages, referrers
- Per-channel traffic
- Time-range filters

### Boost admin
- (TBD — need to inspect `boost.php` to enumerate features. Action item.)

### Editorial AI scheduler (NEW in v6)
- Schedule create / list / edit / delete
- Cron expression or natural-language schedule
- Prompt template (with content-context variables)
- Target action (create draft, update field, audit pages, etc.)
- Run history per schedule
- Cost tracking per run

---

## Track 6: Forge code-editor UX

Goal: "Highlight a chunk of HTML in the code editor, right-click, Forge it. The whole region becomes editable in one pass — multiple `data-outpost` markers, repeater detection, section comments separating logical blocks."

**Substrate (already built):**
- `php/smart-forge.php` — DOMDocument-based scanner with `smart_forge_scan`, section detection, repeater detection, role detection, CSS-selector generation. Handles single-element analysis.
- `php/forge-analyze.php` — analysis runner.

**Missing (Track 6 work):**
- Selection-aware right-click in the admin's CodeMirror editor → calls `smart_forge_scan` on the selection only, not the full document.
- Multi-element output formatting: insert all detected `data-outpost` markers + section-boundary HTML comments + repeater wrappers in one diff.
- Inverse operation: "un-forge" a region (strip Outpost markers) for refactoring.
- Preview pane showing the forged result side-by-side with the original.

---

## Editorial AI scheduler (cross-cuts Track 3 + 5)

The scheduler is the marquee differentiator. Spec:

- **Schedule definition:** stored in `editorial_jobs` table. Fields: `id`, `name`, `cron_expression`, `prompt`, `model`, `target_action`, `target_params`, `enabled`, `last_run`, `last_run_status`.
- **Execution:** PHP cron task (or external cron hitting an internal endpoint) walks `editorial_jobs WHERE enabled = 1 AND next_run_at <= now`. For each job, calls Ranger with the prompt, executes the target action (e.g. `create_item` for "draft a blog post on X"), records run history.
- **Target actions:** any existing Ranger tool. Most common: `create_item` (drafts a post), `update_item` (refreshes existing content), `search_content` + `update_item` (audit + fix), webhook dispatch.
- **Cost ceiling:** per-job and per-day spending caps. When hit, job is paused with admin notification.
- **Prompt context variables:** `{site_name}`, `{brand_voice}`, `{recent_items[10]}`, `{channel:{slug}}`, etc. Injected before prompt is sent.

---

## Open questions (deferred until they block work)

1. **Boost admin feature list** — need to read `php/boost.php` to inventory before redesigning.
2. **Forge audit on messy real-world HTML** — ideally test smart-forge on Webflow export, Framer export, ChatGPT-Tailwind, hand-coded, WordPress theme. Not a hard prerequisite but reduces risk in Track 6.
3. **Cost-control admin UI for Ranger / Editorial AI** — needs UX design beyond "scheduler config." A monthly budget view, a "Ranger spent X on Y this week" report.
4. **Public vs agency scope (deferred)** — the v6 build assumes the *product* is for both Tony's agency clients and a future public market. Marketing site, billing, support tier are deferred until v6 is feature-complete.

---

## What to do next (whoever picks this up)

If you're an agent freshly assigned to this build, the immediate next steps are:

1. Read this entire document.
2. Check which track is yours from the Six tracks table.
3. Read the relevant section in detail.
4. Look at the existing code in the relevant area before making changes.
5. Honor the ground rules — especially "no feature removal."
6. When you finish work for a track, update this document with status and any decisions made.

Schema reconciliation (above) is settled and ready for implementation. MCP tool list (Track 3) is specified. Template hierarchy (Track 4) is specified. Tracks 1, 2, 5, 6 require Sites' design components and will be detailed by Sites in follow-up artifacts.
