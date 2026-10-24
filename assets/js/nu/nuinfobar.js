/* nuBuilder Next – nuInfoBar: nuBuilder's info bar / notification at the top or bottom of the window.
 *
 * Same API as nuBuilder:
 *   $.nuInfoBar(options)                          $.nuInfoBar.fixed(options)          $('#box').nuInfoBar(options)
 *   $.nuInfoBar.info|success|warning|error(message, options)
 *   $.nuInfoBar.modal(options)                    $.nuInfoBar.modal.info|success|warning|error(message, options)
 *   $.nuInfoBar.shake(options)  .shakeLast(options)  .closeAll(options)  .updateContent(html)
 * Additions: every call returns the bar (jQuery); $.nuInfoBar.close(bar), $.nuInfoBar.count().
 *
 * Differences / improvements over nuBuilder's version:
 *   - several bars (allowMultiple) are stacked instead of lying on top of each other;
 *   - closing (also closeAll) uses each bar's own settings (position, animation); the page and the navbar are pushed by the
 *     real height of all fixed bars and given back when the last one closes;
 *   - modal: a backdrop blocks the page (a click on it shakes the bar), the focus moves into the bar and stays there (Tab);
 *   - auto-close pauses while the mouse is over the bar or the focus is in it; Escape closes a bar that has a close button;
 *   - role="status" / "alert" (warning, error) / "alertdialog" (modal) for screen readers; no motion with "reduce motion";
 *   - theme "light" follows the light / dark mode of nuBuilder Next, "dark" is always dark;
 *   - default icons are Bootstrap Icons (always there); Font Awesome classes (fas fa-…) work when nuBuilder's Font Awesome is loaded;
 *   - forms open as new pages in nuBuilder Next, so a bar disappears with its form (hideOnFormClose: true); with
 *     hideOnFormClose: false it is shown again on the next page of this tab (without its callbacks).
 */
(function ($) {
	'use strict';
	if (!$ || $.nuInfoBar) return;

	var defaults = {
		html: '',
		type: 'info',               // info, success, warning, error
		theme: 'light',             // light (follows light / dark mode), dark
		icon: null,                 // icon class, e.g. 'fas fa-check' or 'bi bi-check' (typed shortcuts)
		customClass: null,
		autoClose: true,
		autoCloseDelay: 5000,
		showCloseButton: true,
		allowMultiple: false,
		hideOnFormClose: true,
		shakeOnShow: false,
		modal: false,
		animationDuration: 300,
		animationType: 'slide',     // slide, fade
		slideDirection: 'down',     // down, up
		shakeDuration: 500,
		shakeIntensity: 5,
		position: 'top',            // top, bottom
		fixed: true,
		zIndex: 10000,
		opacity: 1,
		closeOnClick: false,
		onShow: null, onClose: null, onClick: null, onShake: null
	};
	var ICONS = {info: 'bi bi-info-circle-fill', success: 'bi bi-check-circle-fill', warning: 'bi bi-exclamation-triangle-fill', error: 'bi bi-x-circle-fill'};
	var TYPES = ['info', 'success', 'warning', 'error'];
	var CARRY_KEY = 'nuInfoBarCarry';
	var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
	var closeLabel = (window.nuNext && window.nuNext.messages && window.nuNext.messages.close) || 'Close';   // set by pages/nuform.php

	function bars() { return $('.nu-info-bar').not('.nu-info-bar--closing'); }
	function dur(ms) { return reduceMotion ? 0 : Math.max(0, Number(ms) || 0); }
	function state(bar) { return $(bar).data('nuInfoBar'); }

	// ------------------------------------------------------------------ stacks (one per position: fixed in the window or in a container)
	function stack(settings, $container) {
		var pos = settings.position === 'bottom' ? 'bottom' : 'top';
		var host = settings.fixed ? $('body') : $container;
		var $s = host.children('.nu-info-bar-stack--' + pos + (settings.fixed ? '.nu-info-bar-stack--fixed' : ':not(.nu-info-bar-stack--fixed)'));
		if (!$s.length) {
			$s = $('<div class="nu-info-bar-stack nu-info-bar-stack--' + pos + (settings.fixed ? ' nu-info-bar-stack--fixed' : '') + '"></div>');
			if (!settings.fixed && host.css('position') === 'static') host.css('position', 'relative');
			host.append($s);
			if (settings.fixed && window.ResizeObserver) new ResizeObserver(push).observe($s[0]);
		}
		$s.css('z-index', Math.max(Number($s.css('z-index')) || 0, Number(settings.zIndex) || defaults.zIndex));
		return $s;
	}
	// fixed bars push the page (and the sticky navbar / action bar) by their height: CSS variables used in nuform.css
	function push() {
		['top', 'bottom'].forEach(function (pos) {
			var s = document.querySelector('body > .nu-info-bar-stack--fixed.nu-info-bar-stack--' + pos);
			var h = s ? s.getBoundingClientRect().height : 0;
			document.documentElement.style.setProperty('--nu-infobar-' + pos, Math.round(h) + 'px');
		});
	}

	// ------------------------------------------------------------------ create / show
	function create($container, options) {
		options = options || {};
		if (options.modal && !Object.prototype.hasOwnProperty.call(options, 'autoClose')) options.autoClose = false;
		var s = $.extend({}, defaults, options);
		if (TYPES.indexOf(s.type) < 0) s.type = 'info';
		if (s.modal) s.fixed = true;                     // inside a container the bar could lie below the backdrop
		if (s.icon && !/<i\b/i.test(String(s.html))) s.html = '<i class="' + s.icon + ' nu-info-bar__icon" aria-hidden="true"></i> ' + s.html;   // icon also for $.nuInfoBar({icon})
		if (!s.allowMultiple) bars().each(function () { destroy(this); });

		var role = s.modal ? 'alertdialog' : (s.type === 'error' || s.type === 'warning' ? 'alert' : 'status');
		var $bar = $('<div class="nu-info-bar" tabindex="-1"></div>')
			.addClass('nu-info-bar--' + s.type + ' nu-info-bar--' + (s.theme === 'dark' ? 'dark' : 'light'))
			.addClass(s.customClass || '').toggleClass('nu-info-bar--clickable', !!s.closeOnClick || !!s.onClick).toggleClass('nu-info-bar--modal', !!s.modal)
			.attr({role: role, 'aria-live': role === 'status' ? 'polite' : 'assertive'})
			.css('opacity', s.opacity);
		if (s.modal) $bar.attr('aria-modal', 'true');
		var $content = $('<div class="nu-info-bar__content"></div>').html(s.html);
		$bar.append($content);
		if (s.showCloseButton) {
			$bar.append($('<button type="button" class="btn-close nu-info-bar__close"></button>').attr('aria-label', closeLabel)
				.toggleClass('btn-close-white', s.theme === 'dark')
				.on('click', function (e) { e.preventDefault(); e.stopPropagation(); close($bar[0]); }));
		}

		var st = {settings: s, timer: null, remaining: s.autoClose ? Number(s.autoCloseDelay) || 0 : 0, started: 0, backdrop: null, prevFocus: document.activeElement};
		$bar.data('nuInfoBar', st);

		$bar.on('click', function (e) {
			if ($(e.target).closest('.nu-info-bar__close').length) return;
			if (s.closeOnClick) { close($bar[0]); return; }
			if (s.onClick) s.onClick.call(this, e);
		});
		$bar.on('keydown', function (e) {
			if (e.key === 'Escape' && s.showCloseButton) { e.stopPropagation(); close($bar[0]); return; }
			if (e.key === 'Tab' && s.modal) trapTab(e, $bar[0]);
		});
		// auto-close pauses while the user reads (mouse over the bar, focus in it)
		$bar.on('mouseenter focusin', function () { pause(st); }).on('mouseleave focusout', function (e) {
			if (e.type === 'focusout' && $bar[0].contains(e.relatedTarget)) return;
			if (e.type === 'mouseleave' && $bar[0].contains(document.activeElement)) return;
			resume($bar[0]);
		});

		if (s.modal) {
			st.backdrop = $('<div class="nu-info-bar-backdrop"></div>').css('z-index', (Number(s.zIndex) || defaults.zIndex) - 1)
				.on('mousedown touchstart click', function (e) {
					e.preventDefault(); e.stopPropagation();
					if (e.type !== 'click') shake($bar[0], {duration: s.shakeDuration, intensity: s.shakeIntensity, onComplete: s.onShake});
				});
			$('body').append(st.backdrop);
		}

		var $s = stack(s, $container && $container.length ? $container : $('body'));
		if (s.position === 'bottom') $s.append($bar); else $s.prepend($bar);   // the newest bar is nearest to the window edge
		push();
		show($bar[0]);
		return $bar;
	}

	function slideFrom(s, h) {
		var down = s.slideDirection !== 'up';
		return s.position === 'bottom' ? (down ? h : -h) : (down ? -h : h);
	}

	function show(bar) {
		var st = state(bar), s = st.settings, d = dur(s.animationDuration);
		if (d && bar.animate) {
			var frames = s.animationType === 'fade'
				? [{opacity: 0}, {opacity: s.opacity}]
				: [{transform: 'translateY(' + slideFrom(s, bar.offsetHeight) + 'px)'}, {transform: 'translateY(0)'}];
			bar.animate(frames, {duration: d, easing: 'ease-out'});
		}
		setTimeout(function () {
			if (!document.body.contains(bar)) return;
			if (s.modal) { var f = $(bar).find('button, a[href], input, select, textarea').filter(':visible').not('.nu-info-bar__close')[0]; (f || bar).focus({preventScroll: true}); }
			if (s.shakeOnShow) shake(bar, {duration: s.shakeDuration, intensity: s.shakeIntensity, onComplete: s.onShake});
			if (s.onShow) s.onShow.call(bar);
			resume(bar);
		}, d);
	}

	// ------------------------------------------------------------------ auto-close timer
	function pause(st) {
		if (!st.timer) return;
		clearTimeout(st.timer); st.timer = null;
		st.remaining = Math.max(500, st.remaining - (Date.now() - st.started));
	}
	function resume(bar) {
		var st = state(bar);
		if (!st || st.timer || !st.settings.autoClose || st.remaining <= 0 || st.closing) return;
		st.started = Date.now();
		st.timer = setTimeout(function () { close(bar); }, st.remaining);
	}

	// ------------------------------------------------------------------ close
	function cleanup(bar) {
		var st = state(bar);
		if (!st) return st;
		st.closing = true;
		clearTimeout(st.timer); st.timer = null;
		if (st.backdrop) { st.backdrop.remove(); st.backdrop = null; }
		return st;
	}
	function removeBar(bar, st, callback) {
		var $s = $(bar).parent();
		var hadFocus = bar.contains(document.activeElement);
		$(bar).remove();
		if ($s.is('.nu-info-bar-stack') && !$s.children().length) $s.remove();
		push();
		if (hadFocus && st.prevFocus && document.body.contains(st.prevFocus) && st.prevFocus.focus) st.prevFocus.focus({preventScroll: true});
		if (callback && st.settings.onClose) st.settings.onClose.call(bar);
	}
	/** Without animation and callback (replaced by a new bar when allowMultiple is false – as nuBuilder). */
	function destroy(bar) { var st = cleanup(bar); if (st) removeBar(bar, st, false); }

	function close(bar, options) {
		bar = $(bar)[0];
		var st0 = state(bar);
		if (!bar || !st0 || st0.closing) return;
		var st = cleanup(bar), s = $.extend({}, st.settings, options || {}), d = dur(s.animationDuration);
		$(bar).addClass('nu-info-bar--closing');
		var done = function () { removeBar(bar, st, true); };
		if (!d || !bar.animate) { done(); return; }
		var frames = s.animationType === 'fade'
			? [{opacity: getComputedStyle(bar).opacity}, {opacity: 0}]
			: [{transform: 'translateY(0)'}, {transform: 'translateY(' + slideFrom(s, bar.offsetHeight) + 'px)'}];
		var a = bar.animate(frames, {duration: d, easing: 'ease-in', fill: 'forwards'});
		a.onfinish = done;
	}

	// ------------------------------------------------------------------ shake
	function shake(bar, o) {
		o = $.extend({direction: 'horizontal', duration: 500, intensity: 5, onComplete: null}, o);
		var axis = o.direction === 'vertical' ? 'Y' : 'X', i = Number(o.intensity) || 5;
		var finish = function () { if (o.onComplete) o.onComplete.call(bar); };
		if (reduceMotion || !bar.animate) {                 // no motion: a short highlight instead
			$(bar).addClass('nu-info-bar--attention');
			setTimeout(function () { $(bar).removeClass('nu-info-bar--attention'); finish(); }, 400);
			return;
		}
		var t = function (v) { return {transform: 'translate' + axis + '(' + v + 'px)'}; };
		bar.animate([t(0), t(i), t(-i), t(i), t(-i), t(0)], {duration: Number(o.duration) || 500, easing: 'ease-in-out'}).onfinish = finish;
	}

	function trapTab(e, bar) {
		var f = $(bar).find('button, a[href], input, select, textarea, [tabindex]:not([tabindex="-1"])').filter(':visible').get();
		if (!f.length) { e.preventDefault(); bar.focus(); return; }
		var first = f[0], last = f[f.length - 1];
		if (e.shiftKey && (document.activeElement === first || document.activeElement === bar)) { e.preventDefault(); last.focus(); }
		else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
	}

	// ------------------------------------------------------------------ public API (nuBuilder's names)
	$.fn.nuInfoBar = function (options) {
		var out = $();
		this.each(function () { out = out.add(create($(this), $.extend({}, options))); });
		return out;
	};
	// not fixed: inside the form area (nuBuilder: #nuhtml)
	$.nuInfoBar = function (options) { return create($('.nuFormContainer').first().length ? $('.nuFormContainer').first() : $('body'), $.extend({}, options)); };
	$.nuInfoBar.defaults = defaults;
	$.nuInfoBar.fixed = function (options) { return $.nuInfoBar($.extend({}, options, {fixed: true})); };

	function typed(type, message, options, extra) {
		options = $.extend({}, options, extra);
		var icon = options.icon || ICONS[type];
		return $.nuInfoBar($.extend({}, options, {html: '<i class="' + icon + ' nu-info-bar__icon" aria-hidden="true"></i> ' + (message == null ? '' : message), type: type, icon: null}));
	}
	$.nuInfoBar.modal = function (options) { return $.nuInfoBar($.extend({}, options, {modal: true})); };
	TYPES.forEach(function (type) {
		$.nuInfoBar[type] = function (message, options) { return typed(type, message, options); };
		$.nuInfoBar.modal[type] = function (message, options) { return typed(type, message, options, {modal: true}); };
	});

	$.nuInfoBar.shake = function (options) {
		var o = $.extend({selector: '.nu-info-bar'}, options), $b = $(o.selector).not('.nu-info-bar--closing');
		if (!$b.length) { console.warn('nuInfoBar: no info bar to shake'); return $b; }
		$b.each(function () { shake(this, o); });
		return $b;
	};
	$.nuInfoBar.shakeLast = function (options) {
		var $b = bars().last();
		if (!$b.length) { console.warn('nuInfoBar: no info bar to shake'); return $b; }
		shake($b[0], options);
		return $b;
	};
	$.nuInfoBar.updateContent = function (html, bar) {
		$(bar || '.nu-info-bar').find('.nu-info-bar__content').html(html);
	};
	$.nuInfoBar.close = function (bar, options) { close(bar || bars().last(), options); };
	/** Closes every bar with its own settings; options (e.g. {animationDuration: 0}) override them for this close. */
	$.nuInfoBar.closeAll = function (options) {
		var o = $.extend({}, options);
		['position', 'fixed', 'modal'].forEach(function (k) { delete o[k]; });   // nuBuilder passed these for its cleanup only
		bars().each(function () { close(this, o); });
	};
	$.nuInfoBar.count = function () { return bars().length; };
	window.nuInfoBarHideOnFormClose = true;          // nuBuilder sets this global; kept for code that reads it

	// ------------------------------------------------------------------ hideOnFormClose: false → shown again on the next page
	var CARRY = ['html', 'type', 'theme', 'customClass', 'autoClose', 'autoCloseDelay', 'showCloseButton', 'allowMultiple', 'modal',
		'animationDuration', 'animationType', 'slideDirection', 'shakeDuration', 'shakeIntensity', 'position', 'fixed', 'zIndex', 'opacity', 'closeOnClick'];
	window.addEventListener('pagehide', function () {
		var keep = [];
		bars().each(function () {
			var s = state(this).settings;
			if (s.hideOnFormClose !== false || !s.fixed) return;
			var o = {};
			CARRY.forEach(function (k) { o[k] = s[k]; });
			o.html = $(this).find('.nu-info-bar__content').html();
			o.allowMultiple = true; o.hideOnFormClose = false;
			keep.push(o);
		});
		try { if (keep.length) sessionStorage.setItem(CARRY_KEY, JSON.stringify(keep)); else sessionStorage.removeItem(CARRY_KEY); } catch (e) { /* storage off */ }
	});
	$(function () {
		var list = null;
		try { list = JSON.parse(sessionStorage.getItem(CARRY_KEY) || 'null'); sessionStorage.removeItem(CARRY_KEY); } catch (e) { /* ignore */ }
		if (Array.isArray(list)) list.forEach(function (o) { $.nuInfoBar(o); });
	});
})(window.jQuery);
