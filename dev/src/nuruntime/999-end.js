})();

// list cells cut to one line: the pointer on a cut cell shows the whole text as its title – set when the pointer
// arrives, only while the text does not fit, never over a title the cell or the form's code set
(function () {
	'use strict';
	document.addEventListener('mouseover', function (e) {
		var c = e.target && e.target.closest ? e.target.closest('#nuBrowseTable td.nuCellTd > div.nuCell') : null;
		if (!c) return;
		var mine = c.hasAttribute('data-nu-cut-title');
		if (c.hasAttribute('title') && !mine) return;
		var cut = c.scrollHeight > c.clientHeight + 1 || c.scrollWidth > c.clientWidth + 1;
		var text = (c.innerText || c.textContent || '').replace(/[ \t]+\n/g, '\n').trim();
		if (cut && text !== '') { c.setAttribute('title', text); c.setAttribute('data-nu-cut-title', ''); }
		else if (mine) { c.removeAttribute('title'); c.removeAttribute('data-nu-cut-title'); }
	});
})();
