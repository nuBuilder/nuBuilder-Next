#!/usr/bin/env python3
"""
Adds new texts to the eleven language files in one go (lang/en, de, fr, it, es, pt, nl, pl, ru, zh, ar.php).

    python3 dev/lang_add.py texts.json

texts.json:
    {"after": "existing_key",                      # the new lines go right after the line of this key (in every file)
     "keys": {"new_key": {"en": "…", "de": "…", "fr": "…", "it": "…", "es": "…", "pt": "…", "nl": "…", "pl": "…",
                          "ru": "…", "zh": "…", "ar": "…"}, …}}

Several blocks: a list of such objects. Every key needs all eleven languages; a key that exists already stops the script.
English is British (colour, customise, dialogue); German neutral (no "Sie"), German quotes „…“. Placeholders %s / %d as in English.
Afterwards: php dev/lang_variants.php (writes en-US / de-CH) and php dev/check_lang.php (must say "all languages complete").
"""
import json, re, sys, os

LANGS = ['en', 'de', 'fr', 'it', 'es', 'pt', 'nl', 'pl', 'ru', 'zh', 'ar']
ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))


def q(s):
    return "'" + s.replace('\\', '\\\\').replace("'", "\\'") + "'"


def main():
    if len(sys.argv) != 2:
        sys.exit(__doc__)
    data = json.load(open(sys.argv[1], encoding='utf-8'))
    blocks = data if isinstance(data, list) else [data]
    for b in blocks:
        for k, v in b['keys'].items():
            missing = [l for l in LANGS if not v.get(l)]
            if missing:
                sys.exit(f'{k}: missing {missing}')
    for lang in LANGS:
        p = os.path.join(ROOT, 'lang', lang + '.php')
        s = open(p, encoding='utf-8').read()
        for b in blocks:
            for k in b['keys']:
                if re.search(r"'" + re.escape(k) + r"' =>", s):
                    sys.exit(f'{p}: {k} exists already')
            m = re.search(r"\n\t[^\n]*'" + re.escape(b['after']) + r"' => [^\n]*\n", s)
            if not m:
                sys.exit(f'{p}: anchor key {b["after"]} not found')
            s = s[:m.end()] + ''.join(f"\t'{k}' => {q(v[lang])},\n" for k, v in b['keys'].items()) + s[m.end():]
        open(p, 'w', encoding='utf-8').write(s)
    print('added', sum(len(b['keys']) for b in blocks), 'keys to', len(LANGS), 'files')


if __name__ == '__main__':
    main()
