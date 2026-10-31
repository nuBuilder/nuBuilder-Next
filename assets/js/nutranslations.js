/* Translations → Missing with a form (0.8.754): copy the AI prompt (POST action=ai_prompt → JSON), select all rows of the answer's preview. */
(function () {
	'use strict';
	var form = document.getElementById('trlAiPromptForm');
	if (form) {
		var msg = document.getElementById('trlAiMsg');
		var out = document.getElementById('trlAiPromptOut');
		var copy = function (text) {
			if (navigator.clipboard && window.isSecureContext) return navigator.clipboard.writeText(text);
			return new Promise(function (resolve, reject) {
				var ta = document.createElement('textarea');
				ta.value = text;
				ta.setAttribute('readonly', '');
				ta.className = 'position-fixed top-0 start-0 opacity-0';
				document.body.appendChild(ta);
				ta.select();
				var ok = false;
				try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
				ta.remove();
				if (ok) resolve(); else reject(new Error('copy'));
			});
		};
		form.addEventListener('submit', function (e) {
			e.preventDefault();
			var btn = document.getElementById('trlAiCopy');
			btn.disabled = true;
			fetch(form.getAttribute('data-url'), {method: 'POST', body: new FormData(form), credentials: 'same-origin', headers: {Accept: 'application/json'}})
				.then(function (r) { return r.json(); })
				.then(function (d) {
					msg.textContent = d.message || '';
					msg.className = 'small ms-2 ' + (d.ok ? 'text-success' : 'text-danger');
					if (!d.ok || !d.prompt) return;
					out.value = d.prompt;
					return copy(d.prompt).then(function () { out.classList.add('d-none'); }, function () {
						out.classList.remove('d-none');        // no clipboard: the prompt to copy by hand
						out.focus();
						out.select();
						msg.textContent = out.getAttribute('data-fail') || msg.textContent;
					});
				})
				.catch(function () { msg.textContent = '⚠'; msg.className = 'small ms-2 text-danger'; })
				.then(function () { btn.disabled = false; });
		});
	}
	var all = document.getElementById('trlAiAll');
	if (all) {
		var picks = function () { return Array.prototype.slice.call(document.querySelectorAll('.trlAiPick')); };
		all.addEventListener('change', function () { picks().forEach(function (c) { c.checked = all.checked; }); });
		picks().forEach(function (c) {
			c.addEventListener('change', function () {
				var p = picks(), n = p.filter(function (x) { return x.checked; }).length;
				all.checked = n === p.length;
				all.indeterminate = n > 0 && n < p.length;
			});
		});
	}
}());
