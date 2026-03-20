# Outpost v4.0 — Smart Forge + Frontend Editing Overlay

**Current version:** 3.3.10
**Target version:** 4.0.0
**Status:** Planning

---

## Vision

Drop in any HTML file. One click turns every content element into an editable field. All editing moves to the frontend. No more backend page editing — the site *is* the editor.

---

## 1. Frontend Overlay — Layout

When a logged-in admin visits any page on the frontend, Outpost injects an editing overlay with three parts:

### 1.1 Top Bar (fixed, full-width)

A branded green strip across the top of the page.

```
┌──────────────────────────────────────────────────────────────────────┐
│  [Outpost Logo] Home                    👤👤  [Save]  [Publish ▾]  │
└──────────────────────────────────────────────────────────────────────┘
```

| Position | Element | Details |
|----------|---------|---------|
| Far left | **Outpost logo** | Small logomark, links back to admin dashboard |
| Left | **Page name** | Current page title, non-editable label |
| Center-right | **Live avatars** | Stacked avatar circles for all admins currently viewing this page. Shows initials or profile photo. Tooltip with name on hover. Real-time via polling or SSE. |
| Right | **Save** | Saves all pending changes as a draft (not published). Disabled when no changes. |
| Right | **Publish ▾** | Dropdown button with three options (see below) |

**Publish dropdown options:**

| Option | Behavior |
|--------|----------|
| **Publish now** | Pushes all saved changes live immediately |
| **Add to Release** | Opens a mini-modal to select an existing release or create a new one. Bundles the changes into that release for batch publishing later. |
| **Share for Review** | Auto-generates a review link for this page and copies it to clipboard. Uses the existing review token system. |

---

### 1.2 Right Sidebar Rail (fixed, vertical icon strip)

A narrow icon rail pinned to the right edge of the viewport. Each icon opens a drawer panel that slides in from the right (pushing or overlaying the page content).

Only one drawer is open at a time. Clicking the active icon closes its drawer.

```
┌──────┐
│  ✏️  │  Edit
│      │
│  🔍  │  SEO
│      │
│  ⚙️  │  Settings
│      │
│  🤖  │  Ranger
│      │
│  🕐  │  History
│      │
│  💬  │  Comments
│      │
│  👁️  │  Preview
└──────┘
```

| # | Icon | Label | Drawer contents |
|---|------|-------|-----------------|
| 1 | Pencil | **Edit** | All editable fields for this page, grouped by section. The primary editing interface. |
| 2 | Search/magnifier | **SEO** | Meta title, meta description, OG image, canonical URL, structured data preview (Google snippet). |
| 3 | Gear | **Settings** | Page slug, visibility (public/members/paid), published date, template assignment, page status. |
| 4 | Ranger spark | **Ranger** | AI assistant — rewrite copy, suggest improvements, generate content, all in context of the current page. |
| 5 | Clock | **History** | Revision timeline for this page. View diffs, restore previous versions. |
| 6 | Chat bubble | **Comments** | Review comments left on this page. Merges with the existing review overlay system. Admins can see and resolve comments inline. |
| 7 | Eye | **Preview** | Toggles the entire overlay off to see the clean page exactly as a visitor would. Click again or press Escape to return to editing. |

---

## 2. Edit Drawer — Detail Design

The Edit drawer is the core of v4.0. It replaces the backend PageEditor entirely.

### 2.1 Layout

```
┌─────────────────────────────────┐
│  Edit                     [×]   │
│─────────────────────────────────│
│                                 │
│  ▼ Hero Section                 │
│    ┌───────────────────────┐    │
│    │ Headline              │    │
│    │ Welcome to our site   │    │
│    └───────────────────────┘    │
│    ┌───────────────────────┐    │
│    │ Subheadline           │    │
│    │ We build great things │    │
│    └───────────────────────┘    │
│    ┌───────────────────────┐    │
│    │ Hero Image        [⬚] │    │
│    │ ┌─────────┐           │    │
│    │ │  thumb  │  hero.jpg │    │
│    │ └─────────┘           │    │
│    └───────────────────────┘    │
│    ┌───────────────────────┐    │
│    │ CTA Button             │    │
│    │ Label: [Get Started  ] │    │
│    │ URL:   [/contact     ] │    │
│    └───────────────────────┘    │
│                                 │
│  ▶ About Section (collapsed)    │
│  ▶ Features Section (collapsed) │
│  ▶ Footer (collapsed)           │
│                                 │
└─────────────────────────────────┘
```

### 2.2 Section Grouping

Fields are organized into collapsible sections based on where they appear on the page. Smart Forge determines grouping during the initial scan (see Section 4) by detecting:

- `<section>` elements with IDs or classes (e.g., `<section class="hero">` → "Hero Section")
- `<header>`, `<footer>`, `<main>`, `<nav>` landmarks
- Heading hierarchy (`<h1>`, `<h2>`) as section separators
- Custom `data-outpost-section="Section Name"` attributes (for explicit control)

Each section header shows the section name and is collapsible. The section containing the field closest to the current scroll position auto-expands.

### 2.3 Field Types in the Drawer

All 16 existing Outpost field types render in the drawer:

| Field Type | Drawer Rendering |
|------------|-----------------|
| **text** | Single-line input with label |
| **textarea** | Multi-line plain text input |
| **richtext** | Inline TipTap editor (bold, italic, links, lists, headings) |
| **image** | Thumbnail preview + upload/replace button + alt text input |
| **gallery** | Grid of thumbnails, drag-to-reorder, add/remove |
| **link** | Two inputs: label + URL |
| **select** | Dropdown with predefined options |
| **toggle** | Switch with label |
| **color** | Color swatch + hex input |
| **number** | Numeric input with optional min/max |
| **date** | Date picker |
| **repeater** | Expandable rows, each containing sub-fields. Add/remove/reorder rows. |
| **flexible** | Layout selector + block content (like repeater but with layout types) |
| **relationship** | Search + select items from other collections |
| **meta_title** | (Moved to SEO tab) |
| **meta_description** | (Moved to SEO tab) |

### 2.4 Hover Highlighting (Drawer ↔ Page)

Bidirectional connection between the drawer and the live page:

- **Hover a field in the drawer** → the corresponding element on the page gets a subtle blue outline + label badge (e.g., "Headline")
- **Hover an element on the page** → the corresponding field in the drawer scrolls into view and highlights with a soft blue background
- **Click an element on the page** → opens the Edit drawer (if closed) and focuses that field's input

This requires a **field-to-selector map** generated by Smart Forge (see Section 4.3).

---

## 3. SEO Drawer — Detail Design

SEO gets its own dedicated drawer because it's critical to the product and deserves focused attention.

### 3.1 Layout

```
┌─────────────────────────────────┐
│  SEO                      [×]   │
│─────────────────────────────────│
│                                 │
│  Google Preview                 │
│  ┌─────────────────────────┐    │
│  │ Your Page Title — Site  │    │
│  │ yoursite.com/about      │    │
│  │ Your meta description   │    │
│  │ appears here in search… │    │
│  └─────────────────────────┘    │
│                                 │
│  Meta Title                     │
│  ┌─────────────────────────┐    │
│  │ Your Page Title         │    │
│  └─────────────────────────┘    │
│  52/60 characters              │
│                                 │
│  Meta Description               │
│  ┌─────────────────────────┐    │
│  │ A brief description of  │    │
│  │ what this page is about │    │
│  └─────────────────────────┘    │
│  120/160 characters            │
│                                 │
│  ─────────────────────────────  │
│                                 │
│  OG Image                       │
│  ┌─────────┐                    │
│  │  thumb  │  og-image.jpg      │
│  └─────────┘  [Replace]         │
│                                 │
│  Canonical URL                  │
│  ┌─────────────────────────┐    │
│  │ https://yoursite.com/…  │    │
│  └─────────────────────────┘    │
│  Leave blank to use default     │
│                                 │
│  Social Preview                 │
│  ┌─────────────────────────┐    │
│  │  ┌──────────────────┐   │    │
│  │  │    OG Image       │   │    │
│  │  ├──────────────────┤   │    │
│  │  │ Your Page Title   │   │    │
│  │  │ Meta description  │   │    │
│  │  │ yoursite.com      │   │    │
│  │  └──────────────────┘   │    │
│  └─────────────────────────┘    │
│  How this page looks when       │
│  shared on social media         │
│                                 │
└─────────────────────────────────┘
```

### 3.2 SEO Features

| Feature | Details |
|---------|---------|
| **Google snippet preview** | Live preview at the top, updates as you type. Shows title (truncated at 60 chars), URL, description (truncated at 160 chars). |
| **Meta title** | Input with character counter. Green under 60, yellow 60-70, red 70+. |
| **Meta description** | Textarea with character counter. Green under 160, yellow 160-200, red 200+. |
| **OG image** | Image picker. Shown in social preview card below. Falls back to first image on page if not set. |
| **Canonical URL** | Optional override. Helper text explains when to use it. |
| **Social preview card** | Facebook/Twitter card mockup showing how the page will look when shared. Updates live. |

---

## 4. Smart Forge — The Scan Engine

Smart Forge is the one-click conversion system that turns raw HTML into a fully editable Outpost page.

### 4.1 User Flow

1. User drops an HTML file into Outpost (via admin or a new "Add Page" action)
2. User clicks **"Forge"** (single button)
3. Smart Forge scans the entire file in one pass
4. Result: a Liquid template with `{{ field_name }}` tags + all fields auto-registered in the database + a field-to-selector map for hover highlighting
5. Page is immediately editable on the frontend

### 4.2 What Smart Forge Detects

| HTML Pattern | Field Type Created | Example |
|-------------|-------------------|---------|
| `<h1>`, `<h2>`, `<h3>` | `text` | `<h1>{{ hero_headline }}</h1>` |
| `<p>`, `<span>` (short text) | `text` | `<p>{{ tagline }}</p>` |
| `<p>` (long text, multiple paragraphs) | `richtext` | `<div>{{ about_body \| raw }}</div>` |
| `<img>` | `image` | `<img src="{{ hero_image \| image }}">` |
| `<a>` with text | `link` | `<a href="{{ cta_url }}">{{ cta_label }}</a>` |
| `<a>` wrapping `<img>` | `image` + `link` | Image with link |
| Repeated sibling structures (e.g., 3 cards) | `repeater` | `{% for item in repeater.features %}` |
| `<button>` | `link` | Label + URL |
| `<input>`, `<form>` | Skipped | Not content |
| `<script>`, `<style>` | Skipped | Not content |
| `<meta name="description">` | `meta_description` | `{{ meta.description }}` |
| `<title>` | `meta_title` | `{{ meta.title }}` |

### 4.3 Field-to-Selector Map

During scanning, Smart Forge records a CSS selector for each field it creates:

```json
{
  "hero_headline": "section.hero > h1",
  "hero_image": "section.hero > .hero-img > img",
  "tagline": "section.hero > p.tagline",
  "features": "section.features > .card"
}
```

This map is stored in the database alongside the page and powers the hover highlighting in the Edit drawer (Section 2.4).

### 4.4 Naming Convention

Smart Forge generates human-readable field names from context:

1. Use the element's `id` or `class` if meaningful (e.g., `class="hero-headline"` → `hero_headline`)
2. Fall back to section name + element type (e.g., Hero section's `<h1>` → `hero_heading`)
3. Fall back to sequential naming (e.g., `heading_1`, `heading_2`)
4. User can rename any field in the Edit drawer after forging

### 4.5 Section Detection

Smart Forge groups fields into sections for the Edit drawer:

1. `<section id="hero">` or `<section class="hero">` → "Hero"
2. `<header>` → "Header", `<footer>` → "Footer"
3. `data-outpost-section="Features"` → "Features" (explicit override)
4. If no semantic structure, group by nearest heading (`<h2>`, `<h3>`)
5. If nothing, flat list (no sections)

---

## 5. Settings Drawer

Page configuration that isn't content or SEO.

```
┌─────────────────────────────────┐
│  Settings                 [×]   │
│─────────────────────────────────│
│                                 │
│  Page Slug                      │
│  ┌─────────────────────────┐    │
│  │ /about                  │    │
│  └─────────────────────────┘    │
│                                 │
│  Visibility                     │
│  ┌─────────────────────────┐    │
│  │ Public              ▾   │    │
│  └─────────────────────────┘    │
│  Public / Members only /        │
│  Paid members only              │
│                                 │
│  Published Date                 │
│  ┌─────────────────────────┐    │
│  │ 2026-03-19             │    │
│  └─────────────────────────┘    │
│                                 │
│  Template                       │
│  ┌─────────────────────────┐    │
│  │ about.html          ▾   │    │
│  └─────────────────────────┘    │
│                                 │
│  ─────────────────────────────  │
│                                 │
│  ⚠️ Delete Page                 │
│                                 │
└─────────────────────────────────┘
```

---

## 6. Other Drawer Panels

### 6.1 Ranger (AI Assistant)

The existing Ranger chat interface, rendered in the drawer instead of the admin panel. Context-aware — it knows which page you're editing and can:

- Rewrite a selected field ("Make this headline punchier")
- Suggest content for empty fields
- Generate meta descriptions from page content
- Apply changes directly to fields in the drawer

### 6.2 History (Revisions)

```
┌─────────────────────────────────┐
│  History                  [×]   │
│─────────────────────────────────│
│                                 │
│  ● Current version              │
│    Today, 2:34 PM — Tony        │
│                                 │
│  ○ Previous version             │
│    Today, 11:15 AM — Tony       │
│    Changed: headline, hero_img  │
│    [Restore]                    │
│                                 │
│  ○ 3 versions ago               │
│    Yesterday, 4:50 PM — Sarah   │
│    Changed: body, cta_label     │
│    [Restore]                    │
│                                 │
└─────────────────────────────────┘
```

### 6.3 Comments (Review Feedback)

Merges with the existing review overlay. Shows all comments left on this page via review links. Admins can:

- See comment pins on the page (numbered badges)
- Reply to comments
- Resolve/reopen comments
- Filter by status (Open / Resolved / All)

### 6.4 Preview

Not a drawer — this is a toggle. Clicking Preview:

1. Hides the top bar, right rail, and any open drawer
2. Shows the page exactly as a visitor would see it
3. Adds a small floating "Exit Preview" pill (bottom-center) or responds to Escape key
4. Returns to editing mode when dismissed

---

## 7. Pages Section — What Happens to It

**The Pages section in the admin panel is removed as a content editor.** It becomes a page *manager* only:

| Stays in admin | Moves to frontend |
|----------------|-------------------|
| Page list (table of all pages) | All content field editing |
| Create new page | SEO fields |
| Smart Forge trigger | — |
| Bulk actions (delete, visibility) | — |
| Collection schema builder | — |
| Navigation editor | — |

The admin panel becomes the *structure* tool. The frontend becomes the *content* tool.

---

## 8. Technical Architecture

### 8.1 Injection Method

`front-router.php` detects logged-in admins and injects the overlay:

```php
if (outpost_is_admin()) {
    $inject = <<<HTML
    <link rel="stylesheet" href="/outpost/editor/editor.css">
    <script>
        window.__OUTPOST_EDIT_MODE__ = true;
        window.__OUTPOST_API_URL__ = "/outpost/api.php";
        window.__OUTPOST_PAGE_ID__ = {$pageId};
        window.__OUTPOST_PAGE_PATH__ = "{$pagePath}";
        window.__OUTPOST_USER__ = {$userJson};
        window.__OUTPOST_CSRF__ = "{$csrfToken}";
    </script>
    <script src="/outpost/editor/editor.js" defer></script>
    HTML;
    $html = str_replace('</body>', $inject . "\n</body>", $html);
}
```

### 8.2 Editor Bundle

The frontend editor is a **separate Svelte 5 app** (`src/editor/`) built independently from the admin SPA. It produces:

- `editor.js` — the overlay application
- `editor.css` — scoped styles (all prefixed with `.outpost-editor-*` to avoid conflicts with the site's CSS)

This app shares field components with the admin SPA (TipTap, image picker, SortableJS for repeaters) but has its own entry point and layout.

### 8.3 API Endpoints (existing + new)

| Endpoint | Method | Purpose | Status |
|----------|--------|---------|--------|
| `pages?path=/about` | GET | Load page + fields by URL path | **New** |
| `fields/bulk-update` | POST | Save all changed fields | Existing |
| `pages/update` | POST | Update slug, visibility, meta | Existing |
| `releases/add-changes` | POST | Add field changes to a release | **New** |
| `editor/active-users` | GET | Poll for live avatars on this page | **New** |
| `editor/field-map` | GET | Get field-to-selector map for a page | **New** |
| `forge/scan` | POST | Smart Forge: scan HTML, return fields + template | **New** |
| `forge/apply` | POST | Smart Forge: write template + register fields | **New** |

### 8.4 Database Changes

```sql
-- Field-to-selector map for hover highlighting
ALTER TABLE fields ADD COLUMN css_selector TEXT DEFAULT NULL;

-- Section grouping for Edit drawer
ALTER TABLE fields ADD COLUMN section_name TEXT DEFAULT NULL;

-- Active editor tracking (for live avatars)
CREATE TABLE editor_sessions (
    id INTEGER PRIMARY KEY,
    user_id INTEGER NOT NULL,
    page_id INTEGER NOT NULL,
    last_seen DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
);
```

---

## 9. Smart Forge — Technical Detail

### 9.1 Scan Pipeline

```
Raw HTML file
    ↓
1. Parse DOM (DOMDocument or regex for lightweight parsing)
    ↓
2. Identify content elements (skip scripts, styles, forms, nav)
    ↓
3. Detect sections (semantic HTML landmarks)
    ↓
4. Detect repeating patterns (sibling elements with same structure)
    ↓
5. Generate field names (from classes, IDs, or context)
    ↓
6. Build field-to-selector map
    ↓
7. Replace content with Liquid tags
    ↓
8. Return: { template, fields[], selectorMap }
```

### 9.2 Repeater Detection Algorithm

1. Find groups of 2+ sibling elements with identical tag structure
2. Compare their child element types and class names
3. If structure matches (same tags, same classes, different text/images), flag as repeater
4. Create a repeater field with sub-fields matching the inner structure
5. Wrap in `{% for item in repeater.field_name %}` / `{% endfor %}`

### 9.3 Edge Cases

| Scenario | Handling |
|----------|----------|
| Inline SVG icons | Skip (not content) |
| Background images in CSS | Skip (can't detect without CSS parsing) |
| Dynamic content (JS-rendered) | Skip (only scans static HTML) |
| Existing Liquid tags | Preserve (don't double-convert) |
| Nested sections | Flatten to one level of grouping |
| Very long text blocks | Classify as `richtext` if > 200 chars or contains `<br>`, `<strong>`, `<em>` |

---

## 10. Implementation Phases

### Phase 1: Foundation
- Build the editor overlay shell (top bar + right rail + drawer framework)
- Implement the Edit drawer with all field types
- Inject overlay for logged-in admins via `front-router.php`
- Wire up Save (field bulk-update) and basic Publish

### Phase 2: Smart Forge
- Build the HTML scan engine (PHP-side DOM parsing)
- Field auto-detection and naming
- Section grouping
- Repeater detection
- Field-to-selector map generation
- Liquid template output

### Phase 3: Hover Highlighting + Polish
- Bidirectional hover highlighting (drawer ↔ page)
- Click-to-edit from page elements
- Section auto-expand on scroll

### Phase 4: SEO + Settings Drawers
- SEO drawer with Google snippet preview and social card preview
- Settings drawer (slug, visibility, published date, template)
- Character counters and validation

### Phase 5: Ranger + History + Comments
- Port Ranger to the frontend drawer
- History/revision panel
- Merge review overlay into Comments panel

### Phase 6: Publish Flow
- Publish dropdown (Publish now / Add to Release / Share for Review)
- Live avatar presence (editor_sessions polling)
- Preview mode toggle

### Phase 7: Admin Panel Cleanup
- Remove content editing from Pages section
- Pages becomes page manager only (list, create, delete, Smart Forge trigger)
- Update navigation and onboarding

---

## 11. Design Specifications

### Colors & Branding

| Element | Color | Notes |
|---------|-------|-------|
| Top bar background | Outpost green (`#10B981` or brand green) | Solid, full-width |
| Top bar text | White | Logo, page name, button labels |
| Right rail background | White | Subtle left border |
| Right rail icons | Gray (`#6B7280`) | Active icon: Outpost green |
| Drawer background | White | Right-aligned, 380–420px wide |
| Drawer header | 16px semibold | With close button |
| Field labels | 11px uppercase muted (`#9CA3AF`) | Consistent with admin design system |
| Section headers | 13px semibold, collapsible | Chevron indicator |
| Hover highlight on page | 2px blue outline (`#3B82F6`) + label badge | Semi-transparent overlay |
| Save button | White text, transparent background | Ghost button style |
| Publish button | White text, darker green background | Primary CTA |

### Spacing & Layout

| Property | Value |
|----------|-------|
| Top bar height | 48px |
| Right rail width | 56px |
| Drawer width | 400px |
| Drawer padding | 24px |
| Field spacing | 20px between fields |
| Section spacing | 12px between sections |
| Field label to input | 6px |

### Animations

| Interaction | Animation |
|-------------|-----------|
| Drawer open | Slide in from right, 200ms ease-out |
| Drawer close | Slide out to right, 150ms ease-in |
| Hover highlight | Fade in, 100ms |
| Section collapse/expand | Height transition, 150ms |
| Save button | Brief checkmark flash on success |
| Preview toggle | Fade out overlay, 200ms |

### Responsive Behavior

| Viewport | Behavior |
|----------|----------|
| Desktop (>1024px) | Full overlay: top bar + rail + drawer |
| Tablet (768–1024px) | Drawer overlays page (not side-by-side) |
| Mobile (<768px) | Full-screen drawer, swipe to dismiss. Top bar condenses to hamburger. |

---

## 12. Key Differentiators vs. Competitors

| Feature | Outpost v4 | Storyblok | WordPress | Webflow |
|---------|-----------|-----------|-----------|---------|
| Drop in raw HTML | Smart Forge (1 click) | Manual component mapping | Not possible | Import tool (lossy) |
| Frontend editing | Full drawer | Full drawer | Gutenberg (sort of) | Designer only |
| Self-hosted | Yes | No (SaaS) | Yes | No (SaaS) |
| AI assistant in editor | Ranger | No | Plugins | No |
| Client review system | Built-in | No | Plugins | Built-in (basic) |
| Release bundling | Built-in | Stages | Plugins | Staging |
| No framework lock-in | Any HTML | Storyblok SDK required | PHP themes | Webflow only |

---

*This document is the source of truth for v4.0 planning. Share with design and development teams for feedback before implementation begins.*
