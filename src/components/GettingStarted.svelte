<script>
  import { onMount } from 'svelte';
  import { setup as setupApi } from '$lib/api.js';
  import { navigate } from '$lib/stores.js';

  let checklist = $state(null);
  let dismissed = $state(false);
  let homePageId = $state(null);
  let loading = $state(true);

  let items = $derived(checklist ? [
    { key: 'add_logo', label: 'Upload your logo', route: 'theme-customizer', done: checklist.add_logo },
    { key: 'edit_homepage', label: 'Edit your homepage', route: 'page-editor', params: homePageId ? { pageId: homePageId } : {}, done: checklist.edit_homepage },
    { key: 'create_post', label: 'Create your first post', route: 'collections', done: checklist.create_post },
    { key: 'setup_navigation', label: 'Set up your navigation', route: 'navigation', done: checklist.setup_navigation },
    { key: 'customize_theme', label: 'Customize your theme', route: 'theme-customizer', done: checklist.customize_theme },
  ] : []);

  let completedCount = $derived(items.filter(i => i.done).length);
  let totalCount = $derived(items.length);
  let allDone = $derived(completedCount === totalCount && totalCount > 0);
  let progress = $derived(totalCount > 0 ? (completedCount / totalCount) * 100 : 0);

  let visible = $derived(!loading && checklist && !dismissed && !allDone);

  onMount(async () => {
    try {
      const data = await setupApi.checklist();
      checklist = data.checklist;
      dismissed = data.dismissed;
      homePageId = data.home_page_id;
    } catch (e) {
      // Endpoint may not exist on older installs
    } finally {
      loading = false;
    }
  });

  async function dismiss() {
    dismissed = true;
    try {
      await setupApi.dismissChecklist();
    } catch (e) {
      // Ignore
    }
  }

  function goTo(item) {
    navigate(item.route, item.params || {});
  }
</script>

{#if visible}
  <div class="gs-card">
    <div class="gs-progress-bar" role="progressbar" aria-valuenow={Math.round(progress)} aria-valuemin="0" aria-valuemax="100" aria-label="Setup progress">
      <div class="gs-progress-fill" style="width: {progress}%"></div>
    </div>
    <div class="gs-header">
      <h3 class="gs-title">Getting Started</h3>
      <span class="gs-count">{completedCount} of {totalCount}</span>
    </div>
    <div class="gs-items">
      {#each items as item}
        <button class="gs-item" class:done={item.done} onclick={() => goTo(item)}>
          <div class="gs-check" class:checked={item.done}>
            {#if item.done}
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" width="14" height="14"><polyline points="20 6 9 17 4 12"/></svg>
            {/if}
          </div>
          <span class="gs-label">{item.label}</span>
          <svg class="gs-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><polyline points="9 18 15 12 9 6"/></svg>
        </button>
      {/each}
    </div>
    <button class="gs-dismiss" onclick={dismiss}>Dismiss</button>
  </div>
{/if}

<style>
  .gs-card {
    background: var(--bg-secondary);
    border: 1px solid var(--border-primary);
    border-radius: 10px;
    overflow: hidden;
    margin-bottom: 32px;
  }
  .gs-progress-bar {
    height: 3px;
    background: var(--border-primary);
  }
  .gs-progress-fill {
    height: 100%;
    background: var(--success);
    transition: width 0.3s ease;
    border-radius: 0 2px 2px 0;
  }
  .gs-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 20px 24px 12px;
  }
  .gs-title {
    font-family: var(--font-serif);
    font-size: 20px;
    font-weight: 600;
    margin: 0;
    color: var(--text-primary);
  }
  .gs-count {
    font-size: 13px;
    color: var(--text-tertiary);
  }
  .gs-items {
    padding: 0 12px;
  }
  .gs-item {
    display: flex;
    align-items: center;
    gap: 12px;
    width: 100%;
    padding: 12px;
    background: none;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    text-align: left;
    color: var(--text-primary);
    transition: background 0.1s;
  }
  .gs-item:hover {
    background: var(--bg-hover);
  }
  .gs-item.done {
    opacity: 0.5;
  }
  .gs-check {
    width: 22px;
    height: 22px;
    border-radius: 50%;
    border: 2px solid var(--border-primary);
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    transition: all 0.15s;
  }
  .gs-check.checked {
    background: var(--success);
    border-color: var(--success);
    color: #fff;
  }
  .gs-label {
    flex: 1;
    font-size: 14px;
    font-weight: 500;
  }
  .gs-arrow {
    color: var(--text-tertiary);
    opacity: 0;
    transition: opacity 0.1s;
  }
  .gs-item:hover .gs-arrow {
    opacity: 1;
  }
  .gs-dismiss {
    display: block;
    width: 100%;
    padding: 14px;
    background: none;
    border: none;
    border-top: 1px solid var(--border-primary);
    color: var(--text-tertiary);
    font-size: 13px;
    cursor: pointer;
    text-align: center;
  }
  .gs-dismiss:hover {
    color: var(--text-secondary);
  }
</style>
