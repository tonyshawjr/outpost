<script>
  import { wrapPartialInclude, wrapMenuLoop } from '$lib/forge-tags.js';
  import { code as codeApi } from '$lib/api.js';
  import { addToast } from '$lib/stores.js';

  let { selectedText = '', activeTabPath = '', menus = [], themeFiles = [], onConfirm, onCreated, onFilesModified, onCancel } = $props();

  let partialName = $state('');
  let creating    = $state(false);
  let connectMenu = $state(false);
  let menuSlug    = $state('');

  // Step 2: apply to other pages
  let step          = $state('create'); // 'create' | 'apply'
  let matchingFiles = $state([]);       // { path, name, content, checked }
  let applying      = $state(false);

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

  let checkedCount = $derived(matchingFiles.filter(f => f.checked).length);

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

      // Check other theme files for the same content
      const otherFiles = themeFiles.filter(f => f.path !== activeTabPath);
      if (otherFiles.length > 0) {
        const matches = [];
        for (const file of otherFiles) {
          try {
            const data = await codeApi.read(file.path);
            if (data.content.includes(selectedText)) {
              matches.push({ path: file.path, name: file.name, content: data.content, checked: true });
            }
          } catch (_) {}
        }
        if (matches.length > 0) {
          matchingFiles = matches;
          step = 'apply';
          creating = false;
          return; // Stay open for step 2
        }
      }
    } catch (err) {
      addToast(err.message, 'error');
    } finally {
      creating = false;
    }
  }

  async function applyToFiles() {
    applying = true;
    const name = partialName.trim().replace(/\.html$/, '').replace(/\s+/g, '-').toLowerCase();
    const includeTag = wrapPartialInclude(name);
    const toApply = matchingFiles.filter(f => f.checked);
    const modifiedPaths = [];

    for (const file of toApply) {
      try {
        const newContent = file.content.replace(selectedText, includeTag);
        await codeApi.write(file.path, newContent);
        modifiedPaths.push(file.path);
      } catch (err) {
        addToast(`Failed to update ${file.name}: ${err.message}`, 'error');
      }
    }

    applying = false;

    if (modifiedPaths.length > 0) {
      addToast(`Partial applied to ${modifiedPaths.length} file(s)`, 'success');
      if (onFilesModified) onFilesModified(modifiedPaths);
    }

    onCancel(); // Close
  }

  function skipApply() {
    onCancel(); // Close
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

{#if step === 'create'}
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
        Replaces static links with <code>&lt;outpost-menu name="{menuSlug || '...'}"&gt;</code> so nav items are managed from the admin.
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

{:else if step === 'apply'}
  <div class="forge-apply-msg">
    Same content found in {matchingFiles.length} other file{matchingFiles.length > 1 ? 's' : ''}.
    Replace with <code>&lt;outpost-include partial="{partialName.trim().replace(/\.html$/, '').replace(/\s+/g, '-').toLowerCase()}" /&gt;</code>?
  </div>

  <div class="forge-apply-list">
    {#each matchingFiles as file, i}
      <label class="forge-check-row">
        <input type="checkbox" bind:checked={matchingFiles[i].checked} />
        <span class="forge-apply-file">{file.name}</span>
      </label>
    {/each}
  </div>

  <div class="forge-actions">
    <button class="btn btn-secondary" onclick={skipApply}>Skip</button>
    <button class="btn btn-primary" onclick={applyToFiles} disabled={applying || checkedCount === 0}>
      {applying ? 'Applying...' : `Apply to ${checkedCount} file${checkedCount !== 1 ? 's' : ''}`}
    </button>
  </div>
{/if}

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

  .forge-apply-msg {
    font-size: 13px;
    color: var(--text);
    line-height: 1.5;
    padding: 0 0 8px;
  }
  .forge-apply-msg code {
    background: var(--ce-surface, rgba(0,0,0,.04));
    padding: 1px 4px;
    border-radius: 3px;
    font-size: 12px;
  }

  .forge-apply-list {
    display: flex;
    flex-direction: column;
    gap: 2px;
    padding: 0 0 8px;
  }

  .forge-apply-file {
    font-size: 13px;
  }
</style>
