<script>
  let { fields = [], settings = {}, formSlug = '' } = $props();

  function choiceLabel(choice) {
    if (typeof choice === 'string') return choice;
    return choice.label || choice.value || '';
  }

  function choiceValue(choice) {
    if (typeof choice === 'string') return choice;
    return choice.value || choice.label || '';
  }
</script>

<div class="preview-wrapper">
  <div class="preview-form">
    {#each fields as field, i}
      {#if field.type === 'section'}
        <div class="preview-section">
          {#if field.label}<h3>{field.label}</h3>{/if}
          {#if field.description}<p class="preview-desc">{field.description}</p>{/if}
        </div>
      {:else if field.type === 'html'}
        <div class="preview-html">{@html field.settings?.content || '<em>HTML block</em>'}</div>
      {:else if field.type === 'hidden'}
        <!-- hidden field: {field.name} -->
      {:else}
        <div class="preview-field">
          {#if field.label}
            <label class="preview-label">
              {field.label}
              {#if field.required}<span class="preview-required">*</span>{/if}
            </label>
          {/if}

          {#if field.type === 'textarea'}
            <textarea class="preview-input preview-textarea" placeholder={field.placeholder || ''} rows={field.settings?.rows || 5} disabled></textarea>
          {:else if field.type === 'select'}
            <select class="preview-input" disabled>
              {#if field.placeholder}
                <option>{field.placeholder}</option>
              {/if}
              {#each (field.choices || []) as choice}
                <option>{choiceLabel(choice)}</option>
              {/each}
            </select>
          {:else if field.type === 'radio'}
            <div class="preview-radio-group">
              {#each (field.choices || []) as choice, ci}
                <label class="preview-radio-label">
                  <input type="radio" name={'preview_' + field.name} disabled />
                  {choiceLabel(choice)}
                </label>
              {/each}
            </div>
          {:else if field.type === 'checkbox'}
            {#if field.choices?.length}
              <div class="preview-checkbox-group">
                {#each field.choices as choice}
                  <label class="preview-checkbox-label">
                    <input type="checkbox" disabled />
                    {choiceLabel(choice)}
                  </label>
                {/each}
              </div>
            {:else}
              <label class="preview-checkbox-label">
                <input type="checkbox" disabled />
                {field.label}
              </label>
            {/if}
          {:else}
            <input
              class="preview-input"
              type={field.type === 'phone' ? 'tel' : field.type}
              placeholder={field.placeholder || ''}
              disabled
            />
          {/if}

          {#if field.description}
            <p class="preview-desc">{field.description}</p>
          {/if}
        </div>
      {/if}
    {/each}

    {#if fields.length > 0}
      <button class="preview-submit" disabled>{settings.submit_label || 'Submit'}</button>
    {:else}
      <div class="preview-empty">Add fields to see a preview</div>
    {/if}
  </div>
</div>

<style>
  .preview-wrapper {
    padding: 24px;
    max-width: 600px;
    margin: 0 auto;
  }

  .preview-form {
    background: var(--card-bg, #fff);
    border: 1px solid var(--border-color, #e5e7eb);
    border-radius: var(--radius-lg, 8px);
    padding: 24px;
  }

  .preview-field {
    margin-bottom: 16px;
  }

  .preview-label {
    display: block;
    font-size: 14px;
    font-weight: 500;
    color: var(--text-primary);
    margin-bottom: 4px;
  }

  .preview-required {
    color: var(--danger-color, #dc2626);
  }

  .preview-input {
    width: 100%;
    padding: 8px 10px;
    font-size: 14px;
    border: 1px solid var(--border-color, #e5e7eb);
    border-radius: var(--radius-md, 6px);
    background: var(--input-bg, #fff);
    color: var(--text-primary);
  }

  .preview-textarea {
    resize: vertical;
  }

  .preview-desc {
    font-size: 12px;
    color: var(--text-tertiary);
    margin-top: 4px;
  }

  .preview-section h3 {
    font-size: 16px;
    font-weight: 600;
    margin: 20px 0 4px;
  }

  .preview-html {
    margin-bottom: 16px;
  }

  .preview-radio-group,
  .preview-checkbox-group {
    display: flex;
    flex-direction: column;
    gap: 6px;
  }

  .preview-radio-label,
  .preview-checkbox-label {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 14px;
    color: var(--text-primary);
  }

  .preview-submit {
    display: block;
    margin-top: 20px;
    padding: 10px 20px;
    background: var(--accent-color, #2563eb);
    color: #fff;
    border: none;
    border-radius: var(--radius-md, 6px);
    font-size: 14px;
    font-weight: 500;
    cursor: not-allowed;
    opacity: 0.7;
  }

  .preview-empty {
    text-align: center;
    padding: 40px;
    color: var(--text-tertiary);
    font-size: 14px;
  }
</style>
