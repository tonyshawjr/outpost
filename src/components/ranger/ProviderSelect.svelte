<script>
  let { value = 'claude', onchange } = $props();

  const providers = [
    { id: 'claude', label: 'Claude', vendor: 'Anthropic' },
    { id: 'gpt', label: 'GPT', vendor: 'OpenAI' },
    { id: 'gemini', label: 'Gemini', vendor: 'Google' },
  ];
</script>

<select
  class="ranger-provider-select"
  value={value}
  onchange={(e) => onchange(e.target.value)}
  aria-label="Select AI provider"
>
  {#each providers as p}
    <option value={p.id}>{p.label}</option>
  {/each}
</select>

<style>
  .ranger-provider-select {
    appearance: none;
    -webkit-appearance: none;
    background: rgba(255, 255, 255, 0.08);
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: var(--radius-sm, 8px);
    color: rgba(255, 255, 255, 0.7);
    font-size: 12px;
    font-weight: 500;
    font-family: var(--font-sans);
    padding: 4px 24px 4px 8px;
    cursor: pointer;
    outline: none;
    transition: border-color 0.15s;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6' fill='none'%3E%3Cpath d='M1 1l4 4 4-4' stroke='rgba(255,255,255,0.5)' stroke-width='1.5' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 8px center;
  }

  .ranger-provider-select:hover {
    border-color: rgba(255, 255, 255, 0.2);
  }

  .ranger-provider-select:focus {
    border-color: rgba(255, 255, 255, 0.3);
  }

  .ranger-provider-select option {
    background: #3D3530;
    color: rgba(255, 255, 255, 0.9);
  }
</style>
