// Shared helpers for capturing the user-guide screenshots.
//
//   const { launch, login, shot, callout, clearCallouts, BASE } = require('./lib.cjs');
//
// Run a capture script with Playwright resolvable, e.g.
//   NODE_PATH=$(npm root -g) DEMO_URL=http://127.0.0.1:8080 node capture/tickets.cjs
//
// Screenshots are written under docs/user-guide/images/<group>/<name>.png, where
// <group> and <name> are the first argument you pass to shot(): shot(page, 'tickets/01-list').
//
// Clean mode: with GUIDE_CLEAN=1 (or run-all.cjs --clean) the same scripts write the same files under
// docs/user-guide/images-clean/ instead, and every call-out (numbered red badge and frame) is skipped, so
// the pictures are the plain screenshots - for reuse on a web site. Nothing else about a script changes.

const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const BASE = (process.env.DEMO_URL || 'http://127.0.0.1:8080').replace(/\/$/, '');
const CLEAN = /^(1|true|yes)$/i.test(process.env.GUIDE_CLEAN || '');
const OUT = path.resolve(__dirname, '..', CLEAN ? 'images-clean' : 'images');

// Every demo login uses the same password; Alex Morgan is the administrator.
const DEMO_USERS = {
  admin: { email: 'alex.morgan@summitridge.example', password: 'DemoAdmin#2026' },
  tech: { email: 'priya.nair@summitridge.example', password: 'DemoPass#2026' },
};

async function launch({ width = 1440, height = 900 } = {}) {
  const browser = await chromium.launch({ args: ['--no-sandbox'] });
  const context = await browser.newContext({
    viewport: { width, height },
    deviceScaleFactor: 1,
    colorScheme: 'light',
  });
  const page = await context.newPage();
  page.setDefaultTimeout(20000);
  return { browser, context, page };
}

async function login(page, who = 'admin') {
  const creds = typeof who === 'string' ? DEMO_USERS[who] : who;
  await page.goto(`${BASE}/login.php`, { waitUntil: 'domcontentloaded' });
  await page.fill('input[name=email]', creds.email);
  await page.fill('input[name=password]', creds.password);
  await Promise.all([
    page.waitForLoadState('domcontentloaded'),
    page.click('button[name=login]'),
  ]);
  // A login that is both an agent and a portal contact is asked which side to open.
  const choice = page.locator('button[name=role_choice][value=agent]');
  if (await choice.count()) {
    await Promise.all([page.waitForLoadState('domcontentloaded'), choice.click()]);
  }
  await page.waitForLoadState('networkidle', { timeout: 6000 }).catch(() => {});
}

async function settle(page, extraMs = 400) {
  // Pages that poll or hold an SSE stream never go fully idle - don't stall on them.
  await page.waitForLoadState('networkidle', { timeout: 6000 }).catch(() => {});
  await page.waitForTimeout(extraMs);
}

// Numbered red call-outs drawn over the live page so a screenshot can say
// "click the button marked 2". items: [{ selector, n, side }] where side is
// 'tl' (default), 'tr', 'bl' or 'br' - the corner of the element the badge sits on.
async function callout(page, items) {
  if (CLEAN) return;
  await page.evaluate((items) => {
    document.querySelectorAll('.ug-callout').forEach((n) => n.remove());
    items.forEach(({ selector, n, side }) => {
      const el = document.querySelector(selector);
      if (!el) return;
      const r = el.getBoundingClientRect();
      const sx = window.scrollX, sy = window.scrollY;
      const box = document.createElement('div');
      box.className = 'ug-callout';
      box.style.cssText = `position:absolute;z-index:2147483646;pointer-events:none;border:3px solid #e03131;border-radius:6px;left:${r.left + sx - 3}px;top:${r.top + sy - 3}px;width:${r.width + 6}px;height:${r.height + 6}px;box-shadow:0 0 0 2px rgba(255,255,255,.7)`;
      const badge = document.createElement('div');
      badge.className = 'ug-callout';
      const bx = (side || 'tl').includes('r') ? r.right + sx - 4 : r.left + sx - 18;
      const by = (side || 'tl').includes('b') ? r.bottom + sy - 4 : r.top + sy - 18;
      badge.textContent = String(n);
      badge.style.cssText = `position:absolute;z-index:2147483647;pointer-events:none;left:${bx}px;top:${by}px;width:26px;height:26px;line-height:26px;text-align:center;border-radius:50%;background:#e03131;color:#fff;font:700 14px/26px system-ui,sans-serif;box-shadow:0 1px 4px rgba(0,0,0,.4)`;
      document.body.appendChild(box);
      document.body.appendChild(badge);
    });
  }, items);
}

async function clearCallouts(page) {
  await page.evaluate(() => document.querySelectorAll('.ug-callout').forEach((n) => n.remove()));
}

// shot(page, 'group/NN-name', { fullPage, selector, delay })
//   selector: capture just that element (a modal, a card...) instead of the viewport.
//   keepHover: leave the pointer where it is (only when the shot is about a hover state).
async function shot(page, rel, { fullPage = false, selector = null, delay = 400, keepHover = false } = {}) {
  const file = path.join(OUT, rel.endsWith('.png') ? rel : `${rel}.png`);
  fs.mkdirSync(path.dirname(file), { recursive: true });
  // Park the pointer in a corner so no table row / button is left in its hover state.
  if (!keepHover) await page.mouse.move(2, 2);
  await settle(page, delay);
  if (selector) {
    await page.locator(selector).first().screenshot({ path: file });
  } else if (fullPage) {
    // A stitched full-page capture lays a fixed sidebar over the content. Resizing the window to the
    // page's own height lets the app lay itself out as it would for a tall monitor instead.
    const vp = page.viewportSize();
    const docHeight = await page.evaluate(() => Math.max(document.documentElement.scrollHeight, document.body ? document.body.scrollHeight : 0));
    const target = Math.min(Math.max(docHeight, vp.height), 4200);
    if (target > vp.height) {
      await page.setViewportSize({ width: vp.width, height: target });
      await settle(page, 350);
    }
    await page.screenshot({ path: file });
    if (target > vp.height) {
      await page.setViewportSize(vp);
      await settle(page, 150);
    }
  } else {
    await page.screenshot({ path: file });
  }
  console.log('saved', path.relative(path.resolve(__dirname, '..', '..', '..'), file));
  return file;
}

async function goto(page, url) {
  await page.goto(url.startsWith('http') ? url : `${BASE}${url}`, { waitUntil: 'domcontentloaded' });
  await settle(page);
}

module.exports = { launch, login, shot, goto, settle, callout, clearCallouts, BASE, OUT, CLEAN, DEMO_USERS };
