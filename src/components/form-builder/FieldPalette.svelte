<script>
  let { onAddField } = $props();

  const fieldTypes = [
    { group: 'Standard', types: [
      { type: 'text', label: 'Text', icon: 'T' },
      { type: 'textarea', label: 'Textarea', icon: '¶' },
      { type: 'number', label: 'Number', icon: '#' },
      { type: 'email', label: 'Email', icon: '@' },
      { type: 'phone', label: 'Phone', icon: '☎' },
      { type: 'url', label: 'URL', icon: '⌘' },
    ]},
    { group: 'Choice', types: [
      { type: 'select', label: 'Dropdown', icon: '▾' },
      { type: 'radio', label: 'Radio', icon: '◉' },
      { type: 'checkbox', label: 'Checkbox', icon: '☑' },
    ]},
    { group: 'Date & Time', types: [
      { type: 'date', label: 'Date', icon: '📅' },
      { type: 'time', label: 'Time', icon: '⏰' },
    ]},
    { group: 'Layout', types: [
      { type: 'section', label: 'Section', icon: '—' },
      { type: 'html', label: 'HTML', icon: '</>' },
      { type: 'hidden', label: 'Hidden', icon: '◌' },
    ]},
  ];
</script>

<div class="palette">
  <div class="palette-title">Add Field</div>
  {#each fieldTypes as group}
    <div class="palette-group">
      <div class="palette-group-label">{group.group}</div>
      <div class="palette-items">
        {#each group.types as ft}
          <button class="palette-item" onclick={() => onAddField(ft.type, ft.label)} title={ft.label}>
            <span class="palette-item-icon">{ft.icon}</span>
            <span class="palette-item-label">{ft.label}</span>
          </button>
        {/each}
      </div>
    </div>
  {/each}
</div>

<style>
  .palette {
    padding: 16px;
    overflow-y: auto;
    height: 100%;
  }

  .palette-title {
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--text-tertiary);
    font-weight: 600;
    margin-bottom: 12px;
  }

  .palette-group {
    margin-bottom: 16px;
  }

  .palette-group-label {
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--text-tertiary);
    margin-bottom: 6px;
    font-weight: 500;
  }

  .palette-items {
    display: flex;
    flex-direction: column;
    gap: 2px;
  }

  .palette-item {
    display: flex;
    align-items: center;
    gap: 8px;
    width: 100%;
    padding: 6px 8px;
    background: none;
    border: 1px solid transparent;
    border-radius: var(--radius-md, 6px);
    cursor: pointer;
    text-align: left;
    color: var(--text-secondary);
    font-size: 13px;
    transition: all 0.1s;
  }

  .palette-item:hover {
    background: var(--bg-hover);
    color: var(--text-primary);
    border-color: var(--border-primary);
  }

  .palette-item-icon {
    width: 20px;
    text-align: center;
    font-size: 13px;
    opacity: 0.6;
    flex-shrink: 0;
  }

  .palette-item-label {
    flex: 1;
    min-width: 0;
  }
</style>
