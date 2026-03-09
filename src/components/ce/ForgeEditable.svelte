<script>
  import { wrapEditable } from '$lib/forge-tags.js';

  let { selectedText = '', suggestedType = 'text', onConfirm, onCancel } = $props();

  let fieldName = $state('');
  let type      = $state(suggestedType || 'text');
  let scope     = $state('page');
  let useDefault = $state(!!selectedText);

  let inputEl = $state(null);

  $effect(() => {
    if (inputEl) inputEl.focus();
  });

  // Auto-slugify: "Hero Title" → "hero_title"
  function slugify(text) {
    return text
      .replace(/<[^>]+>/g, '')       // strip HTML tags
      .replace(/[^\w\s-]/g, '')      // remove non-word chars
      .trim()
      .replace(/\s+/g, '_')
      .toLowerCase()
      .slice(0, 40);
  }

  // Pre-populate field name from selection
  $effect(() => {
    if (!selectedText) return;
    // For image/media: try alt text first
    const altMatch = selectedText.match(/alt\s*=\s*["']([^"']+)/i);
    if (altMatch) {
      fieldName = slugify(altMatch[1]);
      return;
    }
    // For short plain text (no HTML)
    if (selectedText.length < 60 && !/<[a-z]/i.test(selectedText)) {
      fieldName = slugify(selectedText);
    }
  });

  let canUseDefault = $derived(['text', 'richtext', 'textarea', 'image', 'link'].includes(type));
  let defaultLabel = $derived(
    type === 'image' ? 'Keep original image as default' :
    type === 'link'  ? 'Keep original link as default' :
    'Use selected text as default'
  );

  function handleSubmit() {
    if (!fieldName.trim()) return;
    const output = wrapEditable({
      fieldName: fieldName.trim(),
      type,
      scope,
      useDefault: useDefault && canUseDefault,
      selectedText,
    });
    onConfirm(output);
  }

  function handleKeydown(e) {
    if (e.key === 'Enter') { e.preventDefault(); handleSubmit(); }
  }

  const types = [
    { value: 'text',     label: 'Text' },
    { value: 'richtext', label: 'Rich Text' },
    { value: 'image',    label: 'Image' },
    { value: 'link',     label: 'Link' },
    { value: 'textarea', label: 'Textarea' },
    { value: 'select',   label: 'Select' },
    { value: 'color',    label: 'Color' },
    { value: 'number',   label: 'Number' },
    { value: 'date',     label: 'Date' },
    { value: 'toggle',   label: 'Toggle' },
  ];
</script>

<div class="forge-field">
  <label class="forge-label">Field name</label>
  <input
    class="forge-input"
    bind:this={inputEl}
    bind:value={fieldName}
    onkeydown={handleKeydown}
    placeholder="hero_title"
    autocomplete="off"
    spellcheck="false"
  />
</div>

<div class="forge-row">
  <div class="forge-field">
    <label class="forge-label">Type</label>
    <select class="forge-select" bind:value={type}>
      {#each types as t}
        <option value={t.value}>{t.label}</option>
      {/each}
    </select>
  </div>
  <div class="forge-field">
    <label class="forge-label">Scope</label>
    <select class="forge-select" bind:value={scope}>
      <option value="page">Page field</option>
      <option value="global">Global</option>
    </select>
  </div>
</div>

{#if canUseDefault && selectedText}
  <label class="forge-check-row">
    <input type="checkbox" bind:checked={useDefault} />
    {defaultLabel}
  </label>
{/if}

<div class="forge-actions">
  <button class="btn btn-secondary" onclick={onCancel}>Cancel</button>
  <button class="btn btn-primary" onclick={handleSubmit} disabled={!fieldName.trim()}>Apply</button>
</div>
