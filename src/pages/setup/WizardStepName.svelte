<script>
  let { siteName = $bindable(''), onNext } = $props();

  function handleKeydown(e) {
    if (e.key === 'Enter' && siteName.trim()) onNext();
  }
</script>

<div class="wizard-step">
  <h2 class="wizard-heading">What's your site called?</h2>
  <p class="wizard-desc">You can always change this later in Settings.</p>
  <input
    class="wizard-input"
    type="text"
    placeholder="My Website"
    bind:value={siteName}
    onkeydown={handleKeydown}
    autofocus
  />
  <div class="wizard-actions">
    <button class="btn btn-primary" onclick={onNext} disabled={!siteName.trim()}>Continue</button>
  </div>
</div>

<style>
  .wizard-step {
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
  }
  .wizard-heading {
    font-family: var(--font-serif);
    font-size: 28px;
    font-weight: 600;
    color: #fff;
    margin: 0 0 8px;
  }
  .wizard-desc {
    font-size: 15px;
    color: rgba(255, 255, 255, 0.5);
    margin: 0 0 32px;
  }
  .wizard-input {
    width: 100%;
    max-width: 400px;
    padding: 14px 18px;
    font-size: 18px;
    font-family: var(--font-serif);
    background: rgba(255, 255, 255, 0.06);
    border: 1px solid rgba(255, 255, 255, 0.12);
    border-radius: 8px;
    color: #fff;
    outline: none;
    transition: border-color 0.15s;
    text-align: center;
  }
  .wizard-input::placeholder {
    color: rgba(255, 255, 255, 0.25);
  }
  .wizard-input:focus {
    border-color: rgba(255, 255, 255, 0.3);
  }
  .wizard-actions {
    margin-top: 32px;
  }
</style>
