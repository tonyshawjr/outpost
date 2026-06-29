<script>
  import { pages as pagesApi } from '$lib/api.js';
  import { navigate, currentPageId, addToast } from '$lib/stores.js';

  let title = $state('');
  let slug = $state('');
  let slugTouched = $state(false);
  let creating = $state(false);
  let titleEl = $state(null);

  function slugify(s) {
    return s.toLowerCase().trim().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
  }

  let autoSlug = $derived(slugTouched ? slug : slugify(title));

  $effect(() => {
    titleEl?.focus();
  });

  async function submit(e) {
    e?.preventDefault();
    const t = title.trim();
    if (!t || creating) return;
    creating = true;
    try {
      const res = await pagesApi.create(t, autoSlug || slugify(t));
      addToast('Page created', 'success');
      currentPageId.set(res.page.id);
      navigate('node-builder', { pageId: res.page.id });
    } catch (err) {
      addToast(err.message || 'Could not create page', 'error');
      creating = false;
    }
  }
</script>

<div class="wrap">
  <form class="card" onsubmit={submit}>
    <h1>Create a new page</h1>
    <p class="sub">Give your page a name to get started.</p>

    <label class="field">
      <span>Page title</span>
      <input
        bind:this={titleEl}
        type="text"
        bind:value={title}
        placeholder="e.g. About Us, Financial Aid, Fall Open House"
        autocomplete="off"
      />
    </label>

    <label class="field">
      <span>URL slug</span>
      <input
        type="text"
        value={autoSlug}
        oninput={(e) => { slugTouched = true; slug = e.target.value; }}
        placeholder="auto-generated-from-title"
        autocomplete="off"
      />
    </label>

    <button type="submit" class="continue" disabled={!title.trim() || creating}>
      {creating ? 'Creating…' : 'Continue'}
    </button>

    <button type="button" class="cancel" onclick={() => navigate('pages')}>
      Cancel and go back to Pages
    </button>
  </form>
</div>

<style>
  .wrap {
    min-height: 100%;
    display: flex;
    align-items: flex-start;
    justify-content: center;
    padding: 12vh 24px 80px;
  }
  .card {
    width: 100%;
    max-width: 560px;
    display: flex;
    flex-direction: column;
  }
  h1 {
    font-size: 34px;
    font-weight: 800;
    letter-spacing: -0.02em;
    color: var(--text);
    text-align: center;
    margin: 0;
  }
  .sub {
    font-size: 16px;
    color: var(--dim);
    text-align: center;
    margin: 10px 0 40px;
  }
  .field {
    display: flex;
    flex-direction: column;
    gap: 8px;
    margin-bottom: 22px;
  }
  .field span {
    font-size: 14px;
    font-weight: 600;
    color: var(--sec);
  }
  .field input {
    width: 100%;
    padding: 14px 16px;
    border: 1px solid var(--border);
    border-radius: 11px;
    background: var(--raised);
    color: var(--text);
    font-size: 15px;
  }
  .field input::placeholder { color: var(--dim); }
  .field input:hover { border-color: var(--overlay-20, var(--border)); }
  .field input:focus-visible { outline: none; border-color: var(--purple); }

  .continue {
    margin-top: 10px;
    padding: 15px;
    border: none;
    border-radius: 11px;
    background: var(--purple);
    color: #fff;
    font-size: 15px;
    font-weight: 600;
    cursor: pointer;
  }
  .continue:hover:not(:disabled) { background: var(--accent-hover); }
  .continue:disabled { opacity: 0.45; cursor: default; }
  .continue:focus-visible { outline: 2px solid var(--purple-soft); outline-offset: 2px; }

  .cancel {
    margin-top: 18px;
    padding: 8px;
    border: none;
    background: none;
    color: var(--dim);
    font-size: 14px;
    cursor: pointer;
  }
  .cancel:hover { color: var(--sec); }
  .cancel:focus-visible { outline: 2px solid var(--purple); outline-offset: 2px; border-radius: 6px; }
</style>
