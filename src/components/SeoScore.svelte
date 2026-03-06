<script>
  let {
    title = '',
    metaTitle = '',
    metaDescription = '',
    slug = '',
    body = '',
    featuredImage = '',
  } = $props();

  let focusKeyword = $state('');

  function stripHtml(html) {
    return (html || '')
      .replace(/<[^>]*>/g, ' ')
      .replace(/&amp;/g, '&').replace(/&lt;/g, '<').replace(/&gt;/g, '>')
      .replace(/&nbsp;/g, ' ').replace(/&quot;/g, '"').replace(/&#39;/g, "'")
      .replace(/\s+/g, ' ').trim();
  }

  let plainText = $derived(stripHtml(body));
  let wordCount = $derived(plainText ? plainText.split(/\s+/).filter(Boolean).length : 0);
  let hasBody = $derived((body || '').replace(/<[^>]*>/g, '').trim().length > 20);

  let firstParaText = $derived.by(() => {
    const m = (body || '').match(/<p[^>]*>([\s\S]*?)<\/p>/i);
    return m ? stripHtml(m[1]) : plainText.slice(0, 300);
  });

  let kw = $derived(focusKeyword.toLowerCase().trim());
  let hasKw = $derived(kw.length > 0);

  let checks = $derived.by(() => {
    const mt = (metaTitle || '').trim();
    const md = (metaDescription || '').trim();
    const t = (title || '').trim();
    const effectiveTitle = (mt || t).toLowerCase();
    const mdLower = md.toLowerCase();
    const textLower = plainText.toLowerCase();
    const firstLower = firstParaText.toLowerCase();
    const slugLower = (slug || '').replace(/^\//, '').toLowerCase();
    const kwSlug = kw.replace(/\s+/g, '-');

    return [
      // Title
      { group: 'Title', label: 'Meta title is set', pass: mt.length > 0, points: 15 },
      { group: 'Title', label: `Title length: ${(mt || t).length} / 60 chars`, pass: (mt || t).length > 0 && (mt || t).length <= 60, points: 10 },
      hasKw && { group: 'Title', label: 'Focus keyword in title', pass: effectiveTitle.includes(kw), points: 10 },
      hasKw && { group: 'Title', label: 'Keyword at start of title', pass: effectiveTitle.trimStart().startsWith(kw), points: 5 },

      // Meta description
      { group: 'Meta', label: 'Meta description is set', pass: md.length > 0, points: 15 },
      { group: 'Meta', label: `Description: ${md.length} chars (50–160)`, pass: md.length >= 50 && md.length <= 160, points: 10 },
      hasKw && { group: 'Meta', label: 'Focus keyword in description', pass: mdLower.includes(kw), points: 10 },

      // URL
      hasKw && { group: 'URL', label: 'Keyword in URL slug', pass: slugLower.includes(kw) || slugLower.includes(kwSlug), points: 5 },

      // Content (only when body exists)
      hasBody && { group: 'Content', label: `Word count: ${wordCount} words (≥ 300)`, pass: wordCount >= 300, points: 15 },
      hasBody && hasKw && { group: 'Content', label: 'Keyword in first paragraph', pass: firstLower.includes(kw), points: 5 },
      hasBody && hasKw && { group: 'Content', label: 'Keyword appears in body', pass: textLower.includes(kw), points: 5 },
      hasBody && { group: 'Content', label: 'Featured image is set', pass: !!featuredImage, points: 5 },
    ].filter(Boolean);
  });

  let totalPoints = $derived(checks.reduce((s, c) => s + c.points, 0));
  let earnedPoints = $derived(checks.filter(c => c.pass).reduce((s, c) => s + c.points, 0));
  let score = $derived(totalPoints > 0 ? Math.round(earnedPoints / totalPoints * 100) : 0);
  let scoreLabel = $derived(
    score >= 80 ? 'Excellent' :
    score >= 60 ? 'Good' :
    score >= 40 ? 'Needs Work' : 'Poor'
  );

  let groups = $derived.by(() => {
    const map = {};
    for (const c of checks) {
      if (!map[c.group]) map[c.group] = [];
      map[c.group].push(c);
    }
    return Object.entries(map).map(([name, items]) => ({ name, items }));
  });

  // Gauge — semicircle arc
  const R = 52;
  const CIRC = Math.PI * R; // ≈ 163.4
  let dashOffset = $derived(CIRC * (1 - score / 100));
  let gaugeColor = $derived(
    score >= 80 ? 'var(--success)' :
    score >= 60 ? 'var(--warning)' :
    score >= 40 ? 'var(--clay)' :
    'var(--danger)'
  );
</script>

<div class="seo-score">

  <!-- Gauge + label stacked -->
  <div class="seo-top">
    <svg class="seo-gauge" viewBox="0 0 140 85" xmlns="http://www.w3.org/2000/svg">
      <!-- Track -->
      <path class="gauge-track" d="M 18,74 A 52,52 0 0,1 122,74" />
      <!-- Fill -->
      <path
        class="gauge-fill"
        d="M 18,74 A 52,52 0 0,1 122,74"
        stroke={gaugeColor}
        stroke-dasharray={CIRC}
        stroke-dashoffset={dashOffset}
      />
      <text x="70" y="56" text-anchor="middle" class="gauge-num">{score}</text>
      <text x="70" y="76" text-anchor="middle" class="gauge-sub">/ 100</text>
    </svg>
    <div class="seo-label-tag">{scoreLabel}</div>
    {#if hasBody}
      <div class="seo-wc">
        <span class="seo-wc-num">{wordCount.toLocaleString()}</span>
        <span class="seo-wc-lbl">words</span>
      </div>
    {/if}
  </div>

  <hr class="seo-divider" />

  <!-- Focus keyword -->
  <div class="seo-kw-row">
    <label class="seo-field-label">Focus Keyword</label>
    <input
      class="seo-kw-input"
      type="text"
      bind:value={focusKeyword}
      placeholder="e.g. web design agency"
    />
  </div>

  <hr class="seo-divider" />

  <!-- Check groups -->
  <div class="seo-groups">
    {#each groups as group}
      <div class="seo-group">
        <div class="seo-group-hd">
          <span>{group.name}</span>
          <span class="seo-group-count">{group.items.filter(c => c.pass).length}/{group.items.length}</span>
        </div>
        {#each group.items as check}
          <div class="seo-check" class:pass={check.pass}>
            <span class="seo-dot"></span>
            <span class="seo-check-text">{check.label}</span>
          </div>
        {/each}
      </div>
    {/each}
  </div>

</div>

<style>
  .seo-score {
    padding: 4px 0 0;
  }

  /* ── Gauge — centered, stacked ── */
  .seo-top {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 4px;
    margin-bottom: 0;
  }

  .seo-gauge {
    width: 160px;
  }

  .gauge-track {
    fill: none;
    stroke: var(--border-primary);
    stroke-width: 7;
    stroke-linecap: round;
  }

  .gauge-fill {
    fill: none;
    stroke-width: 7;
    stroke-linecap: round;
    transition: stroke-dashoffset 0.5s ease, stroke 0.3s ease;
  }

  .gauge-num {
    font-size: 28px;
    font-weight: 700;
    fill: var(--text-primary);
    font-family: inherit;
  }

  .gauge-sub {
    font-size: 11px;
    fill: var(--text-tertiary);
    font-family: inherit;
  }

  .seo-label-tag {
    font-size: 13px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: var(--text-secondary);
  }

  .seo-wc {
    display: flex;
    align-items: baseline;
    gap: 4px;
    margin-top: 2px;
  }

  .seo-wc-num {
    font-size: 14px;
    font-weight: 600;
    color: var(--text-primary);
  }

  .seo-wc-lbl {
    font-size: 11px;
    color: var(--text-tertiary);
    text-transform: uppercase;
    letter-spacing: 0.05em;
  }

  /* ── Divider ── */
  .seo-divider {
    border: none;
    border-top: 1px solid var(--border-secondary);
    margin: 16px 0;
  }

  /* ── Focus keyword ── */
  .seo-kw-row {
    margin-bottom: 16px;
  }

  .seo-field-label {
    display: block;
    font-size: 11px;
    font-weight: 500;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: var(--text-tertiary);
    margin-bottom: 5px;
  }

  .seo-kw-input {
    width: 100%;
    box-sizing: border-box;
    border: 1px solid transparent;
    border-radius: 6px;
    background: var(--bg-tertiary);
    font-size: 13px;
    padding: 8px 10px;
    color: var(--text-primary);
    outline: none;
    font-family: inherit;
    transition: border-color 0.15s, box-shadow 0.15s;
  }

  .seo-kw-input:hover {
    border-color: var(--border-secondary);
  }

  .seo-kw-input:focus {
    border-color: var(--accent);
    box-shadow: 0 0 0 3px var(--accent-soft);
  }

  .seo-kw-input::placeholder {
    color: var(--text-tertiary);
  }

  /* ── Check groups ── */
  .seo-groups {
    display: flex;
    flex-direction: column;
    gap: 14px;
  }

  .seo-group {
    display: flex;
    flex-direction: column;
    gap: 5px;
  }

  .seo-group-hd {
    display: flex;
    justify-content: space-between;
    font-size: 11px;
    font-weight: 500;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: var(--text-tertiary);
    margin-bottom: 2px;
  }

  .seo-group-count {
    font-weight: 400;
  }

  .seo-check {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 12px;
    color: var(--text-tertiary);
    line-height: 1.4;
  }

  .seo-check.pass {
    color: var(--text-secondary);
  }

  .seo-dot {
    flex-shrink: 0;
    width: 7px;
    height: 7px;
    border-radius: 50%;
    border: 1.5px solid var(--border-primary);
    background: transparent;
    transition: background 0.2s, border-color 0.2s;
  }

  .seo-check.pass .seo-dot {
    background: var(--success);
    border-color: var(--success);
  }

  .seo-check-text {
    flex: 1;
  }
</style>
