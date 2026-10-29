/*
 * Design mode on the form page (0.8.662, index.php?p=form&f=…&r=…&design=1 – core/form/nudesign.php); since 0.8.670 the only way to
 * change a form (the former form builder's links lead here); on the list page (no r) it designs the list's columns.
 * The form runs as usual; nudesigner.js (the builder preview's designer) lays its overlays over the objects – select, Ctrl+click, box,
 * move, resize, arrow keys, Arrange panel, Delete – and reports through postMessage to this window. This file adds
 *   - the bar (draft status, Undo, Discard, Publish, Done),
 *   - the icon bar on the left (0.8.663): Structure (tabs → objects, search, drag to reorder / to another tab) and Add (the table's
 *     columns not on the form yet, every object type – click, or drag onto the form), Ctrl+D duplicates,
 *   - tabs on the form (0.8.663): double-click renames, "+" adds, drag reorders, right-click: rename / hide / move / delete,
 *   - the properties panel: Content · Behaviour (events dialog) · Layout · Style, the form's settings with nothing selected, the common
 *     settings of several objects (with the Arrange actions); on phones a bottom sheet with the sections as tabs.
 * Every change is saved through the builder API (index.php?p=builderapi): in the open draft, or in a new one started by the first change
 * (draft.start + preview), so users keep the published form until Publish. A form never published is changed directly. Changes the page
 * cannot show at once reload it; the selection, tab and scroll position come back (sessionStorage).
 */
(function () {
	'use strict';
	var N = window.nuNext;
	if (!N || !N.design || (N.mode !== 'edit' && N.mode !== 'browse')) return;
	var D = N.design, L = D.texts || {}, origin = window.location.origin;
	var BROWSE = N.mode === 'browse';  // the list (0.8.669): its columns are designed on the list page itself
	var selCol = '';                  // list: the selected column (zzzzsys_browse_id)
	if (window.NuFormatDesigner && D.format_designer) window.NuFormatDesigner.init(D.format_designer);
	var DR = D.draft;                 // open draft (NuFormDraft::info) or null
	var S = null;                     // builder state: form, tabs, objects, events
	var selIds = [];                  // selected objects (sob_all_id), from nudesigner.js
	var KEY = 'nunext-design:' + N.form.id;
	var INPUT_TYPES = ['text', 'nuDate', 'nuNumber', 'number', 'checkbox', 'button', 'date', 'datetime-local', 'time', 'email', 'tel', 'url', 'password', 'month', 'week', 'color', 'range', 'file', 'nuAutoNumber', 'nuScroll'];
	var TYPES = ['input', 'select', 'textarea', 'editor', 'lookup', 'calc', 'display', 'subform', 'html', 'word', 'run', 'image', 'chart', 'contentbox'];
	var VALUE_TYPES = ['input', 'select', 'textarea', 'editor', 'lookup', 'calc', 'display'];
	var EVENTS = ['onchange', 'onclick', 'onblur', 'onfocus', 'oninput', 'onkeydown', 'onkeyup', 'ondblclick', 'afterinsertrow', 'clickdelete'];
	var COLTYPES = ['text', 'longtext', 'int', 'bigint', 'decimal', 'date', 'datetime', 'time', 'bool', 'id', 'uuid'];
	var ICONS = {input: 'bi-input-cursor-text', select: 'bi-menu-down', textarea: 'bi-textarea-t', editor: 'bi-type', display: 'bi-eye', calc: 'bi-calculator',
		lookup: 'bi-search', subform: 'bi-table', html: 'bi-code-slash', chart: 'bi-bar-chart', word: 'bi-fonts', contentbox: 'bi-bounding-box', run: 'bi-play-btn', image: 'bi-image'};
	var SIZES = {textarea: [400, 60], editor: [700, 300], subform: [700, 200], contentbox: [600, 200], html: [400, 60], run: [150, 30], lookup: [100, 22], chart: [500, 300], image: [200, 100]};
	var open = {content: true, behaviour: true, layout: true, style: false};
	var adv = {};
	var missing = {};                 // object id → column proposal of the last save (the panel offers to add it)
	var phoneSec = 'content';         // phones: the section shown in the bottom sheet
	var phone = window.matchMedia ? window.matchMedia('(max-width: 767.98px)') : {matches: false};

	function t(k) { return L[k] || k; }
	function fmt(s) { var a = arguments, i = 1; return String(s).replace(/%(\d\$)?[sd]/g, function (m, pos) { return pos ? a[+pos[0]] : a[i++]; }); }
	function el(tag, attrs, kids) {
		var e = document.createElement(tag);
		Object.keys(attrs || {}).forEach(function (k) {
			var v = attrs[k];
			if (v === null || v === undefined || v === false) return;
			if (k === 'text') e.textContent = v;
			else if (k === 'class') e.className = v;
			else if (k.indexOf('on') === 0) e.addEventListener(k.substr(2), v);
			else e.setAttribute(k, v === true ? '' : v);
		});
		(kids || []).forEach(function (c) { if (c) e.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); });
		return e;
	}
	function icon(cls) { return el('i', {'class': 'bi ' + cls, 'aria-hidden': 'true'}); }
	function post(msg) { window.postMessage(msg, origin); }
	function nunext(o) { try { var j = JSON.parse(o.sob_all_json || '{}'); return (j && j.nunext) || {}; } catch (e) { return {}; } }
	function objByFid(fid) { return S ? S.objects.filter(function (x) { return x.sob_all_id === fid; })[0] : null; }
	function objById(id) { return S ? S.objects.filter(function (x) { return x.zzzzsys_object_id === id; })[0] : null; }
	function layout() { return (S && S.form && S.form.layout) || N.form.layout || 'absolute'; }
	function plain(s) { var d = document.createElement('div'); d.innerHTML = String(s || ''); return (d.textContent || '').trim(); }
	function isField(e) { return !!e && (/^(INPUT|TEXTAREA|SELECT)$/.test(e.tagName) || e.isContentEditable || !!(e.closest && e.closest('.ts-wrapper, .modal'))); }

	// ------------------------------------------------------------------ server
	function api(action, data, noDraft) {
		data = data || {};
		var body = Object.assign({action: action, form_id: N.form.id}, DR && !noDraft ? {draft_id: DR.id, rev: DR.rev} : {}, data);
		return fetch('index.php?p=builderapi', {
			method: 'POST', credentials: 'same-origin',
			headers: {'Content-Type': 'application/json', 'X-CSRF-Token': N.csrf},
			body: JSON.stringify(body)
		}).then(function (r) { return r.json().catch(function () { return {ok: false, error: 'HTTP ' + r.status}; }); }).then(function (j) {
			if (!j.ok && j.code === 'draft_rev') { reloadKeep(); throw new Error(j.error || ''); }
			if (j.draft) DR = j.draft;
			return j;
		});
	}
	function ensureDraft() {
		if (DR || D.unpublished) return Promise.resolve();
		return api('draft.start', {preview: true}, true).then(function (j) {
			if (!j.ok) throw new Error(j.error || 'Error');
			DR = j.draft;
		});
	}
	/** One change: in the draft (started when needed); reload = the page must be drawn again to show it. */
	function change(action, data, reload) {
		busy(true);
		return ensureDraft().then(function () { return api(action, data); }).then(function (j) {
			if (!j.ok) throw new Error(j.error || 'Error');
			if (j.form) S = j;
			if (j.object_id && j.missing_column) missing[j.object_id] = j.missing_column;
			if (reload) { refreshKeep(); return j; }
			busy(false); say(t('ds_saved'));
			renderBar(); renderPanel(); renderFly();
			return j;
		}).catch(function (e) { busy(false); if (e.message) say(e.message, true); renderBar(); throw e; });
	}
	/** Several changes one after the other (several objects, new objects …), then a reload. */
	function chain(steps, after) {
		busy(true);
		var p = ensureDraft();
		steps.forEach(function (st) { p = p.then(function () { return api(st[0], st[1]).then(function (j) { if (!j.ok) throw new Error(j.error || 'Error'); if (j.form) S = j; if (j.object_id && j.missing_column) missing[j.object_id] = j.missing_column; return j; }); }); });
		return p.then(function (j) { if (after) after(j); refreshKeep(); }).catch(function (e) { busy(false); say(e.message, true); renderBar(); });
	}

	// ------------------------------------------------------------------ reload with the same selection, tab and scroll position
	function activePane() { return document.querySelector('#nuEditForm .tab-pane.active'); }
	function activeTabId() { var p = activePane(); return p ? p.getAttribute('data-nu-tab-id') || '' : ''; }
	var keepTab = null;
	function reloadKeep() {
		try {
			window.sessionStorage.setItem(KEY, JSON.stringify({sel: selIds, tab: keepTab !== null ? keepTab : activeTabId(), y: window.scrollY, open: open, adv: adv,
				missing: missing, fly: flyKind, phoneSec: phoneSec, col: selCol}));
		} catch (e) { /* no storage */ }
		window.location.replace(D.self);
	}
	/**
	 * Draws the form's body again without reloading the page (0.8.665): the page is fetched, its #nuEditForm and client objects
	 * replace the current ones, the designer lays new overlays over them; the selection, tab and scroll position stay. The form's own
	 * nuOnLoad does not run again (objects it hid stay faded) – "Reload the form" in the bar draws everything anew. A changed layout or
	 * any problem falls back to a full reload.
	 */
	function refreshKeep() {
		if (BROWSE) { reloadKeep(); return Promise.resolve(); }   // the list: drawn again by the server
		var keepSel = selIds.slice(), tab = keepTab !== null ? keepTab : activeTabId(), y = window.scrollY;
		var hiddenIds = Array.prototype.map.call(document.querySelectorAll('#nuEditForm .nuDsHidden'), function (w) { return w.id.replace(/^nuw_/, ''); });
		keepTab = null;
		busy(true);
		return fetch(D.self, {credentials: 'same-origin'}).then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.text(); }).then(function (html) {
			var m = /window\.nuNext = (\{[\s\S]*?\});<\/script>/.exec(html);
			var doc = new DOMParser().parseFromString(html, 'text/html');
			var nf = doc.getElementById('nuEditForm'), cur = document.getElementById('nuEditForm');
			if (!m || !nf || !cur) throw new Error('page');
			var data = JSON.parse(m[1]);
			if (!data.form || data.form.layout !== N.form.layout) throw new Error('layout');
			['objects', 'subforms', 'objmenu', 'draft'].forEach(function (k) { if (k in data) N[k] = data[k]; });
			if (data.form) N.form = Object.assign(N.form, {description: data.form.description, code: data.form.code});
			cur.innerHTML = nf.innerHTML;
			if (!D.safe && typeof window.nuNextRunDeferred === 'function') { try { window.nuNextRunDeferred(cur); } catch (e) { /* form code */ } }
			if (N.fitButtons) { try { N.fitButtons(); } catch (e) { /* ignore */ } }
			hiddenIds.forEach(function (id) { var w = document.getElementById('nuw_' + id); if (w) markHidden(w); });
			showHidden();
			setupTabs();
			if (tab === '__last' && S && S.tabs && S.tabs.length) tab = S.tabs[S.tabs.length - 1].zzzzsys_tab_id;
			if (tab) selectTabById(tab);
			selIds = keepSel.filter(function (id) { return document.getElementById('nuw_' + id); });
			if (typeof window.nuDesignerRescan === 'function') window.nuDesignerRescan(selIds);
			window.scrollTo(0, y);
			busy(false);
			say(t('ds_saved'));
			renderBar(); renderPanel(); renderFly();
		}).catch(function () { reloadKeep(); });
	}
	function selectTabById(id) {
		var b = document.querySelector('#nuTabHolder .nuTab[data-nu-tab-id="' + (window.CSS && CSS.escape ? CSS.escape(id) : id) + '"]');
		if (b && window.nuSelectTab) window.nuSelectTab(+b.getAttribute('data-nu-tab'));
	}
	function restore() {
		var v = null;
		try { v = JSON.parse(window.sessionStorage.getItem(KEY) || 'null'); window.sessionStorage.removeItem(KEY); } catch (e) { v = null; }
		if (!v) return;
		if (v.open) open = v.open;
		if (v.adv) adv = v.adv;
		if (v.missing) missing = v.missing;
		if (v.phoneSec) phoneSec = v.phoneSec;
		if (v.col) selCol = v.col;
		if (v.tab) { selectTabById(v.tab); setTimeout(function () { if (activeTabId() !== v.tab) selectTabById(v.tab); }, 350); }   // after the form's own tab restore
		var ids = (v.sel || []).filter(function (id) { return document.getElementById('nuw_' + id); });
		if (ids[0]) post({nuBuilderSelect: ids[0]});
		if (v.fly) openFly(v.fly);
		if (v.y) window.scrollTo(0, v.y);
	}

	// ------------------------------------------------------------------ bar
	var bar = el('div', {'class': 'nuDsBar', id: 'nuDsBar', role: 'region', 'aria-label': t('ds_mode')});
	var status = el('span', {'class': 'nuDsStatus', id: 'nuDsStatus'});
	var msg = el('span', {'class': 'nuDsMsg', id: 'nuDsMsg', role: 'status', 'aria-live': 'polite'});
	var bUndo = el('button', {type: 'button', 'class': 'btn btn-outline-secondary', id: 'nuDsUndo', title: t('builder_draft_undo'), 'aria-label': t('builder_draft_undo'), onclick: undo}, [icon('bi-arrow-counterclockwise')]);
	var bDiscard = el('button', {type: 'button', 'class': 'btn btn-outline-secondary', id: 'nuDsDiscard', text: t('builder_draft_discard'), onclick: discard});
	var bChanges = el('button', {type: 'button', 'class': 'btn btn-outline-secondary', id: 'nuDsChangesBtn', title: t('ds_changes'), 'aria-label': t('ds_changes'), onclick: function () { openWork('changes'); }}, [icon('bi-file-diff')]);
	var bPublish = el('button', {type: 'button', 'class': 'btn btn-primary', id: 'nuDsPublish', text: t('builder_draft_publish_btn'), onclick: publish});
	var bDone = el('a', {'class': 'btn btn-outline-primary', id: 'nuDsDone', href: D.exit}, [icon('bi-check-lg me-1'), t('ds_done')]);
	bar.appendChild(el('span', {'class': 'badge nuDsBadge'}, [icon('bi-pencil-square me-1'), t('ds_mode')]));
	bar.appendChild(el('strong', {'class': 'nuDsTitle', text: N.form.description || N.form.code}));
	if (D.safe) bar.appendChild(el('span', {'class': 'badge nuDsSafeBadge', id: 'nuDsSafeBadge', title: t('ds_safe_tip')}, [icon('bi-shield-check me-1'), t('ds_safe_on')]));
	bar.appendChild(status);
	bar.appendChild(msg);
	bar.appendChild(el('span', {'class': 'nuDsSpacer'}));
	// phones: Structure and Add sit in the bar (no icon bar there)
	if (!BROWSE) bar.appendChild(el('button', {type: 'button', 'class': 'btn btn-outline-secondary nuDsBarTool', 'data-fly': 'structure', title: t('ds_structure'), 'aria-label': t('ds_structure'), onclick: function () { toggleFly('structure'); }}, [icon('bi-layers')]));
	if (!BROWSE) bar.appendChild(el('button', {type: 'button', 'class': 'btn btn-outline-secondary nuDsBarTool', 'data-fly': 'add', title: t('ds_add'), 'aria-label': t('ds_add'), onclick: function () { toggleFly('add'); }}, [icon('bi-plus-circle')]));
	// safe mode (0.8.667): the form without its own code – to fix a form whose JavaScript or Before Edit PHP breaks it
	bar.appendChild(el('a', {'class': 'btn btn-outline-secondary' + (D.safe ? ' active' : ''), id: 'nuDsSafe', href: D.safe_url, role: 'button', 'aria-pressed': D.safe ? 'true' : 'false',
		title: D.safe ? t('ds_safe_off') : t('ds_safe') + ' – ' + t('ds_safe_tip'), 'aria-label': D.safe ? t('ds_safe_off') : t('ds_safe')}, [icon('bi-shield-check')]));
	bar.appendChild(el('button', {type: 'button', 'class': 'btn btn-outline-secondary', id: 'nuDsReload', title: t('ds_reload'), 'aria-label': t('ds_reload'), onclick: function () { reloadKeep(); }}, [icon('bi-arrow-repeat')]));
	bar.appendChild(el('button', {type: 'button', 'class': 'btn btn-outline-secondary nuDsBarTool', 'data-work': 'code', title: t('ds_code'), 'aria-label': t('ds_code'), onclick: function () { openWork('code'); }}, [icon('bi-code-square')]));
	bar.appendChild(el('button', {type: 'button', 'class': 'btn btn-outline-secondary nuDsBarTool', 'data-work': 'history', title: t('ds_history'), 'aria-label': t('ds_history'), onclick: function () { openWork('history'); }}, [icon('bi-clock-history')]));
	bar.appendChild(bUndo);
	bar.appendChild(bChanges);
	bar.appendChild(bDiscard);
	bar.appendChild(bPublish);
	bar.appendChild(bDone);

	function renderBar() {
		if (D.unpublished) status.textContent = t('ds_status_unpublished');
		else if (DR) status.textContent = DR.mine === false ? fmt(t('ds_status_draft_by'), DR.created_by, DR.changes) : fmt(t(+DR.changes === 1 ? 'ds_status_draft_1' : 'ds_status_draft'), DR.changes);
		else status.textContent = t('ds_status_none');
		status.title = status.textContent;                       // cut with … on narrow screens
		bUndo.disabled = D.unpublished ? false : !(DR && DR.undo_count > 0);
		bDiscard.hidden = !DR;
		bChanges.hidden = !DR;
		bPublish.hidden = !D.can_publish || (!D.unpublished && !DR);     // publishing needs the admin right formpublish
		bPublish.textContent = D.unpublished ? t('builder_draft_publish_new') : t('builder_draft_publish_btn');
	}
	var msgT = null;
	function say(text, err) {
		clearTimeout(msgT);
		msg.textContent = text || '';
		msg.classList.toggle('nuDsMsgErr', !!err);
		if (!err) msgT = setTimeout(function () { msg.textContent = ''; }, 2500);
	}
	function busy(on) { document.documentElement.classList.toggle('nuDsBusy', !!on); }

	function undo() {
		busy(true);
		(DR ? api('draft.undo', {}) : api('history.undo', {}, true)).then(function (j) {
			if (!j.ok) throw new Error(j.error || 'Error');
			refreshKeep();
		}).catch(function (e) { busy(false); say(e.message, true); });
	}
	function confirmAsk(text, ok, label, cls) {
		if (typeof window.nuConfirmAsk === 'function') window.nuConfirmAsk(text, ok, label, cls);
		else if (window.confirm(text)) ok();
	}
	function discard() {
		if (!DR) return;
		confirmAsk(t('builder_draft_discard_confirm'), function () {
			api('draft.discard', {}).then(function (j) {
				if (!j.ok) throw new Error(j.error || 'Error');
				DR = null;
				refreshKeep();
			}).catch(function (e) { say(e.message, true); });
		}, t('builder_draft_discard'), 'btn btn-danger');
	}

	// ------------------------------------------------------------------ dialogs (Publish, events)
	function modal(id, title, body, buttons, wide) {
		var root = el('div', {'class': 'modal fade', id: id, tabindex: '-1', 'aria-hidden': 'true', 'aria-labelledby': id + 'T'}, [
			el('div', {'class': 'modal-dialog modal-dialog-centered modal-dialog-scrollable' + (wide ? ' modal-lg' : '')}, [el('div', {'class': 'modal-content'}, [
				el('div', {'class': 'modal-header py-2'}, [el('h2', {'class': 'modal-title fs-6', id: id + 'T', text: title}),
					el('button', {type: 'button', 'class': 'btn-close', 'data-bs-dismiss': 'modal', 'aria-label': t('cancel')})]),
				el('div', {'class': 'modal-body'}, body),
				el('div', {'class': 'modal-footer py-2'}, [el('button', {type: 'button', 'class': 'btn btn-outline-secondary', 'data-bs-dismiss': 'modal', text: t(buttons.length ? 'cancel' : 'close')})].concat(buttons))
			])])
		]);
		document.body.appendChild(root);
		return root;
	}
	function show(root) { if (window.nuBs && window.nuBs.ready()) window.nuBs.modal(root).show(); }
	function hide(root) { if (window.nuBs && window.nuBs.ready()) window.nuBs.modal(root).hide(); }

	// Publish: the change note; warnings → "Publish anyway", a conflict → the builder (it compares)
	var pubDlg = null;
	function publish() {
		if (D.unpublished) {
			api('form.publishnew', {}, true).then(function (j) { if (!j.ok) throw new Error(j.error || 'Error'); D.unpublished = false; refreshKeep(); })
				.catch(function (e) { say(e.message, true); });
			return;
		}
		if (!DR) return;
		if (!pubDlg) {
			pubDlg = {note: el('textarea', {'class': 'form-control', id: 'nuDsPubNote', rows: '3'}), info: el('div', {'class': 'nuDsPubInfo small mt-2', id: 'nuDsPubInfo'}),
				sum: el('div', {'class': 'nuDsPubSum', id: 'nuDsPubSum'})};
			pubDlg.ok = el('button', {type: 'button', 'class': 'btn btn-primary', id: 'nuDsPubOk', onclick: doPublish});
			pubDlg.root = modal('nuDsPublishDlg', t('ds_publish_title'), [el('label', {'class': 'form-label', 'for': 'nuDsPubNote', text: t('ds_publish_note')}), pubDlg.note,
				el('h3', {'class': 'h6 mt-3', text: t('builder_draft_summary')}), pubDlg.sum, pubDlg.info], [pubDlg.ok], true);
		}
		pubDlg.note.value = DR.note || '';
		pubDlg.info.textContent = '';
		pubDlg.ok.textContent = t('builder_draft_publish_btn');
		pubDlg.ok.className = 'btn btn-primary';
		pubDlg.warn = false;
		pubDlg.force = false;
		pubDlg.code = '';
		pubDlg.sum.innerHTML = '';
		pubDlg.sum.appendChild(el('div', {'class': 'text-body-secondary small', text: '…'}));
		api('draft.diff', {}).then(function (r) { pubDlg.sum.innerHTML = ''; if (r.ok) pubDlg.sum.appendChild(diffView(r.diff)); }).catch(function () { pubDlg.sum.innerHTML = ''; });
		if (window.nuBs && window.nuBs.ready()) show(pubDlg.root);
		else { var n = window.prompt(t('ds_publish_note')); if (n !== null) { pubDlg.note.value = n; doPublish(); } }
	}
	function doPublish() {
		var d = pubDlg;
		d.ok.disabled = true;
		api('draft.publish', {note: d.note.value, warnings_ok: d.warn, force: d.force, confirm_code: d.code}).then(function (j) {
			d.ok.disabled = false;
			d.info.textContent = '';
			if (j.ok) { DR = null; hide(d.root); refreshKeep(); return; }
			var list = function (head, items) {
				d.info.appendChild(el('div', {'class': 'fw-semibold', text: head}));
				d.info.appendChild(el('ul', {'class': 'mb-0'}, (items || []).map(function (x) { return el('li', {text: String(x)}); })));
			};
			if (j.conflict) {
				// the published form was changed after the draft started: overwrite only with the typed form code (as the builder)
				var code = el('input', {'class': 'form-control form-control-sm mt-2', id: 'nuDsPubCode', autocomplete: 'off', 'aria-label': t('builder_draft_overwrite')});
				code.addEventListener('input', function () { d.code = code.value; });
				d.info.appendChild(el('div', {'class': 'alert alert-danger small mb-0'}, [el('strong', {text: t('builder_draft_conflict')}), el('p', {'class': 'mb-1', text: t('builder_draft_conflict_text')}),
					diffView(j.changes), el('div', {'class': 'mt-2', text: fmt(t('builder_draft_overwrite_type'), (S && S.form.sfo_code) || N.form.code)}), code]));
				d.force = true;
				d.ok.textContent = t('builder_draft_overwrite');
				d.ok.className = 'btn btn-danger';
			}
			else if (j.warnings) { list(t('builder_draft_warnings'), j.warnings); d.warn = true; d.ok.textContent = t('builder_draft_warnings_ok'); }
			else if (j.errors) list(t('builder_draft_errors'), j.errors);
			else d.info.textContent = j.error || 'Error';
		}).catch(function (e) { d.ok.disabled = false; d.info.textContent = e.message; });
	}

	// The code window (0.8.665): SQL, JavaScript, HTML of a panel field in CodeMirror with the code editors' toolbar (NuCodeTools:
	// undo, search, format, full screen …) and – for SQL and HTML – the # menu of hash variables. OK = Ctrl+Enter / Ctrl+S.
	var cmLoad = null;
	function loadCM() {
		if (window.CodeMirror) return Promise.resolve();
		if (cmLoad) return cmLoad;
		var cm = D.cm || [];
		cmLoad = new Promise(function (ok, fail) {
			document.head.appendChild(el('link', {rel: 'stylesheet', href: cm[0]}));
			var js = el('script', {src: cm[1]});
			js.onload = function () { var ad = el('script', {src: cm[2]}); ad.onload = ok; ad.onerror = ok; document.head.appendChild(ad); };
			js.onerror = fail;
			document.head.appendChild(js);
		});
		return cmLoad;
	}
	function codeDialog(title, value, lang, onOk) {
		loadCM().then(function () {
			var old = document.getElementById('nuDsCodeDlg');
			if (old) old.remove();
			var ta = el('textarea', {'aria-label': title});
			var tools = el('span', {'class': 'nuDsCodeTools'}), hash = el('span', {'class': 'nuDsCodeHash'});
			var ok = el('button', {type: 'button', 'class': 'btn btn-primary', id: 'nuDsCodeOk', text: t('ok')});
			var root = modal('nuDsCodeDlg', title, [el('div', {'class': 'nuDsCodeBar'}, [tools, hash, el('span', {'class': 'badge text-bg-secondary ms-auto', text: lang})]), ta,
				el('div', {'class': 'small text-body-secondary mt-2', text: t('form_code_hint')})], [ok], false);
			root.querySelector('.modal-dialog').classList.add('modal-xl', 'modal-fullscreen-lg-down');
			var mode = {SQL: 'text/x-sql', JS: 'javascript', HTML: 'htmlmixed', CSS: 'css'}[lang] || null;
			var cm = window.CodeMirror.fromTextArea(ta, {mode: mode, lineNumbers: true, lineWrapping: true, indentWithTabs: true, tabSize: 4,
				theme: document.documentElement.getAttribute('data-bs-theme') === 'dark' ? 'default nuCmDark' : 'default'});
			cm.setValue(value || '');
			cm.clearHistory();
			var applied = false, start = value || '';
			var size = function () { cm.setSize(null, root.querySelector('.modal-dialog').classList.contains('modal-fullscreen') ? 'calc(100vh - 10rem)' : '60vh'); cm.refresh(); };
			var full = function (on) { root.querySelector('.modal-dialog').classList.toggle('modal-fullscreen', on); size(); return on; };
			if (window.NuCodeTools) window.NuCodeTools.attach(cm, tools, {lang: lang, db: N.db_type, fullscreen: full});
			if (window.NuHashVars && N.hashCatalog && (lang === 'SQL' || lang === 'HTML')) window.NuHashVars.attach(cm, hash, {catalog: N.hashCatalog, globals: N.globals});
			var apply = function () { applied = true; hide(root); if (cm.getValue() !== start) onOk(cm.getValue()); };
			cm.setOption('extraKeys', {'Ctrl-Enter': apply, 'Cmd-Enter': apply, 'Ctrl-S': apply, 'Cmd-S': apply});
			ok.addEventListener('click', apply);
			root.addEventListener('hide.bs.modal', function (e) { if (!applied && cm.getValue() !== start && !window.confirm(t('form_code_discard'))) e.preventDefault(); });
			root.addEventListener('shown.bs.modal', function () { size(); cm.focus(); });
			root.addEventListener('hidden.bs.modal', function () { root.remove(); });
			if (window.nuBs && window.nuBs.ready()) window.nuBs.modal(root, {backdrop: 'static'}).show();
		}, function () { say('CodeMirror', true); });
	}
	function codeButton(title, lang, get, set) {
		return el('button', {type: 'button', 'class': 'btn btn-sm btn-link nuDsCodeBtn', title: t('ds_code_edit'), 'aria-label': t('ds_code_edit') + ': ' + title,
			onclick: function () { codeDialog(title, get(), lang, set); }}, [icon('bi-arrows-angle-expand')]);
	}

	// Events of an object (stage 4): event + JavaScript per row, saved together
	var evDlg = null;
	function editEvents(o) {
		if (evDlg) evDlg.root.remove();
		var rows = el('div', {'class': 'nuDsEvRows', id: 'nuDsEvRows'});
		var addRow = function (ev) {
			var sel = el('select', {'class': 'form-select form-select-sm', 'aria-label': t('builder_event')});
			EVENTS.concat(ev.event && EVENTS.indexOf(ev.event) < 0 ? [ev.event] : []).forEach(function (x) { sel.appendChild(el('option', {value: x, text: x, selected: x === (ev.event || 'onchange') ? true : null})); });
			var js = el('textarea', {'class': 'form-control form-control-sm nuDsMono', rows: '3', spellcheck: 'false', 'aria-label': 'JavaScript'});
			js.value = ev.js || '';
			var r = el('div', {'class': 'nuDsEvRow'}, [sel, js, el('div', {'class': 'd-flex flex-column gap-1'}, [
				codeButton(o.sob_all_id + ' · ' + sel.value, 'JS', function () { return js.value; }, function (v) { js.value = v; }),
				el('button', {type: 'button', 'class': 'btn btn-sm btn-outline-danger', title: t('delete'), 'aria-label': t('delete'), onclick: function () { r.remove(); }}, [icon('bi-x-lg')])])]);
			rows.appendChild(r);
		};
		((S.events && S.events[o.zzzzsys_object_id]) || []).forEach(addRow);
		var ok = el('button', {type: 'button', 'class': 'btn btn-primary', id: 'nuDsEvSave', text: t('save'), onclick: function () {
			var events = Array.prototype.map.call(rows.querySelectorAll('.nuDsEvRow'), function (r) { return {event: r.querySelector('select').value, js: r.querySelector('textarea').value}; })
				.filter(function (x) { return x.js.trim() !== ''; });
			hide(evDlg.root);
			change('object.save', {object_id: o.zzzzsys_object_id, fields: {}, events: events}, true).catch(function () { /* said */ });
		}});
		evDlg = {root: modal('nuDsEventsDlg', t('builder_sec_events') + ' · ' + o.sob_all_id, [rows,
			el('button', {type: 'button', 'class': 'btn btn-sm btn-outline-primary mt-2', id: 'nuDsEvAdd', onclick: function () { addRow({}); }}, [icon('bi-plus-lg me-1'), t('builder_add_event')])], [ok], true)};
		show(evDlg.root);
	}

	// ------------------------------------------------------------------ icon bar + Structure / Add (stage 2)
	var rail = el('nav', {'class': 'nuDsRail', id: 'nuDsRail', 'aria-label': t('ds_tools')}, [
		BROWSE ? null : el('button', {type: 'button', 'class': 'nuDsRailBtn', id: 'nuDsRailStructure', 'data-fly': 'structure', title: t('ds_structure') + ' (Ctrl+K)', 'aria-label': t('ds_structure'), onclick: function () { toggleFly('structure'); }}, [icon('bi-layers')]),
		BROWSE ? null : el('button', {type: 'button', 'class': 'nuDsRailBtn', id: 'nuDsRailAdd', 'data-fly': 'add', title: t('ds_add'), 'aria-label': t('ds_add'), onclick: function () { toggleFly('add'); }}, [icon('bi-plus-circle')]),
		el('button', {type: 'button', 'class': 'nuDsRailBtn', id: 'nuDsRailCode', 'data-work': 'code', title: t('ds_code'), 'aria-label': t('ds_code'), onclick: function () { openWork('code'); }}, [icon('bi-code-square')]),
		el('button', {type: 'button', 'class': 'nuDsRailBtn', id: 'nuDsRailHistory', 'data-work': 'history', title: t('ds_history'), 'aria-label': t('ds_history'), onclick: function () { openWork('history'); }}, [icon('bi-clock-history')]),
		el('button', {type: 'button', 'class': 'nuDsRailBtn', id: 'nuDsRailForm', title: t('ds_form'), 'aria-label': t('ds_form'), onclick: function () { closeFly(); post({nuBuilderSelectNone: true}); selIds = []; selCol = ''; markCols(); renderPanel(); }}, [icon('bi-window')]),
		el('button', {type: 'button', 'class': 'nuDsRailBtn nuDsRailKeys', id: 'nuDsRailKeys', title: t('ds_keys_title') + ' (?)', 'aria-label': t('ds_keys_title'), onclick: function () { shortcutsDialog(); }}, [icon('bi-keyboard')])
	]);
	var fly = el('div', {'class': 'nuDsFly', id: 'nuDsFly', role: 'dialog', 'aria-modal': 'false', hidden: true});
	var flyKind = '', flyQ = '', tableCols = null;
	var flyTab = '', flyType = '', collapsedTabs = {};   // Structure (0.8.671): filters by tab and object type, folded tab sections
	function toggleFly(kind) { if (flyKind === kind) closeFly(); else openFly(kind); }
	function openFly(kind) {
		flyKind = kind;
		fly.hidden = false;
		document.documentElement.classList.add('nuDsFlyOpen');
		renderFly();
		var q = fly.querySelector('input[type=search]');
		if (q && !phone.matches) q.focus();
		if (kind === 'add' && tableCols === null && S && S.form.sfo_table) {
			tableCols = [];
			api('columns', {table: S.form.sfo_table}, true).then(function (j) { tableCols = j.ok ? j.columns : []; renderFly(); }).catch(function () { tableCols = []; });
		}
	}
	function closeFly() {
		flyKind = '';
		fly.hidden = true;
		document.documentElement.classList.remove('nuDsFlyOpen');
		renderFly();
	}
	function renderFly() {
		document.querySelectorAll('[data-fly]').forEach(function (b) { b.setAttribute('aria-pressed', b.getAttribute('data-fly') === flyKind ? 'true' : 'false'); });
		if (!flyKind || !S) return;
		fly.textContent = '';
		fly.setAttribute('aria-label', t(flyKind === 'add' ? 'ds_add' : 'ds_structure'));
		fly.appendChild(el('div', {'class': 'nuDsFlyHead'}, [el('strong', {text: flyKind === 'add' ? fmt(t('ds_add_to'), tabTitle(activeTabId())) : t('ds_structure')}),
			el('button', {type: 'button', 'class': 'btn-close', 'aria-label': t('cancel'), onclick: closeFly})]));
		if (flyKind === 'structure') renderStructure(); else renderAdd();
	}
	function tabTitle(id) { var tb = (S.tabs || []).filter(function (x) { return x.zzzzsys_tab_id === id; })[0]; return tb ? plain(tb.syt_title) : ''; }
	function tabIndex(id) { var b = document.querySelector('#nuTabHolder .nuTab[data-nu-tab-id="' + id + '"]'); return b ? +b.getAttribute('data-nu-tab') : -1; }
	function sorted(tabId) {
		return S.objects.filter(function (o) { return (o.sob_all_zzzzsys_tab_id || '') === tabId; }).sort(function (a, b) { return (+a.sob_all_order || 0) - (+b.sob_all_order || 0); });
	}

	function renderStructure() {
		var q = el('input', {type: 'search', 'class': 'form-control form-control-sm', id: 'nuDsStructQ', placeholder: t('ds_search_objects'), 'aria-label': t('ds_search_objects'), value: flyQ});
		q.addEventListener('input', function () { flyQ = q.value; var pos = q.selectionStart; renderFly(); var n = fly.querySelector('#nuDsStructQ'); if (n) { n.focus(); n.setSelectionRange(pos, pos); } });
		var allTabs = S.tabs && S.tabs.length ? S.tabs : [{zzzzsys_tab_id: '', syt_title: N.form.description}];
		if (flyTab && !allTabs.some(function (x) { return x.zzzzsys_tab_id === flyTab; })) flyTab = '';
		var used = {};
		S.objects.forEach(function (o) { used[o.sob_all_type] = 1; });
		if (flyType && !used[flyType]) flyType = '';
		var fTab = el('select', {'class': 'form-select form-select-sm', id: 'nuDsStructTab', 'aria-label': t('ds_filter_tab')},
			[el('option', {value: '', text: t('ds_filter_all_tabs')})].concat(allTabs.length > 1 ? allTabs.map(function (x) { return el('option', {value: x.zzzzsys_tab_id, text: plain(x.syt_title) || '–', selected: x.zzzzsys_tab_id === flyTab ? true : null}); }) : []));
		var fType = el('select', {'class': 'form-select form-select-sm', id: 'nuDsStructType', 'aria-label': t('ds_filter_type')},
			[el('option', {value: '', text: t('ds_filter_all_types')})].concat(TYPES.filter(function (x) { return used[x]; }).map(function (x) { return el('option', {value: x, text: t('bf_type_' + x), selected: x === flyType ? true : null}); })));
		fTab.addEventListener('change', function () { flyTab = fTab.value; renderFly(); });
		fType.addEventListener('change', function () { flyType = fType.value; renderFly(); });
		if (allTabs.length < 2) fTab.disabled = true;
		fly.appendChild(el('div', {'class': 'nuDsFlySearch'}, [q, el('div', {'class': 'nuDsFlyFilters'}, [fTab, fType])]));
		var list = el('div', {'class': 'nuDsTree', id: 'nuDsTree'});
		var needle = flyQ.trim().toLowerCase();
		var filtering = !!(needle || flyType);
		var drag = null;
		allTabs.forEach(function (tb) {
			if (flyTab && tb.zzzzsys_tab_id !== flyTab) return;
			var items = sorted(tb.zzzzsys_tab_id).filter(function (o) {
				return (!flyType || o.sob_all_type === flyType) && (!needle || o.sob_all_id.toLowerCase().indexOf(needle) >= 0 || plain(o.sob_all_label).toLowerCase().indexOf(needle) >= 0);
			});
			if (filtering && !items.length) return;
			// a tab section folds with a click on its title (a search or a type filter shows the matches anyway)
			var folded = !filtering && !!collapsedTabs[tb.zzzzsys_tab_id];
			var group = el('div', {'class': 'nuDsTreeTab' + (folded ? ' nuDsFolded' : ''), 'data-tab': tb.zzzzsys_tab_id}, [
				el('button', {type: 'button', 'class': 'nuDsTreeFold', 'aria-expanded': folded ? 'false' : 'true', 'data-fold': tb.zzzzsys_tab_id, title: t(folded ? 'ds_expand' : 'ds_collapse'),
					onclick: function () { collapsedTabs[tb.zzzzsys_tab_id] = !folded; renderFly(); }},
					[icon((folded ? 'bi-chevron-right' : 'bi-chevron-down') + ' me-1'), icon('bi-folder2 me-1'), el('span', {text: plain(tb.syt_title) || '–'})]),
				el('span', {'class': 'nuDsTreeCount', text: String(items.length)})]);
			list.appendChild(group);
			// drop on the tab title: the object moves to the end of that tab
			group.addEventListener('dragover', function (e) { if (drag) { e.preventDefault(); group.classList.add('nuDsDropOn'); } });
			group.addEventListener('dragleave', function () { group.classList.remove('nuDsDropOn'); });
			group.addEventListener('drop', function (e) { e.preventDefault(); group.classList.remove('nuDsDropOn'); if (drag) moveInStructure(drag, null, tb.zzzzsys_tab_id); });
			if (folded) return;
			items.forEach(function (o) {
				var acc = String(o.sob_all_access || '0');
				var row = el('button', {type: 'button', 'class': 'nuDsTreeRow', draggable: 'true', 'data-id': o.sob_all_id, 'aria-current': selIds.indexOf(o.sob_all_id) >= 0 ? 'true' : null,
					onclick: function () { if (tabIndex(tb.zzzzsys_tab_id) >= 0) selectTabById(tb.zzzzsys_tab_id); post({nuBuilderSelect: o.sob_all_id}); if (phone.matches) closeFly(); }}, [
					icon('bi-grip-vertical nuDsGrip'), icon((ICONS[o.sob_all_type] || 'bi-square') + ' nuDsTreeIcon'),
					el('span', {'class': 'nuDsTreeId', text: o.sob_all_id}),
					acc === '2' || acc === '3' ? el('span', {title: t('bf_access_' + acc)}, [icon('bi-eye-slash')]) : null,
					el('span', {'class': 'nuDsTreeType', text: t('bf_type_' + o.sob_all_type)})
				]);
				row.addEventListener('dragstart', function (e) { drag = o; e.dataTransfer.effectAllowed = 'move'; try { e.dataTransfer.setData('text/plain', o.sob_all_id); } catch (x) { /* ignore */ } row.classList.add('nuDsDragging'); });
				row.addEventListener('dragend', function () { drag = null; row.classList.remove('nuDsDragging'); });
				row.addEventListener('dragover', function (e) { if (drag && drag !== o) { e.preventDefault(); row.classList.add('nuDsDropBefore'); } });
				row.addEventListener('dragleave', function () { row.classList.remove('nuDsDropBefore'); });
				row.addEventListener('drop', function (e) { e.preventDefault(); row.classList.remove('nuDsDropBefore'); if (drag && drag !== o) moveInStructure(drag, o, tb.zzzzsys_tab_id); });
				list.appendChild(row);
			});
		});
		fly.appendChild(list);
		fly.appendChild(el('p', {'class': 'nuDsFlyFoot', text: t('ds_structure_hint')}));
	}
	// new order: the dragged object goes before "before" (null = end of the tab); another tab = its tab changes too
	function moveInStructure(o, before, tabId) {
		var ids = S.objects.slice().sort(function (a, b) { return (+a.sob_all_order || 0) - (+b.sob_all_order || 0); }).map(function (x) { return x.zzzzsys_object_id; });
		ids.splice(ids.indexOf(o.zzzzsys_object_id), 1);
		var at = before ? ids.indexOf(before.zzzzsys_object_id) : -1;
		if (at < 0) {
			var last = sorted(tabId).filter(function (x) { return x !== o; }).pop();
			at = last ? ids.indexOf(last.zzzzsys_object_id) + 1 : ids.length;
		}
		ids.splice(at, 0, o.zzzzsys_object_id);
		var steps = [];
		if ((o.sob_all_zzzzsys_tab_id || '') !== tabId) steps.push(['object.save', {object_id: o.zzzzsys_object_id, fields: {sob_all_zzzzsys_tab_id: tabId}}]);
		steps.push(['object.order', {ids: ids}]);
		selIds = [o.sob_all_id];
		keepTab = tabId;
		chain(steps);
	}

	function colToObject(c) {
		var ty = String(c.type || '').toLowerCase(), f = {sob_all_type: 'input', sob_input_type: 'text'};
		if (/text|blob/.test(ty) && !/^(tiny)?text$/.test(ty) || ty === 'longtext' || ty === 'mediumtext') { f.sob_all_type = 'textarea'; f.sob_input_type = ''; }
		else if (/^(tinyint|bit|bool)/.test(ty) && (+c.length === 1 || /bool|bit/.test(ty))) f.sob_input_type = 'checkbox';
		else if (/^date$/.test(ty) || /^datetime|^timestamp/.test(ty)) f.sob_input_type = 'nuDate';
		else if (c.numeric) f.sob_input_type = 'nuNumber';
		if (ty === 'text') { f.sob_all_type = 'textarea'; f.sob_input_type = ''; }
		return f;
	}
	function renderAdd() {
		var used = {};
		S.objects.forEach(function (o) { used[o.sob_all_id.toLowerCase()] = 1; });
		var pk = String((S.form && S.form.sfo_primary_key) || '').toLowerCase();
		var cols = (tableCols || []).filter(function (c) { return !used[c.name.toLowerCase()] && c.name.toLowerCase() !== pk && !c.auto; });
		if (S.form.sfo_table) {
			fly.appendChild(el('div', {'class': 'nuDsFlySub', text: fmt(t('ds_add_columns'), S.form.sfo_table)}));
			var box = el('div', {'class': 'nuDsColList', id: 'nuDsColList'});
			if (tableCols === null || (tableCols.length === 0 && cols.length === 0 && tableCols !== null && !tableCols.length)) box.appendChild(el('p', {'class': 'nuDsFlyFoot', text: tableCols === null ? '…' : t('ds_add_all_used')}));
			else if (!cols.length) box.appendChild(el('p', {'class': 'nuDsFlyFoot', text: t('ds_add_all_used')}));
			cols.forEach(function (c) {
				var f = colToObject(c);
				var b = el('button', {type: 'button', 'class': 'nuDsTreeRow', draggable: 'true', 'data-col': c.name, onclick: function () { addObject(f.sob_all_type, {id: c.name, input: f.sob_input_type}); }}, [
					icon('bi-plus-lg nuDsTreeIcon'), el('span', {'class': 'nuDsTreeId', text: c.name}), el('span', {'class': 'nuDsTreeType', text: c.type})]);
				dragSource(b, {type: f.sob_all_type, id: c.name, input: f.sob_input_type});
				box.appendChild(b);
			});
			fly.appendChild(box);
		}
		fly.appendChild(el('div', {'class': 'nuDsFlySub', text: t('ds_add_elements')}));
		var grid = el('div', {'class': 'nuDsElGrid', id: 'nuDsElGrid'});
		TYPES.forEach(function (ty) {
			var b = el('button', {type: 'button', 'class': 'nuDsEl', draggable: 'true', 'data-type': ty, onclick: function () { addObject(ty, {}); }}, [icon(ICONS[ty] || 'bi-square'), el('span', {text: t('bf_type_' + ty)})]);
			dragSource(b, {type: ty});
			grid.appendChild(b);
		});
		fly.appendChild(grid);
		fly.appendChild(el('p', {'class': 'nuDsFlyFoot', text: t('ds_add_hint')}));
	}
	var dragNew = null;
	function dragSource(b, spec) {
		b.addEventListener('dragstart', function (e) { dragNew = spec; e.dataTransfer.effectAllowed = 'copy'; try { e.dataTransfer.setData('text/plain', spec.id || spec.type); } catch (x) { /* ignore */ } });
		b.addEventListener('dragend', function () { dragNew = null; document.documentElement.classList.remove('nuDsDropping'); });
	}
	function uniqueId(base) {
		var ids = S.objects.map(function (x) { return x.sob_all_id; }), n = 1, id = base;
		while (ids.indexOf(id) >= 0) id = base + '_' + (++n);
		return id;
	}
	function bottomOf(tabId) {
		return S.objects.filter(function (x) { return (x.sob_all_zzzzsys_tab_id || '') === tabId && x.sob_all_type !== 'contentbox'; })
			.reduce(function (m, x) { return Math.max(m, (+x.sob_all_top || 0) + (+x.sob_all_height || 0)); }, 0);
	}
	function labelOf(name) { return String(name).replace(/^[a-z]{2,5}_/, '').replace(/_/g, ' ').replace(/\b\w/g, function (m) { return m.toUpperCase(); }); }
	/** A new object on the open tab: at the bottom, or where it was dropped (absolute layout). */
	function addObject(type, spec, pos) {
		var tab = activeTabId() || ((S.tabs || [])[0] || {}).zzzzsys_tab_id || '';
		var size = SIZES[type] || [250, 22];
		var maxOrder = sorted(tab).reduce(function (m, x) { return Math.max(m, +x.sob_all_order || 0); }, 0);
		var fid = spec.id ? uniqueId(spec.id) : uniqueId(type === 'contentbox' ? 'box' : 'new_' + type);
		var input = spec.input || (type === 'input' ? 'text' : '');
		var fields = {sob_all_type: type, sob_all_id: fid, sob_all_label: spec.id ? labelOf(spec.id) : t('bf_type_' + type),
			sob_all_zzzzsys_tab_id: tab, sob_all_order: maxOrder + 10, sob_all_top: pos ? pos.top : bottomOf(tab) + 12, sob_all_left: pos ? pos.left : 170,
			sob_all_width: input === 'checkbox' ? 30 : (input === 'nuDate' ? 120 : size[0]), sob_all_height: size[1], sob_all_align: 'left', sob_input_type: input,
			sob_subform_add: type === 'subform' ? '1' : '', sob_subform_delete: type === 'subform' ? '1' : '', sob_subform_type: type === 'subform' ? 'g' : '',
			sob_run_method: type === 'run' ? 'b' : '', sob_run_type: type === 'run' ? 'F' : '', sob_run_target: type === 'run' ? '0' : '', sob_lookup_description_width: type === 'lookup' ? '200' : ''};
		selIds = [fid];
		keepTab = tab;
		chain([['object.save', {object_id: '', fields: fields}]]);
	}
	// drop a new object onto the open tab (absolute layout: at the drop point)
	function dropTargets() {
		var form = document.getElementById('nuEditForm');
		if (!form) return;
		form.addEventListener('dragover', function (e) { if (dragNew) { e.preventDefault(); document.documentElement.classList.add('nuDsDropping'); } });
		form.addEventListener('dragleave', function (e) { if (e.target === form) document.documentElement.classList.remove('nuDsDropping'); });
		form.addEventListener('drop', function (e) {
			if (!dragNew) return;
			e.preventDefault();
			document.documentElement.classList.remove('nuDsDropping');
			var spec = dragNew;
			dragNew = null;
			var pane = activePane(), pos = null;
			if (pane && layout() === 'absolute') {
				var r = pane.getBoundingClientRect();
				pos = {top: Math.max(0, Math.round((e.clientY - r.top) / 5) * 5), left: Math.max(0, Math.round((e.clientX - r.left - 120) / 5) * 5)};
			}
			addObject(spec.type, spec, pos);
		});
	}

	// Ctrl+D (⌘D): the selected objects are duplicated, with their events, below the originals
	function duplicate(objs) {
		var steps = [], ids = [];
		objs.forEach(function (o) {
			var fields = {};
			Object.keys(o).forEach(function (k) { if (k.indexOf('sob_') === 0 && k !== 'sob_all_json' && k !== 'sob_json' && k !== 'sob_all_zzzzsys_form_id') fields[k] = o[k]; });
			fields.sob_all_id = uniqueId(o.sob_all_id);
			while (ids.indexOf(fields.sob_all_id) >= 0) fields.sob_all_id = fields.sob_all_id + '_2';
			ids.push(fields.sob_all_id);
			fields.sob_all_top = (+o.sob_all_top || 0) + (+o.sob_all_height || 20) + 8;
			fields.sob_all_order = (+o.sob_all_order || 0) + 1;
			steps.push(['object.save', {object_id: '', fields: fields, events: ((S.events && S.events[o.zzzzsys_object_id]) || []).map(function (e) { return {event: e.event, js: e.js}; })}]);
		});
		if (!steps.length) return;
		selIds = ids.slice(0, 1);
		chain(steps);
	}

	// ------------------------------------------------------------------ tabs on the form (stage 3)
	function tabRows() { return (S.tabs || []).map(function (x) { return {id: x.zzzzsys_tab_id, title: x.syt_title, access: String(x.syt_access || '0')}; }); }
	function saveTabs(rows, focusId) { keepTab = focusId !== undefined ? focusId : activeTabId(); return change('tabs.save', {tabs: rows}, true).catch(function () { /* said */ }); }
	function setupTabs() {
		var holder = document.getElementById('nuTabHolder');
		if (!holder || !S || !(S.tabs || []).length) return;
		holder.classList.remove('d-none');
		holder.classList.add('nuDsTabs');
		var drag = null;
		holder.querySelectorAll('.nuTab').forEach(function (b) {
			var li = b.parentElement, id = b.getAttribute('data-nu-tab-id');
			var row = (S.tabs || []).filter(function (x) { return x.zzzzsys_tab_id === id; })[0];
			if (!row) return;
			if (li.style.display === 'none' || String(row.syt_access) === '2' || String(row.syt_access) === '3') {
				li.style.display = '';
				li.classList.add('nuDsTabHidden');
				b.insertBefore(icon('bi-eye-slash me-1'), b.firstChild);
				b.title = t('bf_access_' + String(row.syt_access || '2'));
			}
			b.setAttribute('title', (b.title ? b.title + ' · ' : '') + t('ds_tab_hint'));
			li.draggable = true;
			b.addEventListener('dblclick', function (e) { e.preventDefault(); renameTab(b, row); });
			b.addEventListener('contextmenu', function (e) { e.preventDefault(); tabMenu(e, b, row); });
			li.addEventListener('dragstart', function (e) { drag = id; e.dataTransfer.effectAllowed = 'move'; try { e.dataTransfer.setData('text/plain', id); } catch (x) { /* ignore */ } li.classList.add('nuDsDragging'); });
			li.addEventListener('dragend', function () { drag = null; li.classList.remove('nuDsDragging'); });
			li.addEventListener('dragover', function (e) { if (drag && drag !== id) { e.preventDefault(); li.classList.add('nuDsDropBefore'); } });
			li.addEventListener('dragleave', function () { li.classList.remove('nuDsDropBefore'); });
			li.addEventListener('drop', function (e) {
				e.preventDefault();
				li.classList.remove('nuDsDropBefore');
				if (!drag || drag === id) return;
				var rows = tabRows(), from = rows.filter(function (x) { return x.id === drag; })[0];
				rows.splice(rows.indexOf(from), 1);
				rows.splice(rows.map(function (x) { return x.id; }).indexOf(id), 0, from);
				saveTabs(rows, drag);
			});
		});
		// "+" next to the tabs, outside the tab list (a tablist holds tabs only)
		var add = el('button', {type: 'button', 'class': 'btn btn-sm nuDsTabAdd', id: 'nuDsTabAdd', title: t('ds_tab_add'), 'aria-label': t('ds_tab_add'), onclick: function () {
			var rows = tabRows();
			rows.push({id: '', title: t('ds_tab_new'), access: '0'});
			keepTab = '__last';
			change('tabs.save', {tabs: rows}, true).catch(function () { /* said */ });
		}}, [icon('bi-plus-lg')]);
		var wrap = el('div', {'class': 'nuDsTabsWrap'});
		holder.parentNode.insertBefore(wrap, holder);
		wrap.appendChild(holder);
		wrap.appendChild(add);
	}
	function renameTab(b, row) {
		if (!D.ga) { say(t('ds_tabs_ga'), true); return; }      // only the globeadmin renames or deletes tabs (builder API, 0.8.247)
		if (b.querySelector('input')) return;
		var old = plain(row.syt_title);
		var inp = el('input', {type: 'text', 'class': 'form-control form-control-sm nuDsTabInput', value: old, 'aria-label': t('ds_tab_rename'), maxlength: '200'});
		var keep = b.innerHTML;
		b.textContent = '';
		b.appendChild(inp);
		inp.focus();
		inp.select();
		var done = false;
		var finish = function (ok) {
			if (done) return;
			done = true;
			var v = inp.value.trim();
			if (!ok || v === '' || v === old) { b.innerHTML = keep; return; }
			saveTabs(tabRows().map(function (x) { return x.id === row.zzzzsys_tab_id ? Object.assign(x, {title: v}) : x; }), row.zzzzsys_tab_id);
		};
		inp.addEventListener('keydown', function (e) { e.stopPropagation(); if (e.key === 'Enter') { e.preventDefault(); finish(true); } if (e.key === 'Escape') { e.preventDefault(); finish(false); } });
		inp.addEventListener('blur', function () { finish(true); });
		inp.addEventListener('click', function (e) { e.stopPropagation(); });
	}
	var menuEl = null;
	function closeMenu() { if (menuEl) { menuEl.remove(); menuEl = null; } }
	function tabMenu(e, b, row) {
		closeMenu();
		var rows = tabRows(), i = rows.map(function (x) { return x.id; }).indexOf(row.zzzzsys_tab_id), acc = String(row.syt_access || '0');
		var item = function (ic, text, fn, cls, disabled) {
			return el('li', {}, [el('button', {type: 'button', 'class': 'dropdown-item' + (cls ? ' ' + cls : ''), disabled: disabled ? true : null, onclick: function () { closeMenu(); fn(); }}, [icon(ic + ' me-2'), text])]);
		};
		var move = function (d) { var r = tabRows(), x = r.splice(i, 1)[0]; r.splice(i + d, 0, x); saveTabs(r, row.zzzzsys_tab_id); };
		menuEl = el('ul', {'class': 'dropdown-menu show nuDsMenu', id: 'nuDsTabMenu', role: 'menu'}, [
			item('bi-pencil', t('ds_tab_rename'), function () { renameTab(b, row); }, '', !D.ga),
			item(acc === '0' ? 'bi-eye-slash' : 'bi-eye', t(acc === '0' ? 'ds_tab_hide' : 'ds_tab_show'), function () {
				saveTabs(tabRows().map(function (x) { return x.id === row.zzzzsys_tab_id ? Object.assign(x, {access: acc === '0' ? '2' : '0'}) : x; }), row.zzzzsys_tab_id);
			}),
			acc !== '3' ? item('bi-person-lock', t('ds_tab_hide_users'), function () {
				saveTabs(tabRows().map(function (x) { return x.id === row.zzzzsys_tab_id ? Object.assign(x, {access: '3'}) : x; }), row.zzzzsys_tab_id);
			}) : null,
			item('bi-arrow-left', t('ds_tab_left'), function () { move(-1); }, '', i <= 0),
			item('bi-arrow-right', t('ds_tab_right'), function () { move(1); }, '', i >= rows.length - 1),
			el('li', {}, [el('hr', {'class': 'dropdown-divider'})]),
			item('bi-trash', t('ds_tab_delete'), function () {
				var n = sorted(row.zzzzsys_tab_id).length;
				confirmAsk(fmt(t(n ? 'ds_tab_delete_confirm_n' : 'ds_tab_delete_confirm'), plain(row.syt_title), n, plain((S.tabs[0] === row ? S.tabs[1] : S.tabs[0] || {}).syt_title || '')), function () {
					saveTabs(tabRows().filter(function (x) { return x.id !== row.zzzzsys_tab_id; }), (S.tabs[0] === row ? (S.tabs[1] || {}).zzzzsys_tab_id : S.tabs[0].zzzzsys_tab_id) || '');
				}, t('delete'), 'btn btn-danger');
			}, 'text-danger', rows.length < 2 || !D.ga),
			D.ga ? null : el('li', {}, [el('span', {'class': 'dropdown-item-text small text-body-secondary nuDsMenuNote', text: t('ds_tabs_ga')})])
		]);
		document.body.appendChild(menuEl);
		var w = menuEl.offsetWidth, h = menuEl.offsetHeight;
		menuEl.style.left = Math.min(e.clientX, window.innerWidth - w - 8) + 'px';
		menuEl.style.top = Math.min(e.clientY, window.innerHeight - h - 8) + 'px';
		var first = menuEl.querySelector('button:not([disabled])');
		if (first) first.focus();
	}
	document.addEventListener('pointerdown', function (e) { if (menuEl && !menuEl.contains(e.target)) closeMenu(); }, true);

	// ------------------------------------------------------------------ properties panel
	var panel = el('aside', {'class': 'nuDsPanel', id: 'nuDsPanel', 'aria-label': t('ds_mode')});
	var sections = [];

	/** A section of the panel (collected; renderPanel lays them out – one below the other, on phones as tabs). */
	function section(key, title, rows, advRows) {
		sections.push({key: key, title: title, rows: (rows || []).filter(Boolean), adv: (advRows || []).filter(Boolean)});
	}
	function drawSection(s, tabbed) {
		var isOpen = tabbed || open[s.key] !== false;
		var head = tabbed ? null : el('button', {type: 'button', 'class': 'nuDsSecHead', 'aria-expanded': isOpen ? 'true' : 'false', 'data-sec': s.key,
			onclick: function () { open[s.key] = !isOpen; renderPanel(); }}, [el('span', {text: s.title}), icon(isOpen ? 'bi-chevron-up' : 'bi-chevron-down')]);
		var body = el('div', {'class': 'nuDsSecBody', 'data-sec-body': s.key});
		if (isOpen) {
			s.rows.forEach(function (r) { body.appendChild(r); });
			if (s.adv.length) {
				body.appendChild(el('button', {type: 'button', 'class': 'nuDsAdv', 'data-adv': s.key, onclick: function () { adv[s.key] = !adv[s.key]; renderPanel(); }},
					[icon(adv[s.key] ? 'bi-dash' : 'bi-plus'), adv[s.key] ? t('ds_fewer') : t('ds_more')]));
				if (adv[s.key]) s.adv.forEach(function (r) { body.appendChild(r); });
			}
		}
		return el('section', {'class': 'nuDsSec' + (tabbed ? ' nuDsSecTabbed' : '')}, [head, isOpen ? body : null]);
	}
	var fieldNo = 0;
	/** A field of the panel: kind text | number | textarea | select | check | ro; save(value) is called on change. */
	function field(label, kind, value, save, opt) {
		opt = opt || {};
		var id = 'nuDsF' + (++fieldNo), input;
		value = value === null || value === undefined ? '' : String(value);
		if (kind === 'format') {           // saved + built-in formats, "+" new, pencil edits (format designer, 0.8.669)
			kind = 'select';
			opt.options = formatOptions(opt.letters || 'DNT', value);
			opt.after = formatButtons(opt.letters || 'DNT', function () { return input.value; }, function (v) { input.value = v; save(v); });
		}
		if (kind === 'select') {
			input = el('select', {'class': 'form-select form-select-sm', id: id, 'data-key': opt.key || null});
			var opts = opt.options || [];
			if (!opts.some(function (o) { return String(o[0]) === value; })) opts = [[value, value === '' ? (opt.empty || t('ds_mixed')) : value]].concat(opts);
			opts.forEach(function (o) { input.appendChild(el('option', {value: o[0], text: o[1], selected: String(o[0]) === value ? true : null})); });
		} else if (kind === 'textarea') {
			input = el('textarea', {'class': 'form-control form-control-sm' + (opt.mono ? ' nuDsMono' : ''), id: id, rows: opt.rows || '3', spellcheck: opt.mono ? 'false' : null, 'data-key': opt.key || null, placeholder: opt.ph || null});
			input.value = value;
		} else if (kind === 'check') {
			input = el('input', {type: 'checkbox', 'class': 'form-check-input', id: id, role: 'switch', checked: value === '1' ? true : null, 'data-key': opt.key || null});
			var wrapC = el('div', {'class': 'form-check form-switch nuDsField'}, [input, el('label', {'class': 'form-check-label', 'for': id, text: label})]);
			input.addEventListener('change', function () { save(input.checked ? '1' : '0'); });
			return wrapC;
		} else {
			input = el('input', {type: kind === 'number' ? 'number' : 'text', 'class': 'form-control form-control-sm' + (opt.mono ? ' nuDsMono' : ''), id: id, value: value,
				readonly: kind === 'ro' ? true : null, 'data-key': opt.key || null, placeholder: opt.ph || null, list: opt.list || null});
		}
		// live (0.8.664): the form shows the value while typing; Esc puts the saved value back (nothing saved)
		if (opt.live && kind !== 'ro' && kind !== 'select') {
			input.addEventListener('input', function () { opt.live(input.value); });
			input.addEventListener('keydown', function (e) {
				if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); input.value = value; opt.live(value); input.blur(); }
				if (e.key === 'Enter' && input.tagName === 'INPUT') { e.preventDefault(); input.blur(); }
			});
		}
		if (kind !== 'ro') input.addEventListener('change', function () {
			var v = input.value;
			if (opt.check && !opt.check(v)) { input.classList.add('is-invalid'); say(opt.err || 'Error', true); return; }
			input.classList.remove('is-invalid');
			if (v !== value) save(v);
		});
		var lab = el('label', {'class': 'form-label nuDsLabel', 'for': id, text: label});
		if (opt.tip) lab = el('div', {'class': 'nuDsLabelRow'}, [lab, el('span', {'class': 'nuInfoTip ms-1', tabindex: '0', role: 'img', 'data-bs-toggle': 'tooltip', 'data-bs-custom-class': 'nuTipWide',
			'aria-label': opt.tip, title: opt.tip}, [icon('bi-info-circle')])]);
		if (opt.attr && window.nuAttributeEditor) {     // HTML attributes: the attribute editor (assets/js/nuattreditor.js)
			var inA = input;
			input = el('div', {'class': 'input-group input-group-sm'}, [inA, el('button', {type: 'button', 'class': 'btn btn-outline-secondary', id: 'nuDsAttrBtn', title: t('builder_attr_title'), 'aria-label': t('builder_attr_title'),
				onclick: function () { window.nuAttributeEditor.open(inA.value, function (v) { inA.value = v; inA.dispatchEvent(new Event('change')); }, L); }}, [icon('bi-sliders')])]);
		}
		// code fields (0.8.665): a button next to the label opens the code window
		if (opt.code) lab = el('div', {'class': 'nuDsLabelRow'}, [lab, codeButton(label, opt.code, function () { return input.value; },
			function (v) { input.value = v; input.dispatchEvent(new Event('change')); })]);
		var row = el('div', {'class': 'nuDsField' + (opt.half ? ' nuDsHalf' : '')}, [lab, input]);
		if (opt.tip && window.nuBs && window.nuBs.tooltip) setTimeout(function () { var tip = row.querySelector('.nuInfoTip'); if (tip && tip.isConnected) window.nuBs.tooltip(tip); }, 0);
		if (opt.after) row.appendChild(opt.after);
		if (opt.search && window.TomSelect && kind === 'select') {
			// searchable list of forms / procedures / reports (as the builder, 0.8.536); after the field is in the panel
			setTimeout(function () { if (!input.tomselect && input.isConnected) new window.TomSelect(input, {create: false, maxOptions: null, searchField: ['text'], dropdownParent: 'body'}); }, 0);
		}
		return row;
	}
	function saveObj(o, fields, reload) { return change('object.save', {object_id: o.zzzzsys_object_id, fields: fields}, reload !== false).catch(function () { /* said */ }); }
	function options(list, filter, none) {
		return (none === false ? [] : [['', t('please_select')]]).concat((list || []).filter(function (f) { return !filter || filter.indexOf(f[2]) >= 0; }).map(function (f) { return [f[0], f[1]]; }));
	}

	// formats (0.8.669, as the builder's format picker): saved ones first, then the built-ins; "+" creates one, the pencil edits it
	var FMT_TYPES = {D: 'Date', N: 'Number', T: 'Text'};
	function formatOptions(letters, value) {
		var list = (D.formats_all || []).filter(function (f) { return letters.indexOf(String(f.value).charAt(0)) >= 0; });
		var out = [['', '—']].concat(list.map(function (f) { return [f.value, f.value + (f['default'] ? ' ★' : '') + (f.description ? ' – ' + f.description : '')]; }));
		if (value && !list.some(function (f) { return f.value === value; })) out.push([value, value]);
		return out;
	}
	function formatButtons(letters, get, set) {
		var rights = (D.format_designer || {}).rights || {}, FD = window.NuFormatDesigner;
		var types = letters.split('').map(function (l) { return FMT_TYPES[l]; });
		var done = function (key) { return function (f, all) { if (all) D.formats_all = all; set(f && f.key !== undefined ? f.key : key); renderPanel(); }; };
		return el('div', {'class': 'nuDsFmtBtns'}, [
			el('button', {type: 'button', 'class': 'btn btn-sm btn-outline-secondary', 'data-ds-fmt': 'new', title: t('builder_fmt_new'), 'aria-label': t('builder_fmt_new'), disabled: !FD || !rights.create ? true : null,
				onclick: function () { FD.open({types: types, type: FMT_TYPES[(get() || letters).charAt(0)], onSaved: done('')}); }}, [icon('bi-plus-lg')]),
			el('button', {type: 'button', 'class': 'btn btn-sm btn-outline-secondary', 'data-ds-fmt': 'edit', title: t('builder_fmt_edit'), 'aria-label': t('builder_fmt_edit'), disabled: !FD ? true : null,
				onclick: function () {
					var f = (D.formats_all || []).filter(function (x) { return x.value === get(); })[0];
					if (!f) return;
					if (f.saved) FD.open({id: f.id, onSaved: done(''), onDeleted: function (all) { D.formats_all = all; set(''); renderPanel(); }});
					else FD.open({types: types, type: FMT_TYPES[f.value.charAt(0)], format: f.value.substring(2), onSaved: done('')});
				}}, [icon('bi-pencil')])]);
	}

	function renderPanel() {
		if (!S) return;
		panel.textContent = '';
		sections = [];
		var objs = BROWSE ? [] : selIds.map(objByFid).filter(Boolean);
		if (BROWSE && colById(selCol)) renderColumn(colById(selCol));
		else if (BROWSE) { renderForm(); columnsSection(); }
		else if (!objs.length) renderForm();
		else if (objs.length === 1) renderObject(objs[0]);
		else renderMany(objs);
		var footEl = panel.querySelector('.nuDsFoot');
		if (footEl) footEl.remove();
		if (phone.matches && sections.length > 1) {
			if (!sections.some(function (s) { return s.key === phoneSec; })) phoneSec = sections[0].key;
			panel.appendChild(el('div', {'class': 'nuDsSecTabs', role: 'tablist', id: 'nuDsSecTabs'}, sections.map(function (s) {
				return el('button', {type: 'button', role: 'tab', 'class': 'nuDsSecTab', 'aria-selected': s.key === phoneSec ? 'true' : 'false', 'data-sec-tab': s.key, text: s.title,
					onclick: function () { phoneSec = s.key; renderPanel(); }});
			})));
			sections.filter(function (s) { return s.key === phoneSec; }).forEach(function (s) { panel.appendChild(drawSection(s, true)); });
		} else sections.forEach(function (s) { panel.appendChild(drawSection(s, false)); });
		if (footEl) panel.appendChild(footEl);
	}

	function head(kicker, title, iconCls, sub, buttons) {
		return el('div', {'class': 'nuDsHead'}, [
			iconCls ? el('span', {'class': 'nuDsIcon'}, [icon(iconCls)]) : null,
			el('div', {'class': 'nuDsHeadText'}, [el('div', {'class': 'nuDsKicker', text: kicker}), el('div', {'class': 'nuDsName', text: title}), sub ? el('div', {'class': 'nuDsSub', text: sub}) : null]),
			buttons ? el('div', {'class': 'nuDsHeadBtns'}, buttons) : null
		]);
	}
	function foot(kids) { panel.appendChild(el('div', {'class': 'nuDsFoot'}, kids)); }

	function renderForm() {
		var f = S.form;
		panel.appendChild(head(t('ds_form'), f.sfo_description || f.sfo_code || '', 'bi-window', ''));
		var fsave = function (key, reload) { return function (v) { var x = {}; x[key] = v; change('form.save', {fields: x}, false).then(function () { if (reload) reloadKeep(); }).catch(function () { /* said */ }); }; };
		if (!D.userhome) {      // nuBuilder Forte's User Home: its form row belongs to Forte (only tabs, objects and Before Edit are edited)
			section('content', t('ds_sec_content'), [
				field(t('builder_description'), 'text', f.sfo_description, function (v) { change('form.save', {fields: {sfo_description: v}}, true).catch(function () { /* said */ }); }, {key: 'sfo_description'}),
				el('div', {'class': 'nuDsGrid2'}, [
					field(t('builder_code'), 'text', f.sfo_code, fsave('sfo_code'), {key: 'sfo_code', mono: true}),
					field(t('builder_group'), 'text', f.sfo_group, fsave('sfo_group'), {key: 'sfo_group'})]),
				el('div', {'class': 'nuDsGrid2'}, [
					field(t('builder_type'), 'select', f.sfo_type, fsave('sfo_type', true), {key: 'sfo_type', options: ['browseedit', 'browse', 'edit', 'launch', 'subform'].map(function (x) { return [x, t('form_type_' + x)]; })}),
					field(t('builder_status'), 'select', String(f.sfo_status === null || f.sfo_status === undefined || f.sfo_status === '' ? '1' : f.sfo_status), fsave('sfo_status', true),
						{key: 'sfo_status', options: [['1', t('builder_status_1')], ['0', t('builder_status_0')], ['3', t('builder_status_3')]]})])
			], [
				field(t('builder_breadcrumb'), 'text', f.sfo_breadcrumb_title, fsave('sfo_breadcrumb_title', true), {key: 'sfo_breadcrumb_title', mono: true, tip: t('builder_breadcrumb_help')})
			]);
			section('data', t('ds_sec_data'), [
				el('div', {'class': 'nuDsGrid2'}, [
					field(t('builder_table'), 'text', f.sfo_table, fsave('sfo_table', true), {key: 'sfo_table', mono: true, list: 'nuDsTables'}),
					field(t('builder_primary_key'), 'text', f.sfo_primary_key, fsave('sfo_primary_key', true), {key: 'sfo_primary_key', mono: true})]),
				tablesList()
			]);
			if (['browse', 'browseedit'].indexOf(f.sfo_type) >= 0) section('list', t('ds_sec_list'), [
				el('div', {'class': 'nuDsGrid2'}, [
					field(t('builder_rows_per_page'), 'number', f.sfo_browse_rows_per_page, fsave('sfo_browse_rows_per_page', BROWSE), {key: 'sfo_browse_rows_per_page'}),
					field(t('builder_redirect'), 'select', f.sfo_browse_redirect_form_id || '', fsave('sfo_browse_redirect_form_id'), {key: 'sfo_browse_redirect_form_id', search: true, options: options(D.forms, ['edit', 'browseedit'])})]),
				el('div', {'class': 'nuDsField'}, [el('button', {type: 'button', 'class': 'btn btn-sm btn-outline-secondary', id: 'nuDsBrowseSql', onclick: function () { W.item = 'sfo_browse_sql'; openWork('code'); }},
					[icon('bi-filetype-sql me-1'), t('builder_browse_sql')])]),
				!BROWSE && D.list_url ? el('div', {'class': 'nuDsField'}, [el('a', {'class': 'btn btn-sm btn-outline-secondary', id: 'nuDsDesignList', href: D.list_url}, [icon('bi-table me-1'), t('ds_design_list')])]) : null,
				BROWSE && D.edit_url ? el('div', {'class': 'nuDsField'}, [el('a', {'class': 'btn btn-sm btn-outline-secondary', id: 'nuDsDesignEdit', href: D.edit_url}, [icon('bi-window me-1'), t('ds_design_edit')])]) : null
			]);
		}
		if (!BROWSE) section('layout', t('ds_sec_layout'), [
			D.userhome ? null : field(t('form_layout'), 'select', layout(), function (v) {
				if (v === 'grid') confirmAsk(t('builder_designer_convert'), function () { change('layout.convert', {}, true).catch(function () { /* said */ }); }, t('form_layout_grid'), 'btn btn-primary');
				else change('form.save', {fields: {layout: v}}, true).catch(function () { /* said */ });
			}, {key: 'layout', options: [['absolute', t('form_layout_absolute')], ['auto', t('form_layout_auto')], ['grid', t('form_layout_grid')]]}),
			el('div', {'class': 'form-check form-switch nuDsField'}, [el('input', {type: 'checkbox', 'class': 'form-check-input', role: 'switch', id: 'nuDsTabOrder', checked: tabOrderOn ? true : null,
				onchange: function (e) { tabOrderOn = e.target.checked; post({nuBuilderTabOrder: tabOrderOn}); }}), el('label', {'class': 'form-check-label', 'for': 'nuDsTabOrder', text: t('builder_taborder')})])
		]);
		var tools = [];
		if (D.ai) tools.push(el('a', {'class': 'btn btn-sm btn-outline-secondary', id: 'nuDsAi', href: D.ai}, [icon('bi-stars me-1'), t('fai_button_change')]));
		if (D.dataaccess) tools.push(el('a', {'class': 'btn btn-sm btn-outline-secondary', id: 'nuDsDataAccess', href: D.dataaccess}, [icon('bi-shield-lock me-1'), t('da_title')]));
		if (D.can_delete_form && !DR) tools.push(el('button', {type: 'button', 'class': 'btn btn-sm btn-outline-danger', id: 'nuDsDeleteForm', onclick: deleteForm}, [icon('bi-trash me-1'), t('builder_delete_form')]));
		if (tools.length) section('tools', t('ds_sec_tools'), [el('div', {'class': 'nuDsTools'}, tools)]);
		foot([el('p', {'class': 'nuDsHint', text: t(BROWSE ? 'ds_hint_list' : 'ds_hint')})]);
	}
	// "Change column…" (as the builder, NuColumnChange): the column's type and length, the data checks, then ALTER TABLE
	function openColumnChange(column) {
		if (!(window.nuBs && window.nuBs.ready())) return;
		var info = null, plan = null, timer = 0, running = false;
		var cur = el('div', {'class': 'mb-3', id: 'nuDsColCur'});
		var type = el('select', {'class': 'form-select form-select-sm', id: 'nuDsColType', 'aria-label': t('builder_col_type')});
		COLTYPES.forEach(function (x) { type.appendChild(el('option', {value: x, text: t('builder_coltype_' + x)})); });
		var len = el('input', {type: 'text', 'class': 'form-control form-control-sm', id: 'nuDsColLen', maxlength: '8', 'aria-label': t('builder_col_length'), placeholder: t('builder_col_length')});
		var free = el('textarea', {'class': 'form-control form-control-sm font-monospace', rows: '3', id: 'nuDsColSql', spellcheck: 'false', 'aria-label': t('builder_colchg_free')});
		var freeBox = el('details', {'class': 'mt-3 d-none', id: 'nuDsColFree'}, [el('summary', {'class': 'small', text: t('builder_colchg_free')}), el('div', {'class': 'form-text mb-1', text: t('builder_colchg_free_hint')}), free]);
		var sqlBox = el('pre', {'class': 'small bg-body-tertiary border rounded p-2 mb-2 text-wrap', id: 'nuDsColPlan'});
		var res = el('div', {id: 'nuDsColChecks'});
		var accept = el('input', {type: 'checkbox', 'class': 'form-check-input', id: 'nuDsColAccept'});
		var acceptBox = el('div', {'class': 'form-check mt-2 d-none'}, [accept, el('label', {'class': 'form-check-label', 'for': 'nuDsColAccept', text: t('builder_colchg_accept')})]);
		var reauth = el('div', {'class': 'alert alert-warning py-2 d-none', id: 'nuDsColReauth'}, [icon('bi-shield-lock me-1'), t('builder_colchg_reauth') + ' ', el('a', {href: 'index.php?p=reauth', id: 'nuDsColReauthLink', text: t('builder_colchg_reauth_link')})]);
		var err = el('div', {'class': 'alert alert-danger py-2 d-none', role: 'alert'});
		var run = el('button', {type: 'button', 'class': 'btn btn-warning', id: 'nuDsColRun', disabled: true}, [icon('bi-database-gear me-1'), t('builder_colchg_run')]);
		var root = modal('nuDsColDlg', fmt(t('builder_colchg_title'), column), [err, reauth, cur,
			el('div', {'class': 'd-flex flex-wrap gap-2 align-items-end'}, [
				el('div', {}, [el('label', {'class': 'form-label small mb-1', 'for': 'nuDsColType', text: t('builder_colchg_new')}), type]),
				el('div', {}, [el('label', {'class': 'form-label small mb-1', 'for': 'nuDsColLen', text: t('builder_col_length')}), len])]),
			freeBox, el('div', {'class': 'small fw-semibold mt-3 mb-1', text: t('builder_colchg_sql')}), sqlBox, res, acceptBox], [run], true);
		root.addEventListener('hidden.bs.modal', function () { root.remove(); });
		var back = D.self + '&dscolchg=' + encodeURIComponent(column);
		function showErr(m) { err.textContent = m || ''; err.classList.toggle('d-none', !m); }
		function syncLen() { var uses = type.value === 'text' || type.value === 'decimal'; len.disabled = !uses; if (!uses) len.value = ''; else if (!len.value) len.value = type.value === 'text' ? '255' : '15,2'; }
		function n(x) { return Number(x).toLocaleString(); }
		function syncRun() { run.disabled = running || !info || info.reauth || !plan || plan.same || (plan.checks.length > 0 && !accept.checked); }
		function data() {
			var d = {column: column, back: back};
			if (free.value.trim() && free.dataset.edited === '1') d.sql = free.value; else { d.type = type.value; d.length = len.value; }
			return d;
		}
		function check() {
			clearTimeout(timer);
			timer = setTimeout(function () {
				api('column.check', data(), true).then(function (r) {
					if (!r.ok) throw new Error(r.error || 'Error');
					plan = r.plan; showErr('');
					sqlBox.textContent = plan.sql + ';';
					if (free.dataset.edited !== '1') free.value = plan.sql;
					res.innerHTML = '';
					if (plan.same) res.appendChild(el('div', {'class': 'alert alert-secondary py-2', text: t('builder_colchg_same')}));
					else if (plan.free) res.appendChild(el('div', {'class': 'alert alert-warning py-2', text: t('builder_colchg_free_nocheck')}));
					else if (!plan.checks.length) res.appendChild(el('div', {'class': 'alert alert-success py-2', id: 'nuDsColOk'}, [icon('bi-check-circle me-1'), t('builder_colchg_ok')]));
					else res.appendChild(el('div', {'class': 'alert alert-warning py-2', id: 'nuDsColLoss'}, [el('div', {'class': 'fw-semibold mb-1', text: t('builder_colchg_loss')}),
						el('ul', {'class': 'mb-0 small'}, plan.checks.map(function (c) {
							return el('li', {'data-check': c.key}, [t('builder_colchg_chk_' + c.key).replace('%1$s', n(c.n)).replace('%2$s', c.arg),
								c.samples.length ? el('span', {'class': 'text-body-secondary', text: ' – ' + t('builder_colchg_eg') + ' ' + c.samples.map(function (v) { return '«' + v + '»'; }).join(', ')}) : null]);
						}))]));
					acceptBox.classList.toggle('d-none', !(plan.checks.length > 0));
					accept.checked = false;
					syncRun();
				}).catch(function (e) { plan = null; showErr(e.message); syncRun(); });
			}, 250);
		}
		type.addEventListener('change', function () { len.value = ''; syncLen(); free.dataset.edited = ''; check(); });
		len.addEventListener('input', function () { free.dataset.edited = ''; check(); });
		free.addEventListener('input', function () { free.dataset.edited = '1'; check(); });
		accept.addEventListener('change', syncRun);
		run.addEventListener('click', function () {
			running = true; syncRun();
			api('column.change', Object.assign(data(), {accept_loss: accept.checked})).then(function (r) {
				running = false;
				if (!r.ok) throw new Error(r.error || 'Error');
				hide(root);
				say(fmt(t('builder_colchg_done'), r.info.sql_type));
			}).catch(function (e) { running = false; showErr(e.message); syncRun(); });
		});
		api('column.info', {column: column, back: back}, true).then(function (r) {
			if (!r.ok) throw new Error(r.error || 'Error');
			info = Object.assign(r.info, {reauth: r.reauth});
			cur.innerHTML = '';
			cur.appendChild(el('div', {}, [t('builder_colchg_current') + ' ', el('code', {id: 'nuDsColCurType', text: info.sql_type}), ' · ' + (info.nullable ? 'NULL' : 'NOT NULL'),
				info.rows !== null ? ' · ' + fmt(t('builder_colchg_rows'), n(info.rows)) : '', info.pk ? el('span', {'class': 'badge text-bg-light border ms-2', text: t('builder_colchg_pk')}) : null]));
			if (r.used_by && r.used_by.length) cur.appendChild(el('div', {'class': 'small text-body-secondary mt-1', text: fmt(t('builder_colchg_used'), r.used_by.join(', '))}));
			cur.appendChild(el('div', {'class': 'small text-body-secondary mt-1', text: t('builder_colchg_backup')}));
			if (r.reauth) { reauth.classList.remove('d-none'); if (r.reauth_url) root.querySelector('#nuDsColReauthLink').href = r.reauth_url; }
			if (r.free_sql) freeBox.classList.remove('d-none');
			type.value = info.type || 'text';
			len.value = info.length || '';
			syncLen();
			check();
		}).catch(function (e) { showErr(e.message); });
		show(root);
	}
	var tabOrderOn = false, tablesDl = null;
	function tablesList() {
		if (!tablesDl) {
			tablesDl = el('datalist', {id: 'nuDsTables'});
			api('tables', {}, true).then(function (j) { (j.tables || []).forEach(function (x) { tablesDl.appendChild(el('option', {value: x})); }); }).catch(function () { /* no list */ });
		}
		return tablesDl;
	}
	// "Delete form" (as the builder, the globeadmin only): the form, optionally its table – only after its name is typed exactly
	function deleteForm() {
		var note = el('div', {'class': 'alert alert-secondary small d-none', id: 'nuDsDelNote'});
		var chk = el('input', {type: 'checkbox', 'class': 'form-check-input', id: 'nuDsDelTable'});
		var lbl = el('label', {'class': 'form-check-label', 'for': 'nuDsDelTable'});
		var typed = el('input', {type: 'text', 'class': 'form-control form-control-sm mt-2', id: 'nuDsDelTyped', autocomplete: 'off', spellcheck: 'false'});
		var typedLbl = el('label', {'class': 'form-label small mt-2 mb-0', 'for': 'nuDsDelTyped'});
		var reauth = el('div', {'class': 'alert alert-warning small mt-2 d-none'}, [t('builder_drop_reauth') + ' ', el('a', {id: 'nuDsDelReauth', href: 'index.php?p=reauth', text: t('builder_drop_reauth_link')})]);
		var box = el('div', {'class': 'd-none mt-2', id: 'nuDsDelBox'}, [el('div', {'class': 'form-check'}, [chk, lbl]), el('div', {'class': 'd-none', id: 'nuDsDelConfirm'}, [typedLbl, typed,
			el('div', {'class': 'small text-danger mt-1', text: t('builder_drop_warning')})]), reauth]);
		var ok = el('button', {type: 'button', 'class': 'btn btn-danger', id: 'nuDsDelOk', disabled: true, text: t('builder_delete_form')});
		var root = modal('nuDsDeleteDlg', t('builder_delete_form'), [el('p', {text: t('builder_delete_form_text')}), note, box], [ok], true);
		var info = null;
		var sync = function () {
			var drop = !!(info && info.can_drop && chk.checked);
			root.querySelector('#nuDsDelConfirm').classList.toggle('d-none', !drop || info.reauth);
			reauth.classList.toggle('d-none', !drop || !info.reauth);
			ok.textContent = drop ? t('builder_delete_form_table') : t('builder_delete_form');
			ok.disabled = !info || (drop && (info.reauth || typed.value !== info.table));
		};
		chk.addEventListener('change', function () { sync(); if (chk.checked) typed.focus(); });
		typed.addEventListener('input', sync);
		typed.addEventListener('paste', function (e) { e.preventDefault(); });
		ok.addEventListener('click', function () {
			ok.disabled = true;
			api('form.delete', {drop_table: info && info.can_drop && chk.checked ? typed.value : ''}, true).then(function (j) {
				if (!j.ok) throw new Error(j.error || 'Error');
				if (j.warning) window.alert(j.warning);
				window.location.href = 'index.php?p=forms';
			}).catch(function (e) { ok.disabled = false; say(e.message, true); note.textContent = e.message; note.classList.remove('d-none'); });
		});
		root.addEventListener('hidden.bs.modal', function () { root.remove(); });
		api('form.deleteinfo', {back: D.self + '&dsdelete=1'}, true).then(function (r) {
			if (!r.ok) throw new Error(r.error || 'Error');
			info = r;
			var m = '';
			if (!r.table) m = t('builder_drop_no_table');
			else if (!r.exists) m = fmt(t('builder_drop_missing'), r.table);
			else if (r.reason === 'used') m = fmt(t('builder_drop_used'), r.table, (r.used_by || []).join(', '));
			else if (r.reason === 'view') m = fmt(t('builder_drop_view'), r.table);
			else if (r.reason) m = fmt(t('builder_drop_kept'), r.table);
			if (m) { note.textContent = m; note.classList.remove('d-none'); }
			if (r.can_drop) {
				lbl.textContent = fmt(t('builder_drop_table'), r.table, r.rows === null ? '?' : String(r.rows));
				typedLbl.textContent = fmt(t('builder_drop_type'), r.table);
				typed.placeholder = r.table;
				if (r.reauth_url) root.querySelector('#nuDsDelReauth').href = r.reauth_url;
				box.classList.remove('d-none');
			}
			sync();
		}).catch(function (e) { note.textContent = e.message; note.classList.remove('d-none'); info = {can_drop: false}; sync(); });
		show(root);
	}

	function deleteObjs(objs) {
		objs = objs.filter(Boolean);
		if (!objs.length) return;
		var one = objs.length === 1, names = objs.map(function (o) { return o.sob_all_id; });
		var text = one ? fmt(t('builder_delete_object_confirm'), names[0]) : fmt(t('builder_delete_objects_confirm'), objs.length, names.join(', '));
		confirmAsk(text, function () {
			selIds = [];
			chain(objs.map(function (o) { return ['object.delete', {object_id: o.zzzzsys_object_id}]; }));
		}, t(one ? 'builder_delete_object' : 'builder_delete_objects'), 'btn btn-danger');
	}

	// a saved object stores a value but the table has no column for it: offer to add it (as the builder)
	function missingBox(o) {
		var mc = missing[o.zzzzsys_object_id];
		if (!mc || mc.name !== o.sob_all_id) return null;
		var type = el('select', {'class': 'form-select form-select-sm', 'aria-label': t('builder_col_type')});
		COLTYPES.forEach(function (x) { type.appendChild(el('option', {value: x, text: t('builder_coltype_' + x), selected: x === mc.type ? true : null})); });
		var len = el('input', {type: 'text', 'class': 'form-control form-control-sm nuDsLen', value: mc.length || '', 'aria-label': t('builder_col_length'), maxlength: '8'});
		return el('div', {'class': 'alert alert-warning nuDsMissing', id: 'nuDsMissing', role: 'alert'}, [
			el('div', {'class': 'fw-semibold mb-1'}, [icon('bi-exclamation-triangle me-1'), fmt(t('builder_missing_column'), mc.name, mc.table)]),
			el('div', {'class': 'd-flex flex-wrap gap-2'}, [type, len,
				el('button', {type: 'button', 'class': 'btn btn-warning btn-sm', id: 'nuDsAddColumn', text: t('builder_add_column_btn'), onclick: function () {
					delete missing[o.zzzzsys_object_id];
					api('column.add', {object_id: o.zzzzsys_object_id, type: type.value, length: len.value}).then(function (j) { if (!j.ok) throw new Error(j.error || 'Error'); say(t('ds_saved')); renderPanel(); })
						.catch(function (e) { missing[o.zzzzsys_object_id] = mc; say(e.message, true); });
				}}),
				el('button', {type: 'button', 'class': 'btn btn-link btn-sm', text: t('builder_not_now'), onclick: function () { delete missing[o.zzzzsys_object_id]; renderPanel(); }})])
		]);
	}

	function renderObject(o) {
		var type = o.sob_all_type, isAbs = layout() === 'absolute', it = o.sob_input_type || 'text';
		var btns = [
			el('button', {type: 'button', 'class': 'btn btn-sm btn-outline-secondary', id: 'nuDsDuplicate', title: t('ds_duplicate') + ' (Ctrl+D)', 'aria-label': t('ds_duplicate'), onclick: function () { duplicate([o]); }}, [icon('bi-copy')]),
			el('button', {type: 'button', 'class': 'btn btn-sm btn-outline-danger nuDsDel', id: 'nuDsDelete', title: t('builder_delete_object'), 'aria-label': t('builder_delete_object'), onclick: function () { deleteObjs([o]); }}, [icon('bi-trash')])
		];
		panel.appendChild(head(t('bf_type_' + type), o.sob_all_id, ICONS[type] || 'bi-square', '', btns));
		var mb = missingBox(o);
		if (mb) panel.appendChild(mb);
		var save = function (key, reload) { return function (v) { var f = {}; f[key] = v; saveObj(o, f, reload); }; };
		var runType = String(o.sob_run_type || '').toUpperCase() || ((D.procedures || []).some(function (p) { return p[0] === o.sob_run_zzzzsys_form_id; }) ? 'P' : ((D.reports || []).some(function (p) { return p[0] === o.sob_run_zzzzsys_form_id; }) ? 'R' : 'F'));
		var fmtField = function (key, letters) {
			return field(t('builder_input_format'), 'format', o[key] || '', save(key), {key: key, letters: letters, empty: '—',
				tip: letters === 'T' ? t('builder_input_mask_help') : null});
		};
		var dispProc = String(o.sob_display_procedure || '');
		var fileTarget = String(o.sob_input_file_target || '0') === '1' ? '1' : '0';
		section('content', t('ds_sec_content'), [
			field(t('builder_obj_id'), 'text', o.sob_all_id, function (v) { selIds = [v.trim()]; save('sob_all_id')(v.trim()); },
				{key: 'sob_all_id', mono: true, check: function (v) { return /^[A-Za-z_][A-Za-z0-9_]*$/.test(v.trim()); }, err: t('ds_err_id')}),
			field(t('builder_obj_label'), 'text', o.sob_all_label, function (v) {
				// the label element shows the new text already: no reload, unless there was none before or it is removed
				saveObj(o, {sob_all_label: v}, !liveLabel(o, null) || v.trim() === '' || String(o.sob_all_label || '').trim() === '');
			}, {key: 'sob_all_label', live: function (v) { liveLabel(o, v); }}),
			field(t('builder_obj_type'), 'select', type, save('sob_all_type'), {key: 'sob_all_type', options: TYPES.map(function (x) { return [x, t('bf_type_' + x)]; })}),
			type === 'input' ? field(t('builder_input_type'), 'select', it, save('sob_input_type'), {key: 'sob_input_type', options: INPUT_TYPES.map(function (x) { return [x, x]; })}) : null,
			type === 'input' && (it === 'nuDate' || it === 'nuNumber') ? fmtField('sob_input_format', it === 'nuDate' ? 'D' : 'N') : null,
			type === 'input' && ['text', 'tel', 'search'].indexOf(it) >= 0 ? fmtField('sob_input_format', 'T') : null,
			type === 'input' && it === 'nuScroll' ? field(t('builder_input_scroll'), 'textarea', o.sob_input_javascript, save('sob_input_javascript'), {key: 'sob_input_javascript', code: 'JS', mono: true, rows: '2'}) : null,
			type === 'input' && it === 'nuAutoNumber' ? field(t('builder_input_count'), 'number', o.sob_input_count, save('sob_input_count', false), {key: 'sob_input_count', tip: t('builder_input_count_help')}) : null,
			type === 'input' && it === 'file' ? field(t('builder_file_target'), 'select', fileTarget, function (v) {
				var f = {sob_input_file_target: v};
				if (v === '1' && !String(o.sob_html_code || '').trim() && D.uppy_template) f.sob_html_code = D.uppy_template;   // the Uppy template (nuBuilder Forte: nu_upload_file_load_uppy_code)
				saveObj(o, f, true);
			}, {key: 'sob_input_file_target', tip: t('builder_file_target_help'), options: [['0', t('builder_file_target_0')], ['1', t('builder_file_target_1')]]}) : null,
			type === 'input' && it === 'file' && fileTarget === '1' ? field(t('builder_html'), 'textarea', o.sob_html_code, save('sob_html_code'), {key: 'sob_html_code', code: 'HTML', mono: true, rows: '5', tip: t('builder_file_uppy_help')}) : null,
			type === 'display' ? field(t('builder_display_proc'), 'select', dispProc, save('sob_display_procedure'), {key: 'sob_display_procedure', search: true, tip: t('builder_display_proc_help'),
				options: [['', t('builder_display_proc_none')]].concat(D.procedures || [])}) : null,
			type === 'calc' ? fmtField('sob_calc_format', 'N') : null,
			type === 'calc' ? field(t('builder_calc_formula'), 'textarea', o.sob_calc_formula, save('sob_calc_formula'), {key: 'sob_calc_formula', code: 'JS', mono: true, rows: '2'}) : null,
			type === 'select' ? field(t('builder_select_sql'), 'textarea', o.sob_select_sql, save('sob_select_sql'), {key: 'sob_select_sql', code: 'SQL', mono: true, rows: '4'}) : null,
			type === 'display' ? field(t('builder_display_sql'), 'textarea', o.sob_display_sql, save('sob_display_sql'), {key: 'sob_display_sql', code: 'SQL', mono: true, rows: '3'}) : null,
			type === 'html' ? field(t('builder_html'), 'textarea', o.sob_html_code, save('sob_html_code'), {key: 'sob_html_code', code: 'HTML', mono: true, rows: '6'}) : null,
			type === 'lookup' ? field(t('builder_lookup_form'), 'select', o.sob_lookup_zzzzsys_form_id, save('sob_lookup_zzzzsys_form_id'), {key: 'sob_lookup_zzzzsys_form_id', search: true, options: options(D.forms, ['browse', 'browseedit'])}) : null,
			type === 'lookup' ? el('div', {'class': 'nuDsGrid2'}, [
				field(t('builder_lookup_code'), 'text', o.sob_lookup_code, save('sob_lookup_code'), {key: 'sob_lookup_code', mono: true}),
				field(t('builder_lookup_description'), 'text', o.sob_lookup_description, save('sob_lookup_description'), {key: 'sob_lookup_description', mono: true})]) : null,
			type === 'subform' ? field(t('builder_subform_form'), 'select', o.sob_subform_zzzzsys_form_id, save('sob_subform_zzzzsys_form_id'), {key: 'sob_subform_zzzzsys_form_id', search: true, options: options(D.forms, ['subform'])}) : null,
			type === 'subform' ? field(t('builder_subform_fk'), 'text', o.sob_subform_foreign_key, save('sob_subform_foreign_key'), {key: 'sob_subform_foreign_key', mono: true}) : null,
			type === 'subform' && o.sob_subform_zzzzsys_form_id ? el('div', {'class': 'nuDsField'}, [el('a', {'class': 'btn btn-sm btn-outline-secondary', id: 'nuDsDesignSub',
				href: 'index.php?p=form&f=' + encodeURIComponent(o.sob_subform_zzzzsys_form_id) + '&r=-1&design=1', target: '_blank', rel: 'noopener'}, [icon('bi-pencil-square me-1'), t('ds_design_subform')])]) : null,
			type === 'chart' ? field(t('builder_chart_type'), 'select', o.sob_html_chart_type || '', save('sob_html_chart_type'), {key: 'sob_html_chart_type',
				options: [['', t('please_select')], ['p', t('builder_chart_p')], ['l', t('builder_chart_l')], ['b', t('builder_chart_b')], ['bs', t('builder_chart_bs')], ['bh', t('builder_chart_bh')], ['bhs', t('builder_chart_bhs')]]}) : null,
			type === 'chart' ? field(t('builder_chart_data'), 'textarea', o.sob_html_javascript, save('sob_html_javascript'), {key: 'sob_html_javascript', code: 'JS', mono: true, rows: '4', tip: t('builder_chart_data_help')}) : null,
			type === 'chart' ? field(t('builder_chart_title'), 'text', o.sob_html_title, save('sob_html_title'), {key: 'sob_html_title'}) : null,
			type === 'chart' ? el('div', {'class': 'nuDsGrid2'}, [
				field(t('builder_chart_vlabel'), 'text', o.sob_html_vertical_label, save('sob_html_vertical_label'), {key: 'sob_html_vertical_label'}),
				field(t('builder_chart_hlabel'), 'text', o.sob_html_horizontal_label, save('sob_html_horizontal_label'), {key: 'sob_html_horizontal_label'})]) : null,
			type === 'image' ? field(t('builder_image_file'), 'select', o.sob_image_zzzzsys_file_id || '', save('sob_image_zzzzsys_file_id'), {key: 'sob_image_zzzzsys_file_id', search: true, tip: t('builder_image_help'),
				options: [['', t('please_select')]].concat(D.files || [])}) : null,
			type === 'run' ? field(t('builder_run_type'), 'select', runType, save('sob_run_type'), {key: 'sob_run_type', options: [['F', t('builder_run_type_F')], ['P', t('builder_run_type_P')], ['R', t('builder_run_type_R')]]}) : null,
			type === 'run' && runType === 'F' ? field(t('builder_run_form'), 'select', o.sob_run_zzzzsys_form_id, save('sob_run_zzzzsys_form_id'), {key: 'sob_run_zzzzsys_form_id', search: true, options: options(D.forms)}) : null,
			type === 'run' && runType === 'P' ? field(t('builder_run_procedure'), 'select', o.sob_run_zzzzsys_form_id, save('sob_run_zzzzsys_form_id'), {key: 'sob_run_zzzzsys_form_id', search: true, options: options(D.procedures)}) : null,
			type === 'run' && runType === 'R' ? field(t('builder_run_report'), 'select', o.sob_run_zzzzsys_form_id, save('sob_run_zzzzsys_form_id'), {key: 'sob_run_zzzzsys_form_id', search: true, options: options(D.reports)}) : null,
			type === 'run' && runType === 'F' ? el('div', {'class': 'nuDsGrid2'}, [
				field(t('builder_run_record'), 'text', o.sob_run_id, save('sob_run_id', false), {key: 'sob_run_id', mono: true, tip: t('builder_run_record_help')}),
				field(t('builder_run_filter'), 'text', o.sob_run_filter, save('sob_run_filter', false), {key: 'sob_run_filter', mono: true, tip: t('builder_run_filter_help')})]) : null
		], [
			type === 'input' && ['text', 'number', 'email', 'tel', 'url'].indexOf(it) >= 0 ? field(t('builder_input_datalist'), 'textarea', o.sob_input_datalist, save('sob_input_datalist'),
				{key: 'sob_input_datalist', rows: '2', ph: 'Red, Green, Blue', tip: t('builder_input_datalist_help')}) : null,
			type === 'lookup' ? field(t('builder_table'), 'text', o.sob_lookup_table, save('sob_lookup_table', false), {key: 'sob_lookup_table', mono: true}) : null,
			type === 'lookup' ? field(t('builder_lookup_js'), 'textarea', o.sob_lookup_javascript, save('sob_lookup_javascript'), {key: 'sob_lookup_javascript', code: 'JS', mono: true, rows: '3'}) : null,
			type === 'lookup' ? field(t('builder_lookup_php'), 'textarea', (S.lookup_php || {})[o.zzzzsys_object_id] || '', function (v) {
				change('object.save', {object_id: o.zzzzsys_object_id, fields: {}, lookup_php: v}, false).catch(function () { /* said */ });
			}, {key: 'lookup_php', code: 'PHP', mono: true, rows: '3', tip: t('builder_lookup_php_help')}) : null,
			type === 'subform' ? field(t('builder_table'), 'text', o.sob_subform_table, save('sob_subform_table', false), {key: 'sob_subform_table', mono: true}) : null,
			type === 'subform' ? field(t('builder_subform_add'), 'check', String(o.sob_subform_add) === '0' ? '0' : '1', save('sob_subform_add'), {key: 'sob_subform_add'}) : null,
			type === 'subform' ? field(t('builder_subform_delete'), 'check', String(o.sob_subform_delete) === '0' ? '0' : '1', save('sob_subform_delete'), {key: 'sob_subform_delete'}) : null,
			type === 'lookup' ? field(t('builder_lookup_desc_width'), 'number', o.sob_lookup_description_width, save('sob_lookup_description_width'), {key: 'sob_lookup_description_width'}) : null,
			type === 'subform' ? field(t('builder_subform_type'), 'select', o.sob_subform_type || 'g', save('sob_subform_type'), {key: 'sob_subform_type', options: [['g', t('builder_subform_grid')], ['f', t('builder_subform_formlayout')]]}) : null,
			type === 'select' ? field(t('builder_select_multiple'), 'check', String(o.sob_select_multiple || '0'), save('sob_select_multiple'), {key: 'sob_select_multiple'}) : null,
			type === 'select' ? field(t('builder_select_search'), 'check', String(o.sob_select_2 || '0'), save('sob_select_2'), {key: 'sob_select_2'}) : null,
			type === 'run' ? field(t('builder_run_method'), 'select', o.sob_run_method === 'i' ? 'i' : 'b', save('sob_run_method'), {key: 'sob_run_method', options: [['b', t('builder_run_b')], ['i', t('builder_run_i')]]}) : null,
			type === 'run' && runType === 'F' && o.sob_run_method !== 'i' ? field(t('builder_run_target'), 'select', String(o.sob_run_target || '0'), save('sob_run_target'), {key: 'sob_run_target',
				options: [0, 1, 2, 3].map(function (i) { return [String(i), t('builder_run_target_' + i)]; })}) : null,
			VALUE_TYPES.indexOf(type) >= 0 ? field(t('builder_obj_cloneable'), 'check', String(o.sob_all_cloneable) === '0' ? '0' : '1', save('sob_all_cloneable', false), {key: 'sob_all_cloneable'}) : null
		]);

		var ev = (S.events && S.events[o.zzzzsys_object_id]) || [];
		var acc = String(o.sob_all_access === null || o.sob_all_access === undefined || o.sob_all_access === '' ? '0' : o.sob_all_access);
		section('behaviour', t('ds_sec_behaviour'), [
			field(t('builder_obj_access'), 'select', acc, save('sob_all_access'), {key: 'sob_all_access', options: [0, 1, 2, 3, 4, 9].map(function (i) { return [String(i), t('bf_access_' + i)]; })}),
			acc === '9' ? field(t('builder_obj_access_cond'), 'textarea', o.sob_all_access_condition, save('sob_all_access_condition', false), {key: 'sob_all_access_condition', code: 'SQL', mono: true}) : null,
			VALUE_TYPES.indexOf(type) >= 0 ? field(t('builder_obj_validate'), 'select', String(o.sob_all_validate || '0'), save('sob_all_validate', false),
				{key: 'sob_all_validate', options: [0, 1, 2, 3].map(function (i) { return [String(i), t('bf_validate_' + i)]; })}) : null,
			el('div', {'class': 'nuDsField'}, [el('div', {'class': 'form-label nuDsLabel', text: t('ds_events')}),
				el('div', {'class': 'nuDsEvents'}, [el('span', {text: ev.length ? ev.map(function (x) { return x.event; }).join(', ') : t('ds_events_none')}),
					el('button', {type: 'button', 'class': 'btn btn-sm btn-outline-secondary', id: 'nuDsEditEvents', onclick: function () { editEvents(o); }}, [icon('bi-lightning me-1'), t('ds_events_edit')])])])
		]);

		var tabs = (S.tabs || []).map(function (x) { return [x.zzzzsys_tab_id, plain(x.syt_title)]; });
		var layRows = [];
		if (isAbs) {
			layRows.push(el('div', {'class': 'nuDsGrid2'}, [
				field(t('builder_top'), 'number', o.sob_all_top, function (v) { moveTo(o, {sob_all_top: v}); }, {key: 'sob_all_top', live: function (v) { livePos(o, 'top', v); }}),
				field(t('builder_left'), 'number', o.sob_all_left, function (v) { moveTo(o, {sob_all_left: v}); }, {key: 'sob_all_left', live: function (v) { livePos(o, 'left', v); }}),
				field(t('builder_width'), 'number', o.sob_all_width, function (v) { saveObj(o, {sob_all_width: v}, false); }, {key: 'sob_all_width', live: function (v) { liveSize(o, v, undefined); }}),
				field(t('builder_height'), 'number', o.sob_all_height, function (v) { saveObj(o, {sob_all_height: v}, false); }, {key: 'sob_all_height', live: function (v) { liveSize(o, undefined, v); }})
			]));
			// order on the tab (Tab key): the designer's Up / Down (its floating panel is hidden in design mode)
			layRows.push(el('div', {'class': 'nuDsField'}, [el('div', {'class': 'form-label nuDsLabel', text: t('ds_order')}), el('div', {'class': 'd-flex gap-2'}, ['up', 'down'].map(function (k) {
				var src = document.querySelector('.nuArrange [data-arrange="' + k + '"]');
				return el('button', {type: 'button', 'class': 'btn btn-sm btn-outline-secondary', 'data-ds-arrange': k, onclick: function () { var b = document.querySelector('.nuArrange [data-arrange="' + k + '"]'); if (b) b.click(); }},
					[icon(k === 'up' ? 'bi-arrow-up me-1' : 'bi-arrow-down me-1'), src ? src.textContent.trim() : k]);
			}))]));
		} else {
			var g = nunext(o);
			layRows.push(field(t('ds_span'), 'select', String(g.span || ''), function (v) { gridSave(o, {span: v}); },
				{key: 'span', empty: '–', options: [['', '–']].concat([1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12].map(function (i) { return [String(i), String(i)]; }))}));
			if (layout() === 'grid') {
				var boxes = [['', '—']].concat(S.objects.filter(function (x) { return x.sob_all_type === 'contentbox' && x !== o; }).map(function (x) { return [x.sob_all_id, x.sob_all_id]; }));
				layRows.push(el('div', {'class': 'nuDsGrid2'}, [
					field(t('builder_grid_row'), 'number', g.row, function (v) { gridSave(o, {row: v}); }, {key: 'grid_row'}),
					field(t('builder_grid_col'), 'number', g.col, function (v) { gridSave(o, {col: v}); }, {key: 'grid_col'})]));
				layRows.push(field(t('builder_grid_box'), 'select', g.box || '', function (v) { gridSave(o, {box: v}); }, {key: 'grid_box', options: boxes}));
			}
		}
		if (tabs.length > 1) layRows.push(field(t('builder_obj_tab'), 'select', o.sob_all_zzzzsys_tab_id, function (v) { keepTab = v; save('sob_all_zzzzsys_tab_id')(v); }, {key: 'sob_all_zzzzsys_tab_id', options: tabs}));
		section('layout', t('ds_sec_layout'), layRows);

		section('style', t('builder_sec_style'), [
			field(t('builder_obj_align'), 'select', o.sob_all_align || 'left', save('sob_all_align'), {key: 'sob_all_align', options: [['left', t('ds_align_left')], ['center', t('ds_align_center')], ['right', t('ds_align_right')]]}),
			type === 'run' || type === 'word' || (type === 'input' && it === 'button') ? field(t('builder_obj_icon'), 'text', o.sob_input_icon, save('sob_input_icon'), {key: 'sob_input_icon', mono: true, ph: 'fa fa-table'}) : null,
			el('div', {'class': 'nuDsGrid2 nuDsGridStyle'}, [
				field(t('builder_style_type'), 'select', o.sob_all_style_type || '', save('sob_all_style_type'), {key: 'sob_all_style_type', empty: '—', options: [['', '—'], ['CSS', 'CSS'], ['Class', 'Class']]}),
				field(t('builder_style'), 'text', o.sob_all_style, save('sob_all_style'), {key: 'sob_all_style', mono: true})])
		], [
			field(t('builder_attributes'), 'text', o.sob_input_attribute, save('sob_input_attribute'), {key: 'sob_input_attribute', mono: true, ph: t('builder_attributes_help'), attr: true})
		]);
		var otools = [];
		if (VALUE_TYPES.indexOf(type) >= 0 && type !== 'display' && type !== 'calc' && S.form.sfo_table) otools.push(el('button', {type: 'button', 'class': 'btn btn-sm btn-outline-secondary', id: 'nuDsColChange',
			onclick: function () { openColumnChange(o.sob_all_id); }}, [icon('bi-database-gear me-1'), t('builder_colchg_btn')]));
		if (D.dataaccess && VALUE_TYPES.indexOf(type) >= 0) otools.push(el('a', {'class': 'btn btn-sm btn-outline-secondary', id: 'nuDsObjAccess', href: D.dataaccess}, [icon('bi-shield-lock me-1'), t('da_title')]));
		if (otools.length) section('tools', t('ds_sec_tools'), [el('div', {'class': 'nuDsTools'}, otools)]);
	}

	// live view while typing (0.8.664) – the label (as the form draws it: the text as HTML, then the required / unique marks), the position
	// and the size; returns false when the object has no label element to change (then the save reloads the page)
	function liveLabel(o, v) {
		var lab = document.getElementById('label_' + o.sob_all_id);
		if (!lab || ['run', 'word', 'html'].indexOf(o.sob_all_type) >= 0) return false;
		if (v === null) return true;
		var marks = lab.querySelector('.nuMarks');
		lab.innerHTML = v;
		if (marks) lab.appendChild(marks);
		return true;
	}
	function livePos(o, side, v) {
		var w = document.getElementById('nuw_' + o.sob_all_id), n = parseInt(v, 10);
		if (w && isFinite(n)) w.style[side] = Math.max(0, n) + 'px';
	}
	function liveSize(o, width, height) {
		var msg2 = {id: o.sob_all_id};
		if (width !== undefined && isFinite(parseInt(width, 10))) msg2.width = parseInt(width, 10) + lookupExtra(o);
		if (height !== undefined && isFinite(parseInt(height, 10))) msg2.height = parseInt(height, 10);
		if (msg2.width !== undefined || msg2.height !== undefined) post({nuBuilderSize: msg2});
	}

	// top / left from the panel: the object moves at once, no reload
	function moveTo(o, f) {
		var w = document.getElementById('nuw_' + o.sob_all_id);
		if (w && f.sob_all_top !== undefined) w.style.top = Math.max(0, parseInt(f.sob_all_top, 10) || 0) + 'px';
		if (w && f.sob_all_left !== undefined) w.style.left = Math.max(0, parseInt(f.sob_all_left, 10) || 0) + 'px';
		saveObj(o, f, false);
	}
	function ensureGrid() {
		if (layout() === 'grid') return Promise.resolve();
		return new Promise(function (resolve, reject) {
			confirmAsk(t('builder_designer_convert'), function () { change('layout.convert', {}, false).then(resolve, reject); }, t('form_layout_grid'), 'btn btn-primary');
		});
	}
	function gridSave(o, part) {
		ensureGrid().then(function () {
			var cur = objByFid(o.sob_all_id) || o, g = nunext(cur);
			var grid = Object.assign({row: g.row || '', col: g.col || '', span: g.span || '', box: g.box || ''}, part);
			return change('object.save', {object_id: cur.zzzzsys_object_id, grid: grid, fields: {}}, true);
		}).catch(function () { /* said */ });
	}

	function renderMany(objs) {
		var btns = [
			el('button', {type: 'button', 'class': 'btn btn-sm btn-outline-secondary', id: 'nuDsDuplicate', title: t('ds_duplicate') + ' (Ctrl+D)', 'aria-label': t('ds_duplicate'), onclick: function () { duplicate(objs); }}, [icon('bi-copy')]),
			el('button', {type: 'button', 'class': 'btn btn-sm btn-outline-danger nuDsDel', id: 'nuDsDelete', title: t('builder_delete_objects'), 'aria-label': t('builder_delete_objects'), onclick: function () { deleteObjs(objs); }}, [icon('bi-trash')])
		];
		panel.appendChild(head(t('ds_selection'), fmt(t('ds_n_objects'), objs.length), 'bi-collection', objs.map(function (o) { return o.sob_all_id; }).join(', '), btns));
		var same = function (k, def) { var v = objs.map(function (o) { var x = o[k]; return x === null || x === undefined || x === '' ? def : String(x); }); return v.every(function (x) { return x === v[0]; }) ? v[0] : ''; };
		var all = function (key) {
			return function (v) {
				if (v === '') return;
				chain(objs.map(function (o) { var f = {}; f[key] = v; return ['object.save', {object_id: o.zzzzsys_object_id, fields: f}]; }));
			};
		};
		// Arrange (absolute layout): the actions of the designer's floating panel (hidden in design mode – the panel has them)
		if (layout() === 'absolute') {
			var ACT = [['up', 'bi-arrow-up'], ['down', 'bi-arrow-down'], ['top', 'bi-align-top'], ['bottom', 'bi-align-bottom'], ['left', 'bi-align-start'], ['right', 'bi-align-end'], ['vertical', 'bi-distribute-vertical'],
				['horizontal', 'bi-distribute-horizontal'], ['thinnest', 'bi-arrows-collapse-vertical'], ['widest', 'bi-arrows-expand-vertical'], ['shortest', 'bi-arrows-collapse'], ['tallest', 'bi-arrows-expand']];
			var gaps = {h: 10, v: 10};
			try { var gv = JSON.parse(window.localStorage.getItem('nunext-arrange-gap') || 'null'); if (gv && isFinite(gv.h) && isFinite(gv.v)) gaps = gv; } catch (e) { /* no storage */ }
			// fixed gap (0.8.664): the first object stays, each next one follows at exactly this distance – horizontally to the next label
			var gapRow = function (what, key, ic, labelKey) {
				var id = 'nuDsGap' + key.toUpperCase();
				var inp = el('input', {type: 'number', min: '0', max: '500', 'class': 'form-control form-control-sm', id: id, value: String(gaps[key])});
				var go = function () { post({nuBuilderArrange: {what: what, gap: inp.value}}); };
				inp.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); go(); } });
				return el('div', {'class': 'nuDsField'}, [el('label', {'class': 'form-label nuDsLabel', 'for': id, text: t(labelKey) + ' (px)'}),
					el('div', {'class': 'input-group input-group-sm'}, [el('span', {'class': 'input-group-text'}, [icon(ic)]), inp,
						el('button', {type: 'button', 'class': 'btn btn-outline-secondary', 'data-ds-gap': what, title: t('dsg_gap_tip'), text: t('dsg_apply'), onclick: go})])]);
			};
			section('arrange', t('ds_sec_arrange'), [el('div', {'class': 'nuDsArrange', id: 'nuDsArrange'}, ACT.map(function (a) {
				var src = document.querySelector('.nuArrange [data-arrange="' + a[0] + '"]');
				var label = src ? src.textContent.trim() : a[0];
				return el('button', {type: 'button', 'class': 'btn btn-sm btn-outline-secondary', 'data-ds-arrange': a[0], disabled: src && src.disabled ? true : null, title: label,
					onclick: function () { var s2 = document.querySelector('.nuArrange [data-arrange="' + a[0] + '"]'); if (s2) s2.click(); }}, [icon(a[1] + ' me-1'), label]);
			})), el('div', {'class': 'nuDsGrid2'}, [gapRow('hgap', 'h', 'bi-distribute-horizontal', 'dsg_hgap'), gapRow('vgap', 'v', 'bi-distribute-vertical', 'dsg_vgap')])]);
		}
		var values = objs.every(function (o) { return VALUE_TYPES.indexOf(o.sob_all_type) >= 0; });
		section('behaviour', t('ds_sec_behaviour'), [
			field(t('builder_obj_access'), 'select', same('sob_all_access', '0'), all('sob_all_access'), {key: 'sob_all_access', options: [0, 1, 2, 3, 4].map(function (i) { return [String(i), t('bf_access_' + i)]; })}),
			values ? field(t('builder_obj_validate'), 'select', same('sob_all_validate', '0'), all('sob_all_validate'), {key: 'sob_all_validate', options: [0, 1, 2, 3].map(function (i) { return [String(i), t('bf_validate_' + i)]; })}) : null
		]);
		var tabs = (S.tabs || []).map(function (x) { return [x.zzzzsys_tab_id, plain(x.syt_title)]; });
		if (tabs.length > 1) section('layout', t('ds_sec_layout'), [field(t('builder_obj_tab'), 'select', same('sob_all_zzzzsys_tab_id', ''), function (v) { keepTab = v; all('sob_all_zzzzsys_tab_id')(v); }, {key: 'sob_all_zzzzsys_tab_id', options: tabs})]);
	}

	// ------------------------------------------------------------------ messages of nudesigner.js
	window.addEventListener('message', function (e) {
		if (e.origin !== origin || !e.data || typeof e.data !== 'object') return;
		var d = e.data;
		if (Array.isArray(d.nuDesignerSel)) {
			// a field still being typed in (the designer keeps the focus on a click): its change is saved first
			var a = document.activeElement;
			if (a && panel.contains(a) && (a.tagName === 'TEXTAREA' || (a.tagName === 'INPUT' && a.type !== 'checkbox'))) a.dispatchEvent(new Event('change'));
			selIds = d.nuDesignerSel.slice();
			renderPanel();
			if (flyKind === 'structure') renderFly();
			return;
		}
		if (!S) return;
		if (d.nuDesignerDelete) { deleteObjs((Array.isArray(d.nuDesignerDelete) ? d.nuDesignerDelete : []).map(objByFid)); return; }
		if (d.nuDesigner) {                                        // one object moved / resized (absolute layout)
			var o = objByFid(d.nuDesigner.id);
			if (!o) return;
			var f = {};
			if (d.nuDesigner.top !== undefined) { f.sob_all_top = d.nuDesigner.top; f.sob_all_left = d.nuDesigner.left; }
			if (d.nuDesigner.width !== undefined) f.sob_all_width = Math.max(10, d.nuDesigner.width - lookupExtra(o));
			if (d.nuDesigner.height !== undefined) f.sob_all_height = d.nuDesigner.height;
			saveObj(o, f, false);
			return;
		}
		if (d.nuDesignerBatch) {                                   // several objects (group move, Arrange panel, arrow keys)
			var items = [];
			d.nuDesignerBatch.forEach(function (it) {
				var ob = objByFid(it.id);
				if (!ob) return;
				var x = {object_id: ob.zzzzsys_object_id, top: it.top, left: it.left};
				if (it.width !== undefined) x.width = Math.max(10, it.width - lookupExtra(ob));
				if (it.height !== undefined) x.height = it.height;
				items.push(x);
			});
			if (items.length) change('objects.geometry', {items: items}, false).catch(function () { /* said */ });
			return;
		}
		if (d.nuDesignerOrder) { order(d.nuDesignerOrder); return; }
		if (d.nuDesignerSpan) { var os = objByFid(d.nuDesignerSpan.id); if (os) gridSave(os, {span: d.nuDesignerSpan.span}); return; }
		if (d.nuDesignerMove) { gridMove(d.nuDesignerMove); }
	});
	// a lookup's wrapper holds code + description: its stored width is the code field's
	function lookupExtra(o) {
		if (o.sob_all_type !== 'lookup') return 0;
		return (o.sob_lookup_description_width === '' || o.sob_lookup_description_width === null || o.sob_lookup_description_width === undefined ? 150 : +o.sob_lookup_description_width || 0) + 34;
	}
	// Arrange panel Up / Down: the selected objects move one place in the order of their tab (as the builder, 0.8.606)
	function order(m) {
		var want = (m.ids || []).map(objByFid).filter(Boolean);
		if (!want.length) return;
		var ids = S.objects.map(function (x) { return x.zzzzsys_object_id; }), chosen = want.map(function (x) { return x.zzzzsys_object_id; });
		var dir = m.dir < 0 ? -1 : 1, tabOf = {};
		S.objects.forEach(function (x) { tabOf[x.zzzzsys_object_id] = x.sob_all_zzzzsys_tab_id; });
		var idx = ids.map(function (x, i) { return dir < 0 ? i : ids.length - 1 - i; });
		idx.forEach(function (i) {
			if (chosen.indexOf(ids[i]) < 0) return;
			for (var j = i + dir; j >= 0 && j < ids.length; j += dir) {
				if (tabOf[ids[j]] !== tabOf[ids[i]]) continue;
				if (chosen.indexOf(ids[j]) >= 0) break;
				var tmp = ids[i]; ids[i] = ids[j]; ids[j] = tmp;
				break;
			}
		});
		change('object.order', {ids: ids}, false).then(function () { post({nuBuilderOrder: S.objects.map(function (x) { return x.sob_all_id; })}); }).catch(function () { /* said */ });
	}
	// auto / grid layout: an object dropped before / after / below / into another
	function gridMove(m) {
		ensureGrid().then(function () {
			var o2 = objByFid(m.id), tgt = objByFid(m.target);
			if (!o2 || !tgt) return null;
			var tg = nunext(tgt), place;
			if (m.side === 'into') {
				var maxRow = S.objects.filter(function (x) { return nunext(x).box === tgt.sob_all_id; }).reduce(function (mx, x) { return Math.max(mx, +nunext(x).row || 0); }, 0);
				place = {row: maxRow + 10, col: 10, box: tgt.sob_all_id};
			} else if (m.side === 'below') place = {row: (+tg.row || 0) + 5, col: 10, box: tg.box || ''};
			else place = {row: +tg.row || 10, col: (+tg.col || 10) + (m.side === 'before' ? -5 : 5), box: tg.box || ''};
			return change('object.save', {object_id: o2.zzzzsys_object_id, fields: {sob_all_zzzzsys_tab_id: tgt.sob_all_zzzzsys_tab_id},
				grid: {row: place.row, col: place.col, span: nunext(o2).span || '', box: place.box}}, true);
		}).catch(function () { /* said */ });
	}

	// ------------------------------------------------------------------ workspace (0.8.668): the form's code, History and the draft's changes
	// A full-width view over the form (below the navbar): Code = form JavaScript (all / edit / browse), CSS, the PHP hooks and the browse
	// SQL in one editor with the code editors' toolbar; History = the builder's snapshots (compare with now, restore); Changes = what the
	// draft changes compared with the published form, with a warning when the published form was changed meanwhile.
	var CODE_ITEMS = [['sfo_javascript', 'builder_js_both', 'JS'], ['sfo_edit_javascript', 'builder_js_edit', 'JS'], ['sfo_browse_javascript', 'builder_js_browse', 'JS'],
		['sfo_style', 'builder_css', 'CSS']].concat(['BE', 'BS', 'AS', 'BB', 'BD', 'AD'].map(function (x) { return ['php:' + x, 'builder_php_' + x, 'PHP']; }))
		.concat([['sfo_browse_sql', 'builder_browse_sql', 'SQL']]);
	var work = el('div', {'class': 'nuDsWork', id: 'nuDsWork', role: 'dialog', 'aria-modal': 'true', 'aria-label': t('ds_workspace'), hidden: true});
	var W = {kind: '', item: '', docs: {}, cm: null, ranCode: false};
	function codeItems() { return D.userhome ? CODE_ITEMS.filter(function (x) { return x[0] === 'php:BE'; }) : CODE_ITEMS; }
	function codeValue(key) {
		if (!S) return '';
		if (key.indexOf('php:') === 0) return (S.php && S.php[key.substr(4)]) || '';
		return S.form[key] || '';
	}
	function codeDirty() { return Object.keys(W.docs).filter(function (k) { return W.docs[k].getValue() !== codeValue(k); }); }
	function openWork(kind) {
		if (!S) return;
		closeFly();
		W.kind = kind;
		work.hidden = false;
		document.documentElement.classList.add('nuDsWorkOpen');
		renderWork();
	}
	function closeWork(force) {
		if (!force && codeDirty().length && !window.confirm(t('ds_code_unsaved'))) return;
		W.kind = '';
		W.docs = {};
		W.cm = null;
		work.hidden = true;
		work.innerHTML = '';
		document.documentElement.classList.remove('nuDsWorkOpen');
		renderRail();
		if (W.ranCode) { W.ranCode = false; reloadKeep(); }        // changed code runs only after a reload
	}
	function renderRail() {
		document.querySelectorAll('[data-work]').forEach(function (b) { b.setAttribute('aria-pressed', b.getAttribute('data-work') === W.kind ? 'true' : 'false'); });
	}
	function renderWork() {
		if (!W.kind) return;
		if (W.kind !== 'code') { W.docs = {}; W.cm = null; }
		work.innerHTML = '';
		var tabs = el('div', {'class': 'nav nav-tabs nuDsWorkTabs', role: 'tablist'});
		[['code', 'bi-code-square', 'ds_code'], ['history', 'bi-clock-history', 'ds_history'], ['changes', 'bi-file-diff', 'ds_changes']].forEach(function (x) {
			if (x[0] === 'changes' && !DR) return;
			tabs.appendChild(el('button', {type: 'button', role: 'tab', 'class': 'nav-link' + (W.kind === x[0] ? ' active' : ''), id: 'nuDsWorkTab_' + x[0], 'aria-selected': W.kind === x[0] ? 'true' : 'false',
				onclick: function () { if (W.kind === x[0]) return; if (W.kind === 'code' && codeDirty().length && !window.confirm(t('ds_code_unsaved'))) return; W.kind = x[0]; renderWork(); }}, [icon(x[1] + ' me-1'), t(x[2])]));
		});
		var body = el('div', {'class': 'nuDsWorkBody'});
		var tools = el('div', {'class': 'nuDsWorkTools'});
		work.appendChild(el('div', {'class': 'nuDsWorkHead'}, [tabs, tools,
			el('button', {type: 'button', 'class': 'btn btn-outline-primary', id: 'nuDsWorkClose', onclick: function () { closeWork(false); }}, [icon('bi-arrow-left me-1'), t('ds_back_form')])]));
		work.appendChild(body);
		renderRail();
		if (W.kind === 'code') workCode(body, tools);
		else if (W.kind === 'history') workHistory(body);
		else workChanges(body, tools);
	}
	function workCode(body, tools) {
		var list = el('div', {'class': 'nuDsCodeList', role: 'tablist', 'aria-orientation': 'vertical'});
		var edWrap = el('div', {'class': 'nuDsCodeEd'});
		var cmTools = el('span', {'class': 'nuDsCodeTools'}), hash = el('span', {'class': 'nuDsCodeHash'}), lang = el('span', {'class': 'badge text-bg-secondary ms-auto'});
		var name = el('strong', {'class': 'nuDsCodeName'});
		var ta = el('textarea', {'aria-label': t('ds_code')});
		edWrap.appendChild(el('div', {'class': 'nuDsCodeBar'}, [name, cmTools, hash, lang]));
		edWrap.appendChild(ta);
		edWrap.appendChild(el('div', {'class': 'small text-body-secondary mt-1', text: t('ds_code_help')}));
		body.appendChild(el('div', {'class': 'nuDsCodeLayout'}, [list, edWrap]));
		var save = el('button', {type: 'button', 'class': 'btn btn-primary', id: 'nuDsCodeSave', disabled: true, onclick: saveCode}, [icon('bi-floppy me-1'), t('save')]);
		tools.appendChild(save);
		var groups = {};
		codeItems().forEach(function (it) {
			var g = it[2] === 'PHP' ? 'PHP' : (it[2] === 'SQL' ? 'SQL' : 'JavaScript & CSS');
			if (!groups[g]) { groups[g] = true; list.appendChild(el('div', {'class': 'nuDsCodeGroup', text: g})); }
			list.appendChild(el('button', {type: 'button', role: 'tab', 'class': 'nuDsCodeItem' + (codeValue(it[0]) ? ' nuDsHasCode' : ''), 'data-code': it[0],
				'aria-selected': 'false', onclick: function () { pick(it[0]); }}, [t(it[1])]));
		});
		var marks = function () {
			var dirty = codeDirty();
			list.querySelectorAll('.nuDsCodeItem').forEach(function (b) {
				var k = b.getAttribute('data-code');
				b.classList.toggle('nuDsCodeDirty', dirty.indexOf(k) >= 0);
				b.classList.toggle('nuDsHasCode', !!(W.docs[k] ? W.docs[k].getValue() : codeValue(k)));
			});
			save.disabled = !dirty.length;
		};
		var modeOf = function (l) { return {SQL: 'text/x-sql', PHP: {name: 'php', startOpen: true}, CSS: 'css'}[l] || 'javascript'; };
		var itemOf = function (k) { return codeItems().filter(function (x) { return x[0] === k; })[0]; };
		function pick(k) {
			var it = itemOf(k);
			if (!it || !W.cm) { W.item = k; return; }
			W.item = k;
			if (!W.docs[k]) { W.docs[k] = window.CodeMirror.Doc(codeValue(k), modeOf(it[2])); W.docs[k].on('change', marks); }
			W.cm.swapDoc(W.docs[k]);
			name.textContent = t(it[1]);
			lang.textContent = it[2];
			list.querySelectorAll('.nuDsCodeItem').forEach(function (b) { var on = b.getAttribute('data-code') === k; b.classList.toggle('active', on); b.setAttribute('aria-selected', on ? 'true' : 'false'); });
			hash.innerHTML = '';
			if (window.NuHashVars && N.hashCatalog && (it[2] === 'SQL' || it[2] === 'PHP')) window.NuHashVars.attach(W.cm, hash, {catalog: N.hashCatalog, globals: N.globals});
			cmTools.innerHTML = '';
			if (window.NuCodeTools) window.NuCodeTools.attach(W.cm, cmTools, {lang: it[2], db: N.db_type});
			W.cm.refresh();
			W.cm.focus();
		}
		function saveCode() {
			var dirty = codeDirty();
			if (!dirty.length) return;
			var fields = {}, php = {};
			dirty.forEach(function (k) { var v = W.docs[k].getValue(); if (k.indexOf('php:') === 0) php[k.substr(4)] = v; else fields[k] = v; });
			save.disabled = true;
			var data = {fields: fields};
			if (Object.keys(php).length) data.php = php;
			change('form.save', data, false).then(function () {
				W.ranCode = W.ranCode || dirty.some(function (k) { return k.indexOf('php:') < 0 && k !== 'sfo_browse_sql'; }) || 'BE' in php;
				marks();
				say(W.ranCode ? t('ds_code_saved_reload') : t('ds_saved'));
			}).catch(function () { marks(); });
		}
		W.saveCode = saveCode;
		loadCM().then(function () {
			if (W.kind !== 'code' || !ta.isConnected) return;
			W.cm = window.CodeMirror.fromTextArea(ta, {lineNumbers: true, lineWrapping: true, indentWithTabs: true, tabSize: 4,
				theme: document.documentElement.getAttribute('data-bs-theme') === 'dark' ? 'default nuCmDark' : 'default',
				extraKeys: {'Ctrl-S': saveCode, 'Cmd-S': saveCode}});
			W.cm.setSize(null, '100%');
			var first = W.item && itemOf(W.item) ? W.item : (codeItems().filter(function (x) { return codeValue(x[0]); })[0] || codeItems()[0])[0];
			pick(first);
			marks();
		}, function () { say('CodeMirror', true); });
	}
	function workHistory(body) {
		body.appendChild(el('p', {'class': 'small text-body-secondary', text: DR ? t('ds_history_draft') : t('builder_history_help')}));
		var box = el('div', {'class': 'nuDsHistory', id: 'nuDsHistory'}, [el('div', {'class': 'text-body-secondary small', text: '…'})]);
		var cmp = el('div', {'class': 'nuDsHistDiff', id: 'nuDsHistDiff'});
		body.appendChild(el('div', {'class': 'nuDsHistLayout'}, [box, cmp]));
		api('history.list', {}).then(function (r) {
			box.innerHTML = '';
			if (!r.ok) throw new Error(r.error || 'Error');
			if (!r.history.length) { box.appendChild(el('p', {'class': 'text-body-secondary', text: t('builder_history_empty')})); return; }
			var ul = el('ul', {'class': 'list-group'});
			r.history.forEach(function (it) {
				var ver = /^published v(\d+)/.exec(it.action || '');
				var li = el('li', {'class': 'list-group-item nuDsHistItem'}, [
					el('div', {'class': 'flex-grow-1 small'}, [
						el('div', {'class': 'fw-semibold'}, [ver ? el('span', {'class': 'badge text-bg-success me-2', text: 'v' + ver[1]}) : null, it.action]),
						el('div', {'class': 'text-body-secondary', text: it.created_at + ' · ' + (it.login || '')})]),
					el('button', {type: 'button', 'class': 'btn btn-sm btn-outline-secondary nuDsHistCmp', text: t('ds_compare'), onclick: function () {
						ul.querySelectorAll('.nuDsHistItem').forEach(function (x) { x.classList.toggle('active', x === li); });
						cmp.innerHTML = '';
						cmp.appendChild(el('div', {'class': 'text-body-secondary small', text: '…'}));
						api('history.diff', {id: it.id}).then(function (d) {
							cmp.innerHTML = '';
							if (!d.ok) throw new Error(d.error || 'Error');
							cmp.appendChild(el('h3', {'class': 'h6', text: fmt(t(DR ? 'ds_compare_draft' : 'ds_compare_now'), it.created_at)}));
							cmp.appendChild(diffView(d.diff));
						}).catch(function (e) { cmp.textContent = e.message; });
					}}),
					DR ? null : el('button', {type: 'button', 'class': 'btn btn-sm btn-outline-primary nuDsHistRestore', text: t('builder_restore'), onclick: function () {
						confirmAsk(t('builder_restore_confirm'), function () {
							busy(true);
							api('history.restore', {id: it.id}, true).then(function (j) { if (!j.ok) throw new Error(j.error || 'Error'); closeWork(true); reloadKeep(); })
								.catch(function (e) { busy(false); say(e.message, true); });
						}, t('builder_restore'), 'btn btn-primary');
					}})
				]);
				ul.appendChild(li);
			});
			box.appendChild(ul);
		}).catch(function (e) { box.textContent = e.message; });
	}
	function workChanges(body, tools) {
		if (D.can_publish) tools.appendChild(el('button', {type: 'button', 'class': 'btn btn-primary', id: 'nuDsWorkPublish', onclick: function () { closeWork(false); publish(); }}, [icon('bi-rocket-takeoff me-1'), t('builder_draft_publish_btn')]));
		var head = el('div', {}), box = el('div', {id: 'nuDsChanges'}, [el('div', {'class': 'text-body-secondary small', text: '…'})]);
		body.appendChild(head);
		body.appendChild(box);
		api('draft.conflict', {}).then(function (c) {
			if (c.ok && c.conflict) head.appendChild(el('div', {'class': 'alert alert-warning small', id: 'nuDsConflict'}, [el('strong', {text: t('builder_draft_conflict')}), ' ' + t('ds_conflict_text'),
				el('details', {'class': 'mt-2'}, [el('summary', {text: t('ds_conflict_show')}), diffView(c.changes)])]));
		}).catch(function () { /* the diff below says enough */ });
		api('draft.diff', {}).then(function (r) {
			box.innerHTML = '';
			if (!r.ok) throw new Error(r.error || 'Error');
			box.appendChild(el('p', {'class': 'small text-body-secondary', text: fmt(t('builder_draft_changes_n'), r.diff.counts.total)}));
			box.appendChild(diffView(r.diff));
			if (r.ddl && r.ddl.length) box.appendChild(el('p', {'class': 'small mt-2'}, [t('builder_draft_ddl') + ' ', r.ddl.map(function (x) { return x.table + '.' + x.column + (x.change ? ' (' + x.change + ')' : ''); }).join(', ')]));
			var v = r.validation || {};
			if (v.errors && v.errors.length) box.appendChild(el('div', {'class': 'alert alert-danger small mt-2'}, [t('builder_draft_errors'), el('ul', {'class': 'mb-0'}, v.errors.map(function (x) { return el('li', {text: String(x)}); }))]));
			if (v.warnings && v.warnings.length) box.appendChild(el('div', {'class': 'alert alert-warning small mt-2'}, [t('builder_draft_warnings'), el('ul', {'class': 'mb-0'}, v.warnings.map(function (x) { return el('li', {text: String(x)}); }))]));
		}).catch(function (e) { box.textContent = e.message; });
	}
	// the differences (NuFormSnapshot::diff) – as in the builder's compare panel
	function lineDiff(a, b) {
		var x = String(a || '').split('\n'), y = String(b || '').split('\n');
		if (x.length * y.length > 4000000) return null;
		var n = x.length, m = y.length, Lc = [], i, j;
		for (i = 0; i <= n; i++) Lc.push(new Uint32Array(m + 1));
		for (i = n - 1; i >= 0; i--) for (j = m - 1; j >= 0; j--) Lc[i][j] = x[i] === y[j] ? Lc[i + 1][j + 1] + 1 : Math.max(Lc[i + 1][j], Lc[i][j + 1]);
		var out = []; i = 0; j = 0;
		while (i < n && j < m) {
			if (x[i] === y[j]) { out.push(['=', x[i]]); i++; j++; } else if (Lc[i + 1][j] >= Lc[i][j + 1]) out.push(['-', x[i++]]); else out.push(['+', y[j++]]);
		}
		while (i < n) out.push(['-', x[i++]]);
		while (j < m) out.push(['+', y[j++]]);
		return out;
	}
	function codeDiff(a, b) {
		var d = lineDiff(a, b);
		if (!d) return el('div', {'class': 'small text-body-secondary', text: t('builder_draft_diff_large')});
		var keep = d.map(function () { return false; });
		d.forEach(function (r, k) { if (r[0] !== '=') for (var q = Math.max(0, k - 2); q <= Math.min(d.length - 1, k + 2); q++) keep[q] = true; });
		var pre = el('pre', {'class': 'nuDsDiffCode'}), folded = false;
		d.forEach(function (r, k) {
			if (!keep[k]) { if (!folded) pre.appendChild(el('div', {'class': 'nuDsDiffFold', text: '…'})); folded = true; return; }
			folded = false;
			pre.appendChild(el('div', {'class': r[0] === '+' ? 'nuDsDiffAdd' : (r[0] === '-' ? 'nuDsDiffDel' : ''), text: (r[0] === '=' ? '  ' : r[0] + ' ') + r[1]}));
		});
		return pre;
	}
	var CODE_COLS = ['sfo_javascript', 'sfo_browse_javascript', 'sfo_edit_javascript', 'sfo_browse_sql', 'sfo_style', 'sob_select_sql', 'sob_display_sql', 'sob_html_code',
		'sob_input_javascript', 'sob_lookup_javascript', 'sob_html_javascript', 'sob_all_access_condition', 'sob_calc_formula'];
	function fieldRows(fields) {
		return el('div', {}, fields.map(function (f) {
			if (CODE_COLS.indexOf(f[0]) >= 0) return el('div', {'class': 'mt-1'}, [el('div', {'class': 'small fw-semibold', text: f[0]}), codeDiff(f[1], f[2])]);
			return el('div', {'class': 'small'}, [el('code', {text: f[0]}), ': ', el('span', {'class': 'nuDsDiffDel px-1', text: f[1] === null ? '∅' : String(f[1])}), ' → ',
				el('span', {'class': 'nuDsDiffAdd px-1', text: f[2] === null ? '∅' : String(f[2])})]);
		}));
	}
	function diffView(d) {
		var box = el('div', {'class': 'nuDsDiff'});
		if (!d || !d.counts || !d.counts.total) { box.appendChild(el('p', {'class': 'text-body-secondary mb-0', text: t('builder_draft_no_changes')})); return box; }
		var sec = function (title, kids) { if (kids.length) box.appendChild(el('details', {'class': 'mb-2', open: true}, [el('summary', {'class': 'fw-semibold', text: title})].concat(kids))); };
		var li = function (cls, text, extra) { return el('div', {'class': 'small ' + cls}, [text].concat(extra || [])); };
		sec(t('builder_sec_form'), d.form.length ? [fieldRows(d.form)] : []);
		var tabs = [];
		d.tabs.added.forEach(function (x) { tabs.push(li('nuDsDiffAdd', '+ ' + plain(x.title))); });
		d.tabs.removed.forEach(function (x) { tabs.push(li('nuDsDiffDel', '− ' + plain(x.title))); });
		d.tabs.changed.forEach(function (x) { tabs.push(li('', '~ ' + plain(x.title), [fieldRows(x.fields)])); });
		if (d.tabs.order) tabs.push(li('', t('builder_draft_order')));
		sec(t('builder_sec_tabs'), tabs);
		var objs = [];
		d.objects.added.forEach(function (x) { objs.push(li('nuDsDiffAdd', '+ ' + x.sob_all_id + ' (' + x.type + ') ' + plain(x.label))); });
		d.objects.removed.forEach(function (x) { objs.push(li('nuDsDiffDel', '− ' + x.sob_all_id + ' (' + x.type + ')')); });
		d.objects.changed.forEach(function (x) { objs.push(li('', '~ ' + x.sob_all_id, [fieldRows(x.fields)])); });
		if (d.objects.moved) objs.push(li('', fmt(t('builder_draft_moved'), d.objects.moved)));
		sec(t('builder_objects'), objs);
		sec(t('builder_sec_events'), (d.events || []).map(function (x) { return li('', x.sob_all_id + ' · ' + x.event, [codeDiff(x.old, x.new)]); }));
		sec('PHP', (d.php || []).map(function (x) { return li('', x.hook === 'AB' ? x.id : x.hook, [codeDiff(x.old, x.new)]); }));
		var br = [];
		if (d.browse) {
			d.browse.added.forEach(function (x) { br.push(li('nuDsDiffAdd', '+ ' + (x.title || x.display))); });
			d.browse.removed.forEach(function (x) { br.push(li('nuDsDiffDel', '− ' + (x.title || x.display))); });
			d.browse.changed.forEach(function (x) { br.push(li('', '~ ' + x.title, [fieldRows(x.fields)])); });
			if (d.browse.order) br.push(li('', t('builder_draft_order')));
		}
		sec(t('builder_sec_browse'), br);
		if (d.sql_builder) sec('SQL builder', [li('', t('builder_draft_sql_builder'))]);
		return box;
	}
	work.addEventListener('keydown', function (e) {
		if (e.key === 'Escape' && !document.querySelector('.modal.show') && !(e.target.closest && e.target.closest('.CodeMirror-hints'))) { e.preventDefault(); e.stopPropagation(); closeWork(false); }
	});
	window.addEventListener('beforeunload', function (e) { if (W.kind === 'code' && codeDirty().length) { e.preventDefault(); e.returnValue = ''; } });

	// ------------------------------------------------------------------ the list (0.8.669): columns designed where the list runs
	// Click a column title to select it (the panel shows its properties), drag titles to reorder, "+" adds a column; every change is
	// saved with browse.save (the whole list of columns, in the draft like every other change) and the list is drawn again.
	function colById(id) { return id && S && S.browse ? S.browse.filter(function (c) { return c.zzzzsys_browse_id === id; })[0] : null; }
	function colFilter(c) { try { return !!((JSON.parse(c.sbr_json || '{}').nunext || {}).filter); } catch (e) { return false; } }
	function colRows(edit) {
		return (S.browse || []).map(function (c) {
			var x = {id: c.zzzzsys_browse_id, title: c.sbr_title || '', display: c.sbr_display || '', align: c.sbr_align || 'l', format: c.sbr_format || '',
				width: c.sbr_width === null || c.sbr_width === undefined || c.sbr_width === '' ? 100 : +c.sbr_width, filter: colFilter(c)};
			return edit && edit[x.id] ? Object.assign(x, edit[x.id]) : x;
		});
	}
	function saveCols(rows, keepSel) {
		if (keepSel !== undefined) selCol = keepSel;
		return change('browse.save', {columns: rows}, true).catch(function () { /* said */ });
	}
	function colSave(c, part) { var e = {}; e[c.zzzzsys_browse_id] = part; saveCols(colRows(e), c.zzzzsys_browse_id); }
	function colMove(c, d) {
		var rows = colRows(), i = rows.map(function (x) { return x.id; }).indexOf(c.zzzzsys_browse_id);
		if (i < 0 || i + d < 0 || i + d >= rows.length) return;
		var x = rows.splice(i, 1)[0];
		rows.splice(i + d, 0, x);
		saveCols(rows, c.zzzzsys_browse_id);
	}
	function colAdd(display) {
		var rows = colRows();
		var name = display || '';
		rows.push({id: '', title: name ? labelOf(name) : t('ds_col_new'), display: name || "''", align: 'l', format: '', width: 120, filter: false});
		selCol = '__last';
		saveCols(rows);
	}
	function colDelete(c) {
		confirmAsk(fmt(t('ds_col_delete_confirm'), plain(c.sbr_title) || c.sbr_display), function () {
			saveCols(colRows().filter(function (x) { return x.id !== c.zzzzsys_browse_id; }), '');
		}, t('delete'), 'btn btn-danger');
	}
	var listCols = null;
	function columnsList() {
		if (listCols === null && S.form.sfo_table) {
			listCols = [];
			api('columns', {table: S.form.sfo_table}, true).then(function (j) { listCols = j.ok ? j.columns : []; var dl = document.getElementById('nuDsColNames'); if (dl) fillCols(dl); }).catch(function () { listCols = []; });
		}
		var dl = el('datalist', {id: 'nuDsColNames'});
		fillCols(dl);
		return dl;
	}
	function fillCols(dl) { dl.innerHTML = ''; (listCols || []).forEach(function (c) { dl.appendChild(el('option', {value: c.name})); }); }
	function columnsSection() {
		var rows = (S.browse || []).map(function (c) {
			var hidden = +c.sbr_width === 0;
			return el('button', {type: 'button', 'class': 'nuDsTreeRow', 'data-col': c.zzzzsys_browse_id, onclick: function () { selCol = c.zzzzsys_browse_id; markCols(); renderPanel(); }}, [
				icon('bi-layout-three-columns nuDsTreeIcon'), el('span', {'class': 'nuDsTreeId', text: plain(c.sbr_title) || '–'}),
				hidden ? el('span', {title: t('ds_col_hidden')}, [icon('bi-eye-slash')]) : null,
				el('span', {'class': 'nuDsTreeType', text: c.sbr_display})]);
		});
		section('columns', t('builder_sec_columns'), [el('div', {'class': 'nuDsTree', id: 'nuDsColTree'}, rows),
			el('div', {'class': 'nuDsField'}, [el('button', {type: 'button', 'class': 'btn btn-sm btn-outline-primary', id: 'nuDsColAdd', onclick: function () { colAdd(''); }}, [icon('bi-plus-lg me-1'), t('builder_add_column')])])]);
	}
	function renderColumn(c) {
		var btns = [
			el('button', {type: 'button', 'class': 'btn btn-sm btn-outline-secondary', 'data-ds-col': 'left', title: t('ds_col_left'), 'aria-label': t('ds_col_left'), onclick: function () { colMove(c, -1); }}, [icon('bi-arrow-left')]),
			el('button', {type: 'button', 'class': 'btn btn-sm btn-outline-secondary', 'data-ds-col': 'right', title: t('ds_col_right'), 'aria-label': t('ds_col_right'), onclick: function () { colMove(c, 1); }}, [icon('bi-arrow-right')]),
			el('button', {type: 'button', 'class': 'btn btn-sm btn-outline-danger nuDsDel', id: 'nuDsColDelete', title: t('delete'), 'aria-label': t('delete'), onclick: function () { colDelete(c); }}, [icon('bi-trash')])
		];
		panel.appendChild(head(t('ds_column'), plain(c.sbr_title) || c.sbr_display, 'bi-layout-three-columns', '', btns));
		var th = document.querySelector('#nuBrowseTable th[data-nu-browse-id="' + c.zzzzsys_browse_id + '"]');
		section('content', t('ds_sec_content'), [
			field(t('builder_col_title'), 'text', c.sbr_title, function (v) { colSave(c, {title: v}); }, {key: 'sbr_title', live: function (v) { if (th) { var lbl = th.querySelector('a, span') || th; lbl.textContent = v; } }}),
			field(t('builder_col_display'), 'text', c.sbr_display, function (v) { colSave(c, {display: v}); }, {key: 'sbr_display', mono: true, list: 'nuDsColNames', tip: t('ds_col_display_tip')}),
			columnsList(),
			field(t('builder_col_format'), 'format', c.sbr_format || '', function (v) { colSave(c, {format: v}); }, {key: 'sbr_format', letters: 'DNT', empty: '—'})
		]);
		section('layout', t('ds_sec_layout'), [
			el('div', {'class': 'nuDsGrid2'}, [
				field(t('builder_col_width'), 'number', c.sbr_width === null || c.sbr_width === undefined ? 100 : c.sbr_width, function (v) { colSave(c, {width: v}); },
					{key: 'sbr_width', tip: t('ds_col_width_tip'), live: function (v) { var n2 = parseInt(v, 10); if (th && n2 > 0) th.style.width = n2 + 'px'; }}),
				field(t('builder_col_align'), 'select', c.sbr_align || 'l', function (v) { colSave(c, {align: v}); }, {key: 'sbr_align', options: [['l', t('ds_align_left')], ['c', t('ds_align_center')], ['r', t('ds_align_right')]]})])
		]);
		section('behaviour', t('ds_sec_behaviour'), [
			field(t('builder_col_filter'), 'check', colFilter(c) ? '1' : '0', function (v) { colSave(c, {filter: v === '1'}); }, {key: 'filter'})
		]);
		foot([el('p', {'class': 'nuDsHint', text: t('ds_hint_list')})]);
	}
	// right-click on a column title (0.8.672, as nuBuilder Forte's menu of a browse title): Object, Properties, Rename…, Align, Width,
	// Filter, Move left / right, Hide / Show, Delete – the same look and keys as the form's object menu (nuform.css .nuObjMenu)
	var colMenu = null;
	function closeColMenu() { if (colMenu) { colMenu.remove(); colMenu = null; } }
	function colMenuOpen(th, x, y) {
		closeColMenu();
		var c = colById(th.getAttribute('data-nu-browse-id'));
		if (!c) return;
		selCol = c.zzzzsys_browse_id; markCols(); renderPanel();
		var oid = th.id || ('nuBrowseTitle' + th.getAttribute('data-nu-column'));
		var item = function (ic, text, fn, opt) {
			opt = opt || {};
			var b = el('button', {type: 'button', 'class': 'dropdown-item d-flex align-items-center gap-2' + (opt.current ? ' fw-bold' : '') + (opt.danger ? ' text-danger' : ''),
				'aria-current': opt.current ? 'true' : null, disabled: opt.disabled ? true : null}, [icon((ic || 'bi-dot') + (ic ? '' : ' invisible')), el('span', {'class': 'me-auto', text: text}),
				opt.current ? icon('bi-check2') : null, opt.sub ? icon('bi-chevron-right small') : null]);
			var li = el('li', {}, [b]);
			if (opt.sub) {
				b.classList.add('nuOmHasSub');
				b.setAttribute('aria-haspopup', 'true');
				b.setAttribute('aria-expanded', 'false');
				li.classList.add('nuOmSubWrap');
				li.appendChild(el('ul', {'class': 'dropdown-menu nuOmSub'}, opt.sub));
				b.addEventListener('click', function (e) { e.stopPropagation(); var on = !li.classList.contains('nuOmOpen'); li.classList.toggle('nuOmOpen', on); b.setAttribute('aria-expanded', on ? 'true' : 'false');
					if (on) { var f = li.querySelector('.nuOmSub button'); if (f) f.focus(); } });
			} else b.addEventListener('click', function (e) { e.stopPropagation(); closeColMenu(); fn(); });
			return li;
		};
		var div = function () { return el('li', {}, [el('hr', {'class': 'dropdown-divider'})]); };
		var al = c.sbr_align || 'l', w = c.sbr_width === null || c.sbr_width === undefined || c.sbr_width === '' ? 100 : +c.sbr_width;
		var rows = colRows(), at = rows.map(function (r) { return r.id; }).indexOf(c.zzzzsys_browse_id);
		var width = el('input', {type: 'number', min: '0', 'class': 'form-control form-control-sm nuOmWidth', id: 'nuDsColMenuWidth', value: String(w), 'aria-label': t('builder_col_width')});
		width.addEventListener('keydown', function (e) {
			e.stopPropagation();
			if (e.key === 'Enter') { e.preventDefault(); var v = Math.max(0, parseInt(width.value, 10) || 0); closeColMenu(); if (v !== w) colSave(c, {width: v}); }
			if (e.key === 'Escape') { e.preventDefault(); closeColMenu(); }
		});
		width.addEventListener('click', function (e) { e.stopPropagation(); });
		var head = el('li', {}, [el('button', {type: 'button', 'class': 'dropdown-item nuOmHead', title: fmt(t('om_copied'), oid), onclick: function (e) {
			e.stopPropagation(); closeColMenu();
			if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(oid).then(function () { say(fmt(t('om_copied'), oid)); }, function () { window.prompt(t('om_object'), oid); });
			else window.prompt(t('om_object'), oid);
		}}, [el('span', {'class': 'text-body-secondary', text: t('om_object') + ': '}), el('b', {text: oid})])]);
		colMenu = el('ul', {'class': 'dropdown-menu show nuObjMenu shadow', id: 'nuDsColMenu', role: 'menu'}, [
			head, div(),
			item('bi-sliders', t('om_properties'), function () { var f = panel.querySelector('[data-key="sbr_title"]'); if (f) f.focus(); }),
			item('bi-input-cursor-text', t('om_rename'), function () {
				var cur = c.sbr_title || '';
				var v = window.prompt(fmt(t('om_rename_prompt'), oid), cur);
				if (v !== null && v.trim() !== '' && v !== cur) colSave(c, {title: v.trim()});
			}),
			div(),
			item('bi-text-left', t('om_align'), null, {sub: [['l', 'om_left', 'bi-text-left'], ['r', 'om_right', 'bi-text-right'], ['c', 'om_center', 'bi-text-center']].map(function (a) {
				return item(a[2], t(a[1]), function () { if (al !== a[0]) colSave(c, {align: a[0]}); }, {current: al === a[0]});
			})}),
			el('li', {'class': 'nuOmWidthRow'}, [el('label', {'class': 'dropdown-item d-flex align-items-center gap-2', 'for': 'nuDsColMenuWidth'}, [icon('bi-arrows'), el('span', {'class': 'me-auto', text: t('builder_col_width')}), width])]),
			item('bi-funnel', t('builder_col_filter'), function () { colSave(c, {filter: !colFilter(c)}); }, {current: colFilter(c)}),
			div(),
			item('bi-arrow-left', t('ds_col_left'), function () { colMove(c, -1); }, {disabled: at <= 0}),
			item('bi-arrow-right', t('ds_col_right'), function () { colMove(c, 1); }, {disabled: at >= rows.length - 1}),
			item(w === 0 ? 'bi-eye' : 'bi-eye-slash', t(w === 0 ? 'ds_col_show' : 'ds_col_hide'), function () { colSave(c, {width: w === 0 ? 100 : 0}); }),
			div(),
			item('bi-trash', t('delete'), function () { colDelete(c); }, {danger: true})
		]);
		document.body.appendChild(colMenu);
		var mw = colMenu.offsetWidth, mh = colMenu.offsetHeight, vw = document.documentElement.clientWidth, vh = document.documentElement.clientHeight;
		var left = Math.max(4, Math.min(x, vw - mw - 4));
		colMenu.classList.toggle('nuOmFlip', left + mw + 220 > vw);
		colMenu.style.position = 'fixed';
		colMenu.style.left = left + 'px';
		colMenu.style.top = Math.max(4, Math.min(y, vh - mh - 4)) + 'px';
		colMenu.style.zIndex = '1070';
		var first = colMenu.querySelector('button');
		if (first) first.focus();
	}
	document.addEventListener('pointerdown', function (e) { if (colMenu && !colMenu.contains(e.target)) closeColMenu(); }, true);
	window.addEventListener('keydown', function (e) {
		if (!colMenu || ['Escape', 'ArrowDown', 'ArrowUp', 'ArrowLeft', 'ArrowRight'].indexOf(e.key) < 0 || e.target === document.getElementById('nuDsColMenuWidth')) return;
		e.preventDefault();
		e.stopPropagation();
		var act = document.activeElement;
		var list = Array.prototype.filter.call(colMenu.querySelectorAll('button:not([disabled]), input'), function (b) { return b.offsetParent !== null; }), i = list.indexOf(act);
		var wrap = act && act.closest ? act.closest('.nuOmSubWrap.nuOmOpen') : null;
		if (e.key === 'Escape') { if (wrap && wrap.querySelector('.nuOmSub').contains(act)) { wrap.classList.remove('nuOmOpen'); wrap.querySelector('button').focus(); } else closeColMenu(); }
		else if (e.key === 'ArrowDown' || e.key === 'ArrowUp') { if (list.length) list[i < 0 ? 0 : (i + (e.key === 'ArrowDown' ? 1 : list.length - 1)) % list.length].focus(); }
		else if (e.key === 'ArrowRight' && act && act.classList.contains('nuOmHasSub')) act.click();
		else if (e.key === 'ArrowLeft' && wrap) { wrap.classList.remove('nuOmOpen'); wrap.querySelector('button').focus(); }
	}, true);
	window.addEventListener('scroll', closeColMenu, true);

	function markCols() {
		document.querySelectorAll('#nuBrowseTable th.nuBrowseTitle').forEach(function (th) {
			th.classList.toggle('nuDsColSel', th.getAttribute('data-nu-browse-id') === selCol);
		});
	}
	function setupBrowse() {
		var table = document.getElementById('nuBrowseTable');
		if (!table) return;
		table.classList.add('nuDsBrowse');
		var drag = null;
		table.querySelectorAll('th.nuBrowseTitle').forEach(function (th) {
			var id = th.getAttribute('data-nu-browse-id');
			th.setAttribute('draggable', 'true');
			th.title = t('ds_col_hint');
			th.addEventListener('dragstart', function (e) { drag = id; e.dataTransfer.effectAllowed = 'move'; try { e.dataTransfer.setData('text/plain', id); } catch (x) { /* ignore */ } th.classList.add('nuDsDragging'); });
			th.addEventListener('dragend', function () { drag = null; th.classList.remove('nuDsDragging'); });
			th.addEventListener('dragover', function (e) { if (drag && drag !== id) { e.preventDefault(); th.classList.add('nuDsDropBefore'); } });
			th.addEventListener('dragleave', function () { th.classList.remove('nuDsDropBefore'); });
			th.addEventListener('drop', function (e) {
				e.preventDefault();
				th.classList.remove('nuDsDropBefore');
				if (!drag || drag === id) return;
				var rows = colRows(), from = rows.filter(function (x) { return x.id === drag; })[0];
				rows.splice(rows.indexOf(from), 1);
				rows.splice(rows.map(function (x) { return x.id; }).indexOf(id), 0, from);
				saveCols(rows, drag);
			});
		});
		table.addEventListener('contextmenu', function (e) {
			var th = e.target.closest && e.target.closest('th.nuBrowseTitle');
			if (!th || e.ctrlKey) return;                       // Ctrl + right-click: the browser's menu
			e.preventDefault();
			var x = e.clientX, y = e.clientY;
			if (!x && !y) { var rc = th.getBoundingClientRect(); x = rc.left + 8; y = rc.bottom; }
			colMenuOpen(th, x, y);
		});
		// a click on a title selects the column (no sorting while designing)
		table.addEventListener('click', function (e) {
			var th = e.target.closest && e.target.closest('th.nuBrowseTitle');
			if (!th) { if (e.target.closest && e.target.closest('tbody')) { e.preventDefault(); e.stopPropagation(); } return; }
			e.preventDefault();
			e.stopPropagation();
			selCol = th.getAttribute('data-nu-browse-id') || '';
			markCols();
			renderPanel();
		}, true);
		var headRow = table.querySelector('thead tr');
		if (headRow) headRow.appendChild(el('th', {scope: 'col', 'class': 'nuDsColAddTh'}, [el('button', {type: 'button', 'class': 'btn btn-sm nuDsTabAdd', id: 'nuDsColAddTh', title: t('builder_add_column'),
			'aria-label': t('builder_add_column'), onclick: function (e) { e.stopPropagation(); colAdd(''); }}, [icon('bi-plus-lg')])]));
		if (selCol === '__last' && S.browse && S.browse.length) selCol = S.browse[S.browse.length - 1].zzzzsys_browse_id;
		markCols();
	}

	// ------------------------------------------------------------------ shortcuts (0.8.671): rename, copy / paste, next object, the list
	function renameDialog(o) {
		var lab = el('input', {type: 'text', 'class': 'form-control', id: 'nuDsRenLabel', value: o.sob_all_label || ''});
		var fid = el('input', {type: 'text', 'class': 'form-control font-monospace', id: 'nuDsRenId', value: o.sob_all_id, spellcheck: 'false', autocomplete: 'off'});
		var err = el('div', {'class': 'invalid-feedback', text: t('ds_err_id')});
		var ok = el('button', {type: 'button', 'class': 'btn btn-primary', id: 'nuDsRenOk', text: t('ok')});
		var root = modal('nuDsRenameDlg', t('ds_rename_title') + ' · ' + o.sob_all_id, [
			el('label', {'class': 'form-label', 'for': 'nuDsRenLabel', text: t('builder_obj_label')}), lab,
			el('label', {'class': 'form-label mt-3', 'for': 'nuDsRenId', text: t('builder_obj_id')}), fid, err], [ok]);
		var go = function () {
			var id = fid.value.trim(), f = {};
			if (!/^[A-Za-z_][A-Za-z0-9_]*$/.test(id)) { fid.classList.add('is-invalid'); fid.focus(); return; }
			if (lab.value !== (o.sob_all_label || '')) f.sob_all_label = lab.value;
			if (id !== o.sob_all_id) f.sob_all_id = id;
			hide(root);
			if (!Object.keys(f).length) return;
			if (f.sob_all_label !== undefined) liveLabel(o, f.sob_all_label);
			selIds = [id];
			saveObj(o, f, f.sob_all_id !== undefined || !liveLabel(o, null));
		};
		ok.addEventListener('click', go);
		[lab, fid].forEach(function (x) { x.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); go(); } }); });
		fid.addEventListener('input', function () { fid.classList.remove('is-invalid'); });
		root.addEventListener('shown.bs.modal', function () { lab.focus(); lab.select(); });
		root.addEventListener('hidden.bs.modal', function () { root.remove(); });
		show(root);
	}
	// copy / paste: the objects (all their properties and events) in this browser, so they can be pasted into this or another form
	var CLIP = 'nunext-design-clip';
	function clip() { try { var c = JSON.parse(window.localStorage.getItem(CLIP) || 'null'); return c && Array.isArray(c.objs) && c.objs.length ? c : null; } catch (e) { return null; } }
	function copyObjs(objs) {
		var c = {form: N.form.id, tab: activeTabId(), objs: objs.map(function (o) {
			var f = {};
			Object.keys(o).forEach(function (k) { if (k.indexOf('sob_') === 0 && k !== 'sob_all_json' && k !== 'sob_json' && k !== 'sob_all_zzzzsys_form_id' && k !== 'sob_all_zzzzsys_tab_id') f[k] = o[k]; });
			return {fields: f, events: ((S.events && S.events[o.zzzzsys_object_id]) || []).map(function (x) { return {event: x.event, js: x.js}; }),
				ab: o.sob_all_type === 'lookup' ? ((S.lookup_php || {})[o.zzzzsys_object_id] || '') : null};
		})};
		try { window.localStorage.setItem(CLIP, JSON.stringify(c)); } catch (e) { say('localStorage', true); return; }
		say(fmt(t('ds_copied'), objs.map(function (o) { return o.sob_all_id; }).join(', ')));
	}
	function pasteObjs() {
		var c = clip();
		if (!c) return;
		var tab = activeTabId() || ((S.tabs || [])[0] || {}).zzzzsys_tab_id || '';
		var here = c.form === N.form.id && c.tab === tab, ids = [], steps = [];
		var maxOrder = sorted(tab).reduce(function (m, x) { return Math.max(m, +x.sob_all_order || 0); }, 0);
		c.objs.forEach(function (x, i) {
			var f = Object.assign({}, x.fields);
			var id = uniqueId(f.sob_all_id || 'obj');
			while (ids.indexOf(id) >= 0) id = id + '_2';
			ids.push(id);
			f.sob_all_id = id;
			f.sob_all_zzzzsys_tab_id = tab;
			f.sob_all_order = maxOrder + 10 * (i + 1);
			if (here) { f.sob_all_top = (+f.sob_all_top || 0) + 20; f.sob_all_left = (+f.sob_all_left || 0) + 20; }
			var data = {object_id: '', fields: f, events: x.events || []};
			if (x.ab !== null && x.ab !== undefined && x.ab !== '') data.lookup_php = x.ab;
			steps.push(['object.save', data]);
		});
		selIds = ids.slice(0, 1);
		keepTab = tab;
		chain(steps);
	}
	// Tab / Shift+Tab: the next / previous object of the open tab, in tab order
	function stepSel(d) {
		var list = sorted(activeTabId()).filter(function (o) { return document.getElementById('nuw_' + o.sob_all_id); });
		if (!list.length) return;
		var cur = list.map(function (o) { return o.sob_all_id; }).indexOf(selIds[selIds.length - 1]);
		var next = list[(cur + d + list.length) % list.length];
		post({nuBuilderSelect: next.sob_all_id});
		var w = document.getElementById('nuw_' + next.sob_all_id);
		if (w && w.scrollIntoView) w.scrollIntoView({block: 'nearest'});
	}
	function shortcutsDialog() {
		if (document.getElementById('nuDsKeysDlg')) return;
		var mac = /Mac|iPhone|iPad/.test(navigator.platform || ''), C = mac ? '⌘' : 'Ctrl';
		var rows = BROWSE ? [['F2', 'ds_key_rename_col'], ['Esc', 'ds_key_esc'], [C + '+Z', 'ds_key_undo'], [C + '+S', 'ds_key_save'], ['?', 'ds_key_keys']] : [
			[t('ds_key_click'), 'ds_key_select'], [C + '+A', 'ds_key_all'], ['Esc', 'ds_key_esc'], ['Tab / Shift+Tab', 'ds_key_tab'],
			['← ↑ → ↓', 'ds_key_move'], [C + '+← ↑ → ↓', 'ds_key_move_big'], ['Shift+← ↑ → ↓', 'ds_key_size'],
			['F2', 'ds_key_rename'], [C + '+D', 'ds_key_dup'], [C + '+C / ' + C + '+X / ' + C + '+V', 'ds_key_copy'], [mac ? '⌫' : 'Del', 'ds_key_delete'],
			[C + '+Z', 'ds_key_undo'], [C + '+S', 'ds_key_save'], [C + '+K', 'ds_key_structure'], ['?', 'ds_key_keys']];
		var root = modal('nuDsKeysDlg', t('ds_keys_title'), [el('table', {'class': 'table table-sm nuDsKeys mb-0'}, [el('tbody', {}, rows.map(function (r) {
			return el('tr', {}, [el('th', {scope: 'row'}, r[0].split(' / ').map(function (k, i) { return el('span', {}, [i ? ' / ' : '', el('kbd', {text: k})]); })), el('td', {text: t(r[1])})]);
		}))])], [], true);
		root.addEventListener('hidden.bs.modal', function () { root.remove(); });
		show(root);
	}

	// ------------------------------------------------------------------ keys: Ctrl+D duplicates, Ctrl+K opens Structure, Esc closes a panel
	document.addEventListener('keydown', function (e) {
		if (!S || isField(e.target) || document.querySelector('.modal.show')) return;
		var mod = e.ctrlKey || e.metaKey;
		if (mod && !e.altKey && (e.key === 'd' || e.key === 'D')) {
			var objs = selIds.map(objByFid).filter(Boolean);
			if (!objs.length) return;
			e.preventDefault();
			duplicate(objs);
			return;
		}
		if (mod && (e.key === 'k' || e.key === 'K') && !BROWSE) { e.preventDefault(); e.stopPropagation(); openFly('structure'); return; }
		if (W.kind) return;                                     // the Code / History view has its own keys
		// 0.8.671: F2 rename, Ctrl+Z undo, Ctrl+S (saved anyway), Ctrl+C / X / V copy, cut and paste objects, Tab / Shift+Tab next / previous
		// object, ? the list of shortcuts
		var objs2 = selIds.map(objByFid).filter(Boolean);
		if (e.key === 'F2' && !mod && !BROWSE && objs2.length) { e.preventDefault(); e.stopPropagation(); renameDialog(objs2[objs2.length - 1]); return; }
		if (e.key === 'F2' && !mod && BROWSE && colById(selCol)) { e.preventDefault(); var ti = panel.querySelector('[data-key="sbr_title"]'); if (ti) { ti.focus(); ti.select(); } return; }
		if (mod && !e.altKey && !e.shiftKey && (e.key === 'z' || e.key === 'Z')) { e.preventDefault(); e.stopPropagation(); if (!bUndo.disabled) undo(); return; }
		if (mod && !e.altKey && (e.key === 's' || e.key === 'S')) { e.preventDefault(); e.stopPropagation(); say(t('ds_autosaved')); return; }
		if (mod && !e.altKey && !e.shiftKey && !BROWSE && (e.key === 'c' || e.key === 'C' || e.key === 'x' || e.key === 'X') && objs2.length) {
			if (window.getSelection && String(window.getSelection()).length) return;   // copying selected text of the page
			e.preventDefault();
			copyObjs(objs2);
			if (e.key === 'x' || e.key === 'X') deleteObjs(objs2);
			return;
		}
		if (mod && !e.altKey && !e.shiftKey && !BROWSE && (e.key === 'v' || e.key === 'V') && clip()) { e.preventDefault(); pasteObjs(); return; }
		if (e.key === 'Tab' && !mod && !e.altKey && !BROWSE && objs2.length) { e.preventDefault(); stepSel(e.shiftKey ? -1 : 1); return; }
		if ((e.key === '?' || (e.key === '/' && e.shiftKey)) && !mod) { e.preventDefault(); shortcutsDialog(); return; }
		if (e.key === 'Escape' && (flyKind || menuEl)) { closeMenu(); closeFly(); }
		else if (e.key === 'Escape' && BROWSE && selCol) { selCol = ''; markCols(); renderPanel(); }
	}, true);

	// ------------------------------------------------------------------ the object menu (right-click, as on the form – 0.8.664)
	// the designer's overlay lies over the object: its right-click opens the form's object menu (nuruntime.js); the menu's changes go
	// through design mode (draft, panel, reload with the selection)
	N.objMenuHook = function (kind, o, fields) {
		var obj = objById(o.object_id);
		if (!obj) return;
		if (kind === 'save') { if (fields.sob_all_zzzzsys_tab_id) keepTab = fields.sob_all_zzzzsys_tab_id; selIds = [obj.sob_all_id]; saveObj(obj, fields, true); }
		else if (kind === 'delete') deleteObjs([obj]);
		else if (kind === 'properties') post({nuBuilderSelect: obj.sob_all_id});
	};
	document.addEventListener('contextmenu', function (e) {
		var ov = e.target.closest && e.target.closest('#nuEditForm .nuDesignOverlay');
		if (!ov || e.ctrlKey || typeof N.objMenuOpen !== 'function') return;
		var w = ov.closest('.nuWrap'), id = w ? w.id.replace(/^nuw_/, '') : '';
		if (!id || !N.objects || !N.objects[id]) return;
		e.preventDefault();
		e.stopPropagation();
		if (selIds.indexOf(id) < 0) post({nuBuilderSelect: id});
		N.objMenuOpen(id, e.clientX, e.clientY);
	}, true);

	// ------------------------------------------------------------------ objects the form's code hides: shown faded, selectable
	function markHidden(w) {
		if (w.closest('.nuSubformCard') || w.classList.contains('nuDsHidden')) return;
		w.classList.remove('nuHidden');
		if (w.style.display === 'none') w.style.display = '';
		w.classList.add('nuDsHidden');
		w.appendChild(el('span', {'class': 'nuDsHiddenBadge', title: t('ds_hidden_code_tip')}, [icon('bi-eye-slash me-1'), t('ds_hidden_code')]));
	}
	function showHidden() {
		document.querySelectorAll('#nuEditForm .nuWrap.nuHidden, #nuEditForm .nuWrap[style*="display: none"]').forEach(markHidden);
	}

	// ------------------------------------------------------------------ start
	function place() {
		var c = document.querySelector('.nuFormContainer') || document.body;
		c.insertBefore(bar, c.firstChild);
		document.body.appendChild(rail);
		document.body.appendChild(fly);
		document.body.appendChild(panel);
		document.body.appendChild(work);
		// the Arrange panel of nudesigner.js sits below the bar (its height changes when it wraps)
		var barH = function () { document.documentElement.style.setProperty('--nu-ds-bar-h', bar.offsetHeight + 'px'); };
		barH();
		if (window.ResizeObserver) new window.ResizeObserver(barH).observe(bar);
		if (phone.addEventListener) phone.addEventListener('change', function () { renderPanel(); });
	}
	place();
	renderBar();
	var v0 = new URLSearchParams(window.location.search);     // back from the password confirmation: the dialog again
	api('state', {}).then(function (j) {
		if (!j.ok) throw new Error(j.error || 'Error');
		S = j;
		renderBar();
		if (BROWSE) {
			restore();
			setupBrowse();
			renderPanel();
			if (v0.get('dsdelete') === '1') deleteForm();
			if (v0.get('dsitem')) W.item = v0.get('dsitem');      // a code part to open first (0.8.676: form info → Edit the SQL)
			if (['code', 'history'].indexOf(v0.get('dswork')) >= 0) openWork(v0.get('dswork'));
			return;
		}
		renderPanel();
		setupTabs();
		dropTargets();
		if (v0.get('dscolchg')) openColumnChange(v0.get('dscolchg'));
		if (v0.get('dsdelete') === '1') deleteForm();
		// old builder links (0.8.670, NuDesign::fromBuilder): an object, Code / History / Changes, Structure / Add
		var o0 = v0.get('dsobj') ? objById(v0.get('dsobj')) : null;
		if (o0) setTimeout(function () { var tb = o0.sob_all_zzzzsys_tab_id; if (tb) selectTabById(tb); post({nuBuilderSelect: o0.sob_all_id}); }, 400);
		if (v0.get('dsfly')) openFly(v0.get('dsfly') === 'add' ? 'add' : 'structure');
		if (v0.get('dsitem')) W.item = v0.get('dsitem');
		if (['code', 'history'].indexOf(v0.get('dswork')) >= 0 || (v0.get('dswork') === 'changes' && DR)) openWork(v0.get('dswork'));
		setTimeout(function () {
			showHidden();
			var v = null;
			try { v = JSON.parse(window.sessionStorage.getItem(KEY) || 'null'); } catch (e) { v = null; }
			if (v && v.tab === '__last' && S.tabs && S.tabs.length) { v.tab = S.tabs[S.tabs.length - 1].zzzzsys_tab_id; try { window.sessionStorage.setItem(KEY, JSON.stringify(v)); } catch (e) { /* ignore */ } }
			restore();
		}, 0);
	}).catch(function (e) { say(e.message, true); });
})();
