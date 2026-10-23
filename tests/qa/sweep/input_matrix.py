"""Real-keystroke input matrix over every safe create/edit form and modal (scratch demo only).
usage: input_matrix.py <shard i/n> [modal|page|both] [--limit N]
For each form: fill every field with valid values, then type each payload class into up to 3 text-like fields with the keyboard,
submit, and classify the outcome. Asserts (exit != 0 on failure, via input_matrix_report.py): no 5xx / PHP text / JS error, no executed
script, no double-escaped output, blank/whitespace-only required names rejected, 5000-char input never causes an error page."""
from common import *
import random
shard, nshard = map(int, sys.argv[1].split('/')); mode = sys.argv[2] if len(sys.argv) > 2 else 'both'
LIMIT = int(sys.argv[sys.argv.index('--limit') + 1]) if '--limit' in sys.argv else 10 ** 9
INV = json.load(open(os.path.join(HERE, 'out', 'forms_admin.json')))
DANGER_PATH = re.compile(r'delete|archive|restore|remove|purge|bulk_|reset|revoke|send|test|sync|import|install|update|upgrade|backup|check|void|cancel|close|disable|impersonat|logout|password|mfa|passkey|kiosk|device|odoo|mail|smtp|oauth|webhook|mesh|comet|unifi|mdm|cron|identity|api_key|user_|role|module|payroll_run|rmm|endpoint|download|export|merge|assign_training|award|publish|run|execute', re.I)
DANGER_PAGE = re.compile(r'security|mail|integration|odoo|webhook|backup|update|cron|comet|unifi|mdm|mesh|calendar_sync|identity|oauth|ai_provider|users|roles|api_key|modules|rmm|endpoint|redis|mcp|telemetry|kiosk|database|login|logout|payroll|training', re.I)
TEXTY = {'text', 'textarea', 'email', 'tel', 'url', 'search', 'number'}
LONG300 = 'x' * 300; LONG5000 = 'Lorem ipsum dolor sit amet ' * 186
CLASSES = [
 ('empty', ''), ('whitespace', '   \t  '), ('one_char', 'a'), ('len_300', LONG300), ('len_5000', LONG5000),
 ('unicode_emoji_rtl', 'Zoë Ünïcode 日本語 مرحبا שלום 😀🚀 café'), ('quotes', '''O'Brien "quoted" `tick` \\ backslash'''),
 ('html_script', '<script>window.__xss=1</script><img src=x onerror="window.__xss=1"><b>bold</b>'), ('amp_entities', 'A&B &amp; &lt;i&gt; <i>x</i>'),
 ('sql', "x'; DROP TABLE users;-- ' OR '1'='1 \" OR 1=1"), ('lead_trail_spaces', '   padded value   '), ('newline', 'line1\nline2')]
NUMCLASSES = [('neg', '-5'), ('zero', '0'), ('huge', '99999999999999999999'), ('decimal', '3.14159'), ('text_in_number', 'abc'), ('exp', '1e309')]
EMAILCLASSES = [('bad_email', 'not-an-email'), ('email_unicode', 'zoë@exämple.com')]
URLCLASSES = [('bad_url', 'javascript:alert(1)')]
def baseline(f, idx):
    t = f['type']; n = f['name'] or ''
    if t == 'email': return 'qa.ui+%d@example.com' % idx
    if t == 'tel': return '6085550100'
    if t == 'url': return 'https://example.com/qa'
    if t == 'number':
        lo = float(f['min']) if f['min'] not in (None, '') else None; hi = float(f['max']) if f['max'] not in (None, '') else None
        v = 1 if lo is None else max(lo, 1); v = min(v, hi) if hi is not None else v; return str(int(v) if float(v).is_integer() else v)
    if t == 'date': return '2026-12-01'
    if t == 'time': return '10:00'
    if t == 'datetime-local': return '2026-12-01T10:00'
    if t == 'color': return '#336699'
    if t == 'password': return 'QaUi#Pass12345'
    if t in ('text', 'textarea', 'search'):
        if 'ip' in n.lower().split('_') or n.lower() in ('ip', 'ipv4', 'ip_address'): return '10.99.0.5'
        if 'mac' in n.lower(): return '00:11:22:33:44:55'
        if 'phone' in n or 'mobile' in n or 'fax' in n: return '6085550100'
        if 'url' in n or 'website' in n or 'uri' in n: return 'https://example.com/qa'
        if 'email' in n: return 'qa.ui+%d@example.com' % idx
        if 'date' in n and 'update' not in n: return '2026-12-01'
        return 'QA-UI-%d' % idx
    return None
JS_FILL = """([sel, val]) => { const e = document.querySelector(sel); if (!e) return false; if (e.tomselect) { e.tomselect.setValue(val); } else { e.value = val; e.dispatchEvent(new Event('input', {bubbles: true})); e.dispatchEvent(new Event('change', {bubbles: true})); } return true; }"""
PHPERR = PHP_ERR
def run():
    WHO = os.environ.get('QA_WHO', 'admin')
    p = sync_playwright().start(); b, ctx, pg = login(p, WHO)
    xss = {'v': False}; evs = {'resp': [], 'js': [], 'texts': []}
    pg.on('pageerror', lambda e: evs['js'].append(str(e)[:150]))
    def on_resp(r):
        try:
            if r.status >= 500: evs['resp'].append(f'{r.status} {r.request.method} {r.url.replace(BASE, "")[:90]}')
            elif r.request.method == 'POST' and r.status < 400 and 'text' in (r.headers.get('content-type', '') + ' '):
                body = r.text()[:4000]
                if PHPERR.search(body): evs['resp'].append('PHP-in-body ' + r.url.replace(BASE, '')[:80])
        except Exception: pass
    pg.on('response', on_resp)
    pg.on('dialog', lambda d: d.accept())
    def nonlocal_relogin():
        nonlocal b, ctx, pg
        try: b.close()
        except Exception: pass
        b, ctx, pg = login(p, WHO)
        pg.on('pageerror', lambda e: evs['js'].append(str(e)[:150])); pg.on('response', on_resp); pg.on('dialog', lambda d: d.accept())
    targets = []
    if mode in ('modal', 'both'):
        for m in INV['modals']:
            for k, f in enumerate(m.get('forms', [])):
                if DANGER_PATH.search(m['path']) or f['method'] != 'post' or not f['submit']: continue
                targets.append({'kind': 'modal', 'page': m['page'], 'modal': m['modal'], 'path': m['path'], 'form': f, 'fi': k})
    if mode in ('page', 'both'):
        for f in INV['pageforms']:
            if DANGER_PAGE.search(f['page']) or f['method'] != 'post' or not f['submit']: continue
            targets.append({'kind': 'page', 'page': f['page'], 'path': f['page'], 'form': f, 'fi': f.get('idx', 0)})
    # dedupe by (kind, path)
    seen = set(); uniq = []
    for t in targets:
        k = (t['kind'], t['path'], t['fi'])
        if k in seen: continue
        seen.add(k); uniq.append(t)
    uniq = [t for i, t in enumerate(uniq) if i % nshard == shard - 1][:LIMIT]
    if os.environ.get('QA_FAST'): uniq = [t for t in uniq if not re.search(r'asset_copy|ticket_add_v2|ticket_add\.php|ticket_saved_view_edit|service_catalog_item_edit|holiday_edit|asset_edit|contact_details|asset_details', t['path'])]
    S = {'b': b, 'ctx': ctx, 'pg': pg}
    results = []
    idx = shard * 100000
    for t in uniq:
        f = t['form']; fields = [x for x in f['fields'] if x['name'] and x['visible'] and not x['disabled'] and not x['readOnly'] and x['type'] not in ('hidden', 'file', 'checkbox', 'radio', 'submit', 'button', 'image', 'reset') or (x['type'] == 'select' and x['name'] and not x['disabled'] and x['visible'])]
        if any(x['required'] and x['type'] == 'file' for x in f['fields']): continue
        fuzz = [x for x in fields if x['type'] in TEXTY][:3]
        if not fuzz: continue
        rec = {'kind': t['kind'], 'path': t['path'], 'page': t['page'], 'fields': [x['name'] for x in fuzz], 'tests': []}
        def open_form():
            pg.goto(BASE + t['page'], timeout=30000, wait_until='domcontentloaded'); time.sleep(.5)
            if 'login' in pg.url:
                nonlocal_relogin()
                pg.goto(BASE + t['page'], timeout=30000, wait_until='domcontentloaded'); time.sleep(.5)
            if t['kind'] == 'modal':
                ok = pg.evaluate("(u) => { const e = [...document.querySelectorAll('.ajax-modal[data-modal-url]')].find(x => x.getAttribute('data-modal-url') === u); if (!e) return false; e.click(); return true }", t['modal'])
                if not ok: raise RuntimeError('trigger not found')
                pg.wait_for_selector('.modal.show form', timeout=8000); time.sleep(.5)
                return '.modal.show form'
            return f"form:nth-of-type({t['fi'] + 1})"
        def fill_all():
            for x in fields:
                if x['type'] == 'select':
                    opts = x.get('options') or []; val = next((o.split('=')[0] for o in opts if o.split('=')[0] not in ('', '0')), None)
                    if val is not None: pg.evaluate(JS_FILL, [f'[name="{x["name"]}"]', val])
                else:
                    v = baseline(x, idx)
                    if v is not None: pg.evaluate(JS_FILL, [f'[name="{x["name"]}"]', v])
        def classes_for(x):
            c = list(CLASSES)
            if x['type'] == 'number': c = [('empty', ''), ('whitespace', '  ')] + NUMCLASSES
            elif x['type'] == 'email': c += EMAILCLASSES
            elif x['type'] == 'url' or 'url' in (x['name'] or ''): c += URLCLASSES
            elif x['type'] in ('tel',): c = [('empty', ''), ('one_char', 'a'), ('len_300', LONG300), ('letters', 'call me'), ('html_script', CLASSES[7][1])]
            return c
        for fx, x in enumerate(fuzz):
            cl = classes_for(x)
            if fx == 0 and len(cl) > 10: cl = [c for c in cl if c[0] != 'newline']
            if fx > 0: cl = [c for c in cl if c[0] in ('empty', 'whitespace', 'html_script', 'neg', 'huge', 'bad_email')]
            for cname, payload in cl:
                idx += 1; tag = 'QA-UI-%d' % idx
                if x['maxlength'] and len(payload) > x['maxlength'] * 3: payload = payload[:x['maxlength'] * 3]
                evs['resp'].clear(); evs['js'].clear(); T0 = time.time()
                res = {'field': x['name'], 'type': x['type'], 'class': cname, 'required': x['required'], 'maxlength': x['maxlength']}
                try:
                    form_sel = open_form(); fill_all()
                    sel = f'{form_sel} [name="{x["name"]}"]'
                    loc = pg.locator(sel).first
                    loc.click(timeout=3000); pg.keyboard.press('Control+A'); pg.keyboard.press('Delete')
                    if payload:
                        typed = payload if x['type'] not in ('textarea',) else payload
                        if '\n' in typed and x['type'] != 'textarea': typed = typed.replace('\n', ' ')
                        pg.keyboard.type(typed if len(typed) < 400 else typed[:400], delay=0)
                        if len(typed) >= 400: pg.evaluate(JS_FILL, [f'[name="{x["name"]}"]', typed])   # long payloads set in one go after real typing of the head
                    typed_val = pg.evaluate("(s) => document.querySelector(s).value", sel)
                    res['typed_len'] = len(typed_val)
                    # submit with a real click on the visible primary submit button
                    btn = pg.locator(f'{form_sel} button[type=submit]:visible, {form_sel} input[type=submit]:visible').first
                    if t['kind'] == 'modal' and not btn.count(): btn = pg.locator('.modal.show button[type=submit]:visible').first
                    inv = pg.evaluate("(s) => { const f = document.querySelector(s); return f ? [...f.querySelectorAll(':invalid')].map(e => ({n: e.name, vis: !!(e.offsetWidth || e.offsetHeight), pane: (e.closest('.tab-pane') && !e.closest('.tab-pane').classList.contains('active')) ? 1 : 0})) : [] }", form_sel)
                    url0 = pg.url
                    btn.click(timeout=3000)
                    time.sleep(.8)
                    try: pg.wait_for_load_state('load', timeout=6000)
                    except Exception: pass
                    html = pg.content()
                    res['client_invalid'] = [i['n'] for i in inv]; res['hidden_tab_invalid'] = any(i['pane'] for i in inv)
                    still_open = pg.evaluate("() => !!document.querySelector('.modal.show form')") if t['kind'] == 'modal' else (pg.url == url0 and bool(inv))
                    res['still_open'] = still_open
                    res['php_text'] = bool(PHPERR.search(pg.evaluate("() => document.body.innerText") or ''))
                    res['xss'] = bool(pg.evaluate("() => !!window.__xss"))
                    res['toast'] = pg.evaluate("() => [...document.querySelectorAll('.toast, #toast-container .toast, .alert-danger, .alert-success')].map(e => e.innerText.trim().slice(0, 100)).join(' | ')")[:160]
                    res['server_errors'] = list(evs['resp']); res['js_errors'] = list(evs['js'])
                    res['accepted'] = (not still_open) and not inv and 'error' not in (res['toast'] or '').lower() and 'cannot' not in (res['toast'] or '').lower() and 'invalid' not in (res['toast'] or '').lower() and 'required' not in (res['toast'] or '').lower()
                    if res['accepted'] and cname in ('html_script', 'amp_entities', 'quotes', 'unicode_emoji_rtl', 'lead_trail_spaces') and 'name' in (x['name'] or ''):
                        try:
                            pg.goto(BASE + t['page'].split('?')[0] + '?q=' + tag, timeout=20000, wait_until='domcontentloaded'); time.sleep(.5)
                        except Exception: pass
                    res['xss2'] = bool(pg.evaluate("() => !!window.__xss"))
                    body_txt = pg.evaluate("() => document.body.innerText") or ''
                    res['double_escaped'] = bool(re.search(r'&quot;|&#0?39;|&lt;b&gt;', body_txt)) and cname in ('quotes', 'html_script', 'unicode_emoji_rtl')
                    res['script_tag_in_dom'] = bool(pg.evaluate("() => !!document.querySelector('main img[onerror], main [onerror]')"))
                except Exception as ex:
                    res['exception'] = str(ex)[:140]
                rec['tests'].append(res)
                if os.environ.get('QA_VERBOSE'): print(t['path'], x['name'], cname, round(time.time() - T0, 1), {k: res.get(k) for k in ('accepted', 'still_open', 'exception')}, flush=True)
        results.append(rec)
        if len(results) % 10 == 0: print(shard, len(results), len(uniq), flush=True); json.dump(results, open(os.path.join(OUT, f'input_{shard}of{nshard}.json'), 'w'))
    json.dump(results, open(os.path.join(OUT, f'input_{shard}of{nshard}.json'), 'w'))
    print('done', shard, 'forms', len(results), 'tests', sum(len(r['tests']) for r in results)); b.close(); p.stop()
run()
