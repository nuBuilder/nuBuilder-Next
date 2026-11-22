#!/usr/bin/env python3
"""Group a style sheet by component (code review L2) without changing what any element looks like.

The file is cut into sections (a comment at the start of a line + the rules up to the next such comment). Each section gets a component
from its comment and selectors (start page, lists, users, code editors …). Going through the file from the top, a section moves up to
the end of the last earlier section of its component – but only when no section it would jump over has a declaration that can compete
with one of its own in the cascade: same property family (margin / margin-top …), same specificity and the same !important. With a
different specificity or importance the order of two rules does not matter, so the result is the same for every element.
    python3 -I dev/css_group.py <file.css> [--write] [--report]
Needs tinycss2. Check the pages afterwards (computed styles before / after).
"""
import re
import sys

import tinycss2

sys.path.insert(0, __import__('os').path.dirname(__file__))
from css_fold import family, offsets, specificity, split_list   # noqa: E402

COMPONENTS = [   # (component, pattern on the section's comment + selectors) – the first that matches
    ('help', r'help|documentation|tutorial|function reference|doc-|nuhelp|nuuh'),
    ('home', r'start page|home|tile|favourite|getting ready|nugr|nuhf|palette|ctrl\+k'),
    ('access', r'access level|admin role|\badm[A-Z]|#adm|nuacc|\.acc[A-Z]|#acc'),
    ('users', r'user form|users|user page|#user|\.user[A-Z]|nuuser|account|sessions?\b|\.sess'),
    ('code', r'code editor|codemirror|code search|global code|syntax check|insert menu|hash variables|nuct|nucode|\.cs[A-Z]|#cs|scode|cm-'),
    ('procedures', r'procedure|\.proc[A-Z]|#proc'),
    ('settings', r'setting|nuset|#set[A-Z]|\.set[A-Z]|#sh[A-Z]'),
    ('update', r'update page|librar|backup|database steps|\.upd|#upd|nulp|nuint|#rst|install'),
    ('dashboards', r'dashboard|nudash'),
    ('notifications', r'notification|\.ntf|#ntf|bell'),
    ('mail', r'e-mail|mail|\.nuemt|#nuemt|\.emt|nuMail'),
    ('lists', r'\blist|browse|table|\brow\b|rows|column|pager|page size|search|filter|nulv|nu-table'),
    ('forms', r'\bform\b|forms|record|draft|design|new form|maintenance|clone|nurec|nufms|cloner|inspect'),
    ('navigation', r'navbar|top bar|logo|breadcrumb|hamburger|\bmenu|\bpin\b|sidebar|nusb|back button|nunav'),
    ('security', r'security|password|2fa|passkey|login|sign-in|#sec|\.sec[A-Z]|ip access|sso|pwpolicy'),
    ('look', r'theme|dark|contrast|accessib|grey|light mode|light theme|segmented|toast|flash|badge|button'),
    ('responsive', r'phone|mobile|tablet|touch|laptops|wide screens|narrow'),
]


def sections(text: str):
    ls = offsets(text)

    def pos(nd) -> int:
        return ls[nd.source_line - 1] + nd.source_column - 1
    nodes = tinycss2.parse_stylesheet(text, skip_comments=False, skip_whitespace=True)
    secs, cur = [], None
    for nd in nodes:
        st = pos(nd)
        if nd.type == 'comment' and (st == 0 or text[st - 1] == '\n'):
            cur = {'start': st, 'keys': set(), 'text': nd.value, 'sel': '', 'opaque': False}
            secs.append(cur)
            continue
        if cur is None:
            cur = {'start': 0, 'keys': set(), 'text': '', 'sel': '', 'opaque': True}   # the head of the file stays first
            secs.append(cur)
        if nd.type == 'qualified-rule':
            add_rule(cur, nd)
        elif nd.type == 'at-rule':
            add_at_rule(cur, nd)
    for i, s in enumerate(secs):
        s['end'] = secs[i + 1]['start'] if i + 1 < len(secs) else len(text)
    return secs


def add_at_rule(sec, nd):
    kw = nd.lower_at_keyword
    if nd.content is not None and kw in ('media', 'supports', 'container', 'layer'):
        for r in tinycss2.parse_rule_list(nd.content, skip_comments=True, skip_whitespace=True):
            if r.type == 'qualified-rule':
                add_rule(sec, r)
            elif r.type == 'at-rule':
                sec['opaque'] = True
    elif kw not in ('keyframes', 'font-face'):
        sec['opaque'] = True     # @import, @charset, @layer statements … never move


def add_rule(sec, nd):
    sel = ' '.join(tinycss2.serialize(nd.prelude).split())
    sec['sel'] += ' ' + sel
    specs = {specificity(x) for x in split_list(sel)}
    for d in tinycss2.parse_declaration_list(nd.content, skip_comments=True, skip_whitespace=True):
        if d.type == 'declaration':
            for sp in specs:
                sec['keys'].add((family(d.name), sp, bool(d.important)))


def component(sec):
    t = (sec['text'] + ' ' + sec['sel']).lower()
    for name, pat in COMPONENTS:
        if re.search(pat.lower(), t):
            return name
    return None


def conflicts(a, b) -> bool:
    if a['opaque'] or b['opaque']:
        return True
    for f, sp, imp in a['keys']:
        if f == 'all' or (('all', sp, imp) in b['keys']) or (f, sp, imp) in b['keys']:
            return True
    return False


def place_of(seq: list):
    """Index of the section the last one of seq moves behind, or None when it stays."""
    s, end = seq[-1], len(seq) - 1
    if s['comp'] is None:
        return None
    t = max((k for k in range(end) if seq[k]['comp'] == s['comp']), default=None)
    if t is None or t == end - 1 or any(conflicts(s, seq[k]) for k in range(t + 1, end)):
        return None
    return t


def piece(text: str, s: dict) -> str:
    part = text[s['start']:s['end']]
    return part if part.endswith('\n') else part + '\n'


def group(text: str):
    secs = sections(text)
    for s in secs:
        s['comp'] = None if s['opaque'] else component(s)
    seq, moved = [], 0
    for s in secs:
        seq.append(s)
        t = place_of(seq)
        if t is not None:
            seq.insert(t + 1, seq.pop())
            moved += 1
    out = ''.join(piece(text, s) for s in seq)
    return out, moved, len(secs)


def main() -> int:
    if len(sys.argv) < 2:
        print(__doc__)
        return 2
    path = sys.argv[1]
    with open(path, encoding='utf-8') as fh:
        src = fh.read()
    out, moved, n = group(src)
    print(f'{path}: {moved} of {n} sections moved next to their component')
    if '--write' in sys.argv and out != src:
        with open(path, 'w', encoding='utf-8') as fh:
            fh.write(out)
    return 0


if __name__ == '__main__':
    sys.exit(main())
