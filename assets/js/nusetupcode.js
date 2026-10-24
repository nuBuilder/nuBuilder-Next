/* Admin tools → Setup code (pages/nusetupcode.php, 0.8.444): a CodeMirror editor per tab (JavaScript, CSS, HTML); the open tab is sent with
 * Save, so the page comes back to it. Tabs in the address: nuapp.js (data-nu-hash-tabs). */
(function () {
	'use strict';
	var form = document.getElementById('scodeForm');
	if (!form) return;
	var editors = [];
	if (window.CodeMirror) {
		Array.prototype.forEach.call(form.querySelectorAll('textarea.scodeCode'), function (ta) {
			var ro = ta.hasAttribute('readonly');
			var cm = CodeMirror.fromTextArea(ta, {mode: ta.getAttribute('data-mode') || 'javascript', lineNumbers: true, lineWrapping: true, matchBrackets: true,
				autoCloseBrackets: true, indentUnit: 4, tabSize: 4, indentWithTabs: true, readOnly: ro ? 'nocursor' : false});
			cm.getWrapperElement().classList.add('scodeCM');
			cm.on('change', function () { cm.save(); ta.dispatchEvent(new Event('input', {bubbles: true})); });   // red Save, leave guard
			editors.push(cm);
		});
	}
	var tabs = document.getElementById('scodeTabs'), field = document.getElementById('scodeTab');
	function current() { var a = tabs && tabs.querySelector('.nav-link.active'); return a ? a.getAttribute('data-nu-tab') || '' : ''; }
	if (tabs) tabs.addEventListener('shown.bs.tab', function () { editors.forEach(function (cm) { cm.refresh(); }); field.value = current(); });   // hidden editors have no size
	form.addEventListener('submit', function () { editors.forEach(function (cm) { cm.save(); }); field.value = current(); });
	field.value = current();
})();
