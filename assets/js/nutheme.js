// Runs in <head> before rendering to avoid a flash of the wrong theme. Default: light.
(function () {
	var stored = 'light';
	try { stored = localStorage.getItem('nunext-theme') || 'light'; } catch (e) {}
	var dark = stored === 'dark' || (stored === 'auto' && window.matchMedia && matchMedia('(prefers-color-scheme: dark)').matches);
	document.documentElement.setAttribute('data-bs-theme', dark ? 'dark' : 'light');
	document.documentElement.setAttribute('data-nu-theme', stored);
	// tabs that the page's script opens after loading (#backups, the last tab of a procedure …): hidden until then, so the first tab does
	// not flash up (0.8.444, [data-nu-tab-restore] in nuapp.css; nuapp.js shows them on DOMContentLoaded, at the latest after 3 s)
	// 0.8.451: not when the server already opened the tab – ?tab=<name> with no or the same #<name> (pager links, redirects), so paging a
	// table in a tab does not blank it for a moment
	var tabQ = (window.location.search.match(/[?&]tab=([^&#]*)/) || [])[1] || '', tabH = window.location.hash.replace(/^#/, '');
	if (!tabQ || (tabH !== '' && tabH !== tabQ)) {
		document.documentElement.classList.add('nuTabsPending');
		setTimeout(function () { document.documentElement.classList.remove('nuTabsPending'); }, 3000);
	}
	// side bar: the icon rail of 0.8.460–0.8.512 is gone (0.8.513) – forget its remembered state
	try { localStorage.removeItem('nunext-sidebar-collapsed'); } catch (e) {}
	// system lists on phones (0.8.471): cards or table chosen with the ▦ / ☰ button of this list (?p=<route>) – before drawing
	try {
		var lvRoute = (window.location.search.match(/[?&]p=([\w-]+)/) || [])[1] || '';
		var lvPhone = lvRoute ? localStorage.getItem('nunext-lv-phone-' + lvRoute) : null;
		if (lvPhone === 'table' || lvPhone === 'cards') document.documentElement.setAttribute('data-lv-phone', lvPhone);
	} catch (e) {}
	// form lists on phones (0.8.993): rows as cards from the first paint – nuruntime.js phoneStack() sets the same class only after
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
	// start page (0.8.995): rows Favourites / Recently used folded from the first paint as nuhome.js stripFold() will fold them
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
	// page forms (0.8.387, $nu->theme()): the choice for the server too
	try { document.cookie = 'nunext_theme=' + encodeURIComponent(stored) + '; path=' + window.location.pathname.replace(/[^/]*$/, '') + '; SameSite=Lax'; } catch (e) {}
})();
