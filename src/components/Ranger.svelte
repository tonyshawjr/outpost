<script>
  import { getApiBase, getCsrfToken } from '$lib/api.js';
  import { darkMode, navigate } from '$lib/stores.js';
  import ChatMessage from './ranger/ChatMessage.svelte';
  import ProviderSelect from './ranger/ProviderSelect.svelte';

  let { open = true, onclose } = $props();

  // Frontend actions that Ranger can trigger via the frontend_action tool
  function executeFrontendAction(action, params) {
    console.log('[Ranger] Frontend action:', action, params);
    switch (action) {
      case 'toggle_dark_mode':
      case 'set_dark_mode': {
        const enable = action === 'toggle_dark_mode' ? undefined : params?.enabled !== false;
        if (enable === undefined) {
          darkMode.update(v => !v);
        } else {
          darkMode.set(enable);
        }
        console.log('[Ranger] Dark mode toggled');
        return { success: true };
      }
      case 'navigate':
        if (params?.page) navigate(params.page, params.params || {});
        return { success: true, navigated_to: params?.page };
      case 'refresh_page':
        window.location.reload();
        return { success: true };
      default:
        return { error: `Unknown frontend action: ${action}` };
    }
  }

  // State
  let messages = $state([]);
  let conversationId = $state(null);
  let streaming = $state(false);
  let abortController = $state(null);
  let provider = $state('claude');
  let model = $state('');
  let input = $state('');
  let conversations = $state([]);
  let view = $state('chat');
  let messagesEnd = $state(null);
  let messagesContainer = $state(null);
  let textareaEl = $state(null);
  let loadingHistory = $state(false);
  let lastUsage = $state(null); // {input_tokens, output_tokens, cached_tokens, cost_cents}
  let usageSummary = $state(null); // {total_cost_cents, total_tokens, conversation_count}
  let visible = $derived(open);
  let pastedImages = $state([]); // [{data: base64, type: 'image/png'}]
  let showScrollBtn = $state(false);
  let prevMessageCount = $state(0);

  // Smart auto-scroll — only when user is near the bottom
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
    const nearBottom = isNearBottom();
    showScrollBtn = !nearBottom && messages.length > 0;
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

  // Auto-resize textarea — track input changes
  $effect(() => {
    // Access input to create dependency
    const _ = input;
    if (textareaEl) {
      textareaEl.style.height = 'auto';
      textareaEl.style.height = Math.min(textareaEl.scrollHeight, 150) + 'px';
    }
  });

  // Escape key to close panel
  function handleKeydown_global(e) {
    if (e.key === 'Escape' && open) handleClose();
  }

  // Character count for long messages
  let charCount = $derived(input.length);
  let showCharCount = $derived(input.length > 500);

  // Derived
  let hasMessages = $derived(messages.length > 0);

  function resizeImage(file, maxWidth = 1200, quality = 0.8) {
    return new Promise((resolve) => {
      const img = new Image();
      img.onload = () => {
        let w = img.width, h = img.height;
        if (w > maxWidth) { h = Math.round(h * maxWidth / w); w = maxWidth; }
        const canvas = document.createElement('canvas');
        canvas.width = w;
        canvas.height = h;
        canvas.getContext('2d').drawImage(img, 0, 0, w, h);
        const dataUrl = canvas.toDataURL('image/jpeg', quality);
        URL.revokeObjectURL(img.src); // Free memory
        resolve({ data: dataUrl.split(',')[1], type: 'image/jpeg' });
      };
      img.src = URL.createObjectURL(file);
    });
  }

  function handlePaste(e) {
    const items = e.clipboardData?.items;
    if (!items) return;
    for (const item of items) {
      if (item.type.startsWith('image/')) {
        e.preventDefault();
        const file = item.getAsFile();
        resizeImage(file).then(img => {
          pastedImages = [...pastedImages, img];
        });
      }
    }
  }

  function removeImage(idx) {
    pastedImages = pastedImages.filter((_, i) => i !== idx);
  }

  function stopStreaming() {
    if (abortController) {
      abortController.abort();
      abortController = null;
    }
    streaming = false;
  }

  function editMessage(idx) {
    // Put the user message text back into the input, remove it and everything after
    const msg = messages[idx];
    if (msg?.role !== 'user') return;
    input = msg.content || '';
    pastedImages = (msg.images || []).map(src => {
      const match = src.match(/^data:(image\/\w+);base64,(.+)$/);
      return match ? { type: match[1], data: match[2] } : null;
    }).filter(Boolean);
    // Remove this message and all messages after it
    messages = messages.slice(0, idx);
    // Focus the textarea
    if (textareaEl) textareaEl.focus();
  }

  async function sendMessage() {
    if ((!input.trim() && pastedImages.length === 0) || streaming) return;

    const userMsg = input.trim();
    const images = [...pastedImages];
    input = '';
    pastedImages = [];

    // Build user message for display — include image thumbnails
    const userDisplay = { role: 'user', content: userMsg, images: images.map(img => `data:${img.type};base64,${img.data}`) };
    messages = [...messages, userDisplay];
    streaming = true;

    // Track the current assistant message index — new one per tool round
    let assistantIdx = -1;
    let lastEventWasToolResult = false;

    function ensureAssistantMessage() {
      // Create a new assistant message if we don't have one or if we're starting a new round
      if (assistantIdx === -1 || lastEventWasToolResult) {
        messages = [...messages, { role: 'assistant', content: '', tool_calls: [] }];
        assistantIdx = messages.length - 1;
        lastEventWasToolResult = false;
      }
    }

    function updateAssistant(updater) {
      messages = messages.map((m, i) => i === assistantIdx ? updater({ ...m }) : m);
    }

    const apiBase = getApiBase();
    const csrfToken = getCsrfToken();
    const controller = new AbortController();
    abortController = controller;

    try {
      const url = new URL(apiBase, window.location.origin);
      url.searchParams.set('action', 'ranger/chat');

      const res = await fetch(url.toString(), {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-Token': csrfToken,
        },
        credentials: 'include',
        signal: controller.signal,
        body: JSON.stringify({
          message: userMsg,
          conversation_id: conversationId,
          provider,
          model,
          images: images.length > 0 ? images : undefined,
        }),
      });

      if (!res.ok) {
        const errText = await res.text();
        try {
          const errJson = JSON.parse(errText);
          updateAssistant(m => ({ ...m, content: `Error: ${errJson.error || res.statusText}` }));
        } catch {
          updateAssistant(m => ({ ...m, content: `Error ${res.status}: ${errText || res.statusText}` }));
        }
        streaming = false;
        return;
      }

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
              executeFrontendAction(event.action, event.params);
            } else if (event.type === 'conversation_id') {
              conversationId = event.id;
            } else if (event.type === 'done') {
              if (event.conversation_id) conversationId = event.conversation_id;
              if (event.usage) lastUsage = event.usage;
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
            console.warn('[Ranger] Parse error:', data, parseErr);
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

  function newChat() {
    messages = [];
    conversationId = null;
    view = 'chat';
  }

  async function loadHistory() {
    view = 'history';
    loadingHistory = true;
    const apiBase = getApiBase();
    const csrfToken = getCsrfToken();
    try {
      const url = new URL(apiBase, window.location.origin);
      url.searchParams.set('action', 'ranger/conversations');
      const res = await fetch(url.toString(), {
        credentials: 'include',
        headers: { 'X-CSRF-Token': csrfToken },
      });
      const data = await res.json();
      conversations = data.conversations || [];
      usageSummary = data.usage_summary || null;
    } catch {
      conversations = [];
    } finally {
      loadingHistory = false;
    }
  }

  async function loadConversation(conv) {
    const apiBase = getApiBase();
    const csrfToken = getCsrfToken();
    try {
      const url = new URL(apiBase, window.location.origin);
      url.searchParams.set('action', 'ranger/conversations');
      url.searchParams.set('id', conv.id);
      const res = await fetch(url.toString(), {
        credentials: 'include',
        headers: { 'X-CSRF-Token': csrfToken },
      });
      const data = await res.json();
      const convoData = data.conversation || {};
      const rawMessages = convoData.messages || [];

      // Normalize messages — stored format may have nested content arrays (Claude format)
      messages = rawMessages.map(m => {
        // User messages are simple {role, content}
        if (m.role === 'user') {
          return { role: 'user', content: typeof m.content === 'string' ? m.content : '' };
        }
        // Assistant messages may have content as array [{type:'text', text:'...'}, {type:'tool_use',...}]
        if (m.role === 'assistant') {
          let text = '';
          let tool_calls = [];
          if (typeof m.content === 'string') {
            text = m.content;
          } else if (Array.isArray(m.content)) {
            for (const block of m.content) {
              if (block.type === 'text') text += block.text || '';
              if (block.type === 'tool_use') {
                tool_calls.push({ name: block.name, input: block.input, status: 'done' });
              }
            }
          }
          return { role: 'assistant', content: text, tool_calls };
        }
        // Tool result messages — skip, they're internal
        return null;
      }).filter(Boolean);

      conversationId = conv.id;
      provider = convoData.provider || provider;
      view = 'chat';
    } catch (err) {
      console.error('[Ranger] Failed to load conversation:', err);
    }
  }

  async function deleteConversation(e, conv) {
    e.stopPropagation();
    const apiBase = getApiBase();
    const csrfToken = getCsrfToken();
    try {
      const url = new URL(apiBase, window.location.origin);
      url.searchParams.set('action', 'ranger/conversations');
      url.searchParams.set('id', conv.id);
      await fetch(url.toString(), {
        method: 'DELETE',
        credentials: 'include',
        headers: { 'X-CSRF-Token': csrfToken },
      });
      conversations = conversations.filter(c => c.id !== conv.id);
      if (conversationId === conv.id) {
        newChat();
      }
    } catch {
      // silently fail
    }
  }

  function handleClose() {
    onclose?.();
  }

  function formatDate(dateStr) {
    if (!dateStr) return '';
    // SQLite datetime('now') returns UTC without timezone indicator — append Z
    const d = new Date(dateStr.includes('T') || dateStr.includes('Z') ? dateStr : dateStr.replace(' ', 'T') + 'Z');
    if (isNaN(d.getTime())) return dateStr;
    const now = new Date();
    const diff = now - d;
    if (diff < 0) return 'Just now'; // future dates (clock skew)
    if (diff < 60000) return 'Just now';
    if (diff < 3600000) return `${Math.floor(diff / 60000)}m ago`;
    if (diff < 86400000) return `${Math.floor(diff / 3600000)}h ago`;
    if (diff < 604800000) return `${Math.floor(diff / 86400000)}d ago`;
    return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
  }
</script>

<svelte:window onkeydown={handleKeydown_global} />
<aside class="ranger-panel" class:visible role="complementary" aria-label="Ranger AI assistant">
  <!-- Header -->
  <div class="ranger-header">
    <div class="ranger-title">
      <svg class="ranger-icon" viewBox="0 0 24 24" fill="rgba(255,255,255,0.9)">
        <path d="M10 2L11.5 7.5L17 9L11.5 10.5L10 16L8.5 10.5L3 9L8.5 7.5L10 2Z"/>
        <path d="M18 12L19 15L22 16L19 17L18 20L17 17L14 16L17 15L18 12Z" opacity="0.6"/>
      </svg>
      <span>Ranger</span>
    </div>
    <button class="ranger-close" onclick={handleClose} aria-label="Close Ranger panel">
      <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5">
        <line x1="5" y1="5" x2="15" y2="15"/><line x1="15" y1="5" x2="5" y2="15"/>
      </svg>
    </button>
  </div>

  <!-- Toolbar -->
  <div class="ranger-toolbar">
    <ProviderSelect value={provider} onchange={(v) => { provider = v; }} />
    <div class="ranger-toolbar-actions">
      <button class="ranger-toolbar-btn" onclick={newChat} title="New Chat">
        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5">
          <line x1="8" y1="3" x2="8" y2="13"/><line x1="3" y1="8" x2="13" y2="8"/>
        </svg>
        New
      </button>
      <button class="ranger-toolbar-btn" class:active={view === 'history'} onclick={loadHistory} title="History">
        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5">
          <circle cx="8" cy="8" r="6"/><polyline points="8 4.5 8 8 10.5 9.5"/>
        </svg>
        History
      </button>
    </div>
  </div>

  <!-- Body -->
  <div class="ranger-body" bind:this={messagesContainer} onscroll={handleBodyScroll}>
    {#if view === 'history'}
      <!-- History list -->
      <div class="ranger-history">
        {#if loadingHistory}
          <div class="ranger-empty">
            <span class="ranger-loading-spinner"></span>
          </div>
        {:else if conversations.length === 0}
          <div class="ranger-empty">
            <div class="ranger-empty-content">
              <svg class="ranger-empty-icon" viewBox="0 0 24 24" fill="none" stroke="rgba(255,255,255,0.25)" stroke-width="1.5">
                <circle cx="12" cy="12" r="9"/><polyline points="12 6 12 12 15 14"/>
              </svg>
              <p class="ranger-empty-text">No conversations yet</p>
              <p class="ranger-empty-subtext">Start a new chat to begin</p>
            </div>
          </div>
        {:else}
          {#if usageSummary}
            <div class="ranger-usage-summary">
              <div class="ranger-usage-stat">
                <span class="ranger-usage-label">Total Spend</span>
                <span class="ranger-usage-value">${(Math.abs(usageSummary.total_cost_cents || 0) / 100).toFixed(2)}</span>
              </div>
              <div class="ranger-usage-stat">
                <span class="ranger-usage-label">Tokens Used</span>
                <span class="ranger-usage-value">{usageSummary.total_tokens.toLocaleString()}</span>
              </div>
              <div class="ranger-usage-stat">
                <span class="ranger-usage-label">Conversations</span>
                <span class="ranger-usage-value">{usageSummary.conversation_count}</span>
              </div>
            </div>
          {/if}
          {#each conversations as conv}
            <div class="ranger-history-item" role="button" tabindex="0" onclick={() => loadConversation(conv)} onkeydown={(e) => e.key === 'Enter' && loadConversation(conv)}>
              <div class="ranger-history-title">{conv.title || 'Untitled conversation'}</div>
              <div class="ranger-history-meta">
                <span class="ranger-history-provider">{conv.provider || 'claude'}</span>
                {#if conv.total_cost_cents > 0}
                  <span class="ranger-history-cost">${(Math.abs(conv.total_cost_cents || 0) / 100).toFixed(3)}</span>
                {/if}
                <span class="ranger-history-date">{formatDate(conv.created_at)}</span>
              </div>
              <button
                class="ranger-history-delete"
                onclick={(e) => { e.stopPropagation(); deleteConversation(e, conv); }}
                aria-label="Delete conversation: {conv.title || 'Untitled'}"
              >
                <svg viewBox="0 0 14 14" fill="none" stroke="currentColor" stroke-width="1.5">
                  <line x1="3" y1="3" x2="11" y2="11"/><line x1="11" y1="3" x2="3" y2="11"/>
                </svg>
              </button>
            </div>
          {/each}
        {/if}
      </div>
    {:else}
      <!-- Chat messages -->
      {#if !hasMessages}
        <div class="ranger-welcome">
          <svg class="ranger-welcome-icon" viewBox="0 0 40 40" fill="none">
            <path d="M20 4L6 14v12l14 10 14-10V14L20 4z" stroke="var(--accent, #2D5A47)" stroke-width="1.2" fill="var(--accent-soft, rgba(45, 90, 71, 0.08))"/>
            <circle cx="20" cy="20" r="4" fill="var(--accent, #2D5A47)"/>
          </svg>
          <h3 class="ranger-welcome-title">Ranger</h3>
          <p class="ranger-welcome-desc">Your AI assistant for Outpost CMS. Ask me to create collections, write content, manage settings, or build templates.</p>
          <div class="ranger-suggestions">
            <button class="ranger-suggestion" onclick={() => { input = 'Create a blog collection with title, excerpt, and body fields'; sendMessage(); }}>
              Create a blog collection
            </button>
            <button class="ranger-suggestion" onclick={() => { input = 'What collections do I have?'; sendMessage(); }}>
              List my collections
            </button>
            <button class="ranger-suggestion" onclick={() => { input = 'Help me set up my navigation menu'; sendMessage(); }}>
              Set up navigation
            </button>
          </div>
        </div>
      {:else}
        <div class="ranger-messages" role="log" aria-live="polite" aria-label="Chat messages">
          {#each messages as message, idx}
            <div class="ranger-msg-row" class:is-user={message.role === 'user'}>
              <ChatMessage {message} />
              {#if message.role === 'user' && !streaming}
                <button class="ranger-edit-btn" onclick={() => editMessage(idx)} title="Edit this message" aria-label="Edit message">
                  <svg viewBox="0 0 14 14" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M10.5 1.5l2 2-8 8H2.5v-2l8-8z"/></svg>
                </button>
              {/if}
            </div>
          {/each}
          {#if streaming}
            <div class="ranger-typing" aria-label="Ranger is typing">
              <span class="ranger-typing-dot"></span>
              <span class="ranger-typing-dot"></span>
              <span class="ranger-typing-dot"></span>
            </div>
          {/if}
          <div bind:this={messagesEnd}></div>
        </div>
      {/if}
    {/if}

    <!-- Scroll to bottom button -->
    {#if showScrollBtn}
      <button class="ranger-scroll-bottom" onclick={() => scrollToBottom()} aria-label="Scroll to latest messages">
        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2">
          <polyline points="4 6 8 10 12 6"/>
        </svg>
      </button>
    {/if}
  </div>

  <!-- Usage indicator -->
  {#if lastUsage && !streaming}
    <div class="ranger-usage">
      <span>{(lastUsage.input_tokens + lastUsage.output_tokens).toLocaleString()} tokens</span>
      <span>·</span>
      <span>${(Math.abs(lastUsage.cost_cents || 0) / 100).toFixed(4)}</span>
      {#if lastUsage.cached_tokens > 0}
        <span>·</span>
        <span class="ranger-usage-cached">{Math.round(lastUsage.cached_tokens / (lastUsage.input_tokens || 1) * 100)}% cached</span>
      {/if}
    </div>
  {/if}

  <!-- Input -->
  <div class="ranger-input-area">
    {#if streaming}
      <button
        class="ranger-stop-bar"
        onclick={stopStreaming}
        aria-label="Stop generating"
      >
        <svg viewBox="0 0 16 16" fill="currentColor" class="ranger-stop-icon">
          <rect x="3" y="3" width="10" height="10" rx="2"/>
        </svg>
        Stop generating
      </button>
    {/if}
    {#if pastedImages.length > 0}
      <div class="ranger-image-previews">
        {#each pastedImages as img, idx}
          <div class="ranger-image-preview">
            <img src="data:{img.type};base64,{img.data}" alt="Pasted screenshot" />
            <button class="ranger-image-remove" onclick={() => removeImage(idx)} aria-label="Remove image">
              <svg viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="2"><line x1="2" y1="2" x2="10" y2="10"/><line x1="10" y1="2" x2="2" y2="10"/></svg>
            </button>
          </div>
        {/each}
      </div>
    {/if}
    <div class="ranger-input-wrap">
      <textarea
        bind:this={textareaEl}
        bind:value={input}
        class="ranger-textarea"
        placeholder="Message Ranger..."
        rows="1"
        disabled={streaming}
        onkeydown={handleKeydown}
        onpaste={handlePaste}
        aria-label="Type a message"
      ></textarea>
      <button
        class="ranger-send"
        onclick={sendMessage}
        disabled={streaming || (!input.trim() && pastedImages.length === 0)}
        aria-label="Send message"
      >
        <svg viewBox="0 0 16 16" fill="none">
          <path d="M2 14l12-6L2 2v4.5l7 1.5-7 1.5V14z" fill="currentColor"/>
        </svg>
      </button>
    </div>
    {#if showCharCount}
      <div class="ranger-char-count" class:warn={charCount > 2000}>{charCount.toLocaleString()} chars</div>
    {/if}
  </div>
</aside>

<style>
  /* Backdrop */
  .ranger-backdrop {
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0);
    z-index: 199;
    transition: background 0.2s ease;
    pointer-events: none;
  }
  .ranger-backdrop.visible {
    background: rgba(0, 0, 0, 0.15);
    pointer-events: auto;
  }

  /* Panel */
  .ranger-panel {
    position: fixed;
    top: 0;
    right: 0;
    bottom: 0;
    width: 400px;
    max-width: 100vw;
    z-index: 200;
    display: flex;
    flex-direction: column;
    background: var(--sidebar-bg, #3D3530);
    color: rgba(255, 255, 255, 0.9);
    border-left: 1px solid rgba(255, 255, 255, 0.08);
    box-shadow: -4px 0 24px rgba(0, 0, 0, 0.15);
    transform: translateX(100%);
    transition: transform 0.25s cubic-bezier(0.4, 0, 0.2, 1);
  }
  .ranger-panel::before {
    content: '';
    position: absolute;
    inset: 0;
    background-image: url('/outpost/admin/assets/topography-bg.jpg');
    background-size: cover;
    background-position: center;
    filter: invert(1) brightness(1.5);
    opacity: 0.06;
    pointer-events: none;
    z-index: 0;
  }
  .ranger-panel > * {
    position: relative;
    z-index: 1;
  }
  .ranger-panel.visible {
    transform: translateX(0);
  }

  /* Header */
  .ranger-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 16px 20px;
    border-bottom: 1px solid rgba(255, 255, 255, 0.08);
    flex-shrink: 0;
  }

  .ranger-title {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 14px;
    font-weight: 600;
    color: rgba(255, 255, 255, 0.95);
    font-family: var(--font-sans);
    letter-spacing: 0.01em;
  }

  .ranger-icon {
    width: 20px;
    height: 20px;
  }

  .ranger-close {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    border: none;
    background: none;
    color: rgba(255, 255, 255, 0.5);
    cursor: pointer;
    border-radius: var(--radius-sm, 8px);
    transition: color 0.15s, background 0.15s;
  }
  .ranger-close:hover {
    color: rgba(255, 255, 255, 0.9);
    background: rgba(255, 255, 255, 0.1);
  }
  .ranger-close:focus-visible {
    outline: 2px solid #6FCF97;
    outline-offset: 2px;
  }
  .ranger-close svg {
    width: 16px;
    height: 16px;
  }

  /* Toolbar */
  .ranger-toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 8px 20px;
    border-bottom: 1px solid rgba(255, 255, 255, 0.08);
    flex-shrink: 0;
    gap: 8px;
  }

  .ranger-toolbar-actions {
    display: flex;
    gap: 4px;
  }

  .ranger-toolbar-btn {
    display: flex;
    align-items: center;
    gap: 4px;
    padding: 5px 10px;
    border: none;
    background: none;
    color: rgba(255, 255, 255, 0.5);
    font-size: 12px;
    font-weight: 500;
    font-family: var(--font-sans);
    cursor: pointer;
    border-radius: var(--radius-sm, 8px);
    transition: color 0.15s, background 0.15s;
  }
  .ranger-toolbar-btn:hover {
    color: rgba(255, 255, 255, 0.9);
    background: rgba(255, 255, 255, 0.08);
  }
  .ranger-toolbar-btn:focus-visible {
    outline: 2px solid #6FCF97;
    outline-offset: 2px;
  }
  .ranger-toolbar-btn.active {
    color: #6FCF97;
    background: rgba(111, 207, 151, 0.12);
  }
  .ranger-toolbar-btn svg {
    width: 14px;
    height: 14px;
  }

  /* Body */
  .ranger-body {
    flex: 1;
    overflow-y: auto;
    min-height: 0;
    position: relative;
    scroll-behavior: smooth;
    -webkit-overflow-scrolling: touch;
  }

  /* Messages */
  .ranger-messages {
    padding: 16px 20px;
    display: flex;
    flex-direction: column;
    gap: 16px;
  }

  /* Welcome */
  .ranger-welcome {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 0 24px 24px;
    text-align: center;
    min-height: 100%;
  }

  .ranger-welcome-icon {
    width: 44px;
    height: 44px;
    margin-bottom: 16px;
    filter: drop-shadow(0 0 8px rgba(45, 90, 71, 0.3));
    animation: ranger-glow 3s ease-in-out infinite;
  }
  @keyframes ranger-glow {
    0%, 100% { filter: drop-shadow(0 0 8px rgba(45, 90, 71, 0.3)); }
    50% { filter: drop-shadow(0 0 14px rgba(45, 90, 71, 0.5)); }
  }

  .ranger-welcome-title {
    font-family: var(--font-serif, Georgia, serif);
    font-size: 22px;
    font-weight: 500;
    color: rgba(255, 255, 255, 0.95);
    margin: 0 0 8px;
  }

  .ranger-welcome-desc {
    font-size: 13.5px;
    line-height: 1.6;
    color: rgba(255, 255, 255, 0.5);
    margin: 0 0 32px;
    max-width: 280px;
  }

  .ranger-suggestions {
    display: flex;
    flex-direction: column;
    gap: 8px;
    width: 100%;
    max-width: 300px;
  }

  .ranger-suggestion {
    padding: 12px 16px;
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: var(--radius-sm, 8px);
    background: rgba(255, 255, 255, 0.04);
    color: rgba(255, 255, 255, 0.7);
    font-size: 13px;
    font-family: var(--font-sans);
    line-height: 1.4;
    cursor: pointer;
    text-align: left;
    transition: border-color 0.15s, background 0.15s, color 0.15s, transform 0.1s;
  }
  .ranger-suggestion:hover {
    border-color: rgba(111, 207, 151, 0.25);
    background: rgba(255, 255, 255, 0.08);
    color: rgba(255, 255, 255, 0.9);
  }
  .ranger-suggestion:active {
    transform: scale(0.98);
  }
  .ranger-suggestion:focus-visible {
    outline: 2px solid #6FCF97;
    outline-offset: 2px;
  }

  /* Typing indicator */
  .ranger-typing {
    display: flex;
    gap: 4px;
    padding: 8px 12px;
    align-items: center;
  }
  .ranger-typing-dot {
    width: 5px;
    height: 5px;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.4);
    animation: ranger-bounce 1.2s ease-in-out infinite;
  }
  .ranger-typing-dot:nth-child(2) { animation-delay: 0.15s; }
  .ranger-typing-dot:nth-child(3) { animation-delay: 0.3s; }
  @keyframes ranger-bounce {
    0%, 60%, 100% { transform: translateY(0); }
    30% { transform: translateY(-4px); }
  }

  /* Scroll to bottom */
  .ranger-scroll-bottom {
    position: sticky;
    bottom: 12px;
    left: 50%;
    transform: translateX(-50%);
    display: flex;
    align-items: center;
    justify-content: center;
    width: 32px;
    height: 32px;
    border: 1px solid rgba(255, 255, 255, 0.15);
    background: var(--sidebar-bg, #3D3530);
    color: rgba(255, 255, 255, 0.7);
    border-radius: 50%;
    cursor: pointer;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.3);
    transition: color 0.15s, background 0.15s, border-color 0.15s;
    z-index: 5;
    margin: 0 auto;
    animation: ranger-fade-in 0.15s ease;
  }
  .ranger-scroll-bottom:hover {
    color: rgba(255, 255, 255, 0.95);
    background: rgba(255, 255, 255, 0.12);
    border-color: rgba(255, 255, 255, 0.25);
  }
  .ranger-scroll-bottom:focus-visible {
    outline: 2px solid #6FCF97;
    outline-offset: 2px;
  }
  .ranger-scroll-bottom svg {
    width: 14px;
    height: 14px;
  }
  @keyframes ranger-fade-in {
    from { opacity: 0; transform: translateX(-50%) translateY(8px); }
    to { opacity: 1; transform: translateX(-50%) translateY(0); }
  }

  /* Usage indicator */
  .ranger-usage {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 4px 20px;
    font-size: 11px;
    color: rgba(255, 255, 255, 0.35);
    font-family: var(--font-mono, monospace);
  }
  .ranger-usage-cached {
    color: #6FCF97;
  }

  /* Input area */
  .ranger-input-area {
    flex-shrink: 0;
    padding: 12px 20px;
    padding-bottom: calc(12px + env(safe-area-inset-bottom, 0px));
    border-top: 1px solid rgba(255, 255, 255, 0.08);
  }

  /* Stop button bar */
  .ranger-stop-bar {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    width: 100%;
    padding: 8px;
    margin-bottom: 8px;
    border: 1px solid rgba(235, 87, 87, 0.3);
    background: rgba(235, 87, 87, 0.1);
    color: #EB5757;
    font-size: 12px;
    font-weight: 500;
    font-family: var(--font-sans);
    border-radius: var(--radius-sm, 8px);
    cursor: pointer;
    transition: background 0.15s, border-color 0.15s;
    animation: ranger-pulse 1.5s ease-in-out infinite;
  }
  .ranger-stop-bar:hover {
    background: rgba(235, 87, 87, 0.18);
    border-color: rgba(235, 87, 87, 0.5);
  }
  .ranger-stop-bar:focus-visible {
    outline: 2px solid #EB5757;
    outline-offset: 2px;
  }
  .ranger-stop-icon {
    width: 12px;
    height: 12px;
  }

  .ranger-image-previews {
    display: flex;
    gap: 8px;
    padding: 0 0 8px;
    flex-wrap: wrap;
  }
  .ranger-image-preview {
    position: relative;
    width: 64px;
    height: 64px;
    border-radius: 6px;
    overflow: hidden;
    border: 1px solid rgba(255, 255, 255, 0.15);
  }
  .ranger-image-preview img {
    width: 100%;
    height: 100%;
    object-fit: cover;
  }
  .ranger-image-remove {
    position: absolute;
    top: 2px;
    right: 2px;
    width: 18px;
    height: 18px;
    border-radius: 50%;
    background: rgba(0,0,0,0.6);
    color: white;
    border: none;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 0;
  }
  .ranger-image-remove svg {
    width: 10px;
    height: 10px;
  }

  .ranger-input-wrap {
    display: flex;
    align-items: flex-end;
    gap: 8px;
    background: rgba(255, 255, 255, 0.06);
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: var(--radius-md, 12px);
    padding: 6px 8px 6px 14px;
    transition: border-color 0.15s, box-shadow 0.15s;
  }
  .ranger-input-wrap:focus-within {
    border-color: rgba(111, 207, 151, 0.35);
    box-shadow: 0 0 0 3px rgba(111, 207, 151, 0.08);
  }

  .ranger-textarea {
    flex: 1;
    border: none;
    background: none;
    color: rgba(255, 255, 255, 0.9);
    font-size: 14px;
    font-family: var(--font-sans);
    line-height: 1.5;
    resize: none;
    outline: none;
    padding: 4px 0;
    min-height: 20px;
    max-height: 150px;
  }
  .ranger-textarea::placeholder {
    color: rgba(255, 255, 255, 0.3);
  }
  .ranger-textarea:disabled {
    opacity: 0.5;
  }

  .ranger-char-count {
    font-size: 11px;
    color: rgba(255, 255, 255, 0.35);
    text-align: right;
    margin-top: 4px;
    font-family: var(--font-sans);
    font-variant-numeric: tabular-nums;
  }
  .ranger-char-count.warn {
    color: #C49A3D;
  }

  .ranger-send {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    border: none;
    background: var(--accent, #2D5A47);
    color: var(--text-inverse, #FDFCFA);
    border-radius: var(--radius-sm, 8px);
    cursor: pointer;
    flex-shrink: 0;
    transition: background 0.15s, opacity 0.15s, transform 0.1s;
  }
  .ranger-send:hover:not(:disabled) {
    background: var(--accent-hover, #1E3D30);
  }
  .ranger-send:active:not(:disabled) {
    transform: scale(0.93);
  }
  .ranger-send:focus-visible {
    outline: 2px solid #6FCF97;
    outline-offset: 2px;
  }
  .ranger-send:disabled {
    opacity: 0.35;
    cursor: default;
  }
  .ranger-send svg {
    width: 14px;
    height: 14px;
  }

  @keyframes ranger-pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.7; }
  }

  /* Edit button on user messages */
  .ranger-msg-row {
    position: relative;
  }
  .ranger-msg-row.is-user {
    display: flex;
    justify-content: flex-end;
    align-items: flex-start;
    gap: 4px;
  }
  .ranger-edit-btn {
    display: none;
    align-items: center;
    justify-content: center;
    width: 22px;
    height: 22px;
    border: none;
    background: rgba(255, 255, 255, 0.08);
    color: rgba(255, 255, 255, 0.4);
    border-radius: 4px;
    cursor: pointer;
    flex-shrink: 0;
    margin-top: 6px;
    transition: color 0.15s, background 0.15s;
  }
  .ranger-msg-row.is-user:hover .ranger-edit-btn {
    display: flex;
  }
  .ranger-edit-btn:hover {
    color: rgba(255, 255, 255, 0.9);
    background: rgba(255, 255, 255, 0.15);
  }
  .ranger-edit-btn:focus-visible {
    display: flex;
    outline: 2px solid #6FCF97;
    outline-offset: 2px;
  }
  .ranger-edit-btn svg {
    width: 12px;
    height: 12px;
  }

  /* Usage summary */
  .ranger-usage-summary {
    display: flex;
    gap: 2px;
    padding: 12px 12px 8px;
    margin-bottom: 4px;
  }
  .ranger-usage-stat {
    flex: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 10px 8px;
    background: rgba(255, 255, 255, 0.04);
    border-radius: 8px;
    border: 1px solid rgba(255, 255, 255, 0.06);
  }
  .ranger-usage-label {
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: rgba(255, 255, 255, 0.4);
    margin-bottom: 4px;
  }
  .ranger-usage-value {
    font-size: 16px;
    font-weight: 600;
    color: rgba(255, 255, 255, 0.9);
    font-family: var(--font-mono, monospace);
  }
  .ranger-history-cost {
    color: #E8B87A;
    font-family: var(--font-mono, monospace);
  }

  /* History */
  .ranger-history {
    padding: 8px 12px;
  }

  .ranger-empty {
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 64px 16px;
  }

  .ranger-empty-content {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 8px;
  }

  .ranger-empty-icon {
    width: 32px;
    height: 32px;
    margin-bottom: 4px;
  }

  .ranger-empty-text {
    font-size: 14px;
    font-weight: 500;
    color: rgba(255, 255, 255, 0.6);
    margin: 0;
  }

  .ranger-empty-subtext {
    font-size: 12px;
    color: rgba(255, 255, 255, 0.35);
    margin: 0;
  }

  .ranger-loading-spinner {
    display: block;
    width: 20px;
    height: 20px;
    border: 2px solid rgba(255, 255, 255, 0.15);
    border-top-color: #6FCF97;
    border-radius: 50%;
    animation: ranger-spin 0.6s linear infinite;
  }
  @keyframes ranger-spin {
    to { transform: rotate(360deg); }
  }

  .ranger-history-item {
    position: relative;
    display: block;
    width: 100%;
    padding: 12px 36px 12px 14px;
    border: none;
    background: none;
    text-align: left;
    cursor: pointer;
    border-radius: var(--radius-sm, 8px);
    transition: background 0.15s;
    border-bottom: 1px solid rgba(255, 255, 255, 0.04);
  }
  .ranger-history-item:last-child {
    border-bottom: none;
  }
  .ranger-history-item:hover {
    background: rgba(255, 255, 255, 0.06);
  }
  .ranger-history-item:focus-visible {
    outline: 2px solid #6FCF97;
    outline-offset: -2px;
  }

  .ranger-history-title {
    font-size: 13px;
    font-weight: 500;
    color: rgba(255, 255, 255, 0.9);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    margin-bottom: 3px;
  }

  .ranger-history-preview {
    font-size: 12px;
    color: rgba(255, 255, 255, 0.4);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    margin-bottom: 4px;
    line-height: 1.4;
  }

  .ranger-history-meta {
    display: flex;
    gap: 8px;
    font-size: 11px;
    color: rgba(255, 255, 255, 0.35);
    text-transform: uppercase;
    letter-spacing: 0.03em;
  }

  .ranger-history-count {
    text-transform: none;
  }

  .ranger-history-delete {
    position: absolute;
    right: 10px;
    top: 50%;
    transform: translateY(-50%);
    display: flex;
    align-items: center;
    justify-content: center;
    width: 22px;
    height: 22px;
    border: none;
    background: none;
    color: rgba(255, 255, 255, 0.3);
    cursor: pointer;
    border-radius: 4px;
    opacity: 0;
    transition: opacity 0.15s, color 0.15s, background 0.15s;
  }
  .ranger-history-item:hover .ranger-history-delete {
    opacity: 1;
  }
  .ranger-history-delete:hover {
    color: #EB5757;
    background: rgba(235, 87, 87, 0.12);
  }
  .ranger-history-delete:focus-visible {
    opacity: 1;
    outline: 2px solid #EB5757;
    outline-offset: 2px;
  }
  .ranger-history-delete svg {
    width: 12px;
    height: 12px;
  }

  /* ── Mobile: < 768px ── */
  @media (max-width: 768px) {
    .ranger-panel {
      width: 100vw;
      border-left: none;
      transform: translateY(100%);
      transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
      border-radius: 16px 16px 0 0;
    }
    .ranger-panel.visible {
      transform: translateY(0);
    }

    .ranger-header {
      padding: 16px 20px;
      padding-top: calc(16px + env(safe-area-inset-top, 0px));
    }

    .ranger-close {
      width: 36px;
      height: 36px;
      background: rgba(255, 255, 255, 0.1);
    }
    .ranger-close svg {
      width: 18px;
      height: 18px;
    }

    .ranger-input-area {
      padding: 12px 16px;
      padding-bottom: calc(12px + env(safe-area-inset-bottom, 0px));
    }

    .ranger-messages {
      padding: 16px 16px;
    }

    .ranger-welcome {
      padding: 0 16px 16px;
    }
  }
</style>
