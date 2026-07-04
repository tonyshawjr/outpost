<script>
  import NodeView from './NodeView.svelte';

  let { editor, preview = false } = $props();
  let root = $derived(editor.tree?.nodes?.[editor.tree.root]);
</script>

{#if root}
  <NodeView node={root} tree={editor.tree} {editor} {preview} />
{/if}
