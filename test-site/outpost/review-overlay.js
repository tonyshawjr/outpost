/**
 * Outpost CMS — Review Overlay
 * Lightweight vanilla JS script injected when ?review=TOKEN is present.
 * Allows external reviewers to leave comments on any page element.
 */
(function() {
  'use strict';

  var TOKEN = window.__OUTPOST_REVIEW_TOKEN__ || '';
  var API   = window.__OUTPOST_API_URL__ || '/outpost/api.php';
  if (!TOKEN) return;

  var PAGE_PATH = window.location.pathname;
  var comments  = [];
  var panel     = null;
  var activePin = null;
  var authorName  = localStorage.getItem('outpost_review_name') || '';
  var authorEmail = localStorage.getItem('outpost_review_email') || '';

  // ── Styles ───────────────────────────────────────────
  var style = document.createElement('style');
  style.textContent = [
    '.opr-fab{position:fixed;bottom:24px;right:24px;z-index:99999;display:flex;align-items:center;gap:6px;padding:10px 18px;background:#111;color:#fff;border:none;border-radius:24px;font:14px/1 -apple-system,system-ui,sans-serif;cursor:pointer;box-shadow:0 4px 16px rgba(0,0,0,.25);transition:transform .15s,box-shadow .15s}',
    '.opr-fab:hover{transform:translateY(-1px);box-shadow:0 6px 20px rgba(0,0,0,.35)}',
    '.opr-fab svg{width:16px;height:16px}',
    '.opr-fab-count{background:#ef4444;color:#fff;font-size:11px;font-weight:600;min-width:18px;height:18px;border-radius:9px;display:flex;align-items:center;justify-content:center;padding:0 5px;margin-left:4px}',
    '.opr-highlight{outline:2px solid #3b82f6!important;outline-offset:2px;cursor:crosshair!important}',
    '.opr-pin{position:absolute;z-index:99998;width:24px;height:24px;border-radius:12px;background:#111;color:#fff;font-size:11px;font-weight:700;display:flex;align-items:center;justify-content:center;cursor:pointer;box-shadow:0 2px 8px rgba(0,0,0,.3);transition:transform .1s;border:2px solid #fff}',
    '.opr-pin:hover{transform:scale(1.15)}',
    '.opr-pin.resolved{background:#6b7280;opacity:.5}',
    '.opr-panel{position:fixed;top:0;right:0;bottom:0;width:380px;max-width:100vw;z-index:100000;background:#fff;box-shadow:-4px 0 24px rgba(0,0,0,.15);display:flex;flex-direction:column;font:14px/1.5 -apple-system,system-ui,sans-serif;color:#1f2937;transition:transform .2s}',
    '.opr-panel.hidden{transform:translateX(100%)}',
    '.opr-panel-header{display:flex;align-items:center;gap:8px;padding:16px 20px;border-bottom:1px solid #e5e7eb}',
    '.opr-panel-title{font-size:15px;font-weight:600;flex:1}',
    '.opr-panel-close{background:none;border:none;cursor:pointer;padding:4px;color:#6b7280;font-size:18px;line-height:1}',
    '.opr-panel-close:hover{color:#111}',
    '.opr-panel-body{flex:1;overflow-y:auto;padding:16px 20px}',
    '.opr-comment{padding:12px 0;border-bottom:1px solid #f3f4f6}',
    '.opr-comment:last-child{border-bottom:none}',
    '.opr-comment-header{display:flex;align-items:center;gap:8px;margin-bottom:6px}',
    '.opr-avatar{width:28px;height:28px;border-radius:14px;background:#e5e7eb;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:600;color:#6b7280;flex-shrink:0}',
    '.opr-author{font-weight:600;font-size:13px}',
    '.opr-time{font-size:12px;color:#9ca3af}',
    '.opr-body{font-size:14px;color:#374151;line-height:1.5}',
    '.opr-reply{font-size:13px;color:#6b7280;margin-left:36px;padding:8px 0}',
    '.opr-reply .opr-author{font-size:12px}',
    '.opr-form{padding:16px 20px;border-top:1px solid #e5e7eb}',
    '.opr-input{width:100%;padding:8px 10px;border:1px solid #d1d5db;border-radius:6px;font:14px/1.4 -apple-system,system-ui,sans-serif;color:#1f2937;resize:none;box-sizing:border-box;margin-bottom:8px}',
    '.opr-input:focus{outline:none;border-color:#3b82f6;box-shadow:0 0 0 2px rgba(59,130,246,.2)}',
    '.opr-row{display:flex;gap:8px;margin-bottom:8px}',
    '.opr-row .opr-input{flex:1;margin-bottom:0}',
    '.opr-submit{padding:8px 16px;background:#111;color:#fff;border:none;border-radius:6px;font:14px/1 -apple-system,system-ui,sans-serif;font-weight:500;cursor:pointer}',
    '.opr-submit:hover{background:#333}',
    '.opr-submit:disabled{opacity:.5;cursor:not-allowed}',
    '.opr-empty{text-align:center;padding:40px 20px;color:#9ca3af;font-size:14px}',
    '.opr-selector-label{font-size:12px;color:#9ca3af;margin-bottom:4px;font-family:monospace;word-break:break-all}',
    '@media(max-width:480px){.opr-panel{width:100vw}}',
  ].join('\n');
  document.head.appendChild(style);

  // ── API ──────────────────────────────────────────────
  function apiUrl(action, params) {
    var url = API + '?action=' + action;
    if (params) {
      for (var k in params) {
        if (params[k] !== undefined && params[k] !== null) {
          url += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(params[k]);
        }
      }
    }
    return url;
  }

  function fetchComments(cb) {
    var xhr = new XMLHttpRequest();
    xhr.open('GET', apiUrl('review/comments', { token: TOKEN, page_path: PAGE_PATH }));
    xhr.onload = function() {
      if (xhr.status === 200) {
        var data = JSON.parse(xhr.responseText);
        comments = data.comments || [];
        cb(null, comments);
      } else {
        cb(new Error('Failed to load comments'));
      }
    };
    xhr.onerror = function() { cb(new Error('Network error')); };
    xhr.send();
  }

  function postComment(body, selector, parentId, cb) {
    var xhr = new XMLHttpRequest();
    xhr.open('POST', apiUrl('review/comment'));
    xhr.setRequestHeader('Content-Type', 'application/json');
    xhr.onload = function() {
      if (xhr.status === 201) {
        var data = JSON.parse(xhr.responseText);
        cb(null, data.comment);
      } else {
        var err;
        try { err = JSON.parse(xhr.responseText).error; } catch(e) { err = 'Error'; }
        cb(new Error(err));
      }
    };
    xhr.onerror = function() { cb(new Error('Network error')); };
    xhr.send(JSON.stringify({
      token: TOKEN,
      author_name: authorName,
      author_email: authorEmail,
      body: body,
      page_path: PAGE_PATH,
      element_selector: selector || '',
      parent_id: parentId || null,
    }));
  }

  // ── CSS Selector ─────────────────────────────────────
  function getSelector(el) {
    if (el.id) return '#' + el.id;
    var parts = [];
    while (el && el !== document.body && el !== document.documentElement) {
      var tag = el.tagName.toLowerCase();
      if (el.id) { parts.unshift('#' + el.id); break; }
      var parent = el.parentElement;
      if (parent) {
        var sibs = parent.children;
        var idx = 0;
        for (var i = 0; i < sibs.length; i++) {
          if (sibs[i].tagName === el.tagName) {
            idx++;
            if (sibs[i] === el) break;
          }
        }
        parts.unshift(tag + ':nth-of-type(' + idx + ')');
      } else {
        parts.unshift(tag);
      }
      el = parent;
    }
    return parts.join(' > ');
  }

  // ── Time formatting ──────────────────────────────────
  function timeAgo(dateStr) {
    var date = new Date(dateStr + (dateStr.indexOf('Z') === -1 ? 'Z' : ''));
    var diff = (Date.now() - date.getTime()) / 1000;
    if (diff < 60) return 'just now';
    if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
    if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
    return Math.floor(diff / 86400) + 'd ago';
  }

  function initials(name) {
    return (name || '?').split(/\s+/).map(function(w) { return w[0]; }).join('').toUpperCase().substring(0, 2);
  }

  // ── Pins ─────────────────────────────────────────────
  function renderPins() {
    // Remove old pins
    document.querySelectorAll('.opr-pin').forEach(function(p) { p.remove(); });

    comments.forEach(function(c, i) {
      if (!c.element_selector) return;
      var el;
      try { el = document.querySelector(c.element_selector); } catch(e) { return; }
      if (!el) return;

      var rect = el.getBoundingClientRect();
      var pin = document.createElement('div');
      pin.className = 'opr-pin' + (c.status === 'resolved' ? ' resolved' : '');
      pin.textContent = String(i + 1);
      pin.style.top = (window.scrollY + rect.top - 12) + 'px';
      pin.style.left = (window.scrollX + rect.left - 12) + 'px';
      pin.onclick = function(e) {
        e.stopPropagation();
        activePin = c;
        openPanel(c.element_selector);
      };
      document.body.appendChild(pin);
    });
  }

  // ── Panel ────────────────────────────────────────────
  function openPanel(selector) {
    if (panel) panel.remove();

    panel = document.createElement('div');
    panel.className = 'opr-panel';
    panel.innerHTML = '<div class="opr-panel-header">' +
      '<div class="opr-panel-title">Feedback' + (selector ? '' : ' — All Comments') + '</div>' +
      '<button class="opr-panel-close" title="Close">&times;</button>' +
      '</div>' +
      '<div class="opr-panel-body"></div>' +
      '<div class="opr-form"></div>';

    document.body.appendChild(panel);

    panel.querySelector('.opr-panel-close').onclick = function() {
      panel.remove();
      panel = null;
      activePin = null;
    };

    renderPanelComments(selector);
    renderPanelForm(selector);
  }

  function renderPanelComments(selector) {
    var body = panel.querySelector('.opr-panel-body');
    var filtered = selector
      ? comments.filter(function(c) { return c.element_selector === selector; })
      : comments;

    if (!filtered.length) {
      body.innerHTML = '<div class="opr-empty">No feedback yet. Click an element on the page or leave a general comment below.</div>';
      return;
    }

    body.innerHTML = '';
    filtered.forEach(function(c) {
      var div = document.createElement('div');
      div.className = 'opr-comment';

      var headerHtml = '<div class="opr-comment-header">' +
        '<div class="opr-avatar">' + initials(c.author_name) + '</div>' +
        '<span class="opr-author">' + escHtml(c.author_name || 'Anonymous') + '</span>' +
        '<span class="opr-time">' + timeAgo(c.created_at) + '</span>' +
        '</div>';

      var selectorHtml = c.element_selector
        ? '<div class="opr-selector-label">' + escHtml(c.element_selector) + '</div>'
        : '';

      div.innerHTML = headerHtml + selectorHtml + '<div class="opr-body">' + escHtml(c.body) + '</div>';

      // Replies
      if (c.replies && c.replies.length) {
        c.replies.forEach(function(r) {
          div.innerHTML += '<div class="opr-reply"><div class="opr-comment-header">' +
            '<div class="opr-avatar" style="width:22px;height:22px;border-radius:11px;font-size:10px">' + initials(r.author_name) + '</div>' +
            '<span class="opr-author">' + escHtml(r.author_name || 'Anonymous') + '</span>' +
            '<span class="opr-time">' + timeAgo(r.created_at) + '</span>' +
            '</div><div class="opr-body">' + escHtml(r.body) + '</div></div>';
        });
      }

      body.appendChild(div);
    });
  }

  function renderPanelForm(selector) {
    var form = panel.querySelector('.opr-form');
    var html = '';

    if (!authorName) {
      html += '<div class="opr-row">' +
        '<input class="opr-input" id="opr-name" placeholder="Your name" value="">' +
        '<input class="opr-input" id="opr-email" placeholder="Email (optional)" value="">' +
        '</div>';
    }

    html += '<textarea class="opr-input" id="opr-body" rows="3" placeholder="Leave your feedback..."></textarea>';
    html += '<button class="opr-submit" id="opr-send">Send Feedback</button>';

    form.innerHTML = html;

    form.querySelector('#opr-send').onclick = function() {
      var bodyEl = document.getElementById('opr-body');
      var bodyVal = bodyEl.value.trim();
      if (!bodyVal) return;

      // Save author info
      if (!authorName) {
        var nameEl = document.getElementById('opr-name');
        var emailEl = document.getElementById('opr-email');
        authorName = (nameEl.value || '').trim();
        authorEmail = (emailEl.value || '').trim();
        if (!authorName) { nameEl.focus(); return; }
        localStorage.setItem('outpost_review_name', authorName);
        localStorage.setItem('outpost_review_email', authorEmail);
      }

      var btn = this;
      btn.disabled = true;
      btn.textContent = 'Sending...';

      postComment(bodyVal, selector || '', null, function(err) {
        btn.disabled = false;
        btn.textContent = 'Send Feedback';
        if (err) {
          alert('Error: ' + err.message);
          return;
        }
        bodyEl.value = '';
        refresh();
      });
    };
  }

  function escHtml(str) {
    var d = document.createElement('div');
    d.textContent = str;
    return d.innerHTML;
  }

  // ── Click Mode ───────────────────────────────────────
  var clickMode = false;
  var highlightedEl = null;

  function enableClickMode() {
    clickMode = true;
    document.body.style.cursor = 'crosshair';
  }

  function disableClickMode() {
    clickMode = false;
    document.body.style.cursor = '';
    if (highlightedEl) {
      highlightedEl.classList.remove('opr-highlight');
      highlightedEl = null;
    }
  }

  document.addEventListener('mouseover', function(e) {
    if (!clickMode) return;
    if (e.target.closest('.opr-panel,.opr-fab,.opr-pin')) return;
    if (highlightedEl) highlightedEl.classList.remove('opr-highlight');
    highlightedEl = e.target;
    highlightedEl.classList.add('opr-highlight');
  }, true);

  document.addEventListener('click', function(e) {
    if (!clickMode) return;
    if (e.target.closest('.opr-panel,.opr-fab,.opr-pin')) return;
    e.preventDefault();
    e.stopPropagation();
    disableClickMode();

    var selector = getSelector(e.target);
    openPanel(selector);
  }, true);

  // ── FAB ──────────────────────────────────────────────
  function renderFAB() {
    var existing = document.querySelector('.opr-fab');
    if (existing) existing.remove();

    var btn = document.createElement('button');
    btn.className = 'opr-fab';
    btn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>Leave Feedback';
    if (comments.length) {
      btn.innerHTML += '<span class="opr-fab-count">' + comments.length + '</span>';
    }

    btn.onclick = function(e) {
      e.stopPropagation();
      if (panel) {
        panel.remove();
        panel = null;
        disableClickMode();
      } else {
        enableClickMode();
      }
    };

    document.body.appendChild(btn);
  }

  // ── Init ─────────────────────────────────────────────
  function refresh() {
    fetchComments(function(err, data) {
      if (!err) {
        comments = data;
        renderPins();
        renderFAB();
        if (panel) {
          var selector = activePin ? activePin.element_selector : '';
          renderPanelComments(selector);
        }
      }
    });
  }

  // Wait for DOM ready
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', refresh);
  } else {
    refresh();
  }

  // Re-position pins on scroll/resize
  var pinTimeout;
  function debounceRenderPins() {
    clearTimeout(pinTimeout);
    pinTimeout = setTimeout(renderPins, 150);
  }
  window.addEventListener('scroll', debounceRenderPins, { passive: true });
  window.addEventListener('resize', debounceRenderPins, { passive: true });

})();
