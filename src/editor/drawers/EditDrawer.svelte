<script>
  import RichTextEditor from '../../components/RichTextEditor.svelte';

  let {
    fields = [],
    fieldMap = {},
    pageId = null,
    pageName = 'Page',
    apiUrl = '/outpost/api.php',
    csrfToken = '',
    highlightedField = null,
    highlightedBlock = null,
    onchanged = () => {},
  } = $props();

  let pageFields = $state([]);
  let manifestBlocks = $state([]); // Block grouping from manifest
  let loading = $state(true);
  let editValues = $state({});
  let originalValues = $state({});
  let saving = $state(false);
  let saveSuccess = $state(false);
  let saveErrors = $state([]);
  let globalPageId = $state(null);

  // Navigation state: null = Level 1, sectionName = Level 2, { section, field } = Level 3
  let currentSection = $state(null);
  let currentField = $state(null);
  let activeTab = $state('general'); // 'general' | 'style'

  // Block settings state (Style tab)
  let blockSettings = $state([]);
  let blockSettingsCollections = $state([]);
  let blockSettingsLoading = $state(false);
  let blockSettingsValues = $state({});
  let blockSettingsSaving = $state({});

  // Media picker state (for gallery + image fields)
  let showMediaPicker = $state(false);
  let mediaPickerCallback = $state(null);
  let mediaItems = $state([]);
  let mediaLoading = $state(false);
  let mediaSelected = $state(null);

  // Alt text cache: { [imagePath]: { altText, mediaId, loaded } }
  let altTextCache = $state({});
  let altTextSaving = $state({});

  let hasChanges = $derived(
    Object.keys(editValues).some(k => editValues[k] !== originalValues[k]) ||
    Object.keys(blockSettingsValues).some(k => blockSettingsValues[k] !== originalBlockSettingsValues[k])
  );

  // Notify parent when changes state changes
  $effect(() => {
    onchanged(hasChanges);
  });

  $effect(() => {
    loadFields();
  });

  async function loadFields() {
    loading = true;
    try {
      const resp = await fetch(apiUrl + '?action=editor/field-map&page_id=' + (pageId || ''), {
        credentials: 'include',
        headers: { 'X-CSRF-Token': csrfToken },
      });
      if (resp.ok) {
        const data = await resp.json();
        const rawFields = data.fields || [];
        const globalFields = data.global_fields || [];
        const allFields = [...rawFields, ...globalFields];
        const manifestGlobals = data.manifest_globals || [];
        globalPageId = data.global_page_id || null;
        manifestBlocks = data.blocks || [];
        pageFields = allFields
          .filter(f => !['meta_title', 'meta_description'].includes(f.field_type))
          .map(f => ({
            name: f.field_name || f.name,
            type: f.field_type || f.type || 'text',
            content: f.content || f.default_value || '',
            selector: f.css_selector || null,
            section: f.section_name || 'Content',
            options: f.options || '',
            label: f.label || formatLabel(f.field_name || f.name),
            isGlobal: f.is_global || manifestGlobals.includes(f.field_name || f.name),
          }));
        const vals = {};
        const orig = {};
        const wrap = document.body;
        for (const f of pageFields) {
          vals[f.name] = f.content;
          orig[f.name] = f.content;
          // For link fields, initialize the _label value from the DOM element's text
          if (f.type === 'link' && wrap) {
            const el = wrap.querySelector(`[data-outpost="${f.name}"]`);
            if (el) {
              vals[f.name + '_label'] = el.textContent.trim();
              orig[f.name + '_label'] = el.textContent.trim();
            }
          }
          // For image fields with no saved value, read default src from DOM
          if (f.type === 'image' && !vals[f.name] && wrap) {
            const el = wrap.querySelector(`[data-outpost="${f.name}"]`);
            if (el && el.src) {
              vals[f.name] = el.getAttribute('src');
              orig[f.name] = el.getAttribute('src');
            }
          }
          // For link fields with no saved value, read default href from DOM
          if (f.type === 'link' && !vals[f.name] && wrap) {
            const el = wrap.querySelector(`[data-outpost="${f.name}"]`);
            if (el) {
              const href = el.getAttribute('href');
              if (href && href !== '#') {
                vals[f.name] = href;
                orig[f.name] = href;
              }
            }
          }
          // For richtext fields with no saved value, read default innerHTML from DOM
          if (f.type === 'richtext' && !vals[f.name] && wrap) {
            const el = wrap.querySelector(`[data-outpost="${f.name}"]`);
            if (el && el.innerHTML) {
              vals[f.name] = el.innerHTML.trim();
              orig[f.name] = el.innerHTML.trim();
            }
          }
        }
        editValues = vals;
        originalValues = orig;
      }
    } catch (err) {
      console.error('[OPE] Failed to load fields:', err);
    } finally {
      loading = false;
    }
  }

  // ── Block Settings (Style tab) ──────────────────────────
  async function loadBlockSettings() {
    if (!pageId) return;
    blockSettingsLoading = true;
    try {
      const resp = await fetch(apiUrl + '?action=editor/block-settings&page_id=' + pageId, {
        credentials: 'include',
        headers: { 'X-CSRF-Token': csrfToken },
      });
      if (resp.ok) {
        const data = await resp.json();
        blockSettings = data.settings || [];
        blockSettingsCollections = data.collections || [];
        // Initialize values map
        const vals = {};
        for (const s of blockSettings) {
          const key = s.block + '.' + s.name;
          vals[key] = s.value ?? s.default ?? '';
        }
        blockSettingsValues = vals;
        originalBlockSettingsValues = { ...vals };
      }
    } catch (err) {
      console.error('[OPE] Failed to load block settings:', err);
    } finally {
      blockSettingsLoading = false;
    }
  }

  // Load block settings when entering a section (so we know if Style tab should show)
  $effect(() => {
    if (currentSection && blockSettings.length === 0 && !blockSettingsLoading) {
      loadBlockSettings();
    }
  });

  // Settings for the current section (match block name from settings API)
  let currentBlockSettings = $derived(() => {
    if (!currentSection) return [];
    const sectionLower = currentSection.toLowerCase().replace(/\s+/g, '-');
    return blockSettings.filter(s => {
      const settingBlock = s.block.toLowerCase();
      return settingBlock === sectionLower ||
             settingBlock === currentSection.toLowerCase();
    });
  });

  // Track original settings values for change detection
  let originalBlockSettingsValues = $state({});

  function updateBlockSetting(setting, value) {
    const key = setting.block + '.' + setting.name;
    blockSettingsValues = { ...blockSettingsValues, [key]: value };

    // Live preview: update CSS custom properties on the page
    const wrap = document.body;
    if (wrap) {
      const blockEl = wrap.querySelector(`[data-outpost-block="${setting.block}"]`);
      const target = blockEl || wrap;
      if (setting.type === 'color' || setting.type === 'range' || setting.type === 'number' || setting.type === 'text') {
        target.style.setProperty('--' + setting.name.replace(/_/g, '-'), value);
      } else if (setting.type === 'select') {
        target.setAttribute('data-' + setting.name.replace(/_/g, '-'), value);
      } else if (setting.type === 'toggle') {
        target.setAttribute('data-' + setting.name.replace(/_/g, '-'), value);
      }
    }
  }

  let hasSettingsChanges = $derived(
    Object.keys(blockSettingsValues).some(k => blockSettingsValues[k] !== originalBlockSettingsValues[k])
  );

  function formatSettingLabel(name) {
    return (name || '')
      .replace(/^loop_/, '')
      .replace(/_/g, ' ')
      .replace(/\b\w/g, c => c.toUpperCase());
  }

  // Group fields by block (from manifest) instead of flat section_name
  let sections = $derived(() => {
    const groups = {};

    if (manifestBlocks.length > 0) {
      // Build a set of all field names claimed by blocks
      const claimedFields = new Set();
      for (const block of manifestBlocks) {
        const blockFields = (block.fields || []);
        for (const fname of blockFields) {
          claimedFields.add(fname);
        }
      }

      // Create a group for each block
      for (const block of manifestBlocks) {
        const blockFieldNames = new Set(block.fields || []);
        const isGlobalBlock = !!block.global;
        const matching = pageFields.filter(f => {
          // For global blocks, match by field name AND isGlobal flag
          if (isGlobalBlock) return blockFieldNames.has(f.name) && f.isGlobal;
          // For page blocks, match by field name AND not global
          return blockFieldNames.has(f.name) && !f.isGlobal;
        });
        if (matching.length > 0) {
          groups[block.name] = matching;
        }
      }

      // Unclaimed page fields go into "Page Fields"
      const unclaimed = pageFields.filter(f => !f.isGlobal && !claimedFields.has(f.name));
      if (unclaimed.length > 0) {
        groups['Page Fields'] = unclaimed;
      }

      // Unclaimed global fields go into "Globals"
      const unclaimedGlobals = pageFields.filter(f => f.isGlobal && !claimedFields.has(f.name));
      if (unclaimedGlobals.length > 0) {
        groups['Globals'] = unclaimedGlobals;
      }
    } else {
      // Fallback: no block info, use old section_name grouping
      for (const f of pageFields) {
        const section = f.section || 'Content';
        if (!groups[section]) groups[section] = [];
        groups[section].push(f);
      }
    }

    return groups;
  });

  let sectionList = $derived(() => {
    const s = sections();
    return Object.entries(s).map(([name, fields]) => ({
      name,
      fields,
      count: fields.length,
      preview: getPreviewText(fields),
      isGlobal: fields.every(f => f.isGlobal),
    }));
  });

  // Fields for current section
  let currentFields = $derived(() => {
    if (!currentSection) return [];
    const s = sections();
    return s[currentSection] || [];
  });

  function getPreviewText(fields) {
    // Get first text/textarea field value as preview
    for (const f of fields) {
      if ((f.type === 'text' || f.type === 'textarea') && f.content) {
        const text = f.content.replace(/<[^>]*>/g, '').trim();
        if (text) return text.length > 60 ? text.substring(0, 60) + '...' : text;
      }
    }
    return '';
  }

  function humanizeSectionName(name) {
    if (!name) return 'Section';
    // "hero" -> "Hero Section", "nav" -> "Nav Section", "Content" stays as is
    const formatted = name.replace(/[-_]/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
    // Don't add "Section" if it already contains it or if it's a known standalone name
    if (formatted.toLowerCase().includes('section') || formatted === 'Content' || formatted === 'Globals') {
      return formatted;
    }
    return formatted;
  }

  // Navigation
  function navigateToSection(name) {
    currentSection = name;
    currentField = null;
    activeTab = 'general';
  }

  function navigateToField(field) {
    currentField = field;
  }

  function navigateBack() {
    if (currentField) {
      currentField = null;
    } else {
      currentSection = null;
      currentField = null;
      activeTab = 'general';
    }
  }

  function navigateHome() {
    currentSection = null;
    currentField = null;
    activeTab = 'general';
  }

  function handleInput(fieldName, value) {
    editValues = { ...editValues, [fieldName]: value };

    // Live preview -- update the DOM element on the page in real-time
    const wrap = document.body;
    if (!wrap) return;

    // If field name ends with _label, update the parent link's text content
    if (fieldName.endsWith('_label')) {
      const baseField = fieldName.replace(/_label$/, '');
      const el = wrap.querySelector(`[data-outpost="${baseField}"]`);
      if (el) el.textContent = value;
      return;
    }

    const field = pageFields.find(f => f.name === fieldName);

    try {
      // Try v2 data-outpost attribute first, fall back to CSS selector
      let el = wrap.querySelector(`[data-outpost="${fieldName}"]`);
      if (!el && field?.selector) {
        el = wrap.querySelector(field.selector);
      }
      if (!el) return;

      const ftype = field?.type || '';
      if (ftype === 'image') {
        if (el.tagName === 'IMG') {
          el.src = value;
        } else if (el.style?.backgroundImage) {
          el.style.backgroundImage = `url('${value}')`;
        }
      } else if (ftype === 'richtext') {
        el.innerHTML = value;
      } else if (ftype === 'toggle') {
        el.style.display = value ? '' : 'none';
      } else if (ftype === 'color') {
        el.style.color = value;
      } else if (ftype === 'link' || el.tagName === 'A') {
        const anchor = el.tagName === 'A' ? el : el.closest('a');
        if (anchor) anchor.href = value;
      } else if (ftype === 'number' || ftype === 'date') {
        el.textContent = value;
      } else {
        el.textContent = value;
      }
    } catch (e) {
      // Selector might not match
    }
  }

  function formatLabel(name) {
    return (name || '').replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
  }

  function parseOptions(optStr) {
    if (!optStr) return [];
    try {
      const parsed = JSON.parse(optStr);
      if (Array.isArray(parsed)) return parsed;
      if (typeof parsed === 'object') return Object.entries(parsed).map(([k, v]) => ({ value: k, label: v }));
    } catch {
      return optStr.split(',').map(s => s.trim()).filter(Boolean).map(s => ({ value: s, label: s }));
    }
    return [];
  }

  function getCharCount(value) {
    return (value || '').length;
  }

  function getFilename(url) {
    if (!url) return '';
    try {
      const parts = url.split('/');
      return parts[parts.length - 1] || url;
    } catch {
      return url;
    }
  }

  function getSectionIcon(name) {
    const lower = (name || '').toLowerCase();
    if (lower.includes('hero') || lower.includes('header') || lower.includes('banner')) return 'hero';
    if (lower.includes('nav') || lower.includes('menu')) return 'nav';
    if (lower.includes('footer')) return 'footer';
    if (lower.includes('seo') || lower.includes('meta')) return 'seo';
    if (lower.includes('social') || lower.includes('contact')) return 'social';
    if (lower.includes('image') || lower.includes('media') || lower.includes('gallery')) return 'media';
    if (lower.includes('cta') || lower.includes('button') || lower.includes('action')) return 'cta';
    if (lower.includes('about') || lower.includes('bio')) return 'about';
    if (lower.includes('feature') || lower.includes('service')) return 'features';
    if (lower === 'globals' || lower.includes('global')) return 'globals';
    return 'content';
  }

  function getFieldTypeIcon(type) {
    switch (type) {
      case 'text': return 'text';
      case 'textarea': return 'textarea';
      case 'richtext': return 'richtext';
      case 'image': return 'image';
      case 'gallery': return 'image';
      case 'link': return 'link';
      case 'toggle': return 'toggle';
      case 'select': return 'select';
      case 'color': return 'color';
      case 'number': return 'number';
      case 'date': return 'date';
      default: return 'text';
    }
  }

  async function saveChanges() {
    if (saving || !hasChanges) return;
    saving = true;
    saveErrors = [];
    try {
      const changedFields = [];
      for (const f of pageFields) {
        const savePageId = f.isGlobal && globalPageId ? globalPageId : pageId;
        if (editValues[f.name] !== originalValues[f.name]) {
          changedFields.push({
            page_id: savePageId,
            field_name: f.name,
            content: editValues[f.name],
          });
        }
        // Also save _label fields for link types
        if (f.type === 'link') {
          const labelKey = f.name + '_label';
          if (editValues[labelKey] !== originalValues[labelKey]) {
            changedFields.push({
              page_id: savePageId,
              field_name: labelKey,
              content: editValues[labelKey],
            });
          }
        }
      }

      const errors = [];
      if (changedFields.length > 0) {
        for (const cf of changedFields) {
          const resp = await fetch(apiUrl + '?action=editor/save-field', {
            method: 'POST',
            credentials: 'include',
            headers: {
              'Content-Type': 'application/json',
              'X-CSRF-Token': csrfToken,
            },
            body: JSON.stringify(cf),
          });
          if (!resp.ok) {
            const err = await resp.json().catch(() => ({}));
            errors.push(cf.field_name + ': ' + (err.error || 'Failed'));
          }
        }
      }

      // Save changed block settings
      const changedSettings = [];
      for (const key of Object.keys(blockSettingsValues)) {
        if (blockSettingsValues[key] !== originalBlockSettingsValues[key]) {
          const [block, ...nameParts] = key.split('.');
          changedSettings.push({ page_id: pageId, block, name: nameParts.join('.'), value: blockSettingsValues[key] });
        }
      }
      for (const cs of changedSettings) {
        const resp = await fetch(apiUrl + '?action=editor/block-settings', {
          method: 'PUT',
          credentials: 'include',
          headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
          body: JSON.stringify(cs),
        });
        if (!resp.ok) {
          const err = await resp.json().catch(() => ({}));
          errors.push(cs.block + '.' + cs.name + ': ' + (err.error || 'Failed'));
        }
      }

      if (errors.length > 0) {
        console.error('[OPE] Save errors:', errors);
        saveErrors = errors;
        setTimeout(() => { saveErrors = []; }, 5000);
      } else {
        originalValues = { ...editValues };
        originalBlockSettingsValues = { ...blockSettingsValues };
        saveSuccess = true;
        setTimeout(() => { saveSuccess = false; }, 2000);
      }
    } catch (err) {
      console.error('[OPE] Save error:', err);
      saveErrors = [err.message || 'Network error'];
      setTimeout(() => { saveErrors = []; }, 5000);
    } finally {
      saving = false;
    }
  }

  // Listen for save events dispatched from Editor.svelte (IconRail save button + Cmd+S)
  $effect(() => {
    function handleOutpostSave() {
      saveChanges();
    }
    window.addEventListener('outpost-save', handleOutpostSave);
    return () => window.removeEventListener('outpost-save', handleOutpostSave);
  });

  // ── Media Picker ──────────────────────────────────────
  function openMediaPicker(callback) {
    mediaPickerCallback = callback;
    mediaSelected = null;
    showMediaPicker = true;
    if (mediaItems.length === 0) loadMediaItems();
  }

  function closeMediaPicker() {
    showMediaPicker = false;
    mediaPickerCallback = null;
    mediaSelected = null;
  }

  function confirmMediaSelection() {
    if (mediaSelected && mediaPickerCallback) {
      mediaPickerCallback(mediaSelected);
    }
    closeMediaPicker();
  }

  async function loadMediaItems() {
    mediaLoading = true;
    try {
      const resp = await fetch(apiUrl + '?action=media', {
        credentials: 'include',
        headers: { 'X-CSRF-Token': csrfToken },
      });
      if (resp.ok) {
        const data = await resp.json();
        mediaItems = (data.media || []).filter(m => m.mime_type && m.mime_type.startsWith('image/'));
      }
    } catch (err) {
      console.error('[OPE] Failed to load media:', err);
    } finally {
      mediaLoading = false;
    }
  }

  async function handleMediaUpload(e) {
    const file = e.target.files[0];
    if (!file) return;
    e.target.value = '';

    const formData = new FormData();
    formData.append('file', file);
    formData.append('csrf_token', csrfToken);

    try {
      const resp = await fetch(apiUrl + '?action=media/upload', {
        method: 'POST',
        credentials: 'include',
        headers: { 'X-CSRF-Token': csrfToken },
        body: formData,
      });
      if (resp.ok) {
        const data = await resp.json();
        if (data.media) {
          mediaItems = [data.media, ...mediaItems];
        }
      }
    } catch (err) {
      console.error('[OPE] Upload failed:', err);
    }
  }

  // ── Gallery helpers ──────────────────────────────────
  function parseGallery(value) {
    if (!value) return [];
    try {
      const parsed = typeof value === 'string' ? JSON.parse(value) : value;
      return Array.isArray(parsed) ? parsed : [];
    } catch {
      return [];
    }
  }

  function galleryAdd(fieldName) {
    openMediaPicker((item) => {
      const current = parseGallery(editValues[fieldName]);
      const updated = [...current, item.path];
      handleInput(fieldName, JSON.stringify(updated));
    });
  }

  function galleryRemove(fieldName, index) {
    const current = parseGallery(editValues[fieldName]);
    const updated = current.filter((_, i) => i !== index);
    handleInput(fieldName, JSON.stringify(updated));
  }

  function galleryMoveUp(fieldName, index) {
    if (index <= 0) return;
    const current = parseGallery(editValues[fieldName]);
    const a = [...current];
    [a[index - 1], a[index]] = [a[index], a[index - 1]];
    handleInput(fieldName, JSON.stringify(a));
  }

  function galleryMoveDown(fieldName, index) {
    const current = parseGallery(editValues[fieldName]);
    if (index >= current.length - 1) return;
    const a = [...current];
    [a[index], a[index + 1]] = [a[index + 1], a[index]];
    handleInput(fieldName, JSON.stringify(a));
  }

  // ── Alt text helpers ──────────────────────────────────
  async function loadAltText(imagePath) {
    if (!imagePath || altTextCache[imagePath]) return;
    altTextCache = { ...altTextCache, [imagePath]: { altText: '', mediaId: null, loaded: false } };
    try {
      const resp = await fetch(apiUrl + '?action=editor/media-lookup&path=' + encodeURIComponent(imagePath), {
        credentials: 'include',
        headers: { 'X-CSRF-Token': csrfToken },
      });
      if (resp.ok) {
        const data = await resp.json();
        if (data.media) {
          altTextCache = { ...altTextCache, [imagePath]: { altText: data.media.alt_text || '', mediaId: data.media.id, loaded: true } };
        } else {
          altTextCache = { ...altTextCache, [imagePath]: { altText: '', mediaId: null, loaded: true } };
        }
      }
    } catch {
      altTextCache = { ...altTextCache, [imagePath]: { altText: '', mediaId: null, loaded: true } };
    }
  }

  async function saveAltText(imagePath, newAlt) {
    altTextCache = { ...altTextCache, [imagePath]: { ...altTextCache[imagePath], altText: newAlt } };
    altTextSaving = { ...altTextSaving, [imagePath]: true };
    try {
      await fetch(apiUrl + '?action=editor/media-alt', {
        method: 'POST',
        credentials: 'include',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-Token': csrfToken,
        },
        body: JSON.stringify({ path: imagePath, alt_text: newAlt }),
      });
    } catch (err) {
      console.error('[OPE] Failed to save alt text:', err);
    } finally {
      altTextSaving = { ...altTextSaving, [imagePath]: false };
    }
  }

  let altTextDebounceTimers = {};
  function handleAltTextInput(imagePath, value) {
    altTextCache = { ...altTextCache, [imagePath]: { ...altTextCache[imagePath], altText: value } };
    if (altTextDebounceTimers[imagePath]) clearTimeout(altTextDebounceTimers[imagePath]);
    altTextDebounceTimers[imagePath] = setTimeout(() => {
      saveAltText(imagePath, value);
    }, 600);
  }

  function sectionChangeCount(sectionName) {
    const s = sections();
    const fields = s[sectionName] || [];
    return fields.filter(f => editValues[f.name] !== originalValues[f.name]).length;
  }

  // Bridge integration: auto-navigate to highlighted field/block
  $effect(() => {
    if (!highlightedField && !highlightedBlock) return;
    if (loading || pageFields.length === 0) return;

    const s = sections();

    if (highlightedField) {
      for (const [sectionName, sectionFields] of Object.entries(s)) {
        const match = sectionFields.find(f => f.name === highlightedField);
        if (match) {
          currentSection = sectionName;
          currentField = null;
          activeTab = 'general';
          requestAnimationFrame(() => {
            const el = document.getElementById('ope-field-' + highlightedField);
            if (el) {
              el.scrollIntoView({ behavior: 'smooth', block: 'center' });
              el.focus();
              el.style.boxShadow = '0 0 0 3px rgba(45, 90, 71, 0.3)';
              setTimeout(() => { el.style.boxShadow = ''; }, 2000);
            }
          });
          return;
        }
      }
    }

    if (highlightedBlock) {
      const blockLower = highlightedBlock.toLowerCase();
      for (const sectionName of Object.keys(s)) {
        if (sectionName.toLowerCase() === blockLower ||
            sectionName.toLowerCase().includes(blockLower) ||
            blockLower.includes(sectionName.toLowerCase())) {
          currentSection = sectionName;
          currentField = null;
          activeTab = 'general';
          return;
        }
      }
      for (const [sectionName, sectionFields] of Object.entries(s)) {
        const match = sectionFields.find(f => (f.name || '').toLowerCase().includes(blockLower));
        if (match) {
          currentSection = sectionName;
          currentField = null;
          activeTab = 'general';
          return;
        }
      }
    }
  });
</script>

<div class="ope-edit-drawer">
  {#if loading}
    <div class="ope-edit-loading">
      <div class="ope-edit-spinner"></div>
      <span>Loading fields...</span>
    </div>
  {:else if pageFields.length === 0}
    <div class="ope-edit-empty">
      <div class="ope-edit-empty-icon">
        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="#D1D5DB" stroke-width="1.5">
          <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" stroke-linecap="round" stroke-linejoin="round"/>
          <polyline points="14 2 14 8 20 8" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
      </div>
      <p class="ope-edit-empty-title">No editable fields yet</p>
      <p class="ope-edit-empty-hint">Open this page's template in the Code Editor and click <strong>Smart Forge</strong> to auto-detect all editable content.</p>
      <a href="/outpost/#/code-editor" class="ope-edit-empty-link">Open Code Editor →</a>
    </div>

  {:else if currentField !== null}
    <!-- ═══ LEVEL 3: FIELD DETAIL ═══ -->
    <div class="ope-level-3">
      <nav class="ope-breadcrumb-bar">
        <button class="ope-breadcrumb-link" onclick={navigateHome}>{pageName}</button>
        <span class="ope-breadcrumb-sep">›</span>
        <button class="ope-breadcrumb-link" onclick={navigateBack}>{humanizeSectionName(currentSection)}</button>
        <span class="ope-breadcrumb-sep">›</span>
        <span class="ope-breadcrumb-current">{currentField.label || formatLabel(currentField.name)}</span>
      </nav>

      <div class="ope-field-detail-header">
        <h2 class="ope-field-detail-title">{currentField.label || formatLabel(currentField.name)}</h2>
        {#if currentField.isGlobal}
          <span class="ope-global-indicator">Global field — changes apply everywhere</span>
        {/if}
      </div>

      <div class="ope-field-detail-body">
        {@render fieldEditor(currentField)}
      </div>
    </div>

  {:else if currentSection !== null}
    <!-- ═══ LEVEL 2: SECTION DETAIL ═══ -->
    <div class="ope-level-2">
      <nav class="ope-breadcrumb-bar">
        <button class="ope-breadcrumb-link" onclick={navigateHome}>{pageName}</button>
        <span class="ope-breadcrumb-sep">›</span>
        <span class="ope-breadcrumb-current">{humanizeSectionName(currentSection)}</span>
      </nav>

      <div class="ope-section-detail-header">
        <h2 class="ope-section-title">{humanizeSectionName(currentSection)}</h2>
        <span class="ope-section-field-count">{currentFields().length} {currentFields().length === 1 ? 'field' : 'fields'}</span>
      </div>

      <!-- Tabs -->
      <div class="ope-tabs">
        <button
          class="ope-tab"
          class:ope-tab-active={activeTab === 'general'}
          onclick={() => { activeTab = 'general'; }}
        >General</button>
        {#if currentBlockSettings().length > 0}
        <button
          class="ope-tab"
          class:ope-tab-active={activeTab === 'style'}
          onclick={() => { activeTab = 'style'; }}
        >Style</button>
        {/if}
      </div>

      {#if activeTab === 'general'}
        <div class="ope-section-fields">
          {#each currentFields() as field}
            <div class="ope-field-group" id="ope-field-group-{field.name}">
              {@render fieldEditor(field)}
            </div>
          {/each}
        </div>
      {:else}
        <!-- Style tab -->
        {#if blockSettingsLoading}
          <div class="ope-edit-loading">
            <div class="ope-edit-spinner"></div>
            <span>Loading settings...</span>
          </div>
        {:else if currentBlockSettings().length === 0}
          <div class="ope-style-empty">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#D1D5DB" stroke-width="1.5">
              <circle cx="12" cy="12" r="3"/>
              <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
            <p class="ope-style-empty-title">No style settings defined</p>
            <p class="ope-style-empty-hint">Add <code>&lt;!-- outpost-settings: ... --&gt;</code> comments in your template to define block settings.</p>
          </div>
        {:else}
          <div class="ope-block-settings">
            {#each currentBlockSettings() as setting}
              {@const settingKey = setting.block + '.' + setting.name}
              {@const currentValue = blockSettingsValues[settingKey] ?? setting.default ?? ''}
              {@const isSaving = blockSettingsSaving[settingKey] || false}

              <div class="ope-setting-group">
                <div class="ope-field-label-row">
                  <label class="ope-field-label">{formatSettingLabel(setting.name)}</label>
                  <span class="ope-field-type-indicator">
                    {setting.type}
                    {#if isSaving}
                      <span class="ope-setting-saving-dot"></span>
                    {/if}
                  </span>
                </div>

                {#if setting.type === 'color'}
                  <div class="ope-color-wrap">
                    <input
                      type="color"
                      value={currentValue || '#000000'}
                      oninput={(e) => updateBlockSetting(setting, e.target.value)}
                      class="ope-color-swatch"
                    />
                    <input
                      class="ope-field-input ope-color-hex"
                      type="text"
                      value={currentValue || ''}
                      oninput={(e) => updateBlockSetting(setting, e.target.value)}
                      placeholder="#000000"
                    />
                  </div>

                {:else if setting.type === 'select'}
                  <select
                    class="ope-field-input ope-field-select"
                    value={currentValue}
                    onchange={(e) => updateBlockSetting(setting, e.target.value)}
                  >
                    {#each (setting.options || []) as opt}
                      <option value={opt} selected={opt === currentValue}>{opt}</option>
                    {/each}
                  </select>

                {:else if setting.type === 'range'}
                  <div class="ope-range-wrap">
                    <input
                      type="range"
                      min={setting.min ?? 0}
                      max={setting.max ?? 100}
                      value={currentValue}
                      oninput={(e) => updateBlockSetting(setting, e.target.value)}
                      class="ope-range-input"
                    />
                    <span class="ope-range-value">{currentValue}</span>
                  </div>

                {:else if setting.type === 'toggle'}
                  <label class="ope-toggle-wrap">
                    <input
                      type="checkbox"
                      checked={currentValue === '1' || currentValue === 'true' || currentValue === true}
                      onchange={(e) => updateBlockSetting(setting, e.target.checked ? 'true' : 'false')}
                      class="ope-toggle-input"
                    />
                    <span class="ope-toggle-track">
                      <span class="ope-toggle-thumb"></span>
                    </span>
                    <span class="ope-toggle-label-text">
                      {currentValue === '1' || currentValue === 'true' || currentValue === true ? 'Enabled' : 'Disabled'}
                    </span>
                  </label>

                {:else if setting.type === 'image'}
                  <div class="ope-setting-image-wrap">
                    {#if currentValue}
                      <div class="ope-setting-image-preview">
                        <img src={currentValue} alt="" class="ope-setting-image-thumb" />
                      </div>
                    {/if}
                    <input
                      class="ope-field-input"
                      type="text"
                      value={currentValue}
                      oninput={(e) => updateBlockSetting(setting, e.target.value)}
                      placeholder="Image URL..."
                    />
                  </div>

                {:else if setting.type === 'number'}
                  <input
                    class="ope-field-input"
                    type="number"
                    value={currentValue}
                    oninput={(e) => updateBlockSetting(setting, e.target.value)}
                    placeholder="0"
                  />

                {:else if setting.type === 'collection'}
                  <select
                    class="ope-field-input ope-field-select"
                    value={currentValue}
                    onchange={(e) => updateBlockSetting(setting, e.target.value)}
                  >
                    <option value="">-- Select collection --</option>
                    {#each blockSettingsCollections as coll}
                      <option value={coll.slug} selected={coll.slug === currentValue}>{coll.name || coll.slug}</option>
                    {/each}
                  </select>

                {:else}
                  <input
                    class="ope-field-input"
                    type="text"
                    value={currentValue}
                    oninput={(e) => updateBlockSetting(setting, e.target.value)}
                    placeholder="Enter value..."
                  />
                {/if}
              </div>
            {/each}
          </div>
        {/if}
      {/if}
    </div>

  {:else}
    <!-- ═══ LEVEL 1: SECTION LIST ═══ -->
    <div class="ope-level-1">
      <div class="ope-section-list-header">
        <span class="ope-section-list-label">SECTIONS</span>
        <span class="ope-section-list-count">{sectionList().length}</span>
      </div>

      <div class="ope-section-cards">
        {#each sectionList() as section}
          {@const changeCount = sectionChangeCount(section.name)}
          <button
            class="ope-section-card"
            class:ope-section-card-global={section.isGlobal}
            onclick={() => navigateToSection(section.name)}
          >
            <div class="ope-section-card-left">
              <div class="ope-section-card-icon" data-type={getSectionIcon(section.name)}>
                {#if getSectionIcon(section.name) === 'hero'}
                  <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18"/></svg>
                {:else if getSectionIcon(section.name) === 'nav'}
                  <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M3 12h18M3 6h18M3 18h18"/></svg>
                {:else if getSectionIcon(section.name) === 'footer'}
                  <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 15h18"/></svg>
                {:else if getSectionIcon(section.name) === 'media'}
                  <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                {:else if getSectionIcon(section.name) === 'social'}
                  <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M16 8a6 6 0 0 1 6 6v7h-4v-7a2 2 0 0 0-4 0v7h-4v-7a6 6 0 0 1 6-6z"/><rect x="2" y="9" width="4" height="12"/><circle cx="4" cy="4" r="2"/></svg>
                {:else if getSectionIcon(section.name) === 'cta'}
                  <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4M10 17l5-5-5-5M13 12H3"/></svg>
                {:else if getSectionIcon(section.name) === 'about'}
                  <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                {:else if getSectionIcon(section.name) === 'features'}
                  <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
                {:else if getSectionIcon(section.name) === 'globals'}
                  <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="12" cy="12" r="10"/><path d="M2 12h20M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
                {:else}
                  <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                {/if}
              </div>
              <div class="ope-section-card-info">
                <span class="ope-section-card-name">{humanizeSectionName(section.name)}</span>
                {#if section.preview}
                  <span class="ope-section-card-preview">{section.preview}</span>
                {:else}
                  <span class="ope-section-card-count">{section.count} {section.count === 1 ? 'field' : 'fields'}</span>
                {/if}
              </div>
            </div>
            <div class="ope-section-card-right">
              {#if changeCount > 0}
                <span class="ope-section-card-badge">{changeCount}</span>
              {/if}
              <svg class="ope-section-card-chevron" width="16" height="16" viewBox="0 0 16 16" fill="none">
                <path d="M6 4l4 4-4 4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
              </svg>
            </div>
          </button>
        {/each}
      </div>
    </div>
  {/if}

  <!-- Save bar -->
  {#if hasChanges}
    <div class="ope-save-bar">
      <div class="ope-save-bar-inner">
        <span class="ope-save-bar-text">Unsaved changes</span>
        <button class="ope-save-btn" onclick={saveChanges} disabled={saving}>
          {#if saving}
            <div class="ope-save-spinner"></div>
            Saving...
          {:else}
            Save
          {/if}
        </button>
      </div>
    </div>
  {/if}

  {#if saveSuccess}
    <div class="ope-save-toast">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <polyline points="20 6 9 17 4 12" stroke-linecap="round" stroke-linejoin="round"/>
      </svg>
      Changes saved
    </div>
  {/if}

  {#if saveErrors.length > 0}
    <div class="ope-save-toast ope-save-toast--error">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <circle cx="12" cy="12" r="10"/>
        <line x1="15" y1="9" x2="9" y2="15"/>
        <line x1="9" y1="9" x2="15" y2="15"/>
      </svg>
      {#if saveErrors.length === 1}
        Save failed: {saveErrors[0]}
      {:else}
        {saveErrors.length} fields failed to save
      {/if}
    </div>
  {/if}
</div>

<!-- Media Picker Modal -->
{#if showMediaPicker}
  <div class="ope-media-overlay" onclick={closeMediaPicker} role="dialog" aria-modal="true">
    <div class="ope-media-modal" onclick={(e) => e.stopPropagation()} role="document">
      <div class="ope-media-modal-header">
        <span class="ope-media-modal-title">Select Image</span>
        <button class="ope-media-modal-close" onclick={closeMediaPicker}>
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
      </div>
      <div class="ope-media-modal-toolbar">
        <label class="ope-media-upload-btn">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
          Upload
          <input type="file" accept="image/*" onchange={handleMediaUpload} hidden />
        </label>
      </div>
      <div class="ope-media-grid-wrap">
        {#if mediaLoading}
          <div class="ope-media-loading">Loading...</div>
        {:else if mediaItems.length === 0}
          <div class="ope-media-loading">No images found. Upload one above.</div>
        {:else}
          <div class="ope-media-grid">
            {#each mediaItems as item (item.id)}
              <div
                class="ope-media-item"
                class:ope-media-item-selected={mediaSelected?.id === item.id}
                onclick={() => mediaSelected = item}
                role="button"
                tabindex="0"
              >
                <img src={item.thumb_path || item.path} alt={item.alt_text || item.original_name} loading="lazy" />
              </div>
            {/each}
          </div>
        {/if}
      </div>
      <div class="ope-media-modal-footer">
        <button class="ope-media-cancel-btn" onclick={closeMediaPicker}>Cancel</button>
        <button class="ope-media-select-btn" onclick={confirmMediaSelection} disabled={!mediaSelected}>Select</button>
      </div>
    </div>
  </div>
{/if}

<!-- ═══ Snippet: Reusable field editor ═══ -->
{#snippet fieldEditor(field)}
  <div class="ope-field-label-row">
    <label class="ope-field-label" for="ope-field-{field.name}">
      {field.label || formatLabel(field.name)}
      {#if field.isGlobal}
        <span class="ope-global-badge" title="Changes to this field apply to all pages">Global</span>
      {/if}
    </label>
    <span class="ope-field-type-indicator">{field.type}</span>
  </div>

  {#if field.type === 'textarea'}
    <textarea
      id="ope-field-{field.name}"
      class="ope-field-input ope-field-textarea"
      value={editValues[field.name] || ''}
      oninput={(e) => handleInput(field.name, e.target.value)}
      rows="4"
      placeholder="Enter {formatLabel(field.name).toLowerCase()}..."
    ></textarea>
    <div class="ope-field-meta">
      <span></span>
      <span class="ope-char-count">{getCharCount(editValues[field.name])} chars</span>
    </div>

  {:else if field.type === 'richtext'}
    <div class="ope-field-richtext-wrap">
      <RichTextEditor
        content={editValues[field.name] || ''}
        onupdate={(html) => handleInput(field.name, html)}
        placeholder="Start writing..."
      />
    </div>

  {:else if field.type === 'image'}
    <div class="ope-image-card" role="button" tabindex="0" onclick={() => openMediaPicker((item) => handleInput(field.name, item.path))}>
      <div class="ope-image-thumb-wrap">
        {#if editValues[field.name]}
          <img src={editValues[field.name]} alt="" class="ope-image-thumb" />
        {:else}
          <div class="ope-image-placeholder">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#9CA3AF" stroke-width="1.5">
              <rect x="3" y="3" width="18" height="18" rx="2"/>
              <circle cx="8.5" cy="8.5" r="1.5"/>
              <polyline points="21 15 16 10 5 21"/>
            </svg>
          </div>
        {/if}
      </div>
      <div class="ope-image-info">
        {#if editValues[field.name]}
          <span class="ope-image-filename">{getFilename(editValues[field.name])}</span>
        {:else}
          <span class="ope-image-filename ope-image-filename-empty">No image selected</span>
        {/if}
        <span class="ope-image-alt">Click to change</span>
      </div>
    </div>
    <input
      id="ope-field-{field.name}"
      class="ope-field-input ope-image-url-input"
      type="text"
      value={editValues[field.name] || ''}
      oninput={(e) => handleInput(field.name, e.target.value)}
      placeholder="Image URL..."
    />
    {#if editValues[field.name]}
      {@const imgPath = editValues[field.name]}
      {(() => { loadAltText(imgPath); return ''; })()}
      {#if altTextCache[imgPath]?.loaded}
        <div class="ope-alt-text-row">
          <label class="ope-alt-text-label" for="ope-alt-{field.name}">Alt text</label>
          <input
            id="ope-alt-{field.name}"
            class="ope-field-input ope-alt-text-input"
            type="text"
            value={altTextCache[imgPath]?.altText || ''}
            oninput={(e) => handleAltTextInput(imgPath, e.target.value)}
            placeholder="Describe this image..."
          />
          {#if altTextSaving[imgPath]}
            <span class="ope-alt-text-saving">Saving...</span>
          {/if}
        </div>
      {/if}
    {/if}

  {:else if field.type === 'gallery'}
    {@const galleryPhotos = parseGallery(editValues[field.name])}
    <div class="ope-gallery-grid">
      {#each galleryPhotos as src, i (i)}
        <div class="ope-gallery-item">
          <img class="ope-gallery-thumb" {src} alt="" />
          <div class="ope-gallery-item-actions">
            <button class="ope-gallery-btn" onclick={() => galleryMoveUp(field.name, i)} disabled={i === 0} title="Move up">
              <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg>
            </button>
            <button class="ope-gallery-btn" onclick={() => galleryMoveDown(field.name, i)} disabled={i === galleryPhotos.length - 1} title="Move down">
              <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
            </button>
            <button class="ope-gallery-btn ope-gallery-btn-remove" onclick={() => galleryRemove(field.name, i)} title="Remove">
              <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
          </div>
        </div>
      {/each}
      <button class="ope-gallery-add" onclick={() => galleryAdd(field.name)}>
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
        <span>Add image</span>
      </button>
    </div>

  {:else if field.type === 'toggle'}
    <label class="ope-toggle-wrap">
      <input
        type="checkbox"
        checked={editValues[field.name] === '1' || editValues[field.name] === 'true' || editValues[field.name] === true}
        onchange={(e) => handleInput(field.name, e.target.checked ? '1' : '0')}
        class="ope-toggle-input"
      />
      <span class="ope-toggle-track">
        <span class="ope-toggle-thumb"></span>
      </span>
      <span class="ope-toggle-label-text">
        {editValues[field.name] === '1' || editValues[field.name] === 'true' || editValues[field.name] === true ? 'Enabled' : 'Disabled'}
      </span>
    </label>

  {:else if field.type === 'select'}
    <select
      id="ope-field-{field.name}"
      class="ope-field-input ope-field-select"
      value={editValues[field.name] || ''}
      onchange={(e) => handleInput(field.name, e.target.value)}
    >
      <option value="">-- Select --</option>
      {#each parseOptions(field.options) as opt}
        <option value={typeof opt === 'string' ? opt : opt.value}>
          {typeof opt === 'string' ? opt : opt.label}
        </option>
      {/each}
    </select>

  {:else if field.type === 'color'}
    <div class="ope-color-wrap">
      <input
        type="color"
        value={editValues[field.name] || '#000000'}
        oninput={(e) => handleInput(field.name, e.target.value)}
        class="ope-color-swatch"
      />
      <input
        id="ope-field-{field.name}"
        class="ope-field-input ope-color-hex"
        type="text"
        value={editValues[field.name] || ''}
        oninput={(e) => handleInput(field.name, e.target.value)}
        placeholder="#000000"
      />
    </div>

  {:else if field.type === 'number'}
    <input
      id="ope-field-{field.name}"
      class="ope-field-input"
      type="number"
      value={editValues[field.name] || ''}
      oninput={(e) => handleInput(field.name, e.target.value)}
      placeholder="0"
    />

  {:else if field.type === 'date'}
    <input
      id="ope-field-{field.name}"
      class="ope-field-input"
      type="date"
      value={editValues[field.name] || ''}
      oninput={(e) => handleInput(field.name, e.target.value)}
    />

  {:else if field.type === 'link'}
    <div class="ope-link-label-group">
      <label class="ope-link-sublabel" for="ope-field-{field.name}-label">Label</label>
      <input
        id="ope-field-{field.name}-label"
        class="ope-field-input"
        type="text"
        value={editValues[field.name + '_label'] || ''}
        oninput={(e) => handleInput(field.name + '_label', e.target.value)}
        placeholder="Button text..."
      />
    </div>
    <div class="ope-link-label-group">
      <label class="ope-link-sublabel" for="ope-field-{field.name}">URL</label>
      <div class="ope-link-wrap">
        <div class="ope-link-icon">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
            <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71" stroke-linecap="round" stroke-linejoin="round"/>
            <path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71" stroke-linecap="round" stroke-linejoin="round"/>
          </svg>
        </div>
        <input
          id="ope-field-{field.name}"
          class="ope-field-input ope-link-input"
          type="text"
          value={editValues[field.name] || ''}
          oninput={(e) => handleInput(field.name, e.target.value)}
          placeholder="Enter URL or path..."
        />
      </div>
    </div>

  {:else}
    <input
      id="ope-field-{field.name}"
      class="ope-field-input"
      type="text"
      value={editValues[field.name] || ''}
      oninput={(e) => handleInput(field.name, e.target.value)}
      placeholder="Enter {formatLabel(field.name).toLowerCase()}..."
    />
    <div class="ope-field-meta">
      <span></span>
      <span class="ope-char-count">{getCharCount(editValues[field.name])} chars</span>
    </div>
  {/if}
{/snippet}

<style>
  .ope-edit-drawer {
    padding: 0;
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Inter', sans-serif;
    height: 100%;
    position: relative;
  }

  /* ── Loading ─────────────────────────────────────────── */
  .ope-edit-loading {
    padding: 60px 24px;
    text-align: center;
    color: #9CA3AF;
    font-size: 13px;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 12px;
  }

  .ope-edit-spinner {
    width: 24px;
    height: 24px;
    border: 2px solid #E5E7EB;
    border-top-color: #2D5A47;
    border-radius: 50%;
    animation: ope-spin 0.6s linear infinite;
  }

  @keyframes ope-spin {
    to { transform: rotate(360deg); }
  }

  /* ── Empty state ─────────────────────────────────────── */
  .ope-edit-empty {
    padding: 60px 24px;
    text-align: center;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 8px;
  }
  .ope-edit-empty-icon { margin-bottom: 8px; opacity: 0.5; }
  .ope-edit-empty-title { margin: 0; font-size: 15px; font-weight: 600; color: #374151; }
  .ope-edit-empty-hint { margin: 0; font-size: 13px; color: #9CA3AF; }
  .ope-edit-empty-link { display: inline-block; margin-top: 12px; color: #2D5A47; font-size: 13px; font-weight: 500; text-decoration: none; }

  /* ══════════════════════════════════════════════════════
     BREADCRUMB BAR
     ══════════════════════════════════════════════════════ */
  .ope-breadcrumb-bar {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 16px 20px 0;
    flex-wrap: wrap;
  }

  .ope-breadcrumb-link {
    background: none;
    border: none;
    color: #6B7280;
    font-size: 12px;
    font-weight: 500;
    cursor: pointer;
    padding: 0;
    font-family: inherit;
    transition: color 0.12s;
    white-space: nowrap;
  }
  .ope-breadcrumb-link:hover {
    color: #2D5A47;
  }

  .ope-breadcrumb-sep {
    color: #D1D5DB;
    font-size: 12px;
    user-select: none;
  }

  .ope-breadcrumb-current {
    color: #111827;
    font-size: 12px;
    font-weight: 600;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: 180px;
  }

  /* ══════════════════════════════════════════════════════
     LEVEL 1 — SECTION LIST
     ══════════════════════════════════════════════════════ */
  .ope-level-1 {
    padding: 0 0 80px;
  }

  .ope-section-list-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 20px 20px 14px;
  }

  .ope-section-list-label {
    font-size: 11px;
    font-weight: 600;
    color: #9CA3AF;
    text-transform: uppercase;
    letter-spacing: 0.08em;
  }

  .ope-section-list-count {
    font-size: 11px;
    color: #9CA3AF;
    background: #F3F4F6;
    min-width: 22px;
    height: 22px;
    border-radius: 11px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 500;
    padding: 0 6px;
  }

  .ope-section-cards {
    display: flex;
    flex-direction: column;
    gap: 1px;
  }

  .ope-section-card {
    display: flex;
    align-items: center;
    justify-content: space-between;
    width: 100%;
    padding: 16px 20px;
    background: none;
    border: none;
    cursor: pointer;
    text-align: left;
    transition: background 0.12s;
    font-family: inherit;
    border-bottom: 1px solid #D1D5DB;
  }
  .ope-section-card:first-child {
    border-top: 1px solid #D1D5DB;
  }
  .ope-section-card:hover {
    background: #F3F4F6;
  }

  .ope-section-card-left {
    display: flex;
    align-items: center;
    gap: 12px;
    flex: 1;
    min-width: 0;
  }

  .ope-section-card-icon {
    width: 34px;
    height: 34px;
    border-radius: 8px;
    background: #F3F4F6;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #6B7280;
    flex-shrink: 0;
    transition: all 0.12s;
  }
  .ope-section-card:hover .ope-section-card-icon {
    background: #E5E7EB;
    color: #374151;
  }
  .ope-section-card-global .ope-section-card-icon {
    background: #EEF2FF;
    color: #6366F1;
  }

  .ope-section-card-info {
    flex: 1;
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: 2px;
  }

  .ope-section-card-name {
    font-size: 15px;
    font-weight: 600;
    color: #111827;
    line-height: 1.3;
  }

  .ope-section-card-preview {
    font-size: 12px;
    color: #6B7280;
    line-height: 1.3;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  .ope-section-card-count {
    font-size: 12px;
    color: #9CA3AF;
    line-height: 1.3;
  }

  .ope-section-card-right {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-shrink: 0;
  }

  .ope-section-card-badge {
    font-size: 10px;
    font-weight: 600;
    color: #fff;
    background: #2D5A47;
    min-width: 18px;
    height: 18px;
    border-radius: 9px;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 0 5px;
  }

  .ope-section-card-chevron {
    color: #D1D5DB;
    flex-shrink: 0;
    transition: color 0.12s;
  }
  .ope-section-card:hover .ope-section-card-chevron {
    color: #9CA3AF;
  }

  /* ══════════════════════════════════════════════════════
     LEVEL 2 — SECTION DETAIL
     ══════════════════════════════════════════════════════ */
  .ope-level-2 {
    padding: 0 0 100px;
  }

  .ope-section-detail-header {
    padding: 14px 20px 16px;
  }

  .ope-section-title {
    font-size: 17px;
    font-weight: 600;
    color: #111827;
    margin: 0 0 2px;
    line-height: 1.3;
  }

  .ope-section-field-count {
    font-size: 12px;
    color: #9CA3AF;
  }

  /* ── Tabs ─────────────────────────────────────────────── */
  .ope-tabs {
    display: flex;
    border-bottom: 1px solid #E5E7EB;
    padding: 0 20px;
    gap: 0;
  }

  .ope-tab {
    padding: 10px 16px;
    background: none;
    border: none;
    border-bottom: 2px solid transparent;
    color: #9CA3AF;
    font-size: 13px;
    font-weight: 500;
    cursor: pointer;
    font-family: inherit;
    transition: all 0.12s;
    margin-bottom: -1px;
  }
  .ope-tab:hover {
    color: #374151;
  }
  .ope-tab-active {
    color: #111827;
    border-bottom-color: #111827;
  }

  /* ── Style tab empty state ─────────────────────────── */
  .ope-style-empty {
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
    padding: 48px 24px;
    gap: 8px;
  }
  .ope-style-empty-title {
    margin: 0;
    font-size: 14px;
    font-weight: 600;
    color: #374151;
  }
  .ope-style-empty-hint {
    margin: 0;
    font-size: 12px;
    color: #9CA3AF;
    max-width: 260px;
    line-height: 1.5;
  }
  .ope-style-empty-hint code {
    font-size: 11px;
    background: #F3F4F6;
    padding: 1px 4px;
    border-radius: 3px;
    font-family: 'SF Mono', 'Fira Code', monospace;
  }

  /* ── Block Settings (Style tab) ──────────────────── */
  .ope-block-settings {
    padding: 16px 20px;
    display: flex;
    flex-direction: column;
    gap: 20px;
  }

  .ope-setting-group {
    display: flex;
    flex-direction: column;
  }

  .ope-setting-saving-dot {
    display: inline-block;
    width: 6px;
    height: 6px;
    background: #F59E0B;
    border-radius: 50%;
    margin-left: 4px;
    animation: ope-pulse 1s ease-in-out infinite;
    vertical-align: middle;
  }

  @keyframes ope-pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.3; }
  }

  .ope-range-wrap {
    display: flex;
    align-items: center;
    gap: 12px;
  }

  .ope-range-input {
    flex: 1;
    height: 4px;
    -webkit-appearance: none;
    appearance: none;
    background: #E5E7EB;
    border-radius: 2px;
    outline: none;
    cursor: pointer;
  }
  .ope-range-input::-webkit-slider-thumb {
    -webkit-appearance: none;
    width: 16px;
    height: 16px;
    background: #2D5A47;
    border-radius: 50%;
    border: none;
    cursor: pointer;
  }
  .ope-range-input::-moz-range-thumb {
    width: 16px;
    height: 16px;
    background: #2D5A47;
    border-radius: 50%;
    border: none;
    cursor: pointer;
  }

  .ope-range-value {
    font-size: 12px;
    font-weight: 600;
    color: #374151;
    min-width: 32px;
    text-align: right;
    font-variant-numeric: tabular-nums;
  }

  .ope-setting-image-wrap {
    display: flex;
    flex-direction: column;
    gap: 8px;
  }

  .ope-setting-image-preview {
    width: 100%;
    height: 80px;
    border-radius: 6px;
    overflow: hidden;
    background: #F3F4F6;
  }

  .ope-setting-image-thumb {
    width: 100%;
    height: 100%;
    object-fit: cover;
  }

  /* ══════════════════════════════════════════════════════
     LEVEL 3 — FIELD DETAIL
     ══════════════════════════════════════════════════════ */
  .ope-level-3 {
    padding: 0 0 100px;
  }

  .ope-field-detail-header {
    padding: 14px 20px 16px;
    border-bottom: 1px solid #F3F4F6;
  }

  .ope-field-detail-title {
    font-size: 17px;
    font-weight: 600;
    color: #111827;
    margin: 0 0 2px;
    line-height: 1.3;
  }

  .ope-global-indicator {
    font-size: 12px;
    color: #6366F1;
    font-weight: 500;
  }

  .ope-field-detail-body {
    padding: 20px 20px;
  }

  /* ══════════════════════════════════════════════════════
     FIELD EDITORS (shared across all levels)
     ══════════════════════════════════════════════════════ */
  .ope-section-fields {
    padding: 16px 20px;
    display: flex;
    flex-direction: column;
    gap: 20px;
  }

  .ope-field-group {
    display: flex;
    flex-direction: column;
  }

  .ope-field-label-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 6px;
  }

  .ope-field-label {
    font-size: 13px;
    font-weight: 500;
    color: #374151;
    line-height: 1.3;
  }

  .ope-field-type-indicator {
    font-size: 10px;
    font-weight: 500;
    color: #C4C9D2;
    text-transform: uppercase;
    letter-spacing: 0.04em;
  }

  .ope-global-badge {
    display: inline-block;
    font-size: 9px;
    font-weight: 600;
    color: #6366F1;
    background: #EEF2FF;
    padding: 1px 5px;
    border-radius: 3px;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    margin-left: 6px;
    vertical-align: middle;
    cursor: help;
  }

  .ope-field-input {
    width: 100%;
    border: 1px solid #E2E8F0;
    background: #FFFFFF;
    padding: 9px 12px;
    font-size: 13px;
    color: #111827;
    border-radius: 6px;
    transition: border-color 0.15s, box-shadow 0.15s;
    font-family: inherit;
    line-height: 1.5;
    box-sizing: border-box;
  }
  .ope-field-input:hover {
    border-color: #CBD5E1;
  }
  .ope-field-input:focus {
    border-color: #2D5A47;
    box-shadow: 0 0 0 3px rgba(45, 90, 71, 0.1);
    outline: none;
  }
  .ope-field-input::placeholder {
    color: #CBD5E1;
  }

  .ope-field-meta {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-top: 4px;
    min-height: 16px;
  }

  .ope-char-count {
    font-size: 11px;
    color: #C4C9D2;
  }

  .ope-field-hint {
    font-size: 11px;
    color: #C4C9D2;
  }

  /* ── Textarea ──────────────────────────────────────── */
  .ope-field-textarea {
    resize: vertical;
    min-height: 80px;
  }

  /* ── Richtext ──────────────────────────────────────── */
  .ope-field-richtext-wrap {
    display: flex;
    flex-direction: column;
    border: 1px solid #E2E8F0;
    border-radius: 8px;
    overflow: hidden;
  }

  .ope-field-richtext-wrap :global(.richtext-editor) {
    display: flex;
    flex-direction: column;
  }

  .ope-field-richtext-wrap :global(.richtext-toolbar) {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 2px;
    padding: 4px 6px;
    background: #F8FAFC;
    border-bottom: 1px solid #E2E8F0;
  }

  .ope-field-richtext-wrap :global(.richtext-toolbar button) {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 26px;
    height: 26px;
    padding: 0;
    border: none;
    border-radius: 4px;
    background: transparent;
    color: #64748B;
    font-size: 11px;
    font-weight: 600;
    cursor: pointer;
    transition: background 0.12s, color 0.12s;
    line-height: 1;
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
  }

  .ope-field-richtext-wrap :global(.richtext-toolbar button:hover) {
    background: #E2E8F0;
    color: #1E293B;
  }

  .ope-field-richtext-wrap :global(.richtext-toolbar button.active) {
    background: #E2E8F0;
    color: #0F172A;
  }

  .ope-field-richtext-wrap :global(.richtext-toolbar button strong) {
    font-size: 12px;
    font-weight: 700;
  }

  .ope-field-richtext-wrap :global(.richtext-toolbar button em) {
    font-size: 12px;
    font-style: italic;
  }

  .ope-field-richtext-wrap :global(.richtext-toolbar button s) {
    font-size: 12px;
  }

  .ope-field-richtext-wrap :global(.richtext-toolbar .separator) {
    width: 1px;
    height: 16px;
    background: #E2E8F0;
    margin: 0 3px;
    flex-shrink: 0;
  }

  .ope-field-richtext-wrap :global(.richtext-toolbar svg) {
    width: 13px;
    height: 13px;
  }

  .ope-field-richtext-wrap :global(.ProseMirror) {
    min-height: 120px;
    padding: 10px 12px;
    background: #fff;
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
    font-size: 13px;
    line-height: 1.6;
    color: #1E293B;
    outline: none;
  }

  .ope-field-richtext-wrap :global(.ProseMirror p) {
    margin: 0 0 0.5em;
  }

  .ope-field-richtext-wrap :global(.ProseMirror p:last-child) {
    margin-bottom: 0;
  }

  .ope-field-richtext-wrap :global(.ProseMirror h2) {
    font-size: 18px;
    font-weight: 600;
    margin: 0.8em 0 0.4em;
  }

  .ope-field-richtext-wrap :global(.ProseMirror h3) {
    font-size: 15px;
    font-weight: 600;
    margin: 0.6em 0 0.3em;
  }

  .ope-field-richtext-wrap :global(.ProseMirror ul),
  .ope-field-richtext-wrap :global(.ProseMirror ol) {
    padding-left: 1.4em;
    margin: 0.4em 0;
  }

  .ope-field-richtext-wrap :global(.ProseMirror blockquote) {
    border-left: 2px solid #E2E8F0;
    padding-left: 12px;
    margin: 0.5em 0;
    color: #64748B;
  }

  .ope-field-richtext-wrap :global(.ProseMirror code) {
    background: #F1F5F9;
    padding: 1px 4px;
    border-radius: 3px;
    font-size: 12px;
  }

  .ope-field-richtext-wrap :global(.ProseMirror pre) {
    background: #F1F5F9;
    padding: 10px 12px;
    border-radius: 6px;
    font-size: 12px;
    overflow-x: auto;
  }

  .ope-field-richtext-wrap :global(.ProseMirror .is-editor-empty:first-child::before) {
    content: attr(data-placeholder);
    color: #94A3B8;
    pointer-events: none;
    float: left;
    height: 0;
  }

  .ope-field-richtext-wrap :global(.ProseMirror a) {
    color: #3B82F6;
    text-decoration: underline;
  }

  .ope-field-richtext-wrap :global(.ProseMirror img) {
    max-width: 100%;
    border-radius: 4px;
    margin: 0.5em 0;
  }

  .ope-field-richtext-wrap :global(.ProseMirror hr) {
    border: none;
    border-top: 1px solid #E2E8F0;
    margin: 1em 0;
  }

  /* ── Image field ───────────────────────────────────── */
  .ope-image-card {
    display: flex;
    gap: 12px;
    padding: 10px;
    border: 1px solid #E2E8F0;
    border-radius: 8px;
    align-items: center;
    cursor: pointer;
    transition: border-color 0.15s, background 0.15s;
  }
  .ope-image-card:hover {
    border-color: #CBD5E1;
    background: #FAFAFA;
  }

  .ope-image-thumb-wrap {
    width: 48px;
    height: 48px;
    border-radius: 6px;
    overflow: hidden;
    background: #F3F4F6;
    flex-shrink: 0;
    display: flex;
    align-items: center;
    justify-content: center;
  }

  .ope-image-thumb {
    width: 100%;
    height: 100%;
    object-fit: cover;
  }

  .ope-image-placeholder {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 100%;
    height: 100%;
  }

  .ope-image-info {
    flex: 1;
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: 2px;
  }

  .ope-image-filename {
    font-size: 12px;
    font-weight: 500;
    color: #374151;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }
  .ope-image-filename-empty {
    color: #9CA3AF;
    font-weight: 400;
  }

  .ope-image-alt {
    font-size: 11px;
    color: #9CA3AF;
  }

  .ope-image-url-input {
    margin-top: 6px;
    font-size: 12px;
  }

  /* ── Toggle ────────────────────────────────────────── */
  .ope-toggle-wrap {
    display: flex;
    align-items: center;
    gap: 10px;
    cursor: pointer;
    padding: 4px 0;
  }
  .ope-toggle-input {
    position: absolute;
    opacity: 0;
    width: 0;
    height: 0;
  }
  .ope-toggle-track {
    width: 38px;
    height: 20px;
    background: #D1D5DB;
    border-radius: 10px;
    position: relative;
    transition: background 0.2s;
    flex-shrink: 0;
  }
  .ope-toggle-input:checked + .ope-toggle-track {
    background: #2D5A47;
  }
  .ope-toggle-thumb {
    position: absolute;
    top: 2px;
    left: 2px;
    width: 16px;
    height: 16px;
    background: white;
    border-radius: 50%;
    transition: transform 0.2s;
    box-shadow: 0 1px 3px rgba(0,0,0,0.12);
  }
  .ope-toggle-input:checked + .ope-toggle-track .ope-toggle-thumb {
    transform: translateX(18px);
  }
  .ope-toggle-label-text {
    font-size: 13px;
    color: #6B7280;
  }

  /* ── Select ────────────────────────────────────────── */
  .ope-field-select {
    appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg width='12' height='12' viewBox='0 0 12 12' fill='none' xmlns='http://www.w3.org/2000/svg'%3E%3Cpath d='M3 4.5L6 7.5L9 4.5' stroke='%239CA3AF' stroke-width='1.5' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 12px center;
    padding-right: 32px;
  }

  /* ── Color ─────────────────────────────────────────── */
  .ope-color-wrap {
    display: flex;
    align-items: center;
    gap: 10px;
  }
  .ope-color-swatch {
    width: 34px;
    height: 34px;
    border: 1px solid #E2E8F0;
    border-radius: 8px;
    padding: 3px;
    cursor: pointer;
    flex-shrink: 0;
    background: none;
  }
  .ope-color-swatch::-webkit-color-swatch-wrapper { padding: 0; }
  .ope-color-swatch::-webkit-color-swatch { border: none; border-radius: 4px; }
  .ope-color-hex { flex: 1; }

  /* ── Link field ────────────────────────────────────── */
  .ope-link-label-group {
    margin-bottom: 8px;
  }
  .ope-link-label-group:last-child {
    margin-bottom: 0;
  }
  .ope-link-sublabel {
    display: block;
    font-size: 11px;
    font-weight: 500;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: #9CA3AF;
    margin-bottom: 4px;
  }
  .ope-link-wrap {
    display: flex;
    align-items: center;
    gap: 0;
    position: relative;
  }
  .ope-link-icon {
    position: absolute;
    left: 12px;
    top: 50%;
    transform: translateY(-50%);
    color: #9CA3AF;
    display: flex;
    align-items: center;
    pointer-events: none;
    z-index: 1;
  }
  .ope-link-input {
    padding-left: 34px;
  }

  /* ══════════════════════════════════════════════════════
     SAVE BAR + TOAST
     ══════════════════════════════════════════════════════ */
  .ope-save-bar {
    position: fixed;
    bottom: 0;
    right: 56px;
    width: 400px;
    z-index: 2147483645;
    pointer-events: auto;
  }

  .ope-save-bar-inner {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 12px 20px;
    background: #fff;
    border-top: 1px solid #E5E7EB;
  }

  .ope-save-bar-text {
    font-size: 13px;
    color: #6B7280;
    font-weight: 500;
  }

  .ope-save-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 7px 18px;
    background: #2D5A47;
    color: white;
    border: none;
    border-radius: 6px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    transition: background 0.15s;
    font-family: inherit;
  }
  .ope-save-btn:hover { background: #245040; }
  .ope-save-btn:disabled { opacity: 0.6; cursor: not-allowed; }

  .ope-save-spinner {
    width: 14px;
    height: 14px;
    border: 2px solid rgba(255,255,255,0.3);
    border-top-color: white;
    border-radius: 50%;
    animation: ope-spin 0.6s linear infinite;
  }

  .ope-save-toast {
    position: fixed;
    bottom: 60px;
    right: 128px;
    background: #111827;
    color: white;
    font-size: 13px;
    font-weight: 500;
    padding: 8px 14px;
    border-radius: 6px;
    display: flex;
    align-items: center;
    gap: 6px;
    z-index: 2147483646;
    pointer-events: none;
    animation: ope-toast-in 0.2s ease-out;
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
  }

  .ope-save-toast--error {
    background: #dc2626;
  }

  @keyframes ope-toast-in {
    from { opacity: 0; transform: translateY(8px); }
    to { opacity: 1; transform: translateY(0); }
  }

  /* ── Alt text ────────────────────────────────────── */
  .ope-alt-text-row {
    margin-top: 6px;
    display: flex;
    flex-direction: column;
    gap: 4px;
    position: relative;
  }
  .ope-alt-text-label {
    font-size: 11px;
    font-weight: 500;
    color: #9CA3AF;
    text-transform: uppercase;
    letter-spacing: 0.04em;
  }
  .ope-alt-text-input {
    font-size: 12px;
  }
  .ope-alt-text-saving {
    font-size: 10px;
    color: #9CA3AF;
    position: absolute;
    right: 0;
    top: 0;
  }

  /* ── Gallery field ──────────────────────────────── */
  .ope-gallery-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 8px;
    margin-top: 4px;
  }
  .ope-gallery-item {
    position: relative;
    border-radius: 6px;
    overflow: visible;
  }
  .ope-gallery-thumb {
    width: 100%;
    aspect-ratio: 1;
    object-fit: cover;
    border-radius: 6px;
    border: 1px solid #E2E8F0;
    display: block;
  }
  .ope-gallery-item-actions {
    position: absolute;
    top: -6px;
    right: -6px;
    display: none;
    gap: 2px;
    background: #fff;
    border: 1px solid #E2E8F0;
    border-radius: 4px;
    padding: 2px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.12);
  }
  .ope-gallery-item:hover .ope-gallery-item-actions {
    display: flex;
  }
  .ope-gallery-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 20px;
    height: 20px;
    background: none;
    border: none;
    border-radius: 3px;
    cursor: pointer;
    color: #6B7280;
    transition: background 0.1s, color 0.1s;
    padding: 0;
  }
  .ope-gallery-btn:hover {
    background: #F3F4F6;
    color: #111827;
  }
  .ope-gallery-btn:disabled {
    opacity: 0.3;
    cursor: default;
  }
  .ope-gallery-btn-remove:hover {
    color: #EF4444;
  }
  .ope-gallery-add {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 4px;
    aspect-ratio: 1;
    border: 1px dashed #D1D5DB;
    border-radius: 6px;
    background: none;
    cursor: pointer;
    color: #9CA3AF;
    font-size: 11px;
    transition: border-color 0.15s, color 0.15s;
  }
  .ope-gallery-add:hover {
    border-color: #6B7280;
    color: #6B7280;
  }

  /* ── Media picker modal ──────────────────────────── */
  .ope-media-overlay {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0,0,0,0.5);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 2147483647;
  }
  .ope-media-modal {
    background: #fff;
    border-radius: 10px;
    width: 520px;
    max-width: 90vw;
    max-height: 80vh;
    display: flex;
    flex-direction: column;
    box-shadow: 0 20px 60px rgba(0,0,0,0.2);
  }
  .ope-media-modal-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 16px 20px;
    border-bottom: 1px solid #E5E7EB;
  }
  .ope-media-modal-title {
    font-size: 15px;
    font-weight: 600;
    color: #111827;
  }
  .ope-media-modal-close {
    background: none;
    border: none;
    color: #9CA3AF;
    cursor: pointer;
    padding: 4px;
    border-radius: 4px;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: background 0.12s;
  }
  .ope-media-modal-close:hover {
    background: #F3F4F6;
    color: #374151;
  }
  .ope-media-modal-toolbar {
    padding: 12px 20px;
    border-bottom: 1px solid #F3F4F6;
  }
  .ope-media-upload-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 12px;
    border: 1px solid #E2E8F0;
    border-radius: 6px;
    background: #fff;
    color: #374151;
    font-size: 13px;
    font-weight: 500;
    cursor: pointer;
    transition: border-color 0.15s;
  }
  .ope-media-upload-btn:hover {
    border-color: #CBD5E1;
  }
  .ope-media-grid-wrap {
    flex: 1;
    overflow-y: auto;
    padding: 16px 20px;
    min-height: 200px;
  }
  .ope-media-loading {
    text-align: center;
    color: #9CA3AF;
    font-size: 13px;
    padding: 40px 0;
  }
  .ope-media-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 8px;
  }
  .ope-media-item {
    border-radius: 6px;
    overflow: hidden;
    cursor: pointer;
    border: 2px solid transparent;
    transition: border-color 0.12s;
    aspect-ratio: 1;
  }
  .ope-media-item:hover {
    border-color: #CBD5E1;
  }
  .ope-media-item-selected {
    border-color: #2D5A47;
  }
  .ope-media-item img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
  }
  .ope-media-modal-footer {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 8px;
    padding: 12px 20px;
    border-top: 1px solid #E5E7EB;
  }
  .ope-media-cancel-btn {
    padding: 7px 14px;
    background: none;
    border: 1px solid #E2E8F0;
    border-radius: 6px;
    font-size: 13px;
    font-weight: 500;
    color: #374151;
    cursor: pointer;
    font-family: inherit;
    transition: border-color 0.15s;
  }
  .ope-media-cancel-btn:hover {
    border-color: #CBD5E1;
  }
  .ope-media-select-btn {
    padding: 7px 18px;
    background: #2D5A47;
    color: white;
    border: none;
    border-radius: 6px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    font-family: inherit;
    transition: background 0.15s;
  }
  .ope-media-select-btn:hover {
    background: #245040;
  }
  .ope-media-select-btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
  }

  /* ── Responsive ────────────────────────────────────── */
  @media (max-width: 768px) {
    .ope-save-bar {
      right: 0;
      width: 100%;
      bottom: 56px;
    }
  }

  @media (min-width: 769px) and (max-width: 1024px) {
    .ope-save-bar {
      width: 360px;
    }
  }
</style>
