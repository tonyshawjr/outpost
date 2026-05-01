<script>
  let { value = $bindable('#7C3AED'), onchange } = $props();
  let open = $state(false);
  let hue = $state(0);
  let saturation = $state(100);
  let lightness = $state(50);
  let hexInput = $state(value);
  let pickerEl = $state(null);
  let externalUpdate = true;

  // Parse initial hex to HSL
  function hexToHsl(hex) {
    let r = parseInt(hex.slice(1, 3), 16) / 255;
    let g = parseInt(hex.slice(3, 5), 16) / 255;
    let b = parseInt(hex.slice(5, 7), 16) / 255;
    let max = Math.max(r, g, b), min = Math.min(r, g, b);
    let h, s, l = (max + min) / 2;
    if (max === min) { h = s = 0; }
    else {
      let d = max - min;
      s = l > 0.5 ? d / (2 - max - min) : d / (max + min);
      switch (max) {
        case r: h = ((g - b) / d + (g < b ? 6 : 0)) / 6; break;
        case g: h = ((b - r) / d + 2) / 6; break;
        case b: h = ((r - g) / d + 4) / 6; break;
      }
    }
    return [Math.round(h * 360), Math.round(s * 100), Math.round(l * 100)];
  }

  function hslToHex(h, s, l) {
    s /= 100; l /= 100;
    let c = (1 - Math.abs(2 * l - 1)) * s;
    let x = c * (1 - Math.abs((h / 60) % 2 - 1));
    let m = l - c / 2;
    let r, g, b;
    if (h < 60) { r = c; g = x; b = 0; }
    else if (h < 120) { r = x; g = c; b = 0; }
    else if (h < 180) { r = 0; g = c; b = x; }
    else if (h < 240) { r = 0; g = x; b = c; }
    else if (h < 300) { r = x; g = 0; b = c; }
    else { r = c; g = 0; b = x; }
    return '#' + [r + m, g + m, b + m].map(v => Math.round(v * 255).toString(16).padStart(2, '0')).join('');
  }

  // Sync HSL state when value changes externally
  $effect(() => {
    if (!externalUpdate) return;
    try {
      const [h, s, l] = hexToHsl(value);
      hue = h; saturation = s; lightness = l;
      hexInput = value;
    } catch {}
  });

  function updateFromHsl() {
    externalUpdate = false;
    value = hslToHex(hue, saturation, lightness);
    hexInput = value;
    if (onchange) onchange(value);
    setTimeout(() => { externalUpdate = true; }, 0);
  }

  function updateFromHex() {
    if (/^#[0-9a-fA-F]{6}$/.test(hexInput)) {
      value = hexInput;
      const [h, s, l] = hexToHsl(hexInput);
      hue = h; saturation = s; lightness = l;
      if (onchange) onchange(value);
    }
  }

  function handleGradientClick(e) {
    const rect = e.currentTarget.getBoundingClientRect();
    saturation = Math.round((e.clientX - rect.left) / rect.width * 100);
    lightness = Math.round(100 - (e.clientY - rect.top) / rect.height * 100);
    updateFromHsl();
  }

  function handleHueClick(e) {
    const rect = e.currentTarget.getBoundingClientRect();
    hue = Math.round((e.clientX - rect.left) / rect.width * 360);
    updateFromHsl();
  }

  // Close on outside click
  $effect(() => {
    if (!open) return;
    function handler(e) {
      if (pickerEl && !pickerEl.contains(e.target)) open = false;
    }
    setTimeout(() => document.addEventListener('click', handler), 0);
    return () => document.removeEventListener('click', handler);
  });

  // Preset colors
  const presets = ['#7C3AED', '#3B82F6', '#059669', '#DC2626', '#D97706', '#DB2777', '#111827', '#6B7280', '#FFFFFF', '#000000'];
</script>

<div class="cp-wrap" bind:this={pickerEl}>
  <button class="cp-trigger" onclick={(e) => { e.stopPropagation(); open = !open; }}>
    <svg class="cp-swatch" viewBox="0 0 18 18" width="22" height="22">
      <path d="M4.03909 0.343217C5.42566 0.166822 7.20841 0 9 0C10.7916 0 12.5743 0.166822 13.9609 0.343217C15.902 0.590152 17.4098 2.09804 17.6568 4.03909C17.8332 5.42566 18 7.20841 18 9C18 10.7916 17.8332 12.5743 17.6568 13.9609C17.4098 15.902 15.902 17.4098 13.9609 17.6568C12.5743 17.8332 10.7916 18 9 18C7.20841 18 5.42566 17.8332 4.03909 17.6568C2.09805 17.4098 0.590152 15.902 0.343217 13.9609C0.166822 12.5743 0 10.7916 0 9C0 7.20841 0.166822 5.42566 0.343217 4.03909C0.590151 2.09805 2.09804 0.590152 4.03909 0.343217Z" fill={value} />
    </svg>
  </button>

  {#if open}
    <div class="cp-dropdown">
      <!-- Saturation/Lightness gradient -->
      <div
        class="cp-gradient"
        style="background: linear-gradient(to bottom, transparent, #000), linear-gradient(to right, #fff, hsl({hue}, 100%, 50%));"
        onclick={handleGradientClick}
        onmousemove={(e) => { if (e.buttons === 1) handleGradientClick(e); }}
      >
        <div class="cp-cursor" style="left: {saturation}%; top: {100 - lightness}%;"></div>
      </div>

      <!-- Hue slider -->
      <div
        class="cp-hue"
        onclick={handleHueClick}
        onmousemove={(e) => { if (e.buttons === 1) handleHueClick(e); }}
      >
        <div class="cp-hue-cursor" style="left: {hue / 360 * 100}%;"></div>
      </div>

      <!-- Presets -->
      <div class="cp-presets">
        {#each presets as color}
          <button
            class="cp-preset"
            style="background: {color};"
            class:active={value === color}
            onclick={() => { value = color; hexInput = color; const [h,s,l] = hexToHsl(color); hue=h; saturation=s; lightness=l; if(onchange) onchange(value); }}
          ></button>
        {/each}
      </div>

      <!-- Hex input -->
      <div class="cp-hex-row">
        <span class="cp-hex-label">HEX</span>
        <input
          class="cp-hex-input"
          type="text"
          bind:value={hexInput}
          onblur={updateFromHex}
          onkeydown={(e) => { if (e.key === 'Enter') updateFromHex(); }}
          maxlength="7"
        />
      </div>
    </div>
  {/if}
</div>

<style>
  .cp-wrap {
    position: relative;
    flex-shrink: 0;
  }
  .cp-trigger {
    background: none;
    border: none;
    cursor: pointer;
    padding: 0;
    display: flex;
    transition: transform .15s;
  }
  .cp-trigger:hover {
    transform: scale(1.1);
  }
  .cp-trigger:active {
    transform: scale(0.95);
  }
  .cp-swatch {
    display: block;
  }

  .cp-dropdown {
    position: absolute;
    top: calc(100% + 8px);
    right: 0;
    width: 240px;
    background: var(--raised, #181B20);
    border: 1px solid var(--border, rgba(255,255,255,.06));
    border-radius: 12px;
    padding: 12px;
    z-index: 100;
    box-shadow: 0 12px 40px rgba(0,0,0,.5);
    animation: cp-in .15s ease;
  }

  @keyframes cp-in {
    from { opacity: 0; transform: translateY(-4px) scale(.97); }
    to { opacity: 1; transform: translateY(0) scale(1); }
  }

  .cp-gradient {
    width: 100%;
    height: 140px;
    border-radius: 8px;
    position: relative;
    cursor: crosshair;
    margin-bottom: 10px;
  }

  .cp-cursor {
    position: absolute;
    width: 14px;
    height: 14px;
    border-radius: 50%;
    border: 2px solid #fff;
    box-shadow: 0 1px 4px rgba(0,0,0,.4);
    transform: translate(-50%, -50%);
    pointer-events: none;
  }

  .cp-hue {
    width: 100%;
    height: 14px;
    border-radius: 7px;
    background: linear-gradient(to right, #f00, #ff0, #0f0, #0ff, #00f, #f0f, #f00);
    position: relative;
    cursor: pointer;
    margin-bottom: 12px;
  }

  .cp-hue-cursor {
    position: absolute;
    top: -1px;
    width: 16px;
    height: 16px;
    border-radius: 50%;
    border: 2px solid #fff;
    box-shadow: 0 1px 4px rgba(0,0,0,.4);
    transform: translateX(-50%);
    pointer-events: none;
  }

  .cp-presets {
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
    margin-bottom: 10px;
  }

  .cp-preset {
    width: 20px;
    height: 20px;
    border-radius: 5px;
    border: 1.5px solid rgba(255,255,255,.1);
    cursor: pointer;
    transition: transform .12s, border-color .12s;
    padding: 0;
  }
  .cp-preset:hover {
    transform: scale(1.15);
    border-color: rgba(255,255,255,.3);
  }
  .cp-preset.active {
    border-color: #fff;
    box-shadow: 0 0 0 2px var(--purple, #7C3AED);
  }

  .cp-hex-row {
    display: flex;
    align-items: center;
    gap: 8px;
  }
  .cp-hex-label {
    font-size: 11px;
    font-weight: 600;
    color: var(--dim, #505460);
    letter-spacing: .05em;
  }
  .cp-hex-input {
    flex: 1;
    background: var(--bg, #101114);
    border: 1px solid var(--border, rgba(255,255,255,.06));
    border-radius: 6px;
    color: var(--text, #F0F0F2);
    font-size: 13px;
    font-family: var(--font);
    padding: 6px 10px;
    outline: none;
    text-transform: uppercase;
  }
  .cp-hex-input:focus {
    border-color: var(--purple, #7C3AED);
  }
</style>
