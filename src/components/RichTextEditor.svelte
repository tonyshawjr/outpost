<script>
  import { onMount, onDestroy } from 'svelte';
  import { Editor, Node, mergeAttributes } from '@tiptap/core';
  import StarterKit from '@tiptap/starter-kit';
  import Link from '@tiptap/extension-link';
  import Image from '@tiptap/extension-image';
  import Placeholder from '@tiptap/extension-placeholder';
  import { embeds as embedsApi, grammar as grammarApi } from '../lib/api.js';
  import { GrammarExtension, extractText, mapMatches, setGrammarMatches, clearGrammar } from '../lib/grammar-extension.js';

  let {
    content = '',
    onupdate = () => {},
    placeholder = 'Start writing...',
  } = $props();

  let element = $state(null);
  let editor = $state(null);

  const EmbedNode = Node.create({
    name: 'embed',
    group: 'block',
    atom: true,
    draggable: true,
    selectable: true,
    addAttributes() {
      return {
        src: { default: null },
        title: { default: '' },
        width: { default: 16 },
        height: { default: 9 },
      };
    },
    parseHTML() {
      return [{
        tag: 'iframe[src]',
        getAttrs: (el) => ({
          src: el.getAttribute('src'),
          title: el.getAttribute('title') || '',
          width: el.getAttribute('width') || 16,
          height: el.getAttribute('height') || 9,
        }),
      }];
    },
    renderHTML({ HTMLAttributes }) {
      return ['span', { class: 'oc-embed' }, ['iframe', mergeAttributes(HTMLAttributes, {
        loading: 'lazy',
        allowfullscreen: 'true',
        frameborder: '0',
        referrerpolicy: 'strict-origin-when-cross-origin',
      })]];
    },
  });

  onMount(() => {
    editor = new Editor({
      element: element,
      extensions: [
        StarterKit,
        Link.configure({
          openOnClick: false,
          HTMLAttributes: { rel: 'noopener noreferrer' },
        }),
        Image,
        EmbedNode,
        GrammarExtension,
        Placeholder.configure({ placeholder }),
      ],
      content: content,
      editorProps: {
        handleClick(view, pos) {
          if (!grammarOn) return false;
          const m = grammarMatches.find((mm) => pos >= mm.from && pos < mm.to);
          if (m) { showPopover(m, view); return false; }
          popover = null;
          return false;
        },
      },
      onTransaction: () => {
        editor = editor;
      },
      onUpdate: ({ editor: ed }) => {
        onupdate(ed.getHTML());
        if (grammarOn) scheduleGrammar();
      },
    });
  });

  let grammarOn = $state(false);
  let grammarBusy = $state(false);
  let grammarMatches = $state([]);
  let popover = $state(null);
  let grammarTimer = null;

  async function runGrammar() {
    if (!editor || !grammarOn) return;
    const { text, segs } = extractText(editor.state.doc);
    if (!text.trim()) { grammarMatches = []; setGrammarMatches(editor.view, []); return; }
    grammarBusy = true;
    try {
      const res = await grammarApi.check(text, 'auto');
      const mapped = mapMatches(res.matches || [], segs);
      grammarMatches = mapped;
      setGrammarMatches(editor.view, mapped);
    } catch (_) {
      grammarMatches = grammarMatches;
    } finally {
      grammarBusy = false;
    }
  }

  function scheduleGrammar() {
    clearTimeout(grammarTimer);
    grammarTimer = setTimeout(runGrammar, 900);
  }

  function toggleGrammar() {
    grammarOn = !grammarOn;
    popover = null;
    if (grammarOn) {
      runGrammar();
    } else {
      grammarMatches = [];
      if (editor) clearGrammar(editor.view);
    }
  }

  function showPopover(m, view) {
    const coords = view.coordsAtPos(m.from);
    popover = { match: m, x: coords.left, y: coords.bottom + 4 };
  }

  function applyFix(rep) {
    if (!popover) return;
    const m = popover.match;
    editor?.chain().focus().insertContentAt({ from: m.from, to: m.to }, { type: 'text', text: rep }).run();
    grammarMatches = grammarMatches.filter((x) => x !== m);
    if (editor) setGrammarMatches(editor.view, grammarMatches);
    popover = null;
    scheduleGrammar();
  }

  onDestroy(() => {
    if (editor) editor.destroy();
  });

  function toggleBold() { editor?.chain().focus().toggleBold().run(); }
  function toggleItalic() { editor?.chain().focus().toggleItalic().run(); }
  function toggleStrike() { editor?.chain().focus().toggleStrike().run(); }
  function toggleH2() { editor?.chain().focus().toggleHeading({ level: 2 }).run(); }
  function toggleH3() { editor?.chain().focus().toggleHeading({ level: 3 }).run(); }
  function toggleBulletList() { editor?.chain().focus().toggleBulletList().run(); }
  function toggleOrderedList() { editor?.chain().focus().toggleOrderedList().run(); }
  function toggleBlockquote() { editor?.chain().focus().toggleBlockquote().run(); }
  function toggleCodeBlock() { editor?.chain().focus().toggleCodeBlock().run(); }
  function setHR() { editor?.chain().focus().setHorizontalRule().run(); }

  function addLink() {
    const url = prompt('Enter URL:');
    if (url) {
      editor?.chain().focus().setLink({ href: url }).run();
    }
  }

  function removeLink() {
    editor?.chain().focus().unsetLink().run();
  }

  function addImage() {
    const url = prompt('Enter image URL:');
    if (url) {
      editor?.chain().focus().setImage({ src: url }).run();
    }
  }

  let embedding = $state(false);
  async function addEmbed() {
    if (embedding) return;
    const url = prompt('Paste a YouTube, Vimeo, Spotify, SoundCloud, or Flickr link');
    if (!url) return;
    embedding = true;
    try {
      const res = await embedsApi.resolve(url.trim());
      if (res.kind === 'photo') {
        editor?.chain().focus().setImage({ src: res.embedUrl, alt: res.title || '' }).run();
      } else {
        editor?.chain().focus().insertContent({
          type: 'embed',
          attrs: { src: res.embedUrl, title: res.title || '', width: res.width || 16, height: res.height || 9 },
        }).run();
      }
    } catch (e) {
      alert(e?.message || 'Could not embed that link');
    } finally {
      embedding = false;
    }
  }
</script>

<div class="richtext-editor">
  <div class="richtext-toolbar">
    <button
      type="button"
      onclick={toggleBold}
      class:active={editor?.isActive('bold')}
      title="Bold"
    ><strong>B</strong></button>

    <button
      type="button"
      onclick={toggleItalic}
      class:active={editor?.isActive('italic')}
      title="Italic"
    ><em>I</em></button>

    <button
      type="button"
      onclick={toggleStrike}
      class:active={editor?.isActive('strike')}
      title="Strikethrough"
    ><s>S</s></button>

    <div class="separator"></div>

    <button
      type="button"
      onclick={toggleH2}
      class:active={editor?.isActive('heading', { level: 2 })}
      title="Heading 2"
    >H2</button>

    <button
      type="button"
      onclick={toggleH3}
      class:active={editor?.isActive('heading', { level: 3 })}
      title="Heading 3"
    >H3</button>

    <div class="separator"></div>

    <button
      type="button"
      onclick={toggleBulletList}
      class:active={editor?.isActive('bulletList')}
      title="Bullet list"
    >
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><circle cx="4" cy="6" r="1" fill="currentColor"/><circle cx="4" cy="12" r="1" fill="currentColor"/><circle cx="4" cy="18" r="1" fill="currentColor"/></svg>
    </button>

    <button
      type="button"
      onclick={toggleOrderedList}
      class:active={editor?.isActive('orderedList')}
      title="Ordered list"
    >
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="10" y1="6" x2="21" y2="6"/><line x1="10" y1="12" x2="21" y2="12"/><line x1="10" y1="18" x2="21" y2="18"/><text x="2" y="8" fill="currentColor" font-size="8" stroke="none">1</text><text x="2" y="14" fill="currentColor" font-size="8" stroke="none">2</text><text x="2" y="20" fill="currentColor" font-size="8" stroke="none">3</text></svg>
    </button>

    <button
      type="button"
      onclick={toggleBlockquote}
      class:active={editor?.isActive('blockquote')}
      title="Blockquote"
    >
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 21c3 0 7-1 7-8V5c0-1.25-.756-2.017-2-2H4c-1.25 0-2 .75-2 1.972V11c0 1.25.75 2 2 2 1 0 1 0 1 1v1c0 1-1 2-2 2s-1 .008-1 1.031V21z"/><path d="M15 21c3 0 7-1 7-8V5c0-1.25-.757-2.017-2-2h-4c-1.25 0-2 .75-2 1.972V11c0 1.25.75 2 2 2h.75c0 2.25.25 4-2.75 4v3z"/></svg>
    </button>

    <button
      type="button"
      onclick={toggleCodeBlock}
      class:active={editor?.isActive('codeBlock')}
      title="Code block"
    >&lt;/&gt;</button>

    <div class="separator"></div>

    <button
      type="button"
      onclick={addLink}
      class:active={editor?.isActive('link')}
      title="Add link"
    >
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 13a5 5 0 007.54.54l3-3a5 5 0 00-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 00-7.54-.54l-3 3a5 5 0 007.07 7.07l1.71-1.71"/></svg>
    </button>

    {#if editor?.isActive('link')}
      <button type="button" onclick={removeLink} title="Remove link">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      </button>
    {/if}

    <button type="button" onclick={addImage} title="Add image" aria-label="Add image">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
    </button>

    <button type="button" onclick={addEmbed} disabled={embedding} title="Embed a video or media link" aria-label="Embed a video or media link">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="2" y="3" width="20" height="14" rx="2" ry="2"/><polygon points="10 8 15 10 10 12" fill="currentColor" stroke="none"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
    </button>

    <button type="button" onclick={setHR} title="Horizontal rule" aria-label="Horizontal rule">
      &mdash;
    </button>

    <div class="separator"></div>

    <button type="button" onclick={toggleGrammar} class:active={grammarOn} title="Check grammar & spelling" aria-label="Check grammar and spelling" aria-pressed={grammarOn}>
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M4 7V5h16v2"/><path d="M9 20h6"/><path d="M12 5v15"/><polyline points="16 16 18 18 22 14"/></svg>
    </button>
  </div>

  <div bind:this={element}></div>

  {#if popover}
    <div class="lt-popover" style="left:{popover.x}px; top:{popover.y}px" role="dialog" aria-label="Writing suggestion">
      <p class="lt-msg">{popover.match.message}</p>
      {#if popover.match.replacements.length}
        <div class="lt-fixes">
          {#each popover.match.replacements as rep (rep)}
            <button type="button" class="lt-fix" onclick={() => applyFix(rep)}>{rep}</button>
          {/each}
        </div>
      {:else}
        <p class="lt-nofix">No suggestion available.</p>
      {/if}
      <button type="button" class="lt-dismiss" onclick={() => (popover = null)}>Dismiss</button>
    </div>
  {/if}
</div>

<svelte:window onmousedown={(e) => { if (popover && !e.target.closest('.lt-popover') && !e.target.closest('.lt-mark')) popover = null; }} />

