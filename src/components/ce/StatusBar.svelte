<script>
  let { line = 1, col = 1, language = '', filepath = '', fileSize = 0 } = $props();

  function formatSize(bytes) {
    if (!bytes) return '';
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
    return (bytes / 1024 / 1024).toFixed(1) + ' MB';
  }
</script>

<div class="sb-bar">
  <div class="sb-left">
    <span class="sb-item">Ln {line}, Col {col}</span>
    {#if filepath}
      <span class="sb-sep">·</span>
      <span class="sb-item sb-path">{filepath}</span>
    {/if}
  </div>
  <div class="sb-right">
    {#if fileSize}
      <span class="sb-item">{formatSize(fileSize)}</span>
      <span class="sb-sep">·</span>
    {/if}
    {#if language}
      <span class="sb-item">{language}</span>
      <span class="sb-sep">·</span>
    {/if}
    <span class="sb-item">UTF-8</span>
    <span class="sb-sep">·</span>
    <span class="sb-item">2 spaces</span>
  </div>
</div>

<style>
  .sb-bar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    height: 24px;
    padding: 0 14px;
    flex-shrink: 0;
    border-top: 1px solid var(--ce-bar-border);
    font-size: 11px;
    color: var(--ce-muted);
    font-family: var(--font-mono);
    gap: 8px;
  }

  .sb-left, .sb-right {
    display: flex;
    align-items: center;
    gap: 6px;
    min-width: 0;
  }

  .sb-left { flex: 1; min-width: 0; overflow: hidden; }

  .sb-item { white-space: nowrap; }

  .sb-path {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    color: var(--ce-filepath);
  }

  .sb-sep { opacity: .4; }
</style>
