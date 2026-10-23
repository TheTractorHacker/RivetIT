"""Read-only page sweep. usage: sweep.py <role> <modules,comma> [label]  -> out/sweep_<label>.json
Loads each page as <role>, records HTTP/PHP/console/network/layout problems, clicks tabs, opens (never submits) modals,
statically checks anchors/links. Never submits a form or clicks a destructive-looking control."""
from common import *
from urllib.parse import urlparse, urljoin
role = sys.argv[1]; mods = sys.argv[2].split(','); label = sys.argv[3] if len(sys.argv) > 3 else role + '_' + '_'.join(mods)
only = os.environ.get('QA_ONLY')  # optional substring filter
targets = [t for t in json.load(open(os.environ.get('QA_TARGETS') or os.path.join(OUT, 'targets.json'))) if t['module'] in mods]
if only: targets = [t for t in targets if only in t['file']]
if os.environ.get('QA_TRAINING_ONLY'): targets = [t for t in targets if 'training' in t['file']]

LINKS_JS = """() => { const out = {anchors: [], hrefs: [], dead: [], hscroll: document.documentElement.scrollWidth - window.innerWidth,
  js: {jquery: !!window.jQuery, bootstrap: !!window.bootstrap}, bodyLen: (document.body && document.body.innerText || '').trim().length,
  title: document.title, h1: (document.querySelector('h1')||{}).innerText || '', sheets: document.styleSheets.length,
  styled: getComputedStyle(document.body).fontFamily + '|' + getComputedStyle(document.body).backgroundColor};
  document.querySelectorAll('a[href]').forEach(a => { const h = a.getAttribute('href');
    const tog = a.getAttribute('data-bs-toggle') || a.getAttribute('role') === 'button' || a.getAttribute('onclick') || a.getAttribute('data-bs-target');
    if (h === '#' || h === '' || /^javascript:/i.test(h)) { if (!tog && !a.closest('[data-bs-toggle]')) out.dead.push((a.innerText||a.title||a.getAttribute('aria-label')||'').trim().slice(0,40) || a.outerHTML.slice(0,80)); }
    else if (h[0] === '#') { if (!tog && h.length > 1 && !document.getElementById(h.slice(1)) && !document.querySelector('[name="'+h.slice(1)+'"]')) out.anchors.push(h); }
    else out.hrefs.push(a.href); });
  return out; }"""

def run():
    p = sync_playwright().start()
    b, ctx, pg = login(p, role)
    state = {'console': [], 'pageerr': [], 'resp': [], 'failed': []}
    pg.on('console', lambda m: state['console'].append(m.text[:300]) if m.type == 'error' else None)
    pg.on('pageerror', lambda e: state['pageerr'].append(str(e)[:300]))
    def on_resp(r):
        if r.status >= 400: state['resp'].append(f'{r.status} {r.request.method} {r.url.replace(BASE, "")[:140]}')
    pg.on('response', on_resp)
    pg.on('requestfailed', lambda r: state['failed'].append(f'{r.failure} {r.url.replace(BASE, "")[:140]}') if 'ERR_ABORTED' not in (r.failure or '') else None)
    results = []
    for i, t in enumerate(targets):
        rec = {'role': role, 'module': t['module'], 'file': t['file'], 'url': t['url'], 'skipped': t['skip']}
        if t['skip']: results.append(rec); continue
        if role.startswith('portal') and i > 0 and 'login' in pg.url:
            ctx.clear_cookies(); pg.goto(BASE + '/login.php'); e_, pw_ = USERS[role]
            pg.fill('input[name=email]', e_); pg.fill('input[name=password]', pw_); pg.locator('button[type=submit]').first.click()
            try: pg.wait_for_url(lambda u: 'login' not in u, timeout=15000)
            except Exception: pass
        for k in state: state[k].clear()
        urls = [t['url']] + [('/' + t['file'] + '?tab=' + tb) for tb in t['tabs']]
        rec['variants'] = []
        for u in urls:
            v = {'url': u}
            for k in state: state[k].clear()
            try:
                if t['sse']:
                    r = ctx.request.get(BASE + u, timeout=3500, max_redirects=0)
                    v['status'] = r.status; v['note'] = 'sse responded'; body = r.text()[:300]
                elif t['ajax']:
                    r = ctx.request.get(BASE + u, timeout=15000)
                    v['status'] = r.status; body = r.text()
                    v['ctype'] = r.headers.get('content-type', ''); v['bytes'] = len(body)
                    v['php_error'] = bool(PHP_ERR.search(body))
                else:
                    resp = pg.goto(BASE + u, timeout=25000, wait_until='domcontentloaded')
                    try: pg.wait_for_load_state('load', timeout=8000)
                    except Exception: pass
                    time.sleep(.5)
                    v['status'] = resp.status if resp else None; v['final'] = pg.url.replace(BASE, '')
                    html = pg.content()
                    m = PHP_ERR.search(html); v['php_error'] = html[max(0, m.start() - 40): m.end() + 160].replace('\n', ' ') if m else None
                    info = pg.evaluate(LINKS_JS)
                    v.update({k2: info[k2] for k2 in ('hscroll', 'js', 'bodyLen', 'title', 'h1', 'sheets', 'styled')})
                    v['dead_links'] = info['dead'][:8]; v['dead_anchors'] = info['anchors'][:8]
                    # static check of internal .php hrefs against the app tree
                    bad = set()
                    for h in set(info['hrefs']):
                        pu = urlparse(h)
                        if pu.netloc != urlparse(BASE).netloc or not pu.path.endswith('.php'): continue
                        if not os.path.isfile(APP + pu.path): bad.add(pu.path)
                    v['broken_hrefs'] = sorted(bad)[:8]
                    v['tabs'] = []; v['modals'] = []
                    # tabs
                    tabs = pg.locator('[data-bs-toggle="tab"]:visible, [data-bs-toggle="pill"]:visible')
                    for ti in range(min(tabs.count(), 14)):
                        el = tabs.nth(ti)
                        try:
                            name = (el.inner_text() or '').strip()[:30]
                            n0 = len(state['console']) + len(state['pageerr']) + len(state['resp'])
                            el.click(timeout=2500); time.sleep(.35)
                            tgt = el.get_attribute('href') or el.get_attribute('data-bs-target')
                            ok = pg.evaluate("(t) => { const e = t && document.querySelector(t); return e ? (e.classList.contains('active') || e.classList.contains('show')) : null }", tgt) if tgt and tgt.startswith('#') else None
                            v['tabs'].append({'tab': name, 'activated': ok, 'new_errors': len(state['console']) + len(state['pageerr']) + len(state['resp']) - n0})
                        except Exception as e:
                            v['tabs'].append({'tab': name if 'name' in dir() else '?', 'error': str(e)[:80]})
                    # modals (open + close only)
                    trig = pg.locator('[data-bs-toggle="modal"]:visible')
                    seen = set(); opened = 0
                    for mi in range(min(trig.count(), 40)):
                        if opened >= 5: break
                        el = trig.nth(mi)
                        try:
                            label_ = ((el.inner_text() or '') + ' ' + (el.get_attribute('title') or '') + ' ' + (el.get_attribute('aria-label') or '')).strip()
                            key = (el.get_attribute('data-modal-url') or '') + label_[:30]
                            if key in seen or DANGER.search(label_) or DANGER.search(el.get_attribute('data-modal-url') or ''): continue
                            seen.add(key); opened += 1
                            n0 = len(state['console']) + len(state['pageerr']) + len(state['resp'])
                            el.click(timeout=2500)
                            try: pg.wait_for_selector('.modal.show', timeout=5000); shown = True
                            except Exception: shown = False
                            time.sleep(.5)
                            txt = pg.evaluate("() => { const m = document.querySelector('.modal.show'); return m ? m.innerText.trim().slice(0, 4000) : '' }")
                            hm = PHP_ERR.search(pg.evaluate("() => { const m = document.querySelector('.modal.show'); return m ? m.innerHTML : '' }") or '')
                            off = pg.evaluate("() => { const m = document.querySelector('.modal.show .modal-dialog'); if (!m) return null; const r = m.getBoundingClientRect(); return {top: r.top, bottom: r.bottom - innerHeight, right: r.right - innerWidth} }")
                            v['modals'].append({'trigger': label_[:40], 'shown': shown, 'body_len': len(txt), 'php_error': bool(hm), 'offscreen': off if off and (off['top'] < -2 or off['right'] > 2) else None,
                                                'new_errors': len(state['console']) + len(state['pageerr']) + len(state['resp']) - n0})
                            pg.keyboard.press('Escape'); time.sleep(.3)
                            if pg.locator('.modal.show').count():
                                pg.evaluate("() => document.querySelectorAll('.modal.show').forEach(m => bootstrap.Modal.getInstance(m)?.hide())"); time.sleep(.3)
                        except Exception as e:
                            v['modals'].append({'trigger': '?', 'error': str(e)[:80]})
                    if ((v.get('php_error') or v['bodyLen'] < 40 or not v['js']['bootstrap'] or v['hscroll'] > 2 or v['status'] >= 400 or state['pageerr']) and v['status'] != 302):
                        sh = f"{role}_{re.sub('[^a-z0-9]+', '_', u.lower())}.png"[:120]
                        try: pg.screenshot(path=os.path.join(SHOTS, sh)); v['shot'] = sh
                        except Exception: pass
            except Exception as e:
                v['exception'] = str(e)[:200]
            v['console'] = list(dict.fromkeys(state['console']))[:6]; v['pageerrors'] = list(dict.fromkeys(state['pageerr']))[:4]
            v['bad_requests'] = list(dict.fromkeys(state['resp']))[:8]; v['failed_requests'] = list(dict.fromkeys(state['failed']))[:5]
            rec['variants'].append(v)
        results.append(rec)
        if i % 20 == 0: print(role, i, '/', len(targets), t['file'], flush=True); json.dump(results, open(os.path.join(OUT, f'sweep_{label}.json'), 'w'), indent=1)
    json.dump(results, open(os.path.join(OUT, f'sweep_{label}.json'), 'w'), indent=1)
    b.close(); p.stop()
run()
print('done', role, mods)
