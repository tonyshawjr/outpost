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

  function boot() {
    var els = document.querySelectorAll('[data-outpost-newsletter]');
    for (var i = 0; i < els.length; i++) init(els[i]);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
