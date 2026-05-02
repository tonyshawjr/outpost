<script>
  import { onMount } from 'svelte';
  import { navigation as navApi, pages as pagesApi } from '$lib/api.js';
  import { addToast } from '$lib/stores.js';
  import EmptyState from '$components/EmptyState.svelte';
  import ContextualTip from '$components/ContextualTip.svelte';
  import { tips } from '$lib/tips.js';
  import Checkbox from '$components/Checkbox.svelte';

  // ── State ───────────────────────────────────────────────
  let menus = $state([]);
  let activeMenuId = $state(null);
  let editingMenu = $state(null);   // full menu object with items[]
  let loading = $state(true);
  let saving = $state(false);
  let dirty = $state(false);

  // New menu form
  let showNewMenu = $state(false);
  let newName = $state('');
  let newSlug = $state('');
  let slugTouched = $state(false);

  // Page picker popover
  let pickerItemIndex = $state(null);  // flat index into allItems
  let pickerChildIndex = $state(null); // index inside parent's children, or null
  let sitePages = $state([]);
  let showPicker = $state(false);
  let pickerSearch = $state('');

  // ── Computed ────────────────────────────────────────────
  let filteredPages = $derived(
    pickerSearch
      ? sitePages.filter(p =>
          p.title?.toLowerCase().includes(pickerSearch.toLowerCase()) ||
          p.path?.toLowerCase().includes(pickerSearch.toLowerCase())
        )
      : sitePages
  );

  // ── Lifecycle ───────────────────────────────────────────
  onMount(async () => {
    await loadMenus();
    try {
      const data = await pagesApi.list();
      sitePages = data.pages || [];
    } catch (e) {}
  });

  async function loadMenus() {
    loading = true;
    try {
      const data = await navApi.list();
      menus = data.menus || [];
      if (menus.length > 0 && activeMenuId === null) {
        await selectMenu(menus[0].id);
      }
    } catch (err) {
      addToast(err.message, 'error');
    } finally {
      loading = false;
    }
  }

  async function selectMenu(id) {
    if (dirty && !confirm('You have unsaved changes. Discard them?')) return;
    activeMenuId = id;
    dirty = false;
    try {
      const data = await navApi.get(id);
      editingMenu = {
        ...data.menu,
        items: (data.menu.items || []).map(normalizeItem),
      };
    } catch (err) {
      addToast(err.message, 'error');
    }
  }

  function normalizeItem(item) {
    return {
      label: item.label ?? '',
      url: item.url ?? '',
      target: item.target ?? '_self',
      children: (item.children || []).map(c => ({
        label: c.label ?? '',
        url: c.url ?? '',
        target: c.target ?? '_self',
      })),
    };
  }

  // ── Items CRUD ──────────────────────────────────────────
  function addItem() {
    editingMenu.items = [...editingMenu.items, { label: '', url: '', target: '_self', children: [] }];
    dirty = true;
  }

  function removeItem(i) {
    editingMenu.items = editingMenu.items.filter((_, idx) => idx !== i);
    dirty = true;
  }

  function moveItem(i, dir) {
    const arr = [...editingMenu.items];
    const j = i + dir;
    if (j < 0 || j >= arr.length) return;
    [arr[i], arr[j]] = [arr[j], arr[i]];
    editingMenu.items = arr;
    dirty = true;
  }

  function updateItem(i, key, val) {
    editingMenu.items = editingMenu.items.map((item, idx) =>
      idx === i ? { ...item, [key]: val } : item
    );
    dirty = true;
  }

  function addChild(i) {
    editingMenu.items = editingMenu.items.map((item, idx) =>
      idx === i
        ? { ...item, children: [...(item.children || []), { label: '', url: '', target: '_self' }] }
        : item
    );
    dirty = true;
  }

  function removeChild(i, j) {
    editingMenu.items = editingMenu.items.map((item, idx) =>
      idx === i
        ? { ...item, children: item.children.filter((_, ci) => ci !== j) }
        : item
    );
    dirty = true;
  }

  function moveChild(i, j, dir) {
    const arr = [...editingMenu.items];
    const children = [...arr[i].children];
    const k = j + dir;
    if (k < 0 || k >= children.length) return;
    [children[j], children[k]] = [children[k], children[j]];
    arr[i] = { ...arr[i], children };
    editingMenu.items = arr;
    dirty = true;
  }

  function updateChild(i, j, key, val) {
    editingMenu.items = editingMenu.items.map((item, idx) =>
      idx === i
        ? {
            ...item,
            children: item.children.map((c, ci) => ci === j ? { ...c, [key]: val } : c),
          }
        : item
    );
    dirty = true;
  }

  // ── Page picker ─────────────────────────────────────────
  function openPicker(itemIdx, childIdx = null) {
    pickerItemIndex = itemIdx;
    pickerChildIndex = childIdx;
    pickerSearch = '';
    showPicker = true;
  }

  function pickPage(page) {
    const url = page.path;
    if (pickerChildIndex !== null) {
      updateChild(pickerItemIndex, pickerChildIndex, 'url', url);
    } else {
      updateItem(pickerItemIndex, 'url', url);
    }
    showPicker = false;
  }

  // ── Save ────────────────────────────────────────────────
  async function saveMenu() {
    saving = true;
    try {
      await navApi.update(editingMenu.id, {
        name: editingMenu.name,
        items: editingMenu.items,
      });
      dirty = false;
      // Update count in list
      menus = menus.map(m => m.id === editingMenu.id
        ? { ...m, name: editingMenu.name, item_count: editingMenu.items.length }
        : m
      );
      addToast('Menu saved', 'success');
    } catch (err) {
      addToast(err.message, 'error');
    } finally {
      saving = false;
    }
  }

  // ── New menu ────────────────────────────────────────────
  function handleNameInput(e) {
    newName = e.target.value;
    if (!slugTouched) {
      newSlug = newName.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
    }
  }

  async function createMenu() {
    const name = newName.trim();
    const slug = newSlug.trim();
    if (!name || !slug) return;
    try {
      const data = await navApi.create({ name, slug });
      menus = [...menus, { ...data.menu, item_count: 0 }];
      showNewMenu = false;
      newName = '';
      newSlug = '';
      slugTouched = false;
      await selectMenu(data.menu.id);
    } catch (err) {
      addToast(err.message, 'error');
    }
  }

  async function deleteMenu(id) {
    if (!confirm('Delete this menu? This cannot be undone.')) return;
    try {
      await navApi.delete(id);
      menus = menus.filter(m => m.id !== id);
      if (activeMenuId === id) {
        activeMenuId = null;
        editingMenu = null;
        dirty = false;
        if (menus.length > 0) await selectMenu(menus[0].id);
      }
      addToast('Menu deleted', 'success');
    } catch (err) {
      addToast(err.message, 'error');
    }
  }

  // ── Keyboard save ───────────────────────────────────────
  function handleKeydown(e) {
    if ((e.metaKey || e.ctrlKey) && e.key === 's') {
      e.preventDefault();
      if (editingMenu && dirty && !saving) saveMenu();
    }
    if (e.key === 'Escape' && showPicker) showPicker = false;
  }
</script>

<svelte:window onkeydown={handleKeydown} />

<div class="nav-page">
  <!-- Header -->
  <div class="page-header">
    <div class="page-header-icon clay">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
    </div>
    <div class="page-header-content">
      <h1 class="page-title">Navigation</h1>
      <p class="page-subtitle">Manage menus used in your theme via <code class="nav-code">&#123;% for item in menu.slug %&#125;</code></p>
    </div>
    {#if editingMenu}
      <div class="page-header-actions">
        <button
          class="btn btn-primary"
          onclick={saveMenu}
          disabled={!dirty || saving}
        >
          {saving ? 'Saving…' : 'Save'}
        </button>
      </div>
    {/if}
  </div>

  <ContextualTip tipKey="navigation" message={tips.navigation} />

  {#if loading}
    <div class="loading-overlay"><div class="spinner"></div></div>
  {:else}
    <div class="nav-layout">
      <!-- Sidebar: menu list -->
      <div class="nav-sidebar">
        <div class="nav-sidebar-label">Menus</div>
        {#each menus as menu}
          <button
            class="nav-menu-item"
            class:active={activeMenuId === menu.id}
            onclick={() => selectMenu(menu.id)}
          >
            <span class="nav-menu-name">{menu.name}</span>
            <span class="nav-menu-slug">{menu.slug}</span>
          </button>
        {/each}

        {#if showNewMenu}
          <div class="nav-new-form">
            <input
              class="nav-input"
              placeholder="Menu name"
              value={newName}
              oninput={handleNameInput}
            />
            <div class="nav-slug-row">
              <span class="nav-slug-prefix">slug: </span>
              <input
                class="nav-input nav-slug-input"
                placeholder="main"
                bind:value={newSlug}
                oninput={() => { slugTouched = true; }}
              />
            </div>
            <div class="nav-new-actions">
              <button class="nav-btn-primary" onclick={createMenu} disabled={!newName || !newSlug}>Create</button>
              <button class="nav-btn-ghost" onclick={() => { showNewMenu = false; newName = ''; newSlug = ''; slugTouched = false; }}>Cancel</button>
            </div>
          </div>
        {:else}
          <button class="nav-add-menu-btn" onclick={() => { showNewMenu = true; }}>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            New menu
          </button>
        {/if}
      </div>

      <!-- Main: item editor -->
      <div class="nav-editor">
        {#if !editingMenu}
          <EmptyState
            title="No menu selected"
            description="Select or create a menu to get started."
          />
        {:else}
          <!-- Menu name row -->
          <div class="nav-menu-meta">
            <input
              class="nav-menu-name-input"
              value={editingMenu.name}
              oninput={(e) => { editingMenu.name = e.target.value; dirty = true; }}
            />
            <span class="nav-menu-slug-badge">/{editingMenu.slug}</span>
            <button class="nav-delete-menu" onclick={() => deleteMenu(editingMenu.id)} title="Delete menu">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="14" height="14"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4h6v2"/></svg>
            </button>
          </div>

          <!-- Column headers -->
          <div class="nav-col-headers">
            <span class="nav-col-label">Label</span>
            <span class="nav-col-url">URL</span>
            <span class="nav-col-ext">External</span>
          </div>

          <!-- Items -->
          <div class="nav-items">
            {#each editingMenu.items as item, i}
              <div class="nav-item-group">
                <!-- Top-level item row -->
                <div class="nav-item-row">
                  <div class="nav-item-reorder">
                    <button class="nav-reorder-btn" onclick={() => moveItem(i, -1)} disabled={i === 0} title="Move up">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="11" height="11"><polyline points="18 15 12 9 6 15"/></svg>
                    </button>
                    <button class="nav-reorder-btn" onclick={() => moveItem(i, 1)} disabled={i === editingMenu.items.length - 1} title="Move down">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="11" height="11"><polyline points="6 9 12 15 18 9"/></svg>
                    </button>
                  </div>

                  <input
                    class="nav-item-input nav-item-label"
                    placeholder="Label"
                    value={item.label}
                    oninput={(e) => updateItem(i, 'label', e.target.value)}
                  />

                  <div class="nav-url-wrap">
                    <input
                      class="nav-item-input nav-item-url"
                      placeholder="/path or https://..."
                      value={item.url}
                      oninput={(e) => updateItem(i, 'url', e.target.value)}
                    />
                    <button class="nav-pick-btn" onclick={() => openPicker(i)} title="Pick a page">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="13" height="13"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                    </button>
                  </div>

                  <Checkbox checked={item.target === '_blank'} onchange={(checked) => updateItem(i, 'target', checked ? '_blank' : '_self')} />

                  <button class="nav-item-delete" onclick={() => removeItem(i)}>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                  </button>
                </div>

                <!-- Children -->
                {#if item.children && item.children.length > 0}
                  {#each item.children as child, j}
                    <div class="nav-item-row nav-child-row">
                      <div class="nav-child-indent">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="12" height="12" opacity="0.3"><polyline points="9 18 15 12 9 6"/></svg>
                      </div>
                      <div class="nav-item-reorder">
                        <button class="nav-reorder-btn" onclick={() => moveChild(i, j, -1)} disabled={j === 0} title="Move up">
                          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="11" height="11"><polyline points="18 15 12 9 6 15"/></svg>
                        </button>
                        <button class="nav-reorder-btn" onclick={() => moveChild(i, j, 1)} disabled={j === item.children.length - 1} title="Move down">
                          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="11" height="11"><polyline points="6 9 12 15 18 9"/></svg>
                        </button>
                      </div>

                      <input
                        class="nav-item-input nav-item-label"
                        placeholder="Label"
                        value={child.label}
                        oninput={(e) => updateChild(i, j, 'label', e.target.value)}
                      />

                      <div class="nav-url-wrap">
                        <input
                          class="nav-item-input nav-item-url"
                          placeholder="/path or https://..."
                          value={child.url}
                          oninput={(e) => updateChild(i, j, 'url', e.target.value)}
                        />
                        <button class="nav-pick-btn" onclick={() => openPicker(i, j)} title="Pick a page">
                          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="13" height="13"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                        </button>
                      </div>

                      <Checkbox checked={child.target === '_blank'} onchange={(checked) => updateChild(i, j, 'target', checked ? '_blank' : '_self')} />

                      <button class="nav-item-delete" onclick={() => removeChild(i, j)}>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                      </button>
                    </div>
                  {/each}
                {/if}

                <!-- Add sub-item -->
                <button class="nav-add-child-btn" onclick={() => addChild(i)}>
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="11" height="11"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                  Sub-item
                </button>
              </div>
            {/each}
          </div>

          <button class="nav-add-item-btn" onclick={addItem}>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            Add item
          </button>
        {/if}
      </div>
    </div>
  {/if}
</div>

<!-- Page picker overlay -->
{#if showPicker}
  <!-- svelte-ignore a11y_click_events_have_key_events a11y_no_static_element_interactions -->
  <div class="nav-picker-overlay" onclick={() => showPicker = false}>
    <div class="nav-picker" onclick={(e) => e.stopPropagation()}>
      <div class="nav-picker-header">
        <span class="nav-picker-title">Pick a page</span>
        <button class="nav-picker-close" onclick={() => showPicker = false}>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
      </div>
      <input
        class="nav-picker-search"
        placeholder="Search pages…"
        bind:value={pickerSearch}
        autofocus
      />
      <div class="nav-picker-list">
        {#if filteredPages.length === 0}
          <p class="nav-picker-empty">No pages found</p>
        {:else}
          {#each filteredPages as page}
            <button class="nav-picker-item" onclick={() => pickPage(page)}>
              <span class="nav-picker-item-title">{page.title || page.path}</span>
              <span class="nav-picker-item-path">{page.path}</span>
            </button>
          {/each}
        {/if}
      </div>
    </div>
  </div>
{/if}

<style>
  .nav-page {
    max-width: var(--content-width);
  }

  .nav-code {
    font-family: var(--font-mono);
    font-size: 0.85em;
    background: var(--raised);
    padding: 1px 5px;
    border-radius: 3px;
  }

  /* Two-column layout */
  .nav-layout {
    display: flex;
    gap: 0;
    align-items: flex-start;
    border-top: 1px solid var(--border);
    padding-top: var(--space-xl);
  }

  /* Left sidebar */
  .nav-sidebar {
    width: 180px;
    flex-shrink: 0;
    border-right: 1px solid var(--border);
    padding-right: var(--space-lg);
    margin-right: var(--space-xl);
  }

  .nav-sidebar-label {
    font-size: 11px;
    font-weight: 600;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: var(--text-muted);
    margin-bottom: var(--space-sm);
  }

  .nav-menu-item {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    width: 100%;
    padding: 7px 10px;
    border-radius: var(--radius-md);
    border: none;
    background: none;
    cursor: pointer;
    text-align: left;
    margin-bottom: 2px;
    transition: background 0.1s;
  }

  .nav-menu-item:hover {
    background: var(--raised);
  }

  .nav-menu-item.active {
    background: var(--raised);
  }

  .nav-menu-name {
    font-size: var(--text-sm);
    font-weight: 500;
    color: var(--text);
    line-height: 1.3;
  }

  .nav-menu-slug {
    font-size: 11px;
    color: var(--text-muted);
    font-family: var(--font-mono);
  }

  .nav-new-form {
    margin-top: var(--space-sm);
    display: flex;
    flex-direction: column;
    gap: 6px;
  }

  .nav-input {
    width: 100%;
    font-size: var(--text-sm);
    background: none;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    padding: 5px 8px;
    color: var(--text);
    box-sizing: border-box;
  }

  .nav-input:focus {
    outline: none;
    border-color: var(--purple);
  }

  .nav-slug-row {
    display: flex;
    align-items: center;
    gap: 4px;
  }

  .nav-slug-prefix {
    font-size: 11px;
    color: var(--text-muted);
    font-family: var(--font-mono);
    flex-shrink: 0;
  }

  .nav-slug-input {
    flex: 1;
    font-family: var(--font-mono);
    font-size: 11px;
  }

  .nav-new-actions {
    display: flex;
    gap: 6px;
  }

  .nav-btn-primary {
    padding: 5px 12px;
    border-radius: var(--radius-sm);
    border: none;
    background: var(--text);
    color: var(--bg);
    font-size: var(--text-sm);
    font-weight: 500;
    cursor: pointer;
  }

  .nav-btn-primary:disabled {
    opacity: 0.4;
    cursor: default;
  }

  .nav-btn-ghost {
    padding: 5px 12px;
    border-radius: var(--radius-sm);
    border: 1px solid var(--border);
    background: none;
    color: var(--sec);
    font-size: var(--text-sm);
    cursor: pointer;
  }

  .nav-add-menu-btn {
    display: flex;
    align-items: center;
    gap: 5px;
    margin-top: var(--space-sm);
    padding: 5px 0;
    background: none;
    border: none;
    color: var(--text-muted);
    font-size: var(--text-sm);
    cursor: pointer;
    transition: color 0.1s;
  }

  .nav-add-menu-btn:hover {
    color: var(--sec);
  }

  /* Right editor */
  .nav-editor {
    flex: 1;
    min-width: 0;
  }

  .nav-menu-meta {
    display: flex;
    align-items: center;
    gap: var(--space-md);
    margin-bottom: var(--space-xl);
  }

  .nav-menu-name-input {
    font-size: var(--text-lg);
    font-weight: 600;
    color: var(--text);
    background: none;
    border: none;
    border-bottom: 1px solid transparent;
    padding: 2px 0;
    flex: 1;
    min-width: 0;
    transition: border-color 0.15s;
  }

  .nav-menu-name-input:hover,
  .nav-menu-name-input:focus {
    outline: none;
    border-bottom-color: var(--border);
  }

  .nav-menu-slug-badge {
    font-size: 11px;
    font-family: var(--font-mono);
    color: var(--text-muted);
    background: var(--raised);
    padding: 2px 7px;
    border-radius: var(--radius-sm);
    flex-shrink: 0;
  }

  .nav-delete-menu {
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 4px;
    background: none;
    border: none;
    color: var(--text-muted);
    cursor: pointer;
    border-radius: var(--radius-sm);
    opacity: 0.5;
    transition: opacity 0.1s, color 0.1s;
    flex-shrink: 0;
  }

  .nav-delete-menu:hover {
    opacity: 1;
    color: var(--error, #e53e3e);
  }

  /* Column headers */
  .nav-col-headers {
    display: flex;
    align-items: center;
    gap: var(--space-sm);
    padding: 0 0 6px;
    border-bottom: 1px solid var(--border);
    margin-bottom: var(--space-sm);
  }

  .nav-col-headers span {
    font-size: 11px;
    font-weight: 600;
    letter-spacing: 0.05em;
    text-transform: uppercase;
    color: var(--text-muted);
  }

  .nav-col-label {
    /* reorder(24px) + label */
    margin-left: calc(24px + var(--space-sm));
    flex: 1;
  }

  .nav-col-url {
    flex: 1.4;
  }

  .nav-col-ext {
    width: 52px;
    text-align: center;
  }

  /* Items */
  .nav-items {
    display: flex;
    flex-direction: column;
    gap: 2px;
    margin-bottom: var(--space-lg);
  }

  .nav-item-group {
    display: flex;
    flex-direction: column;
    gap: 1px;
    background: var(--bg);
    border-radius: var(--radius-md);
    padding: 2px 0;
  }

  .nav-item-group:hover {
    background: var(--raised);
  }

  .nav-item-row {
    display: flex;
    align-items: center;
    gap: var(--space-sm);
    padding: 4px 6px;
    border-radius: var(--radius-sm);
    min-height: 36px;
  }

  .nav-child-row {
    padding-left: 10px;
  }

  .nav-child-indent {
    display: flex;
    align-items: center;
    flex-shrink: 0;
    margin-left: 4px;
  }

  .nav-item-reorder {
    display: flex;
    flex-direction: column;
    gap: 0;
    flex-shrink: 0;
  }

  .nav-reorder-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    background: none;
    border: none;
    color: var(--text-muted);
    cursor: pointer;
    padding: 1px 2px;
    opacity: 0;
    border-radius: 2px;
    transition: opacity 0.1s;
  }

  .nav-item-row:hover .nav-reorder-btn,
  .nav-child-row:hover .nav-reorder-btn {
    opacity: 0.6;
  }

  .nav-reorder-btn:hover {
    opacity: 1 !important;
    color: var(--text);
  }

  .nav-reorder-btn:disabled {
    opacity: 0.15 !important;
    cursor: default;
  }

  .nav-item-input {
    background: none;
    border: none;
    border-bottom: 1px solid transparent;
    padding: 4px 0;
    font-size: var(--text-sm);
    color: var(--text);
    transition: border-color 0.15s;
    min-width: 0;
  }

  .nav-item-input:focus {
    outline: none;
    border-bottom-color: var(--border);
  }

  .nav-item-label {
    flex: 1;
  }

  .nav-url-wrap {
    flex: 1.4;
    display: flex;
    align-items: center;
    gap: 4px;
    min-width: 0;
  }

  .nav-item-url {
    flex: 1;
    min-width: 0;
    color: var(--sec);
    font-family: var(--font-mono);
    font-size: 12px;
  }

  .nav-pick-btn {
    flex-shrink: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    background: none;
    border: none;
    color: var(--text-muted);
    cursor: pointer;
    padding: 3px;
    border-radius: var(--radius-sm);
    opacity: 0;
    transition: opacity 0.1s;
  }

  .nav-item-row:hover .nav-pick-btn,
  .nav-child-row:hover .nav-pick-btn {
    opacity: 0.6;
  }

  .nav-pick-btn:hover {
    opacity: 1 !important;
    color: var(--text);
  }

  .nav-item-delete {
    display: flex;
    align-items: center;
    justify-content: center;
    background: none;
    border: none;
    color: var(--text-muted);
    cursor: pointer;
    padding: 4px;
    border-radius: var(--radius-sm);
    opacity: 0;
    transition: opacity 0.1s, color 0.1s;
    flex-shrink: 0;
  }

  .nav-item-row:hover .nav-item-delete,
  .nav-child-row:hover .nav-item-delete {
    opacity: 0.5;
  }

  .nav-item-delete:hover {
    opacity: 1 !important;
    color: var(--error, #e53e3e);
  }

  .nav-add-child-btn {
    display: flex;
    align-items: center;
    gap: 4px;
    padding: 3px 6px 3px 52px;
    background: none;
    border: none;
    color: var(--text-muted);
    font-size: 11px;
    cursor: pointer;
    transition: color 0.1s;
    opacity: 0;
  }

  .nav-item-group:hover .nav-add-child-btn {
    opacity: 1;
  }

  .nav-add-child-btn:hover {
    color: var(--sec);
  }

  .nav-add-item-btn {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 8px 10px;
    background: none;
    border: 1px dashed var(--border);
    border-radius: var(--radius-md);
    color: var(--text-muted);
    font-size: var(--text-sm);
    cursor: pointer;
    width: 100%;
    transition: color 0.1s, border-color 0.1s;
    margin-top: var(--space-sm);
  }

  .nav-add-item-btn:hover {
    color: var(--sec);
    border-color: var(--border);
  }

  /* Page picker */
  .nav-picker-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.3);
    z-index: 1000;
    display: flex;
    align-items: flex-start;
    justify-content: center;
    padding-top: 120px;
  }

  .nav-picker {
    background: var(--bg);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    box-shadow: 0 8px 32px rgba(0,0,0,0.15);
    width: 360px;
    max-height: 440px;
    display: flex;
    flex-direction: column;
    overflow: hidden;
  }

  .nav-picker-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 12px 16px;
    border-bottom: 1px solid var(--border);
  }

  .nav-picker-title {
    font-size: var(--text-sm);
    font-weight: 600;
    color: var(--text);
  }

  .nav-picker-close {
    display: flex;
    align-items: center;
    background: none;
    border: none;
    color: var(--text-muted);
    cursor: pointer;
    padding: 2px;
    border-radius: var(--radius-sm);
  }

  .nav-picker-search {
    padding: 10px 16px;
    border: none;
    border-bottom: 1px solid var(--border);
    background: none;
    font-size: var(--text-sm);
    color: var(--text);
    width: 100%;
    box-sizing: border-box;
  }

  .nav-picker-search:focus {
    outline: none;
  }

  .nav-picker-list {
    overflow-y: auto;
    flex: 1;
    padding: 4px;
  }

  .nav-picker-item {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    width: 100%;
    padding: 8px 12px;
    border: none;
    background: none;
    border-radius: var(--radius-sm);
    cursor: pointer;
    text-align: left;
    transition: background 0.1s;
  }

  .nav-picker-item:hover {
    background: var(--raised);
  }

  .nav-picker-item-title {
    font-size: var(--text-sm);
    color: var(--text);
    font-weight: 500;
  }

  .nav-picker-item-path {
    font-size: 11px;
    color: var(--text-muted);
    font-family: var(--font-mono);
  }

  .nav-picker-empty {
    font-size: var(--text-sm);
    color: var(--text-muted);
    padding: var(--space-lg);
    text-align: center;
    margin: 0;
  }

  /* ── Mobile ── */
  @media (max-width: 768px) {
    .nav-layout {
      flex-direction: column;
    }

    .nav-sidebar {
      width: 100%;
      border-right: none;
      border-bottom: 1px solid var(--border);
      padding-right: 0;
      padding-bottom: var(--space-md);
      margin-right: 0;
      margin-bottom: var(--space-md);
      display: flex;
      gap: var(--space-xs);
      flex-wrap: wrap;
    }

    .nav-sidebar-label {
      width: 100%;
    }

    .nav-menu-item {
      padding: 6px 10px;
    }

    .nav-col-headers {
      display: none;
    }

    .nav-item-row {
      flex-wrap: wrap;
    }

    .nav-col-url {
      flex: 1 1 100%;
      padding-left: calc(24px + var(--space-sm));
    }

    .nav-col-ext {
      width: auto;
    }

    .nav-picker {
      width: calc(100vw - 2rem);
      max-width: 360px;
    }
  }
</style>
