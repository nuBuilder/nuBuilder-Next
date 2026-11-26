	// =================================================================== formatting (assets/js/nu/nuformat.js)
	var addFormatting = NuFormatLib.add, removeFormatting = NuFormatLib.remove;

	/** A stored date / date-time / time value that exists (2026-02-30 does not). */
	function validDate(v) {
		if (/^\d{2}:\d{2}(:\d{2})?$/.test(v)) return +v.substr(0, 2) < 24 && +v.substr(3, 2) < 60;
		var m = /^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::(\d{2}))?)?$/.exec(v);
		if (!m) return false;
		if (m[1] === '0000') return true;                                    // nuBuilder's empty date
		var d = new Date(+m[1], +m[2] - 1, +m[3]);
		return d.getFullYear() === +m[1] && d.getMonth() === +m[2] - 1 && d.getDate() === +m[3] && (m[4] === undefined || (+m[4] < 24 && +m[5] < 60));
	}
	/** Applies the display format of an input and marks negative numbers / incomplete masks. */
	function applyFormat(el, typing) {
		var f = el.getAttribute && el.getAttribute('data-nu-format');
		if (!f) return;
		if (typing && f.charAt(0).toUpperCase() === 'T') {
			var typed = NuFormatLib.mask(el.value, f.substring(2));
			if (typed.masked !== el.value && typed.raw !== '') el.value = typed.masked;
		} else if (el.value !== '') {
			var raw = removeFormatting(el.value, f), before = el.value;
			if (f.charAt(0).toUpperCase() === 'D' && !validDate(raw)) {        // nuBuilder (nuReformat): a date it cannot read is cleared
				el.value = '';
				['nuFormatValueClearedGlobal', 'nuFormatValueCleared'].forEach(function (fn) {
					if (typeof window[fn] === 'function') { try { window[fn]($(el), before); } catch (x) { reportError(x.message, fn); } }
				});
				el.classList.remove('nuNegative');
				return;
			}
			var formatted = addFormatting(raw, f);
			if (formatted !== el.value) el.value = formatted;
			el.classList.toggle('nuNegative', NuFormatLib.cssClass(raw, f) !== '');
		}
		if (el.value === '') el.classList.remove('nuNegative');
		if (f.charAt(0).toUpperCase() === 'T') el.classList.toggle('is-invalid', !typing && !NuFormatLib.maskComplete(el.value, f));
	}
	N.applyFormat = applyFormat;

	// text masks: format while typing (only when the caret is at the end and not while deleting)
	$(document).on('input', '#nuEditForm input[data-nu-format^="T|"]', function (e) {
		var oe = e.originalEvent || {};
		if (/^delete/.test(oe.inputType || '') || this.selectionStart !== this.value.length) return;
		applyFormat(this, true);
	});

	window.nuFORM = window.nuFORM || {};
	nuFORM.addFormatting = addFormatting;
	nuFORM.removeFormatting = function (v, f, id) { return rawFormatted(v, f, id); };
	nuFORM.getCurrent = function () {
		// + browse_columns / browse_rows / … of a list, as Forte's breadcrumb (form code: nuFORM.getCurrent().browse_columns)
		return $.extend(nuCurrentProperties(), {hash: nuHashFromEditForm()});
	};
	nuFORM.SQLFields = function (sql) {
		var m = /select\s+([\s\S]*?)\s+from\s/i.exec(String(sql || ''));
		if (!m) return [];
		return m[1].split(',').map(function (s) { s = s.trim(); var a = /\s+as\s+(\w+)$/i.exec(s); return a ? a[1] : s.replace(/^.*\./, ''); });
	};

	// nuBuilder's nuFORM (nuFormObject) – thin wrappers onto nuBuilder Next's functions. The table / form schema is not
	// sent to the browser: tableSchema / formSchema stay empty, getTables() and the field lists return [].
	// nuBuilder Forte keeps the breadcrumb's state in nuFORM (nuformclass.js addBreadcrumb) – form code reads e.g.
	// nuFORM.getProperty('tab_start')[0].tabNumber; those keys come from the page, the rest are properties
	var crumbSet = {};
	nuFORM.getProperty = function (f) {
		if (Object.prototype.hasOwnProperty.call(crumbSet, f)) return crumbSet[f];
		var b = crumbBase();
		if (Object.prototype.hasOwnProperty.call(b, f)) return b[f];
		return nuGetProperty(f);
	};
	nuFORM.setProperty = function (f, v) { if (Object.prototype.hasOwnProperty.call(crumbBase(), f)) { crumbSet[f] = v; return; } return nuSetProperty(f, v); };
	nuFORM.subforms = function () { return [''].concat($('#nuEditForm .nuSubform[id]').map(function () { return this.id; }).get().filter(function (id) { return (N.subforms || {})[id]; })); };
	nuFORM.subform = function (id) {
		if (id) return nuSubformObject(id);
		// '' = the main form as a one-row sub-form object, as nuBuilder Forte: ID, nuDelete, then the fields; checkboxes as 0 / 1
		var v = mainValues(), keys = Object.keys(v);
		return {id: 'nuBuilder4EditForm', foreign_key: '', primary_key: N.form.primary_key || '', object_id: N.form.id, table: N.form.table || '', action: 'save',
			fields: ['ID', 'nuDelete'].concat(keys), rows: [[N.record_id, 0].concat(keys.map(function (k) { var el = g(k); return el && el.type === 'checkbox' ? (el.checked ? 1 : 0) : v[k]; }))],
			deleted: [0], edited: [[0, 0].concat(keys.map(function (k) { var el = g(k); return el && el.classList.contains('nuEdited') ? 1 : 0; }))], columns: [],
			chartData: null, chartDataPivot: null};
	};
	// nuBuilder Forte's nuFORM.data(): the main form as id 'nuBuilder4EditForm' (object_id = the form, action 'save'), then its sub-forms
	nuFORM.data = function () {
		return nuFORM.subforms().map(function (id) {
			var o = nuFORM.subform(id); o.columns = null; o.action = 'save';
			if (id === '') { o.id = 'nuBuilder4EditForm'; o.object_id = N.form.id; }
			return o;
		});
	};
	nuFORM.calc = function (field) {
		var p = String(field).split('.');
		if (p.length !== 2) { var el = g(field); return el ? nuFORM.removeFormatting(el.value, el.getAttribute('data-nu-format') || '') : 0; }
		var o = nuSubformObject(p[0]), c = o.fields.indexOf(p[1]), v = 0;
		if (c < 0) return 0;
		o.rows.forEach(function (r, i) { if (!o.deleted[i]) v = parseFloat((Number(v) + Number(r[c])).toPrecision(10)); });
		return Number(v);
	};
	nuFORM.scrollList = function (e, l) {
		if (!e || !Array.isArray(l) || !l.length || (e.key !== 'ArrowUp' && e.key !== 'ArrowDown')) return;
		var el = e.target, i = l.indexOf(el.value);
		i = i < 0 ? (e.key === 'ArrowDown' ? 0 : l.length - 1) : (i + (e.key === 'ArrowDown' ? 1 : l.length - 1)) % l.length;
		$(el).val(l[i]).trigger('change');
		if (e.preventDefault) e.preventDefault();
	};
	nuFORM.tableSchema = nuFORM.tableSchema || {};
	nuFORM.formSchema = nuFORM.formSchema || {};
	nuFORM.scroll = nuFORM.scroll || [];
	nuFORM.getTables = nuFORM.getJustTables = function () { return Object.keys(nuFORM.tableSchema); };
	nuFORM.getForms = function () { return Object.keys(nuFORM.formSchema); };
	nuFORM.tableFields = nuFORM.formFields = function () { return []; };
	nuFORM.dataType = function () { return ''; };
	nuFORM.tablesFromSQL = function (sql) {
		var out = [], re = /\b(?:from|join)\s+[`"[]?([\w.]+)/gi, m;
		while ((m = re.exec(String(sql || ''))) !== null) if (out.indexOf(m[1]) < 0) out.push(m[1]);
		return out;
	};
	Object.defineProperty(nuFORM, 'edited', {configurable: true, get: function () { return !!N.edited; }, set: function (v) { N.edited = !!v; }});
	Object.defineProperty(nuFORM, 'breadcrumbs', {configurable: true, get: function () {   // earlier forms (title / url), the current one = its properties
		return (N.breadcrumbs || []).slice(0, -1).map(function (b) { return {title: b.title || '', url: b.url || ''}; }).concat([nuCurrentProperties()]);
	}});
	nuFORM.removeLast = nuFORM.removeAfter = nuFORM.addBreadcrumb = function () { console.warn('nuFORM: breadcrumbs are kept by nuBuilder Next.'); };

	// nuBuilder's nuSERVERRESPONSE (its last answer: the form's properties) – built from nuCurrentProperties() when read
	var serverResponse = null;
	Object.defineProperty(window, 'nuSERVERRESPONSE', {configurable: true,
		get: function () {
			if (serverResponse) return serverResponse;
			return $.extend({viewSchema: [], tableSchema: {}, formSchema: {}, buttons: {}, user_a11y: false}, nuCurrentProperties(), {
				title: N.form.description || '', search: N.browse ? N.browse.q : '', browse_filtered_rows: N.browse ? N.browse.total : 0,
				home_id: (N.user && N.user.home_id) || '',
				// in the order of nuBuilder Forte's list (content boxes, order, tab, run objects last) – form code may count on it
				objects: (N.object_order && N.object_order.length ? N.object_order : Object.keys(N.objects || {}))
					.filter(function (id) { return N.objects && N.objects[id]; }).map(function (id) { return $.extend({id: id}, N.objects[id]); })
			});
		},
		set: function (v) { serverResponse = v; }
	});

	// images of form code with a path relative to nuBuilder Forte's folder (img src="custom/graphics/x.png") that are not in nuBuilder
	// Next's folder: loaded once more from Forte's folder
	document.addEventListener('error', function (e) {
		var el = e.target;
		if (!el || el.tagName !== 'IMG' || !N.forte_base || el.getAttribute('data-nu-forte')) return;
		var src = el.getAttribute('src') || '';
		if (src === '' || /^([a-z][a-z0-9+.-]*:|\/|#|\.\.\/)/i.test(src)) return;
		el.setAttribute('data-nu-forte', '1');
		el.setAttribute('src', N.forte_base + src);
	}, true);

