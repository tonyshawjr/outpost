<script>
  import { wrapForm } from '$lib/forge-tags.js';
  import { isFormHtml, parseFormHtml, slugify } from '$lib/forge-form-parser.js';
  import { formBuilder } from '$lib/api.js';
  import { addToast } from '$lib/stores.js';

  let { forms = [], selectedText = '', onConfirm, onCancel } = $props();

  // Detect mode
  let parsed = $derived(selectedText && isFormHtml(selectedText) ? parseFormHtml(selectedText) : null);
  let mode = $derived(parsed ? 'create' : forms.length > 0 ? 'pick' : 'manual');

  // Mode A: create from HTML
  let formName = $state('');
  let formSlug = $state('');
  let submitLabel = $state('');
  let slugError = $state('');
  let creating = $state(false);

  // Initialize from parsed data
  $effect(() => {
    if (parsed) {
      formName = parsed.name;
      formSlug = slugify(parsed.name);
      submitLabel = parsed.submitLabel;
      userEditedSlug = false;
    }
  });

  // Auto-update slug when name changes (only in create mode)
  let userEditedSlug = $state(false);
  function handleNameInput(e) {
    formName = e.target.value;
    if (!userEditedSlug) formSlug = slugify(formName);
  }
  function handleSlugInput(e) {
    formSlug = e.target.value;
    userEditedSlug = true;
    slugError = '';
  }

  // Mode B/C: existing form or manual slug
  let pickSlug = $state('');
  $effect(() => { if (forms.length > 0) pickSlug = forms[0]?.slug ?? ''; });

  let manualSlug = $state('');
  let manualInputEl = $state(null);
  $effect(() => { if (manualInputEl && mode === 'manual') manualInputEl.focus(); });

  // Field type icons (same as form builder)
  const TYPE_ICONS = {
    text: 'T', textarea: '\u00B6', number: '#', email: '@', phone: '\u260E', url: '\u2318',
    select: '\u25BE', radio: '\u25C9', checkbox: '\u2611', date: '\uD83D\uDCC5', time: '\u23F0',
    hidden: '\u25CC',
  };

  async function handleCreate() {
    if (creating) return;
    if (!formName.trim() || !formSlug.trim()) return;
    creating = true;
    slugError = '';
    try {
      await formBuilder.create({
        name: formName.trim(),
        slug: formSlug.trim(),
        fields: parsed?.fields ?? [],
        settings: {
          submit_label: submitLabel || 'Submit',
          honeypot: true,
          confirmation_type: 'message',
          confirmation_message: 'Thank you! Your submission has been received.',
        },
      });
      addToast('Form created');
      onConfirm(wrapForm({ formSlug: formSlug.trim() }));
    } catch (err) {
      const msg = err?.message || String(err);
      if (msg.toLowerCase().includes('slug') || msg.toLowerCase().includes('duplicate') || msg.toLowerCase().includes('exists')) {
        slugError = 'A form with this slug already exists';
      } else {
        slugError = msg;
      }
    } finally {
      creating = false;
    }
  }

  function handlePick() {
    if (!pickSlug.trim()) return;
    onConfirm(wrapForm({ formSlug: pickSlug.trim() }));
  }

  function handleManual() {
    if (!manualSlug.trim()) return;
    onConfirm(wrapForm({ formSlug: manualSlug.trim() }));
  }

  function handleKeydown(e) {
    if (e.key === 'Enter') {
      e.preventDefault();
      if (mode === 'create') handleCreate();
      else if (mode === 'pick') handlePick();
      else handleManual();
    }
  }
</script>

{#if mode === 'create'}
  <!-- Mode A: Create from HTML -->
  <div class="forge-field">
    <label class="forge-label">Form Name</label>
    <input
      class="forge-input"
      value={formName}
      oninput={handleNameInput}
      onkeydown={handleKeydown}
      placeholder="Contact Form"
      autocomplete="off"
    />
  </div>

  <div class="forge-field">
    <label class="forge-label">Slug</label>
    <input
      class="forge-input"
      class:forge-input-error={slugError}
      value={formSlug}
      oninput={handleSlugInput}
      onkeydown={handleKeydown}
      placeholder="contact-form"
      autocomplete="off"
      spellcheck="false"
    />
    {#if slugError}
      <div class="forge-error">{slugError}</div>
    {/if}
  </div>

  {#if parsed?.fields?.length}
    <div class="forge-field">
      <label class="forge-label">Detected Fields ({parsed.fields.length})</label>
      <div class="forge-field-list">
        {#each parsed.fields as f}
          <div class="forge-field-row">
            <span class="forge-field-icon">{TYPE_ICONS[f.type] || '?'}</span>
            <span class="forge-field-label">{f.label}</span>
            <span class="forge-field-type">{f.type}</span>
            {#if f.required}
              <span class="forge-field-required">*</span>
            {/if}
          </div>
        {/each}
      </div>
    </div>
  {:else}
    <div class="forge-hint">No fields detected — an empty form will be created.</div>
  {/if}

  <div class="forge-field">
    <label class="forge-label">Submit Label</label>
    <input
      class="forge-input"
      bind:value={submitLabel}
      onkeydown={handleKeydown}
      placeholder="Submit"
      autocomplete="off"
    />
  </div>

  <div style="font-size:11px;color:var(--text-muted)">
    Creates form and inserts <code>{`{% form '${formSlug || '...'}' %}`}</code>
  </div>

  <div class="forge-actions">
    <button class="btn btn-secondary" onclick={onCancel}>Cancel</button>
    <button class="btn btn-primary" onclick={handleCreate} disabled={!formName.trim() || !formSlug.trim() || creating}>
      {creating ? 'Creating...' : 'Create & Insert'}
    </button>
  </div>

{:else if mode === 'pick'}
  <!-- Mode B: Pick existing form -->
  <div class="forge-field">
    <label class="forge-label">Form</label>
    <select class="forge-select" bind:value={pickSlug}>
      {#each forms as f}
        <option value={f.slug}>{f.name || f.slug}</option>
      {/each}
    </select>
  </div>

  <div style="font-size:11px;color:var(--text-muted)">
    Inserts <code>{`{% form '${pickSlug || '...'}' %}`}</code>
  </div>

  <div class="forge-actions">
    <button class="btn btn-secondary" onclick={onCancel}>Cancel</button>
    <button class="btn btn-primary" onclick={handlePick} disabled={!pickSlug.trim()}>Apply</button>
  </div>

{:else}
  <!-- Mode C: Manual slug -->
  <div class="forge-field">
    <label class="forge-label">Form Slug</label>
    <input
      class="forge-input"
      bind:this={manualInputEl}
      bind:value={manualSlug}
      onkeydown={handleKeydown}
      placeholder="contact"
      autocomplete="off"
      spellcheck="false"
    />
  </div>

  <div style="font-size:11px;color:var(--text-muted)">
    Inserts <code>{`{% form '${manualSlug || '...'}' %}`}</code>
  </div>

  <div class="forge-actions">
    <button class="btn btn-secondary" onclick={onCancel}>Cancel</button>
    <button class="btn btn-primary" onclick={handleManual} disabled={!manualSlug.trim()}>Apply</button>
  </div>
{/if}

<style>
  .forge-input-error {
    border-color: var(--danger, #e53e3e) !important;
  }
  .forge-error {
    font-size: 11px;
    color: var(--danger, #e53e3e);
    margin-top: 4px;
  }
  .forge-hint {
    font-size: 11px;
    color: var(--text-muted);
    padding: 8px 0;
  }
  .forge-field-list {
    display: flex;
    flex-direction: column;
    gap: 2px;
    max-height: 160px;
    overflow-y: auto;
    border: 1px solid var(--border-color, #e2e8f0);
    border-radius: 6px;
    padding: 4px;
  }
  .forge-field-row {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 3px 6px;
    border-radius: 4px;
    font-size: 12px;
  }
  .forge-field-row:nth-child(odd) {
    background: var(--bg-subtle, rgba(0,0,0,.02));
  }
  .forge-field-icon {
    width: 16px;
    text-align: center;
    font-size: 11px;
    color: var(--text-muted);
    flex-shrink: 0;
  }
  .forge-field-label {
    flex: 1;
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }
  .forge-field-type {
    font-size: 10px;
    text-transform: uppercase;
    color: var(--text-muted);
    letter-spacing: 0.5px;
    flex-shrink: 0;
  }
  .forge-field-required {
    color: var(--danger, #e53e3e);
    font-weight: 600;
    flex-shrink: 0;
  }
</style>
