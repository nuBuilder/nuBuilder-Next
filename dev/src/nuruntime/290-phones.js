	// =================================================================== phones: fixed-position forms stacked (review F1)
	// Below 576 px the objects of an absolute layout keep no top / left: html.nuPhoneStack (nuform.css) puts them below each other,
	// label above the field, full width; the order follows top, then left (style.order). Content boxes become a section title.
	// Off for one form: nuNextPhoneLayout(false) in nuOnLoad (or Global JS); not while designing.
	var phoneOff = false, phoneMq = window.matchMedia ? window.matchMedia('(max-width: 575.98px)') : null;
	api('nuNextPhoneLayout', function (on) { phoneOff = on === false; phoneStack(); });
	// nuBuilder Forte's nuPortraitScreen(columns): the edit form in one column at any width – here the phone layout (labels above the
	// fields, html.nuPortrait caps the width on wide screens); lists are left as they are, as in Forte. Returns the scale (always 1).
	var portrait = false;
	api('nuPortraitScreen', function () {
		if (N.mode !== 'edit') return 1;
		portrait = true;
		window.nuPORTRAITSCREEN = true;
		phoneStack();
		return 1;
	});
	function phoneListKey() { return 'nunext-browse-phone-' + ((N.form && N.form.id) || ''); }
	function phoneListPref() { try { return localStorage.getItem(phoneListKey()) || ''; } catch (x) { return ''; } }
	$(document).on('click', '#nuPhoneCardsBtn', function () {
		var next = document.documentElement.classList.contains('nuPhoneCards') ? 'table' : 'cards';
		try { if (next === 'cards') localStorage.removeItem(phoneListKey()); else localStorage.setItem(phoneListKey(), next); } catch (x) { /* storage blocked */ }
		phoneStack();
		if (window.nuStickyHeadCheck) window.nuStickyHeadCheck();
	});
	// a textarea that is the lowest object of its tab fills the window's free height (250 px at least) – on load, when its tab is
	// shown and when the window changes size; the wrapper and the absolute area of the tab follow (the Notes form)
	// like: a wrapper whose right edge the textarea takes over (the Notes form: as wide as the editor on Notes)
	function fitTextToWindow(ta, like) {
		if (!ta || ta.tagName !== 'TEXTAREA') return;
		var own = ta.closest('.nuWrap');
		if (like && own && like.style.width && own.style.left && !document.documentElement.classList.contains('nuDesigning')) {
			var w = parseFloat(like.style.left) + parseFloat(like.style.width) - parseFloat(own.style.left);
			if (w > ta.offsetWidth || !ta.offsetWidth) { own.style.width = w + 'px'; ta.style.width = w + 'px'; }
		}
		var fit = function () {
			if (ta.offsetParent === null || document.documentElement.classList.contains('nuDesigning')) return;
			var wrap = ta.closest('.nuWrap') || ta, pane = ta.closest('.nuTabPane') || document;
			var rect = wrap.getBoundingClientRect(), below = false;
			pane.querySelectorAll('.nuWrap').forEach(function (w) {
				if (w !== wrap && !wrap.contains(w) && w.offsetParent !== null && w.getBoundingClientRect().top >= rect.bottom - 2) below = true;
			});
			if (below) return;
			var main = document.querySelector('main') || document.body, tr = ta.getBoundingClientRect();
			var rest = Math.max(0, main.getBoundingClientRect().bottom - Math.max(rect.bottom, tr.bottom));
			var h = Math.max(250, Math.floor(window.innerHeight - tr.top - rest - 8));
			var delta = h - ta.offsetHeight;
			if (Math.abs(delta) < 2) return;
			ta.style.height = h + 'px';
			if (wrap !== ta && wrap.style.height) wrap.style.height = (wrap.offsetHeight + delta) + 'px';
			var area = wrap.offsetParent;
			if (area && area !== document.body && area.style.height) area.style.height = Math.max(0, area.offsetHeight + delta) + 'px';
		};
		fit();
		document.addEventListener('shown.bs.tab', function () { setTimeout(fit, 0); });
		var tm; window.addEventListener('resize', function () { clearTimeout(tm); tm = setTimeout(fit, 120); });
	}
	function phoneStack() {
		var html = document.documentElement;
		var phone = !!(phoneMq && phoneMq.matches) && !phoneOff && !html.classList.contains('nuDesigning');
		var forced = portrait && !phoneOff && !html.classList.contains('nuDesigning');
		// a form in a dialog (iframe, also a lookup window) of a wide window keeps the desktop layout – a narrow dialog is not a phone
		if (phone && (N.iframe || N.lookup)) { try { if (window.top !== window && window.top.innerWidth >= 576) phone = false; } catch (x) { /* another origin */ } }
		// browse rows as cards (F2) – unless the user chose the table with the ▦ / ☰ button (per form on this device)
		var cards = phone && !!document.getElementById('nuBrowseTable') && !document.body.classList.contains('nuPrintView') && phoneListPref() !== 'table';
		html.classList.toggle('nuPhoneCards', cards);
		var pb = document.getElementById('nuPhoneCardsBtn');
		if (pb) { pb.setAttribute('aria-pressed', cards ? 'false' : 'true'); pb.classList.toggle('nuPhoneIsTable', !cards); }
		var pa = document.querySelector('.nuPhAdd'), ab = g('nuAddButton');   // phones: Add next to the search only while Add exists (form code may hide it)
		if (pa) pa.hidden = !ab || ab.hidden || ab.style.display === 'none';
		var on = (phone || forced) && !!document.querySelector('.nuLayout-absolute .nuAbsoluteCanvas');
		html.classList.toggle('nuPhoneStack', on);
		html.classList.toggle('nuPortrait', on && forced);
		if (!on) return;
		$('.nuLayout-absolute .nuAbsoluteCanvas').each(function () {
			$(this).children('.nuAbs').each(function () {
				var t = parseInt(this.style.top, 10) || 0, l = parseInt(this.style.left, 10) || 0;
				this.style.order = String(t * 10 + Math.min(9, Math.floor(l / 100)));   // rows first, left to right within ~ a row
			});
		});
	}
	if (phoneMq) { try { phoneMq.addEventListener('change', function () { phoneStack(); growFrames(); }); } catch (x) { /* old Safari */ } }

	// embedded lists (review F3): a form shown in a run object of another form gets html.nuEmbedded (compact chrome, nuform.css);
	// the parent grows such iframes to their content while its objects flow (phones, auto / grid layouts) – absolute layouts keep their size
	if (N.iframe) { try { if (window.frameElement && !window.frameElement.closest('.modal')) document.documentElement.classList.add('nuEmbedded'); } catch (x) { /* other origin */ } }
	if (N.rdv) document.documentElement.classList.add('nuRdView');   // detail form of row details: read only, no toolbar
	// framed forms of run objects: src only now – the parent's functions (nuSetValue …) exist before the frame's code runs
	// in nuBuilder Forte the list's search field and buttons sit in #nuActionHolder – form code that hides the holder (or
	// #nuSearchField) at load hides the search row; the title bar goes when nothing in it is left
	function forteHidden() {
		if (N.mode !== 'browse') return;
		var off = function (sel) { var e = document.querySelector(sel); return !!e && (e.style.display === 'none' || e.classList.contains('nuHolderHidden')); };   // also nuHideHolders
		var form = document.querySelector('.nuBrowseSearch');
		if (form && off('#nuActionHolder')) form.style.display = 'none';
		else if (off('#nuSearchField')) $('.nuSearchGroup').hide();
		var head = document.querySelector('.nuFormHeader');
		if (head && off('#nuActionHolder') && off('#nuBreadcrumbHolder')) head.style.display = 'none';
	}

	// properties the form's code set at load (nuSetProperty before the frames load) reach the framed form, as in Forte
	$(function () {
		var fr = $('iframe.nuIframe[data-nu-src]').filter(function () { return !this.getAttribute('src'); });
		if (!fr.length) return;
		withScope(function (ps) {
			fr.each(function () {
				var src = this.getAttribute('data-nu-src');
				if (ps) {
					try { var u = new URL(src, location.href); u.searchParams.set('ps', ps); src = u.pathname + u.search + u.hash; } catch (x) { /* keep src */ }
				}
				if (!this.getAttribute('src')) this.setAttribute('src', src);
			});
		});
	});
	function growFrames() {
		$('.nuWrap_run iframe.nuIframe').each(function () {
			var f = this;
			var fit = function () {
				var flow = document.documentElement.classList.contains('nuPhoneStack') || !f.closest('.nuAbs');
				if (!f.dataset.nuH0) f.dataset.nuH0 = f.style.height || '';
				if (!flow) { f.style.height = f.dataset.nuH0; return; }
				try {
					var d = f.contentDocument;
					if (!d || !d.body) return;
					var main = d.getElementById('main') || d.body;
					var h = Math.ceil(main.getBoundingClientRect().height + (main === d.body ? 0 : main.getBoundingClientRect().top + 8));
					if (h > 40) { f.style.height = Math.min(h, 2400) + 'px'; f.parentElement.style.height = 'auto'; }
				} catch (x) { /* other origin: keep the size */ }
			};
			// fit now and whenever the framed page changes size – also when it had loaded before this ran (the observer was
			// only attached on a later load event, so a frame that was already loaded kept its first, too small height)
			var watch = function () {
				fit();
				try {
					var d = f.contentDocument, el = d && (d.getElementById('main') || d.body);
					if (!el || !window.ResizeObserver || f.__nuObserved === el) return;
					if (f.__nuObs) f.__nuObs.disconnect();
					f.__nuObs = new ResizeObserver(fit); f.__nuObs.observe(el); f.__nuObserved = el;
				} catch (x) { /* other origin */ }
			};
			if (f.__nuGrow) { watch(); return; }
			f.__nuGrow = true;
			f.addEventListener('load', watch);
			try { if (f.contentDocument && f.contentDocument.readyState === 'complete' && f.contentDocument.body && f.contentDocument.body.childElementCount) watch(); } catch (x) { /* ignore */ }
		});
	}

	function fitButtons(root) {
		$(root || document).find('.btn.nuFormBtn').each(function () {
			if (this.closest('template') || /(^|\s)btn-/.test(this.className)) return;
			try { fitButtonInk(this); } catch (x) { /* ignore */ }
			fitButtonTitle(this);
			if (btnSizes) btnSizes.observe(this);
		});
	}
	N.fitButtons = fitButtons;

	// the fixed layout's canvas grows with what its objects show: an HTML object whose content the form's code fills later (a
	// table built on a tab click …) runs out of its stored height – nuBuilder Forte's page then simply gets longer; the canvas never
	// shrinks below the height the form was drawn with
	function fitCanvas(c) {
		if (!c || document.documentElement.classList.contains('nuPhoneStack') || !c.offsetParent) return;
		var base = +c.getAttribute('data-nu-h') || 0;
		if (!base) { base = parseFloat(c.style.height) || c.offsetHeight; c.setAttribute('data-nu-h', String(base)); }
		var need = base;
		Array.prototype.forEach.call(c.children, function (el) {
			if (!el.classList.contains('nuAbs') || el.offsetParent === null) return;
			need = Math.max(need, el.offsetTop + Math.max(el.offsetHeight, el.scrollHeight) + 10);
		});
		if (Math.abs(need - (parseFloat(c.style.height) || 0)) > 1) c.style.height = need + 'px';
	}
	function fitCanvases() { document.querySelectorAll('.nuLayout-absolute .nuAbsoluteCanvas').forEach(fitCanvas); }
	var canvasRO = typeof window.ResizeObserver === 'function' ? new window.ResizeObserver(function (en) {
		en.forEach(function (x) { fitCanvas(x.target.closest && x.target.closest('.nuAbsoluteCanvas')); });
	}) : null;
	function watchCanvases() {
		if (!canvasRO) return;
		document.querySelectorAll('.nuLayout-absolute .nuAbsoluteCanvas > .nuAbs .nuHtml > *').forEach(function (el) { canvasRO.observe(el); });
	}
	$(document).on('shown.bs.tab', '.nuTab', function () { setTimeout(fitCanvases, 30); });
	// fixed-layout forms wider than the window: nuBuilder Next's page margins (main + the tab pane) took ~55 px more than Forte's, so a
	// form that fits Forte's window needed a sideways scroll. While a canvas would scroll, html.nuWideForm takes the margins away (form
	// card to the window edges, no pane padding) and the canvas is only as wide as its objects (no 20 px gap after the last one)
	function fitWide() {
		var root = document.documentElement, form = document.getElementById('nuEditForm');
		if (N.mode !== 'edit' || !form || root.classList.contains('nuPhoneStack') || root.classList.contains('nuDesigning')) { root.classList.remove('nuWideForm'); return; }
		var canvases = Array.prototype.filter.call(document.querySelectorAll('.nuLayout-absolute .nuAbsoluteCanvas'), function (c) { return c.offsetParent !== null; });
		root.classList.remove('nuWideForm');
		form.style.marginLeft = form.style.marginRight = '';
		var wide = false;
		canvases.forEach(function (c) {
			if (!c.hasAttribute('data-nu-minw')) c.setAttribute('data-nu-minw', c.style.minWidth || '');
			c.style.minWidth = c.getAttribute('data-nu-minw');
			var right = 0;
			Array.prototype.forEach.call(c.children, function (el) { if (el.classList.contains('nuAbs') && el.offsetParent !== null) right = Math.max(right, el.offsetLeft + el.offsetWidth); });
			var pane = c.parentElement, ps = getComputedStyle(pane), room = pane.clientWidth - parseFloat(ps.paddingLeft) - parseFloat(ps.paddingRight);
			if (c.offsetWidth > room + 1) { wide = true; c.setAttribute('data-nu-right', String(right)); }
		});
		if (!wide) return;
		var main = form.closest('main'), ms = main ? getComputedStyle(main) : null;
		root.classList.add('nuWideForm');
		if (ms) { form.style.marginLeft = '-' + ms.paddingLeft; form.style.marginRight = '-' + ms.paddingRight; }
		canvases.forEach(function (c) { var r = +c.getAttribute('data-nu-right'); if (r) c.style.minWidth = r + 'px'; });
	}
	var wideTimer = 0;
	$(window).on('resize', function () { clearTimeout(wideTimer); wideTimer = setTimeout(fitWide, 150); });
	$(document).on('shown.bs.tab', '.nuTab', function () { setTimeout(fitWide, 40); });
	N.fitWide = fitWide;
	N.fitCanvases = fitCanvases;

	// read-only text fields of the fixed layout (Forte's widths): nuBuilder Next's font is a little wider, so a value like
	// "2026-11-02 18:11:05" may be cut. The field grows to the right while nothing stands there (other objects of the same height
	// band, the content box around it, the canvas), at most by the missing width; what is still cut shows as tooltip.
	function fitReadonly(root) {
		$(root || document).find('.nuAbsoluteCanvas > .nuAbs input.form-control').each(function () {
			var el = this, wrap = el.closest('.nuAbs');
			if (!(el.readOnly || el.disabled) || el.type === 'hidden' || el.closest('template') || !el.offsetWidth) return;
			var need = el.scrollWidth - el.clientWidth;
			if (need <= 0) return;
			var canvas = wrap.parentNode, r = wrap.getBoundingClientRect(), limit = canvas.getBoundingClientRect().right - 4;
			Array.prototype.forEach.call(canvas.children, function (o) {
				if (o === wrap || !o.offsetWidth || o.tagName === 'TEMPLATE') return;
				var q = o.getBoundingClientRect();
				if (q.bottom <= r.top || q.top >= r.bottom) return;                              // another height band
				if (q.left <= r.left && q.right >= r.right) { limit = Math.min(limit, q.right - 10); return; }   // a box around it
				if (q.left >= r.right - 1) limit = Math.min(limit, q.left - 8);                 // something to the right
			});
			var grow = Math.max(0, Math.min(need + 2, Math.floor(limit - r.right)));
			if (grow > 0) wrap.style.width = (wrap.offsetWidth + grow) + 'px';
			if (el.scrollWidth > el.clientWidth && !el.title) el.title = el.value;
		});
	}
	N.fitReadonly = fitReadonly;
	new MutationObserver(function () { if (N.mode === 'edit') fitButtons(document); })
		.observe(document.documentElement, {attributes: true, attributeFilter: ['data-bs-theme']});

