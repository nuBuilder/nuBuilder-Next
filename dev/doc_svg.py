#!/usr/bin/env python3
"""Turn a diagram SVG (inline styles, e.g. drawn by Claude) into the help's class-based inline SVG (0.8.495).

    python3 -I dev/doc_svg.py <file.svg> <id prefix>  > snippet.svg

The help runs under a strict CSP (no style attributes), so colours become classes styled in assets/css/nuapp.css
(.doc-flow …, light and dark): boxes dg-box + dg-n (neutral) / dg-p (purple) / dg-g (green), texts dg-t (14 px title) or dg-s
(12 px subtitle) + the box colour, dg-start for left-aligned headings, lines dg-line, dashed separators dg-sep, arrow heads dg-head.
Ids get the prefix (unique over all help pages); title / desc are kept for screen readers. Paste the result into a page as
<figure class="doc-figure">…</figure>. Unknown colours stop the script – add them to the maps below and to nuapp.css.
"""
import re
import sys

BOX = {'rgb(252, 252, 251)': 'n', 'rgb(238, 237, 254)': 'p', 'rgb(225, 245, 238)': 'g'}
TXT = {'rgb(11, 11, 11)': 'n', 'rgb(82, 81, 78)': 'n', 'rgb(60, 52, 137)': 'p', 'rgb(83, 74, 183)': 'p',
       'rgb(8, 80, 65)': 'g', 'rgb(15, 110, 86)': 'g'}


def style_of(attrs, key):
    st = re.search(r'style="([^"]*)"', attrs)
    m = re.search(r'(?:^|;)' + key + r':([^;]+)', st.group(1)) if st else None
    return m.group(1).strip() if m else ''


def convert(src, prefix):
    src = re.sub(r'<metadata>.*?</metadata>', '', src, flags=re.S)
    ids = re.findall(r'\sid="([^"]+)"', src)

    def tag(m):
        name, attrs, end = m.group(1), m.group(2), m.group(3)
        cls = None
        if name == 'rect':
            cls = 'dg-box dg-' + BOX[style_of(attrs, 'fill')]
        elif name == 'text':
            cls = ('dg-t' if style_of(attrs, 'font-size') == '14px' else 'dg-s') + ' dg-' + TXT[style_of(attrs, 'fill')]
            if 'text-anchor="middle"' not in attrs:
                cls += ' dg-start'
        elif name in ('line', 'path'):
            if 'context-stroke' in attrs:
                return '<path d="' + re.search(r'\sd="([^"]+)"', attrs).group(1) + '" class="dg-head"' + end
            cls = 'dg-sep' if 'stroke-dasharray' in attrs else 'dg-line'
        attrs = re.sub(r'\s*style="[^"]*"', '', attrs)
        attrs = re.sub(r'\s(?:text-anchor|stroke-width|fill)="[^"]*"', '', attrs)
        attrs = attrs.replace(' dominant-baseline="central"', ' dy=".35em"')
        return '<' + name + attrs + (' class="' + cls + '"' if cls else '') + end

    src = re.sub(r'<(rect|text|line|path|g|title|desc)\b([^>]*?)(/?>)', tag, src)
    src = re.sub(r'</?g>', '', src)
    vb = re.search(r'viewBox="([^"]+)"', src).group(1)
    src = re.sub(r'<svg[^>]*>', '<svg class="doc-flow" viewBox="' + vb + '" role="img" aria-labelledby="' + prefix + '-title '
                 + prefix + '-desc" xmlns="http://www.w3.org/2000/svg">', src, count=1)
    src = src.replace('<title>', '<title id="' + prefix + '-title">', 1).replace('<desc>', '<desc id="' + prefix + '-desc">', 1)
    for i in ids:
        src = src.replace('id="' + i + '"', 'id="' + prefix + '-' + i + '"').replace('url(#' + i + ')', 'url(#' + prefix + '-' + i + ')')
    return re.sub(r'\n\s*\n', '\n', src).strip()


if __name__ == '__main__':
    if len(sys.argv) != 3:
        sys.exit(__doc__)
    print(convert(open(sys.argv[1], encoding='utf-8').read(), sys.argv[2]))
