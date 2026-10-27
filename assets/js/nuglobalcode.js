/* Admin tools → Custom code (pages/nuglobalcode.php, 0.8.444; Global JS & CSS 0.8.523): a CodeMirror editor per tab (JavaScript, CSS, HTML); the open tab is sent with
 * Save, so the page comes back to it. Tabs in the address: nuapp.js (data-nu-hash-tabs). */
(function () {
	'use strict';
	var form = document.getElementById('scodeForm');
	if (!form) return;
	var editors = [], byTab = {};
	if (window.CodeMirror) {
		Array.prototype.forEach.call(form.querySelectorAll('textarea.scodeCode'), function (ta) {
			var ro = ta.hasAttribute('readonly');
			var cm = CodeMirror.fromTextArea(ta, {mode: ta.getAttribute('data-mode') || 'javascript', lineNumbers: true, lineWrapping: true, matchBrackets: true,
				autoCloseBrackets: true, indentUnit: 4, tabSize: 4, indentWithTabs: true, readOnly: ro ? 'nocursor' : false});
			cm.getWrapperElement().classList.add('scodeCM');
			cm.on('change', function () { cm.save(); ta.dispatchEvent(new Event('input', {bubbles: true})); });   // red Save, leave guard
			editors.push(cm);
			byTab[ta.id.replace(/^scode-/, '')] = cm;
			// toolbar (0.8.554): undo / redo, search, replace, comment, invisibles, format, full screen
			var tools = form.querySelector('.scodeTools[data-for="' + ta.id + '"]');
			if (tools && window.NuCodeTools) NuCodeTools.attach(cm, tools, {lang: {css: 'CSS', htmlmixed: 'HTML'}[ta.getAttribute('data-mode')] || 'JS', readOnly: ro});
		});
	}
	// height (0.8.554): the editor of the open tab reaches down to the bottom of the window (at least 360 px), also after resizing;
	// not while the toolbar's full screen is on – that one sizes the editor itself
	var MIN_H = 360, GAP = 24;
	function fit() {
		if (document.documentElement.classList.contains('nuCodeFullOpen')) return;
		editors.forEach(function (cm) {
			var w = cm.getWrapperElement();
			if (!w.offsetParent) return;                                     // hidden tab: sized when it is shown
			cm.setSize(null, Math.max(MIN_H, Math.floor(window.innerHeight - (w.getBoundingClientRect().top + window.scrollY) - GAP)));
		});
	}
	var fitTimer = null;
	window.addEventListener('resize', function () { clearTimeout(fitTimer); fitTimer = setTimeout(fit, 80); });
	fit();
	var tabs = document.getElementById('scodeTabs'), field = document.getElementById('scodeTab');
	function current() { var a = tabs && tabs.querySelector('.nav-link.active'); return a ? a.getAttribute('data-nu-tab') || '' : ''; }
	if (tabs) tabs.addEventListener('shown.bs.tab', function () { fit(); editors.forEach(function (cm) { cm.refresh(); }); field.value = current(); });   // hidden editors have no size
	form.addEventListener('submit', function () { editors.forEach(function (cm) { cm.save(); }); field.value = current(); });
	field.value = current();
	// Templates (0.8.524, core/nucodetemplates.php): ▾ Templates per tab – a click inserts at the cursor; "Preview…" opens a dialogue
	// with all templates of the tab, description and code (Insert / Copy)
	var dataEl = document.getElementById('scodeTplData');
	if (dataEl) {
		var TPL = JSON.parse(dataEl.getAttribute('data-json') || '{}'), TT = JSON.parse(dataEl.getAttribute('data-t') || '{}');
		var dlg = document.getElementById('scodeTplDlg'), list = document.getElementById('scodeTplList'), dlgTab = null, dlgSel = null;
		var el = function (tag, cls, text) { var e = document.createElement(tag); if (cls) e.className = cls; if (text !== undefined) e.textContent = text; return e; };
		var insert = function (tab, code) {
			var cm = byTab[tab], ta = document.getElementById('scode-' + tab);
			var text = code.replace(/\s+$/, '') + '\n';
			if (cm) {
				var doc = cm.getDoc(), cur = doc.getCursor(), line = doc.getLine(cur.line);
				if (line.trim() !== '') text = '\n' + text;                                     // on its own lines
				if (doc.getValue().trim() !== '' && cur.line === doc.lastLine() && line.trim() !== '') text = '\n' + text;
				doc.replaceRange(text, cur);
				cm.focus();
			} else if (ta) {
				ta.value += (ta.value && !/\n$/.test(ta.value) ? '\n' : '') + text;
				ta.dispatchEvent(new Event('input', {bubbles: true}));
			}
			var tabBtn = document.getElementById('scodeTabBtn-' + tab);
			if (tabBtn && !tabBtn.classList.contains('active') && window.nuBs) nuBs.tab(tabBtn).show();
		};
		var groups = function (tab) {
			var g = {};
			(TPL.tabs[tab] || []).forEach(function (t) { (g[t.cat] = g[t.cat] || []).push(t); });
			return g;
		};
		var show = function (t) {
			dlgSel = t;
			document.getElementById('scodeTplName').textContent = t.title;
			document.getElementById('scodeTplDesc').textContent = t.desc;
			document.getElementById('scodeTplCode').textContent = t.code;
			Array.prototype.forEach.call(list.querySelectorAll('.list-group-item'), function (b) {
				var on = b.getAttribute('data-id') === t.id;
				b.classList.toggle('active', on); b.setAttribute('aria-selected', on ? 'true' : 'false');
			});
		};
		var openDlg = function (tab, first) {
			dlgTab = tab;
			var btn = document.getElementById('scodeTabBtn-' + tab);
			document.getElementById('scodeTplDlgTab').textContent = btn ? btn.textContent.trim() : tab;
			list.textContent = '';
			var g = groups(tab), pick = null;
			Object.keys(TPL.cats).forEach(function (c) {
				if (!g[c]) return;
				list.appendChild(el('div', 'list-group-item small fw-semibold text-body-secondary text-uppercase scodeTplCat', TPL.cats[c]));
				g[c].forEach(function (t) {
					var b = el('button', 'list-group-item list-group-item-action', t.title);
					b.type = 'button'; b.setAttribute('role', 'option'); b.setAttribute('data-id', t.id);
					b.addEventListener('click', function () { show(t); });
					list.appendChild(b);
					if (!pick || t.id === first) pick = t;
				});
			});
			if (pick) show(pick);
			nuBs.modal(dlg).show();
		};
		Array.prototype.forEach.call(form.querySelectorAll('.scodeTpl'), function (box) {
			var tab = box.getAttribute('data-tab'), menu = box.querySelector('.scodeTplMenu'), g = groups(tab);
			Object.keys(TPL.cats).forEach(function (c) {
				if (!g[c]) return;
				var h = el('li'); h.appendChild(el('h6', 'dropdown-header', TPL.cats[c])); menu.appendChild(h);
				g[c].forEach(function (t) {
					var li = el('li'), b = el('button', 'dropdown-item', t.title);
					b.type = 'button'; b.title = t.desc;
					b.addEventListener('click', function () { insert(tab, t.code); });
					li.appendChild(b); menu.appendChild(li);
				});
			});
			var sep = el('li'); sep.appendChild(el('hr', 'dropdown-divider')); menu.appendChild(sep);
			var pl = el('li'), pb = el('button', 'dropdown-item', TT.preview);
			pb.type = 'button';
			pb.addEventListener('click', function () { openDlg(tab); });
			pl.appendChild(pb); menu.appendChild(pl);
		});
		document.getElementById('scodeTplInsert').addEventListener('click', function () {
			if (!dlgSel) return;
			insert(dlgTab, dlgSel.code);
			nuBs.modal(dlg).hide();
		});
		var copyBtn = document.getElementById('scodeTplCopy');
		copyBtn.addEventListener('click', function () {
			if (!dlgSel || !navigator.clipboard) return;
			navigator.clipboard.writeText(dlgSel.code).then(function () {
				var old = copyBtn.innerHTML;
				copyBtn.textContent = TT.copied;
				setTimeout(function () { copyBtn.innerHTML = old; }, 1500);
			});
		});
	}

	// Global JS & CSS (0.8.523): the "Runs on" switches are shown on both global tabs – kept in step (the JS tab's ones are sent)
	Array.prototype.forEach.call(form.querySelectorAll('.scodeScopeSw'), function (sw) {
		sw.addEventListener('change', function () {
			Array.prototype.forEach.call(form.querySelectorAll('.scodeScopeSw[data-scope="' + sw.getAttribute('data-scope') + '"]'), function (o) {
				if (o !== sw && o.checked !== sw.checked) { o.checked = sw.checked; if (o.name) o.dispatchEvent(new Event('change', {bubbles: true})); }
			});
		});
	});
})();
