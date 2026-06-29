<script>
  import { pages as pagesApi } from '$lib/api.js';
  import { navigate, addToast } from '$lib/stores.js';

  let title = $state('');
  let slug = $state('');
  let slugTouched = $state(false);
  let html = $state('');
  let css = $state('');
  let js = $state('');
  let pane = $state('html');
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
      const res = await pagesApi.importHtml(title.trim(), autoSlug || slugify(title), html, css, js);
      const parts = [];
      if (res.fields) parts.push(`${res.fields} field${res.fields === 1 ? '' : 's'}`);
      if (res.classes) parts.push(`${res.classes} class${res.classes === 1 ? '' : 'es'}`);
      addToast(parts.length ? `Imported — ${parts.join(', ')}` : 'Page imported', 'success');
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
    <p class="sub">Paste a page's HTML, CSS, and JS. Mark dynamic parts with <code>data-outpost</code> for editable fields; CSS classes populate your Selectors panel automatically.</p>

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

    <div class="panes">
      <div class="pane-tabs" role="tablist" aria-label="Code panes">
        <button type="button" role="tab" aria-selected={pane === 'html'} class:on={pane === 'html'} onclick={() => (pane = 'html')}>HTML</button>
        <button type="button" role="tab" aria-selected={pane === 'css'} class:on={pane === 'css'} onclick={() => (pane = 'css')}>CSS{css.trim() ? ' •' : ''}</button>
        <button type="button" role="tab" aria-selected={pane === 'js'} class:on={pane === 'js'} onclick={() => (pane = 'js')}>JS{js.trim() ? ' •' : ''}</button>
      </div>

      {#if pane === 'html'}
        <textarea bind:value={html} rows="16" spellcheck="false" aria-label="HTML"
          placeholder={'<main>\n  <h1 data-outpost="headline">Welcome</h1>\n  <p data-outpost="body" data-type="textarea">Your text…</p>\n</main>'}
        ></textarea>
      {:else if pane === 'css'}
        <textarea bind:value={css} rows="16" spellcheck="false" aria-label="CSS"
          placeholder={'.headline { font-size: 3rem; font-weight: 800; }\n.btn { padding: 12px 20px; border-radius: 8px; }'}
        ></textarea>
      {:else}
        <textarea bind:value={js} rows="16" spellcheck="false" aria-label="JavaScript"
          placeholder={"document.querySelector('.btn')?.addEventListener('click', () => {});"}
        ></textarea>
      {/if}
      <p class="pane-hint">CSS classes explode into your Selectors panel (created or updated). CSS &amp; JS are saved as the page’s assets and linked automatically.</p>
    </div>

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
  .field input::placeholder { color: var(--dim); }
  .field input:focus-visible { outline: none; border-color: var(--purple); }

  .panes { margin-bottom: 18px; }
  .pane-tabs { display: flex; gap: 4px; margin-bottom: 8px; }
  .pane-tabs button {
    padding: 7px 14px;
    border: none;
    border-radius: 7px;
    background: transparent;
    color: var(--sec);
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
  }
  .pane-tabs button.on { background: var(--hover); color: var(--text); }
  .pane-tabs button:focus-visible { outline: 2px solid var(--purple); outline-offset: 1px; }

  .panes textarea {
    width: 100%;
    padding: 12px 14px;
    border: 1px solid var(--border);
    border-radius: 10px;
    background: var(--raised);
    color: var(--text);
    font-family: var(--font-mono, ui-monospace, monospace);
    font-size: 13px;
    line-height: 1.55;
    resize: vertical;
  }
  .panes textarea::placeholder { color: var(--dim); }
  .panes textarea:focus-visible { outline: none; border-color: var(--purple); }
  .pane-hint { font-size: 12px; color: var(--dim); line-height: 1.5; margin: 8px 2px 0; }

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
