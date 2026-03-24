/**
 * Outpost CMS — Site Search Client
 * Auto-discovers [data-outpost-search] containers and wires up search.
 * No dependencies. Under 200 lines.
 */
(function () {
  'use strict';

  var DEBOUNCE_MS = 300;
  var MIN_CHARS = 2;
  var API_PATH = '/outpost/api.php?action=search/site';

  function init() {
    var containers = document.querySelectorAll('[data-outpost-search]');
    for (var i = 0; i < containers.length; i++) {
      setupContainer(containers[i]);
    }
  }

  function setupContainer(container) {
    var input = container.querySelector('[data-outpost-search-input]');
    var resultsEl = container.querySelector('[data-outpost-search-results]');
    if (!input || !resultsEl) return;

    var limit = parseInt(container.getAttribute('data-limit')) || 20;
    var timer = null;

    // Read initial query from URL
    var params = new URLSearchParams(window.location.search);
    var initialQ = params.get('q') || '';
    if (initialQ) {
      input.value = initialQ;
      doSearch(initialQ);
    }

    input.addEventListener('input', function () {
      clearTimeout(timer);
      var q = input.value.trim();
      updateURL(q);
      if (q.length < MIN_CHARS) {
        resultsEl.innerHTML = '';
        return;
      }
      timer = setTimeout(function () { doSearch(q); }, DEBOUNCE_MS);
    });

    input.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') {
        e.preventDefault();
        clearTimeout(timer);
        var q = input.value.trim();
        updateURL(q);
        if (q.length >= MIN_CHARS) doSearch(q);
      }
    });

    function doSearch(query) {
      resultsEl.innerHTML = '<div class="outpost-search-loading">Searching…</div>';
      resultsEl.classList.add('outpost-search-loading-state');

      var url = API_PATH + '&q=' + encodeURIComponent(query) + '&limit=' + limit;
      fetch(url)
        .then(function (r) {
          if (!r.ok) throw new Error('HTTP ' + r.status);
          return r.json();
        })
        .then(function (data) {
          resultsEl.classList.remove('outpost-search-loading-state');
          renderResults(resultsEl, data, query);
        })
        .catch(function () {
          resultsEl.classList.remove('outpost-search-loading-state');
          resultsEl.innerHTML = '<div class="outpost-search-error">Search unavailable. Please try again.</div>';
        });
    }
  }

  function renderResults(el, data, query) {
    if (!data.results || data.results.length === 0) {
      el.innerHTML = '<div class="outpost-search-empty">No results found for "' + escapeHTML(query) + '"</div>';
      return;
    }

    var html = '';
    for (var i = 0; i < data.results.length; i++) {
      var r = data.results[i];
      var typeLabel = r.collection_slug || r.type;
      html += '<div class="outpost-search-result">'
        + '<a href="' + escapeAttr(r.url) + '">'
        + (r.image ? '<img class="outpost-search-result-image" src="' + escapeAttr(r.image) + '" alt="" loading="lazy">' : '')
        + '<div class="outpost-search-result-body">'
        + '<div class="outpost-search-result-title">' + escapeHTML(r.title) + '</div>'
        + '<div class="outpost-search-result-type">' + escapeHTML(typeLabel) + '</div>'
        + (r.excerpt ? '<div class="outpost-search-result-excerpt">' + r.excerpt + '</div>' : '')
        + '</div>'
        + '</a>'
        + '</div>';
    }

    if (data.total > data.results.length) {
      html += '<div class="outpost-search-more">' + data.total + ' results found</div>';
    }

    el.innerHTML = html;
  }

  function updateURL(q) {
    if (!window.history || !window.history.replaceState) return;
    var url = new URL(window.location);
    if (q) {
      url.searchParams.set('q', q);
    } else {
      url.searchParams.delete('q');
    }
    window.history.replaceState(null, '', url);
  }

  function escapeHTML(str) {
    var div = document.createElement('div');
    div.appendChild(document.createTextNode(str || ''));
    return div.innerHTML;
  }

  function escapeAttr(str) {
    return (str || '').replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  }

  // Init on DOM ready
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
