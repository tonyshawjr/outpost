<script>
  import { onMount } from 'svelte';
  import { settings as settingsApi } from '$lib/api.js';
  import { addToast, currentSettingsSection, navigate } from '$lib/stores.js';

  import GeneralSettings from './settings/GeneralSettings.svelte';
  import TeamSettings from './settings/TeamSettings.svelte';
  import MembersSettings from './settings/MembersSettings.svelte';
  import EmailSettings from './settings/EmailSettings.svelte';
  import IntegrationsSettings from './settings/IntegrationsSettings.svelte';
  import ImportSettings from './settings/ImportSettings.svelte';
  import AdvancedSettings from './settings/AdvancedSettings.svelte';

  let settings = $state({});
  let loading = $state(true);
  let saving = $state(false);
  let dirty = $state(false);

  let section = $derived($currentSettingsSection);

  const sections = [
    { id: 'general', label: 'General' },
    { id: 'team', label: 'Team' },
    { id: 'members', label: 'Members' },
    { id: 'email', label: 'Email' },
    { id: 'integrations', label: 'Integrations' },
    { id: 'import', label: 'Import' },
    { id: 'advanced', label: 'Advanced' },
  ];

  onMount(async () => {
    loading = true;
    try {
      const data = await settingsApi.get();
      settings = data.settings || {};
    } catch (err) {
      addToast(err.message, 'error');
    } finally {
      loading = false;
    }
  });

  function onSettingChange(key, value) {
    settings = { ...settings, [key]: value };
    dirty = true;
  }

  async function save() {
    saving = true;
    try {
      await settingsApi.update(settings);
      dirty = false;
      addToast('Settings saved', 'success');
    } catch (err) {
      addToast(err.message, 'error');
    } finally {
      saving = false;
    }
  }

  function setSection(id) {
    navigate('settings', { section: id });
  }

  // Which sections use the save bar (key-value settings)
  const saveBarSections = ['general', 'email', 'members', 'integrations', 'advanced'];
  let showSaveBar = $derived(dirty && saveBarSections.includes(section));
</script>

{#if loading}
  <div class="loading-overlay"><div class="spinner"></div></div>
{:else}
  <div class="page-header">
    <div class="page-header-icon sage">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83 0 2 2 0 010-2.83l.06-.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 010-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 012.83-2.83l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 014 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 2.83l-.06.06A1.65 1.65 0 0019.4 9a1.65 1.65 0 001.51 1H21a2 2 0 010 4h-.09a1.65 1.65 0 00-1.51 1z"/></svg>
    </div>
    <div class="page-header-content">
      <h1 class="page-title">Settings</h1>
      <p class="page-subtitle">Manage your site configuration</p>
    </div>
  </div>

  <div class="settings-hub">
    <nav class="settings-nav">
      {#each sections as s}
        <button
          class="settings-nav-item"
          class:active={section === s.id}
          onclick={() => setSection(s.id)}
        >
          {s.label}
        </button>
      {/each}
    </nav>

    <div class="settings-content">
      {#if section === 'general'}
        <GeneralSettings {settings} {onSettingChange} />
      {:else if section === 'team'}
        <TeamSettings />
      {:else if section === 'members'}
        <MembersSettings {settings} {onSettingChange} />
      {:else if section === 'email'}
        <EmailSettings {settings} {onSettingChange} />
      {:else if section === 'integrations'}
        <IntegrationsSettings {settings} {onSettingChange} />
      {:else if section === 'import'}
        <ImportSettings />
      {:else if section === 'advanced'}
        <AdvancedSettings {settings} {onSettingChange} />
      {/if}
    </div>
  </div>

  {#if showSaveBar}
    <div class="save-bar has-changes">
      <span class="save-bar-status dirty">Unsaved changes</span>
      <button class="btn btn-primary" onclick={save} disabled={saving}>
        {saving ? 'Saving...' : 'Save Settings'}
      </button>
    </div>
  {/if}
{/if}

<style>
  .settings-hub {
    max-width: var(--content-width-wide);
    display: grid;
    grid-template-columns: 200px 1fr;
    gap: 0;
    min-height: 400px;
    border-top: 1px solid var(--border-primary);
    padding-top: var(--space-xl);
  }

  .settings-nav {
    display: flex;
    flex-direction: column;
    gap: 2px;
    position: sticky;
    top: var(--space-xl);
    align-self: start;
    border-right: 1px solid var(--border-primary);
    padding-right: var(--space-lg);
    margin-right: var(--space-xl);
  }

  .settings-nav-item {
    display: block;
    width: 100%;
    padding: 7px 10px;
    background: none;
    border: none;
    border-radius: var(--radius-md);
    font-size: 14px;
    font-weight: 400;
    color: var(--text-secondary);
    cursor: pointer;
    text-align: left;
    transition: color 0.1s, background 0.1s;
    margin-bottom: 2px;
  }

  .settings-nav-item:hover {
    color: var(--text-primary);
    background: var(--bg-secondary);
  }

  .settings-nav-item.active {
    color: var(--text-primary);
    font-weight: 500;
    background: var(--bg-secondary);
  }

  .settings-content {
    min-width: 0;
  }

  /* Shared section styles used by sub-components via :global */
  .settings-content :global(.settings-section) {
    max-width: 680px;
  }

  .settings-content :global(.settings-section-title) {
    font-family: var(--font-serif);
    font-size: 20px;
    font-weight: 600;
    color: var(--text-primary);
    margin: 0 0 var(--space-xs);
  }

  .settings-content :global(.settings-section-desc) {
    font-size: var(--font-size-sm);
    color: var(--text-secondary);
    margin: 0 0 var(--space-xl);
  }

  @media (max-width: 768px) {
    .settings-hub {
      grid-template-columns: 1fr;
      gap: var(--space-lg);
    }
    .settings-nav {
      flex-direction: row;
      flex-wrap: wrap;
      position: static;
      gap: var(--space-xs);
      border-right: none;
      padding-right: 0;
      margin-right: 0;
      padding-bottom: var(--space-md);
      border-bottom: 1px solid var(--border-primary);
      margin-bottom: 0;
    }
    .settings-nav-item {
      width: auto;
      padding: 6px 12px;
    }
  }
</style>
