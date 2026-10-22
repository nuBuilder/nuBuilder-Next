// Runs in <head> before rendering to avoid a flash of the wrong theme. Default: light.
(function () {
	var stored = 'light';
	try { stored = localStorage.getItem('nunext-theme') || 'light'; } catch (e) {}
	var dark = stored === 'dark' || (stored === 'auto' && window.matchMedia && matchMedia('(prefers-color-scheme: dark)').matches);
	document.documentElement.setAttribute('data-bs-theme', dark ? 'dark' : 'light');
	document.documentElement.setAttribute('data-nu-theme', stored);
})();
