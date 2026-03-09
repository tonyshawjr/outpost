<script>
  let { tabs = [], activeIndex = -1, onSwitch, onClose, onPin } = $props();

  function handleClose(e, index) {
    e.stopPropagation();
    onClose(index);
  }

  function fileExt(name) {
    return name.split('.').pop().toLowerCase();
  }

  function tabBadgeClass(name) {
    return 'et-dot et-dot-' + fileExt(name);
  }
</script>

<div class="et-bar">
  {#each tabs as tab, i}
    {@const dirty = tab.content !== tab.originalContent}
    <button
      class="et-tab"
      class:active={i === activeIndex}
      class:preview={tab.preview}
      onclick={() => onSwitch(i)}
      ondblclick={() => { if (onPin) onPin(i); }}
      title={tab.path}
    >
      {#if dirty}
        <span class="et-dirty" title="Unsaved"></span>
      {:else}
        <span class={tabBadgeClass(tab.name)}></span>
      {/if}
      <span class="et-name">{tab.name}</span>
      <span class="et-close" onclick={(e) => handleClose(e, i)} title="Close">
        <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      </span>
    </button>
  {/each}
</div>

<style>
  .et-bar {
    display: flex;
    align-items: stretch;
    overflow-x: auto;
    overflow-y: hidden;
    flex-shrink: 0;
    scrollbar-width: none;
  }
  .et-bar::-webkit-scrollbar { display: none; }

  .et-tab {
    display: flex;
    align-items: center;
    gap: 6px;
    height: 36px;
    padding: 0 10px 0 12px;
    background: none;
    border: none;
    border-right: 1px solid var(--ce-bar-border);
    font-family: var(--font-sans);
    font-size: 12px;
    color: var(--ce-filepath);
    cursor: pointer;
    white-space: nowrap;
    flex-shrink: 0;
    transition: background var(--transition-fast), color var(--transition-fast);
    position: relative;
  }
  .et-tab.preview .et-name { font-style: italic; }
  .et-tab:hover { background: var(--ce-btn-hover-bg); color: var(--ce-text); }
  .et-tab.active {
    background: var(--ce-btn-hover-bg);
    color: var(--ce-text);
  }
  .et-tab.active::after {
    content: '';
    position: absolute;
    bottom: 0;
    left: 0;
    right: 0;
    height: 2px;
    background: var(--forest);
    border-radius: 1px 1px 0 0;
  }

  .et-dirty {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: #f59e0b;
    flex-shrink: 0;
  }

  /* Color dots by file type */
  .et-dot {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    flex-shrink: 0;
    background: #737373;
  }
  .et-dot-html, .et-dot-htm { background: #e34c26; }
  .et-dot-css  { background: #1572b6; }
  .et-dot-js   { background: #f7df1e; }
  .et-dot-json { background: #f7df1e; }
  .et-dot-php  { background: #777bb3; }
  .et-dot-md   { background: #083fa1; }

  .et-name {
    max-width: 140px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .et-close {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 18px;
    height: 18px;
    border-radius: 3px;
    color: var(--ce-muted);
    opacity: 0;
    transition: opacity var(--transition-fast), background var(--transition-fast);
    flex-shrink: 0;
  }
  .et-tab:hover .et-close,
  .et-tab.active .et-close { opacity: 1; }
  .et-close:hover { background: var(--ce-btn-hover-bg); color: var(--ce-text); }
</style>
