<script>
  let { tool } = $props();

  const toolLabels = {
    create_collection: 'Created collection',
    manage_collection: 'Updated collection',
    create_item: 'Created content item',
    update_item: 'Updated content item',
    delete_item: 'Deleted content item',
    write_file: 'Wrote file',
    read_file: 'Read file',
    manage_menu: 'Updated navigation',
    create_page: 'Created page',
    update_page: 'Updated page',
    delete_page: 'Deleted page',
    manage_global: 'Updated global',
    manage_media: 'Managed media',
    manage_folder: 'Updated folder',
    manage_theme: 'Updated theme',
    query_database: 'Queried database',
  };

  const runningLabels = {
    create_collection: 'Creating collection',
    manage_collection: 'Updating collection',
    create_item: 'Creating content item',
    update_item: 'Updating content item',
    delete_item: 'Deleting content item',
    write_file: 'Writing file',
    read_file: 'Reading file',
    manage_menu: 'Updating navigation',
    create_page: 'Creating page',
    update_page: 'Updating page',
    delete_page: 'Deleting page',
    manage_global: 'Updating global',
    manage_media: 'Managing media',
    manage_folder: 'Updating folder',
    manage_theme: 'Updating theme',
    query_database: 'Querying database',
  };

  let label = $derived(
    tool.status === 'running'
      ? (runningLabels[tool.name] || tool.name)
      : (toolLabels[tool.name] || tool.name)
  );

  let summary = $derived.by(() => {
    // Show error message for error state
    if (tool.status === 'error' && tool.result && typeof tool.result === 'object' && tool.result.error) {
      return tool.result.error;
    }
    if (tool.status === 'error' && tool.result && typeof tool.result === 'string') {
      return tool.result.length > 150 ? tool.result.slice(0, 150) + '...' : tool.result;
    }
    if (tool.result && typeof tool.result === 'object' && tool.result.summary) {
      return tool.result.summary;
    }
    if (tool.result && typeof tool.result === 'string') {
      return tool.result.length > 120 ? tool.result.slice(0, 120) + '...' : tool.result;
    }
    if (tool.input && typeof tool.input === 'object') {
      const name = tool.input.name || tool.input.title || tool.input.slug || tool.input.path;
      if (name) return `"${name}"`;
    }
    return '';
  });
</script>

<div class="tool-card" class:running={tool.status === 'running'} class:error={tool.status === 'error'} role="status">
  <div class="tool-icon">
    {#if tool.status === 'running'}
      <span class="tool-spinner"></span>
    {:else if tool.status === 'error'}
      <svg viewBox="0 0 16 16" fill="none" stroke="#EB5757" stroke-width="1.5">
        <line x1="4" y1="4" x2="12" y2="12"/><line x1="12" y1="4" x2="4" y2="12"/>
      </svg>
    {:else}
      <svg viewBox="0 0 16 16" fill="none" stroke="#6FCF97" stroke-width="1.5">
        <polyline points="3 8.5 6.5 12 13 4"/>
      </svg>
    {/if}
  </div>
  <div class="tool-body">
    <span class="tool-label">{label}</span>
    {#if summary}
      <span class="tool-summary" class:error-text={tool.status === 'error'}>{summary}</span>
    {/if}
  </div>
</div>

<style>
  .tool-card {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 7px 10px;
    border-radius: 6px;
    border: 1px solid rgba(255, 255, 255, 0.06);
    background: rgba(255, 255, 255, 0.03);
    font-size: 12px;
    line-height: 1.4;
    color: rgba(255, 255, 255, 0.8);
    transition: border-color 0.2s, background 0.2s;
  }

  .tool-card.running {
    border-color: rgba(111, 207, 151, 0.2);
    background: rgba(111, 207, 151, 0.05);
    animation: tool-pulse 2s ease-in-out infinite;
  }

  .tool-card.error {
    border-color: rgba(235, 87, 87, 0.25);
    background: rgba(235, 87, 87, 0.06);
  }

  @keyframes tool-pulse {
    0%, 100% { background: rgba(111, 207, 151, 0.05); }
    50% { background: rgba(111, 207, 151, 0.1); }
  }

  .tool-icon {
    flex-shrink: 0;
    width: 16px;
    height: 16px;
    display: flex;
    align-items: center;
    justify-content: center;
  }

  .tool-icon svg {
    width: 13px;
    height: 13px;
  }

  .tool-spinner {
    display: block;
    width: 12px;
    height: 12px;
    border: 1.5px solid rgba(255, 255, 255, 0.12);
    border-top-color: #6FCF97;
    border-radius: 50%;
    animation: tool-spin 0.6s linear infinite;
  }

  @keyframes tool-spin {
    to { transform: rotate(360deg); }
  }

  .tool-body {
    flex: 1;
    min-width: 0;
    display: flex;
    flex-wrap: wrap;
    gap: 0 6px;
    align-items: baseline;
  }

  .tool-label {
    font-weight: 500;
    color: rgba(255, 255, 255, 0.85);
  }

  .tool-summary {
    color: rgba(255, 255, 255, 0.45);
    word-break: break-word;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    max-width: 100%;
  }

  .tool-summary.error-text {
    color: #EB5757;
    white-space: normal;
  }
</style>
