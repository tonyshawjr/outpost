<script>
  let {
    pageData = null,
    pageId = null,
    pagePath = '/',
    apiUrl = '/outpost/api.php',
    csrfToken = '',
    showToast = () => {},
  } = $props();

  let metaTitle = $state('');
  let metaDescription = $state('');
  let ogImage = $state('');
  let canonicalUrl = $state('');
  let saving = $state(false);

  // Initialize from pageData
  $effect(() => {
    if (pageData) {
      metaTitle = pageData.meta_title || pageData.title || '';
      metaDescription = pageData.meta_description || '';
      ogImage = pageData.og_image || '';
      canonicalUrl = pageData.canonical_url || '';
    }
  });

  const titleLength = $derived(metaTitle.length);
  const descLength = $derived(metaDescription.length);

  const titleColor = $derived(
    titleLength === 0 ? '#9CA3AF' : titleLength <= 60 ? '#10B981' : titleLength <= 70 ? '#F59E0B' : '#EF4444'
  );
  const descColor = $derived(
    descLength === 0 ? '#9CA3AF' : descLength <= 160 ? '#10B981' : descLength <= 200 ? '#F59E0B' : '#EF4444'
  );

  const displayUrl = $derived(() => {
    const host = window.location.hostname;
    return host + pagePath;
  });

  async function save() {
    saving = true;
    try {
      const resp = await fetch(apiUrl + '?action=pages&id=' + pageId, {
        method: 'PUT',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-Token': csrfToken,
        },
        credentials: 'include',
        body: JSON.stringify({
          meta_title: metaTitle,
          meta_description: metaDescription,
          og_image: ogImage,
          canonical_url: canonicalUrl,
        }),
      });
      if (resp.ok) {
        showToast('SEO settings saved');
      } else {
        showToast('Failed to save SEO settings');
      }
    } catch {
      showToast('Failed to save SEO settings');
    } finally {
      saving = false;
    }
  }

  let socialTab = $state('facebook');
</script>

<div class="ope-seo-drawer">
  <!-- Google Preview -->
  <div class="ope-seo-section">
    <div class="ope-seo-section-label">Search Preview</div>
    <div class="ope-seo-google-preview">
      <div class="ope-seo-google-title">{metaTitle || 'Page Title'}</div>
      <div class="ope-seo-google-url">{displayUrl()}</div>
      <div class="ope-seo-google-desc">{metaDescription || 'Add a meta description to control how this page appears in search results.'}</div>
    </div>
  </div>

  <!-- Meta Title -->
  <div class="ope-seo-section">
    <label class="ope-seo-label">
      Meta Title
      <span class="ope-seo-counter" style="color: {titleColor}">{titleLength}/60</span>
    </label>
    <input
      type="text"
      class="ope-seo-input"
      placeholder="Page title for search engines"
      bind:value={metaTitle}
    />
  </div>

  <!-- Meta Description -->
  <div class="ope-seo-section">
    <label class="ope-seo-label">
      Meta Description
      <span class="ope-seo-counter" style="color: {descColor}">{descLength}/160</span>
    </label>
    <textarea
      class="ope-seo-textarea"
      placeholder="Brief description for search results"
      rows="3"
      bind:value={metaDescription}
    ></textarea>
  </div>

  <!-- OG Image -->
  <div class="ope-seo-section">
    <label class="ope-seo-label">OG Image</label>
    {#if ogImage}
      <div class="ope-seo-og-preview">
        <img src={ogImage} alt="OG preview" />
        <button class="ope-seo-og-remove" onclick={() => { ogImage = ''; }}>Remove</button>
      </div>
    {:else}
      <div class="ope-seo-og-placeholder">
        No image set. Falls back to the first image on the page.
      </div>
    {/if}
  </div>

  <!-- Canonical URL -->
  <div class="ope-seo-section">
    <label class="ope-seo-label">Canonical URL</label>
    <input
      type="text"
      class="ope-seo-input"
      placeholder="https://example.com/page"
      bind:value={canonicalUrl}
    />
    <span class="ope-seo-hint">Leave blank to use the current URL.</span>
  </div>

  <!-- Social Preview -->
  <div class="ope-seo-section">
    <div class="ope-seo-section-label">Social Preview</div>
    <div class="ope-seo-social-tabs">
      <button
        class="ope-seo-social-tab"
        class:active={socialTab === 'facebook'}
        onclick={() => { socialTab = 'facebook'; }}
      >Facebook</button>
      <button
        class="ope-seo-social-tab"
        class:active={socialTab === 'twitter'}
        onclick={() => { socialTab = 'twitter'; }}
      >Twitter</button>
    </div>

    <div class="ope-seo-social-card">
      {#if ogImage}
        <div class="ope-seo-social-image">
          <img src={ogImage} alt="" />
        </div>
      {:else}
        <div class="ope-seo-social-image ope-seo-social-image-empty">
          No image
        </div>
      {/if}
      <div class="ope-seo-social-body">
        <div class="ope-seo-social-url">{window.location.hostname}</div>
        <div class="ope-seo-social-title">{metaTitle || 'Page Title'}</div>
        <div class="ope-seo-social-desc">{metaDescription || 'Page description will appear here.'}</div>
      </div>
    </div>
  </div>

  <!-- Save -->
  <div class="ope-seo-actions">
    <button class="ope-seo-save" onclick={save} disabled={saving}>
      {saving ? 'Saving...' : 'Save SEO Settings'}
    </button>
  </div>
</div>

<style>
  .ope-seo-drawer {
    padding: 20px;
    display: flex;
    flex-direction: column;
    gap: 24px;
  }

  .ope-seo-section {
    display: flex;
    flex-direction: column;
    gap: 6px;
  }

  .ope-seo-section-label {
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: #9CA3AF;
    margin-bottom: 4px;
  }

  .ope-seo-label {
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: #9CA3AF;
    display: flex;
    justify-content: space-between;
    align-items: center;
  }

  .ope-seo-counter {
    font-size: 11px;
    font-weight: 500;
    letter-spacing: 0;
    text-transform: none;
  }

  .ope-seo-input {
    width: 100%;
    padding: 8px 0;
    border: none;
    border-bottom: 1px solid transparent;
    font-size: 14px;
    color: #111827;
    background: transparent;
    outline: none;
    transition: border-color 0.15s;
    font-family: inherit;
  }
  .ope-seo-input:hover {
    border-bottom-color: #E5E7EB;
  }
  .ope-seo-input:focus {
    border-bottom-color: #2D5A47;
  }

  .ope-seo-textarea {
    width: 100%;
    padding: 8px 0;
    border: none;
    border-bottom: 1px solid transparent;
    font-size: 14px;
    color: #111827;
    background: transparent;
    outline: none;
    resize: vertical;
    min-height: 60px;
    font-family: inherit;
    transition: border-color 0.15s;
  }
  .ope-seo-textarea:hover {
    border-bottom-color: #E5E7EB;
  }
  .ope-seo-textarea:focus {
    border-bottom-color: #2D5A47;
  }

  .ope-seo-hint {
    font-size: 12px;
    color: #D1D5DB;
  }

  /* Google Preview */
  .ope-seo-google-preview {
    background: #fff;
    border: 1px solid #E5E7EB;
    border-radius: 8px;
    padding: 16px;
  }
  .ope-seo-google-title {
    font-size: 18px;
    color: #1a0dab;
    line-height: 1.3;
    margin-bottom: 2px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }
  .ope-seo-google-url {
    font-size: 13px;
    color: #006621;
    margin-bottom: 4px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }
  .ope-seo-google-desc {
    font-size: 13px;
    color: #545454;
    line-height: 1.5;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
  }

  /* OG Image */
  .ope-seo-og-preview {
    position: relative;
    border-radius: 8px;
    overflow: hidden;
  }
  .ope-seo-og-preview img {
    width: 100%;
    height: 160px;
    object-fit: cover;
    display: block;
  }
  .ope-seo-og-remove {
    position: absolute;
    top: 8px;
    right: 8px;
    background: rgba(0, 0, 0, 0.6);
    color: white;
    border: none;
    border-radius: 4px;
    padding: 4px 10px;
    font-size: 12px;
    cursor: pointer;
  }
  .ope-seo-og-placeholder {
    font-size: 13px;
    color: #D1D5DB;
    padding: 12px 0;
  }

  /* Social Preview */
  .ope-seo-social-tabs {
    display: flex;
    gap: 0;
    border-bottom: 1px solid #E5E7EB;
    margin-bottom: 12px;
  }
  .ope-seo-social-tab {
    background: none;
    border: none;
    border-bottom: 2px solid transparent;
    color: #9CA3AF;
    font-size: 13px;
    font-weight: 500;
    padding: 8px 16px;
    cursor: pointer;
    transition: all 0.15s;
  }
  .ope-seo-social-tab.active {
    color: #111827;
    border-bottom-color: #2D5A47;
  }

  .ope-seo-social-card {
    border: 1px solid #E5E7EB;
    border-radius: 8px;
    overflow: hidden;
  }
  .ope-seo-social-image {
    height: 180px;
    overflow: hidden;
    background: #F3F4F6;
  }
  .ope-seo-social-image img {
    width: 100%;
    height: 100%;
    object-fit: cover;
  }
  .ope-seo-social-image-empty {
    display: flex;
    align-items: center;
    justify-content: center;
    color: #D1D5DB;
    font-size: 13px;
  }
  .ope-seo-social-body {
    padding: 12px;
  }
  .ope-seo-social-url {
    font-size: 11px;
    color: #9CA3AF;
    text-transform: uppercase;
    letter-spacing: 0.03em;
    margin-bottom: 4px;
  }
  .ope-seo-social-title {
    font-size: 15px;
    font-weight: 600;
    color: #111827;
    margin-bottom: 4px;
    line-height: 1.3;
  }
  .ope-seo-social-desc {
    font-size: 13px;
    color: #6B7280;
    line-height: 1.4;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
  }

  /* Save */
  .ope-seo-actions {
    padding-top: 8px;
  }
  .ope-seo-save {
    width: 100%;
    padding: 10px;
    background: #2D5A47;
    color: white;
    border: none;
    border-radius: 6px;
    font-size: 13px;
    font-weight: 500;
    cursor: pointer;
    transition: background 0.15s;
  }
  .ope-seo-save:hover:not(:disabled) {
    background: #1E4535;
  }
  .ope-seo-save:disabled {
    opacity: 0.5;
    cursor: default;
  }
</style>
