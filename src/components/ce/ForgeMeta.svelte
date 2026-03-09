<script>
  import { wrapMeta } from '$lib/forge-tags.js';

  let { selectedText = '', suggestedType = 'title', onConfirm, onCancel } = $props();

  let metaType = $state(suggestedType || 'title');

  function handleSubmit() {
    const output = wrapMeta({ metaType, selectedText });
    onConfirm(output);
  }
</script>

<div class="forge-field">
  <label class="forge-label">Meta type</label>
  <select class="forge-select" bind:value={metaType}>
    <option value="title">Page Title</option>
    <option value="description">Meta Description</option>
  </select>
</div>

<div style="font-size:11px;color:var(--text-muted)">
  {#if metaType === 'title'}
    Wraps in <code>{'{{ meta.title }}...{{ /meta.title }}'}</code>
  {:else}
    Wraps in <code>{'{{ meta.description }}...{{ /meta.description }}'}</code>
  {/if}
</div>

<div class="forge-actions">
  <button class="btn btn-secondary" onclick={onCancel}>Cancel</button>
  <button class="btn btn-primary" onclick={handleSubmit}>Apply</button>
</div>
