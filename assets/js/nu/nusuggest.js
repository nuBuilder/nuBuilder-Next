/* Suggestions while typing – a list below a text input, no library.
 *
 *   nuNextAutocomplete(idOrElement, options) → {search(text), close(), destroy()}
 *   options: source   array of texts or {label, value, …} | function (term, done) | URL (GET ?term=…, JSON array) |
 *                     left out = the query set on the object in design mode (asked from the server)
 *            min      characters before the list opens (1)        max    rows shown (15)
 *            delay    ms after the last key (250)                 strict only values of the list are kept
 *            fill     {column or key of the item: field id}       width  px of the list (default: the field's width)
 *            select   function (item, input) – return false to keep the field as it is
 *            autoFocus first row marked at once
 * Inputs with data-nu-suggest (design mode: Suggestions) attach by themselves on focus – also in new sub-form rows.
 * Where jQuery UI is not loaded, $(…).autocomplete({source, minLength, delay, autoFocus, select}) is answered by this list.
 */
(function () {
	'use strict';
	var seqId = 0, KEY = '__nuSuggest';

	function norm(item) {
		if (item === null || item === undefined) return null;
		if (typeof item !== 'object') { var s = String(item); return {label: s, value: s, row: {}}; }
		var v = item.value !== undefined ? item.value : item.label;
		var l = item.label !== undefined ? item.label : item.value;
		if (v === undefined) return null;
		return {label: String(l), value: String(v), row: item.row || item, raw: item};
	}

	/** Rows of the server → items: value = first column, label = all columns, row = {column: text}. */
	function fromRows(res) {
		var cols = (res && res.cols) || [], out = [];
		((res && res.rows) || []).forEach(function (r) {
			var row = {};
			cols.forEach(function (c, i) { row[c] = r[i]; });
			out.push({label: r.filter(function (x) { return x !== ''; }).join(' · '), value: String(r[0] === undefined ? '' : r[0]), row: row});
		});
		return out;
	}

	function fold(s) { return String(s).toLowerCase(); }

	function Suggest(input, opt) {
		var self = this;
		this.input = input;
		this.opt = opt || {};
		this.min = this.opt.min === undefined ? 1 : Math.max(0, Number(this.opt.min) || 0);
		this.max = Math.max(1, Math.min(50, Number(this.opt.max) || 15));
		this.delay = this.opt.delay === undefined ? 250 : Math.max(0, Number(this.opt.delay) || 0);
		this.items = [];
		this.active = -1;
		this.seq = 0;
		this.picked = null;                                       // the value last taken from the list
		this.id = 'nuSuggest' + (++seqId);
		this.list = document.createElement('ul');
		this.list.className = 'dropdown-menu nuSuggestList';
		this.list.id = this.id;
		this.list.setAttribute('role', 'listbox');
		this.list.hidden = true;
		document.body.appendChild(this.list);
		input.setAttribute('role', 'combobox');
		input.setAttribute('aria-autocomplete', 'list');
		input.setAttribute('aria-expanded', 'false');
		input.setAttribute('aria-controls', this.id);
		input.setAttribute('autocomplete', 'off');

		this.on = {
			input: function () { self.picked = null; self.later(); },
			keydown: function (e) { self.key(e); },
			blur: function () { setTimeout(function () { self.close(); self.strict(); }, 120); },
			place: function () { if (self.isOpen()) self.place(); }
		};
		input.addEventListener('input', this.on.input);
		input.addEventListener('keydown', this.on.keydown);
		input.addEventListener('blur', this.on.blur);
		window.addEventListener('resize', this.on.place);
		window.addEventListener('scroll', this.on.place, true);
		// pointerdown keeps the focus in the field; the click takes the row
		this.list.addEventListener('pointerdown', function (e) { e.preventDefault(); });
		this.list.addEventListener('click', function (e) {
			var li = e.target.closest ? e.target.closest('[data-nu-i]') : null;
			if (li) self.choose(Number(li.getAttribute('data-nu-i')));
		});
		this.list.addEventListener('mousemove', function (e) {
			var li = e.target.closest ? e.target.closest('[data-nu-i]') : null;
			if (li) self.mark(Number(li.getAttribute('data-nu-i')), true);
		});
	}

	Suggest.prototype.isOpen = function () { return !this.list.hidden; };

	Suggest.prototype.later = function () {
		var self = this;
		clearTimeout(this.timer);
		this.timer = setTimeout(function () { self.search(self.input.value); }, this.delay);
	};

	/** Items for a text: done(items) – from the list, the function, the URL or the server. */
	Suggest.prototype.fetch = function (term, done) {
		var src = this.opt.source, self = this;
		if (Array.isArray(src)) {
			var t = fold(term), starts = [], has = [];
			src.forEach(function (x) {
				var it = norm(x);
				if (!it) return;
				var p = fold(it.label).indexOf(t);
				if (p === 0) starts.push(it); else if (p > 0) has.push(it);
			});
			return done(starts.concat(has));
		}
		if (typeof src === 'function') {
			try { src(term, function (items) { done((items || []).map(norm).filter(Boolean)); }); } catch (x) { console.error(x); done([]); }
			return;
		}
		if (typeof src === 'string' && src !== '') {
			var url = src + (src.indexOf('?') < 0 ? '?' : '&') + 'term=' + encodeURIComponent(term);
			fetch(url, {credentials: 'same-origin', headers: {'Accept': 'application/json'}})
				.then(function (r) { return r.json(); })
				.then(function (items) { done((Array.isArray(items) ? items : []).map(norm).filter(Boolean)); })
				.catch(function () { done([]); });
			return;
		}
		var N = window.nuNext || {}, oid = this.input.getAttribute('data-nu-object-id');
		if (!oid || typeof N.call !== 'function') return done([]);
		var prefix = this.input.getAttribute('data-nu-prefix') || '';
		N.call('suggest', {object_id: oid, term: term, values: N.mainValues ? N.mainValues() : {}, row: prefix && N.rowValues ? N.rowValues(prefix) : {}})
			.then(function (res) { done(res && res.ok ? fromRows(res) : []); })
			.catch(function () { done([]); });
		void self;
	};

	Suggest.prototype.search = function (term) {
		var self = this, n = ++this.seq;
		term = String(term === undefined || term === null ? this.input.value : term);
		if (term.length < this.min || this.input.readOnly || this.input.disabled) return this.close();
		this.fetch(term, function (items) {
			if (n !== self.seq) return;                              // a later text was asked for meanwhile
			if (document.activeElement !== self.input && !self.forced) return self.close();
			self.show(items.slice(0, self.max), term);
		});
	};

	Suggest.prototype.show = function (items, term) {
		var self = this, t = fold(term);
		this.items = items;
		this.active = -1;
		while (this.list.firstChild) this.list.removeChild(this.list.firstChild);
		if (!items.length) return this.close();
		items.forEach(function (it, i) {
			var li = document.createElement('li');
			li.className = 'dropdown-item nuSuggestItem';
			li.id = self.id + '_' + i;
			li.setAttribute('role', 'option');
			li.setAttribute('data-nu-i', String(i));
			var p = t === '' ? -1 : fold(it.label).indexOf(t);
			if (p < 0) li.textContent = it.label;
			else {
				li.appendChild(document.createTextNode(it.label.slice(0, p)));
				var m = document.createElement('mark');
				m.textContent = it.label.slice(p, p + term.length);
				li.appendChild(m);
				li.appendChild(document.createTextNode(it.label.slice(p + term.length)));
			}
			self.list.appendChild(li);
		});
		this.list.hidden = false;
		this.list.classList.add('show');
		this.input.setAttribute('aria-expanded', 'true');
		this.place();
		if (this.opt.autoFocus) this.mark(0);
	};

	Suggest.prototype.place = function () {
		var r = this.input.getBoundingClientRect(), l = this.list;
		if (!r.width && !r.height) return this.close();
		var w = Number(this.opt.width) || 0;
		l.style.minWidth = Math.max(r.width, 120) + 'px';
		l.style.width = w ? w + 'px' : '';
		l.style.maxWidth = Math.max(160, window.innerWidth - 16) + 'px';
		var h = l.offsetHeight, below = window.innerHeight - r.bottom, up = below < Math.min(h, 220) + 8 && r.top > below;
		l.style.maxHeight = Math.max(120, (up ? r.top : below) - 12) + 'px';
		h = l.offsetHeight;
		l.style.left = Math.max(8, Math.min(r.left, window.innerWidth - l.offsetWidth - 8)) + 'px';
		l.style.top = (up ? r.top - h - 2 : r.bottom + 2) + 'px';
	};

	Suggest.prototype.mark = function (i, quiet) {
		var kids = this.list.children;
		if (this.active >= 0 && kids[this.active]) { kids[this.active].classList.remove('active'); kids[this.active].removeAttribute('aria-selected'); }
		this.active = i;
		if (i < 0 || !kids[i]) { this.input.removeAttribute('aria-activedescendant'); return; }
		kids[i].classList.add('active');
		kids[i].setAttribute('aria-selected', 'true');
		this.input.setAttribute('aria-activedescendant', kids[i].id);
		if (!quiet && kids[i].scrollIntoView) kids[i].scrollIntoView({block: 'nearest'});
	};

	Suggest.prototype.close = function () {
		clearTimeout(this.timer);
		this.forced = false;
		if (this.list.hidden) return;
		this.list.hidden = true;
		this.list.classList.remove('show');
		this.input.setAttribute('aria-expanded', 'false');
		this.input.removeAttribute('aria-activedescendant');
		this.active = -1;
	};

	Suggest.prototype.key = function (e) {
		var n = this.items.length, open = this.isOpen();
		if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
			if (!open) { if (e.key === 'ArrowDown' && !e.altKey) { this.forced = true; this.search(this.input.value); e.preventDefault(); } return; }
			e.preventDefault();
			var d = e.key === 'ArrowDown' ? 1 : -1, i = this.active + d;
			this.mark(i < -1 ? n - 1 : (i >= n ? -1 : i));
		} else if (e.key === 'Enter') {
			if (open && this.active >= 0) { e.preventDefault(); e.stopPropagation(); this.choose(this.active); }
		} else if (e.key === 'Tab') {
			if (open && this.active >= 0) this.choose(this.active);
			else this.close();
		} else if (e.key === 'Escape') {
			if (open) { e.preventDefault(); e.stopPropagation(); this.close(); }
		} else if (e.key === 'Home' || e.key === 'End') {
			if (open && this.active >= 0) { e.preventDefault(); this.mark(e.key === 'Home' ? 0 : n - 1); }
		}
	};

	/** Sets a field as the form's code would (edited mark, change event); a plain input outside a form gets the events. */
	function setField(el, value) {
		if (!el) return;
		if (el.id && typeof window.nuSetValue === 'function' && el.hasAttribute('data-nu-field')) { window.nuSetValue(el.id, value); return; }
		el.value = value;
		el.dispatchEvent(new Event('input', {bubbles: true}));
		el.dispatchEvent(new Event('change', {bubbles: true}));
	}

	Suggest.prototype.choose = function (i) {
		var it = this.items[i], input = this.input, self = this;
		if (!it) return;
		this.seq++;
		this.close();
		var keep = false;
		if (typeof this.opt.select === 'function') {
			try { keep = this.opt.select(it.raw !== undefined ? it.raw : it, input, it) === false; } catch (x) { console.error(x); }
		}
		if (keep) return;
		var fill = this.opt.fill || {}, prefix = input.getAttribute('data-nu-prefix') || '', own = input.getAttribute('data-nu-field') || input.id, value = it.value;
		Object.keys(fill).forEach(function (col) { if (fill[col] === own && it.row[col] !== undefined) value = String(it.row[col]); });
		this.picked = value;
		setField(input, value);
		Object.keys(fill).forEach(function (col) {
			if (fill[col] === own || it.row[col] === undefined) return;
			setField(document.getElementById(prefix + fill[col]) || document.getElementById(fill[col]), String(it.row[col]));
		});
		void self;
	};

	/** strict: a text that is not in the list is taken out again (a text of the list in other letter case is corrected). */
	Suggest.prototype.strict = function () {
		var self = this, v = this.input.value;
		if (!this.opt.strict || v === '' || v === this.picked || this.input.readOnly || this.input.disabled) return;
		this.fetch(v, function (items) {
			if (self.input.value !== v) return;
			var hit = items.filter(function (it) { return fold(it.value) === fold(v); })[0];
			if (hit) { self.picked = hit.value; if (hit.value !== v) setField(self.input, hit.value); return; }
			setField(self.input, '');
			self.input.classList.add('is-invalid');
			if (self.opt.strictText) self.input.title = self.opt.strictText;
			setTimeout(function () { self.input.classList.remove('is-invalid'); }, 2500);
		});
	};

	Suggest.prototype.destroy = function () {
		this.close();
		this.input.removeEventListener('input', this.on.input);
		this.input.removeEventListener('keydown', this.on.keydown);
		this.input.removeEventListener('blur', this.on.blur);
		window.removeEventListener('resize', this.on.place);
		window.removeEventListener('scroll', this.on.place, true);
		if (this.list.parentNode) this.list.parentNode.removeChild(this.list);
		delete this.input[KEY];
	};

	function attach(target, opt) {
		var input = typeof target === 'string' ? document.getElementById(target) : (target && target.jquery ? target[0] : target);
		if (!input || !/^(INPUT|TEXTAREA)$/.test(input.tagName)) return null;
		if (input[KEY]) input[KEY].destroy();
		var cfg = {};
		try { cfg = JSON.parse(input.getAttribute('data-nu-suggest') || '{}') || {}; } catch (x) { cfg = {}; }
		var o = {};
		Object.keys(cfg).forEach(function (k) { o[k] = cfg[k]; });
		if (cfg.src === 'list') o.source = cfg.list || [];
		if (cfg.msg) o.strictText = cfg.msg;
		Object.keys(opt || {}).forEach(function (k) { o[k] = opt[k]; });
		var s = new Suggest(input, o);
		input[KEY] = s;
		return {search: function (t) { s.forced = true; s.search(t); }, close: function () { s.close(); }, destroy: function () { s.destroy(); }, element: input};
	}

	// nuNextAutocomplete(id, options) = NuSuggest.attach – declared in nuruntime.js
	window.NuSuggest = {attach: attach, of: function (el) { return el ? el[KEY] || null : null; }};

	// inputs set up in design mode: attached on the first focus (so new sub-form rows need nothing)
	document.addEventListener('focusin', function (e) {
		var el = e.target;
		if (el && el.getAttribute && el.hasAttribute('data-nu-suggest') && !el[KEY]) attach(el);
	});

	// ------------------------------------------------------------------ jQuery UI's autocomplete, where jQuery UI is not loaded
	var $ = window.jQuery;
	if ($ && $.fn && !$.fn.autocomplete) {
		$.fn.autocomplete = function (o, a, b) {
			if (typeof o === 'string') {
				var first = this[0], s = first ? first[KEY] : null;
				if (o === 'instance') return s ? s.ui : undefined;
				if (o === 'widget') return s ? $(s.list) : $();
				return this.each(function () {
					var x = this[KEY];
					if (!x) return;
					if (o === 'destroy') { $(this).removeData('ui-autocomplete'); x.destroy(); }
					else if (o === 'close') x.close();
					else if (o === 'search') { x.forced = true; x.search(a === undefined ? this.value : a); }
					else if (o === 'disable') x.opt.disabled = true;
					else if (o === 'enable') x.opt.disabled = false;
					else if (o === 'option' && typeof a === 'string' && b !== undefined) {
						if (a === 'source') x.opt.source = uiSource(b);
						else if (a === 'minLength') x.min = Math.max(0, Number(b) || 0);
						else if (a === 'delay') x.delay = Math.max(0, Number(b) || 0);
					}
				});
			}
			o = o || {};
			return this.each(function () {
				var el = this, $el = $(el);
				var ctl = attach(el, {
					source: uiSource(o.source), min: o.minLength === undefined ? 1 : o.minLength, delay: o.delay === undefined ? 300 : o.delay, autoFocus: !!o.autoFocus,
					select: function (raw, input, it) {
						if (typeof o.select !== 'function') return true;
						var ev = $.Event('autocompleteselect');
						var r = o.select.call(el, ev, {item: {label: it.label, value: it.value}});
						return !(r === false || ev.isDefaultPrevented());
					}
				});
				if (!ctl) return;
				var ui = {element: $el, options: o, menu: {element: $(el[KEY].list)}, close: ctl.close, search: ctl.search, _resizeMenu: function () {}};
				el[KEY].ui = ui;
				$el.data('ui-autocomplete', ui).data('uiAutocomplete', ui);
				if (typeof o.create === 'function') { try { o.create.call(el, $.Event('autocompletecreate'), {}); } catch (x) { console.error(x); } }
			});
		};
	}

	/** jQuery UI's source (array, URL, function (request, response)) → the source of the list. */
	function uiSource(src) {
		if (typeof src !== 'function') return src;
		return function (term, done) { src({term: term}, function (items) { done(items || []); }); };
	}
})();
