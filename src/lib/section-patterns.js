export const SECTION_CATEGORIES = ['All', 'Hero', 'Features', 'Content', 'CTA'];

export const SECTION_PATTERNS = [
  {
    id: 'hero-centered',
    name: 'Centered hero',
    category: 'Hero',
    html: `<section class="sp-hero">
  <p class="sp-hero__eyebrow" data-outpost="hero_eyebrow">Introducing</p>
  <h1 class="sp-hero__title" data-outpost="hero_title">Build pages that feel handmade</h1>
  <p class="sp-hero__sub" data-outpost="hero_sub">A clean starting point you can restyle in seconds. Swap the words, keep the polish.</p>
  <a class="sp-hero__cta" href="#" data-outpost="hero_cta">Get started</a>
</section>`,
    css: `.sp-hero { padding: clamp(3rem, 8vw, 7rem) 1.5rem; text-align: center; background: #0b0b12; color: #fff; }
.sp-hero__eyebrow { font-size: 0.85rem; letter-spacing: 0.14em; text-transform: uppercase; color: #a78bfa; margin: 0 0 1rem; }
.sp-hero__title { font-size: clamp(2rem, 5vw, 3.5rem); font-weight: 800; line-height: 1.05; margin: 0 auto 1rem; max-width: 18ch; }
.sp-hero__sub { font-size: clamp(1rem, 2vw, 1.2rem); color: rgba(255,255,255,0.72); margin: 0 auto 2rem; max-width: 46ch; line-height: 1.6; }
.sp-hero__cta { display: inline-block; padding: 0.9rem 1.8rem; border-radius: 10px; background: #6d5efc; color: #fff; font-weight: 600; text-decoration: none; }`,
  },
  {
    id: 'hero-split',
    name: 'Split hero',
    category: 'Hero',
    html: `<section class="sp-split">
  <div class="sp-split__text">
    <h1 class="sp-split__title" data-outpost="split_title">Your words, beautifully framed</h1>
    <p class="sp-split__sub" data-outpost="split_sub">Pair a strong headline with a supporting image. Everything here is an editable element.</p>
    <a class="sp-split__cta" href="#" data-outpost="split_cta">Learn more</a>
  </div>
  <img class="sp-split__img" src="" alt="" data-outpost="split_image" data-type="image">
</section>`,
    css: `.sp-split { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: clamp(1.5rem, 4vw, 3rem); align-items: center; padding: clamp(2.5rem, 6vw, 5rem) 1.5rem; max-width: 1100px; margin: 0 auto; }
.sp-split__title { font-size: clamp(1.8rem, 4vw, 3rem); font-weight: 800; line-height: 1.1; margin: 0 0 1rem; }
.sp-split__sub { font-size: 1.05rem; color: #555; line-height: 1.6; margin: 0 0 1.5rem; }
.sp-split__cta { display: inline-block; padding: 0.8rem 1.5rem; border-radius: 10px; background: #6d5efc; color: #fff; font-weight: 600; text-decoration: none; }
.sp-split__img { width: 100%; height: 100%; min-height: 240px; object-fit: cover; border-radius: 14px; background: #eceafd; }`,
  },
  {
    id: 'feature-grid',
    name: 'Feature grid',
    category: 'Features',
    html: `<section class="sp-feat">
  <h2 class="sp-feat__title" data-outpost="feat_title">Everything you need</h2>
  <div class="sp-feat__grid">
    <article class="sp-feat__card">
      <h3 class="sp-feat__name" data-outpost="feat_1_name">Fast</h3>
      <p class="sp-feat__desc" data-outpost="feat_1_desc">Static output that loads instantly, wherever you host it.</p>
    </article>
    <article class="sp-feat__card">
      <h3 class="sp-feat__name" data-outpost="feat_2_name">Editable</h3>
      <p class="sp-feat__desc" data-outpost="feat_2_desc">Mark any element as a field and edit it without touching code.</p>
    </article>
    <article class="sp-feat__card">
      <h3 class="sp-feat__name" data-outpost="feat_3_name">Yours</h3>
      <p class="sp-feat__desc" data-outpost="feat_3_desc">Own the files. No lock-in, no monthly platform tax.</p>
    </article>
  </div>
</section>`,
    css: `.sp-feat { padding: clamp(2.5rem, 6vw, 5rem) 1.5rem; max-width: 1100px; margin: 0 auto; }
.sp-feat__title { font-size: clamp(1.6rem, 3.5vw, 2.4rem); font-weight: 800; text-align: center; margin: 0 0 2.5rem; }
.sp-feat__grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1.25rem; }
.sp-feat__card { padding: 1.75rem; border: 1px solid #ececf2; border-radius: 14px; background: #fff; }
.sp-feat__name { font-size: 1.15rem; font-weight: 700; margin: 0 0 0.5rem; }
.sp-feat__desc { font-size: 0.95rem; color: #666; line-height: 1.6; margin: 0; }`,
  },
  {
    id: 'stats-band',
    name: 'Stats band',
    category: 'Content',
    html: `<section class="sp-stats">
  <div class="sp-stats__item">
    <span class="sp-stats__num" data-outpost="stat_1_num">10k+</span>
    <span class="sp-stats__label" data-outpost="stat_1_label">Pages shipped</span>
  </div>
  <div class="sp-stats__item">
    <span class="sp-stats__num" data-outpost="stat_2_num">99.9%</span>
    <span class="sp-stats__label" data-outpost="stat_2_label">Uptime</span>
  </div>
  <div class="sp-stats__item">
    <span class="sp-stats__num" data-outpost="stat_3_num">2 min</span>
    <span class="sp-stats__label" data-outpost="stat_3_label">To first edit</span>
  </div>
</section>`,
    css: `.sp-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 1.5rem; padding: clamp(2rem, 5vw, 3.5rem) 1.5rem; max-width: 900px; margin: 0 auto; text-align: center; }
.sp-stats__item { display: flex; flex-direction: column; gap: 0.35rem; }
.sp-stats__num { font-size: clamp(2rem, 5vw, 3rem); font-weight: 800; color: #6d5efc; }
.sp-stats__label { font-size: 0.9rem; color: #777; text-transform: uppercase; letter-spacing: 0.05em; }`,
  },
  {
    id: 'testimonial',
    name: 'Testimonial',
    category: 'Content',
    html: `<section class="sp-quote">
  <blockquote class="sp-quote__text" data-outpost="quote_text">This is the first builder that got out of my way. I shipped the whole site in an afternoon.</blockquote>
  <p class="sp-quote__author" data-outpost="quote_author">Alex Rivera, Founder</p>
</section>`,
    css: `.sp-quote { padding: clamp(2.5rem, 6vw, 5rem) 1.5rem; max-width: 720px; margin: 0 auto; text-align: center; }
.sp-quote__text { font-size: clamp(1.3rem, 3vw, 1.9rem); font-weight: 500; line-height: 1.4; color: #1a1a22; margin: 0 0 1.25rem; }
.sp-quote__author { font-size: 0.95rem; color: #777; margin: 0; }`,
  },
  {
    id: 'cta-band',
    name: 'CTA band',
    category: 'CTA',
    html: `<section class="sp-cta">
  <h2 class="sp-cta__title" data-outpost="cta_title">Ready to ship?</h2>
  <p class="sp-cta__sub" data-outpost="cta_sub">Start free — no card, no lock-in.</p>
  <a class="sp-cta__btn" href="#" data-outpost="cta_href">Start building</a>
</section>`,
    css: `.sp-cta { padding: clamp(2.5rem, 6vw, 4.5rem) 1.5rem; text-align: center; background: linear-gradient(135deg, #6d5efc, #8b5cf6); color: #fff; }
.sp-cta__title { font-size: clamp(1.6rem, 4vw, 2.6rem); font-weight: 800; margin: 0 0 0.5rem; }
.sp-cta__sub { font-size: 1.05rem; color: rgba(255,255,255,0.85); margin: 0 0 1.75rem; }
.sp-cta__btn { display: inline-block; padding: 0.9rem 1.9rem; border-radius: 10px; background: #fff; color: #4c3fd8; font-weight: 700; text-decoration: none; }`,
  },
  {
    id: 'content-lead',
    name: 'Lead paragraph',
    category: 'Content',
    html: `<section class="sp-lead">
  <h2 class="sp-lead__title" data-outpost="lead_title">A short story about your work</h2>
  <p class="sp-lead__body" data-outpost="lead_body" data-type="richtext">Open with the one thing that matters most. Keep it human, keep it specific, and let the rest of the page earn attention from there.</p>
</section>`,
    css: `.sp-lead { padding: clamp(2.5rem, 6vw, 5rem) 1.5rem; max-width: 680px; margin: 0 auto; }
.sp-lead__title { font-size: clamp(1.6rem, 3.5vw, 2.4rem); font-weight: 800; margin: 0 0 1rem; }
.sp-lead__body { font-size: 1.15rem; color: #444; line-height: 1.7; margin: 0; }`,
  },
  {
    id: 'feature-media',
    name: 'Media + points',
    category: 'Features',
    html: `<section class="sp-media">
  <img class="sp-media__img" src="" alt="" data-outpost="media_image" data-type="image">
  <div class="sp-media__body">
    <h2 class="sp-media__title" data-outpost="media_title">Show, don't just tell</h2>
    <ul class="sp-media__list">
      <li class="sp-media__item" data-outpost="media_pt_1">Pair a visual with three tight points.</li>
      <li class="sp-media__item" data-outpost="media_pt_2">Each point is its own editable field.</li>
      <li class="sp-media__item" data-outpost="media_pt_3">Swap the image with one click.</li>
    </ul>
  </div>
</section>`,
    css: `.sp-media { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: clamp(1.5rem, 4vw, 3rem); align-items: center; padding: clamp(2.5rem, 6vw, 5rem) 1.5rem; max-width: 1100px; margin: 0 auto; }
.sp-media__img { width: 100%; min-height: 240px; object-fit: cover; border-radius: 14px; background: #eceafd; }
.sp-media__title { font-size: clamp(1.6rem, 3.5vw, 2.4rem); font-weight: 800; margin: 0 0 1.25rem; }
.sp-media__list { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 0.85rem; }
.sp-media__item { position: relative; padding-left: 1.5rem; color: #444; line-height: 1.5; }
.sp-media__item::before { content: ""; position: absolute; left: 0; top: 0.5rem; width: 0.6rem; height: 0.6rem; border-radius: 50%; background: #6d5efc; }`,
  },
];
