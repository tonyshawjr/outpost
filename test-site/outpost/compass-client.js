/**
 * Outpost CMS — Compass Client
 * Faceted search & filtering for collection items.
 *
 * Self-initializing: runs on DOMContentLoaded, discovers all [data-compass]
 * elements, wires up facet controls, syncs state with URL query params,
 * and fetches filtered results via AJAX.
 *
 * No dependencies. Vanilla JS, modern syntax (ES2020+).
 */

(function () {
  'use strict';

  // ────────────────────────────────────────────────────────
  // Utilities
  // ────────────────────────────────────────────────────────

  /** Debounce helper — returns a wrapper that delays `fn` by `ms`. */
  function debounce(fn, ms) {
    let timer;
    return function (...args) {
      clearTimeout(timer);
      timer = setTimeout(() => fn.apply(this, args), ms);
    };
  }

  /** Dispatch a custom event on an element (bubbles, cancelable). */
  function emit(el, name, detail = {}) {
    return el.dispatchEvent(
      new CustomEvent(name, { bubbles: true, cancelable: true, detail })
    );
  }

  /** Shallow-equal comparison for two plain objects (string/array values). */
  function stateEqual(a, b) {
    const ka = Object.keys(a);
    const kb = Object.keys(b);
    if (ka.length !== kb.length) return false;
    return ka.every((k) => {
      const va = a[k];
      const vb = b[k];
      if (Array.isArray(va) && Array.isArray(vb)) {
        return va.length === vb.length && va.every((v, i) => v === vb[i]);
      }
      return va === vb;
    });
  }

  // ────────────────────────────────────────────────────────
  // OutpostCompass Controller
  // ────────────────────────────────────────────────────────

  class OutpostCompass {
    /**
     * @param {HTMLElement} root — the top-level [data-compass] wrapper
     */
    constructor(root) {
      this.root = root;

      /** Current filter state — facetName → value (string | string[]). */
      this.state = {};

      /** Collection slug pulled from the root element. */
      this.collection = root.dataset.compassCollection || '';

      /** API base URL (auto-detected from <script> location or explicit). */
      this.apiBase = root.dataset.compassApi || this._detectApi();

      /** Results container. */
      this.resultsEl = root.querySelector('[data-compass-results]');

      /** Count display element. */
      this.countEl = root.querySelector('[data-compass-count]');

      /** Partial template name for server-side rendering of results. */
      this.partial = this.resultsEl?.dataset.compassPartial || '';

      /** Original (server-rendered) results HTML — used as initial state. */
      this._originalHTML = this.resultsEl ? this.resultsEl.innerHTML : '';

      /** Per-page count. */
      this.perPage = parseInt(root.dataset.compassPerpage, 10) || 12;

      /** Current page. */
      this.currentPage = 1;

      /** Loading flag to avoid concurrent requests. */
      this.loading = false;

      /** Facet elements keyed by facet name. */
      this.facets = {};

      /** Mobile drawer state. */
      this._drawerOpen = false;

      /** Abort controller for in-flight requests. */
      this._abortCtrl = null;
    }

    // ── Lifecycle ────────────────────────────────────────

    /** Discover facets, parse URL, bind events, optionally fetch. */
    init() {
      this._discoverFacets();
      this._buildMobileDrawer();
      this._bindGlobalEvents();

      // Restore state from URL
      const urlState = this._parseURL();
      if (Object.keys(urlState).length) {
        this.state = urlState;
        this._syncFacetsFromState();
        this.filter();
      }
    }

    // ── State ────────────────────────────────────────────

    /**
     * Set a facet value and trigger filtering.
     * @param {string} name  Facet name (matches data-compass-facet attribute)
     * @param {*}      value String, string[], or null to clear
     */
    setState(name, value) {
      const prev = { ...this.state };

      // Normalize: null/undefined/empty string → remove from state
      if (value === null || value === undefined || value === '') {
        delete this.state[name];
      } else if (Array.isArray(value) && value.length === 0) {
        delete this.state[name];
      } else {
        this.state[name] = value;
      }

      // Reset to page 1 when any filter changes (unless the change IS a page change)
      if (name !== 'page') {
        this.currentPage = 1;
        delete this.state.page;
      } else {
        this.currentPage = parseInt(value, 10) || 1;
      }

      if (!stateEqual(prev, this.state)) {
        emit(this.root, 'compass:stateChange', {
          facet: name,
          value,
          state: { ...this.state },
        });
        this.filter();
      }
    }

    /** Clear all filters and reset to default state. */
    reset() {
      this.state = {};
      this.currentPage = 1;
      this._syncFacetsFromState();
      this._updateURL();
      emit(this.root, 'compass:reset');

      // Restore original server-rendered results
      if (this.resultsEl && this._originalHTML) {
        this.resultsEl.innerHTML = this._originalHTML;
      }
      if (this.countEl) {
        this.countEl.textContent = '';
      }
      this._updateActiveBadge();
    }

    // ── Filtering (AJAX) ─────────────────────────────────

    /** Build query params and fetch filtered results from the server. */
    async filter() {
      if (this.loading) {
        // Abort previous in-flight request
        if (this._abortCtrl) this._abortCtrl.abort();
      }

      const allowed = emit(this.root, 'compass:beforeFilter', {
        state: { ...this.state },
      });
      if (!allowed) return;

      this.loading = true;
      this._abortCtrl = new AbortController();
      this._setLoading(true);

      // Build query string
      const params = new URLSearchParams();
      params.set('action', 'compass/filter');
      params.set('collection', this.collection);
      params.set('per_page', String(this.perPage));

      if (this.partial) {
        params.set('partial', this.partial);
      }

      for (const [key, val] of Object.entries(this.state)) {
        if (Array.isArray(val)) {
          // Multiple values → comma-separated
          params.set(key, val.join(','));
        } else {
          params.set(key, String(val));
        }
      }

      if (this.currentPage > 1) {
        params.set('page', String(this.currentPage));
      }

      const url = this.apiBase + '?' + params.toString();

      try {
        const res = await fetch(url, { signal: this._abortCtrl.signal });
        if (!res.ok) throw new Error(`HTTP ${res.status}`);

        const json = await res.json();

        // Update results
        this._updateResults(json);

        // Update facet counts if the API provides them
        if (json.facets) {
          this._updateFacetCounts(json.facets);
        }

        // Update URL
        this._updateURL();

        // Update mobile badge
        this._updateActiveBadge();

        emit(this.root, 'compass:afterFilter', {
          state: { ...this.state },
          total: json.total ?? 0,
          page: this.currentPage,
        });
      } catch (err) {
        if (err.name === 'AbortError') return; // Intentional abort
        console.error('[Compass] Filter request failed:', err);
      } finally {
        this.loading = false;
        this._setLoading(false);
      }
    }

    // ── DOM Updates ──────────────────────────────────────

    /**
     * Replace the results container with server-rendered HTML.
     * @param {object} json — API response
     */
    _updateResults(json) {
      if (!this.resultsEl) return;

      const html = json.html ?? '';
      const total = json.total ?? 0;

      if (total === 0 || !html) {
        const emptyMsg =
          this.resultsEl.dataset.compassEmpty || 'No results found.';
        this.resultsEl.innerHTML =
          '<div class="compass-empty">' + this._escapeHTML(emptyMsg) + '</div>';
      } else {
        this.resultsEl.innerHTML = html;
      }

      // Update count display
      if (this.countEl) {
        this.countEl.textContent = String(total);
      }

      // Render pagination if a pager facet exists
      this._renderPager(total);
    }

    /**
     * Update count badges on facet options and optionally disable zero-count items.
     * @param {object} facets — { facetName: { value: count, ... }, ... }
     */
    _updateFacetCounts(facets) {
      for (const [name, rawCounts] of Object.entries(facets)) {
        const facetEl = this.facets[name];
        if (!facetEl) continue;

        // Normalize: API returns [{value, display, count}, ...] arrays
        // Convert to { value: count } map for easy lookup
        const counts = {};
        if (Array.isArray(rawCounts)) {
          rawCounts.forEach((entry) => {
            counts[entry.value] = entry.count ?? 0;
          });
        } else if (rawCounts && typeof rawCounts === 'object') {
          // Already a { value: count } map (legacy)
          Object.assign(counts, rawCounts);
        }

        // Find all options with data-compass-value
        const options = facetEl.querySelectorAll('[data-compass-value]');
        options.forEach((opt) => {
          const val = opt.dataset.compassValue;
          const count = counts[val] ?? 0;
          const badge = opt.querySelector('.compass-count');
          if (badge) {
            badge.textContent = String(count);
          }
          opt.classList.toggle('compass-zero', count === 0);
        });

        // For <select> facets update option text counts
        const select = facetEl.querySelector('select');
        if (select) {
          Array.from(select.options).forEach((opt) => {
            if (!opt.value) return; // skip "All" placeholder
            const count = counts[opt.value] ?? 0;
            // Strip existing count suffix and re-add
            opt.textContent = opt.textContent.replace(/\s*\(\d+\)$/, '');
            opt.textContent += ` (${count})`;
          });
        }
      }
    }

    /** Add/remove the loading class on the results container. */
    _setLoading(on) {
      if (this.resultsEl) {
        this.resultsEl.classList.toggle('compass-loading', on);
      }
    }

    // ── Facet Discovery & Binding ────────────────────────

    /** Find all [data-compass-facet] elements inside the root and wire them up. */
    _discoverFacets() {
      const els = this.root.querySelectorAll('[data-compass-facet]');
      els.forEach((el) => {
        const name = el.dataset.compassFacet;
        const type = el.dataset.compassType || this._inferType(el);
        this.facets[name] = el;
        this._bindFacet(el, name, type);
      });

      // Also bind the reset button if present
      const resetBtn = this.root.querySelector('[data-compass-reset]');
      if (resetBtn) {
        resetBtn.addEventListener('click', (e) => {
          e.preventDefault();
          this.reset();
        });
      }
    }

    /** Infer facet type from element contents if data-compass-type is missing. */
    _inferType(el) {
      if (el.querySelector('select')) return 'dropdown';
      if (el.querySelector('input[type="checkbox"]')) return 'checkbox';
      if (el.querySelector('input[type="radio"]')) return 'radio';
      if (el.querySelector('input[type="search"], input[type="text"]'))
        return 'search';
      if (el.querySelector('input[type="range"]')) return 'range';
      if (el.querySelector('[data-compass-az]')) return 'az';
      if (el.querySelector('[data-compass-timesince]')) return 'timesince';
      if (el.querySelector('[data-compass-proximity]')) return 'proximity';
      if (el.querySelector('[data-compass-hierarchy]')) return 'hierarchy';
      return 'unknown';
    }

    /**
     * Bind event listeners for a specific facet element.
     */
    _bindFacet(el, name, type) {
      switch (type) {
        case 'dropdown':
          this._bindDropdown(el, name);
          break;
        case 'checkbox':
          this._bindCheckbox(el, name);
          break;
        case 'radio':
          this._bindRadio(el, name);
          break;
        case 'search':
          this._bindSearch(el, name);
          break;
        case 'range':
          this._bindRange(el, name);
          break;
        case 'az':
          this._bindAZ(el, name);
          break;
        case 'toggle':
          this._bindToggle(el, name);
          break;
        case 'proximity':
          this._bindProximity(el, name);
          break;
        case 'hierarchy':
          this._bindHierarchy(el, name);
          break;
        case 'timesince':
          this._bindTimeSince(el, name);
          break;
        case 'sort':
          this._bindSort(el, name);
          break;
        case 'pager':
          // Pager is rendered dynamically, no initial bind
          break;
        default:
          console.warn(`[Compass] Unknown facet type "${type}" for "${name}"`);
      }
    }

    // ── Facet Type Handlers ──────────────────────────────

    /** Dropdown: <select> change handler. */
    _bindDropdown(el, name) {
      const select = el.querySelector('select');
      if (!select) return;
      select.addEventListener('change', () => {
        this.setState(name, select.value || null);
      });
    }

    /** Checkboxes: multiple selection → array value. */
    _bindCheckbox(el, name) {
      const boxes = el.querySelectorAll('input[type="checkbox"]');
      boxes.forEach((cb) => {
        cb.addEventListener('change', () => {
          const checked = Array.from(
            el.querySelectorAll('input[type="checkbox"]:checked')
          ).map((c) => c.value);
          this.setState(name, checked.length ? checked : null);
        });
      });
    }

    /** Radio buttons: single selection. */
    _bindRadio(el, name) {
      const radios = el.querySelectorAll('input[type="radio"]');
      radios.forEach((r) => {
        r.addEventListener('change', () => {
          if (r.checked) {
            this.setState(name, r.value || null);
          }
        });
      });
    }

    /** Text search: debounced input. */
    _bindSearch(el, name) {
      const input = el.querySelector(
        'input[type="search"], input[type="text"]'
      );
      if (!input) return;

      const handler = debounce(() => {
        this.setState(name, input.value.trim() || null);
      }, 300);

      input.addEventListener('input', handler);

      // Also filter on Enter (immediate, no debounce)
      input.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
          e.preventDefault();
          this.setState(name, input.value.trim() || null);
        }
      });
    }

    /**
     * Range slider: dual-handle range.
     * Expects two <input type="range"> inside the facet element,
     * one with data-compass-range="min" and one with data-compass-range="max".
     */
    _bindRange(el, name) {
      const minInput = el.querySelector('[data-compass-range="min"]');
      const maxInput = el.querySelector('[data-compass-range="max"]');

      if (!minInput || !maxInput) {
        // Single range input fallback
        const single = el.querySelector('input[type="range"]');
        if (single) {
          const handler = debounce(() => {
            this.setState(name, single.value);
          }, 200);
          single.addEventListener('input', handler);
        }
        return;
      }

      const minDisplay = el.querySelector('[data-compass-range-min-display]');
      const maxDisplay = el.querySelector('[data-compass-range-max-display]');

      const handler = debounce(() => {
        let min = parseFloat(minInput.value);
        let max = parseFloat(maxInput.value);

        // Prevent crossing
        if (min > max) {
          const temp = min;
          min = max;
          max = temp;
          minInput.value = String(min);
          maxInput.value = String(max);
        }

        // Update display labels
        if (minDisplay) minDisplay.textContent = String(min);
        if (maxDisplay) maxDisplay.textContent = String(max);

        // Store as two state keys: name_min and name_max
        this.state[name + '_min'] = String(min);
        this.state[name + '_max'] = String(max);
        this.currentPage = 1;
        delete this.state.page;

        emit(this.root, 'compass:stateChange', {
          facet: name,
          value: { min, max },
          state: { ...this.state },
        });
        this.filter();
      }, 200);

      minInput.addEventListener('input', () => {
        if (minDisplay) minDisplay.textContent = minInput.value;
        handler();
      });
      maxInput.addEventListener('input', () => {
        if (maxDisplay) maxDisplay.textContent = maxInput.value;
        handler();
      });
    }

    /** A-Z listing: letter buttons. */
    _bindAZ(el, name) {
      const buttons = el.querySelectorAll('[data-compass-az]');
      buttons.forEach((btn) => {
        btn.addEventListener('click', (e) => {
          e.preventDefault();
          const letter = btn.dataset.compassAz;

          // Toggle: clicking active letter clears it
          if (this.state[name] === letter) {
            this.setState(name, null);
            btn.classList.remove('compass-az-active');
          } else {
            // Remove active from siblings
            buttons.forEach((b) => b.classList.remove('compass-az-active'));
            btn.classList.add('compass-az-active');
            this.setState(name, letter === 'all' ? null : letter);
          }
        });
      });
    }

    /** Toggle: on/off switch or checkbox. */
    _bindToggle(el, name) {
      const input = el.querySelector('input[type="checkbox"]');
      if (!input) return;
      input.addEventListener('change', () => {
        this.setState(name, input.checked ? '1' : null);
      });
    }

    /** Proximity: geolocation-based search. */
    _bindProximity(el, name) {
      const btn = el.querySelector('[data-compass-proximity]');
      const radiusSelect = el.querySelector('[data-compass-radius]');
      const statusEl = el.querySelector('[data-compass-proximity-status]');

      if (!btn) return;

      btn.addEventListener('click', (e) => {
        e.preventDefault();

        if (this.state.lat && this.state.lng) {
          // Already active — toggle off
          delete this.state.lat;
          delete this.state.lng;
          delete this.state.radius;
          btn.classList.remove('compass-proximity-active');
          if (statusEl) statusEl.textContent = '';
          this.filter();
          return;
        }

        if (!navigator.geolocation) {
          if (statusEl) statusEl.textContent = 'Geolocation not supported.';
          return;
        }

        btn.classList.add('compass-proximity-loading');
        if (statusEl) statusEl.textContent = 'Locating...';

        navigator.geolocation.getCurrentPosition(
          (pos) => {
            btn.classList.remove('compass-proximity-loading');
            btn.classList.add('compass-proximity-active');
            if (statusEl) statusEl.textContent = '';

            const radius = radiusSelect
              ? radiusSelect.value
              : el.dataset.compassDefaultRadius || '25';

            this.state.lat = String(pos.coords.latitude);
            this.state.lng = String(pos.coords.longitude);
            this.state.radius = radius;
            this.currentPage = 1;
            delete this.state.page;
            this.filter();
          },
          (err) => {
            btn.classList.remove('compass-proximity-loading');
            if (statusEl) {
              statusEl.textContent =
                err.code === 1
                  ? 'Location permission denied.'
                  : 'Unable to get location.';
            }
          },
          { enableHighAccuracy: false, timeout: 10000 }
        );
      });

      // Radius change (if already located)
      if (radiusSelect) {
        radiusSelect.addEventListener('change', () => {
          if (this.state.lat && this.state.lng) {
            this.state.radius = radiusSelect.value;
            this.currentPage = 1;
            delete this.state.page;
            this.filter();
          }
        });
      }
    }

    /**
     * Hierarchy: chained dropdowns.
     * Each <select> in the facet has data-compass-level="0", "1", etc.
     * Selecting a parent populates/enables the next level.
     */
    _bindHierarchy(el, name) {
      const selects = Array.from(
        el.querySelectorAll('select[data-compass-level]')
      ).sort(
        (a, b) =>
          parseInt(a.dataset.compassLevel) - parseInt(b.dataset.compassLevel)
      );

      if (!selects.length) return;

      selects.forEach((select, idx) => {
        select.addEventListener('change', () => {
          // Clear all child selects
          for (let i = idx + 1; i < selects.length; i++) {
            selects[i].innerHTML =
              '<option value="">All</option>';
            selects[i].disabled = true;
          }

          const value = select.value;
          if (!value) {
            // Cleared this level — update state with parent levels only
            const values = selects
              .slice(0, idx)
              .map((s) => s.value)
              .filter(Boolean);
            this.setState(name, values.length ? values : null);
            return;
          }

          // Enable next level and fetch its options
          if (idx + 1 < selects.length) {
            this._fetchHierarchyChildren(name, value, selects[idx + 1]);
          }

          // Build full hierarchy value
          const values = selects
            .slice(0, idx + 1)
            .map((s) => s.value)
            .filter(Boolean);
          this.setState(name, values.length ? values : null);
        });
      });
    }

    /** Fetch child options for a hierarchy level. */
    async _fetchHierarchyChildren(facetName, parentValue, childSelect) {
      const params = new URLSearchParams({
        action: 'compass/hierarchy',
        collection: this.collection,
        facet: facetName,
        parent: parentValue,
      });

      try {
        const res = await fetch(this.apiBase + '?' + params.toString());
        if (!res.ok) return;
        const json = await res.json();
        const options = json.data || [];

        childSelect.innerHTML = '<option value="">All</option>';
        options.forEach((opt) => {
          const el = document.createElement('option');
          el.value = opt.value || opt;
          el.textContent = opt.label || opt.value || opt;
          childSelect.appendChild(el);
        });
        childSelect.disabled = false;
      } catch (err) {
        console.error('[Compass] Hierarchy fetch failed:', err);
      }
    }

    /** Time-since: preset date range buttons/dropdown. */
    _bindTimeSince(el, name) {
      // Works with either buttons or a <select>
      const select = el.querySelector('select');
      if (select) {
        select.addEventListener('change', () => {
          this.setState(name, select.value || null);
        });
        return;
      }

      const buttons = el.querySelectorAll('[data-compass-timesince]');
      buttons.forEach((btn) => {
        btn.addEventListener('click', (e) => {
          e.preventDefault();
          const val = btn.dataset.compassTimesince;
          buttons.forEach((b) => b.classList.remove('compass-timesince-active'));

          if (this.state[name] === val) {
            // Toggle off
            this.setState(name, null);
          } else {
            btn.classList.add('compass-timesince-active');
            this.setState(name, val);
          }
        });
      });
    }

    /** Sort dropdown. */
    _bindSort(el, name) {
      const select = el.querySelector('select');
      if (!select) return;
      select.addEventListener('change', () => {
        this.setState('sort', select.value || null);
      });
    }

    // ── Pagination ───────────────────────────────────────

    /**
     * Render pagination controls inside the [data-compass-pager] element.
     * @param {number} total — total result count from API
     */
    _renderPager(total) {
      const pagerEl = this.root.querySelector('[data-compass-pager]');
      if (!pagerEl) return;

      const totalPages = Math.ceil(total / this.perPage);
      if (totalPages <= 1) {
        pagerEl.innerHTML = '';
        return;
      }

      const current = this.currentPage;
      let html = '<nav class="compass-pager" aria-label="Pagination">';

      // Previous button
      html += `<button class="compass-pager-btn compass-pager-prev" ${current <= 1 ? 'disabled' : ''} data-compass-page="${current - 1}" aria-label="Previous page">&laquo; Prev</button>`;

      // Page numbers — show window of 5 around current
      const startPage = Math.max(1, current - 2);
      const endPage = Math.min(totalPages, current + 2);

      if (startPage > 1) {
        html += `<button class="compass-pager-btn" data-compass-page="1">1</button>`;
        if (startPage > 2) {
          html += '<span class="compass-pager-ellipsis">&hellip;</span>';
        }
      }

      for (let i = startPage; i <= endPage; i++) {
        const active = i === current ? ' compass-pager-active' : '';
        html += `<button class="compass-pager-btn${active}" data-compass-page="${i}" ${i === current ? 'aria-current="page"' : ''}>${i}</button>`;
      }

      if (endPage < totalPages) {
        if (endPage < totalPages - 1) {
          html += '<span class="compass-pager-ellipsis">&hellip;</span>';
        }
        html += `<button class="compass-pager-btn" data-compass-page="${totalPages}">${totalPages}</button>`;
      }

      // Next button
      html += `<button class="compass-pager-btn compass-pager-next" ${current >= totalPages ? 'disabled' : ''} data-compass-page="${current + 1}" aria-label="Next page">Next &raquo;</button>`;

      html += '</nav>';
      pagerEl.innerHTML = html;

      // Bind page buttons
      pagerEl.querySelectorAll('[data-compass-page]').forEach((btn) => {
        btn.addEventListener('click', (e) => {
          e.preventDefault();
          if (btn.disabled) return;
          const page = parseInt(btn.dataset.compassPage, 10);
          if (page < 1 || page > totalPages) return;
          this.setState('page', String(page));

          // Scroll to top of results
          if (this.resultsEl) {
            this.resultsEl.scrollIntoView({ behavior: 'smooth', block: 'start' });
          }
        });
      });
    }

    // ── URL State Management ─────────────────────────────

    /** Push current filter state into the URL without page reload. */
    _updateURL() {
      const params = new URLSearchParams();

      for (const [key, val] of Object.entries(this.state)) {
        if (Array.isArray(val)) {
          params.set(key, val.join(','));
        } else {
          params.set(key, val);
        }
      }

      if (this.currentPage > 1) {
        params.set('page', String(this.currentPage));
      }

      const qs = params.toString();
      const newURL =
        window.location.pathname + (qs ? '?' + qs : '') + window.location.hash;

      history.pushState({ compass: true, state: { ...this.state } }, '', newURL);
    }

    /** Parse filter state from the current URL query params. */
    _parseURL() {
      const params = new URLSearchParams(window.location.search);
      const state = {};

      // Known multi-value params (checkboxes) are comma-separated
      params.forEach((val, key) => {
        if (key === 'page') {
          this.currentPage = parseInt(val, 10) || 1;
          state.page = val;
          return;
        }
        // If value contains commas, treat as array
        if (val.includes(',')) {
          state[key] = val.split(',').filter(Boolean);
        } else {
          state[key] = val;
        }
      });

      return state;
    }

    /** Sync facet UI elements to match the current state object. */
    _syncFacetsFromState() {
      for (const [name, el] of Object.entries(this.facets)) {
        const val = this.state[name];
        const type = el.dataset.compassType || this._inferType(el);

        switch (type) {
          case 'dropdown':
          case 'sort': {
            const select = el.querySelector('select');
            if (select) select.value = val || '';
            break;
          }
          case 'checkbox': {
            const values = Array.isArray(val) ? val : val ? [val] : [];
            el.querySelectorAll('input[type="checkbox"]').forEach((cb) => {
              cb.checked = values.includes(cb.value);
            });
            break;
          }
          case 'radio': {
            el.querySelectorAll('input[type="radio"]').forEach((r) => {
              r.checked = r.value === (val || '');
            });
            break;
          }
          case 'search': {
            const input = el.querySelector(
              'input[type="search"], input[type="text"]'
            );
            if (input) input.value = val || '';
            break;
          }
          case 'toggle': {
            const input = el.querySelector('input[type="checkbox"]');
            if (input) input.checked = val === '1';
            break;
          }
          case 'az': {
            el.querySelectorAll('[data-compass-az]').forEach((btn) => {
              btn.classList.toggle(
                'compass-az-active',
                btn.dataset.compassAz === val
              );
            });
            break;
          }
          case 'timesince': {
            const select = el.querySelector('select');
            if (select) {
              select.value = val || '';
            } else {
              el.querySelectorAll('[data-compass-timesince]').forEach((btn) => {
                btn.classList.toggle(
                  'compass-timesince-active',
                  btn.dataset.compassTimesince === val
                );
              });
            }
            break;
          }
          case 'range': {
            const minInput = el.querySelector('[data-compass-range="min"]');
            const maxInput = el.querySelector('[data-compass-range="max"]');
            if (minInput && this.state[name + '_min']) {
              minInput.value = this.state[name + '_min'];
            }
            if (maxInput && this.state[name + '_max']) {
              maxInput.value = this.state[name + '_max'];
            }
            break;
          }
          // Proximity and hierarchy restore from state is handled by their params
        }
      }
    }

    // ── Mobile Drawer ────────────────────────────────────

    /** Build the mobile filter drawer structure. */
    _buildMobileDrawer() {
      // Find the facets container
      const facetsContainer = this.root.querySelector('[data-compass-facets]');
      if (!facetsContainer) return;

      // Create the toggle button (visible on mobile only)
      const toggleBtn = document.createElement('button');
      toggleBtn.className = 'compass-mobile-toggle';
      toggleBtn.type = 'button';
      toggleBtn.setAttribute('aria-label', 'Toggle filters');
      toggleBtn.innerHTML =
        '<span class="compass-mobile-toggle-text">Filters</span>' +
        '<span class="compass-mobile-toggle-badge" data-compass-active-count></span>';

      // Create backdrop
      const backdrop = document.createElement('div');
      backdrop.className = 'compass-drawer-backdrop';

      // Create drawer wrapper
      const drawer = document.createElement('div');
      drawer.className = 'compass-drawer';

      // Drawer header
      const drawerHeader = document.createElement('div');
      drawerHeader.className = 'compass-drawer-header';
      drawerHeader.innerHTML =
        '<span class="compass-drawer-title">Filters</span>' +
        '<button class="compass-drawer-close" type="button" aria-label="Close filters">&times;</button>';

      // Drawer footer with apply button
      const drawerFooter = document.createElement('div');
      drawerFooter.className = 'compass-drawer-footer';
      drawerFooter.innerHTML =
        '<button class="compass-drawer-apply" type="button">Apply Filters</button>';

      // Move facets into drawer on mobile
      drawer.appendChild(drawerHeader);

      const drawerBody = document.createElement('div');
      drawerBody.className = 'compass-drawer-body';
      drawer.appendChild(drawerBody);
      drawer.appendChild(drawerFooter);

      // Insert elements
      this.root.insertBefore(toggleBtn, this.root.firstChild);
      this.root.appendChild(backdrop);
      this.root.appendChild(drawer);

      // Store references
      this._drawer = drawer;
      this._drawerBody = drawerBody;
      this._drawerBackdrop = backdrop;
      this._drawerToggle = toggleBtn;
      this._facetsContainer = facetsContainer;

      // Event: open drawer
      toggleBtn.addEventListener('click', () => this._openDrawer());

      // Event: close drawer
      drawerHeader
        .querySelector('.compass-drawer-close')
        .addEventListener('click', () => this._closeDrawer());
      backdrop.addEventListener('click', () => this._closeDrawer());
      drawerFooter
        .querySelector('.compass-drawer-apply')
        .addEventListener('click', () => this._closeDrawer());

      // Close on Escape
      document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && this._drawerOpen) {
          this._closeDrawer();
        }
      });
    }

    /** Open the mobile filter drawer. */
    _openDrawer() {
      if (!this._drawer || !this._facetsContainer) return;

      // Move facets into the drawer body
      this._drawerBody.appendChild(this._facetsContainer);

      this._drawer.classList.add('compass-drawer-open');
      this._drawerBackdrop.classList.add('compass-drawer-backdrop-visible');
      document.body.classList.add('compass-drawer-noscroll');
      this._drawerOpen = true;
    }

    /** Close the mobile filter drawer. */
    _closeDrawer() {
      if (!this._drawer || !this._facetsContainer) return;

      // Move facets back to original position (before results)
      const results = this.resultsEl || this._drawer;
      this.root.insertBefore(this._facetsContainer, results);

      this._drawer.classList.remove('compass-drawer-open');
      this._drawerBackdrop.classList.remove('compass-drawer-backdrop-visible');
      document.body.classList.remove('compass-drawer-noscroll');
      this._drawerOpen = false;
    }

    /** Update the active filter count badge on the mobile toggle. */
    _updateActiveBadge() {
      const badge = this.root.querySelector('[data-compass-active-count]');
      if (!badge) return;

      // Count non-empty state entries (exclude sort, page)
      const count = Object.entries(this.state).filter(
        ([k, v]) => k !== 'sort' && k !== 'page' && v !== null && v !== ''
      ).length;

      badge.textContent = count > 0 ? String(count) : '';
      badge.classList.toggle('compass-badge-visible', count > 0);
    }

    // ── Global Events ────────────────────────────────────

    /** Listen for browser back/forward to restore compass state. */
    _bindGlobalEvents() {
      window.addEventListener('popstate', (e) => {
        if (e.state?.compass) {
          this.state = e.state.state || {};
          this.currentPage = parseInt(this.state.page, 10) || 1;
          this._syncFacetsFromState();
          this.filter();
        } else {
          // No compass state — parse from URL (handles initial back nav)
          const urlState = this._parseURL();
          if (Object.keys(urlState).length) {
            this.state = urlState;
            this._syncFacetsFromState();
            this.filter();
          } else {
            this.reset();
          }
        }
      });
    }

    // ── Helpers ──────────────────────────────────────────

    /** Auto-detect the API URL from the current page context. */
    _detectApi() {
      // Look for a <meta> tag or <script> data attribute
      const meta = document.querySelector('meta[name="outpost-api"]');
      if (meta) return meta.getAttribute('content');

      // Default: assume /outpost/api.php relative to site root
      return '/outpost/api.php';
    }

    /** Escape HTML special characters. */
    _escapeHTML(str) {
      const div = document.createElement('div');
      div.textContent = str;
      return div.innerHTML;
    }
  }

  // ────────────────────────────────────────────────────────
  // Auto-initialization
  // ────────────────────────────────────────────────────────

  /** All active Compass instances on the page. */
  const instances = [];

  function initCompass() {
    const roots = document.querySelectorAll('[data-compass]');
    roots.forEach((root) => {
      // Prevent double-init
      if (root._compassInstance) return;

      const compass = new OutpostCompass(root);
      compass.init();
      root._compassInstance = compass;
      instances.push(compass);
    });
  }

  // Run on DOMContentLoaded, or immediately if DOM is already ready
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initCompass);
  } else {
    initCompass();
  }

  // Expose for external scripting
  window.OutpostCompass = OutpostCompass;
  window.__compassInstances = instances;
})();
