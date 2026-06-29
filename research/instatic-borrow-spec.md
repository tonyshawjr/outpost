# Instatic → Outpost: Borrow Spec

**Status:** Planning / source-of-truth. No code yet.
**Date:** 2026-06-26
**Author of source product:** David Babinec (CoreBunch) — team behind Motion.page + Core Framework.
**Source repo studied:** https://github.com/CoreBunch/Instatic (v0.0.6, MIT, Bun + React 19).
**Goal:** Take *everything good* from Instatic's editing experience and bring it into Outpost (PHP 8 + SQLite + Svelte 5) **alongside** what Outpost already has — not a rewrite.

> Tony's directive: "Take all the good stuff." Outpost stays a **content manager AND a page builder.** This doc is the map for how.

---

## 0. TL;DR — the one decision everything hangs on

Outpost today edits content two ways: **form fields bound to `data-outpost` attributes** in flat theme templates, and a **list-based block builder** (`page_blocks`, ordered, drag-to-reorder via SortableJS, fields edited in a side form). There is **no node tree, no nesting, no visual canvas, no class system.**

Everything Tony loved in the screenshots — the layers panel, the class-based style panel, Componentize, the selectors panel, the Figma 3-breakpoint canvas — **all assume a node tree underneath.** You cannot bolt the *behavior* onto a flat block list; you can only bolt on the *look*.

**The decision: introduce a node-tree page model as a new spine, and re-cast Outpost's existing "blocks" as "Visual Components" (reusable subtrees with fields).** This preserves 100% of what Outpost has, unifies it with the new builder, and is exactly the model Instatic proves works (their page tree + Visual Components + `data_tables` content model all coexist in one store).

This doc assumes that decision. Section 3 defends it and shows the coexistence path.

---

## 1. Side-by-side: where each product stands

| Capability | Instatic | Outpost today | Gap |
|---|---|---|---|
| Stack | Bun + TS + React 19 | PHP 8 + SQLite + Svelte 5 | (different, fine) |
| Page model | Node tree (`NodeTree<BaseNode>`) | Flat ordered `page_blocks` + flat `data-outpost` templates | **node tree missing** |
| Visual canvas | Multi-breakpoint iframes, Figma-like | List UI + single preview iframe | **canvas missing** |
| Layers panel | Tree w/ tag badges + classes | none | **missing** |
| Styling | Class-based + Core Framework tokens→utilities | CSS vars + Tailwind CDN, no class authoring | **class system missing** |
| Reusable pieces | Visual Components (typed params, slots) | Theme blocks (flat HTML + fields) | **blocks ≈ components, need nesting+params** |
| Content model | One universal `data_tables`/`data_rows` | Specific tables (`pages`, `collections`, `collection_items`, `page_blocks`) | fine as-is; keep |
| Publisher | Bake-to-disk + auto dynamic "holes" | PHP server-render per request | optional later |
| AI in-app | ~35-tool agent editing the canvas | Ranger + Editorial AI (content), MCP server exists | **builder-aware agent missing** |
| MCP for external agents | (their agent is internal) | `php/mcp.php` already exists (content CRUD) | **extend to builder ops** |

**Punchline:** Outpost already owns the content-manager half and even has AI + an MCP server. The missing half is the **visual page-builder spine** and the **class-based style system** that the screenshots are all expressions of.

---

## 2. The borrow catalog — every screenshot, mapped

Each item: what it is in Instatic → Outpost status → what to build → effort (S/M/L/XL).

### 2.1 Layers panel (shots 1, 2, 4, 7) — *Tony: "It's beautiful. I love the colors."*
- **What it is:** tree view of the node tree. Each row = `[tag badge] [type] [.class]`. Badge color keyed to HTML tag (`body`/`main`/`section`/`img`/`div`/`header`). Search, expand/collapse, hover→highlight on canvas, double-click rename, per-row visibility/lock.
- **Outpost today:** none (block list is flat, no hierarchy).
- **Build:** Svelte tree component over the node tree. Tag→color map. Wire selection to a shared `selectedNodeId` store; hover to canvas highlight.
- **Effort:** **S** for the panel itself once the node tree exists (2.X). The *colors/polish* are a styling pass — cheap, high payoff.
- **Depends on:** node tree (§3).

### 2.2 Floating "island" toolbar + per-node action bar (shots 1, 2, 5, 7) — *Tony: "a little island up there… play button, eyeball, code button."*
- **What it is:** two things. (a) The top floating island = **mode switcher**: select tool, live/preview eyeball, `</>` code view, device/breakpoint toggles. (b) The toolbar that appears *on a selected node* (grab / grid / duplicate / trash pills) = per-node quick actions.
- **Outpost today:** PageBuilder has Save/Publish/status but no floating mode island, no per-node action bar.
- **Build:** floating toolbar components; bind to editor mode + selection. Pure UI.
- **Effort:** **S–M.** Big polish-to-effort ratio.

### 2.3 Top workspace bar (shots 1, 6) — Dashboard / Site / Content / Data / Media / Plugins / Users
- **What it is:** primary nav across the whole admin. "Site" = the builder; "Content" = the writing/CMS surface; "Data" = custom tables; rest self-explanatory.
- **Outpost today:** has Dashboard, Collections, PageBuilder, Media, Globals, Navigation, Settings, EditorialAI, Design, Brand. Close already — different labels.
- **Build:** mostly **rename/reorganize** to the cleaner top-bar IA. Add a "Data" workspace only if/when custom tables land (optional).
- **Effort:** **S** (IA/labels) + optional **L** (Data workspace, later).

### 2.4 Class-based style panel + Componentize (shots 2, 4, 5) — *Tony: "class-based… create classes, manage classes, componentize… all the CSS on the side."*
- **What it is:** the page-builder core. Select a node → **"Add or create selector"** + **Componentize** button → stacked class chips (`.glass-panel` `.hero-card`, removable, cascade-ordered) → full CSS controls (Flex/Grid toggle, columns/rows, align/justify, gap, position, size, overflow, z-index). **You edit a class, not a node** — classes stack on nodes.
- **Outpost today:** none. Styling is theme CSS files + CSS vars + Tailwind CDN. No in-admin class authoring, no per-node style editing.
- **Build:** (1) a **class registry** (named style rules: selector + declarations, "assigned" vs "ambient"); (2) `node.classIds[]` ordered assignment; (3) the right-panel control set that reads/writes the active class's declarations; (4) Componentize = extract selected subtree + its rules into a Visual Component.
- **Effort:** **XL.** This is the heart of the work and the most valuable. Tackle after the node tree + a basic canvas exist.
- **Depends on:** node tree, class registry, CSS emission pipeline.

### 2.5 Selectors panel (shot 7) — *Tony: "go into each portion of your selectors and change out."*
- **What it is:** the class library. Tabs All / User / Utility / Unused. Per class: "Used N times", **AMBIENT** badge (matches by context vs. explicitly assigned), right-click → Edit / Rename / Duplicate / Apply to selected / Remove / Copy selector. Fast search.
- **Outpost today:** none.
- **Build:** management UI over the class registry from 2.4. "Unused" = no node references it; "Ambient" = rule whose selector matches by descendant/context, not via `classIds`.
- **Effort:** **M** (once the class registry exists).
- **Depends on:** 2.4.

### 2.6 Color token panel / Core Framework (shot 8) — *Tony: "core framework built in."*
- **What it is:** design tokens. One color (`--moss #55613f`) + toggles: Text/Background/Border/Fill utility generation, Transparent variants, Generate shades, Generate tints. One token → a whole utility scale. Plus fluid `clamp()` type + spacing scales.
- **Outpost today:** CSS vars in `variables.css` + a Design/Brand panel that injects brand colors/fonts at runtime. **Partial** — has tokens, lacks the generate-scale + utility-class machinery.
- **Build:** a token model + generator that emits `--color-<slug>`, shade/tint/transparent variants (HSL math), and optional utility classes into a generated stylesheet. Mirror Instatic's `buildFrameworkPlan()` conceptually in PHP (publish) and JS (canvas preview).
- **Effort:** **L.** Outpost's existing Design panel is a real head start.
- **Note:** Core Framework is *their* product. Borrow the *idea/UX*, build your own generator. (It's also a WP/ACSS-adjacent thing Tony already knows well.)

### 2.7 3-breakpoint Figma canvas + live mode (shots 4, 5) — *Tony: "Figma look… three breakpoints… live mode zooms into that breakpoint."*
- **What it is:** each breakpoint is its own **iframe**, rendered side-by-side, pan/zoom (shot 4 at 46%). Iframes give real CSS isolation + media queries + units that match the published page. "Live mode" = one full-width iframe at 100% for direct editing (shot 5). Selection rings live *outside* the zoom transform so they stay 1px at any zoom.
- **Outpost today:** PageBuilder has a single preview iframe (renders `/pages/blocks/render`). No multi-breakpoint, no pan/zoom, no in-canvas selection.
- **Build:** render the node tree into N iframes (one per breakpoint), a transform layer for pan/zoom, a selection/hover overlay portaled to canvas-root coordinates, click-to-select wired to the layers panel + style panel. **Svelte-in-iframe is very doable.**
- **Effort:** **XL.** The canvas is the second-biggest lift after the class system. Can start with one breakpoint, add the multi-frame view later.
- **Depends on:** node tree.

### 2.8 Content workspace (shot 6) — Outpost is already here
- **What it is:** Collections (Posts/Portfolio), a post with featured media, SEO title/desc, slug, status, author, public URL.
- **Outpost today:** **already has this** — collections, collection items, fields, SEO, status, media. Arguably ahead of Instatic.
- **Build:** mostly a **visual polish pass** to match the cleaner Instatic chrome; keep the engine.
- **Effort:** **S** (polish).

### 2.9 AI "Describe what you want to build" sidebar (shot 3) — *Tony: "connect it to OpenAI, local models, anything."*
- **What it is:** a builder agent. Prompt box ("Add a hero section with a heading and button"), model picker with BYO-key (OpenAI o3/o1/GPT-5.5 shown, with per-token pricing), and **the agent edits the canvas as real, undoable nodes** via ~29 browser tools that flow HTML through the same import pipeline as a human paste.
- **Outpost today:** **Ranger** (AI assistant) + **Editorial AI** (scheduled content jobs) exist, but they're content-oriented, not builder-aware. No node-mutation tool surface.
- **Build:** a tool layer that mutates the node tree (insert/replace HTML→nodes, applyClass, moveNode, set tokens, page/template ops), a provider-agnostic driver (BYO key — Outpost already stores AI creds), and a sidebar UI. **Reuse the same mutation layer for the MCP (§2.10).**
- **Effort:** **L** (the agent UI + driver) on top of **the mutation layer** which you build once.
- **Depends on:** node tree + mutation API (§3) + class system.

### 2.10 Outpost MCP for Claude Code (Tony's #1 want) — *"a full-on MCP so Claude Code can get in there and build."*
- **What it is:** the *external* twin of 2.9. Instatic's agent is internal; **Outpost already ships `php/mcp.php`** (JSON-RPC over HTTP, Bearer/API-key auth, content CRUD). Extend it with **builder mutation tools** so Claude Code drives the page builder from the terminal — the exact pattern Tony already runs with **Etch Bridge** (terminal → REST queue → live builder) and **Novamira**.
- **Outpost today:** MCP exists for content ops. No builder/node ops.
- **Build:** expose the same node-tree mutation API as MCP tools: `insert_block`, `apply_class`, `set_field`, `move_node`, `set_token`, `componentize`, `list_nodes`, `read_node`, `publish`. **One mutation layer, two front doors** (in-app sidebar + MCP).
- **Effort:** **M** once the mutation layer exists (MCP server is already there to extend).
- **Strategic note:** this is the highest-leverage borrow. Build the mutation API as the contract; the UI agent and the MCP are both thin clients of it. Mirrors how Etch Bridge already works in Tony's stack.

---

## 3. The spine: node-tree model (the enabling work)

Everything in §2.1–2.9 depends on a node tree. Build it first.

### 3.1 Model
- A **node** = `{ id, type, tag, props, classIds[], children[], dynamicBindings?, inlineStyles? }`.
  - `type` = module kind (`container`, `text`, `image`, `button`, `link`, `list`, `svg`, `form*`, `loop`, `component-ref`, `outlet`). Mirrors Instatic's `base.*` modules.
  - `tag` = the HTML element (so the layers badge + published DOM are honest).
- A **page** = a tree of nodes (root `body`).
- Store as JSON. Two options:
  - **(A)** new `page_nodes` table, or
  - **(B)** reuse `page_blocks.fields` to hold a tree JSON per page (one root "block").
  - **Recommend (A)** for clarity, but it's a cheap call either way; JSON-in-SQLite is already the Outpost pattern.

### 3.2 Blocks → Visual Components (the unification)
- Outpost's **theme blocks** (flat HTML + `data-outpost` fields + scoped CSS) map almost 1:1 onto Instatic **Visual Components** (reusable subtree + typed params + slots).
- Migration path: a block's `.html` is parsed (via an HTML→nodes importer, §3.4) into a node subtree; its `data-outpost` fields become **typed component parameters**; its CSS becomes class rules. Existing blocks keep working; new ones are authored visually + Componentized.
- This means **nothing Outpost has is thrown away** — blocks get *more* powerful (nesting, params, slots), and the library Tony already built carries forward.

### 3.3 Mutation API (the contract — build once, reuse everywhere)
A single set of tree operations, called by: the canvas, the layers panel, the AI sidebar, **and** the MCP. Names mirror Instatic's tool surface:
`insertNode, deleteNode, updateNodeProps, moveNode, duplicateNode, renameNode, wrapNode, setBreakpointOverride, assignClass, removeClass, applyCss, insertHtml, componentize, set*Tokens, addPage, setPageTemplate`.
- Patch-based **undo/redo** (Instatic uses Mutative patches; in Svelte, capture inverse patches per mutation). This is the thing Outpost lacks today (only dirty flags) and the builder needs it.

### 3.4 HTML ↔ nodes importer (force multiplier)
- `importHtml(html) → nodes` (strip `<script>`/`on*`, harvest inline + `<style>` CSS, map every element to a module, link classes by name). This powers: **paste-HTML**, **Super Import** (whole static site), **AND the AI agent's output** (agent writes HTML → same pipeline → editable nodes). Build it once; it pays off three times.

### 3.5 Publisher
- Outpost server-renders per request in PHP today — that's fine and can stay. The node tree → HTML/CSS emitter is the new piece (one function, pure, shared by canvas preview and publish).
- **Optional later:** adopt Instatic's bake-to-disk + auto "dynamic holes" for speed. Not required for parity; park it.

---

## 4. Class-based styling system (the other big pillar)

This is what makes the right-hand style panel real. It's independent enough to call out separately.

- **Class registry:** named rules `{ id, selector, kind: 'class'|'ambient', declarations, contexts[] }`.
- **Assignment:** `node.classIds[]`, ordered; later wins (cascade).
- **Ambient rules:** match by selector/descendant, shown as non-removable chips.
- **Emission:** one CSS writer, deduped per class, shared by canvas preview (JS) and publish (PHP). Cascade order: reset → framework/tokens → classes → user styles.
- **Tokens → utilities:** §2.6 generator feeds this (utility classes are "locked," publisher-authored).
- **Replaces Tailwind-CDN reliance** with an authored, owned system — better for clean output and for the "edit a class, see it everywhere" UX.

Effort: **XL**, but it's the differentiator. Sequence it right after a minimal canvas exists so you can see classes apply live.

---

## 5. Phased roadmap

Each phase ships something usable. Don't build the whole thing before shipping.

- **Phase 0 — IA + polish (S, fast win, no architecture):** rename/reorg top bar to Dashboard/Site/Content/Data/Media/Plugins/Users; restyle existing PageBuilder + content workspace to the Instatic chrome; add the floating mode island as cosmetic. Tony *sees* the direction immediately. *(Optional: ship the layers-panel look over the current flat block list as a teaser.)*
- **Phase 1 — node-tree spine (XL):** node model + storage + mutation API + patch undo + HTML↔nodes importer + node→HTML emitter. Migrate one theme block to a Visual Component to prove the path. No new UI required to land this; it's the engine.
- **Phase 2 — canvas + layers (XL):** single-breakpoint iframe canvas with click-to-select, the layers panel (real, over the tree), per-node action bar. This is the first "wow."
- **Phase 3 — class system + selectors panel + style panel (XL):** class registry, `classIds`, the right-panel CSS controls, Componentize, selectors panel. The core page-builder experience lands here.
- **Phase 4 — tokens / Core-Framework-style generator (L):** color tokens + shade/tint/transparent + fluid type/spacing scales + utility generation, wired into the style panel (shot 8).
- **Phase 5 — multi-breakpoint + live mode (L):** side-by-side frames, pan/zoom, breakpoint overrides, live mode.
- **Phase 6 — AI sidebar + MCP builder tools (L+M):** point the AI sidebar and the existing `php/mcp.php` at the Phase-1 mutation API. BYO-key driver. Claude Code can now build in Outpost. *(Could pull earlier — once Phase 1's mutation API exists, the MCP tools can ship before the fancy canvas, since they don't need UI.)*

> **Sequencing nuance:** the MCP (Tony's favorite) only needs **Phase 1** (the mutation API), not the canvas. If MCP-first matters more than visuals-first, do Phase 0 → Phase 1 → Phase 6(MCP half) before the canvas. Flagging because it changes the order.

---

## 6. Honest compromises / risks

- **This is months, not weeks.** Phases 1–3 are each XL. The node tree + class system are real engines. Phase 0 buys visible progress while the engine is built.
- **PHP vs Bun:** Outpost server-renders in PHP; Instatic emits TS both sides. The node→HTML emitter must exist twice (PHP for publish, JS for canvas preview) unless you render canvas previews through the PHP endpoint (Outpost already does this for blocks — viable, with a latency cost).
- **No QuickJS plugin sandbox in PHP.** Instatic's killer plugin-security story (WASM sandbox) doesn't port cheaply to PHP. Park plugins; it's not in the screenshots Tony cared about.
- **Class system replaces Tailwind-CDN habits.** Migrating themes off Tailwind utilities is a content/theme job, not just code.
- **Undo/redo is new infrastructure.** Outpost has none today; the builder hard-requires it. Build it into the mutation API from day one (Phase 1), not bolted on later.

---

## 7. What NOT to rebuild (Outpost already has it)

- Block system (becomes Visual Components — evolve, don't replace).
- Collections / content model / SEO / status / media — keep; polish only.
- MCP server (`php/mcp.php`) — **extend**, don't recreate.
- AI plumbing (Ranger, Editorial AI, stored AI creds) — reuse the credential + provider layer for the builder agent.
- Design/Brand panel + CSS vars — the seed for the token generator.

---

## 7.5 Convergence: templating engine vs. node-tree builder (STRATEGIC — decide before v1 ship)

Outpost now has **two authoring modes** and they must converge, not compete:
- **Template pages** — hand-coded theme `.html` with `data-outpost` fields. "Deploys like WordPress." Devs write markup, editors fill fields.
- **Visual pages** — node-tree built in the Visual Builder. "Edits like Webflow." No hand-written theme.

**Recommendation (Tony + Claude, 2026-06-29):** NOT a separate plugin/blank-theme silo (that splits globals, nav, SEO, deploy). Instead **per-page authoring mode inside the existing theme system**:
- A page is either a Template page (coded) or a Visual page (node tree) — a flag on the page.
- A Visual page **renders its node tree into the active theme's layout** (the theme still owns head/header/footer/partials/global CSS via the templating engine). The builder only produces the page body, dropped into the theme's content slot (like an `<outpost-outlet>`).
- Result: one site, one theme, one deploy, shared globals/nav/SEO. The builder is just "design this page visually" vs "this page uses a coded template."
- The "blank/minimal theme" is a good **default** for visual-first users, not a separate system.

Front-router change required at integration time: page → if it has a node tree, render the tree (via the node-engine renderer) into the theme layout; else render the `data-outpost` template as today. Tony's instinct that the builder might "become a plugin" is directionally right about *packaging/optionality*, wrong if it means a parallel site. Park until after the builder feature-set is complete (post #9), but design the page model now so a page can carry either representation.

## 8. Open questions (answer when ready, not blocking)

1. **MCP-first or visuals-first?** (§5 sequencing nuance.) Changes whether Phase 6's MCP half jumps ahead of Phase 2.
2. **Node storage:** new `page_nodes` table vs tree-JSON in `page_blocks`. (Recommend new table.)
3. **Canvas preview rendering:** PHP endpoint (reuse current block-render path, simpler, slower) vs JS emitter in the iframe (faster, more code). (Recommend start with PHP endpoint, migrate hot paths later.)
4. **Scope of v1:** which module types ship first (container/text/image/button/link covers ~80% of the screenshots).

---

## Reference

- Source repo cloned at: `…/scratchpad/Instatic` (this session) — re-clone from GitHub if gone.
- Deep-dive notes captured this session: publisher 3-layer pipeline, ~35-tool AI agent, QuickJS plugin sandbox, universal `data_tables` content model, Core Framework token generator, class/ambient style model, multi-breakpoint iframe canvas.
- Outpost current-state map: §1, §7 above (from `php/engine.php`, `php/blocks.php`, `php/db.php`, `php/mcp.php`, `src/pages/PageBuilder.svelte`, `src/pages/Design.svelte`, `php/content/themes/starter/`).
