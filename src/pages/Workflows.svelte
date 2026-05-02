<script>
  import { onMount } from 'svelte';
  import { workflows as workflowsApi } from '$lib/api.js';
  import { addToast, navigate } from '$lib/stores.js';
  import EmptyState from '$components/EmptyState.svelte';
  import ColorPicker from '$components/ColorPicker.svelte';
  import Checkbox from '$components/Checkbox.svelte';

  let workflowsList = $state([]);
  let loading = $state(true);
  let showEditor = $state(false);
  let editingWorkflow = $state(null);
  let submitting = $state(false);

  // Form state
  let formName = $state('');
  let formSlug = $state('');
  let formStages = $state([]);

  const ROLE_OPTIONS = [
    { value: 'editor', label: 'Editor' },
    { value: 'developer', label: 'Developer' },
    { value: 'admin', label: 'Admin' },
    { value: 'super_admin', label: 'Super Admin' },
  ];

  const STAGE_COLORS = [
    '#8A857D', '#E8B87A', '#6FCF97', '#2D5A47',
    '#5A9BD5', '#D97706', '#9B59B6', '#E74C3C',
    '#1ABC9C', '#F39C12', '#3498DB', '#E91E63',
  ];

  onMount(async () => {
    await loadWorkflows();
  });

  async function loadWorkflows() {
    loading = true;
    try {
      const data = await workflowsApi.list();
      workflowsList = data.workflows || [];
    } catch (err) {
      addToast(err.message, 'error');
    } finally {
      loading = false;
    }
  }

  function slugifyName(text) {
    return text.toLowerCase().replace(/[^a-z0-9-]/g, '-').replace(/-+/g, '-').replace(/^-|-$/g, '');
  }

  function autoSlug() {
    if (!editingWorkflow) formSlug = slugifyName(formName);
  }

  function autoStageSlug(i) {
    if (!formStages[i].slugEdited) {
      formStages[i].slug = slugifyName(formStages[i].name);
    }
  }

  function resetForm() {
    formName = '';
    formSlug = '';
    formStages = [
      { name: 'Draft', slug: 'draft', color: '#8A857D', roles: ['editor', 'developer', 'admin', 'super_admin'], can_move_to: [], slugEdited: true },
      { name: 'Published', slug: 'published', color: '#2D5A47', roles: ['admin', 'super_admin'], can_move_to: [], slugEdited: true },
    ];
    editingWorkflow = null;
    showEditor = false;
  }

  function openCreate() {
    resetForm();
    showEditor = true;
  }

  function openEdit(wf) {
    editingWorkflow = wf;
    formName = wf.name;
    formSlug = wf.slug;
    formStages = (wf.stages || []).map(s => ({
      ...s,
      roles: s.roles || [],
      can_move_to: s.can_move_to || [],
      slugEdited: true,
    }));
    showEditor = true;
  }

  function addStage() {
    const idx = formStages.length;
    const color = STAGE_COLORS[idx % STAGE_COLORS.length];
    // Insert before the last stage (published)
    const newStage = { name: '', slug: '', color, roles: ['admin', 'super_admin'], can_move_to: [], slugEdited: false };
    formStages = [...formStages.slice(0, -1), newStage, formStages[formStages.length - 1]];
  }

  function removeStage(i) {
    const stage = formStages[i];
    if (stage.slug === 'draft' || stage.slug === 'published') {
      addToast('Cannot remove required stages (Draft and Published)', 'error');
      return;
    }
    const removed = stage.slug;
    formStages = formStages.filter((_, idx) => idx !== i).map(s => ({
      ...s,
      can_move_to: (s.can_move_to || []).filter(t => t !== removed),
    }));
  }

  function toggleTransition(fromIdx, toSlug) {
    const current = formStages[fromIdx].can_move_to || [];
    if (current.includes(toSlug)) {
      formStages[fromIdx].can_move_to = current.filter(s => s !== toSlug);
    } else {
      formStages[fromIdx].can_move_to = [...current, toSlug];
    }
  }

  function toggleRole(stageIdx, role) {
    const current = formStages[stageIdx].roles || [];
    if (current.includes(role)) {
      formStages[stageIdx].roles = current.filter(r => r !== role);
    } else {
      formStages[stageIdx].roles = [...current, role];
    }
  }

  async function saveWorkflow() {
    if (!formName || formStages.length < 2) return;
    submitting = true;
    try {
      const stages = formStages.map(({ slugEdited, ...rest }) => rest);
      if (editingWorkflow) {
        await workflowsApi.update(editingWorkflow.id, { name: formName, stages });
        addToast('Workflow updated', 'success');
      } else {
        await workflowsApi.create({ name: formName, slug: formSlug, stages });
        addToast('Workflow created', 'success');
      }
      await loadWorkflows();
      resetForm();
    } catch (err) {
      addToast(err.message, 'error');
    } finally {
      submitting = false;
    }
  }

  async function deleteWorkflow(wf) {
    if (wf.is_default) {
      addToast('Cannot delete the default workflow', 'error');
      return;
    }
    if (!confirm(`Delete workflow "${wf.name}"?`)) return;
    try {
      await workflowsApi.delete(wf.id);
      await loadWorkflows();
      addToast('Workflow deleted', 'success');
    } catch (err) {
      addToast(err.message, 'error');
    }
  }
</script>

<div class="workflows-page">
  <div class="page-header">
    <div class="page-header-icon sage">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>
    </div>
    <div class="page-header-content">
      <h1 class="page-title">Workflows</h1>
      <p class="page-subtitle">Define custom approval stages for your collections</p>
    </div>
    <div class="page-header-actions">
      <button class="btn btn-primary" onclick={openCreate}>
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        New Workflow
      </button>
    </div>
  </div>

  {#if loading}
    <div class="loading-overlay"><div class="spinner"></div></div>
  {:else if workflowsList.length === 0}
    <EmptyState
      icon='<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>'
      title="No workflows yet"
      description="Create custom approval workflows for your content."
      ctaLabel="Create Workflow"
      ctaAction={openCreate}
    />
  {:else}
    <div class="wf-grid">
      {#each workflowsList as wf (wf.id)}
        <div class="wf-card">
          <div class="wf-card-header">
            <div>
              <div class="wf-card-name">
                {wf.name}
                {#if wf.is_default}
                  <span class="wf-default-badge">Default</span>
                {/if}
              </div>
              <div class="wf-card-meta">
                {wf.stages.length} stage{wf.stages.length !== 1 ? 's' : ''}
                {#if wf.collection_count > 0}
                  &middot; {wf.collection_count} collection{wf.collection_count !== 1 ? 's' : ''}
                {/if}
              </div>
            </div>
          </div>

          <!-- Visual Pipeline -->
          <div class="wf-pipeline">
            {#each wf.stages as stage, i}
              <div class="wf-stage-dot-wrap">
                <div class="wf-stage-dot" style="background: {stage.color};" title={stage.name}></div>
                <span class="wf-stage-label">{stage.name}</span>
              </div>
              {#if i < wf.stages.length - 1}
                <div class="wf-stage-arrow">
                  <svg width="16" height="8" viewBox="0 0 16 8" fill="none"><path d="M0 4h14M11 1l3 3-3 3" stroke="var(--dim)" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </div>
              {/if}
            {/each}
          </div>

          <div class="wf-card-actions">
            <button class="btn btn-secondary btn-sm" onclick={() => openEdit(wf)}>Edit</button>
            {#if !wf.is_default}
              <button class="btn btn-secondary btn-sm btn-danger-text" onclick={() => deleteWorkflow(wf)}>Delete</button>
            {/if}
          </div>
        </div>
      {/each}
    </div>
  {/if}
</div>

<!-- Create / Edit Workflow Modal -->
{#if showEditor}
  <div class="modal-overlay" onclick={resetForm} role="dialog" tabindex="-1">
    <div class="modal modal-lg" onclick={(e) => e.stopPropagation()} role="document">
      <div class="modal-header">
        <h2 class="modal-title">{editingWorkflow ? 'Edit' : 'New'} Workflow</h2>
        <button class="btn btn-ghost btn-sm" onclick={resetForm} aria-label="Close">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
      </div>

      <div class="form-group">
        <label class="form-label" for="wf-name">Name</label>
        <input id="wf-name" class="input" type="text" bind:value={formName} oninput={autoSlug} placeholder="Editorial Review" />
      </div>

      {#if !editingWorkflow}
        <div class="form-group">
          <label class="form-label" for="wf-slug">Slug</label>
          <input id="wf-slug" class="input" type="text" bind:value={formSlug} placeholder="editorial-review" />
          <span class="wf-hint">URL-safe identifier. Cannot be changed after creation.</span>
        </div>
      {:else}
        <div class="form-group">
          <label class="form-label">Slug</label>
          <div class="wf-hint" style="padding: 6px 0;">
            <code style="font-size: 12px;">{formSlug}</code>
          </div>
        </div>
      {/if}

      <!-- Visual preview -->
      <div class="form-group">
        <label class="form-label">Pipeline Preview</label>
        <div class="wf-pipeline wf-pipeline-preview">
          {#each formStages as stage, i}
            <div class="wf-stage-dot-wrap">
              <div class="wf-stage-dot" style="background: {stage.color};" title={stage.name || 'Untitled'}></div>
              <span class="wf-stage-label">{stage.name || 'Untitled'}</span>
            </div>
            {#if i < formStages.length - 1}
              <div class="wf-stage-arrow">
                <svg width="16" height="8" viewBox="0 0 16 8" fill="none"><path d="M0 4h14M11 1l3 3-3 3" stroke="var(--dim)" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
              </div>
            {/if}
          {/each}
        </div>
      </div>

      <!-- Stages -->
      <div class="form-group">
        <label class="form-label">Stages</label>
        <p class="wf-hint" style="margin-bottom: 8px;">
          Define the stages content moves through. "Draft" and "Published" are required.
        </p>

        {#each formStages as stage, i (i)}
          <div class="wf-stage-card">
            <div class="wf-stage-card-header">
              <ColorPicker bind:value={stage.color} />
              <input
                class="input wf-stage-name"
                type="text"
                bind:value={stage.name}
                oninput={() => autoStageSlug(i)}
                placeholder="Stage name"
                disabled={stage.slug === 'draft' || stage.slug === 'published'}
              />
              <span class="wf-stage-slug-display">{stage.slug || '...'}</span>
              {#if stage.slug !== 'draft' && stage.slug !== 'published'}
                <button class="btn btn-ghost btn-sm" onclick={() => removeStage(i)} aria-label="Remove stage" title="Remove stage">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
              {/if}
            </div>

            <!-- Transitions -->
            <div class="wf-stage-section">
              <label class="wf-section-label">Can move to</label>
              <div class="wf-transition-checks">
                {#each formStages as target, ti}
                  {#if ti !== i}
                    <div class="wf-check-label">
                      <Checkbox checked={(stage.can_move_to || []).includes(target.slug)} onchange={() => toggleTransition(i, target.slug)} />
                      <span class="wf-check-dot" style="background: {target.color};"></span>
                      {target.name || target.slug}
                    </div>
                  {/if}
                {/each}
              </div>
            </div>

            <!-- Roles -->
            <div class="wf-stage-section">
              <label class="wf-section-label">Who can move items here</label>
              <div class="wf-transition-checks">
                {#each ROLE_OPTIONS as opt}
                  <div class="wf-check-label">
                    <Checkbox checked={(stage.roles || []).includes(opt.value)} onchange={() => toggleRole(i, opt.value)} />
                    {opt.label}
                  </div>
                {/each}
              </div>
            </div>
          </div>
        {/each}

        <button class="btn btn-secondary btn-sm" onclick={addStage} style="margin-top: 4px;">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
          Add Stage
        </button>
      </div>

      <div class="modal-footer">
        <button class="btn btn-secondary" onclick={resetForm}>Cancel</button>
        <button class="btn btn-primary" onclick={saveWorkflow} disabled={!formName || formStages.length < 2 || submitting}>
          {submitting ? 'Saving...' : (editingWorkflow ? 'Save Changes' : 'Create Workflow')}
        </button>
      </div>
    </div>
  </div>
{/if}

<style>
  .workflows-page {
    max-width: var(--content-width);
  }

  .wf-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
    gap: var(--space-lg);
  }

  .wf-card {
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    padding: var(--space-lg);
    background: var(--bg);
    transition: border-color 0.15s;
  }

  .wf-card:hover {
    border-color: var(--border-secondary, var(--border));
  }

  .wf-card-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    margin-bottom: var(--space-md);
  }

  .wf-card-name {
    font-family: var(--font);
    font-size: 18px;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 8px;
  }

  .wf-default-badge {
    font-family: var(--font);
    font-size: 10px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--dim);
    padding: 2px 6px;
    border: 1px solid var(--border);
    border-radius: 3px;
  }

  .wf-card-meta {
    font-size: var(--font-size-sm);
    color: var(--dim);
    margin-top: 2px;
  }

  .wf-card-actions {
    display: flex;
    gap: var(--space-xs);
    margin-top: var(--space-md);
  }

  /* Pipeline visualization */
  .wf-pipeline {
    display: flex;
    align-items: center;
    gap: 4px;
    padding: var(--space-md) 0;
    flex-wrap: wrap;
  }

  .wf-pipeline-preview {
    padding: var(--space-sm) var(--space-md);
    background: var(--hover);
    border-radius: var(--radius-md);
    border: 1px solid var(--border);
  }

  .wf-stage-dot-wrap {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 4px;
  }

  .wf-stage-dot {
    width: 12px;
    height: 12px;
    border-radius: 50%;
    flex-shrink: 0;
  }

  .wf-stage-label {
    font-size: 10px;
    font-weight: 500;
    color: var(--dim);
    text-transform: uppercase;
    letter-spacing: 0.03em;
    white-space: nowrap;
  }

  .wf-stage-arrow {
    display: flex;
    align-items: center;
    margin-bottom: 14px; /* align with dots, not labels */
  }

  /* Stage editor cards */
  .wf-stage-card {
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    padding: var(--space-md);
    margin-bottom: var(--space-sm);
    background: var(--hover);
  }

  .wf-stage-card-header {
    display: flex;
    align-items: center;
    gap: var(--space-sm);
  }

  .wf-color-input {
    width: 28px;
    height: 28px;
    border: none;
    border-radius: 4px;
    cursor: pointer;
    padding: 0;
    background: none;
    flex-shrink: 0;
  }

  .wf-stage-name {
    flex: 1;
    min-width: 0;
  }

  .wf-stage-slug-display {
    font-size: 11px;
    color: var(--dim);
    font-family: var(--font-mono);
    flex-shrink: 0;
  }

  .wf-stage-section {
    margin-top: var(--space-sm);
    padding-top: var(--space-sm);
    border-top: 1px solid var(--border);
  }

  .wf-section-label {
    display: block;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--dim);
    margin-bottom: 6px;
  }

  .wf-transition-checks {
    display: flex;
    flex-wrap: wrap;
    gap: 6px 12px;
  }

  .wf-check-label {
    display: flex;
    align-items: center;
    gap: 4px;
    font-size: 13px;
    color: var(--sec);
    cursor: pointer;
  }

  .wf-check-label input[type="checkbox"] {
    accent-color: var(--purple);
  }

  .wf-check-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    flex-shrink: 0;
  }

  .wf-hint {
    font-size: var(--font-size-xs);
    color: var(--dim);
  }

  .btn-danger-text {
    color: var(--error, #e53e3e);
  }

  .btn-danger-text:hover {
    background: rgba(229, 62, 62, 0.08);
    color: var(--error, #e53e3e);
  }

  @media (max-width: 768px) {
    .wf-grid {
      grid-template-columns: 1fr;
    }
  }
</style>
