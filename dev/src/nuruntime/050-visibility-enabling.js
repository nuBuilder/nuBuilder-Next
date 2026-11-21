	// =================================================================== visibility / enabling (nuBuilder nucommon.js, adapted)
	// nuBuilder Next renders every object in a wrapper #nuw_<id> (label + control; subform cells too), tabs are Bootstrap panes.
	// So hiding is done on the wrapper with the class nuHidden: it stays hidden when tabs change, and nuBuilder's
	// data-nu-tab="x…" marker is not needed. Objects without a wrapper (subform table cells) hide their parts.

	/** nuBuilder: ids of the parts of an object ([id, label_id, …]) and its type ('lookup', 'select2' or ''). */
	api('nuObjectComponents', function (id) {
		var componentIds = [id, 'label_' + id], type = '';
		var el = g(id);
		if (el && el.getAttribute('data-nu-type') === 'lookup') {
			componentIds.push(id + 'code', id + 'button', id + 'description');
			type = 'lookup';
		} else if (el && el.tomselect) {
			type = 'select2';                                   // Tom Select (nuBuilder: Select2)
		}
		return {componentIds: componentIds, type: type};
	});

	/** Control elements of an object (no label): the field, lookup code / button / description, Tom Select. */
	function controls(id) {
		var c = nuObjectComponents(id), list = [];
		c.componentIds.forEach(function (cid, i) { if (i !== 1) { var e = g(cid); if (e) list.push(e); } });
		return list;
	}
	function wrapOf(id) { return g('nuw_' + id); }

	function hideOne(id) {
		var w = wrapOf(id);
		if (w) { w.classList.add('nuHidden'); return; }
		nuObjectComponents(id).componentIds.forEach(function (cid) { var e = g(cid); if (e) e.classList.add('nuHidden'); });
	}
	function showOne(id) {
		var w = wrapOf(id), shown = false;
		var parts = w ? [w] : nuObjectComponents(id).componentIds.map(g).filter(Boolean);
		parts.forEach(function (e) {
			if (e.classList.contains('nuHidden') || e.style.display === 'none') shown = true;
			e.classList.remove('nuHidden');
			if (e.style.display === 'none') e.style.display = '';     // hidden by nuBuilder access / old style code
		});
		if (w) nuObjectComponents(id).componentIds.forEach(function (cid) {   // parts hidden one by one with $().hide()
			var e = g(cid);
			if (e && e.style.display === 'none') { e.style.display = ''; shown = true; }
		});
		return shown;
	}

	api('nuHide', function (ids) { arr(ids).forEach(hideOne); });
	// openTab: accepted for nuBuilder compatibility – Bootstrap tabs need no re-layout after showing objects
	api('nuShow', function (ids, visible, openTab) {
		arr(ids).forEach(function (id) { if (visible === false) hideOne(id); else showOne(id); });
	});
	api('nuRemove', function (ids) {
		arr(ids).forEach(function (id) {
			var el = g(id);
			if (el && el.tomselect) el.tomselect.destroy();
			var w = wrapOf(id);
			if (w) w.remove();
			nuObjectComponents(id).componentIds.forEach(function (cid) { var e = g(cid); if (e) e.remove(); });
		});
	});

	api('nuIsVisible', function (id) {
		if (!id) return undefined;
		var $e = typeof id === 'string' ? $('#' + id) : $(id);
		if (!$e.length) return null;
		return $e.css('display') !== 'none' && $e.css('visibility') !== 'hidden' && !$e.is(':hidden');
	});
	api('nuIsHidden', function (id) { return !nuIsVisible(id); });

	/** nuBuilder's optional hook nuOnDisableGlobal(id, $component): return false = keep the component usable. */
	function allowDisable(id, el) {
		if (typeof window.nuOnDisableGlobal !== 'function') return true;
		try { return window.nuOnDisableGlobal(id, $(el)) !== false; } catch (x) { reportError(x.message, 'nuOnDisableGlobal'); return true; }
	}
	function setState(el, disabled, readonly) {
		el.disabled = disabled;
		if ('readOnly' in el) el.readOnly = readonly;
		el.classList.toggle('nuReadonly', disabled || readonly);
		if (el.tomselect) el.tomselect[disabled || readonly ? 'disable' : 'enable']();
		var picker = g(el.id + '_file');                          // file object: hidden picker + remove button
		if (picker) { picker.disabled = disabled || readonly; $(picker).closest('.nuFileField').find('.nuFileRemove').prop('disabled', disabled || readonly); }
		if (el.classList.contains('nuDate')) el.onclick = disabled || readonly ? null : function () { nuPopupCalendar(this); };
		if (el.nuInlineCM) el.nuInlineCM.setOption('readOnly', disabled || readonly);          // inline code editor (code snippets)
	}
	/** Kinds of fields that have no readonly state: they are disabled instead (the value is still saved – nuBuilder Next reads it itself). */
	function noReadonly(el) {
		return el.tagName === 'SELECT' || el.tagName === 'BUTTON' || ['checkbox', 'radio', 'button', 'file', 'color', 'range'].indexOf(el.type) !== -1;
	}

	/**
	 * nuButtonLoading(button, spinning = true, autoEnableAfterSeconds = 0) – as nuBuilder: a spinner on the button and the button
	 * disabled while a background process runs; nuButtonLoading(button, false) ends it, autoEnableAfterSeconds ends it by itself.
	 * button: id, element or jQuery. <button> (Run buttons, own HTML): Bootstrap spinner in place of the icon, before .nuButtonLabel;
	 * <input type="button"> (input objects of type button): animated spinner in the button (nuform.css .nuButtonSpinning).
	 * Also: keeps the width (no jump), aria-busy for screen readers, a button that was disabled before stays disabled, calling it
	 * again while spinning only restarts the auto-enable time, a removed button is ignored. Returns the button (or null).
	 */
	api('nuButtonLoading', function (button, spinning, autoEnableAfterSeconds) {
		var b = typeof button === 'string' ? g(button) : (button && button.jquery ? button[0] : button);
		if (!b || !b.nodeType) return null;
		spinning = spinning !== false;
		clearTimeout(b._nuSpinTimer); b._nuSpinTimer = null;
		var on = b.hasAttribute('data-nu-spinning');
		if (spinning) {
			if (!on) {
				if (b.disabled || b.classList.contains('nuReadonly')) return null;   // as nuBuilder: a disabled button does not start
				b.setAttribute('data-nu-spinning', '1');
				b._nuSpinMinWidth = b.style.minWidth;
				b.style.minWidth = Math.ceil(b.getBoundingClientRect().width) + 'px';
				b.disabled = true;
				b.classList.add('disabled', 'nuButtonSpinning');
				b.setAttribute('aria-busy', 'true');
				if (b.tagName !== 'INPUT') {
					var icon = b.querySelector('i:not([nu-data-spinner]), svg:not([nu-data-spinner])');
					if (icon) { icon.classList.add('d-none'); icon.setAttribute('data-nu-spinner-hidden', '1'); }
					var sp = document.createElement('span');
					sp.className = 'spinner-border spinner-border-sm nuButtonSpinner';
					sp.setAttribute('nu-data-spinner', 'true');
					sp.setAttribute('aria-hidden', 'true');
					var label = b.querySelector('.nuButtonLabel');
					if (label) { sp.classList.add('me-1'); label.parentNode.insertBefore(sp, label); }
					else if (icon) icon.parentNode.insertBefore(sp, icon);
					else if (b.textContent.trim() !== '') { sp.classList.add('me-1'); b.insertBefore(sp, b.firstChild); }
					else b.appendChild(sp);
				}
			}
			var secs = Number(autoEnableAfterSeconds) || 0;
			if (secs > 0) b._nuSpinTimer = setTimeout(function () { if (b.isConnected) nuButtonLoading(b, false); }, secs * 1000);
		} else if (on) {
			b.removeAttribute('data-nu-spinning');
			b.disabled = false;
			b.classList.remove('disabled', 'nuButtonSpinning');
			b.removeAttribute('aria-busy');
			b.style.minWidth = b._nuSpinMinWidth || '';
			b.querySelectorAll('[nu-data-spinner]').forEach(function (x) { x.remove(); });
			b.querySelectorAll('[data-nu-spinner-hidden]').forEach(function (x) { x.classList.remove('d-none'); x.removeAttribute('data-nu-spinner-hidden'); });
		}
		return b;
	});
	$.fn.nuButtonLoading = function (spinning, autoEnableAfterSeconds) { return this.each(function () { nuButtonLoading(this, spinning, autoEnableAfterSeconds); }); };

	api('nuEnable', function (ids, enable) {
		if (enable === false) { nuDisable(ids); return; }
		arr(ids).forEach(function (id) { controls(id).forEach(function (el) { setState(el, false, false); }); });
	});
	api('nuDisable', function (ids) {
		arr(ids).forEach(function (id) {
			controls(id).forEach(function (el) {
				el.classList.add('nuReadonly');
				if (allowDisable(id, el)) setState(el, true, true);
			});
		});
	});
	// read only: text can still be selected and copied; selects, checkboxes and buttons cannot be read only and are disabled
	api('nuReadonly', function (ids, readonly) {
		var on = readonly !== false;
		arr(ids).forEach(function (id) {
			controls(id).forEach(function (el) {
				if (id + 'description' === el.id) return;           // lookup description is always read only
				if (noReadonly(el)) setState(el, on, on); else setState(el, false, on);
			});
		});
	});
	// also read only by the access settings of the object (rendered readonly / disabled by the server)
	api('nuIsDisabled', function (id) { var e = g(id); return !!e && (e.disabled || e.readOnly === true || e.classList.contains('nuReadonly')); });
	api('nuIsEnabled', function (id) { var e = g(id); return !!e && !nuIsDisabled(id); });

	/** Focus an object (lookup: its code field), on its tab. */
	api('nuSetFocus', function (id) {
		var el = g(id);
		if (el && el.getAttribute('data-nu-type') === 'lookup') el = g(id + 'code') || el;
		if (!el) return false;
		var pane = el.closest('.nuTabPane');
		if (pane && !pane.classList.contains('active')) nuSelectTab(g(pane.getAttribute('aria-labelledby')));
		setTimeout(function () { if (el.tomselect) el.tomselect.focus(); else el.focus(); }, pane ? 160 : 0);
		return true;
	});

	/** Removes the empty option of a select; setIndex: index to select afterwards (number), or keep the selection. */
	// nuBuilder Forte: no id = every select of the page; setIndex sets the selected index without a change event
	api('nuSelectRemoveEmpty', function (ids, setIndex) {
		var els = ids === undefined || ids === null ? Array.prototype.slice.call(document.querySelectorAll('select')) : arr(ids).map(g);
		els.forEach(function (el) {
			if (!el || el.tagName !== 'SELECT') return;
			$(el).find('option').filter(function () { return String(this.value).trim() === '' && $(this).text().trim() === ''; }).remove();
			if (setIndex !== undefined && setIndex !== null && setIndex !== false) el.selectedIndex = Number(setIndex);
			if (el.tomselect) { el.tomselect.clearOptions(); el.tomselect.sync(); }
		});
	});

