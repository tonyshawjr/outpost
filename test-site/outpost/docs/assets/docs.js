// Outpost CMS Docs — Shared JS

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
