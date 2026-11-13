/* global nuBs */
/*
 * Calendars (0.8.1165, FullCalendar 6 standard, loaded on first use from data-fc / data-fc-css / data-fc-locales of this script).
 *  - list view: button #nuCalendarOpen[data-nu-cal] on a form list → full-size dialogue with the list's calendar (search / filters apply)
 *  - calendar objects and dashboard widgets: div.nuCalHost[data-nu-cal] → mounted in place
 * data-nu-cal: {f, o?, r?, state?, rows?, view, edit, drag, add, open (form id of the records), sf / ef (start / end field of a new record),
 *   allday, min, max, height}. Events come from ?p=calendar (action events), moving / resizing posts action move; a click on an event opens
 *   its record in a dialogue (iframe), a click / drag on empty days a new record with start and end filled in. Groups: colour + filter chips.
 */
(function () {
	'use strict';
	var me = document.currentScript;
	var A = function (k) { return me ? me.getAttribute(k) || '' : ''; };
	var URL_ = A('data-url'), T = {};
	try { T = JSON.parse(A('data-texts') || '{}'); } catch (e) { T = {}; }
	function t(k) { return T[k] || k; }
	function el(tag, cls, txt) { var e = document.createElement(tag); if (cls) e.className = cls; if (txt !== undefined && txt !== null) e.textContent = txt; return e; }
	function csrf() { return (window.nuNext || {}).csrf || A('data-csrf'); }
	function store(k, v) { try { if (v === undefined) return localStorage.getItem(k); localStorage.setItem(k, v); } catch (e) { /* no storage */ } return null; }
	var VIEWS = {month: 'dayGridMonth', week: 'timeGridWeek', day: 'timeGridDay', list: 'listWeek'};

	// ------------------------------------------------------------------ FullCalendar on first use
	var loading = null;
	function script(src) {
		return new Promise(function (ok, bad) { var s = document.createElement('script'); s.src = src; s.onload = ok; s.onerror = bad; document.head.appendChild(s); });
	}
	function load() {
		if (window.FullCalendar && window.FullCalendar.Calendar) return Promise.resolve();
		if (loading) return loading;
		if (!document.querySelector('link[data-nu-fc]')) {
			var l = document.createElement('link'); l.rel = 'stylesheet'; l.href = A('data-fc-css'); l.setAttribute('data-nu-fc', '1'); document.head.appendChild(l);
		}
		loading = script(A('data-fc')).then(function () { return script(A('data-fc-locales')).catch(function () { /* English then */ }); });
		return loading;
	}

	// ------------------------------------------------------------------ server
	function post(cfg, action, extra) {
		var fd = new FormData();
		fd.append('action', action);
		fd.append('f', cfg.f);
		if (cfg.o) fd.append('o', cfg.o);
		if (cfg.r) fd.append('r', cfg.r);
		var st = cfg.state || {};
		Object.keys(st).forEach(function (k) {
			if (k === 'f' || k === 'p') return;
			var v = st[k];
			if (v && typeof v === 'object') Object.keys(v).forEach(function (i) { fd.append(k + '[' + i + ']', v[i]); });
			else if (v !== null && v !== undefined) fd.append(k, v);
		});
		Object.keys(extra || {}).forEach(function (k) { fd.append(k, extra[k]); });
		return fetch(URL_, {method: 'POST', body: fd, credentials: 'same-origin', headers: {'X-CSRF-Token': csrf(), 'Accept': 'application/json'}})
			.then(function (r) { return r.json(); });
	}

	// ------------------------------------------------------------------ records in a dialogue
	var REC = null;
	function recordDialog(url, onClose) {
		if (!REC) {
			REC = el('div', 'modal fade nuCalRecDlg');
			REC.tabIndex = -1;
			REC.innerHTML = '<div class="modal-dialog modal-xl modal-fullscreen-md-down"><div class="modal-content">'
				+ '<div class="modal-header py-2"><h2 class="modal-title fs-6"></h2><a class="btn btn-sm btn-outline-secondary ms-auto me-2 nuCalRecTab" target="_blank" rel="noopener"></a>'
				+ '<button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>'
				+ '<div class="modal-body p-0"><iframe class="nuCalRecFrame" title=""></iframe></div></div></div>';
			document.body.appendChild(REC);
			REC.querySelector('.btn-close').setAttribute('aria-label', t('close'));
			REC.querySelector('.nuCalRecTab').innerHTML = '<i class="bi bi-box-arrow-up-right" aria-hidden="true"></i>';
			REC.querySelector('.nuCalRecTab').title = t('cal_open');
			REC.querySelector('.nuCalRecTab').setAttribute('aria-label', t('cal_open'));
			REC.addEventListener('hidden.bs.modal', function () {
				REC.querySelector('iframe').src = 'about:blank';
				var f = REC._onClose; REC._onClose = null; if (f) f();
			});
		}
		REC.querySelector('.modal-title').textContent = t('cal_open');
		REC.querySelector('iframe').title = t('cal_open');
		REC.querySelector('iframe').src = url + (url.indexOf('?') < 0 ? '?' : '&') + 'iframe=1';
		REC.querySelector('.nuCalRecTab').href = url;
		REC._onClose = onClose;
		nuBs.modal(REC).show();
	}
	function formUrl(f, r, nv) {
		return 'index.php?p=form&f=' + encodeURIComponent(f) + '&r=' + encodeURIComponent(r) + (nv ? '&nv=' + encodeURIComponent(JSON.stringify(nv)) : '');
	}
	function toast(msg, bad) {
		if (window.nuMessage && bad) { window.nuMessage([msg]); return; }
		var d = el('div', 'nuCalToast' + (bad ? ' nuCalToastBad' : ''), msg);
		d.setAttribute('role', bad ? 'alert' : 'status');
		document.body.appendChild(d);
		setTimeout(function () { d.remove(); }, bad ? 6000 : 2500);
	}

	// ------------------------------------------------------------------ one calendar
	function day(s) { return String(s || '').substring(0, 10); }
	function minusDay(s) { var d = new Date(day(s) + 'T00:00:00'); d.setDate(d.getDate() - 1); return d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2); }
	function local(s) { return String(s || '').substring(0, 19); }   // FullCalendar's ISO string without the offset = local time

	function mount(host, cfg) {
		if (host._nuCal) return Promise.resolve(host._nuCal);
		host.innerHTML = '';
		var bar = el('div', 'nuCalGroups'); bar.hidden = true;
		var box = el('div', 'nuCalBox');
		var note = el('div', 'nuCalNote small text-body-secondary'); note.hidden = true;
		host.appendChild(bar); host.appendChild(box); host.appendChild(note);
		var hidden = {}, cache = null, groups = {};
		var key = 'nunext-cal-view-' + (cfg.key || cfg.f + (cfg.o ? '-' + cfg.o : ''));
		return load().then(function () {
			var FC = window.FullCalendar;
			var lang = (document.documentElement.lang || 'en').toLowerCase();
			var view = store(key) || (window.innerWidth < 576 && (!cfg.view || cfg.view === 'month') ? 'listWeek' : VIEWS[cfg.view] || 'dayGridMonth');   // phones: the list of the week first
			var cal = new FC.Calendar(box, {
				initialView: view,
				locale: lang,
				height: cfg.height ? cfg.height : (host.classList.contains('nuCalFill') ? '100%' : 'auto'),
				headerToolbar: {left: 'prev,next today', center: 'title', right: 'dayGridMonth,timeGridWeek,timeGridDay,listWeek'},
				buttonText: {today: t('cal_today'), month: t('cal_month'), week: t('cal_week'), day: t('cal_day'), list: t('cal_list')},
				nowIndicator: true,
				dayMaxEvents: true,
				slotMinTime: (cfg.min || '00:00') + ':00',
				slotMaxTime: (cfg.max || '24:00') + ':00',
				scrollTime: (!cfg.min || cfg.min === '00:00' ? '07:00' : cfg.min) + ':00',   // the whole day by default, scrolled to the morning
				noEventsText: t('cal_no_events'),
				editable: !!cfg.drag,
				eventDurationEditable: !!cfg.drag && !!cfg.ef,
				selectable: !!cfg.add,
				selectMirror: true,
				eventDisplay: 'block',
				events: function (info, ok, bad) {
					var range = info.startStr + '|' + info.endStr;
					if (cache && cache.range === range && !cache.stale) { ok(filter(cache.events)); return; }
					(cfg.rows ? Promise.resolve({ok: true, events: cfg.rows}) : post(cfg, 'events', {start: local(info.startStr), end: local(info.endStr)})).then(function (d) {
						if (!d || !d.ok) { note.hidden = false; note.textContent = (d && d.error) || t('form_error'); note.classList.add('text-danger'); bad(new Error('nuCal')); return; }
						note.classList.remove('text-danger');
						note.hidden = !d.more; note.textContent = d.more ? t('cal_too_many') : '';
						cache = {range: range, events: d.events || []};
						drawGroups(cache.events);
						ok(filter(cache.events));
					}).catch(function (e) { bad(e); });
				},
				datesSet: function (info) { store(key, info.view.type); },
				eventClick: function (info) {
					info.jsEvent.preventDefault();
					if (!info.event.id) return;
					if (cfg.link) {                                          // dashboard widget: the widget's form link
						var href = cfg.link.replace('__NU_RID__', encodeURIComponent(info.event.id));
						if (cfg.newTab || info.jsEvent.ctrlKey || info.jsEvent.metaKey) window.open(href, '_blank', 'noopener'); else location.href = href;
						return;
					}
					if (!cfg.open) return;
					recordDialog(formUrl(cfg.open, info.event.id), refresh);
				},
				select: function (info) {
					cal.unselect();
					if (!cfg.add) return;
					var nv = {};
					if (cfg.sf) nv[cfg.sf] = info.allDay ? day(info.startStr) : local(info.startStr);
					if (cfg.ef) nv[cfg.ef] = info.allDay ? minusDay(info.endStr) : local(info.endStr);
					recordDialog(formUrl(cfg.open, '-1', nv), refresh);
				},
				eventDrop: function (info) { move(info); },
				eventResize: function (info) { move(info); },
				eventDidMount: function (info) {
					var g = (info.event.extendedProps || {}).group;
					info.el.title = info.event.title + (g ? ' · ' + g : '');
				}
			});
			function filter(list) { return list.filter(function (e) { return !(e.extendedProps && hidden[e.extendedProps.group]); }); }
			function refresh() { if (cache) cache.stale = true; cal.refetchEvents(); }
			function move(info) {
				var ev = info.event, allDay = ev.allDay;
				var start = allDay ? day(ev.startStr) : local(ev.startStr);
				var end = ev.end ? (allDay ? day(ev.endStr) : local(ev.endStr)) : '';
				post(cfg, 'move', {id: ev.id, start: start, end: end, allday: allDay ? '1' : '0'}).then(function (d) {
					if (!d || !d.ok) { info.revert(); toast((d && d.error) || t('form_error'), true); return; }
					toast(t('cal_moved'));
					refresh();
				}).catch(function () { info.revert(); toast(t('form_error'), true); });
			}
			function drawGroups(list) {
				list.forEach(function (e) { var g = e.extendedProps && e.extendedProps.group; if (g) groups[g] = e.backgroundColor || groups[g] || ''; });
				var names = Object.keys(groups).sort();
				bar.hidden = names.length < 2;
				if (bar.hidden) return;
				bar.innerHTML = '';
				bar.appendChild(el('span', 'nuCalGroupsLbl small text-body-secondary', t('cal_group')));
				var all = el('button', 'btn btn-sm nuCalChip nuCalChipAll', t('cal_all_groups'));
				all.type = 'button';
				all.setAttribute('aria-pressed', Object.keys(hidden).length ? 'false' : 'true');
				all.addEventListener('click', function () { hidden = {}; drawGroups([]); cal.refetchEvents(); });
				bar.appendChild(all);
				names.forEach(function (g) {
					var b = el('button', 'btn btn-sm nuCalChip');
					b.type = 'button';
					b.setAttribute('aria-pressed', hidden[g] ? 'false' : 'true');
					var dot = el('span', 'nuCalDot'); dot.setAttribute('aria-hidden', 'true'); if (groups[g]) dot.style.background = groups[g];
					b.appendChild(dot); b.appendChild(document.createTextNode(g));
					b.addEventListener('click', function () {
						if (hidden[g]) delete hidden[g]; else hidden[g] = true;
						drawGroups([]); cal.refetchEvents();
					});
					bar.appendChild(b);
				});
			}
			cal.render();
			host._nuCal = cal;
			host.nuCalRefresh = refresh;
			return cal;
		}).catch(function () { host.textContent = t('form_error'); });
	}

	// ------------------------------------------------------------------ list view: dialogue
	var DLG = null;
	function openList(btn) {
		var cfg;
		try { cfg = JSON.parse(btn.getAttribute('data-nu-cal') || '{}'); } catch (e) { return; }
		if (!DLG) {
			DLG = el('div', 'modal fade nuCalDlg');
			DLG.id = 'nuCalDlg'; DLG.tabIndex = -1; DLG.setAttribute('aria-labelledby', 'nuCalTitle');
			DLG.innerHTML = '<div class="modal-dialog modal-xl modal-fullscreen-lg-down"><div class="modal-content">'
				+ '<div class="modal-header py-2"><h2 class="modal-title fs-5" id="nuCalTitle"></h2>'
				+ '<button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>'
				+ '<div class="modal-body"><div class="nuCalHost nuCalFill" id="nuCalListHost"></div></div></div></div>';
			document.body.appendChild(DLG);
			DLG.querySelector('.btn-close').setAttribute('aria-label', t('close'));
			DLG.addEventListener('shown.bs.modal', function () {
				var h = DLG.querySelector('#nuCalListHost');
				mount(h, DLG._cfg).then(function (cal) { if (cal) cal.updateSize(); });
			});
		}
		DLG.querySelector('#nuCalTitle').textContent = cfg.title || btn.textContent.trim();
		var host = DLG.querySelector('#nuCalListHost');
		if (host._nuCal && JSON.stringify(DLG._cfg) !== JSON.stringify(cfg)) { host._nuCal.destroy(); host._nuCal = null; }
		DLG._cfg = cfg;
		nuBs.modal(DLG).show();
	}

	function mountAll(root) {
		(root || document).querySelectorAll('.nuCalHost[data-nu-cal]').forEach(function (h) {
			var cfg;
			try { cfg = JSON.parse(h.getAttribute('data-nu-cal') || '{}'); } catch (e) { return; }
			if (cfg.r === undefined && window.nuNext && window.nuNext.record_id) cfg.r = window.nuNext.record_id;
			mount(h, cfg);
		});
	}
	document.addEventListener('click', function (e) {
		var b = e.target.closest && e.target.closest('#nuCalendarOpen[data-nu-cal]');
		if (b) { e.preventDefault(); openList(b); }
	});
	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { mountAll(); }); else mountAll();

	window.NuCalendar = {mount: mount, mountAll: mountAll, load: load, refresh: function (host) { if (host && host.nuCalRefresh) host.nuCalRefresh(); }};
})();
