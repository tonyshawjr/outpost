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

## Build & Deploy (Local Development)
- Build: `npm run build` (outputs to `php/admin/`)
- Deploy to test-site: copy `php/admin/*` and changed PHP files to `test-site/outpost/`
- Preserve `test-site/outpost/data/`, `uploads/`, `cache/` directories
- Always copy `index.html` after build — it references hashed asset filenames that change every build

## API Pattern
- REST API at `api.php?action=<endpoint>` with session auth + CSRF
- Database migrations use `PRAGMA table_info` + `ALTER TABLE ADD COLUMN`
- JSON columns for flexible data (schema, data, blocks)

## Git & Release Workflow

**Repository:** `https://github.com/tonyshawjr/outpost.git` (branch: `main`)

### Development cycle
1. Make changes in `php/` (backend) and `src/` (Svelte admin SPA)
2. Test locally: `npm run dev` for Svelte HMR, `php -S` for backend
3. Build: `npm run build`
4. Deploy to test-site for final testing (see deploy steps in MEMORY.md)
5. After every feature/fix, follow the "After Every Feature" checklist above

### Release a new version
```bash
# 1. Version is already bumped (done in "After Every Feature" step)
# 2. Build + package
npm run build
npm run package          # produces dist/outpost/ + dist/outpost-vX.X.X.zip

# 3. Commit and push
git add -A
git commit -m "v1.0.0-beta.XX — Description of changes"
git push origin main

# 4. Tag and push the tag
git tag -a v1.0.0-beta.XX -m "v1.0.0-beta.XX — Description"
git push origin v1.0.0-beta.XX

# 5. Create GitHub Release
gh release create v1.0.0-beta.XX dist/outpost-v1.0.0-beta.XX.zip \
  --title "v1.0.0-beta.XX" \
  --notes "Changelog entry here"
```

### What goes in the repo (tracked)
- `php/` — all PHP source files + themes + docs
- `src/` — Svelte 5 source code
- `test-site/` — test deployment (minus data/uploads/cache)
- `scripts/` — build tooling
- Config files: `package.json`, `vite.config.js`, `CLAUDE.md`, etc.

### What stays out of the repo (.gitignore)
- `node_modules/`, `dist/`, `php/admin/` — build artifacts
- `*.db`, `*.db-shm`, `*.db-wal` — all database files
- `test-site/outpost/data/`, `uploads/`, `cache/` — user data
- `Outpost Website/` — deployed production copy
- `memory/`, `.claude/` — local Claude Code tooling

### Auto-updater (built into admin)
Live Outpost sites can check for and apply updates from the admin Settings page:
- **Check**: `GET api.php?action=updates/check` — compares `OUTPOST_VERSION` against latest GitHub Release
- **Apply**: `POST api.php?action=updates/apply` — downloads release zip, extracts core files only
- **Never touches**: `themes/` (user themes), `data/` (database), `uploads/` (media), `cache/` (cleared after update)
- **Safe files** (overwritten on update): all `.php` in root, `admin/`, `docs/`, `member-pages/`, `tools/`
- Database migrations run automatically on next request after update
