(function () {
  function init(el) {
    if (el.getAttribute('data-o-nl-init')) return;
    el.setAttribute('data-o-nl-init', '1');

    var placeholder = el.getAttribute('data-placeholder') || 'you@example.com';
    var label = el.getAttribute('data-label') || 'Subscribe';

    var form = document.createElement('form');
    form.className = 'outpost-newsletter-form';
    form.setAttribute('novalidate', '');

    var input = document.createElement('input');
    input.type = 'email';
    input.name = 'email';
    input.required = true;
    input.placeholder = placeholder;
    input.setAttribute('aria-label', 'Email address');
    input.autocomplete = 'email';

    var button = document.createElement('button');
    button.type = 'submit';
    button.textContent = label;

    var msg = document.createElement('p');
    msg.className = 'outpost-newsletter-msg';
    msg.setAttribute('role', 'status');
    msg.setAttribute('aria-live', 'polite');

    form.appendChild(input);
    form.appendChild(button);
    el.appendChild(form);
    el.appendChild(msg);

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var email = input.value.trim();
      if (!email) return;
      button.disabled = true;
      msg.textContent = '';
      fetch('/outpost/api.php?action=newsletter/subscribe', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ email: email, source: 'form' })
      })
        .then(function (r) {
          return r.json().then(function (d) {
            return { ok: r.ok, data: d };
          });
        })
        .then(function (res) {
          msg.textContent = res.data.message || (res.ok ? 'Check your email to confirm your subscription.' : (res.data.error || 'Something went wrong.'));
          if (res.ok) form.reset();
        })
        .catch(function () {
          msg.textContent = 'Something went wrong. Please try again.';
        })
        .finally(function () {
          button.disabled = false;
        });
    });
  }

  function initOptin(el) {
    if (el.getAttribute('data-o-nl-init')) return;
    el.setAttribute('data-o-nl-init', '1');
    var label = el.getAttribute('data-label') || 'Email me newsletter updates';

    fetch('/outpost/api.php?action=lodge/profile', { credentials: 'same-origin' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (data) {
        if (!data || !data.profile) { el.style.display = 'none'; return; }
        var token = data.csrf_token || '';
        var wrap = document.createElement('label');
        wrap.className = 'outpost-newsletter-optin';

        var box = document.createElement('input');
        box.type = 'checkbox';
        box.checked = !!data.profile.newsletter_optin;

        var span = document.createElement('span');
        span.textContent = ' ' + label;

        var status = document.createElement('span');
        status.className = 'outpost-newsletter-optin-msg';
        status.setAttribute('role', 'status');
        status.setAttribute('aria-live', 'polite');

        wrap.appendChild(box);
        wrap.appendChild(span);
        el.appendChild(wrap);
        el.appendChild(status);

        box.addEventListener('change', function () {
          box.disabled = true;
          status.textContent = '';
          fetch('/outpost/api.php?action=lodge/profile', {
            method: 'PUT',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': token },
            body: JSON.stringify({ newsletter_optin: box.checked ? 1 : 0 })
          })
            .then(function (r) { return r.ok; })
            .then(function (ok) {
              status.textContent = ok ? 'Saved.' : 'Could not save.';
              if (!ok) box.checked = !box.checked;
            })
            .catch(function () { status.textContent = 'Could not save.'; box.checked = !box.checked; })
            .finally(function () { box.disabled = false; });
        });
      })
      .catch(function () { el.style.display = 'none'; });
  }

  function boot() {
    var els = document.querySelectorAll('[data-outpost-newsletter]');
    for (var i = 0; i < els.length; i++) init(els[i]);
    var optins = document.querySelectorAll('[data-outpost-newsletter-optin]');
    for (var j = 0; j < optins.length; j++) initOptin(optins[j]);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
