<script>
  import { wrapPartialInclude, wrapMenuLoop } from '$lib/forge-tags.js';
  import { code as codeApi } from '$lib/api.js';
  import { addToast } from '$lib/stores.js';

  let { selectedText = '', activeTabPath = '', menus = [], onConfirm, onCreated, onCancel } = $props();

  let partialName = $state('');
  let creating    = $state(false);
  let connectMenu = $state(false);
  let menuSlug    = $state('');

  let inputEl = $state(null);
  $effect(() => { if (inputEl) inputEl.focus(); });

  // Auto-suggest "nav" if selection looks like a nav
  $effect(() => {
    if (isNav && !partialName) partialName = 'nav';
  });

  // Set default menu slug from available menus
  $effect(() => {
    if (connectMenu && !menuSlug && menus.length > 0) {
      menuSlug = menus[0]?.slug ?? '';
    }
  });

  // Detect if selection is a <nav> with 2+ links
  let isNav = $derived(/^<nav[\s>]/i.test(selectedText.trim()));
  let hasMultipleLinks = $derived((selectedText.match(/<a\b/gi) || []).length >= 2);
  let showMenuOption = $derived(isNav && hasMultipleLinks);

  // Derive theme root from active tab path
  let themeRoot = $derived.by(() => {
    if (!activeTabPath) return '';
    const parts = activeTabPath.split('/');
    return parts.length > 1 ? parts[0] : '';
  });

  async function handleSubmit() {
    const name = partialName.trim().replace(/\.html$/, '').replace(/\s+/g, '-').toLowerCase();
    if (!name || !themeRoot) return;

    creating = true;
    try {
      // Ensure partials/ directory exists
      const partialsDir = `${themeRoot}/partials`;
      try {
        await codeApi.create(partialsDir, 'folder');
      } catch (_) {
        // Folder may already exist — that's fine
      }

      // Determine content for the partial file
      let content = selectedText;
      if (connectMenu && menuSlug.trim()) {
        content = applyNavMenuConversion(selectedText, menuSlug.trim());
      }

      // Create partial file with content
      const filePath = `${partialsDir}/${name}.html`;
      await codeApi.create(filePath, 'file', content);

      // Replace selection with include tag
      const output = wrapPartialInclude(name);
      onConfirm(output);

      // Notify parent to refresh tree and open the new file
      if (onCreated) onCreated(filePath);

      addToast(`Partial "${name}" created`, 'success');
    } catch (err) {
      addToast(err.message, 'error');
    } finally {
      creating = false;
    }
  }

  /**
   * Find the <ul> or <ol> containing nav links and wrap it in a menu loop.
   * Replaces repeated <li><a> items with a single loop template.
   */
  function applyNavMenuConversion(html, slug) {
    // Find a <ul> or <ol> with multiple <li><a> elements
    const listRe = /(<(?:ul|ol)\b[^>]*>)([\s\S]*?)(<\/(?:ul|ol)>)/i;
    const listMatch = html.match(listRe);
    if (!listMatch) {
      // No list found — wrap the whole inner content
      return wrapMenuLoop({ menuSlug: slug, linkVar: 'link', selectedText: html, applyMapping: true });
    }

    const [, openTag, innerContent, closeTag] = listMatch;

    // Use wrapMenuLoop for just the inner content (the <li> items)
    const wrappedInner = wrapMenuLoop({
      menuSlug: slug,
      linkVar: 'link',
      selectedText: innerContent.trim(),
      applyMapping: true,
    });

    // Reconstruct: list open tag + wrapped content + list close tag
    return html.replace(listRe, `${openTag}\n${wrappedInner}\n      ${closeTag}`);
  }

  function handleKeydown(e) {
    if (e.key === 'Enter') { e.preventDefault(); handleSubmit(); }
  }
</script>

<div class="forge-field">
  <label class="forge-label">Partial name</label>
  <input
    class="forge-input"
    bind:this={inputEl}
    bind:value={partialName}
    onkeydown={handleKeydown}
    placeholder="header"
    autocomplete="off"
    spellcheck="false"
  />
</div>

{#if showMenuOption}
  <label class="forge-check-row">
    <input type="checkbox" bind:checked={connectMenu} />
    Connect to admin menu
  </label>

  {#if connectMenu}
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
          placeholder="main"
          autocomplete="off"
        />
      {/if}
    </div>
    <div class="forge-nav-hint">
      Replaces static links with <code>{'{%'} for link in menu.{menuSlug || '...'} {'%}'}</code> so nav items are managed from the admin.
    </div>
  {/if}
{/if}

{#if themeRoot}
  <div style="font-size:11px;color:var(--text-muted)">
    Creates <code>{themeRoot}/partials/{partialName || '...'}.html</code>
  </div>
{/if}

<div class="forge-actions">
  <button class="btn btn-secondary" onclick={onCancel}>Cancel</button>
  <button class="btn btn-primary" onclick={handleSubmit} disabled={!partialName.trim() || creating || (connectMenu && !menuSlug.trim())}>
    {creating ? 'Creating...' : 'Extract'}
  </button>
</div>

<style>
  .forge-nav-hint {
    font-size: 11px;
    color: var(--ce-muted, #888);
    line-height: 1.5;
    padding: 0 0 4px;
  }
  .forge-nav-hint code {
    background: var(--ce-surface, rgba(0,0,0,.04));
    padding: 1px 4px;
    border-radius: 3px;
    font-size: 11px;
  }
</style>
