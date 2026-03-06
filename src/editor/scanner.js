// Scanner — finds data-ope-* annotated elements and image fields, builds editable field map

let fields = [];

export function init() {
  // scan is called explicitly from boot()
}

export function scan() {
  fields = [];

  // 1. Find all annotated text/richtext/textarea elements
  const annotated = document.querySelectorAll('[data-ope-field]');
  console.log('[OPE] Found', annotated.length, 'annotated elements');
  annotated.forEach(el => {
    console.log('[OPE]  -', el.dataset.opeField, '(' + (el.dataset.opeType || 'text') + ')', el.dataset.opeGlobal === '1' ? '[global]' : '', el);
    fields.push({
      el,
      key: fieldKey(el),
      fieldName: el.dataset.opeField,
      type: el.dataset.opeType || 'text',
      id: parseInt(el.dataset.opeId) || 0,
      pageId: parseInt(el.dataset.opePage) || 0,
      itemId: parseInt(el.dataset.opeItem) || 0,
      collection: el.dataset.opeCollection || '',
      global: el.dataset.opeGlobal === '1',
    });
  });

  // 2. Match image fields to <img> elements by src
  const ctx = window.__OPE || {};
  if (ctx.imageFields) {
    console.log('[OPE] Image fields to match:', ctx.imageFields.length);
    const allImgs = document.querySelectorAll('img[src]');
    console.log('[OPE] Total <img> elements on page:', allImgs.length);
    for (const img of ctx.imageFields) {
      if (!img.value) { console.log('[OPE]  - Skipping', img.name, '(empty value)'); continue; }
      let imgEl = null;
      for (const candidate of allImgs) {
        if (candidate.getAttribute('src')?.includes(img.value) || candidate.src?.includes(img.value)) {
          imgEl = candidate;
          break;
        }
      }
      if (!imgEl) {
        console.log('[OPE]  - NO MATCH for', img.name, 'value:', img.value);
        // Log all img srcs for debugging
        allImgs.forEach(i => console.log('[OPE]    img src:', i.getAttribute('src')));
        continue;
      }
      console.log('[OPE]  - Matched', img.name, '->', imgEl);
      fields.push({
        el: imgEl,
        key: img.item ? `item:${img.item}:${img.name}` : `field:${img.id}`,
        fieldName: img.name,
        type: 'image',
        id: img.id || 0,
        pageId: img.page || 0,
        itemId: img.item || 0,
        collection: img.collection || '',
        global: !!img.global,
      });
    }
  }

  console.log('[OPE] Total editable fields:', fields.length);
  return fields;
}

export function getFields() {
  return fields;
}

function fieldKey(el) {
  if (el.dataset.opeItem) {
    return `item:${el.dataset.opeItem}:${el.dataset.opeField}`;
  }
  if (el.dataset.opeId) {
    return `field:${el.dataset.opeId}`;
  }
  return `page:${el.dataset.opePage}:${el.dataset.opeField}`;
}
