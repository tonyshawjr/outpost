<script>
  import KeyValueEditor from './KeyValueEditor.svelte';
  import { channels } from '$lib/api.js';

  let { config = $bindable({}) } = $props();
  let testing = $state(false);
  let testResult = $state(null);
  let testError = $state(null);

  // Initialize local pairs from config on mount (one-time)
  let headerPairs = $state(
    Object.entries(config.headers || {}).map(([key, value]) => ({ key, value }))
  );
  let paramPairs = $state(
    Object.entries(config.params || {}).map(([key, value]) => ({ key, value }))
  );

  // Sync pairs → config (only when pairs change, not the reverse)
  function syncHeadersToConfig() {
    const obj = {};
    headerPairs.forEach((p) => { if (p.key) obj[p.key] = p.value; });
    config.headers = obj;
  }

  function syncParamsToConfig() {
    const obj = {};
    paramPairs.forEach((p) => { if (p.key) obj[p.key] = p.value; });
    config.params = obj;
  }

  function setMethod(method) {
    config = { ...config, method };
  }

  function setAuthType(type) {
    config = { ...config, auth_type: type, auth_config: {} };
  }

  function updateAuthConfig(key, value) {
    config = { ...config, auth_config: { ...config.auth_config, [key]: value } };
  }

  async function testConnection() {
    testing = true;
    testResult = null;
    testError = null;
    try {
      const res = await channels.discover({
        url: config.url,
        method: config.method,
        auth_type: config.auth_type,
        auth_config: config.auth_config,
        headers: config.headers,
        params: config.params,
        data_path: config.data_path || '',
      });
      testResult = res;
    } catch (err) {
      testError = err.message;
    } finally {
      testing = false;
    }
  }
</script>

<div class="connect-step">
  <!-- URL -->
  <div class="field-group">
    <label class="field-label">API URL</label>
    <input
      type="url"
      class="field-input field-input-lg"
      placeholder="https://api.example.com/v1/items"
      value={config.url || ''}
      oninput={(e) => (config.url = e.target.value)}
    />
  </div>

  <!-- Method -->
  <div class="field-group">
    <label class="field-label">Method</label>
    <div class="pill-group">
      <button
        class="pill"
        class:pill-active={config.method === 'GET'}
        onclick={() => setMethod('GET')}
      >GET</button>
      <button
        class="pill"
        class:pill-active={config.method === 'POST'}
        onclick={() => setMethod('POST')}
      >POST</button>
    </div>
  </div>

  <!-- Auth -->
  <div class="field-group">
    <label class="field-label">Authentication</label>
    <div class="pill-group">
      {#each ['none', 'api_key', 'bearer', 'basic'] as type}
        <button
          class="pill"
          class:pill-active={config.auth_type === type}
          onclick={() => setAuthType(type)}
        >
          {type === 'none' ? 'None' : type === 'api_key' ? 'API Key' : type === 'bearer' ? 'Bearer Token' : 'Basic Auth'}
        </button>
      {/each}
    </div>

    {#if config.auth_type === 'api_key'}
      <div class="auth-fields">
        <div class="auth-row">
          <div class="field-group-inline">
            <label class="field-label">API Key</label>
            <input
              type="password"
              class="field-input"
              placeholder="Your API key"
              value={config.auth_config?.key || ''}
              oninput={(e) => updateAuthConfig('key', e.target.value)}
            />
          </div>
          <div class="field-group-inline">
            <label class="field-label">Header Name</label>
            <input
              type="text"
              class="field-input"
              placeholder="X-API-Key"
              value={config.auth_config?.header || 'X-API-Key'}
              oninput={(e) => updateAuthConfig('header', e.target.value)}
            />
          </div>
        </div>
      </div>
    {/if}

    {#if config.auth_type === 'bearer'}
      <div class="auth-fields">
        <div class="field-group-inline">
          <label class="field-label">Token</label>
          <input
            type="password"
            class="field-input"
            placeholder="Bearer token"
            value={config.auth_config?.token || ''}
            oninput={(e) => updateAuthConfig('token', e.target.value)}
          />
        </div>
      </div>
    {/if}

    {#if config.auth_type === 'basic'}
      <div class="auth-fields">
        <div class="auth-row">
          <div class="field-group-inline">
            <label class="field-label">Username</label>
            <input
              type="text"
              class="field-input"
              placeholder="Username"
              value={config.auth_config?.username || ''}
              oninput={(e) => updateAuthConfig('username', e.target.value)}
            />
          </div>
          <div class="field-group-inline">
            <label class="field-label">Password</label>
            <input
              type="password"
              class="field-input"
              placeholder="Password"
              value={config.auth_config?.password || ''}
              oninput={(e) => updateAuthConfig('password', e.target.value)}
            />
          </div>
        </div>
      </div>
    {/if}
  </div>

  <!-- Headers -->
  <div class="field-group">
    <label class="field-label">Headers</label>
    <KeyValueEditor bind:pairs={headerPairs} keyPlaceholder="Header name" valuePlaceholder="Value" onChange={syncHeadersToConfig} />
  </div>

  <!-- Params -->
  <div class="field-group">
    <label class="field-label">Query Parameters</label>
    <KeyValueEditor bind:pairs={paramPairs} onChange={syncParamsToConfig} />
  </div>

  <!-- Test -->
  <div class="test-section">
    <button
      class="btn btn-primary"
      onclick={testConnection}
      disabled={testing || !config.url}
    >
      {testing ? 'Testing...' : 'Test Connection'}
    </button>

    {#if testResult}
      <div class="test-success">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <polyline points="20 6 9 17 4 12"/>
        </svg>
        Connected — {testResult.item_count ?? 0} items found
      </div>
    {/if}

    {#if testError}
      <div class="test-error">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/>
        </svg>
        {testError}
      </div>
    {/if}
  </div>
</div>

<style>
  .connect-step {
    display: flex;
    flex-direction: column;
    gap: 24px;
  }

  .field-group {
    display: flex;
    flex-direction: column;
    gap: 6px;
  }

  .field-label {
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--text-muted);
    font-weight: 600;
  }

  .field-input {
    width: 100%;
    padding: 6px 8px;
    font-size: 13px;
    color: var(--text);
    background: transparent;
    border: 1px solid transparent;
    border-radius: var(--radius-sm);
    transition: border-color 0.15s;
  }

  .field-input:hover {
    border-color: var(--border);
  }

  .field-input:focus {
    border-color: var(--accent);
    outline: none;
  }

  .field-input::placeholder {
    color: var(--text-muted);
  }

  .field-input-lg {
    padding: 10px 12px;
    font-size: 15px;
  }

  .pill-group {
    display: flex;
    gap: 4px;
  }

  .pill {
    padding: 5px 12px;
    font-size: 12px;
    font-weight: 500;
    color: var(--text-secondary);
    background: transparent;
    border: 1px solid transparent;
    border-radius: var(--radius-sm);
    cursor: pointer;
    transition: all 0.15s;
  }

  .pill:hover {
    background: var(--bg-secondary);
  }

  .pill-active {
    color: var(--text);
    background: var(--bg-secondary);
    border-color: var(--border);
  }

  .auth-fields {
    margin-top: 12px;
  }

  .auth-row {
    display: flex;
    gap: 12px;
  }

  .auth-row .field-group-inline {
    flex: 1;
  }

  .field-group-inline {
    display: flex;
    flex-direction: column;
    gap: 4px;
  }

  .test-section {
    display: flex;
    align-items: center;
    gap: 16px;
    padding-top: 8px;
  }

  .test-success {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 13px;
    color: var(--success);
    font-weight: 500;
  }

  .test-error {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 13px;
    color: var(--danger);
    font-weight: 500;
  }
</style>
