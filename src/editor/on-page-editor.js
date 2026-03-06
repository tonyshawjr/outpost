// Outpost CMS — On-Page Editor
// Entry point. Always active for logged-in admins.
import './styles.css';
import { init as initScanner, scan } from './scanner.js';
import { init as initOverlay, enable as enableOverlay, setClickHandler } from './overlay.js';
import { init as initTextEditor, activate as activateText, isActive as isTextActive } from './text-editor.js';
import { init as initRichtextEditor, activate as activateRichtext, isActive as isRichtextActive } from './richtext-editor.js';
import { init as initImagePicker, activate as activateImage, isActive as isImageActive } from './image-picker.js';
import { init as initSaveManager } from './save-manager.js';

function handleFieldClick(field) {
  console.log('[OPE] Click:', field.fieldName, field.type);
  if (isTextActive() || isRichtextActive() || isImageActive()) return;

  switch (field.type) {
    case 'text':
    case 'textarea':
      activateText(field);
      break;
    case 'richtext':
      activateRichtext(field);
      break;
    case 'image':
      activateImage(field);
      break;
  }
}

function boot() {
  try {
    console.log('[OPE] Booting on-page editor...');
    console.log('[OPE] Context:', window.__OPE ? 'present' : 'MISSING');
    initScanner();
    initOverlay();
    initTextEditor();
    initRichtextEditor();
    initImagePicker();
    initSaveManager();

    scan();
    enableOverlay();
    setClickHandler(handleFieldClick);
    console.log('[OPE] Boot complete');
  } catch (err) {
    console.error('[OPE] Boot error:', err);
  }
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', boot);
} else {
  boot();
}
