	// =================================================================== calendar (native date picker)
	api('nuPopupCalendar', function (el, initial) {
		if (!el || el.readOnly || el.disabled) return;
		var fmt = el.getAttribute('data-nu-format') || 'D|yyyy-mm-dd';
		var picker = g('nuCalendarPicker');
		if (!picker) {
			picker = document.createElement('input');
			picker.type = 'date';
			picker.id = 'nuCalendarPicker';
			picker.tabIndex = -1;
			picker.setAttribute('aria-hidden', 'true');
			picker.className = 'nuCalendarPicker';
			document.body.appendChild(picker);
		}
		var start = removeFormatting(el.value || initial || '', fmt);
		if (!/^\d{4}-\d{2}-\d{2}/.test(start) && initial) start = removeFormatting(initial, fmt);
		picker.value = /^\d{4}-\d{2}-\d{2}/.test(start) ? start.substr(0, 10) : '';
		// nuBuilder: nuOnSetCalendarOptions(id, {options}) – the browser's own date picker knows only the range:
		// minDate / maxDate (or min / max) as Date, yyyy-mm-dd or in the field's format; other options are ignored
		picker.removeAttribute('min'); picker.removeAttribute('max');
		if (typeof window.nuOnSetCalendarOptions === 'function') {
			var cal = {options: {format: fmt.substring(2), todayHighlight: true, clearBtn: true, autohide: true}};
			var user = callHook('nuOnSetCalendarOptions', el.id, cal);
			var co = $.extend({}, user && typeof user === 'object' ? user : {}, cal.options);
			var ymdOf = function (x) {
				if (!x) return '';
				if (x instanceof Date) return x.getFullYear() + '-' + String(x.getMonth() + 1).padStart(2, '0') + '-' + String(x.getDate()).padStart(2, '0');
				x = String(x);
				return /^\d{4}-\d{2}-\d{2}/.test(x) ? x.substr(0, 10) : String(removeFormatting(x, fmt)).substr(0, 10);
			};
			var mn = ymdOf(co.minDate || co.min), mx = ymdOf(co.maxDate || co.max);
			if (/^\d{4}-\d{2}-\d{2}$/.test(mn)) picker.min = mn;
			if (/^\d{4}-\d{2}-\d{2}$/.test(mx)) picker.max = mx;
		}
		var r = el.getBoundingClientRect();
		picker.style.top = (r.bottom + window.scrollY) + 'px';
		picker.style.left = (r.left + window.scrollX) + 'px';
		picker.onchange = function () {
			if (!picker.value) return;
			var time = /\d{2}:\d{2}(:\d{2})?$/.exec(removeFormatting(el.value, fmt));
			el.value = addFormatting(picker.value + (time ? ' ' + time[0] : ''), fmt);
			$(el).trigger('change');
		};
		try { picker.showPicker(); } catch (x) { picker.focus(); }
	});
	window.nuSetCalendarOnTop = window.nuSetCalendarOnTop || function () {};

