/* nuBuilder Next form builder: object list + properties panel + live preview. Talks to index.php?p=builderapi. */
(function () {
	'use strict';

	var D = JSON.parse(document.getElementById('nuBuilderData').textContent);
	var L = D.labels || {};
	function t(k) { return L[k] || k; }

	// ------------------------------------------------------------------ helpers
	function h(tag, attrs, kids) {
		var e = document.createElement(tag);
		Object.keys(attrs || {}).forEach(function (k) {
			var v = attrs[k];
			if (v === null || v === undefined || v === false) return;
			if (k === 'text') e.textContent = v;
			else if (k === 'html') e.innerHTML = v;
			else if (k.indexOf('on') === 0) e.addEventListener(k.substr(2), v);
			else e.setAttribute(k, v === true ? '' : v);
		});
		(kids || []).forEach(function (c) { if (c) e.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); });
		return e;
	}
	function $(s, r) { return (r || document).querySelector(s); }
	function $$(s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); }
	var uid = 0;
	function nextId(p) { uid++; return 'nb_' + (p || 'f') + uid; }

	// remove button of a list row (0.8.139): the row is crossed out and the same button brings it back; crossed-out rows are
	// left out when the panel (or the new form) is saved – window.nuToggleRemoved in nuapp.js
	function removeBtn(row, cls) {
		var b = h('button', {type: 'button', 'class': cls || 'btn btn-link btn-sm px-1 text-danger', title: t('remove'), 'aria-label': t('remove'), 'aria-pressed': 'false'}, [h('i', {'class': 'bi bi-x-circle'})]);
		b.addEventListener('click', function () {
			window.nuToggleRemoved(row, b, {remove: t('remove'), undo: t('remove_undo'), pending: t('remove_pending')});
			setDirty(true);
		});
		return b;
	}

	// form drafts (0.8.386): DR = the draft the builder works on (draft mode), LD = the open draft shown in live mode
	var DR = D.draft_mode ? D.draft : null, LD = D.draft_mode ? null : (D.draft || null);
	var DP = D.draft_policy || {};

	function api(action, data) {
		data = data || {};
		var send = Object.assign({action: action, form_id: D.form_id}, DR && !data.no_draft ? {draft_id: DR.id, rev: DR.rev} : {}, data);
		return fetch('index.php?p=builderapi', {
			method: 'POST', credentials: 'same-origin',
			headers: {'Content-Type': 'application/json', 'X-CSRF-Token': D.csrf},
			body: JSON.stringify(send)
		}).then(function (r) { return r.json().catch(function () { return {ok: false, error: 'HTTP ' + r.status}; }); })
			.then(function (j) {
				if (!j.ok && j.code === 'draft_open_confirm' && !data.live_ok) {          // live change while a draft is open (globeadmin)
					if (window.confirm(j.error)) return api(action, Object.assign({}, data, {live_ok: true}));
					throw new Error('');
				}
				if (!j.ok && j.code === 'draft_rev' && DR) {                             // changed by somebody else meanwhile: reload
					return api('state', {}).then(function (st) { if (window.nbLoad) window.nbLoad(st); throw new Error(j.error); });
				}
				if (!j.ok) throw new Error(j.error || 'Error');
				if (DR && j.draft) DR = j.draft;
				if (!DR && j.live_draft !== undefined) LD = j.live_draft;
				if (window.nbRenderDraftBar) window.nbRenderDraftBar();
				return j;
			});
	}

	function flash(ok, msg) {
		var hint = $('#nbSavedHint');
		if (ok && hint) { hint.classList.remove('d-none'); clearTimeout(flash.t); flash.t = setTimeout(function () { hint.classList.add('d-none'); }, 2200); }
		var box = $('#nbPanelError');
		if (box) { box.classList.toggle('d-none', ok); box.textContent = ok ? '' : msg; }
	}

	// ------------------------------------------------------------------ "new form" wizard
	if (D['new']) {
		var form = $('#nbNewForm');
		var stale = $('#nbStale');
		if (stale) stale.remove();                    // this script is current: no "outdated files" warning
		var COLTYPES = ['text', 'longtext', 'int', 'bigint', 'decimal', 'date', 'datetime', 'time', 'bool', 'id'];
		var DEFLEN = {text: '255', decimal: '15,2'};

		function mode() { return $('#nb_mode_new').checked ? 'new' : 'existing'; }
		function applyMode() {
			var launch = $('#nb_type').value === 'launch' || $('#nb_type').value === 'page';   // page forms (0.8.387): no table either
			$('.nbTableSection').classList.toggle('d-none', launch);
			var lay = $('#nb_layout');
			if (lay) lay.closest('[class*="col-"]').classList.toggle('d-none', $('#nb_type').value === 'page');
			$$('.nbExisting').forEach(function (e) { e.classList.toggle('d-none', launch || mode() !== 'existing'); });
			$$('.nbNew').forEach(function (e) { e.classList.toggle('d-none', launch || mode() !== 'new'); });
			if (mode() === 'new' && !$('#nbColRows').children.length) addCol();
		}
		$$('input[name=table_mode]').forEach(function (r) { r.addEventListener('change', applyMode); });
		$('#nb_type').addEventListener('change', applyMode);

		// existing tables
		var existingTables = [];
		api('tables', {}).then(function (r) {
			existingTables = r.tables.map(function (x) { return x.toLowerCase(); });
			var s = $('#nb_table');
			r.tables.forEach(function (tb) { s.appendChild(h('option', {value: tb, text: tb})); });
			// searchable (Tom Select, like Select2): type to filter the tables; the select keeps its value and change event
			if (window.TomSelect) new TomSelect(s, {create: false, maxOptions: null, allowEmptyOption: false, placeholder: t('please_select'),
				searchField: ['text'], sortField: [{field: '$order'}], render: {no_results: function () { return '<div class="no-results">' + t('no_records') + '</div>'; }}});
		}).catch(function () {});
		$('#nb_table').addEventListener('change', function () {
			var info = $('#nbColumnInfo'), pk = $('#nb_pk');
			pk.innerHTML = '';
			pk.appendChild(h('option', {value: '', text: t('builder_pk_auto')}));
			info.textContent = '';
			if (!this.value) return;
			api('columns', {table: this.value}).then(function (r) {
				var auto = r.columns.filter(function (c) { return c.auto; })[0];
				r.columns.forEach(function (c) { pk.appendChild(h('option', {value: c.name, text: c.name + (c.auto ? ' (auto)' : '')})); });
				pk.options[0].textContent = t('builder_pk_auto') + (auto ? ': ' + auto.name : '');
				info.textContent = t('builder_columns_found').replace('%d', r.columns.length);
			}).catch(function (e) { info.textContent = e.message; });
		});

		// new table: column grid
		function addCol(c) {
			c = c || {};
			var tr = h('tr', {});
			var name = h('input', {'class': 'form-control form-control-sm', 'data-f': 'name', value: c.name || '', maxlength: 64, autocomplete: 'off', spellcheck: 'false', 'aria-label': t('builder_col_name'), pattern: '[A-Za-z_][A-Za-z0-9_]*'});
			var label = h('input', {'class': 'form-control form-control-sm', 'data-f': 'label', value: c.label || '', maxlength: 100, 'aria-label': t('builder_obj_label')});
			var type = h('select', {'class': 'form-select form-select-sm', 'data-f': 'type', 'aria-label': t('builder_col_type')});
			COLTYPES.forEach(function (x) { type.appendChild(h('option', {value: x, text: t('builder_coltype_' + x)})); });
			type.value = c.type || 'text';
			var len = h('input', {'class': 'form-control form-control-sm nbLen', 'data-f': 'length', value: c.length || DEFLEN[type.value] || '', maxlength: 8, 'aria-label': t('builder_col_length')});
			var req = h('input', {type: 'checkbox', 'class': 'form-check-input', 'data-f': 'required', 'aria-label': t('builder_col_required')});
			function syncLen() {
				var uses = type.value === 'text' || type.value === 'decimal';
				len.disabled = !uses;
				if (!uses) len.value = ''; else if (!len.value) len.value = DEFLEN[type.value];
				req.disabled = type.value === 'bool';
				if (req.disabled) req.checked = false;
			}
			type.addEventListener('change', function () { len.value = ''; syncLen(); });
			syncLen();
			name.addEventListener('input', function () {           // label follows the name until edited
				if (!label.dataset.touched) label.value = this.value.replace(/^[a-z]{2,5}_/, '').replace(/_/g, ' ').replace(/\b\w/g, function (m) { return m.toUpperCase(); });
			});
			label.addEventListener('input', function () { this.dataset.touched = '1'; });
			tr.appendChild(h('td', {}, [name]));
			tr.appendChild(h('td', {}, [label]));
			tr.appendChild(h('td', {}, [type]));
			tr.appendChild(h('td', {}, [len]));
			tr.appendChild(h('td', {'class': 'text-center'}, [req]));
			tr.appendChild(h('td', {'class': 'text-nowrap'}, [
				h('button', {type: 'button', 'class': 'btn btn-link btn-sm px-1', 'aria-label': t('builder_up'), onclick: function () { if (tr.previousElementSibling) tr.parentNode.insertBefore(tr, tr.previousElementSibling); }}, [h('i', {'class': 'bi bi-chevron-up'})]),
				removeBtn(tr)
			]));
			$('#nbColRows').appendChild(tr);
			if (!c.name) name.focus();
		}
		$('#nbAddCol').addEventListener('click', function () { addCol(); });

		$('#nb_new_table').addEventListener('input', function () {
			var pk = $('#nb_new_pk');
			if (!pk.dataset.touched) pk.placeholder = (this.value || 'table') + '_id';
		});
		$('#nb_new_pk').addEventListener('input', function () { this.dataset.touched = '1'; });

		$('#nb_desc').addEventListener('input', function () {
			var code = $('#nb_code');
			if (!code.dataset.touched) code.value = this.value.replace(/[^A-Za-z0-9]+(.)?/g, function (m, c) { return c ? c.toUpperCase() : ''; }).replace(/^./, function (c) { return c.toLowerCase(); });
		});
		$('#nb_code').addEventListener('input', function () { this.dataset.touched = '1'; });

		form.addEventListener('submit', function (e) {
			e.preventDefault();
			var err = $('#nbNewError');
			err.classList.add('d-none');
			var d = {description: $('#nb_desc').value.trim(), code: $('#nb_code').value.trim(), type: $('#nb_type').value, layout: $('#nb_layout').value, table_mode: mode()};
			if (mode() === 'existing') {
				d.table = $('#nb_table').value;
				d.primary_key = $('#nb_pk').value;
				d.generate = $('#nb_generate').checked;
			} else {
				d.table = $('#nb_new_table').value.trim();
				d.primary_key = $('#nb_new_pk').value.trim();
				d.audit = $('#nb_audit').checked;
				d.nulog = $('#nb_nulog').checked;
				d.columns = $$('#nbColRows tr:not(.nuRowRemoved)').map(function (tr) {
					var c = {};
					$$('[data-f]', tr).forEach(function (x) { c[x.getAttribute('data-f')] = x.type === 'checkbox' ? x.checked : x.value; });
					return c;
				});
				if (d.table && existingTables.indexOf(d.table.toLowerCase()) >= 0) {
					err.textContent = t('builder_err_table_exists').replace('%s', d.table);
					err.classList.remove('d-none');
					return;
				}
			}
			var btn = form.querySelector('button[type=submit]');
			btn.disabled = true;
			api('form.create', d).then(function (r) { window.location.href = 'index.php?p=builder&f=' + encodeURIComponent(r.form_id); })
				.catch(function (x) { err.textContent = x.message; err.classList.remove('d-none'); btn.disabled = false; });
		});
		applyMode();
		return;
	}

	// ------------------------------------------------------------------ workspace state
	var S = null;                    // server state: form, tabs, objects, browse, events, php, lookup_php
	var sel = {kind: 'form'};        // {kind: form|code|tabs|browse|object, id}
	var RO = !!D.readonly;
	var tableColumns = [];

	var TYPES = ['input', 'select', 'textarea', 'editor', 'lookup', 'calc', 'display', 'subform', 'contentbox', 'html', 'chart', 'word', 'run', 'image'];
	var FILES = [];
	var ICON = {input: 'bi-input-cursor-text', select: 'bi-menu-button-wide', textarea: 'bi-textarea-t', editor: 'bi-journal-richtext', display: 'bi-display', calc: 'bi-calculator',
		lookup: 'bi-search', subform: 'bi-table', html: 'bi-code', word: 'bi-fonts', contentbox: 'bi-bounding-box', run: 'bi-play-btn', image: 'bi-image'};
	var INPUT_TYPES = ['text', 'nuDate', 'nuNumber', 'number', 'checkbox', 'button', 'date', 'datetime-local', 'time', 'email', 'tel', 'url', 'password', 'month', 'week', 'color', 'range', 'file', 'nuAutoNumber', 'nuScroll'];
	var FMT_TYPES = {D: 'Date', N: 'Number', T: 'Text'};
	if (window.NuFormatDesigner && D.formatDesigner) NuFormatDesigner.init(D.formatDesigner);

	/**
	 * Format picker: select with saved formats (zzzzsys_format) and built-ins, "+" opens the format designer,
	 * the pencil edits the selected saved format. letters: 'D', 'N', 'T' or combinations.
	 */
	function formatPicker(value, letters, attrs) {
		var sel = h('select', Object.assign({'class': 'form-select form-select-sm'}, attrs || {}));
		function fill(v) {
			sel.innerHTML = '';
			sel.appendChild(h('option', {value: '', text: '—'}));
			var list = (D.formats || []).filter(function (f) { return letters.indexOf(f.value.charAt(0)) >= 0; });
			var saved = h('optgroup', {label: t('builder_fmt_saved')}), builtin = h('optgroup', {label: t('builder_fmt_builtin')});
			list.forEach(function (f) {
				(f.saved ? saved : builtin).appendChild(h('option', {value: f.value, text: f.value + (f['default'] ? ' ★' : '') + (f.description ? ' – ' + f.description : '')}));
			});
			if (saved.children.length) sel.appendChild(saved);
			if (builtin.children.length) sel.appendChild(builtin);
			if (v && !list.some(function (f) { return f.value === v; })) sel.appendChild(h('option', {value: v, text: v}));
			sel.value = v || '';
			sync();
		}
		var rights = (D.formatDesigner || {}).rights || {};
		var types = letters.split('').map(function (l) { return FMT_TYPES[l]; });
		function changed(v) { fill(v); sel.dispatchEvent(new Event('change', {bubbles: true})); }
		var add = h('button', {type: 'button', 'class': 'btn btn-sm btn-outline-secondary', title: t('builder_fmt_new'), 'aria-label': t('builder_fmt_new'), disabled: RO || !rights.create || !window.NuFormatDesigner,
			onclick: function () { NuFormatDesigner.open({types: types, type: FMT_TYPES[(sel.value || letters).charAt(0)], onSaved: function (f, all) { D.formats = all; changed(f.key); }}); }},
			[h('i', {'class': 'bi bi-plus-lg'})]);
		var edit = h('button', {type: 'button', 'class': 'btn btn-sm btn-outline-secondary', title: t('builder_fmt_edit'), 'aria-label': t('builder_fmt_edit'),
			onclick: function () {
				var f = (D.formats || []).filter(function (x) { return x.value === sel.value; })[0];
				if (!f) return;
				if (f.saved) NuFormatDesigner.open({id: f.id, onSaved: function (n, all) { D.formats = all; changed(n.key); }, onDeleted: function (all) { D.formats = all; changed(''); }});
				else NuFormatDesigner.open({types: types, type: FMT_TYPES[f.value.charAt(0)], format: f.value.substring(2), onSaved: function (n, all) { D.formats = all; changed(n.key); }});
			}}, [h('i', {'class': 'bi bi-pencil'})]);
		function sync() {
			var f = (D.formats || []).filter(function (x) { return x.value === sel.value; })[0];
			edit.disabled = RO || !f || !window.NuFormatDesigner || !(f.saved ? rights.save || rights['delete'] : rights.create);
		}
		sel.addEventListener('change', sync);
		fill(value);
		return {el: h('div', {'class': 'nbFormatPick'}, [sel, add, edit]), select: sel};
	}
	var EVENTS = ['onchange', 'onclick', 'onblur', 'onfocus', 'oninput', 'onkeydown', 'onkeyup', 'ondblclick', 'afterinsertrow', 'clickdelete'];

	function objById(id) { return S.objects.filter(function (o) { return o.zzzzsys_object_id === id; })[0]; }
	function nunext(o) { try { var j = JSON.parse(o.sob_all_json || '{}'); return (j && j.nunext) || {}; } catch (e) { return {}; } }

	function load(state) {
		S = state;
		if (DR && state.draft) DR = state.draft;
		if (!DR && state.live_draft !== undefined) LD = state.live_draft;
		$('#nbTitle').textContent = S.form.sfo_description || '';
		$('#nbType').textContent = t('form_type_' + (S.form.sfo_type || 'browseedit'));
		renderTabFilter();
		renderObjects();
	}

	// ------------------------------------------------------------------ sidebar
	function renderTabFilter() {
		var f = $('#nbTabFilter'), cur = f.value;
		f.innerHTML = '';
		f.appendChild(h('option', {value: '', text: t('builder_all_tabs')}));
		S.tabs.forEach(function (tb) { f.appendChild(h('option', {value: tb.zzzzsys_tab_id, text: tb.syt_title})); });
		f.value = cur && S.tabs.some(function (tb) { return tb.zzzzsys_tab_id === cur; }) ? cur : '';
	}

	function tabTitle(id) { var tb = S.tabs.filter(function (x) { return x.zzzzsys_tab_id === id; })[0]; return tb ? tb.syt_title : '—'; }

	function renderObjects() {
		var list = $('#nbObjectList'), tab = $('#nbTabFilter').value, q = $('#nbObjSearch').value.trim().toLowerCase();
		list.innerHTML = '';
		var shown = S.objects.filter(function (o) {
			if (tab && o.sob_all_zzzzsys_tab_id !== tab) return false;
			return !q || (o.sob_all_id + ' ' + (o.sob_all_label || '') + ' ' + o.sob_all_type).toLowerCase().indexOf(q) >= 0;
		});
		$('#nbObjCount').textContent = S.objects.length;
		var lastTab = null;
		shown.forEach(function (o) {
			if (!tab && o.sob_all_zzzzsys_tab_id !== lastTab) {
				lastTab = o.sob_all_zzzzsys_tab_id;
				list.appendChild(h('li', {'class': 'list-group-item nbTabHeader', text: tabTitle(lastTab)}));
			}
			var active = sel.kind === 'object' && sel.id === o.zzzzsys_object_id;
			var label = String(o.sob_all_label || '').replace(/<[^>]*>/g, '').trim();
			var item = h('li', {'class': 'list-group-item nbObj' + (active ? ' active' : ''), role: 'option', 'aria-selected': active ? 'true' : 'false', 'data-id': o.zzzzsys_object_id}, [
				h('button', {type: 'button', 'class': 'nbObjMain', onclick: guarded(function () { select('object', o.zzzzsys_object_id); })}, [
					h('i', {'class': 'bi ' + (ICON[o.sob_all_type] || 'bi-square') + ' nbObjIcon', 'aria-hidden': 'true'}),
					h('span', {'class': 'nbObjText'}, [h('span', {'class': 'nbObjId', text: o.sob_all_id}), label ? h('span', {'class': 'nbObjLabel', text: label}) : null]),
					h('span', {'class': 'badge nbObjType', text: o.sob_all_type + (o.sob_all_type === 'input' && o.sob_input_type ? ':' + o.sob_input_type : '')})
				]),
				RO ? null : h('span', {'class': 'nbObjMove'}, [
					h('button', {type: 'button', 'class': 'btn btn-link btn-sm', title: t('builder_up'), 'aria-label': t('builder_up'), onclick: function () { move(o, -1); }}, [h('i', {'class': 'bi bi-chevron-up'})]),
					h('button', {type: 'button', 'class': 'btn btn-link btn-sm', title: t('builder_down'), 'aria-label': t('builder_down'), onclick: function () { move(o, 1); }}, [h('i', {'class': 'bi bi-chevron-down'})])
				])
			]);
			list.appendChild(item);
		});
		if (!shown.length) list.appendChild(h('li', {'class': 'list-group-item text-body-secondary small', text: t('builder_no_objects')}));
		$$('.nbSections [data-nb-section]').forEach(function (b) { b.classList.toggle('active', sel.kind === b.getAttribute('data-nb-section')); });
	}

	function move(o, dir) {
		var same = S.objects.filter(function (x) { return x.sob_all_zzzzsys_tab_id === o.sob_all_zzzzsys_tab_id; });
		var i = same.indexOf(o), j = i + dir;
		if (j < 0 || j >= same.length) return;
		var ids = S.objects.map(function (x) { return x.zzzzsys_object_id; });
		var a = ids.indexOf(o.zzzzsys_object_id), b = ids.indexOf(same[j].zzzzsys_object_id);
		ids[a] = same[j].zzzzsys_object_id; ids[b] = o.zzzzsys_object_id;
		api('object.order', {ids: ids}).then(function (r) { load(r); preview(); }).catch(function (e) { alert(e.message); });
	}

	$('#nbTabFilter').addEventListener('change', renderObjects);
	$('#nbObjSearch').addEventListener('input', renderObjects);
	$$('.nbSections [data-nb-section]').forEach(function (b) { b.addEventListener('click', guarded(function () { select(b.getAttribute('data-nb-section')); })); });

	var addMenu = $('.nbAddMenu');
	TYPES.forEach(function (tp) {
		addMenu.appendChild(h('li', {}, [h('button', {type: 'button', 'class': 'dropdown-item', onclick: function () { addObject(tp); }},
			[h('i', {'class': 'bi ' + ICON[tp] + ' me-2'}), t('bf_type_' + tp)])]));
	});

	// ------------------------------------------------------------------ field rendering
	/** spec: {key, label, kind: text|number|select|textarea|code|switch, options:[[v,l]], list:[..], help, cls} */
	function field(spec, value) {
		var id = nextId(), input;
		var v = value === null || value === undefined ? '' : String(value);
		var common = {id: id, 'data-key': spec.key, 'class': '', disabled: RO || spec.disabled};
		if (spec.kind === 'select') {
			input = h('select', Object.assign(common, {'class': 'form-select form-select-sm'}));
			(spec.options || []).forEach(function (o) { input.appendChild(h('option', {value: o[0], text: o[1]})); });
			if (v !== '' && !(spec.options || []).some(function (o) { return String(o[0]) === v; })) input.appendChild(h('option', {value: v, text: v}));
			input.value = v;
		} else if (spec.kind === 'format') {
			var fp = formatPicker(v, spec.letters || 'DNT', Object.assign(common, {'class': 'form-select form-select-sm'}));
			input = fp.el;
		} else if (spec.kind === 'textarea' || spec.kind === 'code') {
			input = h('textarea', Object.assign(common, {'class': 'form-control form-control-sm' + (spec.kind === 'code' ? ' nbCode' : ''), rows: spec.rows || (spec.kind === 'code' ? 6 : 3), spellcheck: spec.kind === 'code' ? 'false' : null}));
			input.value = v;
		} else if (spec.kind === 'switch') {
			input = h('input', Object.assign(common, {type: 'checkbox', role: 'switch', 'class': 'form-check-input'}));
			input.checked = v === '1' || (v === '' && spec.default === '1');
			return h('div', {'class': 'form-check form-switch nbFld ' + (spec.cls || 'col-12')}, [input, h('label', {'class': 'form-check-label', 'for': id, text: t(spec.label)})]);
		} else {
			input = h('input', Object.assign(common, {type: spec.kind === 'number' ? 'number' : 'text', 'class': 'form-control form-control-sm', value: v, autocomplete: 'off', spellcheck: 'false'}));
			if (spec.list) {
				var dl = h('datalist', {id: id + '_dl'});
				spec.list.forEach(function (x) { dl.appendChild(h('option', {value: x})); });
				input.setAttribute('list', id + '_dl');
				input = h('div', {}, [input, dl]);
			}
			// HTML attributes: button that opens the attribute editor (assets/js/nuattreditor.js)
			if (spec.attrEditor && window.nuAttributeEditor) {
				var attrIn = input;
				var attrBtn = h('button', {type: 'button', 'class': 'btn btn-outline-secondary nbAttrBtn', title: t('builder_attr_title'), 'aria-label': t('builder_attr_title'), disabled: RO || spec.disabled},
					[h('i', {'class': 'bi bi-sliders'})]);
				attrBtn.addEventListener('click', function () {
					window.nuAttributeEditor.open(attrIn.value, function (v) {
						attrIn.value = v;
						attrIn.dispatchEvent(new Event('input', {bubbles: true}));
						attrIn.dispatchEvent(new Event('change', {bubbles: true}));
						attrIn.focus();
					}, L);
				});
				input = h('div', {'class': 'input-group input-group-sm'}, [attrIn, attrBtn]);
			}
		}
		return h('div', {'class': 'nbFld ' + (spec.cls || 'col-12')}, [   // nbFld: packed tighter in a wide panel (nubuilder.css, 0.8.449)
			h('label', {'class': 'form-label small mb-1', 'for': id, text: t(spec.label)}),
			input,
			spec.help ? h('div', {'class': 'form-text', text: t(spec.help)}) : null
		]);
	}

	function collect(root) {
		var out = {};
		$$('[data-key]', root).forEach(function (e) { out[e.getAttribute('data-key')] = e.type === 'checkbox' ? (e.checked ? '1' : '0') : e.value; });
		return out;
	}

	function section(title, kids) {
		return h('fieldset', {'class': 'nbFieldset'}, [h('legend', {'class': 'nbLegend', text: t(title)}), h('div', {'class': 'row g-2'}, kids)]);
	}

	// unsaved changes in the open panel: the Save button turns red
	function setDirty(on) { var b = $('#nbSave'); if (b) b.classList.toggle('nbDirty', !!on); }
	function isDirty() { return !!$('#nbSave.nbDirty'); }
	var curSave = null;   // save function of the open panel
	// unsaved panel: leaving the page or opening another panel asks Save / Discard / Keep editing (nuLeaveGuard, assets/js/nuapp.js)
	function savePanel() {
		var b = $('#nbSave');
		if (!curSave || !b) return Promise.reject(new Error(''));
		b.disabled = true;
		return Promise.resolve(curSave()).then(function () { flash(true); setDirty(false); b.disabled = false; },
			function (e) { flash(false, e.message); b.disabled = false; throw e; });
	}
	if (window.nuLeaveGuard) window.nuLeaveGuard.add({
		dirty: isDirty,
		discard: function () { setDirty(false); },
		save: function (go) { savePanel().then(function () { if (typeof go === 'function') go(); else window.location.href = go; }, function () { /* error shown */ }); }
	});
	function guarded(fn) { return function () { var a = arguments, me = this; if (isDirty() && window.nuLeaveGuard) window.nuLeaveGuard.ask(function () { fn.apply(me, a); }); else fn.apply(me, a); }; }

	function panel(title, body, onSave, extraButtons) {
		$('#nbPanelTitle').textContent = title;
		var p = $('#nbPanel');
		p.innerHTML = '';
		p.appendChild(body);
		if (!p.nbDirtyBound) {
			p.nbDirtyBound = true;
			p.addEventListener('input', function (e) { if (e.isTrusted) setDirty(true); });
			p.addEventListener('change', function (e) { if (e.isTrusted) setDirty(true); });
		}
		p.appendChild(h('div', {'class': 'alert alert-danger d-none mt-3 mb-0 py-2', id: 'nbPanelError', role: 'alert'}));
		// the panel's buttons sit in the toolbar at the top, next to "Open form" / "Delete form"
		var top = $('#nbTopActions');
		top.innerHTML = '';
		curSave = !RO && onSave ? onSave : null;
		if (!RO && onSave) {
			(extraButtons || []).forEach(function (b) { top.appendChild(b); });
			top.appendChild(h('button', {type: 'button', 'class': 'btn nuBtnAct nuBtnSave btn-sm', id: 'nbSave', title: t('save') + ': ' + title + ' (Ctrl+S)', onclick: function () {
				savePanel().catch(function () { /* error shown */ });
			}}, [h('i', {'class': 'bi bi-floppy me-1'}), t('save')]));
		}
	}

	// Ctrl+S / Cmd+S saves the open panel
	document.addEventListener('keydown', function (e) {
		if ((e.ctrlKey || e.metaKey) && !e.altKey && (e.key === 's' || e.key === 'S')) {
			var b = $('#nbSave');
			if (!b) return;
			e.preventDefault();
			if (document.activeElement && document.activeElement.blur) document.activeElement.blur();   // commit CodeMirror / inputs
			if (!b.disabled) b.click();
		}
	});

	function formOptions(filterType) {
		return [['', t('please_select')]].concat((D.forms || []).filter(function (f) { return !filterType || filterType.indexOf(f[2]) >= 0; }).map(function (f) { return [f[0], f[1]]; }));
	}

	// ------------------------------------------------------------------ panels
	function select(kind, id) {
		sel = {kind: kind, id: id};
		if (kind === 'browse' && S && S.form.sfo_type === 'browseedit' && previewMode() !== 'browse') setPreviewMode('browse', true);   // browse columns: show the list
		renderObjects();
		({form: panelForm, code: panelCode, tabs: panelTabs, browse: panelBrowse, object: panelObject, history: panelHistory, compare: panelCompare})[kind](id);
		upgradeEditors($('#nbPanel'));
		refreshEditors($('#nbPanel'));
		if (kind === 'object') highlight(objById(id));
	}

	var formTab = 'general';   // open tab of the form settings
	function panelForm() {
		var f = S.form;
		var general = h('div', {'class': 'nbFormTab', 'data-tab': 'general', role: 'tabpanel'}, [
			section('builder_sec_general', [
				field({key: 'sfo_description', label: 'builder_description', cls: 'col-sm-7'}, f.sfo_description),
				field({key: 'sfo_code', label: 'builder_code', cls: 'col-sm-5'}, f.sfo_code),
				field({key: 'sfo_type', label: 'builder_type', kind: 'select', cls: 'col-sm-4', options: ['browseedit', 'browse', 'edit', 'launch', 'subform'].map(function (x) { return [x, t('form_type_' + x)]; })}, f.sfo_type),
				field({key: 'sfo_group', label: 'builder_group', cls: 'col-sm-4'}, f.sfo_group),
				field({key: 'sfo_status', label: 'builder_status', kind: 'select', cls: 'col-sm-4', options: [['1', t('builder_status_1')], ['0', t('builder_status_0')], ['3', t('builder_status_3')]]}, f.sfo_status || '1'),
				field({key: 'sfo_table', label: 'builder_table', cls: 'col-sm-7'}, f.sfo_table),
				field({key: 'sfo_primary_key', label: 'builder_primary_key', cls: 'col-sm-5'}, f.sfo_primary_key),
				field({key: 'sfo_breadcrumb_title', label: 'builder_breadcrumb', help: 'builder_breadcrumb_help'}, f.sfo_breadcrumb_title)
			]),
			section('form_layout', [
				field({key: 'layout', label: 'form_layout', kind: 'select', cls: 'col-sm-6', options: ['absolute', 'auto', 'grid'].map(function (x) { return [x, t('form_layout_' + x)]; })}, f.layout),
				h('div', {'class': 'col-sm-6 d-flex align-items-end'}, [RO ? null : h('button', {type: 'button', 'class': 'btn btn-outline-secondary btn-sm w-100', onclick: convertGrid},
					[h('i', {'class': 'bi bi-grid-3x3-gap me-1'}), t('builder_convert_grid')])]),
				h('div', {'class': 'col-12 form-text', text: t('builder_layout_help')})
			]),
		]);
		var browse = h('div', {'class': 'nbFormTab', 'data-tab': 'browse', role: 'tabpanel'}, [
			h('div', {'class': 'row g-2'}, [
				field({key: 'sfo_browse_sql', label: 'builder_browse_sql', kind: 'code', rows: 16, help: 'builder_browse_sql_help'}, f.sfo_browse_sql),
				field({key: 'sfo_browse_rows_per_page', label: 'builder_rows_per_page', kind: 'number', cls: 'col-sm-4'}, f.sfo_browse_rows_per_page),
				field({key: 'sfo_browse_redirect_form_id', label: 'builder_redirect', kind: 'select', cls: 'col-sm-8', options: formOptions(['edit', 'browseedit'])}, f.sfo_browse_redirect_form_id)
			])
		]);
		// tabs General / Browse – the open tab stays open after saving
		var nav = h('ul', {'class': 'nav nav-tabs mb-3 nbFormTabs', role: 'tablist'});
		var panes = [general, browse];
		function showTab(name) {
			formTab = name;
			Array.prototype.forEach.call(nav.querySelectorAll('.nav-link'), function (l) {
				var on = l.getAttribute('data-tab') === name;
				l.classList.toggle('active', on);
				l.setAttribute('aria-selected', on ? 'true' : 'false');
			});
			panes.forEach(function (pn) { pn.classList.toggle('d-none', pn.getAttribute('data-tab') !== name); if (pn.getAttribute('data-tab') === name) refreshEditors(pn); });
		}
		[['general', 'builder_sec_general', 'bi-sliders'], ['browse', 'builder_sec_browse', 'bi-table']].forEach(function (tb) {
			nav.appendChild(h('li', {'class': 'nav-item', role: 'presentation'}, [h('button', {type: 'button', 'class': 'nav-link', role: 'tab', 'data-tab': tb[0],
				onclick: function () {
					showTab(tb[0]);
					if (tb[0] === 'browse' && S.form.sfo_type === 'browseedit' && previewMode() !== 'browse') setPreviewMode('browse', true);   // browse SQL: show the list
				}}, [h('i', {'class': 'bi ' + tb[2] + ' me-1'}), t(tb[1])])]));
		});
		var body = h('div', {}, [nav, general, browse]);
		// edit and launch forms have no list: no Browse tab (the values stay and are saved unchanged)
		function syncBrowseTab() {
			var typeSel = general.querySelector('[data-key="sfo_type"]');
			var none = typeSel && ['edit', 'launch'].indexOf(typeSel.value) !== -1;
			nav.querySelector('[data-tab="browse"]').parentNode.classList.toggle('d-none', none);
			if (none && formTab === 'browse') showTab('general');
		}
		var typeSel = general.querySelector('[data-key="sfo_type"]');
		if (typeSel) typeSel.addEventListener('change', syncBrowseTab);
		showTab(formTab);
		syncBrowseTab();
		panel(t('builder_sec_form'), body, function () {
			return api('form.save', {fields: collect(body)}).then(function (r) { load(r); preview(); });
		});
	}

	function convertGrid() {
		if (!confirm(t('builder_convert_confirm'))) return;
		api('layout.convert', {}).then(function (r) { load(r); select('form'); $('#nbPreviewLayout').value = ''; preview(); flash(true); }).catch(function (e) { flash(false, e.message); });
	}

	function panelCode() {
		var f = S.form;
		var items = [['sfo_javascript', 'builder_js_both'], ['sfo_edit_javascript', 'builder_js_edit'], ['sfo_browse_javascript', 'builder_js_browse'], ['sfo_style', 'builder_css']]
			.concat(['BE', 'BS', 'AS', 'BB', 'BD', 'AD'].map(function (x) { return ['php_' + x, 'builder_php_' + x]; }));
		var nav = h('div', {'class': 'nbCodeNav d-flex flex-wrap gap-1 mb-2', role: 'tablist'});
		var areas = h('div', {});
		items.forEach(function (it, i) {
			var key = it[0], isPhp = key.indexOf('php_') === 0;
			var val = isPhp ? S.php[key.substr(4)] : f[key];
			var area = h('div', {'class': i ? 'd-none' : ''}, [field({key: key, label: it[1], kind: 'code', rows: 18}, val)]);
			areas.appendChild(area);
			var b = h('button', {type: 'button', 'class': 'btn btn-sm ' + (i ? 'btn-outline-secondary' : 'btn-secondary') + (val ? ' nbHasCode' : ''), text: t(it[1]), onclick: function () {
				$$('.nbCodeNav button').forEach(function (x) { x.classList.replace('btn-secondary', 'btn-outline-secondary'); });
				b.classList.replace('btn-outline-secondary', 'btn-secondary');
				Array.prototype.forEach.call(areas.children, function (a) { a.classList.add('d-none'); });
				area.classList.remove('d-none');
				refreshEditors(area);
			}});
			nav.appendChild(b);
		});
		var body = h('div', {}, [nav, areas, h('div', {'class': 'form-text', text: t('builder_code_help')})]);
		panel(t('builder_sec_code'), body, function () {
			var v = collect(body), fields = {}, php = {};
			Object.keys(v).forEach(function (k) { if (k.indexOf('php_') === 0) php[k.substr(4)] = v[k]; else fields[k] = v[k]; });
			return api('form.save', {fields: fields, php: php}).then(function (r) { load(r); preview(); });
		});
	}

	function listEditor(rows, columns, makeRow) {
		var tbody = h('tbody', {});
		function addRow(r) {
			var tr = makeRow(r);
			if (!RO) tr.appendChild(h('td', {'class': 'text-nowrap'}, [
				h('button', {type: 'button', 'class': 'btn btn-link btn-sm px-1', 'aria-label': t('builder_up'), onclick: function () { if (tr.previousElementSibling) tbody.insertBefore(tr, tr.previousElementSibling); }}, [h('i', {'class': 'bi bi-chevron-up'})]),
				h('button', {type: 'button', 'class': 'btn btn-link btn-sm px-1', 'aria-label': t('builder_down'), onclick: function () { if (tr.nextElementSibling) tbody.insertBefore(tr.nextElementSibling, tr); }}, [h('i', {'class': 'bi bi-chevron-down'})]),
				removeBtn(tr)
			]));
			tbody.appendChild(tr);
		}
		rows.forEach(addRow);
		var table = h('div', {'class': 'table-responsive'}, [h('table', {'class': 'table table-sm align-middle nbListTable'}, [
			h('thead', {}, [h('tr', {}, columns.map(function (c) { return h('th', {'class': 'small', text: t(c)}); }).concat([h('th', {})]))]), tbody])]);
		return {el: table, tbody: tbody, add: addRow};
	}

	function cell(input) { return h('td', {}, [input]); }
	function inp(attrs) { return h('input', Object.assign({'class': 'form-control form-control-sm', disabled: RO, autocomplete: 'off'}, attrs)); }
	function sel2(options, value, attrs) {
		var s = h('select', Object.assign({'class': 'form-select form-select-sm', disabled: RO}, attrs));
		options.forEach(function (o) { s.appendChild(h('option', {value: o[0], text: o[1]})); });
		s.value = value || options[0][0];
		return s;
	}

	function panelTabs() {
		var acc = [['0', t('builder_tab_visible')], ['2', t('builder_tab_hidden')], ['3', t('builder_tab_hidden_users')]];
		var ed = listEditor(S.tabs, ['builder_tab_title', 'builder_tab_access'], function (tb) {
			var tr = h('tr', {'data-id': tb.zzzzsys_tab_id || ''});
			tr.appendChild(cell(inp({value: tb.syt_title || '', 'data-f': 'title', 'aria-label': t('builder_tab_title')})));
			tr.appendChild(cell(sel2(acc, tb.syt_access || '0', {'data-f': 'access', 'aria-label': t('builder_tab_access')})));
			return tr;
		});
		// existing tabs: only the globeadmin renames or removes them (new, unsaved rows stay editable)
		if (!RO && !D.globeadmin) $$('tr[data-id]', ed.tbody).forEach(function (tr) {
			if (!tr.getAttribute('data-id')) return;
			var ti = $('[data-f=title]', tr);
			ti.readOnly = true; ti.title = t('builder_tabs_globeadmin');
			var rb = $('.bi-x-circle', tr);
			if (rb) rb.closest('button').remove();
		});
		var body = h('div', {}, [ed.el, RO ? null : h('button', {type: 'button', 'class': 'btn btn-outline-primary btn-sm', onclick: function () { ed.add({syt_title: t('builder_new_tab')}); }},
			[h('i', {'class': 'bi bi-plus-lg me-1'}), t('builder_add_tab')]), h('div', {'class': 'form-text', text: t('builder_tabs_help') + (RO || D.globeadmin ? '' : ' ' + t('builder_tabs_globeadmin'))})]);
		panel(t('builder_sec_tabs'), body, function () {
			var tabs = $$('tr:not(.nuRowRemoved)', ed.tbody).map(function (tr) { return {id: tr.getAttribute('data-id'), title: $('[data-f=title]', tr).value, access: $('[data-f=access]', tr).value}; });
			return api('tabs.save', {tabs: tabs}).then(function (r) { load(r); panelTabs(); preview(); });
		});
	}

	function panelBrowse() {
		var listId = nextId('cols');
		var dl = h('datalist', {id: listId});
		tableColumns.forEach(function (c) { dl.appendChild(h('option', {value: c})); });
		var ed = listEditor(S.browse, ['builder_col_title', 'builder_col_display', 'builder_col_align', 'builder_col_format', 'builder_col_width', 'builder_col_filter'], function (b) {
			var tr = h('tr', {'data-id': b.zzzzsys_browse_id || ''});
			tr.appendChild(cell(inp({value: b.sbr_title || '', 'data-f': 'title', 'aria-label': t('builder_col_title')})));
			tr.appendChild(cell(inp({value: b.sbr_display || '', 'data-f': 'display', list: listId, spellcheck: 'false', 'aria-label': t('builder_col_display')})));
			tr.appendChild(cell(sel2([['l', '←'], ['c', '↔'], ['r', '→']], b.sbr_align || 'l', {'data-f': 'align', 'aria-label': t('builder_col_align')})));
			tr.appendChild(h('td', {}, [formatPicker(b.sbr_format || '', 'DNT', {'data-f': 'format', disabled: RO, 'aria-label': t('builder_col_format')}).el]));
			tr.appendChild(cell(inp({value: b.sbr_width === undefined ? 100 : b.sbr_width, type: 'number', min: 0, 'data-f': 'width', 'class': 'form-control form-control-sm nbNum', 'aria-label': t('builder_col_width')})));
			var flt = h('input', {type: 'checkbox', 'class': 'form-check-input', 'data-f': 'filter', disabled: RO, 'aria-label': t('builder_col_filter')});
			try { flt.checked = !!((JSON.parse(b.sbr_json || '{}').nunext || {}).filter); } catch (e) { flt.checked = false; }
			tr.appendChild(h('td', {'class': 'text-center'}, [flt]));
			return tr;
		});
		var body = h('div', {}, [ed.el, dl, RO ? null : h('button', {type: 'button', 'class': 'btn btn-outline-primary btn-sm', onclick: function () { ed.add({sbr_width: 100}); }},
			[h('i', {'class': 'bi bi-plus-lg me-1'}), t('builder_add_column')]), h('div', {'class': 'form-text', text: t('builder_browse_help')})]);
		panel(t('builder_sec_browse'), body, function () {
			var cols = $$('tr:not(.nuRowRemoved)', ed.tbody).map(function (tr) {
				var o = {id: tr.getAttribute('data-id')};
				$$('[data-f]', tr).forEach(function (e) { o[e.getAttribute('data-f')] = e.type === 'checkbox' ? e.checked : e.value; });
				return o;
			});
			return api('browse.save', {columns: cols}).then(function (r) { load(r); panelBrowse(); preview(); });
		});
	}

	// ------------------------------------------------------------------ object panel
	function objectSpecs(o) {
		var type = o.sob_all_type, it = o.sob_input_type || 'text';
		var tabs = S.tabs.map(function (tb) { return [tb.zzzzsys_tab_id, tb.syt_title]; });
		var boxes = [['', '—']].concat(S.objects.filter(function (x) { return x.sob_all_type === 'contentbox' && x !== o; }).map(function (x) { return [x.sob_all_id, x.sob_all_id]; }));
		var g = nunext(o);
		// run objects: type F form / P procedure / R report (nuBuilder detects it from the id when sob_run_type is empty)
		var run = {id: o.sob_run_zzzzsys_form_id || ''};
		run.isProc = (D.procedures || []).some(function (p) { return p[0] === run.id; });
		run.isReport = (D.reports || []).some(function (p) { return p[0] === run.id; });
		run.type = String(o.sob_run_type || '').toUpperCase() || (run.isProc ? 'P' : (run.isReport ? 'R' : 'F'));
		if (o.sob_run_method === 'w') { o.sob_run_method = 'b'; if (!o.sob_run_target) o.sob_run_target = '2'; }     // nuBuilder Next 0.2 "new window"
		run.method = o.sob_run_method === 'i' ? 'i' : 'b';
		if (type === 'run') { o.sob_run_type = run.type; if (!o.sob_run_target) o.sob_run_target = '0'; }
		// display objects: procedure (stored as id; nuBuilder also finds it by code) – a value not in the list stays selectable
		var dispProc = String(o.sob_display_procedure || ''), dispOpts = [['', t('builder_display_proc_none')]].concat(D.procedures || []);
		if (dispProc !== '' && !dispOpts.some(function (p) { return p[0] === dispProc; })) dispOpts.push([dispProc, dispProc]);
		var spec = [
			['builder_sec_general', [
				{key: 'sob_all_type', label: 'builder_obj_type', kind: 'select', cls: 'col-sm-6', options: TYPES.map(function (x) { return [x, t('bf_type_' + x)]; }), change: true},
				{key: 'sob_all_id', label: 'builder_obj_id', cls: 'col-sm-6', help: 'builder_obj_id_help'},
				{key: 'sob_all_label', label: 'builder_obj_label'},
				{key: 'sob_all_zzzzsys_tab_id', label: 'builder_obj_tab', kind: 'select', cls: 'col-sm-6', options: tabs},
				{key: 'sob_all_access', label: 'builder_obj_access', kind: 'select', cls: 'col-sm-6', options: [['0', t('bf_access_0')], ['1', t('bf_access_1')], ['2', t('bf_access_2')], ['3', t('bf_access_3')], ['4', t('bf_access_4')], ['9', t('bf_access_9')]], change: true},
				{key: 'sob_all_access_condition', label: 'builder_obj_access_cond', kind: 'code', rows: 2, help: 'builder_obj_access_cond_help', show: String(o.sob_all_access || '') === '9'},
				{key: 'sob_all_validate', label: 'builder_obj_validate', kind: 'select', cls: 'col-sm-6', options: [['0', t('bf_validate_0')], ['1', t('bf_validate_1')], ['2', t('bf_validate_2')], ['3', t('bf_validate_3')]], types: ['input', 'select', 'textarea', 'lookup', 'calc']},
				{key: 'sob_all_align', label: 'builder_obj_align', kind: 'select', cls: 'col-sm-6', options: [['left', '←'], ['center', '↔'], ['right', '→']]},
				{key: 'sob_all_cloneable', label: 'builder_obj_cloneable', kind: 'switch', 'default': '1'}
			]],
			['builder_sec_type', [
				{key: 'sob_input_type', label: 'builder_input_type', kind: 'select', cls: 'col-sm-6', options: INPUT_TYPES.map(function (x) { return [x, x]; }), types: ['input'], change: true},
				{key: 'sob_input_format', label: 'builder_input_format', cls: 'col-sm-6', kind: 'format', letters: it === 'nuDate' ? 'D' : (it === 'nuNumber' ? 'N' : 'T'),
					types: ['input'], show: ['nuDate', 'nuNumber', 'text', 'tel', 'search'].indexOf(it) >= 0, help: ['text', 'tel', 'search'].indexOf(it) >= 0 ? 'builder_input_mask_help' : null},
				{key: 'sob_input_datalist', label: 'builder_input_datalist', kind: 'textarea', types: ['input'], help: 'builder_input_datalist_help', show: ['text', 'number', 'email', 'tel', 'url'].indexOf(it) >= 0},
				{key: 'sob_input_javascript', label: 'builder_input_scroll', kind: 'code', rows: 2, types: ['input'], show: it === 'nuScroll'},
				{key: 'sob_input_count', label: 'builder_input_count', cls: 'col-sm-6', types: ['input'], show: it === 'nuAutoNumber', help: 'builder_input_count_help'},
				// file objects (0.8.459): target Database (the file in the record) or File System (Uppy uploads to a server folder, HTML code = Uppy template)
				{key: 'sob_input_file_target', label: 'builder_file_target', kind: 'select', cls: 'col-sm-6', types: ['input'], show: it === 'file', change: true, help: 'builder_file_target_help',
					options: [['0', t('builder_file_target_0')], ['1', t('builder_file_target_1')]], value: String(o.sob_input_file_target || '0') === '1' ? '1' : '0'},
				{key: 'sob_html_code', label: 'builder_html', kind: 'code', rows: 8, types: ['input'], show: it === 'file' && String(o.sob_input_file_target || '0') === '1', help: 'builder_file_uppy_help'},
				{key: 'sob_select_sql', label: 'builder_select_sql', kind: 'code', types: ['select'], help: 'builder_select_sql_help'},
				{key: 'sob_select_multiple', label: 'builder_select_multiple', kind: 'switch', cls: 'col-sm-6', types: ['select']},
				{key: 'sob_select_2', label: 'builder_select_search', kind: 'switch', cls: 'col-sm-6', types: ['select']},
				{key: 'sob_display_procedure', label: 'builder_display_proc', kind: 'select', options: dispOpts, types: ['display'], help: 'builder_display_proc_help'},
				{key: 'sob_display_sql', label: 'builder_display_sql', kind: 'code', types: ['display']},
				{key: 'sob_calc_formula', label: 'builder_calc_formula', kind: 'code', rows: 2, types: ['calc'], help: 'builder_calc_help'},
				{key: 'sob_calc_format', label: 'builder_input_format', kind: 'format', letters: 'N', types: ['calc']},
				{key: 'sob_lookup_zzzzsys_form_id', label: 'builder_lookup_form', kind: 'select', options: formOptions(['browse', 'browseedit']), types: ['lookup']},
				{key: 'sob_lookup_code', label: 'builder_lookup_code', cls: 'col-sm-6', types: ['lookup']},
				{key: 'sob_lookup_description', label: 'builder_lookup_description', cls: 'col-sm-6', types: ['lookup']},
				{key: 'sob_lookup_description_width', label: 'builder_lookup_desc_width', kind: 'number', cls: 'col-sm-6', types: ['lookup']},
				{key: 'sob_lookup_table', label: 'builder_table', cls: 'col-sm-6', types: ['lookup']},
				{key: 'sob_lookup_javascript', label: 'builder_lookup_js', kind: 'code', rows: 3, types: ['lookup']},
				{key: 'lookup_php', label: 'builder_lookup_php', kind: 'code', rows: 4, types: ['lookup'], help: 'builder_lookup_php_help'},
				{key: 'sob_subform_zzzzsys_form_id', label: 'builder_subform_form', kind: 'select', options: formOptions(['subform']), types: ['subform']},
				{key: 'sob_subform_type', label: 'builder_subform_type', kind: 'select', cls: 'col-sm-6', options: [['g', t('builder_subform_grid')], ['f', t('builder_subform_formlayout')]], types: ['subform']},
				{key: 'sob_subform_foreign_key', label: 'builder_subform_fk', cls: 'col-sm-6', types: ['subform']},
				{key: 'sob_subform_table', label: 'builder_table', cls: 'col-sm-6', types: ['subform']},
				{key: 'sob_subform_add', label: 'builder_subform_add', kind: 'switch', cls: 'col-sm-6', types: ['subform'], 'default': '1'},
				{key: 'sob_subform_delete', label: 'builder_subform_delete', kind: 'switch', cls: 'col-sm-6', types: ['subform'], 'default': '1'},
				{key: 'sob_html_code', label: 'builder_html', kind: 'code', rows: 8, types: ['html']},
				{key: 'sob_html_chart_type', label: 'builder_chart_type', kind: 'select', types: ['chart'], options: [['', t('please_select')], ['p', t('builder_chart_p')], ['l', t('builder_chart_l')],
					['b', t('builder_chart_b')], ['bs', t('builder_chart_bs')], ['bh', t('builder_chart_bh')], ['bhs', t('builder_chart_bhs')]]},
				{key: 'sob_html_javascript', label: 'builder_chart_data', kind: 'code', rows: 4, types: ['chart'], help: 'builder_chart_data_help'},
				{key: 'sob_html_title', label: 'builder_chart_title', types: ['chart']},
				{key: 'sob_html_vertical_label', label: 'builder_chart_vlabel', cls: 'col-sm-6', types: ['chart']},
				{key: 'sob_html_horizontal_label', label: 'builder_chart_hlabel', cls: 'col-sm-6', types: ['chart']},
				{key: 'sob_image_zzzzsys_file_id', label: 'builder_image_file', kind: 'select', options: [['', t('please_select')]].concat(FILES), types: ['image'], help: 'builder_image_help'},
				{key: 'sob_run_type', label: 'builder_run_type', kind: 'select', cls: run.type === 'F' ? 'col-sm-7' : 'col-12', types: ['run'], change: true,
					options: [['F', t('builder_run_type_F')], ['P', t('builder_run_type_P')], ['R', t('builder_run_type_R')]]},
				{key: 'sob_run_method', label: 'builder_run_method', kind: 'select', cls: run.type === 'F' ? 'col-sm-5' : 'col-12', options: [['b', t('builder_run_b')], ['i', t('builder_run_i')]], types: ['run'], change: true},
				{key: 'sob_run_zzzzsys_form_id', label: 'builder_run_form', kind: 'select', options: formOptions(), value: run.type === 'F' && !run.isProc ? run.id : '', types: ['run'], show: run.type === 'F'},
				{key: 'sob_run_zzzzsys_form_id', label: 'builder_run_procedure', kind: 'select', options: [['', t('please_select')]].concat(D.procedures || []), value: run.isProc ? run.id : '',
					types: ['run'], show: run.type === 'P', help: 'builder_run_procedure_help'},
				{key: 'sob_run_zzzzsys_form_id', label: 'builder_run_report', kind: 'select', value: run.type === 'R' ? run.id : '', types: ['run'], show: run.type === 'R', help: 'builder_run_report_help',
					options: [['', t('please_select')]].concat(D.reports || []).concat(run.type === 'R' && run.id && !run.isReport ? [[run.id, run.id]] : [])},
				{key: 'sob_run_id', label: 'builder_run_record', cls: 'col-sm-6', types: ['run'], show: run.type === 'F', help: 'builder_run_record_help'},
				{key: 'sob_run_filter', label: 'builder_run_filter', cls: 'col-sm-6', types: ['run'], show: run.type === 'F', help: 'builder_run_filter_help'},
				{key: 'sob_run_target', label: 'builder_run_target', kind: 'select', types: ['run'], show: run.type === 'F' && run.method === 'b',
					options: [['0', t('builder_run_target_0')], ['1', t('builder_run_target_1')], ['2', t('builder_run_target_2')], ['3', t('builder_run_target_3')]]}
			]],
			['builder_sec_position', [
				{key: 'sob_all_top', label: 'builder_top', kind: 'number', cls: 'col-3'},
				{key: 'sob_all_left', label: 'builder_left', kind: 'number', cls: 'col-3'},
				{key: 'sob_all_width', label: 'builder_width', kind: 'number', cls: 'col-3'},
				{key: 'sob_all_height', label: 'builder_height', kind: 'number', cls: 'col-3'},
				{key: 'grid_row', label: 'builder_grid_row', kind: 'number', cls: 'col-3', value: g.row},
				{key: 'grid_col', label: 'builder_grid_col', kind: 'number', cls: 'col-3', value: g.col},
				{key: 'grid_span', label: 'builder_grid_span', kind: 'select', cls: 'col-3', value: g.span, options: [['', 'auto']].concat([1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12].map(function (n) { return [String(n), String(n)]; }))},
				{key: 'grid_box', label: 'builder_grid_box', kind: 'select', cls: 'col-3', value: g.box, options: boxes},
				{note: 'builder_position_help'}
			]],
			['builder_sec_style', [
				{key: 'sob_all_style_type', label: 'builder_style_type', kind: 'select', cls: 'col-sm-4', options: [['', '—'], ['CSS', 'CSS'], ['Class', 'Class']]},
				{key: 'sob_all_style', label: 'builder_style', cls: 'col-sm-8'},
				{key: 'sob_input_attribute', label: 'builder_attributes', help: 'builder_attributes_help', attrEditor: true}
			]]
		];
		return spec;
	}

	function panelObject(id) {
		var o = objById(id);
		if (!o) { select('form'); return; }
		var draft = Object.assign({}, o);
		var body = h('div', {});
		// a file object switched to "File System" with empty HTML code gets the Uppy template (nuBuilder: procedure nu_upload_file_load_uppy_code)
		function uppyTemplate(d) {
			if (d.sob_all_type === 'input' && d.sob_input_type === 'file' && String(d.sob_input_file_target || '0') === '1' && !String(d.sob_html_code || '').trim() && D.uppy_template) {
				d.sob_html_code = D.uppy_template;
			}
		}
		function build() {
			body.innerHTML = '';
			objectSpecs(draft).forEach(function (sec) {
				var kids = [];
				sec[1].forEach(function (s) {
					if (s.note) { kids.push(h('div', {'class': 'col-12 form-text mt-0', text: t(s.note)})); return; }
					if (s.types && s.types.indexOf(draft.sob_all_type) < 0) return;
					if (s.show === false) return;
					var val = 'value' in s ? s.value : (s.key === 'lookup_php' ? S.lookup_php[o.zzzzsys_object_id] : draft[s.key]);
					var f = field(s, val);
					if (s.change) $('[data-key]', f).addEventListener('change', function () { Object.assign(draft, collect(body)); uppyTemplate(draft); build(); });
					kids.push(f);
				});
				if (kids.length) body.appendChild(section(sec[0], kids));
			});
			body.appendChild(eventsEditor(o));
			if (body.isConnected) { upgradeEditors(body); refreshEditors(body); }
		}
		build();
		panel(o.sob_all_id + ' · ' + t('bf_type_' + o.sob_all_type), body, function () {
			var v = collect(body), fields = {}, grid = {}, events = [];
			Object.keys(v).forEach(function (k) {
				if (k.indexOf('grid_') === 0) grid[k.substr(5)] = v[k];
				else if (k !== 'lookup_php' && k.indexOf('ev_') !== 0) fields[k] = v[k];
			});
			$$('.nbEventRow:not(.nuRowRemoved)', body).forEach(function (r) { events.push({event: $('select', r).value, js: $('textarea', r).value}); });
			var payload = {object_id: o.zzzzsys_object_id, fields: fields, grid: grid, events: events};
			if ('lookup_php' in v) payload.lookup_php = v.lookup_php;
			return api('object.save', payload).then(function (r) { load(r); select('object', r.object_id); preview(); if (r.missing_column) offerColumn(r.object_id, r.missing_column); });
		}, RO ? [] : [
			h('button', {type: 'button', 'class': 'btn btn-outline-danger btn-sm', onclick: function () {
				if (!confirm(t('builder_delete_object_confirm').replace('%s', o.sob_all_id))) return;
				api('object.delete', {object_id: o.zzzzsys_object_id}).then(function (r) { load(r); select('form'); preview(); }).catch(function (e) { flash(false, e.message); });
			}}, [h('i', {'class': 'bi bi-trash me-1'}), t('builder_delete_object')]),
			h('button', {type: 'button', 'class': 'btn btn-outline-secondary btn-sm', onclick: function () { duplicate(o); }}, [h('i', {'class': 'bi bi-copy me-1'}), t('builder_clone_object')])
		]);
	}

	/** Shown when a saved object has no column in the form's table: propose a type and add it on confirm. */
	function offerColumn(objectId, mc) {
		var types = ['text', 'longtext', 'int', 'bigint', 'decimal', 'date', 'datetime', 'time', 'bool', 'id'];
		var type = sel2(types.map(function (x) { return [x, t('builder_coltype_' + x)]; }), mc.type, {'aria-label': t('builder_col_type')});
		var len = inp({value: mc.length || '', 'class': 'form-control form-control-sm nbLen', 'aria-label': t('builder_col_length'), maxlength: 8});
		function syncLen() { var uses = type.value === 'text' || type.value === 'decimal'; len.disabled = !uses; if (!uses) len.value = ''; else if (!len.value) len.value = type.value === 'text' ? '255' : '15,2'; }
		type.addEventListener('change', function () { len.value = ''; syncLen(); });
		syncLen();
		var box = h('div', {'class': 'alert alert-warning nbMissingColumn', role: 'alert'}, [
			h('div', {'class': 'fw-semibold mb-1'}, [h('i', {'class': 'bi bi-exclamation-triangle me-1'}), t('builder_missing_column').replace('%1$s', mc.name).replace('%2$s', mc.table)]),
			h('div', {'class': 'small mb-2', text: t('builder_missing_column_help')}),
			h('div', {'class': 'd-flex flex-wrap gap-2 align-items-center'}, [
				h('div', {'class': 'nbMcType'}, [type]), h('div', {'class': 'nbMcLen'}, [len]),
				h('button', {type: 'button', 'class': 'btn btn-warning btn-sm', onclick: function () {
					var b = this; b.disabled = true;
					api('column.add', {object_id: objectId, type: type.value, length: len.value}).then(function (r) {
						load(r); box.remove(); flash(true); preview();
					}).catch(function (e) { b.disabled = false; flash(false, e.message); });
				}}, [h('i', {'class': 'bi bi-plus-lg me-1'}), t('builder_add_column_btn')]),
				h('button', {type: 'button', 'class': 'btn btn-link btn-sm', text: t('builder_not_now'), onclick: function () { box.remove(); }})
			])
		]);
		var p = $('#nbPanel');
		p.insertBefore(box, p.firstChild);
		box.scrollIntoView({block: 'nearest'});
	}

	function eventsEditor(o) {
		var rows = h('div', {'class': 'nbEvents'});
		function add(ev) {
			var r = h('div', {'class': 'nbEventRow row g-2 mb-2'}, [
				h('div', {'class': 'col-sm-4'}, [sel2(EVENTS.map(function (x) { return [x, x]; }), ev.event || 'onchange', {'aria-label': t('builder_event')})]),
				h('div', {'class': 'col-sm-7'}, [(function () { var ta = h('textarea', {'class': 'form-control form-control-sm nbCode', rows: 2, spellcheck: 'false', disabled: RO, 'aria-label': 'JavaScript'}); ta.value = ev.js || ''; return ta; })()]),
				h('div', {'class': 'col-sm-1'})
			]);
			if (!RO) r.lastChild.appendChild(removeBtn(r, 'btn btn-link btn-sm text-danger'));
			rows.appendChild(r);
			upgradeEditors(r);
		}
		(S.events[o.zzzzsys_object_id] || []).forEach(add);
		return h('fieldset', {'class': 'nbFieldset'}, [h('legend', {'class': 'nbLegend', text: t('builder_sec_events')}), rows,
			RO ? null : h('button', {type: 'button', 'class': 'btn btn-outline-primary btn-sm', onclick: function () { add({}); }}, [h('i', {'class': 'bi bi-plus-lg me-1'}), t('builder_add_event')])]);
	}

	function uniqueId(base) {
		var ids = S.objects.map(function (x) { return x.sob_all_id; }), n = 1, id = base;
		while (ids.indexOf(id) >= 0) id = base + '_' + (++n);
		return id;
	}

	function bottomOf(tabId) {
		return S.objects.filter(function (x) { return x.sob_all_zzzzsys_tab_id === tabId && x.sob_all_type !== 'contentbox'; })
			.reduce(function (m, x) { return Math.max(m, (+x.sob_all_top || 0) + (+x.sob_all_height || 0)); }, 0);
	}

	function addObject(type) {
		var tab = $('#nbTabFilter').value || (sel.kind === 'object' && objById(sel.id) ? objById(sel.id).sob_all_zzzzsys_tab_id : (S.tabs[0] || {}).zzzzsys_tab_id);
		var size = {textarea: [400, 60], editor: [700, 300], subform: [700, 200], contentbox: [600, 200], html: [400, 60], run: [150, 30], lookup: [100, 22]}[type] || [250, 22];
		var maxOrder = S.objects.reduce(function (m, x) { return Math.max(m, +x.sob_all_order || 0); }, 0);
		var fields = {sob_all_type: type, sob_all_id: uniqueId(type === 'contentbox' ? 'box' : 'new_' + type), sob_all_label: t('bf_type_' + type),
			sob_all_zzzzsys_tab_id: tab, sob_all_order: maxOrder + 10, sob_all_top: bottomOf(tab) + 12, sob_all_left: 170,
			sob_all_width: size[0], sob_all_height: size[1], sob_all_align: 'left', sob_input_type: type === 'input' ? 'text' : '',
			sob_subform_add: type === 'subform' ? '1' : '', sob_subform_delete: type === 'subform' ? '1' : '', sob_subform_type: type === 'subform' ? 'g' : '',
			sob_run_method: type === 'run' ? 'b' : '', sob_run_type: type === 'run' ? 'F' : '', sob_run_target: type === 'run' ? '0' : '', sob_lookup_description_width: type === 'lookup' ? '200' : ''};
		api('object.save', {object_id: '', fields: fields}).then(function (r) { load(r); select('object', r.object_id); preview(); }).catch(function (e) { alert(e.message); });
		// the column is offered once the user has given the object its real ID and saved it
	}

	function duplicate(o) {
		var fields = {};
		Object.keys(o).forEach(function (k) { if (k.indexOf('sob_') === 0 && k !== 'sob_all_json' && k !== 'sob_json' && k !== 'sob_all_zzzzsys_form_id') fields[k] = o[k]; });
		fields.sob_all_id = uniqueId(o.sob_all_id);
		fields.sob_all_top = (+o.sob_all_top || 0) + (+o.sob_all_height || 20) + 8;
		fields.sob_all_order = (+o.sob_all_order || 0) + 1;
		var events = (S.events[o.zzzzsys_object_id] || []).map(function (e) { return {event: e.event, js: e.js}; });
		api('object.save', {object_id: '', fields: fields, events: events}).then(function (r) { load(r); select('object', r.object_id); preview(); if (r.missing_column) offerColumn(r.object_id, r.missing_column); }).catch(function (e) { flash(false, e.message); });
	}

	// ------------------------------------------------------------------ code editors (CodeMirror 5)
	var MODES = {sfo_javascript: 'javascript', sfo_edit_javascript: 'javascript', sfo_browse_javascript: 'javascript', sob_lookup_javascript: 'javascript',
		sob_calc_formula: 'javascript', sob_input_javascript: 'javascript', sob_html_javascript: 'javascript', sfo_style: 'css', sob_html_code: 'htmlmixed', lookup_php: 'text/x-php'};
	function modeFor(ta) {
		var k = ta.getAttribute('data-key') || '';
		if (MODES[k]) return MODES[k];
		if (k.indexOf('php_') === 0) return 'text/x-php';
		if (/_sql$/.test(k)) return 'text/x-sql';
		return 'javascript';                                   // event code
	}
	function upgradeEditors(root) {
		if (!window.CodeMirror) return;
		$$('textarea.nbCode', root).forEach(function (ta) {
			if (ta.nbCM) return;
			var mode = modeFor(ta);
			var cm = CodeMirror.fromTextArea(ta, {
				mode: mode === 'text/x-php' ? {name: 'php', startOpen: true} : mode, lineNumbers: true, lineWrapping: true,
				matchBrackets: true, autoCloseBrackets: true, indentUnit: 4, tabSize: 4, indentWithTabs: true, viewportMargin: Infinity,
				readOnly: RO || ta.disabled ? 'nocursor' : false
			});
			cm.on('change', function () { cm.save(); setDirty(true); });
			cm.getWrapperElement().classList.add('nbCM');
			// height: at least the textarea's rows (CodeMirror grows with the content up to 62vh)
			var rows = parseInt(ta.getAttribute('rows'), 10) || 3;
			cm.getScrollerElement().style.minHeight = Math.max(4.5, Math.min(rows * 1.2, 40)) + 'rem';   // 1.2rem = one line
			// toolbar: undo / redo, search, replace, comment, invisibles, format – and the "#" menu for SQL, PHP and HTML
			var bar = h('div', {'class': 'nbCodeBar d-flex justify-content-end gap-2'});
			cm.getWrapperElement().parentNode.insertBefore(bar, cm.getWrapperElement());
			var lang = {'text/x-sql': 'SQL', 'text/x-php': 'PHP', htmlmixed: 'HTML', css: 'CSS'}[mode] || 'JS';
			if (ta.getAttribute('data-key') === 'sob_calc_formula') lang = 'TEXT';           // a formula, not a program
			if (window.NuCodeTools) NuCodeTools.attach(cm, bar, {lang: lang, db: D.db_type, readOnly: RO || ta.disabled});
			if (!RO && !ta.disabled && window.NuHashVars && D.hash_catalog && (mode === 'text/x-sql' || mode === 'text/x-php' || mode === 'htmlmixed')) {
				NuHashVars.attach(cm, bar, {catalog: D.hash_catalog, fields: function () {
					return S.objects.filter(function (o) { return ['input', 'select', 'lookup', 'textarea', 'calc', 'display', 'editor'].indexOf(o.sob_all_type) >= 0 && o.sob_input_type !== 'button'; })
						.map(function (o) { return [o.sob_all_id, String(o.sob_all_label || '').replace(/<[^>]*>/g, '').trim()]; });
				}});
			}
			ta.nbCM = cm;
		});
	}
	function refreshEditors(root) { $$('textarea.nbCode', root).forEach(function (ta) { if (ta.nbCM) ta.nbCM.refresh(); }); }
	window.nbRefreshEditors = refreshEditors;

	// ------------------------------------------------------------------ history
	function panelHistory() {
		var body = h('div', {}, [h('div', {'class': 'text-body-secondary small', text: '…'})]);
		panel(t('builder_sec_history'), body, null);
		api('history.list', {}).then(function (r) {
			body.innerHTML = '';
			if (!r.history.length) { body.appendChild(h('p', {'class': 'text-body-secondary', text: t('builder_history_empty')})); return; }
			body.appendChild(h('p', {'class': 'small text-body-secondary', text: t('builder_history_help')}));
			var list = h('ul', {'class': 'list-group nbHistory'});
			r.history.forEach(function (it) {
				var ver = /^published v(\d+)/.exec(it.action || '');                 // versions of form drafts (0.8.386)
				list.appendChild(h('li', {'class': 'list-group-item d-flex align-items-center gap-2'}, [
					h('div', {'class': 'flex-grow-1 small'}, [
						h('div', {'class': 'fw-semibold'}, [ver ? h('span', {'class': 'badge text-bg-success me-2', text: 'v' + ver[1]}) : null, it.action]),
						h('div', {'class': 'text-body-secondary', text: it.created_at + ' · ' + (it.login || '')})
					]),
					RO || DR ? null : h('button', {type: 'button', 'class': 'btn btn-sm btn-outline-primary', text: t('builder_restore'), onclick: function () {
						if (!confirm(t('builder_restore_confirm'))) return;
						api('history.restore', {id: it.id}).then(function (s2) { load(s2); select('history'); preview(); flash(true); }).catch(function (e) { flash(false, e.message); });
					}})
				]));
			});
			body.appendChild(list);
		}).catch(function (e) { body.textContent = e.message; });
	}

	var undoBtn = $('#nbUndo');
	if (undoBtn && DR) undoBtn.hidden = true;                   // draft mode: the draft bar has its own Undo
	if (DR && $('#nbDeleteForm')) $('#nbDeleteForm').hidden = true;   // a form with an open draft is not deleted
	if (undoBtn) undoBtn.addEventListener('click', function () {
		undoBtn.disabled = true;
		api('history.undo', {}).then(function (r) {
			load(r);
			select(sel.kind === 'object' && objById(sel.id) ? 'object' : (sel.kind === 'object' ? 'form' : sel.kind), sel.id);
			preview();
			flash(true);
		}).catch(function (e) { alert(e.message); }).then(function () { undoBtn.disabled = false; });
	});

	// ------------------------------------------------------------------ designer in the preview (drag & drop)
	function objByFieldId(fid) { return S.objects.filter(function (x) { return x.sob_all_id === fid; })[0]; }

	function ensureGrid() {
		if (S.form.layout === 'grid') return Promise.resolve();
		if (!confirm(t('builder_designer_convert'))) return Promise.reject(new Error(''));
		return api('layout.convert', {}).then(function (r) { load(r); $('#nbPreviewLayout').value = ''; });
	}

	function saveObject(o, payload, reloadPreview) {
		return api('object.save', Object.assign({object_id: o.zzzzsys_object_id}, payload)).then(function (r) {
			load(r);
			if (sel.kind === 'object' && sel.id === o.zzzzsys_object_id) select('object', o.zzzzsys_object_id);
			if (reloadPreview) preview();
			flash(true);
		}).catch(function (e) { flash(false, e.message); preview(); });
	}

	window.addEventListener('message', function (e) {
		if (e.origin !== window.location.origin || !e.data || RO) return;
		var d = e.data;
		if (d.nuDesigner) {                                    // absolute layout: position / size
			var o = objByFieldId(d.nuDesigner.id);
			if (!o) return;
			var f = {};
			if (d.nuDesigner.top !== undefined) { f.sob_all_top = d.nuDesigner.top; f.sob_all_left = d.nuDesigner.left; }
			if (d.nuDesigner.width !== undefined) {
				var w = d.nuDesigner.width;
				if (o.sob_all_type === 'lookup') w -= (o.sob_lookup_description_width === '' || o.sob_lookup_description_width == null ? 150 : +o.sob_lookup_description_width || 0) + 34;   // 0 = description hidden
				f.sob_all_width = Math.max(10, w);
			}
			if (d.nuDesigner.height !== undefined) f.sob_all_height = d.nuDesigner.height;
			saveObject(o, {fields: f}, false);
		}
		if (d.nuDesignerSpan) {                                // grid: width in columns
			var os = objByFieldId(d.nuDesignerSpan.id);
			if (!os) return;
			ensureGrid().then(function () { saveObject(objByFieldId(d.nuDesignerSpan.id), {grid: Object.assign(nunext(objByFieldId(d.nuDesignerSpan.id)), {span: d.nuDesignerSpan.span})}, true); }).catch(function () { preview(); });
		}
		if (d.nuDesignerMove) {                                // grid: new place
			ensureGrid().then(function () {
				var m = d.nuDesignerMove, o2 = objByFieldId(m.id), tgt = objByFieldId(m.target);
				if (!o2 || !tgt) return;
				var tg = nunext(tgt), g = nunext(o2), place;
				if (m.side === 'into') {
					var inBox = S.objects.filter(function (x) { return nunext(x).box === tgt.sob_all_id; });
					var maxRow = inBox.reduce(function (mx, x) { return Math.max(mx, +nunext(x).row || 0); }, 0);
					place = {row: maxRow + 10, col: 10, box: tgt.sob_all_id};
				} else if (m.side === 'below') {
					place = {row: (+tg.row || 0) + 5, col: 10, box: tg.box || ''};
				} else {
					place = {row: +tg.row || 10, col: (+tg.col || 10) + (m.side === 'before' ? -5 : 5), box: tg.box || ''};
				}
				saveObject(o2, {fields: {sob_all_zzzzsys_tab_id: tgt.sob_all_zzzzsys_tab_id}, grid: {row: place.row, col: place.col, span: g.span || '', box: place.box}}, true);
			}).catch(function () { preview(); });
		}
	});

	// ------------------------------------------------------------------ preview
	var frame = $('#nbPreview');
	$('#nbPreviewRecord').value = D.preview_record || '-1';
	// Browse and edit forms (0.8.439): the preview shows the list (Browse) or a record (Edit) – remembered per form in this browser
	var PM_KEY = 'nunext-builder-pvmode-' + D.form_id, pmBox = $('#nbPreviewMode'), pmMode = null;
	function previewMode() {
		if (pmMode === null) { pmMode = 'edit'; try { if (localStorage.getItem(PM_KEY) === 'browse') pmMode = 'browse'; } catch (e) { /* no storage */ } }
		return pmMode;
	}
	function setPreviewMode(m, reload) {
		pmMode = m === 'browse' ? 'browse' : 'edit';
		try { if (pmMode === 'browse') localStorage.setItem(PM_KEY, 'browse'); else localStorage.removeItem(PM_KEY); } catch (e) { /* no storage */ }
		if (reload) preview();
	}
	if (pmBox) pmBox.addEventListener('click', function (e) {
		var b = e.target.closest('[data-mode]');
		if (b && b.getAttribute('data-mode') !== previewMode()) setPreviewMode(b.getAttribute('data-mode'), true);
	});
	function preview() {
		var type = S.form.sfo_type, both = type === 'browseedit';
		var browse = type === 'browse' || (both && previewMode() === 'browse');
		if (pmBox) {
			pmBox.classList.toggle('d-none', !both);
			pmBox.querySelectorAll('[data-mode]').forEach(function (b) {
				var on = b.getAttribute('data-mode') === previewMode();
				b.classList.toggle('active', on); b.setAttribute('aria-pressed', on ? 'true' : 'false');
			});
		}
		var hint = $('#nbDesignerHint');
		if (hint) hint.classList.toggle('d-none', browse);   // dragging objects needs the edit form
		var rec = browse ? '' : ($('#nbPreviewRecord').value.trim() || '-1');
		$('#nbPreviewRecord').disabled = browse;
		var q = {p: 'form', f: D.form_id, iframe: '1', builder: '1', draft: DR ? DR.id : 'off'};   // draft mode: the preview shows the draft (0.8.386)
		if (rec !== '') q.r = rec;
		if ($('#nbPreviewLayout').value) q.layout = $('#nbPreviewLayout').value;
		if (type === 'subform') { frame.removeAttribute('src'); frame.srcdoc = '<p style="font:14px sans-serif;padding:1rem;color:#666">' + t('builder_preview_subform') + '</p>'; return; }
		frame.removeAttribute('srcdoc');
		frame.src = 'index.php?' + new URLSearchParams(q).toString();
	}
	function highlight(o) {
		try { if (o && frame.contentWindow) frame.contentWindow.postMessage({nuBuilderSelect: o.sob_all_id}, window.location.origin); } catch (e) { /* ignore */ }
	}
	// tab order (0.8.521): the preview numbers its objects in the order the Tab key visits them; on / off remembered in this browser
	var TO_KEY = 'nunext-builder-taborder', toBtn = $('#nbTabOrder'), tabOrder = false;
	try { tabOrder = localStorage.getItem(TO_KEY) === '1'; } catch (e) { /* ignore */ }
	function sendTabOrder() {
		if (toBtn) { toBtn.classList.toggle('active', tabOrder); toBtn.setAttribute('aria-pressed', tabOrder ? 'true' : 'false'); }
		try { if (frame.contentWindow) frame.contentWindow.postMessage({nuBuilderTabOrder: tabOrder}, window.location.origin); } catch (e) { /* ignore */ }
	}
	if (toBtn) toBtn.addEventListener('click', function () {
		tabOrder = !tabOrder;
		try { localStorage.setItem(TO_KEY, tabOrder ? '1' : '0'); } catch (e) { /* ignore */ }
		sendTabOrder();
	});
	sendTabOrder();
	frame.addEventListener('load', function () { if (sel.kind === 'object') highlight(objById(sel.id)); sendTabOrder(); });
	// show / hide the preview (small screens): remembered in localStorage; without a stored choice hidden below 992 px
	var PV_KEY = 'nunext-builder-preview', ws = $('#nbWorkspace'), pvBtn = $('#nbPreviewToggle');
	function previewOpen() { return !ws.classList.contains('nbPreviewCollapsed'); }
	function setPreview(open, remember) {
		var was = previewOpen();
		ws.classList.toggle('nbPreviewCollapsed', !open);
		var label = pvBtn.getAttribute(open ? 'data-hide' : 'data-show');
		pvBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
		pvBtn.setAttribute('title', label); pvBtn.setAttribute('aria-label', label);
		pvBtn.querySelector('i').className = 'bi ' + (open ? 'bi-chevron-bar-right' : 'bi-eye');
		if (remember) { try { localStorage.setItem(PV_KEY, open ? 'open' : 'closed'); } catch (e) { /* private mode */ } }
		if (open && !was && S) preview();                  // reload: a hidden iframe has no size
		window.dispatchEvent(new Event('resize'));
	}
	var pvStored = null;
	try { pvStored = localStorage.getItem(PV_KEY); } catch (e) { /* ignore */ }
	setPreview(pvStored ? pvStored === 'open' : window.matchMedia('(min-width: 992px)').matches, false);
	pvBtn.addEventListener('click', function () { setPreview(!previewOpen(), true); });
	window.nbShowPreview = function () { if (!previewOpen()) setPreview(true, false); };

	$('#nbPreviewRecord').addEventListener('change', preview);
	$('#nbPreviewLayout').addEventListener('change', preview);
	$('#nbPreviewReload').addEventListener('click', preview);
	window.addEventListener('message', function (e) {
		if (e.origin !== window.location.origin || !e.data || !e.data.nuBuilderPick) return;
		var o = S.objects.filter(function (x) { return x.sob_all_id === e.data.nuBuilderPick; })[0];
		if (o) select('object', o.zzzzsys_object_id);
	});

	// "Delete form" (0.8.245): a dialog that can also delete the form's table – only after its name is typed exactly
	var del = $('#nbDeleteForm'), dm = $('#nbDeleteModal');
	function openDelete() {
		if (!dm || !nuBs.ready()) {                      // fallback without the dialog: the form only, as before
			if (confirm(t('builder_delete_form_confirm'))) api('form.delete', {}).then(function () { window.location.href = 'index.php?p=forms'; }).catch(function (e) { alert(e.message); });
			return;
		}
		var note = $('#nbDelTableNote'), box = $('#nbDelTableBox'), chk = $('#nbDelTable'), cbox = $('#nbDelConfirmBox'), typed = $('#nbDelTyped'),
			ok = $('#nbDelOk'), okText = $('#nbDelOkText'), reauth = $('#nbDelReauth'), info = null;
		var sync = function () {
			var drop = !!(info && info.can_drop && chk.checked);
			cbox.classList.toggle('d-none', !drop || info.reauth);
			reauth.classList.toggle('d-none', !drop || !info.reauth);
			okText.textContent = drop ? t('builder_delete_form_table') : t('builder_delete_form');
			ok.disabled = !info || (drop && (info.reauth || typed.value !== info.table));
		};
		note.classList.add('d-none'); box.classList.add('d-none'); chk.checked = false; typed.value = ''; ok.disabled = true;
		chk.onchange = function () { sync(); if (chk.checked && !info.reauth) typed.focus(); };
		typed.oninput = sync;
		typed.onpaste = function (e) { e.preventDefault(); };   // typed, not pasted
		ok.onclick = function () {
			var drop = info && info.can_drop && chk.checked ? typed.value : '';
			ok.disabled = true;
			api('form.delete', {drop_table: drop}).then(function (r) {
				if (r.warning) alert(r.warning);
				window.location.href = 'index.php?p=forms';
			}).catch(function (e) { ok.disabled = false; alert(e.message); });
		};
		api('form.deleteinfo', {}).then(function (r) {
			info = r;
			var msg = '';
			if (!r.table) msg = t('builder_drop_no_table');
			else if (!r.exists) msg = t('builder_drop_missing').replace('%s', r.table);
			else if (r.reason === 'used') msg = t('builder_drop_used').replace('%s', r.table).replace('%s', r.used_by.join(', '));
			else if (r.reason === 'view') msg = t('builder_drop_view').replace('%s', r.table);
			else if (r.reason) msg = t('builder_drop_kept').replace('%s', r.table);
			if (msg) { note.textContent = msg; note.classList.remove('d-none'); }
			if (r.can_drop) {
				$('#nbDelTableLabel').textContent = t('builder_drop_table').replace('%s', r.table).replace('%s', r.rows === null ? '?' : String(r.rows));
				$('#nbDelTypedLabel').textContent = t('builder_drop_type').replace('%s', r.table);
				typed.placeholder = r.table;
				if (r.reauth_url) $('#nbDelReauthLink').href = r.reauth_url;
				box.classList.remove('d-none');
			}
			sync();
		}).catch(function (e) { note.textContent = e.message; note.classList.remove('d-none'); info = {can_drop: false}; sync(); });
		nuBs.modal(dm).show();
	}
	if (del) del.addEventListener('click', openDelete);
	if (del && new URLSearchParams(window.location.search).get('delete') === '1') openDelete();   // back from the password confirmation

	// ------------------------------------------------------------------ form drafts (0.8.386)
	// Live mode: pill LIVE, "Start draft" (or the info bar of an open draft); draft mode: pill DRAFT and the amber bar with Preview,
	// Compare, Undo, "Saving in the preview", Publish… and Discard. Switching between the modes reloads the page.
	window.nbLoad = function (st) { load(st); select(sel.kind === 'object' && objById(sel.id) ? 'object' : (sel.kind === 'object' ? 'form' : sel.kind), sel.id); preview(); };
	function fmtTime(s) { return String(s || '').replace(/^(\d{4})-(\d\d)-(\d\d) (\d\d:\d\d).*$/, '$3.$2. $4'); }
	function go(url) { if (window.nuLeaveGuard && isDirty()) window.nuLeaveGuard.ask(function () { window.location.href = url; }); else window.location.href = url; }
	function renderDraftBar() {
		var pill = $('#nbModePill'), bar = $('#nbDraftBar');
		if (!pill || !bar || !S) return;
		pill.className = 'badge fs-6 fw-normal nbModePill ' + (DR ? 'text-bg-warning' : 'text-bg-success');
		pill.textContent = DR ? t('builder_draft_draft') : t('builder_draft_live');
		bar.innerHTML = '';
		bar.className = 'nbDraftBar mb-3';
		var btn = function (cls, icon, label, fn, attrs) { return h('button', Object.assign({type: 'button', 'class': 'btn btn-sm ' + cls, onclick: fn}, attrs || {}), [h('i', {'class': 'bi ' + icon + ' me-1'}), label]); };
		if (!DR) {
			var items = [];
			if (LD) {
				bar.classList.add('nbDraftInfo');
				items.push(h('i', {'class': 'bi bi-cone-striped'}), h('span', {'class': 'me-auto', text: t('builder_draft_open_info').replace('%s', LD.created_by).replace('%s', fmtTime(LD.created_at))}));
				items.push(btn('btn-primary', 'bi-pencil-square', t('builder_draft_open_btn'), function () { go(DP.draft_url); }, {id: 'nbDraftOpen'}));
			} else if (!RO && D.can_save) {
				items.push(h('span', {'class': 'me-auto small text-body-secondary', text: t('builder_draft_start_tip')}));
				if (DP.unpublished && DP.can_publish) items.push(btn('btn-outline-success', 'bi-rocket-takeoff', t('builder_draft_publish_new'), publishNew, {id: 'nbPublishNew'}));
				items.push(btn(DP.required ? 'btn-primary' : 'btn-outline-primary', 'bi-cone-striped', t('builder_draft_start'), startDraft, {id: 'nbDraftStart', title: t('builder_draft_start_tip')}));
			}
			bar.hidden = !items.length;
			items.forEach(function (x) { bar.appendChild(x); });
			return;
		}
		bar.hidden = false;
		bar.classList.add('nbDraftActive');
		var info = t('builder_draft_bar').replace('%s', DR.created_by).replace('%s', fmtTime(DR.created_at)).replace('%s', fmtTime(DR.updated_at)).replace('%s', DR.changes);
		var saves = h('input', {'class': 'form-check-input', type: 'checkbox', role: 'switch', id: 'nbDraftSaves', checked: DR.preview_saves ? 'checked' : null});
		saves.addEventListener('change', function () {
			if (saves.checked && !window.confirm(t('builder_draft_saves_confirm'))) { saves.checked = false; return; }
			api('draft.settings', {preview_saves: saves.checked}).then(function (r) { load(r); flash(true); }).catch(function (e) { flash(false, e.message); });
		});
		[h('i', {'class': 'bi bi-cone-striped'}),
			h('span', {'class': 'me-auto'}, [info, DR.conflict ? h('span', {'class': 'badge text-bg-danger ms-2', id: 'nbDraftConflict', text: t('builder_draft_conflict')}) : null]),
			btn('btn-outline-dark', 'bi-box-arrow-up-right', t('builder_draft_preview'), function () { window.open(DR.preview_url, '_blank', 'noopener'); }, {id: 'nbDraftPreview'}),
			btn('btn-outline-dark', 'bi-file-diff', t('builder_draft_compare'), function () { guarded(function () { select('compare'); })(); }, {id: 'nbDraftCompare'}),
			btn('btn-outline-dark', 'bi-arrow-counterclockwise', t('builder_draft_undo'), draftUndo, {id: 'nbDraftUndo', disabled: DR.undo_count ? null : 'disabled', title: DR.undo_label || null}),
			h('div', {'class': 'form-check form-switch mb-0 small'}, [saves, h('label', {'class': 'form-check-label', 'for': 'nbDraftSaves', text: t('builder_draft_saves')})]),
			DP.can_publish ? btn('btn-success', 'bi-rocket-takeoff', t('builder_draft_publish'), publishDialog, {id: 'nbDraftPublish'}) : null,
			btn('btn-outline-danger', 'bi-trash', t('builder_draft_discard'), discardDraft, {id: 'nbDraftDiscard'}),
			h('a', {'class': 'btn btn-sm btn-link', href: DP.live_url, text: t('builder_draft_live')})
		].forEach(function (x) { if (x) bar.appendChild(x); });
	}
	window.nbRenderDraftBar = renderDraftBar;
	function startDraft() {
		var note = window.prompt(t('builder_draft_note_prompt'), '');
		if (note === null) return;
		api('draft.start', {note: note, no_draft: true}).then(function () { window.location.href = DP.draft_url; }).catch(function (e) { alert(e.message); });
	}
	function publishNew() {
		api('form.publishnew', {no_draft: true}).then(function (r) { alert(t('builder_draft_published').replace('%s', r.version)); window.location.href = DP.live_url; }).catch(function (e) { alert(e.message); });
	}
	function draftUndo() {
		api('draft.undo', {}).then(function (r) { window.nbLoad(r); flash(true); }).catch(function (e) { if (e.message) alert(e.message); });
	}
	function discardDraft() {
		if (!window.confirm(t('builder_draft_discard_confirm'))) return;
		api('draft.discard', {}).then(function () { setDirty(false); window.location.href = DP.live_url; }).catch(function (e) { alert(e.message); });
	}

	/** Line diff (LCS) of two texts: [['=', line] | ['-', line] | ['+', line]]; null when too large. */
	function lineDiff(a, b) {
		var x = String(a || '').split('\n'), y = String(b || '').split('\n');
		if (x.length * y.length > 4000000) return null;
		var n = x.length, m = y.length, L = [], i, j;
		for (i = 0; i <= n; i++) { L.push(new Uint32Array(m + 1)); }
		for (i = n - 1; i >= 0; i--) for (j = m - 1; j >= 0; j--) L[i][j] = x[i] === y[j] ? L[i + 1][j + 1] + 1 : Math.max(L[i + 1][j], L[i][j + 1]);
		var out = []; i = 0; j = 0;
		while (i < n && j < m) {
			if (x[i] === y[j]) { out.push(['=', x[i]]); i++; j++; } else if (L[i + 1][j] >= L[i][j + 1]) { out.push(['-', x[i++]]); } else { out.push(['+', y[j++]]); }
		}
		while (i < n) out.push(['-', x[i++]]);
		while (j < m) out.push(['+', y[j++]]);
		return out;
	}
	function codeDiff(oldText, newText) {
		var d = lineDiff(oldText, newText);
		if (!d) return h('div', {'class': 'small text-body-secondary', text: t('builder_draft_diff_large')});
		// unchanged lines far from a change are folded
		var keep = d.map(function () { return false; });
		d.forEach(function (r, k) { if (r[0] !== '=') for (var q = Math.max(0, k - 2); q <= Math.min(d.length - 1, k + 2); q++) keep[q] = true; });
		var pre = h('pre', {'class': 'nbDiffCode'}), folded = false;
		d.forEach(function (r, k) {
			if (!keep[k]) { if (!folded) pre.appendChild(h('div', {'class': 'nbDiffFold', text: '…'})); folded = true; return; }
			folded = false;
			pre.appendChild(h('div', {'class': r[0] === '+' ? 'nbDiffAdd' : (r[0] === '-' ? 'nbDiffDel' : ''), text: (r[0] === '=' ? '  ' : r[0] + ' ') + r[1]}));
		});
		return pre;
	}
	var CODE_COLS = ['sfo_javascript', 'sfo_browse_javascript', 'sfo_edit_javascript', 'sfo_browse_sql', 'sfo_style', 'sob_select_sql', 'sob_display_sql', 'sob_html_code',
		'sob_input_javascript', 'sob_lookup_javascript', 'sob_html_javascript', 'sob_all_access_condition', 'sob_calc_formula'];
	function fieldRows(fields) {
		return h('div', {}, fields.map(function (f) {
			if (CODE_COLS.indexOf(f[0]) >= 0) return h('div', {'class': 'mt-1'}, [h('div', {'class': 'small fw-semibold', text: f[0]}), codeDiff(f[1], f[2])]);
			return h('div', {'class': 'small'}, [h('code', {text: f[0]}), ': ', h('span', {'class': 'nbDiffDel px-1', text: f[1] === null ? '∅' : f[1]}), ' → ', h('span', {'class': 'nbDiffAdd px-1', text: f[2] === null ? '∅' : f[2]})]);
		}));
	}
	/** The differences (NuFormSnapshot::diff) as lists – compare panel, publish dialog and conflicts. */
	function diffView(d) {
		var box = h('div', {'class': 'nbDiff'});
		if (!d.counts.total) { box.appendChild(h('p', {'class': 'text-body-secondary mb-0', text: t('builder_draft_no_changes')})); return box; }
		var sec = function (title, kids) { if (kids.length) box.appendChild(h('details', {'class': 'mb-2', open: 'open'}, [h('summary', {'class': 'fw-semibold', text: title})].concat(kids))); };
		var li = function (cls, text, extra) { return h('div', {'class': 'small ' + cls}, [text].concat(extra || [])); };
		sec(t('builder_sec_form'), d.form.length ? [fieldRows(d.form)] : []);
		var tabs = [];
		d.tabs.added.forEach(function (x) { tabs.push(li('nbDiffAdd', '+ ' + x.title)); });
		d.tabs.removed.forEach(function (x) { tabs.push(li('nbDiffDel', '− ' + x.title)); });
		d.tabs.changed.forEach(function (x) { tabs.push(li('', '~ ' + x.title, [fieldRows(x.fields)])); });
		if (d.tabs.order) tabs.push(li('', t('builder_draft_order')));
		sec(t('builder_sec_tabs'), tabs);
		var objs = [];
		d.objects.added.forEach(function (x) { objs.push(li('nbDiffAdd', '+ ' + x.sob_all_id + ' (' + x.type + ') ' + (x.label || ''))); });
		d.objects.removed.forEach(function (x) { objs.push(li('nbDiffDel', '− ' + x.sob_all_id + ' (' + x.type + ')')); });
		d.objects.changed.forEach(function (x) { objs.push(li('', '~ ' + x.sob_all_id, [fieldRows(x.fields)])); });
		if (d.objects.moved) objs.push(li('', t('builder_draft_moved').replace('%s', d.objects.moved)));
		sec(t('builder_objects'), objs);
		sec(t('builder_sec_events'), d.events.map(function (x) { return li('', x.sob_all_id + ' · ' + x.event, [codeDiff(x.old, x.new)]); }));
		sec('PHP', d.php.map(function (x) { return li('', x.hook === 'AB' ? x.id : x.hook, [codeDiff(x.old, x.new)]); }));
		var br = [];
		d.browse.added.forEach(function (x) { br.push(li('nbDiffAdd', '+ ' + (x.title || x.display))); });
		d.browse.removed.forEach(function (x) { br.push(li('nbDiffDel', '− ' + (x.title || x.display))); });
		d.browse.changed.forEach(function (x) { br.push(li('', '~ ' + x.title, [fieldRows(x.fields)])); });
		if (d.browse.order) br.push(li('', t('builder_draft_order')));
		sec(t('builder_sec_browse'), br);
		if (d.sql_builder) sec('SQL builder', [li('', t('builder_draft_sql_builder'))]);
		return box;
	}
	function panelCompare() {
		var body = h('div', {}, [h('div', {'class': 'text-body-secondary small', text: '…'})]);
		panel(t('builder_draft_summary'), body, null);
		api('draft.diff', {}).then(function (r) {
			body.innerHTML = '';
			body.appendChild(h('p', {'class': 'small text-body-secondary', text: t('builder_draft_changes_n').replace('%s', r.diff.counts.total)}));
			body.appendChild(diffView(r.diff));
			if (r.ddl && r.ddl.length) body.appendChild(h('p', {'class': 'small mt-2'}, [t('builder_draft_ddl') + ' ', r.ddl.map(function (x) { return x.table + '.' + x.column; }).join(', ')]));
		}).catch(function (e) { body.textContent = e.message; });
	}

	/** Publish…: change note, summary, checks; conflict → overwrite only with the typed form code. */
	function publishDialog() {
		var wrap = h('div', {'class': 'modal fade', tabindex: '-1', id: 'nbPublishModal'});
		var note = h('textarea', {'class': 'form-control', id: 'nbPublishNote', rows: '2', maxlength: '1000'});
		note.value = DR.note || '';
		var summary = h('div', {'class': 'nbPublishSummary'}, [h('div', {'class': 'text-body-secondary small', text: '…'})]);
		var msg = h('div', {'class': 'nbPublishMsg'});
		var ok = h('button', {type: 'button', 'class': 'btn btn-success', id: 'nbPublishGo'}, [h('i', {'class': 'bi bi-rocket-takeoff me-1'}), t('builder_draft_publish_btn')]);
		var state = {warnings_ok: false, force: false, confirm_code: ''};
		wrap.appendChild(h('div', {'class': 'modal-dialog modal-lg modal-dialog-scrollable'}, [h('div', {'class': 'modal-content'}, [
			h('div', {'class': 'modal-header'}, [h('h2', {'class': 'modal-title h5', text: t('builder_draft_publish')}), h('button', {type: 'button', 'class': 'btn-close', 'data-bs-dismiss': 'modal', 'aria-label': t('cancel')})]),
			h('div', {'class': 'modal-body'}, [h('label', {'class': 'form-label', 'for': 'nbPublishNote', text: t('builder_draft_note')}), note,
				h('h3', {'class': 'h6 mt-3', text: t('builder_draft_summary')}), summary, msg]),
			h('div', {'class': 'modal-footer'}, [h('button', {type: 'button', 'class': 'btn btn-outline-secondary', 'data-bs-dismiss': 'modal', text: t('cancel')}), ok])
		])]));
		document.body.appendChild(wrap);
		wrap.addEventListener('hidden.bs.modal', function () { wrap.remove(); });
		nuBs.modal(wrap).show();
		api('draft.diff', {}).then(function (r) {
			summary.innerHTML = '';
			summary.appendChild(diffView(r.diff));
			if (r.validation.errors.length) summary.appendChild(h('div', {'class': 'alert alert-danger small mt-2 mb-0'}, [t('builder_draft_errors'), h('ul', {'class': 'mb-0'}, r.validation.errors.map(function (x) { return h('li', {text: x}); }))]));
		}).catch(function (e) { summary.textContent = e.message; });
		var list = function (cls, title, items) { return h('div', {'class': 'alert ' + cls + ' small mt-3 mb-0'}, [title, h('ul', {'class': 'mb-0'}, items.map(function (x) { return h('li', {text: x}); }))]); };
		ok.addEventListener('click', function () {
			msg.innerHTML = '';
			if (!note.value.trim()) { msg.appendChild(h('div', {'class': 'alert alert-danger small mt-3 mb-0', text: t('builder_draft_note_required')})); note.focus(); return; }
			ok.disabled = true;
			fetch('index.php?p=builderapi', {method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/json', 'X-CSRF-Token': D.csrf},
				body: JSON.stringify(Object.assign({action: 'draft.publish', form_id: D.form_id, draft_id: DR.id, note: note.value}, state))})
				.then(function (r) { return r.json(); }).then(function (j) {
					ok.disabled = false;
					if (j.ok) { setDirty(false); alert(t('builder_draft_published').replace('%s', j.version)); window.location.href = DP.live_url; return; }
					if (j.error) { msg.appendChild(h('div', {'class': 'alert alert-danger small mt-3 mb-0', text: j.error})); return; }
					if (j.errors) { msg.appendChild(list('alert-danger', t('builder_draft_errors'), j.errors)); return; }
					if (j.conflict) {
						var code = h('input', {'class': 'form-control form-control-sm mt-2', id: 'nbPublishCode', autocomplete: 'off', 'aria-label': t('builder_draft_overwrite')});
						msg.appendChild(h('div', {'class': 'alert alert-danger small mt-3 mb-0'}, [h('strong', {text: t('builder_draft_conflict')}), h('p', {'class': 'mb-1', text: t('builder_draft_conflict_text')}),
							diffView(j.changes), h('div', {'class': 'mt-2', text: t('builder_draft_overwrite_type').replace('%s', S.form.sfo_code)}), code]));
						state.force = true;
						code.addEventListener('input', function () { state.confirm_code = code.value; });
						ok.lastChild.textContent = t('builder_draft_overwrite');
						ok.className = 'btn btn-danger';
						return;
					}
					if (j.warnings) {
						var cb = h('input', {'class': 'form-check-input', type: 'checkbox', id: 'nbPublishWarn'});
						cb.addEventListener('change', function () { state.warnings_ok = cb.checked; });
						msg.appendChild(h('div', {'class': 'alert alert-warning small mt-3 mb-0'}, [t('builder_draft_warnings'), h('ul', {}, j.warnings.map(function (x) { return h('li', {text: x}); })),
							h('div', {'class': 'form-check'}, [cb, h('label', {'class': 'form-check-label', 'for': 'nbPublishWarn', text: t('builder_draft_warnings_ok')})])]));
					}
				}).catch(function (e) { ok.disabled = false; msg.textContent = e.message; });
		});
	}

	// ------------------------------------------------------------------ start
	api('state', {}).then(function (r) {
		load(r);
		renderDraftBar();
		select('form');
		preview();
		// opened from a form's options menu / Ctrl+Shift+A, H, F, O: ?open=arrange | add | form | objects
		var open = new URLSearchParams(window.location.search).get('open');
		// opened from a form's label menu (Properties…): ?obj=<zzzzsys_object_id>
		var objParam = new URLSearchParams(window.location.search).get('obj');
		if (objParam && objById(objParam)) select('object', objParam);
		if (open === 'add') { var tg = document.querySelector('.nbAddMenu') && document.querySelector('.nbAddMenu').previousElementSibling; if (tg && nuBs.ready()) nuBs.dropdown(tg).show(); }
		if (open === 'objects' && $('#nbObjSearch')) $('#nbObjSearch').focus();
		if (open === 'arrange') { window.nbShowPreview(); var pv = document.querySelector('.nbPreviewCard'); if (pv) pv.scrollIntoView({block: 'start'}); }
		api('files', {}).then(function (f) { FILES.length = 0; Array.prototype.push.apply(FILES, f.files); }).catch(function () {});
		if (S.form.sfo_table) api('columns', {table: S.form.sfo_table}).then(function (c) { tableColumns = c.columns.map(function (x) { return x.name; }); }).catch(function () {});
	}).catch(function (e) { $('#nbPanel').textContent = e.message; });
})();
