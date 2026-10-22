/*
 * Format designer (modal): create and edit formats in zzzzsys_format – date/time, number/currency, text mask.
 * Used by the formats page and the form builder.
 *
 *   NuFormatDesigner.init(data)            data from nu_format_designer_data() (csrf, labels, rights, max)
 *   NuFormatDesigner.open({id, type, types, onSaved(format, formats), onDeleted(formats)})
 */
(function () {
	'use strict';
	var D = {labels: {}, rights: {}, max: 28};
	var modal, bsModal, opts, st, usage = [];
	var TYPES = {Date: 'D', Number: 'N', Text: 'T'};
	var DATE_TOKENS = ['dd', 'mm', 'yyyy', 'yy', 'mmm', 'mmmm', 'ddd', 'dddd', 'hh', 'nn', 'ss'];
	var DATE_SEPS = ['.', '-', '/', ' ', ':', ', '];

	function t(k) { return D.labels[k] || k; }
	function h(tag, attrs, kids) {
		var e = document.createElement(tag);
		Object.keys(attrs || {}).forEach(function (k) {
			var v = attrs[k];
			if (v === null || v === undefined || v === false) return;
			if (k === 'text') e.textContent = v;
			else if (k.slice(0, 2) === 'on' && typeof v === 'function') e.addEventListener(k.slice(2), v);
			else e.setAttribute(k, v === true ? '' : v);
		});
		(kids || []).forEach(function (c) { if (c !== null && c !== undefined) e.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); });
		return e;
	}
	function api(action, data) {
		return fetch('index.php?p=formatapi', {
			method: 'POST', credentials: 'same-origin',
			headers: {'Content-Type': 'application/json', 'X-CSRF-Token': D.csrf},
			body: JSON.stringify(Object.assign({action: action}, data || {}))
		}).then(function (r) { return r.json().catch(function () { return {ok: false, error: 'HTTP ' + r.status}; }); });
	}
	function pad(n) { return String(n).padStart(2, '0'); }
	function nowIso() { var d = new Date(); return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes()) + ':' + pad(d.getSeconds()); }
	var uid = 0;
	function id(p) { return 'nfd' + (p || '') + (++uid); }

	// ------------------------------------------------------------------ state
	function blank(type) {
		return {id: '', type: type || 'Date', format: type === 'Number' ? '1\'000.00' : (type === 'Text' ? '' : 'dd.mm.yyyy'), description: '', 'default': false,
			negative: 'minus', store: 'masked', complete: true, sample: type === 'Text' ? '' : nowIso()};
	}
	function key() { return TYPES[st.type] + '|' + st.format; }
	function spec() { return NuFormatLib.numberSpec(st.format); }

	// ------------------------------------------------------------------ modal
	function ensureModal() {
		if (modal) return;
		modal = h('div', {'class': 'modal fade', tabindex: '-1', 'aria-hidden': 'true', id: 'nuFormatDesigner'}, [
			h('div', {'class': 'modal-dialog modal-lg modal-dialog-scrollable'}, [
				h('div', {'class': 'modal-content'}, [
					h('div', {'class': 'modal-header'}, [h('h2', {'class': 'modal-title fs-5', id: 'nfdTitle'}), h('button', {type: 'button', 'class': 'btn-close', 'data-bs-dismiss': 'modal', 'aria-label': t('cancel')})]),
					h('div', {'class': 'modal-body', id: 'nfdBody'}),
					h('div', {'class': 'modal-footer', id: 'nfdFoot'})
				])
			])
		]);
		document.body.appendChild(modal);
		bsModal = nuBs.modal(modal);
	}

	function open(o) {
		D = window.nuFormatDesignerData || D;
		ensureModal();
		opts = o || {};
		usage = [];
		if (opts.id) {
			api('get', {id: opts.id}).then(function (r) {
				if (!r.ok) { window.alert(r.error || 'Error'); return; }
				var f = r.format;
				st = {id: f.id, type: f.type, format: f.format, description: f.description || '', 'default': !!f['default'], negative: f.negative, store: f.store,
					complete: f.complete !== false, sample: f.type === 'Text' ? '' : nowIso()};
				usage = r.usage || [];
				render();
				bsModal.show();
			});
			return;
		}
		var types = opts.types || ['Date', 'Number', 'Text'];
		st = blank(opts.type && types.indexOf(opts.type) >= 0 ? opts.type : types[0]);
		if (opts.format) st.format = opts.format;
		render();
		bsModal.show();
	}

	function canSave() { return st.id ? D.rights.save : D.rights.create; }

	function render() {
		var body = document.getElementById('nfdBody'), foot = document.getElementById('nfdFoot');
		document.getElementById('nfdTitle').textContent = st.id ? t('fmt_edit') : t('fmt_new');
		body.innerHTML = '';
		foot.innerHTML = '';
		var types = opts.types || ['Date', 'Number', 'Text'];

		// type
		var typeGroup = h('div', {'class': 'btn-group mb-3', role: 'group', 'aria-label': t('fmt_type')});
		['Date', 'Number', 'Text'].forEach(function (ty) {
			var rid = id('type');
			var r = h('input', {type: 'radio', 'class': 'btn-check', name: 'nfdType', id: rid, value: ty, autocomplete: 'off',
				disabled: !!st.id || types.indexOf(ty) < 0, onchange: function () { st = blank(ty); render(); }});
			r.checked = st.type === ty;
			typeGroup.appendChild(r);
			typeGroup.appendChild(h('label', {'class': 'btn btn-outline-primary btn-sm', 'for': rid}, [h('i', {'class': 'bi ' + {Date: 'bi-calendar3', Number: 'bi-123', Text: 'bi-input-cursor-text'}[ty] + ' me-1'}), t('fmt_type_' + ty.toLowerCase())]));
		});
		body.appendChild(typeGroup);

		var row = h('div', {'class': 'row g-3'});
		body.appendChild(row);
		if (st.type === 'Date') renderDate(row);
		if (st.type === 'Number') renderNumber(row);
		if (st.type === 'Text') renderText(row);

		// common
		var did = id('desc'), defId = id('def');
		row.appendChild(h('div', {'class': 'col-sm-8'}, [
			h('label', {'class': 'form-label small mb-1', 'for': did, text: t('fmt_description')}),
			h('input', {'class': 'form-control form-control-sm', id: did, maxlength: '100', value: st.description, 'data-err': 'description', oninput: function () { st.description = this.value; }}),
			h('div', {'class': 'invalid-feedback'})
		]));
		var def = h('input', {'class': 'form-check-input', type: 'checkbox', role: 'switch', id: defId, onchange: function () { st['default'] = this.checked; }});
		def.checked = !!st['default'];
		row.appendChild(h('div', {'class': 'col-sm-4 d-flex align-items-end'}, [h('div', {'class': 'form-check form-switch mb-1'}, [def, h('label', {'class': 'form-check-label small', 'for': defId, text: t('fmt_default')})])]));

		// result + preview
		row.appendChild(h('div', {'class': 'col-12'}, [h('div', {'class': 'nfdResult'}, [
			h('div', {'class': 'small text-body-secondary', text: t('fmt_stored_as')}),
			h('code', {id: 'nfdKey', 'class': 'fs-6'}),
			h('div', {id: 'nfdKeyErr', 'class': 'small text-danger'}),
			h('div', {'class': 'small text-body-secondary mt-2', text: t('fmt_preview')}),
			h('div', {id: 'nfdPreview', 'class': 'nfdPreview'})
		])]));

		if (st.id && usage.length) {
			row.appendChild(h('div', {'class': 'col-12'}, [h('div', {'class': 'alert alert-info small mb-0 py-2'}, [
				h('div', {'class': 'fw-semibold', text: t('fmt_used_by').replace('%d', usage.length)}),
				h('div', {text: usage.slice(0, 12).map(function (u) { return u.object; }).join(', ') + (usage.length > 12 ? ' …' : '')}),
				h('div', {'class': 'mt-1', text: t('fmt_used_by_hint')})
			])]));
		}
		body.appendChild(h('div', {'class': 'alert alert-danger d-none mt-3 mb-0 py-2', id: 'nfdError', role: 'alert'}));

		// footer
		if (st.id && D.rights['delete']) {
			foot.appendChild(h('button', {type: 'button', 'class': 'btn btn-outline-danger me-auto', disabled: usage.length > 0 || String(st.id).indexOf('nu') === 0,
				title: usage.length ? t('fmt_err_in_use').replace('%d', usage.length) : null, onclick: remove}, [h('i', {'class': 'bi bi-trash me-1'}), t('delete')]));
		}
		foot.appendChild(h('button', {type: 'button', 'class': 'btn btn-outline-secondary', 'data-bs-dismiss': 'modal', text: t('cancel')}));
		if (canSave()) foot.appendChild(h('button', {type: 'button', 'class': 'btn nuBtnAct nuBtnSave', id: 'nfdSave', onclick: save}, [h('i', {'class': 'bi bi-floppy me-1'}), t('save')]));
		if (!canSave()) $$('input,select,textarea', body).forEach(function (e) { e.disabled = true; });
		update();
	}
	function $$(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }

	// ------------------------------------------------------------------ date
	function renderDate(row) {
		var pid = id('pat');
		var input = h('input', {'class': 'form-control nfdMono', id: pid, value: st.format, 'data-err': 'format', maxlength: String(D.max), spellcheck: 'false', autocomplete: 'off',
			oninput: function () { st.format = this.value; update(); }});
		function insert(s) {
			var a = input.selectionStart === null ? input.value.length : input.selectionStart, b = input.selectionEnd === null ? a : input.selectionEnd;
			input.value = input.value.slice(0, a) + s + input.value.slice(b);
			input.focus();
			input.setSelectionRange(a + s.length, a + s.length);
			st.format = input.value;
			update();
		}
		var tokens = h('div', {'class': 'd-flex flex-wrap gap-1 mt-2'});
		DATE_TOKENS.forEach(function (k) {
			tokens.appendChild(h('button', {type: 'button', 'class': 'btn btn-sm btn-outline-secondary nfdMono', title: t('fmt_tok_' + k), onclick: function () { insert(k); }, text: k}));
		});
		DATE_SEPS.forEach(function (s) {
			tokens.appendChild(h('button', {type: 'button', 'class': 'btn btn-sm btn-light border nfdMono', onclick: function () { insert(s); }, text: s === ' ' ? '␣' : s}));
		});
		tokens.appendChild(h('button', {type: 'button', 'class': 'btn btn-sm btn-light border', title: t('fmt_clear'), onclick: function () { input.value = ''; st.format = ''; update(); input.focus(); }}, [h('i', {'class': 'bi bi-backspace'})]));
		row.appendChild(h('div', {'class': 'col-12'}, [
			h('label', {'class': 'form-label small mb-1', 'for': pid, text: t('fmt_pattern')}), input, h('div', {'class': 'invalid-feedback'}), tokens,
			h('div', {'class': 'form-text', text: t('fmt_date_help')})
		]));
		var sid = id('sample');
		row.appendChild(h('div', {'class': 'col-sm-6'}, [
			h('label', {'class': 'form-label small mb-1', 'for': sid, text: t('fmt_sample')}),
			h('input', {'class': 'form-control form-control-sm nfdMono', id: sid, value: st.sample, oninput: function () { st.sample = this.value; update(); }}),
			h('div', {'class': 'form-text', text: t('fmt_sample_date_help')})
		]));
	}

	// ------------------------------------------------------------------ number
	function renderNumber(row) {
		var s = spec();
		function set(part, v) { var x = spec(); x[part] = v; st.format = NuFormatLib.numberPattern(x); update(); }
		function sel(label, part, options, cls) {
			var i = id(part), e = h('select', {'class': 'form-select form-select-sm', id: i, onchange: function () { set(part, this.value); }});
			options.forEach(function (o) { var op = h('option', {value: o[0], text: o[1]}); e.appendChild(op); });
			e.value = String(s[part]);
			return h('div', {'class': cls || 'col-sm-3'}, [h('label', {'class': 'form-label small mb-1', 'for': i, text: t(label)}), e]);
		}
		function txt(label, part, ph, cls) {
			var i = id(part);
			return h('div', {'class': cls || 'col-sm-3'}, [h('label', {'class': 'form-label small mb-1', 'for': i, text: t(label)}),
				h('input', {'class': 'form-control form-control-sm nfdMono', id: i, value: s[part], placeholder: ph, maxlength: '8', oninput: function () { set(part, this.value.replace(/[0-9|]/g, '')); }})]);
		}
		row.appendChild(txt('fmt_prefix', 'prefix', 'CHF '));
		row.appendChild(sel('fmt_thousands', 'sep', [['', t('fmt_none')], ['\'', '1\'000'], [',', '1,000'], ['.', '1.000'], [' ', '1 000']]));
		row.appendChild(sel('fmt_decimal', 'dec', [['.', '0.5'], [',', '0,5']]));
		row.appendChild(sel('fmt_places', 'places', [0, 1, 2, 3, 4, 5, 6].map(function (n) { return [String(n), String(n)]; })));
		row.appendChild(txt('fmt_suffix', 'suffix', ' %'));
		var nid = id('neg'), neg = h('select', {'class': 'form-select form-select-sm', id: nid, onchange: function () { st.negative = this.value; update(); }});
		[['minus', '-1\'234.50'], ['brackets', '(1\'234.50)'], ['red', t('fmt_neg_red')], ['red_brackets', t('fmt_neg_red_brackets')]].forEach(function (o) { neg.appendChild(h('option', {value: o[0], text: o[1]})); });
		neg.value = st.negative;
		row.appendChild(h('div', {'class': 'col-sm-5'}, [h('label', {'class': 'form-label small mb-1', 'for': nid, text: t('fmt_negative')}), neg]));
		row.appendChild(h('div', {'class': 'col-sm-4 d-flex align-items-end'}, [h('div', {'class': 'form-text', text: t('fmt_number_help')})]));
		var hidden = h('input', {type: 'hidden', 'data-err': 'format'});
		row.appendChild(h('div', {'class': 'col-12 d-none'}, [hidden, h('div', {'class': 'invalid-feedback'})]));
	}

	// ------------------------------------------------------------------ text mask
	function renderText(row) {
		var mid = id('mask');
		row.appendChild(h('div', {'class': 'col-12'}, [
			h('label', {'class': 'form-label small mb-1', 'for': mid, text: t('fmt_mask')}),
			h('input', {'class': 'form-control nfdMono', id: mid, value: st.format, 'data-err': 'format', maxlength: String(D.max), spellcheck: 'false', autocomplete: 'off',
				placeholder: 'CH99 9999 9999 9999 9999 9', oninput: function () { st.format = this.value; update(); }}),
			h('div', {'class': 'invalid-feedback'}),
			h('div', {'class': 'nfdLegend small mt-2'}, [
				h('span', {}, [h('code', {text: '9'}), ' ' + t('fmt_mask_9')]), h('span', {}, [h('code', {text: 'a'}), ' ' + t('fmt_mask_a')]),
				h('span', {}, [h('code', {text: 'A'}), ' ' + t('fmt_mask_A')]), h('span', {}, [h('code', {text: '*'}), ' ' + t('fmt_mask_star')]),
				h('span', {}, [h('code', {text: '\\'}), ' ' + t('fmt_mask_escape')])
			]),
			h('div', {'class': 'd-flex flex-wrap gap-1 mt-2'}, [['CH99 9999 9999 9999 9999 9', 'IBAN CH'], ['+41 99 999 99 99', t('fmt_ex_phone')], ['9999', t('fmt_ex_zip')],
				['999.9999.9999.99', t('fmt_ex_ahv')], ['AAA-999', t('fmt_ex_code')]].map(function (x) {
				return h('button', {type: 'button', 'class': 'btn btn-sm btn-light border', onclick: function () { st.format = x[0]; document.getElementById(mid).value = x[0]; update(); }}, [x[1]]);
			}))
		]));
		var sid = id('store'), store = h('select', {'class': 'form-select form-select-sm', id: sid, onchange: function () { st.store = this.value; update(); }});
		[['masked', t('fmt_store_masked')], ['raw', t('fmt_store_raw')]].forEach(function (o) { store.appendChild(h('option', {value: o[0], text: o[1]})); });
		store.value = st.store;
		row.appendChild(h('div', {'class': 'col-sm-6'}, [h('label', {'class': 'form-label small mb-1', 'for': sid, text: t('fmt_store')}), store]));
		var cid = id('compl'), compl = h('input', {'class': 'form-check-input', type: 'checkbox', role: 'switch', id: cid, onchange: function () { st.complete = this.checked; update(); }});
		compl.checked = !!st.complete;
		row.appendChild(h('div', {'class': 'col-sm-6 d-flex align-items-end'}, [h('div', {'class': 'form-check form-switch mb-1'}, [compl, h('label', {'class': 'form-check-label small', 'for': cid, text: t('fmt_complete')})])]));
		var tid = id('try');
		row.appendChild(h('div', {'class': 'col-sm-6'}, [
			h('label', {'class': 'form-label small mb-1', 'for': tid, text: t('fmt_try')}),
			h('input', {'class': 'form-control form-control-sm nfdMono', id: tid, value: st.sample, autocomplete: 'off', oninput: function (e) {
				if (!/^delete/.test(e.inputType || '') && this.selectionStart === this.value.length && st.format) this.value = NuFormatLib.mask(this.value, st.format).masked;
				st.sample = this.value; update();
			}})
		]));
	}

	// ------------------------------------------------------------------ preview
	function update() {
		var k = key(), err = '';
		if (!st.format) err = t('fmt_err_required');
		else if (st.format.length > D.max) err = t('fmt_err_length').replace('%d', D.max);
		else if (/\|/.test(st.format)) err = t('fmt_err_chars');
		document.getElementById('nfdKey').textContent = k;
		document.getElementById('nfdKeyErr').textContent = err;
		NuFormatLib.setOptions(k, {negative: st.negative, store: st.store, complete: st.complete});
		var p = document.getElementById('nfdPreview');
		p.innerHTML = '';
		function line(input, out, cls) {
			p.appendChild(h('div', {'class': 'nfdPreviewRow'}, [h('span', {'class': 'nfdMono text-body-secondary', text: input}), h('i', {'class': 'bi bi-arrow-right mx-2 text-body-tertiary'}),
				h('span', {'class': 'fw-semibold ' + (cls || ''), text: out})]));
		}
		if (!st.format) return;
		if (st.type === 'Date') {
			line(st.sample, NuFormatLib.add(st.sample, k));
			var back = NuFormatLib.remove(NuFormatLib.add(st.sample, k), k);
			p.appendChild(h('div', {'class': 'small text-body-secondary mt-1', text: t('fmt_read_back') + ': ' + back}));
		}
		if (st.type === 'Number') {
			['1234567.891', '-1234.5', '0.5', '42'].forEach(function (v) { line(v, NuFormatLib.add(v, k), NuFormatLib.cssClass(v, k) ? 'text-danger' : ''); });
		}
		if (st.type === 'Text') {
			var m = NuFormatLib.mask(st.sample || '', st.format);
			if (st.sample) {
				line(st.sample, m.masked);
				p.appendChild(h('div', {'class': 'small mt-1 ' + (m.complete ? 'text-success' : 'text-warning-emphasis'), text: (m.complete ? t('fmt_complete_ok') : t('fmt_incomplete')) + ' · ' + t('fmt_stored_value') + ': ' + (st.store === 'raw' ? m.raw : m.masked)}));
			} else {
				p.appendChild(h('div', {'class': 'small text-body-secondary', text: t('fmt_try_hint')}));
			}
		}
	}

	function showErrors(errors, msg) {
		$$('[data-err]', modal).forEach(function (e) { e.classList.remove('is-invalid'); });
		var box = document.getElementById('nfdError');
		box.classList.add('d-none');
		var rest = [];
		Object.keys(errors || {}).forEach(function (f) {
			var e = modal.querySelector('[data-err="' + f + '"]');
			if (e && e.type !== 'hidden') { e.classList.add('is-invalid'); var fb = e.parentNode.querySelector('.invalid-feedback'); if (fb) fb.textContent = errors[f]; }
			else rest.push(errors[f]);
		});
		if (!errors && msg) rest.push(msg);
		if (rest.length) { box.textContent = rest.join(' '); box.classList.remove('d-none'); }
	}

	function save() {
		var b = document.getElementById('nfdSave');
		b.disabled = true;
		api('save', {id: st.id, type: st.type, format: st.format, description: st.description, 'default': st['default'], negative: st.negative, store: st.store, complete: st.complete})
			.then(function (r) {
				b.disabled = false;
				if (!r.ok) { showErrors(r.errors, r.error); return; }
				if (r.client) NuFormatLib.setRegistry(r.client);
				bsModal.hide();
				if (opts.onSaved) opts.onSaved(r.format, r.formats);
			}, function (e) { b.disabled = false; showErrors(null, e.message); });
	}

	function remove() {
		if (!window.confirm(t('fmt_delete_confirm').replace('%s', key()))) return;
		api('delete', {id: st.id}).then(function (r) {
			if (!r.ok) { showErrors(null, r.error); return; }
			bsModal.hide();
			if (opts.onDeleted) opts.onDeleted(r.formats);
		});
	}

	window.NuFormatDesigner = {init: function (d) { D = d; window.nuFormatDesignerData = d; if (d.client) NuFormatLib.setRegistry(d.client); }, open: open};
})();
