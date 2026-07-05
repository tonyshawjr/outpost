<script>
  import { X, Copy, Check, Loader2 } from 'lucide-svelte';
  import { nodes as nodesApi } from '$lib/api.js';

  let { pageId, onclose } = $props();

  let html = $state('');
  let loading = $state(true);
  let error = $state('');
  let copied = $state(false);
  let dialogEl = $state(null);

  $effect(() => {
    dialogEl?.focus();
    load();
  });

  async function load() {
    if (!pageId) { error = 'No page loaded.'; loading = false; return; }
    try {
      const res = await nodesApi.render(pageId);
      html = res.html || '';
    } catch (e) {
      error = e?.message || 'Could not render the page.';
    } finally {
      loading = false;
    }
  }

  function copy() {
    navigator.clipboard?.writeText(html).then(() => {
      copied = true;
      setTimeout(() => (copied = false), 1500);
    });
  }

  function onKey(e) {
    if (e.key === 'Escape') { e.stopPropagation(); onclose?.(); }
  }
</script>

<div class="overlay" role="presentation" onclick={(e) => { if (e.target === e.currentTarget) onclose?.(); }}>
  <div class="dialog" bind:this={dialogEl} tabindex="-1" role="dialog" aria-modal="true" aria-labelledby="cv-title" onkeydown={onKey}>
    <header class="head">
      <h2 id="cv-title">Page HTML</h2>
      <div class="actions">
        <button class="ghost" onclick={copy} disabled={loading || !!error}>
          {#if copied}<Check size={14} aria-hidden="true" /><span>Copied</span>
          {:else}<Copy size={14} aria-hidden="true" /><span>Copy</span>{/if}
        </button>
        <button class="close" onclick={() => onclose?.()} aria-label="Close code view">
          <X size={18} aria-hidden="true" />
        </button>
      </div>
    </header>
    <div class="body">
      {#if loading}
        <div class="state"><Loader2 size={18} class="spin" aria-hidden="true" /><span>Rendering…</span></div>
      {:else if error}
        <p class="state err" role="alert">{error}</p>
      {:else}
        <pre><code>{html}</code></pre>
      {/if}
    </div>
    <p class="note">The static HTML this page bakes to. Dynamic fields fill from managed content at request time.</p>
  </div>
</div>

<style>
  .overlay { position: fixed; inset: 0; z-index: 210; display: flex; align-items: center; justify-content: center; padding: 24px; background: rgba(0, 0, 0, 0.5); }
  .dialog { display: flex; flex-direction: column; width: 100%; max-width: 820px; max-height: 86vh; background: var(--raised); border: 1px solid var(--border); border-radius: 14px; box-shadow: var(--shadow-lg, 0 24px 60px rgba(0, 0, 0, 0.35)); overflow: hidden; }
  .dialog:focus-visible { outline: none; }
  .head { display: flex; align-items: center; gap: 12px; padding: 14px 16px; border-bottom: 1px solid var(--border); }
  .head h2 { margin: 0; font-size: 15px; font-weight: 600; color: var(--text); }
  .actions { margin-left: auto; display: flex; align-items: center; gap: 6px; }
  .ghost { display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; border: 1px solid var(--border); border-radius: 8px; background: transparent; color: var(--sec); font-size: 12.5px; font-weight: 600; cursor: pointer; }
  .ghost:hover:not(:disabled) { background: var(--hover); color: var(--text); }
  .ghost:disabled { opacity: 0.5; cursor: default; }
  .ghost:focus-visible { outline: 2px solid var(--purple); outline-offset: 1px; }
  .close { display: inline-flex; padding: 6px; border: none; border-radius: 7px; background: transparent; color: var(--sec); cursor: pointer; }
  .close:hover { background: var(--hover); color: var(--text); }
  .close:focus-visible { outline: 2px solid var(--purple); outline-offset: 1px; }
  .body { flex: 1; min-height: 0; overflow: auto; background: var(--bg); }
  pre { margin: 0; padding: 16px; }
  code { font-family: var(--font-mono, ui-monospace, SFMono-Regular, Menlo, monospace); font-size: 12.5px; line-height: 1.6; color: var(--text); white-space: pre-wrap; word-break: break-word; }
  .state { display: flex; align-items: center; justify-content: center; gap: 10px; padding: 40px; color: var(--dim); font-size: 13px; }
  .state.err { color: var(--red, #d64545); }
  .state :global(.spin) { animation: cv-spin 0.8s linear infinite; }
  .note { margin: 0; padding: 10px 16px; border-top: 1px solid var(--border); font-size: 11.5px; color: var(--dim); }
  @keyframes cv-spin { to { transform: rotate(360deg); } }
</style>
