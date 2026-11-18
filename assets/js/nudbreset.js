/* global nuBs */
/*
 * Reset database in steps (0.8.1290): the progress window stays open while the page asks ?p=dbresetstep again and again
 * (NuDbReset::step – about 12 seconds of work each). A failed or cut request is asked again; a step that still runs answers
 * "busy". At the end (done or error) the page is loaded again and shows the result.
 */
(function () {
	'use strict';
	var dlg = document.getElementById('dbrProgress');
	if (!dlg || !dlg.getAttribute('data-nu-dbr-job')) return;
	var job = dlg.getAttribute('data-nu-dbr-job');
	var url = dlg.getAttribute('data-nu-dbr-step');
	var back = dlg.getAttribute('data-nu-dbr-done');
	var lost = dlg.getAttribute('data-nu-dbr-lost') || '';
	var bar = document.getElementById('dbrBar');
	var fill = document.getElementById('dbrBarFill');
	var phase = document.getElementById('dbrPhase');
	var fails = 0;
	var leaving = false;
	nuBs.modal(dlg, { backdrop: 'static', keyboard: false }).show();
	window.addEventListener('beforeunload', function (e) {
		if (leaving) return;
		e.preventDefault();
		e.returnValue = '';
	});

	function show(r) {
		var p = Math.max(0, Math.min(100, parseInt(r.pct, 10) || 0));
		fill.style.width = p + '%';
		bar.setAttribute('aria-valuenow', String(p));
		if (r.text) phase.textContent = r.text;
	}

	function finish() {
		leaving = true;
		window.location.href = back;
	}

	function step() {
		var body = new URLSearchParams();
		body.set('job', job);
		fetch(url, { method: 'POST', body: body, credentials: 'same-origin', cache: 'no-store' })
			.then(function (res) {
				if (!res.ok) throw new Error('HTTP ' + res.status);
				return res.json();
			})
			.then(function (r) {
				fails = 0;
				show(r);
				dlg.setAttribute('data-nu-dbr-phase', r.phase || '');
				if (r.done || r.phase === 'error' || r.phase === 'none') { finish(); return; }
				window.setTimeout(step, r.busy ? 3000 : 50);
			})
			.catch(function () {
				// the host cut the request (time limit, proxy) – the step goes on on the server; ask again
				fails++;
				if (lost) phase.textContent = lost;
				if (fails > 60) { finish(); return; }
				window.setTimeout(step, Math.min(15000, 2000 * fails));
			});
	}
	step();
}());
