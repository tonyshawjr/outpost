<script>
  let {
    sample = [],
    slug = '',
    schema = [],
    urlPattern = '',
    fieldMap = [],
  } = $props();

  let copied = $state(false);
  let copiedSingle = $state(false);

  // Get visible field names
  let fieldNames = $derived(
    fieldMap.length > 0
      ? fieldMap.map((f) => f.target)
      : schema.map((f) => f.name)
  );

  // Limit table columns
  let visibleFields = $derived(fieldNames.slice(0, 8));

  function getLoopTemplate() {
    const lines = [`<outpost-each channel="${slug || 'my_channel'}">`];
    visibleFields.forEach((f) => {
      lines.push(`  <span data-outpost="${f}" />`);
    });
    lines.push('</outpost-each>');
    return lines.join('\n');
  }

  function getSingleTemplate() {
    const lines = [`<outpost-single channel="${slug || 'my_channel'}">`];
    visibleFields.forEach((f) => {
      lines.push(`  <span data-outpost="${f}" />`);
    });
    lines.push('</outpost-single>');
    return lines.join('\n');
  }

  function copyTemplate() {
    navigator.clipboard.writeText(getLoopTemplate());
    copied = true;
    setTimeout(() => (copied = false), 2000);
  }

  function copySingleTemplate() {
    navigator.clipboard.writeText(getSingleTemplate());
    copiedSingle = true;
    setTimeout(() => (copiedSingle = false), 2000);
  }

  function cellValue(item, field) {
    const val = item?.[field];
    if (val === null || val === undefined) return '';
    if (typeof val === 'object') return JSON.stringify(val);
    const str = String(val);
    return str.length > 50 ? str.slice(0, 50) + '...' : str;
  }
</script>

<div class="preview-step">
  <!-- Item count -->
  <div class="preview-meta">
    <span class="meta-count">{sample.length} items</span>
    <span class="meta-note">will be cached from this channel</span>
  </div>

  <!-- Preview Table -->
  {#if sample.length > 0 && visibleFields.length > 0}
    <div class="field-group">
      <label class="field-label">Preview</label>
      <div class="table-wrap">
        <table class="preview-table">
          <thead>
            <tr>
              {#each visibleFields as f}
                <th>{f}</th>
              {/each}
            </tr>
          </thead>
          <tbody>
            {#each sample.slice(0, 5) as item, i (i)}
              <tr>
                {#each visibleFields as f}
                  <td>{cellValue(item, f)}</td>
                {/each}
              </tr>
            {/each}
          </tbody>
        </table>
      </div>
    </div>
  {/if}

  <!-- Loop Template -->
  <div class="field-group">
    <div class="code-header">
      <label class="field-label">Loop Template</label>
      <button class="copy-btn" onclick={copyTemplate}>
        {copied ? 'Copied' : 'Copy'}
      </button>
    </div>
    <pre class="code-block">{getLoopTemplate()}</pre>
  </div>

  <!-- Single Template (if URL pattern set) -->
  {#if urlPattern}
    <div class="field-group">
      <div class="code-header">
        <label class="field-label">Single Item Template</label>
        <button class="copy-btn" onclick={copySingleTemplate}>
          {copiedSingle ? 'Copied' : 'Copy'}
        </button>
      </div>
      <pre class="code-block">{getSingleTemplate()}</pre>
    </div>
  {/if}
</div>

<style>
  .preview-step {
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

  .preview-meta {
    display: flex;
    align-items: baseline;
    gap: 6px;
  }

  .meta-count {
    font-size: 20px;
    font-weight: 600;
    color: var(--text);
  }

  .meta-note {
    font-size: 13px;
    color: var(--text-muted);
  }

  .table-wrap {
    overflow-x: auto;
    border-radius: var(--radius-sm);
  }

  .preview-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 12px;
  }

  .preview-table th {
    text-align: left;
    padding: 6px 10px;
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--text-muted);
    font-weight: 600;
    border-bottom: 1px solid var(--border);
    white-space: nowrap;
  }

  .preview-table td {
    padding: 6px 10px;
    color: var(--text-secondary);
    font-family: var(--font-mono);
    font-size: 11px;
    border-bottom: 1px solid var(--border);
    white-space: nowrap;
    max-width: 200px;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  .preview-table tbody tr:last-child td {
    border-bottom: none;
  }

  .preview-table tbody tr:hover {
    background: var(--bg-secondary);
  }

  .code-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
  }

  .copy-btn {
    padding: 3px 10px;
    font-size: 11px;
    font-weight: 500;
    color: var(--text-muted);
    background: transparent;
    border: 1px solid transparent;
    border-radius: var(--radius-sm);
    cursor: pointer;
    transition: all 0.15s;
  }

  .copy-btn:hover {
    color: var(--text);
    border-color: var(--border);
  }

  .code-block {
    padding: 12px 14px;
    font-family: var(--font-mono);
    font-size: 12px;
    line-height: 1.6;
    color: var(--text-secondary);
    background: var(--bg-secondary);
    border-radius: var(--radius-sm);
    overflow-x: auto;
    white-space: pre;
    margin: 0;
  }
</style>
