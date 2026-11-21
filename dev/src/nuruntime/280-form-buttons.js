	// =================================================================== form buttons (input + run objects)
	// 1. Text colour: a button the form's CSS gives its own background (as usual for nuBuilder Forte – #id { background-color: … })
	//    keeps white text as in nuBuilder Forte, dark text only on a light background (white below 2.4:1). Only while the text colour
	//    is still ours (--nu-btn-ink): a colour the form's CSS sets itself stays. Classes nuBtnInkLight / nuBtnInkDark (nuform.css).
	// 2. One line: a label cut off with … gets the full label (+ the new-tab / popup hint) as tooltip, checked again when the size
	//    changes (a button on a hidden tab has no size until the tab is shown).
	var btnProbe = null, btnSizes = window.ResizeObserver ? new ResizeObserver(function (es) { es.forEach(function (e) { fitButtonTitle(e.target); }); }) : null;
	// any CSS colour → [r, g, b, a]. What getComputedStyle hands out – rgb(…) / rgba(…) – is read as it is; other notations go through
	// a probe element once per notation and theme (asking the probe made the browser work out the styles of the whole
	// page again for every button and every badge cell – 60 times on a list with two badge columns). Callers only read the result.
	var rgbSeen = {}, rgbTheme = null;
	function cssRgb(v) {
		v = String(v || '').trim();
		if (!v) return null;
		var m = /^rgba?\(\s*([\d.]+)\s*[,\s]\s*([\d.]+)\s*[,\s]\s*([\d.]+)\s*(?:[,/]\s*([\d.]+)\s*)?\)$/.exec(v);
		if (m) return [+m[1], +m[2], +m[3], m[4] === undefined ? 1 : +m[4]];
		var theme = document.documentElement.getAttribute('data-bs-theme') || '';
		if (theme !== rgbTheme) { rgbSeen = {}; rgbTheme = theme; }             // var(--…) colours differ between light and dark
		if (Object.prototype.hasOwnProperty.call(rgbSeen, v)) return rgbSeen[v];
		if (!btnProbe) { btnProbe = document.createElement('span'); btnProbe.hidden = true; document.body.appendChild(btnProbe); }
		btnProbe.style.color = ''; btnProbe.style.color = v;
		if (!btnProbe.style.color) return (rgbSeen[v] = null);
		m = getComputedStyle(btnProbe).color.match(/[\d.]+/g);
		return (rgbSeen[v] = m && m.length >= 3 ? [+m[0], +m[1], +m[2], m.length > 3 ? +m[3] : 1] : null);
	}
	function sameRgb(a, b) { return !!a && !!b && Math.abs(a[0] - b[0]) < 2 && Math.abs(a[1] - b[1]) < 2 && Math.abs(a[2] - b[2]) < 2; }
	function luminance(c) {
		var f = function (v) { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); };
		return 0.2126 * f(c[0]) + 0.7152 * f(c[1]) + 0.0722 * f(c[2]);
	}
	function fitButtonInk(b) {
		b.classList.remove('nuBtnInkLight', 'nuBtnInkDark');
		var c = getComputedStyle(b);
		if (c.backgroundImage && c.backgroundImage !== 'none') return;            // gradient / image: cannot judge it
		var bg = cssRgb(c.backgroundColor);
		if (!bg || bg[3] < 0.5 || sameRgb(bg, cssRgb(c.getPropertyValue('--bs-btn-bg')))) return;   // transparent, or the look's own colour
		if (!sameRgb(cssRgb(c.color), cssRgb(c.getPropertyValue('--nu-btn-ink')))) return;           // text colour set by the form's CSS
		b.classList.add(1.05 / (luminance(bg) + 0.05) >= 2.4 ? 'nuBtnInkLight' : 'nuBtnInkDark');
	}
	// narrow buttons (nuBuilder Forte's quick buttons of 50–60 px: "+27", "100"): the solid look's side padding would cut the
	// label – less padding first (nuBtnTight 6 px, nuBtnTighter 2 px), the tooltip only when the label still does not fit
	// cut = the label does not fit: the button's own overflow, or its label span's (text-overflow: ellipsis) – a label 0.7 px too wide
	// was cut to "…" while the button's rounded scrollWidth still fitted
	function btnCut(b) {
		if (b.scrollWidth > b.clientWidth + 1) return true;
		var lab = b.querySelector('.nuButtonLabel');
		if (!lab || !b.clientWidth) return false;
		var cs = getComputedStyle(b), room = b.clientWidth - parseFloat(cs.paddingLeft) - parseFloat(cs.paddingRight);
		var used = lab.getBoundingClientRect().width, icon = b.querySelector('.nuObjIcon');
		if (icon) used += icon.getBoundingClientRect().width + 4;
		return used > room + 0.05;
	}
	function fitButtonPad(b) {
		b.classList.remove('nuBtnTight', 'nuBtnTighter', 'nuBtnWrap');
		if (!btnCut(b)) return;
		// a button tall enough for two lines wraps its label, as in nuBuilder Forte (a 47 px button showed "Feedback Tool -…")
		if (b.clientHeight >= 38) {
			b.classList.add('nuBtnWrap');
			if (!btnCut(b) && b.scrollHeight <= b.clientHeight + 1) return;
			b.classList.remove('nuBtnWrap');
		}
		b.classList.add('nuBtnTight');
		if (btnCut(b)) b.classList.add('nuBtnTighter');
	}
	function fitButtonTitle(b) {
		if (!b.hasAttribute('data-nu-title0')) b.setAttribute('data-nu-title0', b.getAttribute('title') || '');
		if (!b.clientWidth) return;
		fitButtonPad(b);
		var t0 = b.getAttribute('data-nu-title0'), hint = b.getAttribute('data-nu-run-hint') || '';
		var lab = b.querySelector('.nuButtonLabel'), text = (lab ? lab.textContent : b.textContent).replace(/\s+/g, ' ').trim();
		if (btnCut(b) && text) b.setAttribute('title', text + (hint ? ' – ' + hint : ''));
		else if (t0) b.setAttribute('title', t0);
		else b.removeAttribute('title');
	}
