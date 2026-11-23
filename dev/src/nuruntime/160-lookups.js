	// =================================================================== lookups
	function lookupInfo(target) {
		var code = g(target + 'code') || g(target + 'button');
		return code ? {object: code.getAttribute('data-nu-object-id'), form: code.getAttribute('data-nu-lookup-form'), prefix: code.getAttribute('data-nu-prefix') || ''} : null;
	}

	function setLookup(target, res) {
		var info = lookupInfo(target) || {prefix: ''};
		var hidden = g(target);
		if (!hidden) return;
		hidden.value = res.pk || '';
		if (g(target + 'code')) g(target + 'code').value = res.code || '';
		if (g(target + 'description')) g(target + 'description').value = res.description || '';
		markEdited(hidden);
		$(hidden).trigger('change');
		applyResult(res, info.prefix);
		var field = hidden.getAttribute('data-nu-field');
		var key = info.prefix ? info.prefix.replace(/\d{3}$/, '') + '.' + field : field;
		var js = ((N.events || {})[key] || {}).lookup;
		if (res.pk && js) runSnippet(js, {target: hidden}, hidden);
		calculate();
		// nuBuilder: (id, prefix, values) – global hook first; called for every lookup (nuBuilder: only in subform rows)
		callHook('nuOnLookupPopulatedGlobal', target, info.prefix || '', res);
		callHook('nuOnLookupPopulated', target, info.prefix || '', res);
	}

	api('nuGetLookupId', function (pk, target) {
		var info = lookupInfo(target);
		if (!info) return;
		window.nuLOOKUPSTATE[info.object] = 'looking';
		return call('lookupid', {object_id: info.object, pk: pk, values: mainValues()}).then(function (res) {
			window.nuLOOKUPSTATE[info.object] = 'found';
			if (res.ok === false) { nuDisplayError(res); return; }
			setLookup(target, res.count ? res : {pk: '', code: '', description: ''});
		});
	});

	api('nuChooseOneLookupRecord', function (e, data) {
		var target = (e.target && e.target.getAttribute('data-nu-target')) || data.target;
		if (data.count === 1) setLookup(target, data);
		else nuBuildLookup(target, e.target ? e.target.value : '');
	});

	api('nuGetLookupCode', function (e) {
		var t = e.target.getAttribute('data-nu-target');
		if (!e.target.value) {
			window.nuLOOKUPCLEARING = true;
			setLookup(t, {pk: '', code: '', description: ''});
			window.nuLOOKUPCLEARING = false;
			return;
		}
		var last = nuFORM.getCurrent();
		last.call_type = 'getlookupcode';
		last.object_id = e.target.getAttribute('data-nu-object-id');
		last.target = t;
		last.code = e.target.value;
		last.hash = nuHashFromEditForm();
		window.nuLOOKUPSTATE[last.object_id] = 'looking';
		nuAjax(last, function (data) {
			window.nuSERVERRESPONSELU = data;
			if (!nuDisplayError(data)) nuChooseOneLookupRecord(e, data);
		});
	});

	// lookup dialog: titled with the list's name, as wide as its columns and as tall as its rows (at most the window) –
	// the list in the frame calls nuNextLookupFit after it is drawn, swapped in place or when one of its menus opens
	var LK = {target: ''};
	function lookupFit() {
		var m = g('nuLookupModal'), fr = m && m.querySelector('iframe'), dlg = m && m.querySelector('.modal-dialog');
		if (!fr || !dlg) return;
		try {
			var d = fr.contentDocument, w = fr.contentWindow, nn = w.nuNext || {};
			if (!d || !d.body || !nn.lookup) return;
			var ttl = g('nuLookupTitle');
			if (ttl && nn.form && nn.form.description) ttl.textContent = String(nn.form.description).replace(/<[^>]*>/g, '');
			var t = d.getElementById('nuBrowseTable'), sum = 0;
			if (t) Array.prototype.forEach.call(t.querySelectorAll('thead > tr > th'), function (th) { if (th.style.display !== 'none') sum += parseFloat(th.style.width) || th.offsetWidth; });
			var wide = t && !t.classList.contains('nuBrowseAutoFit') ? Math.ceil(sum) + 48 : 760;
			dlg.style.maxWidth = Math.min(window.innerWidth - 24, Math.max(560, wide)) + 'px';
			var main = d.getElementById('main') || d.body, bottom = main.getBoundingClientRect().top + main.scrollHeight + w.scrollY;
			Array.prototype.forEach.call(d.querySelectorAll('.dropdown-menu.show'), function (dm) { bottom = Math.max(bottom, dm.getBoundingClientRect().bottom + w.scrollY + 8); });
			var head = m.querySelector('.modal-header');
			var room = window.innerHeight - 56 - (head ? head.offsetHeight : 48);
			fr.style.height = Math.max(240, Math.min(room, Math.ceil(bottom))) + 'px';
			// the list has moved its buttons, marked the chosen row and the size fits: now it may be seen
			if (d.documentElement.classList.contains('nuLookupReady')) reveal(dlg);
		} catch (x) { /* another origin */ }
	}
	window.nuNextLookupFit = lookupFit;
	// until then the dialog's content stays invisible (class nuLkWait) – no flash of the list at another size or before its code ran
	function reveal(dlg) { window.clearTimeout(LK.wait); dlg.classList.remove('nuLkWait'); }
	window.nuNextLookupCurrent = function () { var h = LK.target && g(LK.target); return h ? String(h.value || '') : ''; };
	window.nuNextLookupClear = function () {
		var t = LK.target;
		nuBs.modal(g('nuLookupModal')).hide();
		if (t && g(t)) { window.nuLOOKUPCLEARING = true; setLookup(t, {pk: '', code: '', description: ''}); window.nuLOOKUPCLEARING = false; }
	};

	api('nuBuildLookup', function (target, search) {
		var info = lookupInfo(target);
		if (!info || !info.form) return;
		var modal = g('nuLookupModal'), fr = modal.querySelector('iframe'), ttl = g('nuLookupTitle');
		LK.target = target;
		if (ttl) { if (!ttl.hasAttribute('data-nu-title')) ttl.setAttribute('data-nu-title', ttl.textContent); ttl.textContent = ttl.getAttribute('data-nu-title'); }
		if (fr && !fr.hasAttribute('data-nu-fit')) {
			fr.setAttribute('data-nu-fit', '1');
			fr.addEventListener('load', lookupFit);
			// a frame that loaded while the dialog was still fading in measured nothing – fit again once it is shown
			modal.addEventListener('shown.bs.modal', function () { lookupFit(); setTimeout(lookupFit, 250); });
		}
		var dlg = modal.querySelector('.modal-dialog');
		if (fr && dlg) {
			fr.setAttribute('src', 'about:blank');                // the list of the last lookup must not show (or be measured) again
			dlg.classList.add('nuLkWait');
			window.clearTimeout(LK.wait);
			LK.wait = window.setTimeout(function () { reveal(dlg); }, 3000);   // a list that never reports: show it anyway
		}
		go(function () { return formUrl(info.form, '', {lookup: target, lo: info.object, q: search || ''}); }, function (url) { $(modal).find('iframe').attr('src', url); });
		nuBs.modal(modal).show();
	});

	window.nuNextLookupChosen = function (target, pk) {
		nuBs.modal(g('nuLookupModal')).hide();
		nuGetLookupId(pk, target);
	};
	window.nuNextCloseLookup = function () {
		['nuLookupModal', 'nuPopupModal'].forEach(function (id) { var m = g(id); if (m && m.classList.contains('show')) nuBs.modal(m).hide(); });
	};
	api('nuClosePopup', function () {
		try { if (window.parent !== window && window.parent.nuNextCloseLookup) window.parent.nuNextCloseLookup(); } catch (e) { /* ignore */ }
	});

	$(document).on('change', '.nuLookupCode', function (e) { window.nuGetLookupCode(e); });
	$(document).on('click', '.nuLookupButton', function () { nuBuildLookup(this.getAttribute('data-nu-target')); });
	$(document).on('keydown', '.nuLookupCode', function (e) { if (e.key === 'ArrowDown' && e.altKey) { e.preventDefault(); nuBuildLookup(this.getAttribute('data-nu-target'), this.value); } });

