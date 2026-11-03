// Accessibility basics for the new Slack/Teams, Quality and employee-portal pages (labels, accessible names, focus visibility, contrast, headings, duplicate ids).
// Scratch instance only (fictional demo data):
//   BASE=http://127.0.0.1:19081 OUT=/some/dir ADMIN_EMAIL=admin@scratch.test ADMIN_PASS=... EMP_EMAIL=alice@a.test EMP_PASS=... node tests/a11y_new_pages.js
// Dark mode is server-side (settings.config_theme_dark_default); this script flips it with DARK_CMD (a shell command that takes "0" or "1"), or run it once per theme with THEME=light|dark.
// Prints one line per finding and exits 1 when anything is found. Needs playwright-core and a Chromium (paths below are this machine's).
const { chromium } = require('/home/sysadmin/.npm/_npx/db89d7302a373f10/node_modules/playwright-core');
const fs = require('fs');
const BASE = process.env.BASE || 'http://127.0.0.1:19081';
const OUT = process.env.OUT || '/tmp/a11y-out';
const THEME = process.env.THEME || 'light';
fs.mkdirSync(OUT, { recursive: true });

// Runs in the page. Returns an array of {rule, detail}.
const probe = () => {
  const found = [];
  const vis = (e) => { const r = e.getBoundingClientRect(); const s = getComputedStyle(e); return r.width > 0 && r.height > 0 && s.visibility !== 'hidden' && s.display !== 'none'; };
  const label = (e) => (e.id ? e.id : e.name || e.tagName.toLowerCase()) + (e.className && typeof e.className === 'string' ? '.' + e.className.split(/\s+/)[0] : '');
  // 1. form controls need a programmatic label
  for (const e of document.querySelectorAll('input:not([type=hidden]):not([type=submit]):not([type=button]):not([type=image]), select, textarea')) {
    if (!vis(e) && e.type !== 'checkbox' && e.type !== 'radio') continue;
    const named = e.getAttribute('aria-label') || e.getAttribute('aria-labelledby') || e.getAttribute('title') || (e.labels && e.labels.length) || e.closest('label');
    if (!named) found.push({ rule: 'control-has-no-label', detail: label(e) + (e.placeholder ? ' (placeholder only: "' + e.placeholder + '")' : '') });
  }
  // 2. buttons and links need an accessible name
  for (const e of document.querySelectorAll('button, a[href], [role=button]')) {
    if (!vis(e)) continue;
    const name = (e.innerText || '').trim() || e.getAttribute('aria-label') || e.getAttribute('title') || e.getAttribute('aria-labelledby') || (e.querySelector('img[alt]') || {}).alt;
    if (!name) found.push({ rule: 'no-accessible-name', detail: label(e) + ' <' + e.tagName.toLowerCase() + '> ' + (e.getAttribute('href') || '').slice(0, 60) });
  }
  // 3. images need alt
  for (const e of document.querySelectorAll('img')) if (vis(e) && !e.hasAttribute('alt')) found.push({ rule: 'img-without-alt', detail: (e.getAttribute('src') || '').slice(0, 80) });
  // 4. duplicate ids
  const seen = {}; for (const e of document.querySelectorAll('[id]')) seen[e.id] = (seen[e.id] || 0) + 1;
  for (const [id, n] of Object.entries(seen)) if (n > 1) found.push({ rule: 'duplicate-id', detail: id + ' x' + n });
  // 5. document language, a single h1, and no skipped heading level
  if (!document.documentElement.lang) found.push({ rule: 'no-html-lang', detail: '' });
  const hs = [...document.querySelectorAll('h1,h2,h3,h4,h5,h6')].filter(vis);
  if (hs.filter((h) => h.tagName === 'H1').length > 1) found.push({ rule: 'multiple-h1', detail: String(hs.filter((h) => h.tagName === 'H1').length) });
  let prev = 0; for (const h of hs) { const l = +h.tagName[1]; if (prev && l > prev + 1) found.push({ rule: 'heading-level-skipped', detail: 'h' + prev + ' -> h' + l + ' "' + h.innerText.trim().slice(0, 40) + '"' }); prev = l; }
  // 6. text contrast (WCAG AA 4.5, large text 3)
  const parse = (c) => { const sr = c.match(/color\(srgb ([\d.]+) ([\d.]+) ([\d.]+)(?: \/ ([\d.]+))?\)/); if (sr) return [+sr[1] * 255, +sr[2] * 255, +sr[3] * 255, sr[4] === undefined ? 1 : +sr[4]]; const m = c.match(/rgba?\(([\d.]+),\s*([\d.]+),\s*([\d.]+)(?:,\s*([\d.]+))?/); return m ? [+m[1], +m[2], +m[3], m[4] === undefined ? 1 : +m[4]] : null; };
  const over = (f, b) => { const a = f[3]; return [f[0] * a + b[0] * (1 - a), f[1] * a + b[1] * (1 - a), f[2] * a + b[2] * (1 - a), 1]; };
  const hasImageBg = (e) => { for (let n = e; n; n = n.parentElement) { const s = getComputedStyle(n); if (s.backgroundImage !== 'none') return true; const c = parse(s.backgroundColor); if (c && c[3] === 1) return false; } return false; };
  const bgOf = (e) => { const chain = []; for (let n = e; n; n = n.parentElement) { const c = parse(getComputedStyle(n).backgroundColor); if (c && c[3] > 0) { chain.push(c); if (c[3] === 1) break; } } let acc = parse(getComputedStyle(document.body).backgroundColor); if (!acc || acc[3] < 1) acc = [255, 255, 255, 1]; if (chain.length && chain[chain.length - 1][3] === 1) acc = chain.pop(); while (chain.length) acc = over(chain.pop(), acc); return acc; };
  const lum = (c) => { const f = (v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); }; return 0.2126 * f(c[0]) + 0.7152 * f(c[1]) + 0.0722 * f(c[2]); };
  const bad = new Map();
  const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
  for (let t = walker.nextNode(); t; t = walker.nextNode()) {
    const txt = t.nodeValue.trim(); if (!txt) continue;
    const e = t.parentElement; if (!e || !vis(e) || /^(SCRIPT|STYLE|NOSCRIPT)$/.test(e.tagName)) continue;
    const s = getComputedStyle(e); if (+s.opacity === 0) continue;
    if (hasImageBg(e)) continue; // gradient or image behind the text: the colour cannot be measured from CSS, check by eye in the screenshot
    const fg0 = parse(s.color); if (!fg0) continue;
    const bg = bgOf(e); const fg = over(fg0, bg);
    const L1 = lum(fg), L2 = lum(bg); const ratio = (Math.max(L1, L2) + 0.05) / (Math.min(L1, L2) + 0.05);
    const px = parseFloat(s.fontSize); const large = px >= 24 || (px >= 18.66 && +s.fontWeight >= 700);
    if (ratio < (large ? 3 : 4.5) && !e.closest('[disabled], .disabled')) { const k = label(e) + '|' + s.color + '|' + s.backgroundColor; if (!bad.has(k)) bad.set(k, { rule: 'low-contrast', detail: ratio.toFixed(2) + ':1 ' + label(e) + ' "' + txt.slice(0, 40) + '" fg ' + s.color + ' on ' + 'rgb(' + bg.slice(0, 3).map(Math.round).join(',') + ')' }); }
  }
  found.push(...bad.values());
  return found;
};

// Tab through the page: every focus stop must show an outline, a box-shadow ring or a border/background change.
const focusProbe = async (page) => {
  const bad = [];
  await page.evaluate(() => { document.activeElement && document.activeElement.blur(); window.scrollTo(0, 0); });
  for (let i = 0; i < 40; i++) {
    await page.keyboard.press('Tab');
    const r = await page.evaluate(() => {
      const e = document.activeElement; if (!e || e === document.body || e.id === 'globalSearchInput') return null; // the shared top-bar search is not one of the new pages
      const s = getComputedStyle(e);
      const outline = s.outlineStyle !== 'none' && parseFloat(s.outlineWidth) > 0;
      const shadow = s.boxShadow && s.boxShadow !== 'none';
      return { name: (e.id || e.name || e.tagName.toLowerCase()) + (typeof e.className === 'string' && e.className ? '.' + e.className.split(/\s+/)[0] : ''), ok: outline || shadow, outline: s.outline, border: s.borderColor, bg: s.backgroundColor };
    });
    if (r && !r.ok) bad.push({ rule: 'focus-not-visible', detail: r.name + ' outline=' + r.outline + ' border=' + r.border });
  }
  return bad;
};

(async () => {
  const browser = await chromium.launch({ executablePath: '/home/sysadmin/.cache/ms-playwright/chromium-1228/chrome-linux64/chrome', args: ['--no-sandbox'] });
  const login = async (email, pass, role) => {
    const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    const page = await ctx.newPage();
    await page.goto(BASE + '/login.php');
    await page.fill('input[name=email]', email); await page.fill('input[name=password]', pass);
    await Promise.all([page.waitForNavigation({ waitUntil: 'load' }).catch(() => {}), page.click('button[type=submit], input[type=submit]')]);
    const choice = await page.$('button[name=role_choice][value=' + role + ']'); if (choice) { await Promise.all([page.waitForNavigation({ waitUntil: 'load' }).catch(() => {}), choice.click()]); }
    return { ctx, page };
  };
  const targets = [
    ['admin', [['chat settings (Slack/Teams)', '/admin/settings_chat.php'], ['quality admin', '/admin/quality.php'], ['portal announcements', '/admin/portal_announcements.php']]],
    ['employee', [['portal home', '/client/index.php'], ['portal notifications', '/client/notifications.php'], ['portal devices', '/client/assets.php'], ['portal quality', '/client/quality.php']]],
  ];
  let total = 0;
  for (const [who, pages] of targets) {
    const creds = who === 'admin' ? [process.env.ADMIN_EMAIL, process.env.ADMIN_PASS, 'agent'] : [process.env.EMP_EMAIL, process.env.EMP_PASS, 'client'];
    const { ctx, page } = await login(...creds);
    for (const [name, url] of pages) {
      for (const [w, h] of [[1280, 900], [390, 844]]) {
        await page.setViewportSize({ width: w, height: h });
        const resp = await page.goto(BASE + url, { waitUntil: 'load' });
        const tag = `${THEME}/${w} ${name}`;
        if (page.url().includes('login.php')) { console.log(`NOTE ${tag}: redirected to login`); continue; }
        const findings = await page.evaluate(probe);
        if (w === 1280) findings.push(...(await focusProbe(page)));
        await page.screenshot({ path: `${OUT}/${THEME}_${w}_${name.replace(/[^a-z0-9]+/gi, '_')}.png`, fullPage: false });
        console.log(`${findings.length ? 'FIND' : 'OK  '} ${tag}: HTTP ${resp.status()}, ${findings.length} finding(s)`);
        for (const f of findings) { console.log(`     ${f.rule}: ${f.detail}`); total++; }
      }
    }
    await ctx.close();
  }
  await browser.close();
  console.log(`TOTAL findings: ${total}`);
  process.exit(total ? 1 : 0);
})();
