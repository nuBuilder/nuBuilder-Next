/* Admin tools → Security check: requests package files that must never be served (README.md, core/*.php, install/*.sql, _backup/ …)
   from this browser and shows which ones the web server hands out. A PHP file that answers with an empty page is fine (include guard). */
(function () {
	'use strict';
	var card = document.getElementById('secProbe');
	if (!card) return;
	var urls = [];
	try { urls = JSON.parse(card.getAttribute('data-urls') || '[]'); } catch (e) { urls = []; }
	var list = card.querySelector('.js-probe-list'), sum = card.querySelector('.js-probe-summary'), help = card.querySelector('.js-probe-help');
	var open = 0, done = 0;

	var row = function (url, state, detail) {
		var li = document.createElement('li');
		li.className = 'list-group-item d-flex gap-3 align-items-center small' + (state === 'open' ? ' sec-open' : '');
		var i = document.createElement('i');
		i.className = 'bi ' + (state === 'ok' ? 'bi-check-circle-fill text-success' : state === 'open' ? 'bi-x-octagon-fill text-danger' : 'bi-question-circle text-secondary');
		var c = document.createElement('code');
		c.className = 'flex-grow-1 text-break';
		c.dir = 'ltr';
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
		fetch(u + (u.indexOf('?') < 0 ? '?' : '&') + 'probe=' + Date.now(), { cache: 'no-store', credentials: 'omit', redirect: 'manual' })
			.then(function (r) {
				return r.text().then(function (body) {
					// served = 200 with content; an empty answer of a PHP file (include guard) or an empty index.html of a protected folder
					// that the server still hands out counts: the folder itself is reachable
					var isPhp = /\.php$/.test(u), served = r.status === 200 && (!isPhp || body.trim() !== '');
					if (served) open++;
					var label = served ? 'data-open' : (r.status === 200 ? 'data-empty' : 'data-ok');
					row(u, served ? 'open' : 'ok', card.getAttribute(label) + ' (HTTP ' + r.status + ')');
				});
			})
			.catch(function () { row(u, 'error', card.getAttribute('data-error')); })
			.then(function () { done++; finish(); });
	});
})();
