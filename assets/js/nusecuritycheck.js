/* Admin tools → Security check: requests package files that must never be served (README.md, core/*.php, install/*.sql, _backup/ …)
   from this browser and shows which ones the web server hands out. A PHP file that answers with an empty page is fine (include guard). */
(function () {
	'use strict';
	var card = document.getElementById('secProbe');
	if (!card) return;
	var urls = [], marks = {};
	try { urls = JSON.parse(card.getAttribute('data-urls') || '[]'); } catch (e) { urls = []; }
	try { marks = JSON.parse(card.getAttribute('data-marks') || '{}'); } catch (e) { marks = {}; }
	var list = card.querySelector('.js-probe-list'), sum = card.querySelector('.js-probe-summary'), help = card.querySelector('.js-probe-help');
	var open = 0, done = 0;

	var row = function (url, state, detail) {
		var li = document.createElement('li');
		li.className = 'list-group-item d-flex gap-3 align-items-center small' + (state === 'open' ? ' sec-open' : '');
		var i = document.createElement('i');
		i.className = 'bi ' + (state === 'ok' ? 'bi-check-circle-fill text-success' : state === 'open' ? 'bi-x-octagon-fill text-danger' : 'bi-question-circle text-secondary');
		var c = document.createElement('a');                    // a link (0.8.502): open the file yourself, e.g. in a private window
		c.className = 'flex-grow-1 text-break font-monospace';
		c.dir = 'ltr';
		c.href = url;
		c.target = '_blank';
		c.rel = 'noopener noreferrer';
		c.title = card.getAttribute('data-link') || '';
		c.textContent = url;
		var s = document.createElement('span');
		s.className = 'text-body-secondary';
		s.textContent = detail;
		li.appendChild(i); li.appendChild(c); li.appendChild(s);
		list.appendChild(li);
	};

	var finish = function () {
		if (done < urls.length) return;
		sum.className = 'badge ' + (open ? 'text-bg-danger' : 'text-bg-success');
		sum.textContent = open ? String(open) : '✓';
		if (help) help.hidden = !open;
	};

	if (!urls.length) { sum.textContent = '–'; return; }
	urls.forEach(function (u) {
		// with the browser's cookies (0.8.503): hosts with a bot protection (InfinityFree …) answer cookie-less requests with their own page
		fetch(u + (u.indexOf('?') < 0 ? '?' : '&') + 'probe=' + Date.now(), { cache: 'no-store', credentials: 'same-origin', redirect: 'manual' })
			.then(function (r) {
				return r.text().then(function (body) {
					// readable = HTTP 200 AND the answer is the file itself: it contains the file's first characters (an empty file: an
					// empty answer). A PHP file that only runs (include guard, no output) is fine; another page with HTTP 200 (the host's
					// error or bot-protection page) is not the file
					var b = body.replace(/\r/g, ''), mark = Object.prototype.hasOwnProperty.call(marks, u) ? marks[u] : null;
					var isFile = mark === null ? b.trim() !== '' : (mark === '' ? b.trim() === '' : b.indexOf(mark) >= 0);
					var served = r.status === 200 && isFile;
					if (served) open++;
					var label = served ? 'data-open' : (r.status !== 200 ? 'data-ok' : (b.trim() === '' ? 'data-empty' : 'data-other'));
					row(u, served ? 'open' : 'ok', card.getAttribute(label) + ' (HTTP ' + r.status + ')');
				});
			})
			.catch(function () { row(u, 'error', card.getAttribute('data-error')); })
			.then(function () { done++; finish(); });
	});
})();

// checks list: the badges Problem / Check / OK filter the list (0.8.693) – a second click on the same badge shows every check again
(function () {
	'use strict';
	var box = document.getElementById('secChecks');
	if (!box) return;
	var btns = box.querySelectorAll('.secFlt'), cur = '';
	function apply() {
		box.querySelectorAll('.list-group > li').forEach(function (li) { li.hidden = cur !== '' && !li.classList.contains('sec-' + cur); });
		btns.forEach(function (b) { var on = b.getAttribute('data-sec-filter') === cur; b.setAttribute('aria-pressed', on ? 'true' : 'false'); b.classList.toggle('secFltOn', on); b.classList.toggle('secFltOff', cur !== '' && !on); });
	}
	btns.forEach(function (b) {
		b.addEventListener('click', function () { var f = b.getAttribute('data-sec-filter'); cur = cur === f ? '' : f; apply(); });
	});
})();

/* Tabs Checks / Settings (review E2): Save in the title bar only while the settings are shown. */
(function () {
	'use strict';
	var tabs = document.getElementById('secTabs'), save = document.getElementById('secSaveBtn');
	if (!tabs || !save) return;
	var sync = function () {
		var on = document.getElementById('secPane-settings');
		save.hidden = !(on && on.classList.contains('active'));
	};
	tabs.addEventListener('shown.bs.tab', sync);
	setTimeout(sync, 0);
})();
