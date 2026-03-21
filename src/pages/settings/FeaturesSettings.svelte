<script>
  import { featureFlags as featureFlagsApi } from '$lib/api.js';
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
  });
  let loading = $state(true);
  let saving = $state(false);

  const features = [
    { key: 'collections', label: 'Collections', desc: 'Content collections and items' },
    { key: 'channels', label: 'Channels', desc: 'RSS and content syndication' },
    { key: 'forms', label: 'Forms', desc: 'Form builder and submissions inbox' },
    { key: 'members', label: 'Members', desc: 'Member registration and management' },
    { key: 'lodge', label: 'Lodge', desc: 'Member-owned content portal' },
    { key: 'analytics', label: 'Analytics', desc: 'Traffic and content analytics' },
    { key: 'media', label: 'Media', desc: 'Media library and uploads' },
    { key: 'code_editor', label: 'Code Editor', desc: 'Theme code editing' },
    { key: 'navigation', label: 'Navigation', desc: 'Menu management' },
  ];

  onMount(async () => {
    try {
      const data = await featureFlagsApi.get();
      if (data.feature_flags && Object.keys(data.feature_flags).length > 0) {
        flags = { ...flags, ...data.feature_flags };
      }
    } catch (e) {}
    loading = false;
  });

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
</style>
