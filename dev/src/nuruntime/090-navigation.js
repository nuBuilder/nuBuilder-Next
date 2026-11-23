	// =================================================================== navigation
	function formUrl(f, r, extra) {
		var q = $.extend({p: 'form', f: f}, (r !== undefined && r !== null && r !== '') ? {r: r} : {}, extra || {});
		if (N.ps && !q.ps) q.ps = N.ps;
		// the opening form and record (page forms read them with $nu->parent)
		if (N.form && !q.pf) { q.pf = N.form.id; if (N.record_id && N.record_id !== '-1') q.pr = N.record_id; }
		return N.base + '?' + $.param(q);
	}
	N.formUrl = formUrl;

	/**
	 * Opens a form like nuBuilder: r '' = browse, '-1' = new record; filter = always-applied browse search words.
	 * n (target): 0 new breadcrumb (default), 1 current breadcrumb (replaces this one), 2 new tab.
	 */
	api('nuForm', function (f, r, filter, search, n) {
		n = String(n === undefined || n === null || n === '' ? '0' : n);
		var extra = {};
		if (search) extra.q = search;
		if (filter) extra.rf = filter;
		if (N.iframe) extra.iframe = '1';
		else if (n !== '2') { var bt = (N.bt || {})[n === '1' ? 'same' : 'child']; if (bt) extra.bt = bt; }
		if (n === '3') { nuPopup(f, r, filter); return; }
		go(function () { return formUrl(f, r, extra); }, n === '2' ? 'blank' : null);
	});

	/** Opens a form in a dialog (nuBuilder run target "Popup"). */
	api('nuPopup', function (f, r, filter) {
		var m = g('nuPopupModal');
		if (!m) return;
		var dlg = m.querySelector('.modal-dialog'), ttl = m.querySelector('.modal-title'), fr = m.querySelector('iframe');
		if (!m.hasAttribute('data-nu-title')) m.setAttribute('data-nu-title', ttl ? ttl.textContent : '');
		if (dlg) dlg.style.maxWidth = '';
		if (ttl) ttl.textContent = m.getAttribute('data-nu-title');
		// as nuBuilder Forte: the dialog shows the form's title, and a list is as wide as its columns (nuResizeBrowseColumns of
		// a form in a dialog), at most the window
		if (fr && !fr.hasAttribute('data-nu-fit')) {
			fr.setAttribute('data-nu-fit', '1');
			fr.addEventListener('load', function () {
				try {
					var w = fr.contentWindow, d = fr.contentDocument, nn = w.nuNext || {};
					if (ttl && nn.form && nn.form.description) ttl.textContent = String(nn.form.description).replace(/<[^>]*>/g, '');
					var t = d && d.getElementById('nuBrowseTable');
					if (dlg && t && !t.classList.contains('nuBrowseAutoFit')) {
						var sum = 0;
						Array.prototype.forEach.call(t.querySelectorAll('thead > tr > th'), function (th) { if (th.style.display !== 'none') sum += parseFloat(th.style.width) || th.offsetWidth; });
						dlg.style.maxWidth = Math.min(window.innerWidth - 32, Math.max(480, Math.ceil(sum) + 64)) + 'px';
					}
				} catch (x) { /* another origin */ }
			});
		}
		go(function () { return formUrl(f, r, $.extend({iframe: '1'}, filter ? {rf: filter} : {})); }, function (url) { $(m).find('iframe').attr('src', url); });
		nuBs.modal(m).show();
		callHook('nuOnPopupOpenedGlobal', f, r, filter);
	});

	// nuBuilder Forte's ids of an open popup / lookup: the box #nuDragDialog, its iframe #nuWindow and the overlay #nuModal – form code
	// sizes the popup from inside ($('#nuDragDialog', window.parent.document).css('width', …)) or asks parent.$('#nuModal').length.
	// The ids go to the dialog that is open and leave it when it closes (Forte removes its dialog). A width set by such code wins
	// over the width nuBuilder Next worked out (max-width), within the window.
	(function forteDialogIds() {
		var PAIR = ['nuPopupModal', 'nuLookupModal'];
		function clear() {
			['nuDragDialog', 'nuWindow', 'nuModal'].forEach(function (id) { var el = g(id); if (el) el.removeAttribute('id'); });
		}
		document.addEventListener('show.bs.modal', function (e) {
			var m = e.target; if (!m || PAIR.indexOf(m.id) < 0) return;
			clear();
			var dlg = m.querySelector('.modal-dialog'), fr = m.querySelector('iframe');
			if (dlg) {
				dlg.id = 'nuDragDialog';
				if (!dlg.nuWidthWatch && window.MutationObserver) {
					dlg.nuWidthWatch = new MutationObserver(function () {
						var w = dlg.style.width;
						if (dlg.id === 'nuDragDialog' && w && dlg.style.maxWidth !== 'calc(100vw - 1rem)') dlg.style.maxWidth = 'calc(100vw - 1rem)';
					});
					dlg.nuWidthWatch.observe(dlg, {attributes: true, attributeFilter: ['style']});
				}
			}
			if (fr) fr.id = 'nuWindow';
		});
		document.addEventListener('shown.bs.modal', function (e) {
			var m = e.target; if (!m || PAIR.indexOf(m.id) < 0) return;
			var bd = document.querySelectorAll('.modal-backdrop'); if (bd.length && !g('nuModal')) bd[bd.length - 1].id = 'nuModal';
		});
		document.addEventListener('hidden.bs.modal', function (e) {
			var m = e.target; if (!m || PAIR.indexOf(m.id) < 0) return;
			var dlg = m.querySelector('.modal-dialog');
			if (dlg && dlg.id === 'nuDragDialog') { dlg.removeAttribute('id'); dlg.style.width = ''; dlg.style.height = ''; }
			var fr = m.querySelector('iframe'); if (fr && fr.id === 'nuWindow') { fr.removeAttribute('id'); fr.style.width = ''; fr.style.height = ''; }
			var o = g('nuModal'); if (o) o.removeAttribute('id');
		});
	})();

	/** Run objects (buttons): open a form or run a procedure, see NuRender::runInfo(). */
	// the button's own onclick (nuBuilder Forte's nuForm(…) …, written by NuRender::run – form code may have changed it): the leave
	// question first when the form is left with unsaved changes
	api('nuRunButton', function (btn, e, fn) {
		if (!btn || btn.disabled || typeof fn !== 'function') return false;
		var tg = btn.getAttribute('data-nu-run-target') || '0', type = btn.getAttribute('data-nu-run-type') || 'F';
		if (type === 'F' && N.mode === 'edit' && N.edited && tg !== '2' && tg !== '3') {
			if (window.nuLeaveGuard) { window.nuLeaveGuard.ask(fn); return false; }
			if (!window.confirm(M.unsaved || 'Leave without saving?')) return false;
		}
		fn();
		return false;
	});
	$(document).on('click', '.nuRunButton', function (e) {
		if (e.isDefaultPrevented() || this.disabled) return;
		if (this.hasAttribute('data-nu-run-onclick')) return;             // runs through its own onclick (nuRunButton)
		var d = this.dataset;
		if (d.nuRunType === 'P') {
			if (!d.nuRunCode) return;
			if (d.nuRunHidden === '1') nuRunPHPHidden(d.nuRunCode); else nuRunPHP(d.nuRunCode);
			return;
		}
		if (d.nuRunType === 'R') { if (d.nuRunCode) nuRunReport(d.nuRunCode); return; }
		if (d.nuRunType === 'U') {                                // a URL: 2 new tab (default), 3 popup window, 0 / 1 this tab
			var url = d.nuRunUrl;
			if (!url) return;
			if (d.nuRunTarget === '3') { window.open(url, '_blank', 'popup,width=1000,height=700,noopener'); return; }
			if (d.nuRunTarget !== '0' && d.nuRunTarget !== '1') { window.open(url, '_blank', 'noopener'); return; }
			var go = function () { window.location.href = url; };
			if (N.mode === 'edit' && N.edited) {
				if (window.nuLeaveGuard) { window.nuLeaveGuard.ask(go); return; }
				if (!window.confirm(M.unsaved || 'Leave without saving?')) return;
			}
			go();
			return;
		}
		if (!d.nuRunForm) return;
		var open = function () {
			if (d.nuRunTarget === '3') nuPopup(d.nuRunForm, d.nuRunRecord, d.nuRunFilter);
			else nuForm(d.nuRunForm, d.nuRunRecord, d.nuRunFilter, '', d.nuRunTarget);
		};
		// leaving the form with unsaved changes: Save / Discard / Keep editing (assets/js/nuapp.js)
		if (N.mode === 'edit' && N.edited && d.nuRunTarget !== '2' && d.nuRunTarget !== '3') {
			if (window.nuLeaveGuard) { window.nuLeaveGuard.ask(open); return; }
			if (!window.confirm(M.unsaved || 'Leave without saving?')) return;
		}
		open();
	});

	api('nuGetBreadcrumb', function (n) {
		var bc = N.breadcrumbs || [];
		if (n !== undefined && bc[n] && bc[n].url && n < bc.length - 1) {
			var to = new URL(bc[n].url, window.location.href);
			if (N.iframe) to.searchParams.set('iframe', '1');
			window.location.href = to.toString();
			return;
		}
		try {
			if (window.parent !== window && window.parent.nuNext) { $.extend(N.props, window.parent.nuNext.props); propsDirty = true; }
		} catch (e) { /* cross-origin */ }
		go(function () {
			var u = new URL(window.location.href);
			u.searchParams.set('ps', N.ps || '');
			u.searchParams.delete('saved');
			return u.toString();
		});
	});
	api('nuOpenPreviousBreadcrumb', function () {
		var bc = N.breadcrumbs || [];
		for (var i = bc.length - 2; i >= 0; i--) if (bc[i].url) { window.location.href = bc[i].url; return; }
	});
	// the browser tab's title as nuBuilder Forte (nuSetBrowserTabTitle) when a shared setting asks for it: "<prefix> - <title>"
	function tabTitle(t) {
		var tt = N.tab_title;
		if (!tt) return false;
		document.title = tt.show ? (tt.prefix ? tt.prefix + ' - ' : '') + (t || 'Properties') : tt.prefix;
		return true;
	}
	api('nuSetTitle', function (t) { var f = safeFrag(t); if (!tabTitle(f.textContent.trim())) document.title = f.textContent.trim(); $('.nuBreadcrumb').last().empty().append(f); });
	$(function () { if (N.tab_title) tabTitle(($('.nuBreadcrumb').last().text() || '').trim()); });

