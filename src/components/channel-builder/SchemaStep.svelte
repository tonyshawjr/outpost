<script>
  import JsonTree from './JsonTree.svelte';
  import { channels } from '$lib/api.js';
  import { onMount } from 'svelte';

  let {
    config = $bindable({}),
    schema = $bindable([]),
    sample = $bindable([]),
    fieldMap = $bindable([]),
    channelType = 'api',
  } = $props();

  let loading = $state(false);
  let error = $state(null);
  let selectedFields = $state([]);

  async function discoverSchema() {
    loading = true;
    error = null;
    try {
      const res = await channels.discover({
        type: channelType,
        url: config.url,
        method: config.method,
        auth_type: config.auth_type,
        auth_config: config.auth_config,
        headers: config.headers,
        params: config.params,
        data_path: config.data_path || '',
        csv_delimiter: config.csv_delimiter || ',',
        csv_has_headers: config.csv_has_headers !== false,
        csv_encoding: config.csv_encoding || 'UTF-8',
      });
      schema = res.schema || [];
      sample = res.sample || [];
      if (res.suggested_path && !config.data_path) {
        config.data_path = res.suggested_path;
      }
      // Set smart defaults for RSS
      if (channelType === 'rss') {
        if (!config.id_field || config.id_field === 'id') config.id_field = 'guid';
        if (!config.slug_field || config.slug_field === 'slug') config.slug_field = 'title';
      }
    } catch (err) {
      error = err.message;
    } finally {
      loading = false;
    }
  }

  // Auto-discover on mount if we have a URL
  onMount(() => {
    if (config.url && schema.length === 0) discoverSchema();
  });

  // Sync selected fields to fieldMap
  $effect(() => {
    fieldMap = selectedFields.map((name) => ({ source: name, target: name }));
  });

  // Flat list of field names for selectors
  let fieldNames = $derived(
    schema.map((f) => f.name)
  );

  function formatSample(item) {
    try {
      return JSON.stringify(item, null, 2);
    } catch {
      return String(item);
    }
  }
</script>

<div class="schema-step">
  <!-- Data Path (API only) -->
  {#if channelType === 'api'}
    <div class="field-group">
      <label class="field-label">Data Path</label>
      <div class="field-row">
        <input
          type="text"
          class="field-input field-input-mono"
          placeholder="e.g. data.listings"
          value={config.data_path || ''}
          oninput={(e) => (config.data_path = e.target.value)}
        />
        <button
          class="btn btn-secondary btn-sm"
          onclick={discoverSchema}
          disabled={loading}
        >
          {loading ? 'Loading...' : 'Refresh'}
        </button>
      </div>
      <span class="field-hint">Where is the array in the response? Leave empty if the response is the array.</span>
    </div>
  {:else}
    <div class="field-group">
      <div class="field-row">
        <div></div>
        <button
          class="btn btn-secondary btn-sm"
          onclick={discoverSchema}
          disabled={loading}
        >
          {loading ? 'Loading...' : 'Re-discover'}
        </button>
      </div>
    </div>
  {/if}

  {#if error}
    <div class="schema-error">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/>
      </svg>
      {error}
    </div>
  {/if}

  {#if loading}
    <div class="schema-loading">Discovering schema...</div>
  {/if}

  <!-- Schema Tree -->
  {#if schema.length > 0}
    <div class="field-group">
      <label class="field-label">Fields</label>
      <p class="field-hint">Select the fields you want to import. Uncheck any you don't need.</p>
      <div class="tree-container">
        <JsonTree fields={schema} bind:selected={selectedFields} selectable={true} />
      </div>
    </div>

    <!-- ID / Slug selectors -->
    <div class="selector-row">
      <div class="field-group">
        <label class="field-label">ID Field</label>
        <select
          class="field-input"
          value={config.id_field || 'id'}
          onchange={(e) => (config.id_field = e.target.value)}
        >
          {#each fieldNames as name}
            <option value={name}>{name}</option>
          {/each}
        </select>
      </div>

      <div class="field-group">
        <label class="field-label">Slug Field</label>
        <select
          class="field-input"
          value={config.slug_field || 'slug'}
          onchange={(e) => (config.slug_field = e.target.value)}
        >
          <option value="">None</option>
          {#each fieldNames as name}
            <option value={name}>{name}</option>
          {/each}
        </select>
      </div>
    </div>
  {/if}

  <!-- Sample Preview -->
  {#if sample.length > 0}
    <div class="field-group">
      <label class="field-label">Sample Data</label>
      <div class="sample-list">
        {#each sample.slice(0, 3) as item, i (i)}
          <pre class="sample-block">{formatSample(item)}</pre>
        {/each}
      </div>
    </div>
  {/if}
</div>

<style>
  .schema-step {
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

  .field-input-mono {
    font-family: var(--font-mono);
    font-size: 12px;
  }

  .field-row {
    display: flex;
    gap: 8px;
    align-items: center;
  }

  .field-row .field-input {
    flex: 1;
  }

  .field-hint {
    font-size: 11px;
    color: var(--text-muted);
    line-height: 1.4;
  }

  .selector-row {
    display: flex;
    gap: 16px;
  }

  .selector-row .field-group {
    flex: 1;
  }

  .tree-container {
    max-height: 360px;
    overflow-y: auto;
    padding: 4px 0;
  }

  .schema-error {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 13px;
    color: var(--danger);
    font-weight: 500;
  }

  .schema-loading {
    font-size: 13px;
    color: var(--text-muted);
  }

  .sample-list {
    display: flex;
    flex-direction: column;
    gap: 8px;
  }

  .sample-block {
    padding: 10px 12px;
    font-family: var(--font-mono);
    font-size: 11px;
    line-height: 1.5;
    color: var(--text-secondary);
    background: var(--bg-secondary);
    border-radius: var(--radius-sm);
    overflow-x: auto;
    white-space: pre-wrap;
    word-break: break-word;
    margin: 0;
    max-height: 160px;
    overflow-y: auto;
  }

  select.field-input {
    appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg width='10' height='6' viewBox='0 0 10 6' fill='none' xmlns='http://www.w3.org/2000/svg'%3E%3Cpath d='M1 1L5 5L9 1' stroke='%239ca3af' stroke-width='1.5' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 8px center;
    padding-right: 28px;
  }
</style>
