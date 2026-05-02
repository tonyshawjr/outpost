<script>
  import { onMount } from 'svelte';
  import { boost as boostApi } from '$lib/api.js';
  import { addToast } from '$lib/stores.js';
  import Checkbox from '$components/Checkbox.svelte';

  let loading = $state(true);
  let saving = $state(false);
  let config = $state({});
  let status = $state(null);
  let clearingCache = $state(false);
  let preloading = $state(false);
  let optimizing = $state(false);
  let dirty = $state(false);
  let excludesText = $state('');

  onMount(async () => {
    await loadStatus();
    loading = false;
  });

  async function loadStatus() {
    try {
      const data = await boostApi.status();
      config = data.config || {};
      status = data;
      excludesText = (config.page_cache_excludes || []).join('\n');
    } catch (err) {
      addToast(err.message, 'error');
    }
  }

  function updateConfig(key, value) {
    config = { ...config, [key]: value };
    dirty = true;
  }

  function toggleConfig(key) {
    updateConfig(key, !config[key]);
  }

  function handleExcludesChange(e) {
    excludesText = e.target.value;
    config = { ...config, page_cache_excludes: excludesText.split('\n').map(s => s.trim()).filter(s => s) };
    dirty = true;
  }

  async function save() {
    saving = true;
    try {
      await boostApi.updateConfig(config);
      dirty = false;
      addToast('Boost settings saved', 'success');
      await loadStatus();
    } catch (err) {
      addToast(err.message, 'error');
    } finally {
      saving = false;
    }
  }

  async function clearCache() {
    clearingCache = true;
    try {
      await boostApi.clearCache();
      addToast('All caches cleared', 'success');
      await loadStatus();
    } catch (err) {
      addToast(err.message, 'error');
    } finally {
      clearingCache = false;
    }
  }

  async function preloadCache() {
    preloading = true;
    try {
      const result = await boostApi.preload();
      addToast(`Cache preloaded: ${result.warmed} pages warmed`, 'success');
      await loadStatus();
    } catch (err) {
      addToast(err.message, 'error');
    } finally {
      preloading = false;
    }
  }

  async function optimizeDb() {
    optimizing = true;
    try {
      const result = await boostApi.optimizeDb();
      addToast('Database optimized successfully', 'success');
      await loadStatus();
    } catch (err) {
      addToast(err.message, 'error');
    } finally {
      optimizing = false;
    }
  }

  function formatTtl(seconds) {
    if (seconds >= 86400) return Math.round(seconds / 86400) + ' days';
    if (seconds >= 3600) return Math.round(seconds / 3600) + ' hours';
    if (seconds >= 60) return Math.round(seconds / 60) + ' min';
    return seconds + 's';
  }
</script>

<div class="settings-section">
  <h3 class="settings-section-title">Boost</h3>
  <p class="settings-section-desc">Performance optimization suite. Speed up your site with caching, compression, and minification.</p>

  {#if loading}
    <div style="display: flex; align-items: center; gap: var(--space-sm);">
      <div class="spinner-sm"></div>
      <span style="font-size: var(--font-size-sm); color: var(--dim);">Loading Boost status...</span>
    </div>
  {:else}

    <!-- Developer Mode — BIG prominent toggle -->
    <div class="dev-mode-card" class:dev-mode-active={config.developer_mode}>
      <div class="dev-mode-header">
        <div>
          <h4 class="dev-mode-title">Developer Mode</h4>
          <p class="dev-mode-desc">
            {#if config.developer_mode}
              All caching and optimization is <strong>disabled</strong>. Pages are always fresh.
            {:else}
              Enable to disable all caching and optimization during development.
            {/if}
          </p>
        </div>
        <Checkbox checked={config.developer_mode} onchange={() => toggleConfig('developer_mode')} />
      </div>
    </div>

    <!-- Master Enable -->
    <div class="boost-block">
      <div class="boost-row">
        <div>
          <h4 class="boost-block-title">Enable Boost</h4>
          <p class="boost-block-desc">Master switch for all performance optimizations.</p>
        </div>
        <Checkbox checked={config.enabled} onchange={() => toggleConfig('enabled')} />
      </div>
    </div>

    {#if config.enabled && !config.developer_mode}

      <!-- Performance Dashboard -->
      {#if status}
        <div class="boost-block">
          <h4 class="boost-block-title">Performance Dashboard</h4>
          <div class="stats-grid">
            <div class="stat-card">
              <span class="stat-label">Cache Hit Rate</span>
              <span class="stat-value">{status.page_cache?.hit_rate?.rate ?? 0}%</span>
              <span class="stat-sub">{status.page_cache?.hit_rate?.hits ?? 0} hits / {status.page_cache?.hit_rate?.misses ?? 0} misses (24h)</span>
            </div>
            <div class="stat-card">
              <span class="stat-label">Cached Pages</span>
              <span class="stat-value">{status.page_cache?.entries ?? 0}</span>
              <span class="stat-sub">{status.page_cache?.size_human ?? '0 B'}</span>
            </div>
            <div class="stat-card">
              <span class="stat-label">Template Cache</span>
              <span class="stat-value">{status.template_cache?.entries ?? 0}</span>
              <span class="stat-sub">{status.template_cache?.size_human ?? '0 B'}</span>
            </div>
            <div class="stat-card">
              <span class="stat-label">Database Size</span>
              <span class="stat-value">{status.database?.size_human ?? '0 B'}</span>
              <span class="stat-sub">
                {#if status.compression?.gzip_available}
                  GZIP available
                {:else}
                  GZIP not available
                {/if}
              </span>
            </div>
          </div>
        </div>
      {/if}

      <!-- Page Caching -->
      <div class="boost-block">
        <div class="boost-row">
          <div>
            <h4 class="boost-block-title">Page Caching</h4>
            <p class="boost-block-desc">Cache full HTML pages for anonymous visitors. Dramatically reduces server load.</p>
          </div>
          <Checkbox checked={config.page_cache} onchange={() => toggleConfig('page_cache')} />
        </div>

        {#if config.page_cache}
          <div class="boost-sub">
            <div class="form-group">
              <label class="form-label-sm">Cache TTL (seconds)</label>
              <div style="display: flex; align-items: center; gap: var(--space-sm);">
                <input
                  class="input input-sm"
                  type="number"
                  min="60"
                  max="604800"
                  value={config.page_cache_ttl}
                  oninput={(e) => updateConfig('page_cache_ttl', parseInt(e.target.value) || 3600)}
                  style="width: 120px;"
                />
                <span class="hint-inline">{formatTtl(config.page_cache_ttl || 3600)}</span>
              </div>
            </div>

            <div class="form-group">
              <label class="form-label-sm">Exclude Paths</label>
              <textarea
                class="input input-sm"
                rows="3"
                value={excludesText}
                oninput={handleExcludesChange}
                placeholder="/outpost/*&#10;/api/*&#10;/members/*"
                style="font-family: var(--font-mono); font-size: 12px;"
              ></textarea>
              <span class="form-hint">One path per line. Use * for wildcards.</span>
            </div>

            <div style="display: flex; gap: var(--space-sm); margin-top: var(--space-md);">
              <button class="btn btn-secondary" onclick={clearCache} disabled={clearingCache} type="button">
                {clearingCache ? 'Clearing...' : 'Clear Cache'}
              </button>
              <button class="btn btn-secondary" onclick={preloadCache} disabled={preloading} type="button">
                {preloading ? 'Preloading...' : 'Preload Cache'}
              </button>
            </div>
          </div>
        {/if}
      </div>

      <!-- Browser Caching -->
      <div class="boost-block">
        <div class="boost-row">
          <div>
            <h4 class="boost-block-title">Browser Caching</h4>
            <p class="boost-block-desc">Set Cache-Control, ETag, and Expires headers on static assets.</p>
          </div>
          <Checkbox checked={config.browser_cache} onchange={() => toggleConfig('browser_cache')} />
        </div>

        {#if config.browser_cache}
          <div class="boost-sub">
            <div class="ttl-grid">
              <div class="form-group">
                <label class="form-label-sm">CSS</label>
                <div style="display: flex; align-items: center; gap: var(--space-xs);">
                  <input class="input input-sm" type="number" min="0" value={config.browser_cache_css_ttl} oninput={(e) => updateConfig('browser_cache_css_ttl', parseInt(e.target.value) || 0)} style="width: 100px;" />
                  <span class="hint-inline">{formatTtl(config.browser_cache_css_ttl || 0)}</span>
                </div>
              </div>
              <div class="form-group">
                <label class="form-label-sm">JavaScript</label>
                <div style="display: flex; align-items: center; gap: var(--space-xs);">
                  <input class="input input-sm" type="number" min="0" value={config.browser_cache_js_ttl} oninput={(e) => updateConfig('browser_cache_js_ttl', parseInt(e.target.value) || 0)} style="width: 100px;" />
                  <span class="hint-inline">{formatTtl(config.browser_cache_js_ttl || 0)}</span>
                </div>
              </div>
              <div class="form-group">
                <label class="form-label-sm">Images</label>
                <div style="display: flex; align-items: center; gap: var(--space-xs);">
                  <input class="input input-sm" type="number" min="0" value={config.browser_cache_img_ttl} oninput={(e) => updateConfig('browser_cache_img_ttl', parseInt(e.target.value) || 0)} style="width: 100px;" />
                  <span class="hint-inline">{formatTtl(config.browser_cache_img_ttl || 0)}</span>
                </div>
              </div>
              <div class="form-group">
                <label class="form-label-sm">Fonts</label>
                <div style="display: flex; align-items: center; gap: var(--space-xs);">
                  <input class="input input-sm" type="number" min="0" value={config.browser_cache_font_ttl || 31536000} oninput={(e) => updateConfig('browser_cache_font_ttl', parseInt(e.target.value) || 0)} style="width: 100px;" />
                  <span class="hint-inline">{formatTtl(config.browser_cache_font_ttl || 31536000)}</span>
                </div>
              </div>
            </div>
            <span class="form-hint">TTL in seconds. Hashed filenames (e.g. app-abc123.js) are sent as immutable.</span>
          </div>
        {/if}
      </div>

      <!-- Compression -->
      <div class="boost-block">
        <div class="boost-row">
          <div>
            <h4 class="boost-block-title">GZIP Compression</h4>
            <p class="boost-block-desc">
              Compress HTML responses. Reduces transfer size by 60-80%.
              {#if status && !status.compression?.gzip_available}
                <span style="color: var(--warning);">GZIP extension not available on this server.</span>
              {/if}
            </p>
          </div>
          <Checkbox checked={config.compression} onchange={() => toggleConfig('compression')} />
        </div>
      </div>

      <!-- HTML Minification -->
      <div class="boost-block">
        <div class="boost-row">
          <div>
            <h4 class="boost-block-title">HTML Minification</h4>
            <p class="boost-block-desc">Strip whitespace and comments from HTML output. Preserves &lt;pre&gt;, &lt;code&gt;, &lt;script&gt;, and &lt;style&gt; content.</p>
          </div>
          <Checkbox checked={config.html_minify} onchange={() => toggleConfig('html_minify')} />
        </div>
      </div>

      <!-- Lazy Loading -->
      <div class="boost-block">
        <div class="boost-row">
          <div>
            <h4 class="boost-block-title">Lazy Loading</h4>
            <p class="boost-block-desc">Auto-add <code>loading="lazy"</code> to images and iframes.</p>
          </div>
          <Checkbox checked={config.lazy_loading} onchange={() => toggleConfig('lazy_loading')} />
        </div>

        {#if config.lazy_loading}
          <div class="boost-sub">
            <div class="form-group">
              <label class="form-label-sm">Skip first N images (above the fold)</label>
              <input
                class="input input-sm"
                type="number"
                min="0"
                max="20"
                value={config.lazy_skip_count}
                oninput={(e) => updateConfig('lazy_skip_count', parseInt(e.target.value) || 0)}
                style="width: 80px;"
              />
            </div>
          </div>
        {/if}
      </div>

      <!-- Database Optimization -->
      <div class="boost-block">
        <h4 class="boost-block-title">Database</h4>
        <p class="boost-block-desc">Clean up stale data and compact the SQLite database to reclaim disk space.</p>

        {#if status}
          <div style="display: flex; align-items: center; gap: var(--space-lg); margin-bottom: var(--space-md);">
            <div style="font-size: var(--font-size-sm); color: var(--sec);">
              Current size: <strong>{status.database?.size_human ?? '—'}</strong>
            </div>
          </div>
        {/if}

        <button class="btn btn-secondary" onclick={optimizeDb} disabled={optimizing} type="button">
          {optimizing ? 'Optimizing...' : 'Optimize Now'}
        </button>
        <span class="form-hint" style="display: inline; margin-left: var(--space-sm);">Removes expired rate limits, old activity logs (90d+), and runs VACUUM.</span>
      </div>

    {:else if !config.developer_mode}
      <div class="boost-disabled-msg">
        <p>Boost is disabled. Enable it to access performance settings.</p>
      </div>
    {:else}
      <div class="boost-disabled-msg">
        <p>Developer Mode is active. All caching and optimization is bypassed. Disable Developer Mode to configure Boost.</p>
      </div>
    {/if}

    <!-- Save Bar -->
    {#if dirty}
      <div class="boost-save-bar">
        <span style="font-size: var(--font-size-sm); color: var(--sec);">Unsaved changes</span>
        <button class="btn btn-primary" onclick={save} disabled={saving} type="button">
          {saving ? 'Saving...' : 'Save Settings'}
        </button>
      </div>
    {/if}
  {/if}
</div>

<style>
  /* Developer Mode Card */
  .dev-mode-card {
    border: 2px solid var(--border);
    border-radius: var(--radius-md);
    padding: var(--space-lg);
    margin-bottom: var(--space-xl);
    transition: border-color 0.2s, background 0.2s;
  }
  .dev-mode-card.dev-mode-active {
    border-color: var(--warning, #d97706);
    background: color-mix(in srgb, var(--warning, #d97706) 5%, transparent);
  }
  .dev-mode-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-lg);
  }
  .dev-mode-title {
    font-size: 16px;
    font-weight: 600;
    color: var(--text);
    margin: 0 0 var(--space-xs);
  }
  .dev-mode-desc {
    font-size: var(--font-size-sm);
    color: var(--sec);
    margin: 0;
    max-width: 420px;
  }

  /* Boost blocks */
  .boost-block {
    margin-top: var(--space-xl);
    padding-top: var(--space-xl);
    border-top: 1px solid var(--border);
  }
  .boost-block:first-of-type {
    margin-top: var(--space-lg);
  }
  .boost-row {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: var(--space-lg);
  }
  .boost-block-title {
    font-size: 15px;
    font-weight: 600;
    color: var(--text);
    margin: 0 0 var(--space-xs);
  }
  .boost-block-desc {
    font-size: var(--font-size-sm);
    color: var(--sec);
    margin: 0;
  }
  .boost-block-desc code {
    font-family: var(--font-mono);
    font-size: 12px;
    background: var(--hover);
    padding: 1px 4px;
    border-radius: 3px;
  }
  .boost-sub {
    margin-top: var(--space-lg);
    padding-left: var(--space-md);
    border-left: 2px solid var(--border);
  }
  .boost-disabled-msg {
    margin-top: var(--space-xl);
    padding: var(--space-lg);
    text-align: center;
    color: var(--dim);
    font-size: var(--font-size-sm);
  }

  /* Stats grid */
  .stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: var(--space-md);
    margin-top: var(--space-md);
  }
  .stat-card {
    display: flex;
    flex-direction: column;
    padding: var(--space-md);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
  }
  .stat-label {
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: var(--dim);
    margin-bottom: var(--space-xs);
  }
  .stat-value {
    font-size: 22px;
    font-weight: 600;
    color: var(--text);
    font-family: var(--font-mono, monospace);
  }
  .stat-sub {
    font-size: 11px;
    color: var(--dim);
    margin-top: 2px;
  }

  /* TTL grid */
  .ttl-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: var(--space-md);
  }

  /* Form elements */
  .form-label-sm {
    display: block;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: var(--dim);
    margin-bottom: var(--space-xs);
  }
  .input-sm {
    font-size: 13px;
    padding: 6px 10px;
  }
  .hint-inline {
    font-size: 12px;
    color: var(--dim);
    white-space: nowrap;
  }
  .form-hint {
    font-size: 11px;
    color: var(--dim);
    margin-top: var(--space-xs);
  }

  /* Save bar */
  .boost-save-bar {
    position: sticky;
    bottom: 0;
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: var(--space-md);
    padding: var(--space-md) var(--space-lg);
    margin-top: var(--space-xl);
    background: var(--bg);
    border-top: 1px solid var(--border);
    border-radius: var(--radius-md) var(--radius-md) 0 0;
    box-shadow: 0 -4px 16px rgba(0,0,0,0.06);
  }

  /* Spinner */
  .spinner-sm {
    width: 16px;
    height: 16px;
    border: 2px solid var(--border);
    border-top-color: var(--purple);
    border-radius: 50%;
    animation: spin 0.6s linear infinite;
  }
  @keyframes spin {
    to { transform: rotate(360deg); }
  }
</style>
