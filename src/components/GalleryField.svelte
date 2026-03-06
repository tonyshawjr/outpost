<script>
  import MediaPicker from '$components/MediaPicker.svelte';

  let { value = '[]', onchange = () => {} } = $props();

  let photos = $state([]);
  let showPicker = $state(false);

  $effect(() => {
    try {
      const parsed = typeof value === 'string' ? JSON.parse(value) : value;
      photos = Array.isArray(parsed) ? parsed : [];
    } catch {
      photos = [];
    }
  });

  function handleSelect(file) {
    photos = [...photos, file.path];
    onchange(photos);
    showPicker = false;
  }

  function remove(index) {
    photos = photos.filter((_, i) => i !== index);
    onchange(photos);
  }

  function moveLeft(index) {
    if (index <= 0) return;
    const a = [...photos];
    [a[index - 1], a[index]] = [a[index], a[index - 1]];
    photos = a;
    onchange(photos);
  }

  function moveRight(index) {
    if (index >= photos.length - 1) return;
    const a = [...photos];
    [a[index], a[index + 1]] = [a[index + 1], a[index]];
    photos = a;
    onchange(photos);
  }
</script>

<div class="gf">
  <div class="gf-grid">
    {#each photos as src, i (i)}
      <div class="gf-item">
        <img class="gf-img" {src} alt="" />
        <div class="gf-actions">
          <button class="gf-btn" onclick={() => moveLeft(i)} disabled={i === 0} title="Move left">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg>
          </button>
          <button class="gf-btn" onclick={() => moveRight(i)} disabled={i === photos.length - 1} title="Move right">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
          </button>
          <button class="gf-btn gf-remove" onclick={() => remove(i)} title="Remove">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
          </button>
        </div>
      </div>
    {/each}

    <button class="gf-add" onclick={() => showPicker = true}>
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
      <span>Add photo</span>
    </button>
  </div>
</div>

{#if showPicker}
  <MediaPicker
    onselect={handleSelect}
    onclose={() => showPicker = false}
  />
{/if}

<style>
  .gf-grid {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    align-items: flex-start;
  }

  .gf-item {
    position: relative;
    width: 80px;
    height: 80px;
    border-radius: var(--radius-sm);
    overflow: visible;
    flex-shrink: 0;
  }

  .gf-img {
    width: 80px;
    height: 80px;
    object-fit: cover;
    border-radius: var(--radius-sm);
    border: 1px solid var(--border-primary);
    display: block;
  }

  .gf-actions {
    position: absolute;
    top: -8px;
    right: -8px;
    display: none;
    gap: 2px;
    background: var(--bg-primary);
    border: 1px solid var(--border-primary);
    border-radius: var(--radius-sm);
    padding: 2px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.12);
  }

  .gf-item:hover .gf-actions {
    display: flex;
  }

  .gf-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 22px;
    height: 22px;
    background: none;
    border: none;
    border-radius: 3px;
    cursor: pointer;
    color: var(--text-secondary);
    transition: background 0.1s, color 0.1s;
  }

  .gf-btn:hover {
    background: var(--bg-secondary);
    color: var(--text-primary);
  }

  .gf-btn:disabled {
    opacity: 0.3;
    cursor: default;
  }

  .gf-remove:hover {
    color: var(--danger);
  }

  .gf-add {
    width: 80px;
    height: 80px;
    border: 1px dashed var(--border-secondary);
    border-radius: var(--radius-sm);
    background: none;
    cursor: pointer;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 4px;
    color: var(--text-tertiary);
    font-size: 11px;
    transition: border-color 0.15s, color 0.15s;
  }

  .gf-add:hover {
    border-color: var(--text-secondary);
    color: var(--text-secondary);
  }
</style>
