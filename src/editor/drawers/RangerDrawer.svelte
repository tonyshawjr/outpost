<script>
  import { streamChat, request, getPagePath } from '../api.js';

  const ctx = window.__OUTPOST_EDITOR__ || window.__OPE || {};

  // State
  let messages = $state([]);
  let conversationId = $state(null);
  let streaming = $state(false);
  let abortController = $state(null);
  let provider = $state('claude');
  let model = $state('');
  let input = $state('');
  let messagesEnd = $state(null);
  let messagesContainer = $state(null);
  let textareaEl = $state(null);
  let showScrollBtn = $state(false);
  let prevMessageCount = $state(0);

  // Available providers
  let providers = $state([]);
  let loadingProviders = $state(true);

  const pageName = ctx.pageName || ctx.pagePath || window.location.pathname;

  $effect(() => {
    loadProviders();
  });

  async function loadProviders() {
    loadingProviders = true;
    try {
      const data = await request('ranger/providers');
      providers = data.providers || [];
      if (providers.length > 0 && !providers.find(p => p.id === provider)) {
        provider = providers[0].id;
      }
    } catch {
      providers = [{ id: 'claude', name: 'Claude' }];
    } finally {
      loadingProviders = false;
    }
  }

  function isNearBottom() {
    if (!messagesContainer) return true;
    const { scrollTop, scrollHeight, clientHeight } = messagesContainer;
    return scrollHeight - scrollTop - clientHeight < 80;
  }

  function scrollToBottom(smooth = true) {
    if (messagesEnd) {
      messagesEnd.scrollIntoView({ behavior: smooth ? 'smooth' : 'instant' });
    }
    showScrollBtn = false;
  }

  function handleBodyScroll() {
    if (!messagesContainer) return;
    showScrollBtn = !isNearBottom() && messages.length > 0;
  }

  $effect(() => {
    if (messages.length && messagesEnd) {
      if (messages.length !== prevMessageCount || isNearBottom()) {
        scrollToBottom();
      } else {
        showScrollBtn = true;
      }
      prevMessageCount = messages.length;
    }
  });

  $effect(() => {
    const _ = input;
    if (textareaEl) {
      textareaEl.style.height = 'auto';
      textareaEl.style.height = Math.min(textareaEl.scrollHeight, 120) + 'px';
    }
  });

  function stopStreaming() {
    if (abortController) {
      abortController.abort();
      abortController = null;
    }
    streaming = false;
  }

  function newChat() {
    messages = [];
    conversationId = null;
  }

  async function sendMessage() {
    if (!input.trim() || streaming) return;

    const userMsg = input.trim();
    input = '';

    messages = [...messages, { role: 'user', content: userMsg }];
    streaming = true;

    let assistantIdx = -1;
    let lastEventWasToolResult = false;

    function ensureAssistantMessage() {
      if (assistantIdx === -1 || lastEventWasToolResult) {
        messages = [...messages, { role: 'assistant', content: '', tool_calls: [] }];
        assistantIdx = messages.length - 1;
        lastEventWasToolResult = false;
      }
    }

    function updateAssistant(updater) {
      messages = messages.map((m, i) => i === assistantIdx ? updater({ ...m }) : m);
    }

    const controller = new AbortController();
    abortController = controller;

    try {
      const contextMsg = `[Context: The user is editing the page "${pageName}" at path "${getPagePath()}"]`;
      const fullMessage = conversationId ? userMsg : contextMsg + '\n\n' + userMsg;

      const res = await streamChat({
        message: fullMessage,
        conversation_id: conversationId,
        provider,
        model,
      }, controller.signal);

      const reader = res.body.getReader();
      const decoder = new TextDecoder();
      let buffer = '';

      while (true) {
        const { done, value } = await reader.read();
        if (done) break;

        buffer += decoder.decode(value, { stream: true });
        const lines = buffer.split('\n');
        buffer = lines.pop();

        for (const line of lines) {
          if (!line.startsWith('data: ')) continue;
          const data = line.slice(6);
          if (data === '[DONE]') continue;

          try {
            const event = JSON.parse(data);
            if (event.type === 'text') {
              ensureAssistantMessage();
              updateAssistant(m => ({ ...m, content: m.content + event.content }));
            } else if (event.type === 'tool_use' || event.type === 'tool_call') {
              ensureAssistantMessage();
              updateAssistant(m => ({
                ...m,
                tool_calls: [...(m.tool_calls || []), {
                  name: event.name,
                  input: event.input,
                  status: 'running',
                }],
              }));
            } else if (event.type === 'tool_result') {
              updateAssistant(m => ({
                ...m,
                tool_calls: (m.tool_calls || []).map(tc =>
                  tc.name === event.name && tc.status === 'running'
                    ? { ...tc, result: event.result, status: 'done' }
                    : tc
                ),
              }));
              lastEventWasToolResult = true;
            } else if (event.type === 'frontend_action') {
              if (event.action === 'refresh_page') {
                window.location.reload();
              }
            } else if (event.type === 'conversation_id') {
              conversationId = event.id;
            } else if (event.type === 'done') {
              if (event.conversation_id) conversationId = event.conversation_id;
            } else if (event.type === 'error') {
              ensureAssistantMessage();
              updateAssistant(m => ({
                ...m,
                content: m.content + `\n\nError: ${event.message}`,
                tool_calls: (m.tool_calls || []).map(tc =>
                  tc.status === 'running' ? { ...tc, status: 'error' } : tc
                ),
              }));
            }
          } catch (parseErr) {
            // skip malformed SSE lines
          }
        }
      }
    } catch (err) {
      ensureAssistantMessage();
      if (err.name === 'AbortError') {
        updateAssistant(m => ({ ...m, content: m.content + (m.content ? '\n\n' : '') + '*Stopped.*' }));
      } else {
        updateAssistant(m => ({ ...m, content: m.content + `\n\nConnection error: ${err.message}` }));
      }
    } finally {
      streaming = false;
      abortController = null;
    }
  }

  function handleKeydown(e) {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      sendMessage();
    }
  }

  function renderText(text) {
    if (!text) return '';
    return text
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
      .replace(/\*(.+?)\*/g, '<em>$1</em>')
      .replace(/`([^`]+)`/g, '<code class="ope-ranger-code">$1</code>')
      .replace(/\n/g, '<br>');
  }
</script>

<div class="ope-ranger-drawer">
  <!-- Provider selector -->
  <div class="ope-ranger-toolbar">
    <select class="ope-ranger-provider" bind:value={provider}>
      {#each providers as p}
        <option value={p.id}>{p.name}</option>
      {/each}
    </select>
    {#if conversationId}
      <button class="ope-ranger-new-btn" onclick={newChat} title="New chat">
        <svg width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5">
          <line x1="8" y1="3" x2="8" y2="13"/><line x1="3" y1="8" x2="13" y2="8"/>
        </svg>
      </button>
    {/if}
  </div>

  <!-- Messages -->
  <div class="ope-ranger-messages" bind:this={messagesContainer} onscroll={handleBodyScroll}>
    {#if messages.length === 0}
      <div class="ope-ranger-empty">
        <svg class="ope-ranger-empty-icon" viewBox="0 0 24 24" fill="none">
          <path d="M10 2L11.5 7.5L17 9L11.5 10.5L10 16L8.5 10.5L3 9L8.5 7.5L10 2Z" fill="#D1D5DB"/>
          <path d="M18 12L19 15L22 16L19 17L18 20L17 17L14 16L17 15L18 12Z" fill="#E5E7EB"/>
        </svg>
        <p class="ope-ranger-empty-title">Ask Ranger anything</p>
        <p class="ope-ranger-empty-sub">Editing <strong>{pageName}</strong></p>
      </div>
    {:else}
      {#each messages as msg, i}
        {#if msg.role === 'user'}
          <div class="ope-ranger-msg ope-ranger-msg-user">
            <div class="ope-ranger-msg-content">{msg.content}</div>
          </div>
        {:else}
          <div class="ope-ranger-msg ope-ranger-msg-assistant">
            {#if msg.content}
              <div class="ope-ranger-msg-content">{@html renderText(msg.content)}</div>
            {/if}
            {#if msg.tool_calls?.length}
              <div class="ope-ranger-tools">
                {#each msg.tool_calls as tc}
                  <div class="ope-ranger-tool" class:ope-ranger-tool-running={tc.status === 'running'}>
                    <div class="ope-ranger-tool-header">
                      {#if tc.status === 'running'}
                        <span class="ope-ranger-tool-spinner"></span>
                      {:else if tc.status === 'done'}
                        <svg width="12" height="12" viewBox="0 0 16 16" fill="none" stroke="#10B981" stroke-width="2"><polyline points="3 8 6.5 11.5 13 5"/></svg>
                      {:else}
                        <svg width="12" height="12" viewBox="0 0 16 16" fill="none" stroke="#EF4444" stroke-width="2"><line x1="4" y1="4" x2="12" y2="12"/><line x1="12" y1="4" x2="4" y2="12"/></svg>
                      {/if}
                      <span class="ope-ranger-tool-name">{tc.name?.replace(/_/g, ' ')}</span>
                    </div>
                  </div>
                {/each}
              </div>
            {/if}
          </div>
        {/if}
      {/each}
    {/if}
    <div bind:this={messagesEnd}></div>
  </div>

  <!-- Scroll to bottom -->
  {#if showScrollBtn}
    <button class="ope-ranger-scroll-btn" onclick={() => scrollToBottom()}>
      <svg width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2">
        <path d="M8 3v10M4 9l4 4 4-4"/>
      </svg>
    </button>
  {/if}

  <!-- Input -->
  <div class="ope-ranger-input-wrap">
    <textarea
      class="ope-ranger-input"
      placeholder="Ask Ranger..."
      bind:value={input}
      bind:this={textareaEl}
      onkeydown={handleKeydown}
      rows="1"
      disabled={streaming}
    ></textarea>
    <div class="ope-ranger-input-actions">
      {#if streaming}
        <button class="ope-ranger-stop-btn" onclick={stopStreaming}>
          <svg width="12" height="12" viewBox="0 0 12 12" fill="currentColor"><rect x="2" y="2" width="8" height="8" rx="1"/></svg>
          Stop
        </button>
      {:else}
        <button
          class="ope-ranger-send-btn"
          onclick={sendMessage}
          disabled={!input.trim()}
        >
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/>
          </svg>
        </button>
      {/if}
    </div>
  </div>
</div>

<style>
  .ope-ranger-drawer {
    display: flex;
    flex-direction: column;
    height: 100%;
    min-height: 0;
  }

  .ope-ranger-toolbar {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 10px 20px;
    border-bottom: 1px solid #F3F4F6;
    flex-shrink: 0;
  }

  .ope-ranger-provider {
    flex: 1;
    padding: 6px 0;
    border: none;
    border-bottom: 1px solid transparent;
    font-size: 13px;
    font-weight: 500;
    color: #111827;
    background: transparent;
    outline: none;
    cursor: pointer;
    font-family: inherit;
    -webkit-appearance: none;
  }
  .ope-ranger-provider:hover { border-bottom-color: #E5E7EB; }
  .ope-ranger-provider:focus { border-bottom-color: #2D5A47; }

  .ope-ranger-new-btn {
    width: 28px;
    height: 28px;
    display: flex;
    align-items: center;
    justify-content: center;
    border: none;
    background: #F3F4F6;
    color: #6B7280;
    border-radius: 6px;
    cursor: pointer;
    transition: all 0.15s;
    padding: 0;
    flex-shrink: 0;
  }
  .ope-ranger-new-btn:hover { background: #E5E7EB; color: #374151; }

  .ope-ranger-messages {
    flex: 1;
    overflow-y: auto;
    padding: 16px 20px;
    min-height: 0;
    display: flex;
    flex-direction: column;
    gap: 16px;
  }

  .ope-ranger-empty {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    flex: 1;
    text-align: center;
    padding: 40px 20px;
  }

  .ope-ranger-empty-icon {
    width: 40px;
    height: 40px;
    margin-bottom: 16px;
    opacity: 0.6;
  }

  .ope-ranger-empty-title {
    font-size: 15px;
    font-weight: 500;
    color: #374151;
    margin: 0 0 4px;
  }

  .ope-ranger-empty-sub {
    font-size: 13px;
    color: #9CA3AF;
    margin: 0;
  }
  .ope-ranger-empty-sub :global(strong) { color: #6B7280; }

  .ope-ranger-msg { max-width: 100%; }
  .ope-ranger-msg-user { align-self: flex-end; }

  .ope-ranger-msg-user .ope-ranger-msg-content {
    background: #2D5A47;
    color: white;
    padding: 10px 14px;
    border-radius: 14px 14px 4px 14px;
    font-size: 13px;
    line-height: 1.5;
    white-space: pre-wrap;
    word-break: break-word;
    max-width: 280px;
  }

  .ope-ranger-msg-assistant .ope-ranger-msg-content {
    font-size: 13px;
    line-height: 1.6;
    color: #374151;
    word-break: break-word;
  }

  .ope-ranger-msg-assistant .ope-ranger-msg-content :global(strong) {
    font-weight: 600;
    color: #111827;
  }

  .ope-ranger-msg-assistant .ope-ranger-msg-content :global(.ope-ranger-code) {
    background: #F3F4F6;
    padding: 1px 5px;
    border-radius: 4px;
    font-family: 'SF Mono', 'Monaco', 'Menlo', monospace;
    font-size: 12px;
    color: #111827;
  }

  .ope-ranger-tools {
    display: flex;
    flex-direction: column;
    gap: 4px;
    margin-top: 8px;
  }

  .ope-ranger-tool {
    display: flex;
    align-items: center;
    padding: 6px 10px;
    background: #F9FAFB;
    border-radius: 6px;
    border: 1px solid #F3F4F6;
  }

  .ope-ranger-tool-running { border-color: #E5E7EB; }

  .ope-ranger-tool-header {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 12px;
    color: #6B7280;
  }

  .ope-ranger-tool-name {
    text-transform: capitalize;
    font-weight: 500;
  }

  .ope-ranger-tool-spinner {
    width: 12px;
    height: 12px;
    border: 2px solid #E5E7EB;
    border-top-color: #2D5A47;
    border-radius: 50%;
    animation: ope-spin 0.6s linear infinite;
  }

  @keyframes ope-spin { to { transform: rotate(360deg); } }

  .ope-ranger-scroll-btn {
    position: absolute;
    bottom: 80px;
    left: 50%;
    transform: translateX(-50%);
    width: 32px;
    height: 32px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: white;
    border: 1px solid #E5E7EB;
    border-radius: 50%;
    color: #6B7280;
    cursor: pointer;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
    z-index: 1;
    padding: 0;
  }
  .ope-ranger-scroll-btn:hover { background: #F9FAFB; color: #374151; }

  .ope-ranger-input-wrap {
    padding: 12px 20px 16px;
    border-top: 1px solid #F3F4F6;
    flex-shrink: 0;
    display: flex;
    align-items: flex-end;
    gap: 8px;
  }

  .ope-ranger-input {
    flex: 1;
    padding: 10px 12px;
    border: 1px solid #E5E7EB;
    border-radius: 10px;
    font-size: 13px;
    color: #111827;
    background: #fff;
    outline: none;
    resize: none;
    min-height: 38px;
    max-height: 120px;
    line-height: 1.4;
    font-family: inherit;
    transition: border-color 0.15s;
  }
  .ope-ranger-input:focus {
    border-color: #2D5A47;
    box-shadow: 0 0 0 2px rgba(45, 90, 71, 0.08);
  }
  .ope-ranger-input::placeholder { color: #D1D5DB; }
  .ope-ranger-input:disabled { opacity: 0.6; }

  .ope-ranger-input-actions { flex-shrink: 0; }

  .ope-ranger-send-btn {
    width: 36px;
    height: 36px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #2D5A47;
    border: none;
    border-radius: 10px;
    color: white;
    cursor: pointer;
    transition: background 0.15s;
    padding: 0;
  }
  .ope-ranger-send-btn:hover:not(:disabled) { background: #1E4535; }
  .ope-ranger-send-btn:disabled { opacity: 0.4; cursor: default; }

  .ope-ranger-stop-btn {
    display: flex;
    align-items: center;
    gap: 4px;
    padding: 8px 12px;
    background: #FEF2F2;
    border: 1px solid #FECACA;
    border-radius: 8px;
    color: #EF4444;
    font-size: 12px;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.15s;
  }
  .ope-ranger-stop-btn:hover { background: #FEE2E2; }
</style>
