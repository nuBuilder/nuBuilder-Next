/* nuFiles – end of the password confirmation window: tell the file manager and close (CSP: no inline script). */
(function () {
	'use strict';
	try {
		if (window.opener && window.opener.location.origin === window.location.origin) {
			window.opener.postMessage({nufReauth: 1}, window.location.origin);
			setTimeout(function () { window.close(); }, 400);
		}
	} catch (e) { /* other origin or no opener: the text asks to close the window */ }
}());
