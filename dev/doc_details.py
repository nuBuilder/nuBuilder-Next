#!/usr/bin/env python3
"""Help pages: find passages that only add detail and mark them data-level="detail" (0.8.884).

    python3 -I dev/doc_details.py            # report: candidates per page (nothing written)
    python3 -I dev/doc_details.py --list     # one line per candidate (file:line, reason, text) – review these
    python3 -I dev/doc_details.py --apply    # write ALL candidates into docs/pages/*.htm (Guide tab only) – only after a review

The reader's level Essentials (Help → side bar) hides these passages; Standard and Everything show them. The heuristic is a first
pass, not a decision: in 0.8.884 44 of its 108 candidates were marked by hand after reading them (the others are main points,
e.g. "as in nuBuilder Forte" in the description of a feature). New text: mark details by hand. Only one-line <p> / <li> are touched; never the
first paragraph after a heading, the lead / audience / see-also paragraphs, anything in an advanced topic or a developer block,
and never the last visible paragraph of a sub-section (dev/tests/run.php checks that every topic keeps its content in Essentials).
"""
import json
import os
import re
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
PAGES = os.path.join(ROOT, 'docs', 'pages')

FORTE = re.compile(r'(as in nuBuilder Forte|unlike nuBuilder Forte|nuBuilder Forte (does|did|shows|has|had|keeps|ignores|writes|stores|uses|'
                   r'needs|only|lists|sends|offers|knows|calls|reads|treats|returns|runs|prints|cannot|can not|leaves|takes|sets)|nuBuilder Forte\'s own|'
                   r'in nuBuilder Forte (it|they|this|the)|\(nuBuilder Forte|nuBuilder Forte 4\.9 (does|has|shows))', re.I)
INTERNAL = re.compile(r'<code>[^<]*(\b(s[a-z]{2}|z{4}sys|nunext)_[a-z0-9_]+|\b[A-Z][A-Z0-9]+_[A-Z0-9_]{2,}\b|[\w.-]+/[\w./-]+\.(php|js|json|css|sql)|'
                      r'\$nu[A-Za-z]+|\bsus_json\b)[^<]*</code>')
SKIP_CLASS = re.compile(r'class="[^"]*\b(doc-lead|doc-for|doc-see)\b')


def guide_files():
    toc = json.load(open(os.path.join(ROOT, 'docs', 'toc.json'), encoding='utf-8'))
    out = []
    for ch in toc['chapters']:
        if ch.get('tab', 'guide') != 'guide':
            continue
        out += [ch.get('file')] + [p['file'] for p in ch.get('pages', [])]
    return [f for f in out if f]


def text_len(s):
    return len(re.sub(r'<[^>]+>', '', s).strip())


def why(line):
    """Reason a one-line <p> / <li> counts as a detail, or None."""
    if FORTE.search(line):
        return 'forte'
    n = len(INTERNAL.findall(line))
    if n >= 2:
        return 'internal'
    if n >= 1 and text_len(line) > 380:
        return 'internal+long'
    if text_len(line) > 520 and line.count('<code>') >= 3:
        return 'long'
    return None


def scan(path):
    lines = open(path, encoding='utf-8').read().split('\n')
    marks, depth_skip, after_heading, block = [], [], False, []   # block: content lines of the current sub-section
    stack = []          # open containers: ('adv' | 'dev' | 'plain')
    blocks, cur = [], []
    for i, raw in enumerate(lines):
        s = raw.strip()
        opened = re.findall(r'<(section|div|ul|ol|details|table|figure)\b([^>]*)>', s)
        for tag, attrs in opened:
            kind = 'adv' if 'data-level="advanced"' in attrs else ('dev' if 'data-doc="dev"' in attrs else ('code' if tag in ('details', 'table', 'figure') else 'plain'))
            stack.append((tag, kind))
        if re.match(r'<h[23]\b', s):
            after_heading = True
            if cur:
                blocks.append(cur)
            cur = []
        elif re.match(r'<(p|li)\b', s):
            inside = {k for _, k in stack}
            one = re.search(r'</(p|li)>\s*$', s) is not None
            plain_p = s.startswith('<p') and not SKIP_CLASS.search(s)
            if s.startswith('<li') or plain_p:
                cand = one and not after_heading and not ({'adv', 'dev', 'code'} & inside) and 'data-level=' not in s and 'data-doc=' not in s.split('>')[0]
                r = why(s) if cand else None
                cur.append((i, r))
                if s.startswith('<p'):
                    after_heading = False
        elif re.match(r'<(ul|ol|table|pre|figure)\b', s):
            cur.append((i, None))       # a list / table / figure keeps the topic readable
            after_heading = False
        for _ in re.findall(r'</(section|div|ul|ol|details|table|figure)>', s):
            if stack:
                stack.pop()
    if cur:
        blocks.append(cur)
    for b in blocks:
        visible = [x for x in b if x[1] is None]
        cands = [x for x in b if x[1] is not None]
        if not visible and cands:          # never empty a sub-section: keep its first candidate
            cands = cands[1:]
        marks += cands
    return lines, marks


def mark(line):
    return re.sub(r'^(\s*<(p|li))\b', r'\1 data-level="detail"', line, count=1)


def main():
    apply = '--apply' in sys.argv
    if '--list' in sys.argv:
        for f in guide_files():
            lines, marks = scan(os.path.join(PAGES, f))
            for i, r in marks:
                print(f'{f}:{i + 1} [{r}] ' + re.sub(r'\s+', ' ', re.sub('<[^>]+>', '', lines[i])).strip()[:200])
        return
    total = chars = 0
    for f in guide_files():
        path = os.path.join(PAGES, f)
        lines, marks = scan(path)
        if not marks:
            continue
        total += len(marks)
        chars += sum(text_len(lines[i]) for i, _ in marks)
        print(f'{f}: {len(marks)}  ' + ', '.join(sorted({r for _, r in marks})))
        if apply:
            for i, _ in marks:
                lines[i] = mark(lines[i])
            open(path, 'w', encoding='utf-8').write('\n'.join(lines))
    print(f'{total} passages, {chars} characters' + (' – marked' if apply else ' (report only, --apply writes them)'))


if __name__ == '__main__':
    main()
