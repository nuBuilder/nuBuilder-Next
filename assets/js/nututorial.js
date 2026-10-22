/* Tutorial (pages/nututorial.php): progress bar width (no inline styles – CSP), the current lesson visible in the index. */
(function () {
	'use strict';
	document.querySelectorAll('.tutBar[data-pct]').forEach(function (b) { b.style.width = Math.max(0, Math.min(100, +b.getAttribute('data-pct'))) + '%'; });
	var act = document.querySelector('.tutIndex .tutLessonLink.active'), box = document.querySelector('.tutIndex .list-group');
	if (act && box && box.scrollHeight > box.clientHeight) box.scrollTop = Math.max(0, act.offsetTop - box.clientHeight / 3);
})();
