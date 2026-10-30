/* nuBuilder Next – jQuery Migrate messages of a form page (0.8.741, NuJquery): loaded after jquery-migrate.js (debug build) while Migrate
   runs with warnings; sends new entries of jQuery.migrateMessages (Migrate 3: migrateWarnings) to ?p=jqwarn (Settings → Code → jQuery lists them per form). */
(function () {
	'use strict';
	var me = document.currentScript;
	var jq = window.jQuery;
	// Migrate 4: jQuery.migrateMessages, Migrate 3: jQuery.migrateWarnings
	var list = function () { return jq.migrateMessages || jq.migrateWarnings || []; };
	if (!me || !jq || !Array.isArray(list())) return;
	var sent = 0;
	function flush() {
		var all = list();
		if (all.length <= sent) return;
		var fd = new FormData();
		fd.append('_csrf', me.getAttribute('data-csrf') || '');
		fd.append('form', me.getAttribute('data-form') || '');
		fd.append('jq', me.getAttribute('data-jq') || '');
		fd.append('msgs', JSON.stringify(all.slice(sent, sent + 50).map(String)));
		sent = Math.min(all.length, sent + 50);
		try {
			if (navigator.sendBeacon) navigator.sendBeacon(me.getAttribute('data-url'), fd);
			else fetch(me.getAttribute('data-url'), {method: 'POST', body: fd, credentials: 'same-origin', keepalive: true});
		} catch (x) { /* only a help */ }
	}
	window.addEventListener('load', function () { setTimeout(flush, 1500); });
	setInterval(flush, 10000);
	window.addEventListener('pagehide', flush);
	document.addEventListener('visibilitychange', function () { if (document.visibilityState === 'hidden') flush(); });
	window.nuNextJqMigrateFlush = flush;
})();
