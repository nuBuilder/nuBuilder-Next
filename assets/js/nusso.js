/* nuBuilder Next: Settings → Security → Single sign-on (0.8.863, pages/nusso.php) – the provider kind sets the address and button
 * placeholders, rows of the group map are added / removed, the default level shows only while users are created. */
(function () {
	'use strict';
	var kind = document.getElementById('ssoKind');
	var issuer = document.getElementById('ssoIssuer');
	var label = document.getElementById('ssoLabel');
	if (kind) kind.addEventListener('change', function () {
		var o = kind.options[kind.selectedIndex];
		if (issuer) issuer.placeholder = o.getAttribute('data-issuer') || '';
		if (label) label.placeholder = o.getAttribute('data-label') || '';
	});
	var create = document.getElementById('ssoCreate');
	var defWrap = document.getElementById('ssoDefaultWrap');
	function syncCreate() { if (create && defWrap) defWrap.hidden = !create.checked; }
	if (create) create.addEventListener('change', syncCreate);
	syncCreate();
	var body = document.querySelector('#ssoMap tbody');
	var add = document.getElementById('ssoMapAdd');
	function changed() {
		var f = document.getElementById('ssoForm');
		if (f) f.dispatchEvent(new Event('change', { bubbles: true }));
	}
	if (add && body) add.addEventListener('click', function () {
		var last = body.querySelector('tr.ssoMapRow:last-child');
		if (!last) return;
		var row = last.cloneNode(true);
		row.querySelectorAll('input').forEach(function (i) { i.value = ''; });
		row.querySelectorAll('select').forEach(function (s) { s.selectedIndex = 0; });
		body.appendChild(row);
		var i = row.querySelector('input');
		if (i) i.focus();
	});
	if (body) body.addEventListener('click', function (e) {
		var b = e.target.closest && e.target.closest('.ssoMapDel');
		if (!b) return;
		var tr = b.closest('tr');
		if (body.querySelectorAll('tr.ssoMapRow').length > 1) tr.remove();
		else { tr.querySelectorAll('input').forEach(function (i) { i.value = ''; }); tr.querySelectorAll('select').forEach(function (s) { s.selectedIndex = 0; }); }
		changed();
	});
}());
