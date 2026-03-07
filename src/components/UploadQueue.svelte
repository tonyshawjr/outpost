<script>
  import { untrack } from 'svelte';
  import { getCsrfToken, getApiBase } from '$lib/api.js';
  import { humanFileSize } from '$lib/utils.js';

  let {
    files = [],
    folderId = null,
    oncomplete = () => {},
    onfileComplete = () => {},
  } = $props();

  const MAX_CONCURRENT = 2;

  let queue = $state([]);
  let collapsed = $state(false);
  let visible = $state(false);
  let autoHideTimer = $state(null);
  let lastFileBatch = $state(null);

  $effect(() => {
    // Only track `files` — untrack everything else to prevent reactive loops
    const currentFiles = files;
    if (currentFiles.length > 0 && currentFiles !== lastFileBatch) {
      untrack(() => {
        lastFileBatch = currentFiles;
        const newItems = currentFiles.map((f, i) => ({
          id: Date.now() + '_' + i,
          file: f,
          name: f.name,
          size: f.size,
          progress: 0,
          status: 'pending', // pending | uploading | done | error | cancelled
          error: null,
          result: null,
          savings: 0,
          xhr: null,
        }));
        queue = [...queue, ...newItems];
        visible = true;
        collapsed = false;
        if (autoHideTimer) clearTimeout(autoHideTimer);
        processQueue();
      });
    }
  });

  let activeCount = $derived(queue.filter(q => q.status === 'uploading').length);
  let pendingCount = $derived(queue.filter(q => q.status === 'pending').length);
  let doneCount = $derived(queue.filter(q => q.status === 'done').length);
  let totalCount = $derived(queue.length);
  let allDone = $derived(pendingCount === 0 && activeCount === 0 && totalCount > 0);

  $effect(() => {
    if (allDone && visible) {
      autoHideTimer = setTimeout(() => {
        collapsed = true;
      }, 3000);
    }
  });

  function processQueue() {
    const uploading = queue.filter(q => q.status === 'uploading').length;
    const pending = queue.filter(q => q.status === 'pending');
    const toStart = Math.min(MAX_CONCURRENT - uploading, pending.length);

    for (let i = 0; i < toStart; i++) {
      startUpload(pending[i]);
    }
  }

  function startUpload(item) {
    item.status = 'uploading';
    item.progress = 0;

    const xhr = new XMLHttpRequest();
    item.xhr = xhr;
    const formData = new FormData();
    formData.append('file', item.file);
    formData.append('csrf_token', getCsrfToken());
    if (folderId != null) formData.append('folder_id', folderId);

    const apiBase = getApiBase();
    const url = new URL(apiBase, window.location.origin);
    url.searchParams.set('action', 'media/upload');

    xhr.upload.onprogress = (e) => {
      if (e.lengthComputable) {
        item.progress = Math.round((e.loaded / e.total) * 100);
        queue = [...queue]; // trigger reactivity
      }
    };

    xhr.onload = () => {
      try {
        const data = JSON.parse(xhr.responseText);
        if (xhr.status >= 200 && xhr.status < 300 && data.media) {
          item.status = 'done';
          item.result = data.media;
          item.progress = 100;
          if (data.media.webp_savings) {
            item.savings = data.media.webp_savings;
          }
          onfileComplete(data.media);
        } else {
          item.status = 'error';
          item.error = data.error || 'Upload failed';
        }
      } catch {
        item.status = 'error';
        item.error = 'Invalid response';
      }
      queue = [...queue];
      processQueue();
      checkAllDone();
    };

    xhr.onerror = () => {
      item.status = 'error';
      item.error = 'Network error';
      queue = [...queue];
      processQueue();
      checkAllDone();
    };

    xhr.onabort = () => {
      item.status = 'cancelled';
      queue = [...queue];
      processQueue();
    };

    xhr.open('POST', url.toString());
    xhr.withCredentials = true;
    xhr.setRequestHeader('X-CSRF-Token', getCsrfToken());
    xhr.send(formData);
  }

  function cancelUpload(item) {
    if (item.xhr && item.status === 'uploading') {
      item.xhr.abort();
    } else if (item.status === 'pending') {
      item.status = 'cancelled';
      queue = [...queue];
    }
  }

  function checkAllDone() {
    const p = queue.filter(q => q.status === 'pending').length;
    const a = queue.filter(q => q.status === 'uploading').length;
    if (p === 0 && a === 0) {
      oncomplete();
    }
  }

  function dismiss() {
    queue = [];
    visible = false;
    if (autoHideTimer) clearTimeout(autoHideTimer);
  }

  function statusIcon(status) {
    if (status === 'done') return '✓';
    if (status === 'error') return '✗';
    if (status === 'cancelled') return '—';
    return '';
  }
</script>

{#if visible && queue.length > 0}
  <div class="upload-queue" class:collapsed>
    <div class="uq-header" onclick={() => collapsed = !collapsed}>
      <span class="uq-title">
        {#if allDone}
          {doneCount} file{doneCount !== 1 ? 's' : ''} uploaded
        {:else}
          Uploading {activeCount + pendingCount} file{(activeCount + pendingCount) !== 1 ? 's' : ''}
        {/if}
      </span>
      <div class="uq-header-actions">
        <button class="uq-btn" onclick={(e) => { e.stopPropagation(); collapsed = !collapsed; }}>
          {collapsed ? '▲' : '▼'}
        </button>
        {#if allDone}
          <button class="uq-btn" onclick={(e) => { e.stopPropagation(); dismiss(); }}>✕</button>
        {/if}
      </div>
    </div>

    {#if !collapsed}
      <div class="uq-list">
        {#each queue as item (item.id)}
          <div class="uq-item" class:done={item.status === 'done'} class:error={item.status === 'error'}>
            <div class="uq-item-info">
              <span class="uq-filename">{item.name}</span>
              <span class="uq-size">{humanFileSize(item.size)}</span>
              {#if item.savings > 0}
                <span class="uq-savings">-{humanFileSize(item.savings)}</span>
              {/if}
            </div>
            <div class="uq-item-status">
              {#if item.status === 'uploading'}
                <div class="uq-progress-bar">
                  <div class="uq-progress-fill" style="width: {item.progress}%"></div>
                </div>
                <button class="uq-cancel" onclick={() => cancelUpload(item)} title="Cancel">✕</button>
              {:else if item.status === 'pending'}
                <span class="uq-pending">Waiting...</span>
                <button class="uq-cancel" onclick={() => cancelUpload(item)} title="Cancel">✕</button>
              {:else}
                <span class="uq-status-icon" class:success={item.status === 'done'} class:fail={item.status === 'error'}>
                  {statusIcon(item.status)}
                </span>
              {/if}
            </div>
            {#if item.error}
              <div class="uq-error">{item.error}</div>
            {/if}
          </div>
        {/each}
      </div>
    {/if}
  </div>
{/if}

<style>
  .upload-queue {
    position: fixed;
    bottom: 24px;
    right: 24px;
    width: 380px;
    background: var(--bg-primary);
    border: 1px solid var(--border-color);
    border-radius: var(--radius-lg);
    box-shadow: 0 8px 30px rgba(0,0,0,0.12);
    z-index: 1000;
    overflow: hidden;
  }

  .uq-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 12px 16px;
    cursor: pointer;
    user-select: none;
    border-bottom: 1px solid var(--border-color);
  }

  .upload-queue.collapsed .uq-header {
    border-bottom: none;
  }

  .uq-title {
    font-size: var(--font-size-sm);
    font-weight: 600;
    color: var(--text-primary);
  }

  .uq-header-actions {
    display: flex;
    gap: 4px;
  }

  .uq-btn {
    background: none;
    border: none;
    padding: 2px 6px;
    cursor: pointer;
    color: var(--text-tertiary);
    font-size: 11px;
    border-radius: var(--radius-sm);
  }
  .uq-btn:hover {
    color: var(--text-primary);
    background: var(--bg-tertiary);
  }

  .uq-list {
    max-height: 300px;
    overflow-y: auto;
  }

  .uq-item {
    padding: 8px 16px;
    border-bottom: 1px solid var(--border-color-light, var(--border-color));
  }
  .uq-item:last-child {
    border-bottom: none;
  }

  .uq-item-info {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 4px;
  }

  .uq-filename {
    font-size: var(--font-size-xs);
    color: var(--text-primary);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    flex: 1;
    min-width: 0;
  }

  .uq-size {
    font-size: 10px;
    color: var(--text-tertiary);
    white-space: nowrap;
  }

  .uq-savings {
    font-size: 10px;
    color: var(--color-success, #22c55e);
    white-space: nowrap;
    font-weight: 600;
  }

  .uq-item-status {
    display: flex;
    align-items: center;
    gap: 8px;
  }

  .uq-progress-bar {
    flex: 1;
    height: 4px;
    background: var(--bg-tertiary);
    border-radius: 2px;
    overflow: hidden;
  }

  .uq-progress-fill {
    height: 100%;
    background: var(--color-accent, #3b82f6);
    border-radius: 2px;
    transition: width 0.15s ease;
  }

  .uq-cancel {
    background: none;
    border: none;
    cursor: pointer;
    color: var(--text-tertiary);
    font-size: 11px;
    padding: 2px 4px;
    flex-shrink: 0;
  }
  .uq-cancel:hover {
    color: var(--color-danger, #ef4444);
  }

  .uq-pending {
    font-size: 10px;
    color: var(--text-tertiary);
    flex: 1;
  }

  .uq-status-icon {
    font-size: 12px;
    font-weight: 700;
  }
  .uq-status-icon.success {
    color: var(--color-success, #22c55e);
  }
  .uq-status-icon.fail {
    color: var(--color-danger, #ef4444);
  }

  .uq-error {
    font-size: 10px;
    color: var(--color-danger, #ef4444);
    margin-top: 2px;
  }
</style>
