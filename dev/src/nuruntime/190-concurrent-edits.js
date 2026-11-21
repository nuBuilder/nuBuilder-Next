	// =================================================================== concurrent edits (core/form/nuconflict.php)
	function ccFmt(t) { var a = Array.prototype.slice.call(arguments, 1), i = 0; return String(t || '').replace(/%s/g, function () { return a[i++] === undefined ? '' : a[i - 1]; }); }
	N.ccOver = {};                                                        // signatures taken over after "keep mine" / "delete anyway"
	/** Signatures of the edited fields (as opened) – the server compares them with the database. */
	function ccPayload(edited, subs) {
		var out = {f: {}, sf: {}}, cc = N.cc;
		edited.forEach(function (f) {
			var k = '||' + f, sig = N.ccOver[k] !== undefined ? N.ccOver[k] : (cc.f || {})[String(f).toLowerCase()];
			if (sig !== undefined) out.f[f] = sig;
		});
		Object.keys(subs || {}).forEach(function (sf) {
			var rowsCc = (cc.sf || {})[sf] || {}, o = {};
			(subs[sf].rows || []).forEach(function (r) {
				var info = r.pk ? rowsCc[r.pk] : null;
				if (!info) return;
				if (r.deleted) { var hk = sf + '|' + r.pk + '|'; o[r.pk] = {h: N.ccOver[hk] !== undefined ? N.ccOver[hk] : info.h}; return; }
				var f = {};
				(r.edited || []).forEach(function (fd) {
					var k = sf + '|' + r.pk + '|' + fd, sig = N.ccOver[k] !== undefined ? N.ccOver[k] : (info.f || {})[String(fd).toLowerCase()];
					if (sig !== undefined) f[fd] = sig;
				});
				if (Object.keys(f).length) o[r.pk] = {f: f};
			});
			if (Object.keys(o).length) out.sf[sf] = o;
		});
		return out;
	}
	function ccRow(c) { return c.sf ? $('#' + c.sf + ' .nuSubformRow').filter(function () { return this.getAttribute('data-nu-primary-key') === String(c.pk); })[0] : null; }
	function ccEl(c) {
		if (!c.sf) return g(c.field);
		var row = ccRow(c), p = row ? row.getAttribute('data-nu-prefix') : '';
		return p ? g(p + c.field) : null;
	}
	/** A value as the user sees it: text of a select option, ✓ for a ticked checkbox, (empty). */
	function ccShow(el, v) {
		v = v === null || v === undefined ? '' : String(v);
		if (el && el.tagName === 'SELECT') { for (var i = 0; i < el.options.length; i++) if (el.options[i].value === v) { v = el.options[i].text; break; } }
		else if (el && el.type === 'checkbox') v = v === '1' ? '✓' : '–';
		return v === '' ? '<span class="text-body-secondary fst-italic">' + esc(M.cc_empty) + '</span>' : esc(v);
	}
	function showConflict(c, close) {
		if (c.gone) { nuMessage([esc(M.cc_record_gone)]); return; }
		var intro = c.by ? ccFmt(M.cc_by, c.by, c.at) : M.cc_anon, rows = '', notes = '';
		(c.fields || []).forEach(function (x, i) {
			var where = x.sf ? ccFmt(M.cc_row, x.sf_label, x.row) : '';
			if (x.gone) { notes += '<li>' + esc(ccFmt(M.cc_row_gone, x.sf_label, x.row)) + '</li>'; return; }
			if (x.deleted_changed) {
				notes += '<li>' + esc(ccFmt(M.cc_row_changed, x.sf_label, x.row)) + ' <span class="text-nowrap">'
					+ '<label class="me-2"><input type="radio" name="nuCc' + i + '" value="mine" checked> ' + esc(M.cc_delete_row) + '</label>'
					+ '<label><input type="radio" name="nuCc' + i + '" value="theirs"> ' + esc(M.cc_keep_row) + '</label></span></li>';
				return;
			}
			var el = ccEl(x);
			var orig = el ? startValue(el) : '', mine = el ? rawValue(el) : '';
			rows += '<tr><td class="fw-semibold">' + esc(x.label) + (where ? '<div class="small text-body-secondary">' + esc(where) + '</div>' : '') + '</td>'
				+ '<td>' + ccShow(el, orig) + '</td><td>' + ccShow(el, mine) + '</td><td>' + ccShow(el, x.theirs) + '</td>'
				+ '<td class="text-nowrap"><label class="me-2"><input type="radio" name="nuCc' + i + '" value="mine" checked> ' + esc(M.cc_keep_mine) + '</label>'
				+ '<label><input type="radio" name="nuCc' + i + '" value="theirs"> ' + esc(M.cc_keep_theirs) + '</label></td></tr>';
		});
		var html = '<div id="nuConflict"><p>' + esc(intro) + '</p>'
			+ (rows ? '<div class="table-responsive"><table class="table table-sm align-middle small mb-2"><thead><tr><th>' + esc(M.cc_field) + '</th><th>' + esc(M.cc_original) + '</th><th>' + esc(M.cc_yours)
				+ '</th><th>' + esc(M.cc_theirs) + '</th><th></th></tr></thead><tbody>' + rows + '</tbody></table></div>' : '')
			+ (notes ? '<ul class="small mb-2">' + notes + '</ul>' : '')
			+ '<p class="small text-body-secondary mb-0">' + esc(M.cc_kept) + '</p></div>';
		var resolve = function ($box) {
			(c.fields || []).forEach(function (x, i) {
				var pick = $box.find('input[name="nuCc' + i + '"]:checked').val();
				if (x.gone) {                                                  // the row is gone: its changes are dropped
					var row = ccRow(x);
					if (row) $(row).find('.nuEdited').removeClass('nuEdited');
					return;
				}
				if (x.deleted_changed) {
					if (pick === 'theirs') { var rw = ccRow(x), d = rw ? g(rw.getAttribute('data-nu-prefix') + 'nuDelete') : null; if (d) { d.checked = false; $(d).trigger('change'); } }
					else N.ccOver[x.sf + '|' + x.pk + '|'] = x.h;
					return;
				}
				if (pick === 'theirs') { var el = ccEl(x); if (el) el.classList.remove('nuEdited'); }   // not written: the saved value stays
				else N.ccOver[(x.sf || '') + '|' + (x.sf ? x.pk : '') + '|' + x.field] = x.sig;
			});
			nuSaveAction(close);
		};
		var reload = function () { N.edited = false; $('#nuEditForm .nuEdited').removeClass('nuEdited'); window.location.reload(); };
		if ($.confirm) {
			$.confirm({
				title: M.cc_title, content: html, useBootstrap: false, boxWidth: Math.min(760, window.innerWidth - 24) + 'px', type: 'orange', columnClass: 'nuConflictBox',
				buttons: {
					save: {text: M.cc_save, btnClass: 'btn-orange', action: function () { resolve(this.$content); }},
					reload: {text: M.cc_reload, action: reload},
					cancel: {text: M.cancel}
				}
			});
		} else if (window.confirm(intro + '\n\n' + M.cc_save + '?')) resolve($(html));
	}

	// "also open by …" (D1): ping every 30 s while the tab is visible; others are shown above the form; never blocks
	if (N.presence && N.mode === 'edit' && N.record_id && N.record_id !== '-1') {
		var presenceUrl = N.base + '?p=formapi&presence=1';
		var ping = function (leave) {
			var body = JSON.stringify({action: 'presence', form_id: N.form.id, record_id: N.record_id, edited: !!N.edited, leave: leave ? 1 : 0});
			return NuUtil.post(presenceUrl, body,
				{keepalive: !!leave, headers: {'Content-Type': 'application/json'},
					strict: true}).then(function (j) { if (!leave && j && j.ok) showPresence(j.others || []); }).catch(function () { /* ignore */ });
		};
		var showPresence = function (others) {
			var box = g('nuPresence');
			if (!others.length) { if (box) box.remove(); return; }
			if (!box) {
				box = document.createElement('div');
				box.id = 'nuPresence';
				box.className = 'alert alert-warning py-2 px-3 mb-3 small d-flex gap-2 align-items-start';
				box.setAttribute('role', 'status');
				var head = document.querySelector('.nuFormHeader');
				if (head && head.parentNode) head.parentNode.insertBefore(box, head.nextSibling); else document.body.insertBefore(box, document.body.firstChild);
			}
			box.innerHTML = '<i class="bi bi-people-fill mt-1"></i><div>' + others.map(function (o) {
				var who = o.name && o.name !== o.login ? o.name + ' (' + o.login + ')' : o.login;
				return esc(ccFmt(o.edited ? M.pr_editing : M.pr_open, who, o.since));
			}).join('<br>') + '</div>';
		};
		var lastPing = 0, lastEdited = false;
		var tick = function (force) {
			if (document.visibilityState === 'hidden') return;
			if (force || Date.now() - lastPing > 29000 || !!N.edited !== lastEdited) { lastPing = Date.now(); lastEdited = !!N.edited; ping(false); }
		};
		setTimeout(function () { tick(true); }, 300);
		setInterval(tick, 5000);                                         // pings every 30 s, at once when unsaved changes start
		document.addEventListener('visibilitychange', function () { if (document.visibilityState === 'visible') tick(true); });
		window.addEventListener('pagehide', function () { ping(true); });
	}

	api('nuCloneAction', function () {
		if (!N.cloning && N.record_id && N.record_id !== '-1') N.cloneFrom = N.record_id;   // field data access: locked values come from the server
		N.record_id = '-1';
		N.is_new = true;
		N.cloning = true;
		Object.keys(N.objects || {}).forEach(function (id) {
			var o = N.objects[id], el = g(id);
			if (!el) return;
			if (id === N.form.primary_key || !o.cloneable) {
				if (el.type === 'checkbox') el.checked = false; else if ('value' in el) el.value = '';
				if (o.type === 'lookup') { if (g(id + 'code')) g(id + 'code').value = ''; if (g(id + 'description')) g(id + 'description').value = ''; }
			}
			if ('value' in el && el.type !== 'button') el.classList.add('nuEdited');
		});
		$('#nuEditForm .nuSubformRow').each(function () {
			if (this.classList.contains('nuNewRow')) return;   // the blank "new" row stays blank
			this.setAttribute('data-nu-primary-key', '');
			$(this).find('[data-nu-field]').addClass('nuEdited');
			if (!this.classList.contains('nuNewRow')) this.classList.add('nuRowEdited');
		});
		$('#nuCloneButton, #nuDeleteButton, #nuNewButton').remove();
		nuSetTitle(nuTranslate('Clone'));
		// clearly visible: a badge in the breadcrumb and a highlighted form until the copy is saved
		$('.nuBreadcrumb').last().append($('<span class="badge text-bg-warning ms-2 nuCloneBadge"><i class="bi bi-copy me-1"></i></span>').append(document.createTextNode(M.clone_badge
			|| 'Clone – not saved')));
		$('#nuEditForm').addClass('nuCloning');
		N.edited = true;
		$('#nuSaveButton').addClass('nuSaveEdited');
		nuToast(M.cloned || 'Record cloned – save to create it.', 'info');
		if (window.nuOnClone) window.nuOnClone();
	});

	// nuBuilder Forte: nuAttachSaveButtonTo(id, dx, dy, height, width, fontSize) – Save below the object (15 px gap + dy, left + dx);
	// the canvas of the tab grows when the button would end below it (was cut off)
	api('nuAttachSaveButtonTo', function (id, dx, dy, h, w, fs) {
		var b = g('nuSaveButton'), target = g('nuw_' + id) || g(id);
		if (!b || !target) return $(b);
		// one holder, moved on every call (form code calls it again after it moved fields – not a new one per call)
		var holder = $(b).closest('.nuAttachedSave');
		if (!holder.length) holder = $('<div class="nuAttachedSave"></div>').append(b);
		$(target).after(holder);
		if (Number(w) > 0) b.style.width = Number(w) + 'px';
		if (Number(h) > 0) { b.style.height = Number(h) + 'px'; b.style.minHeight = '0'; b.style.paddingTop = '0'; b.style.paddingBottom = '0'; }
		if (fs !== undefined && fs !== null) { if (typeof fs === 'number') b.style.fontSize = fs + 'px'; else $(b).css(fs); }
		if (Number(w) > 0 || Number(h) > 0) {
			b.classList.add('nuSaveSized');                                     // one line in the given size; the icon goes when the label needs the room
			if (b.scrollWidth > b.clientWidth + 1) b.classList.add('nuSaveNoIcon');
		}
		if (target.classList.contains('nuAbs')) {
			// as nuBuilder Forte: from the object's CSS top / height – also while it is hidden (form code shows it later); the drawn place
			// (offsetTop) of a hidden object is 0, so the button ended at the top of the tab
			var ctl = g(id), css = function (el, k) { var n = el ? parseFloat(el.style[k]) : NaN; return isNaN(n) ? null : n; };
			var tTop = css(target, 'top'), tLeft = css(target, 'left');
			var tH = css(ctl, 'height') !== null ? css(ctl, 'height') : (ctl && ctl.offsetHeight) || css(target, 'height') || target.offsetHeight;
			var top = (tTop !== null ? tTop : target.offsetTop) + tH + 15 + (Number(dy) || 0), left = (tLeft !== null ? tLeft : target.offsetLeft) + (Number(dx) || 0);
			holder.addClass('nuAbs').css({top: top + 'px', left: left + 'px'});
			var canvas = target.closest('.nuAbsoluteCanvas');
			if (canvas) {
				var need = top + (Number(h) > 0 ? Number(h) : b.offsetHeight) + 15;
				if (canvas.offsetHeight < need) canvas.style.minHeight = need + 'px';
			}
		}
		return $(b);
	});

	// nuBuilder: nuSetSaveButtonPosition(top, left, height, width, fontSize or css) – the Save button moves onto the first tab of an
	// absolute layout (like nuBuilder: only shown there); other layouts keep it in the action bar
	api('nuSetSaveButtonPosition', function (t, l, h, w, fs) {
		var b = g('nuSaveButton'), rec = g('nuRECORD');
		if (!b || !rec || !rec.classList.contains('nuLayout-absolute')) return $(b);
		var canvas = rec.querySelector('.nuTabPane .nuAbsoluteCanvas') || rec.querySelector('.nuTabPane');
		if (!canvas) return $(b);
		if (!w) w = b.offsetWidth;
		if (!h) h = b.offsetHeight;
		canvas.appendChild(b);
		$(b).addClass('nuSaveMoved').css({position: 'absolute', top: t + 'px', left: l + 'px', width: w + 'px', height: h + 'px', margin: 'unset'});
		if (fs !== undefined && fs !== null) { if (typeof fs === 'number') b.style.fontSize = fs + 'px'; else $(b).css(fs); }
		var need = Number(t) + Number(h) + 10;
		if (canvas.offsetHeight < need) canvas.style.minHeight = need + 'px';
		return $(b);
	});

	// nuBuilder Forte: nuBrowseRowsPerPageFilter(options) adds a "Rows" select to a browse list – here the list footer has a page size
	// menu already, so the call replaces the sizes of that menu for this form (Forte's default sizes without options;
	// insertBeforeTarget / customStyle of Forte's select are not needed). The chosen size stays in the address (?per=).
	api('nuBrowseRowsPerPageFilter', function (options) {
		if (N.mode !== 'browse') return;
		var menu = document.querySelector('.nuLfPerMenu'), tpl = menu && menu.querySelector('a.dropdown-item');
		if (!tpl) return;
		var sizes = (Array.isArray(options) && options.length ? options : [10, 20, 30, 40, 50, 60, 70, 80, 90, 100, 150, 200])
			.map(Number).filter(function (n) { return n >= 1 && n <= 1000 && Math.floor(n) === n; });
		if (!sizes.length) return;
		var act = menu.querySelector('a.dropdown-item.active') || tpl;
		var cur = Number(new URL(act.getAttribute('href'), location.href).searchParams.get('per')) || 0;
		if (cur && sizes.indexOf(cur) < 0) sizes.push(cur);
		sizes = sizes.filter(function (n, i) { return sizes.indexOf(n) === i; }).sort(function (a, b) { return a - b; });
		var text = tpl.textContent, num = text.match(/\d+/);
		menu.innerHTML = '';
		sizes.forEach(function (n) {
			var u = new URL(tpl.getAttribute('href'), location.href);
			u.searchParams.set('per', String(n));
			var a = document.createElement('a');
			a.className = 'dropdown-item' + (n === cur ? ' active' : '');
			if (n === cur) a.setAttribute('aria-current', 'true');
			a.href = u.pathname.split('/').pop() + u.search + u.hash;
			a.textContent = num ? text.replace(num[0], String(n)) : String(n);
			var li = document.createElement('li');
			li.appendChild(a);
			menu.appendChild(li);
		});
	});

	// nuBuilder Forte's action buttons are <input type="button" value="…">: code reads and changes the label with .value / $(…).val()
	// (e.g. filter buttons that mark the chosen one with "✓ "). Ours are <button>s (the label may hold HTML), so value = the label as
	// text and a jQuery val hook for button.nuActionButton gets / sets the label.
	if ($.valHooks) {
		var prevHook = $.valHooks.button;
		$.valHooks.button = {
			get: function (el) {
				if (el.tagName === 'BUTTON' && el.classList.contains('nuActionButton')) return el.textContent.trim();   // not <input type=button> (same hook key)
				return prevHook && prevHook.get ? prevHook.get(el, 'value') : undefined;
			},
			set: function (el, v) {
				if (el.tagName === 'BUTTON' && el.classList.contains('nuActionButton')) { var lb = el.querySelector('.nuButtonLabel'); (lb || el).textContent = v; el.value = v; return true; }
				return prevHook && prevHook.set ? prevHook.set(el, v, 'value') : undefined;
			}
		};
	}
	// nuBuilder Forte 4.9: built-in buttons by id (Save, SaveClose, Delete, Clone, Add, Print, Back, Run, RunHidden,
	// BuildFastForm, BuildFastReport) get their label, action and icon when those are left out; icon = icon classes,
	// insertAfterElement = id of the element to put the button after (else the action bar); value / title translated
	var ACTION_DEFAULTS = {
		Save: ['Save', 'nuSaveAction()', 'fa-regular fa-floppy-disk'], SaveClose: ['Save & Close', 'nuSaveAction(true)', 'fa-regular fa-floppy-disk'],
		Delete: ['Delete', 'nuDeleteAction()', 'fa-regular fa-square-minus'], Add: ['', 'nuAddAction()', 'fa-solid fa-plus'],
		Print: ['', 'nuPrintAction()', 'fa-solid fa-list-ul'], Back: ['Back', 'nuBackAction(this)', 'fa-solid fa-chevron-left'],
		Clone: ['Clone', 'nuCloneAction()', 'fa-regular fa-clone'], BuildFastForm: ['Build Fast Form', 'nuRunPHPHidden("nu_run_fast_form", true)', 'fa-solid fa-bolt'],
		BuildFastReport: ['Build Fast Report', 'nuRunPHPHidden("nu_run_fast_report", true)', 'fa-solid fa-bolt'],
		RunHidden: ['Run', 'nuRunHiddenAction()', 'fa-regular fa-circle-play'], Run: ['Run', 'nuRunAction()', 'fa-regular fa-circle-play']
	};
	api('nuAddActionButton', function (id, value, func, title, icon, insertAfterElement) {
		var d = ACTION_DEFAULTS[id];
		if (d) {
			if (value === undefined || value === null) value = d[0];
			if (func === undefined || func === null) func = d[1];
			if (icon === undefined || icon === null) icon = d[2] + ' fa-width-auto fa-lg';
		}
		if (value && typeof value === 'object') value = nuIsMobile() && value.valueMobile !== undefined ? value.valueMobile : (value.value || '');
		value = value === undefined || value === null ? '' : nuTranslate(String(value));
		var cls = 'btn btn-outline-secondary nuActionButton' + (['Save', 'Add', 'Clone', 'Delete'].indexOf(id) >= 0 ? ' nu' + id + 'Button' : '');
		$('#nu' + id + 'Button.nuActionButton').remove();
		var b = $('<button type="button"></button>').addClass(cls).attr('id', 'nu' + id + 'Button').attr('title', title ? nuTranslate(String(title)) : '');
		if (value !== '') b.append($('<span class="nuButtonLabel"></span>').html(value));
		b.attr('value', b.text().trim());
		if (func) b.on('click', function (e) { if (typeof func === 'function') func(e); else runSnippet(func, e, this); });
		if (insertAfterElement && g(insertAfterElement)) b.insertAfter('#' + insertAfterElement); else $('#nuActionHolder').append(b);
		if (icon) nuAddInputIcon(b.attr('id'), icon);
		return b;
	});
	if (!window.nuBackAction) api('nuBackAction', function () { nuOpenPreviousBreadcrumb(); });
	api('nuRemoveActionButton', function (id) { $('#nu' + id + 'Button').remove(); });

	api('nuAddDatalist', function (id, data) {
		var el = g(id);
		if (!el) return;
		var listId = 'nuDatalist_' + id;
		$('#' + listId).remove();
		var dl = $('<datalist></datalist>').attr('id', listId);
		arr(data).forEach(function (d) { dl.append($('<option>').attr('value', Array.isArray(d) ? d[0] : d).text(Array.isArray(d) && d[1] !== undefined ? d[1] : '')); });
		$(el).after(dl).attr('list', listId).attr('autocomplete', 'off');
	});

