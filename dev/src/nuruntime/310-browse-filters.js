	// =================================================================== browse filters
	function reloadBrowse(extra) {
		go(function () {
			var u = new URL(window.location.href);
			u.searchParams.set('ps', N.ps || '');
			u.searchParams.delete('pg');
			Object.keys(extra || {}).forEach(function (k) { if (extra[k] === null || extra[k] === '') u.searchParams.delete(k); else u.searchParams.set(k, extra[k]); });
			return u.toString();
		});
	}
	N.reloadBrowse = reloadBrowse;

	/** nuBuilder: nuAddBrowseFilter(columnId or index).nuSearchablePopup({items}) → property "<id>_filter" used as #<id>_filter# in the browse SQL. */
	api('nuAddBrowseFilter', function (column) {
		var isNum = typeof column === 'number';
		var th = isNum ? g('nuBrowseTitle' + column) : document.querySelector('th[data-nu-browse-id="' + column + '"]');
		var key = (isNum ? 'nuBrowseTitle' + column : column) + '_filter';
		var obj = $(th);
		obj.nuSearchablePopup = function (settings) {
			if (!th) return obj;
			var items = (settings && settings.items) || [];
			if (typeof items === 'string') { try { items = JSON.parse(items); } catch (e) { items = []; } }
			if (settings && settings.multiSelect === true) return multiFilter(th, key, items, settings, obj);   // several values
			var sel = $('<select class="form-select form-select-sm nuBrowseFilterSelect"></select>').attr('aria-label', th.textContent.trim());
			sel.append($('<option value=""></option>').text(M.all || '–'));
			items.forEach(function (it) {
				if (Array.isArray(it) && it.length === 1) it = it[0];
				var v = Array.isArray(it) ? it[0] : it, l = Array.isArray(it) ? (it[1] !== undefined ? it[1] : it[0]) : it;
				if (v === null || v === undefined || String(v) === '') return;
				sel.append($('<option></option>').val(String(v)).text(String(l)));
			});
			var cur = N.props[key] || '';
			sel.val(cur);
			if (cur !== '') $(th).addClass('nuFiltered');
			sel.on('click', function (e) { e.stopPropagation(); });
			sel.on('change', function () { nuSetProperty(key, sel.val()); reloadBrowse(); });
			$(th).find('.nuBrowseFilterSelect').remove();
			$(th).append(sel);
			return obj;
		};
		// nuBuilder Forte 4.9.2: the same with several values, no multiSelect option needed
		obj.nuSearchableMultiPopup = function (settings) { return obj.nuSearchablePopup($.extend({}, settings || {}, {multiSelect: true})); };
		return obj;
	});

	/**
	 * nuBuilder 4.9: nuAddBrowseFilter(col).nuSearchablePopup({items, multiSelect: true}) – a list of check boxes; the property
	 * "<id>_filter" gets the chosen values as a comma list (nu_filter_match / FIND_IN_SET in the browse SQL).
	 */
	function multiFilter(th, key, items, settings, obj) {
		var chosen = String(N.props[key] || '').split(',').filter(function (v) { return v !== ''; });
		var box = $('<details class="nuBrowseFilterMulti"></details>');
		var sum = $('<summary class="btn btn-sm btn-outline-secondary"></summary>').attr('aria-label', th.textContent.trim());
		var menu = $('<div class="nuBrowseFilterMenu shadow-sm"></div>');
		var all = items.slice();
		if (settings.emptyText !== false) all.push([settings.emptyValue !== undefined ? String(settings.emptyValue) : '-1', settings.emptyText || M.empty || '(empty)']);
		all.forEach(function (it, i) {
			if (Array.isArray(it) && it.length === 1) it = it[0];
			var v = Array.isArray(it) ? it[0] : it, l = Array.isArray(it) ? (it[1] !== undefined ? it[1] : it[0]) : it;
			if (v === null || v === undefined || String(v) === '') return;
			var id = 'nuBf_' + key.replace(/\W/g, '_') + '_' + i;
			var cb = $('<input type="checkbox" class="form-check-input">').attr('id', id).val(String(v)).prop('checked', chosen.indexOf(String(v)) >= 0);
			menu.append($('<div class="form-check"></div>').append(cb, $('<label class="form-check-label"></label>').attr('for', id).text(String(l))));
		});
		var apply = $('<button type="button" class="btn btn-sm btn-primary mt-2"></button>').text(M.ok || 'OK');
		var clear = $('<button type="button" class="btn btn-sm btn-outline-secondary mt-2 ms-1"></button>').text(M.all || '–');
		menu.append($('<div></div>').append(apply, clear));
		var label = function () { sum.text(chosen.length ? '(' + chosen.length + ')' : (M.all || '–')); };
		label();
		apply.on('click', function () {
			var vals = menu.find('input:checked').map(function () { return this.value; }).get();
			nuSetProperty(key, vals.join(','));
			reloadBrowse();
		});
		clear.on('click', function () { nuSetProperty(key, ''); reloadBrowse(); });
		box.on('click', function (e) { e.stopPropagation(); });
		box.append(sum, menu);
		if (chosen.length) $(th).addClass('nuFiltered');
		$(th).find('.nuBrowseFilterSelect, .nuBrowseFilterMulti').remove();
		$(th).append(box);
		return obj;
	}

	/**
	 * nuBuilder 4.9: nuAddBrowseTitleSelect(column, options[, width, style]) – a select under a column title; its value is the property
	 * nuBrowseTitle<n>_select (or nuBrowseTitle_<browse id>_select for an id), used as #…_select# in the browse SQL.
	 */
	api('nuAddBrowseTitleSelect', function (column, optionsData, customWidth, style) {
		if (!Array.isArray(optionsData) || !optionsData.length) return null;
		var isNum = typeof column === 'number';
		var th = isNum ? g('nuBrowseTitle' + column) : document.querySelector('th[data-nu-browse-id="' + column + '"]');
		if (!th) { console.warn('nuAddBrowseTitleSelect: no column ' + column); return null; }   // eslint-disable-line no-console
		var index = isNum ? column : +th.getAttribute('data-nu-col');
		var prop = isNum ? 'nuBrowseTitle' + column + '_select' : 'nuBrowseTitle_' + column + '_select';
		var sel = $('<select class="form-select form-select-sm nuBrowseFilterSelect"></select>').attr('id', 'nuBrowseTitle' + index + '_select').attr('aria-label', th.textContent.trim());
		var simple = !Array.isArray(optionsData[0]);
		optionsData.forEach(function (it) {
			var v = simple ? it : it[0], l = simple ? it : (it[1] !== undefined ? it[1] : it[0]);
			sel.append($('<option></option>').val(v === null || v === undefined ? '' : String(v)).text(l === null || l === undefined ? '' : String(l)));
		});
		if (customWidth !== undefined && customWidth !== null && customWidth !== '') sel[0].style.width = typeof customWidth === 'number' ? customWidth + 'px' : String(customWidth);
		if (style && typeof style === 'object') Object.keys(style).forEach(function (k) { try { sel[0].style[k] = style[k]; } catch (e) { /* ignore */ } });
		if (!isNum) sel.attr('data-nu-title-id', column);
		var cur = N.props[prop];
		if (cur !== undefined && cur !== null) sel.val(String(cur));
		if (sel.val() && String(cur || '') !== '') $(th).addClass('nuFiltered');
		sel.on('click mousedown', function (e) { e.stopPropagation(); });
		sel.on('change', function () { nuSetProperty(prop, sel.val()); reloadBrowse(); });
		$(th).find('#' + sel.attr('id')).remove();
		$(th).append(sel);
		return sel;
	});

