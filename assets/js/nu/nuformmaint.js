/* global nuBs */
/* Maintenance of forms (0.8.808) – one dialog for the forms list (status menu, "Maintenance…" button), the form page's notice and design mode.
   Data: #nuFmsData[data-url, data-csrf, data-t (texts JSON)]. API: window.nuFormMaintOpen({forms: [{id, label}], pick: true|false, on, text, until, onDone(changed, on)}).
   pick = the forms can be chosen in the dialog (GET ?p=formstatus&all=1). Saves through POST ?p=formstatus (pages/nuformstatus.php). */
(function () {
	'use strict';
	var D = document.getElementById('nuFmsData');
	if (!D) return;
	var T = {};
	try { T = JSON.parse(D.getAttribute('data-t') || '{}'); } catch (e) { T = {}; }
	function t(k) { return T[k] || k; }
	function el(tag, attrs, kids) {
		var e = document.createElement(tag);
		Object.keys(attrs || {}).forEach(function (k) {
			var v = attrs[k];
			if (v === null || v === undefined || v === false) return;
			if (k === 'text') e.textContent = v; else if (k === 'onclick') e.addEventListener('click', v); else e.setAttribute(k, v === true ? '' : v);
		});
		(kids || []).forEach(function (c) { if (c) e.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); });
		return e;
	}
	function api(method, body, query) {
		return fetch(D.getAttribute('data-url') + (query || ''), {method: method, credentials: 'same-origin',
			headers: method === 'POST' ? {'Content-Type': 'application/json', 'X-CSRF-Token': D.getAttribute('data-csrf')} : {},
			body: body ? JSON.stringify(body) : undefined}).then(function (r) { return r.json().catch(function () { return {ok: false}; }); });
	}
	function toast(msg, ok) {
		var box = document.getElementById('nuFmsToast') || document.body.appendChild(el('div', {id: 'nuFmsToast', 'class': 'position-fixed bottom-0 end-0 p-3 nuFormStatusToast'}));
		var a = el('div', {'class': 'alert py-2 px-3 small shadow-sm mb-2 ' + (ok ? 'alert-success' : 'alert-danger'), role: ok ? 'status' : 'alert', text: msg});
		box.appendChild(a);
		setTimeout(function () { a.remove(); }, ok ? 3000 : 7000);
	}
	window.nuFormMaintToast = toast;

	function open(opt) {
		opt = opt || {};
		var old = document.getElementById('nuFmsDlg');
		if (old) old.remove();
		var chosen = {};
		(opt.forms || []).forEach(function (f) { chosen[f.id] = f.label; });
		var listBox = null, flt = null, cnt = null;
		var txt = el('textarea', {'class': 'form-control', id: 'nuFmsText', rows: '3', maxlength: '500', placeholder: t('form_st_maint_text')});
		txt.value = opt.text || '';
		var until = el('input', {type: 'datetime-local', 'class': 'form-control', id: 'nuFmsUntil'});
		until.value = (opt.until || '').replace(' ', 'T');
		var body = [];
		if (opt.pick) {
			flt = el('input', {type: 'search', 'class': 'form-control form-control-sm mb-2', id: 'nuFmsFilter', placeholder: t('search'), 'aria-label': t('search')});
			listBox = el('div', {'class': 'nuFmsList border rounded', id: 'nuFmsList', role: 'group', 'aria-label': t('fms_forms')}, [el('div', {'class': 'p-2 small text-body-secondary', text: '…'})]);
			cnt = el('span', {'class': 'badge rounded-pill text-bg-secondary ms-1', id: 'nuFmsCount', text: '0'});
			body.push(el('label', {'class': 'form-label', 'for': 'nuFmsFilter'}, [t('fms_forms'), cnt]), flt, listBox);
		} else {
			body.push(el('p', {'class': 'fw-semibold mb-2', id: 'nuFmsFormName', text: (opt.forms || []).map(function (f) { return f.label; }).join(', ')}));
		}
		body.push(el('label', {'class': 'form-label mt-3', 'for': 'nuFmsText', text: t('fms_text')}), txt,
			el('label', {'class': 'form-label mt-3', 'for': 'nuFmsUntil', text: t('fms_until')}), until,
			el('div', {'class': 'form-text', text: t('fms_until_hint')}),
			el('div', {'class': 'alert alert-secondary small py-2 mt-3 mb-0', text: t('fms_who')}));
		var bOff = opt.on || opt.pick ? el('button', {type: 'button', 'class': 'btn btn-outline-danger me-auto', id: 'nuFmsOff', text: t('fms_end')}) : null;
		var bOn = el('button', {type: 'button', 'class': 'btn btn-warning', id: 'nuFmsOn', text: opt.on && !opt.pick ? t('save') : t('fms_start')});
		var m = el('div', {'class': 'modal fade', id: 'nuFmsDlg', tabindex: '-1', 'aria-labelledby': 'nuFmsTitle', 'aria-hidden': 'true'}, [
			el('div', {'class': 'modal-dialog modal-dialog-scrollable'}, [el('div', {'class': 'modal-content'}, [
				el('div', {'class': 'modal-header'}, [el('h2', {'class': 'modal-title fs-5', id: 'nuFmsTitle'}, [el('i', {'class': 'bi bi-cone-striped me-2', 'aria-hidden': 'true'}), t('fms_title')]),
					el('button', {type: 'button', 'class': 'btn-close', 'data-bs-dismiss': 'modal', 'aria-label': t('close')})]),
				el('div', {'class': 'modal-body'}, body),
				el('div', {'class': 'modal-footer'}, [bOff, el('button', {type: 'button', 'class': 'btn btn-outline-secondary', 'data-bs-dismiss': 'modal', text: t('cancel')}), bOn])])])]);
		document.body.appendChild(m);
		var inst = nuBs.modal(m);
		m.addEventListener('hidden.bs.modal', function () { m.remove(); });
		function ids() { return Object.keys(chosen); }
		function count() { if (cnt) cnt.textContent = String(ids().length); }
		function send(on) {
			if (!ids().length) { toast(t('fms_none_chosen'), false); return; }
			bOn.disabled = true; if (bOff) bOff.disabled = true;
			api('POST', {forms: ids(), on: on, text: txt.value, until: until.value}).then(function (j) {
				bOn.disabled = false; if (bOff) bOff.disabled = false;
				if (!j.ok) { toast(j.message || t('forms_status_failed'), false); return; }
				inst.hide();
				toast((on ? t('fms_started') : t('fms_ended')).replace('%d', String((j.changed || []).length)), true);
				if (opt.onDone) opt.onDone(j.changed || [], on);
			}, function () { bOn.disabled = false; if (bOff) bOff.disabled = false; toast(t('forms_status_failed'), false); });
		}
		bOn.addEventListener('click', function () { send(true); });
		if (bOff) bOff.addEventListener('click', function () { send(false); });
		if (opt.pick) {
			api('GET', null, (D.getAttribute('data-url').indexOf('?') >= 0 ? '&' : '?') + 'all=1').then(function (j) {
				listBox.textContent = '';
				(j.forms || []).forEach(function (f) {
					var id = 'nuFmsF_' + f.id.replace(/[^\w-]/g, '_');
					var label = f.code + (f.description ? ' – ' + f.description : '');
					var cb = el('input', {type: 'checkbox', 'class': 'form-check-input', id: id, value: f.id});
					if (chosen[f.id] !== undefined || f.status === '2') { cb.checked = true; chosen[f.id] = label; }
					if (f.status === '0' || f.status === '3') cb.disabled = true;     // Draft / Archived: users cannot open them anyway
					cb.addEventListener('change', function () { if (cb.checked) chosen[f.id] = label; else delete chosen[f.id]; count(); });
					var row = el('div', {'class': 'form-check nuFmsRow', 'data-q': label.toLowerCase()}, [cb, el('label', {'class': 'form-check-label', 'for': id}, [label,
						f.status !== '1' ? el('span', {'class': 'badge ms-2 ' + (f.status === '2' ? 'bg-danger-subtle text-danger-emphasis' : 'bg-secondary-subtle text-secondary-emphasis'), text: t('builder_status_' + f.status)}) : null])]);
					listBox.appendChild(row);
				});
				count();
			});
			flt.addEventListener('input', function () {
				var q = flt.value.trim().toLowerCase();
				Array.prototype.forEach.call(listBox.querySelectorAll('.nuFmsRow'), function (r) { r.hidden = q !== '' && r.getAttribute('data-q').indexOf(q) < 0; });
			});
		}
		inst.show();
	}
	window.nuFormMaintOpen = open;

	// the notice on the form page (pages/nuform.php #nuFormMaint): Change… / End maintenance
	var bar = document.getElementById('nuFormMaint');
	if (bar) {
		var form = [{id: bar.getAttribute('data-form'), label: bar.getAttribute('data-label')}];
		var edit = bar.querySelector('.nuFormMaintEdit'), end = bar.querySelector('.nuFormMaintEnd');
		if (edit) edit.addEventListener('click', function () { open({forms: form, on: true, text: bar.getAttribute('data-text'), until: bar.getAttribute('data-until'), onDone: function () { location.reload(); }}); });
		if (end) end.addEventListener('click', function () {
			api('POST', {forms: [form[0].id], on: false}).then(function (j) { if (j.ok) location.reload(); else toast(j.message || t('forms_status_failed'), false); });
		});
	}
	// the forms list's toolbar button (pages/nuforms.php)
	var listBtn = document.getElementById('nuFmsListBtn');
	if (listBtn) listBtn.addEventListener('click', function () { open({pick: true, onDone: function () { location.reload(); }}); });
})();
