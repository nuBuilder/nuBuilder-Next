/* nuBuilder Next: the "New form" wizard (index.php?p=builder&new=1). Forms are changed in design mode on the form page (* assets/js/nu/nudesign.js) – the former builder (object list, properties panel, preview) was replaced by it. Talks to index.php?p=builderapi. */
(function () {
	'use strict';

	var D = JSON.parse(document.getElementById('nuBuilderData').textContent);
	var L = D.labels || {};
	function t(k) { return L[k] || k; }

	// ------------------------------------------------------------------ helpers
	// chosen columns of an existing table: object yes / no, label, list column, order (drag, arrows, Alt+↑ / ↓).
	// Untouched, nothing is sent and the server makes its default (every column, the auto key left out, six list columns).
	function pickList() {
		var wrap = $('#nbPickWrap'), body = $('#nbPickRows'), all = $('#nbPickAll'), allL = $('#nbPickAllList');
		var cols = [], touched = false, dragged = null;
		var TEXT = ['text', 'mediumtext', 'longtext', 'ntext'];
		function rows() { return $$('tr:not(.nbExtra)', body); }
		function autoPk() {
			var v = $('#nb_pk').value;
			var a = cols.filter(function (c) { return c.auto; })[0];
			return v === '' ? (a ? a.name : '') : (cols.filter(function (c) { return c.name === v && c.auto; })[0] ? v : '');
		}
		function sync() {
			var r = rows(), on = r.filter(function (x) { return $('.nbPickOn', x).checked; });
			var ls = on.filter(function (x) { return $('.nbPickList', x).checked; });
			$('#nbPickCount').textContent = t('builder_pick_count').replace('%s', String(on.length)).replace('%s', String(r.length));
			all.checked = r.length > 0 && on.length === r.length; all.indeterminate = on.length > 0 && on.length < r.length;
			allL.checked = on.length > 0 && ls.length === on.length; allL.indeterminate = ls.length > 0 && ls.length < on.length;
			allL.disabled = on.length === 0;
			r.forEach(function (x, i) {
				var off = !$('.nbPickOn', x).checked;
				x.classList.toggle('nbPickOff', off);
				$('.nbPickList', x).disabled = off; $('.nbPickLabel', x).disabled = off;
				$('.nbPickFlt', x).disabled = off || !$('.nbPickList', x).checked;   // a filter needs the list column
				if (x._sh) x._sh.disable(off);
				$('.nbPickUp', x).disabled = i === 0; $('.nbPickDown', x).disabled = i === r.length - 1;
			});
		}
		function touch() { touched = true; sync(); }
		function move(tr, dir, focusCls) {
			var to = mainSibling(tr, dir);
			if (!to) return;
			if (dir < 0) body.insertBefore(tr, to); else (to._x || to).after(tr);
			placeExtras(body);
			touch();
			var f = $(focusCls || '.nbPickUp', tr);
			if (f && f.disabled) f = $(dir < 0 ? '.nbPickDown' : '.nbPickUp', tr);
			if (f) f.focus();
		}
		function row(c, on, list) {
			var tr = h('tr', {'data-name': c.name});
			var cb = h('input', {type: 'checkbox', 'class': 'form-check-input nbPickOn', 'aria-label': t('builder_pick_obj') + ': ' + c.name});
			cb.checked = on;
			var lb = h('input', {'class': 'form-control form-control-sm nbPickLabel', maxlength: 100, placeholder: c.label || c.name, 'aria-label': t('builder_obj_label') + ': ' + c.name});
			var ls = h('input', {type: 'checkbox', 'class': 'form-check-input nbPickList', 'aria-label': t('builder_pick_list') + ': ' + c.name});
			ls.checked = list;
			var fl = h('input', {type: 'checkbox', 'class': 'form-check-input nbPickFlt', 'aria-label': t('builder_col_filter') + ': ' + c.name});
			var up = h('button', {type: 'button', 'class': 'btn btn-link btn-sm px-1 nbPickUp', title: t('builder_pick_up'), 'aria-label': t('builder_pick_up')}, [h('i', {'class': 'bi bi-arrow-up'})]);
			var dn = h('button', {type: 'button', 'class': 'btn btn-link btn-sm px-1 nbPickDown', title: t('builder_pick_down'), 'aria-label': t('builder_pick_down')}, [h('i', {'class': 'bi bi-arrow-down'})]);
			up.addEventListener('click', function () { move(tr, -1, '.nbPickUp'); });
			dn.addEventListener('click', function () { move(tr, 1, '.nbPickDown'); });
			[cb, ls, fl].forEach(function (x) { x.addEventListener('change', touch); });
			var sh = shownCtl(kindOf(c.type, c.length), 7);
			tr._sh = sh; tr._x = sh.row; sh.row._row = tr;
			sh.sel.addEventListener('change', touch);
			sh.row.addEventListener('input', function () { touched = true; });
			sh.row.addEventListener('change', function () { touched = true; });
			lb.addEventListener('input', function () { touched = true; });
			tr.addEventListener('keydown', function (e) {
				if (!e.altKey || (e.key !== 'ArrowUp' && e.key !== 'ArrowDown')) return;
				e.preventDefault();
				var keep = document.activeElement;
				move(tr, e.key === 'ArrowUp' ? -1 : 1);
				if (keep && tr.contains(keep)) keep.focus();
			});
			// only the grip is draggable (the row is the drag image) – text in the label field stays selectable
			var grip = h('span', {'class': 'nbPickGrip me-1', title: t('builder_pick_drag'), 'aria-hidden': 'true', draggable: 'true'}, [h('i', {'class': 'bi bi-grip-vertical'})]);
			grip.addEventListener('dragstart', function (e) {
				dragged = tr; tr.classList.add('nbPickDragging');
				try { e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', c.name); e.dataTransfer.setDragImage(tr, 10, 10); } catch (x) { /* old browsers */ }
			});
			grip.addEventListener('dragend', function () { tr.classList.remove('nbPickDragging'); dragged = null; placeExtras(body); touch(); });
			tr.appendChild(h('td', {'class': 'nbPickC'}, [grip, cb]));
			tr.appendChild(h('td', {}, [h('code', {'class': 'nbPickName', text: c.name}), h('span', {'class': 'small text-body-secondary ms-1', text: c.type || ''})]));
			tr.appendChild(h('td', {}, [lb]));
			tr.appendChild(h('td', {}, [sh.sel]));
			tr.appendChild(h('td', {'class': 'text-center'}, [ls]));
			tr.appendChild(h('td', {'class': 'text-center'}, [fl]));
			tr.appendChild(h('td', {'class': 'text-nowrap text-end'}, [up, dn]));
			return tr;
		}
		function defaults() {
			body.innerHTML = '';
			var pk = autoPk(), n = 0;
			cols.forEach(function (c) {
				var on = c.name !== pk;
				var list = on && n < 6 && TEXT.indexOf(String(c.type).toLowerCase()) < 0;
				if (list) n++;
				var tr = row(c, on, list);
				body.appendChild(tr); body.appendChild(tr._x);
			});
			touched = false;
			sync();
		}
		body.addEventListener('dragover', function (e) {
			if (!dragged) return;
			e.preventDefault();
			var over = e.target.closest ? e.target.closest('tr') : null;
			if (over && over._row) over = over._row;            // the settings line of a row counts as the row
			if (!over || over === dragged || over.parentNode !== body) return;
			var b = over.getBoundingClientRect();
			body.insertBefore(dragged, e.clientY > b.top + b.height / 2 ? over.nextElementSibling : over);
		});
		body.addEventListener('drop', function (e) { if (dragged) e.preventDefault(); });
		all.addEventListener('change', function () { rows().forEach(function (x) { $('.nbPickOn', x).checked = all.checked; }); touch(); });
		allL.addEventListener('change', function () { rows().forEach(function (x) { if ($('.nbPickOn', x).checked) $('.nbPickList', x).checked = allL.checked; }); touch(); });
		$('#nbPickReset').addEventListener('click', defaults);
		$('#nb_pk').addEventListener('change', function () { if (!touched) defaults(); });
		var gen = $('#nb_generate');
		function genSync() { wrap.hidden = !cols.length || !gen.checked; }
		gen.addEventListener('change', genSync);
		return {
			build: function (c) { cols = c || []; defaults(); genSync(); },
			touched: function () { return touched; },
			value: function () {
				return rows().filter(function (x) { return $('.nbPickOn', x).checked; }).map(function (x) {
					return Object.assign({name: x.getAttribute('data-name'), label: $('.nbPickLabel', x).value.trim(), browse: $('.nbPickList', x).checked,
						filter: $('.nbPickList', x).checked && $('.nbPickFlt', x).checked}, x._sh.value());
				});
			}
		};
	}

	// access levels of the wizard: "Select all" ticks / clears the levels shown, the group select filters by sal_group
	function levelsBar() {
		var all = document.getElementById('nbLvAll');
		if (!all) return;
		var grp = document.getElementById('nbLvGroup'), cnt = document.getElementById('nbLvCount');
		var rows = $$('.nbLevelRow');
		function shown() { return rows.filter(function (r) { return !r.hidden; }).map(function (r) { return r.querySelector('.nbLevel'); }); }
		function sync() {
			var v = shown(), on = v.filter(function (x) { return x.checked; }).length;
			all.checked = v.length > 0 && on === v.length;
			all.indeterminate = on > 0 && on < v.length;
			all.disabled = v.length === 0;
			var n = $$('.nbLevel:checked').length;
			if (cnt) cnt.textContent = t('builder_levels_count').replace('%s', String(n)).replace('%s', String(rows.length));
		}
		all.addEventListener('change', function () { shown().forEach(function (x) { x.checked = all.checked; }); sync(); });
		if (grp) grp.addEventListener('change', function () {
			rows.forEach(function (r) { r.hidden = grp.value !== '*' && r.getAttribute('data-group') !== grp.value; });
			sync();
		});
		rows.forEach(function (r) { r.querySelector('.nbLevel').addEventListener('change', sync); });
		sync();
	}

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

	// "Shown as" of a column (new table and chosen columns of an existing one): the object type, the first choice = what the
	// wizard makes by itself; Select / Multiselect / Lookup open a line below the row for their options / the form to look up in
	var SHOWN = {
		text: ['text', 'textarea', 'select', 'multiselect', 'editor', 'lookup'], longtext: ['textarea', 'editor', 'text', 'multiselect'],
		int: ['number', 'select', 'lookup', 'checkbox'], decimal: ['number', 'select'], date: ['date', 'text'], datetime: ['date', 'text'],
		time: ['time', 'text'], bool: ['checkbox', 'yesno'], id: ['text', 'lookup', 'select']
	};
	// the kind of a column: a type of the new-table grid or a database type of an existing table
	function kindOf(type, length) {
		var x = String(type || '').toLowerCase();
		if (x === 'uuid' || x === 'id') return 'id';
		if (x === 'bigint') return 'int';
		if (SHOWN[x]) return x;
		if (/^(tinyint|bit|boolean|bool)$/.test(x) && (x !== 'tinyint' || !length || +length <= 1)) return 'bool';
		if (/text$|^(mediumtext|longtext|ntext|clob)$/.test(x)) return 'longtext';
		if (/int$/.test(x)) return 'int';
		if (/^(decimal|numeric|float|double|real|money|smallmoney)/.test(x)) return 'decimal';
		if (x === 'date') return 'date';
		if (/^(datetime|datetime2|timestamp|smalldatetime|datetimeoffset)$/.test(x)) return 'datetime';
		if (x === 'time') return 'time';
		return 'text';
	}
	function shownCtl(kind, colspan) {
		var sel = h('select', {'class': 'form-select form-select-sm nbShown', 'aria-label': t('builder_shown')});
		var x = h('tr', {'class': 'nbExtra', hidden: true}), cell = h('td', {colspan: String(colspan)});
		x.appendChild(cell);
		var opt = null, look = null;
		function fill(k) {
			sel.innerHTML = '';
			(SHOWN[k] || SHOWN.text).forEach(function (v) { sel.appendChild(h('option', {value: v, text: t('builder_shown_' + v)})); });
		}
		function extra() {
			cell.innerHTML = ''; opt = null; look = null;
			var v = sel.value, grid = h('div', {'class': 'nbExtraGrid'});
			if (v === 'select' || v === 'multiselect') {
				opt = h('input', {'class': 'form-control form-control-sm font-monospace nbShownOpts', maxlength: 4000, autocomplete: 'off', spellcheck: 'false',
					placeholder: '1|' + t('builder_shown_opt1') + '|2|' + t('builder_shown_opt2'), 'aria-label': t('builder_select_sql')});
				grid.append(h('label', {'class': 'small text-body-secondary', text: t('builder_select_sql')}), opt,
					h('span', {'class': 'small text-body-secondary nbExtraHint', text: t('builder_shown_opts_hint')}));
			} else if (v === 'lookup') {
				look = h('select', {'class': 'form-select form-select-sm nbShownLookup', 'aria-label': t('builder_shown_lookup_form')});
				look.appendChild(h('option', {value: '', text: t('please_select')}));
				(D.lookup_forms || []).forEach(function (f) { look.appendChild(h('option', {value: f[0], text: f[1]})); });
				grid.append(h('label', {'class': 'small text-body-secondary', text: t('builder_shown_lookup_form')}), look,
					h('span', {'class': 'small text-body-secondary nbExtraHint', text: t('builder_shown_lookup_hint')}));
			}
			x.hidden = !grid.children.length;
			if (grid.children.length) {
				cell.appendChild(grid);
				if (look && window.TomSelect) new TomSelect(look, {create: false, maxOptions: null, allowEmptyOption: false, placeholder: t('please_select'),
					dropdownParent: 'body', render: {no_results: function () { return '<div class="no-results">' + t('no_records') + '</div>'; }}});
			}
		}
		sel.addEventListener('change', extra);
		fill(kind);
		return {
			sel: sel, row: x,
			setKind: function (k) { fill(k); extra(); },
			disable: function (off) { sel.disabled = off; x.classList.toggle('nbExtraOff', off); },
			value: function () {
				var r = {shown: sel.value};
				if (opt) r.options = opt.value.trim();
				if (look) r.lookup = look.value;
				return r;
			}
		};
	}
	// the line of a row's settings follows the row (after moving, dragging, a new row)
	function placeExtras(body) {
		$$('tr:not(.nbExtra)', body).forEach(function (tr) { if (tr._x && tr.nextElementSibling !== tr._x) tr.after(tr._x); });
	}
	function mainSibling(tr, dir) {
		var n = dir < 0 ? tr.previousElementSibling : tr.nextElementSibling;
		while (n && n.classList.contains('nbExtra')) n = dir < 0 ? n.previousElementSibling : n.nextElementSibling;
		return n;
	}

	// remove button of a list row: the row is crossed out and the same button brings it back; crossed-out rows are
	// left out when the panel (or the new form) is saved – window.nuToggleRemoved in nuapp.js
	function removeBtn(row, cls) {
		var b = h('button',
			{type: 'button', 'class': cls || 'btn btn-link btn-sm px-1 text-danger', title: t('remove'), 'aria-label': t('remove'), 'aria-pressed': 'false'},
			[h('i', {'class': 'bi bi-x-circle'})]);
		b.addEventListener('click', function () {
			window.nuToggleRemoved(row, b, {remove: t('remove'), undo: t('remove_undo'), pending: t('remove_pending')});
		});
		return b;
	}

	function api(action, data) {
		return NuUtil.post('index.php?p=builderapi', Object.assign({action: action}, data || {}), {csrf: D.csrf})
			.then(function (j) { if (!j.ok) throw new Error(j.error || 'Error'); return j; });
	}

	// ------------------------------------------------------------------ "new form" wizard
	if (D['new'] || document.getElementById('nbNewForm')) {
		var form = $('#nbNewForm');
		var stale = $('#nbStale');
		if (stale) stale.remove();                    // this script is current: no "outdated files" warning
		var COLTYPES = ['text', 'longtext', 'int', 'bigint', 'decimal', 'date', 'datetime', 'time', 'bool', 'id', 'uuid'];
		var DEFLEN = {text: '255', decimal: '15,2'};

		function mode() { return $('#nb_mode_new').checked ? 'new' : 'existing'; }
		function applyMode() {
			var launch = $('#nb_type').value === 'launch' || $('#nb_type').value === 'page';   // page forms: no table either
			var nf = $('#nbNewForm');
			if (nf) { nf.classList.toggle('nbIsNew', mode() === 'new'); nf.classList.toggle('nbIsExisting', mode() === 'existing'); nf.classList.toggle('nbIsLaunch', $('#nb_type').value === 'launch'); }
			$('.nbTableSection').classList.toggle('d-none', launch);
			var lay = $('#nb_layout');
			if (lay) lay.closest('[class*="col-"]').classList.toggle('d-none', $('#nb_type').value === 'page');
			$$('.nbExisting').forEach(function (e) { e.classList.toggle('d-none', launch || mode() !== 'existing'); });
			$$('.nbNew').forEach(function (e) { e.classList.toggle('d-none', launch || mode() !== 'new'); });
			if (mode() === 'new' && !$('#nbColRows').children.length) addCol();
			var after = $('.nbAfter');                              // User Home button / access levels: not for sub-forms
			if (after) after.classList.toggle('d-none', $('#nb_type').value === 'subform');
			var noTable = $('.nbNoTable');                          // step 2 of a launch / page form: nothing to choose
			if (noTable) noTable.classList.toggle('d-none', !launch);
		}

		// three steps: Form · Table and fields · Layout and access; Next checks the step, Enter = Next until the last one
		var step = 1;
		function showStep(n) {
			step = n;
			$$('.nbStep').forEach(function (e) { e.hidden = +e.getAttribute('data-step') !== n; });
			$$('.nbStepMark').forEach(function (li) {
				var k = +li.getAttribute('data-step');
				li.classList.toggle('active', k === n);
				li.classList.toggle('done', k < n);
				if (k === n) li.setAttribute('aria-current', 'step'); else li.removeAttribute('aria-current');
			});
			$('#nbStepBack').hidden = n === 1;
			$('#nbStepNext').hidden = n === 3;
			$('#nbCreate').hidden = n !== 3;
			$('#nbNewError').classList.add('d-none');
			var first = $$('.nbStep[data-step="' + n + '"] input:not([type=radio]):not([type=checkbox]), .nbStep[data-step="' + n + '"] select').filter(function (x) { return x.offsetParent !== null; })[0];
			if (first && n > 1) first.focus();
		}
		function stepError(msg) { var err = $('#nbNewError'); err.textContent = msg; err.classList.remove('d-none'); }
		function stepOk(n) {
			if (n === 1) return [$('#nb_desc'), $('#nb_code')].every(function (x) { x.value = x.value.trim(); return x.reportValidity(); });
			if (n === 2 && !$('.nbTableSection').classList.contains('d-none')) {
				if (mode() === 'existing' && !$('#nb_table').value) { stepError(t('builder_nf_err_table')); return false; }
				if (mode() === 'new') {
					var tn = $('#nb_new_table');
					tn.value = tn.value.trim();
					if (!tn.value) { stepError(t('builder_nf_err_table')); tn.focus(); return false; }
					if (!tn.reportValidity()) return false;
					if (existingTables.indexOf(tn.value.toLowerCase()) >= 0) { stepError(t('builder_err_table_exists').replace('%s', tn.value)); return false; }
				}
			}
			return true;
		}
		$('#nbStepNext').addEventListener('click', function () { if (stepOk(step)) showStep(step + 1); });
		$('#nbStepBack').addEventListener('click', function () { showStep(Math.max(1, step - 1)); });
		$$('.nbStepMark').forEach(function (li) {
			li.addEventListener('click', function () { var k = +li.getAttribute('data-step'); if (k < step) showStep(k); });
		});
		$$('input[name=table_mode]').forEach(function (r) { r.addEventListener('change', applyMode); });
		$('#nb_type').addEventListener('change', applyMode);

		// existing tables
		var existingTables = [];
		api('tables', {}).then(function (r) {
			existingTables = r.tables.map(function (x) { return x.toLowerCase(); });
			var s = $('#nb_table'), views = (r.views || []).map(function (x) { return x.toLowerCase(); });
			// views (under their own heading – Tables / Views – instead of a "(view)" suffix; no headings without views)
			var vs = r.tables.filter(function (tb) { return views.indexOf(tb.toLowerCase()) >= 0; }), ts = r.tables.filter(function (tb) { return vs.indexOf(tb) < 0; });
			var opt = function (tb, isView) { return h('option', {value: tb, text: tb, 'data-view': isView ? '1' : null}); };
			if (vs.length) {
				var gt = h('optgroup', {label: t('builder_group_tables')}), gv = h('optgroup', {label: t('builder_group_views')});
				ts.forEach(function (tb) { gt.appendChild(opt(tb, false)); });
				vs.forEach(function (tb) { gv.appendChild(opt(tb, true)); });
				if (ts.length) s.appendChild(gt);
				s.appendChild(gv);
			} else ts.forEach(function (tb) { s.appendChild(opt(tb, false)); });
			// searchable (Tom Select, like Select2): type to filter the tables; the select keeps its value and change event
			if (window.TomSelect) new TomSelect(s, {create: false, maxOptions: null, allowEmptyOption: false, placeholder: t('please_select'),
				searchField: ['text'], sortField: [{field: '$order'}], lockOptgroupOrder: true, render: {no_results: function () { return '<div class="no-results">' + t('no_records') + '</div>'; }}});
		}).catch(function () {});
		$('#nb_table').addEventListener('change', function () {
			var info = $('#nbColumnInfo'), pk = $('#nb_pk');
			pk.innerHTML = '';
			pk.appendChild(h('option', {value: '', text: t('builder_pk_auto')}));
			info.textContent = '';
			var vi = $('#nbViewInfo');
			vi.hidden = true;
			vi.textContent = '';
			pick.build([]);
			if (!this.value) return;
			api('columns', {table: this.value}).then(function (r) {
				pick.build(r.columns);
				var auto = r.columns.filter(function (c) { return c.auto; })[0];
				r.columns.forEach(function (c) { pk.appendChild(h('option', {value: c.name, text: c.name + (c.auto ? ' (auto)' : '')})); });
				pk.options[0].textContent = t('builder_pk_auto') + (auto ? ': ' + auto.name : '');
				info.textContent = t('builder_columns_found').replace('%d', r.columns.length);
				if (r.view) viewInfo(r.view);
			}).catch(function (e) { info.textContent = e.message; });
		});

		var pick = pickList();

		// a view was picked: the suggested key column and whether the database can save to it; read only → form type Browse
		function viewInfo(v) {
			var vi = $('#nbViewInfo'), type = $('#nb_type'), lines = [], warn = false;
			if (v.updatable === true) lines.push(t('builder_view_ok'));
			else if (v.updatable === false) {
				warn = true;
				if (type.value === 'browseedit' || type.value === 'edit') { type.value = 'browse'; type.dispatchEvent(new Event('change')); lines.push(t('builder_view_ro')); }
				else lines.push(t('builder_view_ro_kept'));
			} else lines.push(t('builder_view_unknown'));
			if (v.key) {
				$('#nb_pk').value = v.key;
				lines.push(t('builder_view_key').replace('%s', v.key));
				if (v.unique === false) { warn = true; lines.push(t('builder_view_key_dup').replace('%s', v.key)); }
			}
			vi.innerHTML = '';
			lines.forEach(function (l, i) { if (i) vi.appendChild(h('br', {})); vi.appendChild(document.createTextNode(l)); });
			vi.className = 'alert py-2 px-3 mt-2 mb-0 small ' + (warn ? 'alert-warning' : 'alert-info');
			vi.hidden = false;
		}

		// new table: column grid
		function addCol(c) {
			c = c || {};
			var tr = h('tr', {});
			var name = h('input',
				{'class': 'form-control form-control-sm', 'data-f': 'name', value: c.name || '', maxlength: 64, autocomplete: 'off', spellcheck: 'false',
					'aria-label': t('builder_col_name'), pattern: '[A-Za-z_][A-Za-z0-9_]*'});
			var label = h('input', {'class': 'form-control form-control-sm', 'data-f': 'label', value: c.label || '', maxlength: 100, 'aria-label': t('builder_obj_label')});
			var type = h('select', {'class': 'form-select form-select-sm', 'data-f': 'type', 'aria-label': t('builder_col_type')});
			COLTYPES.forEach(function (x) { type.appendChild(h('option', {value: x, text: t('builder_coltype_' + x)})); });
			type.value = c.type || 'text';
			var sh = shownCtl(kindOf(type.value), 7);
			tr._sh = sh; tr._x = sh.row; sh.row._row = tr;
			// a removed row (crossed out) takes its settings line along
			new MutationObserver(function () { sh.row.classList.toggle('nuRowRemoved', tr.classList.contains('nuRowRemoved')); }).observe(tr, {attributes: true, attributeFilter: ['class']});
			var len = h('input', {'class': 'form-control form-control-sm nbLen', 'data-f': 'length', value: c.length || DEFLEN[type.value] || '', maxlength: 8, 'aria-label': t('builder_col_length')});
			var req = h('input', {type: 'checkbox', 'class': 'form-check-input', 'data-f': 'required', 'aria-label': t('builder_col_required')});
			function syncLen() {
				var uses = type.value === 'text' || type.value === 'decimal';
				len.disabled = !uses;
				if (!uses) len.value = ''; else if (!len.value) len.value = DEFLEN[type.value];
				req.disabled = type.value === 'bool';
				if (req.disabled) req.checked = false;
			}
			type.addEventListener('change', function () { len.value = ''; syncLen(); sh.setKind(kindOf(type.value)); });
			syncLen();
			name.addEventListener('input', function () {           // label follows the name until edited
				if (!label.dataset.touched) label.value = this.value.replace(/^[a-z]{2,5}_/, '').replace(/_/g, ' ').replace(/\b\w/g, function (m) { return m.toUpperCase(); });
			});
			label.addEventListener('input', function () { this.dataset.touched = '1'; });
			tr.appendChild(h('td', {}, [name]));
			tr.appendChild(h('td', {}, [label]));
			tr.appendChild(h('td', {}, [type]));
			tr.appendChild(h('td', {}, [sh.sel]));
			tr.appendChild(h('td', {}, [len]));
			tr.appendChild(h('td', {'class': 'text-center'}, [req]));
			tr.appendChild(h('td', {'class': 'text-nowrap'}, [
				h('button',
					{type: 'button', 'class': 'btn btn-link btn-sm px-1', 'aria-label': t('builder_up'),
						onclick: function () { var p = mainSibling(tr, -1); if (p) { tr.parentNode.insertBefore(tr, p); placeExtras(tr.parentNode); } }},
					[h('i', {'class': 'bi bi-chevron-up'})]),
				removeBtn(tr)
			]));
			$('#nbColRows').appendChild(tr);
			$('#nbColRows').appendChild(sh.row);
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
			if (step < 3) { if (stepOk(step)) showStep(step + 1); return; }
			var err = $('#nbNewError');
			err.classList.add('d-none');
			var d = {description: $('#nb_desc').value.trim(), code: $('#nb_code').value.trim(), type: $('#nb_type').value, layout: $('#nb_layout').value, table_mode: mode()};
			if (mode() === 'existing') {
				d.table = $('#nb_table').value;
				d.primary_key = $('#nb_pk').value;
				d.generate = $('#nb_generate').checked;
				if (d.generate && pick.touched()) d.pick = pick.value();
			} else {
				d.table = $('#nb_new_table').value.trim();
				d.primary_key = $('#nb_new_pk').value.trim();
				d.pk_type = $('#nb_new_pk_type').value;
				d.audit = $('#nb_audit').checked;
				d.nulog = $('#nb_nulog').checked;
				d.columns = $$('#nbColRows tr:not(.nuRowRemoved):not(.nbExtra)').map(function (tr) {
					var c = {};
					$$('[data-f]', tr).forEach(function (x) { c[x.getAttribute('data-f')] = x.type === 'checkbox' ? x.checked : x.value; });
					return Object.assign(c, tr._sh.value());
				});
				if (d.table && existingTables.indexOf(d.table.toLowerCase()) >= 0) {
					err.textContent = t('builder_err_table_exists').replace('%s', d.table);
					err.classList.remove('d-none');
					return;
				}
			}
			if ($('#nb_userhome')) d.user_home = $('#nb_userhome').checked;
			d.levels = $$('.nbLevel:checked').map(function (x) { return x.value; });
			var btn = form.querySelector('button[type=submit]');
			btn.disabled = true;
			api('form.create', d).then(function (r) {
				// the new form opens in design mode (pages/nubuilder.php → NuDesign::fromBuilder; page forms: their own editor)
				window.location.href = 'index.php?p=builder&f=' + encodeURIComponent(r.form_id);
			})
				.catch(function (x) { err.textContent = x.message; err.classList.remove('d-none'); btn.disabled = false; });
		});
		levelsBar();
		applyMode();
		return;
	}
})();
