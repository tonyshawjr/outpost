<script>
  let { key = '', label = '', value = '', defaultValue = '', onchange = () => {} } = $props();

  let searchQuery = $state('');
  let showDropdown = $state(false);
  let loading = $state(false);
  let fonts = $state([]);

  const systemFonts = [
    'System Default',
    'Inter',
    'Roboto',
    'Open Sans',
    'Lato',
    'Montserrat',
    'Poppins',
    'Raleway',
    'Nunito',
    'Playfair Display',
    'Merriweather',
    'Source Sans Pro',
    'PT Sans',
    'Oswald',
    'DM Sans',
    'Space Grotesk',
    'Libre Baskerville',
    'Crimson Text',
    'Work Sans',
    'Rubik',
    'Fira Sans',
    'IBM Plex Sans',
    'IBM Plex Serif',
    'JetBrains Mono',
    'Outfit',
    'Sora',
    'Manrope',
    'Plus Jakarta Sans',
    'Geist',
  ];

  let filtered = $derived(
    searchQuery
      ? systemFonts.filter(f => f.toLowerCase().includes(searchQuery.toLowerCase()))
      : systemFonts
  );

  function selectFont(font) {
    onchange(key, font);
    showDropdown = false;
    searchQuery = '';
  }

  function clearFont() {
    onchange(key, '');
  }
</script>

<div class="font-field">
  <label class="font-label">{label}</label>
  <div class="font-input-wrap">
    <button
      class="font-input"
      type="button"
      onclick={() => { showDropdown = !showDropdown; }}
    >
      <span class="font-input-value" style={value && value !== 'System Default' ? `font-family: '${value}', sans-serif` : ''}>
        {value || defaultValue || 'Select font...'}
      </span>
      <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
    </button>
    {#if value}
      <button class="font-clear" type="button" onclick={clearFont} title="Reset to default">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      </button>
    {/if}
  </div>
  {#if showDropdown}
    <div class="font-dropdown">
      <input
        class="font-search"
        type="text"
        placeholder="Search fonts..."
        bind:value={searchQuery}
      />
      <div class="font-list">
        {#each filtered as font}
          <button
            class="font-option"
            class:active={value === font}
            type="button"
            onclick={() => selectFont(font)}
            style={font !== 'System Default' ? `font-family: '${font}', sans-serif` : ''}
          >
            {font}
          </button>
        {/each}
      </div>
    </div>
  {/if}
</div>

<style>
  .font-field { position: relative; margin-bottom: var(--space-lg, 16px); }
  .font-label {
    display: block;
    font-size: 12px;
    font-weight: 500;
    color: var(--sec);
    margin-bottom: var(--space-xs, 4px);
    text-transform: uppercase;
    letter-spacing: 0.04em;
  }
  .font-input-wrap { display: flex; align-items: center; gap: 4px; }
  .font-input {
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 8px 12px;
    background: var(--bg-secondary, #f5f5f5);
    border: 1px solid var(--border-primary, #e5e5e5);
    border-radius: var(--radius-sm, 6px);
    cursor: pointer;
    font-size: 14px;
    color: var(--text);
    text-align: left;
  }
  .font-input:hover { border-color: var(--border-hover, #ccc); }
  .font-input-value { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .font-clear {
    background: none; border: none; color: var(--dim); cursor: pointer;
    padding: 4px; border-radius: 4px;
  }
  .font-clear:hover { color: var(--text); }
  .font-dropdown {
    position: absolute;
    top: 100%;
    left: 0;
    right: 0;
    z-index: 100;
    background: var(--bg-primary, #fff);
    border: 1px solid var(--border-primary, #e5e5e5);
    border-radius: var(--radius-sm, 6px);
    box-shadow: 0 8px 24px rgba(0,0,0,0.12);
    margin-top: 4px;
  }
  .font-search {
    width: 100%;
    padding: 8px 12px;
    border: none;
    border-bottom: 1px solid var(--border-primary, #e5e5e5);
    font-size: 13px;
    outline: none;
    background: transparent;
    color: var(--text);
  }
  .font-list { max-height: 240px; overflow-y: auto; padding: 4px; }
  .font-option {
    display: block;
    width: 100%;
    padding: 8px 10px;
    background: none;
    border: none;
    text-align: left;
    font-size: 14px;
    color: var(--text);
    cursor: pointer;
    border-radius: 4px;
  }
  .font-option:hover { background: var(--bg-hover, #f0f0f0); }
  .font-option.active { background: var(--accent-bg, #e8f0fe); color: var(--accent, #1a73e8); font-weight: 500; }
</style>
