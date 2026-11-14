/*
 * Code search (0.8.1172, pages/nucodesearch.php): quick searches (function / table / hash variable → whole word, exact text; functions
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
	// "Functions nuBuilder Next lacks" is a search the server builds (preset=missing, 0.8.1173): typing a text of your own ends it
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
	// search and replace (0.8.1174): the field is sent only while its row is open; the button counts the ticked lines
	var rpRow = document.getElementById('csRpRow'), rp = document.getElementById('csRp'), rm = document.getElementById('csRmode');
	if (rpRow && rp && rm) {
		rpRow.addEventListener('shown.bs.collapse', function () { rp.disabled = false; rm.disabled = false; rp.focus(); });
		rpRow.addEventListener('hidden.bs.collapse', function () { rp.disabled = true; rm.disabled = true; });
		rp.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); form.requestSubmit ? form.requestSubmit() : form.submit(); } });
	}
	var go2 = document.getElementById('csRpGo');
	function count() {
		if (!go2) return;
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
