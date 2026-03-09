<script>
  import { wrapForm } from '$lib/forge-tags.js';

  let { forms = [], onConfirm, onCancel } = $props();

  let formSlug = $state(forms[0]?.slug ?? '');

  let inputEl = $state(null);
  $effect(() => { if (inputEl && forms.length === 0) inputEl.focus(); });

  function handleSubmit() {
    if (!formSlug.trim()) return;
    const output = wrapForm({ formSlug: formSlug.trim() });
    onConfirm(output);
  }

  function handleKeydown(e) {
    if (e.key === 'Enter') { e.preventDefault(); handleSubmit(); }
  }
</script>

<div class="forge-field">
  <label class="forge-label">Form</label>
  {#if forms.length > 0}
    <select class="forge-select" bind:value={formSlug}>
      {#each forms as f}
        <option value={f.slug}>{f.name || f.slug}</option>
      {/each}
    </select>
  {:else}
    <input
      class="forge-input"
      bind:this={inputEl}
      bind:value={formSlug}
      onkeydown={handleKeydown}
      placeholder="contact"
      autocomplete="off"
      spellcheck="false"
    />
  {/if}
</div>

<div style="font-size:11px;color:var(--text-muted)">
  Inserts <code>{`{% form '${formSlug || '...'}' %}`}</code>
</div>

<div class="forge-actions">
  <button class="btn btn-secondary" onclick={onCancel}>Cancel</button>
  <button class="btn btn-primary" onclick={handleSubmit} disabled={!formSlug.trim()}>Apply</button>
</div>
