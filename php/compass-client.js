/**
 * Outpost CMS — Compass Client v2
 * Data-attribute-driven faceted search & filtering.
 *
 * Discovery: finds all [data-compass] elements on the page,
 * groups them by data-collection, wires up events, syncs URL state,
 * and fetches filtered results via AJAX.
 *
 * No wrapper divs. No forced classes. Developer owns the HTML.
 *
 * No dependencies. Vanilla JS, ES2020+.
 */

(function () {
  'use strict';

  // ────────────────────────────────────────────────────────
  // Utilities
  // ────────────────────────────────────────────────────────

  function debounce(fn, ms) {
    let timer;
    return function (...args) {
      clearTimeout(timer);
      timer = setTimeout(() => fn.apply(this, args), ms);
    };
  }

  function emit(el, name, detail = {}) {
    return el.dispatchEvent(
      new CustomEvent(name, { bubbles: true, cancelable: true, detail })
    );
  }

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

  function escapeHTML(str) {
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
  }

  // ────────────────────────────────────────────────────────
  // CompassController — one per collection
  // ────────────────────────────────────────────────────────

  class CompassController {
    constructor(collection) {
      this.collection = collection;
      this.state = {};
      this.currentPage = 1;
      this.loading = false;
      this._abortCtrl = null;

      /** All registered elements by compass type → array of elements */
      this.elements = {};

      /** API base */
      this.apiBase = this._detectApi();

      /** Per-page (from pager element if present) */
      this.perPage = 12;

      /** Results container(s) */
      this.resultsEls = [];

      /** Count element(s) */
      this.countEls = [];

      /** Selections element(s) */
      this.selectionsEls = [];

      /** Original HTML for results (server-rendered) */
      this._originalHTML = new Map();

      /** Whether we use instant filtering or submit-button mode */
      this._hasSubmitButton = false;

      /** Pending state (used in submit mode — accumulated before submit) */
      this._pendingState = {};
    }

    // ── Registration ──────────────────────────────────────

    register(el) {
      const type = el.getAttribute('data-compass');
      if (!this.elements[type]) this.elements[type] = [];
      this.elements[type].push(el);
    }

    // ── Lifecycle ─────────────────────────────────────────

    init() {
      // Check for submit button — determines instant vs. manual mode
      this._hasSubmitButton = !!(this.elements['submit'] && this.elements['submit'].length);

      this._bindAll();
      this._autoPopulate().then(() => {
        this._restoreFromURL();
      });

      // Listen for popstate
      window.addEventListener('popstate', (e) => {
        if (e.state?.compass === this.collection) {
          this.state = e.state.state || {};
          this.currentPage = parseInt(this.state.page, 10) || 1;
          this._syncUIFromState();
          this.filter();
        } else {
          const urlState = this._parseURL();
          if (Object.keys(urlState).length) {
            this.state = urlState;
            this._syncUIFromState();
            this.filter();
          }
        }
      });
    }

    // ── State ─────────────────────────────────────────────

    setState(name, value, opts = {}) {
      const target = this._hasSubmitButton && !opts.force
        ? this._pendingState
        : this.state;

      const prev = { ...this.state };

      if (value === null || value === undefined || value === '') {
        delete target[name];
      } else if (Array.isArray(value) && value.length === 0) {
        delete target[name];
      } else {
        target[name] = value;
      }

      // In instant mode, filter immediately
      if (!this._hasSubmitButton || opts.force) {
        if (name !== 'page') {
          this.currentPage = 1;
          delete this.state.page;
        } else {
          this.currentPage = parseInt(value, 10) || 1;
        }

        if (!stateEqual(prev, this.state)) {
          emit(document, 'compass:stateChange', {
            collection: this.collection,
            facet: name,
            value,
            state: { ...this.state },
          });
          this.filter();
        }
      }
    }

    /** Apply pending state (submit mode) */
    _applyPending() {
      Object.assign(this.state, this._pendingState);
      this._pendingState = {};
      this.currentPage = 1;
      delete this.state.page;
      emit(document, 'compass:stateChange', {
        collection: this.collection,
        state: { ...this.state },
      });
      this.filter();
    }

    reset() {
      this.state = {};
      this._pendingState = {};
      this.currentPage = 1;
      this._syncUIFromState();
      this._updateURL();
      this._updateSelections();

      // Restore original server-rendered results
      this.resultsEls.forEach((el) => {
        const original = this._originalHTML.get(el);
        if (original) el.innerHTML = original;
      });

      this.countEls.forEach((el) => {
        el.textContent = el.dataset.compassOriginal || '';
      });

      emit(document, 'compass:reset', { collection: this.collection });
    }

    // ── Filtering ─────────────────────────────────────────

    async filter() {
      if (this.loading && this._abortCtrl) {
        this._abortCtrl.abort();
      }

      const allowed = emit(document, 'compass:beforeFilter', {
        collection: this.collection,
        state: { ...this.state },
      });
      if (!allowed) return;

      this.loading = true;
      this._abortCtrl = new AbortController();
      this._setLoading(true);

      const params = new URLSearchParams();
      params.set('action', 'compass/filter');
      params.set('collection', this.collection);
      params.set('per_page', String(this.perPage));

      // Check for partial template on results element
      const partial = this.resultsEls[0]?.dataset.compassPartial || '';
      if (partial) params.set('partial', partial);

      for (const [key, val] of Object.entries(this.state)) {
        if (Array.isArray(val)) {
          params.set(key, val.join(','));
        } else {
          params.set(key, String(val));
        }
      }

      // Include search fields from the search input
      const searchEls = this.elements['search'] || [];
      if (searchEls.length && this.state.q) {
        const fields = searchEls[0].getAttribute('data-fields');
        if (fields) params.set('fields', fields);
      }

      if (this.currentPage > 1) {
        params.set('page', String(this.currentPage));
      }

      const url = this.apiBase + '?' + params.toString();

      try {
        const res = await fetch(url, { signal: this._abortCtrl.signal });
        if (!res.ok) throw new Error(`HTTP ${res.status}`);

        const json = await res.json();
        this._updateResults(json);
        this._updateURL();
        this._updateSelections();

        if (json.facets) {
          this._updateFacetCounts(json.facets);
        }

        emit(document, 'compass:afterFilter', {
          collection: this.collection,
          state: { ...this.state },
          total: json.total ?? 0,
          page: this.currentPage,
        });
      } catch (err) {
        if (err.name === 'AbortError') return;
        console.error('[Compass] Filter request failed:', err);
      } finally {
        this.loading = false;
        this._setLoading(false);
      }
    }

    // ── DOM Updates ───────────────────────────────────────

    _updateResults(json) {
      let html = json.html ?? '';
      const total = json.total ?? 0;
      const items = json.items || json.data || [];

      // If no server-rendered HTML but we have items, render client-side from a <template>
      if (!html && total > 0 && items.length > 0 && this._clientTemplate) {
        html = this._renderFromTemplate(this._clientTemplate, items);
      }

      this.resultsEls.forEach((el) => {
        if (total === 0 || !html) {
          const emptyMsg = el.dataset.compassEmpty || 'No results found.';
          el.innerHTML = '<div class="compass-empty">' + escapeHTML(emptyMsg) + '</div>';
        } else {
          el.innerHTML = html;
        }
      });

      this.countEls.forEach((el) => {
        const template = el.dataset.compassTemplate || '{n} results';
        el.textContent = template.replace('{n}', String(total));
      });

      this._renderPager(total);
    }

    /**
     * Render items using a <template> string with {{field}} placeholders.
     */
    _renderFromTemplate(tpl, items) {
      return items.map((item) => {
        const d = item.data || item;
        let out = tpl;
        // Replace {{field}} placeholders
        out = out.replace(/\{\{(\w+)\}\}/g, (_, key) => {
          if (key === 'url') return item.url || ('/' + this.collection + '/' + (item.slug || ''));
          if (key === 'slug') return item.slug || '';
          if (key === 'id') return item.id || '';
          return escapeHTML(String(d[key] ?? ''));
        });
        return out;
      }).join('');
    }

    _setLoading(on) {
      this.resultsEls.forEach((el) => {
        el.classList.toggle('compass-loading', on);
      });
    }

    _updateFacetCounts(facets) {
      for (const [name, rawCounts] of Object.entries(facets)) {
        const counts = {};
        if (Array.isArray(rawCounts)) {
          rawCounts.forEach((entry) => {
            counts[entry.value] = entry.count ?? 0;
          });
        } else if (rawCounts && typeof rawCounts === 'object') {
          Object.assign(counts, rawCounts);
        }

        // Update select options
        (this.elements['dropdown'] || []).forEach((sel) => {
          const source = sel.getAttribute('data-source') || '';
          const facetName = source.replace(/^(folder|field|label):/, '');
          if (facetName !== name) return;
          Array.from(sel.options).forEach((opt) => {
            if (!opt.value) return;
            const count = counts[opt.value] ?? 0;
            opt.textContent = opt.textContent.replace(/\s*\(\d+\)$/, '');
            opt.textContent += ` (${count})`;
          });
        });

        // Update checkbox containers
        (this.elements['checkbox'] || []).forEach((container) => {
          const source = container.getAttribute('data-source') || '';
          const facetName = source.replace(/^(folder|field|label):/, '');
          if (facetName !== name) return;
          container.querySelectorAll('.compass-count').forEach((badge) => {
            const cb = badge.closest('label')?.querySelector('input[type="checkbox"]');
            if (cb) {
              const count = counts[cb.value] ?? 0;
              badge.textContent = String(count);
            }
          });
        });

        // Update radio containers
        (this.elements['radio'] || []).forEach((container) => {
          const source = container.getAttribute('data-source') || '';
          const facetName = source.replace(/^(folder|field|label):/, '');
          if (facetName !== name) return;
          container.querySelectorAll('.compass-count').forEach((badge) => {
            const rb = badge.closest('label')?.querySelector('input[type="radio"]');
            if (rb) {
              const count = counts[rb.value] ?? 0;
              badge.textContent = String(count);
            }
          });
        });
      }
    }

    // ── Selections Display ────────────────────────────────

    _updateSelections() {
      this.selectionsEls.forEach((el) => {
        const pills = [];
        for (const [key, val] of Object.entries(this.state)) {
          if (key === 'page' || key === 'sort') continue;
          const values = Array.isArray(val) ? val : [val];
          values.forEach((v) => {
            pills.push(
              '<span class="compass-pill">' +
                escapeHTML(v) +
                ' <button class="compass-pill-remove" data-compass-remove-facet="' +
                escapeHTML(key) + '" data-compass-remove-value="' + escapeHTML(v) +
                '" type="button" aria-label="Remove filter">&times;</button>' +
              '</span>'
            );
          });
        }
        el.innerHTML = pills.join('');

        // Bind removal
        el.querySelectorAll('.compass-pill-remove').forEach((btn) => {
          btn.addEventListener('click', (e) => {
            e.preventDefault();
            const facet = btn.dataset.compassRemoveFacet;
            const removeVal = btn.dataset.compassRemoveValue;
            const current = this.state[facet];
            if (Array.isArray(current)) {
              const filtered = current.filter((v) => v !== removeVal);
              this.setState(facet, filtered.length ? filtered : null, { force: true });
            } else {
              this.setState(facet, null, { force: true });
            }
          });
        });
      });
    }

    // ── Pagination ────────────────────────────────────────

    _renderPager(total) {
      const pagerEls = this.elements['pager'] || [];
      if (!pagerEls.length) return;

      const totalPages = Math.ceil(total / this.perPage);
      const current = this.currentPage;

      pagerEls.forEach((pagerEl) => {
        if (totalPages <= 1) {
          pagerEl.innerHTML = '';
          return;
        }

        let html = '';

        // Previous
        html += `<button class="compass-pager-btn" ${current <= 1 ? 'disabled' : ''} data-page="${current - 1}" aria-label="Previous page">&laquo; Prev</button>`;

        const startPage = Math.max(1, current - 2);
        const endPage = Math.min(totalPages, current + 2);

        if (startPage > 1) {
          html += `<button class="compass-pager-btn" data-page="1">1</button>`;
          if (startPage > 2) html += '<span class="compass-pager-ellipsis">&hellip;</span>';
        }

        for (let i = startPage; i <= endPage; i++) {
          const active = i === current ? ' compass-pager-active' : '';
          html += `<button class="compass-pager-btn${active}" data-page="${i}" ${i === current ? 'aria-current="page"' : ''}>${i}</button>`;
        }

        if (endPage < totalPages) {
          if (endPage < totalPages - 1) html += '<span class="compass-pager-ellipsis">&hellip;</span>';
          html += `<button class="compass-pager-btn" data-page="${totalPages}">${totalPages}</button>`;
        }

        // Next
        html += `<button class="compass-pager-btn" ${current >= totalPages ? 'disabled' : ''} data-page="${current + 1}" aria-label="Next page">Next &raquo;</button>`;

        pagerEl.innerHTML = html;

        pagerEl.querySelectorAll('[data-page]').forEach((btn) => {
          btn.addEventListener('click', (e) => {
            e.preventDefault();
            if (btn.disabled) return;
            const page = parseInt(btn.dataset.page, 10);
            if (page < 1 || page > totalPages) return;
            this.setState('page', String(page), { force: true });
            if (this.resultsEls[0]) {
              this.resultsEls[0].scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
          });
        });
      });
    }

    // ── Auto-Populate ─────────────────────────────────────
    // For selects/checkbox/radio containers that are empty,
    // fetch values from the compass/values API and populate.

    async _autoPopulate() {
      const toPopulate = [];

      // Dropdowns: if select has only 1 option (the default "All"), auto-populate
      (this.elements['dropdown'] || []).forEach((sel) => {
        if (sel.tagName === 'SELECT' && sel.options.length <= 1) {
          const source = sel.getAttribute('data-source');
          if (source) toPopulate.push({ el: sel, source, type: 'dropdown' });
        }
      });

      // Checkboxes: if container is empty, auto-populate
      (this.elements['checkbox'] || []).forEach((container) => {
        if (!container.children.length) {
          const source = container.getAttribute('data-source');
          if (source) toPopulate.push({ el: container, source, type: 'checkbox' });
        }
      });

      // Radio: if container is empty, auto-populate
      (this.elements['radio'] || []).forEach((container) => {
        if (!container.children.length) {
          const source = container.getAttribute('data-source');
          if (source) toPopulate.push({ el: container, source, type: 'radio' });
        }
      });

      // A-Z: if container is empty, fill with letter buttons
      (this.elements['az'] || []).forEach((container) => {
        if (!container.children.length) {
          let html = '<button class="compass-az-btn compass-az-active" data-letter="" type="button">All</button>';
          for (let i = 65; i <= 90; i++) {
            const l = String.fromCharCode(i);
            html += `<button class="compass-az-btn" data-letter="${l}" type="button">${l}</button>`;
          }
          container.innerHTML = html;
          this._bindAZ(container);
        }
      });

      // Fetch values for items that need populating
      for (const item of toPopulate) {
        try {
          const facetName = (item.source || '').replace(/^(folder|field|label):/, '');
          const params = new URLSearchParams({
            action: 'compass/values',
            collection: this.collection,
            facet: facetName,
          });
          const res = await fetch(this.apiBase + '?' + params.toString());
          if (!res.ok) continue;
          const json = await res.json();
          const values = json.data || json.values || [];

          if (item.type === 'dropdown' && item.el.tagName === 'SELECT') {
            // values is array of {value, display, count} or {value: count} map
            const entries = Array.isArray(values)
              ? values
              : Object.entries(values).map(([v, c]) => ({
                  value: v,
                  display: typeof c === 'object' ? c.display || v : v,
                  count: typeof c === 'object' ? c.count : c,
                }));
            const showCounts = item.el.getAttribute('data-show-counts') === 'true';
            entries.forEach((entry) => {
              const opt = document.createElement('option');
              opt.value = entry.value;
              opt.textContent = (entry.display || entry.value) + (showCounts && entry.count != null ? ` (${entry.count})` : '');
              item.el.appendChild(opt);
            });
            // Auto-upgrade to searchable dropdown if threshold met
            this._maybeUpgradeDropdown(item.el, entries, facetName);
          } else if (item.type === 'checkbox') {
            const entries = Array.isArray(values) ? values : Object.entries(values).map(([v, c]) => ({
              value: v, display: typeof c === 'object' ? c.display || v : v, count: typeof c === 'object' ? c.count : c
            }));
            const facetName = (item.source || '').replace(/^(folder|field|label):/, '');
            let html = '';
            entries.forEach((entry) => {
              html += `<label class="compass-checkbox"><input type="checkbox" value="${escapeHTML(entry.value)}"> ${escapeHTML(entry.display || entry.value)} <span class="compass-count">${entry.count ?? ''}</span></label>`;
            });
            item.el.innerHTML = html;
            this._bindCheckbox(item.el, facetName);
          } else if (item.type === 'radio') {
            const entries = Array.isArray(values) ? values : Object.entries(values).map(([v, c]) => ({
              value: v, display: typeof c === 'object' ? c.display || v : v, count: typeof c === 'object' ? c.count : c
            }));
            const facetName = (item.source || '').replace(/^(folder|field|label):/, '');
            let html = '';
            entries.forEach((entry) => {
              html += `<label class="compass-radio"><input type="radio" name="compass_${escapeHTML(facetName)}" value="${escapeHTML(entry.value)}"> ${escapeHTML(entry.display || entry.value)} <span class="compass-count">${entry.count ?? ''}</span></label>`;
            });
            item.el.innerHTML = html;
            this._bindRadio(item.el, facetName);
          }
        } catch (err) {
          console.error('[Compass] Auto-populate failed:', err);
        }
      }
    }

    // ── Binding ───────────────────────────────────────────

    _bindAll() {
      // Search inputs
      (this.elements['search'] || []).forEach((el) => this._bindSearch(el));

      // Dropdowns
      (this.elements['dropdown'] || []).forEach((el) => this._bindDropdown(el));

      // Checkboxes
      (this.elements['checkbox'] || []).forEach((el) => {
        const source = el.getAttribute('data-source') || '';
        const name = source.replace(/^(folder|field|label):/, '');
        if (el.querySelectorAll('input[type="checkbox"]').length) {
          this._bindCheckbox(el, name);
        }
        // If empty, _autoPopulate will handle binding after fetch
      });

      // Radio
      (this.elements['radio'] || []).forEach((el) => {
        const source = el.getAttribute('data-source') || '';
        const name = source.replace(/^(folder|field|label):/, '');
        if (el.querySelectorAll('input[type="radio"]').length) {
          this._bindRadio(el, name);
        }
      });

      // Range (min/max pair)
      (this.elements['range-min'] || []).forEach((el) => this._bindRangeMin(el));
      (this.elements['range-max'] || []).forEach((el) => this._bindRangeMax(el));

      // A-Z containers
      (this.elements['az'] || []).forEach((el) => {
        if (el.children.length) this._bindAZ(el);
      });

      // Toggle
      (this.elements['toggle'] || []).forEach((el) => this._bindToggle(el));

      // Proximity
      (this.elements['proximity'] || []).forEach((el) => this._bindProximity(el));

      // Sort
      (this.elements['sort'] || []).forEach((el) => this._bindSort(el));

      // Reset
      (this.elements['reset'] || []).forEach((el) => {
        el.addEventListener('click', (e) => {
          e.preventDefault();
          this.reset();
        });
      });

      // Submit
      (this.elements['submit'] || []).forEach((el) => {
        el.addEventListener('click', (e) => {
          e.preventDefault();
          // Merge pending state into real state and filter
          this._applyPending();
        });
      });

      // Results containers
      this._clientTemplate = null;
      (this.elements['results'] || []).forEach((el) => {
        this.resultsEls.push(el);
        this._originalHTML.set(el, el.innerHTML);
        // Cache client-side template before it gets replaced by filtering
        const tplEl = el.querySelector('template[data-compass-template]');
        if (tplEl && !this._clientTemplate) {
          // Use .innerHTML — <template> content is inert HTML
          this._clientTemplate = tplEl.innerHTML;
        }
      });

      // Count elements
      (this.elements['count'] || []).forEach((el) => {
        this.countEls.push(el);
        el.dataset.compassOriginal = el.textContent;
      });

      // Selections
      (this.elements['selections'] || []).forEach((el) => {
        this.selectionsEls.push(el);
      });

      // Pager — set perPage
      (this.elements['pager'] || []).forEach((el) => {
        const pp = parseInt(el.getAttribute('data-per-page'), 10);
        if (pp) this.perPage = pp;
      });
    }

    // ── Individual Binders ────────────────────────────────

    _bindSearch(el) {
      const handler = debounce(() => {
        this.setState('q', el.value.trim() || null);
      }, 300);

      el.addEventListener('input', handler);
      el.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
          e.preventDefault();
          if (this._hasSubmitButton) {
            this._pendingState.q = el.value.trim() || undefined;
            if (!this._pendingState.q) delete this._pendingState.q;
            this._applyPending();
          } else {
            this.setState('q', el.value.trim() || null);
          }
        }
      });
    }

    _bindDropdown(el) {
      if (el.tagName !== 'SELECT') return;
      const source = el.getAttribute('data-source') || '';
      const name = source.replace(/^(folder|field|label):/, '') || 'filter';

      el.addEventListener('change', () => {
        this.setState(name, el.value || null);
      });
    }

    /**
     * Upgrade a <select> to a searchable dropdown if:
     * - data-searchable="true" is set, OR
     * - options exceed threshold (15) and data-searchable is not "false"
     */
    _maybeUpgradeDropdown(sel, entries, facetName) {
      const attr = sel.getAttribute('data-searchable');
      const threshold = parseInt(sel.getAttribute('data-searchable-threshold')) || 15;
      const shouldUpgrade = attr === 'true' || (attr !== 'false' && sel.options.length > threshold);
      if (!shouldUpgrade) return;

      // Hide original select
      sel.style.display = 'none';

      // Build custom searchable dropdown
      const wrap = document.createElement('div');
      wrap.className = 'compass-searchable-dropdown';
      // Copy width-related classes from the original select
      const cls = sel.className.replace(/\binput\b/, '').trim();
      if (cls) wrap.className += ' ' + cls;

      const trigger = document.createElement('button');
      trigger.type = 'button';
      trigger.className = 'compass-sd-trigger';
      const defaultText = sel.options[0] ? sel.options[0].textContent : 'Select...';
      trigger.innerHTML = '<span class="compass-sd-label">' + escapeHTML(defaultText) + '</span><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>';

      const panel = document.createElement('div');
      panel.className = 'compass-sd-panel';
      panel.style.display = 'none';

      const searchInput = document.createElement('input');
      searchInput.type = 'text';
      searchInput.className = 'compass-sd-search';
      searchInput.placeholder = 'Type to filter...';

      const list = document.createElement('div');
      list.className = 'compass-sd-list';

      // "All" option
      const allItem = document.createElement('div');
      allItem.className = 'compass-sd-item compass-sd-item-active';
      allItem.setAttribute('data-value', '');
      allItem.textContent = defaultText;
      list.appendChild(allItem);

      // Build option items
      entries.forEach((entry) => {
        const item = document.createElement('div');
        item.className = 'compass-sd-item';
        item.setAttribute('data-value', entry.value);
        item.textContent = (entry.display || entry.value) + (entry.count != null ? ' (' + entry.count + ')' : '');
        list.appendChild(item);
      });

      panel.appendChild(searchInput);
      panel.appendChild(list);
      wrap.appendChild(trigger);
      wrap.appendChild(panel);
      sel.parentNode.insertBefore(wrap, sel.nextSibling);

      // Toggle panel
      let open = false;
      const togglePanel = () => {
        open = !open;
        panel.style.display = open ? '' : 'none';
        if (open) { searchInput.value = ''; filterList(''); searchInput.focus(); }
      };
      trigger.addEventListener('click', togglePanel);

      // Close on outside click
      document.addEventListener('click', (e) => {
        if (open && !wrap.contains(e.target)) {
          open = false;
          panel.style.display = 'none';
        }
      });

      // Filter list
      const filterList = (q) => {
        const lower = q.toLowerCase();
        list.querySelectorAll('.compass-sd-item').forEach((item) => {
          const val = item.getAttribute('data-value');
          if (val === '') { item.style.display = ''; return; } // always show "All"
          item.style.display = item.textContent.toLowerCase().includes(lower) ? '' : 'none';
        });
      };
      searchInput.addEventListener('input', () => filterList(searchInput.value));

      // Select item
      list.addEventListener('click', (e) => {
        const item = e.target.closest('.compass-sd-item');
        if (!item) return;
        const val = item.getAttribute('data-value');
        // Update active state
        list.querySelectorAll('.compass-sd-item').forEach((i) => i.classList.remove('compass-sd-item-active'));
        item.classList.add('compass-sd-item-active');
        // Update trigger label
        trigger.querySelector('.compass-sd-label').textContent = val ? item.textContent : defaultText;
        // Update hidden select
        sel.value = val;
        sel.dispatchEvent(new Event('change', { bubbles: true }));
        // Close
        open = false;
        panel.style.display = 'none';
      });

      // Keyboard: Escape closes
      searchInput.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') { open = false; panel.style.display = 'none'; trigger.focus(); }
      });
    }

    _bindCheckbox(container, name) {
      container.querySelectorAll('input[type="checkbox"]').forEach((cb) => {
        cb.addEventListener('change', () => {
          const checked = Array.from(
            container.querySelectorAll('input[type="checkbox"]:checked')
          ).map((c) => c.value);
          this.setState(name, checked.length ? checked : null);
        });
      });
    }

    _bindRadio(container, name) {
      container.querySelectorAll('input[type="radio"]').forEach((r) => {
        r.addEventListener('change', () => {
          if (r.checked) this.setState(name, r.value || null);
        });
      });
    }

    _bindRangeMin(el) {
      const source = el.getAttribute('data-source') || '';
      const name = source.replace(/^(folder|field|label):/, '') || 'range';
      const handler = debounce(() => {
        this.state[name + '_min'] = el.value;
        this.currentPage = 1;
        delete this.state.page;
        this.filter();
      }, 200);
      el.addEventListener('input', handler);
    }

    _bindRangeMax(el) {
      const source = el.getAttribute('data-source') || '';
      const name = source.replace(/^(folder|field|label):/, '') || 'range';
      const handler = debounce(() => {
        this.state[name + '_max'] = el.value;
        this.currentPage = 1;
        delete this.state.page;
        this.filter();
      }, 200);
      el.addEventListener('input', handler);
    }

    _bindAZ(container) {
      const source = container.getAttribute('data-source') || '';
      const name = source.replace(/^(folder|field|label):/, '') || 'az';
      const buttons = container.querySelectorAll('[data-letter]');

      buttons.forEach((btn) => {
        btn.addEventListener('click', (e) => {
          e.preventDefault();
          const letter = btn.dataset.letter;

          if (this.state[name] === letter && letter !== '') {
            this.setState(name, null);
            buttons.forEach((b) => b.classList.remove('compass-az-active'));
            container.querySelector('[data-letter=""]')?.classList.add('compass-az-active');
          } else {
            buttons.forEach((b) => b.classList.remove('compass-az-active'));
            btn.classList.add('compass-az-active');
            this.setState(name, letter === '' ? null : letter);
          }
        });
      });
    }

    _bindToggle(el) {
      if (el.tagName === 'INPUT' && el.type === 'checkbox') {
        const source = el.getAttribute('data-source') || '';
        const name = source.replace(/^(folder|field|label):/, '') || 'toggle';
        el.addEventListener('change', () => {
          this.setState(name, el.checked ? '1' : null);
        });
      }
    }

    _bindProximity(el) {
      const radius = el.getAttribute('data-radius') || '25';
      const unit = el.getAttribute('data-unit') || 'miles';

      el.addEventListener('click', (e) => {
        e.preventDefault();

        if (this.state.lat && this.state.lng) {
          // Toggle off
          delete this.state.lat;
          delete this.state.lng;
          delete this.state.radius;
          el.classList.remove('compass-proximity-active');
          this.filter();
          return;
        }

        if (!navigator.geolocation) return;

        el.classList.add('compass-proximity-loading');
        navigator.geolocation.getCurrentPosition(
          (pos) => {
            el.classList.remove('compass-proximity-loading');
            el.classList.add('compass-proximity-active');
            this.state.lat = String(pos.coords.latitude);
            this.state.lng = String(pos.coords.longitude);
            this.state.radius = radius;
            this.currentPage = 1;
            delete this.state.page;
            this.filter();
          },
          () => {
            el.classList.remove('compass-proximity-loading');
          },
          { enableHighAccuracy: false, timeout: 10000 }
        );
      });
    }

    _bindSort(el) {
      if (el.tagName !== 'SELECT') return;
      el.addEventListener('change', () => {
        this.setState('sort', el.value || null, { force: true });
      });
    }

    // ── URL ───────────────────────────────────────────────

    _updateURL() {
      const params = new URLSearchParams();
      for (const [key, val] of Object.entries(this.state)) {
        if (Array.isArray(val)) {
          params.set(key, val.join(','));
        } else {
          params.set(key, val);
        }
      }
      if (this.currentPage > 1) params.set('page', String(this.currentPage));

      const qs = params.toString();
      const newURL = window.location.pathname + (qs ? '?' + qs : '') + window.location.hash;
      history.pushState({ compass: this.collection, state: { ...this.state } }, '', newURL);
    }

    _parseURL() {
      const params = new URLSearchParams(window.location.search);
      const state = {};
      params.forEach((val, key) => {
        if (key === 'page') {
          this.currentPage = parseInt(val, 10) || 1;
          state.page = val;
          return;
        }
        if (val.includes(',')) {
          state[key] = val.split(',').filter(Boolean);
        } else {
          state[key] = val;
        }
      });
      return state;
    }

    _restoreFromURL() {
      const urlState = this._parseURL();
      if (Object.keys(urlState).length) {
        this.state = urlState;
        this._syncUIFromState();
        // Emit stateChange so page-level scripts (e.g. toggle carousels/results) react
        emit(document, 'compass:stateChange', {
          collection: this.collection,
          state: { ...this.state },
        });
        this.filter();
      }
    }

    _syncUIFromState() {
      // Search
      (this.elements['search'] || []).forEach((el) => {
        el.value = this.state.q || '';
      });

      // Dropdowns
      (this.elements['dropdown'] || []).forEach((el) => {
        if (el.tagName !== 'SELECT') return;
        const source = el.getAttribute('data-source') || '';
        const name = source.replace(/^(folder|field|label):/, '') || 'filter';
        el.value = this.state[name] || '';
      });

      // Checkboxes
      (this.elements['checkbox'] || []).forEach((container) => {
        const source = container.getAttribute('data-source') || '';
        const name = source.replace(/^(folder|field|label):/, '');
        const vals = this.state[name];
        const valArr = Array.isArray(vals) ? vals : vals ? [vals] : [];
        container.querySelectorAll('input[type="checkbox"]').forEach((cb) => {
          cb.checked = valArr.includes(cb.value);
        });
      });

      // Radio
      (this.elements['radio'] || []).forEach((container) => {
        const source = container.getAttribute('data-source') || '';
        const name = source.replace(/^(folder|field|label):/, '');
        container.querySelectorAll('input[type="radio"]').forEach((r) => {
          r.checked = r.value === (this.state[name] || '');
        });
      });

      // Toggle
      (this.elements['toggle'] || []).forEach((el) => {
        if (el.tagName === 'INPUT' && el.type === 'checkbox') {
          const source = el.getAttribute('data-source') || '';
          const name = source.replace(/^(folder|field|label):/, '') || 'toggle';
          el.checked = this.state[name] === '1';
        }
      });

      // Sort
      (this.elements['sort'] || []).forEach((el) => {
        if (el.tagName === 'SELECT') {
          el.value = this.state.sort || '';
        }
      });

      // A-Z
      (this.elements['az'] || []).forEach((container) => {
        const source = container.getAttribute('data-source') || '';
        const name = source.replace(/^(folder|field|label):/, '') || 'az';
        container.querySelectorAll('[data-letter]').forEach((btn) => {
          btn.classList.toggle('compass-az-active', btn.dataset.letter === (this.state[name] || ''));
        });
      });
    }

    // ── Helpers ───────────────────────────────────────────

    _detectApi() {
      const meta = document.querySelector('meta[name="outpost-api"]');
      if (meta) return meta.getAttribute('content');
      return '/outpost/api.php';
    }
  }

  // ────────────────────────────────────────────────────────
  // Auto-initialization
  // ────────────────────────────────────────────────────────

  const controllers = {};

  function initCompass() {
    // Discover all [data-compass] elements and group by collection
    const els = document.querySelectorAll('[data-compass]');
    els.forEach((el) => {
      const collection = el.getAttribute('data-collection');
      if (!collection) return;

      if (!controllers[collection]) {
        controllers[collection] = new CompassController(collection);
      }
      controllers[collection].register(el);
    });

    // Initialize each controller
    for (const [col, ctrl] of Object.entries(controllers)) {
      if (!ctrl._initialized) {
        ctrl.init();
        ctrl._initialized = true;
      }
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initCompass);
  } else {
    initCompass();
  }

  // Expose for external scripting
  window.OutpostCompass = CompassController;
  window.__compassControllers = controllers;
})();
