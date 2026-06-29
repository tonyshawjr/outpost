<script>
  import { pages as pagesApi } from '$lib/api.js';
  import { addToast } from '$lib/stores.js';
  import TokensPanel from './TokensPanel.svelte';
  import { Link as LinkIcon, Save, ExternalLink } from 'lucide-svelte';

  let { editor, page, onupdated } = $props();

  let isHome = $derived(page?.path === '/');

  let title = $state('');
  let metaTitle = $state('');
  let metaDescription = $state('');
  let status = $state('published');
  let visibility = $state('public');
  let slug = $state('');
  let version = $state('');

  let saving = $state(false);
  let renaming = $state(false);

  $effect(() => {
    const p = page;
    if (!p) return;
    title = p.title || '';
    metaTitle = p.meta_title || '';
    metaDescription = p.meta_description || '';
    status = p.status || 'published';
    visibility = p.visibility || 'public';
    slug = isHome ? '' : (p.path || '').replace(/^\//, '');
    version = p.updated_at || '';
  });

  let siteUrl = $derived.by(() => {
    const base = window.location.pathname.split('/outpost')[0] || '';
    return window.location.origin + base + (page?.path || '');
  });

  async function save() {
    if (saving) return;
    saving = true;
    try {
      const res = await pagesApi.update(page.id, {
        title,
        meta_title: metaTitle,
        meta_description: metaDescription,
        status,
        visibility,
        _version: version,
      });
      version = res.updated_at || version;
      onupdated?.({ ...page, title, meta_title: metaTitle, meta_description: metaDescription, status, visibility, updated_at: version });
      addToast('Page settings saved', 'success');
    } catch (e) {
      addToast(e.message || 'Save failed', 'error');
    } finally {
      saving = false;
    }
  }

  async function rename() {
    if (renaming) return;
    const next = slug.trim();
    if (!next || `/${next}` === page.path) return;
    renaming = true;
    try {
      const res = await pagesApi.rename(page.id, next);
      onupdated?.({ ...page, path: res.path });
      slug = res.path.replace(/^\//, '');
      addToast(`Route changed to ${res.path}`, 'success');
    } catch (e) {
      addToast(e.message || 'Could not change route', 'error');
      slug = (page.path || '').replace(/^\//, '');
    } finally {
      renaming = false;
    }
  }
</script>

<div class="page-settings">
  <div class="ps-inner">
    {#if !page}
      <p class="ps-empty">No page loaded.</p>
    {:else}
      <section class="card" aria-labelledby="ps-route">
        <h2 id="ps-route" class="card-title"><LinkIcon size={15} aria-hidden="true" /> Route</h2>
        {#if isHome}
          <p class="ps-note">This is the homepage. Its route is fixed at <code>/</code>.</p>
        {:else}
          <label class="field">
            <span>URL path</span>
            <div class="slug-row">
              <span class="slug-prefix">/</span>
              <input type="text" bind:value={slug} spellcheck="false" autocapitalize="off" placeholder="about" aria-label="URL path slug" />
              <button class="btn-secondary" onclick={rename} disabled={renaming || !slug.trim() || `/${slug.trim()}` === page.path}>
                {renaming ? 'Updating…' : 'Update route'}
              </button>
            </div>
          </label>
          <p class="ps-note">Changing the route renames the published file. Existing links to the old URL will break.</p>
        {/if}
        <a class="ps-view" href={siteUrl} target="_blank" rel="noopener noreferrer">
          <ExternalLink size={13} aria-hidden="true" /> <span>{page.path}</span>
        </a>
      </section>

      <section class="card" aria-labelledby="ps-general">
        <h2 id="ps-general" class="card-title">General</h2>
        <label class="field">
          <span>Title</span>
          <input type="text" bind:value={title} />
        </label>
        <div class="grid2">
          <label class="field">
            <span>Status</span>
            <select bind:value={status}>
              <option value="published">Published</option>
              <option value="draft">Draft</option>
            </select>
          </label>
          <label class="field">
            <span>Visibility</span>
            <select bind:value={visibility}>
              <option value="public">Public</option>
              <option value="members">Members only</option>
              <option value="paid">Paid members</option>
            </select>
          </label>
        </div>
      </section>

      <section class="card" aria-labelledby="ps-seo">
        <h2 id="ps-seo" class="card-title">SEO</h2>
        <label class="field">
          <span>Meta title</span>
          <input type="text" bind:value={metaTitle} placeholder={title} />
        </label>
        <label class="field">
          <span>Meta description</span>
          <textarea rows="3" bind:value={metaDescription}></textarea>
        </label>
      </section>

      <div class="ps-actions">
        <button class="btn-primary" onclick={save} disabled={saving}>
          <Save size={15} aria-hidden="true" />
          <span>{saving ? 'Saving…' : 'Save settings'}</span>
        </button>
      </div>

      <section class="card" aria-labelledby="ps-tokens">
        <h2 id="ps-tokens" class="card-title">Design tokens</h2>
        <p class="ps-note">Colors and scales for this site. Saved with the page when you press Save in the builder.</p>
        <div class="tokens-host">
          <TokensPanel {editor} />
        </div>
      </section>
    {/if}
  </div>
</div>

<style>
  .page-settings {
    flex: 1;
    min-height: 0;
    overflow-y: auto;
    background: var(--bg);
  }
  .ps-inner {
    max-width: 640px;
    margin: 0 auto;
    padding: 28px 24px 64px;
    display: flex;
    flex-direction: column;
    gap: 18px;
  }
  .ps-empty { color: var(--dim); font-size: 14px; }

  .card {
    background: var(--raised);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 18px 18px 16px;
  }
  .card-title {
    display: flex;
    align-items: center;
    gap: 7px;
    font-size: 13px;
    font-weight: 600;
    color: var(--text);
    margin: 0 0 14px;
  }
  .card-title :global(svg) { color: var(--purple-soft, var(--purple)); }

  .field { display: flex; flex-direction: column; gap: 6px; margin-bottom: 12px; }
  .field:last-child { margin-bottom: 0; }
  .field > span {
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: var(--dim);
  }
  .field input,
  .field select,
  .field textarea {
    width: 100%;
    padding: 9px 11px;
    border: 1px solid var(--border);
    border-radius: 8px;
    background: var(--bg);
    color: var(--text);
    font-size: 13px;
    font-family: inherit;
    resize: vertical;
  }
  .field input:focus-visible,
  .field select:focus-visible,
  .field textarea:focus-visible { outline: none; border-color: var(--purple); }

  .grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }

  .slug-row { display: flex; align-items: center; gap: 6px; }
  .slug-prefix { color: var(--dim); font-family: var(--font-mono, ui-monospace, monospace); }
  .slug-row input { flex: 1; }

  .ps-note { font-size: 12px; color: var(--dim); line-height: 1.5; margin: 8px 0 0; }
  .ps-note code { font-family: var(--font-mono, ui-monospace, monospace); background: var(--hover); padding: 1px 5px; border-radius: 4px; }

  .ps-view {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    margin-top: 12px;
    font-size: 12px;
    color: var(--purple-soft, var(--purple));
    text-decoration: none;
    font-family: var(--font-mono, ui-monospace, monospace);
  }
  .ps-view:hover { text-decoration: underline; }
  .ps-view:focus-visible { outline: 2px solid var(--purple); outline-offset: 2px; border-radius: 4px; }

  .ps-actions { display: flex; justify-content: flex-end; }

  .tokens-host { margin-top: 6px; }

  .btn-primary {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    padding: 9px 16px;
    border: none;
    border-radius: 8px;
    background: var(--purple);
    color: #fff;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
  }
  .btn-primary:hover:not(:disabled) { background: var(--accent-hover, var(--purple)); }
  .btn-primary:disabled { opacity: 0.5; cursor: default; }
  .btn-primary:focus-visible { outline: 2px solid var(--purple-soft, var(--purple)); outline-offset: 2px; }

  .btn-secondary {
    padding: 8px 12px;
    border: 1px solid var(--border);
    border-radius: 8px;
    background: transparent;
    color: var(--sec);
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    flex-shrink: 0;
  }
  .btn-secondary:hover:not(:disabled) { background: var(--hover); color: var(--text); }
  .btn-secondary:disabled { opacity: 0.45; cursor: default; }
  .btn-secondary:focus-visible { outline: 2px solid var(--purple); outline-offset: 1px; }
</style>
