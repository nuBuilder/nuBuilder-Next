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
	if (!window.CodeMirror) return;                              // without CodeMirror the plain textarea stays
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
})();
