/* nuBuilder Next – the only place that talks to Bootstrap's JavaScript (0.8.244). Every page script uses nuBs.* instead of
 * bootstrap.Modal / Tooltip / … directly, so a later Bootstrap version (6: ES modules only, no window.bootstrap) changes this file only.
 * Loaded right after bootstrap.bundle.min.js (core/nulayout.php, login, installer, print list).
 *
 *   nuBs.ready()               Bootstrap's JavaScript is there
 *   nuBs.modal(el, opts)       Modal instance (show / hide …), null without Bootstrap or element
 *   nuBs.tooltip(el, opts)     Tooltip instance (created if needed) · nuBs.tooltipOf(el): existing instance or null
 *   nuBs.dropdown(el, opts) · nuBs.tab(el) · nuBs.toast(el, opts) · nuBs.collapse(el, opts)
 *
 * window.bootstrap stays available for nuBuilder form code that uses it directly (with Bootstrap 6 this file would set it).
 */
(function () {
	'use strict';
	var lib = function () { return window.bootstrap || null; };
	var instance = function (name) {
		return function (el, opts) {
			var b = lib();
			if (!b || !b[name] || !el) return null;
			return b[name].getOrCreateInstance(el, opts);
		};
	};
	window.nuBs = {
		ready: function () { return !!lib(); },
		modal: instance('Modal'),
		tooltip: instance('Tooltip'),
		dropdown: instance('Dropdown'),
		tab: instance('Tab'),
		toast: instance('Toast'),
		collapse: instance('Collapse'),
		tooltipOf: function (el) { var b = lib(); return b && b.Tooltip && el ? b.Tooltip.getInstance(el) : null; }
	};
})();
