<script>
  let {
    onpublish = () => {},
    showToast = () => {},
    apiUrl = '/outpost/api.php',
    csrfToken = '',
    pageId = null,
    onclose = () => {},
  } = $props();

  let showReleaseModal = $state(false);
  let releases = $state([]);
  let selectedRelease = $state('');
  let newReleaseName = $state('');
  let creatingRelease = $state(false);

  function publishNow() {
    onpublish();
    onclose();
  }

  function openReleaseModal() {
    showReleaseModal = true;
    loadReleases();
  }

  async function loadReleases() {
    try {
      const resp = await fetch(apiUrl + '?action=releases', {
        credentials: 'include',
        headers: { 'X-CSRF-Token': csrfToken },
      });
      if (resp.ok) {
        const data = await resp.json();
        releases = (data.releases || []).filter(r => r.status === 'draft');
      }
    } catch {
      // silently fail
    }
  }

  async function addToRelease() {
    if (!selectedRelease && !newReleaseName.trim()) return;
    creatingRelease = true;
    try {
      let releaseId = selectedRelease;

      if (!releaseId && newReleaseName.trim()) {
        const resp = await fetch(apiUrl + '?action=releases', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': csrfToken,
          },
          credentials: 'include',
          body: JSON.stringify({ name: newReleaseName.trim() }),
        });
        if (resp.ok) {
          const data = await resp.json();
          releaseId = data.release?.id;
        }
      }

      if (releaseId) {
        await fetch(apiUrl + '?action=releases/' + releaseId + '/pages', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': csrfToken,
          },
          credentials: 'include',
          body: JSON.stringify({ page_id: pageId }),
        });
        showToast('Added to release');
      }
    } catch {
      showToast('Failed to add to release');
    } finally {
      creatingRelease = false;
      showReleaseModal = false;
      onclose();
    }
  }

  async function shareForReview() {
    try {
      const resp = await fetch(apiUrl + '?action=pages/' + pageId + '/review-link', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-Token': csrfToken,
        },
        credentials: 'include',
      });
      if (resp.ok) {
        const data = await resp.json();
        const url = data.url || (window.location.origin + window.location.pathname + '?preview=' + (data.token || ''));
        await navigator.clipboard.writeText(url);
        showToast('Review link copied!');
      } else {
        const token = Math.random().toString(36).slice(2, 10);
        const url = window.location.origin + window.location.pathname + '?preview=' + token;
        await navigator.clipboard.writeText(url);
        showToast('Review link copied!');
      }
    } catch {
      showToast('Failed to generate review link');
    }
    onclose();
  }

  function handleClickOutside(e) {
    if (!e.target.closest('.ope-publish-dropdown')) {
      if (showReleaseModal) {
        showReleaseModal = false;
      } else {
        onclose();
      }
    }
  }
</script>

<svelte:window onclick={handleClickOutside} />

{#if !showReleaseModal}
  <div class="ope-publish-dropdown" onclick={(e) => { e.stopPropagation(); }}>
    <button class="ope-publish-option" onclick={publishNow}>
      <div class="ope-publish-option-icon">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
          <polyline points="20 6 9 17 4 12"/>
        </svg>
      </div>
      <div>
        <div class="ope-publish-option-title">Publish now</div>
        <div class="ope-publish-option-desc">Push all changes live</div>
      </div>
    </button>
    <button class="ope-publish-option" onclick={(e) => { e.stopPropagation(); openReleaseModal(); }}>
      <div class="ope-publish-option-icon">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
          <rect x="3" y="4" width="18" height="16" rx="2"/>
          <path d="M7 10h10M7 14h7"/>
        </svg>
      </div>
      <div>
        <div class="ope-publish-option-title">Add to Release</div>
        <div class="ope-publish-option-desc">Bundle with other changes</div>
      </div>
    </button>
    <button class="ope-publish-option" onclick={(e) => { e.stopPropagation(); shareForReview(); }}>
      <div class="ope-publish-option-icon">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
          <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/>
          <path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>
        </svg>
      </div>
      <div>
        <div class="ope-publish-option-title">Share for Review</div>
        <div class="ope-publish-option-desc">Generate a preview link</div>
      </div>
    </button>
  </div>
{:else}
  <div class="ope-publish-dropdown ope-publish-release-modal" onclick={(e) => { e.stopPropagation(); }}>
    <div class="ope-publish-release-header">Add to Release</div>

    {#if releases.length > 0}
      <select class="ope-publish-release-select" bind:value={selectedRelease}>
        <option value="">Select a release...</option>
        {#each releases as rel}
          <option value={rel.id}>{rel.name}</option>
        {/each}
      </select>
    {/if}

    <div class="ope-publish-release-divider">or create new</div>

    <input
      type="text"
      class="ope-publish-release-input"
      placeholder="Release name"
      bind:value={newReleaseName}
    />

    <div class="ope-publish-release-actions">
      <button class="ope-publish-release-cancel" onclick={() => { showReleaseModal = false; }}>Cancel</button>
      <button
        class="ope-publish-release-add"
        onclick={addToRelease}
        disabled={creatingRelease || (!selectedRelease && !newReleaseName.trim())}
      >
        {creatingRelease ? 'Adding...' : 'Add changes'}
      </button>
    </div>
  </div>
{/if}

<style>
  .ope-publish-dropdown {
    position: absolute;
    top: 100%;
    right: 0;
    margin-top: 6px;
    background: white;
    border: 1px solid #E5E7EB;
    border-radius: 10px;
    box-shadow: 0 8px 30px rgba(0, 0, 0, 0.12);
    min-width: 260px;
    overflow: hidden;
    z-index: 2147483647;
    padding: 4px;
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Inter', sans-serif;
  }

  .ope-publish-option {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    width: 100%;
    padding: 10px 12px;
    border: none;
    background: transparent;
    cursor: pointer;
    text-align: left;
    border-radius: 8px;
    transition: background 0.1s;
    color: #374151;
  }
  .ope-publish-option:hover {
    background: #F9FAFB;
  }

  .ope-publish-option-icon {
    width: 28px;
    height: 28px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #F3F4F6;
    border-radius: 6px;
    color: #6B7280;
    flex-shrink: 0;
  }

  .ope-publish-option-title {
    font-size: 13px;
    font-weight: 500;
    color: #111827;
  }

  .ope-publish-option-desc {
    font-size: 12px;
    color: #9CA3AF;
    margin-top: 1px;
  }

  /* Release modal */
  .ope-publish-release-modal {
    padding: 16px;
    display: flex;
    flex-direction: column;
    gap: 12px;
  }

  .ope-publish-release-header {
    font-size: 14px;
    font-weight: 600;
    color: #111827;
  }

  .ope-publish-release-select {
    width: 100%;
    padding: 8px 10px;
    border: 1px solid #E5E7EB;
    border-radius: 6px;
    font-size: 13px;
    color: #111827;
    background: white;
    font-family: inherit;
  }

  .ope-publish-release-divider {
    font-size: 11px;
    color: #9CA3AF;
    text-align: center;
    text-transform: uppercase;
    letter-spacing: 0.05em;
  }

  .ope-publish-release-input {
    width: 100%;
    padding: 8px 10px;
    border: 1px solid #E5E7EB;
    border-radius: 6px;
    font-size: 13px;
    color: #111827;
    font-family: inherit;
    outline: none;
  }
  .ope-publish-release-input:focus {
    border-color: #2D5A47;
  }

  .ope-publish-release-actions {
    display: flex;
    gap: 8px;
    justify-content: flex-end;
  }

  .ope-publish-release-cancel {
    background: #F3F4F6;
    color: #374151;
    border: none;
    border-radius: 6px;
    padding: 6px 14px;
    font-size: 12px;
    font-weight: 500;
    cursor: pointer;
  }

  .ope-publish-release-add {
    background: #2D5A47;
    color: white;
    border: none;
    border-radius: 6px;
    padding: 6px 14px;
    font-size: 12px;
    font-weight: 500;
    cursor: pointer;
    transition: background 0.15s;
  }
  .ope-publish-release-add:hover:not(:disabled) {
    background: #1E4535;
  }
  .ope-publish-release-add:disabled {
    opacity: 0.5;
    cursor: default;
  }
</style>
