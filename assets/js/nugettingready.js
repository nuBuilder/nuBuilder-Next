/* Getting ready checklist (pages/nugettingready.php): on phones the steps are one scrolling row – show the current step. */
(function () {
	'use strict';
	var a = document.querySelector('.nuGrSteps .nuGrStep.active');
	var row = a && a.closest('ol');
	if (!row || row.scrollWidth <= row.clientWidth) return;
	row.scrollLeft = Math.max(0, a.offsetLeft - row.offsetLeft - 24);
})();
