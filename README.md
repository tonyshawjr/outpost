# Outpost CMS

A lightweight, self-hosted headless CMS built for developers. PHP 8 + SQLite backend, Svelte 5 admin panel, data-attribute template engine (with legacy Liquid syntax support). No Composer, no npm on the server — drop it in and it runs.

---

## What It Is

Outpost gives you a full content management system that installs into any PHP host as a single directory (`/outpost/`). It includes:

- A **Svelte 5 admin panel** for managing content, media, users, and sites
- A **PHP template engine** (data-attribute v2, with legacy Liquid syntax) for rendering sites
- A **public Content API** for headless/JAMstack use
- A **member system** for front-end gating of content
- A **developer toolset** (Template Reference, Code Editor, Site Manager)

The goal: something a developer can drop into a client site, hand off to a non-technical editor, and never have to explain WordPress to anyone again.

---

## Stack

| Layer | Tech |
|---|---|
| Backend | PHP 8.x, SQLite (PDO), no Composer |
| Frontend | Svelte 5 (runes: `$state`, `$derived`, `$effect`) |
| Build | Vite 6 |
| Rich text | TipTap |
| Drag reorder | SortableJS |
| Code editor | CodeMirror 6 |

---

## Directory Structure

```
cms/
├── src/                    # Svelte 5 SPA source
│   ├── pages/              # Route components (Dashboard, PageEditor, etc.)
│   ├── components/         # Shared UI (Sidebar, TopBar, RightSidebar, etc.)
│   ├── lib/                # api.js, stores.js, utils.js
│   └── styles/             # admin.css (global), tokens + site vars
│
├── php/                    # PHP backend source (deploy from here)
│   ├── api.php             # REST API entry point
│   ├── content-api.php     # Public headless Content API
│   ├── auth.php            # Session auth + CSRF
│   ├── engine.php          # Liquid-style template compiler
│   ├── roles.php           # Role/capability definitions
│   ├── sites/              # Default starter site
│   └── admin/              # Built Svelte SPA (output of npm run build)
│
├── test-site/              # Local dev test environment
│   └── outpost/            # Live copy of php/ for testing
│
├── CLAUDE.md               # Agent instructions (tech rules, deploy steps)
├── FEATURES.md             # Feature log — update after every change
└── README.md               # This file
```

---

## Local Development

You need two processes running:

**1. PHP test server** (handles API calls and serves the admin shell):
```bash
cd cms
php -S localhost:8080 -t test-site
```
Admin is at: `http://localhost:8080/outpost/`

**2. Vite dev server** (hot-reload for Svelte changes, proxies API to :8080):
```bash
cd cms
npm run dev
```
Dev admin is at: `http://localhost:5173` (or whatever Vite assigns)

---

## Build & Deploy

```bash
# Build Svelte SPA
cd cms
npm run build
# Output: php/admin/

# Deploy built admin to test environment
cp -R php/admin/. test-site/outpost/admin/

# Deploy PHP files to test environment (when PHP changes)
cp php/content-api.php test-site/outpost/content-api.php
# (etc. for other PHP files)
```

For production, copy the entire `php/` directory contents to your server's `/outpost/` directory. Preserve `data/`, `uploads/`, and `cache/` between deploys.

---

## Key Concepts

### Routing
The admin is a Svelte SPA. Routes are managed via `currentRoute` store in `src/lib/stores.js`. Navigation calls `navigate('route-name')`. Route components live in `src/pages/`.

### API Pattern
All endpoints: `api.php?action=<endpoint>` with session auth + CSRF token.
Public Content API: `api.php?action=content/<endpoint>` — no auth, wide-open CORS.

### Template Engine
Site templates use `{{ variable }}`, `{% for %}`, `{% if %}`, `{% include %}` syntax compiled to PHP by `engine.php`. Templates compile once and cache in `cache/templates/`. Cache clears on save.

### Field Keys
Collection fields from the Content API return `{ name: "", label: "title", type: "text" }` — `name` is always empty, `label` is the actual identifier. Use `field.label || field.name` in frontend code.

### Content API (`content-api.php`)
Read-only public endpoints. Key ones:
- `content/schema` — full type graph
- `content/items&collection=slug` — published items with fields + terms
- `content/pages` — page fields
- `content/syntax` — template engine syntax reference (maintained here, not in frontend)

### Roles & Permissions
6 roles: `super_admin`, `admin`, `developer`, `editor`, `free_member`, `paid_member`.
Capability-based: `canAccessCodeEditor` gates Template Reference and Code Editor. Permission checked both in PHP (`outpost_require_cap()`) and in the Svelte route layer.

### Layout Pattern — No Right Sidebar
Routes that need full content width (Template Reference, Code Editor) add `no-right-sidebar` class to `.app-layout`. This collapses the 320px right column to 0. Set in `App.svelte` via `class:no-right-sidebar={...}`.

---

## Svelte Rules (important)

- Svelte 5 runes only — no `export let`, no `$:`, no `on:click`
- Use `$state()`, `$derived()`, `$effect()`, `$props()`
- Event handlers: `onclick={handler}` not `on:click={handler}`
- No event modifiers — use `onclick={(e) => { e.stopPropagation(); }}`

---

## Adding New Template Engine Features

1. Implement the feature in `php/engine.php`
2. Add documentation rows to `handle_content_syntax()` in `php/content-api.php`
3. Deploy both files — the Syntax tab in Template Reference auto-updates from the API, no frontend rebuild needed

---

## Feature Log

See `FEATURES.md` for a running log of all implemented features. Update it after every meaningful change — it's used to generate documentation.
