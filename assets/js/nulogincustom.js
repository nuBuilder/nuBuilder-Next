/* nuBuilder Next – login page from $nuWelcomeBodyInnerHTML (nuconfig.php).
 * The HTML may be nuBuilder's: inputs #nuusername / #nupassword without name, <form action="#" onsubmit="return false">,
 * onclick="nuLoginRequest()". Inline handlers are blocked by the Content-Security-Policy, so this file turns that markup
 * into a normal POST to nuBuilder Next's login: fields named username / password, CSRF token, Enter and the button submit. */
(function () {
	'use strict';
	var body = document.body;
	var pw0 = document.querySelector('input[type=password]');
	var form = document.getElementById('nuLoginForm') || (pw0 && pw0.closest('form')) || document.querySelector('form');
	if (!form) return;

	form.setAttribute('method', 'post');
	form.setAttribute('action', body.getAttribute('data-login-url'));
	form.removeAttribute('onsubmit');
	form.removeAttribute('target');

	if (!form.querySelector('input[name="_csrf"]')) {
		var t = document.createElement('input');
		t.type = 'hidden'; t.name = '_csrf'; t.value = body.getAttribute('data-csrf') || '';
		form.appendChild(t);
	}

	var user = form.querySelector('[name="username"]') || form.querySelector('#nuusername') ||
		form.querySelector('input:not([type]), input[type=text], input[type=email]');
	var pass = form.querySelector('[name="password"]') || form.querySelector('#nupassword') || form.querySelector('input[type=password]');
	if (user) {
		user.name = 'username';
		user.setAttribute('autocomplete', 'username');
		user.setAttribute('autocapitalize', 'off');
		user.setAttribute('spellcheck', 'false');
		if (!user.value) user.value = body.getAttribute('data-login-user') || '';
	}
	if (pass) {
		pass.name = 'password';
		pass.setAttribute('autocomplete', 'current-password');
	}

	// nuBuilder's "SSO Log in" button: nuBuilder Next has no SSO, so it is hidden instead of sending a normal login
	var sso = document.getElementById('submitSSO');
	if (sso) sso.hidden = true;

	// buttons with onclick="nuLoginRequest()" and type="button" submit the form
	form.querySelectorAll('button, input[type=button], input[type=submit]').forEach(function (b) {
		if (b === sso) return;
		b.removeAttribute('onclick');
		if (b.type === 'button') b.addEventListener('click', function () { form.requestSubmit ? form.requestSubmit() : form.submit(); });
	});
	if (pass) pass.removeAttribute('onkeypress');   // Enter submits the form natively

	var focus = user && !user.value ? user : pass;
	if (focus) focus.focus();
})();
