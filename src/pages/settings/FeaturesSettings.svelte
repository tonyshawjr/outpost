<script>
  import { featureFlags as featureFlagsApi, settings as settingsApi } from '$lib/api.js';
  import { featureFlags as featureFlagsStore, addToast } from '$lib/stores.js';
  import { onMount } from 'svelte';

  let flags = $state({
    collections: true,
    channels: true,
    forms: true,
    members: true,
    lodge: false,
    analytics: true,
    media: true,
    code_editor: true,
    navigation: true,
    releases: true,
    workflows: true,
    review_links: true,
    backups: true,
    ranger: true,
  });
  let loading = $state(true);
  let saving = $state(false);
  let lodgeSlug = $state('lodge');
  let savingSlug = $state(false);

  const features = [
    { key: 'collections', label: 'Collections', desc: 'Content collections and items' },
    { key: 'channels', label: 'Channels', desc: 'RSS and content syndication' },
    { key: 'forms', label: 'Forms', desc: 'Form builder and submissions inbox' },
    { key: 'members', label: 'Members', desc: 'Member registration and management' },
    { key: 'lodge', label: 'Lodge', desc: 'Member-owned content portal' },
    { key: 'analytics', label: 'Analytics', desc: 'Traffic and content analytics' },
    { key: 'media', label: 'Media', desc: 'Media library and uploads' },
    { key: 'code_editor', label: 'Code Editor', desc: 'Theme code editing' },
    { key: 'releases', label: 'Releases', desc: 'Content versioning and release management' },
    { key: 'workflows', label: 'Workflows', desc: 'Custom publishing workflows' },
    { key: 'navigation', label: 'Navigation', desc: 'Menu management' },
    { key: 'review_links', label: 'Review Links', desc: 'Shareable review links for client feedback' },
    { key: 'backups', label: 'Backups', desc: 'Database backup and restore' },
    { key: 'ranger', label: 'Ranger', desc: 'AI assistant' },
  ];

  onMount(async () => {
    try {
      const data = await featureFlagsApi.get();
      if (data.feature_flags && Object.keys(data.feature_flags).length > 0) {
        flags = { ...flags, ...data.feature_flags };
      }
    } catch (e) {}
    try {
      const data = await settingsApi.get();
      if (data.settings?.lodge_slug) {
        lodgeSlug = data.settings.lodge_slug;
      }
    } catch (e) {}
    loading = false;
  });

  async function saveLodgeSlug() {
    const slug = lodgeSlug.toLowerCase().replace(/[^a-z0-9-]/g, '').replace(/^-|-$/g, '') || 'lodge';
    lodgeSlug = slug;
    savingSlug = true;
    try {
      await settingsApi.update({ lodge_slug: slug });
      addToast('Lodge URL slug saved', 'success');
    } catch (err) {
      addToast(err.message, 'error');
    } finally {
      savingSlug = false;
    }
  }

  async function toggle(key) {
    flags = { ...flags, [key]: !flags[key] };
    saving = true;
    try {
      const data = await featureFlagsApi.update(flags);
      if (data.feature_flags) {
        flags = { ...flags, ...data.feature_flags };
        featureFlagsStore.set(data.feature_flags);
      }
      addToast('Feature updated', 'success');
    } catch (err) {
      flags = { ...flags, [key]: !flags[key] };
      addToast(err.message, 'error');
    } finally {
      saving = false;
    }
  }
</script>

<div class="settings-section">
  <h3 class="settings-section-title">Features</h3>
  <p class="settings-section-desc">Toggle which features appear in the admin sidebar. Disabled features hide from the menu but data is preserved.</p>

  {#if loading}
    <p style="font-size: var(--font-size-sm); color: var(--text-tertiary);">Loading...</p>
  {:else}
    <div class="features-list">
      {#each features as feat}
        <div class="feature-row">
          <div class="feature-info">
            <span class="feature-label">{feat.label}</span>
            <span class="feature-desc">{feat.desc}</span>
          </div>
          <button
            class="toggle"
            class:active={flags[feat.key]}
            onclick={() => toggle(feat.key)}
            type="button"
            disabled={saving}
          ></button>
        </div>
      {/each}
    </div>

    {#if flags.lodge}
      <div class="lodge-config-section">
        <h4 class="lodge-config-title">LODGE CONFIGURATION</h4>
        <div class="lodge-config-row">
          <div class="lodge-config-info">
            <span class="lodge-config-label">Lodge URL Slug</span>
            <span class="lodge-config-desc">The front-end URL prefix for the member portal (e.g., /{lodgeSlug})</span>
          </div>
          <div class="lodge-config-input-group">
            <span class="lodge-config-prefix">/</span>
            <input
              class="input lodge-config-input"
              type="text"
              bind:value={lodgeSlug}
              placeholder="lodge"
              onkeydown={(e) => { if (e.key === 'Enter') saveLodgeSlug(); }}
            />
            <button class="btn btn-secondary btn-sm" onclick={saveLodgeSlug} disabled={savingSlug}>
              {savingSlug ? 'Saving...' : 'Save'}
            </button>
          </div>
        </div>
      </div>
    {/if}
  {/if}
</div>

<style>
  .features-list {
    display: flex;
    flex-direction: column;
  }
  .feature-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: var(--space-md) 0;
    border-bottom: 1px solid var(--border-primary);
  }
  .feature-row:last-child {
    border-bottom: none;
  }
  .feature-info {
    display: flex;
    flex-direction: column;
    gap: 2px;
  }
  .feature-label {
    font-size: 14px;
    font-weight: 500;
    color: var(--text-primary);
  }
  .feature-desc {
    font-size: var(--font-size-xs);
    color: var(--text-tertiary);
  }

  .lodge-config-section {
    margin-top: var(--space-lg);
    padding-top: var(--space-lg);
    border-top: 1px solid var(--border-primary);
  }

  .lodge-config-title {
    font-size: 11px;
    font-weight: 600;
    letter-spacing: 0.05em;
    text-transform: uppercase;
    color: var(--text-tertiary);
    margin: 0 0 var(--space-md);
  }

  .lodge-config-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-md);
  }

  .lodge-config-info {
    display: flex;
    flex-direction: column;
    gap: 2px;
  }

  .lodge-config-label {
    font-size: 14px;
    font-weight: 500;
    color: var(--text-primary);
  }

  .lodge-config-desc {
    font-size: var(--font-size-xs);
    color: var(--text-tertiary);
  }

  .lodge-config-input-group {
    display: flex;
    align-items: center;
    gap: 4px;
  }

  .lodge-config-prefix {
    font-size: 14px;
    color: var(--text-tertiary);
    font-family: var(--font-mono);
  }

  .lodge-config-input {
    width: 140px;
    height: 30px;
    font-size: 13px;
    font-family: var(--font-mono);
  }
</style>
