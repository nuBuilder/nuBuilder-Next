	// =================================================================== label menu (globeadmin): quick changes of an object
	// Right-click on a label or button of the edit form: Object id (click = copy) · Properties… · Rename… · Actions (Delete, Move to tab) · Access · Align · Validation.
	// Changes are saved through the builder API and the form is reloaded.
	(function () {
		var OM = N.objmenu;
		if (!OM || (N.mode !== 'edit' && N.mode !== 'browse')) return;   // browse: the column title menu
		var T = OM.m, menu = null;

		function builderCall(action, data) {
			// in a draft preview the label menu changes the draft; a live change while a draft is open is confirmed first
			return NuUtil.post(N.base + '?p=builderapi', $.extend({action: action, form_id: N.form.id}, N.draft ? {draft_id: N.draft.id, rev: N.draft.rev} : {}, data))
				.then(function (j) {
					if (!j.ok && j.code === 'draft_open_confirm' && !data.live_ok && window.confirm(j.error)) return builderCall(action, $.extend({}, data, {live_ok: true}));
					if (!j.ok && j.code === 'draft_rev') { window.location.reload(); }
					if (!j.ok) throw new Error(j.error || T.error);
					if (N.draft && j.draft) N.draft.rev = j.draft.rev;
					return j;
				});
		}
		function change(o, fields) {
			if (N.objMenuHook) { N.objMenuHook('save', o, fields); return; }      // design mode: its draft, its reload
			if (N.edited && !window.confirm(T.unsaved)) return;
			builderCall('object.save', {object_id: o.object_id, fields: fields}).then(function () { N.edited = false; window.location.reload(); })
				.catch(function (e) { nuMessage([esc(e.message)]); });
		}
		function close() {
			if (menu) {
				var c = menu.find('.nuOmGeo').data('nuOmGeoCommit');            // a changed position / size is not lost when the menu closes
				if (c) c();
				$(document).off('pointerup.nuOmGeo');
				menu.remove(); menu = null;
			}
		}

		function item(icon, text, onClick, opt) {
			opt = opt || {};
			var li = $('<li></li>');
			var b = $('<button type="button" class="dropdown-item d-flex align-items-center gap-2"></button>')
				.append($('<i class="bi"></i>').addClass(icon || 'bi-dot').css('visibility', icon ? '' : 'hidden'))
				.append($('<span class="me-auto"></span>').text(text));
			if (opt.current) b.addClass('fw-bold').attr('aria-current', 'true').append('<i class="bi bi-check2"></i>');
			if (opt.danger) b.addClass('text-danger');
			if (opt.sub) {
				b.addClass('nuOmHasSub').attr({'aria-haspopup': 'true', 'aria-expanded': 'false'}).append('<i class="bi bi-chevron-right small"></i>');
				var sub = $('<ul class="dropdown-menu nuOmSub"></ul>');
				opt.sub.forEach(function (x) { sub.append(x); });
				li.addClass('nuOmSubWrap').append(b, sub);
				b.on('click', function (e) {                          // touch / keyboard: open the submenu
					e.stopPropagation();
					var open = !li.hasClass('nuOmOpen');
					li.siblings().removeClass('nuOmOpen').find('> button').attr('aria-expanded', 'false');
					li.toggleClass('nuOmOpen', open); b.attr('aria-expanded', open ? 'true' : 'false');
					if (open) sub.find('button').first().trigger('focus');
				});
				return li;
			}
			b.on('click', function (e) { e.stopPropagation(); close(); onClick(); });
			return li.append(b);
		}
		function divider() { return $('<li><hr class="dropdown-divider"></li>'); }

		// Top / Left / Width / Height (as nuBuilder Forte's object menu): the form changes while typing or spinning; saved once – on
		// Enter / leaving the field, and for the spin arrows when the mouse button is released (not on every step) – without a reload
		function geoRows(id, o) {
			var wrap = document.getElementById('nuw_' + id);
			if (o.top === undefined || !wrap || !wrap.classList.contains('nuAbs')) return $();
			var el = document.getElementById(id);
			var saved = {top: o.top, left: o.left, width: o.width, height: o.height}, pending = null, down = false;
			var apply = function (k, v) {
				if (k === 'height') {
					wrap.style.height = v + 'px';
					if (el && el !== wrap && /^(TEXTAREA|SELECT|INPUT|BUTTON|IFRAME|IMG)$/.test(el.tagName)) el.style.height = v + 'px';
				} else wrap.style[k] = v + 'px';
			};
			var commit = function () {
				if (!pending) return;
				var f = pending; pending = null;
				var same = Object.keys(f).every(function (k) { return f[k] === saved[k]; });
				if (same) return;
				if (N.objMenuHook) { N.objMenuHook('save', o, {sob_all_top: f.top, sob_all_left: f.left, sob_all_width: f.width, sob_all_height: f.height}); return; }
				builderCall('objects.geometry', {items: [$.extend({object_id: o.object_id}, f)]}).then(function () {
					Object.keys(f).forEach(function (k) { saved[k] = o[k] = f[k]; });   // no message: a series of clicks would show one per click
				}).catch(function (e) { nuMessage([esc(e.message)]); });
			};
			// several clicks on a spin arrow in a row: one save 700 ms after the last release
			var timer = null;
			var later = function () { clearTimeout(timer); timer = setTimeout(commit, 700); };
			var li = $('<li class="nuOmGeo"></li>');
			['top', 'left', 'width', 'height'].forEach(function (k) {
				var inp = $('<input type="number" class="form-control form-control-sm nuOmGeoInput" step="1">')
					.attr({min: k === 'top' || k === 'left' ? 0 : 10, 'data-geo': k, 'aria-label': T['geo_' + k]}).val(saved[k]);
				var row = $('<label class="nuOmGeoRow"></label>').append($('<span></span>').text(T['geo_' + k]), inp);
				inp.on('click mousedown keydown', function (e) { e.stopPropagation(); });
				inp.on('pointerdown', function () { down = true; });
				inp.on('input', function () {
					var v = parseInt(this.value, 10);
					if (isNaN(v)) return;
					v = Math.max(k === 'top' || k === 'left' ? 0 : 10, v);
					apply(k, v);
					pending = $.extend(pending || $.extend({}, saved), (function () { var x = {}; x[k] = v; return x; })());
				});
				inp.on('change', function () { if (!down) commit(); });          // typed + Enter / blur; spinning waits for the release
				inp.on('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); commit(); } });
				li.append(row);
			});
			$(document).on('pointerup.nuOmGeo', function () { if (down) { down = false; later(); } });
			li.data('nuOmGeoCommit', function () { clearTimeout(timer); commit(); });
			return $().add(divider()).add(li);
		}

		// Button settings: one dialog for a run object or an input button – Run (target, record, filter, method, open in),
		// Events (JavaScript per event), Label & look (label, icon), Access; one Save through the builder API (live or the open draft)
		var EVENTS = ['onclick', 'onchange', 'onblur', 'onfocus', 'oninput', 'onkeydown', 'onkeyup', 'ondblclick', 'onmouseover', 'onmouseout'];
		function isButton(o) { return o && (o.type === 'run' || (o.type === 'input' && o.input === 'button')); }
		function settings(id) {
			var o = N.objects[id];
			if (!isButton(o)) return;
			if (N.objMenuHook) { N.objMenuHook('properties', o); return; }
			$('#nuOmSetDlg').remove();
			var L = OM.run_lists || {forms: [], procs: [], reports: [], pivots: []};
			var r = o.run ? $.extend({}, o.run) : null;
			var dlg = $('<div class="modal fade nuOmSetDlg" id="nuOmSetDlg" tabindex="-1" aria-labelledby="nuOmSetTitle"><div class="modal-dialog modal-lg modal-dialog-scrollable modal-fullscreen-sm-down nuOmSetDialog"><div class="modal-content">'
				+ '<div class="modal-header py-2"><h2 class="modal-title fs-5 text-truncate" id="nuOmSetTitle"></h2><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>'
				+ '<div class="modal-body pt-2"><ul class="nav nav-tabs mb-3" role="tablist"></ul><div class="nuOmSetPanes"></div></div>'
				+ '<div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"></button><button type="button" class="btn btn-primary" id="nuOmSetSave"></button></div>'
				+ '</div></div></div>').appendTo('body');
			dlg.find('.modal-title').append($('<i class="bi bi-gear me-2"></i>'), $('<code></code>').text(id));
			dlg.find('.btn-close').attr('aria-label', T.cancel);
			dlg.find('.modal-footer .btn-outline-secondary').text(T.cancel);
			dlg.find('#nuOmSetSave').text(T.save);
			var tabs = dlg.find('.nav-tabs'), panes = dlg.find('.nuOmSetPanes');
			function pane(key, title) {
				var b = $('<button type="button" class="nav-link" role="tab"></button>').attr({id: 'nuOmSetTab_' + key, 'data-pane': key}).text(title);
				tabs.append($('<li class="nav-item" role="presentation"></li>').append(b));
				var p = $('<div class="nuOmSetPane" role="tabpanel" hidden></div>').attr('id', 'nuOmSetPane_' + key);
				panes.append(p);
				b.on('click', function () {
					tabs.find('.nav-link').removeClass('active').attr('aria-selected', 'false');
					b.addClass('active').attr('aria-selected', 'true');
					panes.children().prop('hidden', true); p.prop('hidden', false);
					nuStore.set('nunext-om-set-tab', key);
				});
				return p;
			}
			function row(label, ctl, tip) {
				var lb = $('<label class="form-label mb-1"></label>').text(label);
				if (ctl && ctl.attr && ctl.attr('id')) lb.attr('for', ctl.attr('id'));
				var head = $('<div class="nuOmSetLbl"></div>').append(lb);
				if (tip) head.append($('<span class="nuInfoTip ms-1" tabindex="0" role="img"><i class="bi bi-info-circle"></i></span>').attr({title: tip, 'aria-label': tip}));
				return $('<div class="mb-3 nuOmSetRow"></div>').append(head, $('<div class="nuOmSetCtl"></div>').append(ctl));
			}
			function select(id2, opts, val) {
				var sel = $('<select class="form-select"></select>').attr('id', id2);
				opts.forEach(function (x) { sel.append($('<option></option>').val(x[0]).text(x[1]).prop('selected', String(x[0]) === String(val))); });
				return sel;
			}

			// --- Run
			var runFields = null;
			if (r) {
				var pRun = pane('run', T.set_run);
				var typeSel = select('nuOmSetType', ['F', 'P', 'R', 'U', 'V'].map(function (k) { return [k, T.r_types[k]]; }), r.type);
				var tgtWrap = $('<div id="nuOmSetTargetWrap"></div>');
				var urlIn = $('<input type="text" class="form-control font-monospace" id="nuOmSetUrl" placeholder="https://">').val(r.url || '');
				var recIn = $('<input type="text" class="form-control font-monospace" id="nuOmSetRecord">').val(r.record);
				var fltIn = $('<input type="text" class="form-control font-monospace" id="nuOmSetFilter">').val(r.filter);
				var methSel = select('nuOmSetMethod', [['b', T.r_b], ['i', T.r_i]], r.method);
				var openWrap = $('<div id="nuOmSetOpenWrap"></div>');
				var link = $('<a class="small nuOmSetLink" target="_blank" rel="noopener" id="nuOmSetOpenTarget"></a>').append('<i class="bi bi-box-arrow-up-right me-1"></i>',
					document.createTextNode(T.open_target));
				var recRow = row(T.r_record, recIn, T.r_record_tip), fltRow = row(T.r_filter, fltIn, T.r_filter_tip), urlRow = row(T.r_url, urlIn);
				pRun.append(row(T.set_run, typeSel), tgtWrap, urlRow, recRow, fltRow, row(T.r_method, methSel), openWrap, link);
				var ts = null, tgtSel = null;
				var drawTarget = function (keep) {
					var k = typeSel.val();
					if (ts) { ts.destroy(); ts = null; }
					tgtWrap.empty(); tgtSel = null;
					var list = k === 'F' ? L.forms : k === 'P' ? L.procs : k === 'R' ? L.reports : k === 'V' ? L.pivots : null;
					if (list) {
						var cur = keep ? (k === 'V' ? r.pivot : r.target) : '';
						tgtSel = select('nuOmSetTarget', [['', T.please_select]].concat(list.map(function (x) { return [x[0], x[1]]; })), cur);
						tgtWrap.append(row(k === 'F' ? T.r_form : k === 'P' ? T.r_proc : k === 'R' ? T.r_report : T.r_pivot, tgtSel));
						if (window.TomSelect) ts = new TomSelect(tgtSel[0], {maxOptions: null, allowEmptyOption: true, create: false, dropdownParent: 'body', plugins: ['dropdown_input'],
							onItemAdd: function () { this.close(); this.blur(); }});
						tgtSel.on('change', drawLink);
					}
					urlRow.prop('hidden', k !== 'U'); recRow.prop('hidden', k !== 'F'); fltRow.prop('hidden', k !== 'F');
					drawOpen();
					drawLink();
				};
				var drawOpen = function () {
					var k = typeSel.val();
					openWrap.empty();
					if (methSel.val() === 'i' || k === 'P' || k === 'R') return;
					var opts = k === 'F' ? [0, 1, 2, 3].map(function (i) { return [String(i), T.r_targets[i]]; }) : [['2', T.r_targets[2]], ['3', T.r_url_popup], ['0', T.r_url_same]];
					var cur = String(r.open);
					if (k !== 'F' && cur === '1') cur = '0';
					openWrap.append(row(T.r_open, select('nuOmSetOpen', opts, opts.some(function (x) { return x[0] === cur; }) ? cur : opts[0][0])));
				};
				var drawLink = function () {
					var k = typeSel.val(), v = tgtSel ? tgtSel.val() : '';
					var href = !v ? '' : k === 'F' ? '?p=form&f=' + encodeURIComponent(v) : k === 'P' ? '?p=procedures&id=' + encodeURIComponent(v)
						: k === 'R' ? '?p=reports&id=' + encodeURIComponent(v) : k === 'V' ? '?p=form&f=' + encodeURIComponent(v.split('|')[0]) : '';
					link.prop('hidden', !href).attr('href', href ? N.base + href : null);
				};
				typeSel.on('change', function () { drawTarget(typeSel.val() === r.type); });
				methSel.on('change', drawOpen);
				drawTarget(true);
				runFields = function () {
					var k = typeSel.val(), v = tgtSel ? tgtSel.val() : '', op = $('#nuOmSetOpen').val();
					var f = {sob_run_method: methSel.val()};
					if (op !== undefined) f.sob_run_target = op;
					if (k === 'V') return v ? $.extend(f, {nunext_run_pivot: v, nunext_run_url: null}) : null;
					if (k === 'U') return urlIn.val().trim() ? $.extend(f, {nunext_run_url: urlIn.val().trim(), nunext_run_pivot: null}) : null;
					if (!v) return null;
					return $.extend(f, {sob_run_type: k, sob_run_zzzzsys_form_id: v, sob_run_id: k === 'F' ? recIn.val() : '', sob_run_filter: k === 'F' ? fltIn.val() : '',
						nunext_run_url: null, nunext_run_pivot: null});
				};
			}

			// --- Events
			var pEv = pane('events', T.set_events + ((o.js || []).length ? ' (' + o.js.length + ')' : ''));
			var evRows = $('<div class="nuOmSetEvRows d-grid gap-2"></div>');
			var addEv = function (ev) {
				var sel = select('', EVENTS.concat(ev.event && EVENTS.indexOf(ev.event) < 0 ? [ev.event] : []).map(function (x) { return [x, x]; }), ev.event || 'onclick')
					.addClass('form-select-sm nuOmSetEvName').attr('aria-label', T.event).removeAttr('id');
				var ta = $('<textarea class="form-control font-monospace nuOmSetEvJs" rows="3" spellcheck="false" aria-label="JavaScript"></textarea>').val(ev.js || '');
				var del = $('<button type="button" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>').attr({title: T.delete, 'aria-label': T.delete});
				var rw = $('<div class="nuOmSetEv"></div>').append($('<div class="d-flex gap-2 mb-1"></div>').append(sel.css('max-width', '14rem'), del.addClass('ms-auto')), ta);
				del.on('click', function () { rw.remove(); });
				evRows.append(rw);
			};
			(o.js || []).forEach(addEv);
			pEv.append(evRows, $('<button type="button" class="btn btn-sm btn-outline-primary mt-2" id="nuOmSetEvAdd"><i class="bi bi-plus-lg me-1"></i></button>')
				.append(document.createTextNode(T.add_event)).on('click', function () { addEv({}); evRows.find('textarea').last().trigger('focus'); }));

			// --- Label & look
			var pLook = pane('look', T.set_look);
			var lblIn = $('<input type="text" class="form-control" id="nuOmSetLabel">').val(o.label_raw !== undefined ? o.label_raw : o.label);
			var icoIn = $('<input type="text" class="form-control font-monospace" id="nuOmSetIcon" placeholder="bi-house">').val(o.icon || '');
			pLook.append(row(T.label, lblIn), row(T.icon, icoIn, T.icon_tip));

			// --- Access
			var pAcc = pane('access', T.access);
			var accSel = select('nuOmSetAccess', T.access_v.map(function (txt, i) { return [String(i), txt]; }), String(o.access || '0'));
			pAcc.append(row(T.access, accSel));

			var last = '';
			try { last = localStorage.getItem('nunext-om-set-tab') || ''; } catch (e) { /* none */ }
			(tabs.find('[data-pane="' + last + '"]').length ? tabs.find('[data-pane="' + last + '"]') : tabs.find('.nav-link').first()).trigger('click');

			dlg.find('#nuOmSetSave').on('click', function () {
				var f = {sob_all_label: lblIn.val(), sob_input_icon: icoIn.val().trim(), sob_all_access: accSel.val()};
				if (runFields) {
					var rf = runFields();
					if (!rf) { tabs.find('[data-pane=run]').trigger('click'); (tgtSel || urlIn).trigger('focus'); return; }
					$.extend(f, rf);
				}
				var events = evRows.children().map(function () {
					return {event: $(this).find('.nuOmSetEvName').val(), js: $(this).find('.nuOmSetEvJs').val()};
				}).get().filter(function (x) { return x.js.trim() !== ''; });
				if (N.edited && !window.confirm(T.unsaved)) return;
				var b = $(this).prop('disabled', true);
				builderCall('object.save', {object_id: o.object_id, fields: f, events: events})
					.then(function () { N.edited = false; window.location.reload(); })
					.catch(function (e) { b.prop('disabled', false); nuMessage([esc(e.message)]); });
			});
			dlg.on('hidden.bs.modal', function () { if (ts) ts.destroy(); dlg.remove(); });
			nuBs.modal(dlg[0]).show();
		}
		N.objButtonSettings = settings;
		// Alt+click on a button opens its settings (not in design mode, which selects objects on click)
		document.addEventListener('click', function (e) {
			if (!e.altKey || N.objMenuHook || N.mode !== 'edit') return;
			var b = e.target.closest && e.target.closest('#nuEditForm button.nuRunButton[id], #nuEditForm button.nuButton[id], #nuEditForm input[type=button][id]');
			if (!b || !isButton(N.objects[b.id])) return;
			e.preventDefault(); e.stopImmediatePropagation();
			settings(b.id);
		}, true);

		function open(id, x, y) {
			close();
			var o = N.objects[id];
			if (!o) return;
			var head = $('<li></li>').append($('<button type="button" class="dropdown-item nuOmHead"></button>')
				.append($('<span class="text-body-secondary"></span>').text(T.object + ': ')).append($('<b></b>').text(id))
				.attr('title', T.copied.replace('%s', id))
				.on('click', function (e) {
					e.stopPropagation(); close();
					var done = function () { nuToast(T.copied.replace('%s', id), 'success'); };
					if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(id).then(done, function () { window.prompt(T.object, id); });
					else window.prompt(T.object, id);               // http: no clipboard API – the id is selected in the prompt
				}));
			var tabs = (OM.tabs || []).filter(function (t) { return t[0] !== o.tab; }).map(function (t) {
				return item('bi-folder2', t[1] || t[0], function () { change(o, {sob_all_zzzzsys_tab_id: t[0]}); });
			});
			// Clone: a copy of the object (id <id>_2 …, below the original, same events) – saved at once, the form reloads
			var actions = [item('bi-copy', T.clone, function () {
				if (N.objMenuHook) { N.objMenuHook('clone', o); return; }
				if (N.edited && !window.confirm(T.unsaved)) return;
				builderCall('object.clone', {object_id: o.object_id}).then(function () { N.edited = false; window.location.reload(); })
					.catch(function (e) { nuMessage([esc(e.message)]); });
			}), item('bi-trash', T.delete, function () {
				if (N.objMenuHook) { N.objMenuHook('delete', o); return; }
				if (!window.confirm(T.delete_confirm.replace('%s', id))) return;
				if (N.edited && !window.confirm(T.unsaved)) return;
				builderCall('object.delete', {object_id: o.object_id}).then(function () { N.edited = false; window.location.reload(); })
					.catch(function (e) { nuMessage([esc(e.message)]); });
			}, {danger: true})];
			if (tabs.length) actions.push(item('bi-folder-symlink', T.move, null, {sub: tabs}));
			var accIcons = ['bi-pencil', 'bi-lock', 'bi-eye-slash', 'bi-person-slash', 'bi-person-lock'];
			var access = T.access_v.map(function (txt, i) { return item(accIcons[i], txt, function () { change(o, {sob_all_access: String(i)}); }, {current: String(o.access || '0') === String(i)}); });
			var align = [['left', 'bi-text-left'], ['center', 'bi-text-center'], ['right', 'bi-text-right']].map(function (a) {
				return item(a[1], T[a[0]], function () { change(o, {sob_all_align: a[0]}); }, {current: (o.align || 'left') === a[0]});
			});
			var valIcons = ['bi-globe', 'bi-input-cursor', 'bi-key', 'bi-star-fill'];
			var validate = T.validate_v.map(function (txt, i) { return item(valIcons[i], txt, function () { change(o, {sob_all_validate: String(i)}); }, {current: String(o.validate || '0') === String(i)}); });

			menu = $('<ul class="dropdown-menu show nuObjMenu shadow" role="menu"></ul>').append(
				head, divider(),
				// design mode: the object is selected and its properties panel is open – both entries would only select it again
				isButton(o) && !N.objMenuHook ? (function () { var li = item('bi-gear', T.settings, function () { settings(id); }); li.find('button').attr({title: T.settings_tip, id: 'nuOmSettings'}); return li; })() : $(),
				N.objMenuHook ? $() : item('bi-sliders', T.properties,
					function () { window.open(N.base + '?p=builder&f=' + encodeURIComponent(N.form.id) + '&obj=' + encodeURIComponent(o.object_id) + '&fid=' + encodeURIComponent(id) + (N.record_id && N.record_id !== '-1' ? '&r=' + encodeURIComponent(N.record_id) : ''), '_blank'); }),
				item('bi-input-cursor-text', T.rename, function () {
					var cur = labelText(id) || o.label || '';            // words / images have no separate label
					var v = window.prompt(T.rename_prompt.replace('%s', id), cur);
					if (v !== null && v.trim() !== '' && v !== cur) change(o, {sob_all_label: v.trim()});
				}),
				divider(), item('bi-lightning', T.actions, null, {sub: actions}),
				divider(), item('bi-shield-lock', T.access, null, {sub: access}), item('bi-text-left', T.align, null, {sub: align}),
				item('bi-check2-square', T.validation, null, {sub: validate}),
				geoRows(id, o)
			).appendTo('body');
			placeMenu(x, y);
		}
		function placeMenu(x, y) {
			// inside the window
			// phones: the long press selected a word – the browser's search sheet then covers the bottom; drop the selection
			// and fit the menu into the visible part of the window (visualViewport), not the layout height
			try { var sel = window.getSelection && window.getSelection(); if (sel && !sel.isCollapsed) sel.removeAllRanges(); } catch (e) { /* none */ }
			var VV = window.visualViewport;
			var w = menu.outerWidth(), h = menu.outerHeight(), vw = document.documentElement.clientWidth,
				vh = VV ? Math.min(document.documentElement.clientHeight, VV.height + VV.offsetTop) : document.documentElement.clientHeight;
			var left = Math.max(4, Math.min(x, vw - w - 4)), SUBW = 220;
			// submenus beside the menu: right, or left near the right edge; no room on either side (phones) → they unfold inside the menu
			var inline = left + w + SUBW > vw && left - SUBW < 0;
			menu.toggleClass('nuOmInline', inline).toggleClass('nuOmFlip', !inline && left + w + SUBW > vw);
			function place() {                                        // keep the whole menu inside the window (also after a submenu unfolds)
				var hh = menu.outerHeight();
				menu.css({top: Math.max(4 + (VV ? VV.offsetTop : 0), Math.min(parseFloat(menu.css('top')) || y, vh - hh - 4)) + 'px'});
			}
			menu.css({position: 'fixed', left: left + 'px', top: Math.max(4 + (VV ? VV.offsetTop : 0), Math.min(y, vh - h - 4)) + 'px', zIndex: 1070,
				maxHeight: (vh - 8 - (VV ? VV.offsetTop : 0)) + 'px', overflowY: inline ? 'auto' : ''});
			// a submenu that would leave the window at the bottom opens upwards from its row
			menu.on('mouseenter focusin click', '.nuOmSubWrap', function () {
				if (inline) { place(); return; }
				var sub = $(this).children('.nuOmSub').removeClass('nuOmUp');
				var r = sub[0].getBoundingClientRect();
				if (r.height && r.bottom > document.documentElement.clientHeight - 4) sub.addClass('nuOmUp');
			});
			menu.find('button').first().trigger('focus');
		}

		// right-click on a tab: tab id (click = copy) · Rename… · Visibility · Move left / right · Delete · Edit in design mode
		// – the same changes as the tab menu of design mode, saved through the builder API (tabs.save = the whole list) and reloaded
		function tabRows() { return (OM.tabs || []).map(function (t) { return {id: t[0], title: t[2] !== undefined ? t[2] : t[1], access: String(t[3] || '0')}; }); }
		function saveTabs(rows) {
			if (N.edited && !window.confirm(T.unsaved)) return;
			builderCall('tabs.save', {tabs: rows}).then(function () { N.edited = false; window.location.reload(); })
				.catch(function (e) { nuMessage([esc(e.message)]); });
		}
		function openTab(tabId, x, y) {
			close();
			var rows = tabRows(), i = rows.map(function (r) { return r.id; }).indexOf(tabId);
			if (i < 0) return;
			var row = rows[i], title = (OM.tabs[i] || [])[1] || row.id, acc = row.access;
			var setAcc = function (a) { saveTabs(tabRows().map(function (r) { if (r.id === tabId) r.access = a; return r; })); };
			var move = function (d) { var r = tabRows(), x0 = r.splice(i, 1)[0]; r.splice(i + d, 0, x0); saveTabs(r); };
			var head = $('<li></li>').append($('<button type="button" class="dropdown-item nuOmHead"></button>')
				.append($('<span class="text-body-secondary"></span>').text(T.tab + ': ')).append($('<b></b>').text(title))
				.attr('title', T.copied.replace('%s', tabId))
				.on('click', function (e) {
					e.stopPropagation(); close();
					var done = function () { nuToast(T.copied.replace('%s', tabId), 'success'); };
					if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(tabId).then(done, function () { window.prompt(T.tab, tabId); });
					else window.prompt(T.tab, tabId);
				}));
			var vis = [['0', 'bi-eye', T.tab_visible], ['2', 'bi-eye-slash', T.tab_hide], ['3', 'bi-person-lock', T.tab_hide_users]].map(function (v) {
				return item(v[1], v[2], function () { setAcc(v[0]); }, {current: acc === v[0]});
			});
			var items = [head, divider(),
				item('bi-input-cursor-text', T.tab_rename, function () {
					var v = window.prompt(T.tab_rename_prompt.replace('%s', title), title);
					if (v !== null && v.trim() !== '' && v.trim() !== title) saveTabs(tabRows().map(function (r) { if (r.id === tabId) r.title = v.trim(); return r; }));
				}),
				item('bi-eye', T.tab_access, null, {sub: vis})];
			if (i > 0) items.push(item('bi-arrow-left', T.tab_left, function () { move(-1); }));
			if (i < rows.length - 1) items.push(item('bi-arrow-right', T.tab_right, function () { move(1); }));
			if (rows.length > 1) {
				items.push(divider(), item('bi-trash', T.tab_delete, function () {
					var n = Object.keys(N.objects || {}).filter(function (k) { return N.objects[k] && N.objects[k].tab === tabId; }).length;
					var other = (OM.tabs[i === 0 ? 1 : 0] || [])[1] || '';
					var msg = n ? T.tab_delete_confirm_n.replace('%s', title).replace('%s', String(n)).replace('%s', other) : T.tab_delete_confirm.replace('%s', title);
					var go = function () { saveTabs(tabRows().filter(function (r) { return r.id !== tabId; })); };
					if (typeof window.nuConfirmAsk === 'function') window.nuConfirmAsk(msg, go, T.delete, 'btn btn-danger', {cancelFirst: true});
					else if (window.confirm(msg)) go();
				}, {danger: true}));
			}
			if (OM.design) items.push(divider(), item('bi-pencil-square', T.tab_design, function () { window.location.href = OM.design; }));
			menu = $('<ul class="dropdown-menu show nuObjMenu nuTabMenu shadow" role="menu"></ul>').append(items).appendTo('body');
			placeMenu(x, y);
		}
		$(document).on('contextmenu', '#nuTabHolder .nuTab[data-nu-tab-id]', function (e) {
			if (e.ctrlKey || N.objMenuHook) return;                              // Ctrl: the browser's menu · design mode has its own tab menu
			e.preventDefault();
			e.stopPropagation();
			var x = e.clientX, y = e.clientY;
			if (!x && !y) { var r = this.getBoundingClientRect(); x = r.left + 8; y = r.bottom; }
			openTab(this.getAttribute('data-nu-tab-id'), x, y);
		});

		// right-click on a column title of the list (as design mode's column menu): title + id (click = copy) · Properties… (design
		// mode with the column selected) · Rename… · Align · Width · Filter · Move left / right · Hide · Delete – saved with browse.save
		// (the whole list of columns) and reloaded
		function colRows() { return (OM.cols || []).map(function (c) { return {id: c[0], title: c[1], display: c[2], align: c[3], format: c[4], width: c[5], filter: !!c[6]}; }); }
		function saveCols(rows) {
			builderCall('browse.save', {columns: rows}).then(function () { window.location.reload(); })
				.catch(function (e) { nuMessage([esc(e.message)]); });
		}
		function openCol(th, x, y) {
			close();
			var bid = th.getAttribute('data-nu-browse-id'), rows = colRows(), i = rows.map(function (r) { return r.id; }).indexOf(bid);
			if (i < 0) return;
			var c = rows[i], oid = bid;   // the column's id (zzzzsys_browse_id) – copied as nuBuilder Forte's menu does (data-nu-title-id)
			var set = function (part) { saveCols(colRows().map(function (r) { return r.id === bid ? $.extend(r, part) : r; })); };
			var move = function (d) { var r = colRows(), x0 = r.splice(i, 1)[0]; r.splice(i + d, 0, x0); saveCols(r); };
			var name = $('<div>').html(c.title).text() || c.display;
			var head = $('<li></li>').append($('<button type="button" class="dropdown-item nuOmHead"></button>')
				.append($('<span class="fw-semibold me-1"></span>').text(name)).append($('<small class="text-body-secondary"></small>').text(oid))
				.attr('title', T.copied.replace('%s', oid))
				.on('click', function (e) {
					e.stopPropagation(); close();
					var done = function () { nuToast(T.copied.replace('%s', oid), 'success'); };
					if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(oid).then(done, function () { window.prompt(T.object, oid); });
					else window.prompt(T.object, oid);
				}));
			var width = $('<input type="number" min="0" step="1" class="form-control form-control-sm nuOmGeoInput nuOmColWidth">').val(String(c.width)).attr('aria-label', T.col_width)
				.on('click', function (e) { e.stopPropagation(); })
				.on('keydown', function (e) {
					e.stopPropagation();
					if (e.key === 'Enter') { e.preventDefault(); close(); }
					if (e.key === 'Escape') { e.preventDefault(); width.val(String(c.width)); close(); }
				});
			var commitWidth = function () { var v = Math.max(0, parseInt(width.val(), 10) || 0); if (v !== c.width) set({width: v}); };   // Enter, or the menu closes
			var align = [['l', 'bi-text-left', T.left], ['r', 'bi-text-right', T.right], ['c', 'bi-text-center', T.center]].map(function (a) {
				return item(a[1], a[2], function () { if (c.align !== a[0]) set({align: a[0]}); }, {current: c.align === a[0]});
			});
			var items = [head, divider()];
			if (OM.design) items.push(item('bi-sliders', T.properties, function () {
				try { window.sessionStorage.setItem('nunext-design:' + N.form.id, JSON.stringify({col: bid})); } catch (e) { /* no storage */ }
				window.location.href = OM.design;
			}));
			items.push(item('bi-input-cursor-text', T.rename, function () {
				var v = window.prompt(T.rename_prompt.replace('%s', oid), c.title);
				if (v !== null && v.trim() !== '' && v !== c.title) set({title: v.trim()});
			}), divider(), item('bi-text-left', T.align, null, {sub: align}),
			$('<li class="nuOmGeo"></li>').data('nuOmGeoCommit',
				commitWidth).append($('<label class="nuOmGeoRow"></label>').append($('<span class="d-flex align-items-center gap-2"><i class="bi bi-arrows"></i></span>').append($('<span></span>').text(T.col_width)), width)),
			item('bi-funnel', T.col_filter, function () { set({filter: !c.filter}); }, {current: c.filter}), divider());
			if (i > 0) items.push(item('bi-arrow-left', T.col_left, function () { move(-1); }));
			if (i < rows.length - 1) items.push(item('bi-arrow-right', T.col_right, function () { move(1); }));
			items.push(item('bi-eye-slash', T.col_hide, function () { set({width: 0}); }), divider(), item('bi-trash', T.delete, function () {
				var go = function () { saveCols(colRows().filter(function (r) { return r.id !== bid; })); };
				var msg = T.col_delete_confirm.replace('%s', name);
				if (typeof window.nuConfirmAsk === 'function') window.nuConfirmAsk(msg, go, T.delete, 'btn btn-danger', {cancelFirst: true});
				else if (window.confirm(msg)) go();
			}, {danger: true}));
			menu = $('<ul class="dropdown-menu show nuObjMenu nuColMenu shadow" role="menu"></ul>').append(items).appendTo('body');
			placeMenu(x, y);
		}
		$(document).on('contextmenu', '#nuBrowseTable th.nuBrowseTitle[data-nu-browse-id]', function (e) {
			if (e.ctrlKey || N.mode !== 'browse' || $('#nuBrowseTable').hasClass('nuDsBrowse')) return;   // Ctrl: the browser's menu · design mode has its own
			e.preventDefault();
			e.stopPropagation();
			var x = e.clientX, y = e.clientY;
			if (!x && !y) { var r = this.getBoundingClientRect(); x = r.left + 8; y = r.bottom; }
			openCol(this, x, y);
		});

		// right-click as in nuBuilder 4.x (before: a left click, which kept labels from focusing their field): labels, content
		// box titles and buttons (input buttons, run buttons); Ctrl + right-click keeps the browser's own menu (inspect); the context-menu
		// key / Shift+F10 opens it at the element
		// also the title an HTML object draws itself (a framed box from the form's CSS: .title, h1–h6, legend, .card-header)
		var HTML_TITLES = ['.title', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'legend', '.card-header'].map(function (x) { return '#nuEditForm .nuHtml[id] ' + x; }).join(', ');
		// also words and images (as nuBuilder Forte)
		$(document).on('contextmenu',
			'#nuEditForm label[id^="label_"], #nuEditForm .nuContentBoxTitle[id^="label_"], #nuEditForm button.nuButton[id], #nuEditForm button.nuRunButton[id], #nuEditForm .nuWord[id], #nuEditForm img.nuImage[id], ' + HTML_TITLES, function (e) {
			if (e.ctrlKey) return;
			var html = $(this).closest('.nuHtml[id]');
			var id = html.length && !$(this).is('label, button') ? html.attr('id') : (/^label_/.test(this.id) && !$(this).is('button') ? this.id.substring(6) : this.id);
			if (!N.objects[id]) return;                              // subform columns / rows and labels without object: the browser's menu
			e.preventDefault();
			e.stopPropagation();
			var x = e.clientX, y = e.clientY;
			if (!x && !y) { var r = this.getBoundingClientRect(); x = r.left + 8; y = r.bottom; }   // from the keyboard
			open(id, x, y);
		});
		// sub-form column titles (as nuBuilder Forte): the column is an object of the sub-form's own form – its id, Properties
		// (design mode of that form with the object chosen) and the sub-form's form in design mode
		function openSfCol(th, x, y) {
			close();
			var id = th.getAttribute('data-nu-field'), oid = th.getAttribute('data-nu-obj'), f = th.getAttribute('data-nu-sf-form');
			var url = function (obj) { return N.base + '?p=form&f=' + encodeURIComponent(f) + '&r=-1&design=1' + (obj ? '&dsobj=' + encodeURIComponent(obj) : ''); };
			var head = $('<li></li>').append($('<button type="button" class="dropdown-item nuOmHead"></button>')
				.append($('<span class="text-body-secondary"></span>').text(T.object + ': ')).append($('<b></b>').text(id))
				.attr('title', T.copied.replace('%s', id))
				.on('click', function (e) {
					e.stopPropagation(); close();
					if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(id).then(function () { nuToast(T.copied.replace('%s', id), 'success'); });
					else window.prompt(T.object, id);
				}));
			menu = $('<ul class="dropdown-menu show nuObjMenu nuSfColMenu shadow" role="menu"></ul>').append(head, divider(),
				item('bi-sliders', T.properties, function () { window.open(url(oid), '_blank'); }),
				item('bi-ui-checks-grid', T.sf_form || T.properties, function () { window.open(url(''), '_blank'); })
			).appendTo('body');
			placeMenu(x, y);
		}
		$(document).on('contextmenu', '#nuEditForm th.nuSubformTitle[data-nu-obj][data-nu-sf-form]', function (e) {
			if (e.ctrlKey || N.objMenuHook || !OM.design) return;          // design mode: the form's own tools · no design right: the browser's menu
			e.preventDefault();
			e.stopPropagation();
			var x = e.clientX, y = e.clientY;
			if (!x && !y) { var r = this.getBoundingClientRect(); x = r.left + 8; y = r.bottom; }
			openSfCol(this, x, y);
		});
		N.objMenuOpen = open;                                                // design mode opens it from the object's overlay
		$(document).on('click', function (e) { if (menu && !$(e.target).closest('.nuObjMenu').length) close(); });
		// keys while the menu is open: on window in the capture phase – before Bootstrap's dropdown handler (capture on document),
		// which would look for a toggle button of our menu and fail
		window.addEventListener('keydown', function (e) {
			if (!menu || ['Escape', 'ArrowDown', 'ArrowUp', 'ArrowLeft', 'ArrowRight'].indexOf(e.key) === -1) return;
			if (e.key !== 'Escape' && document.activeElement && document.activeElement.classList.contains('nuOmGeoInput')) return;   // the number fields step
			e.stopPropagation();
			e.preventDefault();
			var act = document.activeElement, inMenu = menu[0].contains(act);
			var list = menu.find('button:visible').toArray(), i = list.indexOf(act);
			if (e.key === 'Escape') {
				var sub = inMenu ? $(act).closest('.nuOmSubWrap.nuOmOpen') : $();
				if (sub.length) { sub.removeClass('nuOmOpen'); sub.find('> button').attr('aria-expanded', 'false').trigger('focus'); } else close();
			} else if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
				if (list.length) list[i < 0 ? 0 : (i + (e.key === 'ArrowDown' ? 1 : list.length - 1)) % list.length].focus();
			} else if (e.key === 'ArrowRight' && $(act).hasClass('nuOmHasSub')) {
				$(act).trigger('click');
			} else if (e.key === 'ArrowLeft') {
				var w = $(act).closest('.nuOmSub').closest('.nuOmSubWrap');
				if (w.length) { w.removeClass('nuOmOpen'); w.find('> button').attr('aria-expanded', 'false').trigger('focus'); }
			}
		}, true);
		$(window).on('scroll resize blur', close);
		$('body').addClass('nuObjMenuOn');
	})();

