<script>
  import { pages as pagesApi } from '$lib/api.js';
  import { navigate, addToast } from '$lib/stores.js';

  let title = $state('');
  let slug = $state('');
  let slugTouched = $state(false);
  let html = $state('');
  let importing = $state(false);
  let titleEl = $state(null);

  function slugify(s) {
    return s.toLowerCase().trim().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
  }

  let autoSlug = $derived(slugTouched ? slug : slugify(title));
  let ready = $derived(title.trim() && html.trim());

  $effect(() => {
    titleEl?.focus();
  });

  async function submit(e) {
    e?.preventDefault();
    if (!ready || importing) return;
    importing = true;
    try {
      const res = await pagesApi.importHtml(title.trim(), autoSlug || slugify(title), html);
      const n = res.fields || 0;
      addToast(n ? `Imported with ${n} editable field${n === 1 ? '' : 's'}` : 'Page imported', 'success');
      navigate('pages');
    } catch (err) {
      if (err && (err.status === 409 || err.name === 'ConflictError')) {
        addToast('A page with that slug already exists. Change the slug and try again.', 'error');
      } else {
        addToast(err.message || 'Import failed', 'error');
      }
      importing = false;
    }
  }
</script>

<div class="wrap">
  <form class="card" onsubmit={submit}>
    <h1>Import HTML</h1>
    <p class="sub">Paste a static HTML page (hand-built, Astro, or AI-generated). Mark dynamic parts with <code>data-outpost</code> and they become editable fields automatically.</p>

    <div class="row">
      <label class="field">
        <span>Page title</span>
        <input bind:this={titleEl} type="text" bind:value={title} placeholder="e.g. Pricing" autocomplete="off" />
      </label>
      <label class="field">
        <span>URL slug</span>
        <input
          type="text"
          value={autoSlug}
          oninput={(e) => { slugTouched = true; slug = e.target.value; }}
          placeholder="auto-from-title"
          autocomplete="off"
        />
      </label>
    </div>

    <label class="field">
      <span>HTML</span>
      <textarea
        bind:value={html}
        rows="16"
        spellcheck="false"
        placeholder={'<main>\n  <h1 data-outpost="headline">Welcome</h1>\n  <p data-outpost="body" data-type="textarea">Your text…</p>\n</main>'}
      ></textarea>
    </label>

    <button type="submit" class="go" disabled={!ready || importing}>
      {importing ? 'Importing…' : 'Import page'}
    </button>
    <button type="button" class="cancel" onclick={() => navigate('pages')}>Cancel and go back to Pages</button>
  </form>
</div>

<style>
  .wrap { min-height: 100%; display: flex; justify-content: center; padding: 8vh 24px 80px; }
  .card { width: 100%; max-width: 720px; display: flex; flex-direction: column; }

  h1 { font-size: 30px; font-weight: 800; letter-spacing: -0.02em; color: var(--text); text-align: center; margin: 0; }
  .sub { font-size: 14px; color: var(--dim); text-align: center; line-height: 1.5; margin: 10px auto 32px; max-width: 540px; }
  .sub code { font-family: var(--font-mono, ui-monospace, monospace); color: var(--purple-soft); }

  .row { display: flex; gap: 14px; }
  .row .field { flex: 1; }

  .field { display: flex; flex-direction: column; gap: 8px; margin-bottom: 18px; }
  .field span { font-size: 13px; font-weight: 600; color: var(--sec); }
  .field input, .field textarea {
    width: 100%;
    padding: 12px 14px;
    border: 1px solid var(--border);
    border-radius: 10px;
    background: var(--raised);
    color: var(--text);
    font-size: 14px;
  }
  .field textarea {
    font-family: var(--font-mono, ui-monospace, monospace);
    font-size: 13px;
    line-height: 1.55;
    resize: vertical;
  }
  .field input::placeholder, .field textarea::placeholder { color: var(--dim); }
  .field input:focus-visible, .field textarea:focus-visible { outline: none; border-color: var(--purple); }

  .go {
    margin-top: 8px;
    padding: 14px;
    border: none;
    border-radius: 10px;
    background: var(--purple);
    color: #fff;
    font-size: 15px;
    font-weight: 600;
    cursor: pointer;
  }
  .go:hover:not(:disabled) { background: var(--accent-hover); }
  .go:disabled { opacity: 0.45; cursor: default; }
  .go:focus-visible { outline: 2px solid var(--purple-soft); outline-offset: 2px; }

  .cancel { margin-top: 16px; padding: 8px; border: none; background: none; color: var(--dim); font-size: 14px; cursor: pointer; }
  .cancel:hover { color: var(--sec); }
  .cancel:focus-visible { outline: 2px solid var(--purple); outline-offset: 2px; border-radius: 6px; }
</style>
