(function () {
  'use strict';

  /* ---- Dark mode toggle ---- */
  var themeToggle = document.getElementById('themeToggle');

  function getTheme() {
    var stored = localStorage.getItem('theme');
    if (stored) return stored;
    return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
  }

  function setTheme(theme) {
    document.documentElement.setAttribute('data-theme', theme);
    localStorage.setItem('theme', theme);
  }

  if (themeToggle) {
    themeToggle.addEventListener('click', function () {
      var current = document.documentElement.getAttribute('data-theme') || getTheme();
      setTheme(current === 'dark' ? 'light' : 'dark');
    });
  }

  if (!document.documentElement.getAttribute('data-theme')) {
    var initial = getTheme();
    if (initial === 'dark') {
      document.documentElement.setAttribute('data-theme', 'dark');
    }
  }

  /* ---- Mobile menu ---- */
  var hamburger   = document.getElementById('hamburger');
  var mobileClose = document.getElementById('mobileClose');
  var overlay     = document.getElementById('mobileOverlay');
  var nav         = document.getElementById('nav');

  if (hamburger && overlay) {
    var isOpen = false;

    function openMenu() {
      isOpen = true;
      hamburger.classList.add('active');
      overlay.classList.add('active');
      overlay.removeAttribute('aria-hidden');
      hamburger.setAttribute('aria-expanded', 'true');
      hamburger.setAttribute('aria-label', 'Close menu');
      document.body.style.overflow = 'hidden';
    }

    function closeMenu() {
      if (!isOpen) return;
      isOpen = false;
      hamburger.classList.remove('active');
      overlay.classList.remove('active');
      overlay.setAttribute('aria-hidden', 'true');
      hamburger.setAttribute('aria-expanded', 'false');
      hamburger.setAttribute('aria-label', 'Open menu');
      document.body.style.overflow = '';
    }

    hamburger.addEventListener('click', function () {
      isOpen ? closeMenu() : openMenu();
    });

    if (mobileClose) {
      mobileClose.addEventListener('click', closeMenu);
    }

    /* Close when any link in the overlay is clicked */
    overlay.querySelectorAll('a').forEach(function (link) {
      link.addEventListener('click', closeMenu);
    });

    /* Close on Escape key */
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && isOpen) closeMenu();
    });
  }

  /* ---- Dropdown keyboard accessibility ---- */
  /* Dropdown items are handled purely with CSS :hover/:focus-within.       */
  /* This adds toggle support for items clicked without hovering (mobile). */
  document.querySelectorAll('.nav-item.has-dropdown > .nav-link--dropdown').forEach(function (link) {
    link.addEventListener('click', function (e) {
      /* Only intercept if we're at mobile-ish widths where hover doesn't apply */
      if (window.innerWidth <= 768) {
        e.preventDefault();
        var parent = link.parentElement;
        var isExpanded = parent.classList.contains('dropdown-open');
        /* Close all open dropdowns */
        document.querySelectorAll('.nav-item.has-dropdown.dropdown-open').forEach(function (el) {
          el.classList.remove('dropdown-open');
          el.querySelector('.nav-link--dropdown').setAttribute('aria-expanded', 'false');
        });
        if (!isExpanded) {
          parent.classList.add('dropdown-open');
          link.setAttribute('aria-expanded', 'true');
        }
      }
    });
  });

  /* ---- Fade-up on scroll (IntersectionObserver) ---- */
  var fadeEls = document.querySelectorAll('.fade-up');

  if ('IntersectionObserver' in window) {
    var observer = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) {
          entry.target.classList.add('visible');
          observer.unobserve(entry.target);
        }
      });
    }, {
      root: null,
      rootMargin: '0px 0px -60px 0px',
      threshold: 0.1
    });

    fadeEls.forEach(function (el) { observer.observe(el); });
  } else {
    fadeEls.forEach(function (el) { el.classList.add('visible'); });
  }

  /* ---- Smooth scroll for anchor links ---- */
  document.querySelectorAll('a[href^="#"]').forEach(function (anchor) {
    anchor.addEventListener('click', function (e) {
      var targetId = this.getAttribute('href');
      if (targetId === '#') return;
      var target = document.querySelector(targetId);
      if (target) {
        e.preventDefault();
        var navHeight = nav ? nav.offsetHeight : 0;
        var pos = target.getBoundingClientRect().top + window.pageYOffset - navHeight;
        window.scrollTo({ top: pos, behavior: 'smooth' });
      }
    });
  });

})();
