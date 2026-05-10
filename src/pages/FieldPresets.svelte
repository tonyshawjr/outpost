<script>
  import { onMount } from 'svelte';
  import { fieldPresets } from '$lib/api.js';
  import { addToast } from '$lib/stores.js';
  import { Plus, Trash2, Lock } from 'lucide-svelte';

  let presets = $state([]);
  let loading = $state(true);
  let editing = $state(null);
  let creating = $state(false);

  async function load() {
    loading = true;
    try {
      const r = await fieldPresets.list();
      presets = r.presets || [];
    } catch (e) {
      addToast(e.message || 'Failed to load presets', 'error');
    } finally {
      loading = false;
    }
  }

  onMount(load);

  function startCreate() {
    editing = {
      slug: '',
      name: '',
      description: '',
      fields: [],
      built_in: false,
    };
    creating = true;
  }

  function startEdit(p) {
    editing = JSON.parse(JSON.stringify(p));
    creating = false;
  }

  function cancelEdit() {
    editing = null;
    creating = false;
  }

  function addField() {
    editing.fields = [...editing.fields, { key: '', label: '', type: 'text' }];
  }

  function removeField(i) {
    editing.fields = editing.fields.filter((_, idx) => idx !== i);
  }

  async function save() {
    const cleaned = editing.fields.filter(f => f.key && f.key.trim() !== '');
    const payload = {
      name: editing.name.trim(),
      description: editing.description.trim(),
      fields: cleaned,
    };
    try {
      if (creating) {
        payload.slug = editing.slug.trim() || editing.name.trim();
        await fieldPresets.create(payload);
        addToast('Preset created', 'success');
      } else {
        await fieldPresets.update(editing.slug, payload);
        addToast('Preset saved', 'success');
      }
      editing = null;
      creating = false;
      await load();
    } catch (e) {
      addToast(e.message || 'Save failed', 'error');
    }
  }

  async function remove(p) {
    if (p.built_in) return;
    if (!confirm(`Delete preset "${p.name}"? This cannot be undone.`)) return;
    try {
      await fieldPresets.delete(p.slug);
      addToast('Preset deleted', 'success');
      await load();
    } catch (e) {
      addToast(e.message || 'Delete failed', 'error');
    }
  }
</script>

<div class="page">
  <header class="page-head">
    <div>
      <h1>Field Presets</h1>
      <p class="muted">Reusable field bundles. Drop a preset into any collection schema by adding <code>{`{ "type": "preset", "preset": "slug" }`}</code> to the schema's fields.</p>
    </div>
    <button class="btn-primary" onclick={startCreate}>
      <Plus size={16} /> New preset
    </button>
  </header>

  {#if loading}
    <div class="loading">Loading presets...</div>
  {:else if !presets.length}
    <div class="empty">No presets yet. Create your first one.</div>
  {:else}
    <div class="list">
      {#each presets as p (p.slug)}
        <div class="row" onclick={() => startEdit(p)} role="button" tabindex="0" onkeydown={(e) => e.key === 'Enter' && startEdit(p)}>
          <div class="row-main">
            <div class="row-title">
              {p.name}
              {#if p.built_in}<span class="pill"><Lock size={11}/> built-in</span>{/if}
            </div>
            <div class="row-meta">
              <span class="slug">{p.slug}</span>
              <span class="dot">·</span>
              <span>{p.fields.length} field{p.fields.length === 1 ? '' : 's'}</span>
              {#if p.description}
                <span class="dot">·</span>
                <span class="desc">{p.description}</span>
              {/if}
            </div>
          </div>
          {#if !p.built_in}
            <button class="row-del" onclick={(e) => { e.stopPropagation(); remove(p); }} aria-label="Delete preset">
              <Trash2 size={14}/>
            </button>
          {/if}
        </div>
      {/each}
    </div>
  {/if}
</div>

{#if editing}
  <div class="overlay" onclick={cancelEdit} role="presentation">
    <div class="sheet" onclick={(e) => e.stopPropagation()} role="dialog" aria-modal="true">
      <header class="sheet-head">
        <h2>{creating ? 'New preset' : `Edit preset — ${editing.name}`}</h2>
        <button class="x" onclick={cancelEdit} aria-label="Close">×</button>
      </header>

      <div class="sheet-body">
        <label class="lbl">Name</label>
        <input class="inp" bind:value={editing.name} placeholder="Image with credit" disabled={editing.built_in && !creating} />

        {#if creating}
          <label class="lbl">Slug</label>
          <input class="inp" bind:value={editing.slug} placeholder="image-with-credit" />
        {:else}
          <label class="lbl">Slug</label>
          <input class="inp" value={editing.slug} disabled />
        {/if}

        <label class="lbl">Description</label>
        <textarea class="inp" rows="2" bind:value={editing.description}></textarea>

        <div class="fields-head">
          <span class="lbl">Fields</span>
          <button class="btn-link" onclick={addField}>+ Add field</button>
        </div>

        {#each editing.fields as f, i}
          <div class="field-row">
            <input class="inp small" placeholder="key" bind:value={f.key} />
            <input class="inp small" placeholder="Label" bind:value={f.label} />
            <select class="inp small" bind:value={f.type}>
              <option value="text">text</option>
              <option value="textarea">textarea</option>
              <option value="richtext">richtext</option>
              <option value="richtext-blocks">richtext-blocks</option>
              <option value="image">image</option>
              <option value="link">link</option>
              <option value="select">select</option>
              <option value="toggle">toggle</option>
              <option value="number">number</option>
              <option value="date">date</option>
              <option value="color">color</option>
              <option value="object">object</option>
            </select>
            <button class="btn-iconic" onclick={() => removeField(i)} aria-label="Remove field"><Trash2 size={13}/></button>
          </div>
        {/each}

        {#if !editing.fields.length}
          <div class="hint">Add at least one field to define the preset.</div>
        {/if}
      </div>

      <footer class="sheet-foot">
        <button class="btn-secondary" onclick={cancelEdit}>Cancel</button>
        <button class="btn-primary" onclick={save} disabled={!editing.name?.trim() || !editing.fields.length}>Save</button>
      </footer>
    </div>
  </div>
{/if}

<style>
  .page { padding: 32px 40px; max-width: 920px; }
  .page-head { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 24px; gap: 24px; }
  .page-head h1 { font-family: var(--font-serif, Georgia, serif); font-size: 32px; margin: 0 0 4px; font-weight: 500; }
  .muted { color: var(--text-tertiary); font-size: 14px; line-height: 1.5; max-width: 60ch; }
  .muted code { background: var(--bg-tertiary); padding: 1px 6px; border-radius: 3px; font-size: 12px; }
  .loading, .empty { color: var(--text-tertiary); padding: 32px 0; }
  .list { display: flex; flex-direction: column; gap: 1px; background: var(--border-secondary); border-radius: 8px; overflow: hidden; }
  .row { display: flex; gap: 12px; padding: 16px 20px; background: var(--bg-primary); cursor: pointer; align-items: center; transition: background 0.1s; }
  .row:hover { background: var(--bg-secondary); }
  .row-main { flex: 1; min-width: 0; }
  .row-title { font-weight: 500; font-size: 15px; display: flex; align-items: center; gap: 8px; }
  .row-meta { color: var(--text-tertiary); font-size: 13px; margin-top: 3px; display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
  .slug { font-family: var(--font-mono, monospace); font-size: 12px; color: var(--text-tertiary); }
  .dot { opacity: 0.5; }
  .desc { color: var(--text-tertiary); }
  .pill { display: inline-flex; align-items: center; gap: 4px; padding: 2px 7px; font-size: 10px; text-transform: uppercase; letter-spacing: 0.06em; color: var(--text-tertiary); }
  .row-del { background: none; border: none; color: var(--text-tertiary); cursor: pointer; padding: 6px; border-radius: 4px; opacity: 0; transition: opacity 0.1s; }
  .row:hover .row-del { opacity: 1; }
  .row-del:hover { color: var(--accent-danger, #e05252); background: var(--bg-tertiary); }

  .overlay { position: fixed; inset: 0; background: rgba(10, 10, 10, 0.55); display: flex; align-items: center; justify-content: center; z-index: 100; padding: 24px; }
  .sheet { background: var(--bg-primary); border-radius: 10px; max-width: 640px; width: 100%; max-height: 90vh; display: flex; flex-direction: column; overflow: hidden; box-shadow: 0 20px 60px rgba(0,0,0,0.4); }
  .sheet-head { padding: 18px 24px; border-bottom: 1px solid var(--border-secondary); display: flex; justify-content: space-between; align-items: center; }
  .sheet-head h2 { font-size: 16px; margin: 0; font-weight: 500; }
  .x { background: none; border: none; font-size: 24px; line-height: 1; color: var(--text-tertiary); cursor: pointer; }
  .sheet-body { padding: 18px 24px; overflow-y: auto; flex: 1; }
  .sheet-foot { padding: 14px 24px; border-top: 1px solid var(--border-secondary); display: flex; justify-content: flex-end; gap: 8px; }
  .lbl { display: block; font-size: 11px; text-transform: uppercase; letter-spacing: 0.06em; color: var(--text-tertiary); margin: 12px 0 6px; }
  .inp { width: 100%; padding: 9px 12px; border: 1px solid transparent; border-radius: 6px; background: var(--bg-tertiary); font-size: 14px; color: var(--text-primary); outline: none; transition: border-color 0.15s; box-sizing: border-box; font-family: inherit; }
  .inp:hover { border-color: var(--border-secondary); }
  .inp:focus { border-color: var(--accent); }
  .inp:disabled { opacity: 0.5; cursor: not-allowed; }

  .fields-head { display: flex; justify-content: space-between; align-items: baseline; margin: 18px 0 8px; }
  .field-row { display: grid; grid-template-columns: 1.2fr 1.2fr 1fr 32px; gap: 6px; margin-bottom: 6px; }
  .inp.small { padding: 7px 10px; font-size: 13px; }
  .btn-iconic { background: none; border: none; color: var(--text-tertiary); cursor: pointer; padding: 0; display: flex; align-items: center; justify-content: center; border-radius: 4px; }
  .btn-iconic:hover { color: var(--accent-danger, #e05252); }
  .btn-primary { display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px; background: var(--text-primary); color: var(--bg-primary); border: none; border-radius: 6px; font-size: 13px; font-weight: 500; cursor: pointer; }
  .btn-primary:disabled { opacity: 0.4; cursor: not-allowed; }
  .btn-secondary { padding: 8px 14px; background: none; border: 1px solid var(--border-secondary); color: var(--text-primary); border-radius: 6px; font-size: 13px; cursor: pointer; }
  .btn-link { background: none; border: none; color: var(--accent); cursor: pointer; font-size: 12px; padding: 0; }
  .hint { color: var(--text-tertiary); font-size: 13px; padding: 8px 0; }
</style>
