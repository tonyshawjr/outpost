<script>
  import { wrapCollectionLoop } from '$lib/forge-tags.js';
  import { detectLoopFields, applyLoopMappings } from '$lib/forge-detect.js';

  let { selectedText = '', collections = [], onConfirm, onCancel } = $props();

  let collectionSlug = $state(collections[0]?.slug ?? '');
  let itemVar        = $state('item');
  let limit          = $state('');
  let orderby        = $state('');

  // Detect mappable content elements in the selected HTML
  let detectedFields = $derived(detectLoopFields(selectedText));

  // Field mappings — each detected field gets a mapped collection field name
  let mappings = $state([]);

  // When detected fields change, initialise mappings with auto-guessed values
  $effect(() => {
    const schema = activeSchema;
    mappings = detectedFields.map(f => ({
      ...f,
      field: autoGuess(f, schema),
    }));
  });

  // Get the selected collection's schema fields
  let activeSchema = $derived.by(() => {
    if (!collectionSlug) return [];
    const col = collections.find(c => c.slug === collectionSlug);
    if (!col?.schema) return [];
    try {
      const s = typeof col.schema === 'string' ? JSON.parse(col.schema) : col.schema;
      return Array.isArray(s) ? s : [];
    } catch { return []; }
  });

  // Auto-guess: match detected field type to a collection field name
  function autoGuess(detected, schema) {
    if (!schema.length) return '';

    // For links, always map to 'url' (the built-in URL field)
    if (detected.context === 'href') return 'url';

    // Try name-based matches first
    const nameHints = {
      image: ['image', 'featured_image', 'photo', 'thumbnail', 'cover', 'avatar', 'picture'],
      text:  ['title', 'name', 'headline', 'heading'],
      richtext: ['body', 'content', 'description', 'excerpt', 'text'],
    };

    const hints = nameHints[detected.type] || [];
    for (const hint of hints) {
      const match = schema.find(s => s.name?.toLowerCase() === hint);
      if (match) return match.name;
    }

    // Fall back to type matching
    const typeMap = { image: 'image', text: 'text', richtext: 'richtext', link: 'text' };
    const wantType = typeMap[detected.type];
    const byType = schema.find(s => s.type === wantType);
    if (byType) return byType.name;

    return '';
  }

  // Snippet type labels
  const typeLabels = { image: 'Image', text: 'Text', richtext: 'Rich Text', link: 'Link' };
  const typeIcons  = { image: '🖼', text: 'Aa', richtext: '¶', link: '🔗' };

  function handleSubmit() {
    if (!collectionSlug.trim()) return;

    // Apply field mappings to the selected HTML
    let processedText = selectedText;
    if (mappings.some(m => m.field)) {
      processedText = applyLoopMappings(selectedText, mappings, itemVar.trim() || 'item');
    }

    const output = wrapCollectionLoop({
      collectionSlug: collectionSlug.trim(),
      itemVar: itemVar.trim() || 'item',
      limit: limit ? parseInt(limit, 10) : '',
      orderby: orderby.trim(),
      selectedText: processedText,
    });
    onConfirm(output);
  }

  function handleKeydown(e) {
    if (e.key === 'Enter') { e.preventDefault(); handleSubmit(); }
  }
</script>

<div class="forge-field">
  <label class="forge-label">Collection</label>
  {#if collections.length > 0}
    <select class="forge-select" bind:value={collectionSlug}>
      {#each collections as c}
        <option value={c.slug}>{c.name || c.slug}</option>
      {/each}
    </select>
  {:else}
    <input
      class="forge-input"
      bind:value={collectionSlug}
      onkeydown={handleKeydown}
      placeholder="post"
      autocomplete="off"
    />
  {/if}
</div>

<div class="forge-row">
  <div class="forge-field">
    <label class="forge-label">Item variable</label>
    <input
      class="forge-input"
      bind:value={itemVar}
      onkeydown={handleKeydown}
      placeholder="item"
      autocomplete="off"
    />
  </div>
  <div class="forge-field">
    <label class="forge-label">Limit</label>
    <input
      class="forge-input"
      type="number"
      bind:value={limit}
      onkeydown={handleKeydown}
      placeholder="All"
      min="1"
    />
  </div>
</div>

<!-- Field Mapper — maps detected HTML elements to collection fields -->
{#if mappings.length > 0 && activeSchema.length > 0}
  <div class="forge-mapper">
    <label class="forge-label">Map Fields</label>
    <div class="forge-mapper-list">
      {#each mappings as mapping, i}
        <div class="forge-mapper-row">
          <div class="forge-mapper-detected">
            <span class="forge-mapper-type" title={typeLabels[mapping.type]}>{typeIcons[mapping.type] || '?'}</span>
            <span class="forge-mapper-snippet" title={mapping.snippet}>{mapping.snippet}</span>
          </div>
          <span class="forge-mapper-arrow">&rarr;</span>
          <select class="forge-mapper-select" bind:value={mappings[i].field}>
            <option value="">Skip</option>
            {#if mapping.context === 'href'}
              <option value="url">url (auto)</option>
            {/if}
            {#each activeSchema as sf}
              <option value={sf.name}>{sf.name}</option>
            {/each}
          </select>
        </div>
      {/each}
    </div>
  </div>
{:else if mappings.length > 0 && activeSchema.length === 0}
  <div class="forge-mapper-hint">
    Select a collection to map HTML elements to fields.
  </div>
{/if}

<div class="forge-field">
  <label class="forge-label">Order by</label>
  <input
    class="forge-input"
    bind:value={orderby}
    onkeydown={handleKeydown}
    placeholder="published_at (optional)"
    autocomplete="off"
  />
</div>

<div class="forge-actions">
  <button class="btn btn-secondary" onclick={onCancel}>Cancel</button>
  <button class="btn btn-primary" onclick={handleSubmit} disabled={!collectionSlug.trim()}>Apply</button>
</div>

<style>
  .forge-mapper { margin-top: 4px; }
  .forge-mapper-list {
    display: flex;
    flex-direction: column;
    gap: 6px;
    margin-top: 6px;
  }
  .forge-mapper-row {
    display: flex;
    align-items: center;
    gap: 6px;
  }
  .forge-mapper-detected {
    display: flex;
    align-items: center;
    gap: 5px;
    flex: 1;
    min-width: 0;
    padding: 4px 8px;
    background: var(--ce-surface, rgba(0,0,0,.04));
    border-radius: 4px;
    font-size: 12px;
    overflow: hidden;
  }
  .forge-mapper-type {
    flex-shrink: 0;
    font-size: 11px;
    opacity: .7;
  }
  .forge-mapper-snippet {
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    color: var(--ce-fg, #333);
    font-size: 12px;
  }
  .forge-mapper-arrow {
    flex-shrink: 0;
    font-size: 11px;
    color: var(--ce-muted, #888);
  }
  .forge-mapper-select {
    flex: 1;
    min-width: 0;
    padding: 4px 6px;
    border: 1px solid var(--border-light, #ddd);
    border-radius: 4px;
    background: var(--bg-primary, #fff);
    color: var(--ce-fg, #333);
    font-size: 12px;
    font-family: var(--font-sans);
  }
  .forge-mapper-select:focus {
    outline: none;
    border-color: var(--forest, #229672);
  }
  .forge-mapper-hint {
    font-size: 12px;
    color: var(--ce-muted, #888);
    padding: 8px 0;
    font-style: italic;
  }
</style>
