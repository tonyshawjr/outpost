<script>
  let { settings = {}, onSettingChange = () => {} } = $props();
</script>

<div class="settings-section">
  <h3 class="settings-section-title">General</h3>
  <p class="settings-section-desc">Basic site configuration.</p>

  <div class="form-group">
    <label class="form-label">Site Name</label>
    <input
      class="input"
      type="text"
      value={settings.site_name || ''}
      oninput={(e) => onSettingChange('site_name', e.target.value)}
    />
  </div>
</div>
