<script>
  import { X, Download, Loader2, Sparkles, ArrowUp, Square } from 'lucide-svelte';
  import { generateImportSection } from '$lib/builder-ai.js';

  let { editor, parentId = null, onclose, onimported } = $props();

  let tab = $state('html');
  let html = $state('');
  let css = $state('');
  let js = $state('');
  let busy = $state(false);
  let error = $state('');
  let dialogEl = $state(null);

  let aiPrompt = $state('');
  let aiBusy = $state(false);
  let aiError = $state('');
  let aiController = null;

  const tabs = [
    { key: 'html', label: 'HTML' },
    { key: 'css', label: 'CSS' },
    { key: 'js', label: 'JavaScript' },
  ];

  let canImport = $derived(!busy && !aiBusy && (html.trim() !== '' || css.trim() !== '' || js.trim() !== ''));

  async function generate() {
    const prompt = aiPrompt.trim();
    if (!prompt || aiBusy) return;
    aiBusy = true;
    aiError = '';
    aiController = new AbortController();
    try {
      await generateImportSection({
        editor,
        prompt,
        signal: aiController.signal,
        onSection: ({ html: h, css: c, js: j }) => {
          if (h) html = h;
          if (c) css = c;
          js = j || '';
          tab = 'html';
        },
        onError: (message) => { aiError = message; },
      });
    } catch (e) {
      if (e?.name !== 'AbortError') aiError = e?.message || 'Generation failed.';
    } finally {
      aiBusy = false;
      aiController = null;
    }
  }

  function stopGenerate() {
    aiController?.abort();
    aiBusy = false;
  }

  async function run() {
    if (!canImport) return;
    busy = true;
    error = '';
    try {
      const res = await editor.importSection(html, css, js, parentId);
      onimported?.(res);
      onclose?.();
    } catch (e) {
      error = e?.message || 'Import failed.';
    } finally {
      busy = false;
    }
  }

  function onKey(e) {
    if (e.key === 'Escape') { e.stopPropagation(); onclose?.(); }
  }

  $effect(() => {
    dialogEl?.querySelector('textarea')?.focus();
  });
</script>

<div class="overlay" role="presentation" onclick={(e) => { if (e.target === e.currentTarget) onclose?.(); }}>
  <div
    class="dialog"
    bind:this={dialogEl}
    role="dialog"
    aria-modal="true"
    aria-labelledby="si-title"
    onkeydown={onKey}
  >
    <header class="head">
      <div>
        <h2 id="si-title">Import section</h2>
        <p class="sub">Paste HTML, CSS and JavaScript. Outpost explodes the markup into editable elements, merges the styles, and drops the section onto the canvas.</p>
      </div>
      <button class="close" onclick={() => onclose?.()} aria-label="Close">
        <X size={18} aria-hidden="true" />
      </button>
    </header>

    <div class="ai-bar">
      <label class="ai-label" for="si-ai">
        <Sparkles size={14} aria-hidden="true" />
        <span>Generate with AI</span>
      </label>
      <div class="ai-row">
        <input
          id="si-ai"
          class="ai-input"
          type="text"
          bind:value={aiPrompt}
          placeholder="Describe a section — e.g. a pricing CTA with three tiers"
          disabled={aiBusy}
          onkeydown={(e) => { if (e.key === 'Enter') { e.preventDefault(); generate(); } }}
        />
        {#if aiBusy}
          <button class="ai-go stop" onclick={stopGenerate} aria-label="Stop generating"><Square size={14} aria-hidden="true" /></button>
        {:else}
          <button class="ai-go" onclick={generate} disabled={!aiPrompt.trim()} aria-label="Generate section"><ArrowUp size={15} aria-hidden="true" /></button>
        {/if}
      </div>
      {#if aiBusy}
        <p class="ai-status" role="status">Generating a section from your provider key…</p>
      {:else if aiError}
        <p class="ai-status err" role="alert">{aiError}</p>
      {/if}
    </div>

    <div class="tabs" role="tablist" aria-label="Section source">
      {#each tabs as t (t.key)}
        <button
          role="tab"
          aria-selected={tab === t.key}
          class:on={tab === t.key}
          onclick={() => (tab = t.key)}
        >
          {t.label}
          {#if (t.key === 'html' && html.trim()) || (t.key === 'css' && css.trim()) || (t.key === 'js' && js.trim())}
            <span class="dot" aria-hidden="true"></span>
          {/if}
        </button>
      {/each}
    </div>

    <div class="editor">
      {#if tab === 'html'}
        <textarea bind:value={html} spellcheck="false" placeholder="<section class=&quot;hero&quot;> … </section>" aria-label="HTML"></textarea>
      {:else if tab === 'css'}
        <textarea bind:value={css} spellcheck="false" placeholder=".hero &lbrace; padding: 4rem; &rbrace;" aria-label="CSS"></textarea>
      {:else}
        <textarea bind:value={js} spellcheck="false" placeholder="document.querySelector('.hero') …" aria-label="JavaScript"></textarea>
      {/if}
    </div>

    {#if error}
      <p class="error" role="alert">{error}</p>
    {/if}

    <footer class="foot">
      <p class="note">JavaScript is appended to this page's script file and runs on the published page only.</p>
      <div class="actions">
        <button class="ghost" onclick={() => onclose?.()} disabled={busy}>Cancel</button>
        <button class="primary" onclick={run} disabled={!canImport}>
          {#if busy}
            <Loader2 size={15} class="spin" aria-hidden="true" />
            <span>Importing…</span>
          {:else}
            <Download size={15} aria-hidden="true" />
            <span>Import section</span>
          {/if}
        </button>
      </div>
    </footer>
  </div>
</div>

<style>
  .overlay {
    position: fixed;
    inset: 0;
    z-index: 200;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 24px;
    background: rgba(0, 0, 0, 0.5);
  }

  .dialog {
    display: flex;
    flex-direction: column;
    width: 100%;
    max-width: 760px;
    max-height: 90vh;
    background: var(--raised);
    border: 1px solid var(--border);
    border-radius: 14px;
    box-shadow: var(--shadow-lg, 0 24px 60px rgba(0, 0, 0, 0.35));
    overflow: hidden;
  }

  .head {
    display: flex;
    align-items: flex-start;
    gap: 16px;
    padding: 18px 20px 14px;
    border-bottom: 1px solid var(--border);
  }
  .head h2 { margin: 0 0 4px; font-size: 16px; font-weight: 600; color: var(--text); }
  .sub { margin: 0; font-size: 12.5px; color: var(--dim); line-height: 1.5; max-width: 60ch; }
  .close {
    margin-left: auto;
    display: inline-flex;
    padding: 6px;
    border: none;
    border-radius: 7px;
    background: transparent;
    color: var(--sec);
    cursor: pointer;
    flex-shrink: 0;
  }
  .close:hover { background: var(--hover); color: var(--text); }
  .close:focus-visible { outline: 2px solid var(--purple); outline-offset: 1px; }

  .ai-bar { padding: 14px 16px 4px; flex-shrink: 0; }
  .ai-label {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: var(--dim);
    margin-bottom: 7px;
  }
  .ai-label :global(svg) { color: var(--purple-soft, var(--purple)); }
  .ai-row {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 6px 6px 6px 12px;
    border: 1px solid var(--border);
    border-radius: 10px;
    background: var(--bg);
  }
  .ai-row:focus-within { border-color: var(--purple); }
  .ai-input {
    flex: 1;
    min-width: 0;
    border: none;
    background: transparent;
    color: var(--text);
    font-family: inherit;
    font-size: 13px;
  }
  .ai-input:focus { outline: none; }
  .ai-input:disabled { opacity: 0.6; }
  .ai-go {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 30px;
    height: 30px;
    flex-shrink: 0;
    border: none;
    border-radius: 8px;
    background: var(--purple);
    color: #fff;
    cursor: pointer;
  }
  .ai-go:hover:not(:disabled) { background: var(--accent-hover, var(--purple)); }
  .ai-go:disabled { opacity: 0.4; cursor: default; }
  .ai-go:focus-visible { outline: 2px solid var(--purple-soft, var(--purple)); outline-offset: 2px; }
  .ai-go.stop { background: var(--red); }
  .ai-status { margin: 8px 2px 0; font-size: 11.5px; color: var(--dim); line-height: 1.4; }
  .ai-status.err { color: var(--red); }

  .tabs { display: flex; gap: 4px; padding: 12px 16px 0; flex-shrink: 0; }
  .tabs button {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 7px 12px;
    border: none;
    border-radius: 7px 7px 0 0;
    background: transparent;
    color: var(--sec);
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
  }
  .tabs button.on { background: var(--hover); color: var(--text); }
  .tabs button:focus-visible { outline: 2px solid var(--purple); outline-offset: 1px; }
  .dot { width: 6px; height: 6px; border-radius: 50%; background: var(--purple); }

  .editor { padding: 0 16px; min-height: 0; flex: 1; display: flex; }
  textarea {
    flex: 1;
    width: 100%;
    min-height: 300px;
    padding: 14px;
    border: 1px solid var(--border);
    border-radius: 0 8px 8px 8px;
    background: var(--bg);
    color: var(--text);
    font-family: var(--font-mono, ui-monospace, SFMono-Regular, Menlo, monospace);
    font-size: 12.5px;
    line-height: 1.6;
    resize: none;
    tab-size: 2;
  }
  textarea:focus-visible { outline: none; border-color: var(--purple); }

  .error {
    margin: 12px 16px 0;
    padding: 9px 12px;
    border-radius: 8px;
    background: var(--red-bg, rgba(220, 60, 60, 0.12));
    color: var(--red, #d64545);
    font-size: 12.5px;
  }

  .foot {
    display: flex;
    align-items: center;
    gap: 16px;
    padding: 14px 20px 16px;
    border-top: 1px solid var(--border);
    margin-top: 14px;
  }
  .note { margin: 0; font-size: 11.5px; color: var(--dim); line-height: 1.45; }
  .actions { margin-left: auto; display: flex; gap: 8px; flex-shrink: 0; }

  .ghost {
    padding: 8px 14px;
    border: 1px solid var(--border);
    border-radius: 8px;
    background: transparent;
    color: var(--sec);
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
  }
  .ghost:hover:not(:disabled) { background: var(--hover); color: var(--text); }
  .ghost:disabled { opacity: 0.5; cursor: default; }
  .ghost:focus-visible { outline: 2px solid var(--purple); outline-offset: 1px; }

  .primary {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 16px;
    border: none;
    border-radius: 8px;
    background: var(--purple);
    color: #fff;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
  }
  .primary:hover:not(:disabled) { background: var(--accent-hover); }
  .primary:disabled { opacity: 0.45; cursor: default; }
  .primary:focus-visible { outline: 2px solid var(--purple-soft); outline-offset: 2px; }
  .primary :global(.spin) { animation: si-spin 0.8s linear infinite; }

  @keyframes si-spin { to { transform: rotate(360deg); } }
</style>
