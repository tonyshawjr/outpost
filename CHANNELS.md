# Channels — External Data Sources for Outpost CMS

> **Status:** Design phase — pre-release feature candidate
> **Concept:** Channels bring external data into Outpost using the same Liquid template syntax developers already know. If Collections are content you create, Channels are content that flows in from somewhere else.

---

## The Big Idea

Every CMS is a content island. You can only display what lives in the database. Channels break that wall — any external data source becomes template-ready with familiar `{% for %}` loops, no custom PHP, no plugins, no build step.

A real estate agent pulls MLS listings. A restaurant pulls their Toast menu. A nonprofit pulls donation data from Stripe. A developer pulls issues from GitHub. Same syntax, same caching, same admin — it's just another loop in your template.

---

## Core Vocabulary

### Current Outpost Naming

| Concept | Current Name | What It Is |
|---|---|---|
| Your content | **Collections** | Structured content you create and manage (blog posts, projects, team members) |
| Categories/tags | **Taxonomies** | Grouping and classification system for collection items |
| External data | *(doesn't exist yet)* | — |

### Proposed: Channels

**Channels** = external data that flows into your site from somewhere else.

Why "Channels":
- Intuitive — a channel is a stream of data flowing in
- Distinct from Collections (your content) — no confusion about what lives where
- Implies something live, connected, ongoing — not a one-time import
- Short, clean, fits the admin nav
- Extensible — a Channel can be an API, an RSS feed, a CSV, a webhook listener, or anything that pipes data in

**The mental model:**
- **Collections** = content you own (you create it, you edit it, it lives in your database)
- **Channels** = content from elsewhere (it flows in, you display it, the source of truth is external)

### Naming Discussion: Taxonomies

Taxonomies is a developer word. Real people don't say "I need to create a taxonomy." Several alternatives were considered:

| Name | Pros | Cons |
|---|---|---|
| **Folders** | Everyone understands it; visual metaphor is strong; implies organization and hierarchy | Could confuse with file system folders (code editor already has a file tree); folders imply containment, not tagging |
| **Groups** | Clean, simple; "group your posts by category" reads naturally | Generic; doesn't imply hierarchy well |
| **Labels** | Gmail made this mainstream; implies flexible tagging; lightweight feel | Might feel too simple for hierarchical categories |
| **Tags** | Universal concept; everyone knows what a tag is | Already overloaded in every CMS; doesn't suggest hierarchy |

**Current recommendation:** Shelve the rename decision until Channels ships. Taxonomies works for developers in beta. If we rename, **Folders** is the strongest candidate — it implies hierarchy (folders can contain subfolders) and organization, which is exactly what taxonomies do. The code editor file-tree confusion is manageable with good UI differentiation.

**Decision needed:** Rename taxonomies before v1.0 stable, or keep "Taxonomies" and revisit later?

---

## Channel Types

### 1. REST API

The primary channel type. Connect to any REST API endpoint.

**Configuration:**
- Endpoint URL (supports path variables like `/api/homes/{id}`)
- HTTP method (GET, POST)
- Authentication method:
  - None (public APIs)
  - API Key (header or query param)
  - Bearer Token
  - Basic Auth
  - OAuth2 (client credentials flow)
- Custom headers
- Query parameters (static + dynamic)
- Response path — where in the JSON response is the data array? (e.g., `data.listings`, `results`, or root if top-level array)
- Pagination support:
  - Offset-based (`?page=1&limit=20`)
  - Cursor-based (`?after=abc123`)
  - Link-header based
- Rate limiting — max requests per minute to the external API

**Template usage:**
```html
{% for home in channel.mls_listings %}
  <div class="listing">
    <h2>{{ home.address }}</h2>
    <p class="price">{{ home.price }}</p>
    <img src="{{ home.photos[0] }}" alt="{{ home.address }}">
    <a href="/listing/{{ home.id }}">View Details</a>
  </div>
{% endfor %}

{% single listing from channel.mls_listings where slug %}
  <h1>{{ listing.address }}</h1>
  <p>{{ listing.description | raw }}</p>
{% endsingle %}
```

### 2. RSS / Atom Feeds

Pull in any RSS or Atom feed as structured data.

**Configuration:**
- Feed URL
- Refresh interval
- Max items to store

**Template usage:**
```html
{% for item in channel.industry_news %}
  <article>
    <h3><a href="{{ item.link }}">{{ item.title }}</a></h3>
    <p>{{ item.description }}</p>
    <time>{{ item.pubDate }}</time>
  </article>
{% endfor %}
```

**Built-in field mapping for RSS:**
- `title`, `link`, `description`, `pubDate`, `author`, `category`, `content` (full HTML), `thumbnail`

### 3. CSV / JSON File Sync

Upload or link to a CSV/JSON file that becomes queryable data.

**Configuration:**
- Upload a file, or provide a URL that returns CSV/JSON
- Column mapping (auto-detected from headers)
- Refresh: manual re-upload, or poll URL on schedule
- Delimiter settings for CSV

**Use cases:**
- Spreadsheet-managed data (Google Sheets export URL, Airtable CSV)
- Product catalogs maintained in Excel
- Event schedules managed by non-technical staff
- Any data that lives in a spreadsheet today

**Template usage:**
```html
{% for product in channel.price_list %}
  <tr>
    <td>{{ product.sku }}</td>
    <td>{{ product.name }}</td>
    <td>{{ product.price }}</td>
  </tr>
{% endfor %}
```

### 4. Webhook Listener (Inbound)

Receive push data from external services. Instead of polling, the external service pushes updates to Outpost.

**Configuration:**
- Outpost generates a unique webhook URL per channel (e.g., `/outpost/api.php?action=channel_webhook&id=abc123`)
- Optional secret/signature validation (HMAC)
- Payload mapping — which fields from the webhook payload to store
- Behavior: replace all data, append, or upsert by key field

**Use cases:**
- Stripe sends payment events → display recent donors
- Shopify sends inventory updates → show in-stock products
- GitHub sends push events → show recent commits
- Any service with outgoing webhooks

---

## Outbound Channels (Send Data Out)

Channels aren't just about pulling data in. Outpost should also be able to **push data out** — making your CMS content available to external systems.

### Outbound Channel Types

**1. Webhook Dispatch (already exists partially)**
- Outpost already has webhooks — extend them so Channel events (data refreshed, new items received) also fire webhooks
- External systems subscribe to Channel update events

**2. API Exposure**
- Each Channel's cached data gets a read-only JSON endpoint automatically
- Example: `api.php?action=channel_data&slug=mls_listings&format=json`
- This means Outpost can act as a **data proxy** — pull from a complex API, expose a simplified version

**3. Scheduled Push**
- Push Channel data (or Collection data) to an external endpoint on a schedule
- Use case: sync CMS content to a static site builder, a mobile app backend, or a partner system

**4. Transform & Forward**
- Pull from Channel A, transform/filter, push to Channel B
- Example: Pull job listings from an ATS API, filter by location, push matching jobs to a Slack channel via webhook

---

## Channel Builder (Admin UI)

The Channel Builder is the admin interface where developers configure channels. It lives in the admin panel alongside Collections, Pages, etc.

### Channel List View
- Card/list of all configured channels
- Each shows: name, type (API/RSS/CSV/Webhook), status (active/paused/error), last sync time, item count
- Create new channel button

### Channel Configuration (Step-by-Step Builder)

**Step 1: Choose Type**
- REST API
- RSS / Atom Feed
- CSV / JSON File
- Webhook Listener

**Step 2: Connect**
- Enter the URL / upload file / configure webhook
- For APIs: set auth method, headers, params
- **Test Connection** button — hit the endpoint, show raw response

**Step 3: Discover Schema**
- Outpost introspects the response and shows the JSON tree structure
- Expandable tree view of all available fields
- Developer checks which fields to include
- Set the "data path" (e.g., `response.data.items` is where the array lives)
- For nested objects, choose how to flatten or preserve structure

**Step 4: Configure**
- Channel slug (used in templates: `channel.{slug}`)
- Cache TTL: 5 minutes, 15 minutes, 1 hour, 6 hours, daily, manual only
- Pagination settings (if applicable)
- Item limit (max items to cache)
- Sort field and direction
- Optional: URL pattern for single-item pages (e.g., `/listing/{slug}`)

**Step 5: Preview & Template Help**
- Show a preview table of the first N items with mapped fields
- Auto-generate the Liquid template code for copy-paste:
  ```
  {% for item in channel.mls_listings %}
    {{ item.address }}
    {{ item.price }}
    {{ item.bedrooms }}
  {% endfor %}
  ```
- Show single-item template code if URL pattern is configured

### Channel Dashboard
- Per-channel stats: total items cached, last sync, next sync, error count
- Manual "Sync Now" button
- Sync history log (last 50 syncs with status, item count, duration)
- Error log with response codes and messages

---

## Technical Architecture

### Database Schema

```sql
-- Channel definitions
CREATE TABLE channels (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    slug TEXT UNIQUE NOT NULL,
    name TEXT NOT NULL,
    type TEXT NOT NULL,           -- 'api', 'rss', 'csv', 'webhook'
    config TEXT NOT NULL,          -- JSON: url, auth, headers, params, data_path, pagination
    field_map TEXT,                -- JSON: which fields to extract and their aliases
    cache_ttl INTEGER DEFAULT 3600,  -- seconds
    url_pattern TEXT,              -- e.g., '/listing/{slug}' for single-item routing
    sort_field TEXT,
    sort_direction TEXT DEFAULT 'desc',
    max_items INTEGER DEFAULT 100,
    status TEXT DEFAULT 'active',  -- 'active', 'paused', 'error'
    last_sync_at TEXT,
    last_error TEXT,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT DEFAULT CURRENT_TIMESTAMP
);

-- Cached channel data
CREATE TABLE channel_items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    channel_id INTEGER NOT NULL,
    external_id TEXT,              -- unique key from the external source
    slug TEXT,                     -- for URL routing
    data TEXT NOT NULL,            -- JSON: the full item data
    sort_value TEXT,               -- extracted sort field for ordering
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (channel_id) REFERENCES channels(id) ON DELETE CASCADE
);

CREATE INDEX idx_channel_items_channel ON channel_items(channel_id);
CREATE INDEX idx_channel_items_slug ON channel_items(channel_id, slug);
CREATE INDEX idx_channel_items_sort ON channel_items(channel_id, sort_value);

-- Sync log
CREATE TABLE channel_sync_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    channel_id INTEGER NOT NULL,
    status TEXT NOT NULL,          -- 'success', 'error', 'partial'
    items_synced INTEGER DEFAULT 0,
    items_added INTEGER DEFAULT 0,
    items_updated INTEGER DEFAULT 0,
    items_removed INTEGER DEFAULT 0,
    duration_ms INTEGER,
    error_message TEXT,
    synced_at TEXT DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (channel_id) REFERENCES channels(id) ON DELETE CASCADE
);
```

### Caching Strategy

All channel data is cached in SQLite. External APIs are **never** hit on a page request — only the cache is read.

- **Background sync**: A lightweight PHP script (`channel-sync.php`) runs via cron or is triggered by the admin
- **On-demand sync**: Admin clicks "Sync Now" in the UI
- **Stale-while-revalidate**: If cache is expired but sync hasn't run yet, serve stale data and trigger async refresh
- **Webhook-triggered**: For webhook channels, data updates immediately on receipt

Cache lives in the same SQLite database as everything else — one-file backup still works.

### Template Engine Integration

Extend `template-engine.php` to recognize `channel.*` references alongside `collection.*`:

```php
// In the Liquid compiler
// {% for item in channel.mls_listings %} compiles to:
cms_channel_list('mls_listings', function($item) {
    // loop body
});

// {% single listing from channel.mls_listings where slug %} compiles to:
cms_channel_single('mls_listings', $_GET['slug'], function($listing) {
    // single body
});
```

New engine functions in `engine.php`:
- `cms_channel_list($slug, $callback, $options)` — loop over cached channel items
- `cms_channel_single($slug, $identifier, $callback)` — get one item by slug/id
- `cms_channel_count($slug)` — item count for display
- `cms_channel_meta($slug)` — last sync time, source URL, etc.

### Routing Integration

Extend `front-router.php` to handle channel URL patterns:

- Channel has `url_pattern = '/listing/{slug}'`
- Request for `/listing/123-main-st` → set `$_GET['slug'] = '123-main-st'` → render `listing.html`
- Same pattern as collection routing — developers already understand this

### Security

- API credentials stored encrypted in the `channels.config` JSON (AES-256 with a site key)
- Webhook endpoints validate HMAC signatures when configured
- Channel admin UI restricted to admin/developer roles only
- Outbound data respects visibility settings — no member-only data exposed via channel API endpoints
- Rate limiting on sync to prevent abuse of external APIs
- Input sanitization on all channel data rendered in templates (auto-escaped by default, same as collections)

---

## Real-World Use Cases

### Real Estate — MLS Listings
Connect to an MLS/IDX API. Display listings with search, filters, and detail pages. Agent updates listings in the MLS; the website updates automatically.

### Restaurant — Menu & Ordering
Pull menu items from Toast, Square, or a Google Sheet. Display the current menu with prices. Link to online ordering.

### Nonprofit — Donor Wall
Webhook from Stripe/PayPal → display recent donors (with permission) on a "Thank You" page.

### Portfolio — GitHub Projects
Pull repos from the GitHub API. Auto-display your open source work with stars, descriptions, and links.

### Job Board — ATS Integration
Pull open positions from Greenhouse, Lever, or Workable. Job seekers see current openings; hiring managers only update the ATS.

### Events — Eventbrite / Meetup
Pull upcoming events and display them on the site. RSVPs still happen on the source platform.

### E-Commerce Lite — Shopify / Snipcart
Pull product data from Shopify's Storefront API. Display products on your Outpost site with "Buy" links that go to Shopify checkout.

### News Aggregation — RSS Feeds
Pull industry news from multiple RSS feeds into a single "Industry News" page. Curated content without manual updates.

### Data Dashboards — Google Sheets
Marketing team maintains KPIs in a Google Sheet. Outpost pulls it in and displays a live dashboard on the internal site.

### Multi-Source Directory
A chamber of commerce pulls member businesses from a CRM API, upcoming events from Eventbrite, and news from an RSS feed — three channels powering one website.

---

## Template Syntax — Full Reference

### Loops
```html
{% for item in channel.slug %}
  {{ item.field_name }}
  {{ item.nested.field }}
  {{ item.html_field | raw }}
  {{ item.image_field | image }}
{% endfor %}
```

### Single Item (by URL slug)
```html
{% single item from channel.slug where slug %}
  <h1>{{ item.title }}</h1>
  <p>{{ item.description | raw }}</p>
{% else %}
  <p>Item not found.</p>
{% endsingle %}
```

### Item Count
```html
<p>{{ channel.slug.count }} listings available</p>
```

### Channel Meta
```html
<small>Last updated: {{ channel.slug.last_sync }}</small>
```

### Conditional
```html
{% if channel.slug.count > 0 %}
  {# show listings #}
{% else %}
  <p>No listings available right now.</p>
{% endif %}
```

### Filtering (future consideration)
```html
{% for item in channel.mls_listings where item.bedrooms >= 3 %}
  {# filtered loop — may be v2 #}
{% endfor %}
```

### Pagination (future consideration)
```html
{% for item in channel.mls_listings limit 10 offset page %}
  {# paginated loop — may be v2 #}
{% endfor %}
```

---

## Phased Rollout

### Phase 1: REST API Channels (ship first)
- Channel CRUD in admin
- REST API connection with auth
- Schema discovery + field mapping
- SQLite caching with TTL
- `{% for item in channel.slug %}` and `{% single %}` in templates
- Manual sync + basic cron support
- Channel routing (URL patterns for single-item pages)

### Phase 2: RSS + CSV Channels
- RSS/Atom feed parser (PHP built-in `simplexml`)
- CSV upload and URL-based CSV sync
- JSON file sync
- Google Sheets public URL support

### Phase 3: Inbound Webhooks
- Webhook URL generation per channel
- HMAC signature validation
- Real-time data updates on webhook receipt
- Webhook event log in admin

### Phase 4: Outbound & Advanced
- Outbound webhook dispatch on channel sync events
- Channel data as read-only JSON API endpoint
- Scheduled push to external endpoints
- Filtering and pagination in template syntax
- Transform & forward (channel-to-channel piping)

---

## Open Questions

1. **Should channels support write-back?** E.g., a contact form submission pushes data to an external CRM via a channel. Or is that a separate "Actions" concept?

2. **Channel + Collection hybrid?** Could a channel sync data into an actual collection, making it editable locally? (Useful for "pull from API, then let editors tweak descriptions.") This blurs the line but is powerful.

3. **Rate limiting UX:** How do we communicate to the admin that their external API has rate limits? Show a warning during setup? Auto-throttle?

4. **Error handling in templates:** If a channel is in error state (API down, auth expired), what renders? Empty loop? Error partial? Stale data with a warning?

5. **Multi-page channels:** For APIs that return thousands of items, do we paginate in the cache? Cap at `max_items`? Both?

6. **Channel marketplace/presets:** Should we ship preset channel configs for popular APIs (Shopify, Stripe, GitHub, RSS) so setup is one-click?

---

## Why This Matters

Every small business and agency site eventually needs external data. Today, that means custom PHP, WordPress plugins, or hiring a developer to write API integration code. Channels make it declarative:

1. Connect (point at the data source)
2. Map (pick the fields you want)
3. Loop (use it in your template)

No plugins. No Composer. No build step. No custom code. Just another `{% for %}` loop.

This is what makes Outpost more than a CMS — it's a **site engine** that can power anything from a blog to a real estate portal to a job board, all with the same zero-config philosophy.
