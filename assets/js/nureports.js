/* nuBuilder Next – report record page (pages/nureports.php): access level list (search, all / none, count) */
(function () {
	'use strict';
	var list = document.getElementById('rptAccessList');
	if (!list) return;
	var count = document.getElementById('rptAccessCount');
	var search = document.getElementById('rptAccessSearch');
	function boxes() { return Array.prototype.slice.call(list.querySelectorAll('input[type=checkbox]')); }
	function update() { if (count) count.textContent = String(boxes().filter(function (b) { return b.checked; }).length); }
	list.addEventListener('change', update);
	if (search) search.addEventListener('input', function () {
		var q = search.value.trim().toLowerCase();
		Array.prototype.forEach.call(list.querySelectorAll('[data-text]'), function (row) { row.classList.toggle('d-none', q !== '' && row.getAttribute('data-text').indexOf(q) < 0); });
	});
	Array.prototype.forEach.call(document.querySelectorAll('[data-access-all]'), function (b) {
		b.addEventListener('click', function () {
			var on = b.getAttribute('data-access-all') === '1';
			boxes().forEach(function (x) { if (!x.closest('.d-none') && x.checked !== on) { x.checked = on; x.dispatchEvent(new Event('change', {bubbles: true})); } });
			update();
		});
	});
})();

/* #access opens the tab Access levels (link of the reports list, 0.8.688). */
(function () {
	'use strict';
	if (location.hash !== '#access' || !window.nuBs || !nuBs.ready()) return;
	var b = document.querySelector('#rptTabs [data-bs-target="#rptTabAccess"]');
	if (b) nuBs.tab(b).show();
})();
