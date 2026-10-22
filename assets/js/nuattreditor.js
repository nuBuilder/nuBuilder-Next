/* nuBuilder Next – editor for an object's HTML attributes (form builder, field "HTML attributes" = sob_input_attribute).
 *
 * Adapted from nuBuilder's attribute editor (nuObjectEditAttributes / openAttributeEditor). Differences:
 *   - Bootstrap modal (light / dark mode, keyboard, focus), texts in the user's language, no inline styles (strict CSP);
 *   - one editable row per attribute instead of a separate add / edit form: name (with suggestions) and value side by side,
 *     known attributes get a selection (autocomplete, spellcheck, inputmode, nu-label-position …) or no value (boolean ones);
 *   - checks while typing: invalid names, duplicates, event handlers (on…) – nuBuilder Next drops those, use the object's
 *     events instead –, numbers (maxlength, tabindex …) and accesskey;
 *   - the result is shown as text below and uses the same format the form reads: name="value", separated by commas.
 *
 * window.nuAttributeEditor.open(text, onApply(text), labels) · .parse(text) → [{name, value|null}] · .stringify(list) → text
 */
(function () {
	'use strict';

	var NAME_RE = /^[A-Za-z_:][-A-Za-z0-9_:.]*$/;
	// same split rule as the server (core/form/nurender.php): a comma followed by the next attribute name
	var SPLIT_RE = /,(?=\s*[A-Za-z_:][-A-Za-z0-9_:.]*\s*(=|,|$))/;

	/** Known attributes: kind text | number | select | bool, with hint or options. */
	var KNOWN = {
		'placeholder': {kind: 'text'}, 'title': {kind: 'text'}, 'value': {kind: 'text'}, 'pattern': {kind: 'text'},
		'aria-label': {kind: 'text'}, 'nu-tooltip': {kind: 'text'}, 'nu-tooltip-icon': {kind: 'text'},
		'maxlength': {kind: 'number', min: 0}, 'minlength': {kind: 'number', min: 0}, 'tabindex': {kind: 'number'},
		'min': {kind: 'text'}, 'max': {kind: 'text'}, 'step': {kind: 'text'}, 'rows': {kind: 'number', min: 1},
		'accesskey': {kind: 'text', single: true},
		'autocomplete': {kind: 'select', options: ['off', 'on', 'name', 'given-name', 'family-name', 'email', 'tel', 'username', 'new-password', 'current-password',
			'organization', 'street-address', 'postal-code', 'address-level2', 'country-name', 'bday', 'one-time-code']},
		'spellcheck': {kind: 'select', options: ['true', 'false']},
		'autocapitalize': {kind: 'select', options: ['off', 'none', 'on', 'sentences', 'words', 'characters']},
		'inputmode': {kind: 'select', options: ['text', 'numeric', 'decimal', 'tel', 'email', 'url', 'search', 'none']},
		'enterkeyhint': {kind: 'select', options: ['enter', 'done', 'go', 'next', 'previous', 'search', 'send']},
		'dir': {kind: 'select', options: ['ltr', 'rtl', 'auto']},
		'nu-label-position': {kind: 'select', options: ['top', 'custom']},
		'nu-tooltip-position': {kind: 'select', options: ['top', 'bottom', 'left', 'right']},
		'nu-tooltip-theme': {kind: 'select', options: ['light', 'dark', 'blue', 'green', 'purple', 'red', 'orange']},
		'nu-tooltip-arrow-size': {kind: 'select', options: ['small', 'default', 'large']},
		'readonly': {kind: 'bool'}, 'required': {kind: 'bool'}, 'autofocus': {kind: 'bool'}, 'disabled': {kind: 'bool'},
		'multiple': {kind: 'bool'}, 'nu-disable-calendar': {kind: 'bool'}
	};

	function parse(text) {
		var out = [];
		String(text || '').split(SPLIT_RE).forEach(function (part, i) {
			if (i % 2 === 1 || part === undefined) return;       // the captured group of the split
			part = part.trim();
			if (part === '') return;
			var eq = part.indexOf('=');
			var name = (eq < 0 ? part : part.slice(0, eq)).trim();
			var value = eq < 0 ? null : part.slice(eq + 1).trim().replace(/^(["'])([\s\S]*)\1$/, '$2');
			out.push({name: name, value: value});
		});
		return out;
	}

	function quote(v) {
		v = String(v);
		if (v.indexOf('"') < 0) return '"' + v + '"';
		if (v.indexOf("'") < 0) return "'" + v + "'";
		return '"' + v.replace(/"/g, "'") + '"';
	}
	function stringify(list) {
		return list.filter(function (a) { return a.name; })
			.map(function (a) { return a.value === null || a.value === undefined ? a.name : a.name + '=' + quote(a.value); })
			.join(', ');
	}

	// ------------------------------------------------------------------ DOM helpers
	function el(tag, attrs, kids) {
		var e = document.createElement(tag);
		Object.keys(attrs || {}).forEach(function (k) {
			var v = attrs[k];
			if (v === null || v === undefined || v === false) return;
			if (k === 'text') e.textContent = v; else if (k === 'class') e.className = v; else e.setAttribute(k, v === true ? '' : v);
		});
		(kids || []).forEach(function (c) { if (c) e.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); });
		return e;
	}
	var uid = 0;

	function open(text, onApply, labels) {
		var L = labels || {};
		var tr = function (k, d) { return L[k] || d; };
		var rows = parse(text);

		var list = el('div', {'class': 'nuAttrList'});
		var preview = el('code', {'class': 'nuAttrPreview'});
		var error = el('div', {'class': 'text-danger small mt-2', role: 'alert'});
		var dlId = 'nuAttrNames' + (++uid);
		var dl = el('datalist', {id: dlId}, Object.keys(KNOWN).concat(['data-']).sort().map(function (n) { return el('option', {value: n}); }));
		var applyBtn = el('button', {type: 'button', 'class': 'btn btn-primary btn-sm', text: tr('builder_attr_apply', 'Apply')});
		var addBtn = el('button', {type: 'button', 'class': 'btn btn-outline-secondary btn-sm', text: ''},
			[el('i', {'class': 'bi bi-plus-lg me-1'}), tr('builder_attr_add', 'Add attribute')]);
		var empty = el('div', {'class': 'text-body-secondary small fst-italic py-2', text: tr('builder_attr_none', 'No attributes yet.')});

		var modal = el('div', {'class': 'modal fade', tabindex: '-1', 'aria-hidden': 'true'}, [
			el('div', {'class': 'modal-dialog modal-lg modal-dialog-scrollable'}, [
				el('div', {'class': 'modal-content'}, [
					el('div', {'class': 'modal-header py-2'}, [
						el('h2', {'class': 'modal-title fs-6', text: tr('builder_attr_title', 'Edit HTML attributes')}),
						el('button', {type: 'button', 'class': 'btn-close', 'data-bs-dismiss': 'modal', 'aria-label': tr('builder_attr_cancel', 'Cancel')})
					]),
					el('div', {'class': 'modal-body'}, [
						el('div', {'class': 'row g-2 small text-body-secondary fw-semibold mb-1 nuAttrHead'}, [
							el('div', {'class': 'col-5', text: tr('builder_attr_name', 'Name')}), el('div', {'class': 'col', text: tr('builder_attr_value', 'Value')})]),
						list, empty, dl,
						el('div', {'class': 'mt-2'}, [addBtn]),
						error,
						el('div', {'class': 'mt-3 small text-body-secondary', text: tr('builder_attr_result', 'Result')}),
						el('div', {'class': 'nuAttrPreviewBox'}, [preview])
					]),
					el('div', {'class': 'modal-footer py-2'}, [
						el('span', {'class': 'small text-body-secondary me-auto d-none d-sm-inline', text: tr('builder_attr_keys', 'Enter: new row · Ctrl+Enter: apply')}),
						el('button', {type: 'button', 'class': 'btn btn-outline-secondary btn-sm', 'data-bs-dismiss': 'modal', text: tr('builder_attr_cancel', 'Cancel')}),
						applyBtn
					])
				])
			])
		]);

		// one row: name · value (text / number / select / none) · delete
		function valueControl(row, name, value) {
			var k = KNOWN[name.toLowerCase()];
			var holder = row.querySelector('.nuAttrValue');
			holder.innerHTML = '';
			var c;
			if (k && k.kind === 'bool') {
				c = el('div', {'class': 'form-text mt-1', text: tr('builder_attr_bool', 'No value (on / off attribute)')});
				c.setAttribute('data-bool', '1');
			} else if (k && k.kind === 'select') {
				c = el('select', {'class': 'form-select form-select-sm', 'aria-label': tr('builder_attr_value', 'Value')},
					k.options.map(function (o) { return el('option', {value: o, text: o}); }));
				if (value !== null && value !== undefined && k.options.indexOf(value) < 0) c.appendChild(el('option', {value: value, text: value}));
				if (value !== null && value !== undefined) c.value = value;
			} else {
				c = el('input', {type: k && k.kind === 'number' ? 'number' : 'text', 'class': 'form-control form-control-sm', spellcheck: 'false', autocomplete: 'off',
					'aria-label': tr('builder_attr_value', 'Value'), placeholder: k ? '' : tr('builder_attr_value_ph', 'empty = attribute without value'), min: k && k.min !== undefined ? String(k.min) : null});
				c.value = value === null || value === undefined ? '' : value;
			}
			c.classList.add('nuAttrValueInput');
			holder.appendChild(c);
		}
		function addRow(name, value, focus) {
			var nameIn = el('input', {type: 'text', 'class': 'form-control form-control-sm font-monospace nuAttrName', list: dlId, spellcheck: 'false', autocomplete: 'off',
				'aria-label': tr('builder_attr_name', 'Name'), placeholder: 'placeholder, data-…'});
			nameIn.value = name || '';
			var del = el('button', {type: 'button', 'class': 'btn btn-sm btn-outline-danger', title: tr('builder_attr_remove', 'Remove'), 'aria-label': tr('builder_attr_remove', 'Remove'), 'aria-pressed': 'false'},
				[el('i', {'class': 'bi bi-trash'})]);
			var row = el('div', {'class': 'row g-2 align-items-start mb-2 nuAttrRow'}, [
				el('div', {'class': 'col-5'}, [nameIn]),
				el('div', {'class': 'col nuAttrValue'}),
				el('div', {'class': 'col-auto'}, [del])
			]);
			valueControl(row, nameIn.value, value);
			var lastKind = KNOWN[nameIn.value.toLowerCase()] ? KNOWN[nameIn.value.toLowerCase()].kind : 'text';
			nameIn.addEventListener('input', function () {
				var k = KNOWN[nameIn.value.trim().toLowerCase()], kind = k ? k.kind : 'text';
				if (kind !== lastKind || kind === 'select') {               // keep a typed value when only the name changes
					var cur = row.querySelector('.nuAttrValueInput');
					valueControl(row, nameIn.value.trim(), cur && cur.value !== undefined ? cur.value : '');
					lastKind = kind;
				}
				update();
			});
			// remove (0.8.139): the row is crossed out and left out of the result; the same button brings it back until Apply
			del.addEventListener('click', function () {
				window.nuToggleRemoved(row, del, {remove: tr('builder_attr_remove', 'Remove'), undo: tr('remove_undo', 'Undo removal'), pending: tr('builder_attr_removed', 'Left out when you click Apply')});
				update();
			});
			list.appendChild(row);
			update();
			if (focus) nameIn.focus();
			return row;
		}

		function read() {
			return Array.prototype.map.call(list.querySelectorAll('.nuAttrRow:not(.nuRowRemoved)'), function (row) {
				var name = row.querySelector('.nuAttrName').value.trim();
				var c = row.querySelector('.nuAttrValueInput');
				var value = c.hasAttribute('data-bool') ? null : c.value;
				if (!KNOWN[name.toLowerCase()] && value === '') value = null;   // unknown attribute without value = boolean
				return {name: name, value: value, row: row};
			});
		}
		function validate(attrs) {
			var seen = {}, msg = '';
			attrs.forEach(function (a) {
				var bad = '';
				var low = a.name.toLowerCase(), k = KNOWN[low];
				if (a.name === '') bad = '';
				else if (!NAME_RE.test(a.name) || a.name === 'data-') bad = tr('builder_attr_err_name', 'Invalid name: %s').replace('%s', a.name);
				else if (/^on/i.test(a.name)) bad = tr('builder_attr_err_event', '%s: events are not set here – use the object\'s events.').replace('%s', a.name);
				else if (seen[low]) bad = tr('builder_attr_err_dup', '%s is there twice.').replace('%s', a.name);
				else if (k && k.kind === 'number' && a.value !== null && a.value !== '' && (!/^-?\d+$/.test(a.value) || (k.min !== undefined && Number(a.value) < k.min)))
					bad = tr('builder_attr_err_number', '%s needs a whole number.').replace('%s', a.name);
				else if (k && k.single && a.value !== null && a.value.length !== 1) bad = tr('builder_attr_err_char', '%s needs exactly one character.').replace('%s', a.name);
				seen[low] = true;
				a.row.querySelector('.nuAttrName').classList.toggle('is-invalid', !!bad);
				if (bad && !msg) msg = bad;
			});
			return msg;
		}
		function update() {
			var attrs = read(), msg = validate(attrs);
			error.textContent = msg;
			applyBtn.disabled = !!msg;
			var shown = list.querySelectorAll('.nuAttrRow').length;            // crossed-out rows are still shown
			empty.hidden = shown > 0;
			list.previousElementSibling.hidden = shown === 0;                // column titles
			var s = stringify(attrs);
			preview.textContent = s || '—';
		}

		list.addEventListener('input', update);
		list.addEventListener('change', update);
		list.addEventListener('keydown', function (e) {
			if (e.key === 'Enter' && !e.ctrlKey && !e.metaKey) { e.preventDefault(); addRow('', null, true); }
		});
		modal.addEventListener('keydown', function (e) {
			if (e.key === 'Enter' && (e.ctrlKey || e.metaKey) && !applyBtn.disabled) { e.preventDefault(); applyBtn.click(); }
		});
		addBtn.addEventListener('click', function () { addRow('', null, true); });

		var bs = nuBs.modal(modal);
		applyBtn.addEventListener('click', function () {
			if (validate(read())) return;
			var result = stringify(read());
			bs.hide();
			if (onApply) onApply(result);
		});
		modal.addEventListener('shown.bs.modal', function () {
			var first = list.querySelector('.nuAttrName');
			if (first) first.focus(); else addRow('', null, true);
		});
		modal.addEventListener('hidden.bs.modal', function () { bs.dispose(); modal.remove(); });

		rows.forEach(function (a) { addRow(a.name, a.value, false); });
		update();
		document.body.appendChild(modal);
		bs.show();
	}

	window.nuAttributeEditor = {open: open, parse: parse, stringify: stringify, known: KNOWN};
})();
