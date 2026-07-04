(function () {
  var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  function setInitial(el, eff, ds) {
    var d = ds || 24;
    el.style.willChange = 'opacity, transform';
    el.style.opacity = '0';
    switch (eff) {
      case 'slide-up': el.style.transform = 'translateY(' + d + 'px)'; break;
      case 'slide-down': el.style.transform = 'translateY(-' + d + 'px)'; break;
      case 'slide-left': el.style.transform = 'translateX(' + d + 'px)'; break;
      case 'slide-right': el.style.transform = 'translateX(-' + d + 'px)'; break;
      case 'scale': el.style.transform = 'scale(0.92)'; break;
      default: el.style.transform = 'none';
    }
  }

  function show(el, dur, delay) {
    el.style.transition = 'opacity ' + dur + 'ms ease ' + delay + 'ms, transform ' + dur + 'ms cubic-bezier(0.16,1,0.3,1) ' + delay + 'ms';
    el.style.opacity = '1';
    el.style.transform = 'none';
  }

  var els = document.querySelectorAll('[data-motion]');
  var io = null;
  var scrollEls = [];

  els.forEach(function (el) {
    var cfg;
    try { cfg = JSON.parse(el.getAttribute('data-motion') || '{}'); } catch (e) { return; }
    if (reduce || !cfg.t) return;
    var dur = cfg.d || 600, delay = cfg.dl || 0, ds = cfg.ds, eff = cfg.e || 'fade';

    if (cfg.t === 'reveal' || cfg.t === 'click') {
      setInitial(el, eff, ds);
      el._ocShow = function () { show(el, dur, delay); };
      el._ocReset = function () { el.style.transition = 'none'; setInitial(el, eff, ds); };
    }

    if (cfg.t === 'reveal') {
      el._ocOnce = !!cfg.o;
      if (!io) {
        io = new IntersectionObserver(function (entries) {
          entries.forEach(function (en) {
            if (en.isIntersecting) { en.target._ocShow(); if (en.target._ocOnce) io.unobserve(en.target); }
            else if (!en.target._ocOnce) en.target._ocReset();
          });
        }, { threshold: 0.15 });
      }
      io.observe(el);
    } else if (cfg.t === 'click') {
      el.style.cursor = 'pointer';
      el.addEventListener('click', function () {
        el._ocReset();
        requestAnimationFrame(function () { requestAnimationFrame(el._ocShow); });
      });
    } else if (cfg.t === 'scroll') {
      el.style.willChange = 'opacity, transform';
      scrollEls.push({ el: el, eff: eff, ds: ds || 40 });
    }
  });

  if (scrollEls.length) {
    var onScroll = function () {
      var vh = window.innerHeight || document.documentElement.clientHeight;
      scrollEls.forEach(function (s) {
        var r = s.el.getBoundingClientRect();
        var p = (vh - r.top) / (vh * 0.6);
        p = p < 0 ? 0 : p > 1 ? 1 : p;
        s.el.style.opacity = String(p);
        var off = (1 - p) * s.ds;
        if (s.eff === 'slide-up') s.el.style.transform = 'translateY(' + off + 'px)';
        else if (s.eff === 'slide-down') s.el.style.transform = 'translateY(' + (-off) + 'px)';
        else if (s.eff === 'slide-left') s.el.style.transform = 'translateX(' + off + 'px)';
        else if (s.eff === 'slide-right') s.el.style.transform = 'translateX(' + (-off) + 'px)';
        else if (s.eff === 'scale') s.el.style.transform = 'scale(' + (0.92 + p * 0.08) + ')';
      });
    };
    window.addEventListener('scroll', onScroll, { passive: true });
    window.addEventListener('resize', onScroll, { passive: true });
    onScroll();
  }
})();
