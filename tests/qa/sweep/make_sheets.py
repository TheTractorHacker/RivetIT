"""Contact sheets for viewing: S1 = 1920 light|dark (first 2600px; S1b next 2600 if longer), S2a = 1366 L|D, S2b = 768 L|D. Skips 403/identical shells (listed as 'same as')."""
import json, os, subprocess, sys, re, hashlib
from concurrent.futures import ThreadPoolExecutor
R = '/home/sysadmin/qa-design-scratch'; S = R + '/sheets'
def probes(role, theme, vp): return json.load(open(f'{R}/shots/{role}/{theme}/{vp}/probes.json'))
def img(role, theme, vp, f): return f'{R}/shots/{role}/{theme}/{vp}/{f}'
def height(path):
    r = subprocess.run(['identify', '-format', '%h', path], capture_output=True, text=True); return int(r.stdout or 0)
def sheet(job):
    out, parts = job
    os.makedirs(os.path.dirname(out), exist_ok=True)
    cmds = []
    for p in parts:
        if not os.path.exists(p['f']): return
    args = ['convert']
    for p in parts:
        args += ['(', p['f'], '-crop', f"{p['w']}x{p['ch']}+0+{p['y']}", '+repage', '-resize', f"{p['rw']}x", '-background', '#888', '-gravity', 'north', '-extent', f"{p['rw']}x{p['rh']}", ')']
    args += ['+append', '-quality', '80', out]
    subprocess.run(args, capture_output=True)
jobs = []; manifest = []; dedupe = {}
for role in ('admin', 'tech', 'portal'):
    P = {(t, v): probes(role, t, v) for t in ('light', 'dark') for v in ('1920x1080', '1366x768', '768x1024')}
    base = P[('light', '1920x1080')]
    for u, rec in base.items():
        if rec.get('error') or not rec.get('probes'): manifest.append((role, u, 'error/none')); continue
        m = rec['probes']['meta']; key = (role, m['text'][:90]) if (rec['status'] == 403 or m['text'].startswith("Skip to main content Summit Ridge Manufacturing Dashboard") and 'access' in m['text'].lower()) else None
        if rec['status'] in (403, 404) and key:
            if key in dedupe: manifest.append((role, u, 'same-as ' + dedupe[key])); continue
            dedupe[key] = u
        slug = rec['shot'][:-4]; h = max(height(img(role, 'light', '1920x1080', rec['shot'])), 1)
        W = 1920; rw = 780
        def part(theme, vp, y, ch, w, rwid):
            f = rec['shot'] if vp == '1920x1080' else P[(theme, vp)].get(u, {}).get('shot', '')
            ph = P[(theme, vp)].get(u, {}).get('probes', {}).get('meta', {}).get('h', ch)
            ch2 = max(200, min(ch, ph - y)); chm = max(200, min(ch, max(P[('light', vp)].get(u, {}).get('probes', {}).get('meta', {}).get('h', ch), P[('dark', vp)].get(u, {}).get('probes', {}).get('meta', {}).get('h', ch)) - y))
            return {'f': img(role, theme, vp, f), 'w': w, 'ch': chm, 'y': y, 'rw': rwid, 'rh': int(chm * rwid / w)}
        jobs.append((f'{S}/{role}/S1/{slug}.jpg', [part('light', '1920x1080', 0, 2600, 1920, 780), part('dark', '1920x1080', 0, 2600, 1920, 780)]))
        if h > 2600: jobs.append((f'{S}/{role}/S1b/{slug}.jpg', [part('light', '1920x1080', 2600, 2600, 1920, 780), part('dark', '1920x1080', 2600, 2600, 1920, 780)]))
        if m['table'] or m['form'] or m['modal'] or m['cards'] >= 3:
            jobs.append((f'{S}/{role}/S2a/{slug}.jpg', [part('light', '1366x768', 0, 1700, 1366, 780), part('dark', '1366x768', 0, 1700, 1366, 780)]))
            jobs.append((f'{S}/{role}/S2b/{slug}.jpg', [part('light', '768x1024', 0, 1300, 768, 768), part('dark', '768x1024', 0, 1300, 768, 768)]))
        manifest.append((role, u, 'sheet'))
print(len(jobs), 'sheets;', sum(1 for m in manifest if m[2] == 'sheet'), 'pages;', sum(1 for m in manifest if m[2].startswith('same')), 'dedup;', sum(1 for m in manifest if m[2].startswith('err')), 'err')
with ThreadPoolExecutor(12) as ex: list(ex.map(sheet, jobs))
json.dump(manifest, open(R + '/sheet_manifest.json', 'w'))
