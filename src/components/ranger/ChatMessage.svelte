<script>
  import ToolCallCard from './ToolCallCard.svelte';

  let { message } = $props();

  function renderMarkdown(text) {
    if (!text) return '';
    let html = text
      // Escape HTML first
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      // Fenced code blocks
      .replace(/```(\w*)\n([\s\S]*?)```/g, (_, lang, code) => {
        return `<pre class="ranger-code-block"><code>${code.trim()}</code></pre>`;
      })
      // Inline code
      .replace(/`([^`]+)`/g, '<code class="ranger-inline-code">$1</code>')
      // Bold
      .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
      // Italic
      .replace(/\*(.+?)\*/g, '<em>$1</em>')
      // Links (block javascript: and data: URLs)
      .replace(/\[([^\]]+)\]\(([^)]+)\)/g, (_, label, url) => {
        if (/^\s*(javascript|data|vbscript):/i.test(url)) return label;
        return `<a href="${url}" target="_blank" rel="noopener">${label}</a>`;
      })
      // Line breaks — double newlines become paragraph breaks, single newlines become <br>
      .replace(/\n{2,}/g, '<br><br>')
      .replace(/\n/g, '<br>')
      // Clean up trailing breaks
      .replace(/(<br>)+$/g, '');
    return html;
  }

  let rendered = $derived(renderMarkdown(message.content));
  let isUser = $derived(message.role === 'user');
</script>

<div class="chat-msg" class:user={isUser} class:assistant={!isUser}>
  {#if !isUser}
    <div class="msg-avatar">
      <svg viewBox="0 0 20 20" fill="none">
        <path d="M10 2L3 7v6l7 5 7-5V7l-7-5z" stroke="var(--accent, #2D5A47)" stroke-width="1.2" fill="var(--accent-soft, rgba(45, 90, 71, 0.08))"/>
        <circle cx="10" cy="10" r="2" fill="var(--accent, #2D5A47)"/>
      </svg>
    </div>
  {/if}
  <div class="msg-content">
    {#if message.images?.length}
      <div class="msg-images">
        {#each message.images as src}
          <img class="msg-image" {src} alt="Screenshot" />
        {/each}
      </div>
    {/if}
    {#if message.content}
      <div class="msg-text">{@html rendered}</div>
    {/if}
    {#if message.tool_calls?.length}
      <div class="msg-tools">
        {#each message.tool_calls as tool}
          <ToolCallCard {tool} />
        {/each}
      </div>
    {/if}
  </div>
</div>

<style>
  .msg-images {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    margin-bottom: 6px;
  }
  .msg-image {
    max-width: 200px;
    max-height: 150px;
    border-radius: 6px;
    border: 1px solid rgba(255, 255, 255, 0.1);
    object-fit: contain;
  }
  .chat-msg {
    display: flex;
    gap: 10px;
    padding: 2px 0;
    max-width: 100%;
  }

  .chat-msg.user {
    justify-content: flex-end;
  }

  .chat-msg.assistant {
    justify-content: flex-start;
  }

  .msg-avatar {
    flex-shrink: 0;
    width: 24px;
    height: 24px;
    margin-top: 2px;
  }

  .msg-avatar svg {
    width: 24px;
    height: 24px;
  }

  .msg-content {
    max-width: 85%;
    min-width: 0;
  }

  .user .msg-content {
    background: rgba(255, 255, 255, 0.08);
    border-radius: 14px 14px 4px 14px;
    padding: 10px 14px;
  }

  .assistant .msg-content {
    padding: 2px 0;
  }

  .msg-text {
    font-size: 14px;
    line-height: 1.6;
    color: rgba(255, 255, 255, 0.88);
    word-break: break-word;
  }

  .user .msg-text {
    color: rgba(255, 255, 255, 0.9);
    font-size: 14px;
  }

  .msg-text :global(strong) {
    font-weight: 600;
    color: rgba(255, 255, 255, 0.95);
  }

  .msg-text :global(em) {
    color: rgba(255, 255, 255, 0.75);
  }

  .msg-text :global(a) {
    color: #6FCF97;
    text-decoration: underline;
    text-decoration-color: rgba(111, 207, 151, 0.3);
    text-underline-offset: 2px;
    transition: text-decoration-color 0.15s;
  }

  .msg-text :global(a:hover) {
    text-decoration-color: #6FCF97;
  }

  .msg-text :global(a:focus-visible) {
    outline: 2px solid #6FCF97;
    outline-offset: 2px;
    border-radius: 2px;
  }

  .msg-text :global(.ranger-inline-code) {
    font-family: var(--font-mono, monospace);
    font-size: 12.5px;
    background: rgba(0, 0, 0, 0.25);
    padding: 2px 6px;
    border-radius: 4px;
    color: #E8B87A;
    border: 1px solid rgba(255, 255, 255, 0.06);
  }

  .msg-text :global(.ranger-code-block) {
    font-family: var(--font-mono, monospace);
    font-size: 12.5px;
    line-height: 1.55;
    background: rgba(0, 0, 0, 0.35);
    color: rgba(255, 255, 255, 0.85);
    padding: 14px 16px;
    border-radius: 8px;
    overflow-x: auto;
    margin: 10px 0;
    white-space: pre-wrap;
    word-break: break-all;
    border: 1px solid rgba(255, 255, 255, 0.06);
  }

  .msg-tools {
    margin-top: 8px;
    display: flex;
    flex-direction: column;
    gap: 4px;
  }
</style>
