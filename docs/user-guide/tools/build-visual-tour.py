#!/usr/bin/env python3
"""Regenerate docs/user-guide/visual-tour.md from the guide pages.

The tour is a gallery: for every page it shows a few representative screenshots (the first one, which is
always the page's "quick tour" picture, plus others spread evenly through the page) with the page's own
caption, and links back to the page. Nothing is written by hand, so it never drifts from the guide.

    python3 docs/user-guide/tools/build-visual-tour.py [--per-page N]
"""
import argparse
import glob
import os
import re

GUIDE = os.path.abspath(os.path.join(os.path.dirname(__file__), '..'))
IMG = re.compile(r'^!\[(?P<alt>[^\]]*)\]\((?P<src>images/[^)]+)\)\s*$')
CAPTION = re.compile(r'^\*Figure\s+\d+\s+[—-]\s+(?P<text>.+?)\*\s*$')

SECTIONS = [
    ('Set up and get around', ['00', '01']),
    ('Daily work', ['02', '02b', '03', '03b', '03c', '04']),
    ('Documentation', ['05', '05b', '06', '06b', '06c']),
    ('Training', ['07', '07b', '08', '08b']),
    ('Connections and insight', ['09', '10', '10b', '11']),
    ('Administration', ['12', '13', '13b', '13c']),
]


def first_sentence(text):
    text = re.sub(r'\s+', ' ', text).strip()
    m = re.match(r'(.+?[.!?])(\s|$)', text)
    return (m.group(1) if m else text).strip()


def read_page(path):
    title, shots = None, []
    lines = open(path, encoding='utf-8').read().split('\n')
    for i, line in enumerate(lines):
        if title is None and line.startswith('# '):
            title = line[2:].strip()
        m = IMG.match(line.strip())
        if not m:
            continue
        caption = ''
        for nxt in lines[i + 1:i + 4]:
            c = CAPTION.match(nxt.strip())
            if c:
                caption = first_sentence(c.group('text'))
                break
        shots.append((m.group('src'), m.group('alt').strip(), caption or m.group('alt').strip()))
    return title, shots


def pick(shots, n):
    if len(shots) <= n:
        return shots
    idx = sorted({round(k * (len(shots) - 1) / (n - 1)) for k in range(n)})
    return [shots[i] for i in idx]


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--per-page', type=int, default=3)
    args = ap.parse_args()

    pages = {}
    for path in sorted(glob.glob(os.path.join(GUIDE, '[0-9][0-9]*.md'))):
        name = os.path.basename(path)
        key = re.match(r'^(\d+b?c?)-', name).group(1)
        pages[key] = (name,) + read_page(path)

    out = [
        '# Visual tour',
        '',
        'A picture-first walk through RivetIT, module by module. Each picture links to the page that explains it. '
        'Numbered red badges mark the controls the text describes. The company, people and numbers are made up.',
        '',
        'Start with [Getting started](01-getting-started.md) if you want words as well. The full list of pages is in the [guide index](README.md).',
        '',
    ]
    total = 0
    for heading, keys in SECTIONS:
        out += [f'## {heading}', '']
        for key in keys:
            if key not in pages:
                continue
            name, title, shots = pages[key]
            out += [f'### {title}', '', f'[Read the page: {title}]({name})', '']
            for src, alt, cap in pick(shots, args.per_page):
                out += [f'![{alt}]({src})', '', f'*{cap}*', '']
                total += 1
    out += [
        '---',
        '',
        f'This page is generated from the guide by `tools/build-visual-tour.py` ({total} pictures). '
        'Do not edit it by hand.',
        '',
    ]
    dest = os.path.join(GUIDE, 'visual-tour.md')
    open(dest, 'w', encoding='utf-8').write('\n'.join(out))
    print(f'wrote {dest} ({total} pictures from {len(pages)} pages)')


if __name__ == '__main__':
    main()
