/* global nuBs */
/* Notification centre – the edit form (pages/nunotifications.php): Tom Select for the recipient fields, "Everybody" locks
   them, "Must confirm" forces the fixed list, and the live number of recipients (action count). */
(function () {
	'use strict';
	var form = document.getElementById('ntfForm');
	if (!form) return;
	var all = document.getElementById('ntf_all'), countBox = document.getElementById('ntfCount'), showBtn = document.getElementById('ntfCountShow');
	var dlg = document.getElementById('ntfPeopleDlg'), tbody = dlg ? dlg.querySelector('tbody') : null, sortCol = 0, sortAsc = true;
	var confirmSw = document.getElementById('ntf_confirm'), live = document.getElementById('ntf_mode_live'), fixed = document.getElementById('ntf_mode_fixed');
	var selects = Array.prototype.slice.call(form.querySelectorAll('select[data-ntf-ts]'));
	var ts = [], timer = 0, seq = 0, people = [];

	selects.forEach(function (s) {
		if (!window.TomSelect) return;
		var t = new TomSelect(s, {
			plugins: ['remove_button'], maxOptions: null, hidePlaceholder: true, closeAfterSelect: false, searchField: ['text', 'sub'],
			create: s.getAttribute('data-create') === '1', persist: false,
			render: {
				option: function (d, esc) { return '<div><div>' + esc(d.text) + '</div>' + (d.sub ? '<div class="small text-body-secondary">' + esc(d.sub) + '</div>' : '') + '</div>'; },
				item: function (d, esc) { return '<div title="' + esc(d.sub || '') + '">' + esc(d.text) + '</div>'; }
			}
		});
		t.on('change', schedule);
		ts.push(t);
	});

	// recipient builder: one row per field (.ntfRule[data-rule]), hidden while empty; "+ …" shows it, × empties and hides it
	var rulesBox = document.getElementById('ntfAudience'), addBox = document.getElementById('ntfAdd'), noneHint = document.getElementById('ntfRuleNone');
	function tsOf(key) { var s = document.getElementById('aud_' + key); return s && s.tomselect ? s.tomselect : null; }
	function syncRules() {
		if (!rulesBox) return;
		var on = !!(all && all.checked), any = false;
		rulesBox.querySelectorAll('.ntfRule').forEach(function (r) {
			var k = r.getAttribute('data-rule'), b = addBox ? addBox.querySelector('[data-rule="' + k + '"]') : null;
			if (b) b.hidden = !r.hidden || (on && k !== 'exclude');
			if (!r.hidden && k !== 'exclude') any = true;
			r.classList.toggle('ntfRuleOff', on && k !== 'exclude');
		});
		if (noneHint) noneHint.hidden = on || any;
	}
	if (addBox) addBox.addEventListener('click', function (e) {
		var b = e.target.closest('.ntfAddRule');
		if (!b) return;
		var r = rulesBox.querySelector('.ntfRule[data-rule="' + b.getAttribute('data-rule') + '"]');
		if (!r) return;
		r.hidden = false;
		syncRules();
		var t = tsOf(b.getAttribute('data-rule'));
		if (t) t.focus(); else { var s = r.querySelector('select'); if (s) s.focus(); }
	});
	if (rulesBox) rulesBox.addEventListener('click', function (e) {
		var x = e.target.closest('.ntfRuleX');
		if (!x) return;
		var k = x.getAttribute('data-rule-x'), r = x.closest('.ntfRule'), t = tsOf(k);
		if (t) t.clear(); else Array.prototype.forEach.call(r.querySelectorAll('option'), function (o) { o.selected = false; });
		r.hidden = true;
		syncRules();
		schedule();
		var b = addBox && addBox.querySelector('[data-rule="' + k + '"]');
		if (b) b.focus();
	});

	// summary in the bottom bar: "24 users · now · until … · Must confirm"
	var sumBox = document.getElementById('ntfSum'), lastN = null;
	function when(v) {
		if (!v) return '';
		var d = new Date(v);
		return isNaN(d) ? v : d.toLocaleString(document.documentElement.lang || undefined, {dateStyle: 'medium', timeStyle: 'short'});
	}
	function fill1(t, x) { return String(t).replace(/%[sd]/, x); }
	var sideHook = null;                                   // the side column of wide screens
	function summary() {
		if (sideHook) sideHook();
		if (!sumBox) return;
		var parts = [], pub = document.getElementById('ntf_pub'), exp = document.getElementById('ntf_exp');
		if (lastN !== null) parts.push(fill1(sumBox.getAttribute('data-users'), lastN));
		parts.push(pub && pub.value ? fill1(sumBox.getAttribute('data-from'), when(pub.value)) : sumBox.getAttribute('data-now'));
		if (exp && exp.value) parts.push(fill1(sumBox.getAttribute('data-until'), when(exp.value)));
		if (confirmSw && confirmSw.checked) parts.push(sumBox.getAttribute('data-confirm'));
		sumBox.textContent = parts.join(' · ');
	}
	['ntf_pub', 'ntf_exp'].forEach(function (id) { var el = document.getElementById(id); if (el) el.addEventListener('change', summary); });

	function lockAudience() {
		syncRules();
		var on = !!(all && all.checked);
		// "Exclude users" stays open with "Everybody"
		ts.forEach(function (t) { if (on && !t.input.hasAttribute('data-ntf-exclude')) t.disable(); else t.enable(); });
		if (!ts.length) selects.forEach(function (s) { s.disabled = on && !s.hasAttribute('data-ntf-exclude'); });
	}
	var modeLocked = !live || live.disabled;               // published: the mode stays
	function lockMode() {
		if (modeLocked || !confirmSw) return;
		if (confirmSw.checked) { fixed.checked = true; live.disabled = true; } else live.disabled = false;
	}

	function count() {
		var fd = new FormData(form);
		fd.set('action', 'count');
		var my = ++seq;
		fetch(countBox.getAttribute('data-url'), {method: 'POST', body: fd, credentials: 'same-origin', headers: {'Accept': 'application/json'}})
			.then(function (r) { return r.json(); })
			.then(function (j) {
				if (my !== seq || !j.ok) return;
				countBox.textContent = j.text;
				lastN = j.n;
				summary();
				countBox.className = 'ntfReach ' + (j.n ? 'ntfReachSome' : 'ntfReachNone');
				people = j.people || [];
				showBtn.hidden = !people.length;
				if (sideHook) sideHook();                      // names of the side column
				if (dlg && dlg.classList.contains('show')) fill();
			}).catch(function () { /* keep */ });
	}
	function schedule() { window.clearTimeout(timer); timer = window.setTimeout(count, 250); }

	if (all) all.addEventListener('change', function () { lockAudience(); schedule(); });
	if (confirmSw) confirmSw.addEventListener('change', function () { lockMode(); summary(); });
	// recipients in a dialogue: full name, login name, department, team, position; a click on a column title sorts by it
	var coll = window.Intl && Intl.Collator ? new Intl.Collator(undefined, {numeric: true, sensitivity: 'base'}) : null;
	function fill() {
		if (!tbody) return;
		var rows = people.slice().sort(function (a, b) {
			var x = String(a[sortCol] || ''), y = String(b[sortCol] || ''), r;
			if (x === '' || y === '') r = x === y ? 0 : (x === '' ? 1 : -1);          // empty cells last, both ways
			else r = (coll ? coll.compare(x, y) : (x < y ? -1 : x > y ? 1 : 0)) * (sortAsc ? 1 : -1);
			return r || (coll ? coll.compare(String(a[0]), String(b[0])) : 0);
		});
		tbody.textContent = '';
		rows.forEach(function (p) {
			var tr = document.createElement('tr');
			p.forEach(function (v, i) { var td = document.createElement('td'); td.textContent = v; td.className = i === 0 ? 'fw-semibold' : 'small'; tr.appendChild(td); });
			tbody.appendChild(tr);
		});
		document.getElementById('ntfPeopleN').textContent = String(people.length);
		dlg.querySelectorAll('.nuNtfSort').forEach(function (b) {
			var on = +b.getAttribute('data-col') === sortCol;
			b.closest('th')[on ? 'setAttribute' : 'removeAttribute']('aria-sort', sortAsc ? 'ascending' : 'descending');
			b.querySelector('i').className = 'bi ms-1' + (on ? (sortAsc ? ' bi-caret-up-fill' : ' bi-caret-down-fill') : '');
		});
	}
	if (dlg) dlg.querySelectorAll('.nuNtfSort').forEach(function (b) {
		b.addEventListener('click', function () {
			var c = +b.getAttribute('data-col');
			sortAsc = c === sortCol ? !sortAsc : true;
			sortCol = c;
			fill();
		});
	});
	showBtn.addEventListener('click', function () { if (!dlg) return; fill(); nuBs.modal(dlg).show(); });
	// side column of wide screens: preview of the notification as the bell shows it, the summary, a few names
	var side = document.getElementById('ntfSide');
	function side_() {
		if (!side) return;
		var d = function (k) { return side.getAttribute('data-' + k) || ''; }, icons = {};
		try { icons = JSON.parse(d('icons')) || {}; } catch (x) { /* keep */ }
		var lv = (form.querySelector('input[name="level"]:checked') || {}).value || 'info';
		var pv = document.getElementById('ntfPv'), title = document.getElementById('ntf_title'), body = document.getElementById('ntf_body');
		pv.className = 'ntfPv ntfPv-' + lv;
		document.getElementById('ntfPvIcon').className = 'bi ' + (icons[lv] || 'bi-info-circle') + ' ntfPvIcon';
		var t = title && title.value.trim();
		document.getElementById('ntfPvTitle').textContent = t || d('empty');
		document.getElementById('ntfPvTitle').classList.toggle('ntfPvNone', !t);
		var txt = body ? body.value : '';
		var fmt = document.getElementById('ntf_format');
		if (fmt && fmt.checked) {                                   // formatted text: shown as plain text (never parsed into this page)
			try { txt = new DOMParser().parseFromString(txt, 'text/html').body.textContent || ''; } catch (x) { txt = ''; }
		}
		txt = txt.replace(/\s+/g, ' ').trim();
		document.getElementById('ntfPvBody').textContent = txt.length > 400 ? txt.slice(0, 400) + '…' : txt;
		var pub = document.getElementById('ntf_pub'), exp = document.getElementById('ntf_exp');
		var conf = !!(confirmSw && confirmSw.checked), pin = document.getElementById('ntf_pinned');
		var from = pub && pub.value ? when(pub.value) : d('now');
		document.getElementById('ntfPvMeta').textContent = from + (conf ? ' · ' + d('confirm') : '');
		document.getElementById('ntfSsN').textContent = lastN === null ? '…' : fill1(d('users'), lastN);
		document.getElementById('ntfSsFrom').textContent = from;
		document.getElementById('ntfSsUntil').textContent = exp && exp.value ? when(exp.value) : d('no-end');
		document.getElementById('ntfSsConfirm').textContent = conf ? d('yes') : d('no');
		document.getElementById('ntfSsPinned').textContent = pin && pin.checked ? d('yes') : d('no');
		var names = people.slice(0, 4).map(function (p) { return p[0]; }), rest = people.length - names.length;
		document.getElementById('ntfSsNames').textContent = names.join(' · ') + (rest > 0 ? ' · ' + fill1(d('more'), rest) : '');
		document.getElementById('ntfSsShow').hidden = !people.length;
	}
	if (side) {
		form.addEventListener('input', side_);
		form.addEventListener('change', side_);
		document.getElementById('ntfSsShow').addEventListener('click', function () { showBtn.click(); });
		sideHook = side_;                                       // the count answer and the date fields call summary()
		side_();
	}
	lockAudience();
	lockMode();
	summary();
	count();

	// ---- formatted text: the switch turns the text field into a TinyMCE editor (when installed); the server cleans the HTML
	var fmt = document.getElementById('ntf_format'), body = document.getElementById('ntf_body'), noTiny = document.getElementById('ntfNoTiny');
	var editor = null, loading = null;
	function esc(s) { return NuUtil.esc(s); }
	function textToHtml(t) {
		t = String(t || '').replace(/\r\n?/g, '\n').trim();
		if (!t) return '';
		return t.split(/\n{2,}/).map(function (p) { return '<p>' + esc(p).replace(/\n/g, '<br>') + '</p>'; }).join('');
	}
	function htmlToText(h) {
		var doc = new DOMParser().parseFromString('<div>' + String(h || '') + '</div>', 'text/html');   // parsed, never run
		var out = '';
		(function walk(n) {
			Array.prototype.forEach.call(n.childNodes, function (c) {
				if (c.nodeType === 3) { out += c.nodeValue; return; }
				if (c.nodeType !== 1) return;
				var tg = c.tagName.toLowerCase();
				if (tg === 'br') { out += '\n'; return; }
				if (tg === 'li') out += '• ';
				walk(c);
				if (/^(p|li|h[1-6]|blockquote|div|ul|ol)$/.test(tg)) out += '\n';
			});
		})(doc.body);
		return out.replace(/[ \t]+\n/g, '\n').replace(/\n{3,}/g, '\n\n').trim();
	}
	function changed() { body.dispatchEvent(new Event('input', {bubbles: true})); body.dispatchEvent(new Event('change', {bubbles: true})); }
	function loadTiny() {
		if (window.tinymce) return Promise.resolve();
		if (loading) return loading;
		loading = new Promise(function (ok, fail) {
			var sc = document.createElement('script');
			sc.src = fmt.getAttribute('data-tiny');
			sc.onload = ok; sc.onerror = fail;
			document.head.appendChild(sc);
		});
		return loading;
	}
	// the HTML source stays hidden (class nuTmceWait, set by the page) until TinyMCE is there or cannot come
	function shown() { if (body) body.classList.remove('nuTmceWait'); }
	setTimeout(shown, 10000);
	function startEditor() {
		if (!fmt.getAttribute('data-tiny')) { shown(); if (noTiny) noTiny.classList.remove('d-none'); return; }
		loadTiny().then(function () {
			if (!fmt.checked || editor) { shown(); return; }
			var dark = document.documentElement.getAttribute('data-bs-theme') === 'dark';
			window.tinymce.init({
				target: body, base_url: fmt.getAttribute('data-base'), suffix: '.min', license_key: 'gpl', promotion: false, branding: false,
				plugins: 'lists link autolink', menubar: false, statusbar: false, height: 260, toolbar_mode: 'wrap',
				toolbar: 'bold italic underline strikethrough | bullist numlist | link blockquote | removeformat | undo redo',
				valid_elements: 'p,br,strong/b,em/i,u,s,strike,ul,ol,li,a[href|target|rel],blockquote,h4,h5,h6,hr',
				link_default_target: '_blank', link_target_list: false, link_title: false, convert_urls: false, relative_urls: false,
				skin: dark ? 'oxide-dark' : 'oxide', content_css: dark ? 'dark' : 'default',
				setup: function (ed) {
					editor = ed;
					ed.on('init', shown);
					ed.on('input change undo redo', function () { ed.save(); changed(); });
				}
			});
		}).catch(function () { shown(); if (noTiny) noTiny.classList.remove('d-none'); });
	}
	function stopEditor() {
		if (editor) { editor.save(); editor.remove(); editor = null; }
		shown();                                                  // after remove(): TinyMCE puts the class back
	}
	// light / dark switch: TinyMCE cannot change its skin while it runs – started again with the other skin, the text kept
	new MutationObserver(function () {
		if (!editor) return;
		editor.save(); editor.remove(); editor = null;
		body.classList.add('nuTmceWait');
		startEditor();
	}).observe(document.documentElement, {attributes: true, attributeFilter: ['data-bs-theme']});

	if (fmt && body) {
		if (fmt.checked) startEditor();
		fmt.addEventListener('change', function () {
			if (fmt.checked) {
				body.removeAttribute('maxlength');
				body.value = textToHtml(body.value);
				startEditor();
			} else {
				if (editor) editor.save();
				if (body.value.trim() && !window.confirm(fmt.getAttribute('data-confirm-text'))) { fmt.checked = true; return; }
				stopEditor();
				body.value = htmlToText(body.value);
				body.setAttribute('maxlength', '4000');
				if (noTiny) noTiny.classList.add('d-none');
			}
			changed();
		});
		form.addEventListener('submit', function () { if (editor) editor.save(); });
	}
})();
