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
	// page forms (0.8.387, $nu->theme()): the choice for the server too
	try { document.cookie = 'nunext_theme=' + encodeURIComponent(stored) + '; path=' + window.location.pathname.replace(/[^/]*$/, '') + '; SameSite=Lax'; } catch (e) {}
})();
