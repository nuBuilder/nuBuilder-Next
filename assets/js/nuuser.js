/* Access level: the "open in new tab" button follows the chosen level (disabled while none is chosen). */
(function () {
	'use strict';
	var sel = document.getElementById('sus_zzzzsys_access_id'), link = document.getElementById('accessOpen');
	if (!sel || !link) return;
	function sync() {
		var id = sel.value, off = id === '';
		link.href = off ? '#' : link.getAttribute('data-url').replace('__ID__', encodeURIComponent(id));
		link.classList.toggle('disabled', off);
		if (off) { link.setAttribute('aria-disabled', 'true'); link.setAttribute('tabindex', '-1'); }
		else { link.removeAttribute('aria-disabled'); link.removeAttribute('tabindex'); }
	}
	sel.addEventListener('change', sync);
	sync();
})();

/* User form tabs General / Permission items (0.8.438): the open tab goes into the address (?tab=perm) and into the form, so a
   reload and the page after Save show the same tab; a field the browser finds invalid opens its tab. */
(function () {
	'use strict';
	var tabs = document.getElementById('userTabs'), field = document.getElementById('userTab');
	if (!tabs || !field) return;
	tabs.addEventListener('shown.bs.tab', function (e) {
		var v = e.target.getAttribute('data-nu-tab') || '';
		field.value = v;
		try {
			var u = new URL(window.location.href);
			if (v) u.searchParams.set('tab', v); else u.searchParams.delete('tab');
			window.history.replaceState(window.history.state, '', u.toString());
		} catch (x) { /* old browser: the address stays */ }
	});
	var form = field.form;
	if (form) form.addEventListener('invalid', function (e) {
		var pane = e.target.closest && e.target.closest('.tab-pane');
		if (!pane || pane.classList.contains('active')) return;
		var btn = tabs.querySelector('[data-bs-target="#' + pane.id + '"]');
		if (btn) nuBs.tab(btn).show();
	}, true);
})();

/* User form: Full name ↔ first / last name, password generator, permission items. */
(function () {
	'use strict';
	var box = document.getElementById('userPerms');
	if (!box) return;
	var filter = document.getElementById('userPermFilter'), count = document.getElementById('userPermCount'), empty = document.getElementById('userPermEmpty');
	var items = Array.prototype.slice.call(box.querySelectorAll('.userPermItem'));

	var tabCount = document.getElementById('userPermTabCount');
	function update() {
		count.textContent = box.querySelectorAll('input[name="perm[]"]:checked').length;
		if (tabCount) tabCount.textContent = count.textContent;
	}
	var group = document.getElementById('userPermGroup');   // group filter (0.8.260) – combined with the search
	// assigned / all / not assigned (0.8.642): taken when the filter changes – a box ticked or cleared afterwards stays in the list
	var state = document.getElementById('userPermState');
	function apply() {
		var q = (filter.value || '').toLowerCase().trim(), g = group ? group.value : '*', st = state ? state.value : 'all', shown = 0;
		items.forEach(function (it) {
			var cb = it.querySelector('input[name="perm[]"]'), sel = !!(cb && cb.checked);
			var on = (q === '' || it.getAttribute('data-search').indexOf(q) !== -1) && (g === '*' || it.getAttribute('data-group') === g)
				&& (st === 'all' || (st === 'assigned') === sel);
			it.classList.toggle('d-none', !on);
			if (on) shown++;
		});
		var table = box.querySelector('.userPermTable');
		if (table) table.classList.toggle('d-none', shown === 0);
		empty.classList.toggle('d-none', shown > 0);
		empty.textContent = st === 'assigned' && q === '' && g === '*' ? empty.getAttribute('data-none-assigned') : empty.getAttribute('data-none');
	}
	if (state) {
		state.addEventListener('change', apply);
		state.value = 'assigned';   // the record opens on "Assigned" (0.8.644) – also when the browser restores the last choice (reload, back button)
		apply();
		window.addEventListener('pageshow', function (e) { if (e.persisted) { state.value = 'assigned'; apply(); } });
	}
	if (filter) {
		filter.addEventListener('input', apply);
		filter.addEventListener('keydown', function (e) { if (e.key === 'Enter') e.preventDefault(); });   // do not submit the user form
	}
	if (group) group.addEventListener('change', apply);
	// sortable columns (0.8.611): ident, group, description, or the selected items first; ties by group and ident; kept per browser
	var SORT_KEY = 'nunext-user-perm-sort', tbody = box.querySelector('.userPermTable tbody');
	function keyOf(it, col) {
		if (col === 'sel') return it.querySelector('input[name="perm[]"]').checked ? '0' : '1';
		if (col === 'ident') return it.querySelector('.userPermIdent label').textContent;
		if (col === 'group') return it.getAttribute('data-group');
		var d = it.querySelector('.userPermDescCell');
		return d ? d.textContent.trim() : '';
	}
	function sortBy(col, dir) {
		if (!tbody) return;
		var th = box.querySelector('th.userPermSort[data-sort="' + col + '"]');
		if (!th) return;
		var cmp = function (a, b) { return a.localeCompare(b, undefined, {sensitivity: 'base', numeric: true}); };
		var sorted = items.slice().sort(function (a, b) {
			var r = cmp(keyOf(a, col), keyOf(b, col)) * (dir === 'desc' ? -1 : 1);
			return r || cmp(keyOf(a, 'group'), keyOf(b, 'group')) || cmp(keyOf(a, 'ident'), keyOf(b, 'ident'));
		});
		sorted.forEach(function (it) { tbody.appendChild(it); });
		box.querySelectorAll('th.userPermSort').forEach(function (o) { o.removeAttribute('aria-sort'); });
		th.setAttribute('aria-sort', dir === 'desc' ? 'descending' : 'ascending');
	}
	box.querySelectorAll('th.userPermSort').forEach(function (th) {
		th.querySelector('button').addEventListener('click', function () {
			var col = th.getAttribute('data-sort'), dir = th.getAttribute('aria-sort') === 'ascending' ? 'desc' : 'asc';
			sortBy(col, dir);
			try { localStorage.setItem(SORT_KEY, col + ':' + dir); } catch (e) { /* no storage */ }
		});
	});
	try {
		var keep = (localStorage.getItem(SORT_KEY) || '').split(':');
		if (keep[0] && !(keep[0] === 'group' && keep[1] === 'asc')) sortBy(keep[0], keep[1] === 'desc' ? 'desc' : 'asc');
	} catch (e) { /* no storage */ }
	// Undo of All / None (0.8.584): the states before the click; a change by hand afterwards ends it
	var undoBtn = document.getElementById('userPermUndo'), before = null, bulk = false;
	function setUndo(snap) { before = snap; if (undoBtn) undoBtn.classList.toggle('d-none', !snap); }
	box.addEventListener('change', function (e) {
		if (e.target.name !== 'perm[]') return;
		update();
		if (!bulk && before) setUndo(null);
	});
	if (undoBtn) undoBtn.addEventListener('click', function () {
		var snap = before;
		if (!snap) return;
		bulk = true;
		snap.forEach(function (x) { if (x[0].checked !== x[1]) { x[0].checked = x[1]; x[0].dispatchEvent(new Event('change', {bubbles: true})); } });
		bulk = false;
		update();
		setUndo(null);
	});
	box.addEventListener('click', function (e) {   // table (0.8.458): a click on the row toggles its checkbox (not on the box / label themselves)
		var row = e.target.closest('.userPermItem');
		if (!row || e.target.closest('input, label, a, button')) return;
		var cb = row.querySelector('input[name="perm[]"]');
		if (cb) { cb.checked = !cb.checked; cb.dispatchEvent(new Event('change', {bubbles: true})); }
	});
	var CONFIRM_ABOVE = 5;   // "All" asks first when more than this many items are shown
	box.querySelectorAll('[data-perm-all]').forEach(function (b) {
		b.addEventListener('click', function () {
			var on = b.getAttribute('data-perm-all') === '1';
			var shown = items.filter(function (it) { return !it.classList.contains('d-none'); });
			function run() {
				var snap = Array.prototype.map.call(box.querySelectorAll('input[name="perm[]"]'), function (cb) { return [cb, cb.checked]; }), changed = 0;
				bulk = true;
				shown.forEach(function (it) {
					var cb = it.querySelector('input[type=checkbox]');
					if (cb && cb.checked !== on) { cb.checked = on; cb.dispatchEvent(new Event('change', {bubbles: true})); changed++; }
				});
				bulk = false;
				update();
				if (changed) setUndo(snap);
			}
			var ask = b.getAttribute('data-perm-confirm');
			if (on && ask && shown.length > CONFIRM_ABOVE && typeof window.nuConfirmAsk === 'function') {
				window.nuConfirmAsk(ask.replace('%d', String(shown.length)), run, b.getAttribute('data-perm-confirm-ok') || '', 'btn btn-primary');
			} else run();
		});
	});

	// Copy from user… (0.8.649): the chosen user's active items (POST action "perm_of", nothing saved) are ticked here – Replace = exactly
	// theirs, Add = the ticked ones stay; saved with Save, Undo as after All / None
	var cdlg = document.getElementById('userPermCopyDlg');
	if (cdlg) {
		var CT = {};
		try { CT = JSON.parse(cdlg.getAttribute('data-t') || '{}'); } catch (e) { /* defaults */ }
		var csel = document.getElementById('userPermCopyFrom'), cinfo = document.getElementById('userPermCopyInfo');
		var cAdd = document.getElementById('userPermCopyAdd'), cRep = document.getElementById('userPermCopyReplace'), cItems = null;
		var boxes = function () { return Array.prototype.slice.call(box.querySelectorAll('input[name="perm[]"]')); };
		var ready = function (on) { cAdd.disabled = !on; cRep.disabled = !on; };
		var cload = function () {
			cItems = null;
			ready(false);
			cinfo.textContent = '';
			var from = csel.value;
			if (!from) return;
			var fd = new FormData();
			fd.append('_csrf', cdlg.getAttribute('data-csrf') || '');
			fd.append('action', 'perm_of');
			fd.append('from', from);
			fetch(cdlg.getAttribute('data-url'), {method: 'POST', body: fd, credentials: 'same-origin', headers: {'Accept': 'application/json'}})
				.then(function (r) { return r.json(); })
				.then(function (j) {
					if (csel.value !== from) return;                      // another user was chosen meanwhile
					if (!j || !j.ok || !Array.isArray(j.items)) throw new Error('x');
					var here = {};
					boxes().forEach(function (cb) { here[cb.value] = cb; });
					cItems = j.items.filter(function (id) { return here[id]; });
					var fresh = cItems.filter(function (id) { return !here[id].checked; }).length;
					cinfo.textContent = (CT.info || '%d / %d').replace('%d', String(cItems.length)).replace('%d', String(fresh));
					ready(true);
				})
				.catch(function () { if (csel.value === from) cinfo.textContent = CT.error || ''; });
		};
		csel.addEventListener('change', cload);
		cdlg.addEventListener('show.bs.modal', function () {
			if (csel.tomselect) csel.tomselect.clear(true); else csel.value = '';
			cload();
		});
		cdlg.addEventListener('shown.bs.modal', function () { if (csel.tomselect) csel.tomselect.focus(); else csel.focus(); });
		var capply = function (replace) {
			if (!cItems) return;
			var want = {}, all = boxes(), changed = 0;
			cItems.forEach(function (id) { want[id] = true; });
			var snap = all.map(function (cb) { return [cb, cb.checked]; });
			bulk = true;
			all.forEach(function (cb) {
				var on = want[cb.value] ? true : (replace ? false : cb.checked);
				if (cb.checked !== on) { cb.checked = on; cb.dispatchEvent(new Event('change', {bubbles: true})); changed++; }
			});
			bulk = false;
			update();
			apply();                                                     // the list shows the result (also with "Assigned")
			if (changed) setUndo(snap);
			var m = window.nuBs && nuBs.modal(cdlg);
			if (m) m.hide();
		};
		cAdd.addEventListener('click', function () { capply(false); });
		cRep.addEventListener('click', function () { capply(true); });
	}

	// phones: descriptions on / off (compact table); the choice stays in this browser
	var descToggle = document.getElementById('userPermDescToggle'), DESC_KEY = 'nunext-perm-desc';
	if (descToggle) {
		try { if (localStorage.getItem(DESC_KEY) === '0') descToggle.checked = false; } catch (e) { /* no storage */ }
		var setDesc = function () { box.classList.toggle('userPermNoDesc', !descToggle.checked); };
		setDesc();
		descToggle.addEventListener('change', function () {
			setDesc();
			try { localStorage.setItem(DESC_KEY, descToggle.checked ? '1' : '0'); } catch (e) { /* no storage */ }
		});
	}
})();

/*
 * Full name (sus_name) ↔ first name / last name, on change:
 * - Full name empty (or built by this script) → built from first + last name (whatever is filled).
 * - New user: changing the full name fills first and last name – unless they were typed by hand.
 * - Existing user: first / last name are only replaced on request: type * into one of them.
 *   "Anna Maria Muster" → first "Anna Maria", last "Muster"; "Muster, Anna" → first "Anna", last "Muster".
 */
(function () {
	'use strict';
	var form = document.getElementById('nuRecordForm');
	var full = document.getElementById('sus_name'), first = document.getElementById('sus_first_name'), last = document.getElementById('sus_last_name');
	if (!form || !full || !first || !last || full.disabled) return;
	var isNew = form.getAttribute('data-nu-new') === '1';
	var autoVal = new Map();                          // field → value this script put there (still "automatic" while unchanged)

	function clean(s) { return String(s || '').replace(/\s+/g, ' ').trim(); }
	function isAuto(el) { return autoVal.has(el) && el.value === autoVal.get(el); }
	function set(el, value) {
		autoVal.set(el, value);
		if (el.value === value) return;
		el.value = value;
		el.classList.add('nuEdited');
		el.dispatchEvent(new Event('input', {bubbles: true}));   // marks the form as changed (Save turns red)
	}
	function buildFull() {
		var joined = clean(clean(first.value) + ' ' + clean(last.value));
		if (joined !== '') set(full, joined);
	}

	/** "First Middle Last" → {firstName: 'First Middle', lastName: 'Last'}; "Last, First" → {firstName: 'First', lastName: 'Last'}. */
	window.nuUserSplitFullName = function (fullName) {
		var name = clean(fullName);
		if (name.indexOf(',') !== -1) {
			var parts = name.split(',');
			return {firstName: clean(parts.slice(1).join(' ')), lastName: clean(parts[0])};
		}
		var words = name === '' ? [] : name.split(' ');
		if (words.length > 1) return {lastName: words.pop(), firstName: words.join(' ')};
		return {firstName: name, lastName: ''};
	};

	window.nuUserSetNameParts = function (element) {
		element = element || full;
		if (element === full && full.value !== clean(full.value)) full.value = clean(full.value);   // single spaces, no spaces around
		if (element !== full && isAuto(element)) return;     // late change event for a value this script set
		var hasStar = element !== full && clean(element.value) === '*';

		if (element !== full && !hasStar) {                  // first / last name typed by hand
			if (clean(full.value) === '' || isAuto(full)) buildFull();
			return;
		}
		if (clean(full.value) === '') {                      // * with an empty full name: nothing to split
			if (hasStar) { element.value = ''; buildFull(); }
			return;
		}
		if (!isNew && !hasStar) return;                      // existing user: parts only on request (*)

		var parts = window.nuUserSplitFullName(full.value);
		if (hasStar || clean(first.value) === '' || isAuto(first)) set(first, parts.firstName);
		if (hasStar || clean(last.value) === '' || isAuto(last)) set(last, parts.lastName);
	};

	[full, first, last].forEach(function (el) {
		el.addEventListener('change', function () { window.nuUserSetNameParts(el); });
	});
})();

/*
 * Password generator (modal #nuPwGen): crypto.getRandomValues without modulo bias, at least one character of every chosen set,
 * shuffled; strength = length × log2(alphabet). "Use password" fills password + confirmation (and copies it when possible).
 * Settings are remembered per browser.
 */
(function () {
	'use strict';
	var modal = document.getElementById('nuPwGen');
	if (!modal || !window.crypto || !window.crypto.getRandomValues) return;
	var $id = function (id) { return document.getElementById(id); };
	var out = $id('pwgOut'), range = $id('pwgLenRange'), num = $id('pwgLen'), amb = $id('pwg_ambiguous');
	var sets = Array.prototype.slice.call(modal.querySelectorAll('.pwgSet'));
	var MIN = +modal.dataset.min || 8, MAX = +modal.dataset.max || 64, KEY = 'nunext.pwgen';
	var labels = JSON.parse(modal.dataset.strength || '[]');
	var shownLen = 0;                                 // length of the password on display
	var CHARS = {
		upper: 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', lower: 'abcdefghijklmnopqrstuvwxyz', digits: '0123456789',
		symbols: '!#$%&()*+,-./:;=?@[]^_{|}~'          // no quotes, backslash, space or < > – safe to paste anywhere
	};
	var AMBIGUOUS = /[0Oo1lI|]/g;

	function randomInt(n) {                            // uniform 0 … n-1 (rejection sampling, no modulo bias)
		var buf = new Uint32Array(1), limit = Math.floor(0x100000000 / n) * n;
		do { crypto.getRandomValues(buf); } while (buf[0] >= limit);
		return buf[0] % n;
	}
	function alphabets() {
		return sets.filter(function (c) { return c.checked; }).map(function (c) {
			var s = CHARS[c.dataset.set];
			return amb.checked ? s.replace(AMBIGUOUS, '') : s;
		});
	}
	function generate() {
		var groups = alphabets(), len = clampLen(num.value), all = groups.join(''), chars = [];
		groups.forEach(function (g) { chars.push(g[randomInt(g.length)]); });          // one of each chosen set
		while (chars.length < len) chars.push(all[randomInt(all.length)]);
		for (var i = chars.length - 1; i > 0; i--) { var j = randomInt(i + 1), t = chars[i]; chars[i] = chars[j]; chars[j] = t; }
		out.value = chars.join('');
		shownLen = len;
		strength(len, all.length);
		$id('pwgCopied').textContent = '';
	}
	function strength(len, pool) {
		var bits = Math.round(len * Math.log2(pool)), lvl = bits < 50 ? 0 : bits < 80 ? 1 : bits < 110 ? 2 : 3;
		var bar = $id('pwgStrengthBar');
		bar.style.width = Math.min(100, bits / 1.3) + '%';
		bar.className = 'progress-bar ' + ['bg-danger', 'bg-warning', 'bg-success', 'bg-success'][lvl];
		$id('pwgStrengthText').textContent = (labels[lvl] || '') + ' · ' + (modal.dataset.bits || '%d').replace('%d', bits);
	}
	function clampLen(v) { v = parseInt(v, 10); return isNaN(v) ? MIN : Math.max(MIN, Math.min(MAX, v)); }
	function save() {
		try {
			var o = {len: clampLen(num.value), amb: amb.checked};
			sets.forEach(function (c) { o[c.dataset.set] = c.checked; });
			localStorage.setItem(KEY, JSON.stringify(o));
		} catch (x) {}
	}
	function load() {
		var o = null;
		try { o = JSON.parse(localStorage.getItem(KEY) || 'null'); } catch (x) {}
		var len = clampLen(o && o.len ? o.len : modal.dataset.default);
		num.value = range.value = len;
		if (o) {
			if (typeof o.amb === 'boolean') amb.checked = o.amb;
			sets.forEach(function (c) { if (typeof o[c.dataset.set] === 'boolean') c.checked = o[c.dataset.set]; });
			if (!sets.some(function (c) { return c.checked; })) sets.forEach(function (c) { c.checked = true; });
		}
		lockLast();
	}
	function lockLast() {                              // at least one character set stays on
		var on = sets.filter(function (c) { return c.checked; });
		sets.forEach(function (c) { c.disabled = on.length === 1 && c.checked; });
	}
	function copy(text) {
		if (navigator.clipboard && window.isSecureContext) return navigator.clipboard.writeText(text).then(function () { return true; }, function () { return false; });
		out.select();
		var ok = false;
		try { ok = document.execCommand('copy'); } catch (x) {}
		return Promise.resolve(ok);
	}

	range.addEventListener('input', function () { num.value = range.value; generate(); });
	// typed length: new password right away when it is valid; on leaving the field only if the length really changed
	// (so clicking "Use password" straight after typing inserts the password that is shown)
	num.addEventListener('input', function () {
		var v = parseInt(num.value, 10);
		if (!isNaN(v) && v >= MIN && v <= MAX && v !== shownLen) { range.value = v; generate(); }
	});
	num.addEventListener('change', function () {
		num.value = range.value = clampLen(num.value);
		if (clampLen(num.value) !== shownLen) generate();
		save();
	});
	range.addEventListener('change', save);
	sets.concat([amb]).forEach(function (c) { c.addEventListener('change', function () { lockLast(); generate(); save(); }); });
	$id('pwgNew').addEventListener('click', generate);
	$id('pwgCopy').addEventListener('click', function () {
		copy(out.value).then(function (ok) { $id('pwgCopied').textContent = ok ? modal.dataset.copied : ''; });
	});
	out.addEventListener('focus', function () { out.select(); });
	modal.addEventListener('show.bs.modal', function () { load(); generate(); });
	modal.addEventListener('shown.bs.modal', function () { $id('pwgUse').focus(); });

	$id('pwgUse').addEventListener('click', function () {
		var pw = out.value;
		['password', 'password_confirm'].forEach(function (id) {
			var el = $id(id);
			if (!el) return;
			el.value = pw;
			el.classList.remove('is-invalid');
			el.dispatchEvent(new Event('input', {bubbles: true}));          // Save turns red
		});
		copy(pw).then(function (ok) {
			var hint = $id('passwordHint');
			if (hint) { hint.textContent = ok ? modal.dataset.usedCopied : modal.dataset.used; hint.classList.add('text-success'); hint.classList.remove('d-none'); }
		});
		if (nuBs.ready()) nuBs.modal(modal).hide();
	});
})();

/* Auto-login link (globeadmin, $nuConfigAutoLogin): copy button. */
(function () {
	'use strict';
	var b = document.getElementById('autoLoginCopy'), f = document.getElementById('autoLoginUrl'), days = document.getElementById('autoLoginDays');
	if (!b || !f) return;
	if (days) days.addEventListener('change', function () {                     // validity: each option carries its own signed link
		var o = days.options[days.selectedIndex];
		if (o && o.getAttribute('data-link')) f.value = o.getAttribute('data-link');
	});
	f.addEventListener('focus', function () { f.select(); });
	b.addEventListener('click', function () {
		var done = function () { var i = b.querySelector('i'); i.className = 'bi bi-check2'; setTimeout(function () { i.className = 'bi bi-clipboard'; }, 1500); };
		if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(f.value).then(done, function () { f.select(); });
		else { f.select(); try { document.execCommand('copy'); done(); } catch (e) { /* ignore */ } }
	});
})();

/* One-time login link (nuBuilder's LOGIN_TOKEN, 0.8.339): copy button. */
(function () {
	'use strict';
	var b = document.getElementById('loginTokenCopy'), f = document.getElementById('loginTokenUrl');
	if (!b || !f) return;
	f.addEventListener('focus', function () { f.select(); });
	b.addEventListener('click', function () {
		var done = function () { var i = b.querySelector('i'); i.className = 'bi bi-check2'; setTimeout(function () { i.className = 'bi bi-clipboard'; }, 1500); };
		if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(f.value).then(done, function () { f.select(); });
		else { f.select(); try { document.execCommand('copy'); done(); } catch (e) { /* ignore */ } }
	});
})();

/* E-mail (0.8.508): checked when the field is left – red with the message while the syntax is wrong (empty is fine; the server checks again). */
(function () {
	'use strict';
	var el = document.getElementById('sus_email');
	if (!el || !el.hasAttribute('data-nu-email-msg')) return;
	var RE = /^[^\s@]+@[^\s@.]+(\.[^\s@.]+)+$/;
	function bad() { var v = el.value.trim(); return v !== '' && (!RE.test(v) || (el.validity && el.validity.typeMismatch)); }
	function feedback() {
		var fb = el.nextElementSibling;
		if (!fb || !fb.classList.contains('invalid-feedback')) {
			fb = document.createElement('div');
			fb.className = 'invalid-feedback';
			el.insertAdjacentElement('afterend', fb);
		}
		return fb;
	}
	function show(on) {
		el.classList.toggle('is-invalid', on);
		if (on) { feedback().textContent = el.getAttribute('data-nu-email-msg'); el.setAttribute('aria-invalid', 'true'); }
		else el.removeAttribute('aria-invalid');
	}
	el.addEventListener('blur', function () { show(bad()); });
	el.addEventListener('input', function () { if (el.classList.contains('is-invalid') && !bad()) show(false); });   // turns back as soon as it is right
})();

/* Welcome e-mail (0.8.508): ticking the switch shows in a toast what will be sent – the procedure nu_send_welcome_email, the e-mail
   template nu_send_welcome_email in the user's language (else the one without a language) or the built-in text (NuWelcome::send). */
(function () {
	'use strict';
	var box = document.getElementById('user_send_welcome_email');
	if (!box || !box.hasAttribute('data-nu-welcome')) return;
	var src, tx;
	try { src = JSON.parse(box.getAttribute('data-nu-welcome')); tx = JSON.parse(box.getAttribute('data-nu-welcome-t')); } catch (e) { return; }
	function which() {
		if (src.proc) return tx.proc.replace('%s', src.proc);
		var sel = document.getElementById('sus_language'), lang = sel ? sel.value.split('|')[0] : '';
		var tries = [lang, lang === '' ? src.fallback : null, ''];
		for (var i = 0; i < tries.length; i++) {
			if (tries[i] !== null && Object.prototype.hasOwnProperty.call(src.tpl, tries[i])) return tx.tpl.replace('%s', src.tpl[tries[i]]);
		}
		// nuBuilder Next's read-only template nu_send_welcome_email_template (0.8.658): user's language, the session's, English
		var std = src.std || {}, tries2 = [lang, src.fallback, 'English'];
		for (var j = 0; j < tries2.length; j++) {
			if (tries2[j] && Object.prototype.hasOwnProperty.call(std, tries2[j])) return tx.std.replace('%s', std[tries2[j]]);
		}
		return tx.builtin.replace('%s', src.code);
	}
	function show() {
		if (!window.nuBs || !nuBs.ready()) return;
		var holder = document.getElementById('nuWelcomeToasts');
		if (!holder) {
			holder = document.createElement('div');
			holder.id = 'nuWelcomeToasts';
			holder.className = 'toast-container position-fixed top-0 end-0 p-3 nuWelcomeToasts';
			document.body.appendChild(holder);
		}
		holder.textContent = '';
		var t = document.createElement('div');
		t.className = 'toast';
		t.setAttribute('role', 'status'); t.setAttribute('aria-live', 'polite');
		var head = document.createElement('div'); head.className = 'toast-header gap-2';
		var ic = document.createElement('i'); ic.className = 'bi bi-envelope'; ic.setAttribute('aria-hidden', 'true');
		var st = document.createElement('strong'); st.className = 'me-auto'; st.textContent = tx.title;
		var x = document.createElement('button'); x.type = 'button'; x.className = 'btn-close'; x.setAttribute('data-bs-dismiss', 'toast'); x.setAttribute('aria-label', tx.close);
		head.appendChild(ic); head.appendChild(st); head.appendChild(x);
		var body = document.createElement('div'); body.className = 'toast-body'; body.textContent = which();
		t.appendChild(head); t.appendChild(body); holder.appendChild(t);
		t.addEventListener('hidden.bs.toast', function () { t.remove(); });
		nuBs.toast(t, {delay: 7000}).show();
	}
	box.addEventListener('change', function () { if (box.checked) show(); });
})();

/* Additional access levels (0.8.585): add a row from the template, remove a row; "none" text while there is no row. */
(function () {
	'use strict';
	var box = document.getElementById('xaBox'), rows = document.getElementById('xaRows'), tpl = document.getElementById('xaTpl'), add = document.getElementById('xaAdd');
	if (!box || !rows || !tpl) return;
	var none = document.getElementById('xaNone');
	function changed() {
		if (none) none.classList.toggle('d-none', rows.querySelector('.xaRow') !== null);
		var f = box.closest('form');
		if (f) f.dispatchEvent(new Event('change', {bubbles: true}));   // leave guard and the red Save
	}
	if (add) add.addEventListener('click', function () {
		rows.appendChild(tpl.content.cloneNode(true));
		var sel = rows.lastElementChild && rows.lastElementChild.querySelector('select');
		if (sel) sel.focus();
		changed();
	});
	rows.addEventListener('click', function (e) {
		var b = e.target.closest('.xaDel');
		if (!b) return;
		b.closest('.xaRow').remove();
		changed();
	});
})();

/* Effective rights (0.8.589): the rights of the main and the additional access levels together, as entered in the form (not saved
   yet) – asked from the server (POST action "effective"); a tick names the additional levels it comes from. */
(function () {
	'use strict';
	var dlg = document.getElementById('xaEffDlg'), btn = document.getElementById('xaEff'), rows = document.getElementById('xaRows');
	if (!dlg) return;
	var T = {};
	try { T = JSON.parse(dlg.getAttribute('data-t') || '{}'); } catch (e) { T = {}; }
	var KEYS = ['view', 'add', 'save', 'delete', 'clone', 'print'], data = null, mainCode = '';
	var $ = function (id) { return document.getElementById(id); };
	function el(tag, cls, text) { var e = document.createElement(tag); if (cls) e.className = cls; if (text !== undefined) e.textContent = text; return e; }
	function badge(text, cls) { return el('span', 'badge ' + (cls || 'text-bg-info') + ' fw-normal ms-1', text); }

	// the button only while there is an additional level row
	function syncBtn() { if (btn && rows) btn.classList.toggle('d-none', !rows.querySelector('.xaRow')); }
	if (rows) new MutationObserver(syncBtn).observe(rows, {childList: true});

	function fromExtra(levels) { return levels.filter(function (c) { return c !== mainCode; }); }
	function onlyExtra(levels) { return levels.length > 0 && levels.indexOf(mainCode) < 0; }

	function cell(levels) {
		var td = el('td', 'text-center text-nowrap');
		if (!levels.length) { td.appendChild(el('i', 'bi bi-dash text-body-tertiary')); return td; }
		var i = el('i', 'bi bi-check-circle-fill text-success');
		i.setAttribute('role', 'img'); i.setAttribute('aria-label', levels.join(', ')); i.title = levels.join(', ');
		td.appendChild(i);
		if (onlyExtra(levels)) fromExtra(levels).forEach(function (c) { td.appendChild(badge(c)); });   // badge only where the main level does not grant it
		return td;
	}
	function render() {
		if (!data) return;
		var q = ($('xaEffFind').value || '').toLowerCase().trim(), only = $('xaEffOnly').checked;
		var match = function (s) { return q === '' || s.toLowerCase().indexOf(q) >= 0; };
		// levels legend
		var lv = $('xaEffLevels'); lv.textContent = '';
		data.levels.forEach(function (l) {
			var b = el('span', 'badge border fw-normal ' + (l.main ? 'text-bg-primary' : (l.active ? 'text-bg-info' : 'text-bg-light text-decoration-line-through')), l.code);
			b.title = l.description + (l.main ? ' – ' + T.main : (l.active ? '' : ' – ' + T.expired + ' ' + l.until));
			lv.appendChild(b);
		});
		// forms
		var ft = document.querySelector('#xaEffForms table'), head = ft.tHead, body = ft.tBodies[0], n = 0;
		head.textContent = ''; body.textContent = '';
		var hr = el('tr'); hr.appendChild(el('th', '', T.form)); KEYS.forEach(function (k) { hr.appendChild(el('th', 'text-center', T[k])); }); head.appendChild(hr);
		data.forms.forEach(function (f) {
			var extra = KEYS.some(function (k) { return onlyExtra(f.rights[k]); });
			if (!match(f.label + ' ' + f.code) || (only && !extra)) return;
			var tr = el('tr'), th = el('td');
			th.appendChild(el('span', 'fw-semibold', f.label));
			if (onlyExtra(f.rights.view)) th.appendChild(badge(T.additional, 'text-bg-warning'));
			if (!f.edit) th.appendChild(badge(T.browse_only, 'text-bg-light border'));
			else if (!f.browse) th.appendChild(badge(T.edit_only, 'text-bg-light border'));
			tr.appendChild(th);
			KEYS.forEach(function (k) { tr.appendChild(cell(f.rights[k])); });
			body.appendChild(tr); n++;
		});
		if (!n) { var tr0 = el('tr'), td0 = el('td', 'text-center text-body-secondary py-3', T.none); td0.colSpan = 7; tr0.appendChild(td0); body.appendChild(tr0); }
		$('xaEffNForms').textContent = n;
		// procedures / reports
		[['procs', 'xaEffProcs', 'xaEffNProcs'], ['reports', 'xaEffReports', 'xaEffNReports']].forEach(function (x) {
			var b = document.querySelector('#' + x[1] + ' tbody'), m = 0;
			b.textContent = '';
			data[x[0]].forEach(function (p) {
				if (!match(p.code + ' ' + p.description) || (only && !onlyExtra(p.levels))) return;
				var tr = el('tr'), td = el('td');
				td.appendChild(el('span', 'fw-semibold', p.code));
				if (p.description) td.appendChild(el('div', 'small text-body-secondary', p.description));
				tr.appendChild(td);
				var tl = el('td', 'text-end');
				p.levels.forEach(function (c) { tl.appendChild(badge(c, c === mainCode ? 'text-bg-primary' : 'text-bg-info')); });
				tr.appendChild(tl);
				b.appendChild(tr); m++;
			});
			if (!m) { var tr1 = el('tr'), td1 = el('td', 'text-center text-body-secondary py-3', T.none); td1.colSpan = 2; tr1.appendChild(td1); b.appendChild(tr1); }
			$(x[2]).textContent = m;
		});
	}
	function load() {
		var fd = new FormData(), sel = $('sus_zzzzsys_access_id');
		fd.append('_csrf', dlg.getAttribute('data-csrf') || '');
		fd.append('action', 'effective');
		fd.append('sus_zzzzsys_access_id', sel ? sel.value : '');
		document.querySelectorAll('#xaRows .xaRow').forEach(function (r) {
			fd.append('xa_acc[]', (r.querySelector('select') || {}).value || '');
			fd.append('xa_until[]', (r.querySelector('.xaUntil') || {}).value || '');
		});
		data = null;
		$('xaEffBusy').classList.remove('d-none');
		fetch(dlg.getAttribute('data-url'), {method: 'POST', body: fd, credentials: 'same-origin', headers: {'Accept': 'application/json'}})
			.then(function (r) { return r.json(); })
			.then(function (j) {
				$('xaEffBusy').classList.add('d-none');
				if (!j || !j.ok) throw new Error('x');
				data = j;
				mainCode = ((j.levels || []).filter(function (l) { return l.main; })[0] || {}).code || '';
				render();
			})
			.catch(function () { $('xaEffBusy').classList.add('d-none'); $('xaEffLevels').textContent = T.error || ''; });
	}
	dlg.addEventListener('show.bs.modal', load);
	$('xaEffFind').addEventListener('input', render);
	$('xaEffOnly').addEventListener('change', render);
	// Copy: tab-separated, one line per form / procedure / report
	$('xaEffCopy').addEventListener('click', function () {
		if (!data) return;
		var lines = [[T.form].concat(KEYS.map(function (k) { return T[k]; })).join('\t')];
		data.forms.forEach(function (f) { lines.push([f.label + ' (' + f.code + ')'].concat(KEYS.map(function (k) { return f.rights[k].join(' + '); })).join('\t')); });
		['procs', 'reports'].forEach(function (k) {
			lines.push('');
			data[k].forEach(function (p) { lines.push(p.code + '\t' + p.levels.join(' + ')); });
		});
		var b = $('xaEffCopy');
		if (navigator.clipboard) navigator.clipboard.writeText(lines.join('\n')).then(function () { b.title = T.copied; b.classList.add('btn-success'); setTimeout(function () { b.classList.remove('btn-success'); }, 1200); });
	});
})();

/* Welcome e-mail preview (0.8.684): with "Preview the e-mail before sending" on, Save first asks the server (welcome_check=1: every check
   of the save, nothing written) for the e-mail; errors → the normal save shows them. The dialog shows recipient, template, subject and the
   mail (iframe ?p=user&welcome_frame=… with a CSP of its own, editable through designMode – or as HTML). "Save and send" submits the form
   with the edited mail (welcome_edit, welcome_subject, welcome_html, welcome_choice, welcome_link); Cancel leaves the form as it was.
   The set-password link is the marker #SET_PASSWORD_URL# until it is sent. The switch is remembered per browser. */
(function () {
	'use strict';
	var form = document.getElementById('nuRecordForm'), box = document.getElementById('user_send_welcome_email');
	var pv = document.getElementById('user_welcome_preview'), wrap = document.getElementById('userWelcomePvWrap'), dlg = document.getElementById('userWelcomePvDlg');
	if (!form || !box || !pv || !wrap || !dlg) return;
	var T = {};
	try { T = JSON.parse(dlg.getAttribute('data-t') || '{}'); } catch (e) { T = {}; }
	var $ = function (id) { return document.getElementById(id); };
	var KEY = 'nunext-welcome-preview';
	try { if (!pv.disabled && localStorage.getItem(KEY) === '1') pv.checked = true; } catch (e) { /* private mode */ }
	pv.addEventListener('change', function () { try { localStorage.setItem(KEY, pv.checked ? '1' : '0'); } catch (e) { /* ignore */ } });
	function syncWrap() { wrap.hidden = !box.checked; }
	box.addEventListener('change', syncWrap);
	syncWrap();

	var frame = $('userWpvFrame'), src = $('userWpvSource'), err = $('userWpvErr'), tpl = $('userWpvTpl'), subj = $('userWpvSubject');
	var state = {choice: 'auto', link: false, edited: false, ok: false, source: ''};
	var doc = function () { try { return frame.contentDocument; } catch (e) { return null; } };
	function html() {
		if (!src.hidden) return src.value;
		var d = doc();
		return d && d.documentElement ? '<!doctype html>\n' + d.documentElement.outerHTML : '';
	}
	function editable() {
		var d = doc();
		if (!d || !d.body) return;
		d.designMode = 'on';
		d.addEventListener('input', function () { state.edited = true; });
	}
	frame.addEventListener('load', editable);
	function load(url) { frame.src = url; }
	function setMode(m) {
		if (m === 'html') { src.value = html(); src.hidden = false; frame.hidden = true; }
		else {
			var d = doc();
			if (!src.hidden && d) { d.open(); d.write(src.value); d.close(); editable(); }
			src.hidden = true; frame.hidden = false;
		}
	}
	document.querySelectorAll('input[name="userWpvMode"]').forEach(function (r) { r.addEventListener('change', function () { if (r.checked) setMode(r.value); }); });
	src.addEventListener('input', function () { state.edited = true; });
	subj.addEventListener('input', function () { state.edited = true; });

	function check(choice) {
		var fd = new FormData(form);
		fd.set('welcome_check', '1');
		fd.set('welcome_choice', choice || 'auto');
		return fetch(form.getAttribute('action') || window.location.href, {method: 'POST', body: fd, credentials: 'same-origin', headers: {Accept: 'application/json'}})
			.then(function (r) { return r.json(); });
	}
	function fill(d) {
		state.choice = d.choice; state.link = !!d.link; state.edited = false; state.source = d.source;
		$('userWpvTo').value = d.to;
		$('userWpvCc').value = (d.cc || []).join(', '); $('userWpvCcWrap').hidden = !(d.cc || []).length;
		$('userWpvBcc').value = (d.bcc || []).join(', '); $('userWpvBccWrap').hidden = !(d.bcc || []).length;
		tpl.textContent = '';
		(d.choices || []).forEach(function (c) { var o = new Option(c[1], c[0]); if (c[0] === d.choice) o.selected = true; tpl.appendChild(o); });
		subj.value = d.subject;
		$('userWpvLinkHint').hidden = !state.link;
		err.textContent = '';
		$('userWpvModeView').checked = true; src.hidden = true; frame.hidden = false;
		load(d.frame);
	}
	function plainSubmit() { state.ok = true; form.requestSubmit ? form.requestSubmit() : form.submit(); }

	form.addEventListener('submit', function (e) {
		if (state.ok || !box.checked || !pv.checked || pv.disabled) return;
		e.preventDefault();
		check('auto').then(function (d) {
			if (!d || !d.ok) { plainSubmit(); return; }          // errors: the normal save shows them in the form
			fill(d);
			nuBs.modal(dlg).show();
		}).catch(function () { plainSubmit(); });
	});
	tpl.addEventListener('change', function () {
		var want = tpl.value;
		if (state.edited && !window.confirm(T.replace)) { tpl.value = state.choice; return; }
		check(want).then(function (d) { if (d && d.ok) fill(d); else err.textContent = T.failed; }).catch(function () { err.textContent = T.failed; });
	});
	$('userWpvSend').addEventListener('click', function () {
		var body = html();
		if (state.link && (body + subj.value).indexOf(T.marker) < 0) { err.textContent = T.link; return; }
		var put = function (n, v) {
			var i = form.querySelector('input[type="hidden"][name="' + n + '"]') || form.appendChild(Object.assign(document.createElement('input'), {type: 'hidden', name: n}));
			i.value = v;
		};
		put('welcome_edit', '1'); put('welcome_subject', subj.value); put('welcome_html', body);
		put('welcome_choice', state.choice); put('welcome_link', state.link ? '1' : '0');
		$('userWpvSend').disabled = true;
		nuBs.modal(dlg).hide();
		plainSubmit();
	});
	dlg.addEventListener('hidden.bs.modal', function () { if (!state.ok) frame.removeAttribute('src'); });
})();
