/*
 * nuBuilder Next dashboards (0.8.298): renders every element with data-nu-dashboard="CODE" – on the start page and in HTML objects of
 * forms – and nuNextDashboard('CODE', 'element_id') / nuNextDashboardRefresh(['CODE']) for form code. Data: ?p=dashboardapi (JSON).
 * KPI tiles, ApexCharts charts (loaded on first use; its CSS comes as a file – the strict CSP allows no injected <style>) and tables.
 */
(function () {
	'use strict';
	if (window.nuNextDashboard) return;
	const me = document.currentScript;
	const C = {
		url: me.getAttribute('data-url'), apex: me.getAttribute('data-apex'), apexCss: me.getAttribute('data-apex-css'),
		csrf: me.getAttribute('data-csrf') || (window.nuNext && window.nuNext.csrf) || '', lang: document.documentElement.lang || 'en',
		nonce: me.getAttribute('data-style-nonce') || '',
	};
	const boards = [];

	const el = (tag, cls, text) => {
		const e = document.createElement(tag);
		if (cls) e.className = cls;
		if (text !== undefined && text !== null) e.textContent = String(text);
		return e;
	};
	const icon = (name) => el('i', 'bi ' + name);

	function post(body) {
		return fetch(C.url, {
			method: 'POST', credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-Token': C.csrf },
			body: JSON.stringify(body),
		}).then((r) => r.json().catch(() => ({ ok: false, error: r.status + ' ' + r.statusText })));
	}

	let apexPromise = null;
	function loadApex() {
		if (window.ApexCharts) return Promise.resolve();
		if (apexPromise) return apexPromise;
		if (!document.getElementById('apexcharts-css') && C.apexCss) {
			// ApexCharts injects its CSS unless an element with this id exists – a <link> keeps the CSP strict
			const l = document.createElement('link');
			l.id = 'apexcharts-css'; l.rel = 'stylesheet'; l.href = C.apexCss;
			document.head.appendChild(l);
		}
		apexPromise = new Promise((resolve, reject) => {
			const s = document.createElement('script');
			s.src = C.apex; s.onload = () => resolve(); s.onerror = () => reject(new Error('ApexCharts'));
			document.head.appendChild(s);
		});
		return apexPromise;
	}

	const dark = () => document.documentElement.getAttribute('data-bs-theme') === 'dark';
	const cssVar = (n, d) => (getComputedStyle(document.documentElement).getPropertyValue(n) || '').trim() || d;
	const num = (v) => (v === null || v === '' || isNaN(Number(v)) ? null : Number(v));

	function fmt(v, o) {
		if (v === null || v === undefined || v === '') return '–';
		if (o.format === 'text' || isNaN(Number(v))) return (o.prefix ? o.prefix + ' ' : '') + String(v) + (o.suffix ? ' ' + o.suffix : '');
		const d = o.format === 'integer' ? 0 : o.decimals;
		let s;
		try { s = new Intl.NumberFormat(C.lang, { minimumFractionDigits: d, maximumFractionDigits: d }).format(Number(v)); } catch (e) { s = Number(v).toFixed(d); }
		if (o.format === 'percent') s += ' %';
		return (o.prefix ? o.prefix + ' ' : '') + s + (o.suffix ? ' ' + o.suffix : '');
	}

	// colour of a KPI by its thresholds: good / warn / bad
	function level(v, o) {
		if (v === null || (o.low === '' && o.high === '')) return '';
		const lo = o.low === '' ? null : Number(o.low), hi = o.high === '' ? null : Number(o.high);
		if (o.higher_better) {
			if (hi !== null && v >= hi) return 'good';
			if (lo !== null && v < lo) return 'bad';
		} else {
			if (lo !== null && v <= lo) return 'good';
			if (hi !== null && v > hi) return 'bad';
		}
		return 'warn';
	}

	function values() {
		// field values of the form page (launch form criteria …); the server uses the saved record's values first
		const out = {};
		document.querySelectorAll('#nuEditForm [data-nu-field]').forEach((f) => {
			if (f.getAttribute('data-nu-prefix') || f.closest('.nuSubformRow')) return;
			const n = f.getAttribute('data-nu-field');
			if (!n || out[n] !== undefined) return;
			out[n] = f.type === 'checkbox' ? (f.checked ? '1' : '0') : (f.value === undefined ? '' : String(f.value));
		});
		return out;
	}

	class Board {
		constructor(host, code) {
			this.host = host; this.code = code; this.cards = {}; this.timers = [];
			const N = window.nuNext;
			this.ctx = N && N.form ? { form_id: N.form.id, record_id: N.record_id || '' } : {};
			let saved = null;
			try { saved = JSON.parse(localStorage.getItem('nuDash.' + code) || 'null'); } catch (e) { saved = null; }
			this.range = saved || null;
			host.classList.add('nuDash');
			host.textContent = '';
			this.init();
		}

		init() {
			post(Object.assign({ action: 'meta', code: this.code }, this.ctx)).then((r) => {
				if (!r.ok) { if (r.hide) { this.host.hidden = true; return; } this.host.appendChild(el('div', 'alert alert-warning small mb-0', r.error)); return; }
				this.meta = r; this.msgs = r.msgs || {};
				if (!this.range) this.range = { range: r.range, from: '', to: '' };
				this.build();
				if (r.widgets.some((w) => w.type === 'chart')) loadApex().then(() => this.loadAll(false), () => this.loadAll(false));
				else this.loadAll(false);
			}, (e) => this.host.appendChild(el('div', 'alert alert-warning small mb-0', e.message)));
		}

		build() {
			const r = this.meta, M = this.msgs;
			const head = el('div', 'nuDashHead');
			if (r.title) head.appendChild(el('h2', 'nuDashTitle', r.title));
			const tools = el('div', 'nuDashTools');
			if (r.dates) {
				const sel = el('select', 'form-select form-select-sm nuDashRange');
				sel.setAttribute('aria-label', M.range);
				['today', 'yesterday', '7d', '30d', '90d', 'month', 'last_month', 'quarter', 'year', 'last_year', 'all', 'custom'].forEach((k) => {
					const o = el('option', '', M['r_' + k]); o.value = k; if (k === this.range.range) o.selected = true; sel.appendChild(o);
				});
				const from = el('input', 'form-control form-control-sm nuDashDate'), to = el('input', 'form-control form-control-sm nuDashDate');
				from.type = to.type = 'date'; from.value = this.range.from || ''; to.value = this.range.to || '';
				from.setAttribute('aria-label', M.from); to.setAttribute('aria-label', M.to);
				const custom = () => { from.hidden = to.hidden = sel.value !== 'custom'; };
				custom();
				const apply = () => {
					this.range = { range: sel.value, from: from.value, to: to.value };
					try { localStorage.setItem('nuDash.' + this.code, JSON.stringify(this.range)); } catch (e) { /* private window */ }
					custom();
					if (sel.value !== 'custom' || (from.value && to.value)) this.loadAll(false);
				};
				sel.addEventListener('change', apply); from.addEventListener('change', apply); to.addEventListener('change', apply);
				tools.append(sel, from, to);
			}
			const rb = el('button', 'btn btn-sm nuBtnAct nuDashRefresh');
			rb.type = 'button'; rb.title = M.refresh; rb.setAttribute('aria-label', M.refresh); rb.appendChild(icon('bi-arrow-clockwise'));
			rb.addEventListener('click', () => this.loadAll(true));
			tools.appendChild(rb);
			if (r.edit) {
				const ed = el('a', 'btn btn-sm nuBtnAct'); ed.href = r.edit; ed.target = '_blank'; ed.rel = 'noopener'; ed.title = 'Dashboard';
				ed.appendChild(icon('bi-pencil')); tools.appendChild(ed);
			}
			head.appendChild(tools);
			this.host.appendChild(head);
			const grid = el('div', 'row g-3 nuDashGrid');
			r.widgets.forEach((w) => {
				const col = el('div', 'col-12 col-md-' + (w.opt.width >= 8 ? 12 : 6) + ' col-xl-' + w.opt.width);
				const card = el('div', 'card nuDashCard nuDashCard-' + w.type);
				const ch = el('div', 'nuDashCardHead');
				ch.appendChild(el('span', 'nuDashCardTitle', /#\w+#/.test(w.title) ? '' : w.title));   // hash variables: the server's title comes with the data
				if (w.link) {
					const a = el('a', 'nuDashLink'); a.href = w.link;
					if (w.opt.new_tab) { a.target = '_blank'; a.rel = 'noopener'; } a.title = w.title; a.setAttribute('aria-label', w.title); a.appendChild(icon('bi-box-arrow-up-right'));
					ch.appendChild(a);
				}
				const body = el('div', 'nuDashBody');
				body.appendChild(el('div', 'nuDashLoading placeholder-glow', '')).appendChild(el('span', 'placeholder col-8'));
				card.append(ch, body); col.appendChild(card); grid.appendChild(col);
				this.cards[w.id] = { w: w, body: body, chart: null, titleEl: ch.firstChild };
				if (w.opt.refresh > 0) this.timers.push(setInterval(() => { if (!document.hidden) this.load(w.id, true); }, w.opt.refresh * 1000));
			});
			this.host.appendChild(grid);
		}

		loadAll(force) { Object.keys(this.cards).forEach((id) => this.load(id, force)); }

		load(id, force) {
			const c = this.cards[id];
			if (c.w.wait) { this.message(c, this.msgs.after_save, 'nuDashWait'); return; }
			const body = Object.assign({ action: 'data', code: this.code, widget: id, force: !!force, range: this.range.range, from: this.range.from, to: this.range.to },
				this.ctx, this.ctx.form_id ? { values: values() } : {});
			c.body.classList.add('nuDashBusy');
			post(body).then((r) => {
				c.body.classList.remove('nuDashBusy');
				if (!r.ok) { this.message(c, r.error || this.msgs.error, 'nuDashErr'); return; }
				if (r.wait) { this.message(c, this.msgs.after_save, 'nuDashWait'); return; }
				if (typeof r.title === 'string' && c.titleEl) c.titleEl.textContent = r.title;
				render(c, r.data, this.msgs);
			}, (e) => { c.body.classList.remove('nuDashBusy'); this.message(c, e.message, 'nuDashErr'); });
		}

		message(c, text, cls) {
			if (c.chart) { try { c.chart.destroy(); } catch (e) { /* ignore */ } c.chart = null; }
			c.body.textContent = '';
			c.body.appendChild(el('div', 'nuDashMsg ' + cls, text));
		}
	}

	function render(c, data, M) {
		const w = c.w, o = w.opt;
		if (c.chart) { try { c.chart.destroy(); } catch (e) { /* ignore */ } c.chart = null; }
		c.body.textContent = '';
		const rows = data.rows || [], cols = data.columns || [];
		if (!rows.length) { c.body.appendChild(el('div', 'nuDashMsg', M.no_data)); return; }
		if (w.type === 'kpi') {
			const v = rows[0][0], vn = num(v), prev = cols.length > 1 ? num(rows[0][1]) : null;
			const box = el('div', 'nuDashKpi');
			const val = el('div', 'nuDashKpiValue', fmt(v, o));
			const lv = level(vn, o);
			if (lv) val.classList.add('nuDashKpi-' + lv);
			box.appendChild(val);
			if (prev !== null && vn !== null) {
				const diff = prev === 0 ? null : ((vn - prev) / Math.abs(prev)) * 100;
				const up = vn >= prev, good = o.higher_better ? up : !up;
				const d = el('div', 'nuDashKpiDelta ' + (vn === prev ? '' : (good ? 'nuDashUp' : 'nuDashDown')));
				d.appendChild(icon(vn === prev ? 'bi-dash' : (up ? 'bi-caret-up-fill' : 'bi-caret-down-fill')));
				d.appendChild(document.createTextNode(' ' + (diff === null ? '' : fmt(Math.abs(diff), { format: 'number', decimals: 1, prefix: '', suffix: '' }) + ' %')));
				d.appendChild(el('span', 'nuDashKpiPrev', M.previous + ' ' + fmt(prev, o)));
				box.appendChild(d);
			}
			if (cols.length > 2 && rows[0][2] !== null) box.appendChild(el('div', 'nuDashKpiNote', rows[0][2]));
			c.body.appendChild(box);
			return;
		}
		if (w.type === 'table') {
			const wrap = el('div', 'table-responsive nuDashTable');
			const t = el('table', 'table table-sm nu-table mb-0'), th = el('thead'), tr = el('tr');
			// a column nu_record_id (0.8.682): hidden; with a form link each row opens its record (new tab with the widget's option)
			const rid = cols.findIndex((n) => String(n).toLowerCase() === 'nu_record_id');
			const open = (id, newTab) => {
				const href = w.row_link.replace('__NU_RID__', encodeURIComponent(id));
				if (newTab) window.open(href, '_blank', 'noopener'); else window.location.href = href;
			};
			cols.forEach((n, i) => { if (i !== rid) tr.appendChild(el('th', typeof rows[0][i] === 'number' ? 'text-end' : '', n)); });
			th.appendChild(tr); t.appendChild(th);
			const tb = el('tbody');
			rows.forEach((r) => {
				const row = el('tr');
				// the first SHOWN column stays unformatted (an id or number like 1059 – review D5: the hidden record id column made it the second)
				const first = rid === 0 ? 1 : 0;
				r.forEach((v, i) => {
					if (i === rid) return;
					const td = el('td', typeof v === 'number' ? 'text-end' : '', typeof v === 'number' && i !== first ? fmt(v, o) : (v === null ? '' : v));
					td.setAttribute('data-nu-label', String(cols[i]));          // the column title on phones (cards, review F2)
					row.appendChild(td);
				});
				const id = rid >= 0 && r[rid] !== null ? String(r[rid]) : '';
				if (id !== '' && w.row_link) {
					row.className = 'nuDashRowLink'; row.tabIndex = 0;
					row.title = M.open_record || '';
					row.addEventListener('click', (e) => open(id, o.new_tab || e.ctrlKey || e.metaKey || e.button === 1));
					row.addEventListener('auxclick', (e) => { if (e.button === 1) open(id, true); });
					row.addEventListener('keydown', (e) => { if (e.key === 'Enter') open(id, o.new_tab || e.ctrlKey || e.metaKey); });
				}
				tb.appendChild(row);
			});
			t.appendChild(tb); wrap.appendChild(t); c.body.appendChild(wrap);
			if (data.more) c.body.appendChild(el('div', 'small text-body-secondary mt-1', M.more));
			return;
		}
		if (!window.ApexCharts) { c.body.appendChild(el('div', 'nuDashMsg nuDashErr', 'ApexCharts')); return; }
		const box = el('div', 'nuDashChart');
		c.body.appendChild(box);
		const accent = cssVar('--bs-primary', '#2f6fd6');
		const palette = [accent, '#20c997', '#fd7e14', '#6f42c1', '#e83e8c', '#0dcaf0', '#ffc107', '#6c757d', '#198754', '#dc3545'];
		const kind = o.chart, pieLike = kind === 'pie' || kind === 'donut' || kind === 'radial';
		const yfmt = (v) => fmt(v, o);
		// axis labels short: no text before / after, no decimals from 100 up (the tooltip shows the full value)
		const afmt = (v) => fmt(v, Object.assign({}, o, { prefix: '', suffix: '', decimals: Math.abs(Number(v)) >= 100 ? 0 : o.decimals }));
		const opts = {
			chart: { type: kind === 'barh' ? 'bar' : (kind === 'radial' ? 'radialBar' : kind), height: o.height, stacked: !!o.stacked, background: 'transparent', nonce: C.nonce || undefined,
				fontFamily: 'inherit', toolbar: { show: false }, animations: { enabled: !window.matchMedia('(prefers-reduced-motion: reduce)').matches } },
			theme: { mode: dark() ? 'dark' : 'light' }, colors: palette, legend: { position: 'bottom' },
			dataLabels: { enabled: pieLike && kind !== 'radial' }, tooltip: { y: { formatter: yfmt } },
			noData: { text: M.no_data },
		};
		if (kind === 'barh') opts.plotOptions = { bar: { horizontal: true } };
		if (pieLike) {
			if (cols.length < 2) { opts.series = [num(rows[0][0]) || 0]; opts.labels = [w.title]; }
			else { opts.series = rows.map((r) => num(r[1]) || 0); opts.labels = rows.map((r) => String(r[0] === null ? '' : r[0])); }
			if (kind === 'radial') opts.plotOptions = { radialBar: { dataLabels: { value: { formatter: (v) => fmt(v, Object.assign({}, o, { format: 'percent' })) } } } };
		} else {
			opts.series = cols.slice(1).map((n, i) => ({ name: n, data: rows.map((r) => num(r[i + 1])) }));
			opts.xaxis = { categories: rows.map((r) => (r[0] === null ? '' : String(r[0]))) };
			if (kind === 'barh') opts.xaxis.labels = { formatter: afmt };
			else opts.yaxis = { labels: { formatter: afmt } };
			if (kind === 'line' || kind === 'area') opts.stroke = { curve: 'smooth', width: 2 };
			if (cols.length < 2) opts.series = [{ name: w.title, data: rows.map((r) => num(r[0])) }];
			// small whole numbers (counts): one tick per unit, no repeated labels like 0 1 1 2 2
			const all = [].concat(...opts.series.map((x) => x.data)).filter((v) => v !== null);
			const max = Math.max(0, ...all);
			if (all.length && all.every((v) => Number.isInteger(v)) && max <= 10 && !o.stacked) {
				const axis = kind === 'barh' ? 'xaxis' : 'yaxis';
				opts[axis] = Object.assign(opts[axis] || {}, { tickAmount: Math.max(1, max), min: 0, max: Math.max(1, max) });
			}
		}
		const draw = () => { c.chart = new window.ApexCharts(box, opts); c.chart.render(); };
		if (box.offsetWidth > 0) { draw(); return; }
		// on a hidden tab (width 0): draw when it becomes visible – ApexCharts would draw an empty chart
		const ro = new ResizeObserver(() => { if (box.offsetWidth > 0 && !c.chart) { ro.disconnect(); draw(); } });
		ro.observe(box);
	}

	// dark / light switch: charts follow
	new MutationObserver(() => {
		boards.forEach((b) => Object.values(b.cards).forEach((c) => { if (c.chart) c.chart.updateOptions({ theme: { mode: dark() ? 'dark' : 'light' } }); }));
	}).observe(document.documentElement, { attributes: true, attributeFilter: ['data-bs-theme'] });

	function mount(host, code) {
		if (!host || host.nuDash) return host && host.nuDash;
		host.nuDash = new Board(host, code);
		boards.push(host.nuDash);
		return host.nuDash;
	}

	/** nuNextDashboard('CODE', 'element_id') – shows dashboard CODE inside the element (e.g. an HTML object of a form). */
	window.nuNextDashboard = function (code, elementId) {
		const host = typeof elementId === 'string' ? document.getElementById(elementId) : elementId;
		if (!host) return null;
		const box = host.querySelector('[data-nu-dashboard]') || host.appendChild(el('div'));
		box.setAttribute('data-nu-dashboard', code);
		return mount(box, code);
	};
	/** nuNextDashboardRefresh(['CODE']) – reads the data again (e.g. after a launch form's criteria changed). */
	window.nuNextDashboardRefresh = function (code) {
		boards.forEach((b) => { if (!code || b.code === code) b.loadAll(true); });
	};
	// the renderer of the editor's test run
	window.nuNextDashboardPreview = function (host, widget, data, msgs) {
		host.textContent = '';
		const body = el('div', 'nuDashBody');
		host.appendChild(body);
		const c = { w: widget, body: body, chart: null };
		const go = () => render(c, data, msgs);
		if (widget.type === 'chart') loadApex().then(go, go); else go();
	};

	const start = () => document.querySelectorAll('[data-nu-dashboard]').forEach((h) => mount(h, h.getAttribute('data-nu-dashboard')));
	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
}());
