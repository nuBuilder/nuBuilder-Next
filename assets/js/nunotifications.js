/* Notification centre – the edit form (0.8.357, pages/nunotifications.php): Tom Select for the recipient fields, "Everybody" locks
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

	function lockAudience() {
		var on = !!(all && all.checked);
		// "Exclude users" stays open with "Everybody" (0.8.510)
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
				countBox.className = 'alert py-2 mb-0 small ' + (j.n ? 'alert-info' : 'alert-warning');
				people = j.people || [];
				showBtn.hidden = !people.length;
				if (dlg && dlg.classList.contains('show')) fill();
			}).catch(function () { /* keep */ });
	}
	function schedule() { window.clearTimeout(timer); timer = window.setTimeout(count, 250); }

	if (all) all.addEventListener('change', function () { lockAudience(); schedule(); });
	if (confirmSw) confirmSw.addEventListener('change', lockMode);
	// recipients in a dialogue (0.8.510): full name, login name, department, team, position; a click on a column title sorts by it
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
	lockAudience();
	lockMode();
	count();

	// ---- formatted text (0.8.400): the switch turns the text field into a TinyMCE editor (when installed); the server cleans the HTML
	var fmt = document.getElementById('ntf_format'), body = document.getElementById('ntf_body'), noTiny = document.getElementById('ntfNoTiny');
	var editor = null, loading = null;
	function esc(t) { return String(t).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
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
	function startEditor() {
		if (!fmt.getAttribute('data-tiny')) { if (noTiny) noTiny.classList.remove('d-none'); return; }
		loadTiny().then(function () {
			if (!fmt.checked || editor) return;
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
					ed.on('input change undo redo', function () { ed.save(); changed(); });
				}
			});
		}).catch(function () { if (noTiny) noTiny.classList.remove('d-none'); });
	}
	function stopEditor() {
		if (editor) { editor.save(); editor.remove(); editor = null; }
	}
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
