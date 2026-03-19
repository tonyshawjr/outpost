# Outpost CMS — Agent Instructions

## After Every Feature
When you complete a new feature, fix, or significant change:

1. **Update `FEATURES.md`** (root directory — this is the only FEATURES.md, do not create one in `php/`). Add a concise entry under the appropriate section.
2. **Bump the version** — increment the patch version by 1 (e.g. `1.0.0-beta.1` → `1.0.0-beta.2`) in both:
   - `package.json` (`"version"` field)
   - `php/config.php` (`OUTPOST_VERSION` constant)
   - The admin footer (`watermark-version-pill` in `App.svelte`) reads from `OUTPOST_VERSION` via the API — updating both `php/config.php` AND `test-site/outpost/config.php` is required for the footer to reflect the new version.
3. **Update `CHANGELOG.md`** — add the new version block at the top using Keep a Changelog format. Each entry is one concise line under `Added`, `Changed`, `Fixed`, or `Security`.
4. **Update `php/docs/changelog.html`** — add the same version block to the developer docs changelog. This is the user-facing changelog at `/outpost/docs/changelog.html`. Match the existing HTML structure. **This is mandatory for every release, not just features that affect docs.**
5. **Update `ROADMAP.md`** — mark the feature as shipped with strikethrough and **Shipped** label. **This is mandatory for every release.** Also update `php/docs/roadmap.html` to match — this is the user-facing roadmap at `/outpost/docs/roadmap.html`. Both files must stay in sync. If the feature changes the direction or scope of a planned item, update that too.
6. **Update developer docs** (`php/docs/`) if the feature affects a documented area — for example, new API endpoints go in `api/admin-api.html`, content management changes go in the relevant `content/*.html` page. Match the existing HTML structure and style. For major features, create a dedicated docs page (e.g., `php/docs/features/ranger.html`). Deploy updated docs to `test-site/outpost/docs/` as well.
7. **Update `php/docs/llms.txt`** — if any developer docs were added or changed, update `llms.txt` with the same information in plain-text/markdown format. This is the LLM-readable reference for the entire CMS. Deploy to `test-site/outpost/docs/llms.txt` and `dist/outpost/docs/llms.txt` as well.
8. **Always create a GitHub Release** — after committing and pushing, ALWAYS tag the version and publish a GitHub Release with the packaged zip. Without a release, the auto-updater cannot see the new version. Run:
   ```bash
   npm run build
   npm run package
   git tag -a vX.X.X -m "vX.X.X — Description"
   git push origin vX.X.X
   gh release create vX.X.X dist/outpost-vX.X.X.zip --title "vX.X.X" --notes "Changelog entry"
   ```
   **This is not optional.** Every version bump must have a corresponding GitHub Release or the update system is broken.

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

### How the auto-updater works (end-to-end)

The admin panel has a built-in updater at **Settings → Updates**. Here's the full flow:

**Developer side (us):**
1. Finish feature/fix → follow "After Every Feature" checklist (version bump, changelog, etc.)
2. `npm run build` → builds admin SPA to `php/admin/`
3. `npm run package` → creates `dist/outpost/` directory + `dist/outpost-vX.X.X.zip` (also generates `.outpost-manifest.json` for managed themes)
4. Commit, push, tag, then create a **GitHub Release** with the zip attached:
   ```bash
   git add -A && git commit -m "v1.0.0-beta.XX — Description"
   git push origin main
   git tag -a v1.0.0-beta.XX -m "v1.0.0-beta.XX"
   git push origin v1.0.0-beta.XX
   gh release create v1.0.0-beta.XX dist/outpost-v1.0.0-beta.XX.zip \
     --title "v1.0.0-beta.XX" --notes "Changelog here"
   ```
5. The GitHub Release is now live. All Outpost sites can see it.

**Live site side (automatic):**
1. Admin visits **Settings → Updates** → UI calls `GET api.php?action=updates/check`
2. PHP fetches `https://api.github.com/repos/tonyshawjr/outpost/releases/latest` (cached 1 hour)
3. Compares `OUTPOST_VERSION` (from `config.php`) against the release tag using `version_compare()`
4. If newer version exists, UI shows update button with release notes
5. Admin clicks **"Update now"** → UI calls `POST api.php?action=updates/apply` with the zip URL
6. PHP downloads the zip, extracts to a temp directory, then copies **only core files**:
   - All `.php` files in the outpost root (api.php, engine.php, config.php, etc.)
   - `admin/` directory (entire SPA rebuild)
   - `docs/` directory
   - `member-pages/` directory
   - `tools/` directory
7. PHP updates **managed themes** in `content/themes/` (v1.9.0+):
   - Themes with `"managed": true` in `theme.json` (Personal, Starter) are eligible
   - Uses `.outpost-manifest.json` (MD5 hashes of shipped files) for conflict detection
   - Files the user hasn't modified → replaced with new version
   - Files the user HAS modified → **skipped**, flagged as conflicts in the response
   - New managed themes in the zip → installed automatically
   - Non-managed themes (user-created or duplicated) → **never touched**
8. PHP clears `cache/templates/*.php` so recompiled templates pick up engine changes
9. Admin SPA reloads automatically — now running the new version. If theme updates occurred, results are shown first.

**What the updater NEVER touches (user data):**
- `themes/` — user-created and duplicated themes (only managed themes with `"managed": true` are updated, and even then user-modified files are preserved)
- `data/` — SQLite database (`cms.db`)
- `uploads/` — user media files
- `cache/` — cleared after update, rebuilds automatically

**Database migrations** run automatically on the next request after update — the API bootstrap checks for missing columns/tables and applies schema changes.

**Key constant:** `OUTPOST_GITHUB_REPO` (defined in `api.php`) = `'tonyshawjr/outpost'` — change this if the repo ever moves.

**For sites that don't have the updater yet:** Manually copy the latest core PHP files + `admin/` directory to their `outpost/` folder. Once they have the updated `api.php` with the updater endpoints, they can use Settings → Updates going forward.
