<script>
  import { wrapConditional } from '$lib/forge-tags.js';

  let { selectedText = '', onConfirm, onCancel } = $props();

  let expression = $state('');
  let operator   = $state('truthy');
  let value      = $state('');

  let inputEl = $state(null);
  $effect(() => { if (inputEl) inputEl.focus(); });

  function handleSubmit() {
    if (!expression.trim()) return;
    const output = wrapConditional({
      expression: expression.trim(),
      operator,
      value: value.trim(),
      selectedText,
    });
    onConfirm(output);
  }

  function handleKeydown(e) {
    if (e.key === 'Enter') { e.preventDefault(); handleSubmit(); }
  }
</script>

<div class="forge-field">
  <label class="forge-label">Expression</label>
  <input
    class="forge-input"
    bind:this={inputEl}
    bind:value={expression}
    onkeydown={handleKeydown}
    placeholder="field_name or @global"
    autocomplete="off"
    spellcheck="false"
  />
</div>

<div class="forge-row">
  <div class="forge-field">
    <label class="forge-label">Operator</label>
    <select class="forge-select" bind:value={operator}>
      <option value="truthy">Is truthy</option>
      <option value="==">Equals</option>
      <option value="!=">Not equals</option>
    </select>
  </div>
  {#if operator !== 'truthy'}
    <div class="forge-field">
      <label class="forge-label">Value</label>
      <input
        class="forge-input"
        bind:value={value}
        onkeydown={handleKeydown}
        placeholder="value"
        autocomplete="off"
      />
    </div>
  {/if}
</div>

<div class="forge-actions">
  <button class="btn btn-secondary" onclick={onCancel}>Cancel</button>
  <button class="btn btn-primary" onclick={handleSubmit} disabled={!expression.trim()}>Apply</button>
</div>
