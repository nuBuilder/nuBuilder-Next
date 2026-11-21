	// =================================================================== nuBuilder API, part 2 (0.7.2)
	// ---- user details (blank for the globeadmin, like nuBuilder)
	api('nuUserId', function () { return N.user.id || ''; });
	api('nuUserLogin', function () { return N.user.login || ''; });
	// permission items (zzzzsys_permission_item.pme_ident) of the user; the globeadmin has all unless userOnly
	api('nuUserPermissions', function () { return (N.user.permissions || []).slice(); });
	api('nuUserHasPermission', function (item, userOnly) {
		if (userOnly !== true && N.user.globeadmin) return true;
		return (N.user.permissions || []).indexOf(String(item)) !== -1;
	});
	api('nuAccessLevelId', function () { return N.user.access_level_id || ''; });
	api('nuUserCode', function () { return N.user.code || ''; });
	api('nuUserPosition', function () { return N.user.position || ''; });
	api('nuUserDepartment', function () { return N.user.department || ''; });
	api('nuUserTeam', function () { return N.user.team || ''; });
	api('nuUserFirstName', function () { return N.user.first_name || ''; });
	api('nuUserLastName', function () { return N.user.last_name || ''; });
	api('nuUserAdditional1', function () { return N.user.additional1 || ''; });
	api('nuUserAdditional2', function () { return N.user.additional2 || ''; });

	// ---- tabs
	function tabIndexByTitle(title) {
		var want = [String(title), nuTranslate(String(title))].map(function (x) { return x.trim().toLowerCase(); });
		var found = -1;
		$('.nuTab').each(function () {
			if (found < 0 && want.indexOf($(this).text().trim().toLowerCase()) >= 0) found = +this.getAttribute('data-nu-tab');
		});
		return found;
	}
	function selectedTab() { return $('.nuTab.active')[0] || null; }
	api('nuOpenTab', function (i) { nuSelectTab(+i); });
	// nuBuilder Forte returns the number as text ("0", "1" …)
	api('nuSelectedTabNumber', function () { var t = selectedTab(); return t ? String(t.getAttribute('data-nu-tab')) : null; });
	// nuBuilder Forte's nuGetFirstObject(objects, tabNr) – the first object of a tab (-1 = any) a user can type into, as jQuery
	// object (lookup: its code field); null when there is none. objects = nuSERVERRESPONSE.objects.
	function shown(el) { var $e = $(el); return $e.length > 0 && $e.css('display') !== 'none' && $e.css('visibility') !== 'hidden' && !$e.is(':hidden'); }
	api('nuGetFirstObject', function (objects, tabNr) {
		var SKIP = ['nuReadonly', 'nuHtml', 'nuImage', 'nuWord', 'nuCalculator', 'nuContentBoxContainer'];
		var any = tabNr === undefined || tabNr === null || Number(tabNr) === -1;
		var tabId = any ? null : $('.nuTab[data-nu-tab="' + String(tabNr).replace(/[^0-9]/g, '') + '"]').attr('data-nu-tab-id');
		var list = objects || [];
		for (var i = 0; i < list.length; i++) {
			var id = list[i] && list[i].id, o = (N.objects || {})[id], el = id ? g(id) : null;
			if (!el || !o) continue;
			if (!any && String(o.tab) !== String(tabId)) continue;
			var code = g(id + 'code'), ts = el.tomselect ? el.tomselect.control_input : null;
			// own visibility test: form code may replace nuIsVisible() with a version that takes only an id
			if (!nuIsEnabled(id) || !(shown(el) || (code && shown(code)) || (ts && shown(ts)))) continue;
			if (SKIP.some(function (c) { return el.classList.contains(c); }) || el.readOnly) continue;
			return code ? $(code) : (ts && !shown(el) ? $(ts) : $(el));
		}
		return null;
	});
	// nuBuilder Forte's nuAddHome(): its Home icon in the breadcrumb – nuBuilder Next always shows its own Home, so nothing to add
	api('nuAddHome', function () { window.nuLoginH = ''; });
	// suggestions while typing (assets/js/nu/nusuggest.js): a list below a text input – a list, a function, a URL or the object's query
	api('nuNextAutocomplete', function (id, options) { return window.NuSuggest ? window.NuSuggest.attach(id, options) : null; });
	api('nuSelectedTabId', function () { var t = selectedTab(); return t ? t.getAttribute('data-nu-tab-id') : null; });
	api('nuSelectedTabTitle', function () { var t = selectedTab(); return t ? t.innerHTML : null; });
	api('nuShowTabByTitle', function (title, visible) {
		var i = tabIndexByTitle(title);
		if (i >= 0) { if (visible === false) nuHideTab(i); else nuShowTab(i); }
	});
	api('nuHideTabByTitle', function (title) { nuShowTabByTitle(title, false); });
	api('nuHideTabs', function () { Array.prototype.forEach.call(arguments, function (a) { if (a === parseInt(a, 10)) nuHideTab(a); else nuHideTabByTitle(a); }); });
	api('nuRemoveTabs', function () {
		Array.prototype.forEach.call(arguments, function (i) {
			var t = g('nuTab' + i);
			if (!t) return;
			var pane = g('nuPane' + i);
			$(t).closest('li').remove();
			if (pane) $(pane).find('[data-nu-field]').addClass('nuRemovedTab');   // values are kept, the tab is gone
			if (pane) $(pane).hide();
		});
	});
	api('nuTabSetMarker', function (tabId, fieldIdOrFlag, className) {
		if (!tabId || fieldIdOrFlag === null || fieldIdOrFlag === undefined) return;
		var tab = document.querySelector('.nuTab[data-nu-tab-id="' + String(tabId).replace(/"/g, '') + '"]');
		if (!tab) return;
		var on = typeof fieldIdOrFlag === 'boolean' ? fieldIdOrFlag : String(nuGetValue(fieldIdOrFlag) || '').trim() !== '';
		String(className || 'nuTabMarker').split(/\s+/).forEach(function (c) { if (c) tab.classList.toggle(c, on); });
	});

	// ---- state
	api('nuIsClone', function () { return !!N.cloning; });
	api('nuIsNewRecord', function () { return !!N.is_new && !N.cloning; });
	window.nuSubformRow = -1;
	$(document).on('focusin', '[data-nu-prefix]', function () {
		var p = this.getAttribute('data-nu-prefix') || '';
		if (p) window.nuSubformRow = Number(p.slice(-3));
	});
	api('nuCurrentRow', function () { return window.nuSubformRow; });
	// nuBuilder: $('#' + prefix + id).val – the value as shown in the field (formatted numbers stay formatted)
	api('nuSubformValue', function (el, field) {
		var p = $(el).attr('data-nu-prefix') || $(el).closest('[data-nu-prefix]').attr('data-nu-prefix') || '';
		return $('#' + p + field).val();
	});

	/**
	 * nuBuilder 4.7+: the clicked browse cell (or another cell of its row – column index or the column's id, data-nu-column-id)
	 * as {pk, row, column, colId, html, value, $cell}. Takes the cell element or the click event (nuSelectBrowse(event, element)).
	 */
	api('nuBrowseRow', function (elOrEvent, columnNumberOrId) {
		var $invoked = (elOrEvent && elOrEvent.target) ? $(elOrEvent.currentTarget || elOrEvent.target) : $(elOrEvent);
		if (!$invoked.is('[data-nu-row]')) $invoked = $invoked.closest('[data-nu-row]');
		var rowAttr = $invoked.attr('data-nu-row');
		var row = rowAttr != null ? parseInt(rowAttr, 10) : null;
		var $cell = $invoked;
		if (columnNumberOrId != null && row !== null) {
			var $byPk = $('.nuCell[data-nu-row="' + row + '"][data-nu-column-id="' + String(columnNumberOrId).replace(/["\\]/g, '') + '"]');
			$cell = $byPk.length ? $byPk : $('#nucell_' + row + '_' + columnNumberOrId);
		}
		return {
			pk: $cell.attr('data-nu-primary-key'), row: row, column: parseInt($cell.attr('data-nu-column'), 10), colId: $cell.attr('data-nu-column-id'),
			html: $cell.html(), value: $cell.text(), $cell: $cell
		};
	});

	// ---- dates and ids
	function ymd(d) { return d.getFullYear() + '-' + nuPad2(d.getMonth() + 1) + '-' + nuPad2(d.getDate()); }
	api('nuCurrentDate', function (format) {
		var s = ymd(new Date());
		return format === undefined ? s : nuFORM.addFormatting(s, /^D\|/.test(format) ? format : 'D|' + format);
	});
	api('nuSetDateValue', function (id, date) {
		var el = g(id);
		if (!el) return false;
		var d = date instanceof Date ? date : (date === undefined || date === null || date === '' ? new Date() : new Date(date));
		if (isNaN(d)) d = new Date();
		var f = el.getAttribute('data-nu-format');
		$(el).val(f ? nuFORM.addFormatting(ymd(d), f) : ymd(d));
		markEdited(el);
		$(el).trigger('change');
		return true;
	});
	window.nuSuffix = window.nuSuffix || 0;
	api('nuSetSuffix', function (a) {
		if (arguments.length === 1) window.nuSuffix = a;
		window.nuSuffix = window.nuSuffix >= 9999 ? 0 : window.nuSuffix + 1;
	});
	api('nuID', function () {
		nuSetSuffix();
		window.nuUniqueID = 'c' + String(Date.now());
		return window.nuUniqueID + String(window.nuSuffix);
	});
	api('nuPasteText', function (id, callback) {
		if (!navigator.clipboard || !navigator.clipboard.readText) return;
		navigator.clipboard.readText().then(function (text) {
			nuSetValue(id, text);
			if (typeof callback === 'function') callback(id, text);
			else if (callback && typeof window[callback] === 'function') window[callback](id, text);
		});
	});

	// ---- string / date helpers nuBuilder adds to String and Date
	function proto(obj, name, fn) { if (!obj.prototype[name]) Object.defineProperty(obj.prototype, name, {value: fn, writable: true, configurable: true}); }
	proto(String, 'nuStartsWith', function (s, ignoreCase) { return ignoreCase ? this.toLowerCase().startsWith(String(s).toLowerCase()) : this.startsWith(s); });
	proto(String, 'nuEndsWith', function (s, ignoreCase) { return ignoreCase ? this.toLowerCase().endsWith(String(s).toLowerCase()) : this.endsWith(s); });
	proto(String, 'nuReplaceAll', function (a, b, ignoreCase) { return this.replace(new RegExp(String(a).replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'g' + (ignoreCase ? 'i' : '')), b); });
	proto(String, 'nuStringToArray', function (sep, trim) { var r = this.split(sep === undefined ? ',' : sep); return trim === false ? r : r.map(function (x) { return x.trim(); }); });
	proto(String, 'nuLeftTrim', function () { return this.replace(/^\s+/, ''); });
	proto(String, 'nuRightTrim', function () { return this.replace(/\s+$/, ''); });
	proto(String, 'nuReplaceNonBreakingSpaces', function (w) { return this.replace(/\xA0/g, w === undefined ? ' ' : w); });
	proto(String, 'nuWithoutNumbers', function () { return this.replace(/[0-9]/g, ''); });
	proto(String, 'nuInsertString', function (i, s) { return i > 0 ? this.substring(0, i) + s + this.substring(i) : s + this; });
	proto(String, 'nuIsEmpty', function () { return this.length === 0; });
	proto(String, 'nuStripBoundaryTags', function (open, close) {
		var r = this.trim();
		if (r.startsWith(open)) r = r.slice(open.length);
		if (r.endsWith(close)) r = r.slice(0, -close.length);
		return r;
	});
	proto(String, 'nuFormat', function () {
		var a = arguments.length === 1 && arguments[0] !== null && typeof arguments[0] === 'object' ? arguments[0] : arguments;
		return this.replace(/{([^}]*)}/g, function (m, k) { return a[k] !== undefined ? a[k] : m; });
	});
	proto(Date, 'nuWithoutTime', function () { var d = new Date(this); d.setHours(0, 0, 0, 0); return d; });
	proto(Date, 'nuAddDays', function (n) { var d = new Date(this.valueOf()); d.setDate(d.getDate() + n); return d; });

	// ---- layout
	// (the owner's decision): 0 = the row with Add / Back / Save …, 1 = nuBuilder Next's top bar (logo, search, user menu),
	// 2 = the tabs of the form. The top bar is only hidden, never removed (menus and the bell live in it): html.nuNoNavbar, --nu-nav-h 0
	var HOLDERS = {0: '#nuActionHolder', 2: '#nuTabHolder, #userTabs'};
	function holderNav(remove) {
		document.documentElement.classList.add('nuNoNavbar');
		document.documentElement.style.setProperty('--nu-nav-h', '0px');   // the bar's measured height is set inline (nuapp.js) and beats the class rule
		window.dispatchEvent(new Event('resize'));          // sticky rows measure again (nuapp.js)
		return remove;
	}
	// in a frame there is no top bar – 1 hides the form's title there, nuBuilder Forte's breadcrumb holder; the list's search row
	// goes with 0 (in Forte it sits in the action holder) – forteHidden() also runs after nuOnLoad
	api('nuHideHolders',
		function () { Array.prototype.forEach.call(arguments,
			function (a) { if (+a === 1) { holderNav(false); if (N.iframe) $('#nuBreadcrumbHolder').addClass('nuHolderHidden'); } else if (HOLDERS[a]) $(HOLDERS[a]).addClass('nuHolderHidden'); }); forteHidden(); });
	api('nuRemoveHolders',
		function () { Array.prototype.forEach.call(arguments,
			function (a) { if (+a === 1) { holderNav(true); if (N.iframe) $('#nuBreadcrumbHolder').addClass('nuHolderHidden'); } else if (HOLDERS[a]) $(HOLDERS[a]).remove(); }); forteHidden(); });
	api('nuSetVerticalTabs', function () { $('#nuEditForm').addClass('nuVerticalTabs'); });
	api('nuPrintEditForm', function (hideObjects, showObjects) {
		hideObjects = arr(hideObjects); showObjects = arr(showObjects);
		document.body.classList.add('nuPrintingEditForm');
		hideObjects.forEach(function (id) { nuHide(id); });
		showObjects.forEach(function (id) { nuShow(id); });
		var restore = function () {
			window.removeEventListener('afterprint', restore);
			document.body.classList.remove('nuPrintingEditForm');
			hideObjects.forEach(function (id) { nuShow(id); });
			showObjects.forEach(function (id) { nuHide(id); });
		};
		window.addEventListener('afterprint', restore);
		window.print();
	});

	// ---- images from nuBuilder Files (zzzzsys_file, by code)
	function fileUrl(code) { return N.base + '?p=file&code=' + encodeURIComponent(String(code)); }
	api('nuAttachImage', function (id, code, fit) {
		var t = g(id);
		if (!t) return;
		var img = document.createElement('img');
		img.id = 'image_' + id;
		img.className = 'nuBrowseImage nuAttachedImage';
		img.alt = '';
		if (fit !== false) img.classList.add('nuImageFit');
		img.src = fileUrl(code);
		t.innerHTML = '';
		t.appendChild(img);
	});
	api('nuAttachButtonImage', function (id, code, cssClass) {
		var t = g(id);
		if (!t) return;
		t.style.backgroundImage = 'url("' + fileUrl(code).replace(/"/g, '%22') + '")';
		t.classList.add(cssClass || 'nuButtonImage');
	});
	api('nuEmbedObject', function (json, containerId, width, height) {
		if (!json) return;
		var d;
		try { d = typeof json === 'string' ? JSON.parse(json) : json; } catch (e) { return; }
		var url = '';
		try { url = atob(d.file); } catch (e) { url = ''; }
		if (url.indexOf('data:') !== 0) url = 'data:' + d.type + ';base64,' + d.file;
		var box = g(containerId);
		if (!box) return;
		width = width === undefined ? 300 : width; height = height === undefined ? 300 : height;
		var el;
		if (/^image\//.test(d.type || '')) { el = document.createElement('img'); el.alt = d.name || ''; }
		else { el = document.createElement('embed'); el.type = d.type || ''; }
		el.src = url;
		if (width !== -1) el.width = width;
		if (height !== -1) el.height = height;
		box.innerHTML = '';
		box.appendChild(el);
	});

	// ---- browse
	// nuBrowseStyleBadge(column[, rules[, options]]) – nuBuilder Forte's badge column; rules (nuBuilder Next only – Forte ignores them):
	//   an object {value: colour | {bg, color, …}} = exact values, or an array of rules (the first that matches wins):
	//   {equals | contains | startsWith | endsWith: text, regex: /…/, test: fn(value, row), default: true, ignoreCase: true,
	//    bg, color, border, class, label, icon, title, badge: false}; bg / color / border '$value' = the cell's own value as colour;
	//   options {ignoreCase}. Only bg → the text colour is chosen for contrast. Text stays text; colours go through element.style.
	function badgeRuleOk(r, v, row, ic) {
		var cmp = function (x) { return ic ? String(x).toLowerCase() : String(x); }, val = cmp(v);
		if (r.default) return true;
		if (r.equals !== undefined && val !== cmp(r.equals)) return false;
		if (r.contains !== undefined && val.indexOf(cmp(r.contains)) < 0) return false;
		if (r.startsWith !== undefined && val.indexOf(cmp(r.startsWith)) !== 0) return false;
		if (r.endsWith !== undefined && (val.length < cmp(r.endsWith).length || val.slice(-cmp(r.endsWith).length) !== cmp(r.endsWith))) return false;
		if (r.regex !== undefined && !(r.regex instanceof RegExp ? r.regex : new RegExp(String(r.regex), ic ? 'i' : '')).test(String(v))) return false;
		if (typeof r.test === 'function' && !r.test(v, row)) return false;
		return r.equals !== undefined || r.contains !== undefined || r.startsWith !== undefined || r.endsWith !== undefined || r.regex !== undefined || typeof r.test === 'function';
	}
	function badgeRules(rules) {
		if (!rules) return [];
		if (Array.isArray(rules)) return rules.filter(function (r) {
			var ok = r && typeof r === 'object';
			if (!ok && N.dev_mode) console.warn('nuBrowseStyleBadge: rule skipped', r);
			return ok;
		});
		if (typeof rules === 'object') return Object.keys(rules).map(function (k) {
			var x = rules[k];
			return $.extend({equals: k}, typeof x === 'string' ? {bg: x} : (x && typeof x === 'object' ? x : {}));
		});
		return [];
	}
	api('nuBrowseStyleBadge', function (column, rules, options) {
		var list = badgeRules(rules), icAll = !!(options && options.ignoreCase);
		nuBrowseLoop(arr(column), function (cell) {
			var txt = $(cell).text().trim();
			if (!txt) return;
			var tr = cell.closest('tr'), row = [];
			if (tr) tr.querySelectorAll('div.nuCell[data-nu-column]').forEach(function (td) { row[+td.getAttribute('data-nu-column')] = td.textContent.trim(); });
			var hit = null;
			for (var i = 0; i < list.length && !hit; i++) {
				try { if (badgeRuleOk(list[i], txt, row, list[i].ignoreCase !== undefined ? !!list[i].ignoreCase : icAll)) hit = list[i]; }
				catch (e) { if (N.dev_mode) console.warn('nuBrowseStyleBadge: rule failed', list[i], e); }
			}
			if (hit && hit.badge === false) return;
			var span = $('<span class="badge rounded-pill nuBrowseBadge"></span>'), el = span[0];
			if (hit && hit.icon) span.append($('<i class="me-1" aria-hidden="true"></i>').addClass('bi ' + (/^bi-/.test(hit.icon) ? hit.icon : 'bi-' + hit.icon)));
			span.append(document.createTextNode(hit && hit.label !== undefined && hit.label !== null ? String(hit.label) : txt));
			if (hit) {
				var col = function (c) { c = c === '$value' ? txt : c; return c !== undefined && c !== null && cssRgb(String(c)) ? String(c) : ''; };
				if (hit.class) { el.classList.remove('nuBrowseBadge'); String(hit.class).split(/\s+/).filter(Boolean).forEach(function (k) { el.classList.add(k); }); }
				var bg = col(hit.bg), fg = col(hit.color), bd = col(hit.border);
				if (bg) { el.style.background = bg; el.classList.remove('nuBrowseBadge'); }
				if (fg) el.style.color = fg;
				else if (bg) { var L = luminance(cssRgb(bg)); el.style.color = 1.05 / (L + 0.05) >= (L + 0.05) / 0.066 ? '#fff' : '#212529'; }   // the better contrast
				if (bd) el.style.border = '1px solid ' + bd;
				if (hit.title) el.title = String(hit.title);
				else if (hit.label !== undefined && String(hit.label) !== txt) el.title = txt;
			}
			if (span.text() !== txt) cell.setAttribute('data-nu-raw', txt);   // the stored value, when a label replaces it (read by dev/tests/compat.js)
			$(cell).empty().append(span);
		});
	});

