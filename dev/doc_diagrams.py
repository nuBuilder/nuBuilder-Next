#!/usr/bin/env python3
"""Diagrams of the help (0.8.496), drawn as class-based inline SVG in the style of the architecture / schema diagrams.

    python3 -I dev/doc_diagrams.py            writes every diagram into its page (docs/pages/…)
    python3 -I dev/doc_diagrams.py --list     names and pages

Each diagram replaces the page's <pre class="doc-diagram"> (first run) or its own <figure class="doc-figure" id="dg-<name>">
(later runs), or goes after the given anchor text. Colours are classes styled in assets/css/nuapp.css (.doc-flow, light + dark):
n = browser / neutral, p = nuBuilder Next (server), g = database / result, a = your code, exits and stops. Change a diagram here and
run the script again – never edit the SVG in the page by hand.
"""
import html
import os
import re
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))


class D:
    def __init__(self, name, w, h, title, desc, wide=False):
        self.name, self.w, self.h, self.title, self.desc, self.wide = name, w, h, title, desc, wide
        self.parts = []

    def box(self, x, y, w, h, title, sub='', c='p'):
        self.parts.append('<rect x="%d" y="%d" width="%d" height="%d" rx="8" class="dg-box dg-%s"/>' % (x, y, w, h, c))
        cx = x + w // 2
        if sub:
            lines = sub.split('\n')
            top = y + h / 2 - 10 - 8 * (len(lines) - 1)
            self.text(cx, top, title, 't', c)
            for i, line in enumerate(lines):
                self.text(cx, top + 20 + i * 16, line, 's', c)
        else:
            self.text(cx, y + h / 2, title, 't', c)
        return self

    def text(self, x, y, s, kind='t', c='n', start=False):
        cls = 'dg-%s dg-%s%s' % (kind, c, ' dg-start' if start else '')
        self.parts.append('<text x="%g" y="%g" dy=".35em" class="%s">%s</text>' % (x, y, cls, html.escape(s, quote=False)))
        return self

    def head(self, x, y, s, start=True):
        return self.text(x, y, s, 't', 'n', start)

    def label(self, x, y, s, start=False, end=False):
        cls = ' dg-start' if start else (' dg-end' if end else '')
        self.parts.append('<text x="%g" y="%g" dy=".35em" class="dg-lbl%s">%s</text>' % (x, y, cls, html.escape(s, quote=False)))
        return self

    def arrow(self, *pts):
        d = 'M' + ' L'.join('%g %g' % p for p in pts)
        self.parts.append('<path d="%s" class="dg-line" marker-end="url(#dg-%s-arrow)"/>' % (d, self.name))
        return self

    def sep(self, y, x1=20, x2=None):
        self.parts.append('<line x1="%d" y1="%d" x2="%d" y2="%d" class="dg-sep"/>' % (x1, y, x2 or self.w - 20, y))
        return self

    def svg(self):
        n = self.name
        return ('<svg class="doc-flow%s" viewBox="0 0 %d %d" role="img" aria-labelledby="dg-%s-title dg-%s-desc" xmlns="http://www.w3.org/2000/svg">'
                '<title id="dg-%s-title">%s</title><desc id="dg-%s-desc">%s</desc>\n'
                '<defs><marker id="dg-%s-arrow" viewBox="0 0 10 10" refX="8" refY="5" markerWidth="6" markerHeight="6" orient="auto-start-reverse">'
                '<path d="M2 1L8 5L2 9" class="dg-head"/></marker></defs>\n%s\n</svg>') % (
            ' doc-flow-wide' if self.wide else '', self.w, self.h, n, n, n, html.escape(self.title), n, html.escape(self.desc), n, '\n'.join(self.parts))


# ------------------------------------------------------------------ the diagrams
def sessions():
    d = D('sessions', 680, 500, 'Sign-in and sessions',
          'The login form goes to NuAuth, which checks the password and the account; a second factor is asked when needed. '
          'The PHP session nunext is noted in zzzzsys_nunext_session and the log. Every later request is checked by NuAuth::user(): '
          'time-out, password changed elsewhere or session ended lead back to the sign-in page.')
    d.head(115, 40, 'Browser', False).head(345, 40, 'Server', False).head(570, 40, 'Database', False)
    d.box(40, 66, 150, 56, 'Login form', '?p=login', 'n')
    d.box(240, 66, 210, 56, 'NuAuth: sign in', 'password · active · not expired')
    d.box(500, 66, 140, 56, 'Second factor', 'app or e-mail code', 'a')
    d.arrow((190, 94), (238, 94)).arrow((450, 94), (498, 94)).label(474, 82, 'if needed')
    d.box(240, 160, 210, 56, 'Session “nunext”', 'user · level · password print')
    d.box(500, 160, 140, 56, '_nunext_session', 'hash, IP, last activity', 'g')
    d.box(500, 240, 140, 56, '_nunext_log', 'login event', 'g')
    d.arrow((300, 122), (300, 158)).arrow((570, 122), (570, 140), (400, 140), (400, 158))
    d.arrow((450, 188), (498, 188)).arrow((450, 205), (475, 205), (475, 268), (498, 268))
    d.sep(320)
    d.box(40, 346, 150, 56, 'Every request', 'cookie nunext', 'n')
    d.box(240, 340, 210, 68, 'NuAuth::user()', 'time-out? password changed?\nsession ended?')
    d.box(500, 346, 140, 56, 'Sign-in page', 'then back to the page', 'a')
    d.arrow((190, 374), (238, 374)).arrow((450, 374), (498, 374)).label(474, 362, 'if not')
    d.box(240, 430, 210, 50, 'The page or JSON answer', '', 'g')
    d.arrow((345, 402), (345, 428))
    return d, '03-03-sessions-arch.htm', None


def save():
    d = D('save', 680, 560, 'How a save works',
          'Posted values go through seven steps: visibility, concurrent edits, validation, Before Save, one transaction for record, '
          'sub-form rows and audit trail, the record log and After Save. Conflicts, field errors and nuDisplayError stop the save.')
    x, w = 220, 240
    steps = [('1 Record visible?', 'table exists, access condition', 'p'), ('2 Changed by others?', 'since the form was opened', 'p'),
             ('3 Validation', 'required · unique · masks', 'p'), ('4 Before Save', 'nu_before_save, the form’s hook', 'a'),
             ('5 Transaction', 'check 2 again with rows locked', 'p'), ('6 Record log', 'when the audit trail is off', 'p'),
             ('7 After Save', '#RECORD_ID# is the saved record', 'a')]
    d.box(20, 20, 160, 52, 'Posted values', 'edited fields, rows', 'n')
    exits = {1: '“Changed meanwhile”', 2: 'field errors', 3: 'nuDisplayError stops'}
    for i, (t, s, c) in enumerate(steps):
        y = 20 + i * 74
        d.box(x, y, w, 52, t, s, c)
        if i == 0:
            d.arrow((180, 46), (x - 2, 46))
        else:
            d.arrow((x + w / 2, y - 22), (x + w / 2, y - 2))
        if i in exits:
            d.box(495, y + 4, 170, 44, exits[i], '', 'a').arrow((x + w, y + 26), (493, y + 26))
    y5 = 20 + 4 * 74
    d.box(20, y5, 160, 52, 'Your tables', 'record · rows · audit trail', 'g').arrow((x, y5 + 26), (182, y5 + 26))
    y7 = 20 + 6 * 74
    d.box(20, y7 + 4, 160, 44, 'JSON {ok, record_id}', '', 'n').arrow((x, y7 + 26), (182, y7 + 26))
    return d, '05-01-save-flow.htm', None


def lifecycle():
    d = D('lifecycle', 760, 470, 'Form lifecycle and event order',
          'Four sequences from left to right: opening a list, opening a record, saving and deleting. Grey boxes run in the browser, '
          'purple ones on the server, amber ones are the hooks with your own PHP.', wide=True)
    d.box(20, 14, 112, 26, 'Browser (JS)', '', 'n').box(144, 14, 112, 26, 'Server', '', 'p').box(268, 14, 112, 26, 'Your PHP hook', '', 'a')
    rows = [('Opening a list', [('nu_before_save', 'then Before Browse', 'a'), ('List query', 'search · sort · paging', 'p'),
                                ('Browse global', 'nuLoadBrowseGlobal', 'n'), ('nuOnLoad', 'the form’s JavaScript', 'n')]),
            ('Opening a record', [('Load record', 'new record = -1', 'p'), ('nu_before_edit', 'then Before Edit', 'a'),
                                  ('Draw objects', 'field data access', 'p'), ('HTML scripts', 'nuLoadEditGlobal', 'n'),
                                  ('nuOnLoad', 'then nuAfterSave', 'n')]),
            ('Saving', [('nuBeforeSave', 'false cancels', 'n'), ('Checks', 'conflicts, validation', 'p'),
                        ('Before Save', 'nuDisplayError stops', 'a'), ('Transaction', 'record · rows · audit', 'p'),
                        ('After Save', 'then the form reloads', 'a')]),
            ('Deleting', [('nuBeforeDelete', 'false cancels', 'n'), ('Before Delete', 'an error stops', 'a'),
                          ('Transaction', 'record · rows · audit', 'p'), ('After Delete', '', 'a'), ('nuAfterDelete', '…Global', 'n')])]
    bw, gap = 136, 15
    for r, (title, boxes) in enumerate(rows):
        y = 74 + r * 100
        d.head(20, y, title)
        for i, (t, s, c) in enumerate(boxes):
            x = 20 + i * (bw + gap)
            d.box(x, y + 16, bw, 56, t, s, c)
            if i:
                d.arrow((x - gap, y + 44), (x - 2, y + 44))
    return d, '04-01-form-lifecycle.htm', ('<div class="doc-sub">', 'before')


def rendering():
    d = D('rendering', 680, 560, 'Form rendering pipeline',
          'index.php?p=form goes through metadata and rights, the hash variables, the Before Browse / Before Edit hooks, the data '
          'of the list or the record, field data access and NuRender to the HTML page, where nuruntime.js starts.')
    d.head(115, 24, 'Browser', False).head(345, 24, 'pages/nuform.php', False).head(570, 24, 'Database', False)
    x, w = 240, 210
    steps = [('Metadata and rights', 'NuFormMeta · NuAcl', 'p'), ('Hash variables', 'NuHash::forForm()', 'p'),
             ('Hooks', 'Before Browse / Before Edit', 'a'), ('Data', 'list: NuBrowse · record: NuRecord', 'p'),
             ('Field data access', 'left out or masked', 'p'), ('HTML', 'NuRender: absolute · auto · grid', 'p')]
    d.box(40, 44, 150, 52, '?p=form&f=…', '&r= for a record', 'n').arrow((190, 70), (238, 70))
    for i, (t, s, c) in enumerate(steps):
        y = 44 + i * 76
        d.box(x, y, w, 52, t, s, c)
        if i:
            d.arrow((x + w / 2, y - 24), (x + w / 2, y - 2))
    d.box(500, 44, 140, 52, 'Form metadata', 'zzzzsys_form, _tab…', 'g').arrow((500, 70), (452, 70))
    d.box(500, 44 + 3 * 76, 140, 52, 'Your tables', '', 'g').arrow((500, 44 + 3 * 76 + 26), (452, 44 + 3 * 76 + 26))
    yb = 44 + 5 * 76
    d.box(40, yb, 150, 52, 'HTML page', '+ nuruntime.js', 'n').arrow((x, yb + 26), (192, yb + 26))
    d.box(40, yb + 84, 150, 52, 'Load hooks', 'then ?p=formapi', 'n').arrow((115, yb + 52), (115, yb + 82))
    return d, '03-05-form-rendering.htm', ('<p>What runs in which order', 'before')


def updating():
    d = D('updating', 680, 640, 'Installing an update',
          'A package from an upload, the _update folder or GitHub is checked and previewed. Install changes the selected columns, '
          'makes a backup, switches maintenance mode on and copies the files; when copying fails the backup is put back. The next '
          'request runs the database steps and ends maintenance mode.')
    for i, (t, s) in enumerate([('Upload', 'nunext.zip'), ('_update/ folder', 'copied by FTP'), ('GitHub', 'daily check')]):
        y = 20 + i * 62
        d.box(20, y, 150, 50, t, s, 'n')
        d.arrow((170, y + 25), (205, y + 25), (205, 107), (238, 107))
    x, w = 240, 220
    steps = [('Check package', 'signature · files · PHP · packs', 'p'), ('Preview', 'database steps · structure', 'p'),
             ('Install: columns', 'selected structure changes', 'p'), ('Backup', 'files, own tables (+ SQL dump)', 'p'),
             ('Maintenance on', 'users see a notice', 'p'), ('Copy files', 'remove old ones · reset cache', 'p'),
             ('Next request', 'database steps (new code)', 'p'), ('Maintenance off', 'done – change history', 'g')]
    for i, (t, s, c) in enumerate(steps):
        y = 82 + i * 68
        d.box(x, y, w, 50, t, s, c)
        if i:
            d.arrow((x + w / 2, y - 18), (x + w / 2, y - 2))
    yc = 82 + 5 * 68
    d.box(510, yc, 150, 50, 'Backup put back', 'when copying fails', 'a').arrow((x + w, yc + 25), (508, yc + 25))
    d.box(510, 82 + 3 * 68, 150, 50, '_backup/', 'kept for rollback', 'g').arrow((x + w, 82 + 3 * 68 + 25), (508, 82 + 3 * 68 + 25))
    return d, '01-04-updating.htm', ('<li>Click <em>Install', 'after-list')


def access():
    d = D('access', 680, 250, 'Which data access rule wins',
          'Of the rules that match the user, the most specific wins: user, then permission item, then team or department, then access '
          'level, then everyone. Two rules of the same rank: the more open mode for fields, hidden for list columns.')
    d.label(20, 24, 'most specific', True).label(660, 24, 'least specific', False, True)
    for i, (t, s) in enumerate([('User', 'one login'), ('Permission item', 'of the user'), ('Team, department', 'of the user'),
                                ('Access level', 'the user’s level'), ('Everyone', 'the default')]):
        x = 20 + i * 131
        d.box(x, 40, 116, 56, t, s, 'p' if i < 4 else 'n')
        if i:
            d.arrow((x - 15, 68), (x - 2, 68))
    d.box(20, 150, 180, 56, 'Rules that match', 'condition true, if any', 'n')
    d.box(250, 144, 180, 68, 'Highest rank wins', 'tie: more open (fields)\nhide (list columns)', 'p')
    d.box(480, 150, 180, 56, 'Mode of the field', 'editable … withheld', 'g')
    d.arrow((200, 178), (248, 178)).arrow((430, 178), (478, 178))
    return d, '04-06-data-access.htm', ('<li><strong>Rules', 'after-list')


def home_users():
    d = D('homeusers', 680, 420, 'Which start page a user gets',
          'A user with an admin role gets the admin start page. Otherwise: the access level’s own page, else the default page for all '
          'access levels, else the automatic page with the forms and reports of the level. On top come the user’s own favourites and '
          'hidden tiles; tiles and groups with conditions show only when they match.')
    qs = [('Admin role?', 'Admin start page'), ('Own page of the level?', 'That page'), ('Default for all levels?', 'The default page')]
    for i, (q, a) in enumerate(qs):
        y = 20 + i * 86
        d.box(40, y, 230, 56, q, '', 'p').box(420, y, 200, 56, a, '', 'g')
        d.arrow((270, y + 28), (418, y + 28)).label(344, y + 18, 'yes')
        d.arrow((155, y + 56), (155, y + 84)).label(170, y + 70, 'no', True)
    d.box(40, 278, 230, 56, 'Automatic page', 'forms + reports of the level', 'g')
    d.box(420, 278, 200, 56, '+ the user’s favourites', 'and hidden tiles', 'n')
    d.box(420, 354, 200, 50, 'Conditions', 'permission, team, department', 'a')
    d.arrow((270, 306), (418, 306)).arrow((520, 334), (520, 352))
    d.arrow((520, 162), (520, 176), (650, 176), (650, 306), (622, 306))
    d.arrow((520, 248), (520, 262), (650, 262))
    return d, '08-07-home-users.htm', ('<li><strong>Which page a user gets', 'after-list')


def drafts():
    d = D('drafts', 680, 330, 'Drafts and publishing',
          'Start draft copies the live form. Save and Undo work in the draft only; Preview shows it to its author. Publish checks the '
          'draft, keeps the live form in the history and replaces it in one transaction; Discard deletes the draft.')
    d.box(20, 60, 170, 64, 'Live form', 'what users work with', 'g')
    d.box(255, 60, 170, 64, 'Draft', 'Save · Undo (20 steps)', 'p')
    d.box(490, 20, 170, 56, 'Preview', 'only you, yellow banner', 'n')
    d.box(490, 110, 170, 56, 'Compare with live', 'line by line', 'n')
    d.arrow((190, 80), (253, 80)).label(222, 68, 'Start draft')
    d.arrow((425, 78), (488, 48)).arrow((425, 108), (488, 138))
    d.box(255, 200, 170, 56, 'Publish', 'checks · note', 'a')
    d.arrow((340, 124), (340, 198))
    d.arrow((255, 228), (105, 228), (105, 126)).label(180, 216, 'replaces live')
    d.box(20, 264, 170, 56, 'History', 'before publish · vN', 'g').arrow((105, 230), (105, 262))
    d.box(490, 200, 170, 56, 'Discard', 'live form untouched', 'a').arrow((425, 100), (455, 100), (455, 228), (488, 228))
    return d, '04-04-form-drafts.htm', ('<li><strong>Start draft', 'before-list')


def libraries():
    d = D('libraries', 680, 400, 'Where libraries come from',
          'Library packs come from the GitHub release “libraries”, the _update folder or an upload; Update → Libraries checks their '
          'signature and every file and installs each version into its own folder in vendor/packs. The bundled libraries come with '
          'nunext.zip into vendor/bundled and are replaced by every update.')
    d.head(20, 20, 'Library packs (TCPDF, PHPMailer, TinyMCE, phpMyAdmin, Adminer, Uppy)')
    for i, (t, s) in enumerate([('GitHub release', 'tag “libraries”'), ('_update/ folder', 'zips copied by FTP'), ('Upload', 'Update → Libraries')]):
        y = 40 + i * 66
        d.box(20, y, 160, 52, t, s, 'n').arrow((180, y + 26), (215, y + 26), (215, 132), (238, 132))
    d.box(240, 104, 200, 56, 'Update → Libraries', 'signature · sha256 per file', 'p')
    d.box(480, 60, 180, 56, 'vendor/packs/', '<name>-<version>/', 'g')
    d.box(480, 150, 180, 56, 'nunext-libs.php', 'active version per library', 'g')
    d.arrow((440, 124), (478, 88)).arrow((440, 142), (478, 178))
    d.sep(262)
    d.head(20, 290, 'Bundled libraries (Bootstrap, jQuery, Tom Select, CodeMirror …)')
    d.box(20, 314, 160, 52, 'nunext.zip', 'every release', 'n')
    d.box(240, 314, 200, 52, 'Update', 'replaces, never deletes', 'p')
    d.box(480, 314, 180, 52, 'vendor/bundled/', 'libraries.json', 'g')
    d.arrow((180, 340), (238, 340)).arrow((440, 340), (478, 340))
    return d, '01-05-copy-files.htm', ('<p>Six libraries', 'before')


def devflow():
    d = D('devflow', 680, 330, 'Releases: fast and gated',
          'A normal request ends with a fast release: the change, the tests of its area and two signed zips. When the owner says gate, '
          'the full test run (smoke, all end-to-end tests, the checks against nuBuilder) makes a gated release that is pushed to GitHub; '
          'the owner publishes the GitHub release.')
    d.head(20, 20, 'A request')
    for i, (t, s, c) in enumerate([('Request', 'from the owner', 'n'), ('Change + tests', 'unit · area e2e · browser', 'p'),
                                   ('Fast release', 'release.py: not gated', 'p'), ('Two zips', 'nunext + nunext-dev', 'g')]):
        x = 20 + i * 165
        d.box(x, 40, 150, 56, t, s, c)
        if i:
            d.arrow((x - 15, 68), (x - 2, 68))
    d.sep(130)
    d.head(20, 158, '“gate”')
    for i, (t, s, c) in enumerate([('Full test run', 'smoke · all e2e · compat', 'p'), ('Gated release', 'release.py --gate', 'p'),
                                   ('Push to GitHub', 'all since the last gate', 'g'), ('GitHub release', 'by the owner', 'a')]):
        x = 20 + i * 165
        d.box(x, 178, 150, 56, t, s, c)
        if i:
            d.arrow((x - 15, 206), (x - 2, 206))
    d.label(20, 262, 'A failing test stops the release.', True)
    return d, '13-00-devflow.htm', ('<h3 id="devflow-gate">Fast release and gate</h3>', 'after')


DIAGRAMS = [sessions, save, lifecycle, rendering, updating, access, home_users, drafts, libraries, devflow]


def place(page, d, anchor):
    path = os.path.join(ROOT, 'docs', 'pages', page)
    src = open(path, encoding='utf-8').read()
    fig = '<figure class="doc-figure" id="dg-%s">%s</figure>' % (d.name, d.svg())
    own = re.compile(r'<figure class="doc-figure" id="dg-%s">.*?</figure>' % d.name, re.S)
    if own.search(src):
        src = own.sub(lambda m: fig, src, count=1)
    elif '<pre class="doc-code doc-diagram">' in src:
        src = re.sub(r'<pre class="doc-code doc-diagram">.*?</pre>', lambda m: fig, src, count=1, flags=re.S)
    else:
        text, mode = anchor
        i = src.find(text)
        if i < 0:
            raise SystemExit('%s: anchor %r not found' % (page, text))
        if mode == 'after':
            i += len(text)
        elif mode != 'before':                           # the list the anchor is in: before its start or after its end
            start = max(src.rfind('<ul', 0, i), src.rfind('<ol', 0, i))
            if mode == 'before-list':
                i = start
            else:
                tag = src[start + 1:start + 3]
                i = src.find('</%s>' % tag, i) + 5
        src = src[:i] + fig + '\n' + src[i:]
    open(path, 'w', encoding='utf-8').write(src)


if __name__ == '__main__':
    for fn in DIAGRAMS:
        d, page, anchor = fn()
        if '--list' in sys.argv:
            print(d.name, page)
        else:
            place(page, d, anchor)
            print('ok', d.name, '→', page)
