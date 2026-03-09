// Outpost CMS Docs — Shared JS

// Load shared sidebar
(function() {
  var sidebar = document.querySelector('.sidebar');
  if (!sidebar) return;

  // Resolve base path for fetching sidebar.html
  var base = document.querySelector('base');
  var basePath = base ? base.getAttribute('href') : '';
  if (basePath && !basePath.endsWith('/')) basePath += '/';

  fetch(basePath + 'assets/sidebar.html')
    .then(function(r) { return r.text(); })
    .then(function(html) {
      sidebar.innerHTML = html;

      // Set active link based on current page path
      var path = location.pathname;
      // Strip base path prefix to get relative doc path
      var docsRoot = path.indexOf('/outpost/docs/');
      var rel = docsRoot >= 0 ? path.slice(docsRoot + '/outpost/docs/'.length) : '';
      if (!rel || rel === '/') rel = 'index.html';
      // Trailing slash = directory index
      if (rel.endsWith('/')) rel = rel + 'index.html';
      // No extension = directory index
      if (rel.indexOf('.') === -1) rel = rel + '/index.html';

      var activeSection = null;

      sidebar.querySelectorAll('.nav-link').forEach(function(link) {
        var href = link.getAttribute('href');
        // Normalize: "themes/" -> "themes/index.html"
        var normalized = href;
        if (normalized.endsWith('/')) normalized += 'index.html';
        if (normalized === rel) {
          link.classList.add('active');
          // Find the parent nav-section to auto-open it
          activeSection = link.closest('.nav-section');
        }
      });

      // Accordion: toggle sections on label click
      sidebar.querySelectorAll('.nav-section-label').forEach(function(label) {
        label.addEventListener('click', function() {
          var section = this.closest('.nav-section');
          var isOpen = section.classList.contains('open');

          // Close all sections
          sidebar.querySelectorAll('.nav-section.open').forEach(function(s) {
            s.classList.remove('open');
          });

          // If it wasn't open, open it
          if (!isOpen) {
            section.classList.add('open');
          }
        });
      });

      // Auto-open the section containing the active page
      if (activeSection) {
        activeSection.classList.add('open');
      }

      // Re-run anchor fix for sidebar links
      sidebar.querySelectorAll('a[href^="#"]').forEach(function(link) {
        link.addEventListener('click', function(e) {
          e.preventDefault();
          var id = this.getAttribute('href').slice(1);
          var el = document.getElementById(id);
          if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
      });
    });
})();

// Fix anchor links when <base> tag is present
document.querySelectorAll('a[href^="#"]').forEach(function(link) {
  link.addEventListener('click', function(e) {
    e.preventDefault();
    var id = this.getAttribute('href').slice(1);
    var el = document.getElementById(id);
    if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
  });
});

// Scroll margin for headings
document.querySelectorAll('h2[id], h3[id]').forEach(function(el) {
  el.style.scrollMarginTop = '24px';
});
