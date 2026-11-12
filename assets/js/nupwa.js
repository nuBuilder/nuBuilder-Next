/* Installable app (0.8.1136): registers the service worker (core/nupwa.php) and offers "Install app" in the user menu. */
(function () {
	'use strict';
	var meta = document.querySelector('meta[name="nu-pwa"]');
	if (!meta || !('serviceWorker' in navigator) || !window.isSecureContext) return;
	var on = meta.getAttribute('content') === 'on';
	var sw = meta.getAttribute('data-sw');
	if (on) {
		navigator.serviceWorker.register(sw).catch(function () { /* blocked by the browser: the app works without */ });
	} else {
		// switched off in Branding: a worker of earlier days removes itself (its new script answers "off")
		navigator.serviceWorker.getRegistrations().then(function (rs) { rs.forEach(function (r) { r.unregister(); }); });
		if (window.caches) caches.keys().then(function (ks) { ks.forEach(function (k) { if (k.indexOf('nunext-') === 0) caches.delete(k); }); });
		return;
	}
	var standalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
	if (standalone) document.documentElement.classList.add('nuStandalone');
	var deferred = null;
	function item() { return document.getElementById('nuMenuInstall'); }
	function show(yes) { var li = document.getElementById('nuMenuInstallLi'); if (li) li.hidden = !yes; }
	window.addEventListener('beforeinstallprompt', function (e) { e.preventDefault(); deferred = e; show(true); });
	window.addEventListener('appinstalled', function () { deferred = null; show(false); });
	var ios = /iPad|iPhone|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
	document.addEventListener('DOMContentLoaded', function () {
		var b = item();
		if (!b) return;
		if (ios && !standalone) show(true);              // Safari has no install prompt: the menu entry explains "Add to Home Screen"
		b.addEventListener('click', function () {
			if (deferred) {
				deferred.prompt();
				deferred.userChoice.finally(function () { deferred = null; show(false); });
			} else if (window.nuConfirmAsk) {
				window.nuConfirmAsk(b.getAttribute('data-ios'), function () {}, 'OK', 'btn btn-primary', { title: b.getAttribute('data-title') });
			}
		});
	});
	window.nuPwa = { canInstall: function () { return !!deferred; }, standalone: standalone };
})();
