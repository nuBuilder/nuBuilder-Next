/* Setup wizard (pages/nusetupwizard.php, 0.8.543): picking a language switches the wizard to it at once – the step is sent with
   action=lang (the answers of the step stay in the session), the server sets the session language and shows the step again. */
(function () {
	'use strict';
	var sel = document.querySelector('select[data-wiz-lang]');
	if (!sel || !sel.form) return;
	sel.addEventListener('change', function () {
		var f = sel.form, a = document.createElement('input');
		a.type = 'hidden'; a.name = 'action'; a.value = 'lang';
		f.appendChild(a);
		if (window.nuLeaveGuard) window.nuLeaveGuard.leave();
		f.submit();
	});
})();
