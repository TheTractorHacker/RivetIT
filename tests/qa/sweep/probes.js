() => {
  const out = {small: [], overflow: [], overlap: [], btnHeights: [], truncated: [], lowres: [], raw: [], fonts: [], radii: [], lightInDark: [], bands: [], dates: [], hdr: {}};
  const vis = e => { const r = e.getBoundingClientRect(); const s = getComputedStyle(e); return r.width > 1 && r.height > 1 && s.visibility !== 'hidden' && s.display !== 'none' && s.opacity !== '0'; };
  const desc = e => (e.tagName.toLowerCase() + (e.id ? '#' + e.id : '') + (e.className && typeof e.className === 'string' ? '.' + e.className.trim().split(/\s+/).slice(0, 3).join('.') : '')).slice(0, 70);
  const txt = e => (e.innerText || '').trim().replace(/\s+/g, ' ').slice(0, 40);
  const root = document.querySelector('main') || document.body;
  const all = [...root.querySelectorAll('*')].filter(vis).slice(0, 4000);
  const vw = innerWidth;
  const fam = {}, rad = {};
  for (const e of all) {
    const s = getComputedStyle(e);
    const hasText = [...e.childNodes].some(n => n.nodeType === 3 && n.textContent.trim().length > 0);
    if (hasText) {
      const fs = parseFloat(s.fontSize);
      if (fs < 12 && !e.closest('.fa, .fas, .far, .fab, i, svg, sup, .badge-dot')) out.small.push(desc(e) + ' ' + fs + 'px "' + txt(e) + '"');
      fam[s.fontFamily.split(',')[0].trim()] = (fam[s.fontFamily.split(',')[0].trim()] || 0) + 1;
      if (/^(undefined|null|NaN|\[object|Array)$/i.test(txt(e)) || /\b(undefined|NaN|\[object Object\]|lorem ipsum|TODO|FIXME|\{\{|%s|%d)\b/i.test(e.innerText || '') && e.children.length === 0) out.raw.push(desc(e) + ' "' + txt(e) + '"');
      if (/^[a-z]+(_[a-z0-9]+){2,}$/.test(txt(e))) out.raw.push('rawkey ' + desc(e) + ' "' + txt(e) + '"');
      if ((s.textOverflow === 'ellipsis' || (s.overflow === 'hidden' && s.whiteSpace === 'nowrap')) && e.scrollWidth > e.clientWidth + 1 && !e.getAttribute('title') && !e.closest('[title]') && !e.getAttribute('data-bs-toggle') ) out.truncated.push(desc(e) + ' "' + txt(e) + '"');
    }
    const r = e.getBoundingClientRect();
    if (e.matches('.btn, .badge, .form-control, .form-select')) { const k = Math.round(parseFloat(s.borderTopLeftRadius)); const key = (e.className.toString().match(/btn|badge|form-control|form-select/) || [''])[0]; rad[key + ':' + k] = (rad[key + ':' + k] || 0) + 1; }
    if (r.right > vw + 1 && !e.closest('.modal, .dropdown-menu, .tooltip, .popover, .offcanvas, .sticky-top') && getComputedStyle(e).position !== 'fixed') out.overflow.push('viewport ' + desc(e) + ' right=' + Math.round(r.right));
    else if (e.parentElement && e.parentElement !== document.body && !e.matches('.dropdown-menu, .tooltip, .popover, tr, td, th, thead, tbody, .visually-hidden, .sr-only') && s.position !== 'absolute' && s.position !== 'fixed') {
      const pr = e.parentElement.getBoundingClientRect(); const ps = getComputedStyle(e.parentElement);
      if (ps.overflowX === 'visible' && (r.right > pr.right + 2 && pr.width > 0) && r.width < vw) out.overflow.push('parent ' + desc(e) + ' in ' + desc(e.parentElement) + ' +' + Math.round(r.right - pr.right) + 'px');
    }
    if (e.tagName === 'IMG') { if (!e.complete || e.naturalWidth === 0) out.lowres.push('broken ' + (e.getAttribute('src') || '').slice(0, 60)); else if (e.naturalWidth < r.width * 0.9 && e.naturalWidth > 0 && !/\.svg/.test(e.currentSrc)) out.lowres.push('lowres ' + e.naturalWidth + '<' + Math.round(r.width) + ' ' + (e.getAttribute('src') || '').slice(0, 50)); }
  }
  out.fonts = Object.entries(fam).sort((a, b) => b[1] - a[1]).slice(0, 4).map(x => x.join(':'));
  out.radii = Object.entries(rad).map(x => x.join(':'));
  // buttons in the same parent with different heights
  const byParent = new Map();
  for (const b of root.querySelectorAll('.btn:not(.btn-close)')) { if (!vis(b)) continue; const p = b.parentElement; if (!byParent.has(p)) byParent.set(p, []); byParent.get(p).push(b); }
  for (const [p, bs] of byParent) { if (bs.length < 2) continue; const hs = bs.map(b => Math.round(b.getBoundingClientRect().height)); if (Math.max(...hs) - Math.min(...hs) > 3) out.btnHeights.push(desc(p) + ' heights=' + hs.join(',') + ' [' + bs.map(txt).join('|').slice(0, 60) + ']'); }
  // overlapping controls
  const ctrls = [...root.querySelectorAll('.btn, a.nav-link, input, select, textarea, .badge')].filter(vis).slice(0, 300);
  for (let i = 0; i < ctrls.length; i++) for (let j = i + 1; j < Math.min(ctrls.length, i + 12); j++) {
    const a = ctrls[i], b2 = ctrls[j]; if (a.contains(b2) || b2.contains(a)) continue;
    const ra = a.getBoundingClientRect(), rb = b2.getBoundingClientRect();
    const w = Math.min(ra.right, rb.right) - Math.max(ra.left, rb.left), h = Math.min(ra.bottom, rb.bottom) - Math.max(ra.top, rb.top);
    if (w > 4 && h > 4 && !a.closest('.tomselected, .ts-wrapper') && !b2.closest('.ts-wrapper')) out.overlap.push(desc(a) + ' x ' + desc(b2) + ' ' + Math.round(w) + 'x' + Math.round(h));
  }
  // dark theme: big light surfaces
  const dark = document.documentElement.getAttribute('data-bs-theme') === 'dark';
  const lum = c => { const m = c.match(/[\d.]+/g); if (!m) return 0; const [r, g, b, a] = m.map(Number); if (a === 0) return -1; return (0.2126 * r + 0.7152 * g + 0.0722 * b) / 255; };
  if (dark) for (const e of all) { const r = e.getBoundingClientRect(); if (r.width * r.height < 6000) continue; const l = lum(getComputedStyle(e).backgroundColor); if (l > 0.72) out.lightInDark.push(desc(e) + ' ' + Math.round(r.width) + 'x' + Math.round(r.height) + ' lum=' + l.toFixed(2)); }
  // big vertical empty bands between consecutive blocks in main
  const kids = [...(document.querySelector('main') || document.body).querySelectorAll(':scope > *, :scope > * > *')].filter(vis).map(e => e.getBoundingClientRect()).sort((a, b) => a.top - b.top);
  let prevBottom = null; for (const r of kids) { if (prevBottom !== null && r.top - prevBottom > 280 && r.top < document.body.scrollHeight - 200) out.bands.push(Math.round(prevBottom) + '->' + Math.round(r.top)); prevBottom = Math.max(prevBottom ?? 0, r.bottom); }
  // header geometry for sibling-page comparison
  const h1 = document.querySelector('main h1, h1, .page-title'); const bc = document.querySelector('.breadcrumb'); const tb = document.querySelector('.card-header, .page-header');
  const g = e => e ? { x: Math.round(e.getBoundingClientRect().x), y: Math.round(e.getBoundingClientRect().y + scrollY), h: Math.round(e.getBoundingClientRect().height), fs: getComputedStyle(e).fontSize } : null;
  out.hdr = { title: h1 ? txt(h1) : null, h1: g(h1), bc: g(bc), bar: g(tb) };
  // date / number format samples
  const body = (root.innerText || '').slice(0, 20000);
  const fmts = {}; for (const [n, re] of [['iso', /\b\d{4}-\d{2}-\d{2}\b/g], ['us', /\b\d{1,2}\/\d{1,2}\/\d{2,4}\b/g], ['dmy', /\b\d{1,2}\.\d{1,2}\.\d{2,4}\b/g], ['long', /\b(Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[a-z]* \d{1,2},? \d{4}\b/g], ['dmon', /\b\d{1,2} (Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[a-z]* \d{4}\b/g], ['cur$', /\$\d[\d,]*(\.\d+)?/g], ['cur-eu', /€\s?\d/g]]) { const m = body.match(re); if (m) fmts[n] = m.length; }
  out.dates = fmts;
  out.meta = { h: document.documentElement.scrollHeight, w: document.documentElement.scrollWidth, table: !!root.querySelector('table'), form: !!root.querySelector('form:not([role=search])'), modal: !!root.querySelector('.ajax-modal, [data-bs-toggle=modal]'), cards: root.querySelectorAll('.card').length, dark, text: (root.innerText || '').trim().replace(/\s+/g, ' ').slice(0, 90) };
  for (const k of ['small', 'overflow', 'overlap', 'btnHeights', 'truncated', 'lowres', 'raw', 'lightInDark']) out[k] = [...new Set(out[k])].slice(0, 6);
  return out;
}
