// Runs in <head> before rendering to avoid a flash of the wrong theme. Default: light.
(function () {
	var stored = 'light';
	try { stored = localStorage.getItem('nunext-theme') || 'light'; } catch (e) {}
	var dark = stored === 'dark' || (stored === 'auto' && window.matchMedia && matchMedia('(prefers-color-scheme: dark)').matches);
	document.documentElement.setAttribute('data-bs-theme', dark ? 'dark' : 'light');
	document.documentElement.setAttribute('data-nu-theme', stored);
	// tabs that the page's script opens after loading (#backups, the last tab of a procedure …): hidden until then, so the first tab does
	// not flash up ([data-nu-tab-restore] in nuapp.css; nuapp.js shows them on DOMContentLoaded, at the latest after 3 s)
	// not when the server already opened the tab – ?tab=<name> with no or the same #<name> (pager links, redirects), so paging a
	// table in a tab does not blank it for a moment
	var tabQ = (window.location.search.match(/[?&]tab=([^&#]*)/) || [])[1] || '', tabH = window.location.hash.replace(/^#/, '');
	if (!tabQ || (tabH !== '' && tabH !== tabQ)) {
		document.documentElement.classList.add('nuTabsPending');
		setTimeout(function () { document.documentElement.classList.remove('nuTabsPending'); }, 3000);
	}
	// form record pages and launch forms: the tab nuruntime.js restores for the same record shown again (reload, back / forward, after Save,
	// opened from itself) – tabs and panes hidden until it is selected, so the first tab does not flash up (html.nuFormTabPending,
	// removed by nuruntime.js after the restore, at the latest after 3 s)
	try {
		var fq = window.location.search, ff = (fq.match(/[?&]f=([^&#]*)/) || [])[1] || '', fr = (fq.match(/[?&]r=([^&#]*)/) || [])[1] || '';
		var ft = ff && /[?&]p=form(&|$)/.test(fq) ? sessionStorage.getItem('nuNextTab:' + decodeURIComponent(ff)) : null;
		if (ft !== null && ft !== '0') {
			var nv = window.performance && performance.getEntriesByType ? performance.getEntriesByType('navigation')[0] : null, same = /[?&]saved=1(&|$)/.test(fq)
				|| (nv && (nv.type === 'reload' || nv.type === 'back_forward'));
			if (!same && document.referrer) {
				var rf = new URL(document.referrer);
				same = rf.origin === window.location.origin && rf.pathname === window.location.pathname && rf.searchParams.get('p') === 'form'
					&& rf.searchParams.get('f') === decodeURIComponent(ff) && (rf.searchParams.get('r') || '') === decodeURIComponent(fr);
			}
			if (same) {
				document.documentElement.classList.add('nuFormTabPending');
				setTimeout(function () { document.documentElement.classList.remove('nuFormTabPending'); }, 3000);
			}
		}
	} catch (e) {}
	// page width (core/nupagewidth.php): this device's choice (localStorage nunext-page-width) or the installation's default
	// (<html data-nu-pw>, Settings → Look) – html.nuPwSet + --nu-pw (px) / html.nuPwFull before the first paint; nuapp.js calls it again
	window.nuPageWidthApply = function () {
		var de = document.documentElement, v = '';
		try { v = localStorage.getItem('nunext-page-width') || ''; } catch (e) {}
		if (!v) v = de.getAttribute('data-nu-pw') || 'standard';
		var px = v === 'wide' ? 2400 : (/^\d+$/.test(v) ? Math.max(1200, Math.min(3840, +v)) : 0);
		de.classList.toggle('nuPwFull', v === 'full');
		de.classList.toggle('nuPwSet', v === 'full' || px > 0);
		if (px) de.style.setProperty('--nu-pw', px + 'px'); else de.style.removeProperty('--nu-pw');
		return v;
	};
	try { window.nuPageWidthApply(); } catch (e) {}
	// side bar: the icon rail of is gone – forget its remembered state
	try { localStorage.removeItem('nunext-sidebar-collapsed'); } catch (e) {}
	// system lists on phones: cards or table chosen with the ▦ / ☰ button of this list (?p=<route>) – before drawing
	try {
		var lvRoute = (window.location.search.match(/[?&]p=([\w-]+)/) || [])[1] || '';
		var lvPhone = lvRoute ? localStorage.getItem('nunext-lv-phone-' + lvRoute) : null;
		if (lvPhone === 'table' || lvPhone === 'cards') document.documentElement.setAttribute('data-lv-phone', lvPhone);
	} catch (e) {}
	// form lists on phones: rows as cards from the first paint – nuruntime.js phoneStack() sets the same class only after
	// loading, so the table showed for a moment. Not when the user chose the table for this form (nunext-browse-phone-<form>), in the
	// print view, in design mode or in a dialog of a wide window; phoneStack() corrects it later in any other case
	try {
		var q = window.location.search, fm = (q.match(/[?&]f=([^&#]*)/) || [])[1] || '';
		if (/[?&]p=form(&|$)/.test(q) && fm && !/[?&](print|design)=1/.test(q) && window.matchMedia && matchMedia('(max-width: 575.98px)').matches
			&& localStorage.getItem('nunext-browse-phone-' + decodeURIComponent(fm)) !== 'table') {
			var wide = false;
			try { wide = window.top !== window && window.top.innerWidth >= 576; } catch (e2) {}
			if (!wide) document.documentElement.classList.add('nuPhoneCards');
		}
	} catch (e) {}
	// start page notices (usability review G12): the strip of two or more notices is folded on phones until the user opens it
	try {
		var nst = localStorage.getItem('nunext-home-notices');
		if (nst === 'shut' || (nst !== 'open' && window.matchMedia && matchMedia('(max-width: 767.98px)').matches)) document.documentElement.classList.add('nuNoticesShut');
	} catch (e) {}
	// a run button's pivot: the list page behind the pivot stays hidden from the first paint (nupivot.js)
	try { if (/[?&]p=form(&|$)/.test(window.location.search)
		&& /[?&]pvonly=1(&|$)/.test(window.location.search)) { document.documentElement.classList.add('nuPvOnly'); setTimeout(function () { if (!document.querySelector('.nuPvDlg')) document.documentElement.classList.remove('nuPvOnly'); },
			5000); } } catch (e) {}
	// start page: rows Favourites / Recently used folded from the first paint as nuhome.js stripFold() will fold them
	// (saved 'folded', Auto collapse, or Recently used on a phone without a choice) – html.nuHomeFold-<fav|recent>, removed by nuhome.js
	try {
		var qp = (window.location.search.match(/[?&]p=([\w-]+)/) || [])[1] || '';
		if (qp === '' || qp === 'home') ['fav', 'recent'].forEach(function (k) {
			var sv = localStorage.getItem('nunext-home-strip-' + k);
			var fold = localStorage.getItem('nunext-home-strip-auto-' + k) === '1' || (sv ? sv === 'folded'
				: k === 'recent' && window.matchMedia && matchMedia('(max-width: 575.98px)').matches);
			if (fold) document.documentElement.classList.add('nuHomeFold-' + k);
		});
	} catch (e) {}
	// warnings a person closed for good (Global code, config file): hidden from the first paint – they flashed until the page's script
	// hid them. html.nuWarnOff-<scode|cfg>, removed by that script once it has set the hidden attributes
	try {
		[['scode', 'nunext-scode-warn-hidden'], ['cfg', 'nunext-cfg-warn-hidden'], ['insp', 'nunext-insp-hint-hidden']].forEach(function (w) {
			if (localStorage.getItem(w[1]) === '1') document.documentElement.classList.add('nuWarnOff-' + w[0]);
		});
	} catch (e) {}
	// settings centre "Compact": descriptions hidden from the first paint
	try { if (localStorage.getItem('nunext-settings-compact') === '1') document.documentElement.classList.add('nuSetCompact'); } catch (e) {}
	// page forms ($nu->theme()): the choice for the server too
	try { document.cookie = 'nunext_theme=' + encodeURIComponent(stored) + '; path=' + window.location.pathname.replace(/[^/]*$/, '') + '; SameSite=Lax'; } catch (e) {}
})();
