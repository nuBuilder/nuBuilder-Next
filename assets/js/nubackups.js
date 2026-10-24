/*
 * Update page → Backups (0.8.451): select several backups and delete them at once; the clean-up rule (keep the newest n / delete
 * older than n days) with a live preview. The newest backup and the newest safety backup before a roll back have no checkbox and are
 * left out by the rule – the server checks the same (NuUpdate::protectedBackups).
 */
(function () {
	'use strict';
	function $(s, r) { return (r || document).querySelector(s); }
	function $$(s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); }
	function size(b) {                                   // as NuFile::size
		if (b <= 0) return '0 KB';
		if (b < 1024) return b + ' B';
		var units = [['TB', Math.pow(1024, 4)], ['GB', Math.pow(1024, 3)], ['MB', Math.pow(1024, 2)]];
		for (var i = 0; i < units.length; i++) {
			if (b >= units[i][1]) { var v = b / units[i][1]; return (v >= 100 ? Math.round(v) : Math.round(v * 10) / 10) + ' ' + units[i][0]; }
		}
		return Math.round(b / 1024) + ' KB';
	}
	function fill(tpl, a, b) { return String(tpl).replace('%d', a).replace('%s', b); }

	// ------------------------------------------------------------------ select several
	var bulk = $('#updBkBulk'), del = $('#updBkDelSel'), all = $('#updBkAll');
	if (bulk && del) {
		var boxes = $$('.updBkSel');
		var update = function () {
			var on = boxes.filter(function (c) { return c.checked; });
			var bytes = on.reduce(function (t, c) { return t + (parseInt(c.getAttribute('data-size'), 10) || 0); }, 0);
			del.disabled = on.length === 0;
			$('span', del).textContent = fill(del.getAttribute('data-label'), on.length);
			bulk.setAttribute('data-confirm', fill(bulk.getAttribute('data-confirm-text'), on.length, size(bytes)));
			if (all) {
				all.checked = boxes.length > 0 && on.length === boxes.length;
				all.indeterminate = on.length > 0 && on.length < boxes.length;
				all.disabled = boxes.length === 0;
			}
		};
		boxes.forEach(function (c) { c.addEventListener('change', update); });
		if (all) all.addEventListener('change', function () { boxes.forEach(function (c) { c.checked = all.checked; }); update(); });
		update();
	}

	// ------------------------------------------------------------------ clean-up rule with preview
	var form = $('#updBkCleanForm');
	if (!form) return;
	var list = [];
	try { list = JSON.parse(form.getAttribute('data-backups') || '[]'); } catch (e) { list = []; }
	var now = parseInt(form.getAttribute('data-now'), 10) || Math.floor(Date.now() / 1000);
	var prev = $('#updBkCleanPreview'), run = $('#updBkCleanRun'), flag = $('#updBkCleanRunFlag');
	function plan() {                                    // as NuUpdate::cleanupPlan
		var age = $('#updBkModeAge').checked;
		var n = Math.max(1, parseInt((age ? $('#updBkDays') : $('#updBkKeepN')).value, 10) || 1);
		var cut = now - n * 86400;
		return list.filter(function (b, i) { return !b.p && (age ? (b.t > 0 && b.t < cut) : i >= n); });
	}
	function show() {
		var p = plan(), bytes = p.reduce(function (t, b) { return t + (b.s || 0); }, 0);
		var kept = list.length - p.length;
		if (!p.length) prev.textContent = prev.getAttribute('data-none');
		else {
			var names = p.slice(0, 8).map(function (b) { return b.v; }).join(', ') + (p.length > 8 ? ', …' : '');
			prev.textContent = fill(prev.getAttribute('data-some'), p.length, size(bytes)) + ' ' + names + ' · ' + fill(prev.getAttribute('data-kept'), kept);
		}
		run.disabled = p.length === 0;
		$('span', run).textContent = fill(run.getAttribute('data-label'), p.length);
		form.setAttribute('data-confirm', fill(form.getAttribute('data-confirm-text'), p.length, size(bytes)));
	}
	$$('input', form).forEach(function (i) { i.addEventListener('input', show); i.addEventListener('change', show); });
	// typing in a number field chooses its rule
	$('#updBkKeepN').addEventListener('focus', function () { $('#updBkModeKeep').checked = true; show(); });
	$('#updBkDays').addEventListener('focus', function () { $('#updBkModeAge').checked = true; show(); });
	run.addEventListener('click', function () { flag.value = '1'; });
	$('#updBkCleanSave').addEventListener('click', function () { flag.value = '0'; });
	show();
})();
