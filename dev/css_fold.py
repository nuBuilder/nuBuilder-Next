#!/usr/bin/env python3
"""Fold later corrections of a style sheet back into the first rule of the same selector (code review L2).

A rule B later in the file with the SAME selector in the SAME @media / @supports context as an earlier rule A gives its declarations to A
when no rule in between (any selector, any context) sets a property of the same family (margin / margin-top, inset / top, font /
line-height …) with the same specificity and !important – only rules equal in both are decided by their order, so the cascade gives
every element the same value as before; only where the value is written changes. B is removed
when nothing is left of it; its comment stays. Text outside the changed rules is kept byte for byte.
    python3 -I dev/css_fold.py <file.css> [--write] [--report]
Needs tinycss2 (pip install tinycss2). Check the pages afterwards (screenshots before / after) – the script is careful, the result is
still a change of a style sheet.
"""
import re
import sys

import tinycss2

FAMILY = {'top': 'inset', 'right': 'inset', 'bottom': 'inset', 'left': 'inset', 'line-height': 'font', 'row-gap': 'gap', 'column-gap': 'gap',
          'place': 'align', 'justify': 'align', 'column': 'columns'}


def family(name: str) -> str:
    n = name.lower()
    if n.startswith('--'):
        return n
    if n in FAMILY:
        return FAMILY[n]
    if n.startswith('-') and not n.startswith('--'):
        n = re.sub(r'^-\w+-', '', n)
    head = n.split('-')[0]
    return FAMILY.get(head, head)


def specificity(sel: str):
    """(ids, classes, types) of one complex selector – :is / :not / :has take their most specific argument, :where nothing."""
    def strip_fn(s, name):
        out, i = '', 0
        while True:
            m = re.search(r':' + name + r'\(', s[i:], re.I)
            if not m:
                return out + s[i:], []
            st = i + m.start()
            j, d = st + len(name) + 2, 1
            while j < len(s) and d:
                d += {'(': 1, ')': -1}.get(s[j], 0)
                j += 1
            args = s[st + len(name) + 2:j - 1]
            out += s[i:st]
            i = j
            rest, more = strip_fn(s[i:], name)
            return out + rest, [args] + more
    s = strip_fn(sel, 'where')[0]
    extra = (0, 0, 0)
    for nm in ('is', 'not', 'has', 'matches'):
        s, args = strip_fn(s, nm)
        for a in args:
            best = max((specificity(x) for x in split_list(a)), default=(0, 0, 0))
            extra = tuple(x + y for x, y in zip(extra, best))
    s = re.sub(r'"[^"]*"|\'[^\']*\'', '', s)
    s = re.sub(r'(?<!:):(before|after|first-line|first-letter)\b', r'::\1', s, flags=re.I)   # old one-colon pseudo-elements
    s = re.sub(r'\([^()]*\)', '', s)                  # arguments of :nth-child(…), :lang(…) … are not part of the count
    ids = len(re.findall(r'#[\w-]', s))
    cls = len(re.findall(r'\.[\w-]|\[|(?<!:):(?!:)[\w-]', s))
    pe = len(re.findall(r'::[\w-]', s))
    tags = len(re.findall(r'(?:^|[\s>+~(])([a-zA-Z][\w-]*)', s)) + pe
    return (ids + extra[0], cls + extra[1], tags + extra[2])


def split_list(sel: str):
    out, d, cur = [], 0, ''
    for ch in sel:
        if ch in '([':
            d += 1
        elif ch in ')]':
            d -= 1
        if ch == ',' and d == 0:
            out.append(cur.strip())
            cur = ''
        else:
            cur += ch
    if cur.strip():
        out.append(cur.strip())
    return out


def offsets(text: str):
    starts = [0]
    for i, ch in enumerate(text):
        if ch == '\n':
            starts.append(i + 1)
    return starts


def rule_end(text: str, i: int) -> int:
    """Index after the '}' that closes the block starting at the first '{' from i (strings and comments skipped)."""
    depth, n = 0, len(text)
    while i < n:
        c = text[i]
        if text.startswith('/*', i):
            i = text.find('*/', i + 2) + 2
            continue
        if c in '"\'':
            j = i + 1
            while j < n and text[j] != c:
                j += 2 if text[j] == '\\' else 1
            i = j + 1
            continue
        if c == '{':
            depth += 1
        elif c == '}':
            depth -= 1
            if depth == 0:
                return i + 1
        i += 1
    raise ValueError('unbalanced braces')


def collect(text: str):
    ls = offsets(text)

    def pos(node) -> int:
        return ls[node.source_line - 1] + node.source_column - 1
    out = []

    def walk(nodes, ctx):
        for nd in nodes:
            if nd.type == 'qualified-rule':
                st = pos(nd)
                en = rule_end(text, st)
                sel = ' '.join(tinycss2.serialize(nd.prelude).split())
                decls = [d for d in tinycss2.parse_declaration_list(nd.content, skip_comments=True, skip_whitespace=True) if d.type == 'declaration']
                out.append({'ctx': ctx, 'sel': sel, 'start': st, 'end': en, 'decls': decls, 'open': text.index('{', st)})
            elif nd.type == 'at-rule' and nd.content is not None:
                kw = nd.lower_at_keyword
                if kw in ('media', 'supports', 'container', 'layer'):
                    walk(tinycss2.parse_rule_list(nd.content, skip_comments=True, skip_whitespace=True),
                         ctx + ((kw, ' '.join(tinycss2.serialize(nd.prelude).split())),))
                else:   # @keyframes, @font-face …: never folded, but their properties count as "in between"
                    out.append({'ctx': ctx + ((kw, '*'),), 'sel': None, 'start': pos(nd), 'end': pos(nd), 'decls': [], 'open': -1,
                                'fam': {('*', None, None)} if kw != 'keyframes' else set()})
    walk(tinycss2.parse_stylesheet(text, skip_comments=True, skip_whitespace=True), ())
    for r in out:
        specs = {specificity(x) for x in split_list(r['sel'])} if r['sel'] else set()
        r.setdefault('fam', {(family(d.name), sp, bool(d.important)) for d in r['decls'] for sp in specs})
    return out


def decl_text(d) -> str:
    return d.name + ': ' + tinycss2.serialize(d.value).strip() + (' !important' if d.important else '')


def fam_keys(decls, specs) -> set:
    return {(family(d.name), sp, bool(d.important)) for d in decls for sp in specs}


def target_of(rules: list, b: int, keep: dict) -> int | None:
    """The first earlier rule of the same selector and context – one that gave declarations away itself is never a target."""
    rb = rules[b]
    if rb['sel'] is None or not rb['decls']:
        return None
    return next((k for k in range(b) if rules[k]['sel'] == rb['sel'] and rules[k]['ctx'] == rb['ctx'] and k not in keep), None)


def movable(rules: list, fams: list, a: int, b: int, mine: list):
    """The declarations of rule b that may move to rule a, with the specificities of b's selector."""
    between = set().union(*fams[a + 1:b])
    if any(x[0] in ('*', 'all') for x in between):
        return [], set()
    # a declaration moves when nothing in between sets its family with the same specificity and importance: only rules of equal
    # specificity and importance are decided by their order
    specs = {specificity(x) for x in split_list(rules[b]['sel'])}
    return [d for d in mine if family(d.name) != 'all' and not fam_keys([d], specs) & between], specs


def plan(rules: list):
    """Which declarations move: {index of A: [decl text]}, {index of B: remaining decls}, count."""
    moved: dict[int, list[str]] = {}
    keep: dict[int, list] = {}
    folded = 0
    fams = [set(r['fam']) for r in rules]     # families each rule sets NOW (what it gave away gone, what it got added)
    for b, rb in enumerate(rules):
        a = target_of(rules, b, keep)
        if a is None:
            continue
        mine = keep.get(b) or rb['decls']
        go, specs = movable(rules, fams, a, b, mine)
        if not go:
            continue
        rest = [d for d in mine if d not in go]
        moved.setdefault(a, []).extend(decl_text(d) for d in go)
        keep[b] = rest
        fams[b] = fam_keys(rest, specs)
        fams[a] |= fam_keys(go, specs)
        folded += len(go)
    return moved, keep, folded


def add_edit(text: str, ra: dict, add: list):
    """The edit that appends the declarations add to rule ra, in the rule's own layout (one line or one per line)."""
    close = ra['end'] - 1
    inner = text[ra['open'] + 1:close]
    sep = '' if inner.rstrip().endswith(';') or inner.strip() == '' else ';'
    if '\n' not in inner:
        return ra['open'] + 1, close, inner.rstrip() + sep + ' ' + '; '.join(add) + '; '
    parts = inner.split('\n')
    prev = parts[-2] if inner.rstrip('\t ').endswith('\n') and len(parts) > 1 else ''
    indent = re.match(r'\s*', prev).group(0) or '\t'
    tail = inner[len(inner.rstrip()):].lstrip('\n')
    return ra['open'] + 1, close, inner.rstrip() + sep + ''.join('\n' + indent + x + ';' for x in add) + '\n' + tail


def remove_edit(text: str, rb: dict):
    """The edit that removes rule rb with the rest of its line when it stood alone on it."""
    s, e = rb['start'], rb['end']
    while e < len(text) and text[e] in ' \t':
        e += 1
    if e < len(text) and text[e] == '\n' and (s == 0 or text[s - 1] == '\n'):
        e += 1
    return s, e, ''


def fold(text: str):
    rules = collect(text)
    moved, keep, folded = plan(rules)
    edits = [add_edit(text, rules[a], add) for a, add in moved.items()]
    removed = 0
    for b, rest in keep.items():
        rb = rules[b]
        if not rest:
            edits.append(remove_edit(text, rb))
            removed += 1
        else:
            edits.append((rb['open'] + 1, rb['end'] - 1, ' ' + '; '.join(decl_text(d) for d in rest) + '; '))
    for s, e, new in sorted(edits, key=lambda x: -x[0]):    # from the end, so offsets stay valid
        text = text[:s] + new + text[e:]
    return text, folded, removed


def main() -> int:
    if len(sys.argv) < 2:
        print(__doc__)
        return 2
    path = sys.argv[1]
    with open(path, encoding='utf-8') as fh:
        src = fh.read()
    out, folded, removed = fold(src)
    print(f'{path}: {folded} declaration(s) folded into earlier rules, {removed} rule(s) removed, {src.count(chr(10))} → {out.count(chr(10))} lines')
    if '--write' in sys.argv and out != src:
        with open(path, 'w', encoding='utf-8') as fh:
            fh.write(out)
    return 0


if __name__ == '__main__':
    sys.exit(main())
