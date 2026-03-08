<script>
  import { onMount } from 'svelte';
  import { formBuilder } from '$lib/api.js';
  import { navigate, addToast } from '$lib/stores.js';
  import { slugify } from '$lib/utils.js';
  import EmptyState from '$components/EmptyState.svelte';
  import ContextualTip from '$components/ContextualTip.svelte';
  import { tips } from '$lib/tips.js';

  let forms = $state([]);
  let loading = $state(true);
  let creating = $state(false);
  let formName = $state('');
  let formSlug = $state('');
  let submitting = $state(false);
  let deleteConfirmId = $state(null);

  onMount(loadForms);

  async function loadForms() {
    loading = true;
    try {
      const data = await formBuilder.list();
      forms = data.forms || [];
    } catch (err) {
      addToast(err.message, 'error');
    } finally {
      loading = false;
    }
  }

  function startCreate() {
    creating = true;
    formName = '';
    formSlug = '';
  }

  function cancelCreate() {
    creating = false;
    formName = '';
    formSlug = '';
  }

  function autoSlug() {
    formSlug = slugify(formName);
  }

  async function handleCreate() {
    if (!formName.trim() || !formSlug.trim()) return;
    submitting = true;
    try {
      const res = await formBuilder.create({
        name: formName.trim(),
        slug: formSlug.trim(),
        fields: [],
        settings: { submit_label: 'Submit', honeypot: true, confirmation_type: 'message', confirmation_message: 'Thank you! Your submission has been received.' },
      });
      addToast('Form created');
      navigate('form-builder', { formId: res.id });
    } catch (err) {
      addToast(err.message, 'error');
    } finally {
      submitting = false;
    }
  }

  async function handleDuplicate(form) {
    try {
      await formBuilder.duplicate(form.id);
      addToast('Form duplicated');
      await loadForms();
    } catch (err) {
      addToast(err.message, 'error');
    }
  }

  async function handleDelete(form) {
    if (deleteConfirmId !== form.id) {
      deleteConfirmId = form.id;
      return;
    }
    try {
      await formBuilder.delete(form.id);
      addToast('Form deleted');
      deleteConfirmId = null;
      await loadForms();
    } catch (err) {
      addToast(err.message, 'error');
    }
  }

  function statusLabel(s) {
    return { active: 'Active', inactive: 'Inactive', draft: 'Draft' }[s] || s;
  }
</script>

<div class="page-container" style="max-width: var(--content-width-wide);">
  <div class="page-header">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="page-header-icon"><path d="M9 11H3v10h6V11z"/><path d="M21 3H3v6h18V3z"/><path d="M21 11h-6v10h6V11z"/></svg>
    <div>
      <h1 class="page-title">Form Builder</h1>
      <p class="page-subtitle">Create and manage structured forms</p>
    </div>
    <div class="page-header-actions">
      {#if !creating}
        <button class="btn btn-primary" onclick={startCreate}>New Form</button>
      {/if}
    </div>
  </div>

  <ContextualTip tipKey="forms" message={tips.forms} />

  <!-- Inline create -->
  {#if creating}
    <div class="create-row">
      <div class="create-fields">
        <div class="create-field">
          <label class="create-label">Name</label>
          <input
            type="text"
            class="create-input"
            bind:value={formName}
            oninput={autoSlug}
            placeholder="Contact Form"
            autofocus
            onkeydown={(e) => { if (e.key === 'Enter') handleCreate(); if (e.key === 'Escape') cancelCreate(); }}
          />
        </div>
        <div class="create-field">
          <label class="create-label">Slug</label>
          <input
            type="text"
            class="create-input create-input-mono"
            bind:value={formSlug}
            placeholder="contact"
            onkeydown={(e) => { if (e.key === 'Enter') handleCreate(); if (e.key === 'Escape') cancelCreate(); }}
          />
        </div>
        {#if formSlug}
          <div class="create-hint">{`{% form '${formSlug}' %}`}</div>
        {/if}
      </div>
      <div class="create-actions">
        <button class="btn btn-secondary" onclick={cancelCreate}>Cancel</button>
        <button class="btn btn-primary" onclick={handleCreate} disabled={submitting || !formName.trim() || !formSlug.trim()}>
          {submitting ? 'Creating...' : 'Create'}
        </button>
      </div>
    </div>
  {/if}

  {#if loading}
    <div class="loading-overlay"><div class="spinner"></div></div>
  {:else if forms.length === 0 && !creating}
    <EmptyState
      icon='<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1"><path d="M9 11H3v10h6V11z"/><path d="M21 3H3v6h18V3z"/><path d="M21 11h-6v10h6V11z"/></svg>'
      title="No forms yet"
      description="Create your first form to start collecting submissions."
      ctaLabel="Create Form"
      ctaAction={startCreate}
    />
  {:else}
    <div class="forms-grid">
      {#each forms as form}
        <div class="form-card">
          <div class="form-card-header">
            <h3 class="form-card-name" onclick={() => navigate('form-builder', { formId: form.id })}>{form.name}</h3>
            <span class="form-card-status" class:status-active={form.status === 'active'} class:status-draft={form.status === 'draft'}>{statusLabel(form.status)}</span>
          </div>
          <div class="form-card-meta">
            <span class="form-card-slug">{form.slug}</span>
            <span class="form-card-dot"></span>
            <span>{form.fields?.length || 0} fields</span>
            <span class="form-card-dot"></span>
            <span>{form.submission_count || 0} submissions</span>
          </div>
          <div class="form-card-actions">
            <button class="form-card-link" onclick={() => navigate('form-builder', { formId: form.id })}>Edit</button>
            <button class="form-card-link" onclick={() => handleDuplicate(form)}>Duplicate</button>
            <button
              class="form-card-link form-card-link-danger"
              onclick={() => handleDelete(form)}
              onmouseleave={() => { if (deleteConfirmId === form.id) deleteConfirmId = null; }}
            >
              {deleteConfirmId === form.id ? 'Confirm delete' : 'Delete'}
            </button>
          </div>
        </div>
      {/each}
    </div>
  {/if}
</div>

<style>
  .create-row {
    display: flex;
    align-items: flex-end;
    gap: 16px;
    padding: 20px 0;
    margin-bottom: 8px;
    border-bottom: 1px solid var(--border-color, #e5e7eb);
  }

  .create-fields {
    flex: 1;
    display: flex;
    align-items: flex-end;
    gap: 16px;
    flex-wrap: wrap;
  }

  .create-field {
    display: flex;
    flex-direction: column;
    gap: 2px;
    min-width: 180px;
  }

  .create-label {
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--text-tertiary);
    font-weight: 500;
  }

  .create-input {
    padding: 6px 0;
    font-size: 14px;
    border: none;
    border-bottom: 1px solid var(--border-color, #e5e7eb);
    background: none;
    color: var(--text-primary);
    outline: none;
    transition: border-color 0.15s;
  }

  .create-input:focus {
    border-bottom-color: var(--accent-color, #2563eb);
  }

  .create-input-mono {
    font-family: var(--font-mono, monospace);
    font-size: 13px;
  }

  .create-hint {
    font-size: 12px;
    color: var(--text-tertiary);
    font-family: var(--font-mono, monospace);
    align-self: center;
    padding-bottom: 6px;
  }

  .create-actions {
    display: flex;
    gap: 8px;
    flex-shrink: 0;
  }

  .forms-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 16px;
    margin-top: 8px;
  }

  .form-card {
    background: var(--card-bg, #fff);
    border: 1px solid var(--border-color, #e5e7eb);
    border-radius: var(--radius-lg, 8px);
    padding: 20px;
    transition: border-color 0.15s;
  }

  .form-card:hover {
    border-color: var(--border-hover, #d1d5db);
  }

  .form-card-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 6px;
  }

  .form-card-name {
    font-size: 15px;
    font-weight: 600;
    color: var(--text-primary);
    cursor: pointer;
    margin: 0;
  }

  .form-card-name:hover {
    color: var(--accent-color, #2563eb);
  }

  .form-card-status {
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--text-tertiary);
    font-weight: 500;
  }

  .status-active {
    color: var(--success-color, #16a34a);
  }

  .status-draft {
    color: var(--warning-color, #d97706);
  }

  .form-card-meta {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 13px;
    color: var(--text-tertiary);
    margin-bottom: 16px;
  }

  .form-card-slug {
    font-family: var(--font-mono, monospace);
    font-size: 12px;
  }

  .form-card-dot {
    width: 3px;
    height: 3px;
    border-radius: 50%;
    background: var(--text-tertiary);
    opacity: 0.4;
  }

  .form-card-actions {
    display: flex;
    gap: 12px;
  }

  .form-card-link {
    background: none;
    border: none;
    font-size: 13px;
    color: var(--text-secondary);
    cursor: pointer;
    padding: 0;
    transition: color 0.1s;
  }

  .form-card-link:hover {
    color: var(--text-primary);
  }

  .form-card-link-danger:hover {
    color: var(--danger-color, #dc2626);
  }
</style>
