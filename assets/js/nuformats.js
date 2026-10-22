/* Formats page: opens the format designer for a row (saved format) or "New format" (also from a built-in row). */
(function () {
	'use strict';
	var el = document.getElementById('nuFormatData');
	if (!el || !window.NuFormatDesigner) return;
	NuFormatDesigner.init(JSON.parse(el.textContent));
	function reload() { window.location.reload(); }
	var nb = document.getElementById('fmtNew');
	if (nb) nb.addEventListener('click', function () { NuFormatDesigner.open({onSaved: reload}); });
	function openRow(tr) {
		if (tr.hasAttribute('data-format-id')) NuFormatDesigner.open({id: tr.getAttribute('data-format-id'), onSaved: reload, onDeleted: reload});
		else if (tr.hasAttribute('data-format-key')) {
			var k = tr.getAttribute('data-format-key');
			NuFormatDesigner.open({type: {D: 'Date', N: 'Number', T: 'Text'}[k.charAt(0)], format: k.substring(2), onSaved: reload});
		}
	}
	document.querySelectorAll('#fmtTable tbody tr').forEach(function (tr) {
		tr.addEventListener('click', function () { openRow(tr); });
		tr.addEventListener('keydown', function (e) { if (e.key === 'Enter') openRow(tr); });
	});
})();
