/* nuBuilder Next – report designer (pages/nureportdesigner.php)
 * Edits nuBuilder's report layout (zzzzsys_report.sre_layout) – the same JSON nuBuilder's designer writes:
 *   groups[0] detail · [1] report header / footer · [2] page header / footer · [3–7] sort groups (header / footer)
 *   objects: objectType field | label | image, fieldName, left / top / width / height in px (4 px = 1 mm), font, colors, format …
 * Canvas with bands, drag & drop, resize, multi-select, align tools, undo / redo, properties, groups, PDF preview.
 */
(function () {
	'use strict';
	var dataEl = document.getElementById('rdData');
	var app = document.getElementById('rdApp');
	if (!dataEl || !app) return;
	var D = JSON.parse(dataEl.textContent);
	var M = D.msg || {};
	var L = D.layout;
	var U = 4;                          // layout units (px) per mm
	var PT = 0.352778 * U;              // layout units per point
	var GROUPS = [3, 4, 5, 6, 7];
	var KEEP = 'KEEP EXACT HEIGHT';
	var S = {zoom: 0, grid: true, snap: true, step: 4, sel: [], band: null, dirty: false, undo: [], redo: [], fields: null, fieldsError: '', tab: 'props', clip: null};
	try { var saved = JSON.parse(localStorage.getItem('nuRdView') || '{}'); if (saved.zoom) S.zoom = saved.zoom; if (saved.grid === false) S.grid = false; if (saved.snap === false) S.snap = false; } catch (x) { /* ignore */ }
	var ro = !D.canSave;

	// ================================================================= helpers
	function el(tag, attrs, kids) {
		var e = document.createElement(tag);
		Object.keys(attrs || {}).forEach(function (k) {
			var v = attrs[k];
			if (v === null || v === undefined || v === false) return;
			if (k === 'text') e.textContent = v;
			else if (k === 'class') e.className = v;
			else if (k.indexOf('on') === 0 && typeof v === 'function') e.addEventListener(k.slice(2), v);
			else e.setAttribute(k, v === true ? '' : v);
		});
		(kids || []).forEach(function (c) { if (c !== null && c !== undefined && c !== false) e.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); });
		return e;
	}
	function icon(name) { return el('i', {'class': 'bi bi-' + name}); }
	function btn(iconName, title, fn, extra) {
		var b = el('button', {type: 'button', 'class': 'btn btn-sm ' + ((extra && extra.cls) || 'btn-outline-secondary'), title: title, 'aria-label': title}, [icon(iconName)]);
		if (extra && extra.text) { b.appendChild(document.createTextNode(' ' + extra.text)); }
		b.addEventListener('click', fn);
		return b;
	}
	function num(v, d) { var n = parseFloat(v); return isFinite(n) ? n : (d || 0); }
	function mm(units) { return Math.round(num(units) / U * 100) / 100; }
	function snapV(v) { return S.snap ? Math.round(v / S.step) * S.step : Math.round(v); }
	function clone(o) { return JSON.parse(JSON.stringify(o)); }
	var colorCtx = document.createElement('canvas').getContext('2d');
	function hex(c) {
		if (!c) return '#ffffff';
		colorCtx.fillStyle = '#000000';
		colorCtx.fillStyle = String(c);
		var v = colorCtx.fillStyle;
		return /^#[0-9a-f]{6}$/i.test(v) ? v : '#000000';
	}
	function cssFont(f) {
		var x = String(f || '').toLowerCase();
		if (x.indexOf('courier') === 0 || x.indexOf('mono') >= 0) return '"Courier New", Courier, monospace';
		if (x.indexOf('times') === 0 || x.indexOf('serif') >= 0 && x.indexOf('sans') < 0) return '"Times New Roman", Times, serif';
		if (x.indexOf('dejavu') === 0) return '"DejaVu Sans", Verdana, sans-serif';
		return 'Helvetica, Arial, sans-serif';
	}

	// ================================================================= model
	function sec(g, s) { return L.groups[g].sections[s]; }
	function usedGroups() { return GROUPS.filter(function (g) { return String(L.groups[g].sortField || '') !== ''; }); }
	/** Bands top to bottom, as they print. */
	function bands() {
		var used = usedGroups();
		var out = [{g: 1, s: 0, label: M.b_report_header}, {g: 2, s: 0, label: M.b_page_header}];
		used.forEach(function (g) { out.push({g: g, s: 0, label: (M.b_group_header || '%s').replace('%s', L.groups[g].sortField)}); });
		out.push({g: 0, s: 0, label: M.b_detail});
		used.slice().reverse().forEach(function (g) { out.push({g: g, s: 1, label: (M.b_group_footer || '%s').replace('%s', L.groups[g].sortField)}); });
		out.push({g: 2, s: 1, label: M.b_page_footer}, {g: 1, s: 1, label: M.b_report_footer});
		return out;
	}
	function eachObject(fn) {
		L.groups.forEach(function (G, g) { (G.sections || []).forEach(function (Sec, s) { (Sec.objects || []).forEach(function (o, i) { fn(o, g, s, i); }); }); });
	}
	function find(id) {
		var r = null;
		eachObject(function (o, g, s, i) { if (o.id === id) r = {o: o, g: g, s: s, i: i}; });
		return r;
	}
	function selected() { return S.sel.map(find).filter(Boolean); }
	function newId() {
		var max = 1000;
		eachObject(function (o) { var m = /(\d+)$/.exec(String(o.id || '')); if (m) max = Math.max(max, parseInt(m[1], 10)); });
		return 'object' + (max + 1);
	}
	function newObject(type, props) {
		var o = {id: '', objectType: type, backgroundColor: '#FFFFFF', borderColor: '#000000', borderWidth: '0', fieldName: '', fontColor: '#000000',
			fontFamily: 'Helvetica', fontSize: '10', fontWeight: '', format: '', textAlign: 'left', left: 40, top: 4, height: 20, width: 160, zIndex: 1, minRows: 0, maxRows: 0};
		Object.keys(props || {}).forEach(function (k) { o[k] = props[k]; });
		o.id = newId();
		o.name = o.id;
		return o;
	}
	function isBox(o) { return o.objectType === 'label' && String(o.fieldName).replace(/\s+/g, ' ') === KEEP; }

	// ----------------------------------------------------------------- undo
	function snapshot() { return JSON.stringify(L); }
	function pushUndo(snap) {
		S.undo.push(snap || snapshot());
		if (S.undo.length > 150) S.undo.shift();
		S.redo = [];
		setDirty(true);
	}
	function change(fn) { var snap = snapshot(); fn(); if (snapshot() !== snap) pushUndo(snap); renderCanvas(); renderProps(); }
	function undo() {
		if (!S.undo.length) return;
		S.redo.push(snapshot());
		L = JSON.parse(S.undo.pop());
		S.sel = S.sel.filter(find);
		setDirty(true);
		renderAll();
	}
	function redo() {
		if (!S.redo.length) return;
		S.undo.push(snapshot());
		L = JSON.parse(S.redo.pop());
		S.sel = S.sel.filter(find);
		setDirty(true);
		renderAll();
	}
	function setDirty(v) {
		S.dirty = v;
		if (ui.dirty) ui.dirty.hidden = !v;
		if (ui.save) ui.save.classList.toggle('nuSaveEdited', v);
		if (ui.undo) ui.undo.disabled = !S.undo.length;
		if (ui.redo) ui.redo.disabled = !S.redo.length;
	}

	// ================================================================= UI skeleton
	var ui = {};
	function buildUI() {
		app.innerHTML = '';
		app.removeAttribute('aria-busy');
		app.classList.toggle('rdReadonly', ro);
		ui.dirty = el('span', {'class': 'badge text-bg-warning rdDirty', text: M.unsaved, hidden: true});
		ui.save = btn('floppy', M.save + ' (Ctrl+S)', save, {cls: 'nuBtnAct nuBtnSave', text: M.save});
		ui.undo = btn('arrow-counterclockwise', M.undo + ' (Ctrl+Z)', undo);
		ui.redo = btn('arrow-clockwise', M.redo + ' (Ctrl+Y)', redo);
		ui.zoomLbl = el('span', {'class': 'rdZoomLbl small', text: Math.round(S.zoom * 100) + '%'});
		ui.align = el('div', {'class': 'btn-group btn-group-sm rdAlign', role: 'group', 'aria-label': M.align});
		[['align-start', 'align_left', 'left'], ['align-center', 'align_center', 'hcenter'], ['align-end', 'align_right', 'right'], ['align-top', 'align_top', 'top'],
			['align-middle', 'align_middle', 'vcenter'], ['align-bottom', 'align_bottom', 'bottom'], ['arrows', 'same_width', 'width'], ['arrows-vertical', 'same_height', 'height'],
			['distribute-horizontal', 'dist_h', 'disth'], ['distribute-vertical', 'dist_v', 'distv']].forEach(function (a) {
			var b = btn(a[0], M[a[1]], function () { alignSel(a[2]); });
			b.setAttribute('data-align', a[2]);
			ui.align.appendChild(b);
		});
		ui.gridBtn = btn('grid-3x3', M.grid, function () { S.grid = !S.grid; view(); renderCanvas(); });
		ui.snapBtn = btn('magnet', M.snap, function () { S.snap = !S.snap; view(); });
		var addGroup = el('div', {'class': 'btn-group btn-group-sm', role: 'group'}, [
			btn('input-cursor-text', M.add_field, function () { addObject('field'); }),
			btn('fonts', M.add_label, function () { addObject('label'); }),
			btn('square-fill', M.add_box, function () { addObject('box'); }),
			btn('dash-lg', M.add_line, function () { addObject('line'); }),
			btn('image', M.add_image, function () { addObject('image'); })
		]);
		var bar = el('div', {'class': 'rdToolbar'}, [
			el('a', {'class': 'btn btn-sm btn-outline-secondary', href: D.back, title: M.back}, [icon('arrow-left'), ' ' + M.back]),
			ui.dirty,
			ro ? el('span', {'class': 'badge text-bg-secondary', text: M.readonly}) : ui.save,
			el('div', {'class': 'btn-group btn-group-sm'}, [ui.undo, ui.redo]),
			ro ? null : addGroup,
			ui.align,
			el('div', {'class': 'btn-group btn-group-sm'}, [btn('copy', M.duplicate + ' (Ctrl+D)', duplicateSel), btn('front', M.bring_front, function () { zSel(1); }), btn('back', M.send_back, function () { zSel(-1); }), btn('trash', M.delete + ' (Del)', deleteSel, {cls: 'btn-outline-danger'})]),
			el('div', {'class': 'btn-group btn-group-sm align-items-center rdZoom'}, [btn('zoom-out', M.zoom + ' −', function () { zoom(-0.25); }), ui.zoomLbl, btn('zoom-in', M.zoom + ' +', function () { zoom(0.25); })]),
			el('div', {'class': 'btn-group btn-group-sm'}, [ui.gridBtn, ui.snapBtn]),
			btn('file-earmark-pdf', M.preview, preview, {cls: 'btn-outline-primary', text: M.preview}),
			btn('question-circle', M.help, function () { help(); })
		]);
		ui.left = el('aside', {'class': 'rdLeft', 'aria-label': M.fields});
		ui.ruler = el('div', {'class': 'rdRuler', 'aria-hidden': 'true'});
		ui.page = el('div', {'class': 'rdPage'});
		ui.canvas = el('div', {'class': 'rdCanvas', tabindex: '-1'}, [el('div', {'class': 'rdPageWrap'}, [ui.ruler, ui.page])]);
		ui.right = el('aside', {'class': 'rdRight', 'aria-label': M.props});
		app.appendChild(bar);
		// title in its own row below the toolbar (0.8.233): code – description · table / SQL source
		app.appendChild(el('div', {'class': 'rdTitle'}, [el('span', {'class': 'fw-semibold', text: D.code + (D.description ? ' – ' + D.description : '')}),
			D.source ? el('span', {'class': 'small text-body-secondary', text: D.source}) : null]));
		app.appendChild(el('div', {'class': 'rdMain'}, [ui.left, ui.canvas, ui.right]));
		view();
	}
	function view() {
		ui.gridBtn.classList.toggle('active', S.grid);
		ui.snapBtn.classList.toggle('active', S.snap);
		ui.zoomLbl.textContent = Math.round(S.zoom * 100) + '%';
		try { localStorage.setItem('nuRdView', JSON.stringify({zoom: S.zoom, grid: S.grid, snap: S.snap})); } catch (x) { /* ignore */ }
	}
	function zoom(d) {
		S.zoom = Math.min(3, Math.max(0.5, Math.round((S.zoom + d) * 100) / 100));
		view();
		renderCanvas();
	}

	// ================================================================= left panel: fields and inserts
	function renderLeft() {
		var p = ui.left;
		p.innerHTML = '';
		var search = el('input', {type: 'search', 'class': 'form-control form-control-sm mb-2', placeholder: M.search, 'aria-label': M.search});
		var list = el('div', {'class': 'rdFieldList'});
		p.appendChild(el('div', {'class': 'rdPanelHead', text: M.fields}));
		p.appendChild(search);
		p.appendChild(list);
		function item(label, props, cls, hint) {
			var ic = {rdFieldCol: 'input-cursor-text', rdFieldCrit: 'ui-checks', rdFieldSys: 'braces', rdFieldImg: 'image'}[cls] || 'dot';
			var it = el('div', {'class': 'rdField ' + (cls || ''), draggable: ro ? 'false' : 'true', tabindex: '0', title: hint || label, 'data-text': label.toLowerCase()}, [icon(ic), el('span', {'class': 'text-truncate', text: label})]);
			it.addEventListener('dragstart', function (e) { e.dataTransfer.setData('text/x-nurd', JSON.stringify(props)); e.dataTransfer.setData('text/plain', label); e.dataTransfer.effectAllowed = 'copy'; });
			it.addEventListener('dblclick', function () { if (!ro) insertAt(props, null); });
			it.addEventListener('keydown', function (e) { if (e.key === 'Enter' && !ro) { e.preventDefault(); insertAt(props, null); } });
			return it;
		}
		if (S.fields === null) {
			list.appendChild(el('div', {'class': 'small text-body-secondary p-2'}, [el('span', {'class': 'spinner-border spinner-border-sm me-1', 'aria-hidden': 'true'}), '…']));
		} else if (S.fieldsError) {
			list.appendChild(el('div', {'class': 'alert alert-warning small p-2 mb-2', text: M.fields_error + ' ' + S.fieldsError}));
		} else if (!S.fields.columns.length) {
			list.appendChild(el('div', {'class': 'small text-body-secondary p-2', text: M.no_fields}));
		}
		if (S.fields) {
			S.fields.columns.forEach(function (c) { list.appendChild(item(c, {objectType: 'field', fieldName: c}, 'rdFieldCol')); });
			if (S.fields.criteria.length) {
				list.appendChild(el('div', {'class': 'rdPanelSub', text: M.criteria}));
				S.fields.criteria.forEach(function (c) { list.appendChild(item(c, {objectType: 'field', fieldName: c}, 'rdFieldCrit')); });
			}
		}
		list.appendChild(el('div', {'class': 'rdPanelSub', text: M.system}));
		[[M.sys_page_of, 'Page #page# / #pages#'], [M.sys_page, '#page#'], [M.sys_pages, '#pages#'], [M.sys_description, '#description#'], [M.sys_code, '#code#'],
			[M.sys_date, '#nu_date#'], [M.sys_datetime, '#nu_date_time#'], [M.sys_user, '#USER_NAME#']].forEach(function (x) {
			list.appendChild(item(x[0], {objectType: 'label', fieldName: x[1].replace('Page', M.page || 'Page')}, 'rdFieldSys', x[1]));
		});
		if (D.images.length) {
			list.appendChild(el('div', {'class': 'rdPanelSub', text: M.images}));
			D.images.forEach(function (im) { list.appendChild(item(im.code, {objectType: 'image', fieldName: 'Image:' + im.code, width: 120, height: 80}, 'rdFieldImg', im.description)); });
		}
		list.appendChild(el('div', {'class': 'small text-body-secondary mt-2 px-1', text: M.drop_hint}));
		search.addEventListener('input', function () {
			var q = search.value.trim().toLowerCase();
			Array.prototype.forEach.call(list.querySelectorAll('.rdField'), function (r) { r.hidden = q !== '' && r.getAttribute('data-text').indexOf(q) < 0; });
		});
	}
	function loadFields() {
		post('fields', {}).then(function (r) {
			if (r && r.ok) { S.fields = {columns: r.columns || [], criteria: r.criteria || []}; S.fieldsError = ''; }
			else { S.fields = {columns: [], criteria: []}; S.fieldsError = (r && (r.message || (r.errors && r.errors[0] && r.errors[0].message))) || ''; }
			renderLeft();
			renderProps();
		});
	}

	// ================================================================= canvas
	function renderCanvas() {
		var z = S.zoom;
		var w = num(L.width, 210) * U * z;
		ui.page.innerHTML = '';
		ui.page.style.width = w + 'px';
		ui.page.classList.toggle('rdNoGrid', !S.grid);
		// ruler: a tick every 5 mm, a number every 10 mm
		ui.ruler.innerHTML = '';
		ui.ruler.style.width = w + 'px';
		for (var m = 0; m <= num(L.width, 210); m += 5) {
			var t = el('span', {'class': 'rdTick' + (m % 10 === 0 ? ' rdTickMajor' : '')}, m % 10 === 0 && m > 0 ? [String(m)] : []);
			t.style.left = (m * U * z) + 'px';
			ui.ruler.appendChild(t);
		}
		bands().forEach(function (b) {
			var Sec = sec(b.g, b.s);
			var key = b.g + '-' + b.s;
			var head = el('div', {'class': 'rdBandHead' + (S.band === key && !S.sel.length ? ' rdBandActive' : '')}, [
				el('span', {'class': 'fw-semibold', text: b.label}),
				el('span', {'class': 'rdBandInfo', text: mm(Sec.height) + ' mm' + (String(Sec.page_break) === '1' ? ' · ⤓' : '')})
			]);
			head.addEventListener('click', function () { selectBand(key); });
			var body = el('div', {'class': 'rdBandBody', 'data-band': key});
			body.style.height = Math.max(0, num(Sec.height) * z) + 'px';
			body.style.backgroundSize = (S.step * z * (S.step < 8 ? 2 : 1)) + 'px ' + (S.step * z * (S.step < 8 ? 2 : 1)) + 'px';
			body.style.backgroundColor = Sec.color && String(Sec.color).toUpperCase() !== '#FFFFFF' ? Sec.color : '';
			(Sec.objects || []).slice().sort(function (a, b2) { return num(a.zIndex) - num(b2.zIndex); }).forEach(function (o) { body.appendChild(objectEl(o)); });
			var grip = el('div', {'class': 'rdBandGrip', title: M.band_height, 'aria-hidden': 'true'});
			grip.addEventListener('pointerdown', function (e) { startBandResize(e, b.g, b.s); });
			body.addEventListener('pointerdown', function (e) { if (e.target === body) startMarquee(e, body, key); });
			body.addEventListener('dragover', function (e) { if (!ro && hasRdData(e)) { e.preventDefault(); e.dataTransfer.dropEffect = 'copy'; body.classList.add('rdDropOver'); } });
			body.addEventListener('dragleave', function () { body.classList.remove('rdDropOver'); });
			body.addEventListener('drop', function (e) {
				body.classList.remove('rdDropOver');
				var d = e.dataTransfer.getData('text/x-nurd');
				if (!d || ro) return;
				e.preventDefault();
				var r = body.getBoundingClientRect();
				insertAt(JSON.parse(d), {band: key, left: snapV((e.clientX - r.left) / z), top: snapV((e.clientY - r.top) / z)});
			});
			ui.page.appendChild(el('div', {'class': 'rdBand', 'data-band': key}, [head, body, grip]));
		});
		updateToolbar();
	}
	function hasRdData(e) { return Array.prototype.indexOf.call(e.dataTransfer.types || [], 'text/x-nurd') >= 0; }

	function objectEl(o) {
		var z = S.zoom;
		var box = isBox(o);
		var e = el('div', {'class': 'rdObj rdT-' + (box ? 'box' : o.objectType) + (S.sel.indexOf(o.id) >= 0 ? ' rdSel' : ''), 'data-id': o.id, tabindex: '-1'});
		e.style.left = num(o.left) * z + 'px';
		e.style.top = num(o.top) * z + 'px';
		e.style.width = Math.max(1, num(o.width) * z) + 'px';
		e.style.height = Math.max(1, num(o.height) * z) + 'px';
		e.style.zIndex = String(num(o.zIndex));
		var bw = num(o.borderWidth);
		if (bw > 0 || box) { e.style.borderStyle = 'solid'; e.style.borderColor = o.borderColor || '#000'; e.style.borderWidth = Math.max(bw > 0 ? 1 : 0, bw / 5 * U * z) + 'px'; }
		e.style.backgroundColor = o.backgroundColor || '';
		if (o.objectType === 'image') {
			var im = String(o.fieldName || '').indexOf('Image:') === 0 ? D.images.filter(function (i) { return 'Image:' + i.code === o.fieldName; })[0] : null;
			if (im && im.url) e.appendChild(el('img', {src: im.url, alt: '', draggable: 'false'}));
			else e.appendChild(el('div', {'class': 'rdImgPh'}, [icon('image'), el('span', {text: o.fieldName || M.image_none})]));
		} else if (!box) {
			var t = el('div', {'class': 'rdText'});
			t.style.fontFamily = cssFont(o.fontFamily);
			t.style.fontSize = Math.max(4, num(o.fontSize, 10) * PT * z) + 'px';
			t.style.lineHeight = Math.max(1, num(o.height) * z) + 'px';
			t.style.color = o.fontColor || '#000';
			t.style.textAlign = o.textAlign || 'left';
			var w = String(o.fontWeight || '').toLowerCase();
			if (w.indexOf('b') >= 0) t.style.fontWeight = '700';
			if (w.indexOf('i') >= 0) t.style.fontStyle = 'italic';
			t.textContent = o.objectType === 'field' ? '[' + (o.fieldName || '?') + ']' + (o.format ? ' ⟨' + String(o.format).slice(2) + '⟩' : '') : String(o.fieldName || '');
			e.appendChild(t);
		}
		if (S.sel.length === 1 && S.sel[0] === o.id && !ro) {
			['n', 'e', 's', 'w', 'ne', 'nw', 'se', 'sw'].forEach(function (h) {
				var hd = el('span', {'class': 'rdHandle rdH-' + h, 'data-h': h});
				hd.addEventListener('pointerdown', function (ev) { startResize(ev, o.id, h); });
				e.appendChild(hd);
			});
		}
		e.addEventListener('pointerdown', function (ev) { if (!ev.target.classList.contains('rdHandle')) startMove(ev, o.id); });
		e.addEventListener('dblclick', function () { S.tab = 'props'; renderProps(); var f = ui.right.querySelector('[data-prop="fieldName"]'); if (f) f.focus(); });
		return e;
	}
	function updateToolbar() {
		var n = S.sel.length;
		Array.prototype.forEach.call(ui.align.querySelectorAll('button'), function (b) {
			var a = b.getAttribute('data-align');
			b.disabled = ro || n < (a === 'disth' || a === 'distv' ? 3 : 2);
		});
	}

	// ----------------------------------------------------------------- selection
	function select(ids, add) {
		if (add) ids.forEach(function (id) { var i = S.sel.indexOf(id); if (i >= 0) S.sel.splice(i, 1); else S.sel.push(id); });
		else S.sel = ids.slice();
		if (S.sel.length) { var f = find(S.sel[0]); if (f) S.band = f.g + '-' + f.s; }
		S.tab = 'props';
		renderCanvas();
		renderProps();
	}
	function selectBand(key) { S.sel = []; S.band = key; S.tab = 'props'; renderCanvas(); renderProps(); }
	function bandBodies() { return Array.prototype.slice.call(ui.page.querySelectorAll('.rdBandBody')); }
	function bandAt(y) {
		var best = null;
		bandBodies().forEach(function (b) { var r = b.getBoundingClientRect(); if (y >= r.top && y <= r.bottom + 2) best = b; });
		return best;
	}

	// ----------------------------------------------------------------- move (also into another band)
	function startMove(e, id) {
		if (e.button !== 0) return;
		e.preventDefault();
		e.stopPropagation();
		ui.canvas.focus({preventScroll: true});
		var add = e.shiftKey || e.ctrlKey || e.metaKey;
		if (S.sel.indexOf(id) < 0 || add) { select([id], add); if (add) return; }
		if (ro) return;
		var z = S.zoom;
		var snap = snapshot();
		var items = selected().map(function (f) {
			var node = ui.page.querySelector('.rdObj[data-id="' + f.o.id + '"]');
			return {f: f, node: node, left: num(f.o.left), top: num(f.o.top), rect: node ? node.getBoundingClientRect() : null};
		});
		var sx = e.clientX, sy = e.clientY, moved = false;
		function mv(ev) {
			var dx = (ev.clientX - sx) / z, dy = (ev.clientY - sy) / z;
			if (!moved && Math.abs(dx) + Math.abs(dy) < 2 / z) return;
			moved = true;
			items.forEach(function (it) {
				if (!it.node) return;
				it.node.style.left = snapV(Math.max(0, it.left + dx)) * z + 'px';
				it.node.style.top = snapV(it.top + dy) * z + 'px';
			});
			var b = bandAt(ev.clientY);
			bandBodies().forEach(function (x) { x.classList.toggle('rdDropOver', x === b && b.getAttribute('data-band') !== items[0].f.g + '-' + items[0].f.s); });
		}
		function up(ev) {
			document.removeEventListener('pointermove', mv);
			document.removeEventListener('pointerup', up);
			bandBodies().forEach(function (x) { x.classList.remove('rdDropOver'); });
			if (!moved) return;
			var dx = (ev.clientX - sx) / z, dy = (ev.clientY - sy) / z;
			var target = bandAt(ev.clientY);
			var key = target ? target.getAttribute('data-band') : null;
			items.forEach(function (it) {
				var o = it.f.o;
				o.left = snapV(Math.max(0, it.left + dx));
				var newTop = snapV(it.top + dy);
				var from = it.f.g + '-' + it.f.s;
				if (key && key !== from && it.rect) {             // dropped into another band: keep the position on the page
					var tr = target.getBoundingClientRect();
					newTop = snapV((it.rect.top + (ev.clientY - sy) - tr.top) / z);
					var src = sec(it.f.g, it.f.s).objects;
					src.splice(src.indexOf(o), 1);
					var k = key.split('-');
					sec(+k[0], +k[1]).objects.push(o);
				}
				o.top = Math.max(0, newTop);
			});
			pushUndo(snap);
			if (key) S.band = key;
			renderCanvas();
			renderProps();
		}
		document.addEventListener('pointermove', mv);
		document.addEventListener('pointerup', up);
	}

	// ----------------------------------------------------------------- resize objects
	function startResize(e, id, h) {
		if (e.button !== 0 || ro) return;
		e.preventDefault();
		e.stopPropagation();
		var f = find(id);
		if (!f) return;
		var o = f.o, z = S.zoom, snap = snapshot();
		var st = {l: num(o.left), t: num(o.top), w: num(o.width), h: num(o.height)};
		var node = ui.page.querySelector('.rdObj[data-id="' + id + '"]');
		var sx = e.clientX, sy = e.clientY;
		function calc(ev) {
			var dx = (ev.clientX - sx) / z, dy = (ev.clientY - sy) / z;
			var l = st.l, t = st.t, w = st.w, hh = st.h;
			if (h.indexOf('e') >= 0) w = snapV(st.w + dx);
			if (h.indexOf('s') >= 0) hh = snapV(st.h + dy);
			if (h.indexOf('w') >= 0) { l = snapV(st.l + dx); w = st.w + st.l - l; }
			if (h.indexOf('n') >= 0) { t = snapV(st.t + dy); hh = st.h + st.t - t; }
			return {l: Math.max(0, l), t: Math.max(0, t), w: Math.max(1, w), h: Math.max(1, hh)};
		}
		function mv(ev) {
			var r = calc(ev);
			node.style.left = r.l * z + 'px'; node.style.top = r.t * z + 'px'; node.style.width = r.w * z + 'px'; node.style.height = r.h * z + 'px';
		}
		function up(ev) {
			document.removeEventListener('pointermove', mv);
			document.removeEventListener('pointerup', up);
			var r = calc(ev);
			o.left = r.l; o.top = r.t; o.width = r.w; o.height = r.h;
			if (snapshot() !== snap) pushUndo(snap);
			renderCanvas();
			renderProps();
		}
		document.addEventListener('pointermove', mv);
		document.addEventListener('pointerup', up);
	}

	// ----------------------------------------------------------------- band height
	function startBandResize(e, g, s) {
		if (e.button !== 0 || ro) return;
		e.preventDefault();
		var Sec = sec(g, s), z = S.zoom, snap = snapshot(), h0 = num(Sec.height), sy = e.clientY;
		var body = ui.page.querySelector('.rdBandBody[data-band="' + g + '-' + s + '"]');
		function mv(ev) { body.style.height = Math.max(0, snapV(h0 + (ev.clientY - sy) / z)) * z + 'px'; }
		function up(ev) {
			document.removeEventListener('pointermove', mv);
			document.removeEventListener('pointerup', up);
			Sec.height = Math.max(0, snapV(h0 + (ev.clientY - sy) / z));
			if (snapshot() !== snap) pushUndo(snap);
			S.band = g + '-' + s;
			renderCanvas();
			renderProps();
		}
		document.addEventListener('pointermove', mv);
		document.addEventListener('pointerup', up);
	}

	// ----------------------------------------------------------------- marquee
	function startMarquee(e, body, key) {
		if (e.button !== 0) return;
		e.preventDefault();
		ui.canvas.focus({preventScroll: true});
		var add = e.shiftKey || e.ctrlKey || e.metaKey;
		var r0 = body.getBoundingClientRect(), sx = e.clientX, sy = e.clientY;
		var box = el('div', {'class': 'rdMarquee'});
		var moved = false;
		function mv(ev) {
			if (!moved && Math.abs(ev.clientX - sx) + Math.abs(ev.clientY - sy) < 4) return;
			if (!moved) { moved = true; body.appendChild(box); }
			box.style.left = Math.min(sx, ev.clientX) - r0.left + 'px';
			box.style.top = Math.min(sy, ev.clientY) - r0.top + 'px';
			box.style.width = Math.abs(ev.clientX - sx) + 'px';
			box.style.height = Math.abs(ev.clientY - sy) + 'px';
		}
		function up(ev) {
			document.removeEventListener('pointermove', mv);
			document.removeEventListener('pointerup', up);
			if (!moved) { if (!add) selectBand(key); return; }
			var z = S.zoom;
			var x1 = (Math.min(sx, ev.clientX) - r0.left) / z, x2 = (Math.max(sx, ev.clientX) - r0.left) / z;
			var y1 = (Math.min(sy, ev.clientY) - r0.top) / z, y2 = (Math.max(sy, ev.clientY) - r0.top) / z;
			var k = key.split('-');
			var ids = sec(+k[0], +k[1]).objects.filter(function (o) {
				return num(o.left) < x2 && num(o.left) + num(o.width) > x1 && num(o.top) < y2 && num(o.top) + num(o.height) > y1;
			}).map(function (o) { return o.id; });
			S.band = key;
			select(ids, add);
		}
		document.addEventListener('pointermove', mv);
		document.addEventListener('pointerup', up);
	}

	// ================================================================= commands
	function targetBand() {
		var k = (S.band || '0-0').split('-');
		return {g: +k[0], s: +k[1]};
	}
	function addObject(kind) {
		if (ro) return;
		var p = {field: {objectType: 'field', fieldName: ''}, label: {objectType: 'label', fieldName: M.add_label || 'Text'},
			box: {objectType: 'label', fieldName: KEEP, backgroundColor: '#DDDDDD', height: 40, width: 160},
			line: {objectType: 'label', fieldName: KEEP, backgroundColor: '#000000', height: 2, width: 720},
			image: {objectType: 'image', fieldName: '', width: 120, height: 80}}[kind];
		insertAt(p, null);
	}
	/** New object from a template; pos {band, left, top} or null (the current band, below the lowest object). */
	function insertAt(props, pos) {
		var snap = snapshot();
		var b = pos ? {g: +pos.band.split('-')[0], s: +pos.band.split('-')[1]} : targetBand();
		var Sec = sec(b.g, b.s);
		var o = newObject(props.objectType || 'field', props);
		if (pos) { o.left = pos.left; o.top = pos.top; }
		else {
			var bottom = 0, left = 40;
			Sec.objects.forEach(function (x) { if (num(x.top) + num(x.height) >= bottom) { bottom = num(x.top) + num(x.height); } });
			o.left = left;
			o.top = Sec.objects.length ? snapV(bottom + 4) : 4;
		}
		if (o.top + o.height > num(Sec.height)) Sec.height = snapV(o.top + o.height + 4);
		Sec.objects.push(o);
		pushUndo(snap);
		S.band = b.g + '-' + b.s;
		S.sel = [o.id];
		S.tab = 'props';
		renderCanvas();
		renderProps();
		var f = ui.right.querySelector('[data-prop="fieldName"]');
		if (f && !pos && props.objectType !== 'image' && !isBox(o)) { f.focus(); if (f.select) f.select(); }
	}
	function deleteSel() {
		if (ro || !S.sel.length) return;
		change(function () {
			selected().forEach(function (f) { var a = sec(f.g, f.s).objects; a.splice(a.indexOf(f.o), 1); });
			S.sel = [];
		});
	}
	function duplicateSel() {
		if (ro || !S.sel.length) return;
		var ids = [];
		change(function () {
			selected().forEach(function (f) {
				var c = clone(f.o);
				c.id = newId(); c.name = c.id;
				c.left = num(c.left) + S.step * 2; c.top = num(c.top) + S.step * 2;
				sec(f.g, f.s).objects.push(c);
				ids.push(c.id);
			});
			S.sel = ids;
		});
	}
	function copySel() { S.clip = selected().map(function (f) { return clone(f.o); }); }
	function paste() {
		if (ro || !S.clip || !S.clip.length) return;
		var b = targetBand(), ids = [];
		change(function () {
			S.clip.forEach(function (o) {
				var c = clone(o);
				c.id = newId(); c.name = c.id;
				c.left = num(c.left) + S.step * 2; c.top = num(c.top) + S.step * 2;
				sec(b.g, b.s).objects.push(c);
				ids.push(c.id);
			});
			S.sel = ids;
		});
	}
	function zSel(d) {
		if (ro || !S.sel.length) return;
		change(function () {
			var all = [];
			eachObject(function (o) { all.push(num(o.zIndex)); });
			var v = d > 0 ? Math.max.apply(null, all.concat([0])) + 1 : Math.min.apply(null, all.concat([0])) - 1;
			selected().forEach(function (f) { f.o.zIndex = v; });
		});
	}
	function nudge(dx, dy) {
		if (ro || !S.sel.length) return;
		change(function () { selected().forEach(function (f) { f.o.left = Math.max(0, num(f.o.left) + dx); f.o.top = Math.max(0, num(f.o.top) + dy); }); });
	}
	function alignSel(how) {
		var fs = selected();
		if (ro || fs.length < 2) return;
		change(function () {
			var os = fs.map(function (f) { return f.o; });
			var ref = os[0];
			var minL = Math.min.apply(null, os.map(function (o) { return num(o.left); }));
			var maxR = Math.max.apply(null, os.map(function (o) { return num(o.left) + num(o.width); }));
			var minT = Math.min.apply(null, os.map(function (o) { return num(o.top); }));
			var maxB = Math.max.apply(null, os.map(function (o) { return num(o.top) + num(o.height); }));
			os.forEach(function (o) {
				if (how === 'left') o.left = minL;
				if (how === 'right') o.left = maxR - num(o.width);
				if (how === 'hcenter') o.left = Math.round((minL + maxR) / 2 - num(o.width) / 2);
				if (how === 'top') o.top = minT;
				if (how === 'bottom') o.top = maxB - num(o.height);
				if (how === 'vcenter') o.top = Math.round((minT + maxB) / 2 - num(o.height) / 2);
				if (how === 'width') o.width = num(ref.width);
				if (how === 'height') o.height = num(ref.height);
			});
			if (how === 'disth' || how === 'distv') {
				var k = how === 'disth' ? ['left', 'width'] : ['top', 'height'];
				var sorted = os.slice().sort(function (a, b) { return num(a[k[0]]) - num(b[k[0]]); });
				var total = sorted.reduce(function (s, o) { return s + num(o[k[1]]); }, 0);
				var start = num(sorted[0][k[0]]), end = num(sorted[sorted.length - 1][k[0]]) + num(sorted[sorted.length - 1][k[1]]);
				var gap = (end - start - total) / (sorted.length - 1), pos = start;
				sorted.forEach(function (o) { o[k[0]] = Math.round(pos); pos += num(o[k[1]]) + gap; });
			}
		});
	}

	// ================================================================= properties panel
	var pendingSnap = null;
	function propInput(label, prop, input, wide) {
		input.setAttribute('data-prop', prop);
		if (!input.id) input.id = 'rdp_' + prop + '_' + Math.random().toString(36).slice(2, 7);
		if (ro) input.disabled = true;
		return el('div', {'class': 'rdProp' + (wide ? ' rdPropWide' : '')}, [el('label', {'class': 'form-label', 'for': input.id, text: label}), input]);
	}
	function bindProps(root, apply) {
		Array.prototype.forEach.call(root.querySelectorAll('[data-prop]'), function (inp) {
			inp.addEventListener('focus', function () { pendingSnap = snapshot(); });
			var handler = function () {
				var snap = pendingSnap || snapshot();
				apply(inp.getAttribute('data-prop'), inp.type === 'checkbox' ? (inp.checked ? '1' : '0') : inp.value, inp);
				if (snapshot() !== snap) { pushUndo(snap); pendingSnap = snapshot(); }
				renderCanvas();
			};
			inp.addEventListener(inp.tagName === 'SELECT' || inp.type === 'checkbox' || inp.type === 'color' ? 'change' : 'input', handler);
			if (inp.type === 'color') inp.addEventListener('input', handler);
		});
	}
	function sel(options, value) {
		var s = el('select', {'class': 'form-select form-select-sm'});
		options.forEach(function (o) { var op = el('option', {value: o[0], text: o[1]}); if (String(o[0]) === String(value)) op.selected = true; s.appendChild(op); });
		if (value !== undefined && value !== null && String(value) !== '' && !options.some(function (o) { return String(o[0]) === String(value); })) s.appendChild(el('option', {value: value, text: value, selected: true}));
		return s;
	}
	function inp(type, value, attrs) {
		var i = el('input', Object.assign({type: type, 'class': type === 'color' ? 'form-control form-control-sm form-control-color' : 'form-control form-control-sm', value: value === undefined || value === null ? '' : value}, attrs || {}));
		return i;
	}
	function common(objs, k) {
		var v = objs.length ? String(objs[0][k] === undefined ? '' : objs[0][k]) : '';
		return objs.every(function (o) { return String(o[k] === undefined ? '' : o[k]) === v; }) ? v : '';
	}
	function fieldDatalist() {
		var id = 'rdFieldsDl';
		var dl = document.getElementById(id);
		if (!dl) { dl = el('datalist', {id: id}); document.body.appendChild(dl); }
		dl.innerHTML = '';
		var cols = S.fields ? S.fields.columns.concat(S.fields.criteria) : [];
		cols.forEach(function (c) { dl.appendChild(el('option', {value: c})); dl.appendChild(el('option', {value: 'SUM(' + c + ')'})); });
		D.images.forEach(function (im) { dl.appendChild(el('option', {value: 'Image:' + im.code})); });
		return id;
	}

	function renderProps() {
		var p = ui.right;
		p.innerHTML = '';
		var tabs = el('div', {'class': 'btn-group btn-group-sm w-100 mb-2', role: 'tablist'}, [
			el('button', {type: 'button', 'class': 'btn btn-outline-secondary' + (S.tab === 'props' ? ' active' : ''), role: 'tab', 'aria-selected': S.tab === 'props' ? 'true' : 'false', text: M.props, onclick: function () { S.tab = 'props'; renderProps(); }}),
			el('button', {type: 'button', 'class': 'btn btn-outline-secondary' + (S.tab === 'report' ? ' active' : ''), role: 'tab', 'aria-selected': S.tab === 'report' ? 'true' : 'false', text: M.report, onclick: function () { S.tab = 'report'; renderProps(); }})
		]);
		p.appendChild(tabs);
		if (S.tab === 'report') { p.appendChild(reportPanel()); return; }
		var fs = selected();
		if (fs.length) { p.appendChild(objectPanel(fs)); return; }
		if (S.band) { p.appendChild(bandPanel()); return; }
		p.appendChild(el('div', {'class': 'small text-body-secondary p-2', text: M.nothing}));
	}

	function objectPanel(fs) {
		var objs = fs.map(function (f) { return f.o; });
		var one = objs.length === 1 ? objs[0] : null;
		var wrap = el('div', {'class': 'rdProps'});
		wrap.appendChild(el('div', {'class': 'rdPanelHead', text: one ? (M.id || 'ID') + ' ' + one.id : (M.selected_n || '%d').replace('%d', objs.length)}));
		var kinds = objs.map(function (o) { return isBox(o) ? 'box' : o.objectType; });
		var kind = kinds.every(function (k) { return k === kinds[0]; }) ? kinds[0] : '';
		if (one) {
			var typeSel = sel([['field', M.type_field], ['label', M.type_label], ['image', M.type_image], ['box', M.add_box]], kind);
			wrap.appendChild(propInput(M.type, 'kind', typeSel));
			var content;
			if (kind === 'box') {
				content = null;
				wrap.appendChild(el('div', {'class': 'small text-body-secondary mb-2', text: M.box_hint}));
			} else if (kind === 'label') {
				content = el('textarea', {'class': 'form-control form-control-sm', rows: '2'});
				content.value = one.fieldName || '';
				wrap.appendChild(propInput(M.content_label, 'fieldName', content, true));
			} else {
				content = inp('text', one.fieldName, {list: fieldDatalist(), spellcheck: 'false', autocomplete: 'off'});
				wrap.appendChild(propInput(kind === 'image' ? M.content_image : M.content_field, 'fieldName', content, true));
				if (kind === 'field') {
					var m = /^(SUM|AVERAGE)\((.*)\)$/i.exec(String(one.fieldName || ''));
					var sumSel = sel([['', M.sum_none], ['SUM', M.sum_sum], ['AVERAGE', M.sum_avg]], m ? m[1].toUpperCase() : '');
					wrap.appendChild(propInput(M.summary, 'summary', sumSel));
					wrap.appendChild(el('div', {'class': 'small text-body-secondary mb-2', text: M.summary_hint}));
				}
			}
		}
		if (kind !== 'image' && kind !== 'box') {
			wrap.appendChild(propInput(M.format, 'format', sel(D.formats.map(function (f) { return [f[0], f[1] || '—']; }), common(objs, 'format'))));
			var fontRow = el('div', {'class': 'rdPropRow'}, [
				propInput(M.font, 'fontFamily', sel(D.fonts.map(function (f) { return [f, f]; }), common(objs, 'fontFamily'))),
				propInput(M.size + ' (' + M.pt + ')', 'fontSize', inp('number', common(objs, 'fontSize'), {min: '4', max: '96', step: '0.5'}))
			]);
			wrap.appendChild(fontRow);
			var w = common(objs, 'fontWeight').toLowerCase();
			wrap.appendChild(el('div', {'class': 'rdPropRow'}, [
				propInput(M.bold, 'bold', el('input', {type: 'checkbox', 'class': 'form-check-input', checked: w.indexOf('b') >= 0})),
				propInput(M.italic, 'italic', el('input', {type: 'checkbox', 'class': 'form-check-input', checked: w.indexOf('i') >= 0})),
				propInput(M.align, 'textAlign', sel([['left', '⟵ ' + M.align_left], ['center', '↔ ' + M.align_center], ['right', '⟶ ' + M.align_right]], common(objs, 'textAlign')))
			]));
		}
		wrap.appendChild(el('div', {'class': 'rdPanelSub', text: M.colors}));
		var colorRow = el('div', {'class': 'rdPropRow'});
		if (kind !== 'image' && kind !== 'box') colorRow.appendChild(propInput(M.color_text, 'fontColor', inp('color', hex(common(objs, 'fontColor') || '#000000'))));
		if (kind !== 'image') colorRow.appendChild(propInput(M.color_back, 'backgroundColor', inp('color', hex(common(objs, 'backgroundColor') || '#ffffff'))));
		colorRow.appendChild(propInput(M.color_border, 'borderColor', inp('color', hex(common(objs, 'borderColor') || '#000000'))));
		colorRow.appendChild(propInput(M.border, 'borderWidth', inp('number', common(objs, 'borderWidth'), {min: '0', max: '20', step: '0.5'})));
		wrap.appendChild(colorRow);
		wrap.appendChild(el('div', {'class': 'rdPanelSub', text: M.position + ' (' + M.mm + ')'}));
		wrap.appendChild(el('div', {'class': 'rdPropRow'}, [
			propInput(M.left, 'left', inp('number', one ? mm(one.left) : '', {step: '0.25', min: '0'})),
			propInput(M.top, 'top', inp('number', one ? mm(one.top) : '', {step: '0.25', min: '0'})),
			propInput(M.width, 'width', inp('number', common(objs, 'width') !== '' ? mm(common(objs, 'width')) : '', {step: '0.25', min: '0.25'})),
			propInput(M.height, 'height', inp('number', common(objs, 'height') !== '' ? mm(common(objs, 'height')) : '', {step: '0.25', min: '0.25'}))
		]));
		if (kind === 'field' || kind === 'label') {
			wrap.appendChild(el('div', {'class': 'rdPropRow'}, [
				propInput(M.rows_min, 'minRows', inp('number', common(objs, 'minRows'), {step: '1', min: '-1'})),
				propInput(M.rows_max, 'maxRows', inp('number', common(objs, 'maxRows'), {step: '1', min: '0'})),
				propInput(M.zindex, 'zIndex', inp('number', common(objs, 'zIndex'), {step: '1'}))
			]));
			wrap.appendChild(el('div', {'class': 'small text-body-secondary', text: M.rows_hint}));
		}
		bindProps(wrap, function (prop, v, input) {
			objs.forEach(function (o) {
				switch (prop) {
					case 'kind':
						if (v === 'box') { o.objectType = 'label'; o.fieldName = KEEP; if (hex(o.backgroundColor) === '#ffffff') o.backgroundColor = '#DDDDDD'; }
						else { if (isBox(o)) o.fieldName = ''; o.objectType = v; }
						break;
					case 'summary':
						var base = String(o.fieldName || '').replace(/^(SUM|AVERAGE)\((.*)\)$/i, '$2').split(',')[0].trim();
						o.fieldName = v === '' ? base : (v === 'AVERAGE' ? 'AVERAGE(' + base + ', ' + base + ')' : v + '(' + base + ')');
						break;
					case 'bold': case 'italic':
						var cur = String(o.fontWeight || '').toLowerCase();
						var b = prop === 'bold' ? v === '1' : cur.indexOf('b') >= 0, it = prop === 'italic' ? v === '1' : cur.indexOf('i') >= 0;
						o.fontWeight = (b ? 'b' : '') + (it ? 'i' : '');
						break;
					case 'left': case 'top': case 'width': case 'height':
						if (v === '') return;
						o[prop] = Math.max(prop === 'width' || prop === 'height' ? 1 : 0, Math.round(num(v) * U));
						break;
					case 'minRows': case 'maxRows': case 'zIndex':
						o[prop] = Math.round(num(v));
						break;
					default:
						o[prop] = v;
				}
			});
			if (prop === 'kind' || prop === 'summary') renderProps();
		});
		return wrap;
	}

	function bandPanel() {
		var k = S.band.split('-'), g = +k[0], s = +k[1], Sec = sec(g, s);
		var b = bands().filter(function (x) { return x.g === g && x.s === s; })[0];
		var wrap = el('div', {'class': 'rdProps'});
		wrap.appendChild(el('div', {'class': 'rdPanelHead', text: M.band + ': ' + (b ? b.label : '')}));
		wrap.appendChild(el('div', {'class': 'rdPropRow'}, [
			propInput(M.band_height + ' (' + M.mm + ')', 'height', inp('number', mm(Sec.height), {step: '0.25', min: '0'})),
			propInput(M.band_color, 'color', inp('color', hex(Sec.color || '#FFFFFF')))
		]));
		if (g !== 2) wrap.appendChild(propInput(M.band_break, 'page_break', el('input', {type: 'checkbox', 'class': 'form-check-input', checked: String(Sec.page_break) === '1'})));
		wrap.appendChild(el('div', {'class': 'small text-body-secondary mt-2', text: (Sec.objects || []).length + ' × ' + M.type_field + ' / ' + M.type_label}));
		bindProps(wrap, function (prop, v) {
			if (prop === 'height') Sec.height = Math.max(0, Math.round(num(v) * U));
			else if (prop === 'page_break') Sec.page_break = v === '1' ? 1 : 0;
			else Sec[prop] = v;
		});
		return wrap;
	}

	function reportPanel() {
		var wrap = el('div', {'class': 'rdProps'});
		wrap.appendChild(el('div', {'class': 'rdPanelHead', text: M.page}));
		wrap.appendChild(el('div', {'class': 'rdPropRow'}, [
			propInput(M.paper, 'paper', sel(Object.keys(D.papers).map(function (p) { return [p, p]; }), L.paper)),
			propInput(M.orientation, 'orientation', sel([['P', M.portrait], ['L', M.landscape]], L.orientation))
		]));
		wrap.appendChild(propInput(M.html, 'ishtml', el('input', {type: 'checkbox', 'class': 'form-check-input', checked: L.ishtml === 'Y'})));
		wrap.appendChild(el('div', {'class': 'small text-body-secondary mb-2', text: M.html_hint}));
		bindProps(wrap, function (prop, v) {
			if (prop === 'ishtml') { L.ishtml = v === '1' ? 'Y' : 'N'; return; }
			L[prop] = v;
			var ph = D.papers[L.paper] || [297, 210];
			L.height = L.orientation === 'L' ? ph[1] : ph[0];
			L.width = L.orientation === 'L' ? ph[0] : ph[1];
		});

		// sort groups: field, direction, order – group headers and footers print when the value changes
		var gw = el('div', {'class': 'rdGroups'});
		gw.appendChild(el('div', {'class': 'rdPanelHead mt-3', text: M.groups}));
		gw.appendChild(el('div', {'class': 'small text-body-secondary mb-2', text: M.groups_hint}));
		var used = usedGroups();
		var cols = S.fields ? S.fields.columns.concat(S.fields.criteria) : [];
		used.forEach(function (g, i) {
			var G = L.groups[g];
			var fsel = sel(cols.map(function (c) { return [c, c]; }), G.sortField);
			fsel.setAttribute('aria-label', M.group_field);
			fsel.disabled = ro;
			var dsel = sel([['a', M.asc], ['d', M.desc]], G.sortBy || 'a');
			dsel.setAttribute('aria-label', M.group_dir);
			dsel.disabled = ro;
			fsel.addEventListener('change', function () { change(function () { G.sortField = fsel.value; G.sections[0].label = fsel.value + ' Header'; G.sections[1].label = fsel.value + ' Footer'; }); });
			dsel.addEventListener('change', function () { change(function () { G.sortBy = dsel.value; }); });
			var row = el('div', {'class': 'rdGroupRow'}, [el('span', {'class': 'badge text-bg-secondary', text: String(i + 1)}), fsel, dsel,
				el('div', {'class': 'btn-group btn-group-sm rdGroupBtns'}, [btn('arrow-up', M.up, function () { moveGroup(i, -1); }), btn('arrow-down', M.down, function () { moveGroup(i, 1); }),
					btn('x-lg', M.group_remove, function () { removeGroup(i); }, {cls: 'btn-outline-danger'})])]);
			Array.prototype.forEach.call(row.querySelectorAll('button'), function (b) { b.disabled = ro; });
			gw.appendChild(row);
		});
		if (used.length < 5 && !ro) {
			var nsel = sel([['', '+ ' + M.group_add]].concat(cols.map(function (c) { return [c, c]; })), '');
			nsel.setAttribute('aria-label', M.group_add);
			nsel.addEventListener('change', function () { if (nsel.value) addGroup(nsel.value); });
			gw.appendChild(nsel);
		}
		wrap.appendChild(gw);
		return wrap;
	}
	/** Groups are kept in order without gaps (3, 4, …) – nuBuilder's runner uses the used ones by position. */
	function groupList() { return usedGroups().map(function (g) { return clone(L.groups[g]); }); }
	function setGroups(list) {
		GROUPS.forEach(function (g, i) {
			L.groups[g] = list[i] || {sortField: '', sortBy: 'a', sections: [{id: '', top: 0, height: 0, label: ' Header', page_break: 0, margins: 0, color: '#FFFFFF', objects: []},
				{id: '', top: 0, height: 0, label: ' Footer', page_break: 0, margins: 0, color: '#FFFFFF', objects: []}]};
		});
	}
	function addGroup(field) {
		change(function () {
			var list = groupList();
			list.push({sortField: field, sortBy: 'a', sections: [
				{id: '', top: 0, height: 28, label: field + ' Header', page_break: 0, margins: 0, color: '#FFFFFF', objects: [newObject('field', {fieldName: field, fontWeight: 'b', top: 4, left: 40, width: 300})]},
				{id: '', top: 0, height: 24, label: field + ' Footer', page_break: 0, margins: 0, color: '#FFFFFF', objects: []}]});
			setGroups(list);
		});
	}
	function removeGroup(i) { change(function () { var list = groupList(); list.splice(i, 1); setGroups(list); S.sel = S.sel.filter(find); }); }
	function moveGroup(i, d) {
		change(function () {
			var list = groupList(), j = i + d;
			if (j < 0 || j >= list.length) return;
			var t = list[i]; list[i] = list[j]; list[j] = t;
			setGroups(list);
		});
	}

	// ================================================================= server
	function post(action, data) {
		var fd = new FormData();
		fd.append('action', action);
		fd.append('_csrf', D.csrf);
		Object.keys(data || {}).forEach(function (k) { fd.append(k, data[k]); });
		return fetch(D.url, {method: 'POST', credentials: 'same-origin', body: fd, headers: {'Accept': 'application/json'}})
			.then(function (r) { return r.json().catch(function () { return {ok: false, message: 'HTTP ' + r.status}; }); })
			.catch(function (e) { return {ok: false, message: String(e && e.message || e)}; });
	}
	function toast(msg, type) { if (window.nuToast) window.nuToast(msg, type); else if (type === 'danger') alert(msg); }
	function save() {
		if (ro) return;
		ui.save.disabled = true;
		post('save', {layout: JSON.stringify(L)}).then(function (r) {
			ui.save.disabled = false;
			if (r && r.ok) { setDirty(false); toast(M.saved, 'success'); }
			else toast(M.save_failed + ' ' + ((r && r.message) || ''), 'danger');
		});
	}
	function preview() {
		var f = document.getElementById('rdPreviewForm');
		if (!f) return;
		f.querySelector('[name=layout]').value = JSON.stringify(L);
		var m = document.getElementById('rdPreviewModal');
		if (m && nuBs.ready()) nuBs.modal(m).show();
		f.submit();
	}
	function help() {
		var m = document.getElementById('rdHelpModal');
		if (!m) {
			m = el('div', {'class': 'modal fade', id: 'rdHelpModal', tabindex: '-1', 'aria-hidden': 'true'}, [el('div', {'class': 'modal-dialog modal-lg'}, [el('div', {'class': 'modal-content'}, [
				el('div', {'class': 'modal-header py-2'}, [el('h2', {'class': 'modal-title fs-6', text: M.help}), el('button', {type: 'button', 'class': 'btn-close', 'data-bs-dismiss': 'modal', 'aria-label': 'Close'})]),
				el('div', {'class': 'modal-body small rdHelp'}, String(M.help_text || '').split(/\\n|\n/).map(function (l) { return el('p', {'class': 'mb-1', text: l}); }))])])]);
			document.body.appendChild(m);
		}
		nuBs.modal(m).show();
	}

	// ================================================================= keyboard
	document.addEventListener('keydown', function (e) {
		var t = e.target;
		var typing = t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA' || t.tagName === 'SELECT' || t.isContentEditable);
		var mod = e.ctrlKey || e.metaKey;
		if (mod && e.key.toLowerCase() === 's') { e.preventDefault(); save(); return; }
		if (typing || document.querySelector('.modal.show')) return;
		var k = e.key.toLowerCase();
		if (mod && k === 'z' && !e.shiftKey) { e.preventDefault(); undo(); return; }
		if (mod && (k === 'y' || (k === 'z' && e.shiftKey))) { e.preventDefault(); redo(); return; }
		if (mod && k === 'd') { e.preventDefault(); duplicateSel(); return; }
		if (mod && k === 'c') { copySel(); return; }
		if (mod && k === 'v') { e.preventDefault(); paste(); return; }
		if (mod && k === 'a') {
			e.preventDefault();
			var b = targetBand();
			select(sec(b.g, b.s).objects.map(function (o) { return o.id; }), false);
			return;
		}
		if (k === 'delete' || k === 'backspace') { if (S.sel.length) { e.preventDefault(); deleteSel(); } return; }
		if (k === 'escape') { S.sel = []; renderCanvas(); renderProps(); return; }
		var step = e.shiftKey ? S.step * 4 : (e.altKey ? 1 : S.step);
		if (k === 'arrowleft') { e.preventDefault(); nudge(-step, 0); }
		if (k === 'arrowright') { e.preventDefault(); nudge(step, 0); }
		if (k === 'arrowup') { e.preventDefault(); nudge(0, -step); }
		if (k === 'arrowdown') { e.preventDefault(); nudge(0, step); }
	});
	window.addEventListener('beforeunload', function (e) { if (S.dirty) { e.preventDefault(); e.returnValue = M.leave || ''; } });

	// ================================================================= start
	function renderAll() { renderLeft(); renderCanvas(); renderProps(); setDirty(S.dirty); }
	buildUI();
	if (!S.zoom) {                     // first visit: the page width fits the canvas
		var avail = ui.canvas.clientWidth - 56;
		S.zoom = Math.max(0.5, Math.min(1.5, Math.floor(avail / (num(L.width, 210) * U) * 20) / 20)) || 1;
		view();
	}
	S.band = '0-0';
	renderAll();
	setDirty(false);
	loadFields();
})();
