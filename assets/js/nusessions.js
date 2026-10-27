/* Logged-in users (pages/nusessions.php): the confirmation of "Log out idle users" names the hours chosen (0.8.595). */
(function () {
	'use strict';
	var f = document.getElementById('sessIdleForm'), sel = document.getElementById('sessIdleHours');
	if (!f || !sel) return;
	var tpl = f.getAttribute('data-confirm-tpl') || '';
	function sync() { if (tpl) f.setAttribute('data-confirm', tpl.replace('99999', sel.value)); }
	sel.addEventListener('change', sync);
	sync();
})();
