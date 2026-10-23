"""Compact multi-page sheets for tablet/1366 review: S2c = 768 light x3 pages, S2d = 1366 light x2 pages (names in sheets/<role>/S2x_map.json)."""
import json, os, subprocess, sys
from concurrent.futures import ThreadPoolExecutor
R = '/home/sysadmin/qa-design-scratch'; S = R + '/sheets'
def probes(role, theme, vp): return json.load(open(f'{R}/shots/{role}/{theme}/{vp}/probes.json'))
jobs = []; maps = {}
for role in ('admin', 'tech', 'portal'):
    names = sorted(os.listdir(f'{S}/{role}/S2a')); 
    base = {v['shot'][:-4]: u for u, v in probes(role, 'light', '1920x1080').items() if v.get('shot')}
    P = {vp: probes(role, 'light', vp) for vp in ('768x1024', '1366x768')}
    byshot = {vp: {v['shot'][:-4]: v for v in P[vp].values() if v.get('shot')} for vp in P}
    for vp, per, crop, tag in (('1366x768', 3, 1000, 'S2d'),):
        slugs = [n[:-4] for n in names]
        for i in range(0, len(slugs), per):
            grp = slugs[i:i + per]; out = f'{S}/{role}/{tag}/{tag}_{i // per:03d}.jpg'; os.makedirs(os.path.dirname(out), exist_ok=True)
            args = ['convert']
            for s in grp:
                f = f'{R}/shots/{role}/light/{vp}/{s}.jpg'; h = byshot[vp].get(s, {}).get('probes', {}).get('meta', {}).get('h', crop)
                args += ['(', f, '-crop', f"{int(vp.split('x')[0])}x{min(crop, h)}+0+0", '+repage', '-background', '#888', '-gravity', 'north', '-extent', f"{vp.split('x')[0]}x{crop}", ')']
            args += ['+append', '-resize', '1560x', '-quality', '78', out]
            jobs.append(args); maps[f'{role}/{out.split("/")[-1]}'] = grp
json.dump(maps, open(f'{S}/S2_map.json', 'w'))
with ThreadPoolExecutor(12) as ex: list(ex.map(lambda a: subprocess.run(a, capture_output=True), jobs))
print(len(jobs), 'sheets')
