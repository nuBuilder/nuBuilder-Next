	// =================================================================== searchable / multiple selects (Tom Select)
	/** nuBuilder Forte 4.9.2: a select whose validation allows no blank has no empty item – with nothing chosen no option is
	 *  selected (selectedIndex -1, $(el).val() = null, nuGetValue() = ''), not the first one the browser would pick. */
	function noBlank(root) {
		$(root || document).find('select[data-nu-noblank]').each(function () {
			if (this.closest('template')) return;
			for (var i = 0; i < this.options.length; i++) if (this.options[i].defaultSelected) return;
			this.selectedIndex = -1;
		});
	}
	function initSelects(root) {
		noBlank(root);
		if (!window.TomSelect) return;
		$(root || document).find('select.nuSelect2').each(function () { initSelect(this); });
	}
	/** One searchable select. extra: Select2 options of nuSetSelect2 / $.fn.select2 (the same mapping as nuOnSetSelect2Options). */
	function initSelect(el, extra) {
		if (!window.TomSelect || el.tomselect || el.closest('template')) return el.tomselect || null;
		{
			var multi = el.multiple;
			// dropdown in <body>: every form object has its own z-index (and subforms scroll), so a dropdown inside the object
			// opened behind later objects or was cut off. In <body> it lies on top of everything (nuform.css: .nuTsDropdown).
			var tsOpt = {
				plugins: multi ? ['remove_button'] : [], allowEmptyOption: true, maxOptions: 1000, create: false,
				dropdownParent: 'body',
				dropdownClass: 'ts-dropdown nuTsDropdown' + (el.closest('.nuAbs, .nuSubformTable') ? ' nuTsSmall' : ''),
				render: {no_results: function () { return '<div class="no-results">–</div>'; }}
			};
			// nuBuilder: nuOnSetSelect2Options(id, {options}) – the Select2 options Tom Select also knows are taken over;
			// {tomselect: {…}} passes Tom Select options directly
			if (typeof window.nuOnSetSelect2Options === 'function' || extra) {
				var s2 = {options: $.extend({placeholder: el.getAttribute('placeholder') || ''}, extra || {})};
				var u = typeof window.nuOnSetSelect2Options === 'function' ? callHook('nuOnSetSelect2Options', el.id, s2) : null;
				var so = $.extend({}, u && typeof u === 'object' ? u : {}, s2.options);
				if (Array.isArray(so.data)) so.data.forEach(function (d) {                       // Select2's data: [{id, text}]
					if (d && d.id !== undefined
						&& !$(el).find('option').filter(function () { return this.value === String(d.id); }).length) $(el).append(new Option(String(d.text !== undefined ? d.text : d.id), String(d.id)));
				});
				if (so.placeholder) tsOpt.placeholder = String(so.placeholder);
				if (so.allowClear) tsOpt.plugins = tsOpt.plugins.concat(['clear_button']);
				if (so.maximumSelectionLength > 0) tsOpt.maxItems = so.maximumSelectionLength;
				if (so.minimumResultsForSearch === Infinity || so.minimumResultsForSearch < 0) tsOpt.controlInput = null;
				if (so.tags === true) tsOpt.create = true;
				if (so.tomselect && typeof so.tomselect === 'object') $.extend(tsOpt, so.tomselect);
			}
			var ts = new TomSelect(el, tsOpt);
			if (el.disabled) ts.disable();
			// custom code changes the <select> options directly: keep Tom Select in sync
			// (the observer is paused while Tom Select itself rewrites the options, otherwise it would loop)
			var obs = new MutationObserver(function () {
				obs.disconnect();
				ts.clearOptions();
				ts.sync();
				obs.takeRecords();
				obs.observe(el, {childList: true});
			});
			obs.observe(el, {childList: true});
			$(el).on('change', function () {
				var v = $(el).val(), cur = ts.getValue();
				if (JSON.stringify(multi ? (v || []) : (v || '')) !== JSON.stringify(multi ? (cur || []) : (cur || ''))) ts.setValue(v, true);
			});
			return ts;
		}
	}
	N.initSelects = initSelects;

	// nuBuilder's nuSetSelect2(id, obj): makes a select searchable – here with Tom Select; obj {top, left, width} places the box
	// on forms with absolute layout as nuBuilder does; returns the id of the box (id + '_select2'). nuSelect2(id): the same (older code).
	function setSelect2(id, obj) {
		var el = g(id);
		if (!el || el.tagName !== 'SELECT') return null;
		el.classList.add('nuSelect2');
		el.setAttribute('data-nu-select2', '1');
		var ts = initSelect(el);
		var boxId = id + '_select2';
		if (ts && ts.wrapper) {
			ts.wrapper.id = boxId;
			if (obj && typeof obj === 'object' && el.closest('.nuAbs')) {
				['top', 'left', 'width'].forEach(function (k) { if (obj[k] !== undefined && obj[k] !== '' && !isNaN(Number(obj[k]))) ts.wrapper.style[k] = Number(obj[k]) + 'px'; });
				if (obj.top !== undefined || obj.left !== undefined) ts.wrapper.style.position = 'absolute';
			}
		}
		return boxId;
	}
	api('nuSetSelect2', setSelect2);
	api('nuSelect2', function (id, obj) { return setSelect2(id, obj); });

	// form code that uses Select2 itself: $('#x').select2({…}) / .select2('open' | 'close' | 'destroy' | 'data' | 'isOpen' | 'focus') –
	// the same calls on Tom Select; without jQuery's own Select2 plug-in only
	if (window.jQuery && !$.fn.select2) {
		$.fn.select2 = function (arg) {
			if (typeof arg === 'string') {
				var first = this.filter('select')[0], ts = first && first.tomselect;
				switch (arg) {
					case 'open': this.each(function () { if (this.tomselect) this.tomselect.open(); }); return this;
					case 'close': this.each(function () { if (this.tomselect) this.tomselect.close(); }); return this;
					case 'focus': if (ts) ts.focus(); return this;
					case 'isOpen': return !!(ts && ts.isOpen);
					case 'enable': this.each(function () { if (this.tomselect) this.tomselect.enable(); }); return this;
					case 'disable': this.each(function () { if (this.tomselect) this.tomselect.disable(); }); return this;
					case 'destroy': this.each(function () { if (this.tomselect) { this.tomselect.destroy(); this.classList.remove('nuSelect2'); } }); return this;
					case 'data':
						if (!first) return [];
						return $(first).find('option:selected').map(function () { return {id: this.value, text: this.text, element: this, selected: true}; }).get();
					default: return this;
				}
			}
			var opt = arg && typeof arg === 'object' ? arg : {};
			this.filter('select').each(function () {
				if (this.tomselect) return;
				if (opt.multiple === true) this.multiple = true;
				this.classList.add('nuSelect2');
				initSelect(this, opt);
			});
			return this;
		};
	}

	// an open dropdown in <body> follows its field when the form or a subform scrolls (Tom Select only listens to the window)
	document.addEventListener('scroll', function (e) {
		if (e.target === document || (e.target.closest && e.target.closest('.ts-dropdown'))) return;
		$('select.nuSelect2').each(function () {
			var ts = this.tomselect;
			if (!ts || !ts.isOpen) return;
			if (!e.target.contains || !e.target.contains(ts.wrapper)) return;   // something else scrolled
			var r = ts.control.getBoundingClientRect(), box = e.target.getBoundingClientRect();
			if (r.bottom < box.top || r.top > box.bottom) ts.close();           // field scrolled out of view
			else ts.positionDropdown();
		});
	}, true);

