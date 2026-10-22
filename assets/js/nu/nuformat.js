/*
 * Display formats (mirrors core/form/nuformat.php): D|date pattern, N|number pattern, T|text mask.
 * window.NuFormatLib is used by the form runtime, the formats page and the builder.
 * Options of saved formats (negative style, mask storage) come from window.nuNext.formats or setRegistry().
 */
(function () {
	'use strict';
	var MON = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
	var MONL = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
	var DAY = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
	var DAYL = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
	var DTOK = /yyyy|yy|mmmm|mmm|mm|dddd|ddd|dd|hh|nn|ss/g;
	var registry = (window.nuNext && window.nuNext.formats) || {};

	function pad2(n) { return String(n).padStart(2, '0'); }

	function options(f) {
		var o = registry[String(f || '').trim()] || {};
		return {negative: o.negative || 'minus', store: o.store === 'raw' ? 'raw' : 'masked', complete: o.complete !== false};
	}

	// ------------------------------------------------------------------ numbers
	function numberSpec(p) {
		var m = /^(.*?)1([^0-9]?)000([^0-9]?)(0*)(.*)$/.exec(p);
		if (!m) return {prefix: '', sep: '', dec: '.', places: 2, suffix: ''};
		var dec = m[3], places = m[4].length, suffix = m[5];
		if (places === 0 && dec !== '') { suffix = dec + suffix; dec = ''; }
		return {prefix: m[1], sep: m[2], dec: dec || '.', places: places, suffix: suffix};
	}

	function numberPattern(s) {
		var places = Math.max(0, Math.min(10, parseInt(s.places, 10) || 0));
		return (s.prefix || '') + '1' + (s.sep || '') + '000' + (places > 0 ? (s.dec || '.') + '0'.repeat(places) : '') + (s.suffix || '');
	}

	function formatNumber(v, p, negative) {
		var n = parseFloat(String(v).replace(',', '.'));
		if (isNaN(n) || !/^\s*-?[\d.,]+\s*$/.test(String(v))) return String(v);
		var s = numberSpec(p), fixed = Math.abs(n).toFixed(s.places).split('.');
		var body = s.prefix + fixed[0].replace(/\B(?=(\d{3})+(?!\d))/g, s.sep) + (s.places ? s.dec + fixed[1] : '') + s.suffix;
		if (n >= 0 || +Math.abs(n).toFixed(s.places) === 0) return body;
		// minus after the prefix, as nuBuilder's addFormatting (€ -99,95 – 0.8.364); brackets around the whole value
		return /brackets$/.test(negative || '') ? '(' + body + ')' : s.prefix + '-' + body.slice(s.prefix.length);
	}

	// ------------------------------------------------------------------ masks
	function maskTokens(mask) {
		var out = [], c = Array.from(String(mask));
		for (var i = 0; i < c.length; i++) {
			if (c[i] === '\\' && i + 1 < c.length) { out.push(['L', c[++i]]); continue; }
			out.push('9aA*'.indexOf(c[i]) >= 0 ? [c[i], ''] : ['L', c[i]]);
		}
		return out;
	}

	function fits(kind, c) {
		if (kind === '9') return /^\d$/.test(c);
		if (kind === 'a' || kind === 'A') return /^\p{L}$/u.test(c);
		if (kind === '*') return /^[\p{L}\p{N}]$/u.test(c);
		return false;
	}

	/** @return {masked, raw, complete, fits}  fits: complete and no letter/digit of the value was dropped */
	function mask(value, m) {
		var tokens = maskTokens(m), input = Array.from(String(value)), i = 0;
		var out = '', raw = '', pending = '', filled = 0, slots = 0, dropped = 0;
		var sig = /^[\p{L}\p{N}]$/u;
		tokens.forEach(function (t) {
			if (t[0] === 'L') {
				if (i < input.length && input[i].toLowerCase() === t[1].toLowerCase()) i++;
				pending += t[1];
				return;
			}
			slots++;
			while (i < input.length && !fits(t[0], input[i])) { if (sig.test(input[i])) dropped++; i++; }
			if (i >= input.length) return;
			var c = t[0] === 'A' ? input[i].toUpperCase() : input[i];
			out += pending + c;
			pending = '';
			raw += c;
			filled++;
			i++;
		});
		if (filled === slots) out += pending;
		for (; i < input.length; i++) if (sig.test(input[i])) dropped++;
		return {masked: filled ? out : String(value), raw: raw, complete: filled === slots, fits: filled === slots && dropped === 0};
	}

	// ------------------------------------------------------------------ dates
	function formatDate(v, p) {
		var t = /^(\d{1,2}):(\d{2})(?::(\d{2}))?$/.exec(v), m;
		if (t) m = [0, '0000', '00', '00', pad2(t[1]), t[2], t[3] || '00'];
		else {
			m = /^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::(\d{2}))?)?/.exec(v);
			if (!m) return v;
			if (m[1] === '0000') return '';
		}
		var y = +m[1], mo = +m[2], d = +m[3], dow = y ? new Date(y, mo - 1, d).getDay() : 0;
		var k = {yyyy: m[1], yy: m[1].substr(2), mmmm: MONL[mo - 1] || '', mmm: MON[mo - 1] || '', mm: m[2], dddd: DAYL[dow], ddd: DAY[dow], dd: m[3],
			hh: m[4] || '00', nn: m[5] || '00', ss: m[6] || '00'};
		return p.replace(DTOK, function (x) { return k[x]; });
	}

	function parseDate(v, p) {
		if (/^\d{4}-\d{2}-\d{2}/.test(v)) return v;          // already ISO
		var parts = {}, tokens = [];
		var re = p.replace(/[.*+?^${}()|[\]\\]/g, '\\$&').replace(DTOK, function (k) {
			tokens.push(k);
			return {yyyy: '(\\d{4})', yy: '(\\d{2})', mmmm: '([A-Za-z]+)', mmm: '([A-Za-z]+)', mm: '(\\d{1,2})', dddd: '([A-Za-z]+)', ddd: '([A-Za-z]+)', dd: '(\\d{1,2})', hh: '(\\d{1,2})', nn: '(\\d{1,2})', ss: '(\\d{1,2})'}[k];
		});
		var m = new RegExp('^' + re + '$').exec(v) || new RegExp('^' + re).exec(v);
		if (!m) {
			var tm = /^(\d{1,2}):(\d{2})(?::(\d{2}))?$/.exec(v);       // time only
			if (tm && !/yyyy|yy|dd/.test(p)) return pad2(tm[1]) + ':' + tm[2] + ':' + (tm[3] || '00');
			var q = /^(\d{1,2})[./-](\d{1,2})[./-](\d{2,4})(?:\s+(\d{1,2}):(\d{2}))?/.exec(v);    // tolerant input: d.m.yyyy / d-m-yy …
			if (!q) return v;
			parts = {dd: q[1], mm: q[2], yyyy: q[3].length === 2 ? '20' + q[3] : q[3], hh: q[4], nn: q[5]};
		} else {
			tokens.forEach(function (k, i) { if (k !== 'dddd' && k !== 'ddd') parts[k] = m[i + 1]; });
			if (parts.yy && !parts.yyyy) parts.yyyy = '20' + parts.yy;
			if (parts.mmm) parts.mm = String(MON.indexOf(parts.mmm.substr(0, 3)) + 1);
			if (parts.mmmm) parts.mm = String(MONL.indexOf(parts.mmmm) + 1);
		}
		if (!parts.yyyy && !parts.dd && parts.hh !== undefined) return pad2(parts.hh) + ':' + pad2(parts.nn || 0) + ':' + pad2(parts.ss || 0);
		var out = parts.yyyy + '-' + pad2(parts.mm) + '-' + pad2(parts.dd);
		if (parts.hh !== undefined && parts.hh !== null) out += ' ' + pad2(parts.hh) + ':' + pad2(parts.nn || 0) + ':' + pad2(parts.ss || 0);
		return out;
	}

	// ------------------------------------------------------------------ public
	function add(v, f) {
		v = v === null || v === undefined ? '' : String(v);
		f = String(f || '');
		if (v === '' || f.length < 3 || f.charAt(1) !== '|') return v;
		var type = f.charAt(0).toUpperCase(), p = f.substring(2);
		if (type === 'D') return formatDate(v, p);
		if (type === 'N') return formatNumber(v, p, options(f).negative);
		if (type === 'T') { var mm = mask(v, p); return mm.fits ? mm.masked : v; }
		return v;
	}

	/**
	 * A typed or pasted number → '1234.56' (0.8.344). Accepts other notations than the field's own (Excel, calculators, PDFs):
	 * currency signs, letters, spaces (also no-break / thin) and apostrophes are dropped; calculator and typographic minus signs,
	 * a trailing minus and brackets mean negative. Decimal separator: with both '.' and ',' the last one; with one kind only, several
	 * of them are thousands separators; a single one is the decimal separator when it is the field's own or is not followed by exactly
	 * three digits ("1,5" → 1.5, "1,234" in a field with '.' decimals → 1234, "1,234" in a field with ',' decimals → 1.234).
	 */
	function number(v, s) {
		var x = String(v).replace(/[\u2212\u2010-\u2014\uFE63\uFF0D]/g, '-');
		if (s.prefix.trim()) x = x.split(s.prefix.trim()).join('');      // the field's own prefix / suffix may contain digits
		if (s.suffix.trim()) x = x.split(s.suffix.trim()).join('');
		var neg = /^[^\d]*-/.test(x) || /\d[^\d]*-[^\d]*$/.test(x) || /^[^\d]*\(.*\)[^\d]*$/.test(x);
		x = x.replace(/[^0-9.,]/g, '');
		var lastDot = x.lastIndexOf('.'), lastCom = x.lastIndexOf(','), dec = '';
		if (lastDot >= 0 && lastCom >= 0) dec = lastDot > lastCom ? '.' : ',';
		else if (lastDot >= 0 || lastCom >= 0) {
			var c = lastDot >= 0 ? '.' : ',';
			if (x.split(c).length === 2 && ((s.places > 0 && c === s.dec) || x.length - x.indexOf(c) - 1 !== 3)) dec = c;   // no places: the field has no decimal separator
		}
		var i = dec ? x.lastIndexOf(dec) : -1;
		var whole = (i >= 0 ? x.slice(0, i) : x).replace(/[.,]/g, ''), frac = i >= 0 ? x.slice(i + 1).replace(/[.,]/g, '') : '';
		if (whole === '' && frac === '') return '';
		return (neg ? '-' : '') + (whole || '0') + (frac !== '' ? '.' + frac : '');
	}

	function remove(v, f) {
		v = v === null || v === undefined ? '' : String(v).trim();
		f = String(f || '');
		if (v === '' || f.length < 3 || f.charAt(1) !== '|') return v;
		var type = f.charAt(0).toUpperCase(), p = f.substring(2);
		if (type === 'D') return parseDate(v, p);
		if (type === 'N') return number(v, numberSpec(p));
		if (type === 'T') {
			var r = mask(v, p);
			if (!r.fits) return v;
			return options(f).store === 'raw' ? r.raw : r.masked;
		}
		return v;
	}

	/** 'nuNegative' when a negative number is shown in red. */
	function cssClass(raw, f) {
		f = String(f || '');
		if (f.charAt(0).toUpperCase() !== 'N' || !(parseFloat(raw) < 0)) return '';
		return /^red/.test(options(f).negative) ? 'nuNegative' : '';
	}

	function maskComplete(v, f) {
		f = String(f || '');
		if (v === '' || f.charAt(0).toUpperCase() !== 'T' || !options(f).complete) return true;
		return mask(v, f.substring(2)).fits;
	}

	window.NuFormatLib = {
		add: add, remove: remove, numberSpec: numberSpec, numberPattern: numberPattern, mask: mask, maskTokens: maskTokens,
		options: options, cssClass: cssClass, maskComplete: maskComplete,
		setRegistry: function (r) { registry = r || {}; }, setOptions: function (key, o) { registry[key] = o; }
	};
})();
