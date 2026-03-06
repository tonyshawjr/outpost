# Outpost CMS — Agent Instructions

## After Every Feature
When you complete a new feature, fix, or significant change:

1. **Update `FEATURES.md`** (root directory — this is the only FEATURES.md, do not create one in `php/`). Add a concise entry under the appropriate section.
2. **Bump the version** — increment the patch version by 1 (e.g. `1.0.0-beta.1` → `1.0.0-beta.2`) in both:
   - `package.json` (`"version"` field)
   - `php/config.php` (`OUTPOST_VERSION` constant)
   - The admin footer (`watermark-version-pill` in `App.svelte`) reads from `OUTPOST_VERSION` via the API — updating both `php/config.php` AND `test-site/outpost/config.php` is required for the footer to reflect the new version.
3. **Update `CHANGELOG.md`** — add the new version block at the top using Keep a Changelog format. Each entry is one concise line under `Added`, `Changed`, `Fixed`, or `Security`.
4. **Update `ROADMAP.md`** — if the feature was on the roadmap, mark it as shipped. If it changes the direction or scope of a planned item, update accordingly.
5. **Update developer docs** (`php/docs/`) if the feature affects a documented area — for example, new API endpoints go in `api/admin-api.html`, content management changes go in the relevant `content/*.html` page. Match the existing HTML structure and style. Deploy updated docs to `test-site/outpost/docs/` as well.
6. **Update `php/docs/llms.txt`** — if any developer docs were added or changed, update `llms.txt` with the same information in plain-text/markdown format. This is the LLM-readable reference for the entire CMS. Deploy to `test-site/outpost/docs/llms.txt` and `dist/outpost/docs/llms.txt` as well.

## Tech Stack
- PHP 8.x + SQLite (PDO), no Composer dependencies
- Svelte 5 with runes syntax ($state, $derived, $effect, $props) — NOT Svelte 4
- Vite 6, base must be `'./'`
- TipTap for richtext, SortableJS for drag reorder

## Svelte Rules
- Use Svelte 5 runes only. No `export let`, no `$:`, no `on:click`.
- Event modifiers are invalid — use `onclick={(e) => { e.stopPropagation(); }}` not `onclick|stopPropagation`
- Use `$state()`, `$derived()`, `$effect()`, `$props()` for reactivity

## Design Principles
- Ghost/Linear minimal aesthetic — flat, modern SaaS
- Very few lines, very few colored backgrounds, minimal stroke
- Borderless inputs (border only on hover/focus)
- Tiny 11px uppercase muted labels, no card wrappers
- No colored background pills — use plain text links/toggles
- Content-first: large serif titles, clean whitespace

## Build & Deploy
- Build: `npm run build` (outputs to `php/admin/`)
- Deploy to test-site: copy `php/admin/*` and `php/api.php` to `test-site/outpost/`
- Preserve `test-site/outpost/data/`, `uploads/`, `cache/` directories

## API Pattern
- REST API at `api.php?action=<endpoint>` with session auth + CSRF
- Database migrations use `PRAGMA table_info` + `ALTER TABLE ADD COLUMN`
- JSON columns for flexible data (schema, data, blocks)
