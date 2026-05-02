<script>
  import { onMount } from 'svelte';
  import { shield as shieldApi } from '$lib/api.js';
  import { addToast } from '$lib/stores.js';
  import Checkbox from '$components/Checkbox.svelte';

  let config = $state({
    enabled: true,
    login_lockout: true,
    login_max_attempts: 5,
    login_lockout_minutes: 15,
    auto_block_after_lockouts: 3,
    firewall_enabled: true,
    firewall_mode: 'block',
    file_integrity: true,
    security_headers: true,
    traffic_logging: true,
    email_notifications: false,
    notification_email: '',
  });

  let status = $state(null);
  let blockedIps = $state([]);
  let securityLog = $state([]);
  let trafficLog = $state([]);
  let loading = $state(true);
  let saving = $state(false);
  let dirty = $state(false);

  // Block IP form
  let newBlockIp = $state('');
  let newBlockReason = $state('');
  let blocking = $state(false);

  // File integrity
  let fileCheckResult = $state(null);
  let checkingFiles = $state(false);

  // Tabs
  let activeTab = $state('config');

  onMount(async () => {
    try {
      const [configRes, statusRes] = await Promise.all([
        shieldApi.getConfig(),
        shieldApi.status(),
      ]);
      config = { ...config, ...configRes.config };
      status = statusRes;
    } catch (err) {
      addToast(err.message, 'error');
    } finally {
      loading = false;
    }
  });

  async function loadTab(tab) {
    activeTab = tab;
    try {
      if (tab === 'blocked' && blockedIps.length === 0) {
        const res = await shieldApi.blockedIps();
        blockedIps = res.blocked_ips || [];
      }
      if (tab === 'log' && securityLog.length === 0) {
        const res = await shieldApi.log(50);
        securityLog = res.logs || [];
      }
      if (tab === 'traffic' && trafficLog.length === 0) {
        const res = await shieldApi.traffic(100);
        trafficLog = res.traffic || [];
      }
    } catch (err) {
      addToast(err.message, 'error');
    }
  }

  function updateConfig(key, value) {
    config = { ...config, [key]: value };
    dirty = true;
  }

  async function saveConfig() {
    saving = true;
    try {
      const res = await shieldApi.updateConfig(config);
      config = { ...config, ...res.config };
      dirty = false;
      addToast('Shield settings saved', 'success');
    } catch (err) {
      addToast(err.message, 'error');
    } finally {
      saving = false;
    }
  }

  async function blockIp() {
    if (!newBlockIp.trim()) return;
    blocking = true;
    try {
      await shieldApi.blockIp(newBlockIp.trim(), newBlockReason.trim() || 'Manually blocked');
      addToast(`Blocked ${newBlockIp}`, 'success');
      newBlockIp = '';
      newBlockReason = '';
      const res = await shieldApi.blockedIps();
      blockedIps = res.blocked_ips || [];
      // Refresh status
      status = await shieldApi.status();
    } catch (err) {
      addToast(err.message, 'error');
    } finally {
      blocking = false;
    }
  }

  async function unblockIp(ip) {
    try {
      await shieldApi.unblockIp(ip);
      blockedIps = blockedIps.filter(b => b.ip !== ip);
      addToast(`Unblocked ${ip}`, 'success');
      status = await shieldApi.status();
    } catch (err) {
      addToast(err.message, 'error');
    }
  }

  async function runFileCheck() {
    checkingFiles = true;
    try {
      fileCheckResult = await shieldApi.fileCheck();
      addToast(
        fileCheckResult.status === 'clean'
          ? `All ${fileCheckResult.files_checked} files verified`
          : `${fileCheckResult.changes.length} file change(s) detected`,
        fileCheckResult.status === 'clean' ? 'success' : 'warning'
      );
    } catch (err) {
      addToast(err.message, 'error');
    } finally {
      checkingFiles = false;
    }
  }

  function formatDate(d) {
    if (!d) return '--';
    return new Date(d + 'Z').toLocaleString();
  }

  function ruleLabel(rule) {
    const labels = {
      sql_injection: 'SQL Injection',
      xss: 'XSS',
      path_traversal: 'Path Traversal',
      php_injection: 'PHP Injection',
      null_byte: 'Null Byte',
      blocked_ip: 'Blocked IP',
      login_lockout: 'Login Lockout',
    };
    return labels[rule] || rule;
  }

  function threatClass(rule) {
    if (['sql_injection', 'php_injection'].includes(rule)) return 'threat-critical';
    if (['xss', 'path_traversal', 'null_byte'].includes(rule)) return 'threat-high';
    return 'threat-medium';
  }
</script>

<div class="settings-section">
  <h3 class="settings-section-title">Shield</h3>
  <p class="settings-section-desc">Security hardening and threat protection for your site.</p>

  {#if loading}
    <p class="shield-loading">Loading security settings...</p>
  {:else}

    <!-- Status Banner -->
    {#if status}
      <div class="shield-status" class:shield-active={config.enabled} class:shield-inactive={!config.enabled}>
        <div class="shield-status-icon">
          {#if config.enabled}
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
          {:else}
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><line x1="4" y1="4" x2="20" y2="20"/></svg>
          {/if}
        </div>
        <div class="shield-status-info">
          <span class="shield-status-label">{config.enabled ? 'Shield Active' : 'Shield Disabled'}</span>
          {#if config.enabled}
            <span class="shield-status-stats">
              {status.blocked_ips} blocked IP{status.blocked_ips !== 1 ? 's' : ''}
              &middot; {status.blocked_24h} blocked request{status.blocked_24h !== 1 ? 's' : ''} today
              &middot; {status.failed_logins_24h} failed login{status.failed_logins_24h !== 1 ? 's' : ''} today
            </span>
          {/if}
        </div>
      </div>
    {/if}

    <!-- Tab Navigation -->
    <div class="shield-tabs">
      <button class="shield-tab" class:active={activeTab === 'config'} onclick={() => loadTab('config')}>Configuration</button>
      <button class="shield-tab" class:active={activeTab === 'blocked'} onclick={() => loadTab('blocked')}>Blocked IPs</button>
      <button class="shield-tab" class:active={activeTab === 'log'} onclick={() => loadTab('log')}>Security Log</button>
      <button class="shield-tab" class:active={activeTab === 'traffic'} onclick={() => loadTab('traffic')}>Traffic</button>
    </div>

    <!-- Configuration Tab -->
    {#if activeTab === 'config'}
      <div class="shield-config">

        <!-- Master Toggle -->
        <div class="shield-block">
          <div class="form-group">
            <label class="form-label">Enable Shield</label>
            <div style="display: flex; align-items: center; gap: var(--space-md);">
              <Checkbox checked={config.enabled} onchange={() => updateConfig('enabled', !config.enabled)} />
              <span class="shield-hint">{config.enabled ? 'All protections active' : 'All protections disabled'}</span>
            </div>
          </div>
        </div>

        <!-- Login Protection -->
        <div class="shield-block">
          <h4 class="shield-block-title">Login Protection</h4>
          <p class="shield-block-desc">Lock out IPs after repeated failed login attempts.</p>

          <div class="form-group">
            <label class="form-label">Enable Login Lockout</label>
            <Checkbox checked={config.login_lockout} onchange={() => updateConfig('login_lockout', !config.login_lockout)} />
          </div>

          {#if config.login_lockout}
            <div class="shield-inline-fields">
              <div class="form-group">
                <label class="form-label">Max Failed Attempts</label>
                <input
                  class="input"
                  type="number"
                  min="1"
                  max="50"
                  value={config.login_max_attempts}
                  oninput={(e) => updateConfig('login_max_attempts', parseInt(e.target.value) || 5)}
                />
              </div>
              <div class="form-group">
                <label class="form-label">Lockout Duration (minutes)</label>
                <input
                  class="input"
                  type="number"
                  min="1"
                  max="1440"
                  value={config.login_lockout_minutes}
                  oninput={(e) => updateConfig('login_lockout_minutes', parseInt(e.target.value) || 15)}
                />
              </div>
              <div class="form-group">
                <label class="form-label">Permanent Block After Lockouts</label>
                <input
                  class="input"
                  type="number"
                  min="1"
                  max="20"
                  value={config.auto_block_after_lockouts}
                  oninput={(e) => updateConfig('auto_block_after_lockouts', parseInt(e.target.value) || 3)}
                />
              </div>
            </div>
          {/if}
        </div>

        <!-- Firewall -->
        <div class="shield-block">
          <h4 class="shield-block-title">Firewall</h4>
          <p class="shield-block-desc">Block common attack patterns (SQL injection, XSS, path traversal, PHP injection).</p>

          <div class="form-group">
            <label class="form-label">Enable Firewall</label>
            <Checkbox checked={config.firewall_enabled} onchange={() => updateConfig('firewall_enabled', !config.firewall_enabled)} />
          </div>

          {#if config.firewall_enabled}
            <div class="form-group">
              <label class="form-label">Firewall Mode</label>
              <div class="shield-mode-toggle">
                <button
                  class="shield-mode-btn"
                  class:active={config.firewall_mode === 'block'}
                  onclick={() => updateConfig('firewall_mode', 'block')}
                  type="button"
                >Block</button>
                <button
                  class="shield-mode-btn"
                  class:active={config.firewall_mode === 'log'}
                  onclick={() => updateConfig('firewall_mode', 'log')}
                  type="button"
                >Log Only</button>
              </div>
              <span class="shield-hint">
                {config.firewall_mode === 'block' ? 'Malicious requests are blocked and logged.' : 'Malicious requests are logged but not blocked (monitoring mode).'}
              </span>
            </div>
          {/if}
        </div>

        <!-- Security Headers -->
        <div class="shield-block">
          <h4 class="shield-block-title">Security Headers</h4>
          <p class="shield-block-desc">Add protective HTTP headers (X-Content-Type-Options, X-Frame-Options, Referrer-Policy, etc.).</p>
          <div class="form-group">
            <label class="form-label">Enable Security Headers</label>
            <Checkbox checked={config.security_headers} onchange={() => updateConfig('security_headers', !config.security_headers)} />
          </div>
        </div>

        <!-- File Integrity -->
        <div class="shield-block">
          <h4 class="shield-block-title">File Integrity Monitoring</h4>
          <p class="shield-block-desc">Track changes to core PHP files. Alerts you if files are modified unexpectedly.</p>
          <div class="form-group">
            <label class="form-label">Enable File Monitoring</label>
            <Checkbox checked={config.file_integrity} onchange={() => updateConfig('file_integrity', !config.file_integrity)} />
          </div>
          {#if config.file_integrity}
            <div style="margin-top: var(--space-md);">
              <button class="btn btn-secondary" onclick={runFileCheck} disabled={checkingFiles} type="button">
                {checkingFiles ? 'Checking...' : 'Run Check Now'}
              </button>
              {#if status?.last_integrity_check}
                <span class="shield-hint" style="margin-left: var(--space-md);">Last check: {formatDate(status.last_integrity_check)}</span>
              {/if}
            </div>
            {#if fileCheckResult}
              <div class="shield-file-result" class:clean={fileCheckResult.status === 'clean'}>
                {#if fileCheckResult.status === 'clean'}
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--success)" stroke-width="2"><path d="M20 6L9 17l-5-5"/></svg>
                  All {fileCheckResult.files_checked} core files verified
                {:else}
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--warning)" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                  {fileCheckResult.changes.length} file(s) changed:
                  <ul class="shield-file-list">
                    {#each fileCheckResult.changes as change}
                      <li>{change.file} {change.status === 'deleted' ? '(deleted)' : '(modified)'}</li>
                    {/each}
                  </ul>
                {/if}
              </div>
            {/if}
          {/if}
        </div>

        <!-- Traffic Logging -->
        <div class="shield-block">
          <h4 class="shield-block-title">Traffic Logging</h4>
          <p class="shield-block-desc">Log recent requests for security monitoring. Keeps the last 1,000 entries.</p>
          <div class="form-group">
            <label class="form-label">Enable Traffic Logging</label>
            <Checkbox checked={config.traffic_logging} onchange={() => updateConfig('traffic_logging', !config.traffic_logging)} />
          </div>
        </div>

        <!-- Notifications -->
        <div class="shield-block">
          <h4 class="shield-block-title">Notifications</h4>
          <p class="shield-block-desc">Receive email alerts for security events (lockouts, blocked attacks, file changes).</p>
          <div class="form-group">
            <label class="form-label">Enable Email Notifications</label>
            <Checkbox checked={config.email_notifications} onchange={() => updateConfig('email_notifications', !config.email_notifications)} />
          </div>
          {#if config.email_notifications}
            <div class="form-group">
              <label class="form-label">Notification Email</label>
              <input
                class="input"
                type="email"
                placeholder="admin@example.com"
                value={config.notification_email}
                oninput={(e) => updateConfig('notification_email', e.target.value)}
              />
            </div>
          {/if}
        </div>

        <!-- Save Button -->
        {#if dirty}
          <div class="shield-save">
            <button class="btn btn-primary" onclick={saveConfig} disabled={saving} type="button">
              {saving ? 'Saving...' : 'Save Shield Settings'}
            </button>
          </div>
        {/if}
      </div>
    {/if}

    <!-- Blocked IPs Tab -->
    {#if activeTab === 'blocked'}
      <div class="shield-panel">
        <div class="shield-add-ip">
          <input
            class="input"
            type="text"
            placeholder="IP address (e.g. 192.168.1.100)"
            bind:value={newBlockIp}
            style="flex: 1;"
          />
          <input
            class="input"
            type="text"
            placeholder="Reason (optional)"
            bind:value={newBlockReason}
            style="flex: 1;"
          />
          <button class="btn btn-primary" onclick={blockIp} disabled={blocking || !newBlockIp.trim()} type="button">
            {blocking ? 'Blocking...' : 'Block IP'}
          </button>
        </div>

        {#if blockedIps.length === 0}
          <p class="shield-empty">No blocked IPs</p>
        {:else}
          <div class="shield-table-wrap">
            <table class="shield-table">
              <thead>
                <tr>
                  <th>IP Address</th>
                  <th>Reason</th>
                  <th>Type</th>
                  <th>Blocked At</th>
                  <th>Expires</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                {#each blockedIps as ip}
                  <tr>
                    <td class="shield-mono">{ip.ip}</td>
                    <td>{ip.reason || '--'}</td>
                    <td><span class="shield-tag" class:auto={ip.auto_blocked}>{ip.auto_blocked ? 'Auto' : 'Manual'}</span></td>
                    <td>{formatDate(ip.blocked_at)}</td>
                    <td>{ip.expires_at ? formatDate(ip.expires_at) : 'Never'}</td>
                    <td>
                      <button class="shield-unblock" onclick={() => unblockIp(ip.ip)} type="button">Unblock</button>
                    </td>
                  </tr>
                {/each}
              </tbody>
            </table>
          </div>
        {/if}
      </div>
    {/if}

    <!-- Security Log Tab -->
    {#if activeTab === 'log'}
      <div class="shield-panel">
        {#if securityLog.length === 0}
          <p class="shield-empty">No security events recorded</p>
        {:else}
          <div class="shield-table-wrap">
            <table class="shield-table">
              <thead>
                <tr>
                  <th>Time</th>
                  <th>IP</th>
                  <th>Rule</th>
                  <th>Path</th>
                  <th>Action</th>
                </tr>
              </thead>
              <tbody>
                {#each securityLog as entry}
                  <tr>
                    <td class="shield-nowrap">{formatDate(entry.created_at)}</td>
                    <td class="shield-mono">{entry.ip}</td>
                    <td><span class="shield-threat {threatClass(entry.rule)}">{ruleLabel(entry.rule)}</span></td>
                    <td class="shield-path" title={entry.path}>{entry.path}</td>
                    <td><span class="shield-tag" class:blocked={entry.blocked}>{entry.blocked ? 'Blocked' : 'Logged'}</span></td>
                  </tr>
                {/each}
              </tbody>
            </table>
          </div>
        {/if}
      </div>
    {/if}

    <!-- Traffic Tab -->
    {#if activeTab === 'traffic'}
      <div class="shield-panel">
        {#if trafficLog.length === 0}
          <p class="shield-empty">No traffic recorded</p>
        {:else}
          <div class="shield-table-wrap">
            <table class="shield-table">
              <thead>
                <tr>
                  <th>Time</th>
                  <th>IP</th>
                  <th>Method</th>
                  <th>Path</th>
                  <th>User Agent</th>
                </tr>
              </thead>
              <tbody>
                {#each trafficLog as entry}
                  <tr>
                    <td class="shield-nowrap">{formatDate(entry.created_at)}</td>
                    <td class="shield-mono">{entry.ip}</td>
                    <td><span class="shield-method">{entry.method}</span></td>
                    <td class="shield-path" title={entry.path}>{entry.path}</td>
                    <td class="shield-ua" title={entry.user_agent}>{entry.user_agent?.substring(0, 60) || '--'}</td>
                  </tr>
                {/each}
              </tbody>
            </table>
          </div>
        {/if}
      </div>
    {/if}

  {/if}
</div>

<style>
  .shield-loading {
    font-size: var(--font-size-sm);
    color: var(--dim);
  }

  /* Status Banner */
  .shield-status {
    display: flex;
    align-items: center;
    gap: var(--space-md);
    padding: var(--space-md) var(--space-lg);
    border-radius: var(--radius-md);
    margin-bottom: var(--space-xl);
  }
  .shield-active {
    background: color-mix(in srgb, var(--success) 8%, transparent);
    border: 1px solid color-mix(in srgb, var(--success) 20%, transparent);
  }
  .shield-inactive {
    background: color-mix(in srgb, var(--dim) 6%, transparent);
    border: 1px solid var(--border);
  }
  .shield-status-icon {
    flex-shrink: 0;
    color: var(--success);
  }
  .shield-inactive .shield-status-icon {
    color: var(--dim);
  }
  .shield-status-info {
    display: flex;
    flex-direction: column;
    gap: 2px;
  }
  .shield-status-label {
    font-size: 14px;
    font-weight: 600;
    color: var(--text);
  }
  .shield-status-stats {
    font-size: var(--font-size-xs);
    color: var(--sec);
  }

  /* Tabs */
  .shield-tabs {
    display: flex;
    gap: 2px;
    border-bottom: 1px solid var(--border);
    margin-bottom: var(--space-xl);
  }
  .shield-tab {
    padding: var(--space-sm) var(--space-md);
    background: none;
    border: none;
    font-size: var(--font-size-sm);
    color: var(--sec);
    cursor: pointer;
    border-bottom: 2px solid transparent;
    margin-bottom: -1px;
    transition: color 0.15s, border-color 0.15s;
  }
  .shield-tab:hover {
    color: var(--text);
  }
  .shield-tab.active {
    color: var(--text);
    font-weight: 500;
    border-bottom-color: var(--purple);
  }

  /* Config Blocks */
  .shield-block {
    margin-top: var(--space-2xl);
    padding-top: var(--space-2xl);
    border-top: 1px solid var(--border);
  }
  .shield-block:first-child {
    margin-top: 0;
    padding-top: 0;
    border-top: none;
  }
  .shield-block-title {
    font-size: 15px;
    font-weight: 600;
    color: var(--text);
    margin: 0 0 var(--space-xs);
  }
  .shield-block-desc {
    font-size: var(--font-size-sm);
    color: var(--sec);
    margin: 0 0 var(--space-lg);
  }

  .shield-inline-fields {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: var(--space-lg);
    margin-top: var(--space-md);
  }

  .shield-hint {
    font-size: var(--font-size-xs);
    color: var(--dim);
  }

  /* Firewall mode toggle */
  .shield-mode-toggle {
    display: inline-flex;
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    overflow: hidden;
    margin-bottom: var(--space-xs);
  }
  .shield-mode-btn {
    padding: 6px 16px;
    background: none;
    border: none;
    font-size: var(--font-size-sm);
    color: var(--sec);
    cursor: pointer;
    transition: all 0.15s;
  }
  .shield-mode-btn.active {
    background: var(--purple);
    color: white;
  }

  /* File integrity result */
  .shield-file-result {
    display: flex;
    align-items: flex-start;
    gap: var(--space-sm);
    margin-top: var(--space-md);
    padding: var(--space-sm) var(--space-md);
    border-radius: var(--radius-sm);
    font-size: var(--font-size-sm);
    background: color-mix(in srgb, var(--warning) 8%, transparent);
    color: var(--text);
  }
  .shield-file-result.clean {
    background: color-mix(in srgb, var(--success) 8%, transparent);
  }
  .shield-file-list {
    margin: var(--space-xs) 0 0;
    padding-left: var(--space-lg);
    font-family: var(--font-mono);
    font-size: var(--font-size-xs);
  }

  /* Save */
  .shield-save {
    margin-top: var(--space-2xl);
    padding-top: var(--space-lg);
    border-top: 1px solid var(--border);
  }

  /* Panel (blocked IPs, log, traffic) */
  .shield-panel {
    min-height: 100px;
  }
  .shield-empty {
    font-size: var(--font-size-sm);
    color: var(--dim);
    text-align: center;
    padding: var(--space-2xl) 0;
  }

  /* Add IP form */
  .shield-add-ip {
    display: flex;
    gap: var(--space-sm);
    margin-bottom: var(--space-lg);
  }

  /* Table */
  .shield-table-wrap {
    overflow-x: auto;
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
  }
  .shield-table {
    width: 100%;
    border-collapse: collapse;
    font-size: var(--font-size-sm);
  }
  .shield-table th {
    text-align: left;
    padding: var(--space-sm) var(--space-md);
    font-size: 0.6875rem;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: var(--dim);
    font-weight: 500;
    border-bottom: 1px solid var(--border);
    white-space: nowrap;
  }
  .shield-table td {
    padding: var(--space-sm) var(--space-md);
    border-bottom: 1px solid var(--border);
    color: var(--text);
  }
  .shield-table tbody tr:last-child td {
    border-bottom: none;
  }
  .shield-table tbody tr:hover {
    background: var(--raised);
  }

  .shield-mono {
    font-family: var(--font-mono);
    font-size: var(--font-size-xs);
  }
  .shield-nowrap {
    white-space: nowrap;
    font-size: var(--font-size-xs);
  }
  .shield-path {
    max-width: 200px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    font-family: var(--font-mono);
    font-size: var(--font-size-xs);
  }
  .shield-ua {
    max-width: 200px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    font-size: var(--font-size-xs);
    color: var(--sec);
  }
  .shield-method {
    font-family: var(--font-mono);
    font-size: var(--font-size-xs);
    font-weight: 600;
  }

  /* Tags */
  .shield-tag {
    font-size: var(--font-size-xs);
    padding: 2px 8px;
    border-radius: var(--radius-sm);
    background: var(--hover);
    color: var(--sec);
  }
  .shield-tag.auto {
    background: color-mix(in srgb, var(--warning) 15%, transparent);
    color: var(--warning);
  }
  .shield-tag.blocked {
    background: color-mix(in srgb, var(--danger) 15%, transparent);
    color: var(--danger);
  }

  .shield-threat {
    font-size: var(--font-size-xs);
    padding: 2px 8px;
    border-radius: var(--radius-sm);
    font-weight: 500;
  }
  .threat-critical {
    background: color-mix(in srgb, var(--danger) 15%, transparent);
    color: var(--danger);
  }
  .threat-high {
    background: color-mix(in srgb, var(--warning) 15%, transparent);
    color: var(--warning);
  }
  .threat-medium {
    background: var(--hover);
    color: var(--sec);
  }

  .shield-unblock {
    background: none;
    border: none;
    font-size: var(--font-size-xs);
    color: var(--danger);
    cursor: pointer;
    padding: 2px 6px;
  }
  .shield-unblock:hover {
    text-decoration: underline;
  }

  @media (max-width: 768px) {
    .shield-inline-fields {
      grid-template-columns: 1fr;
    }
    .shield-add-ip {
      flex-direction: column;
    }
  }
</style>
