<script>
  import { wrapMenuLoop } from '$lib/forge-tags.js';

  let { selectedText = '', menus = [], onConfirm, onCancel } = $props();

  let menuSlug  = $state(menus[0]?.slug ?? '');
  let linkVar   = $state('link');
  let applyMapping = $state(true);

  // Detect if selected text has multiple links (good candidate for auto-mapping)
  let hasMultipleLinks = $derived(
    (selectedText.match(/<a\b/gi) || []).length >= 2
  );

  function handleSubmit() {
    if (!menuSlug.trim()) return;
    const output = wrapMenuLoop({
      menuSlug: menuSlug.trim(),
      linkVar: linkVar.trim() || 'link',
      selectedText,
      applyMapping: applyMapping && hasMultipleLinks,
    });
    onConfirm(output);
  }

  function handleKeydown(e) {
    if (e.key === 'Enter') { e.preventDefault(); handleSubmit(); }
  }
</script>

<div class="forge-field">
  <label class="forge-label">Menu</label>
  {#if menus.length > 0}
    <select class="forge-select" bind:value={menuSlug}>
      {#each menus as m}
        <option value={m.slug}>{m.name || m.slug}</option>
      {/each}
    </select>
  {:else}
    <input
      class="forge-input"
      bind:value={menuSlug}
      onkeydown={handleKeydown}
      placeholder="main"
      autocomplete="off"
    />
  {/if}
</div>

<div class="forge-field">
  <label class="forge-label">Link variable</label>
  <input
    class="forge-input"
    bind:value={linkVar}
    onkeydown={handleKeydown}
    placeholder="link"
    autocomplete="off"
  />
</div>

{#if hasMultipleLinks}
  <label class="forge-check-row">
    <input type="checkbox" bind:checked={applyMapping} />
    Replace links with menu tags automatically
  </label>
  <div class="forge-mapper-hint">
    Keeps the first link as the loop template. Adds <code>data-outpost="url"</code> and <code>data-type="link"</code> attributes to the link element.
  </div>
{/if}

<div class="forge-actions">
  <button class="btn btn-secondary" onclick={onCancel}>Cancel</button>
  <button class="btn btn-primary" onclick={handleSubmit} disabled={!menuSlug.trim()}>Apply</button>
</div>

<style>
  .forge-mapper-hint {
    font-size: 11px;
    color: var(--ce-muted, #888);
    line-height: 1.5;
    padding: 0 0 4px;
  }
  .forge-mapper-hint code {
    background: var(--ce-surface, rgba(0,0,0,.04));
    padding: 1px 4px;
    border-radius: 3px;
    font-size: 11px;
  }
</style>
