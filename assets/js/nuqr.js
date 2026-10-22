/* Renders [data-qr] elements as SVG QR codes (qrcode-generator). */
(function () {
	'use strict';
	if (typeof qrcode !== 'function') return;
	document.querySelectorAll('[data-qr]').forEach(function (el) {
		var qr = qrcode(0, 'M');
		qr.addData(el.getAttribute('data-qr'));
		qr.make();
		el.innerHTML = qr.createSvgTag({cellSize: 5, margin: 2, scalable: true});
	});
})();
