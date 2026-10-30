/* Webhooks (0.8.715, pages/nuwebhooks.php): copy the secret shown once. */
(function () {
	'use strict';
	var b = document.getElementById('whSecretCopy');
	if (!b) return;
	b.addEventListener('click', function () {
		var inp = document.querySelector(b.getAttribute('data-copy'));
		if (!inp) return;
		inp.select();
		var done = function () { b.querySelector('i').className = 'bi bi-clipboard-check'; };
		if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(inp.value).then(done, function () { document.execCommand('copy'); done(); });
		else { document.execCommand('copy'); done(); }
	});
})();
