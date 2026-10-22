/*
 * Form designer inside the builder preview (index.php?p=form&builder=1).
 *   absolute layout: drag objects to move them, drag the corner handle to resize (5 px grid, Alt = 1 px)
 *   auto / grid layout: drag an object onto another to place it before/after it or into a new row below,
 *                       drag the right handle to change its width (1–12 columns)
 * Changes are sent to the builder window with postMessage; the builder saves them.
 */
(function () {
	'use strict';
	var N = window.nuNext;
	if (!N || !N.builder || N.mode !== 'edit') return;

	var origin = window.location.origin;
	var absolute = N.form.layout === 'absolute';
	var GRID = 5;
	var selected = null;

	function send(msg) { window.parent.postMessage(msg, origin); }
	function idOf(w) { return w.id.replace(/^nuw_/, ''); }
	function snap(v, e) { return e && e.altKey ? Math.round(v) : Math.round(v / GRID) * GRID; }

	document.documentElement.classList.add('nuDesigning');

	// every object gets an overlay that catches the mouse (inputs stay inert while designing)
	document.querySelectorAll('#nuEditForm .nuWrap').forEach(function (w) {
		if (w.closest('.nuSubformCard')) return;
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

	function select(w, notify) {
		if (selected) selected.classList.remove('nuBuilderSelected');
		selected = w;
		if (w) w.classList.add('nuBuilderSelected');
		if (w && notify) send({nuBuilderPick: idOf(w)});
	}

	// the builder selects objects from its list
	window.addEventListener('message', function (e) {
		if (e.origin !== origin || !e.data || !e.data.nuBuilderSelect) return;
		var w = document.getElementById('nuw_' + e.data.nuBuilderSelect);
		if (!w) return;
		var pane = w.closest('.tab-pane');
		if (pane && !pane.classList.contains('active') && window.nuSelectTab) window.nuSelectTab(+pane.id.replace('nuTabPane', ''));
		select(w, false);
		w.scrollIntoView({block: 'nearest'});
	});

	// ----------------------------------------------------------------- move
	function startMove(e, w) {
		if (e.button !== 0) return;
		e.preventDefault();
		select(w, true);
		var sx = e.clientX, sy = e.clientY, moved = false;
		if (absolute) {
			var top0 = w.offsetTop, left0 = w.offsetLeft;
			drag(e, function (ev) {
				var dx = ev.clientX - sx, dy = ev.clientY - sy;
				if (!moved && Math.abs(dx) + Math.abs(dy) < 3) return;
				moved = true;
				// snap the movement, not the position: existing pixel alignment of migrated forms is kept
				w.style.left = Math.max(0, left0 + snap(dx, ev)) + 'px';
				w.style.top = Math.max(0, top0 + snap(dy, ev)) + 'px';
			}, function () {
				if (moved) send({nuDesigner: {id: idOf(w), top: parseInt(w.style.top, 10), left: parseInt(w.style.left, 10)}});
			});
			return;
		}
		// flow layouts: find a drop target under the pointer
		var ghost = null, target = null, side = null;
		drag(e, function (ev) {
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

	// ----------------------------------------------------------------- resize
	function startResize(e, w) {
		if (e.button !== 0) return;
		e.preventDefault();
		e.stopPropagation();
		select(w, true);
		var sx = e.clientX, sy = e.clientY, w0 = w.offsetWidth, h0 = w.offsetHeight;
		if (absolute) {
			var hasHeight = !!w.style.height;
			drag(e, function (ev) {
				w.style.width = Math.max(10, w0 + snap(ev.clientX - sx, ev)) + 'px';
				if (hasHeight) w.style.height = Math.max(10, h0 + snap(ev.clientY - sy, ev)) + 'px';
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
		drag(e, function (ev) {
			span = Math.max(1, Math.min(12, span0 + Math.round((ev.clientX - sx) / colW)));
			w.className = w.className.replace(/\bcol-md-\d+\b/, 'col-md-' + span);
			w.setAttribute('data-span', span);
		}, function () {
			if (span !== span0) send({nuDesignerSpan: {id: idOf(w), span: span}});
		});
	}

	function drag(e, onMove, onEnd) {
		function mv(ev) { onMove(ev); }
		function up(ev) {
			document.removeEventListener('pointermove', mv);
			document.removeEventListener('pointerup', up);
			onEnd(ev);
		}
		document.addEventListener('pointermove', mv);
		document.addEventListener('pointerup', up);
	}

	// keyboard nudging in the absolute layout
	document.addEventListener('keydown', function (e) {
		if (!absolute || !selected || ['ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight'].indexOf(e.key) < 0) return;
		e.preventDefault();
		var step = e.altKey ? 1 : GRID;
		var top = selected.offsetTop + (e.key === 'ArrowDown' ? step : e.key === 'ArrowUp' ? -step : 0);
		var left = selected.offsetLeft + (e.key === 'ArrowRight' ? step : e.key === 'ArrowLeft' ? -step : 0);
		selected.style.top = Math.max(0, top) + 'px';
		selected.style.left = Math.max(0, left) + 'px';
		clearTimeout(selected._nudge);
		var w = selected;
		w._nudge = setTimeout(function () { send({nuDesigner: {id: idOf(w), top: parseInt(w.style.top, 10), left: parseInt(w.style.left, 10)}}); }, 400);
	});
})();
