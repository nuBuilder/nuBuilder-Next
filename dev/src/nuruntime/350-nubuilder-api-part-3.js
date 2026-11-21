	// =================================================================== nuBuilder API, part 3 (Group 2)
	// ---- refresh select / display objects: the server re-runs their SQL with the current values (main form + subform row)
	function rowValues(prefix) {
		var v = {};
		if (!prefix) return v;
		$('[data-nu-prefix="' + prefix + '"][data-nu-field]').each(function () {
			if (this.type === 'button' || this.tagName === 'DIV' || this.tagName === 'BUTTON') return;
			v[this.getAttribute('data-nu-field')] = rawValue(this);
		});
		return v;
	}
	N.rowValues = rowValues;
	/** Elements to refresh: prefix + id; with another form id and no prefix, that field in every row of the subforms showing that form. */
	function refreshTargets(prefix, id, formId) {
		if (prefix || !formId || formId === N.form.id) { var el = g((prefix || '') + id); return el ? [el] : []; }
		var out = [];
		Object.keys(N.subforms || {}).forEach(function (sf) {
			if (N.subforms[sf].form_id !== formId) return;
			$('#' + sf + ' [data-nu-field="' + id + '"][data-nu-prefix]').each(function () { if (!this.closest('template')) out.push(this); });
		});
		return out;
	}
	function refreshCall(el, id, formId) {
		return call('refresh', {object: id, object_form: formId || N.form.id, values: mainValues(), row: rowValues(el.getAttribute('data-nu-prefix') || '')});
	}
	function refreshSelect(prefix, selectId, formId, removeBlank) {
		formId = formId || N.form.id;
		var els = refreshTargets(prefix, selectId, formId);
		if (!els.length) { callHook('nuSelectObjectRefreshed', formId, (prefix || '') + selectId, -1); return Promise.resolve(-1); }
		return Promise.all(els.map(function (el) {
			return refreshCall(el, selectId, formId).then(function (res) {
				var count = -1;
				if (res && res.ok !== false && res.found) {
					var wasSaved = !N.edited, cur = $(el).val();
					var keep = Array.isArray(cur) ? cur.map(String) : [String(cur === null ? '' : cur)];
					var frag = document.createDocumentFragment();
					var nb = el.hasAttribute('data-nu-noblank'), any = false;   // no blank item: validation No Blanks / No Duplicates
					if (removeBlank !== true && !el.multiple && !nb) frag.appendChild(new Option('', ''));
					(res.options || []).forEach(function (o) {
						var opt = new Option(o[1], o[0]);
						if (keep.indexOf(String(o[0])) >= 0) { opt.selected = true; any = true; }
						frag.appendChild(opt);
					});
					el.innerHTML = '';
					el.appendChild(frag);
					if (nb && !any) el.selectedIndex = -1;
					count = (res.options || []).length;
					if (wasSaved) nuHasNotBeenEdited();
				}
				callHook('nuSelectObjectRefreshed', formId, el.id, count);
				return count;
			});
		})).then(function (r) { return r.length === 1 ? r[0] : r; });
	}
	function refreshDisplay(prefix, displayId, formId) {
		formId = formId || N.form.id;
		return Promise.all(refreshTargets(prefix, displayId, formId).map(function (el) {
			return refreshCall(el, displayId, formId).then(function (res) {
				if (!res || res.ok === false || !res.found) { nuMessage([nuTranslate('Failed to refresh the Display Object:') + ' ' + el.id]); return false; }
				var f = el.getAttribute('data-nu-format');
				var v = res.value === null || res.value === undefined ? '' : String(res.value);
				// as nuBuilder Forte (nuSetValue): the field changes – "edited", and the value is saved when the table has its column
				if ('value' in el) { var nv = f ? nuFORM.addFormatting(v, f) : v; if (el.value !== nv) { el.value = nv; $(el).trigger('change'); } } else el.textContent = v;
				callHook('nuDisplayObjectRefreshed', el.id, formId);
				return true;
			});
		}));
	}
	api('nuRefreshSelectObject', function (selectId, formId, removeBlank) { return refreshSelect('', selectId, formId, removeBlank); });
	api('nuSubformRefreshSelectObject', function (prefix, selectId, formId, removeBlank) { return refreshSelect(prefix, selectId, formId, removeBlank); });
	api('nuRefreshDisplayObject', function (displayId, formId) { return refreshDisplay('', displayId, formId); });
	api('nuSubformRefreshDisplayObject', function (prefix, displayId, formId) { return refreshDisplay(prefix, displayId, formId); });

	// ---- browse: search from code, columns excluded from the search
	// the address of this list – also when code rewrote the address bar the way nuBuilder Forte does (?f=…&r=…&h=…, without p=form)
	function browseUrl() {
		var u = new URL(window.location.href);
		if (!u.searchParams.get('p') && N.form && N.mode === 'browse') {
			['h', 'r'].forEach(function (k) { u.searchParams.delete(k); });
			u.searchParams.set('p', 'form'); u.searchParams.set('f', N.form.id);
		}
		return u;
	}
	function currentNoSearch() {
		var v = browseUrl().searchParams.get('nsc') || '';
		return v === '' ? [] : v.split(',').map(Number).filter(function (n) { return !isNaN(n); });
	}
	function setSearchHidden(name, value) {
		var f = document.querySelector('form.nuBrowseSearch');
		if (!f) return;
		var i = f.querySelector('input[type=hidden][name="' + name + '"]');
		if (value === '' || value === null) { if (i) i.remove(); return; }
		if (!i) { i = document.createElement('input'); i.type = 'hidden'; i.name = name; f.appendChild(i); }
		i.value = value;
	}
	function applyNoSearchMarks(list) {
		$('.nuBrowseTitle').each(function () { this.classList.toggle('nuNoSearch', list.indexOf(+this.getAttribute('data-nu-col')) >= 0); });
	}
	function applyNoSearch(list) {
		list = list.filter(function (v, i, a) { return a.indexOf(v) === i; }).sort(function (a, b) { return a - b; });
		applyNoSearchMarks(list);
		var val = list.join(','), u = browseUrl(), before = u.searchParams.get('nsc') || '';
		setSearchHidden('nsc', val);
		if (val === before) return;
		if (val) u.searchParams.set('nsc', val); else u.searchParams.delete('nsc');
		if ((u.searchParams.get('q') || '') !== '') { u.searchParams.delete('pg'); window.location.replace(u.toString()); }   // the shown result used other columns
		else history.replaceState(null, '', u.toString());
	}
	api('nuSetNoSearchColumns', function (columns) { applyNoSearch(currentNoSearch().concat(arr(columns).map(Number))); });
	api('nuSetSearchColumns', function (columns) {
		columns = arr(columns).map(Number);
		var no = [];
		$('.nuBrowseTitle').each(function () { var i = +this.getAttribute('data-nu-col'); if (columns.indexOf(i) < 0) no.push(i); });
		applyNoSearch(no);
	});
	api('nuSearchColumnsReset', function () { applyNoSearch([]); });
	// advanced search: the panel lives in assets/js/nu/nuadvsearch.js (browse lists only)
	api('nuNextAdvancedSearch', function (conditions, mode) { return typeof window.nuNextAdvSearch === 'function' ? window.nuNextAdvSearch(conditions, mode) : null; });
	api('nuSearchAction', function (search, filter) {
		var field = g('nuSearchField'), u = browseUrl();
		if (arguments.length > 0 && field) field.value = search === undefined || search === null ? '' : search;
		var o = {search: field ? field.value : (arguments.length > 0 ? String(search) : (u.searchParams.get('q') || '')),
			filter: arguments.length === 2 ? String(filter === null || filter === undefined ? '' : filter) : (u.searchParams.get('rf') || '')};
		var ok = true;
		['nuOnSearchActionGlobal', 'nuOnSearchAction'].forEach(function (h) { if (ok && typeof window[h] === 'function' && callHook(h, o) === false) ok = false; });
		if (!ok) return;
		if (o.search) u.searchParams.set('q', o.search); else u.searchParams.delete('q');
		if (o.filter) u.searchParams.set('rf', o.filter); else u.searchParams.delete('rf');
		u.searchParams.delete('pg');
		// properties set just before (nuSetProperty + nuSearchAction, as filter buttons / selects of nuBuilder Forte do) must reach the
		// browse SQL as hash variables: store them first and pass the new scope (was: the scope of the page as loaded)
		withScope(function (key) {
			if (key) u.searchParams.set('ps', key); else u.searchParams.delete('ps');
			browseNav(u.toString());
		});
	});
	$(document).on('submit', 'form.nuBrowseSearch', function (e) {   // the search field: nuOnSearchAction hooks may change or cancel it
		if (typeof window.nuOnSearchActionGlobal !== 'function' && typeof window.nuOnSearchAction !== 'function') {
			if (e.isDefaultPrevented() || !smoothOk()) return;                 // in place: the form's fields as a URL
			var qs = new URLSearchParams();
			new FormData(this).forEach(function (v, k) { if (typeof v === 'string') qs.append(k, v); });
			var to = new URL(this.getAttribute('action') || 'index.php', window.location.href);
			to.search = qs.toString();
			if (browseGo(to.toString(), true)) e.preventDefault();
			return;
		}
		e.preventDefault();
		nuSearchAction(g('nuSearchField') ? g('nuSearchField').value : '');
	});

	// ---- browse lists refreshed in place: search, column filters, sort titles, pages and the page size fetch the list and swap
	// only its card (no page reload, no flicker). Automatic for forms without code of their own (server flag N.browse.smooth: no form /
	// browse JavaScript, no Setup → Header or includes) and without load / search / row hooks; nuNextSmoothBrowse(true) in form code turns
	// it on anyway (the form's nuLoadBrowseGlobal / nuOnLoad then run again after each swap), nuNextSmoothBrowse(false) off. Anything
	// unusual (another form, a record, an error, a changed toolbar) loads the page the normal way.
	var SM = {on: null, ctrl: null};
	api('nuNextSmoothBrowse', function (on) { SM.on = on === undefined ? true : !!on; });
	function smoothOk() {
		if (N.mode !== 'browse' || !N.browse || !window.fetch || !window.DOMParser || document.body.classList.contains('nuPrintView')) return false;
		if (SM.on !== null) return SM.on;
		if (!N.browse.smooth) return false;
		return ['nuOnLoad', 'nuLoadBrowseGlobal', 'nuSelectBrowse', 'nuOnSearchAction', 'nuOnSearchActionGlobal', 'nuBrowseFunction'].every(function (h) {
			if (typeof window[h] !== 'function') return true;
			var src = '';
			try { src = Function.prototype.toString.call(window[h]); } catch (e) { return false; }
			src = src.replace(/\/\*[\s\S]*?\*\//g, '').replace(/\/\/[^\n]*/g, '');   // an empty hook (nuBuilder Forte's default Setup → Header) does nothing
			return /^\s*function\s*[\w$]*\s*\([^)]*\)\s*\{\s*\}\s*$/.test(src);
		});
	}
	function browseKey(href) {
		try {
			var u = new URL(href, window.location.href), q = u.searchParams;
			if (u.origin !== window.location.origin || q.get('p') !== 'form' || q.get('r') || q.get('print') || q.get('export') || q.get('fmt')) return '';
			return u.pathname + '|' + q.get('f') + '|' + (q.get('lookup') || '') + '|' + (q.get('lo') || '') + '|' + (q.get('iframe') || '') + '|' + (q.get('design') || '');
		} catch (e) { return ''; }
	}
	function sameBrowse(href) { var k = browseKey(href); return k !== '' && k === browseKey(window.location.href); }
	function browseNav(url) { if (!browseGo(url, true)) window.location.href = url; }
	function browseGo(url, push) {
		if (!smoothOk() || !sameBrowse(url)) return false;
		var old = document.querySelector('.nuBrowseCard');
		if (!old) return false;
		if (SM.ctrl) SM.ctrl.abort();
		SM.ctrl = window.AbortController ? new AbortController() : null;
		var act = document.activeElement, focusId = act && act.id && old.contains(act) ? act.id : '', caret = null;
		try { if (act && typeof act.selectionStart === 'number') caret = act.selectionStart; } catch (e) { /* not a text field */ }
		var slow = setTimeout(function () { old.classList.add('nuLvLoading'); old.setAttribute('aria-busy', 'true'); }, 150);
		fetch(url, {credentials: 'same-origin', signal: SM.ctrl ? SM.ctrl.signal : undefined}).then(function (r) {
			if (!r.ok || (r.redirected && !sameBrowse(r.url))) throw new Error('fallback');
			return r.text().then(function (html) { return [html, r.url || url]; });
		}).then(function (res) {
			clearTimeout(slow);
			var m = /window\.nuNext = (\{[\s\S]*?\});<\/script>/.exec(res[0]);
			var doc = new DOMParser().parseFromString(res[0], 'text/html');
			var fresh = doc.querySelector('.nuBrowseCard'), cur = document.querySelector('.nuBrowseCard');
			if (!m || !fresh || !cur) throw new Error('fallback');
			var data = JSON.parse(m[1]);
			if (!data.form || data.form.id !== N.form.id || data.mode !== 'browse' || !data.browse) throw new Error('fallback');
			// the toolbar (export / print only with records …): swapped as well, a different set of buttons = a normal load
			var ids = ['nuExportButton', 'nuPrintButton'];
			if (ids.some(function (id) { return !document.getElementById(id) !== !doc.getElementById(id); })) throw new Error('fallback');
			ids.forEach(function (id) { var a = document.getElementById(id), b = doc.getElementById(id); if (a && b) a.replaceWith(document.importNode(b, true)); });
			document.querySelectorAll('body > .tooltip').forEach(function (t) { t.remove(); });
			var node = document.importNode(fresh, true);
			cur.replaceWith(node);
			N.browse = data.browse;
			N.adv = data.adv;
			if (data.ps) N.ps = data.ps;                           // the new page's scope of hash variables (nuSetProperty …)
			if (data.globals) N.globals = data.globals;
			if (data.props) N.props = data.props;
			if (push !== false) history.pushState({nuBr: 1}, '', res[1]);
			if (doc.title) document.title = doc.title;
			browseAfterSwap(node);
			if (focusId) {
				var f = document.getElementById(focusId);
				if (f) { f.focus({preventScroll: true}); try { if (caret !== null) f.setSelectionRange(caret, caret); } catch (e) { /* select */ } }
			}
			if (node.getBoundingClientRect().top < 0) node.scrollIntoView({block: 'start'});
			document.documentElement.setAttribute('data-nu-lv-swaps', String((+document.documentElement.getAttribute('data-nu-lv-swaps') || 0) + 1));   // for the tests
		}).catch(function (e) {
			clearTimeout(slow);
			if (e && e.name === 'AbortError') return;
			window.location.href = url;
		});
		return true;
	}
	function browseAfterSwap(root) {
		if (window.nuBs && window.nuBs.ready()) root.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function (el) { window.nuBs.tooltip(el); });
		[['nuOpenMin', root], ['nuBindConfirm', root], ['nuBindPagerJump', root], ['nuAdvSearchInit']].forEach(function (f) {
			if (typeof window[f[0]] === 'function') { try { window[f[0]](f[1]); } catch (e) { /* one helper must not stop the others */ } }
		});
		applyNoSearchMarks(currentNoSearch());
		initSelects(root);
		colFilterSelects(root);
		if (typeof window.nuHighlightSearch === 'function') { try { window.nuHighlightSearch(); } catch (x) { reportError(x.message, 'nuHighlightSearch'); } }
		if (SM.on === true) callHook('nuLoadBrowseGlobal', N.form.id, N.form.code);
		if (N.browse && N.browse.badges) Object.keys(N.browse.badges).forEach(function (i) {
			try { window.nuBrowseStyleBadge(+i, N.browse.badges[i] && N.browse.badges[i].length ? N.browse.badges[i] : undefined); } catch (e) { /* see the load */ }
		});
		if (SM.on === true) callHook('nuOnLoad', N.form.id, N.form.code);
		BR.ctl = null;
		brRestore();
		if (typeof window.nuAttachToolTip === 'function') { try { window.nuAttachToolTip(); } catch (x) { reportError(x.message, 'nuAttachToolTip'); } }
		phoneStack();
		document.dispatchEvent(new CustomEvent('nu:listrefresh', {detail: {card: root}}));
	}
	// long filter lists (review B2): searchable (Tom Select) from 15 values on
	function colFilterSelects(root) {
		if (window.TomSelect) $(root).find('select.nuColumnFilter').each(function () {
			if (this.options.length < 16 || this.tomselect) return;
			try { new window.TomSelect(this,
				{maxOptions: null, allowEmptyOption: true, controlInput: '<input>',
					render: {no_results: function () { return '<div class="no-results">' + esc(M.no_results || '–') + '</div>'; }}}); } catch (x) { /* keep the plain select */ }
		});
	}
	if (N.mode === 'browse') {
		window.nuListGo = function (url) { return browseGo(url, true); };   // the page jump of the footer (assets/js/nuapp.js)
		try { history.replaceState(Object.assign({}, history.state || {}, {nuBr: 1}), ''); } catch (e) { /* ignore */ }
		window.addEventListener('popstate', function (e) {
			if (!e.state || !e.state.nuBr) return;
			if (!browseGo(window.location.href, false)) window.location.reload();
		});
		document.addEventListener('click', function (e) {
			if (e.defaultPrevented || e.button !== 0 || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) return;
			var a = e.target.closest && e.target.closest('.nuBrowseCard a[href]');
			if (!a || a.target || a.hasAttribute('download') || a.getAttribute('href').charAt(0) === '#') return;
			if (browseGo(a.href, true)) e.preventDefault();
		});
	}

	// ---- Run::iFrame objects (same origin: the embedded nuBuilder Next form is reachable directly)
	function frameDoc(id) { var f = g(id); try { return f && f.contentWindow ? f.contentWindow.document : null; } catch (e) { return null; } }
	api('nuGetIframeValue', function (iframeId, elementId, method) {
		var d = frameDoc(iframeId), el = d && d.getElementById(elementId);
		if (!el) return null;
		return method === 'html' ? el.innerHTML : $(el).val();
	});
	api('nuSetIframeValue', function (iframeId, elementId, value, method) {
		var f = g(iframeId), d = frameDoc(iframeId), el = d && d.getElementById(elementId);
		if (!el) return false;
		if (method === 'html') el.innerHTML = value;
		else if (f.contentWindow.nuSetValue) f.contentWindow.nuSetValue(elementId, value);
		else $(el).val(value);
		return true;
	});
	api('nuGetIframeProperty', function (iframeId, property) {
		var f = g(iframeId);
		try { return f && f.contentWindow && f.contentWindow.nuGetProperty ? (f.contentWindow.nuGetProperty(property) ?? null) : null; } catch (e) { return null; }
	});
	api('nuSetIframeProperty', function (iframeId, property, value) {
		var f = g(iframeId);
		try { if (f && f.contentWindow && f.contentWindow.nuSetProperty) f.contentWindow.nuSetProperty(property, value); } catch (e) { /* ignore */ }
	});
	function rerun(id, param, value) {
		var el = g(id);
		if (!el) return;
		if (el.tagName !== 'IFRAME') {   // a run button: the next click uses the new value
			el.setAttribute(param === 'rf' ? 'data-nu-run-filter' : 'data-nu-run-record', value === undefined ? '' : value);
			return;
		}
		var u = new URL(el.getAttribute('src'), window.location.href);
		if (value !== undefined && value !== null && (u.searchParams.get(param) || '') === String(value)) return;
		if (value !== undefined && value !== null) { if (value === '') u.searchParams.delete(param); else u.searchParams.set(param, value); }
		if (param === 'r' && value === '') u.searchParams.delete('r');
		withScope(function (key) {
			u.searchParams.set('ps', key);
			el.setAttribute('src', u.pathname + u.search);
		});
	}
	api('nuFilterRun', function (id, filter) { rerun(id, 'rf', filter); });
	api('nuRecordRun', function (id, recordId) { rerun(id, 'r', recordId === undefined ? undefined : String(recordId)); });

	// ---- subform filters: nuSubformAddFilter({sfName: ['col', …] | {col: {type: 'select' | 'search', all, blank, placeholder, width, float}}})
	function cellText(el) {
		if (!el) return '';
		if (el.type === 'checkbox') return el.checked ? '1' : '0';
		if (el.tagName === 'SELECT') return $(el).find('option:selected').map(function () { return this.text; }).get().join(', ');
		var c = g(el.id + 'code');                   // lookups: the code, as nuBuilder Forte filters (nuSubformFilterCellValue)
		if (c) return String(c.value || '');
		return 'value' in el ? String(el.value) : $(el).text();
	}
	function sfRows(sf) { return $('#' + sf + ' .nuSubformRow').filter(function () { return !this.closest('template'); }); }
	function sfFilterRows(sf, cols) {
		sfRows(sf).each(function () {
			var row = this, prefix = row.id;
			if (row.classList.contains('nuNewRow')) { row.classList.remove('nuFilteredOut'); return; }
			var rowNo = parseInt(String(prefix).slice(sf.length), 10), all = sfRows(sf).length, hookHide = null;
			var show = Object.keys(cols).every(function (col) {
				var f = g(sf + col + '_filter');
				if (!f) return true;
				var txt = cellText(g(prefix + col)), match, isAll = false, blank = false, fv;
				if (f.tagName === 'SELECT') {
					var opt = f.options[f.selectedIndex];
					isAll = !opt || opt.getAttribute('data-nu-all') === 'true';
					fv = opt ? opt.text : '';
					match = isAll || txt.toLowerCase() === fv.toLowerCase();
				} else {
					fv = f.value; blank = fv === '';
					match = blank || txt.toLowerCase().indexOf(fv.toLowerCase()) >= 0;
				}
				// nuBuilder's hook decides per column (the last column's answer counts): true = hide the row
				if (typeof window.nuSubformOnFilterRows === 'function') {
					try { hookHide = !!window.nuSubformOnFilterRows(sf,
						{val: txt, filter: fv, type: f.tagName === 'SELECT' ? 'select' : 'search', optionAll: isAll, optionBlank: blank, isMatch: match}, rowNo, all); }
					catch (x) { reportError(x.message, 'nuSubformOnFilterRows'); }
					return true;
				}
				return match;
			});
			if (hookHide !== null) show = !hookHide;
			row.classList.toggle('nuFilteredOut', !show);
		});
	}
	function sfDistinct(sf, col) {
		var seen = {}, out = [];
		sfRows(sf).each(function () {
			var prefix = this.id;
			if (this.classList.contains('nuNewRow')) return;
			var t = cellText(g(prefix + col));
			if (t !== '' && !seen[t]) { seen[t] = 1; out.push(t); }
		});
		return out.sort(function (a, b) { return a.localeCompare(b); });
	}
	// nuBuilder's helpers inside nuSubformAddFilter, reachable from form code
	api('nuSubformFilterArray', function (sf, columns) {
		var out = {}, list = Array.isArray(columns) ? columns : Object.keys(columns || {});
		list.forEach(function (col) {
			var f = g(sf + col + '_filter'), type = Array.isArray(columns) ? 'select' : ((columns[col] || {}).type || 'select');
			var opt = f && f.tagName === 'SELECT' ? f.options[f.selectedIndex] : null;
			out[col] = {type: type, value: f ? (opt ? opt.text : f.value) : '', all: !!(opt && opt.getAttribute('data-nu-all') === 'true')};
		});
		return out;
	});
	api('nuSubformFilterAddObject', function (type, sf, col, filterId, prop) {
		var def = {}; def[sf] = {}; def[sf][col] = $.extend({}, prop || {}, {type: type || 'select'});
		nuSubformAddFilter(def);
		return $('#' + (filterId || sf + col + '_filter'));
	});
	api('nuSubformAddFilter', function (filter) {
		Object.keys(filter || {}).forEach(function (sf) {
			var def = filter[sf], cols = {};
			if (Array.isArray(def)) def.forEach(function (c) { cols[c] = {type: 'select'}; }); else cols = def || {};
			Object.keys(cols).forEach(function (col) {
				var prop = cols[col] || {}, type = prop.type || 'select', id = sf + col + '_filter';
				if (g(id)) return;
				var el;
				if (type === 'search') {
					el = $('<input type="search" class="form-control form-control-sm nuSubformFilter">').attr('id', id);
					if (prop.datalist !== false) {
						var dl = $('<datalist>').attr('id', id + '_list');
						el.attr('list', id + '_list').on('focus', function () {
							dl.empty(); sfDistinct(sf, col).forEach(function (t) { dl.append($('<option>').attr('value', t)); });
							if (typeof window.nuSubformFilterOnAddValues === 'function') { try { window.nuSubformFilterOnAddValues(sf, id); } catch (x) { reportError(x.message, 'nuSubformFilterOnAddValues'); } }
						});
						el = el.add(dl);
					}
					el.filter('input').on('input', function () { sfFilterRows(sf, cols); });
				} else {
					el = $('<select class="form-select form-select-sm nuSubformFilter"></select>').attr('id', id);
					var all = prop.all === undefined ? '(' + nuTranslate('All') + ')' : prop.all;
					var fill = function () {
						var cur = el.val();
						el.find('option:not([data-nu-persistent])').remove();
						sfDistinct(sf, col).forEach(function (t) {
							var r = {add: true, text: t, value: t};
							if (typeof window.nuSubformFilterOnAddValue === 'function') {   // nuBuilder: may change or skip an option
								try { r = window.nuSubformFilterOnAddValue(sf, true, t, t) || r; } catch (x) { reportError(x.message, 'nuSubformFilterOnAddValue'); }
							}
							if (r.add !== false) el.append($('<option>').text(r.text).attr('value', r.value));
						});
						if (typeof window.nuSubformFilterOnAddValues === 'function') { try { window.nuSubformFilterOnAddValues(sf, id); } catch (x) { reportError(x.message, 'nuSubformFilterOnAddValues'); } }
						if (cur !== null) el.val(cur);
					};
					if (all !== false) el.append($('<option value="" data-nu-persistent="true" data-nu-all="true" selected>').text(all));
					if (prop.blank !== false) el.append($('<option value="" data-nu-persistent="true">'));
					el.on('focus mousedown', fill).on('change', function () { sfFilterRows(sf, cols); });
				}
				if (prop.placeholder) el.filter('input').attr('placeholder', nuTranslate(prop.placeholder));
				if (prop.width) el.css('width', prop.width);
				var title = g('title_' + sf + col);
				if (title) $(title).append(el);
				else {                                       // form-layout subform: a filter bar above the rows
					var bar = $('#' + sf + ' > .nuSubformFilterBar');
					if (!bar.length) bar = $('<div class="nuSubformFilterBar d-flex flex-wrap gap-2 mb-2"></div>').prependTo('#' + sf);
					var lbl = $('label[for$="' + col + '"]').first().clone().find('.nuMarks').remove().end().text().trim() || col;
					bar.append($('<div class="nuSubformFilterItem"></div>').append($('<div class="small text-body-secondary"></div>').text(lbl)).append(el));
				}
			});
		});
	});

	// ---- labels above their objects (absolute layout; auto and grid layouts have them there already)
	api('nuLabelOnTop', function (include, exclude, offsetTop, offsetLeft) {
		offsetTop = offsetTop === undefined ? -18 : offsetTop;
		offsetLeft = offsetLeft || 0;
		include = include || Object.keys(N.objects || {});
		exclude = exclude || [];
		arr(include).forEach(function (id) {
			if (exclude.indexOf(id) >= 0) return;
			var el = g(id), lab = g('label_' + id);
			if (!el || !lab) return;
			var box = el.closest('.nuAbs') || el, lbox = lab.closest('.nuAbs') || lab;
			if (box === lbox) {                          // label and object in one positioned box: move the label above the object
				lab.classList.remove('nuLabelLeft');
				lab.classList.add('nuLabelTop');
				if (offsetTop !== -18) { lab.style.bottom = 'auto'; lab.style.top = offsetTop + 'px'; }
				if (offsetLeft) lab.style.left = offsetLeft + 'px';
				lab.style.textAlign = 'left';
			} else if (box.style.top !== '') {
				lbox.style.top = (parseFloat(box.style.top) + offsetTop) + 'px';
				lbox.style.left = (parseFloat(box.style.left) + offsetLeft) + 'px';
				lab.style.textAlign = 'left';
			}
			el.setAttribute('data-nu-label-position', 'top');
		});
	});
	if (!$.fn.nuLabelOnTop) $.fn.nuLabelOnTop = function (offsetTop, offsetLeft) { nuLabelOnTop(this.map(function () { return this.id; }).get(), [], offsetTop, offsetLeft); return this; };

	// ---- hooks: tabs and breadcrumbs (nuOnSelectTab may cancel; nuOnTabSelected after the switch)
	$(document).on('show.bs.tab', '.nuTab', function (e) {
		if (window.nuOnSelectTab) {
			try { if (window.nuOnSelectTab(e.target) === false) e.preventDefault(); } catch (x) { reportError(x.message, 'nuOnSelectTab'); }
		}
	});
	// nuBuilder Forte marks the selected tab with nuTabSelected – form code reads $('.nuTabSelected')[0].id (vertical tab menus …)
	$(document).on('shown.bs.tab', '.nuTab', function (e) {
		$('.nuTab.nuTabSelected').not(e.target).removeClass('nuTabSelected');
		e.target.classList.add('nuTabSelected');
	});
	$(document).on('shown.bs.tab', '.nuTab', function (e) {
		if (N.autoTab) { N.autoTab = false; return; }   // chosen by the page (Notes), not by the user: not remembered
		nuStore.session.set(tabKey(), e.target.getAttribute('data-nu-tab'));
		['nuOnTabSelectedGlobal', 'nuOnTabSelected'].forEach(function (fn) {
			if (typeof window[fn] === 'function') { try { window[fn](e.target); } catch (x) { reportError(x.message, fn); } }
		});
	});
	// the Back button (#nuBackButton) goes to a breadcrumb too: the same hook may stop it
	$(document).on('click', 'a.nuBreadcrumb, a#nuBackButton', function (e) {
		if (typeof window.nuOnBeforeGetBreadcrumb !== 'function') return;
		var n = this.id === 'nuBackButton' ? parseInt(this.getAttribute('data-nu-crumb'), 10) : parseInt(String(this.id).replace('nuBreadcrumb', ''), 10);
		try { if (window.nuOnBeforeGetBreadcrumb(n) === false) e.preventDefault(); } catch (x) { reportError(x.message, 'nuOnBeforeGetBreadcrumb'); }
	});
	function callHook(name) {
		if (typeof window[name] !== 'function') return undefined;
		try { return window[name].apply(window, Array.prototype.slice.call(arguments, 1)); }
		catch (x) { reportError(x.message, name); console.error(x); }
		return undefined;
	}
	N.callHook = callHook;

