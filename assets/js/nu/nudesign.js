/* global nuBs */
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
	var BROWSE = N.mode === 'browse';
	var HASLIST = ['browse', 'browseedit'].indexOf(N.form.type) >= 0 && !(N.design && N.design.userhome);   // List flyout (0.8.820)  // the list (0.8.669): its columns are designed on the list page itself
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
			if (W.kind === 'list') renderWork();
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
	function activeTabId() { var p = activePane(); return p ? p.getAttribute('data-nu-pane-tab') || '' : ''; }
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
			if (W.kind === 'list') renderWork();
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
	var bUndo = el('button', {type: 'button', 'class': 'btn btn-outline-secondary', id: 'nuDsUndo', title: t('builder_draft_undo') + ' (Ctrl+Z)', 'aria-label': t('builder_draft_undo'), onclick: undo}, [icon('bi-arrow-counterclockwise')]);
	var bRedo = el('button', {type: 'button', 'class': 'btn btn-outline-secondary', id: 'nuDsRedo', title: t('ds_redo') + ' (Ctrl+Y)', 'aria-label': t('ds_redo'), onclick: redo}, [icon('bi-arrow-clockwise')]);   // review H2
	var bDiscard = el('button', {type: 'button', 'class': 'btn btn-outline-secondary', id: 'nuDsDiscard', text: t('builder_draft_discard'), onclick: discard});
	var bChanges = el('button', {type: 'button', 'class': 'btn btn-outline-secondary', id: 'nuDsChangesBtn', title: t('ds_changes'), 'aria-label': t('ds_changes'), onclick: function () { openWork('changes'); }}, [icon('bi-file-diff')]);
	var bPublish = el('button', {type: 'button', 'class': 'btn btn-primary', id: 'nuDsPublish', text: t('builder_draft_publish_btn'), title: t('ds_publish_tip'), onclick: function () { publish(); }});
	// Publish options (0.8.797): ask for a note or publish with one click (per browser), "Publish with a note…" always asks
	var PUB_ASK = 'nunext-design-pubask';
	var pubAsk = function () { try { return localStorage.getItem(PUB_ASK) !== '0'; } catch (e) { return true; } };
	var bPubAsk = el('input', {'class': 'form-check-input', type: 'checkbox', role: 'switch', id: 'nuDsPubAsk'});
	bPubAsk.checked = pubAsk();
	bPubAsk.addEventListener('change', function () { try { localStorage.setItem(PUB_ASK, bPubAsk.checked ? '1' : '0'); } catch (e) { /* private window */ } });
	var bPubMenu = el('ul', {'class': 'dropdown-menu dropdown-menu-end nuDsPubMenu', id: 'nuDsPubMenu'}, [
		el('li', {}, [el('div', {'class': 'dropdown-item-text'}, [el('div', {'class': 'form-check form-switch mb-0', title: t('ds_pub_ask_tip')}, [bPubAsk,
			el('label', {'class': 'form-check-label', 'for': 'nuDsPubAsk', text: t('ds_pub_ask')})]),
			el('div', {'class': 'small text-body-secondary mt-1', text: t('ds_pub_ask_tip')})])]),
		el('li', {}, [el('hr', {'class': 'dropdown-divider'})]),
		el('li', {}, [el('button', {type: 'button', 'class': 'dropdown-item', id: 'nuDsPubWithNote', onclick: function () { publish({ask: true}); }}, [icon('bi-chat-left-text me-2'), t('ds_pub_with_note')])])]);
	var bPubMore = el('button', {type: 'button', 'class': 'btn btn-primary dropdown-toggle dropdown-toggle-split', id: 'nuDsPublishMore', 'data-bs-toggle': 'dropdown',
		'data-bs-auto-close': 'outside', 'aria-expanded': 'false', 'aria-label': t('ds_pub_more')});   // no tooltip: it would cover the open menu
	var bPubGrp = el('div', {'class': 'btn-group nuDsPubGrp', id: 'nuDsPublishGrp', role: 'group'}, [bPublish, bPubMore, bPubMenu]);
	var bDone = el('a', {'class': 'btn btn-outline-primary', id: 'nuDsDone', href: D.exit, title: t('ds_done_tip'), onclick: done}, [icon('bi-check-lg me-1'), t('ds_done')]);
	bar.appendChild(el('span', {'class': 'badge nuDsBadge'}, [icon('bi-pencil-square me-1'), t('ds_mode')]));
	bar.appendChild(el('strong', {'class': 'nuDsTitle', text: N.form.description || N.form.code}));
	if (D.safe) bar.appendChild(el('span', {'class': 'badge nuDsSafeBadge', id: 'nuDsSafeBadge', title: t('ds_safe_tip')}, [icon('bi-shield-check me-1'), t('ds_safe_on')]));
	bar.appendChild(status);
	bar.appendChild(msg);
	// messages of the form's own code (review H1): collected by nuruntime.js (N.formMsgs) instead of covering the panel
	var bMsgs = el('button', {type: 'button', 'class': 'btn btn-sm btn-outline-secondary nuDsFormMsgs', id: 'nuDsFormMsgs', hidden: 'hidden', title: t('ds_form_msgs_tip'), 'aria-label': t('ds_form_msgs'),
		onclick: function () {
			var list = (N.formMsgs || []).map(function (m) { return (m.kind === 'toast' ? '• ' : '! ') + m.text; });
			if (typeof window.nuConfirmAsk === 'function') window.nuConfirmAsk(t('ds_form_msgs_tip'), function () { N.formMsgs = []; syncMsgs(); }, t('ds_form_msgs_clear'), 'btn btn-outline-secondary', {title: t('ds_form_msgs'), details: list});
		}}, [icon('bi-chat-left-text me-1'), el('span', {'class': 'nuDsFormMsgsN'})]);
	function syncMsgs() {
		var n = (N.formMsgs || []).length;
		bMsgs.hidden = n === 0;
		bMsgs.querySelector('.nuDsFormMsgsN').textContent = String(n);
	}
	document.addEventListener('nu:formmsg', syncMsgs);
	bar.appendChild(bMsgs);
	setTimeout(syncMsgs, 0);
	bar.appendChild(el('span', {'class': 'nuDsSpacer'}));
	// phones: Structure and Add sit in the bar (no icon bar there)
	if (!BROWSE) bar.appendChild(el('button', {type: 'button', 'class': 'btn btn-outline-secondary nuDsBarTool', 'data-fly': 'structure', title: t('ds_structure'), 'aria-label': t('ds_structure'), onclick: function () { toggleFly('structure'); }}, [icon('bi-layers')]));
	if (!BROWSE) bar.appendChild(el('button', {type: 'button', 'class': 'btn btn-outline-secondary nuDsBarTool', 'data-fly': 'add', title: t('ds_add'), 'aria-label': t('ds_add'), onclick: function () { toggleFly('add'); }}, [icon('bi-plus-circle')]));
	if (!BROWSE && D.rules && !D.userhome) bar.appendChild(el('button', {type: 'button', 'class': 'btn btn-outline-secondary nuDsBarTool', 'data-fly': 'rules', title: t('vr_title'), 'aria-label': t('vr_title'), onclick: function () { toggleFly('rules'); }}, [icon('bi-check2-square')]));
	if (HASLIST) bar.appendChild(el('button', {type: 'button', 'class': 'btn btn-outline-secondary nuDsBarTool', 'data-work': 'list', title: t('ds_list_title'), 'aria-label': t('ds_list_title'), onclick: function () { openWork('list'); }}, [icon('bi-table')]));
	// safe mode (0.8.667): the form without its own code – to fix a form whose JavaScript or Before Edit PHP breaks it
	bar.appendChild(el('a', {'class': 'btn btn-outline-secondary' + (D.safe ? ' active' : ''), id: 'nuDsSafe', href: D.safe_url, role: 'button', 'aria-pressed': D.safe ? 'true' : 'false',
		title: D.safe ? t('ds_safe_off') : t('ds_safe') + ' – ' + t('ds_safe_tip'), 'aria-label': D.safe ? t('ds_safe_off') : t('ds_safe')}, [icon('bi-shield-check')]));
	// maintenance (0.8.808): users cannot open the form while it is on – the dialog of nuformmaint.js
	if (D.maint_ok && window.nuFormMaintOpen) bar.appendChild(el('button', {type: 'button', 'class': 'btn ' + (D.maint ? 'btn-danger active' : 'btn-outline-secondary'), id: 'nuDsMaint',
		'aria-pressed': D.maint ? 'true' : 'false', title: t(D.maint ? 'fms_ds_on' : 'fms_ds_tip'), 'aria-label': t('fms_title'),
		onclick: function () {
			window.nuFormMaintOpen({forms: [{id: D.form_id, label: D.form_label}], on: !!D.maint, text: D.maint ? D.maint.text : '', until: D.maint ? D.maint.until : '',
				onDone: function () { location.reload(); }});
		}}, [icon('bi-cone-striped')]));
	bar.appendChild(el('button', {type: 'button', 'class': 'btn btn-outline-secondary', id: 'nuDsReload', title: t('ds_reload'), 'aria-label': t('ds_reload'), onclick: function () { reloadKeep(); }}, [icon('bi-arrow-repeat')]));
	bar.appendChild(el('button', {type: 'button', 'class': 'btn btn-outline-secondary nuDsBarTool', 'data-work': 'code', title: t('ds_code'), 'aria-label': t('ds_code'), onclick: function () { openWork('code'); }}, [icon('bi-code-square')]));
	bar.appendChild(el('button', {type: 'button', 'class': 'btn btn-outline-secondary nuDsBarTool', 'data-work': 'history', title: t('ds_history'), 'aria-label': t('ds_history'), onclick: function () { openWork('history'); }}, [icon('bi-clock-history')]));
	bar.appendChild(bUndo);
	bar.appendChild(bRedo);
	bar.appendChild(bChanges);
	bar.appendChild(bDiscard);
	bar.appendChild(bPubGrp);
	bar.appendChild(bDone);
	// phones (0.8.831): one line – the bar's other buttons sit in a ⋯ menu (built when it opens, from the buttons that are shown),
	// the status is a dot, and the bar slides away while scrolling down
	var dot = el('span', {'class': 'nuDsDot', id: 'nuDsDot', role: 'img'});
	bar.insertBefore(dot, status);
	var phMenu = el('ul', {'class': 'dropdown-menu dropdown-menu-end nuDsPhMenu', id: 'nuDsPhMenu'});
	var phMore = el('button', {type: 'button', 'class': 'btn btn-outline-secondary nuDsPhOnly', id: 'nuDsPhMore', 'data-bs-toggle': 'dropdown', 'aria-expanded': 'false',
		title: t('ds_ph_menu'), 'aria-label': t('ds_ph_menu')}, [icon('bi-three-dots')]);
	var phGrp = el('div', {'class': 'btn-group nuDsPhOnly', id: 'nuDsPhGrp'}, [phMore, phMenu]);
	bar.insertBefore(phGrp, bDone);
	function phItems() {
		var list = [];
		var push = function (b, label, ic) {
			if (!b || b.hidden || b.disabled || !b.isConnected) return;
			list.push(el('li', {}, [el('button', {type: 'button', 'class': 'dropdown-item', 'data-ph-for': b.id || b.getAttribute('data-fly') || b.getAttribute('data-work') || '',
				onclick: function () { b.click(); }}, [icon((ic || 'bi-dot') + ' me-2'), label])]));
		};
		if (!bPubGrp.hidden) push(bPublish, bPublish.textContent, 'bi-cloud-upload');
		if (!bPubGrp.hidden && !bPubMore.hidden) push(document.getElementById('nuDsPubWithNote') || bPublish, t('ds_pub_with_note'), 'bi-chat-left-text');
		push(bUndo, t('builder_draft_undo'), 'bi-arrow-counterclockwise');
		push(bRedo, t('ds_redo'), 'bi-arrow-clockwise');
		push(bChanges, t('ds_changes'), 'bi-file-diff');
		bar.querySelectorAll('.nuDsBarTool[data-fly="rules"], .nuDsBarTool[data-work]').forEach(function (b) { push(b, b.getAttribute('aria-label'), b.querySelector('i') ? b.querySelector('i').className.replace(/\s*me-\d/, '') : ''); });
		push(document.getElementById('nuDsReload'), t('ds_reload'), 'bi-arrow-repeat');
		push(document.getElementById('nuDsSafe'), D.safe ? t('ds_safe_off') : t('ds_safe'), 'bi-shield-check');
		push(document.getElementById('nuDsMaint'), t('fms_title'), 'bi-cone-striped');
		push(bMsgs, t('ds_form_msgs'), 'bi-chat-left-text');
		if (!bDiscard.hidden) { list.push(el('li', {}, [el('hr', {'class': 'dropdown-divider'})])); push(bDiscard, t('builder_draft_discard'), 'bi-trash'); }
		var last = list[list.length - 1];
		if (last && last.querySelector('button[data-ph-for="nuDsDiscard"]')) last.querySelector('button').classList.add('text-danger');
		return list;
	}
	phMore.addEventListener('show.bs.dropdown', function () { phMenu.textContent = ''; phItems().forEach(function (li) { phMenu.appendChild(li); }); });
	phMore.addEventListener('click', function () { if (!(window.nuBs && nuBs.ready())) { phMenu.textContent = ''; phItems().forEach(function (li) { phMenu.appendChild(li); }); phMenu.classList.toggle('show'); } });
	var lastY = window.scrollY, barAway = false;
	window.addEventListener('scroll', function () {
		var y = window.scrollY;
		if (!phone.matches || phMenu.classList.contains('show')) { lastY = y; if (barAway) { barAway = false; document.documentElement.classList.remove('nuDsBarAway'); } return; }
		if (y > lastY + 8 && y > 80) barAway = true;
		else if (y < lastY - 8 || y < 40) barAway = false;
		if (Math.abs(y - lastY) > 8 || y < 40) lastY = y;
		document.documentElement.classList.toggle('nuDsBarAway', barAway);
		placeQuick();
	}, {passive: true});

	function renderBar() {
		if (D.unpublished) status.textContent = t('ds_status_unpublished');
		else if (DR) status.textContent = DR.mine === false ? fmt(t('ds_status_draft_by'), DR.created_by, DR.changes) : fmt(t(+DR.changes === 1 ? 'ds_status_draft_1' : 'ds_status_draft'), DR.changes);
		else status.textContent = t('ds_status_none');
		status.title = status.textContent;                       // cut with … on narrow screens
		dot.className = 'nuDsDot ' + (D.unpublished ? 'nuDsDotNew' : DR ? 'nuDsDotDraft' : 'nuDsDotNone');
		dot.title = status.textContent; dot.setAttribute('aria-label', status.textContent);
		bUndo.disabled = D.unpublished ? false : !(DR && DR.undo_count > 0);
		bRedo.disabled = !(DR && DR.redo_count > 0);
		bRedo.hidden = !DR;
		bDiscard.hidden = !DR;
		bChanges.hidden = !DR;
		bPubGrp.hidden = !D.can_publish || (!D.unpublished && !DR);      // publishing needs the admin right formpublish
		bPublish.textContent = D.unpublished ? t('builder_draft_publish_new') : t('builder_draft_publish_btn');
		bPublish.title = D.unpublished ? t('ds_publish_new_tip') : t('ds_publish_tip');
		bPubMore.hidden = !!D.unpublished;                                // a new form has no draft and no note
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
	function redo() {
		if (!DR) return;
		busy(true);
		api('draft.redo', {}).then(function (j) {
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

	// Done (0.8.797): with unpublished changes ask Publish now / Leave as draft / Cancel
	var doneDlg = null;
	function done(e) {
		var pending = D.unpublished || (DR && +DR.changes > 0);
		if (!pending || !(window.nuBs && window.nuBs.ready())) return;      // plain link
		e.preventDefault();
		if (!doneDlg) {
			doneDlg = {text: el('p', {'class': 'mb-0', id: 'nuDsDoneText'}), nopub: el('p', {'class': 'small text-body-secondary mt-2 mb-0', id: 'nuDsDoneNoPub', text: t('ds_done_nopub')}),
				keep: el('a', {'class': 'btn btn-outline-primary', id: 'nuDsDoneKeep', href: D.exit, text: t('ds_done_keep')}),
				pub: el('button', {type: 'button', 'class': 'btn btn-primary', id: 'nuDsDonePublish', text: t('ds_done_publish')})};
			doneDlg.pub.addEventListener('click', function () {
				hide(doneDlg.root);
				publish({then: function () { window.location.href = D.exit; }});
			});
			doneDlg.root = modal('nuDsDoneDlg', t('ds_done_title'), [doneDlg.text, doneDlg.nopub], [doneDlg.keep, doneDlg.pub], false);
		}
		doneDlg.text.textContent = D.unpublished ? t('ds_done_text_new') : fmt(t('ds_done_text'), DR.changes);
		doneDlg.pub.hidden = !D.can_publish;
		doneDlg.nopub.hidden = !!D.can_publish;
		show(doneDlg.root);
		setTimeout(function () { (D.can_publish ? doneDlg.pub : doneDlg.keep).focus(); }, 200);
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

	// Ask AI (0.8.810): copy a prompt with the current form (the open draft), paste the AI's answer, check it, apply it to the draft –
	// step by step; the requests go to Form with AI (pages/nuformai.php, D.ai), which writes into the open draft
	var aiDlg = null;
	function aiPost(data) {
		var b = new URLSearchParams();
		b.set('_csrf', N.csrf);
		Object.keys(data).forEach(function (k) { b.set(k, data[k]); });
		return fetch(D.ai, {method: 'POST', body: b, credentials: 'same-origin', headers: {Accept: 'application/json'}}).then(function (r) { return r.json(); });
	}
	function aiCopy(text) {
		if (navigator.clipboard && window.isSecureContext) return navigator.clipboard.writeText(text);
		return new Promise(function (ok, fail) {
			var ta = el('textarea', {readonly: 'readonly', 'class': 'position-fixed top-0 start-0 opacity-0'});
			ta.value = text; document.body.appendChild(ta); ta.select();
			var done = false;
			try { done = document.execCommand('copy'); } catch (e) { done = false; }
			ta.remove();
			if (done) ok(); else fail(new Error('copy'));
		});
	}
	function askAi() {
		if (aiDlg) { show(aiDlg.root); return; }
		var req = el('textarea', {'class': 'form-control', id: 'nuDsAiReq', rows: '3', placeholder: t('fai_request_ph_change')});
		var msg = el('div', {'class': 'small mt-1', id: 'nuDsAiMsg', 'aria-live': 'polite'});
		var ans = el('textarea', {'class': 'form-control font-monospace small', id: 'nuDsAiAns', rows: '6', placeholder: '```json …```'});
		var plan = el('div', {'class': 'small mt-2', id: 'nuDsAiPlan'});
		var ddl = el('input', {type: 'checkbox', 'class': 'form-check-input', id: 'nuDsAiDdl'});
		var ddlBox = el('div', {'class': 'form-check mt-2', id: 'nuDsAiDdlBox', hidden: 'hidden'}, [ddl, el('label', {'class': 'form-check-label', 'for': 'nuDsAiDdl', text: t('fai_ddl_ok')})]);
		var apply = el('button', {type: 'button', 'class': 'btn btn-primary', id: 'nuDsAiApply', disabled: 'disabled'}, [icon('bi-magic me-1'), t('fai_apply')]);
		function li(items, cls) { return el('ul', {'class': 'mb-1 ps-3 ' + (cls || '')}, items.map(function (x) { return el('li', {text: x}); })); }
		function check() {
			if (ans.value.trim() === '') return;
			apply.disabled = true; plan.textContent = '';
			aiPost({action: 'check', answer: ans.value}).then(function (d) {
				plan.textContent = '';
				if (d.errors && d.errors.length) plan.appendChild(el('div', {'class': 'alert alert-danger py-2 mb-2'}, [el('div', {'class': 'fw-semibold', text: t('fai_errors')}), li(d.errors)]));
				if (d.warnings && d.warnings.length) plan.appendChild(el('div', {'class': 'alert alert-warning py-2 mb-2'}, [el('div', {'class': 'fw-semibold', text: t('fai_warnings')}), li(d.warnings)]));
				if (d.ok && d.plan) {
					var p = d.plan, rows = [];
					var f = function (k) { var a = Array.prototype.slice.call(arguments, 1), i = 0; return t(k).replace(/%[sd]/g, function () { return a[i++]; }); };
					if (p.columns && p.columns.length) rows.push(f('fai_plan_columns', p.columns.map(function (c) { return c.name; }).join(', ')));
					if (p.added && p.added.length) rows.push(f('fai_plan_added', p.added.join(', ')));
					if (p.changed && p.changed.length) rows.push(f('fai_plan_changed', p.changed.map(function (c) { return c.id; }).join(', ')));
					if (p.removed && p.removed.length) rows.push(f('fai_plan_removed', p.removed.join(', ')));
					if (p.moved) rows.push(t('fai_plan_moved'));
					if (p.tabs) rows.push(f('fai_plan_tabs', p.tabs));
					if (p.browse) rows.push(f('fai_plan_browse', p.browse));
					if (p.form && p.form.length) rows.push(f('fai_plan_form', p.form.join(', ')));
					if (p.code && p.code.length) rows.push(f('fai_plan_code', p.code.join(', ')));
					if (!rows.length) rows.push(t('fai_plan_none'));
					plan.appendChild(el('div', {'class': 'fw-semibold', text: t('fai_plan')}));
					plan.appendChild(li(rows, 'nuDsAiRows'));
					ddlBox.hidden = !(p.columns && p.columns.length) && !p.new_table;
					ddl.checked = false;
					apply.disabled = false;
				}
			}, function (e) { plan.textContent = e.message; });
		}
		ans.addEventListener('paste', function () { setTimeout(check, 0); });
		ans.addEventListener('input', function () { apply.disabled = true; });
		var copyB = el('button', {type: 'button', 'class': 'btn btn-sm btn-outline-primary', id: 'nuDsAiCopy'}, [icon('bi-clipboard me-1'), t('fai_copy')]);
		copyB.addEventListener('click', function () {
			msg.textContent = ''; msg.className = 'small mt-1';
			aiPost({action: 'prompt', request: req.value}).then(function (d) {
				if (!d.ok) { msg.textContent = d.error || ''; msg.className = 'small mt-1 text-danger'; return; }
				aiDlg.prompt = d.text;
				return aiCopy(d.text).then(function () { msg.textContent = t('fai_copied'); msg.className = 'small mt-1 text-success'; },
					function () { msg.textContent = t('fai_copy_failed'); msg.className = 'small mt-1 text-warning'; });
			});
		});
		// context file (0.8.961, NuAiContext): main button = one .md file, menu = .md / zip + full function reference
		var ctxFull = el('input', {type: 'checkbox', 'class': 'form-check-input', role: 'switch', id: 'nuDsAiCtxFull'});
		var ctxItem = function (fmt, ic, label) { return el('li', {}, [el('button', {type: 'button', 'class': 'dropdown-item', 'data-nu-ctx': fmt}, [icon(ic + ' me-2'), label])]); };
		var ctxBox = el('div', {'class': 'btn-group btn-group-sm', id: 'nuDsAiCtx'}, [
			el('button', {type: 'button', 'class': 'btn btn-outline-primary', 'data-nu-ctx': 'md', id: 'nuDsAiCtxMd', title: t('fai_ctx_tip')}, [icon('bi-file-earmark-arrow-down me-1'), t('fai_ctx')]),
			el('button', {type: 'button', 'class': 'btn btn-outline-primary dropdown-toggle dropdown-toggle-split', 'data-bs-toggle': 'dropdown', 'aria-expanded': 'false', 'aria-label': t('fai_ctx_more')}),
			el('ul', {'class': 'dropdown-menu'}, [ctxItem('md', 'bi-filetype-md', t('fai_ctx_md')), ctxItem('zip', 'bi-file-zip', t('fai_ctx_zip')), el('li', {}, [el('hr', {'class': 'dropdown-divider'})]),
				el('li', {}, [el('div', {'class': 'dropdown-item-text'}, [el('div', {'class': 'form-check form-switch mb-0'}, [ctxFull, el('label', {'class': 'form-check-label', 'for': 'nuDsAiCtxFull', text: t('fai_ctx_full')})])])])])
		]);
		ctxBox.addEventListener('click', function (e) {
			var b = e.target.closest('[data-nu-ctx]');
			if (!b) return;
			var body = new URLSearchParams();
			body.set('_csrf', N.csrf); body.set('action', 'context'); body.set('fmt', b.getAttribute('data-nu-ctx')); body.set('full', ctxFull.checked ? '1' : '0');
			msg.textContent = ''; msg.className = 'small mt-1';
			fetch(D.ai, {method: 'POST', body: body, credentials: 'same-origin'}).then(function (r) {
				if (!r.ok) throw new Error('HTTP ' + r.status);
				var name = r.headers.get('X-Nu-File-Name') || 'nunext-context.md';
				return r.blob().then(function (bl) {
					var url = URL.createObjectURL(bl), a = el('a', {href: url, download: name, 'class': 'd-none'});
					document.body.appendChild(a); a.click(); a.remove();
					setTimeout(function () { URL.revokeObjectURL(url); }, 60000);
					msg.textContent = t('fai_ctx_done'); msg.className = 'small mt-1 text-success';
				});
			}).catch(function (x) { msg.textContent = t('fai_ctx_failed').replace('%s', x.message); msg.className = 'small mt-1 text-danger'; });
		});
		var pasteB = el('button', {type: 'button', 'class': 'btn btn-sm btn-outline-secondary', id: 'nuDsAiPaste'}, [icon('bi-clipboard-check me-1'), t('fai_paste')]);
		pasteB.addEventListener('click', function () {
			if (!navigator.clipboard || !navigator.clipboard.readText) { ans.focus(); return; }
			navigator.clipboard.readText().then(function (x) { ans.value = x; check(); }, function () { ans.focus(); });
		});
		var checkB = el('button', {type: 'button', 'class': 'btn btn-sm btn-outline-secondary', id: 'nuDsAiCheck'}, [icon('bi-check2-circle me-1'), t('fai_check')]);
		checkB.addEventListener('click', check);
		apply.addEventListener('click', function () {
			if (!ddlBox.hidden && !ddl.checked) { ddl.focus(); ddlBox.classList.add('text-danger'); return; }
			apply.disabled = true;
			aiPost({action: 'create', answer: ans.value, ddl_ok: ddl.checked ? '1' : '0', note: req.value.split('\n')[0].slice(0, 200)}).then(function (d) {
				if (d.ok) { reloadKeep(); return; }
				apply.disabled = false;
				plan.textContent = '';
				plan.appendChild(el('div', {'class': 'alert alert-danger py-2'}, [li(d.errors || ['?'])]));
			}, function (e) { apply.disabled = false; plan.textContent = e.message; });
		});
		var body = [
			el('p', {'class': 'small text-body-secondary', text: t('fai_ask_help')}),
			el('label', {'class': 'form-label small fw-semibold', 'for': 'nuDsAiReq'}, [el('span', {'class': 'badge rounded-pill text-bg-primary me-1', text: '1'}), t('fai_ask_req')]), req,
			el('div', {'class': 'd-flex flex-wrap gap-2 align-items-center mt-2'}, [copyB, ctxBox, el('a', {'class': 'small ms-auto', href: D.ai, id: 'nuDsAiFull', text: t('fai_ask_full')})]), msg,
			el('label', {'class': 'form-label small fw-semibold mt-3', 'for': 'nuDsAiAns'}, [el('span', {'class': 'badge rounded-pill text-bg-primary me-1', text: '2'}), t('fai_step2')]), ans,
			el('div', {'class': 'd-flex flex-wrap gap-2 mt-2'}, [pasteB, checkB]),
			plan, ddlBox
		];
		aiDlg = {root: modal('nuDsAiDlg', t('fai_ask'), body, [apply], true)};
		show(aiDlg.root);
		setTimeout(function () { req.focus(); }, 300);
	}

	// Publish: the change note; warnings → "Publish anyway", a conflict → the builder (it compares)
	// opt.ask: always show the window (Publish with a note…); opt.then: after a successful publish (Done → Publish now)
	var pubDlg = null;
	function publish(opt) {
		opt = opt || {};
		if (D.unpublished) {
			api('form.publishnew', {}, true).then(function (j) { if (!j.ok) throw new Error(j.error || 'Error'); D.unpublished = false; say(t('ds_published')); if (opt.then) opt.then(); else refreshKeep(); })
				.catch(function (e) { say(e.message, true); });
			return;
		}
		if (!DR) { if (opt.then) opt.then(); return; }
		if (!pubDlg) {
			pubDlg = {note: el('textarea', {'class': 'form-control', id: 'nuDsPubNote', rows: '3'}), info: el('div', {'class': 'nuDsPubInfo small mt-2', id: 'nuDsPubInfo'}),
				sum: el('div', {'class': 'nuDsPubSum', id: 'nuDsPubSum'})};
			pubDlg.ok = el('button', {type: 'button', 'class': 'btn btn-primary', id: 'nuDsPubOk', onclick: doPublish});
			pubDlg.root = modal('nuDsPublishDlg', t('ds_publish_title'), [el('label', {'class': 'form-label', 'for': 'nuDsPubNote', text: t('ds_publish_note_opt')}), pubDlg.note,
				el('h3', {'class': 'h6 mt-3', text: t('builder_draft_summary')}), pubDlg.sum, pubDlg.info], [pubDlg.ok], true);
		}
		pubDlg.note.value = DR.note || '';
		pubDlg.info.textContent = '';
		pubDlg.ok.textContent = t('builder_draft_publish_btn');
		pubDlg.ok.className = 'btn btn-primary';
		pubDlg.warn = false;
		pubDlg.force = false;
		pubDlg.code = '';
		pubDlg.then = opt.then || null;
		pubDlg.shown = false;
		if (!opt.ask && !pubAsk()) { doPublish(); return; }        // one click: the window only opens for warnings, errors or a conflict
		pubDlg.sum.innerHTML = '';
		pubDlg.sum.appendChild(el('div', {'class': 'text-body-secondary small', text: '…'}));
		api('draft.diff', {}).then(function (r) { pubDlg.sum.innerHTML = ''; if (r.ok) pubDlg.sum.appendChild(diffView(r.diff)); }).catch(function () { pubDlg.sum.innerHTML = ''; });
		pubDlg.shown = true;
		if (window.nuBs && window.nuBs.ready()) show(pubDlg.root);
		else { var n = window.prompt(t('ds_publish_note')); if (n !== null) { pubDlg.note.value = n; doPublish(); } }
	}
	function doPublish() {
		var d = pubDlg;
		d.ok.disabled = true;
		api('draft.publish', {note: d.note.value, warnings_ok: d.warn, force: d.force, confirm_code: d.code}).then(function (j) {
			d.ok.disabled = false;
			d.info.textContent = '';
			if (j.ok) { DR = null; if (d.shown) hide(d.root); say(t('ds_published')); if (d.then) d.then(); else refreshKeep(); return; }
			if (!d.shown) {                                          // one-click publish that needs an answer: open the window now
				d.shown = true;
				d.sum.innerHTML = '';
				api('draft.diff', {}).then(function (r) { d.sum.innerHTML = ''; if (r.ok) d.sum.appendChild(diffView(r.diff)); }).catch(function () { d.sum.innerHTML = ''; });
				if (window.nuBs && window.nuBs.ready()) show(d.root);
			}
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
			js.onload = ok;
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
		el('button', {type: 'button', 'class': 'nuDsRailBtn', id: 'nuDsRailForm', title: t('ds_form'), 'aria-label': t('ds_form'), onclick: function () { if (!work.hidden) { closeWork(); if (!work.hidden) return; } closeFly(); post({nuBuilderSelectNone: true}); selIds = []; selCol = ''; markCols(); renderPanel(); }}, [icon('bi-window')]),
		BROWSE ? null : el('button', {type: 'button', 'class': 'nuDsRailBtn', id: 'nuDsRailStructure', 'data-fly': 'structure', title: t('ds_structure') + ' (Ctrl+K)', 'aria-label': t('ds_structure'), onclick: function () { toggleFly('structure'); }}, [icon('bi-layers')]),
		BROWSE ? null : el('button', {type: 'button', 'class': 'nuDsRailBtn', id: 'nuDsRailAdd', 'data-fly': 'add', title: t('ds_add'), 'aria-label': t('ds_add'), onclick: function () { toggleFly('add'); }}, [icon('bi-plus-circle')]),
		el('button', {type: 'button', 'class': 'nuDsRailBtn', id: 'nuDsRailCode', 'data-work': 'code', title: t('ds_code'), 'aria-label': t('ds_code'), onclick: function () { openWork('code'); }}, [icon('bi-code-square')]),
		!BROWSE && D.rules && !D.userhome ? el('button', {type: 'button', 'class': 'nuDsRailBtn', id: 'nuDsRailRules', 'data-fly': 'rules', title: t('vr_title'), 'aria-label': t('vr_title'), onclick: function () { toggleFly('rules'); }},
			[icon('bi-check2-square'), el('span', {'class': 'nuDsRailCount', id: 'nuDsRailRulesN', hidden: (D.rules.list || []).length ? null : true, text: String((D.rules.list || []).length)})]) : null,
		el('button', {type: 'button', 'class': 'nuDsRailBtn', id: 'nuDsRailHistory', 'data-work': 'history', title: t('ds_history'), 'aria-label': t('ds_history'), onclick: function () { openWork('history'); }}, [icon('bi-clock-history')]),
		HASLIST ? el('button', {type: 'button', 'class': 'nuDsRailBtn', id: 'nuDsRailList', 'data-work': 'list', title: t('ds_list_title'), 'aria-label': t('ds_list_title'), onclick: function () { openWork('list'); }}, [icon('bi-table')]) : null,
		el('button', {type: 'button', 'class': 'nuDsRailBtn nuDsRailKeys', id: 'nuDsRailKeys', title: t('ds_keys_title') + ' (?)', 'aria-label': t('ds_keys_title'), onclick: function () { shortcutsDialog(); }}, [icon('bi-keyboard')])
	]);
	var fly = el('div', {'class': 'nuDsFly', id: 'nuDsFly', role: 'dialog', 'aria-modal': 'false', hidden: true});
	var flyKind = '', flyQ = '', tableCols = null;
	var flyTab = '', flyType = '', collapsedTabs = {};   // Structure (0.8.671): filters by tab and object type, folded tab sections
	function toggleFly(kind) { if (flyKind === kind) closeFly(); else openFly(kind); }
	function openFly(kind) {
		if (!work.hidden) { closeWork(); if (!work.hidden) return; }      // the workspace (Code / History) covers the flyout: close it first (0.8.817)
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
		addPage = '';
		fly.hidden = true;
		document.documentElement.classList.remove('nuDsFlyOpen');
		renderFly();
	}
	function renderFly() {
		document.querySelectorAll('[data-fly]').forEach(function (b) { b.setAttribute('aria-pressed', b.getAttribute('data-fly') === flyKind ? 'true' : 'false'); });
		if (!flyKind || !S) return;
		fly.textContent = '';
		var flyTitle = flyKind === 'rules' ? t('vr_title') : (flyKind === 'add' ? fmt(t('ds_add_to'), tabTitle(activeTabId())) : t('ds_structure'));
		fly.setAttribute('aria-label', flyKind === 'rules' ? t('vr_title') : t(flyKind === 'add' ? 'ds_add' : 'ds_structure'));
		if (flyKind === 'add' && addPage === 'input') {   // 0.8.846: second page – the kind of input
			fly.appendChild(el('div', {'class': 'nuDsFlyHead nuDsFlyHeadSub'}, [
				el('button', {type: 'button', 'class': 'btn btn-sm btn-outline-secondary nuDsFlyBack', id: 'nuDsAddBack', title: t('ds_add_input_back'), 'aria-label': t('ds_add_input_back'),
					onclick: function () { addPage = ''; renderFly(); var b = document.querySelector('#nuDsElGrid [data-type="input"]'); if (b) b.focus(); }}, [icon('bi-arrow-left')]),
				el('div', {'class': 'nuDsFlyHeadText'}, [el('div', {'class': 'nuDsFlyCrumb', text: flyTitle + ' › ' + t('bf_type_input')}), el('strong', {text: t('ds_add_input_title')})]),
				el('button', {type: 'button', 'class': 'btn-close', 'aria-label': t('cancel'), onclick: closeFly})]));
			renderAddInput();
			return;
		}
		fly.appendChild(el('div', {'class': 'nuDsFlyHead'}, [el('strong', {text: flyTitle}),
			el('button', {type: 'button', 'class': 'btn-close', 'aria-label': t('cancel'), onclick: closeFly})]));
		if (flyKind === 'structure') renderStructure(); else if (flyKind === 'rules') fly.appendChild(el('div', {'class': 'nuDsFlyRules'}, [rulesBox()])); else renderAdd();
	}
	// ------------------------------------------------------------------ List flyout (0.8.820): every setting of the form's list in one place
	function listFormSave(key, reload) {
		return function (v) { var x = {}; x[key] = v; change('form.save', {fields: x}, !!reload).catch(function () { /* said */ }); };
	}
	function listCodeBtn(id, item, label, ic) {
		return el('button', {type: 'button', 'class': 'btn btn-sm btn-outline-secondary', id: id, onclick: function () { W.item = item; openWork('code'); }}, [icon(ic + ' me-1'), label]);
	}
	function renderListFly(container) {
		var f = S.form, outer = el('div', {'class': 'nuDsWorkList', id: 'nuDsListFly'});
		container.appendChild(outer);
		if (['browse', 'browseedit'].indexOf(f.sfo_type) < 0) { outer.appendChild(el('p', {'class': 'nuDsHint', text: t('ds_list_none')})); return; }
		var side = el('div', {'class': 'nuDsWorkListSide'}), main = el('div', {'class': 'nuDsWorkListMain'});
		outer.appendChild(side); outer.appendChild(main);
		var box = side;
		var h = function (txt) { return el('h3', {'class': 'nuDsFlySub', text: txt}); };
		// settings
		box.appendChild(h(t('ds_list_settings')));
		box.appendChild(el('div', {'class': 'nuDsGrid2'}, [
			field(t('builder_rows_per_page'), 'number', f.sfo_browse_rows_per_page, listFormSave('sfo_browse_rows_per_page', BROWSE), {key: 'sfo_browse_rows_per_page'}),
			field(t('ds_list_rowh'), 'number', f.sfo_browse_row_height, listFormSave('sfo_browse_row_height', BROWSE), {key: 'sfo_browse_row_height', tip: t('ds_list_rowh_tip'), ph: '0'})]));
		box.appendChild(field(t('ds_list_target'), 'select', String(f.sfo_browse_target === null || f.sfo_browse_target === undefined || f.sfo_browse_target === '' ? '0' : f.sfo_browse_target),
			listFormSave('sfo_browse_target', BROWSE), {key: 'sfo_browse_target', options: ['0', '1', '2', '3'].map(function (x) { return [x, t('ds_list_target_' + x)]; })}));
		box.appendChild(field(t('builder_redirect'), 'select', f.sfo_browse_redirect_form_id || '', listFormSave('sfo_browse_redirect_form_id'),
			{key: 'sfo_browse_redirect_form_id', search: true, options: options(D.forms, ['edit', 'browseedit'])}));
		box.appendChild(field(t('ds_list_multi'), 'check', String(f.sfo_browse_title_multiline || '0') === '1' ? '1' : '0', listFormSave('sfo_browse_title_multiline', BROWSE), {key: 'sfo_browse_title_multiline'}));
		box.appendChild(field(t('ds_list_autofit'), 'check', String(f.sfo_browse_autoresize_columns || '0') === '1' ? '1' : '0', listFormSave('sfo_browse_autoresize_columns', BROWSE), {key: 'sfo_browse_autoresize_columns'}));
		// code of the list
		box.appendChild(h(t('ds_list_code')));
		box.appendChild(el('div', {'class': 'nuDsTools'}, [
			listCodeBtn('nuDsListSql', 'sfo_browse_sql', t('builder_browse_sql'), 'bi-filetype-sql'),
			listCodeBtn('nuDsListBB', 'php:BB', t('builder_php_BB'), 'bi-filetype-php'),
			listCodeBtn('nuDsListJs', 'sfo_browse_javascript', t('builder_js_browse'), 'bi-filetype-js')]));
		// columns: order, title, column / expression, width, alignment, hide
		box = main;
		box.appendChild(h(t('builder_sec_columns')));
		var cols = S.browse || [];
		var thr = el('tr', {}, ['', t('builder_col_title'), t('builder_col_display'), t('builder_col_width'), t('builder_col_align'), ''].map(function (x) { return el('th', {scope: 'col', text: x}); }));
		var tbody = el('tbody');
		var list = el('div', {'class': 'table-responsive'}, [el('table', {'class': 'table table-sm align-middle nuDsListColTable', id: 'nuDsListCols'}, [el('thead', {}, [thr]), tbody])]);
		cols.forEach(function (c, i) {
			var id = c.zzzzsys_browse_id, hidden = +c.sbr_width === 0;
			var part = function (p) { var e = {}; e[id] = p; saveCols(colRows(e), id); };
			var title = el('input', {type: 'text', 'class': 'form-control form-control-sm', value: plain(c.sbr_title), 'aria-label': t('builder_col_title'), 'data-lc': 'title'});
			var disp = el('input', {type: 'text', 'class': 'form-control form-control-sm nuDsMono', value: c.sbr_display || '', list: 'nuDsColNames', 'aria-label': t('builder_col_display'), 'data-lc': 'display'});
			var width = el('input', {type: 'number', min: '0', 'class': 'form-control form-control-sm', value: hidden ? '' : String(c.sbr_width === null || c.sbr_width === '' ? 100 : c.sbr_width), 'aria-label': t('builder_col_width'), 'data-lc': 'width', disabled: hidden ? true : null});
			var align = el('select', {'class': 'form-select form-select-sm', 'aria-label': t('builder_col_align'), 'data-lc': 'align'},
				['l', 'c', 'r'].map(function (a) { return el('option', {value: a, text: t({l: 'om_left', c: 'om_center', r: 'om_right'}[a]), selected: (c.sbr_align || 'l') === a ? true : null}); }));
			title.addEventListener('change', function () { part({title: title.value}); });
			disp.addEventListener('change', function () { if (disp.value.trim() !== '') part({display: disp.value.trim()}); });
			width.addEventListener('change', function () { var w = Math.max(1, parseInt(width.value, 10) || 100); part({width: w}); });
			align.addEventListener('change', function () { part({align: align.value}); });
			var btn = function (ic, tip, dc, fn, dis) { return el('button', {type: 'button', 'class': 'btn btn-sm btn-link p-0 ' + (dc || 'text-body-secondary'), title: tip, 'aria-label': tip, disabled: dis ? true : null, onclick: fn}, [icon(ic)]); };
			tbody.appendChild(el('tr', {'class': 'nuDsListCol' + (hidden ? ' nuDsListColOff' : ''), 'data-col': id}, [
				el('td', {'class': 'nuDsListColNo'}, [el('span', {'class': 'nuDsListGrip', draggable: 'true', 'data-grip': id, title: t('ds_col_drag'), 'aria-hidden': 'true'},
					[icon('bi-grip-vertical'), el('span', {text: String(i + 1)})])]),
				el('td', {'class': 'nuDsLcTitle'}, [title]), el('td', {'class': 'nuDsLcDisp'}, [disp]), el('td', {'class': 'nuDsLcWidth'}, [width]), el('td', {'class': 'nuDsLcAlign'}, [align]),
				el('td', {'class': 'nuDsLcBtns'}, [
					btn(hidden ? 'bi-eye-slash' : 'bi-eye', hidden ? t('ds_col_show') : t('ds_col_hidden'), '', function () { part({width: hidden ? 100 : 0}); }),
					btn('bi-arrow-up', t('ds_col_left'), '', function () { colMove(c, -1); }, i === 0),
					btn('bi-arrow-down', t('ds_col_right'), '', function () { colMove(c, 1); }, i === cols.length - 1),
					btn('bi-trash', t('delete'), 'text-danger', function () { colDelete(c); })])]));
		});
		if (!cols.length) tbody.appendChild(el('tr', {}, [el('td', {colspan: '6', 'class': 'nuDsHint', text: t('ds_list_nocols')})]));
		// drag and drop of the rows by their number (0.8.823): drop above / below the row under the pointer, then one browse.save
		var dragId = '';
		tbody.addEventListener('dragstart', function (e) {
			var g = e.target.closest && e.target.closest('[data-grip]');
			if (!g) { e.preventDefault(); return; }
			dragId = g.getAttribute('data-grip');
			e.dataTransfer.effectAllowed = 'move';
			try { e.dataTransfer.setData('text/plain', dragId); } catch (x) { /* ignore */ }
			var tr = g.closest('tr'); if (tr) { tr.classList.add('nuDsListDragging'); try { e.dataTransfer.setDragImage(tr, 20, 15); } catch (x) { /* ignore */ } }
		});
		var mark = function (tr, after) {
			tbody.querySelectorAll('.nuDsDropBefore, .nuDsDropAfter').forEach(function (x) { x.classList.remove('nuDsDropBefore', 'nuDsDropAfter'); });
			if (tr) tr.classList.add(after ? 'nuDsDropAfter' : 'nuDsDropBefore');
		};
		tbody.addEventListener('dragover', function (e) {
			if (!dragId) return;
			var tr = e.target.closest && e.target.closest('tr[data-col]');
			if (!tr) return;
			e.preventDefault();
			var r = tr.getBoundingClientRect();
			mark(tr, e.clientY > r.top + r.height / 2);
		});
		tbody.addEventListener('dragleave', function (e) { if (!tbody.contains(e.relatedTarget)) mark(null); });
		tbody.addEventListener('dragend', function () { dragId = ''; mark(null); tbody.querySelectorAll('.nuDsListDragging').forEach(function (x) { x.classList.remove('nuDsListDragging'); }); });
		tbody.addEventListener('drop', function (e) {
			var tr = e.target.closest && e.target.closest('tr[data-col]');
			if (!dragId || !tr) return;
			e.preventDefault();
			var r = tr.getBoundingClientRect(), after = e.clientY > r.top + r.height / 2, target = tr.getAttribute('data-col'), from = dragId;
			dragId = ''; mark(null);
			if (target === from) return;
			var rows = colRows(), ids = rows.map(function (x) { return x.id; });
			var moving = rows.splice(ids.indexOf(from), 1)[0];
			var at = rows.map(function (x) { return x.id; }).indexOf(target) + (after ? 1 : 0);
			rows.splice(at, 0, moving);
			if (rows.map(function (x) { return x.id; }).join() === ids.join()) return;
			saveCols(rows, from);
		});
		box.appendChild(list);
		box.appendChild(columnsList());
		box.appendChild(el('div', {'class': 'd-flex flex-wrap gap-2 mt-2'}, [
			el('button', {type: 'button', 'class': 'btn btn-sm btn-outline-primary', id: 'nuDsListAddCol', onclick: function () { colPick(this); }}, [icon('bi-plus-lg me-1'), t('builder_add_column')]),
			!BROWSE && D.list_url ? el('a', {'class': 'btn btn-sm btn-outline-secondary', id: 'nuDsListOpen', href: D.list_url}, [icon('bi-table me-1'), t('ds_design_list')]) : null,
			BROWSE && D.edit_url ? el('a', {'class': 'btn btn-sm btn-outline-secondary', id: 'nuDsListEditLink', href: D.edit_url}, [icon('bi-window me-1'), t('ds_design_edit')]) : null]));
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
			var b = el('button', {type: 'button', 'class': 'nuDsEl' + (ty === 'input' ? ' nuDsElMore' : ''), draggable: 'true', 'data-type': ty,
				onclick: function () { if (ty === 'input') { addPage = 'input'; addDone = ''; renderFly(); var f1 = fly.querySelector('.nuDsKind'); if (f1) f1.focus(); return; } addObject(ty, {}); }},
				[icon(ICONS[ty] || 'bi-square'), el('span', {text: t('bf_type_' + ty)})].concat(ty === 'input' ? [el('small', {'class': 'nuDsElSub', text: t('ds_add_input_pick')})] : []));
			dragSource(b, {type: ty});
			grid.appendChild(b);
		});
		fly.appendChild(grid);
		fly.appendChild(el('p', {'class': 'nuDsFlyFoot', text: t('ds_add_hint')}));
	}
	// 0.8.846: Add → Input = a second page with the frequent kinds as tiles and the others below "All input types"
	var addPage = '', addMore = false, addDone = '';
	var INPUT_FREQUENT = [['text', 'bi-fonts'], ['nuNumber', 'bi-123'], ['nuDate', 'bi-calendar3'], ['checkbox', 'bi-check2-square'], ['email', 'bi-envelope'],
		['tel', 'bi-telephone'], ['button', 'bi-hand-index'], ['file', 'bi-file-earmark-arrow-up'], ['time', 'bi-clock']];
	function inputLabel(it) { var k = 'ds_it_' + it.replace(/[^A-Za-z0-9]/g, '_'); var x = t(k); return x === k ? it : x; }
	function addInput(it) {
		addDone = inputLabel(it);
		addObject('input', {input: it, label: inputLabel(it)});
	}
	function renderAddInput() {
		var body = el('div', {'class': 'nuDsAddInput', id: 'nuDsAddInput'});
		body.appendChild(el('div', {'class': 'nuDsFlySub', text: t('ds_add_input_frequent')}));
		var grid = el('div', {'class': 'nuDsElGrid', id: 'nuDsKindGrid'});
		INPUT_FREQUENT.forEach(function (k) {
			var b = el('button', {type: 'button', 'class': 'nuDsEl nuDsKind', draggable: 'true', 'data-input': k[0], onclick: function () { addInput(k[0]); }}, [icon(k[1]), el('span', {text: inputLabel(k[0])})]);
			dragSource(b, {type: 'input', input: k[0], label: inputLabel(k[0])});
			grid.appendChild(b);
		});
		body.appendChild(grid);
		var others = INPUT_TYPES.filter(function (x) { return !INPUT_FREQUENT.some(function (k) { return k[0] === x; }); });
		var moreBtn = el('button', {type: 'button', 'class': 'nuDsKindMore', id: 'nuDsKindMore', 'aria-expanded': addMore ? 'true' : 'false',
			onclick: function () { addMore = !addMore; renderFly(); var m = document.getElementById('nuDsKindMore'); if (m) m.focus(); }},
			[el('span', {text: fmt(t('ds_add_input_all'), INPUT_TYPES.length)}), icon(addMore ? 'bi-chevron-up' : 'bi-chevron-down')]);
		body.appendChild(moreBtn);
		if (addMore) {
			var chips = el('div', {'class': 'nuDsKindChips', id: 'nuDsKindChips'});
			others.sort(function (a, b) { return inputLabel(a).localeCompare(inputLabel(b)); }).forEach(function (it) {
				var c = el('button', {type: 'button', 'class': 'nuDsKindChip', draggable: 'true', 'data-input': it, onclick: function () { addInput(it); }}, [inputLabel(it)]);
				dragSource(c, {type: 'input', input: it, label: inputLabel(it)});
				chips.appendChild(c);
			});
			body.appendChild(chips);
		}
		body.appendChild(el('p', {'class': 'nuDsFlyFoot', text: t('ds_add_input_hint')}));
		if (addDone) body.appendChild(el('div', {'class': 'nuDsKindDone', id: 'nuDsKindDone', role: 'status', text: fmt(t('ds_add_input_done'), addDone)}));
		fly.appendChild(body);
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
		var fid = spec.id ? uniqueId(spec.id) : uniqueId(type === 'contentbox' ? 'box' : 'new_' + (type === 'input' && spec.input ? spec.input.toLowerCase().replace(/[^a-z0-9]/g, '') : type));
		var input = spec.input || (type === 'input' ? 'text' : '');
		var fields = {sob_all_type: type, sob_all_id: fid, sob_all_label: spec.id ? labelOf(spec.id) : (spec.label || t('bf_type_' + type)),
			sob_all_zzzzsys_tab_id: tab, sob_all_order: maxOrder + 10, sob_all_top: pos ? pos.top : bottomOf(tab) + 12, sob_all_left: pos ? pos.left : 170,
			sob_all_width: input === 'checkbox' || input === 'color' ? 30 : (['nuDate', 'date', 'time', 'button', 'nuNumber', 'number', 'month', 'week', 'nuAutoNumber'].indexOf(input) >= 0 ? 120 : size[0]), sob_all_height: input === 'button' ? 30 : size[1], sob_all_align: 'left', sob_input_type: input,
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
		// readable names (review H3): "Date / time: dd.mm.yyyy" instead of the stored "D|dd.mm.yyyy", sorted by type
		var kind = {D: 'fmt_type_date', N: 'fmt_type_number', T: 'fmt_type_text'};
		var nice = function (v) { var k = kind[String(v).charAt(0)]; return k && String(v).charAt(1) === '|' ? t(k) + ': ' + String(v).substring(2) : String(v); };
		list = list.slice().sort(function (a, b) { return String(a.value).charAt(0) === String(b.value).charAt(0) ? 0 : (String(a.value).charAt(0) < String(b.value).charAt(0) ? -1 : 1); });
		var out = [['', '—']].concat(list.map(function (f) { return [f.value, nice(f.value) + (f['default'] ? ' ★' : '') + (f.description ? ' – ' + f.description : '')]; }));
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
		if (phone.matches) panel.insertBefore(sheetHandle, panel.firstChild);
		setSheet(sheet);
		placeQuick();
	}

	// phones (0.8.831): the panel is a bottom sheet with three heights – peek (name of the selection), half, full
	var sheet = 'peek', SHEETS = ['peek', 'half', 'full'];
	var sheetHandle = el('button', {type: 'button', 'class': 'nuDsSheetHandle', id: 'nuDsSheetHandle', title: t('ds_ph_sheet'), 'aria-label': t('ds_ph_sheet')}, [el('span', {'class': 'nuDsSheetGrip'})]);
	function setSheet(st) {
		sheet = SHEETS.indexOf(st) >= 0 ? st : 'peek';
		SHEETS.forEach(function (x) { panel.classList.toggle('nuDsSheet-' + x, phone.matches && x === sheet); });
		sheetHandle.setAttribute('aria-expanded', sheet === 'peek' ? 'false' : 'true');
		panel.setAttribute('data-sheet', phone.matches ? sheet : '');
		if (phone.matches && sheet === 'peek') panel.scrollTop = 0;
	}
	function stepSheet(d) { setSheet(SHEETS[Math.max(0, Math.min(2, SHEETS.indexOf(sheet) + d))]); setTimeout(keepVisible, 260); }
	sheetHandle.addEventListener('click', function () { if (sheetHandle.nuSwiped) { sheetHandle.nuSwiped = false; return; } setSheet(sheet === 'full' ? 'peek' : SHEETS[SHEETS.indexOf(sheet) + 1]); setTimeout(keepVisible, 260); });
	var swY = null;
	panel.addEventListener('touchstart', function (e) { swY = (e.target.closest && e.target.closest('.nuDsSheetHandle, .nuDsHead')) && e.touches[0] ? e.touches[0].clientY : null; }, {passive: true});
	panel.addEventListener('touchend', function (e) {
		if (swY === null || !e.changedTouches[0]) return;
		var dy = e.changedTouches[0].clientY - swY; swY = null;
		if (Math.abs(dy) < 30) return;
		sheetHandle.nuSwiped = e.target.closest && !!e.target.closest('.nuDsSheetHandle');
		stepSheet(dy < 0 ? 1 : -1);
	}, {passive: true});
	panel.addEventListener('focusin', function (e) {      // typing needs room: a field of the peeking sheet opens it half way
		if (phone.matches && sheet === 'peek' && e.target.matches && e.target.matches('input, select, textarea')) stepSheet(1);
	});
	// the selected object stays visible between the bar and the sheet
	function keepVisible() {
		if (!phone.matches || selIds.length !== 1) return;
		var w = document.getElementById('nuw_' + selIds[0]);
		if (!w) return;
		var r = w.getBoundingClientRect(), bottom = window.innerHeight - panel.offsetHeight - 12, top = bar.getBoundingClientRect().bottom + 52;
		if (r.bottom > bottom) window.scrollBy(0, Math.min(r.bottom - bottom, r.top - top));
		else if (r.top < top) window.scrollBy(0, r.top - top);
		placeQuick();
	}
	// quick actions above the selected object (phones): label, up, down, duplicate, delete, all settings
	var quick = el('div', {'class': 'nuDsQuick', id: 'nuDsQuick', role: 'toolbar', 'aria-label': t('ds_ph_quick'), hidden: 'hidden'});
	function qBtn(id, ic, label, fn) { return el('button', {type: 'button', 'class': 'btn btn-sm', id: id, title: label, 'aria-label': label, onclick: function () { var o = objByFid(selIds[0]); if (o) fn(o); }}, [icon(ic)]); }
	[qBtn('nuDsQLabel', 'bi-input-cursor-text', t('ds_ph_label'), function (o) { renameDialog(o); }),
		qBtn('nuDsQUp', 'bi-arrow-up', t('ds_ph_up'), function (o) { order({ids: [o.sob_all_id], dir: -1}); }),
		qBtn('nuDsQDown', 'bi-arrow-down', t('ds_ph_down'), function (o) { order({ids: [o.sob_all_id], dir: 1}); }),
		qBtn('nuDsQDup', 'bi-copy', t('ds_ph_dup'), function (o) { duplicate([o]); }),
		qBtn('nuDsQDel', 'bi-trash', t('ds_ph_del'), function (o) { deleteObjs([o]); }),
		qBtn('nuDsQMore', 'bi-sliders', t('ds_ph_props'), function () { setSheet('half'); setTimeout(keepVisible, 260); })
	].forEach(function (b) { quick.appendChild(b); });
	quick.lastChild.classList.add('nuDsQMore');
	quick.querySelector('#nuDsQDel').classList.add('text-danger');
	function placeQuick() {
		var w = phone.matches && !BROWSE && selIds.length === 1 ? document.getElementById('nuw_' + selIds[0]) : null;
		var r = w ? w.getBoundingClientRect() : null;
		var barB = barAway ? 0 : bar.getBoundingClientRect().bottom, sheetT = window.innerHeight - (panel.offsetHeight || 0);
		if (!r || r.bottom < barB || r.top > sheetT || sheet === 'full') { quick.hidden = true; return; }
		quick.hidden = false;
		var h = quick.offsetHeight || 40, qw = quick.offsetWidth || 240;
		var top = r.top - h - 6;
		if (top < barB + 4) top = Math.min(r.bottom + 6, sheetT - h - 4);
		quick.style.top = Math.round(top) + 'px';
		quick.style.left = Math.round(Math.max(8, Math.min(window.innerWidth - qw - 8, r.left))) + 'px';
	}
	window.addEventListener('resize', placeQuick);

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
						{key: 'sfo_status', options: [['1', t('builder_status_1')], ['0', t('builder_status_0')], ['2', t('builder_status_2')], ['3', t('builder_status_3')]]})])
			], [
				field(t('builder_breadcrumb'), 'text', f.sfo_breadcrumb_title, fsave('sfo_breadcrumb_title', true), {key: 'sfo_breadcrumb_title', mono: true, tip: t('builder_breadcrumb_help')})
			]);
			section('data', t('ds_sec_data'), [
				el('div', {'class': 'nuDsGrid2'}, [
					field(t('builder_table'), 'text', f.sfo_table, fsave('sfo_table', true), {key: 'sfo_table', mono: true, list: 'nuDsTables'}),
					field(t('builder_primary_key'), 'text', f.sfo_primary_key, fsave('sfo_primary_key', true), {key: 'sfo_primary_key', mono: true})]),
				tablesList()
			]);
		}
		if (HASLIST && !D.userhome) section('list', t('ds_sec_list'), [el('div', {'class': 'nuDsField'}, [el('button', {type: 'button', 'class': 'btn btn-sm btn-outline-secondary', id: 'nuDsOpenListFly',
			onclick: function () { openWork('list'); }}, [icon('bi-table me-1'), t('ds_list_open')])]),
			BROWSE && D.edit_url ? el('div', {'class': 'nuDsField'}, [el('a', {'class': 'btn btn-sm btn-outline-secondary', id: 'nuDsDesignEdit', href: D.edit_url}, [icon('bi-window me-1'), t('ds_design_edit')])]) : null]);
		if (!BROWSE) section('layout', t('ds_sec_layout'), [
			D.userhome ? null : field(t('form_layout'), 'select', layout(), function (v) {
				if (v === 'grid') confirmAsk(t('builder_designer_convert'), function () { change('layout.convert', {}, true).catch(function () { /* said */ }); }, t('form_layout_grid'), 'btn btn-primary');
				else change('form.save', {fields: {layout: v}}, true).catch(function () { /* said */ });
			}, {key: 'layout', options: [['absolute', t('form_layout_absolute')], ['auto', t('form_layout_auto')], ['grid', t('form_layout_grid')]]}),
			el('div', {'class': 'form-check form-switch nuDsField'}, [el('input', {type: 'checkbox', 'class': 'form-check-input', role: 'switch', id: 'nuDsTabOrder', checked: tabOrderOn ? true : null,
				onchange: function (e) { tabOrderOn = e.target.checked; post({nuBuilderTabOrder: tabOrderOn}); }}), el('label', {'class': 'form-check-label', 'for': 'nuDsTabOrder', text: t('builder_taborder')})])
		]);
		var tools = [];
		if (D.ai) tools.push(el('button', {type: 'button', 'class': 'btn btn-sm btn-outline-secondary', id: 'nuDsAi', onclick: function () { askAi(); }}, [icon('bi-stars me-1'), t('fai_ask')]));
		if (D.formaccess && typeof window.nuFormAccessOpen === 'function') tools.push(el('button', {type: 'button', 'class': 'btn btn-sm btn-outline-secondary', id: 'nuDsFormAccess',
			onclick: function () { window.nuFormAccessOpen(N.form.id); }}, [icon('bi-people me-1'), t('ds_form_access')]));
		if (D.dataaccess) tools.push(el('a', {'class': 'btn btn-sm btn-outline-secondary', id: 'nuDsDataAccess', href: D.dataaccess}, [icon('bi-shield-lock me-1'), t('da_title')]));
		if (D.translations) {                                    // missing translations of this form and its sub-forms (0.8.754)
			var trm = D.translations.missing || {}, trk = Object.keys(trm);
			tools.push(el('a', {'class': 'btn btn-sm ' + (trk.length ? 'btn-outline-warning' : 'btn-outline-secondary'), id: 'nuDsTranslations', href: D.translations.url, target: '_blank', rel: 'noopener',
				title: trk.length ? trk.map(function (l) { return l + ': ' + trm[l]; }).join(', ') : t('ds_trl_done')},
				[icon('bi-translate me-1'), t('ds_trl'), trk.length ? el('span', {'class': 'badge text-bg-warning ms-1', text: t('ds_trl_missing').replace('%s', trk.map(function (l) { return trm[l]; }).reduce(function (a, b) { return a + b; }, 0))}) : null]));
		}
		if (D.can_delete_form && !DR) tools.push(el('button', {type: 'button', 'class': 'btn btn-sm btn-outline-danger', id: 'nuDsDeleteForm', onclick: deleteForm}, [icon('bi-trash me-1'), t('builder_delete_form')]));
		if (tools.length) section('tools', t('ds_sec_tools'), [el('div', {'class': 'nuDsTools'}, tools)]);
		foot([el('p', {'class': 'nuDsHint', text: t(BROWSE ? 'ds_hint_list' : 'ds_hint')})]);
	}
	// ------------------------------------------------------------------ validation rules (0.8.812, core/form/nurules.php): list + editor
	var VR_NOVAL = ['empty', 'nempty'];
	function vrFields(scope) {
		var R = D.rules, out;
		if (scope) out = ((R.subs[scope] || {}).fields || []).slice();
		else {
			out = R.fields.slice();
			Object.keys(R.subs || {}).forEach(function (sf) { out.push(['count:' + sf, 'COUNT(' + (R.subs[sf].label || sf) + ')', 'number']); });
		}
		return out;
	}
	function vrQuote(v) {
		if (v && typeof v === 'object' && !Array.isArray(v) && v.f !== undefined) return String(v.f);
		if (v && typeof v === 'object' && !Array.isArray(v) && v.h !== undefined) return '#' + v.h + '#';
		if (v === 'today') return 'TODAY';
		v = String(v == null ? '' : v);
		return /^-?\d+(\.\d+)?$/.test(v) ? v : "'" + v.replace(/\\/g, '\\\\').replace(/'/g, "\\'") + "'";
	}
	var VR_SYM = {eq: '=', ne: '!=', lt: '<', gt: '>', le: '<=', ge: '>=', contains: 'CONTAINS', ncontains: 'NOT CONTAINS', starts: 'STARTS WITH', ends: 'ENDS WITH', regex: 'MATCHES', before: 'BEFORE', after: 'AFTER'};
	/** A cond tree as text – as NuFormRules::text(). */
	function vrText(c) {
		if (!c) return '';
		if (c.expr) return String(c.expr);
		return (c.items || []).map(function (it) {
			if (it.items) return '(' + vrText(it) + ')';
			var f = String(it.f).indexOf('count:') === 0 ? 'COUNT(' + String(it.f).slice(6) + ')' : String(it.f), o = it.o, v = it.v;
			if (o === 'empty') return f + ' IS EMPTY';
			if (o === 'nempty') return f + ' IS NOT EMPTY';
			if (o === 'between') return f + ' BETWEEN ' + vrQuote((v || [])[0]) + ' AND ' + vrQuote((v || [])[1]);
			if (o === 'in' || o === 'nin') return f + (o === 'nin' ? ' NOT IN (' : ' IN (') + (Array.isArray(v) ? v : [v]).map(vrQuote).join(', ') + ')';
			return f + ' ' + (VR_SYM[o] || o) + ' ' + vrQuote(v);
		}).join(c.op === 'or' ? ' OR ' : ' AND ');
	}
	var VR_HASH = /^#([A-Za-z_][A-Za-z0-9_]{0,80})#$/;
	/** A typed value: "#NAME#" = hash variable {h}, else the text. */
	function vrParse(x) { x = String(x == null ? '' : x).trim(); var m = VR_HASH.exec(x); return m ? {h: m[1]} : x; }
	function vrRaw(v) { return v && typeof v === 'object' && v.h !== undefined ? '#' + v.h + '#' : String(v == null ? '' : v); }
	/** Tom Select search of the rule fields: plain "contains" (its fuzzy search offered #ID# for #E2E_C#). */
	function vrScore(search) {
		var q = String(search || '').trim().toLowerCase();
		return function (item) { return !q || (String(item.value) + ' ' + String(item.text || '')).toLowerCase().indexOf(q) >= 0 ? 1 : 0; };
	}
	/** Hash variables to choose from: hash cookies of the page (nuSetProperty / globals) and the system variables (N.hashCatalog). */
	function vrHashOptions() {
		var out = [], seen = {};
		function add(n, d) { var v = '#' + n + '#'; if (seen[v]) return; seen[v] = true; out.push({value: v, text: d || v}); }
		Object.keys(N.globals || {}).concat(Object.keys(N.props || {})).forEach(function (n) { if (/^[A-Za-z_][A-Za-z0-9_]*$/.test(n)) add(n, t('vr_hash_cookie')); });
		((N.hashCatalog || {}).groups || []).forEach(function (g) { (g.items || []).forEach(function (it) { add(it[0], it[1]); }); });
		return out;
	}
	function vrSimple(c) { return !c || (!c.expr && (c.items || []).every(function (it) { return !it.items; })); }

	function rulesBox() {
		var R = D.rules, list = R.list || [];
		var rows = list.map(function (r, i) {
			var sw = el('input', {type: 'checkbox', 'class': 'form-check-input', role: 'switch', checked: r.on ? true : null, 'aria-label': t('vr_on'), 'data-vr-on': r.id,
				onchange: function (e) { var all = list.map(function (x) { return Object.assign({}, x); }); all[i].on = e.target.checked; vrSave(all); }});
			return el('div', {'class': 'nuDsRule' + (r.on ? '' : ' nuDsRuleOff'), 'data-vr': r.id}, [
				el('div', {'class': 'form-check form-switch m-0'}, [sw]),
				el('button', {type: 'button', 'class': 'nuDsRuleMain', 'data-vr-edit': r.id, onclick: function () { ruleDialog(i); }}, [
					el('span', {'class': 'badge me-1 ' + (r.kind === 'warn' ? 'text-bg-warning' : 'text-bg-danger'), text: t(r.kind === 'warn' ? 'vr_kind_warn' : 'vr_kind_error')}),
					el('span', {'class': 'nuDsRuleName', text: r.name || r.msg}),
					el('code', {'class': 'nuDsRuleCond', text: (r.when ? t('vr_when_short') + ' ' + vrText(r.when) + ' → ' : '') + vrText(r.check)})]),
				el('button', {type: 'button', 'class': 'btn btn-sm btn-link text-danger p-0', 'data-vr-del': r.id, title: t('delete'), 'aria-label': t('delete'),
					onclick: function () { confirmAsk(fmt(t('vr_del_confirm'), r.name || r.msg), function () { vrSave(list.filter(function (x, j) { return j !== i; })); }, t('delete'), 'btn btn-danger'); }}, [icon('bi-trash')])]);
		});
		return el('div', {id: 'nuDsRules'}, [
			rows.length ? el('div', {'class': 'nuDsRuleList'}, rows) : el('p', {'class': 'nuDsHint mb-2', text: t('vr_none')}),
			el('div', {'class': 'd-flex flex-wrap gap-2'}, [
				el('button', {type: 'button', 'class': 'btn btn-sm btn-outline-primary', id: 'nuDsRuleAdd', onclick: function () { ruleDialog(-1); }}, [icon('bi-plus-lg me-1'), t('vr_add')]),
				list.length ? el('button', {type: 'button', 'class': 'btn btn-sm btn-outline-secondary', id: 'nuDsRuleTestAll', onclick: function () { vrTestAll(); }}, [icon('bi-check2-circle me-1'), t('vr_test_all')]) : null]),
			el('div', {'class': 'small mt-2', id: 'nuDsRuleTestOut', 'aria-live': 'polite'})]);
	}
	function vrRefresh() {
		var n = (D.rules.list || []).length, b = document.getElementById('nuDsRailRulesN');
		if (b) { b.textContent = String(n); b.hidden = !n; }
		renderFly();
	}
	function vrSave(all) {
		return change('rules.save', {rules: all}, false).then(function (j) { D.rules.list = j.rules || []; vrRefresh(); return j; }).catch(function () { vrRefresh(); });
	}
	function vrTestRid() { return N.record_id && N.record_id !== '-1' ? String(N.record_id) : ''; }
	function vrResults(box, j) {
		box.textContent = '';
		if (!j.ok) { box.appendChild(el('div', {'class': 'text-danger', text: j.error || 'Error'})); return; }
		box.appendChild(el('div', {'class': 'text-body-secondary mb-1', text: fmt(t('vr_test_on'), j.record_id)}));
		(j.results || []).forEach(function (x) {
			box.appendChild(el('div', {'class': x.ok ? 'text-success' : 'text-danger', 'data-vr-res': x.ok ? 'ok' : 'fail'}, [icon(x.ok ? 'bi-check-circle me-1' : 'bi-x-circle me-1'),
				(x.name ? x.name + ': ' : '') + (x.ok ? t('vr_test_ok') : x.message)]));
		});
	}
	function vrTestAll() {
		var box = document.getElementById('nuDsRuleTestOut');
		api('rules.test', {rules: D.rules.list, record_id: vrTestRid()}).then(function (j) { vrResults(box, j); }).catch(function (e) { say(e.message, true); });
	}

	/** One condition editor: builder (all / any + rows) or expression. Returns {root, get(): cond | null | false (error), setScope(scope)}. */
	function condEditor(idp, cond, scope, optional) {
		var mode = vrSimple(cond) ? 'builder' : 'expr', rows = [];
		var root = el('div', {'class': 'nuDsCond', id: idp});
		var seg = el('div', {'class': 'btn-group btn-group-sm mb-2', role: 'group'});
		['builder', 'expr'].forEach(function (m) {
			var rid = idp + 'M' + m;
			seg.appendChild(el('input', {type: 'radio', 'class': 'btn-check', name: idp + 'Mode', id: rid, value: m, checked: m === mode ? true : null,
				onchange: function () { switchMode(m); }}));
			seg.appendChild(el('label', {'class': 'btn btn-outline-secondary', 'for': rid, text: t(m === 'builder' ? 'vr_mode_builder' : 'vr_mode_expr')}));
		});
		var join = el('select', {'class': 'form-select form-select-sm w-auto', id: idp + 'Join', 'aria-label': t('vr_join')}, [
			el('option', {value: 'and', text: t('vr_join_all')}), el('option', {value: 'or', text: t('vr_join_any')})]);
		join.value = cond && cond.op === 'or' ? 'or' : 'and';
		var rowBox = el('div', {'class': 'nuDsCondRows', id: idp + 'Rows'});
		var addRow = el('button', {type: 'button', 'class': 'btn btn-sm btn-outline-secondary', id: idp + 'AddRow', onclick: function () { rows.push(row(null)); draw(); }}, [icon('bi-plus me-1'), t('vr_add_cond')]);
		var builder = el('div', {'class': 'nuDsCondBuilder'}, [el('div', {'class': 'mb-2'}, [join]), rowBox, addRow]);
		var expr = el('textarea', {'class': 'form-control form-control-sm font-monospace', rows: '3', id: idp + 'Expr', spellcheck: 'false', 'aria-label': t('vr_mode_expr'), placeholder: "status = 'shipped' AND ship_date IS NOT EMPTY"});
		var exprHelp = el('div', {'class': 'form-text', text: t('vr_expr_help') + ' ' + t('vr_hash_help')});
		var exprBox = el('div', {'class': 'nuDsCondExpr'}, [expr, exprHelp]);
		var err = el('div', {'class': 'small text-danger mt-1', id: idp + 'Err'});
		root.appendChild(seg); root.appendChild(builder); root.appendChild(exprBox); root.appendChild(err);

		function opSel(v) {
			var s = el('select', {'class': 'form-select form-select-sm', 'data-vr': 'o', 'aria-label': t('vr_operator')});
			D.rules.ops.forEach(function (o) { s.appendChild(el('option', {value: o, text: t('vr_op_' + o)})); });
			s.value = v || 'eq';
			return s;
		}
		function fieldSel(v, extra) {
			var s = el('select', {'class': 'form-select form-select-sm', 'data-vr': extra || 'f', 'aria-label': t('vr_field')});
			vrFields(scope).forEach(function (f) { s.appendChild(el('option', {value: f[0], text: f[0] + (f[1] && f[1] !== f[0] ? ' – ' + f[1] : '')})); });
			if (v) { if (!Array.prototype.some.call(s.options, function (o) { return o.value === v; })) s.appendChild(el('option', {value: v, text: v})); s.value = v; }
			return s;
		}
		function row(leaf) {
			leaf = leaf || {};
			var v = leaf.v, kind = v && typeof v === 'object' && !Array.isArray(v) && v.f !== undefined ? 'field' : (v === 'today' ? 'today' : 'value');
			var r = el('div', {'class': 'nuDsCondRow'});
			var f = fieldSel(leaf.f), o = opSel(leaf.o);
			var vk = el('select', {'class': 'form-select form-select-sm', 'data-vr': 'vk', 'aria-label': t('vr_value_kind')}, [
				el('option', {value: 'value', text: t('vr_vk_value')}), el('option', {value: 'field', text: t('vr_vk_field')}), el('option', {value: 'today', text: t('vr_vk_today')})]);
			vk.value = kind;
			// one value (0.8.814): free text or a hash variable #NAME# – a searchable field that takes any text (Tom Select, create)
			var one = el('input', {type: 'text', 'class': 'form-control form-control-sm', 'data-vr': 'one', 'aria-label': t('vr_value'), placeholder: t('vr_value_ph')});
			var a = el('input', {type: 'text', 'class': 'form-control form-control-sm', 'data-vr': 'a', 'aria-label': t('vr_value')});
			var b = el('input', {type: 'text', 'class': 'form-control form-control-sm', 'data-vr': 'b', 'aria-label': t('vr_value')});
			var vf = fieldSel(kind === 'field' ? v.f : '', 'vf');
			if (leaf.o === 'between' && Array.isArray(v)) { a.value = vrRaw(v[0]); b.value = vrRaw(v[1]); }
			else if ((leaf.o === 'in' || leaf.o === 'nin') && Array.isArray(v)) a.value = v.map(vrRaw).join(', ');
			else if (kind === 'value' && v !== undefined && v !== null) one.value = vrRaw(v);
			var del = el('button', {type: 'button', 'class': 'btn btn-sm btn-link text-danger p-0', title: t('remove'), 'aria-label': t('remove'), onclick: function () { api2.destroy(); rows = rows.filter(function (x) { return x !== api2; }); draw(); }}, [icon('bi-x-lg')]);
			[f, o, vk, vf, one, a, b, del].forEach(function (x) { r.appendChild(x); });
			if (window.TomSelect) {             // the left side (0.8.815): a field or a hash variable #NAME# (hash cookies, #USER_ID# …)
				var lcur = f.value, lopts = Array.prototype.map.call(f.options, function (op) { return {value: op.value, text: op.textContent}; }).concat(vrHashOptions());
				new window.TomSelect(f, {items: lcur ? [lcur] : [], create: function (input) { input = input.trim(); return VR_HASH.test(input) ? {value: input, text: input} : false; },
					createFilter: function (input) { return VR_HASH.test(String(input).trim()); }, addPrecedence: true, persist: false, maxItems: 1, options: lopts, valueField: 'value', labelField: 'text',
					searchField: ['value', 'text'], score: vrScore, refreshThrottle: 0, dropdownParent: 'body', dropdownClass: 'ts-dropdown nuTsDropdown nuTsSmall', maxOptions: 400,
					render: {option_create: function (d, e) { return '<div class="create">' + e(t('vr_value_use')).replace('%s', '<strong>' + e(d.input) + '</strong>') + '</div>'; }}});
			}
			if (window.TomSelect) {
				var opts = vrHashOptions(), cur = one.value;
				if (cur !== '' && !opts.some(function (x) { return x.value === cur; })) opts.unshift({value: cur, text: cur});
				new window.TomSelect(one, {create: true, createOnBlur: true, addPrecedence: true, persist: false, maxItems: 1, options: opts, items: cur !== '' ? [cur] : [], valueField: 'value',
					labelField: 'text', searchField: ['value', 'text'], score: vrScore, refreshThrottle: 0, dropdownParent: 'body', dropdownClass: 'ts-dropdown nuTsDropdown nuTsSmall', maxOptions: 300,
					render: {option: function (d, e) { return '<div><code>' + e(d.value) + '</code>' + (d.text && d.text !== d.value ? ' <span class="small text-body-secondary">' + e(d.text) + '</span>' : '') + '</div>'; },
						item: function (d, e) { return '<div>' + e(d.value) + '</div>'; },
						option_create: function (d, e) { return '<div class="create">' + e(t('vr_value_use')).replace('%s', '<strong>' + e(d.input) + '</strong>') + '</div>'; }}});
			}
			function oneBox() { return one.tomselect ? one.tomselect.wrapper : one; }
			function vis() {
				var op = o.value, noVal = VR_NOVAL.indexOf(op) >= 0, multi = op === 'between' || op === 'in' || op === 'nin';
				vk.hidden = noVal || multi;
				if (multi && vk.value !== 'value') vk.value = 'value';
				vf.hidden = noVal || multi || vk.value !== 'field';
				oneBox().hidden = noVal || multi || vk.value !== 'value';
				a.hidden = noVal || !multi;
				b.hidden = op !== 'between';
				a.placeholder = op === 'in' || op === 'nin' ? t('vr_in_ph') : '';
				if (one.tomselect) one.tomselect.settings.placeholder = op === 'regex' ? '^[A-Z]{2}\\d+$' : t('vr_value_ph');
			}
			[o, vk].forEach(function (x) { x.addEventListener('change', vis); });
			vis();
			var api2 = {root: r, destroy: function () { [f, one].forEach(function (x) { if (x.tomselect) x.tomselect.destroy(); }); }, get: function () {
				var op = o.value, out = {f: f.value, o: op};
				if (VR_NOVAL.indexOf(op) >= 0) return out;
				if (op === 'between') out.v = [vrParse(a.value), vrParse(b.value)];
				else if (op === 'in' || op === 'nin') out.v = a.value.split(',').map(function (x) { return x.trim(); }).filter(function (x) { return x !== ''; }).map(vrParse);
				else out.v = vk.value === 'field' ? {f: vf.value} : (vk.value === 'today' ? 'today' : (op === 'regex' ? one.value.trim() : vrParse(one.value)));
				return out;
			}};
			return api2;
		}
		function draw() {
			rowBox.textContent = '';
			if (!rows.length) rowBox.appendChild(el('p', {'class': 'nuDsHint mb-2', text: t(optional ? 'vr_when_none' : 'vr_cond_none')}));
			rows.forEach(function (r) { rowBox.appendChild(r.root); });
		}
		function fromCond(c) { rows.forEach(function (x) { x.destroy(); }); rows = (c && c.items || []).map(function (it) { return row(it); }); join.value = c && c.op === 'or' ? 'or' : 'and'; draw(); }
		function builderCond() { return {op: join.value, items: rows.map(function (r) { return r.get(); })}; }
		function switchMode(m) {
			err.textContent = '';
			if (m === 'expr') { expr.value = rows.length ? vrText(builderCond()) : ''; mode = m; vis2(); return; }
			if (expr.value.trim() === '') { fromCond(null); mode = m; vis2(); return; }
			api('rules.parse', {expr: expr.value}).then(function (j) {
				if (!j.ok) { err.textContent = j.error || ''; back('expr'); return; }
				if (!vrSimple(j.tree)) { err.textContent = t('vr_nested'); back('expr'); return; }
				fromCond(j.tree); mode = m; vis2();
			});
		}
		function back(m) { var r = document.getElementById(idp + 'M' + m); if (r) r.checked = true; mode = m; vis2(); }
		function vis2() { builder.hidden = mode !== 'builder'; exprBox.hidden = mode !== 'expr'; }
		if (mode === 'builder') fromCond(cond); else expr.value = vrText(cond);
		draw(); vis2();
		return {root: root,
			get: function () {
				if (mode === 'expr') return expr.value.trim() === '' ? null : {expr: expr.value.trim()};
				return rows.length ? builderCond() : null;
			},
			error: function (m) { err.textContent = m || ''; },
			setScope: function (s) { var cur = this.get(); scope = s; if (mode === 'builder') fromCond(cur && !cur.expr ? {op: cur.op, items: cur.items.filter(function (it) { return String(it.f).charAt(0) === '#' || vrFields(s).some(function (f) { return f[0] === it.f; }); })} : null); }};
	}

	var vrDlg = null;
	function ruleDialog(index) {
		if (vrDlg) { vrDlg.root.querySelectorAll('select, input').forEach(function (x) { if (x.tomselect) x.tomselect.destroy(); }); vrDlg.root.remove(); vrDlg = null; }
		var R = D.rules, r = index >= 0 ? JSON.parse(JSON.stringify(R.list[index])) : {id: '', on: true, kind: 'error', scope: '', msg: '', name: '', field: '', levels: [], perm: '', check: null, when: null};
		var name = el('input', {type: 'text', 'class': 'form-control form-control-sm', id: 'nuDsRuleName', maxlength: '100', value: r.name || ''});
		var msgF = el('textarea', {'class': 'form-control form-control-sm', id: 'nuDsRuleMsg', rows: '2', maxlength: '500', placeholder: t('vr_msg_ph')});
		msgF.value = r.msg || '';
		var kind = el('select', {'class': 'form-select form-select-sm', id: 'nuDsRuleKind'}, [el('option', {value: 'error', text: t('vr_kind_error_long')}), el('option', {value: 'warn', text: t('vr_kind_warn_long')})]);
		kind.value = r.kind === 'warn' ? 'warn' : 'error';
		var scope = el('select', {'class': 'form-select form-select-sm', id: 'nuDsRuleScope'}, [el('option', {value: '', text: t('vr_scope_record')})]);
		Object.keys(R.subs || {}).forEach(function (sf) { scope.appendChild(el('option', {value: sf, text: fmt(t('vr_scope_rows'), R.subs[sf].label || sf)})); });
		scope.value = r.scope || '';
		var mark = el('select', {'class': 'form-select form-select-sm', id: 'nuDsRuleField'});
		function fillMark() {
			mark.textContent = '';
			mark.appendChild(el('option', {value: '', text: t('vr_field_none')}));
			if (scope.value === '') R.fields.forEach(function (f) { mark.appendChild(el('option', {value: f[0], text: f[0] + (f[1] ? ' – ' + f[1] : '')})); });
			mark.value = scope.value === '' ? r.field || '' : '';
			mark.disabled = scope.value !== '';
		}
		fillMark();
		var check = condEditor('nuDsRuleCheck', r.check, r.scope || '', false);
		var whenOn = el('input', {type: 'checkbox', 'class': 'form-check-input', id: 'nuDsRuleWhenOn', checked: r.when ? true : null});
		var when = condEditor('nuDsRuleWhen', r.when, r.scope || '', true);
		when.root.hidden = !r.when;
		whenOn.addEventListener('change', function () { when.root.hidden = !whenOn.checked; });
		scope.addEventListener('change', function () { fillMark(); check.setScope(scope.value); when.setScope(scope.value); });
		var lvBox = el('div', {'class': 'nuDsRuleLevels border rounded p-2', id: 'nuDsRuleLevels'});
		R.levels.forEach(function (l) {
			var id = 'nuDsRuleLv_' + l[0].replace(/[^\w-]/g, '_');
			lvBox.appendChild(el('div', {'class': 'form-check'}, [el('input', {type: 'checkbox', 'class': 'form-check-input', id: id, value: l[0], checked: (r.levels || []).indexOf(l[0]) >= 0 ? true : null}),
				el('label', {'class': 'form-check-label', 'for': id, text: l[1]})]));
		});
		var perm = el('select', {'class': 'form-select form-select-sm', id: 'nuDsRulePerm'}, [el('option', {value: '', text: t('vr_perm_any')})]);
		R.perms.forEach(function (p) { perm.appendChild(el('option', {value: p[0], text: p[1]})); });
		if (r.perm && !R.perms.some(function (p) { return p[0] === r.perm; })) perm.appendChild(el('option', {value: r.perm, text: r.perm}));
		perm.value = r.perm || '';
		var on = el('input', {type: 'checkbox', 'class': 'form-check-input', role: 'switch', id: 'nuDsRuleOn', checked: r.on ? true : null});
		var testRid = el('input', {type: 'text', 'class': 'form-control form-control-sm', id: 'nuDsRuleTestId', placeholder: t('vr_test_id_ph'), value: vrTestRid(), 'aria-label': t('vr_test_id')});
		var testOut = el('div', {'class': 'small mt-2', id: 'nuDsRuleTestRes', 'aria-live': 'polite'});
		// errors on top of the dialogue, always in sight (0.8.816): sticky, the dialogue scrolls up, the field gets the focus
		var errAll = el('div', {'class': 'alert alert-danger py-2 px-3 small nuDsRuleErr', id: 'nuDsRuleErr', role: 'alert', hidden: true});
		function showErr(text, target) {
			errAll.textContent = '';
			errAll.appendChild(icon('bi-exclamation-triangle-fill me-2'));
			String(text || '').split('\n').forEach(function (line, i) { if (i) errAll.appendChild(el('br')); errAll.appendChild(document.createTextNode(line)); });
			errAll.hidden = false;
			var mb = vrDlg && vrDlg.root.querySelector('.modal-body');
			if (mb) mb.scrollTop = 0;
			if (target) {
				target.classList.add(target.matches('input, textarea, select') ? 'is-invalid' : 'nuDsCondBad');
				var f = target.matches('input, textarea, select') ? target : target.querySelector('input, textarea, select, button');
				if (f) setTimeout(function () { try { f.focus({preventScroll: true}); } catch (e) { /* ignore */ } }, 50);
			}
		}
		function clearErr() {
			errAll.hidden = true; errAll.textContent = '';
			[msgF, check.root].forEach(function (x) { x.classList.remove('is-invalid', 'nuDsCondBad'); });
		}
		msgF.addEventListener('input', function () { msgF.classList.remove('is-invalid'); });
		['input', 'change'].forEach(function (ev) { check.root.addEventListener(ev, function () { check.root.classList.remove('nuDsCondBad'); }); });
		function lbl(forId, text, tip) {
			return el('label', {'class': 'form-label small mb-1', 'for': forId}, [text, tip ? el('span', {'class': 'nuInfoTip ms-1', tabindex: '0', role: 'img', 'data-bs-toggle': 'tooltip', 'aria-label': tip, title: tip}, [icon('bi-info-circle')]) : null]);
		}
		function collect() {
			var c = check.get(), w = whenOn.checked ? when.get() : null;
			return {id: r.id, on: on.checked, name: name.value.trim(), msg: msgF.value.trim(), kind: kind.value, scope: scope.value, field: mark.value, check: c, when: w,
				levels: Array.prototype.filter.call(lvBox.querySelectorAll('input:checked'), function () { return true; }).map(function (x) { return x.value; }), perm: perm.value};
		}
		var bTest = el('button', {type: 'button', 'class': 'btn btn-outline-secondary me-auto', id: 'nuDsRuleTest'}, [icon('bi-check2-circle me-1'), t('vr_test')]);
		var bSave = el('button', {type: 'button', 'class': 'btn btn-primary', id: 'nuDsRuleSave', text: t('save')});
		bTest.addEventListener('click', function () {
			var x = collect();
			if (!x.id) x.id = 'test';
			api('rules.test', {rules: [x], record_id: testRid.value.trim()}).then(function (j) { vrResults(testOut, j); }).catch(function (e) { testOut.textContent = e.message; });
		});
		bSave.addEventListener('click', function () {
			var x = collect(), all = R.list.map(function (y) { return Object.assign({}, y); });
			if (index >= 0) all[index] = x; else all.push(x);
			clearErr();
			if (!x.msg) { showErr(fmt(t('vr_err_msg'), x.name || t('vr_rule')), msgF); return; }     // the usual gaps at once, without the server
			if (!x.check) { showErr(fmt(t('vr_err_check'), x.name || t('vr_rule')), check.root); return; }
			bSave.disabled = true;
			change('rules.save', {rules: all}, false).then(function (j) {
				bSave.disabled = false;
				D.rules.list = j.rules || [];
				hide(vrDlg.root);
				vrRefresh();
			}).catch(function (e) { bSave.disabled = false; showErr(e.message, null); });
		});
		var body = [errAll,
			el('div', {'class': 'nuDsGrid2'}, [el('div', {}, [lbl('nuDsRuleName', t('vr_name')), name]), el('div', {}, [lbl('nuDsRuleKind', t('vr_kind')), kind])]),
			el('div', {'class': 'mt-2'}, [lbl('nuDsRuleMsg', t('vr_msg'), t('vr_msg_tip') + ' ' + t('vr_hash_help')), msgF]),
			el('div', {'class': 'nuDsGrid2 mt-2'}, [el('div', {}, [lbl('nuDsRuleScope', t('vr_scope'), t('vr_scope_tip')), scope]), el('div', {}, [lbl('nuDsRuleField', t('vr_mark'), t('vr_mark_tip')), mark])]),
			el('h3', {'class': 'fs-6 mt-3 mb-1', text: t('vr_check')}), el('div', {'class': 'form-text mt-0 mb-2', text: t('vr_check_hint')}), check.root,
			el('div', {'class': 'form-check mt-3'}, [whenOn, el('label', {'class': 'form-check-label', 'for': 'nuDsRuleWhenOn', text: t('vr_when')})]), when.root,
			el('details', {'class': 'mt-3', id: 'nuDsRuleWho', open: (r.levels || []).length || r.perm ? true : null}, [el('summary', {'class': 'small', text: t('vr_who')}),
				el('div', {'class': 'form-text mb-1', text: t('vr_who_hint')}), lbl('nuDsRuleLevels', t('vr_levels')), lvBox, el('div', {'class': 'mt-2'}, [lbl('nuDsRulePerm', t('vr_perm')), perm])]),
			el('div', {'class': 'form-check form-switch mt-3'}, [on, el('label', {'class': 'form-check-label', 'for': 'nuDsRuleOn', text: t('vr_on')})]),
			el('div', {'class': 'd-flex gap-2 align-items-center mt-3'}, [el('span', {'class': 'small text-nowrap', text: t('vr_test_id')}), testRid]), testOut];
		vrDlg = {root: modal('nuDsRuleDlg', t(index >= 0 ? 'vr_edit' : 'vr_add'), body, [bTest, bSave], true)};
		vrDlg.root.addEventListener('hidden.bs.modal', function () {
			if (!vrDlg) return;
			vrDlg.root.querySelectorAll('select, input').forEach(function (x) { if (x.tomselect) x.tomselect.destroy(); });   // their dropdowns live in <body>
			vrDlg.root.remove(); vrDlg = null;
		});
		show(vrDlg.root);
		try { vrDlg.root.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function (x) { window.nuBs.tooltip(x); }); } catch (e) { /* ignore */ }
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
		var runType = nunext(o).url ? 'U' : String(o.sob_run_type || '').toUpperCase() || ((D.procedures || []).some(function (p) { return p[0] === o.sob_run_zzzzsys_form_id; }) ? 'P' : ((D.reports || []).some(function (p) { return p[0] === o.sob_run_zzzzsys_form_id; }) ? 'R' : 'F'));
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
			type === 'select' ? field(t('builder_select_sql'), 'textarea', o.sob_select_sql, save('sob_select_sql'), {key: 'sob_select_sql', code: 'SQL', mono: true, rows: '4', tip: t('builder_select_sql_help')}) : null,
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
			type === 'run' ? field(t('builder_run_type'), 'select', runType, function (v) {   // URL (0.8.830): kept in sob_all_json nunext.url
				if (v === 'U') saveObj(o, {nunext_run_url: 'https://', sob_run_type: null, sob_run_target: '2'});
				else saveObj(o, {sob_run_type: v, nunext_run_url: runType === 'U' ? null : undefined});
			}, {key: 'sob_run_type', options: [['F', t('builder_run_type_F')], ['P', t('builder_run_type_P')], ['R', t('builder_run_type_R')], ['U', t('builder_run_type_U')]]}) : null,
			type === 'run' && runType === 'U' ? field(t('builder_run_url'), 'text', nunext(o).url || '', save('nunext_run_url'), {key: 'nunext_run_url', mono: true, ph: 'https://example.com/item?id=#RECORD_ID#', tip: t('builder_run_url_help')}) : null,
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
			type === 'run' && runType === 'U' && o.sob_run_method !== 'i' ? field(t('builder_run_target'), 'select', ['0', '1'].indexOf(String(o.sob_run_target)) >= 0 ? '0' : (String(o.sob_run_target) === '3' ? '3' : '2'), save('sob_run_target'), {key: 'sob_run_target',
				options: [['2', t('builder_run_target_2')], ['3', t('builder_run_url_popup')], ['0', t('builder_run_url_same')]]}) : null,
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
			respFields(o, g).forEach(function (x) { if (x) layRows.push(x); });
		}
		layRows.push(hideField(o));
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
	// responsive containers (0.8.959, core/form/nuresponsive.php): a content box's direction, an object's width in a box, hide per screen
	function respSave(o, part) {
		var cur = objByFid(o.sob_all_id) || o;
		return change('object.save', {object_id: cur.zzzzsys_object_id, grid: part, fields: {}}, true).catch(function () { /* said */ });
	}
	function respFields(o, g) {
		var out = [], st = g.stack || null, sz = g.size || {};
		if (o.sob_all_type === 'contentbox') {
			var dirSave = function (v) { respSave(o, {stack: v === '' ? null : Object.assign({}, st || {}, {dir: v})}); };
			out.push(field(t('ds_stack_dir'), 'select', st ? st.dir : '', dirSave, {key: 'stack_dir', tip: t('ds_stack_dir_tip'),
				options: [['', t('ds_stack_none')], ['v', t('ds_stack_v')], ['h', t('ds_stack_h')], ['g', t('ds_stack_g')]]}));
			if (st) {
				var stSave = function (k) { return function (v) { var n = Object.assign({}, st); n[k] = v === '' ? null : v; if (k === 'wrap' || k === 'phone_v') n[k] = v === '1'; respSave(o, {stack: n}); }; };
				var row1 = [field(t('ds_stack_gap'), 'number', st.gap === undefined ? '' : st.gap, stSave('gap'), {key: 'stack_gap', ph: '12'}),
					field(t('ds_stack_align'), 'select', st.align || '', stSave('align'), {key: 'stack_align', options: [['', '—'], ['start', t('ds_al_start')], ['center', t('ds_al_center')], ['end', t('ds_al_end')], ['stretch', t('ds_al_stretch')]]})];
				out.push(el('div', {'class': 'nuDsGrid2'}, row1));
				if (st.dir !== 'g') out.push(field(t('ds_stack_justify'), 'select', st.justify || '', stSave('justify'), {key: 'stack_justify',
					options: [['', '—'], ['start', t('ds_al_start')], ['center', t('ds_al_center')], ['end', t('ds_al_end')], ['between', t('ds_al_between')]]}));
				if (st.dir === 'h') {
					out.push(field(t('ds_stack_wrap'), 'check', st.wrap ? '1' : '0', stSave('wrap'), {key: 'stack_wrap'}));
					out.push(field(t('ds_stack_phone_v'), 'check', st.phone_v === false ? '0' : '1', stSave('phone_v'), {key: 'stack_phone_v'}));
				}
				if (st.dir === 'g') {
					var cols = st.cols || {lg: 2, md: 2, sm: 1};
					var colSave = function (k) { return function (v) { var c = Object.assign({}, cols); c[k] = v; respSave(o, {stack: Object.assign({}, st, {cols: c})}); }; };
					out.push(el('div', {'class': 'nuDsGrid3'}, ['lg', 'md', 'sm'].map(function (k) {
						return field(t('ds_cols_' + k), 'number', cols[k], colSave(k), {key: 'stack_cols_' + k});
					})));
				}
			}
		}
		// the object's width inside a box with a direction
		var szSave = function (k) { return function (v) { var n = Object.assign({}, sz); n[k] = v === '' ? null : v; respSave(o, {size: n}); }; };
		out.push(field(t('ds_size_w'), 'select', sz.w || '', szSave('w'), {key: 'size_w', tip: t('ds_size_tip'),
			options: [['', t('ds_size_auto')], ['fixed', t('ds_size_fixed')], ['fit', t('ds_size_fit')], ['fill', t('ds_size_fill')]]}));
		var more = [];
		if (sz.w === 'fixed') more.push(field(t('ds_size_px'), 'number', sz.px, szSave('px'), {key: 'size_px'}));
		if (sz.w === 'fill') more.push(field(t('ds_size_grow'), 'number', sz.grow || 1, szSave('grow'), {key: 'size_grow'}));
		more.push(field(t('ds_size_min'), 'number', sz.min, szSave('min'), {key: 'size_min'}));
		more.push(field(t('ds_size_max'), 'number', sz.max, szSave('max'), {key: 'size_max'}));
		more.push(field(t('ds_size_span'), 'number', sz.span, szSave('span'), {key: 'size_span'}));
		out.push(el('div', {'class': 'nuDsGrid2'}, more));
		return out;
	}
	/** "Put in a new box" (0.8.959): a content box with a vertical direction around the selected objects, at the place of the first one. */
	function wrapInBox(objs) {
		ensureGrid().then(function () {
			var list = objs.map(function (o) { return objByFid(o.sob_all_id) || o; });
			var first = list.slice().sort(function (a, b) { var x = nunext(a), y = nunext(b); return ((+x.row || 0) - (+y.row || 0)) || ((+x.col || 0) - (+y.col || 0)); })[0];
			var g0 = nunext(first), fid = uniqueId('box');
			var top = Math.min.apply(null, list.map(function (o) { return +o.sob_all_top || 0; })), left = Math.min.apply(null, list.map(function (o) { return +o.sob_all_left || 0; }));
			var right = Math.max.apply(null, list.map(function (o) { return (+o.sob_all_left || 0) + (+o.sob_all_width || 0); })), bottom = Math.max.apply(null, list.map(function (o) { return (+o.sob_all_top || 0) + (+o.sob_all_height || 0); }));
			var fields = {sob_all_type: 'contentbox', sob_all_id: fid, sob_all_label: '', sob_all_zzzzsys_tab_id: first.sob_all_zzzzsys_tab_id, sob_all_order: +first.sob_all_order || 0,
				sob_all_top: Math.max(0, top - 30), sob_all_left: Math.max(0, left - 10), sob_all_width: right - left + 20, sob_all_height: bottom - top + 40, sob_all_align: 'left'};
			var steps = [['object.save', {object_id: '', fields: fields, grid: {row: g0.row || '', col: g0.col || '', span: '12', box: g0.box || '', stack: {dir: 'v'}}}]];
			list.forEach(function (o, i) { steps.push(['object.save', {object_id: o.zzzzsys_object_id, fields: {}, grid: {box: fid, row: (i + 1) * 10, col: 10}}]); });
			selIds = [fid];
			chain(steps);
		}).catch(function () { /* said */ });
	}
	function hideField(o) {
		var hd = nunext(o).hide || [];
		var boxes = ['sm', 'md', 'lg'].map(function (k) {
			var id = 'nuDsHide_' + k;
			var inp = el('input', {type: 'checkbox', 'class': 'form-check-input', id: id, 'data-key': 'hide_' + k, checked: hd.indexOf(k) >= 0 ? true : null});
			inp.addEventListener('change', function () {
				var now = ['sm', 'md', 'lg'].filter(function (x) { var c = document.getElementById('nuDsHide_' + x); return c && c.checked; });
				respSave(o, {hide: now.length ? now : null});
			});
			return el('div', {'class': 'form-check form-check-inline'}, [inp, el('label', {'class': 'form-check-label', 'for': id, text: t('ds_hide_' + k)})]);
		});
		return el('div', {'class': 'nuDsField', id: 'nuDsHideOn'}, [el('div', {'class': 'nuDsLabelRow'}, [el('span', {'class': 'form-label nuDsLabel', text: t('ds_hide_on')}),
			el('span', {'class': 'nuInfoTip ms-1', tabindex: '0', role: 'img', 'data-bs-toggle': 'tooltip', 'data-bs-custom-class': 'nuTipWide', 'aria-label': t('ds_hide_tip'), title: t('ds_hide_tip')}, [icon('bi-info-circle')])]),
			el('div', {}, boxes)]);
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
		if (layout() !== 'absolute' && objs.every(function (o) { return o.sob_all_type !== 'contentbox'; })) btns.unshift(el('button', {type: 'button', 'class': 'btn btn-sm btn-outline-secondary', id: 'nuDsWrapBox',
			title: t('ds_wrap_box'), 'aria-label': t('ds_wrap_box'), onclick: function () { wrapInBox(objs); }}, [icon('bi-bounding-box')]));
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
			if (phone.matches) setTimeout(keepVisible, 60);
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
		W.opener = document.activeElement;
		work.hidden = false;
		document.documentElement.classList.add('nuDsWorkOpen');
		renderWork();
		// the focus goes into the workspace (aria-modal, review H2) – Esc closes it then; the code editor takes it itself
		setTimeout(function () { if (!W.kind || work.contains(document.activeElement)) return; work.setAttribute('tabindex', '-1'); work.focus({preventScroll: true}); }, 80);
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
		if (W.opener && document.body.contains(W.opener)) { try { W.opener.focus({preventScroll: true}); } catch (x) { /* ignore */ } }
		W.opener = null;
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
		[['code', 'bi-code-square', 'ds_code'], ['list', 'bi-table', 'ds_list_title'], ['history', 'bi-clock-history', 'ds_history'], ['changes', 'bi-file-diff', 'ds_changes']].forEach(function (x) {
			if (x[0] === 'changes' && !DR) return;
			if (x[0] === 'list' && !HASLIST) return;
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
		else if (W.kind === 'list') renderListFly(body);
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
		function saveCode(force) {
			var dirty = codeDirty();
			if (!dirty.length) return;
			// syntax check first (0.8.781, NuCodeTools): errors ask "Save anyway"; Cancel opens the first part with an error at its line
			if (force !== true && window.NuCodeTools && window.NuCodeTools.check) {
				Promise.all(dirty.map(function (k) {
					var it = itemOf(k);
					return window.NuCodeTools.check(W.docs[k].getValue(), it ? it[2] : '').then(function (p) { return {k: k, label: it ? t(it[1]) : k, problems: p || []}; }, function () { return {k: k, label: k, problems: []}; });
				})).then(function (res) {
					window.NuCodeTools.confirmProblems(res, function () { saveCode(true); }, function (first) {
						pick(first.k);
						var p = first.problems.filter(function (x) { return x.sev === 'error'; })[0];
						if (p && W.cm) { W.cm.setCursor({line: p.line - 1, ch: p.col || 0}); W.cm.focus(); }
					});
				});
				return;
			}
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
	// badge of a column (0.8.688): null = none, true = the default look, [rules] = rules of nuBrowseStyleBadge (sbr_json nunext.badge)
	function colBadge(c) { try { var b = (JSON.parse(c.sbr_json || '{}').nunext || {}).badge; return b ? (Array.isArray(b) ? b : true) : null; } catch (e) { return null; } }
	// click action of a column (0.8.872): sbr_json nunext.action {t form|list|proc|report|js, id, rec, open, pass, look, icon, label}
	function colAction(c) { try { var a = (JSON.parse(c.sbr_json || '{}').nunext || {}).action; return a && a.t ? a : null; } catch (e) { return null; } }
	function colRows(edit) {
		return (S.browse || []).map(function (c) {
			var x = {id: c.zzzzsys_browse_id, title: c.sbr_title || '', display: c.sbr_display || '', align: c.sbr_align || 'l', format: c.sbr_format || '',
				width: c.sbr_width === null || c.sbr_width === undefined || c.sbr_width === '' ? 100 : +c.sbr_width, filter: colFilter(c), badge: colBadge(c)};
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
	// "+" of the list design (review H3): first choose the column – the table's columns not shown yet, or an expression – nothing is added
	// (and no draft started) before a choice
	var pickEl = null;
	function closePick() { if (pickEl) { pickEl.remove(); pickEl = null; document.removeEventListener('mousedown', pickOutside, true); } }
	function pickOutside(e) { if (pickEl && !pickEl.contains(e.target)) closePick(); }
	function colPick(btn) {
		closePick();
		columnsList();
		var shown = (S.browse || []).map(function (c) { return String(c.sbr_display || '').trim(); });
		var q = el('input', {type: 'search', 'class': 'form-control form-control-sm mb-1', placeholder: t('search'), 'aria-label': t('search')});
		var list = el('div', {'class': 'nuDsColPickList', role: 'menu'});
		var fill = function () {
			list.innerHTML = '';
			var f = q.value.trim().toLowerCase(), cols = (listCols || []).filter(function (c) { return shown.indexOf(c.name) < 0 && (!f || c.name.toLowerCase().indexOf(f) >= 0); });
			cols.forEach(function (c) {
				list.appendChild(el('button', {type: 'button', 'class': 'dropdown-item d-flex gap-2 align-items-center', role: 'menuitem', 'data-col-name': c.name, onclick: function () { closePick(); colAdd(c.name); }},
					[icon('bi-layout-three-columns'), el('span', {text: c.name}), c.type ? el('small', {'class': 'text-body-secondary ms-auto', text: c.type}) : null]));
			});
			if (!cols.length && listCols && listCols.length) list.appendChild(el('div', {'class': 'small text-body-secondary px-2 py-1', text: t('ds_col_pick_none')}));
			list.appendChild(el('button', {type: 'button', 'class': 'dropdown-item d-flex gap-2 align-items-center border-top mt-1 pt-2', role: 'menuitem', id: 'nuDsColPickExpr', onclick: function () { closePick(); colAdd(''); }}, [icon('bi-braces'), el('span', {text: t('ds_col_pick_expr')})]));
		};
		q.addEventListener('input', fill);
		q.addEventListener('keydown', function (e) { if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); closePick(); btn.focus(); } if (e.key === 'Enter') { var b = list.querySelector('[data-col-name]'); if (b) { e.preventDefault(); b.click(); } } });
		pickEl = el('div', {'class': 'nuObjMenu nuDsColPick', id: 'nuDsColPick'}, [el('div', {'class': 'small fw-semibold px-1 mb-1', text: t('builder_add_column')}), q, list]);
		document.body.appendChild(pickEl);
		var r = btn.getBoundingClientRect();
		pickEl.style.position = 'fixed';
		pickEl.style.top = Math.min(r.bottom + 4, window.innerHeight - 320) + 'px';
		pickEl.style.left = Math.max(8, Math.min(r.left, window.innerWidth - 300)) + 'px';
		fill();
		if (listCols !== null && !listCols.length && S.form.sfo_table) setTimeout(fill, 600);   // the columns are still loading
		q.focus();
		setTimeout(function () { document.addEventListener('mousedown', pickOutside, true); }, 0);
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
			// a div with the role of a button (0.8.946): Chrome starts no drag on a <button>
			var pick = function () { selCol = c.zzzzsys_browse_id; markCols(); renderPanel(); };
			return el('div', {role: 'button', tabindex: '0', 'class': 'nuDsTreeRow', 'data-col': c.zzzzsys_browse_id, draggable: 'true', title: t('ds_col_drag'), onclick: pick,
				onkeydown: function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); pick(); } }}, [
				icon('bi-layout-three-columns nuDsTreeIcon'), el('span', {'class': 'nuDsTreeId', text: plain(c.sbr_title) || '–'}),
				hidden ? el('span', {title: t('ds_col_hidden')}, [icon('bi-eye-slash')]) : null,
				el('span', {'class': 'nuDsTreeType', text: c.sbr_display})]);
		});
		var tree = el('div', {'class': 'nuDsTree', id: 'nuDsColTree'}, rows);
		// drag a row to another place (0.8.946), as the number cells of the Browse list tab
		var dragId = '', mark = function (row, after) {
			tree.querySelectorAll('.nuDsDropBefore, .nuDsDropAfter').forEach(function (x) { x.classList.remove('nuDsDropBefore', 'nuDsDropAfter'); });
			if (row) row.classList.add(after ? 'nuDsDropAfter' : 'nuDsDropBefore');
		};
		tree.addEventListener('dragstart', function (e) {
			var r = e.target.closest && e.target.closest('[data-col]');
			if (!r) return;
			dragId = r.getAttribute('data-col');
			e.dataTransfer.effectAllowed = 'move';
			try { e.dataTransfer.setData('text/plain', dragId); } catch (x) { /* ignore */ }
			r.classList.add('nuDsListDragging');
		});
		tree.addEventListener('dragover', function (e) {
			if (!dragId) return;
			var r = e.target.closest && e.target.closest('[data-col]');
			if (!r) return;
			e.preventDefault();
			var b = r.getBoundingClientRect();
			mark(r, e.clientY > b.top + b.height / 2);
		});
		tree.addEventListener('dragleave', function (e) { if (!tree.contains(e.relatedTarget)) mark(null); });
		tree.addEventListener('dragend', function () { dragId = ''; mark(null); tree.querySelectorAll('.nuDsListDragging').forEach(function (x) { x.classList.remove('nuDsListDragging'); }); });
		tree.addEventListener('drop', function (e) {
			var r = e.target.closest && e.target.closest('[data-col]');
			if (!dragId || !r) return;
			e.preventDefault();
			var b = r.getBoundingClientRect(), after = e.clientY > b.top + b.height / 2, target = r.getAttribute('data-col'), from = dragId;
			dragId = ''; mark(null);
			if (target === from) return;
			var list = colRows(), ids = list.map(function (x) { return x.id; });
			var moving = list.splice(ids.indexOf(from), 1)[0];
			list.splice(list.map(function (x) { return x.id; }).indexOf(target) + (after ? 1 : 0), 0, moving);
			if (list.map(function (x) { return x.id; }).join() === ids.join()) return;
			saveCols(list);                                                    // the panel keeps showing this list
		});
		section('columns', t('builder_sec_columns'), [tree,
			el('div', {'class': 'nuDsField'}, [el('button', {type: 'button', 'class': 'btn btn-sm btn-outline-primary', id: 'nuDsColAdd', onclick: function () { colPick(this); }}, [icon('bi-plus-lg me-1'), t('builder_add_column')])])]);
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
			field(t('builder_col_filter'), 'check', colFilter(c) ? '1' : '0', function (v) { colSave(c, {filter: v === '1'}); }, {key: 'filter'}),
			field(t('ds_col_badge'), 'check', colBadge(c) ? '1' : '0', function (v) { colSave(c, {badge: v === '1' ? (colBadge(c) || true) : null}); }, {key: 'badge', tip: t('ds_col_badge_tip')}),
			colBadge(c) && !colAction(c) ? badgeRulesEditor(c) : null
		].concat(actionEditor(c)));
		foot([el('p', {'class': 'nuDsHint', text: t('ds_hint_list')})]);
	}
	// On click of a column (0.8.872): what a click on the cell does instead of opening the row's edit form
	function actionEditor(c) {
		var a = colAction(c) || {t: ''};
		var put = function (part) {
			var n = Object.assign({}, a, part);
			if (part.t !== undefined && part.t !== a.t) n = {t: part.t, look: a.look || 'link'};     // another kind: its own target
			colSave(c, {action: n.t ? n : null});
		};
		var out = [field(t('ds_col_action'), 'select', a.t || '', function (v) { put({t: v}); }, {key: 'action_t', tip: t('ds_col_action_tip'),
			options: [['', t('ds_act_none')], ['form', t('ds_act_form')], ['list', t('ds_act_list')], ['proc', t('ds_act_proc')], ['report', t('ds_act_report')], ['js', t('ds_act_js')]]})];
		if (!a.t) return out;
		if (a.t === 'form') out.push(field(t('ds_act_target_form'), 'select', a.id || '', function (v) { put({id: v}); }, {key: 'action_id', search: true, options: options(D.forms, ['edit', 'browseedit'])}));
		if (a.t === 'list') out.push(field(t('ds_act_target_list'), 'select', a.id || '', function (v) { put({id: v}); }, {key: 'action_id', search: true, options: options(D.forms, ['browse', 'browseedit'])}));
		if (a.t === 'proc') out.push(field(t('builder_run_procedure'), 'select', a.id || '', function (v) { put({id: v}); }, {key: 'action_id', search: true, options: options(D.procedures)}));
		if (a.t === 'report') out.push(field(t('builder_run_report'), 'select', a.id || '', function (v) { put({id: v}); }, {key: 'action_id', search: true, options: options(D.reports)}));
		if (a.t === 'js') out.push(field(t('ds_act_fn'), 'text', a.id || '', function (v) { put({id: v.trim()}); }, {key: 'action_id', mono: true, ph: 'openReadings', tip: t('ds_act_fn_tip')}));
		if (a.t === 'form') out.push(field(t('ds_act_rec'), 'select', a.rec || 'row', function (v) { put({rec: v}); }, {key: 'action_rec',
			options: [['row', t('ds_act_rec_row')], ['value', t('ds_act_rec_value')], ['new', t('ds_act_rec_new')]]}));
		if (a.t === 'form' || a.t === 'list') out.push(field(t('ds_act_open'), 'select', a.open || '0', function (v) { put({open: v}); }, {key: 'action_open',
			options: [['0', t('ds_act_open_same')], ['2', t('ds_act_open_tab')], ['3', t('ds_act_open_popup')]]}));
		if (a.t !== 'js') out.push(field(t('ds_act_pass'), 'text', a.pass || 'PARENT_ID', function (v) { put({pass: v.trim()}); }, {key: 'action_pass', mono: true, tip: t('ds_act_pass_tip')}));
		out.push(field(t('ds_act_look'), 'select', a.look || 'link', function (v) { put({look: v}); }, {key: 'action_look',
			options: [['link', t('ds_act_look_link')], ['button', t('ds_act_look_button')], ['icon', t('ds_act_look_icon')]]}));
		if (a.look === 'button' || a.look === 'icon') {
			out.push(el('div', {'class': 'nuDsGrid2'}, [
				field(t('ds_act_icon'), 'text', a.icon || '', function (v) { put({icon: v.trim()}); }, {key: 'action_icon', mono: true, ph: 'bi-speedometer2', tip: t('ds_act_icon_tip')}),
				field(t('ds_act_label'), 'text', a.label || '', function (v) { put({label: v}); }, {key: 'action_label', tip: t('ds_act_label_tip')})]));
		}
		if (!a.id) out.push(el('p', {'class': 'nuDsHint text-warning-emphasis', id: 'nuDsActNoTarget', text: t('ds_act_need_target')}));
		return out;
	}
	// rules of a badge column: "when the value [is | contains | starts with | is anything else] [text] → colour"; the first that matches wins
	function badgeRulesEditor(c) {
		var b = colBadge(c), rules = Array.isArray(b) ? b.map(function (r) { return Object.assign({}, r); }) : [];
		var box = el('div', {'class': 'nuDsBadgeRules', id: 'nuDsBadgeRules'});
		var kindOf = function (r) { return r.default ? 'default' : (r.contains !== undefined ? 'contains' : (r.startsWith !== undefined ? 'startsWith' : 'equals')); };
		var save = function () { colSave(c, {badge: rules.length ? rules : true}); };
		function draw() {
			box.innerHTML = '';
			rules.forEach(function (r, i) {
				var k = kindOf(r), val = k === 'default' ? '' : String(r[k] === undefined ? '' : r[k]);
				var sel = el('select', {'class': 'form-select form-select-sm nuDsBrKind', 'aria-label': t('ds_badge_when'), onchange: function () {
					var v = val; ['equals', 'contains', 'startsWith', 'default'].forEach(function (x) { delete r[x]; });
					if (sel.value === 'default') r.default = true; else r[sel.value] = v;
					save();
				}}, [['equals', t('ds_badge_is')], ['contains', t('ds_badge_contains')], ['startsWith', t('ds_badge_starts')], ['default', t('ds_badge_other')]].map(function (o) {
					return el('option', {value: o[0], selected: o[0] === k}, [o[1]]);
				}));
				var txt = el('input', {type: 'text', 'class': 'form-control form-control-sm nuDsBrValue', value: val, 'aria-label': t('ds_badge_value'), disabled: k === 'default',
					onchange: function () { r[kindOf(r)] = txt.value; save(); }});
				var bg = el('input', {type: 'color', 'class': 'form-control form-control-sm form-control-color nuDsBrColor', value: /^#[0-9a-f]{6}$/i.test(r.bg || '') ? r.bg : '#6c757d',
					'aria-label': t('ds_badge_colour'), title: t('ds_badge_colour'), onchange: function () { r.bg = bg.value; save(); }});
				var del = el('button', {type: 'button', 'class': 'btn btn-sm btn-outline-danger nuDsBrDel', title: t('delete'), 'aria-label': t('delete'), onclick: function () { rules.splice(i, 1); save(); }}, [icon('bi-x-lg')]);
				box.appendChild(el('div', {'class': 'nuDsBadgeRule'}, [sel, txt, bg, del]));
			});
			box.appendChild(el('button', {type: 'button', 'class': 'btn btn-sm btn-outline-primary', id: 'nuDsBadgeAdd', onclick: function () { rules.push({equals: '', bg: '#0d6efd'}); save(); }},
				[icon('bi-plus-lg me-1'), t('ds_badge_add')]));
		}
		draw();
		return box;
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
		var oid = th.id || ('nuBrowseTitle' + th.getAttribute('data-nu-col'));
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
		}}, [el('span', {'class': 'fw-semibold me-1', text: plain(c.sbr_title) || c.sbr_display}), el('small', {'class': 'text-body-secondary', text: oid})])]);   // the title first (review H3), the id to copy
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
			'aria-label': t('builder_add_column'), onclick: function (e) { e.stopPropagation(); colPick(this); }}, [icon('bi-plus-lg')])]));
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
		var rows = BROWSE ? [['F2', 'ds_key_rename_col'], ['Esc', 'ds_key_esc'], [C + '+Z', 'ds_key_undo'], [C + '+Y', 'ds_key_redo'], [C + '+S', 'ds_key_save'], ['?', 'ds_key_keys']] : [
			[t('ds_key_click'), 'ds_key_select'], [C + '+A', 'ds_key_all'], ['Esc', 'ds_key_esc'], ['Tab / Shift+Tab', 'ds_key_tab'],
			['← ↑ → ↓', 'ds_key_move'], [C + '+← ↑ → ↓', 'ds_key_move_big'], ['Shift+← ↑ → ↓', 'ds_key_size'],
			['F2', 'ds_key_rename'], [C + '+D', 'ds_key_dup'], [C + '+C / ' + C + '+X / ' + C + '+V', 'ds_key_copy'], [mac ? '⌫' : 'Del', 'ds_key_delete'],
			[C + '+Z', 'ds_key_undo'], [C + '+Y', 'ds_key_redo'], [C + '+S', 'ds_key_save'], [C + '+K', 'ds_key_structure'], ['?', 'ds_key_keys']];
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
		if (mod && !e.altKey && ((e.key === 'y' || e.key === 'Y') || (e.shiftKey && (e.key === 'z' || e.key === 'Z')))) { e.preventDefault(); e.stopPropagation(); if (!bRedo.disabled && !bRedo.hidden) redo(); return; }
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
		else if (kind === 'clone') duplicate([obj]);                   // object menu → Actions → Clone (0.8.927)
	};
	// double-click on an object (review H2): it is selected and its label field in the panel gets the focus (F2 = rename dialogue)
	document.addEventListener('dblclick', function (e) {
		var ov = e.target.closest && e.target.closest('#nuEditForm .nuDesignOverlay');
		if (!ov) return;
		var w = ov.closest('.nuWrap'), id = w ? w.id.replace(/^nuw_/, '') : '';
		if (!id) return;
		e.preventDefault();
		if (selIds.indexOf(id) < 0) post({nuBuilderSelect: id});
		setTimeout(function () {
			var f = panel.querySelector('[data-key="sob_all_label"]');
			if (f) { f.focus(); if (f.select) f.select(); } else { var o = objByFid(id); if (o) renameDialog(o); }
		}, 120);
	}, true);
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
		document.body.appendChild(quick);
		var sheetH = function () { document.documentElement.style.setProperty('--nu-ds-sheet-h', (phone.matches ? panel.offsetHeight : 0) + 'px'); placeQuick(); };
		sheetH();
		if (window.ResizeObserver) new window.ResizeObserver(sheetH).observe(panel);
		// the Arrange panel of nudesigner.js sits below the bar (its height changes when it wraps)
		var barH = function () { document.documentElement.style.setProperty('--nu-ds-bar-h', bar.offsetHeight + 'px'); };
		barH();
		if (window.ResizeObserver) new window.ResizeObserver(barH).observe(bar);
		if (phone.addEventListener) phone.addEventListener('change', function () { renderPanel(); });
		// the icons of the bar and the rail name themselves after 300 ms (review H2) – Bootstrap tooltips instead of the slow native title
		if (window.nuBs && nuBs.ready() && window.matchMedia && window.matchMedia('(hover: hover)').matches) {
			[bar, rail].forEach(function (root) {
				root.querySelectorAll('button[title], a[title]').forEach(function (b) {
					try { nuBs.tooltip(b, {delay: {show: 300, hide: 0}, placement: root === rail ? 'right' : 'bottom', trigger: 'hover'}); } catch (x) { /* keep the title */ }
				});
			});
		}
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
			if (['code', 'history'].indexOf(v0.get('dswork')) >= 0 || (v0.get('dswork') === 'list' && HASLIST)) openWork(v0.get('dswork'));
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
		if (v0.get('dsfly') === 'list' && HASLIST) openWork('list'); else if (v0.get('dsfly')) openFly(['add', 'rules'].indexOf(v0.get('dsfly')) >= 0 ? v0.get('dsfly') : 'structure');
		if (v0.get('dsitem')) W.item = v0.get('dsitem');
		if (['code', 'history'].indexOf(v0.get('dswork')) >= 0 || (v0.get('dswork') === 'changes' && DR) || (v0.get('dswork') === 'list' && HASLIST)) openWork(v0.get('dswork'));
		setTimeout(function () {
			showHidden();
			var v = null;
			try { v = JSON.parse(window.sessionStorage.getItem(KEY) || 'null'); } catch (e) { v = null; }
			if (v && v.tab === '__last' && S.tabs && S.tabs.length) { v.tab = S.tabs[S.tabs.length - 1].zzzzsys_tab_id; try { window.sessionStorage.setItem(KEY, JSON.stringify(v)); } catch (e) { /* ignore */ } }
			restore();
		}, 0);
	}).catch(function (e) { say(e.message, true); });
})();
