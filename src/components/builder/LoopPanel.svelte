<script>
  let { editor, selected, collections = [] } = $props();

  let collection = $derived(selected.props.collection || '');
  let limit = $derived(selected.props.limit ?? 6);
  let sortKey = $derived(`${selected.props.sort || 'created_at'}:${(selected.props.order || 'DESC').toUpperCase()}`);

  const sortOptions = [
    { value: 'created_at:DESC', label: 'Newest first' },
    { value: 'created_at:ASC', label: 'Oldest first' },
    { value: 'published_at:DESC', label: 'Recently published' },
    { value: 'slug:ASC', label: 'Slug A–Z' },
    { value: 'sort_order:ASC', label: 'Manual order' },
  ];

  function setCollection(e) {
    editor.updateProps(selected.id, { collection: e.target.value });
  }
  function setLimit(e) {
    const n = Math.max(0, Math.min(100, parseInt(e.target.value, 10) || 0));
    editor.updateProps(selected.id, { limit: n });
  }
  function setSort(e) {
    const [sort, order] = e.target.value.split(':');
    editor.updateProps(selected.id, { sort, order });
  }
</script>

<div class="loop">
  <label class="field">
    <span>Collection</span>
    <select value={collection} onchange={setCollection}>
      <option value="">Choose a collection…</option>
      {#each collections as c (c.slug)}
        <option value={c.slug}>{c.name}</option>
      {/each}
    </select>
  </label>

  {#if collection}
    <label class="field">
      <span>Items to show</span>
      <input type="number" min="0" max="100" value={limit} oninput={setLimit} />
    </label>
    <label class="field">
      <span>Order</span>
      <select value={sortKey} onchange={setSort}>
        {#each sortOptions as o (o.value)}
          <option value={o.value}>{o.label}</option>
        {/each}
      </select>
    </label>
    <p class="loop-hint">Design the template once — it repeats for every item. Bind text and images to collection fields with <strong>Dynamic content</strong>.</p>
  {:else}
    <p class="loop-hint">Pick a collection, then add the elements that make up one item. They'll repeat for every entry.</p>
  {/if}
</div>

<style>
  .loop { display: flex; flex-direction: column; gap: 12px; }
  .field { display: flex; flex-direction: column; gap: 5px; }
  .field span { font-size: 11px; text-transform: uppercase; letter-spacing: 0.05em; color: var(--dim); }
  .field select, .field input {
    width: 100%; padding: 8px 10px;
    border: 1px solid var(--border); border-radius: 8px;
    background: var(--raised); color: var(--text); font-size: 13px;
  }
  .field select:focus-visible, .field input:focus-visible { outline: 2px solid var(--purple); outline-offset: 1px; border-color: var(--purple); }
  .loop-hint { font-size: 12px; color: var(--dim); line-height: 1.5; margin: 0; }
  .loop-hint strong { color: var(--sec); font-weight: 600; }
</style>
