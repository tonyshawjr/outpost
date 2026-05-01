<script>
  import { onMount } from 'svelte';

  let { checked = $bindable(false), label = '', onchange } = $props();
  let inputEl = $state(null);
  let taskEl = $state(null);
  const uid = 'cb-' + Math.random().toString(36).substr(2, 6);

  function handleChange(e) {
    checked = e.target.checked;
    if (onchange) onchange(checked);

    if (checked && taskEl) {
      // Animate the lines burst
      const lines = taskEl.querySelector('.cb-lines');
      if (lines) {
        lines.style.strokeDashoffset = '4.5px';
        setTimeout(() => { lines.style.strokeDashoffset = '13.5px'; }, 200);
      }
    }
  }
</script>

<div class="cb-item" bind:this={taskEl}>
  <label class="cb-label">
    <input type="checkbox" bind:checked onchange={handleChange} bind:this={inputEl} />
    <svg viewBox="0 0 21 18" class="cb-svg">
      <symbol id="{uid}-path" viewBox="0 0 21 18" xmlns="http://www.w3.org/2000/svg">
        <path d="M5.22003 7.26C5.72003 7.76 7.57 9.7 8.67 11.45C12.2 6.05 15.65 3.5 19.19 1.69" fill="none" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round" />
      </symbol>
      <defs>
        <mask id="{uid}-mask">
          <use class="tick mask" href="#{uid}-path" />
        </mask>
      </defs>
      <path class="cb-shape" d="M1.08722 4.13374C1.29101 2.53185 2.53185 1.29101 4.13374 1.08722C5.50224 0.913124 7.25112 0.75 9 0.75C10.7489 0.75 12.4978 0.913124 13.8663 1.08722C15.4681 1.29101 16.709 2.53185 16.9128 4.13374C17.0869 5.50224 17.25 7.25112 17.25 9C17.25 10.7489 17.0869 12.4978 16.9128 13.8663C16.709 15.4681 15.4682 16.709 13.8663 16.9128C12.4978 17.0869 10.7489 17.25 9 17.25C7.25112 17.25 5.50224 17.0869 4.13374 16.9128C2.53185 16.709 1.29101 15.4681 1.08722 13.8663C0.913124 12.4978 0.75 10.7489 0.75 9C0.75 7.25112 0.913124 5.50224 1.08722 4.13374Z" />
      <use class="cb-tick" href="#{uid}-path" stroke="currentColor" />
      <path fill="var(--bg, #101114)" mask="url(#{uid}-mask)" d="M4.03909 0.343217C5.42566 0.166822 7.20841 0 9 0C10.7916 0 12.5743 0.166822 13.9609 0.343217C15.902 0.590152 17.4098 2.09804 17.6568 4.03909C17.8332 5.42566 18 7.20841 18 9C18 10.7916 17.8332 12.5743 17.6568 13.9609C17.4098 15.902 15.902 17.4098 13.9609 17.6568C12.5743 17.8332 10.7916 18 9 18C7.20841 18 5.42566 17.8332 4.03909 17.6568C2.09805 17.4098 0.590152 15.902 0.343217 13.9609C0.166822 12.5743 0 10.7916 0 9C0 7.20841 0.166822 5.42566 0.343217 4.03909C0.590151 2.09805 2.09804 0.590152 4.03909 0.343217Z" />
    </svg>
    <svg class="cb-lines" viewBox="0 0 11 11">
      <path d="M5.88086 5.89441L9.53504 4.26746" />
      <path d="M5.5274 8.78838L9.45391 9.55161" />
      <path d="M3.49371 4.22065L5.55387 0.79198" />
    </svg>
    {#if label}
      <span class="cb-text">{label}</span>
    {/if}
  </label>
</div>

<style>
  .cb-item {
    display: flex;
    align-items: center;
    gap: 0;
    position: relative;
    transition: background .15s linear;
    border-radius: 6px;
    overflow: hidden;
    -webkit-mask-image: -webkit-radial-gradient(white, black);
  }

  .cb-item:hover {
    background: rgba(255,255,255,.03);
  }

  .cb-label {
    display: flex;
    align-items: center;
    padding: 8px 10px;
    cursor: pointer;
    position: relative;
    -webkit-tap-highlight-color: transparent;
    width: 100%;
    gap: 0;
  }

  .cb-label input {
    display: block;
    outline: none;
    border: none;
    background: none;
    padding: 0;
    margin: 0;
    -webkit-appearance: none;
    width: 18px;
    height: 18px;
  }

  .cb-svg {
    display: block;
    position: absolute;
    width: 21px;
    height: 18px;
    left: 10px;
    top: 8px;
    color: var(--purple, #7C3AED);
    transition: color .25s linear;
  }

  .cb-shape {
    stroke-width: 1.5px;
    stroke: var(--dim, #505460);
    fill: none;
    transition: fill .25s linear, stroke .25s linear;
  }

  .cb-tick {
    stroke-dasharray: 20;
    stroke-dashoffset: 20px;
    transition: stroke-dashoffset .15s ease;
  }

  .cb-tick.mask {
    stroke: #fff;
  }

  input:checked + .cb-svg .cb-shape {
    fill: var(--purple, #7C3AED);
    stroke: var(--purple, #7C3AED);
  }

  input:checked + .cb-svg .cb-tick {
    stroke-dashoffset: 0;
    stroke: #fff;
    transition: stroke-dashoffset .2s cubic-bezier(0, .45, 1, .5), stroke 0s;
  }

  .cb-label:hover input:not(:checked) + .cb-svg .cb-shape {
    stroke: var(--sec, #878B95);
  }

  .cb-lines {
    display: block;
    position: absolute;
    width: 11px;
    height: 11px;
    fill: none;
    stroke: var(--purple, #7C3AED);
    stroke-width: 1.25;
    stroke-linecap: round;
    top: 2px;
    left: 28px;
    stroke-dasharray: 4.5px;
    stroke-dashoffset: 13.5px;
    pointer-events: none;
    transition: stroke-dashoffset .2s ease;
  }

  .cb-text {
    font-size: 14px;
    font-weight: 500;
    color: var(--text, #F0F0F2);
    position: relative;
    transition: color .25s;
    user-select: none;
    margin-left: 10px;
  }

  .cb-text::before {
    content: '';
    position: absolute;
    height: 1px;
    left: -2px;
    right: -2px;
    top: 50%;
    transform-origin: 0 50%;
    transform: translateY(-50%) scaleX(0);
    background: currentColor;
    transition: transform .2s ease;
  }

</style>
