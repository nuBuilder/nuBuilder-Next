/*
 * Form designer inside the builder preview (index.php?p=form&builder=1).
 *   absolute layout: drag objects to move them, drag the corner handle to resize (5 px grid, Alt = 1 px)
 *     several objects (0.8.606, as nuBuilder Forte's arrange mode): Ctrl / ⌘ + click adds or removes an object, dragging on an empty
 *     place selects the objects in the box (Ctrl / ⌘ keeps the earlier ones), Ctrl / ⌘ + A selects all objects of the tab, Esc none;
 *     the selection moves together; arrow keys move it, Shift + arrow keys change width / height; the Arrange panel aligns
 *     (top, bottom, left, right), spaces evenly (vertical, horizontal), makes them the same size (shortest, tallest, thinnest,
 *     widest) and moves them up / down in the object order
 *   auto / grid layout: drag an object onto another to place it before/after it or into a new row below,
 *                       drag the right handle to change its width (1–12 columns)
 * Changes are sent to the builder window with postMessage; the builder saves them (several objects in one step: nuDesignerBatch).
 */
(function () {
	'use strict';
	var N = window.nuNext;
	if (!N || !N.builder || N.mode !== 'edit') return;

	var me = document.currentScript;
	var L = {};
	try { L = JSON.parse((me && me.getAttribute('data-labels')) || '{}'); } catch (e) { L = {}; }
	function lbl(k, d) { return L[k] || d; }

	var origin = window.location.origin;
	var absolute = N.form.layout === 'absolute';
	var GRID = 5;
	var selected = null;          // the main (last clicked) object
	var sel = [];                 // all selected objects (absolute layout)

	function send(msg) { window.parent.postMessage(msg, origin); }
	function idOf(w) { return w.id.replace(/^nuw_/, ''); }
	function snap(v, e) { return e && e.altKey ? Math.round(v) : Math.round(v / GRID) * GRID; }
	function mod(e) { return e.ctrlKey || e.metaKey; }

	document.documentElement.classList.add('nuDesigning');

	// every object gets an overlay that catches the mouse (inputs stay inert while designing)
	var wraps = [];
	document.querySelectorAll('#nuEditForm .nuWrap').forEach(function (w) {
		if (w.closest('.nuSubformCard')) return;
		wraps.push(w);
		var ov = document.createElement('div');
		ov.className = 'nuDesignOverlay';
		ov.title = idOf(w);
		w.appendChild(ov);
		var handle = document.createElement('div');
		handle.className = 'nuDesignHandle ' + (absolute ? 'nuDesignHandleCorner' : 'nuDesignHandleRight');
		w.appendChild(handle);
		ov.addEventListener('pointerdown', function (e) { startMove(e, w); });
		handle.addEventListener('pointerdown', function (e) { startResize(e, w); });
	});

	function paint() {
		wraps.forEach(function (w) {
			w.classList.toggle('nuBuilderSelected', w === selected);
			w.classList.toggle('nuDesignSelected', sel.indexOf(w) >= 0 && w !== selected);
		});
		panelState();
	}
	function select(w, notify) {
		selected = w;
		sel = w ? [w] : [];
		paint();
		if (w && notify) send({nuBuilderPick: idOf(w)});
	}
	function toggle(w) {
		var i = sel.indexOf(w);
		if (i >= 0) { sel.splice(i, 1); if (selected === w) selected = sel[sel.length - 1] || null; }
		else { sel.push(w); selected = w; }
		paint();
		if (selected) send({nuBuilderPick: idOf(selected)});
	}
	function visible(w) { return !!(w.offsetParent || w.getClientRects().length); }
	function activePane() { return document.querySelector('#nuEditForm .tab-pane.active') || document.getElementById('nuEditForm'); }

	// tab order (0.8.521, button in the builder's preview bar): a red number on every object the Tab key stops at, in that order –
	// over all tabs, like nuBuilder's arrange mode; a sub-form counts once
	var FOCUSABLE = 'input:not([type=hidden]):not([tabindex="-1"]):not([disabled]), select:not([disabled]), textarea:not([tabindex="-1"]):not([disabled]), button:not([disabled]):not([tabindex="-1"]), a[href]:not([tabindex="-1"]), [contenteditable="true"]';
	function showTabOrder(on) {
		document.querySelectorAll('.nuDesignOrder').forEach(function (b) { b.remove(); });
		document.documentElement.classList.toggle('nuShowTabOrder', !!on);
		if (!on) return;
		var n = 0;
		document.querySelectorAll('#nuEditForm .nuWrap').forEach(function (w) {
			if (w.parentElement && w.parentElement.closest('.nuWrap')) return;             // objects inside a sub-form: counted with it
			if (/\bnuWrap_(html|word|image|contentbox|chart)\b/.test(w.className)) return;  // display objects: links inside them are not form fields
			var stops = Array.prototype.filter.call(w.querySelectorAll(FOCUSABLE), function (el) { return !el.closest('.nuDesignOverlay, .nuDesignHandle, label'); });
			if (!stops.length) return;
			var b = document.createElement('span');
			b.className = 'nuDesignOrder';
			b.textContent = String(++n);
			if (w.getBoundingClientRect().width < 48) b.classList.add('nuDesignOrderOut');   // e.g. a checkbox: the number next to it
			w.appendChild(b);
		});
	}

	// new object order from the builder (Arrange panel Up / Down, 0.8.608): the objects change places in the page, each parent keeps
	// its slots – absolute objects do not move on screen, but Tab and the tab order numbers follow the new order
	function reorder(ids) {
		var rank = {};
		ids.forEach(function (id, i) { rank[id] = i; });
		var parents = [];
		wraps.forEach(function (w) { if (w.parentElement && parents.indexOf(w.parentElement) < 0) parents.push(w.parentElement); });
		parents.forEach(function (p) {
			var kids = Array.prototype.filter.call(p.children, function (c) { return wraps.indexOf(c) >= 0 && idOf(c) in rank; });
			if (kids.length < 2) return;
			var sorted = kids.slice().sort(function (a, b) { return rank[idOf(a)] - rank[idOf(b)]; });
			if (sorted.every(function (w, i) { return w === kids[i]; })) return;
			var marks = kids.map(function (w) { var m = document.createComment(''); p.insertBefore(m, w); return m; });
			marks.forEach(function (m, i) { p.replaceChild(sorted[i], m); });
		});
		if (document.documentElement.classList.contains('nuShowTabOrder')) showTabOrder(true);
	}

	// the builder selects objects from its list
	window.addEventListener('message', function (e) {
		if (e.origin === origin && e.data && 'nuBuilderTabOrder' in e.data) { showTabOrder(e.data.nuBuilderTabOrder); return; }
		if (e.origin === origin && e.data && Array.isArray(e.data.nuBuilderOrder)) { reorder(e.data.nuBuilderOrder); return; }
		if (e.origin !== origin || !e.data || !e.data.nuBuilderSelect) return;
		var w = document.getElementById('nuw_' + e.data.nuBuilderSelect);
		if (!w) return;
		var pane = w.closest('.tab-pane');
		if (pane && !pane.classList.contains('active') && window.nuSelectTab) window.nuSelectTab(+pane.id.replace('nuTabPane', ''));
		if (sel.indexOf(w) < 0 || sel.length < 2) select(w, false);
		w.scrollIntoView({block: 'nearest'});
	});

	// ----------------------------------------------------------------- geometry of several objects (absolute layout)
	function geo(w) {
		return {id: idOf(w), top: parseInt(w.style.top, 10) || 0, left: parseInt(w.style.left, 10) || 0, width: parseInt(w.style.width, 10) || w.offsetWidth,
			height: w.style.height ? parseInt(w.style.height, 10) || w.offsetHeight : null};
	}
	function sendBatch(list) {
		var items = list.map(function (w) {
			var g = geo(w), it = {id: g.id, top: g.top, left: g.left, width: g.width};
			if (g.height !== null) it.height = g.height;
			return it;
		});
		if (items.length) send({nuDesignerBatch: items});
	}
	var batchT = null;
	function sendLater() {
		clearTimeout(batchT);
		var list = sel.slice();
		batchT = setTimeout(function () { sendBatch(list); }, 400);
	}

	// ----------------------------------------------------------------- move
	function startMove(e, w) {
		if (e.button !== 0) return;
		e.preventDefault();
		window.focus();                                               // preventDefault keeps the focus outside: arrow keys need it here
		if (absolute && mod(e)) { toggle(w); return; }                // Ctrl / ⌘ + click: add / remove (0.8.606)
		if (!absolute || sel.indexOf(w) < 0) select(w, true);
		else { selected = w; paint(); send({nuBuilderPick: idOf(w)}); }
		var sx = e.clientX, sy = e.clientY, moved = false;
		if (absolute) {
			var group = sel.slice(), start = group.map(function (x) { return [x.offsetTop, x.offsetLeft]; });
			drag(function (ev) {
				var dx = ev.clientX - sx, dy = ev.clientY - sy;
				if (!moved && Math.abs(dx) + Math.abs(dy) < 3) return;
				moved = true;
				// snap the movement, not the position: existing pixel alignment of migrated forms is kept
				group.forEach(function (x, i) {
					x.style.left = Math.max(0, start[i][1] + snap(dx, ev)) + 'px';
					x.style.top = Math.max(0, start[i][0] + snap(dy, ev)) + 'px';
				});
			}, function () {
				if (!moved) return;
				if (group.length === 1) send({nuDesigner: {id: idOf(w), top: parseInt(w.style.top, 10), left: parseInt(w.style.left, 10)}});
				else sendBatch(group);
			});
			return;
		}
		// flow layouts: find a drop target under the pointer
		var ghost = null, target = null, side = null;
		drag(function (ev) {
			if (!moved && Math.abs(ev.clientX - sx) + Math.abs(ev.clientY - sy) < 6) return;
			if (!moved) {
				moved = true;
				w.classList.add('nuDesignDragging');
				ghost = document.createElement('div');
				ghost.className = 'nuDesignGhost';
				ghost.textContent = idOf(w);
				document.body.appendChild(ghost);
			}
			ghost.style.left = (ev.clientX + 12) + 'px';
			ghost.style.top = (ev.clientY + 12) + 'px';
			clearMarks();
			var el = document.elementFromPoint(ev.clientX, ev.clientY);
			var t = el && el.closest ? el.closest('#nuEditForm .nuWrap') : null;
			if (t && (t === w || w.contains(t) || t.closest('.nuSubformCard'))) t = null;
			target = t;
			if (t) {
				var r = t.getBoundingClientRect();
				var isBox = t.classList.contains('nuWrap_contentbox');
				side = isBox && ev.clientY > r.top + 30 ? 'into' : (ev.clientY > r.bottom - r.height * 0.25 ? 'below' : (ev.clientX < r.left + r.width / 2 ? 'before' : 'after'));
				t.classList.add('nuDrop-' + side);
			}
		}, function () {
			clearMarks();
			w.classList.remove('nuDesignDragging');
			if (ghost) ghost.remove();
			if (moved && target) send({nuDesignerMove: {id: idOf(w), target: idOf(target), side: side}});
		});
	}

	function clearMarks() {
		document.querySelectorAll('.nuDrop-before, .nuDrop-after, .nuDrop-below, .nuDrop-into').forEach(function (x) {
			x.classList.remove('nuDrop-before', 'nuDrop-after', 'nuDrop-below', 'nuDrop-into');
		});
	}

	// ----------------------------------------------------------------- box selection on an empty place (absolute layout, 0.8.606)
	if (absolute) {
		document.addEventListener('pointerdown', function (e) {
			if (e.button !== 0 || e.target.closest('.nuDesignOverlay, .nuDesignHandle, .nuArrange, .nav, a, button, input, select, textarea')) return;
			var pane = activePane();
			if (!pane || !pane.contains(e.target)) return;
			e.preventDefault();
			window.focus();
			var keep = mod(e) ? sel.slice() : [];
			var sx = e.clientX, sy = e.clientY, box = null;
			drag(function (ev) {
				if (!box) {
					if (Math.abs(ev.clientX - sx) + Math.abs(ev.clientY - sy) < 4) return;
					box = document.createElement('div');
					box.className = 'nuDesignBox';
					document.body.appendChild(box);
				}
				var l = Math.min(sx, ev.clientX), t = Math.min(sy, ev.clientY);
				box.style.left = l + 'px'; box.style.top = t + 'px';
				box.style.width = Math.abs(ev.clientX - sx) + 'px'; box.style.height = Math.abs(ev.clientY - sy) + 'px';
			}, function (ev) {
				if (!box) { if (!keep.length) select(null, false); return; }
				var r = {l: Math.min(sx, ev.clientX), t: Math.min(sy, ev.clientY), r: Math.max(sx, ev.clientX), b: Math.max(sy, ev.clientY)};
				box.remove();
				var hit = wraps.filter(function (w) {
					if (!visible(w) || !pane.contains(w)) return false;
					var q = w.getBoundingClientRect();
					return q.left < r.r && q.right > r.l && q.top < r.b && q.bottom > r.t;
				});
				sel = keep.concat(hit.filter(function (w) { return keep.indexOf(w) < 0; }));
				selected = sel[sel.length - 1] || null;
				paint();
				if (selected) send({nuBuilderPick: idOf(selected)});
			});
		});
	}

	// ----------------------------------------------------------------- resize
	function startResize(e, w) {
		if (e.button !== 0) return;
		e.preventDefault();
		e.stopPropagation();
		select(w, true);
		var sx = e.clientX, sy = e.clientY, w0 = w.offsetWidth, h0 = w.offsetHeight;
		if (absolute) {
			var hasHeight = !!w.style.height;
			drag(function (ev) {
				setSize(w, Math.max(10, w0 + snap(ev.clientX - sx, ev)), hasHeight ? Math.max(10, h0 + snap(ev.clientY - sy, ev)) : null);
			}, function () {
				var msg = {id: idOf(w), width: parseInt(w.style.width, 10)};
				if (hasHeight) msg.height = parseInt(w.style.height, 10);
				send({nuDesigner: msg});
			});
			return;
		}
		var row = w.parentElement, colW = row.getBoundingClientRect().width / 12;
		var m = /\bcol-md-(\d+)\b/.exec(w.className);
		var span0 = m ? +m[1] : Math.max(1, Math.min(12, Math.round(w0 / colW))), span = span0;
		drag(function (ev) {
			span = Math.max(1, Math.min(12, span0 + Math.round((ev.clientX - sx) / colW)));
			w.className = w.className.replace(/\bcol-md-\d+\b/, 'col-md-' + span);
			w.setAttribute('data-span', span);
		}, function () {
			if (span !== span0) send({nuDesignerSpan: {id: idOf(w), span: span}});
		});
	}

	// new size of an object (absolute layout): the wrapper and the control inside it change together, so the preview shows the new size at
	// once – controls with a fixed height / width in px (run buttons, text areas, editors, HTML, charts, lookups) take the same difference (0.8.609)
	function setSize(w, width, height) {
		var dw = width === null ? 0 : width - w.offsetWidth, dh = height === null ? 0 : height - w.offsetHeight;
		if (width !== null) w.style.width = width + 'px';
		if (height !== null) w.style.height = height + 'px';
		Array.prototype.forEach.call(w.children, function (c) {
			if (/\bnuDesign(Overlay|Handle|Order)\b|\bnuLabel\b|\bnuWrap\b/.test(c.className) || c.tagName === 'LABEL') return;   // the label keeps its size
			if (dw && /px$/.test(c.style.width)) c.style.width = Math.max(10, parseFloat(c.style.width) + dw) + 'px';
			if (dh && /px$/.test(c.style.height)) c.style.height = Math.max(10, parseFloat(c.style.height) + dh) + 'px';
		});
	}

	function drag(onMove, onEnd) {
		function mv(ev) { onMove(ev); }
		function up(ev) {
			document.removeEventListener('pointermove', mv);
			document.removeEventListener('pointerup', up);
			onEnd(ev);
		}
		document.addEventListener('pointermove', mv);
		document.addEventListener('pointerup', up);
	}

	// ----------------------------------------------------------------- keyboard (absolute layout)
	function onKey(e) {
		if (!absolute) return;
		var tag = e.target && e.target.tagName;
		if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || (e.target && e.target.isContentEditable)) return;
		if (mod(e) && (e.key === 'a' || e.key === 'A')) {               // all objects of the tab (0.8.606)
			e.preventDefault();
			var pane = activePane();
			sel = wraps.filter(function (w) { return visible(w) && pane.contains(w); });
			selected = sel[sel.length - 1] || null;
			paint();
			return;
		}
		if (e.key === 'Escape' && sel.length) { select(null, false); return; }
		if (!sel.length || ['ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight'].indexOf(e.key) < 0) return;
		e.preventDefault();
		var step = e.altKey ? 1 : GRID;
		var dx = e.key === 'ArrowRight' ? step : e.key === 'ArrowLeft' ? -step : 0;
		var dy = e.key === 'ArrowDown' ? step : e.key === 'ArrowUp' ? -step : 0;
		sel.forEach(function (w) {
			if (e.shiftKey) {                                             // Shift: size, as nuBuilder Forte (0.8.606)
				setSize(w, dx ? Math.max(10, w.offsetWidth + dx) : null, dy && w.style.height ? Math.max(10, w.offsetHeight + dy) : null);
			} else {
				w.style.top = Math.max(0, w.offsetTop + dy) + 'px';
				w.style.left = Math.max(0, w.offsetLeft + dx) + 'px';
			}
		});
		sendLater();
	}
	document.addEventListener('keydown', onKey, true);   // capture: before the form runtime's own keys (Esc …)

	// ----------------------------------------------------------------- Arrange panel (absolute layout, 0.8.606)
	var panel = null, btns = {};
	var ACTIONS = [
		['up', 'bi-arrow-up', 1], ['top', 'bi-align-top', 2],
		['down', 'bi-arrow-down', 1], ['bottom', 'bi-align-bottom', 2],
		['vertical', 'bi-distribute-vertical', 3], ['left', 'bi-align-start', 2],
		['horizontal', 'bi-distribute-horizontal', 3], ['right', 'bi-align-end', 2],
		['shortest', 'bi-arrows-collapse', 2], ['thinnest', 'bi-arrows-collapse-vertical', 2],
		['tallest', 'bi-arrows-expand', 2], ['widest', 'bi-arrows-expand-vertical', 2]
	];
	function buildPanel() {
		panel = document.createElement('div');
		panel.className = 'nuArrange';
		panel.setAttribute('role', 'toolbar');
		panel.setAttribute('aria-label', lbl('title', 'Arrange'));
		var head = document.createElement('div');
		head.className = 'nuArrangeHead';
		head.title = lbl('drag', '');
		var grip = document.createElement('i');
		grip.className = 'bi bi-grip-vertical nuArrangeGrip';
		grip.setAttribute('aria-hidden', 'true');
		head.appendChild(grip);
		var ttl = document.createElement('span');
		ttl.className = 'nuArrangeTitle';
		head.appendChild(ttl);
		var tip = document.createElement('i');
		tip.className = 'bi bi-info-circle nuArrangeTip';
		tip.title = lbl('hint', '');
		tip.setAttribute('aria-label', lbl('hint', ''));
		tip.setAttribute('role', 'img');
		head.appendChild(tip);
		panel.appendChild(head);
		movable(head);
		var grid = document.createElement('div');
		grid.className = 'nuArrangeGrid';
		ACTIONS.forEach(function (a) {
			var b = document.createElement('button');
			b.type = 'button';
			b.className = 'btn btn-sm btn-outline-secondary nuArrangeBtn';
			b.setAttribute('data-arrange', a[0]);
			var i = document.createElement('i');
			i.className = 'bi ' + a[1] + ' me-1';
			i.setAttribute('aria-hidden', 'true');
			b.appendChild(i);
			b.appendChild(document.createTextNode(lbl(a[0], a[0])));
			b.addEventListener('click', function () { arrange(a[0]); });
			btns[a[0]] = [b, a[2]];
			grid.appendChild(b);
		});
		panel.appendChild(grid);
		document.body.appendChild(panel);
	}
	// the panel can be dragged by its title row (0.8.607); the place is kept per browser, double-click = back to the corner
	var POS_KEY = 'nunext-arrange-pos';
	function placePanel(pos) {
		if (!pos) { panel.style.left = ''; panel.style.top = ''; panel.style.right = ''; return; }
		var w = panel.offsetWidth || 250, h = panel.offsetHeight || 40;
		var l = Math.max(0, Math.min(pos.l, window.innerWidth - w)), t = Math.max(0, Math.min(pos.t, window.innerHeight - Math.min(h, 40)));
		panel.style.left = l + 'px'; panel.style.top = t + 'px'; panel.style.right = 'auto';
	}
	function savedPos() {
		try { var v = JSON.parse(window.localStorage.getItem(POS_KEY) || 'null'); return v && isFinite(v.l) && isFinite(v.t) ? v : null; } catch (e) { return null; }
	}
	function savePos(v) {
		try { if (v) window.localStorage.setItem(POS_KEY, JSON.stringify(v)); else window.localStorage.removeItem(POS_KEY); } catch (e) { /* no storage */ }
	}
	function movable(head) {
		head.addEventListener('pointerdown', function (e) {
			if (e.button !== 0 || e.target.closest('.nuArrangeTip')) return;
			e.preventDefault();
			e.stopPropagation();
			var r = panel.getBoundingClientRect(), dx = e.clientX - r.left, dy = e.clientY - r.top;
			panel.classList.add('nuArrangeMoving');
			drag(function (ev) { placePanel({l: ev.clientX - dx, t: ev.clientY - dy}); }, function () {
				panel.classList.remove('nuArrangeMoving');
				savePos({l: parseInt(panel.style.left, 10) || 0, t: parseInt(panel.style.top, 10) || 0});
			});
		});
		head.addEventListener('dblclick', function () { savePos(null); placePanel(null); });
		window.addEventListener('resize', function () { var v = savedPos(); if (v) placePanel(v); });
	}

	function panelState() {
		if (!absolute) return;
		if (!panel) buildPanel();
		var was = panel.classList.contains('nuArrangeOn');
		panel.classList.toggle('nuArrangeOn', sel.length > 0);
		if (!was && sel.length) placePanel(savedPos());
		panel.querySelector('.nuArrangeTitle').textContent = lbl('title', 'Arrange') + ' · ' + sel.length;
		Object.keys(btns).forEach(function (k) { btns[k][0].disabled = sel.length < btns[k][1]; });
	}

	function arrange(what) {
		if (!sel.length) return;
		if (what === 'up' || what === 'down') { send({nuDesignerOrder: {ids: sel.map(idOf), dir: what === 'up' ? -1 : 1}}); return; }
		var g = sel.map(function (w) { return {w: w, t: w.offsetTop, l: w.offsetLeft, wd: w.offsetWidth, ht: w.offsetHeight, hh: !!w.style.height}; });
		var min = function (f) { return Math.min.apply(null, g.map(f)); }, max = function (f) { return Math.max.apply(null, g.map(f)); };
		if (what === 'top') { var t0 = min(function (x) { return x.t; }); g.forEach(function (x) { x.w.style.top = t0 + 'px'; }); }
		if (what === 'bottom') { var b0 = max(function (x) { return x.t + x.ht; }); g.forEach(function (x) { x.w.style.top = (b0 - x.ht) + 'px'; }); }
		if (what === 'left') { var l0 = min(function (x) { return x.l; }); g.forEach(function (x) { x.w.style.left = l0 + 'px'; }); }
		if (what === 'right') { var r0 = max(function (x) { return x.l + x.wd; }); g.forEach(function (x) { x.w.style.left = (r0 - x.wd) + 'px'; }); }
		if (what === 'thinnest' || what === 'widest') {
			var wd = what === 'thinnest' ? min(function (x) { return x.wd; }) : max(function (x) { return x.wd; });
			g.forEach(function (x) { setSize(x.w, wd, null); });
		}
		if (what === 'shortest' || what === 'tallest') {         // objects with their own height (textareas, HTML, images …)
			var hs = g.filter(function (x) { return x.hh; });
			if (hs.length) {
				var ht = what === 'shortest' ? Math.min.apply(null, hs.map(function (x) { return x.ht; })) : Math.max.apply(null, hs.map(function (x) { return x.ht; }));
				hs.forEach(function (x) { setSize(x.w, null, ht); });
			}
		}
		if (what === 'vertical' || what === 'horizontal') {
			// as nuBuilder Forte: the first and the last stay, the gaps between the objects become equal (overlapping: equal steps)
			var v = what === 'vertical', pos = v ? 't' : 'l', size = v ? 'ht' : 'wd', prop = v ? 'top' : 'left';
			g.sort(function (a, b) { return a[pos] - b[pos]; });
			var gaps = 0, steps = 0;
			for (var i = 1; i < g.length; i++) { gaps += g[i][pos] - (g[i - 1][pos] + g[i - 1][size]); steps += g[i][pos] - g[i - 1][pos]; }
			var gap = Math.round(gaps / (g.length - 1)), step = Math.round(steps / (g.length - 1)), at = g[0][pos];
			for (var j = 1; j < g.length; j++) {
				at = gap < 0 ? at + step : at + g[j - 1][size] + gap;
				g[j].w.style[prop] = at + 'px';
			}
		}
		sendBatch(sel);
	}
	panelState();
})();
