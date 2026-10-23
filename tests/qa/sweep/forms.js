(form) => {
  const vis = e => { const r = e.getBoundingClientRect(); const s = getComputedStyle(e); return r.width > 0 && r.height > 0 && s.visibility !== 'hidden' && s.display !== 'none'; };
  const labelOf = e => { let t = ''; if (e.id) { const l = document.querySelector('label[for="' + CSS.escape(e.id) + '"]'); if (l) t = l.innerText; } if (!t) { const l = e.closest('label') || (e.closest('.form-group, .mb-3, .col, [class*=col-]') || {}).querySelector?.('label'); if (l) t = l.innerText; } return t.trim().replace(/\s+/g, ' ').slice(0, 50); };
  const fields = [];
  const els = [...form.querySelectorAll('input, select, textarea')];
  const ext = form.id ? [...document.querySelectorAll('[form="' + CSS.escape(form.id) + '"]')] : [];
  for (const e of els.concat(ext)) {
    const type = e.tagName === 'SELECT' ? 'select' : e.tagName === 'TEXTAREA' ? 'textarea' : (e.type || 'text');
    if (['submit', 'button', 'reset', 'image'].includes(type)) continue;
    const pane = e.closest('.tab-pane');
    const f = { name: e.name || null, id: e.id || null, type, required: e.required, maxlength: e.maxLength > 0 && e.maxLength < 1e6 ? e.maxLength : null, minlength: e.minLength > 0 ? e.minLength : null,
      min: e.min || null, max: e.max || null, step: e.step || null, pattern: e.pattern || null, placeholder: (e.placeholder || '').slice(0, 40), disabled: e.disabled, readOnly: e.readOnly,
      visible: vis(e), pane: pane ? pane.id : null, inactivePane: pane ? !pane.classList.contains('active') && !pane.classList.contains('show') : false,
      tom: !!e.tomselect, tiny: !!(window.tinymce && e.id && tinymce.get(e.id)), value: type === 'password' ? '' : (e.value || '').slice(0, 60), checked: e.checked, label: labelOf(e), autocomplete: e.autocomplete || null, ac: e.getAttribute('autocomplete'), autofocus: e.autofocus,
      options: e.tagName === 'SELECT' ? [...e.options].slice(0, 6).map(o => o.value + '=' + o.text.trim().slice(0, 20)) : null, optionCount: e.tagName === 'SELECT' ? e.options.length : null,
      multiple: e.multiple || false };
    fields.push(f);
  }
  const sub = [...form.querySelectorAll('button[type=submit], input[type=submit], button:not([type])')].concat(form.id ? [...document.querySelectorAll('button[form="' + CSS.escape(form.id) + '"]')] : []).concat([...(form.closest('.modal-content') || document.createElement('div')).querySelectorAll('.modal-footer button[type=submit]')]);
  return { action: form.getAttribute('action'), method: (form.method || 'get').toLowerCase(), id: form.id || null, enctype: form.enctype, nfields: fields.length, fields,
           submit: [...new Set(sub)].map(b => ({ text: (b.innerText || b.value || '').trim().slice(0, 30), name: b.name || null, cls: (b.className || '').toString().slice(0, 50), visible: vis(b) })) };
}
