<script>
  let { field = null, onChange } = $props();

  function update(key, value) {
    if (!field) return;
    onChange({ ...field, [key]: value });
  }

  function updateSettings(key, value) {
    if (!field) return;
    const settings = { ...(field.settings || {}), [key]: value };
    onChange({ ...field, settings });
  }

  function updateChoices(text) {
    const lines = text.split('\n').filter(l => l.trim());
    const choices = lines.map(l => {
      const parts = l.split('|').map(s => s.trim());
      return parts.length > 1 ? { label: parts[0], value: parts[1] } : { label: parts[0], value: parts[0] };
    });
    onChange({ ...field, choices });
  }

  function choicesText() {
    if (!field?.choices?.length) return '';
    return field.choices.map(c => {
      if (typeof c === 'string') return c;
      return c.label === c.value ? c.label : `${c.label}|${c.value}`;
    }).join('\n');
  }

  let hasChoices = $derived(field && ['select', 'radio', 'checkbox'].includes(field.type));
  let isInputType = $derived(field && !['hidden', 'html', 'section'].includes(field.type));
  let isNumberType = $derived(field?.type === 'number');
  let isTextareaType = $derived(field?.type === 'textarea');
  let isHtmlType = $derived(field?.type === 'html');
</script>

<div class="settings-panel">
  {#if !field}
    <div class="settings-empty">
      <p>Select a field to edit its settings</p>
    </div>
  {:else}
    <div class="settings-title">Field Settings</div>

    <div class="settings-section">
      <div class="settings-field">
        <label class="settings-label">Label</label>
        <input type="text" class="settings-input" value={field.label} oninput={(e) => update('label', e.target.value)} />
      </div>

      <div class="settings-field">
        <label class="settings-label">Name (machine)</label>
        <input type="text" class="settings-input mono" value={field.name} oninput={(e) => update('name', e.target.value)} />
      </div>

      <div class="settings-field">
        <label class="settings-label">Type</label>
        <select class="settings-input" value={field.type} onchange={(e) => update('type', e.target.value)}>
          <option value="text">Text</option>
          <option value="textarea">Textarea</option>
          <option value="number">Number</option>
          <option value="email">Email</option>
          <option value="phone">Phone</option>
          <option value="url">URL</option>
          <option value="select">Dropdown</option>
          <option value="radio">Radio</option>
          <option value="checkbox">Checkbox</option>
          <option value="date">Date</option>
          <option value="time">Time</option>
          <option value="section">Section</option>
          <option value="html">HTML</option>
          <option value="hidden">Hidden</option>
        </select>
      </div>
    </div>

    {#if isInputType}
      <div class="settings-section">
        <div class="settings-field">
          <label class="settings-checkbox-label">
            <input type="checkbox" checked={field.required} onchange={(e) => update('required', e.target.checked)} />
            Required
          </label>
        </div>

        <div class="settings-field">
          <label class="settings-label">Placeholder</label>
          <input type="text" class="settings-input" value={field.placeholder || ''} oninput={(e) => update('placeholder', e.target.value)} />
        </div>

        <div class="settings-field">
          <label class="settings-label">Description</label>
          <input type="text" class="settings-input" value={field.description || ''} oninput={(e) => update('description', e.target.value)} />
        </div>

        <div class="settings-field">
          <label class="settings-label">Default Value</label>
          <input type="text" class="settings-input" value={field.default_value || ''} oninput={(e) => update('default_value', e.target.value)} />
        </div>

        <div class="settings-field">
          <label class="settings-label">CSS Classes</label>
          <input type="text" class="settings-input" value={field.css_classes || ''} oninput={(e) => update('css_classes', e.target.value)} />
        </div>
      </div>
    {/if}

    {#if hasChoices}
      <div class="settings-section">
        <div class="settings-field">
          <label class="settings-label">Choices</label>
          <textarea class="settings-input settings-textarea" value={choicesText()} oninput={(e) => updateChoices(e.target.value)} placeholder="One per line. Use Label|value for different display/stored values."></textarea>
          <p class="settings-hint">One choice per line. Use "Label|value" for different display/stored values.</p>
        </div>
      </div>
    {/if}

    {#if isNumberType}
      <div class="settings-section">
        <div class="settings-field">
          <label class="settings-label">Min</label>
          <input type="number" class="settings-input" value={field.settings?.min ?? ''} oninput={(e) => updateSettings('min', e.target.value)} />
        </div>
        <div class="settings-field">
          <label class="settings-label">Max</label>
          <input type="number" class="settings-input" value={field.settings?.max ?? ''} oninput={(e) => updateSettings('max', e.target.value)} />
        </div>
        <div class="settings-field">
          <label class="settings-label">Step</label>
          <input type="text" class="settings-input" value={field.settings?.step ?? ''} oninput={(e) => updateSettings('step', e.target.value)} placeholder="1" />
        </div>
      </div>
    {/if}

    {#if isTextareaType}
      <div class="settings-section">
        <div class="settings-field">
          <label class="settings-label">Rows</label>
          <input type="number" class="settings-input" value={field.settings?.rows ?? 5} oninput={(e) => updateSettings('rows', parseInt(e.target.value) || 5)} />
        </div>
      </div>
    {/if}

    {#if isHtmlType}
      <div class="settings-section">
        <div class="settings-field">
          <label class="settings-label">HTML Content</label>
          <textarea class="settings-input settings-textarea tall" value={field.settings?.content ?? ''} oninput={(e) => updateSettings('content', e.target.value)} placeholder="<p>Custom HTML content...</p>"></textarea>
        </div>
      </div>
    {/if}
  {/if}
</div>

<style>
  .settings-panel {
    padding: 16px;
    overflow-y: auto;
    height: 100%;
  }

  .settings-empty {
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 200px;
    color: var(--text-tertiary);
    font-size: 14px;
    text-align: center;
  }

  .settings-title {
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--text-tertiary);
    font-weight: 600;
    margin-bottom: 16px;
  }

  .settings-section {
    margin-bottom: 16px;
    padding-bottom: 16px;
    border-bottom: 1px solid var(--border-primary);
  }

  .settings-section:last-child {
    border-bottom: none;
  }

  .settings-field {
    margin-bottom: 12px;
  }

  .settings-label {
    display: block;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--text-tertiary);
    font-weight: 500;
    margin-bottom: 4px;
  }

  .settings-input {
    width: 100%;
    padding: 6px 8px;
    font-size: 13px;
    border: 1px solid transparent;
    border-radius: var(--radius-md, 6px);
    background: var(--bg-tertiary);
    color: var(--text-primary);
    transition: border-color 0.15s;
  }

  .settings-input:hover {
    border-color: var(--border-primary);
  }

  .settings-input:focus {
    border-color: var(--accent);
    outline: none;
  }

  .settings-input.mono {
    font-family: var(--font-mono, monospace);
    font-size: 12px;
  }

  .settings-textarea {
    min-height: 80px;
    resize: vertical;
    font-family: var(--font-mono, monospace);
    font-size: 12px;
    line-height: 1.5;
  }

  .settings-textarea.tall {
    min-height: 120px;
  }

  .settings-checkbox-label {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 13px;
    color: var(--text-primary);
    cursor: pointer;
  }

  .settings-hint {
    font-size: 11px;
    color: var(--text-tertiary);
    margin-top: 4px;
    line-height: 1.4;
  }
</style>
