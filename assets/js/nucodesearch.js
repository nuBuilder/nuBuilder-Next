/*
 * Code search (pages/nucodesearch.php): quick searches (function / table / hash variable → whole word, exact text; functions
 * nuBuilder Next lacks → one regular expression over their names), expand / collapse the groups, Save search…, Esc clears the field.
 */
(function () {
	'use strict';
	var form = document.getElementById('csForm');
	if (!form) return;
	var q = document.getElementById('csQ');
	function setChk(id, on) { var e = document.getElementById(id); if (e) e.checked = on; }
	function setMode(m) { var e = document.getElementById('csM_' + m); if (e) e.checked = true; }
	function setLang(list) {
		var s = document.getElementById('csLang');
		if (!s) return;
		if (s.tomselect) s.tomselect.setValue(list, true);
		else Array.prototype.forEach.call(s.options, function (o) { o.selected = list.indexOf(o.value) >= 0; });
	}
	function go(text, regex, langs) {
		q.value = text;
		setMode('phrase');
		setChk('csW', !regex);
		setChk('csR', !!regex);
		setChk('csC', false);
		if (langs) setLang(langs);
		form.requestSubmit ? form.requestSubmit() : form.submit();
	}
	function quick(id, fn) {
		var s = document.getElementById(id);
		if (!s) return;
		s.addEventListener('change', function () {
			if (!s.value) return;
			var opt = s.options[s.selectedIndex];
			fn(s.value, opt);
		});
	}
	quick('csQuickFn', function (v, opt) { go(v, false, [opt && opt.getAttribute('data-lang') === 'php' ? 'php' : 'js']); });
	quick('csQuickTable', function (v) { go(v, false, []); });
	quick('csQuickHash', function (v) {
		// #NAME# – "whole word" would need letters around the #: plain exact text instead
		q.value = v; setMode('phrase'); setChk('csW', false); setChk('csR', false); setChk('csC', false); setLang([]);
		form.requestSubmit ? form.requestSubmit() : form.submit();
	});
	// "Functions nuBuilder Next lacks" is a search the server builds (preset=missing): typing a text of your own ends it
	var preset = document.getElementById('csPreset');
	if (preset) q.addEventListener('input', function () {
		if (preset.parentNode) preset.parentNode.removeChild(preset);
		var chip = document.getElementById('csPresetChip');
		if (chip) chip.remove();
	});
	// groups: expand / collapse all
	var tog = document.getElementById('csToggleAll');
	if (tog) tog.addEventListener('click', function () {
		var groups = document.querySelectorAll('.csGroup');
		var anyOpen = Array.prototype.some.call(groups, function (d) { return d.open; });
		Array.prototype.forEach.call(groups, function (d) { d.open = !anyOpen; });
		tog.textContent = anyOpen ? tog.getAttribute('data-expand') : tog.getAttribute('data-collapse');
	});
	// Save search…
	var so = document.getElementById('csSaveOpen'), sf = document.getElementById('csSaveForm');
	if (so && sf) so.addEventListener('click', function () {
		sf.classList.remove('d-none');
		var n = document.getElementById('csSaveName');
		if (n) { n.focus(); n.select(); }
	});
	// search and replace: the field is sent only while its row is open; the button counts the ticked lines
	var rpRow = document.getElementById('csRpRow'), rp = document.getElementById('csRp'), rm = document.getElementById('csRmode');
	if (rpRow && rp && rm) {
		rpRow.addEventListener('shown.bs.collapse', function () { rp.disabled = false; rm.disabled = false; rp.focus(); });
		rpRow.addEventListener('hidden.bs.collapse', function () { rp.disabled = true; rm.disabled = true; });
		rp.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); form.requestSubmit ? form.requestSubmit() : form.submit(); } });
	}
	var go2 = document.getElementById('csRpGo');
	function count() {
		if (!go2) return;
		// an unticked line: its marks struck through and its new line hidden (class csRpOff – nuapp.css)
		Array.prototype.forEach.call(document.querySelectorAll('.csRpTick'), function (c) {
			var hit = c.closest('.csHit');
			if (hit) hit.classList.toggle('csRpOff', !c.checked);
		});
		var n = document.querySelectorAll('.csRpTick:checked').length;
		go2.textContent = (go2.getAttribute('data-label') || '%d').replace('%d', String(n));
		go2.disabled = n === 0;
	}
	document.addEventListener('change', function (e) { if (e.target && e.target.classList && e.target.classList.contains('csRpTick')) count(); });
	function tickAll(on) { Array.prototype.forEach.call(document.querySelectorAll('.csRpTick'), function (c) { c.checked = on; }); count(); }
	var all = document.getElementById('csRpAll'), none = document.getElementById('csRpNone');
	if (all) all.addEventListener('click', function () { tickAll(true); });
	if (none) none.addEventListener('click', function () { tickAll(false); });
	count();
	q.addEventListener('keydown', function (e) { if (e.key === 'Escape' && q.value) { q.value = ''; e.preventDefault(); } });
})();

// phones (the owner picked mockup A of the canvas "Code search on phones"): one search row – field · Options · Filters (count).
// Below 576 px the options (match mode, whole word, case, regular expression, Without, quick searches) and the filters are moved into
// two bottom sheets (#csOptSheet / #csFiltSheet – inside the form, so they are still sent); back to their places from 576 px on.
// Closing a sheet after a change searches again when there is something to search for.
(function () {
	'use strict';
	var form = document.getElementById('csForm'), optS = document.getElementById('csOptSheet'), filtS = document.getElementById('csFiltSheet');
	if (!form || !optS || !filtS || !window.nuBs) return;
	var mq = window.matchMedia('(max-width: 575.98px)'), moved = [], changed = false;
	function park(el, sheet) {
		var mark = document.createComment('cs-home');
		el.parentNode.insertBefore(mark, el);
		sheet.querySelector('.modal-body').appendChild(el);
		moved.push([el, mark]);
	}
	function apply() {
		if (mq.matches && !moved.length) {
			Array.prototype.forEach.call(form.querySelectorAll('[data-cs-opt]'), function (el) { park(el, optS); });
			var f = document.getElementById('csFilters');
			if (f) { park(f, filtS); f.classList.add('show', 'csInSheet'); }
		} else if (!mq.matches && moved.length) {
			moved.forEach(function (m) { m[1].parentNode.insertBefore(m[0], m[1]); m[1].remove(); });
			var f2 = document.getElementById('csFilters');
			if (f2) {
				f2.classList.remove('csInSheet');
				var btn = form.querySelector('.csFiltersBtn');
				if (btn && btn.getAttribute('aria-expanded') !== 'true') f2.classList.remove('show');
			}
			moved = [];
			[optS, filtS].forEach(function (s) { if (s.classList.contains('show')) nuBs.modal(s).hide(); });
		}
	}
	apply();
	if (mq.addEventListener) mq.addEventListener('change', apply);
	document.getElementById('csOptBtn').addEventListener('click', function () { changed = false; nuBs.modal(optS).show(); });
	document.getElementById('csFiltBtn').addEventListener('click', function () { changed = false; nuBs.modal(filtS).show(); });
	[optS, filtS].forEach(function (s) {
		s.addEventListener('change', function () { changed = true; });
		s.addEventListener('input', function () { changed = true; });
		s.addEventListener('hidden.bs.modal', function () {
			var q = document.getElementById('csQ');
			if (changed && mq.matches && (q && q.value.trim() !== '' || document.getElementById('csPreset'))) {
				changed = false;
				form.requestSubmit ? form.requestSubmit() : form.submit();
			}
		});
	});
})();
