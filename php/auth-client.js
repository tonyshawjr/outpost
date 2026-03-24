/**
 * Outpost CMS -- Member Auth Client
 * Handles login, register, and forgot-password forms on theme pages.
 * Auto-discovers forms with [data-outpost-auth] attribute.
 * No dependencies.
 */
(function () {
  'use strict';

  var API_BASE = '/outpost/member-api.php';

  function init() {
    document.querySelectorAll('[data-outpost-auth]').forEach(setupForm);
  }

  function setupForm(form) {
    var type = form.getAttribute('data-outpost-auth'); // 'login', 'register', 'forgot-password'
    var errorEl = form.querySelector('[data-auth-error]');
    var successEl = form.querySelector('[data-auth-success]');
    var submitBtn = form.querySelector('[type="submit"]');
    var redirectUrl = form.getAttribute('data-auth-redirect') || '/';

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      if (errorEl) errorEl.textContent = '';
      if (errorEl) errorEl.style.display = 'none';
      if (successEl) successEl.style.display = 'none';

      var originalText = submitBtn ? submitBtn.textContent : '';
      if (submitBtn) { submitBtn.disabled = true; submitBtn.textContent = 'Please wait...'; }

      var body = {};

      if (type === 'login') {
        body.email = (form.querySelector('[name="email"]') || {}).value || '';
        body.password = (form.querySelector('[name="password"]') || {}).value || '';

        post('login', body, function (data) {
          if (submitBtn) { submitBtn.disabled = false; submitBtn.textContent = originalText; }
          if (data.error) {
            showError(errorEl, data.error);
            return;
          }
          // Store CSRF token for subsequent requests
          if (data.csrf_token) {
            document.cookie = 'outpost_member_csrf=' + data.csrf_token + ';path=/;SameSite=Lax';
          }
          // Redirect to return URL or default
          var params = new URLSearchParams(window.location.search);
          var returnUrl = params.get('return') || redirectUrl;
          window.location.href = returnUrl;
        }, function (err) {
          if (submitBtn) { submitBtn.disabled = false; submitBtn.textContent = originalText; }
          showError(errorEl, 'Something went wrong. Please try again.');
        });

      } else if (type === 'register') {
        body.username = (form.querySelector('[name="name"]') || form.querySelector('[name="username"]') || {}).value || '';
        body.email = (form.querySelector('[name="email"]') || {}).value || '';
        body.password = (form.querySelector('[name="password"]') || {}).value || '';

        post('register', body, function (data) {
          if (submitBtn) { submitBtn.disabled = false; submitBtn.textContent = originalText; }
          if (data.error) {
            showError(errorEl, data.error);
            return;
          }
          if (data.requires_verification) {
            if (successEl) {
              successEl.textContent = data.message || 'Please check your email to verify your account.';
              successEl.style.display = '';
            }
            form.style.display = 'none';
            return;
          }
          // Auto-login after registration
          window.location.href = redirectUrl;
        }, function () {
          if (submitBtn) { submitBtn.disabled = false; submitBtn.textContent = originalText; }
          showError(errorEl, 'Something went wrong. Please try again.');
        });

      } else if (type === 'forgot-password') {
        body.email = (form.querySelector('[name="email"]') || {}).value || '';

        post('forgot-password', body, function (data) {
          if (submitBtn) { submitBtn.disabled = false; submitBtn.textContent = originalText; }
          if (data.error) {
            showError(errorEl, data.error);
            return;
          }
          if (successEl) {
            successEl.textContent = data.message || 'If that email exists, a reset link has been sent.';
            successEl.style.display = '';
          }
        }, function () {
          if (submitBtn) { submitBtn.disabled = false; submitBtn.textContent = originalText; }
          showError(errorEl, 'Something went wrong. Please try again.');
        });
      }
    });
  }

  function post(action, body, onSuccess, onError) {
    fetch(API_BASE + '?action=' + action, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
    })
      .then(function (r) { return r.json(); })
      .then(onSuccess)
      .catch(onError);
  }

  function showError(el, msg) {
    if (!el) return;
    el.textContent = msg;
    el.style.display = '';
  }

  // Init on DOM ready
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
