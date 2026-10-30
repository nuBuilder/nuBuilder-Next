/* nuBuilder Next – password policy checklist (0.8.745, NuPwPolicy::rulesHtml): ticks length and character classes while typing.
   Leaked passwords and the login / name rule are checked on saving. */
(function () {
	'use strict';
	function setup(box) {
		var field = document.getElementById(box.getAttribute('data-for'));
		if (!field || box.nuPwDone) return;
		box.nuPwDone = true;
		var min = +box.getAttribute('data-min') || 8;
		var tests = {
			len: function (v) { return Array.from(v).length >= min; },
			upper: function (v) { return /\p{Lu}/u.test(v); },
			lower: function (v) { return /\p{Ll}/u.test(v); },
			digit: function (v) { return /\d/.test(v); },
			symbol: function (v) { return /[^\p{L}\p{N}]/u.test(v); }
		};
		function run() {
			var v = field.value;
			box.classList.toggle('nuPwTyping', v !== '');
			box.querySelectorAll('li[data-rule]').forEach(function (li) {
				var ok = v !== '' && tests[li.getAttribute('data-rule')](v);
				li.classList.toggle('nuPwOk', ok);
				li.classList.toggle('nuPwBad', v !== '' && !ok);
				li.querySelector('i').className = 'bi ' + (v === '' ? 'bi-dot' : (ok ? 'bi-check-circle-fill' : 'bi-x-circle')) ;
			});
		}
		field.addEventListener('input', run);
		run();
	}
	function all() { document.querySelectorAll('.nuPwRules[data-for]').forEach(setup); }
	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', all); else all();
})();
