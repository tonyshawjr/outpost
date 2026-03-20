/**
 * Outpost CMS — Review Overlay v2
 * Lightweight vanilla JS script injected when ?review=TOKEN is present.
 * Allows external reviewers to leave comments on any page element.
 */
(function() {
  'use strict';

  var TOKEN = window.__OUTPOST_REVIEW_TOKEN__ || '';
  var API   = window.__OUTPOST_API_URL__ || '/outpost/api.php';
  if (!TOKEN) return;

  var PAGE_PATH    = window.location.pathname;
  var comments     = [];
  var panelOpen    = false;
  var commentMode  = false;
  var highlightedEl = null;
  var activeSelector = null;
  var authorName   = localStorage.getItem('outpost_review_name') || '';
  var authorEmail  = localStorage.getItem('outpost_review_email') || '';

  // ── Styles (all prefixed with outpost-review-) ─────────────
  var style = document.createElement('style');
  style.textContent = '\n\
/* FAB */\n\
.outpost-review-fab {\n\
  position: fixed; bottom: 24px; right: 24px; z-index: 2147483640;\n\
  width: 48px; height: 48px; border-radius: 24px;\n\
  background: #3D3530; color: #fff; border: none; cursor: pointer;\n\
  box-shadow: 0 4px 16px rgba(0,0,0,.3); display: flex; align-items: center; justify-content: center;\n\
  transition: transform .15s, box-shadow .15s;\n\
  font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", system-ui, sans-serif;\n\
}\n\
.outpost-review-fab:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(0,0,0,.4); }\n\
.outpost-review-fab svg { width: 22px; height: 22px; }\n\
.outpost-review-fab-badge {\n\
  position: absolute; top: -4px; right: -4px;\n\
  background: #ef4444; color: #fff; font-size: 10px; font-weight: 700;\n\
  min-width: 18px; height: 18px; border-radius: 9px;\n\
  display: flex; align-items: center; justify-content: center;\n\
  padding: 0 4px; line-height: 1;\n\
}\n\
\n\
/* Highlight on hover in comment mode */\n\
.outpost-review-el-highlight {\n\
  outline: 2px solid #10b981 !important;\n\
  outline-offset: 2px !important;\n\
  cursor: crosshair !important;\n\
}\n\
\n\
/* Brief flash when clicking a pin */\n\
.outpost-review-el-flash {\n\
  outline: 2px solid #3b82f6 !important;\n\
  outline-offset: 2px !important;\n\
  transition: outline-color 0.3s ease !important;\n\
}\n\
\n\
/* Pins */\n\
.outpost-review-pin {\n\
  position: absolute; z-index: 2147483638;\n\
  width: 24px; height: 24px; border-radius: 12px;\n\
  background: #3D3530; color: #fff; font-size: 11px; font-weight: 700;\n\
  display: flex; align-items: center; justify-content: center;\n\
  cursor: pointer; box-shadow: 0 2px 8px rgba(0,0,0,.3);\n\
  border: 2px solid #fff; transition: transform .1s;\n\
  font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", system-ui, sans-serif;\n\
  pointer-events: auto;\n\
}\n\
.outpost-review-pin:hover { transform: scale(1.15); }\n\
.outpost-review-pin.resolved { background: #6b7280; opacity: .5; }\n\
\n\
/* Panel */\n\
.outpost-review-panel {\n\
  position: fixed; top: 0; right: 0; bottom: 0; width: 360px; max-width: 100vw;\n\
  z-index: 2147483641;\n\
  background: #3D3530;\n\
  background-image: url("data:image/svg+xml,%3Csvg width=\'200\' height=\'200\' xmlns=\'http://www.w3.org/2000/svg\'%3E%3Cfilter id=\'n\'%3E%3CfeTurbulence type=\'fractalNoise\' baseFrequency=\'.65\' numOctaves=\'3\' stitchTiles=\'stitch\'/%3E%3C/filter%3E%3Crect width=\'100%25\' height=\'100%25\' filter=\'url(%23n)\' opacity=\'.03\'/%3E%3C/svg%3E");\n\
  box-shadow: -4px 0 24px rgba(0,0,0,.25);\n\
  display: flex; flex-direction: column;\n\
  font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", system-ui, sans-serif;\n\
  font-size: 14px; color: rgba(255,255,255,.9);\n\
  transform: translateX(100%); transition: transform .25s ease;\n\
}\n\
.outpost-review-panel.open { transform: translateX(0); }\n\
\n\
.outpost-review-panel-header {\n\
  display: flex; align-items: center; padding: 16px 20px;\n\
  border-bottom: 1px solid rgba(255,255,255,.1);\n\
}\n\
.outpost-review-panel-title { flex: 1; font-size: 15px; font-weight: 600; margin: 0; }\n\
.outpost-review-panel-close {\n\
  background: none; border: none; cursor: pointer; padding: 4px;\n\
  color: rgba(255,255,255,.5); font-size: 20px; line-height: 1;\n\
}\n\
.outpost-review-panel-close:hover { color: rgba(255,255,255,.9); }\n\
\n\
.outpost-review-panel-body { flex: 1; overflow-y: auto; padding: 12px 20px; }\n\
\n\
.outpost-review-comment {\n\
  padding: 14px 0;\n\
  border-bottom: 1px solid rgba(255,255,255,.08);\n\
  cursor: pointer;\n\
}\n\
.outpost-review-comment:last-child { border-bottom: none; }\n\
.outpost-review-comment:hover { background: rgba(255,255,255,.03); margin: 0 -20px; padding-left: 20px; padding-right: 20px; }\n\
\n\
.outpost-review-comment-header {\n\
  display: flex; align-items: center; gap: 8px; margin-bottom: 6px;\n\
}\n\
.outpost-review-avatar {\n\
  width: 28px; height: 28px; border-radius: 14px;\n\
  background: rgba(255,255,255,.15); display: flex; align-items: center; justify-content: center;\n\
  font-size: 11px; font-weight: 600; color: rgba(255,255,255,.7); flex-shrink: 0;\n\
}\n\
.outpost-review-author { font-weight: 600; font-size: 13px; color: rgba(255,255,255,.9); }\n\
.outpost-review-time { font-size: 12px; color: rgba(255,255,255,.4); }\n\
.outpost-review-element-desc {\n\
  font-size: 11px; color: rgba(255,255,255,.35); margin-bottom: 4px;\n\
  display: flex; align-items: center; gap: 4px;\n\
}\n\
.outpost-review-element-desc svg { width: 12px; height: 12px; opacity: 0.5; }\n\
.outpost-review-body { font-size: 14px; color: rgba(255,255,255,.85); line-height: 1.5; }\n\
\n\
.outpost-review-reply {\n\
  margin-left: 36px; padding: 8px 0;\n\
}\n\
.outpost-review-reply .outpost-review-author { font-size: 12px; }\n\
.outpost-review-reply .outpost-review-avatar { width: 22px; height: 22px; font-size: 9px; }\n\
\n\
.outpost-review-empty {\n\
  text-align: center; padding: 40px 20px; color: rgba(255,255,255,.35); font-size: 14px;\n\
}\n\
\n\
.outpost-review-form {\n\
  padding: 16px 20px; border-top: 1px solid rgba(255,255,255,.1);\n\
}\n\
.outpost-review-input {\n\
  width: 100%; padding: 10px 12px;\n\
  border: 1px solid rgba(255,255,255,.15); border-radius: 6px;\n\
  font: 14px/1.4 -apple-system, system-ui, sans-serif;\n\
  color: #fff; background: rgba(255,255,255,.08);\n\
  resize: none; box-sizing: border-box; margin-bottom: 8px;\n\
}\n\
.outpost-review-input::placeholder { color: rgba(255,255,255,.3); }\n\
.outpost-review-input:focus {\n\
  outline: none; border-color: rgba(255,255,255,.3);\n\
  background: rgba(255,255,255,.12);\n\
}\n\
.outpost-review-row { display: flex; gap: 8px; margin-bottom: 8px; }\n\
.outpost-review-row .outpost-review-input { flex: 1; margin-bottom: 0; }\n\
.outpost-review-submit {\n\
  padding: 10px 20px; background: #fff; color: #3D3530;\n\
  border: none; border-radius: 6px;\n\
  font: 14px/1 -apple-system, system-ui, sans-serif;\n\
  font-weight: 600; cursor: pointer; width: 100%;\n\
}\n\
.outpost-review-submit:hover { background: rgba(255,255,255,.9); }\n\
.outpost-review-submit:disabled { opacity: .5; cursor: not-allowed; }\n\
\n\
/* Comment mode button */\n\
.outpost-review-mode-btn {\n\
  display: flex; align-items: center; gap: 6px;\n\
  padding: 8px 14px; margin-bottom: 12px;\n\
  background: rgba(255,255,255,.1); color: rgba(255,255,255,.8);\n\
  border: 1px solid rgba(255,255,255,.15); border-radius: 6px;\n\
  cursor: pointer; font-size: 13px; font-weight: 500;\n\
  font-family: -apple-system, system-ui, sans-serif;\n\
  width: 100%; justify-content: center;\n\
}\n\
.outpost-review-mode-btn:hover { background: rgba(255,255,255,.15); }\n\
.outpost-review-mode-btn.active { background: #10b981; color: #fff; border-color: #10b981; }\n\
.outpost-review-mode-btn svg { width: 14px; height: 14px; }\n\
\n\
/* Name modal */\n\
.outpost-review-modal-overlay {\n\
  position: fixed; inset: 0; z-index: 2147483645;\n\
  background: rgba(0,0,0,.5); display: flex; align-items: center; justify-content: center;\n\
}\n\
.outpost-review-modal {\n\
  background: #3D3530; border-radius: 12px; padding: 28px 24px;\n\
  width: 340px; max-width: 90vw; box-shadow: 0 20px 60px rgba(0,0,0,.4);\n\
  font-family: -apple-system, system-ui, sans-serif; color: rgba(255,255,255,.9);\n\
}\n\
.outpost-review-modal h3 { margin: 0 0 4px; font-size: 16px; font-weight: 600; }\n\
.outpost-review-modal p { margin: 0 0 16px; font-size: 13px; color: rgba(255,255,255,.5); }\n\
.outpost-review-modal .outpost-review-input { margin-bottom: 10px; }\n\
.outpost-review-modal .outpost-review-submit { margin-top: 4px; }\n\
\n\
@media (max-width: 480px) {\n\
  .outpost-review-panel { width: 100vw; }\n\
}\n\
';
  document.head.appendChild(style);

  // ── Containers ─────────────────────────────────────────────
  var panelEl = null;
  var fabEl = null;
  var pinsContainer = null;

  // ── API ────────────────────────────────────────────────────
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
        cb(null, JSON.parse(xhr.responseText).comment);
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

  // ── CSS Selector ───────────────────────────────────────────
  function getSelector(el) {
    if (el.id) return '#' + CSS.escape(el.id);
    var parts = [];
    while (el && el !== document.body && el !== document.documentElement) {
      var tag = el.tagName.toLowerCase();
      if (el.id) {
        parts.unshift('#' + CSS.escape(el.id));
        break;
      }
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

  // ── Friendly element description ───────────────────────────
  function describeElement(selector) {
    if (!selector) return 'General comment';
    var el;
    try { el = document.querySelector(selector); } catch(e) { return 'Page element'; }
    if (!el) return 'Page element';

    // 1. Check for aria-label
    var ariaLabel = el.getAttribute('aria-label');
    if (ariaLabel) return truncate(ariaLabel, 50);

    // 2. Check for alt text (images)
    if (el.tagName === 'IMG' && el.alt) return truncate(el.alt, 50);

    // 3. Check heading content
    var heading = el.querySelector('h1, h2, h3, h4, h5, h6');
    if (heading && heading.textContent.trim()) {
      return truncate(heading.textContent.trim(), 50);
    }

    // 4. Direct text content (excluding child elements' text for short elements)
    var text = getDirectText(el);
    if (text.length > 3) return truncate(text, 50);

    // 5. Full text content
    var fullText = el.textContent.trim();
    if (fullText.length > 3) return truncate(fullText, 50);

    // 6. Tag + meaningful class
    var meaningful = getMeaningfulClass(el);
    if (meaningful) return meaningful;

    // 7. Tag name fallback
    var tagMap = {
      'nav': 'Navigation', 'header': 'Header', 'footer': 'Footer',
      'main': 'Main content', 'section': 'Section', 'article': 'Article',
      'aside': 'Sidebar', 'form': 'Form', 'button': 'Button',
      'a': 'Link', 'img': 'Image', 'video': 'Video', 'table': 'Table',
      'ul': 'List', 'ol': 'List', 'p': 'Paragraph', 'div': 'Container',
    };
    return tagMap[el.tagName.toLowerCase()] || 'Page element';
  }

  function getDirectText(el) {
    var text = '';
    for (var i = 0; i < el.childNodes.length; i++) {
      if (el.childNodes[i].nodeType === 3) {
        text += el.childNodes[i].textContent;
      }
    }
    return text.trim();
  }

  function getMeaningfulClass(el) {
    var classes = el.className;
    if (typeof classes !== 'string' || !classes) return '';
    var words = classes.split(/[\s_-]+/).filter(function(w) {
      return w.length > 2 && !/^(col|row|mt|mb|pt|pb|px|py|mx|my|d|w|h|flex|grid|text|bg|p|m)\d*$/.test(w);
    });
    if (words.length > 0) {
      // Capitalize and join first 2-3 meaningful words
      return words.slice(0, 3).map(function(w) {
        return w.charAt(0).toUpperCase() + w.slice(1);
      }).join(' ');
    }
    return '';
  }

  function truncate(str, len) {
    str = str.replace(/\s+/g, ' ').trim();
    if (str.length <= len) return str;
    return str.substring(0, len) + '...';
  }

  // ── Time formatting ────────────────────────────────────────
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

  function escHtml(str) {
    var d = document.createElement('div');
    d.textContent = str;
    return d.innerHTML;
  }

  // ── Name/Email Modal ───────────────────────────────────────
  function ensureAuthor(cb) {
    if (authorName) { cb(); return; }
    var overlay = document.createElement('div');
    overlay.className = 'outpost-review-modal-overlay';
    overlay.innerHTML = '<div class="outpost-review-modal">' +
      '<h3>Before you start</h3>' +
      '<p>Enter your name so your feedback is attributed to you.</p>' +
      '<input class="outpost-review-input" id="outpost-review-modal-name" placeholder="Your name" autofocus>' +
      '<input class="outpost-review-input" id="outpost-review-modal-email" placeholder="Email (optional)">' +
      '<button class="outpost-review-submit" id="outpost-review-modal-go">Continue</button>' +
      '</div>';
    document.body.appendChild(overlay);

    var nameInput = overlay.querySelector('#outpost-review-modal-name');
    var emailInput = overlay.querySelector('#outpost-review-modal-email');
    var goBtn = overlay.querySelector('#outpost-review-modal-go');

    function submit() {
      var n = nameInput.value.trim();
      if (!n) { nameInput.focus(); return; }
      authorName = n;
      authorEmail = emailInput.value.trim();
      localStorage.setItem('outpost_review_name', authorName);
      localStorage.setItem('outpost_review_email', authorEmail);
      overlay.remove();
      cb();
    }

    goBtn.onclick = submit;
    nameInput.addEventListener('keydown', function(e) { if (e.key === 'Enter') submit(); });
    emailInput.addEventListener('keydown', function(e) { if (e.key === 'Enter') submit(); });

    overlay.addEventListener('click', function(e) {
      if (e.target === overlay) overlay.remove();
    });
  }

  // ── Pins ───────────────────────────────────────────────────
  function renderPins() {
    if (!pinsContainer) {
      pinsContainer = document.createElement('div');
      pinsContainer.style.cssText = 'position:absolute;top:0;left:0;width:0;height:0;z-index:2147483638;pointer-events:none;';
      document.body.appendChild(pinsContainer);
    }
    pinsContainer.innerHTML = '';

    // Group comments by selector to get pin numbers
    var selectorIndex = {};
    var pinNum = 0;
    comments.forEach(function(c) {
      if (!c.element_selector) return;
      if (!(c.element_selector in selectorIndex)) {
        pinNum++;
        selectorIndex[c.element_selector] = pinNum;
      }
    });

    // Render one pin per unique selector
    Object.keys(selectorIndex).forEach(function(sel) {
      var el;
      try { el = document.querySelector(sel); } catch(e) { return; }
      if (!el) return;

      var rect = el.getBoundingClientRect();
      var pin = document.createElement('div');
      pin.className = 'outpost-review-pin';
      pin.textContent = String(selectorIndex[sel]);
      pin.style.top = (window.scrollY + rect.top - 4) + 'px';
      pin.style.left = (window.scrollX + rect.right - 20) + 'px';

      pin.onclick = function(e) {
        e.stopPropagation();
        activeSelector = sel;
        openPanel();
        flashElement(sel);
      };

      pinsContainer.appendChild(pin);
    });
  }

  function flashElement(selector) {
    var el;
    try { el = document.querySelector(selector); } catch(e) { return; }
    if (!el) return;
    el.classList.add('outpost-review-el-flash');
    setTimeout(function() { el.classList.remove('outpost-review-el-flash'); }, 1500);
  }

  // ── Panel ──────────────────────────────────────────────────
  function createPanel() {
    panelEl = document.createElement('div');
    panelEl.className = 'outpost-review-panel';
    panelEl.innerHTML =
      '<div class="outpost-review-panel-header">' +
        '<h2 class="outpost-review-panel-title">Feedback</h2>' +
        '<button class="outpost-review-panel-close" title="Close">&times;</button>' +
      '</div>' +
      '<div class="outpost-review-panel-body"></div>' +
      '<div class="outpost-review-form"></div>';
    document.body.appendChild(panelEl);

    panelEl.querySelector('.outpost-review-panel-close').onclick = closePanel;
  }

  function openPanel() {
    if (!panelEl) createPanel();
    panelEl.classList.add('open');
    panelOpen = true;
    renderPanelBody();
    renderPanelForm();
    // Move FAB left to avoid overlap
    if (fabEl) fabEl.style.right = '384px';
  }

  function closePanel() {
    if (panelEl) panelEl.classList.remove('open');
    panelOpen = false;
    activeSelector = null;
    disableCommentMode();
    if (fabEl) fabEl.style.right = '24px';
  }

  function renderPanelBody() {
    if (!panelEl) return;
    var body = panelEl.querySelector('.outpost-review-panel-body');

    // Comment mode toggle button
    var modeBtn = '<button class="outpost-review-mode-btn' + (commentMode ? ' active' : '') + '" id="outpost-review-mode-toggle">' +
      '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="16"/><line x1="8" y1="12" x2="16" y2="12"/></svg>' +
      (commentMode ? 'Click an element on the page...' : 'Click on an element to comment') +
      '</button>';

    var filtered = activeSelector
      ? comments.filter(function(c) { return c.element_selector === activeSelector; })
      : comments;

    if (!filtered.length) {
      body.innerHTML = modeBtn + '<div class="outpost-review-empty">No feedback yet.<br>Click an element on the page to leave a comment.</div>';
    } else {
      var html = modeBtn;
      filtered.forEach(function(c) {
        var desc = describeElement(c.element_selector);
        html += '<div class="outpost-review-comment" data-selector="' + escHtml(c.element_selector || '') + '">' +
          '<div class="outpost-review-comment-header">' +
            '<div class="outpost-review-avatar">' + initials(c.author_name) + '</div>' +
            '<span class="outpost-review-author">' + escHtml(c.author_name || 'Anonymous') + '</span>' +
            '<span class="outpost-review-time">' + timeAgo(c.created_at) + '</span>' +
          '</div>';

        if (c.element_selector) {
          html += '<div class="outpost-review-element-desc">' +
            '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 3h6v6M9 21H3v-6M21 3l-7 7M3 21l7-7"/></svg>' +
            escHtml(desc) +
          '</div>';
        }

        html += '<div class="outpost-review-body">' + escHtml(c.body) + '</div>';

        // Replies
        if (c.replies && c.replies.length) {
          c.replies.forEach(function(r) {
            html += '<div class="outpost-review-reply">' +
              '<div class="outpost-review-comment-header">' +
                '<div class="outpost-review-avatar">' + initials(r.author_name) + '</div>' +
                '<span class="outpost-review-author">' + escHtml(r.author_name || 'Anonymous') + '</span>' +
                '<span class="outpost-review-time">' + timeAgo(r.created_at) + '</span>' +
              '</div>' +
              '<div class="outpost-review-body">' + escHtml(r.body) + '</div>' +
            '</div>';
          });
        }

        html += '</div>';
      });
      body.innerHTML = html;
    }

    // Bind mode toggle
    var toggleBtn = body.querySelector('#outpost-review-mode-toggle');
    if (toggleBtn) {
      toggleBtn.onclick = function() {
        if (commentMode) {
          disableCommentMode();
        } else {
          enableCommentMode();
        }
        renderPanelBody();
      };
    }

    // Bind comment clicks to flash the element
    body.querySelectorAll('.outpost-review-comment[data-selector]').forEach(function(el) {
      el.onclick = function() {
        var sel = el.getAttribute('data-selector');
        if (sel) flashElement(sel);
      };
    });
  }

  function renderPanelForm() {
    if (!panelEl) return;
    var form = panelEl.querySelector('.outpost-review-form');
    var html = '<textarea class="outpost-review-input" id="outpost-review-body" rows="3" placeholder="Leave your feedback..."></textarea>';
    html += '<button class="outpost-review-submit" id="outpost-review-send">Send Feedback</button>';
    form.innerHTML = html;

    form.querySelector('#outpost-review-send').onclick = function() {
      var bodyEl = form.querySelector('#outpost-review-body');
      var bodyVal = bodyEl.value.trim();
      if (!bodyVal) return;

      ensureAuthor(function() {
        var btn = form.querySelector('#outpost-review-send');
        btn.disabled = true;
        btn.textContent = 'Sending...';

        postComment(bodyVal, activeSelector || '', null, function(err) {
          btn.disabled = false;
          btn.textContent = 'Send Feedback';
          if (err) {
            alert('Error: ' + err.message);
            return;
          }
          bodyEl.value = '';
          refresh();
        });
      });
    };
  }

  // ── Comment Mode (hover + click elements) ──────────────────
  function enableCommentMode() {
    commentMode = true;
    document.body.style.cursor = 'crosshair';
  }

  function disableCommentMode() {
    commentMode = false;
    document.body.style.cursor = '';
    if (highlightedEl) {
      highlightedEl.classList.remove('outpost-review-el-highlight');
      highlightedEl = null;
    }
  }

  document.addEventListener('mouseover', function(e) {
    if (!commentMode) return;
    var t = e.target;
    if (t.closest('.outpost-review-panel,.outpost-review-fab,.outpost-review-pin,.outpost-review-modal-overlay')) return;
    if (highlightedEl) highlightedEl.classList.remove('outpost-review-el-highlight');
    highlightedEl = t;
    highlightedEl.classList.add('outpost-review-el-highlight');
  }, true);

  document.addEventListener('click', function(e) {
    if (!commentMode) return;
    var t = e.target;
    if (t.closest('.outpost-review-panel,.outpost-review-fab,.outpost-review-pin,.outpost-review-modal-overlay')) return;
    e.preventDefault();
    e.stopPropagation();

    disableCommentMode();

    var selector = getSelector(t);
    activeSelector = selector;

    // Open panel focused on this element
    openPanel();
    flashElement(selector);

    // Focus the textarea
    setTimeout(function() {
      var ta = document.querySelector('#outpost-review-body');
      if (ta) ta.focus();
    }, 100);
  }, true);

  // ── FAB ────────────────────────────────────────────────────
  function renderFAB() {
    if (fabEl) fabEl.remove();

    fabEl = document.createElement('button');
    fabEl.className = 'outpost-review-fab';
    fabEl.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>';
    if (comments.length > 0) {
      fabEl.innerHTML += '<span class="outpost-review-fab-badge">' + comments.length + '</span>';
    }

    if (panelOpen) {
      fabEl.style.right = '384px';
    }

    fabEl.onclick = function(e) {
      e.stopPropagation();
      if (panelOpen) {
        closePanel();
      } else {
        ensureAuthor(function() {
          activeSelector = null;
          openPanel();
        });
      }
    };

    document.body.appendChild(fabEl);
  }

  // ── Refresh ────────────────────────────────────────────────
  function refresh() {
    fetchComments(function(err) {
      if (!err) {
        renderPins();
        renderFAB();
        if (panelOpen) {
          renderPanelBody();
        }
      }
    });
  }

  // ── Init ───────────────────────────────────────────────────
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', refresh);
  } else {
    refresh();
  }

  // Re-position pins on scroll/resize
  var pinTimeout;
  function debounceRenderPins() {
    clearTimeout(pinTimeout);
    pinTimeout = setTimeout(renderPins, 100);
  }
  window.addEventListener('scroll', debounceRenderPins, { passive: true });
  window.addEventListener('resize', debounceRenderPins, { passive: true });

})();
