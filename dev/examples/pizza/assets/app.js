/* Pizza shop – the browser side. NuPage (from $nu->sdk()) sends the CSRF token and signs out cleanly. */
/* global NuPage, ApexCharts */
(function () {
	'use strict';
	var B = JSON.parse(document.getElementById('pzBoot').textContent);
	var dataEl = document.getElementById('pzData');
	var D = dataEl ? JSON.parse(dataEl.textContent) : {};

	// theme "auto" = follow the system, like nuBuilder Next does
	var html = document.documentElement;
	if (html.dataset.themePref === 'auto' && window.matchMedia('(prefers-color-scheme: dark)').matches) html.setAttribute('data-bs-theme', 'dark');

	function el(tag, cls, text) { var e = document.createElement(tag); if (cls) e.className = cls; if (text != null) e.textContent = text; return e; }
	function money(v) { return B.currency + (B.currency.length > 1 ? ' ' : '') + v.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ','); }
	// order times in the page's language: Nov 24, 6:15 PM (or 24h with "clock": "24h")
	function when(stamp) {
		var d = new Date(stamp.replace(' ', 'T'));
		return d.toLocaleString(html.lang || 'en-US', { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit', hour12: B.clock !== '24h' });
	}
	function toast(text, kind) {
		var t = el('div', 'pz-toast pz-toast-' + (kind || 'ok'), text);
		document.getElementById('pzToasts').appendChild(t);
		setTimeout(function () { t.classList.add('is-out'); }, 3500);
		setTimeout(function () { t.remove(); }, 4000);
	}
	function post(url, fields) {
		var f = new FormData();
		Object.keys(fields).forEach(function (k) { f.append(k, fields[k]); });
		return NuPage.fetch(url, { method: 'POST', body: f }).then(function (r) { return r.json(); });
	}
	function ago(stamp, now) {
		var m = Math.max(0, Math.round((Date.parse(now.replace(' ', 'T')) - Date.parse(stamp.replace(' ', 'T'))) / 60000));
		return m < 1 ? 'just now' : (m < 60 ? m + ' min' : Math.floor(m / 60) + ' h ' + (m % 60) + ' min');
	}
	var STATUS = { new: 'Received', oven: 'In the oven', ready: 'Ready', done: 'Delivered', cancelled: 'Cancelled' };
	function itemsList(o) {
		var ul = el('ul', 'pz-items');
		o.items.forEach(function (i) {
			var li = el('li');
			li.appendChild(el('b', null, i.qty + '× ')); li.appendChild(document.createTextNode(i.name + ' '));
			li.appendChild(el('span', 'pz-size', i.size));
			if (i.extras.length) li.appendChild(el('div', 'pz-ex', '+ ' + i.extras.join(', ')));
			ul.appendChild(li);
		});
		return ul;
	}

	// ------------------------------------------------------------ menu + cart
	if (B.view === 'menu') {
		var KEY = 'pizza-cart';
		var cart = [];
		try { cart = JSON.parse(sessionStorage.getItem(KEY) || '[]'); } catch (e) { cart = []; }
		var cards = {};
		document.querySelectorAll('.pz-card').forEach(function (c) { cards[c.dataset.id] = c; });
		cart = cart.filter(function (l) { return cards[l.id] && !cards[l.id].classList.contains('is-soldout'); });

		function each(l) {
			var c = cards[l.id], p = JSON.parse(c.dataset.prices)[l.size];
			l.extras.forEach(function (e) { p += B.extras[e] || 0; });
			return p;
		}
		function kind() { return document.querySelector('input[name=kind]:checked').value; }
		function draw() {
			try { sessionStorage.setItem(KEY, JSON.stringify(cart)); } catch (e) { /* private window: the cart lives in this page only */ }
			var ul = document.getElementById('pzLines'), sub = 0, n = 0;
			ul.textContent = '';
			cart.forEach(function (l, k) {
				var li = el('li', 'pz-line'), p = each(l) * l.qty;
				sub += p; n += l.qty;
				var name = el('div', 'pz-lname'); name.appendChild(el('b', null, cards[l.id].dataset.name + ' ')); name.appendChild(el('span', 'pz-size', l.size));
				if (l.extras.length) name.appendChild(el('div', 'pz-ex', '+ ' + l.extras.join(', ')));
				var q = el('div', 'pz-qty');
				[['−', -1], [null, 0], ['+', 1]].forEach(function (b) {
					if (b[0] === null) { q.appendChild(el('span', null, String(l.qty))); return; }
					var x = el('button', 'btn btn-sm', b[0]); x.type = 'button'; x.setAttribute('aria-label', b[1] < 0 ? 'One less' : 'One more');
					x.onclick = function () { l.qty += b[1]; if (l.qty < 1) cart.splice(k, 1); else if (l.qty > 20) l.qty = 20; draw(); };
					q.appendChild(x);
				});
				li.appendChild(name); li.appendChild(q); li.appendChild(el('div', 'pz-lprice', money(p)));
				ul.appendChild(li);
			});
			var fee = kind() === 'delivery' && cart.length ? B.fee : 0, total = sub + fee;
			document.getElementById('pzCount').textContent = n;
			document.getElementById('pzEmpty').hidden = cart.length > 0;
			document.getElementById('pzSub').textContent = cart.length ? money(sub) : '–';
			document.getElementById('pzFee').textContent = kind() === 'delivery' ? (cart.length ? money(fee) : '–') : 'free';
			document.getElementById('pzTotal').textContent = cart.length ? money(total) : '–';
			document.getElementById('pzAddrBox').hidden = kind() !== 'delivery';
			var short = kind() === 'delivery' && sub < B.min;
			document.getElementById('pzMin').textContent = short && cart.length ? 'Delivery from ' + money(B.min) + ' – ' + money(B.min - sub) + ' to go.' : '';
			document.getElementById('pzGo').disabled = !cart.length || short || !B.open || B.readOnly;
			document.getElementById('pzCartBtnText').textContent = n + ' · ' + (cart.length ? money(total) : '–');
			document.getElementById('pzCartBtn').hidden = !cart.length;
		}
		document.querySelectorAll('.pz-add').forEach(function (b) {
			b.addEventListener('click', function () {
				var c = b.closest('.pz-card'), size = c.querySelector('.pz-sizes input:checked').value;
				var ex = [].map.call(c.querySelectorAll('.pz-extras input:checked'), function (i) { return i.value; });
				var same = cart.filter(function (l) { return l.id === c.dataset.id && l.size === size && l.extras.join() === ex.join(); })[0];
				if (same) same.qty = Math.min(20, same.qty + 1); else cart.push({ id: c.dataset.id, size: size, extras: ex, qty: 1 });
				c.querySelectorAll('.pz-extras input:checked').forEach(function (i) { i.checked = false; });
				c.querySelector('.pz-extras').open = false;
				b.classList.add('is-added'); setTimeout(function () { b.classList.remove('is-added'); }, 700);
				draw();
			});
		});
		document.querySelectorAll('input[name=kind]').forEach(function (r) { r.addEventListener('change', draw); });
		document.querySelectorAll('input[name=pzf]').forEach(function (r) {
			r.addEventListener('change', function () {
				var f = r.value;
				Object.keys(cards).forEach(function (id) { cards[id].hidden = f !== '' && cards[id].dataset[f] !== '1'; });
			});
		});
		document.getElementById('pzCartBtn').addEventListener('click', function () { document.getElementById('pzCart').scrollIntoView({ behavior: 'smooth' }); });
		document.getElementById('pzOrder').addEventListener('submit', function (e) {
			e.preventDefault();
			var go = document.getElementById('pzGo'), err = document.getElementById('pzErr');
			go.disabled = true; err.textContent = '';
			post(B.urls.order, { cart: JSON.stringify(cart), kind: kind(), address: document.getElementById('pzAddr').value, note: document.getElementById('pzNote').value })
				.then(function (r) {
					if (r.error) { err.textContent = r.message || r.error; go.disabled = false; return; }
					cart = []; draw();
					try { sessionStorage.setItem('pizza-just', String(r.number)); } catch (x) { /* only the highlight is lost */ }
					location.href = B.urls.myOrders;
				}).catch(function () { err.textContent = 'The order could not be sent – please try again.'; go.disabled = false; });
		});
		draw();
	}

	// ------------------------------------------------------------ my orders (polling)
	if (B.view === 'mine') {
		var STEPS = ['new', 'oven', 'ready', 'done'], last = {}, just = '';
		try { just = sessionStorage.getItem('pizza-just') || ''; sessionStorage.removeItem('pizza-just'); } catch (e) { just = ''; }
		var drawMine = function (orders) {
			var box = document.getElementById('pzMine');
			box.textContent = '';
			document.getElementById('pzMineEmpty').hidden = orders.length > 0;
			orders.forEach(function (o) {
				var c = el('article', 'pz-order pz-st-' + o.status + (String(o.number) === just ? ' is-just' : ''));
				var h = el('header');
				h.appendChild(el('h2', null, 'Order #' + o.number));
				h.appendChild(el('span', 'pz-when', when(o.created) + ' · ' + (o.kind === 'delivery' ? 'Delivery' : 'Pickup')));
				h.appendChild(el('b', 'pz-otot', o.total));
				c.appendChild(h);
				if (o.status === 'cancelled') c.appendChild(el('div', 'pz-cancelled', 'Cancelled'));
				else {
					var st = el('ol', 'pz-steps'), at = STEPS.indexOf(o.status);
					STEPS.forEach(function (s, k) {
						var li = el('li', k < at ? 'is-done' : (k === at ? 'is-now' : ''), s === 'done' ? (o.kind === 'delivery' ? 'Delivered' : 'Picked up') : STATUS[s]);
						if (k === at) li.setAttribute('aria-current', 'step');
						st.appendChild(li);
					});
					c.appendChild(st);
				}
				c.appendChild(itemsList(o));
				if (o.status === 'new' && !B.readOnly) {
					var x = el('button', 'btn btn-sm btn-outline-danger', 'Cancel order'); x.type = 'button';
					x.onclick = function () {
						if (x.dataset.sure !== '1') { x.dataset.sure = '1'; x.textContent = 'Really cancel?'; return; }   // two clicks instead of a confirm()
						post(B.urls.cancel, { id: o.id }).then(function (r) { if (!r.ok) toast('Too late – the kitchen has started.', 'warn'); poll(); });
					};
					c.appendChild(x);
				}
				if (last[o.id] && last[o.id] !== o.status && o.status === 'ready') toast('Order #' + o.number + ' is ready!');
				last[o.id] = o.status;
				box.appendChild(c);
			});
		};
		var poll = function () { NuPage.fetch(B.urls.mine + '&passive=1').then(function (r) { return r.json(); }).then(function (d) { drawMine(d.orders); }).catch(function () {}); };
		drawMine(D.orders);
		if (just) toast('Thank you – order #' + just + ' is in the kitchen.');
		setInterval(function () { if (!document.hidden) poll(); }, B.poll);
	}

	// ------------------------------------------------------------ kitchen board (polling)
	if (B.view === 'board') {
		var NEXT = { new: ['oven', 'Start baking', 'bi-fire'], oven: ['ready', 'Ready', 'bi-bell'], ready: ['done', 'Handed over', 'bi-check2'] };
		var seen = null;
		var drawBoard = function (d) {
			var lists = {};
			document.querySelectorAll('.pz-col').forEach(function (c) { lists[c.dataset.status] = c; c.querySelector('[data-list]').textContent = ''; });
			var counts = { new: 0, oven: 0, ready: 0, done: 0 }, fresh = 0;
			d.orders.slice().reverse().forEach(function (o) {
				if (!lists[o.status]) return;
				counts[o.status]++;
				var c = el('article', 'pz-ticket');
				if (seen && !seen[o.id]) { c.classList.add('is-new'); fresh++; }
				var mins = (Date.parse(d.now.replace(' ', 'T')) - Date.parse(o.created.replace(' ', 'T'))) / 60000;
				if (o.status !== 'done' && mins > 20) c.classList.add('is-late');
				var h = el('header');
				h.appendChild(el('b', null, '#' + o.number));
				h.appendChild(el('span', 'pz-kind', o.kind === 'delivery' ? '🛵 Delivery' : '🏪 Pickup'));
				h.appendChild(el('span', 'pz-age', ago(o.created, d.now)));
				c.appendChild(h);
				c.appendChild(el('div', 'pz-cust', o.customer));
				c.appendChild(itemsList(o));
				if (o.note) c.appendChild(el('div', 'pz-note', '“' + o.note + '”'));
				if (o.kind === 'delivery' && o.status === 'ready') c.appendChild(el('div', 'pz-addr', o.address));
				var n = NEXT[o.status];
				if (n && !B.readOnly) {
					var row = el('div', 'pz-acts'), go = el('button', 'btn btn-sm pz-go');
					go.type = 'button'; go.innerHTML = '<i class="bi ' + n[2] + '"></i> '; go.appendChild(document.createTextNode(n[1]));
					go.onclick = function () { move(o, n[0], go); };
					row.appendChild(go);
					if (o.status === 'new') { var x = el('button', 'btn btn-sm btn-outline-danger', 'Cancel'); x.type = 'button'; x.onclick = function () { move(o, 'cancelled', x); }; row.appendChild(x); }
					c.appendChild(row);
				}
				lists[o.status].querySelector('[data-list]').appendChild(c);
			});
			Object.keys(lists).forEach(function (k) { lists[k].querySelector('[data-count]').textContent = counts[k]; });
			if (seen && fresh) toast(fresh === 1 ? 'A new order came in.' : fresh + ' new orders came in.');
			seen = {}; d.orders.forEach(function (o) { seen[o.id] = 1; });
		};
		var load = function () { NuPage.fetch(B.urls.board + '&passive=1').then(function (r) { return r.json(); }).then(drawBoard).catch(function () {}); };
		var move = function (o, to, btn) {
			btn.disabled = true;
			post(B.urls.move, { id: o.id, from: o.status, to: to }).then(function (r) { if (!r.ok) toast(r.error || 'Not possible.', 'warn'); load(); });
		};
		drawBoard(D);
		setInterval(function () { if (!document.hidden) load(); }, B.poll);
	}

	// ------------------------------------------------------------ pizzas (back office)
	if (B.view === 'items') {
		document.querySelectorAll('input[data-flag]').forEach(function (s) {
			s.addEventListener('change', function () {
				var tr = s.closest('tr');
				post(B.urls.flag, { id: tr.dataset.id, flag: s.dataset.flag, on: s.checked ? '1' : '0' }).then(function (r) {
					if (!r.ok) { s.checked = !s.checked; toast('Not saved.', 'warn'); return; }
					if (s.dataset.flag === 'active') tr.classList.toggle('pz-off', !s.checked);
					toast('Saved.');
				});
			});
		});
	}
	if (B.view === 'item') {
		var prev = document.getElementById('pzPrev'), name = document.getElementById('pzName');
		var redraw = function () {
			var t = [].map.call(document.querySelectorAll('input[name="toppings[]"]:checked'), function (i) { return i.value; }).join(',');
			prev.src = B.urls.svg + '&name=' + encodeURIComponent(name.value || 'new') + '&t=' + encodeURIComponent(t);
		};
		document.getElementById('pzItem').addEventListener('change', redraw);
		name.addEventListener('input', function () { clearTimeout(name._t); name._t = setTimeout(redraw, 400); });
	}

	// ------------------------------------------------------------ today (ApexCharts from nuBuilder Next's own copy)
	if (B.view === 'today' && window.ApexCharts) {
		var dark = html.getAttribute('data-bs-theme') === 'dark';
		var base = { chart: { type: 'bar', height: 280, toolbar: { show: false }, background: 'transparent', fontFamily: 'inherit' },
			theme: { mode: dark ? 'dark' : 'light' }, colors: ['#c8432b'], dataLabels: { enabled: false }, grid: { strokeDashArray: 3 },
			plotOptions: { bar: { borderRadius: 4 } } };
		new ApexCharts(document.getElementById('pzChartHours'), Object.assign({}, base,
			{ series: [{ name: 'Orders', data: D.orders }], xaxis: { categories: D.hours } })).render();
		new ApexCharts(document.getElementById('pzChartTop'), Object.assign({}, base,
			{ series: [{ name: 'Pizzas', data: D.qty }], xaxis: { categories: D.top }, plotOptions: { bar: { horizontal: true, borderRadius: 4 } }, colors: ['#e0a63b'] })).render();
	}
})();
