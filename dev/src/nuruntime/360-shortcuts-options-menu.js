	// =================================================================== keyboard shortcuts + options menu (nuBuilder: Ctrl+Shift+key)
	// Only what exists in nuBuilder Next and the user may use (N.tools from the server, buttons that are on the page).
	function openTab(url) { window.open(url, '_blank', 'noopener'); }
	function builderUrl(open) { return N.base + '?p=builder&f=' + encodeURIComponent(N.form.id) + (open ? '&open=' + open : ''); }
	function goPage(p) {
		var b = N.browse || {}, u = new URL(window.location.href);
		p = Math.max(1, Math.min(b.pages || 1, p));
		if (p === (b.page || 1)) return;
		u.searchParams.set('pg', p);
		browseNav(u.toString());
	}
	function nextTab(step) {
		var tabs = $('.nuTab:visible').get(), cur = tabs.indexOf(selectedTab());
		if (!tabs.length) return;
		var t = tabs[(cur + step + tabs.length) % tabs.length];
		nuSelectTab(+t.getAttribute('data-nu-tab'));
	}
	function showFormInfo() {
		var panel = g('nuFormInfoBtn');                       // the (i) panel next to Delete – Ctrl+Shift+M opens it
		if (panel && window.nuBs) { nuBs.dropdown(panel).show(); return; }
		var S = N.shortcuts || {}, rows = [[S.info_form, N.form.description], [S.info_code, N.form.code], ['ID', N.form.id], [S.info_type, N.form.type],
			[S.info_table, N.form.table], [S.info_pk, N.form.primary_key], [S.info_layout, N.form.layout]];
		if (N.mode === 'edit') rows.push([S.info_record, N.record_id]);
		nuMessage(S.form_info || 'Form info', rows.filter(function (r) { return r[1]; }).map(function (r) { return '<b>' + esc(r[0]) + ':</b> ' + esc(r[1]); }));
	}
	function logoutAsk() {
		if (!window.confirm((N.shortcuts || {}).logout_confirm || 'Log out?')) return;
		var f = document.createElement('form');
		f.method = 'post'; f.action = N.base + '?p=logout';
		var i = document.createElement('input'); i.type = 'hidden'; i.name = '_csrf'; i.value = N.csrf; f.appendChild(i);
		document.body.appendChild(f); f.submit();
	}
	function shortcutActions() {
		var T = N.tools || {}, ga = !!N.user.globeadmin, edit = N.mode === 'edit', browse = N.mode === 'browse';
		var has = function (id) { return !!g(id); };
		// [key, label key, icon, group, available, action] – group 0: a keyboard shortcut only, not in the options menu (add, print,
		// search, form info, form properties and object list have buttons of their own on the page; arrange objects = the form
		// builder button; save / delete / clone = the record buttons; log out = the user menu; refresh, database, logged-in users,
		// File Manager – their keys stay)
		return [
			['a', 'arrange', 'bi-arrows-move', 0, edit && T.design, function () { openTab(builderUrl('arrange')); }],
			['h', 'add_object', 'bi-plus-lg', 1, edit && T.design, function () { openTab(builderUrl('add')); }],
			// design mode → Code → Browse SQL; Ctrl+Shift+B, the pane of the list where there is one
			['b', 'browse_sql', 'bi-filetype-sql', 0, (browse && (!!T.browsesql || !!N.browseSqlOpen)) || (edit && !!N.codePaneOpen),
				function () { if (N.codePaneOpen
					&& !browse) N.codePaneOpen(); else if (N.browseSqlOpen) N.browseSqlOpen(); else window.location.href = T.browsesql; }],   // the code pane on edit forms   // shortcut only – the entry is in Design ▾
			// the form's table in phpMyAdmin / Adminer (Ctrl+Shift+E stays the same without a menu entry of its own)
			['', 'db_table', 'bi-table', 1, T.pma && !!N.form.table, function () { nuVendorLogin('DB', N.form.table); }],
			['', 'recycle_bin', 'bi-trash3', 1, !!T.recycle, function () { window.location.href = T.recycle; }],   // deleted records of this form
			['', 'form_access', 'bi-people', 1, !!T.formaccess && typeof window.nuFormAccessOpen === 'function', function () { window.nuFormAccessOpen(N.form.id); }],
			['', 'code_search', 'bi-search', 1, !!T.codesearch, function () { openTab(T.codesearch); }],   // the code search with this form as filter
			['f', 'form_properties', 'bi-gear', 0, T.design, function () { openTab(builderUrl('form')); }],
			['o', 'object_list', 'bi-list-ul', 0, T.design, function () { openTab(builderUrl('objects')); }],
			['s', 'save', 'bi-floppy', 0, edit && has('nuSaveButton'), function () { if (document.activeElement) $(document.activeElement).trigger('blur'); nuSaveAction(); }],
			['y', 'delete', 'bi-trash', 0, edit && has('nuDeleteButton'), function () { nuDeleteAction(); }],
			['c', 'clone', 'bi-copy', 0, edit && has('nuCloneButton'), function () { nuCloneAction(); }],
			['s', 'search', 'bi-search', 0, browse && has('nuSearchField'), function () { nuSearchAction(); }],
			['a', 'add', 'bi-plus-lg', 0, browse && has('nuAddButton'), function () { $('#nuAddButton').trigger('click'); }],
			['p', 'print', 'bi-printer', 0, browse && has('nuPrintButton'),
				function () { var h = $('#nuPrintButton').attr('data-print-url') || $('#nuPrintButton').attr('href'); go(function () { return printUrl(h); }, 'blank'); }],
			['?', 'help', 'bi-book', 3, true, function () { openTab(N.base + '?p=help#forms-use'); }],
			['r', 'refresh', 'bi-arrow-repeat', 0, true, function () { window.location.reload(); }],
			['d', 'debug', 'bi-bug', 4, T.debug, function () { openTab(N.base + '?p=debug'); }],
			['e', 'database', 'bi-database', 0, T.pma, function () { nuVendorLogin('DB', N.form.table || ''); }],   // the primary database tool
			['j', 'sessions', 'bi-key', 0, T.sessions, function () { openTab(N.base + '?p=sessions'); }],
			['', 'file_manager', 'bi-folder2-open', 0, T.tfm, function () { nuVendorLogin('TFM'); }],   // no key: Ctrl+Shift+Q is Change password (nuBuilder Forte; review D8 – it meant both)
			['q', 'password', 'bi-key', 5, edit && T.password, function () { openTab(N.base + '?p=password'); }],
			['m', 'form_info', 'bi-info-circle', 0, ga, showFormInfo],
			['x', 'properties', 'bi-braces', 5, ga, function () { showHash(); }],
			// all columns of the open record with type and stored value (new tab); no shortcut – Ctrl+Shift+I opens the browser's tools
			['', 'inspect', 'bi-table', 5, ga && edit && N.record_id && N.record_id !== '-1' && !!N.form.table, function () {
				openTab(N.base + '?p=inspect&f=' + encodeURIComponent(N.form.id) + '&r=' + encodeURIComponent(N.record_id));
			}],
			['l', 'logout', 'bi-box-arrow-right', 0, !N.iframe, logoutAsk],   // Log out is in the user menu of the top bar
			// not in the menu (navigation keys, like nuBuilder)
			['arrowright', 'tab_next', '', 0, edit && $('.nuTab').length > 1, function () { nextTab(1); }],
			['arrowleft', 'tab_prev', '', 0, edit && $('.nuTab').length > 1, function () { nextTab(-1); }],
			['arrowright', 'page_next', '', 0, browse, function () { goPage((N.browse.page || 1) + 1); }],
			['arrowleft', 'page_prev', '', 0, browse, function () { goPage((N.browse.page || 1) - 1); }],
			['home', 'page_first', '', 0, browse, function () { goPage(1); }],
			['end', 'page_last', '', 0, browse, function () { goPage(N.browse.pages || 1); }]
		].filter(function (a) { return a[4]; });
	}
	// Mac: Control+Shift (⌃⇧) is shown – it never collides with macOS or the browser; Cmd+Shift works too where the browser lets it through
	var IS_MAC = /Mac|iPhone|iPad/i.test([navigator.userAgentData && navigator.userAgentData.platform, navigator.platform, navigator.userAgent].join(' '));
	// touch device without mouse / keyboard (phone, tablet): no Ctrl key – the options menu shows no shortcuts
	var TOUCH_ONLY = !!(window.matchMedia && window.matchMedia('(hover: none) and (pointer: coarse)').matches);
	function keyLabel(k) { var n = k.length === 1 ? k.toUpperCase() : k.charAt(0).toUpperCase() + k.slice(1); return IS_MAC ? '⌃⇧' + n : 'Ctrl+Shift+' + n; }
	document.addEventListener('keydown', function (e) {
		if (!(e.ctrlKey || e.metaKey) || !e.shiftKey || e.altKey) return;
		if ($('.modal.show').length) return;                 // code editor, messages: their own keys
		var key = (e.key || '').toLowerCase();
		if (e.key === '?' || (e.code === 'Slash' && !/^[a-z0-9]$/.test(key))) key = '?';   // ? on every keyboard layout
		if (N.mode === 'browse' && /^Digit[1-9]$/.test(e.code || '') && g('nuSearchField')) {   // Ctrl+Shift+1…9: column in / out of the search
			e.preventDefault();
			var col = Number(e.code.slice(5)) - 1, list = currentNoSearch(), at = list.indexOf(col);
			if (at >= 0) list.splice(at, 1); else list.push(col);
			applyNoSearch(list);
			return;
		}
		var act = shortcutActions().filter(function (a) { return a[0] === key; })[0];
		if (!act) return;
		e.preventDefault();
		act[5]();
	}, true);
	/** Hash viewer (globeadmin): every #variable# of this form with its value and where it comes from. */
	function showHash() {
		withScope(function () {
			call('hash', {values: N.mode === 'edit' ? mainValues() : {}}).then(function (res) {
				if (!res || !res.ok) { nuDisplayError(res); return; }
				var src = M.hash_src || {}, locked = res.locked || [];
				var rows = res.hash.map(function (h) {
					return '<tr data-hash-row="' + esc(h.name) + '"><td><code>#' + esc(h.name) + '#</code></td><td class="text-break">' + esc(h.value) + '</td><td><span class="badge nuHashSrc nuHashSrc-' + esc(h.source) + '">' + esc(src[h.source]
						|| h.source) + '</span>'
						+ (locked.indexOf(h.name) >= 0 ? ' <i class="bi bi-lock" title="' + esc(M.hash_locked || 'set in PHP') + '"></i>' : '') + '</td></tr>';
				}).join('');
				nuMessage(N.shortcuts.properties || 'Hash', ['<input type="search" class="form-control form-control-sm mb-2" id="nuHashFilter" placeholder="' + esc(M.hash_filter || 'Filter') + '">'
					+ '<div class="nuHashTable"><table class="table table-sm small mb-0" id="nuHashTable"><thead><tr><th>' + esc(M.hash_name
						|| 'Name') + '</th><th>' + esc(M.hash_value || 'Value') + '</th><th>' + esc(M.hash_source
						|| 'Source') + '</th></tr></thead><tbody>' + rows + '</tbody></table></div>']);
				$('#nuHashFilter').on('input', function () {
					var q = this.value.toLowerCase();
					$('#nuHashTable tbody tr').each(function () { this.hidden = q !== '' && this.textContent.toLowerCase().indexOf(q) < 0; });
				}).trigger('focus');
			});
		});
	}
	N.showHash = showHash;

	// the list inside the lookup dialog: the buttons of the title row go into the search row (one title – the dialog's),
	// the record already chosen gets a ✓, a foot with Clear + the keys, Esc clears the search / closes, the dialog fits the list
	function lookupFrame() {
		var P = window.parent;
		if (!P || P === window) return;
		var fit = function () { try { if (P.nuNextLookupFit) P.nuNextLookupFit(); } catch (x) { /* ignore */ } };
		var cur = '';
		try { cur = P.nuNextLookupCurrent ? P.nuNextLookupCurrent() : ''; } catch (x) { /* ignore */ }
		var place = function () {
			var row = document.querySelector('.nuBrowseSearch .nuListSearch'), ah = g('nuActionHolder');
			if (row && ah && ah.parentNode !== row) { ah.classList.add('nuLookupActs'); row.appendChild(ah); }
			Array.prototype.forEach.call(document.querySelectorAll('#nuBrowseTable tr.nuBrowseRow'), function (tr) {
				var on = cur !== '' && tr.getAttribute('data-nu-primary-key') === cur;
				tr.classList.toggle('nuLookupCur', on);
				if (on) tr.setAttribute('aria-current', 'true'); else tr.removeAttribute('aria-current');
				var td = on && !tr.querySelector('.nuLookupCurMark') ? Array.prototype.find.call(tr.querySelectorAll('td.nuCellTd'), function (c) { return c.style.display !== 'none'; }) : null;
				if (td) (td.querySelector('.nuCell') || td).insertAdjacentHTML('afterbegin', '<i class="bi bi-check-lg nuLookupCurMark" aria-hidden="true"></i>');
			});
			var card = document.querySelector('.nuBrowseCard');
			if (card && !g('nuLookupFoot')) {
				var foot = $('<div class="nuLookupFoot" id="nuLookupFoot"></div>');
				if (cur !== '') {
					$('<button type="button" class="btn btn-sm btn-outline-secondary" id="nuLookupClear"></button>').text(M.lk_clear || 'Clear').attr('title', M.lk_clear_tip || '')
						.on('click', function () { try { P.nuNextLookupClear(); } catch (x) { /* ignore */ } }).appendTo(foot);
				}
				$('<span class="nuLookupKeys"></span>').text(M.lk_keys || '').appendTo(foot);
				foot.insertAfter(card);
			}
		};
		place();
		document.documentElement.classList.add('nuLookupReady');
		fit();
		document.addEventListener('nu:listrefresh', function () { place(); fit(); });
		$(document).on('shown.bs.dropdown hidden.bs.dropdown shown.bs.collapse hidden.bs.collapse', function () { setTimeout(fit, 0); });
		window.addEventListener('load', fit);
		document.addEventListener('keydown', function (e) {
			if (e.key !== 'Escape' || e.defaultPrevented || document.querySelector('.dropdown-menu.show, .modal.show')) return;
			var t = e.target, sf = g('nuSearchField');
			if (t === sf && sf.value !== '') {                     // first Esc: the search goes (the list again without it)
				e.preventDefault();
				sf.value = '';
				var f = sf.form;
				if (f) { if (f.requestSubmit) f.requestSubmit(); else f.submit(); }
				return;
			}
			if (t && t.closest && t.closest('tr.nuBrowseRow')) return;   // a row: Esc goes to the search first (nuapp.js)
			e.preventDefault();
			try { P.nuNextCloseLookup(); } catch (x) { /* ignore */ }
		});
	}

	function buildOptionsMenu() {
		var holder = g('nuActionHolder');
		if (!holder || g('nuOptions')) return;
		var S = N.shortcuts || {}, acts = shortcutActions().filter(function (a) { return a[3] > 0; });
		var wrap = $('<div class="dropdown nuOptionsMenu"></div>');
		var btn = $('<button type="button" class="btn btn-outline-secondary" id="nuOptions" data-bs-toggle="dropdown" aria-expanded="false"><i class="bi bi-three-dots-vertical"></i></button>')
			.attr({title: S.options || 'Options', 'aria-label': S.options || 'Options'});
		var ul = $('<ul class="dropdown-menu nuOptionsList"></ul>');
		phoneDesignItems(ul);
		ul.append($('<li><h6 class="dropdown-header"></h6></li>').find('h6').text(S.options || 'Options').end());
		var last = null;
		acts.forEach(function (a) {
			if (last !== null && a[3] !== last) ul.append('<li><hr class="dropdown-divider"></li>');
			last = a[3];
			var b = $('<button type="button" class="dropdown-item d-flex align-items-center gap-2"></button>').attr({'data-nu-shortcut': a[0], 'data-nu-act': a[1]})
				.append($('<i class="bi"></i>').addClass(a[2]))
				.append($('<span class="me-auto"></span>').text(S[a[1]] || a[1]))
				.append(TOUCH_ONLY || !a[0] ? null : $('<kbd class="nuKbd"></kbd>').text(keyLabel(a[0])));
			b.on('click', a[5]);
			ul.append($('<li></li>').append(b));
		});
		// audit trail: all changes of this record
		if (N.audit) {                                                   // with the record tools, before the last group (log out)
			var hist = $('<li></li>').append($('<a class="dropdown-item d-flex align-items-center gap-2" id="nuAuditHistory"></a>').attr('href', N.audit.url)
				.append('<i class="bi bi-clock-history"></i>').append($('<span class="me-auto"></span>').text(N.audit.label)));
			var lastHr = ul.children('li').filter(function () { return $(this).find('hr').length > 0; }).last();
			if (lastHr.length) hist.insertBefore(lastHr); else ul.append(hist);
		}
		// nuBuilder's <table>_nulog of this record: who added / last edited it
		if (N.nulog) {
			ul.append('<li><hr class="dropdown-divider"></li>');
			var info = $('<li class="px-3 py-1 small text-body-secondary nuNulogInfo"></li>').attr('title', N.nulog.column);
			if (!N.nulog.added && !N.nulog.edited) info.text(N.nulog.none);
			[N.nulog.added, N.nulog.edited].forEach(function (t) { if (t) info.append($('<div></div>').text(t)); });
			ul.append(info);
		}
		wrap.append(btn).append(ul).appendTo(holder);
		btn.on('shown.bs.dropdown', function () { callHook('nuOnOptionsListLoadedGlobal'); });   // nuBuilder: each time the list opens
	}
	N.shortcutActions = shortcutActions;

	// phones (the owner picked mockup C): Design, its ▾ entries, the (i) form info and the layout switch are hidden in the
	// toolbar below 576 px (nuapp.css) and offered at the top of the ⋮ menu instead – items li.nuOptPhone, shown only there
	function phoneDesignItems(ul) {
		var ds = g('nuDesignButton'), sql = g('nuBrowseSqlEdit'), cols = g('nuBrowseColsEdit'), info = g('nuFormInfoBtn'), seg = g('nuLayoutSeg');
		var items = [];
		var item = function (icon, text, act) {
			var el = typeof act === 'string' ? $('<a class="dropdown-item d-flex align-items-center gap-2"></a>').attr('href',
				act) : $('<button type="button" class="dropdown-item d-flex align-items-center gap-2"></button>').on('click', act);
			return el.append($('<i class="bi" aria-hidden="true"></i>').addClass(icon)).append($('<span class="me-auto"></span>').text(text));
		};
		if (ds) items.push(item('bi-pencil-square', (ds.textContent || '').trim(), ds.getAttribute('href')).attr('data-nu-opt', 'design'));
		[[sql, 'bi-filetype-sql', 'browse_sql'], [g('nuBrowsePhpEdit'), 'bi-filetype-php', 'browse_php'],
			[g('nuBrowseJsEdit'), 'bi-filetype-js', 'browse_js'], [cols, 'bi-layout-three-columns', 'columns'],
			[g('nuEditCodeEdit'), 'bi-code-slash', 'edit_code']].forEach(function (x) {
			if (x[0]) items.push(item(x[1], x[0].getAttribute('data-nu-full') || (x[0].textContent || '').trim(), function () { x[0].click(); }).attr('data-nu-opt', x[2]));
		});
		if (info) items.push(item('bi-info-circle', info.getAttribute('title') || 'Info', function () {
			var src = g('nuFormInfo'), box = $('<div class="nuFormInfo nuFormInfoPhone"></div>');
			if (src) box.append($(src).children().clone());
			box.find('.nuFiTitle').remove();                     // the message has the title; Copy all stays in the head
			box.find('[id]').removeAttr('id');
			nuMessage(info.getAttribute('title') || 'Info', [box.prop('outerHTML')]);
		}).attr('data-nu-opt', 'info'));
		var links = seg ? $(seg).children('a') : $();
		if (!items.length && !links.length) return;
		items.forEach(function (el) { ul.append($('<li class="nuOptPhone"></li>').append(el)); });
		if (links.length) {
			var grp = $('<div class="btn-group btn-group-sm nuSeg nuOptLayout" role="group"></div>').attr('aria-label', seg.getAttribute('aria-label') || '');
			links.each(function () { grp.append($(this).clone().removeAttr('id')); });
			ul.append($('<li class="nuOptPhone" data-nu-opt="layout"></li>').append(grp));
		}
		ul.append('<li class="nuOptPhone"><hr class="dropdown-divider"></li>');
	}

