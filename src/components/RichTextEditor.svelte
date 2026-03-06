<script>
  import { onMount, onDestroy } from 'svelte';
  import { Editor } from '@tiptap/core';
  import StarterKit from '@tiptap/starter-kit';
  import Link from '@tiptap/extension-link';
  import Image from '@tiptap/extension-image';
  import Placeholder from '@tiptap/extension-placeholder';

  let {
    content = '',
    onupdate = () => {},
    placeholder = 'Start writing...',
  } = $props();

  let element = $state(null);
  let editor = $state(null);

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
        Placeholder.configure({ placeholder }),
      ],
      content: content,
      onTransaction: () => {
        // Force Svelte reactivity
        editor = editor;
      },
      onUpdate: ({ editor: ed }) => {
        onupdate(ed.getHTML());
      },
    });
  });

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

    <button type="button" onclick={addImage} title="Add image">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
    </button>

    <button type="button" onclick={setHR} title="Horizontal rule">
      &mdash;
    </button>
  </div>

  <div bind:this={element}></div>
</div>
