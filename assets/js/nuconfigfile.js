/* Admin tools → nuconfig.php (pages/nuconfigfile.php): CodeMirror on the textarea with the code tools (assets/js/nu/nucodetools.js:
 * undo/redo, search, replace, comment, invisibles, format, full screen – no hash variables here). Ctrl+S / Save: nuapp.js (data-nu-save). */
(function () {
	'use strict';
	var ta = document.getElementById('cfgContent');
	if (!ta) return;
	var f = document.getElementById('cfgForm'), live = document.getElementById('cfgLive'), cm = null, errLine = null, checked = false;

	// Save: check first (syntax, run, database – the same checks as the server), stay on the page with the error and its line
	function showError(msg, line) {
		live.textContent = msg; live.hidden = false;
		if (cm) {
			if (errLine !== null) cm.removeLineClass(errLine, 'background', 'cfgErrLine');
			errLine = null;
			if (line > 0 && line <= cm.lineCount()) {
				errLine = line - 1;
				cm.addLineClass(errLine, 'background', 'cfgErrLine');
				cm.scrollIntoView({line: errLine, ch: 0}, 80);
				cm.setCursor({line: errLine, ch: 0});
			}
			cm.focus();
		}
	}
	if (f && live) f.addEventListener('submit', function (e) {
		var btn = e.submitter;
		if (cm) cm.save();
		if (checked || !btn || btn.value !== 'save') return;       // Check and the second (checked) submit go through
		e.preventDefault();
		btn.disabled = true;
		var fd = new FormData(f);
		fd.set('action', 'precheck');
		fetch(f.getAttribute('action'), {method: 'POST', body: fd, credentials: 'same-origin', headers: {'Accept': 'application/json'}})
			.then(function (r) { return r.json(); })
			.then(function (j) {
				btn.disabled = false;
				if (!j.ok) { showError(j.error, j.line); return; }
				live.hidden = true;
				checked = true;
				if (f.requestSubmit) f.requestSubmit(btn); else btn.click();
			})
			.catch(function () { btn.disabled = false; checked = true; if (f.requestSubmit) f.requestSubmit(btn); else btn.click(); });   // no answer: the server still checks
	});
	if (!window.CodeMirror) { initInsert(null); return; }        // without CodeMirror the plain textarea stays
	var ro = ta.hasAttribute('readonly');
	cm = CodeMirror.fromTextArea(ta, {mode: {name: 'php', startOpen: false}, lineNumbers: true, lineWrapping: true, matchBrackets: true,
		autoCloseBrackets: true, indentUnit: 4, tabSize: 4, indentWithTabs: true, readOnly: ro ? 'nocursor' : false});
	cm.getWrapperElement().classList.add('cfgCM');
	cm.on('change', function () {
		cm.save(); ta.dispatchEvent(new Event('input', {bubbles: true}));    // marks the form as changed
		if (errLine !== null) { cm.removeLineClass(errLine, 'background', 'cfgErrLine'); errLine = null; }
	});
	var tools = document.querySelector('.cfgCodeTools');
	if (tools && window.NuCodeTools) NuCodeTools.attach(cm, tools, {lang: 'PHP', readOnly: ro});
	initInsert(cm);
})();

/* Insert variable (0.8.442): the dialog lists the variables nuBuilder Next reads (NuConfigFile::catalog) that the editor's text does not
 * set yet – a commented-out line counts as not set. A click inserts "$name = default;  //-- comment" as a line of its own after the
 * cursor's line (the cursor of the last click / key in the editor), otherwise at the end (before a closing ?>). Own variables below. */
function initInsert(cm) {
	'use strict';
	var box = document.getElementById('cfgInsert'), ta = document.getElementById('cfgContent');
	if (!box || !ta) return;
	var cat = [];
	try { cat = JSON.parse(box.getAttribute('data-catalog') || '[]'); } catch (e) { cat = []; }
	var list = document.getElementById('cfgInsList'), search = document.getElementById('cfgInsSearch'), done = document.getElementById('cfgInsDone');
	var placed = false;                                       // the user put the cursor somewhere (click or key in the editor)
	if (cm) { cm.on('mousedown', function () { placed = true; }); cm.on('keydown', function () { placed = true; }); }
	else { ta.addEventListener('mousedown', function () { placed = true; }); ta.addEventListener('keydown', function () { placed = true; }); }

	function text() { return cm ? cm.getValue() : ta.value; }
	function names(re) { var out = {}, m, s = text(); re.lastIndex = 0; while ((m = re.exec(s)) !== null) out[m[1]] = true; return out; }
	function setNames() { return names(/^[ \t]*\$([A-Za-z_]\w*)\s*=/gm); }
	function commentedNames() { return names(/^[ \t]*(?:\/\/|#)[ \t]*\$([A-Za-z_]\w*)\s*=/gm); }
	function el(tag, cls, txt) { var e = document.createElement(tag); if (cls) e.className = cls; if (txt !== undefined) e.textContent = txt; return e; }
	function safeComment(c) { return String(c || '').replace(/[\r\n]+/g, ' ').replace(/\?>/g, '? >').trim(); }   // ?> would end PHP inside a // comment
	function lineOf(name, value, comment) { comment = safeComment(comment); return '$' + name + ' = ' + value + ';' + (comment ? '\t\t//-- ' + comment : ''); }

	function insert(line) {
		var at;
		if (cm) {
			var doc = cm.getDoc(), last = doc.lastLine();
			if (placed) {
				var c = doc.getCursor(), t = doc.getLine(c.line);
				if (t.trim() === '') { doc.replaceRange(line, {line: c.line, ch: 0}, {line: c.line, ch: t.length}); at = c.line; }
				else { doc.replaceRange('\n' + line, {line: c.line, ch: t.length}); at = c.line + 1; }
			} else {
				var close = -1;
				for (var i = last; i >= 0; i--) { var li = doc.getLine(i).trim(); if (li === '') continue; if (li === '?>') close = i; break; }
				if (close >= 0) { doc.replaceRange(line + '\n', {line: close, ch: 0}); at = close; }
				else { var lt = doc.getLine(last); doc.replaceRange((lt.trim() === '' ? '' : '\n') + line + '\n', {line: last, ch: lt.length}); at = lt.trim() === '' ? last : last + 1; }
			}
			doc.setCursor({line: at, ch: doc.getLine(at).length});
			cm.scrollIntoView({line: at, ch: 0}, 80);
			cm.addLineClass(at, 'background', 'cfgInsLine');
			setTimeout(function () { cm.removeLineClass(at, 'background', 'cfgInsLine'); }, 2500);
			placed = true;                                    // the next one goes below this one
		} else {
			var v = ta.value, m = /\?>\s*$/.exec(v);
			ta.value = m ? v.slice(0, m.index) + line + '\n' + v.slice(m.index) : v.replace(/\s*$/, '\n') + line + '\n';
			ta.dispatchEvent(new Event('input', {bubbles: true}));
		}
	}

	function render() {
		var set = setNames(), com = commentedNames(), q = (search.value || '').toLowerCase().trim(), group = null, n = 0;
		list.textContent = '';
		cat.forEach(function (v) {
			if (set[v.name]) return;
			if (q && (v.name + ' ' + v.comment + ' ' + v.group).toLowerCase().indexOf(q) === -1) return;
			if (v.group !== group) { group = v.group; list.appendChild(el('div', 'list-group-item small fw-semibold text-uppercase text-body-secondary bg-body-tertiary py-1', group)); }
			var b = el('button', 'list-group-item list-group-item-action cfgInsItem');
			b.type = 'button';
			b.setAttribute('data-name', v.name);
			var head = el('div', 'd-flex flex-wrap align-items-baseline gap-2');
			head.appendChild(el('code', 'cfgInsName', '$' + v.name));
			head.appendChild(el('span', 'small text-body-secondary font-monospace', '= ' + v.value));
			if (com[v.name]) head.appendChild(el('span', 'badge text-bg-light border', box.getAttribute('data-commented')));
			b.appendChild(head);
			if (v.comment) b.appendChild(el('div', 'small text-body-secondary', v.comment));
			b.addEventListener('click', function () {
				insert(lineOf(v.name, v.value, v.comment));
				done.textContent = box.getAttribute('data-done').replace('%s', '$' + v.name);
				render();
			});
			list.appendChild(b);
			n++;
		});
		if (!n) list.appendChild(el('div', 'list-group-item small text-body-secondary', box.getAttribute('data-none')));
	}
	search.addEventListener('input', render);
	box.addEventListener('show.bs.modal', function () { done.textContent = ''; document.getElementById('cfgInsErr').textContent = ''; render(); });
	box.addEventListener('shown.bs.modal', function () { search.focus(); });

	// own variable: name (letters, digits, _), type text / number / true / false, optional comment
	var nameIn = document.getElementById('cfgInsName'), typeIn = document.getElementById('cfgInsType'), valIn = document.getElementById('cfgInsValue');
	var comIn = document.getElementById('cfgInsComment'), err = document.getElementById('cfgInsErr');
	function syncType() { valIn.disabled = typeIn.value === 'true' || typeIn.value === 'false'; }
	typeIn.addEventListener('change', syncType); syncType();
	document.getElementById('cfgInsAdd').addEventListener('click', function () {
		var name = nameIn.value.trim().replace(/^\$/, ''), type = typeIn.value, v = valIn.value, value;
		err.textContent = '';
		if (!/^[A-Za-z_][A-Za-z0-9_]*$/.test(name)) { err.textContent = box.getAttribute('data-bad-name'); nameIn.focus(); return; }
		if (setNames()[name]) { err.textContent = box.getAttribute('data-exists'); nameIn.focus(); return; }
		if (type === 'number') {
			if (!/^-?\d+(\.\d+)?$/.test(v.trim())) { err.textContent = box.getAttribute('data-bad-number'); valIn.focus(); return; }
			value = v.trim();
		} else if (type === 'true' || type === 'false') value = type;
		else value = "'" + v.replace(/\\/g, '\\\\').replace(/'/g, "\\'") + "'";
		insert(lineOf(name, value, comIn.value));
		done.textContent = box.getAttribute('data-done').replace('%s', '$' + name);
		nameIn.value = 'nuConfig'; valIn.value = ''; comIn.value = '';
		render();
	});
}
