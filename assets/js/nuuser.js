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

/* User form: Full name ↔ first / last name, password generator, permission items. */
(function () {
	'use strict';
	var box = document.getElementById('userPerms');
	if (!box) return;
	var filter = document.getElementById('userPermFilter'), count = document.getElementById('userPermCount'), empty = document.getElementById('userPermEmpty');
	var items = Array.prototype.slice.call(box.querySelectorAll('.userPermItem'));

	function update() {
		count.textContent = box.querySelectorAll('input[name="perm[]"]:checked').length;
	}
	var group = document.getElementById('userPermGroup');   // group filter (0.8.260) – combined with the search
	function apply() {
		var q = (filter.value || '').toLowerCase().trim(), g = group ? group.value : '*', shown = 0;
		items.forEach(function (it) {
			var on = (q === '' || it.getAttribute('data-search').indexOf(q) !== -1) && (g === '*' || it.getAttribute('data-group') === g);
			it.classList.toggle('d-none', !on);
			if (on) shown++;
		});
		box.querySelectorAll('.userPermGroup').forEach(function (g) {
			g.classList.toggle('d-none', !g.querySelector('.userPermItem:not(.d-none)'));
		});
		empty.classList.toggle('d-none', shown > 0);
	}
	if (filter) {
		filter.addEventListener('input', apply);
		filter.addEventListener('keydown', function (e) { if (e.key === 'Enter') e.preventDefault(); });   // do not submit the user form
	}
	if (group) group.addEventListener('change', apply);
	box.addEventListener('change', function (e) { if (e.target.name === 'perm[]') update(); });
	box.querySelectorAll('[data-perm-all]').forEach(function (b) {
		b.addEventListener('click', function () {
			var on = b.getAttribute('data-perm-all') === '1';
			items.forEach(function (it) {
				if (it.classList.contains('d-none')) return;
				var cb = it.querySelector('input[type=checkbox]');
				if (cb && cb.checked !== on) { cb.checked = on; cb.dispatchEvent(new Event('change', {bubbles: true})); }
			});
			update();
		});
	});
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
			if (hint) { hint.textContent = ok ? modal.dataset.usedCopied : modal.dataset.used; hint.classList.add('text-success'); }
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
