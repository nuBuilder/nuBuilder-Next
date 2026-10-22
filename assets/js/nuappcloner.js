/* App cloner (pages/nuappcloner.php): show / hide the parts that apply, table filter, confirmation before a real run and before Delete. */
(function () {
	'use strict';
	var form = document.getElementById('acForm');
	if (!form) return;
	var $ = function (s) { return document.querySelector(s); };
	function sync() {
		$('#acLogin').hidden = $('#acSame').checked;
		$('#acTablesBox').hidden = $('#acAll').checked;
		$('#acFilesBox').hidden = !$('#acFiles').checked;
	}
	form.addEventListener('change', sync);
	sync();

	var rows = Array.prototype.slice.call(document.querySelectorAll('.nuAcTable'));
	$('#acTableFilter').addEventListener('input', function () {
		var q = this.value.trim().toLowerCase();
		rows.forEach(function (r) { r.classList.toggle('d-none', q !== '' && r.getAttribute('data-name').indexOf(q) < 0); });
	});
	$('#acTableFilter').addEventListener('keydown', function (e) { if (e.key === 'Enter') e.preventDefault(); });
	var mark = function (on) { rows.forEach(function (r) { if (!r.classList.contains('d-none')) r.querySelector('input').checked = on; }); };
	$('#acTablesOn').addEventListener('click', function () { mark(true); });
	$('#acTablesOff').addEventListener('click', function () { mark(false); });

	// a real run changes another database / folder: ask once (with what will be emptied)
	form.addEventListener('submit', function (e) {
		var b = e.submitter;
		if (b && b.hasAttribute('data-confirm-submit')) {                 // Delete (saved configuration)
			if (!window.confirm(b.getAttribute('data-confirm-submit'))) e.preventDefault();
			return;
		}
		if (!b || b.value !== 'run') return;
		var msg = form.getAttribute('data-confirm-run') || 'Run?';
		var extra = [];
		if ($('#acMode').value === 'clear') extra.push('• ' + $('#acMode').selectedOptions[0].textContent + ': ' + $('#acDb').value);
		if ($('#acFiles').checked && $('#acFMode').value === 'clear') extra.push('• ' + $('#acFMode').selectedOptions[0].textContent + ': ' + $('#acTgt').value);
		if (!window.confirm(msg + (extra.length ? '\n\n' + extra.join('\n') : ''))) e.preventDefault();
	});
})();
