/* Validation rules of a form (0.8.812) – the browser side of core/form/nurules.php (NuFormRules): the same rules, checked while typing
   (fields of failed error rules get .is-invalid + the message as tooltip) and before a save. The server checks again – it decides.
   Data: window.nuNext.rules {rules, kinds, sub, today, texts}. API used by nuruntime.js: window.nuNextRules.check() / askWarnings(). */
(function () {
	'use strict';
	var N = window.nuNext || {}, R = N.rules;
	if (!R || !R.rules || !R.rules.length) return;
	var TX = R.texts || {};

	function el(id) { return document.getElementById(id); }
	function val(e) {
		if (!e) return '';
		if (typeof N.rawValue === 'function') { var rv = N.rawValue(e); return Array.isArray(rv) ? rv.join(',') : String(rv == null ? '' : rv); }   // without the field's format
		if (e.type === 'checkbox') return e.checked ? '1' : '0';
		if (e.multiple) return Array.prototype.filter.call(e.options, function (o) { return o.selected; }).map(function (o) { return o.value; }).join(',');
		return String(e.value == null ? '' : e.value);
	}
	function mainValues() {
		var out = {};
		Object.keys(R.kinds || {}).forEach(function (f) { out[f] = val(el(f)); });
		return out;
	}
	function rowsOf(sf) {
		var box = el(sf), rows = [], fields = Object.keys((R.sub || {})[sf] || {});
		if (!box) return rows;
		Array.prototype.forEach.call(box.querySelectorAll('.nuSubformRow'), function (r) {
			var p = r.getAttribute('data-nu-prefix') || '', del = el(p + 'nuDelete');
			if (del && del.checked) return;
			if (!r.getAttribute('data-nu-primary-key') && !r.querySelector('.nuEdited')) return;   // the blank add-row
			var v = {};
			fields.forEach(function (f) { v[f] = val(el(p + f)); });
			rows.push(v);
		});
		return rows;
	}

	/** A hash variable (0.8.814): a form property / hash cookie of the page first (nuGetProperty), else the value the server sent. */
	function hv(name) {
		if (typeof window.nuGetProperty === 'function') {
			try { var p = window.nuGetProperty(name); if (p !== undefined && p !== null) return String(p); } catch (e) { /* ignore */ }
		}
		var H = R.hash || {};
		if (Object.prototype.hasOwnProperty.call(H, name)) return String(H[name]);
		var low = String(name).toLowerCase(), k = Object.keys(H).filter(function (x) { return x.toLowerCase() === low; })[0];
		return k !== undefined ? String(H[k]) : null;
	}
	function isNum(s) { return s !== '' && !isNaN(Number(s)) && /^\s*-?\d+(\.\d+)?\s*$/.test(s); }
	function rx(p) { try { return new RegExp(p, 'iu'); } catch (e) { return null; } }
	function leaf(it, vals, kinds, counts) {
		var f = String(it.f), isCount = f.indexOf('count:') === 0;
		var hm = !isCount && /^#([A-Za-z_][A-Za-z0-9_]*)#$/.exec(f);   // a hash variable on the left (0.8.815)
		var a = isCount ? String(counts[f.slice(6)] || 0) : (hm ? String(hv(hm[1]) || '').trim() : String(vals[f] == null ? '' : vals[f]).trim());
		var kind = isCount ? 'number' : (kinds[f] || 'text'), o = it.o, v = it.v;
		var empty = a === '' || (kind === 'checkbox' && a === '0');
		if (o === 'empty') return empty;
		if (o === 'nempty') return !empty;
		function V(x) {
			if (x && typeof x === 'object' && !Array.isArray(x) && x.f !== undefined) return String(vals[x.f] == null ? '' : vals[x.f]).trim();
			if (x && typeof x === 'object' && !Array.isArray(x) && x.h !== undefined) { var h = hv(x.h); return h === null ? '' : h.trim(); }
			if (x === 'today') return R.today;
			return String(x == null ? '' : x).trim();
		}
		function cmp(x, y) {
			if (isNum(x) && isNum(y)) { var d = Number(x) - Number(y); return d < 0 ? -1 : (d > 0 ? 1 : 0); }
			if (kind === 'date' || /^\d{4}-\d{2}-\d{2}/.test(x)) { x = x.slice(0, y.length === 10 ? 10 : 19); return x < y ? -1 : (x > y ? 1 : 0); }
			x = x.toLowerCase(); y = y.toLowerCase();
			return x < y ? -1 : (x > y ? 1 : 0);
		}
		var b;
		switch (o) {
			case 'eq': return cmp(a, V(v)) === 0;
			case 'ne': return cmp(a, V(v)) !== 0;
			case 'lt': case 'before': return a !== '' && cmp(a, V(v)) < 0;
			case 'gt': case 'after': return a !== '' && cmp(a, V(v)) > 0;
			case 'le': return a !== '' && cmp(a, V(v)) <= 0;
			case 'ge': return a !== '' && cmp(a, V(v)) >= 0;
			case 'between': return a !== '' && cmp(a, V((v || [])[0])) >= 0 && cmp(a, V((v || [])[1])) <= 0;
			case 'contains': b = V(v); return b === '' || a.toLowerCase().indexOf(b.toLowerCase()) >= 0;
			case 'ncontains': b = V(v); return b === '' || a.toLowerCase().indexOf(b.toLowerCase()) < 0;
			case 'starts': return a.toLowerCase().indexOf(V(v).toLowerCase()) === 0;
			case 'ends': b = V(v).toLowerCase(); return b === '' || a.toLowerCase().slice(-b.length) === b;
			case 'in': case 'nin':
				var hit = (Array.isArray(v) ? v : [v]).some(function (x) { return cmp(a, V(x)) === 0; });
				return o === 'in' ? hit : !hit;
			case 'regex': var r = rx(String(v)); return r ? r.test(a) : true;
		}
		return true;
	}
	function holds(c, vals, kinds, counts) {
		var items = (c && c.items) || [], or = c && c.op === 'or';
		if (!items.length) return true;
		for (var i = 0; i < items.length; i++) {
			var r = items[i].items ? holds(items[i], vals, kinds, counts) : leaf(items[i], vals, kinds, counts);
			if (or && r) return true;
			if (!or && !r) return false;
		}
		return !or;
	}
	function message(r, vals) {
		return String(r.msg || '').replace(/#([A-Za-z_][A-Za-z0-9_]*)#/g, function (m, k) {
			if (Object.prototype.hasOwnProperty.call(vals, k)) return String(vals[k]);
			var h = hv(k);
			return h === null ? m : h;
		});
	}

	/** Every rule: {errors: [{message, field}], warnings: [{id, message, field}]} – as NuFormRules::run. */
	function check() {
		var vals = mainValues(), counts = {}, rows = {}, out = {errors: [], warnings: []};
		Object.keys(R.sub || {}).forEach(function (sf) { rows[sf] = rowsOf(sf); counts[sf] = rows[sf].length; });
		R.rules.forEach(function (r) {
			var sets = r.scope ? rows[r.scope] || [] : [vals], kinds = r.scope ? (R.sub[r.scope] || {}) : R.kinds;
			for (var n = 0; n < sets.length; n++) {
				var v = sets[n];
				if (r.when && !holds(r.when, v, kinds, counts)) continue;
				if (holds(r.check, v, kinds, counts)) continue;
				var msg = message(r, r.scope ? Object.assign({}, vals, v) : v);
				if (r.scope) msg = String(TX.row || '').replace('%d', String(n + 1)) + ' ' + msg;
				var item = {message: msg, field: r.scope ? '' : (r.field || '')};
				if (r.kind === 'warn') out.warnings.push({id: r.id, message: msg, field: item.field}); else out.errors.push(item);
				break;
			}
		});
		return out;
	}

	// live marks: fields named by failed error rules, once the user has touched the form
	var marked = [];
	function live() {
		marked.forEach(function (e) { e.classList.remove('is-invalid', 'nuRuleBad'); e.removeAttribute('data-nu-rule-msg'); if (e.getAttribute('data-nu-rule-title') !== null) { e.title = e.getAttribute('data-nu-rule-title'); e.removeAttribute('data-nu-rule-title'); } });
		marked = [];
		var res = check();
		res.errors.concat(res.warnings).forEach(function (x) {
			var e = x.field && el(x.field);
			if (!e || !e.classList.contains('nuEdited') && !touched[x.field]) return;
			if (e.getAttribute('data-nu-rule-msg') === null) e.setAttribute('data-nu-rule-title', e.title || '');
			e.classList.add('nuRuleBad');
			if (res.errors.indexOf(x) >= 0) e.classList.add('is-invalid');
			e.setAttribute('data-nu-rule-msg', x.message);
			e.title = x.message;
			marked.push(e);
		});
		document.dispatchEvent(new CustomEvent('nu:rulescheck', {detail: res}));
	}
	var touched = {}, timer = null;
	var form = el('nuEditForm') || document;
	['input', 'change'].forEach(function (ev) {
		form.addEventListener(ev, function (e) {
			if (e.target && e.target.id) touched[e.target.id] = true;
			clearTimeout(timer);
			timer = setTimeout(live, 250);
		});
	});

	function esc(s) { return String(s).replace(/[&<>"']/g, function (c) { return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]; }); }
	function askWarnings(list, onOk) {
		var details = list.map(function (w) { return w.message; });
		if (typeof window.nuConfirmAsk === 'function') {
			window.nuConfirmAsk(TX.warn_intro || '', onOk, TX.warn_ok || 'OK', 'btn btn-warning', {title: TX.warn_title, details: details});
			return;
		}
		if (window.confirm((TX.warn_intro || '') + '\n\n' + details.join('\n'))) onOk();
	}

	window.nuNextRules = {check: check, askWarnings: askWarnings, live: live, holds: holds, esc: esc};
})();
