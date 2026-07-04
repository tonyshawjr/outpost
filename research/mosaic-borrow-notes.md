# Mosaic → Outpost: Borrow Notes

**Status:** Research / observation. **Shipped so far:** #1 theme-first picker + #2 animated import (mosaic-wizard); **#11 loop element + visual dynamic-data** (v6.0.0-beta.36, bakes to `<outpost-each>`, verified live); **#3 numbered orientation** + **#4 "Made it yours" recap** (v6.0.0-beta.36); **#9 section/pattern gallery** (v6.0.0-beta.37, live-thumbnail picker → `importSection`) + **#7 preview toggle + View live** (v6.0.0-beta.37). Editor-shell items #5/#6/#15/#17 landed via the v6 builder arc. **Remaining:** #10 template grid, #16 style-guide editor, and the interaction cluster #8/#12/#13/#14.
**Date:** 2026-07-03
**Source:** Mosaic "Getting Started" video — https://www.youtube.com/watch?v=hOKe3h3FdpM (demo theme "Monolith", plugin build `Mosaic-pro-beta-0.0.15.zip`)
**Companion doc:** [`instatic-borrow-spec.md`](./instatic-borrow-spec.md) — the source-of-truth plan this cross-references.

> Watched the full ~8-min walkthrough frame-by-frame. Mosaic is a **WordPress-native Webflow**: installs as a WP plugin, edits inside WP admin with an "Exit to WordPress" button, ships starter themes, and does class-based visual editing + loops/dynamic-data + scroll interactions. It is the closest public analog to the Instatic-borrow direction, and it validates the node-tree spine decision (Section 3 of the borrow spec). One key difference: **Mosaic has no AI sidebar** — it's pure direct manipulation. That gap is Outpost's opening.

---

## How Mosaic maps to Outpost's north star

Outpost's north star (borrow-spec §0a) is **import static HTML → theme → dynamic holes**, with the visual builder as the *secondary/optional* layer. Mosaic is almost entirely the visual-builder half — so treat these notes as **enrichment for the secondary layer + the onboarding/wizard experience**, NOT a reason to re-center on the visual builder. The highest-value steals below are the **wizard/onboarding** and the **loop + dynamic-data flow**, both of which serve the CMS story, not just the builder.

---

## The borrow list (grouped, mapped, prioritized)

Effort: S / M / L / XL. Priority: 🔥 steal first · ➋ strong · ➌ nice-to-have.

### 🧙 Onboarding / Wizard — *the standout; Tony flagged this specifically*
| # | What Mosaic does | Outpost status | Priority · Effort |
|---|---|---|---|
| 1 | **Theme-first start screen** — "Choose a theme to start" full-bleed gallery of named starter themes (Gibraltar, Monolith, BLNK, simply) with live preview thumbnails. You pick, you're building. No blank canvas. | Has themes (Personal, Starter) but no gallery-style first-run picker | 🔥 · M |
| 2 | **Animated import modal** — after picking, a progress card ticks real steps w/ checkmarks: *Connecting → Installing template → Creating necessary tables → Connecting to cloud*. Turns the wait into a trust-builder. | No first-run import ceremony | 🔥 · S |
| 3 | **On-canvas 3-step orientation** — homepage preview annotated with numbered pills **1 Setup · 2 Overview · 3 Build**. Instant mental model. | None | ➋ · S |
| 4 | **"Made it yours in minutes" recap** — closes by recapping the exact path taken; natural fit for an in-app onboarding checklist / progress tracker. | None | ➋ · M |

### 🎨 Editor shell / layout
| # | What Mosaic does | Outpost status | Priority · Effort |
|---|---|---|---|
| 5 | **Three-pane Webflow layout** — left: **Navigation tree** (full DOM/layers) + tabs *Templates / Modules / Components / Style Guides*; center canvas; right style inspector. | This is the node-tree spine already decided in borrow-spec §0/§3. Mosaic confirms the exact tab set. | (already planned) |
| 6 | **Breakpoint bar** — **Base / Tablet / Mobile** toggles + "Edit breakpoints" for custom. Base labeled "applies at all breakpoints" — clear cascade. | Borrow-spec #7 (multi-breakpoint), currently de-prioritized | ➌ · L |
| 7 | **Template ↔ Preview toggle** + **Update** button + undo/redo in top bar. One click between editing structure and seeing the rendered page. | Single preview iframe today | ➋ · S |
| 8 | **"Type / to choose a block"** empty-state + **"Edit in Mosaic"** button injected onto native pages — smooth builder↔native handoff. | Block builder exists; no slash-insert affordance | ➌ · M |

### 🧩 Insert / patterns
| # | What Mosaic does | Outpost status | Priority · Effort |
|---|---|---|---|
| 9 | **Categorized pattern picker** — full-screen library of pre-designed *sections* filtered by *Page / Hero / Featured / Content*, each a real thumbnail. Drop-and-restyle, not build-from-scratch. | Blocks exist but no visual section gallery w/ thumbnails | ➋ · M |

### 🏛 Templates + CMS — *strongest alignment with Outpost's core*
| # | What Mosaic does | Outpost status | Priority · Effort |
|---|---|---|---|
| 10 | **Template archive** — Homepage, Blog archive, Single post, Contact, 404, Archive as editable templates in one grid. "Templates work within Mosaic to display content on pages with specific rules and styling." | Outpost has templates + globals; not surfaced as a single visual grid | ➋ · M |
| 11 | **Loop element + dynamic data** — a loop pulls all blog posts; **Edit** enters its scope; the **first item is the template**; anything added/styled flows to every item; **dynamic-data icon** → variables popover → *Insert dynamic data* (e.g. Featured Image) → every post binds its own data live. | This IS Outpost's `data-outpost` templating direction, done visually. Direct match to the dynamic-holes model. | 🔥 · L |

### 🎬 Interactions — *the surprise standout*
| # | What Mosaic does | Outpost status | Priority · Effort |
|---|---|---|---|
| 12 | **Interaction panel** — scroll + click triggers; e.g. "continuous scroll" animating **opacity over 60% of scroll, 0%→100%**. | None | ➋ · L |
| 13 | **Bottom timeline** — After-Effects-style, multi-track; drag animations to **stagger delays** across instances for sequenced scroll-synced fades. | None | ➌ · XL |
| 14 | **Select targets on canvas** — an Action tab lets you pick which elements an interaction hits by clicking them directly. | None | ➌ · M |

### 🧱 Components + design system
| # | What Mosaic does | Outpost status | Priority · Effort |
|---|---|---|---|
| 15 | **Component instances** — edit one, all update; "Add to library"; clear component-vs-instance distinction. | Borrow-spec: recast blocks as Visual Components. Match. | (already planned) |
| 16 | **Style Guides / global tokens** — settings panel for color palette (primary/secondary swatches), fonts, breakpoints, defined globally + referenced everywhere. | Outpost has CSS-var design tokens; not surfaced as a visual style-guide editor | ➋ · M |
| 17 | **Semantic + utility class system** — their own blog card is literally "How to Use Semantic and Utility Classes in Mosaic." Confirms a Webflow-style class model. | Borrow-spec: class system missing, planned. Match. | (already planned) |

---

## Top 5 to steal first (ranked)
1. **Theme-first wizard + animated import modal** (#1, #2) — highest perceived-value, lowest effort. Serves onboarding regardless of builder depth.
2. **Loop-scope + Insert-dynamic-data flow** (#11) — the visual expression of Outpost's dynamic-holes core. Direct north-star alignment.
3. **On-canvas numbered orientation overlay** (#3) — cheap, huge for first-run comprehension.
4. **Scroll-interaction timeline** (#12/#13) — differentiator; heavier lift, stage later.
5. **Categorized section/pattern picker** (#9) — makes "made it yours in minutes" real.

## The honest gap = Outpost's opening
Mosaic shows **zero AI** in its entire getting-started flow — it's pure direct manipulation. Outpost already has Ranger + Editorial AI + an MCP server (`php/mcp.php`), and the north star is "AI builds the HTML." **Everything above + an AI builder sidebar is a combination Mosaic does not have.** Don't chase Mosaic's timeline/interaction depth at the cost of the AI + import-as-theme thesis; borrow the wizard polish and the dynamic-data flow, keep AI as the wedge.
