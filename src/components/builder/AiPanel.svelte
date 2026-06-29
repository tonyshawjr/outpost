<script>
  import { tick } from 'svelte';
  import { Sparkles, ArrowUp, Square, X, Wand2 } from 'lucide-svelte';
  import { runBuilderAgent } from '$lib/builder-ai.js';

  let { editor, onclose } = $props();

  let messages = $state([]);
  let input = $state('');
  let streaming = $state(false);
  let assistantIdx = -1;
  let abortController = null;
  let transcriptEl = $state(null);
  let inputEl = $state(null);

  const examples = [
    'Add a hero section with a headline, subtext, and a call-to-action button',
    'Add a three-column feature grid',
    'Add a centered contact call-to-action',
  ];

  $effect(() => {
    if (inputEl) inputEl.focus();
  });

  async function scrollToEnd() {
    await tick();
    if (transcriptEl) transcriptEl.scrollTop = transcriptEl.scrollHeight;
  }

  function updateAssistant(updater) {
    messages = messages.map((m, i) => (i === assistantIdx ? updater({ ...m }) : m));
  }

  async function send(text) {
    const prompt = (text ?? input).trim();
    if (!prompt || streaming) return;
    input = '';
    streaming = true;

    messages = [...messages, { role: 'user', content: prompt }];
    messages = [...messages, { role: 'assistant', content: '', edits: [] }];
    assistantIdx = messages.length - 1;
    scrollToEnd();

    const payload = messages
      .filter((m) => m.content || m.role === 'user')
      .map((m) => ({ role: m.role, content: m.content }));

    abortController = new AbortController();

    try {
      await runBuilderAgent({
        editor,
        messages: payload,
        signal: abortController.signal,
        onText: (chunk) => { updateAssistant((m) => ({ ...m, content: m.content + chunk })); scrollToEnd(); },
        onOps: (ops) => {
          const summary = editor.applyAiOps(ops);
          updateAssistant((m) => ({ ...m, edits: [...(m.edits || []), summary] }));
          scrollToEnd();
        },
        onError: (message) => {
          updateAssistant((m) => ({ ...m, error: message }));
          scrollToEnd();
        },
      });
    } catch (e) {
      if (e.name !== 'AbortError') updateAssistant((m) => ({ ...m, error: e.message || 'Connection error' }));
    } finally {
      streaming = false;
      abortController = null;
    }
  }

  function stop() {
    abortController?.abort();
    streaming = false;
  }

  function onKeydown(e) {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      send();
    }
  }

  function editTotal(edits) {
    return (edits || []).reduce((sum, s) => sum + s.inserted + s.updated + s.removed + s.moved + s.classes + s.fields, 0);
  }
</script>

<aside class="ai-panel" aria-label="Build with AI">
  <header class="ai-head">
    <span class="ai-title"><Sparkles size={15} aria-hidden="true" /> Build with AI</span>
    <button class="ai-close" onclick={onclose} aria-label="Close AI panel">
      <X size={16} aria-hidden="true" />
    </button>
  </header>

  <div class="ai-transcript" bind:this={transcriptEl} role="log" aria-live="polite" aria-label="Conversation">
    {#if messages.length === 0}
      <div class="ai-intro">
        <p class="ai-intro-lead">Describe what you want and I'll build it on the page.</p>
        <ul class="ai-examples">
          {#each examples as ex (ex)}
            <li>
              <button class="ai-example" onclick={() => send(ex)}>
                <Wand2 size={13} aria-hidden="true" />
                <span>{ex}</span>
              </button>
            </li>
          {/each}
        </ul>
      </div>
    {/if}

    {#each messages as m, i (i)}
      <div class="ai-msg {m.role}">
        {#if m.role === 'user'}
          <p class="ai-bubble">{m.content}</p>
        {:else}
          {#if m.content}
            <p class="ai-text">{m.content}</p>
          {/if}
          {#if editTotal(m.edits) > 0}
            <p class="ai-applied" role="status">
              Applied {editTotal(m.edits)} {editTotal(m.edits) === 1 ? 'edit' : 'edits'} to the page
            </p>
          {/if}
          {#if m.error}
            <p class="ai-error">{m.error}</p>
          {:else if streaming && i === assistantIdx && !m.content}
            <p class="ai-thinking">Building…</p>
          {/if}
        {/if}
      </div>
    {/each}
  </div>

  <form class="ai-compose" onsubmit={(e) => { e.preventDefault(); send(); }}>
    <label class="ai-input-label" for="ai-input">Describe what to build</label>
    <div class="ai-input-row">
      <textarea
        id="ai-input"
        bind:this={inputEl}
        bind:value={input}
        onkeydown={onKeydown}
        rows="2"
        placeholder="Describe a section, change, or layout…"
        disabled={streaming}
      ></textarea>
      {#if streaming}
        <button type="button" class="ai-send stop" onclick={stop} aria-label="Stop generating">
          <Square size={15} aria-hidden="true" />
        </button>
      {:else}
        <button type="submit" class="ai-send" disabled={!input.trim()} aria-label="Send">
          <ArrowUp size={16} aria-hidden="true" />
        </button>
      {/if}
    </div>
    <p class="ai-hint">Edits apply live and are undoable with ⌘Z. Uses your provider key from Settings → Integrations.</p>
  </form>
</aside>

<style>
  .ai-panel {
    width: 340px;
    flex-shrink: 0;
    display: flex;
    flex-direction: column;
    min-height: 0;
    border-left: 1px solid var(--border);
    background: var(--raised);
  }

  .ai-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 12px 14px;
    border-bottom: 1px solid var(--border);
    flex-shrink: 0;
  }
  .ai-title {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    font-size: 13px;
    font-weight: 600;
    color: var(--text);
  }
  .ai-title :global(svg) { color: var(--purple-soft, var(--purple)); }
  .ai-close {
    display: inline-flex;
    padding: 5px;
    border: none;
    border-radius: 6px;
    background: transparent;
    color: var(--sec);
    cursor: pointer;
  }
  .ai-close:hover { background: var(--hover); color: var(--text); }
  .ai-close:focus-visible { outline: 2px solid var(--purple); outline-offset: 1px; }

  .ai-transcript {
    flex: 1;
    min-height: 0;
    overflow-y: auto;
    padding: 14px;
    display: flex;
    flex-direction: column;
    gap: 14px;
  }

  .ai-intro-lead {
    font-size: 13px;
    color: var(--sec);
    line-height: 1.5;
    margin: 0 0 12px;
  }
  .ai-examples { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 7px; }
  .ai-example {
    display: flex;
    align-items: center;
    gap: 8px;
    width: 100%;
    padding: 9px 11px;
    border: 1px solid var(--border);
    border-radius: 8px;
    background: transparent;
    color: var(--sec);
    font-size: 12.5px;
    text-align: left;
    line-height: 1.4;
    cursor: pointer;
  }
  .ai-example:hover { background: var(--hover); color: var(--text); }
  .ai-example:focus-visible { outline: 2px solid var(--purple); outline-offset: 1px; }
  .ai-example :global(svg) { flex-shrink: 0; color: var(--purple-soft, var(--purple)); }

  .ai-msg { display: flex; flex-direction: column; gap: 6px; }
  .ai-msg.user { align-items: flex-end; }

  .ai-bubble {
    max-width: 88%;
    margin: 0;
    padding: 8px 12px;
    border-radius: 12px 12px 3px 12px;
    background: var(--purple);
    color: #fff;
    font-size: 13px;
    line-height: 1.5;
    white-space: pre-wrap;
    word-break: break-word;
  }
  .ai-text {
    margin: 0;
    font-size: 13px;
    line-height: 1.55;
    color: var(--text);
    white-space: pre-wrap;
    word-break: break-word;
  }
  .ai-applied {
    margin: 0;
    align-self: flex-start;
    padding: 4px 9px;
    border-radius: 6px;
    background: var(--purple-bg, var(--hover));
    color: var(--purple-soft, var(--purple));
    font-size: 11.5px;
    font-weight: 600;
  }
  .ai-thinking { margin: 0; font-size: 12.5px; color: var(--dim); }
  .ai-error {
    margin: 0;
    font-size: 12.5px;
    color: var(--red);
    line-height: 1.5;
  }

  .ai-compose {
    flex-shrink: 0;
    padding: 12px 14px 14px;
    border-top: 1px solid var(--border);
  }
  .ai-input-label {
    position: absolute;
    width: 1px; height: 1px;
    padding: 0; margin: -1px;
    overflow: hidden;
    clip: rect(0 0 0 0);
    white-space: nowrap;
    border: 0;
  }
  .ai-input-row {
    display: flex;
    align-items: flex-end;
    gap: 8px;
    padding: 8px;
    border: 1px solid var(--border);
    border-radius: 10px;
    background: var(--bg);
  }
  .ai-input-row:focus-within { border-color: var(--purple); }
  .ai-input-row textarea {
    flex: 1;
    min-width: 0;
    border: none;
    background: transparent;
    color: var(--text);
    font-family: inherit;
    font-size: 13px;
    line-height: 1.5;
    resize: none;
    max-height: 160px;
  }
  .ai-input-row textarea:focus { outline: none; }
  .ai-input-row textarea:disabled { opacity: 0.6; }

  .ai-send {
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
  .ai-send:hover:not(:disabled) { background: var(--accent-hover, var(--purple)); }
  .ai-send:disabled { opacity: 0.4; cursor: default; }
  .ai-send:focus-visible { outline: 2px solid var(--purple-soft, var(--purple)); outline-offset: 2px; }
  .ai-send.stop { background: var(--red); }

  .ai-hint {
    margin: 8px 2px 0;
    font-size: 11px;
    color: var(--dim);
    line-height: 1.45;
  }
</style>
