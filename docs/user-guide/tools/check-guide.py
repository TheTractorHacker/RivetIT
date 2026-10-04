#!/usr/bin/env python3
"""Lint the user guide: broken links/anchors/images, unreferenced images, filler words, stray real-looking data.

    python3 docs/user-guide/tools/check-guide.py        # exit 1 if anything is wrong
"""
import glob, os, re, sys

G = os.path.abspath(os.path.join(os.path.dirname(__file__), '..'))
REPO = os.path.abspath(os.path.join(G, '..', '..'))
problems = []

def slug(h):
    h = re.sub(r'`', '', h.strip().lower())
    h = re.sub(r'[^\w\- ]', '', h)
    return h.replace(' ', '-')

def anchors(path):
    out, seen = set(), {}
    in_code = False
    for line in open(path, encoding='utf-8'):
        if line.startswith('```'): in_code = not in_code
        if in_code: continue
        m = re.match(r'^#{1,6}\s+(.*)', line)
        if m:
            s = slug(re.sub(r'\[([^\]]*)\]\([^)]*\)', r'\1', m.group(1)))
            n = seen.get(s, 0); seen[s] = n + 1
            out.add(s if n == 0 else f'{s}-{n}')
    return out

pages = sorted(glob.glob(os.path.join(G, '*.md')))
anc = {p: anchors(p) for p in pages}
referenced = set()
LINK = re.compile(r'!?\[[^\]]*\]\(([^)\s]+)\)')
for p in pages:
    name = os.path.basename(p)
    text = open(p, encoding='utf-8').read()
    body = re.sub(r'```.*?```', '', text, flags=re.S)
    for t in LINK.findall(body):
        if re.match(r'^(https?:|mailto:)', t): continue
        target, _, frag = t.partition('#')
        dest = os.path.normpath(os.path.join(os.path.dirname(p), target)) if target else p
        if target.startswith('images/'): referenced.add(dest)
        if not os.path.exists(dest):
            problems.append(f'{name}: broken link/image -> {t}'); continue
        if frag and dest.endswith('.md') and frag not in anc.get(dest, anchors(dest)):
            problems.append(f'{name}: missing anchor -> {t}')
    for m in re.finditer(r'\b(simply|just|easily|easy|obviously|of course)\b', body, re.I):
        line = body[:m.start()].count('\n') + 1
        ctx = body[max(0, m.start()-25):m.end()+25].replace('\n', ' ')
        problems.append(f'{name}: filler word "{m.group(0)}" near: ...{ctx}...')
    if re.search(r'[\U0001F300-\U0001FAFF☀-➿]', body):
        problems.append(f'{name}: contains an emoji')
    for m in re.finditer(r'[\w.+-]+@([\w-]+\.)+[a-z]{2,}', body):
        if not m.group(0).lower().endswith(('.example', '.test')) and 'example.com' not in m.group(0) and 'noreply' not in m.group(0):
            problems.append(f'{name}: email that is not .example -> {m.group(0)}')
    for m in re.finditer(r'\b(?:\d{1,3}\.){3}\d{1,3}\b', body):
        ip = m.group(0)
        if not (ip.startswith(('10.', '192.168.', '192.0.2.', '127.', '0.', '255.', '203.0.113.', '198.51.100.', '1.', '8.8.')) ):
            problems.append(f'{name}: IP address outside private/doc ranges -> {ip}')
for img in glob.glob(os.path.join(G, 'images', '*', '*.png')):
    if img not in referenced:
        problems.append(f'unreferenced image: {os.path.relpath(img, G)}')
print('\n'.join(problems) if problems else 'guide check: clean')
sys.exit(1 if problems else 0)
