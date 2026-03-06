<script>
  import { slugify } from '$lib/utils.js';

  let {
    name = $bindable(''),
    slug = $bindable(''),
    cacheTtl = $bindable(3600),
    maxItems = $bindable(100),
    sortField = $bindable(''),
    sortDirection = $bindable('desc'),
    urlPattern = $bindable(''),
    schema = [],
  } = $props();

  function autoSlug() {
    slug = slugify(name);
  }

  let fieldNames = $derived(schema.map((f) => f.name));

  const ttlOptions = [
    { label: '5 minutes', value: 300 },
    { label: '15 minutes', value: 900 },
    { label: '1 hour', value: 3600 },
    { label: '6 hours', value: 21600 },
    { label: 'Daily', value: 86400 },
    { label: 'Manual only', value: 0 },
  ];
</script>

<div class="config-step">
  <!-- Name + Slug -->
  <div class="name-row">
    <div class="field-group">
      <label class="field-label">Channel Name</label>
      <input
        type="text"
        class="field-input"
        placeholder="My API Feed"
        value={name}
        oninput={(e) => {
          name = e.target.value;
          autoSlug();
        }}
      />
    </div>
    <div class="field-group">
      <label class="field-label">Slug</label>
      <input
        type="text"
        class="field-input field-input-mono"
        placeholder="my-api-feed"
        value={slug}
        oninput={(e) => (slug = e.target.value)}
      />
    </div>
  </div>

  <!-- Cache TTL -->
  <div class="field-group">
    <label class="field-label">Cache Duration</label>
    <div class="ttl-group">
      {#each ttlOptions as opt}
        <button
          class="pill"
          class:pill-active={cacheTtl === opt.value}
          onclick={() => (cacheTtl = opt.value)}
        >
          {opt.label}
        </button>
      {/each}
    </div>
  </div>

  <!-- Max Items -->
  <div class="field-group">
    <label class="field-label">Max Items</label>
    <input
      type="number"
      class="field-input field-input-short"
      min="1"
      max="10000"
      value={maxItems}
      oninput={(e) => (maxItems = parseInt(e.target.value) || 100)}
    />
  </div>

  <!-- Sort -->
  <div class="sort-row">
    <div class="field-group">
      <label class="field-label">Sort Field</label>
      <select
        class="field-input"
        value={sortField}
        onchange={(e) => (sortField = e.target.value)}
      >
        <option value="">Default order</option>
        {#each fieldNames as fname}
          <option value={fname}>{fname}</option>
        {/each}
      </select>
    </div>
    <div class="field-group">
      <label class="field-label">Direction</label>
      <div class="pill-group">
        <button
          class="pill"
          class:pill-active={sortDirection === 'asc'}
          onclick={() => (sortDirection = 'asc')}
        >ASC</button>
        <button
          class="pill"
          class:pill-active={sortDirection === 'desc'}
          onclick={() => (sortDirection = 'desc')}
        >DESC</button>
      </div>
    </div>
  </div>

  <!-- URL Pattern -->
  <div class="field-group">
    <label class="field-label">URL Pattern</label>
    <input
      type="text"
      class="field-input field-input-mono"
      placeholder="/listing/{slug}"
      value={urlPattern}
      oninput={(e) => (urlPattern = e.target.value)}
    />
    <span class="field-hint">Leave empty for no single-item pages. Use {'{slug}'} or {'{id}'} as placeholders.</span>
  </div>
</div>

<style>
  .config-step {
    display: flex;
    flex-direction: column;
    gap: 24px;
  }

  .field-group {
    display: flex;
    flex-direction: column;
    gap: 6px;
  }

  .field-label {
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--text-muted);
    font-weight: 600;
  }

  .field-input {
    width: 100%;
    padding: 6px 8px;
    font-size: 13px;
    color: var(--text);
    background: transparent;
    border: 1px solid transparent;
    border-radius: var(--radius-sm);
    transition: border-color 0.15s;
  }

  .field-input:hover {
    border-color: var(--border);
  }

  .field-input:focus {
    border-color: var(--accent);
    outline: none;
  }

  .field-input::placeholder {
    color: var(--text-muted);
  }

  .field-input-mono {
    font-family: var(--font-mono);
    font-size: 12px;
  }

  .field-input-short {
    max-width: 120px;
  }

  .name-row,
  .sort-row {
    display: flex;
    gap: 16px;
  }

  .name-row .field-group,
  .sort-row .field-group {
    flex: 1;
  }

  .ttl-group {
    display: flex;
    flex-wrap: wrap;
    gap: 4px;
  }

  .pill-group {
    display: flex;
    gap: 4px;
  }

  .pill {
    padding: 5px 12px;
    font-size: 12px;
    font-weight: 500;
    color: var(--text-secondary);
    background: transparent;
    border: 1px solid transparent;
    border-radius: var(--radius-sm);
    cursor: pointer;
    transition: all 0.15s;
  }

  .pill:hover {
    background: var(--bg-secondary);
  }

  .pill-active {
    color: var(--text);
    background: var(--bg-secondary);
    border-color: var(--border);
  }

  .field-hint {
    font-size: 11px;
    color: var(--text-muted);
    line-height: 1.4;
  }

  select.field-input {
    appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg width='10' height='6' viewBox='0 0 10 6' fill='none' xmlns='http://www.w3.org/2000/svg'%3E%3Cpath d='M1 1L5 5L9 1' stroke='%239ca3af' stroke-width='1.5' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 8px center;
    padding-right: 28px;
  }
</style>
