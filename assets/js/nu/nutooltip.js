/* nuBuilder Next – tooltip icons: a small help icon next to every form object with the attribute nu-tooltip-icon="text / HTML".
 * Hover, focus or tap shows the text in a tooltip with an arrow pointing at the icon.
 *
 * Same API as nuBuilder:
 *   nuAttachToolTip(options)        attach to new fields (runs by itself when the form is loaded and when fields are added)
 *   nuAttachToolTip.refresh()       place the icons again        nuAttachToolTip.destroy()   remove everything
 *   function nuOnToolTipIcon(element, settings)     change the settings per field (theme, iconSize, gapRight, tooltipPosition,
 *                                                   animationDuration, iconClasses, onClick …)
 *   function nuOnToolTipIconClick(element, text)    called when an icon is clicked
 *   options: selector, createIcon, gapRight (8), iconSize (16), zIndex (50), iconClasses, onClick(el, text), tooltipPosition
 *            (top|bottom|left|right), arrowSize (small|default|large), theme (light|dark|blue|green|purple|red|orange),
 *            animationDuration (200), accessibility (true)
 *   per field: nu-tooltip-position, nu-tooltip-theme, nu-tooltip-arrow-size;  nu-tooltip="…" (no icon) = tooltip on the field itself
 *
 * Differences / improvements over nuBuilder's version:
 *   - the icon lives next to the field inside the object, so it moves, hides (nuHide, tabs) and scrolls with it – no page
 *     coordinates, no scroll / resize work; lookups get it after the description, select2 after the visible box;
 *   - new or removed fields are found by themselves (no need to call nuAttachToolTip() after adding fields);
 *   - the tooltip flips to the other side when there is no room and stays on screen, the arrow keeps pointing at the icon;
 *   - the mouse can move into the tooltip (links can be clicked); a click / tap on the icon pins the tooltip (touch screens),
 *     Escape or a click elsewhere closes it; keyboard: Tab to the icon shows it;
 *   - Bootstrap Icons by default (Font Awesome classes work when nuBuilder's Font Awesome is loaded); theme light follows the
 *     light / dark mode; no motion with "reduce motion"; the text is cleaned (only simple formatting tags, links http(s) only).
 */
(function () {
	'use strict';
	if (window.nuAttachToolTip) return;

	var DEFAULTS = {
		selector: '[nu-tooltip-icon]', createIcon: true, gapRight: 8, iconSize: 16, zIndex: 50,
		iconClasses: 'bi bi-question-circle-fill', onClick: null, tooltipPosition: 'top', arrowSize: 'default',
		theme: 'light', animationDuration: 200, accessibility: true
	};
	var THEMES = ['light', 'dark', 'blue', 'green', 'purple', 'red', 'orange'];
	var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
	var RECS = new Map();             // field → {icon, target, anchor, settings, ro}
	var tip = null, current = null, pinned = false, hideTimer = null, observer = null, scanTimer = null;
	var lastOptions = {};

	function textOf(el, s) { return (el.getAttribute(s.createIcon ? 'nu-tooltip-icon' : (el.hasAttribute('nu-tooltip') ? 'nu-tooltip' : 'nu-tooltip-icon')) || '').trim(); }
	function call(name) {
		var f = window[name];
		if (typeof f !== 'function') return;
		try { return f.apply(window, Array.prototype.slice.call(arguments, 1)); } catch (e) { console.error(name + ': ' + e.message); }
	}

	// ------------------------------------------------------------------ text: only simple formatting
	var TAGS = {B: 1, STRONG: 1, I: 1, EM: 1, U: 1, SMALL: 1, CODE: 1, SPAN: 1, BR: 1, P: 1, UL: 1, OL: 1, LI: 1, A: 1, KBD: 1, MARK: 1};
	function clean(html) {
		var box = document.createElement('div');
		box.innerHTML = html;
		(function walk(node) {
			Array.prototype.slice.call(node.childNodes).forEach(function (c) {
				if (c.nodeType === 3) return;
				if (c.nodeType !== 1) { c.remove(); return; }
				if (!TAGS[c.tagName]) { c.replaceWith(document.createTextNode(c.textContent)); return; }
				Array.prototype.slice.call(c.attributes).forEach(function (a) {
					var n = a.name.toLowerCase();
					var ok = n === 'class' || (c.tagName === 'A' && n === 'href' && /^https?:\/\//i.test(a.value)) || (c.tagName === 'A' && n === 'target');
					if (!ok) c.removeAttribute(a.name);
				});
				if (c.tagName === 'A') { c.setAttribute('target', '_blank'); c.setAttribute('rel', 'noopener'); }
				walk(c);
			});
		})(box);
		return box.innerHTML;
	}

	// ------------------------------------------------------------------ the tooltip (one per page)
	function tooltip() {
		if (tip && tip.isConnected) return tip;
		tip = document.createElement('div');
		tip.id = 'nu-tooltip';
		tip.className = 'nuToolTip';
		tip.setAttribute('role', 'tooltip');
		tip.hidden = true;
		tip.addEventListener('mouseenter', function () { clearTimeout(hideTimer); });
		tip.addEventListener('mouseleave', function () { if (!pinned) hideSoon(); });
		document.body.appendChild(tip);
		return tip;
	}

	function show(rec, pin) {
		var el = rec.field, s = rec.settings, text = textOf(el, s);
		if (!text) return;
		clearTimeout(hideTimer);
		var t = tooltip();
		var pos = el.getAttribute('nu-tooltip-position') || s.tooltipPosition;
		var theme = el.getAttribute('nu-tooltip-theme') || s.theme;
		var size = el.getAttribute('nu-tooltip-arrow-size') || s.arrowSize;
		t.innerHTML = clean(text);
		t.className = 'nuToolTip nuToolTip--' + (THEMES.indexOf(theme) >= 0 ? theme : 'light') + (size === 'small' || size === 'large' ? ' nuToolTip--arrow-' + size : '');
		t.style.zIndex = String((Number(s.zIndex) || 50) + 1000);   // above the form objects and the sticky bars
		t.style.setProperty('--nu-tt-duration', (reduceMotion ? 0 : Number(s.animationDuration) || 0) + 'ms');
		t.hidden = false;
		t.classList.remove('nuToolTip--in');
		if (current && current !== rec && current.target) current.target.removeAttribute('aria-describedby');
		current = rec; pinned = !!pin;
		rec.target.setAttribute('aria-describedby', 'nu-tooltip');
		place(rec.target, t, pos);
		requestAnimationFrame(function () { t.classList.add('nuToolTip--in'); });
	}
	function hide() {
		clearTimeout(hideTimer);
		if (!tip || tip.hidden) return;
		if (current && current.target) current.target.removeAttribute('aria-describedby');
		current = null; pinned = false;
		tip.classList.remove('nuToolTip--in');
		var d = reduceMotion ? 0 : 150;
		hideTimer = setTimeout(function () { if (tip && !tip.classList.contains('nuToolTip--in')) tip.hidden = true; }, d);
	}
	function hideSoon() { clearTimeout(hideTimer); hideTimer = setTimeout(hide, 120); }

	/** Place the tooltip next to the target; flip when there is no room, keep it on screen, arrow at the target's centre. */
	function place(target, t, pos) {
		var r = target.getBoundingClientRect(), gap = 10, m = 8;
		var vw = document.documentElement.clientWidth, vh = document.documentElement.clientHeight;
		t.style.left = '0px'; t.style.top = '0px';
		var w = t.offsetWidth, h = t.offsetHeight;
		var room = {top: r.top - gap - h >= m, bottom: r.bottom + gap + h <= vh - m, left: r.left - gap - w >= m, right: r.right + gap + w <= vw - m};
		var opposite = {top: 'bottom', bottom: 'top', left: 'right', right: 'left'};
		if (!room[pos]) pos = room[opposite[pos]] ? opposite[pos] : (['top', 'bottom', 'right', 'left'].filter(function (p) { return room[p]; })[0] || pos);
		var cx = r.left + r.width / 2, cy = r.top + r.height / 2, left, top;
		if (pos === 'top' || pos === 'bottom') {
			left = Math.min(Math.max(m, cx - w / 2), vw - w - m);
			top = pos === 'top' ? r.top - gap - h : r.bottom + gap;
			t.style.setProperty('--nu-tt-arrow', Math.min(Math.max(12, cx - left), w - 12) + 'px');
		} else {
			top = Math.min(Math.max(m, cy - h / 2), vh - h - m);
			left = pos === 'left' ? r.left - gap - w : r.right + gap;
			t.style.setProperty('--nu-tt-arrow', Math.min(Math.max(10, cy - top), h - 10) + 'px');
		}
		t.setAttribute('data-pos', pos);
		t.style.left = Math.round(left) + 'px';
		t.style.top = Math.round(top) + 'px';
	}

	// ------------------------------------------------------------------ icons
	/** The visible part the icon goes after: select2 box, lookup group, input group – otherwise the field itself. */
	function anchorOf(el) {
		if (el.tomselect && el.tomselect.wrapper) return el.tomselect.wrapper;
		if (el.type === 'hidden') { var n = el.nextElementSibling; if (n && n.classList.contains('nuLookupGroup')) return n; }
		var g = el.closest('.input-group');
		return g || el;
	}
	function position(rec) {
		if (!rec.icon) return;
		var a = rec.anchor, host = rec.icon.parentNode;
		if (!a.isConnected || !host || a.offsetParent === null || !a.offsetWidth) { rec.icon.hidden = true; return; }
		rec.icon.hidden = false;
		var ar = a.getBoundingClientRect(), hr = host.getBoundingClientRect(), s = rec.settings;
		rec.icon.style.left = Math.round(ar.right - hr.left + host.scrollLeft + (Number(s.gapRight) || 0)) + 'px';
		rec.icon.style.top = Math.round(ar.top - hr.top + host.scrollTop + ar.height / 2 - (Number(s.iconSize) || 16) / 2) + 'px';
	}

	function attachOne(el, base) {
		var s = Object.assign({}, base);
		call(s.createIcon ? 'nuOnToolTipIcon' : 'nuOnAdvancedToolTip', el, s);
		var rec = {field: el, settings: s, icon: null, target: el, anchor: anchorOf(el), ro: null};
		if (s.createIcon) {
			var icon = document.createElement('i');
			icon.className = s.iconClasses + ' nuToolTipIcon';
			if (el.id) icon.id = el.id + '_helpicon';
			icon.style.fontSize = (Number(s.iconSize) || 16) + 'px';
			icon.style.zIndex = String(Number(s.zIndex) || 50);
			icon.classList.toggle('nuToolTipIcon--click', !!s.onClick);
			if (s.accessibility) {
				icon.setAttribute('tabindex', '0');
				icon.setAttribute('role', 'button');
				icon.setAttribute('aria-label', (window.nuNext && window.nuNext.messages && window.nuNext.messages.help) || 'Help');
			}
			// inside the object (absolute layout: the .nuAbs wrapper), so it moves, hides and scrolls with the field
			var host = rec.anchor.closest('.nuAbs') || rec.anchor.parentElement;
			if (getComputedStyle(host).position === 'static') host.classList.add('nuToolTipHost');
			host.appendChild(icon);
			rec.icon = rec.target = icon;
			if (window.ResizeObserver) { rec.ro = new ResizeObserver(function () { position(rec); }); rec.ro.observe(rec.anchor); }
			position(rec);
		} else {
			el.classList.add(s.onClick ? 'nuToolTipField--click' : 'nuToolTipField');
		}
		var t = rec.target;
		t.addEventListener('mouseenter', function () { if (!pinned || current !== rec) show(rec, false); });
		t.addEventListener('mouseleave', function () { if (!pinned) hideSoon(); });
		t.addEventListener('focus', function () { show(rec, false); });
		t.addEventListener('blur', function () { if (!pinned) hideSoon(); });
		t.addEventListener('keydown', function (e) {
			if (e.key === 'Escape') { hide(); return; }
			if (s.createIcon && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); click(rec, e); }
		});
		if (s.createIcon) t.addEventListener('click', function (e) { e.preventDefault(); e.stopPropagation(); click(rec, e); });
		RECS.set(el, rec);
	}
	function click(rec, e) {
		var text = textOf(rec.field, rec.settings);
		if (rec.settings.onClick) { hide(); try { rec.settings.onClick(rec.field, text, e); } catch (x) { console.error(x); } }
		else if (pinned && current === rec) hide(); else show(rec, true);       // tap / click: keep it open
		call('nuOnToolTipIconClick', rec.field, text);
	}
	function detach(el) {
		var rec = RECS.get(el);
		if (!rec) return;
		if (rec.ro) rec.ro.disconnect();
		if (rec.icon) rec.icon.remove();
		el.classList.remove('nuToolTipField', 'nuToolTipField--click');
		if (current === rec) hide();
		RECS.delete(el);
	}

	// ------------------------------------------------------------------ public
	function nuAttachToolTip(options) {
		if (options) lastOptions = Object.assign({}, lastOptions, options);
		var base = Object.assign({}, DEFAULTS, lastOptions);
		var found = new Set();
		var scan = function (sel, createIcon) {
			document.querySelectorAll(sel).forEach(function (el) {
				if (el.closest('template') || el.closest('#nu-tooltip')) return;
				var s = Object.assign({}, base, {createIcon: createIcon});
				if (!textOf(el, s)) return;
				found.add(el);
				var rec = RECS.get(el);
				if (rec && rec.anchor !== anchorOf(el)) { detach(el); rec = null; }   // e.g. Tom Select started after the first scan
				if (!rec) attachOne(el, s); else position(rec);
			});
		};
		scan(base.selector, base.createIcon);
		if (base.selector === DEFAULTS.selector) scan('[nu-tooltip]:not([nu-tooltip-icon])', false);   // tooltip on the field itself
		RECS.forEach(function (rec, el) { if (!found.has(el) || !el.isConnected) detach(el); });
		watch();
		return RECS.size;
	}
	nuAttachToolTip.refresh = function () { RECS.forEach(position); if (current && tip && !tip.hidden) place(current.target, tip, tip.getAttribute('data-pos') || 'top'); };
	nuAttachToolTip.destroy = function () {
		Array.from(RECS.keys()).forEach(detach);
		if (observer) { observer.disconnect(); observer = null; }
		if (tip) { tip.remove(); tip = null; }
	};
	nuAttachToolTip.hide = hide;

	// fields added / removed or the attribute changed: attach / detach by itself
	function watch() {
		if (observer || !window.MutationObserver) return;
		observer = new MutationObserver(function (list) {
			var relevant = list.some(function (m) {
				if (m.type === 'attributes') return true;
				return Array.prototype.some.call(m.addedNodes, function (n) { return n.nodeType === 1 && !n.classList.contains('nuToolTipIcon') && n.id !== 'nu-tooltip'; })
					|| Array.prototype.some.call(m.removedNodes, function (n) { return n.nodeType === 1 && !n.classList.contains('nuToolTipIcon'); });
			});
			if (!relevant) return;
			clearTimeout(scanTimer);
			scanTimer = setTimeout(function () { nuAttachToolTip(); }, 60);
		});
		observer.observe(document.body, {childList: true, subtree: true, attributes: true, attributeFilter: ['nu-tooltip-icon', 'nu-tooltip']});
	}

	document.addEventListener('click', function (e) {
		if (pinned && !e.target.closest('.nuToolTip') && !e.target.closest('.nuToolTipIcon')) hide();
	});
	document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && pinned) hide(); });
	window.addEventListener('resize', function () { if (current && tip && !tip.hidden) hide(); }, {passive: true});
	document.addEventListener('scroll', function () { if (current && tip && !tip.hidden) nuAttachToolTip.refresh(); }, {passive: true, capture: true});

	window.nuAttachToolTip = nuAttachToolTip;
})();
