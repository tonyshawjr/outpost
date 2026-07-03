<script>
  import { pages as pagesApi } from '$lib/api.js';
  import { navigate, addToast } from '$lib/stores.js';
  import Checkbox from '$components/Checkbox.svelte';

  let mode = $state('choose');

  let title = $state('');
  let slug = $state('');
  let slugTouched = $state(false);
  let html = $state('');
  let css = $state('');
  let js = $state('');
  let pane = $state('html');
  let importing = $state(false);
  let titleEl = $state(null);

  let sitePhase = $state('pick');
  let dragging = $state(false);
  let manifest = $state(null);
  let stagingId = $state('');
  let fileName = $state('');
  let overwrite = $state(true);

  function slugify(s) {
    return s.toLowerCase().trim().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
  }

  let autoSlug = $derived(slugTouched ? slug : slugify(title));
  let ready = $derived(title.trim() && html.trim());

  $effect(() => {
    if (mode === 'page') titleEl?.focus();
  });

  function formatBytes(n) {
    if (!n) return '0 B';
    const units = ['B', 'KB', 'MB'];
    let i = 0;
    let v = n;
    while (v >= 1024 && i < units.length - 1) { v /= 1024; i++; }
    return `${v.toFixed(v < 10 && i > 0 ? 1 : 0)} ${units[i]}`;
  }

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

  async function stageFile(file) {
    if (!file) return;
    if (!/\.zip$/i.test(file.name)) {
      addToast('Please choose a .zip of your site folder', 'error');
      return;
    }
    if (file.size > 80 * 1048576) {
      addToast('Zip too large (max 80 MB)', 'error');
      return;
    }
    fileName = file.name;
    sitePhase = 'staging';
    try {
      const res = await pagesApi.importSiteStage(file);
      stagingId = res.stagingId;
      manifest = res;
      sitePhase = 'review';
    } catch (err) {
      addToast(err.message || 'Could not read that zip', 'error');
      sitePhase = 'pick';
    }
  }

  function onDrop(e) {
    e.preventDefault();
    dragging = false;
    stageFile(e.dataTransfer?.files?.[0]);
  }

  async function applyImport() {
    if (sitePhase === 'applying') return;
    sitePhase = 'applying';
    try {
      const res = await pagesApi.importSiteApply(stagingId, overwrite);
      const parts = [`${res.filesWritten} file${res.filesWritten === 1 ? '' : 's'}`];
      if (res.pages?.length) parts.push(`${res.pages.length} page${res.pages.length === 1 ? '' : 's'}`);
      if (res.classes) parts.push(`${res.classes} class${res.classes === 1 ? '' : 'es'}`);
      addToast(`Site imported — ${parts.join(', ')}`, 'success');
      navigate('pages');
    } catch (err) {
      addToast(err.message || 'Import failed', 'error');
      sitePhase = 'review';
    }
  }

  async function discardStaged() {
    if (stagingId) {
      try { await pagesApi.importSiteDiscard(stagingId); } catch (err) {}
    }
    stagingId = '';
    manifest = null;
    fileName = '';
    sitePhase = 'pick';
  }

  let conflictCount = $derived(
    manifest ? (manifest.conflicts?.files?.length || 0) + (manifest.conflicts?.pages?.length || 0) : 0
  );
</script>

<div class="wrap">
  {#if mode === 'choose'}
    <div class="card choose">
      <h1>Import</h1>
      <p class="sub">Bring an existing site into Outpost. Pick how much you're bringing in.</p>
      <div class="choices">
        <button type="button" class="choice" onclick={() => (mode = 'site')}>
          <span class="choice-title">A whole site</span>
          <span class="choice-desc">Upload a .zip of your site folder — HTML pages, CSS, JS, images and fonts. We stage it, show you what we found, then you apply.</span>
        </button>
        <button type="button" class="choice" onclick={() => (mode = 'page')}>
          <span class="choice-title">A single page</span>
          <span class="choice-desc">Paste one page's HTML, CSS and JS. Mark dynamic parts with <code>data-outpost</code> to make them editable.</span>
        </button>
      </div>
      <button type="button" class="cancel" onclick={() => navigate('pages')}>Cancel and go back to Pages</button>
    </div>

  {:else if mode === 'page'}
    <form class="card" onsubmit={submit}>
      <h1>Import a page</h1>
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
      <button type="button" class="cancel" onclick={() => (mode = 'choose')}>Back</button>
    </form>

  {:else}
    <div class="card">
      <h1>Import a whole site</h1>

      {#if sitePhase === 'pick'}
        <p class="sub">Upload a <code>.zip</code> of your site folder. We only accept web assets — HTML, CSS, JS, images and fonts. Anything else (including PHP) is skipped.</p>
        <label
          class="drop"
          class:drag={dragging}
          ondragover={(e) => { e.preventDefault(); dragging = true; }}
          ondragleave={() => (dragging = false)}
          ondrop={onDrop}
        >
          <input type="file" accept=".zip,application/zip" onchange={(e) => stageFile(e.target.files?.[0])} />
          <span class="drop-title">Drop your site .zip here</span>
          <span class="drop-sub">or click to browse — max 80 MB</span>
        </label>
        <button type="button" class="cancel" onclick={() => (mode = 'choose')}>Back</button>

      {:else if sitePhase === 'staging'}
        <p class="sub">Reading <strong>{fileName}</strong>…</p>
        <div class="busy"><span class="spinner"></span> Staging your files</div>

      {:else if sitePhase === 'review'}
        <p class="sub">Staged <strong>{fileName}</strong> — nothing is live yet. Review what we found, then apply.</p>

        <div class="stats">
          <div class="stat"><span class="n">{manifest.pages.length}</span><span class="l">Pages</span></div>
          <div class="stat"><span class="n">{manifest.cssFiles.length}</span><span class="l">Stylesheets</span></div>
          <div class="stat"><span class="n">{manifest.jsFiles.length}</span><span class="l">Scripts</span></div>
          <div class="stat"><span class="n">{manifest.assets.length}</span><span class="l">Assets</span></div>
        </div>

        {#if manifest.pages.length}
          <div class="section">
            <h2>Pages</h2>
            <ul class="list">
              {#each manifest.pages as p}
                <li>
                  <span class="path">{p.path}</span>
                  <span class="badge" class:exists={p.exists}>{p.exists ? 'Updates existing' : 'New'}</span>
                </li>
              {/each}
            </ul>
          </div>
        {/if}

        {#if conflictCount > 0}
          <div class="warn">
            <strong>{conflictCount} file{conflictCount === 1 ? '' : 's'} already exist{conflictCount === 1 ? 's' : ''}</strong> in your site.
            <div class="overwrite">
              <Checkbox bind:checked={overwrite} label="Overwrite existing files" />
            </div>
          </div>
        {/if}

        {#if manifest.skipped?.length}
          <details class="skipped">
            <summary>{manifest.skipped.length} file{manifest.skipped.length === 1 ? '' : 's'} skipped</summary>
            <ul>
              {#each manifest.skipped.slice(0, 60) as s}<li>{s}</li>{/each}
              {#if manifest.skipped.length > 60}<li>…and {manifest.skipped.length - 60} more</li>{/if}
            </ul>
          </details>
        {/if}

        <button type="button" class="go" onclick={applyImport}>
          Apply — write {manifest.fileCount} file{manifest.fileCount === 1 ? '' : 's'} ({formatBytes(manifest.totalBytes)})
        </button>
        <button type="button" class="cancel" onclick={discardStaged}>Discard and choose a different zip</button>

      {:else}
        <p class="sub">Writing files to your site…</p>
        <div class="busy"><span class="spinner"></span> Applying import</div>
      {/if}
    </div>
  {/if}
</div>

<style>
  .wrap { min-height: 100%; display: flex; justify-content: center; padding: 8vh 24px 80px; }
  .card { width: 100%; max-width: 720px; display: flex; flex-direction: column; }

  h1 { font-size: 30px; font-weight: 800; letter-spacing: -0.02em; color: var(--text); text-align: center; margin: 0; }
  h2 { font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.06em; color: var(--dim); margin: 0 0 10px; }
  .sub { font-size: 14px; color: var(--dim); text-align: center; line-height: 1.5; margin: 10px auto 32px; max-width: 540px; }
  .sub code { font-family: var(--font-mono, ui-monospace, monospace); color: var(--purple-soft); }

  .choices { display: flex; flex-direction: column; gap: 12px; margin-bottom: 20px; }
  .choice {
    display: flex; flex-direction: column; gap: 6px;
    padding: 20px 22px; text-align: left;
    border: 1px solid var(--border); border-radius: 12px;
    background: var(--raised); color: var(--text); cursor: pointer;
  }
  .choice:hover { border-color: var(--purple); }
  .choice:focus-visible { outline: 2px solid var(--purple); outline-offset: 2px; }
  .choice-title { font-size: 16px; font-weight: 700; }
  .choice-desc { font-size: 13px; color: var(--dim); line-height: 1.5; }
  .choice-desc code { font-family: var(--font-mono, ui-monospace, monospace); color: var(--purple-soft); }

  .row { display: flex; gap: 14px; }
  .row .field { flex: 1; }

  .field { display: flex; flex-direction: column; gap: 8px; margin-bottom: 18px; }
  .field span { font-size: 13px; font-weight: 600; color: var(--sec); }
  .field input, .field textarea {
    width: 100%; padding: 12px 14px;
    border: 1px solid var(--border); border-radius: 10px;
    background: var(--raised); color: var(--text); font-size: 14px;
  }
  .field input::placeholder { color: var(--dim); }
  .field input:focus-visible { outline: none; border-color: var(--purple); }

  .panes { margin-bottom: 18px; }
  .pane-tabs { display: flex; gap: 4px; margin-bottom: 8px; }
  .pane-tabs button {
    padding: 7px 14px; border: none; border-radius: 7px;
    background: transparent; color: var(--sec);
    font-size: 13px; font-weight: 600; cursor: pointer;
  }
  .pane-tabs button.on { background: var(--hover); color: var(--text); }
  .pane-tabs button:focus-visible { outline: 2px solid var(--purple); outline-offset: 1px; }

  .panes textarea {
    width: 100%; padding: 12px 14px;
    border: 1px solid var(--border); border-radius: 10px;
    background: var(--raised); color: var(--text);
    font-family: var(--font-mono, ui-monospace, monospace);
    font-size: 13px; line-height: 1.55; resize: vertical;
  }
  .panes textarea::placeholder { color: var(--dim); }
  .panes textarea:focus-visible { outline: none; border-color: var(--purple); }
  .pane-hint { font-size: 12px; color: var(--dim); line-height: 1.5; margin: 8px 2px 0; }

  .drop {
    display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 6px;
    padding: 52px 24px; margin-bottom: 18px;
    border: 2px dashed var(--border); border-radius: 14px;
    background: var(--raised); cursor: pointer; text-align: center;
  }
  .drop:hover, .drop.drag { border-color: var(--purple); background: var(--hover); }
  .drop:focus-within { outline: 2px solid var(--purple); outline-offset: 2px; }
  .drop input { position: absolute; width: 1px; height: 1px; opacity: 0; pointer-events: none; }
  .drop-title { font-size: 15px; font-weight: 600; color: var(--text); }
  .drop-sub { font-size: 13px; color: var(--dim); }

  .busy { display: flex; align-items: center; justify-content: center; gap: 10px; padding: 40px 0; color: var(--sec); font-size: 14px; }
  .spinner {
    width: 18px; height: 18px; border-radius: 50%;
    border: 2px solid var(--border); border-top-color: var(--purple);
    animation: spin 0.7s linear infinite;
  }
  @keyframes spin { to { transform: rotate(360deg); } }

  .stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; margin-bottom: 24px; }
  .stat { display: flex; flex-direction: column; align-items: center; gap: 2px; padding: 16px 8px; border: 1px solid var(--border); border-radius: 10px; }
  .stat .n { font-size: 24px; font-weight: 800; color: var(--text); }
  .stat .l { font-size: 11px; text-transform: uppercase; letter-spacing: 0.05em; color: var(--dim); }

  .section { margin-bottom: 22px; }
  .list { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; }
  .list li { display: flex; align-items: center; justify-content: space-between; padding: 9px 2px; border-bottom: 1px solid var(--border); }
  .list li:last-child { border-bottom: none; }
  .path { font-family: var(--font-mono, ui-monospace, monospace); font-size: 13px; color: var(--text); }
  .badge { font-size: 11px; font-weight: 600; color: var(--purple-soft); }
  .badge.exists { color: var(--dim); }

  .warn { padding: 14px 16px; margin-bottom: 22px; border: 1px solid var(--border); border-radius: 10px; background: var(--raised); font-size: 13px; color: var(--sec); line-height: 1.5; }
  .warn strong { color: var(--text); }
  .overwrite { margin-top: 10px; }

  .skipped { margin-bottom: 22px; font-size: 13px; color: var(--dim); }
  .skipped summary { cursor: pointer; }
  .skipped ul { margin: 8px 0 0; padding-left: 18px; max-height: 180px; overflow: auto; }
  .skipped li { font-family: var(--font-mono, ui-monospace, monospace); font-size: 12px; line-height: 1.6; }

  .go {
    margin-top: 8px; padding: 14px; border: none; border-radius: 10px;
    background: var(--purple); color: #fff; font-size: 15px; font-weight: 600; cursor: pointer;
  }
  .go:hover:not(:disabled) { background: var(--accent-hover); }
  .go:disabled { opacity: 0.45; cursor: default; }
  .go:focus-visible { outline: 2px solid var(--purple-soft); outline-offset: 2px; }

  .cancel { margin-top: 16px; padding: 8px; border: none; background: none; color: var(--dim); font-size: 14px; cursor: pointer; }
  .cancel:hover { color: var(--sec); }
  .cancel:focus-visible { outline: 2px solid var(--purple); outline-offset: 2px; border-radius: 6px; }
</style>
