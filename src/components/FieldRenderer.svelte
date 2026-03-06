<script>
  import RichTextEditor from './RichTextEditor.svelte';
  import MediaPicker from './MediaPicker.svelte';
  import RepeaterField from './RepeaterField.svelte';
  import GalleryField from './GalleryField.svelte';
  import FlexibleField from './FlexibleField.svelte';
  import RelationshipField from './RelationshipField.svelte';

  let {
    field,
    onchange = () => {},
  } = $props();

  let showMediaPicker = $state(false);

  function handleInput(e) {
    onchange(field.id, e.target.value);
  }

  function handleToggle() {
    const newVal = field.content === '1' || field.content === 'true' ? '0' : '1';
    onchange(field.id, newVal);
  }

  function handleRichText(html) {
    onchange(field.id, html);
  }

  function handleImageSelect(media) {
    onchange(field.id, media.path);
    showMediaPicker = false;
  }

  function handleRepeaterChange(items) {
    onchange(field.id, JSON.stringify(items));
  }

  let options = $derived(() => {
    if (!field.options) return [];
    try {
      return JSON.parse(field.options);
    } catch {
      return [];
    }
  });

  let isToggleOn = $derived(field.content === '1' || field.content === 'true');
  let displayValue = $derived(field.content || field.default_value || '');

  let friendlyName = $derived(field.field_name.replace(/_/g, ' '));
</script>

<div class="fr-field">
  <label class="fr-label">{friendlyName}</label>

  {#if field.field_type === 'text' || field.field_type === 'meta_title'}
    <input
      class="fr-input"
      type="text"
      value={displayValue}
      oninput={handleInput}
      placeholder={field.default_value || ''}
    />

  {:else if field.field_type === 'textarea' || field.field_type === 'meta_description'}
    <textarea
      class="fr-textarea"
      rows="3"
      value={displayValue}
      oninput={handleInput}
      placeholder={field.default_value || ''}
    ></textarea>

  {:else if field.field_type === 'richtext'}
    <RichTextEditor
      content={displayValue}
      onupdate={handleRichText}
    />

  {:else if field.field_type === 'image'}
    <div class="fr-image-row">
      {#if displayValue}
        <div class="fr-image-preview">
          <img src={displayValue} alt="Preview" />
          <button class="fr-image-clear" onclick={() => onchange(field.id, '')} type="button" aria-label="Remove image">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
          </button>
        </div>
      {:else}
        <button class="fr-image-upload" onclick={() => showMediaPicker = true} type="button">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
          <span>Add image</span>
        </button>
      {/if}
    </div>

  {:else if field.field_type === 'link'}
    <input
      class="fr-input"
      type="url"
      value={displayValue}
      oninput={handleInput}
      placeholder={field.default_value || 'https://'}
    />

  {:else if field.field_type === 'select'}
    <select class="fr-select" value={displayValue} onchange={handleInput}>
      <option value="">Select...</option>
      {#each options() as opt}
        <option value={opt}>{opt}</option>
      {/each}
    </select>

  {:else if field.field_type === 'toggle'}
    <div class="fr-toggle-row">
      <button
        class="toggle"
        class:active={isToggleOn}
        onclick={handleToggle}
        type="button"
        role="switch"
        aria-checked={isToggleOn}
      ></button>
      <span class="fr-toggle-label">{isToggleOn ? 'On' : 'Off'}</span>
    </div>

  {:else if field.field_type === 'color'}
    <div class="fr-color-row">
      <input
        type="color"
        value={field.content || field.default_value || '#000000'}
        oninput={handleInput}
        class="fr-color-swatch"
      />
      <input
        class="fr-input"
        type="text"
        value={displayValue}
        oninput={handleInput}
        placeholder="#000000"
        style="flex: 1;"
      />
    </div>

  {:else if field.field_type === 'number'}
    <input
      class="fr-input"
      type="number"
      value={displayValue}
      oninput={handleInput}
      placeholder={field.default_value || '0'}
    />

  {:else if field.field_type === 'date'}
    <input
      class="fr-input"
      type="date"
      value={displayValue}
      oninput={handleInput}
    />

  {:else if field.field_type === 'repeater'}
    <RepeaterField
      schema={field.options}
      items={displayValue}
      onchange={handleRepeaterChange}
    />

  {:else if field.field_type === 'gallery'}
    <GalleryField
      value={displayValue}
      onchange={(photos) => onchange(field.id, JSON.stringify(photos))}
    />

  {:else if field.field_type === 'flexible'}
    <FlexibleField
      layouts={field.options || '{}'}
      items={displayValue}
      onchange={(items) => onchange(field.id, JSON.stringify(items))}
    />

  {:else if field.field_type === 'relationship'}
    {@const relOpts = (() => { try { return JSON.parse(field.options || '{}'); } catch { return {}; } })()}
    <RelationshipField
      collection={relOpts.collection || ''}
      multiple={relOpts.multiple !== false}
      max={relOpts.max || 0}
      value={displayValue}
      onchange={(val) => onchange(field.id, val)}
    />

  {:else}
    <input
      class="fr-input"
      type="text"
      value={displayValue}
      oninput={handleInput}
      placeholder={field.default_value || ''}
    />
  {/if}
</div>

{#if showMediaPicker}
  <MediaPicker
    onselect={handleImageSelect}
    onclose={() => showMediaPicker = false}
  />
{/if}

<style>
  .fr-field {
    padding: 18px 0;
    border-bottom: 1px solid var(--border-secondary);
  }

  .fr-field:last-child {
    border-bottom: none;
  }

  .fr-label {
    display: block;
    font-size: 11px;
    font-weight: 600;
    color: var(--text-tertiary);
    text-transform: uppercase;
    letter-spacing: 0.06em;
    margin-bottom: 8px;
  }

  /* Text inputs — subtle well with hover/focus reveal */
  .fr-input {
    width: 100%;
    padding: 10px 14px;
    border: 1px solid transparent;
    border-radius: 8px;
    background: var(--bg-tertiary);
    font-size: 15px;
    color: var(--text-primary);
    font-family: var(--font-sans);
    outline: none;
    transition: border-color 0.15s, box-shadow 0.15s;
  }

  .fr-input:hover {
    border-color: var(--border-secondary);
  }

  .fr-input:focus {
    border-color: var(--accent);
    box-shadow: 0 0 0 3px var(--accent-soft);
  }

  .fr-input::placeholder {
    color: var(--text-light);
  }

  /* Textarea — same well treatment */
  .fr-textarea {
    width: 100%;
    padding: 10px 14px;
    border: 1px solid transparent;
    border-radius: 8px;
    background: var(--bg-tertiary);
    font-size: 15px;
    color: var(--text-primary);
    font-family: var(--font-sans);
    outline: none;
    resize: vertical;
    min-height: 60px;
    transition: border-color 0.15s, box-shadow 0.15s;
  }

  .fr-textarea:hover {
    border-color: var(--border-secondary);
  }

  .fr-textarea:focus {
    border-color: var(--accent);
    box-shadow: 0 0 0 3px var(--accent-soft);
  }

  .fr-textarea::placeholder {
    color: var(--text-light);
  }

  /* Select — same well treatment */
  .fr-select {
    width: 100%;
    padding: 10px 14px;
    border: 1px solid transparent;
    border-radius: 8px;
    background: var(--bg-tertiary);
    font-size: 15px;
    color: var(--text-primary);
    font-family: var(--font-sans);
    outline: none;
    cursor: pointer;
    appearance: none;
    transition: border-color 0.15s, box-shadow 0.15s;
  }

  .fr-select:focus {
    border-color: var(--accent);
    box-shadow: 0 0 0 3px var(--accent-soft);
  }

  /* Image — clean upload area */
  .fr-image-row {
    margin-top: 4px;
  }

  .fr-image-upload {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 12px 16px;
    border: 1px dashed var(--border-secondary);
    border-radius: var(--radius-md);
    background: none;
    color: var(--text-tertiary);
    font-size: 14px;
    cursor: pointer;
    transition: border-color 0.15s, color 0.15s;
    width: 100%;
  }

  .fr-image-upload:hover {
    border-color: var(--accent);
    color: var(--accent);
  }

  .fr-image-preview {
    position: relative;
    display: inline-block;
  }

  .fr-image-preview img {
    max-width: 200px;
    max-height: 120px;
    border-radius: var(--radius-md);
    display: block;
  }

  .fr-image-clear {
    position: absolute;
    top: 4px;
    right: 4px;
    width: 24px;
    height: 24px;
    border-radius: 50%;
    background: rgba(0, 0, 0, 0.5);
    color: white;
    border: none;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    opacity: 0;
    transition: opacity 0.15s;
  }

  .fr-image-preview:hover .fr-image-clear {
    opacity: 1;
  }

  /* Toggle row */
  .fr-toggle-row {
    display: flex;
    align-items: center;
    gap: 8px;
  }

  .fr-toggle-label {
    font-size: 13px;
    color: var(--text-tertiary);
  }

  /* Color row */
  .fr-color-row {
    display: flex;
    align-items: center;
    gap: var(--space-sm);
  }

  .fr-color-swatch {
    width: 36px;
    height: 36px;
    border: 1px solid var(--border-secondary);
    border-radius: var(--radius-sm);
    padding: 2px;
    cursor: pointer;
    background: none;
    flex-shrink: 0;
  }
</style>
