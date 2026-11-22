/* Example plugin: a script on every page (strict CSP – files only, no inline code). */
(function () {
	'use strict';
	document.documentElement.setAttribute('data-example-plugin', '1');
})();
