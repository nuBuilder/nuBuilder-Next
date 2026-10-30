/* Admin tools → Dashboards, widget editor (pages/nudashboards.php): fields by type / source, link targets, Test with preview (0.8.298). */
(function () {
	'use strict';
	const $ = (id) => document.getElementById(id);
	const form = $('dashWForm');
	if (!form) return;
	const type = $('dType');
	const shapes = $('dashShapes');

	function src() { const r = form.querySelector('input[name="source"]:checked'); return r ? r.value : 'sql'; }
	function toggle() {
		form.querySelectorAll('[data-dash-src]').forEach((e) => { e.hidden = e.getAttribute('data-dash-src') !== src(); });
		form.querySelectorAll('[data-dash-type]').forEach((e) => { e.hidden = e.getAttribute('data-dash-type') !== type.value; });
		$('dShape').textContent = shapes.getAttribute('data-' + type.value) || '';
		links();
	}
	// link target suggestions: forms (id) or reports (code)
	function links() {
		const dl = $('dLinkList'), kind = $('dLinkType').value;
		dl.textContent = '';
		let list = [];
		try { list = JSON.parse(dl.getAttribute(kind === 'form' ? 'data-forms' : (kind === 'report' ? 'data-reports' : 'data-none')) || '[]'); } catch (e) { list = []; }
		list.forEach((x) => { const o = document.createElement('option'); o.value = x[0]; o.label = x[1]; dl.appendChild(o); });
		$('dLink').disabled = kind === '';
	}
	type.addEventListener('change', toggle);
	form.querySelectorAll('input[name="source"]').forEach((r) => r.addEventListener('change', toggle));
	$('dLinkType').addEventListener('change', links);
	toggle();

	function fields() {
		const w = { opt: {} };
		new FormData(form).forEach((v, k) => {
			const m = /^opt\[(\w+)\]$/.exec(k);
			if (m) w.opt[m[1]] = v; else if (k !== '_csrf' && k !== 'action') w[k] = v;
		});
		return w;
	}

	$('dashTest').addEventListener('click', () => {
		const card = $('dashTestCard'), err = $('dashTestError'), btn = $('dashTest');
		card.hidden = false; err.hidden = true; btn.disabled = true;
		const script = document.querySelector('script[data-url][data-apex]');
		fetch(script.getAttribute('data-url'), {
			method: 'POST', credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-Token': script.getAttribute('data-csrf') },
			body: JSON.stringify({ action: 'test', w: fields(), range: $('dashTestRange').value, record_id: $('dashTestRecord').value }),
		}).then((r) => r.json()).then((r) => {
			btn.disabled = false;
			const prev = $('dashTestPreview'), tab = $('dashTestTable');
			prev.textContent = ''; tab.textContent = ''; $('dashTestMs').textContent = '';
			if (!r.ok) { err.textContent = r.error || 'Error'; err.hidden = false; return; }
			$('dashTestMs').textContent = r.ms + ' ms · ' + r.data.rows.length + (r.data.more ? '+' : '') + ' rows';
			window.nuNextDashboardPreview(prev, r.widget, r.data, r.msgs);
			const t = document.createElement('table'); t.className = 'table table-sm nu-table mb-0';
			const hr = t.createTHead().insertRow();
			r.data.columns.forEach((c) => { const th = document.createElement('th'); th.textContent = c; hr.appendChild(th); });
			const tb = t.createTBody();
			r.data.rows.slice(0, 50).forEach((row) => { const tr = tb.insertRow(); row.forEach((v) => { tr.insertCell().textContent = v === null ? 'NULL' : String(v); }); });
			tab.appendChild(t);
		}, (e) => { btn.disabled = false; err.textContent = e.message; err.hidden = false; });
	});
}());

/* Dashboard form: Access levels list (0.8.697) – ticked count in the badge, group filter (hidden levels keep their ticks) */
(function () {
	'use strict';
	const cnt = document.getElementById('dAccCount');
	if (cnt) {
		const boxes = document.querySelectorAll('.dashAccessList input[name="access[]"]');
		const count = () => { const n = [...boxes].filter((b) => b.checked).length, all = cnt.getAttribute('data-total');
			cnt.textContent = n + ' / ' + all; cnt.title = (cnt.getAttribute('data-fmt') || '').replace('%d', n).replace('%d', all); };
		boxes.forEach((b) => b.addEventListener('change', count));
	}
	const sel = document.getElementById('dAccGroup');
	if (!sel) return;
	const apply = () => document.querySelectorAll('.dashAccessList [data-group]').forEach((e) => {
		e.hidden = sel.value !== '*' && e.getAttribute('data-group') !== sel.value;
	});
	sel.addEventListener('change', apply);
	apply();
})();
