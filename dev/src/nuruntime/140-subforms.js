	// =================================================================== subforms
	api('nuSubformObject', function (sf) {
		// '' (or nothing) = the main form, as nuBuilder Forte (id nuBuilder4EditForm, one row)
		if (sf === '' || sf === undefined || sf === null) return nuFORM.subform('');
		var info = (N.subforms || {})[sf] || {fields: []};
		var o = {id: sf, foreign_key: info.foreign_key, primary_key: info.primary_key, object_id: info.object_id, table: info.table,
			fields: ['ID'].concat(info.fields).concat(['nuDelete']), rows: [], deleted: [], edited: [], columns: [], chartData: null, chartDataPivot: null};
		$('#' + sf + ' .nuSubformRow').each(function () {
			var p = this.getAttribute('data-nu-prefix');
			var pk = this.getAttribute('data-nu-primary-key') || '';
			var row = [pk || '-1'], ed = [0];                                         // a new row's ID is -1, as nuBuilder Forte
			// checkboxes as 0 / 1 and an edited mark for nuDelete too, as nuBuilder Forte
			info.fields.forEach(function (f) { var el = g(p + f); row.push(el && el.type === 'checkbox' ? (el.checked ? 1 : 0) : rawValue(el)); ed.push(el && el.classList.contains('nuEdited') ? 1 : 0); });
			var del = g(p + 'nuDelete');
			var isBlankNew = pk === '' && !this.classList.contains('nuRowEdited');
			var d = (del && del.checked) || isBlankNew ? 1 : 0;
			row.push(d); ed.push(0);
			o.rows.push(row); o.deleted.push(d); o.edited.push(ed);
		});
		return o;
	});

	// nuBuilder Forte's nuAddRow(sub-form[, setFocus]): the blank last row becomes a row (a change on its first field – it gets its edit
	// mark, its afterinsertrow event ran when it was drawn) and a new blank row follows; the focus goes to that new row (before,
	// a second blank row was appended). Without a blank row (adding switched off …) a row is appended as before, when there is a template.
	api('nuAddRow', function (sf, setFocus) {
		var last = $('#' + sf + ' .nuSubformBody').children('.nuSubformRow').last()[0];
		var o = nuSubformObject(sf), f = o.fields[1];
		var el = last && last.classList.contains('nuNewRow') && f ? g(last.getAttribute('data-nu-prefix') + f) : null;
		if (!el) return appendRow(sf);
		$(el).trigger('change');
		if (!last.classList.contains('nuRowEdited')) markEdited(el);       // a field the change handler skips (lookup code …)
		var next = last.nextElementSibling;
		if (setFocus !== false && next && next.getAttribute) {
			var np = next.getAttribute('data-nu-prefix'), ne = g(np + f + 'code') || g(np + f);   // a lookup: its code field
			if (ne && ne.focus) ne.focus();
		}
		return last;
	});
	// nuBuilder Forte's sub-form event beforeinsertrow: runs before a new row is added – Forte runs it from nuAddSubformRow,
	// i.e. on every change of a field in a sub-form row; code that sets nuCancel = true stops the new row. Variables as in Forte: t (the
	// changed field), e (event) and nuCancel. @return bool true = cancelled
	function beforeInsert(sf, el) {
		var code = ((N.events || {})[sf] || {}).beforeinsertrow;
		if (!code || !String(code).trim()) return false;
		try { return new Function('t', 'e', 'event', 'var nuCancel = false;\n' + code + '\n;return nuCancel === true;').call(window, el || null,
			{target: el}, {target: el}) === true; }   // eslint-disable-line no-new-func
		catch (x) { reportError(x.message, 'beforeinsertrow'); console.error(x); return false; }
	}
	function appendRow(sf, checked) {
		var tpl = g(sf + 'Template');
		if (!tpl) return null;
		if (!checked && beforeInsert(sf, null)) return null;
		var body = $('#' + sf + ' .nuSubformBody')[0];
		var n = $(body).children('.nuSubformRow').length;
		var html = tpl.innerHTML.split('__ROW__').join(nuPad3(n));
		$(body).append(html);
		var row = g(sf + nuPad3(n));
		nuNextRunDeferred(row);
		initSelects(row);
		fitButtons(row);
		var ev = ((N.events || {})[sf] || {}).afterinsertrow;
		if (ev) runSnippet(ev, {target: row}, row);
		return row;
	}

	api('nuSubformRowObject', function (id, field) {
		var p = $('#' + id).attr('data-nu-prefix') || '';
		return $('#' + p + field);
	});
	api('nuSubformRowId', function (el) { return $(el).closest('.nuSubformRow').attr('data-nu-primary-key'); });
	api('nuSubformRowNumber', function (id) { var p = $('#' + id).attr('data-nu-prefix') || ''; return parseInt(p.slice(-3), 10); });
	api('nuSubformColumnArray', function (sf, field, includeDeleted) {
		var o = nuSubformObject(sf), c = o.fields.indexOf(field), a = [];
		o.rows.forEach(function (r, i) { if (includeDeleted || !o.deleted[i]) a.push(r[c]); });
		return a;
	});
	api('nuSubformColumnUnique', function (sf, field, label) {
		var a = nuSubformColumnArray(sf, field).filter(function (v) { return v !== ''; });
		return a.length === new Set(a).size ? true : (nuTranslate('Duplicate values in') + ' ' + (label || field));
	});
	// nuBuilder Forte: without a field the whole sub-form (also its add / delete controls); enable === false disables
	function sfToggle(sf, field, on) {
		$('#' + sf + ' [data-nu-field' + (field ? '="' + field + '"' : '') + ']').each(function () { nuEnable(this.id, on); });
		if (!field) $('#' + sf).toggleClass('nuSubformDisabled', !on).find('input.nuSubformDelete').prop('disabled', !on);
	}
	api('nuSubformDisable', function (sf, field) { sfToggle(sf, field, false); });
	api('nuSubformEnable', function (sf, field, enable) { sfToggle(sf, field, enable !== false); });
	api('nuSubformHideColumn', function (sf, field) { var i = $('#title_' + sf + field).index(); $('#' + sf + ' tr').each(function () { $(this).children().eq(i).hide(); }); });

