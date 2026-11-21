	// =================================================================== values
	function rawValue(el) {
		if (!el) return '';
		if (el.type === 'checkbox') return el.checked ? '1' : '0';
		if (el.tagName === 'SELECT' && el.multiple) return $(el).val() || [];
		// a chosen option that is disabled has no value (jQuery's val(), so nuBuilder Forte's): nuSelectAddEnglishOption adds such a
		// placeholder (value 1) to an empty language select – it was saved as the language
		// (only the option or its <optgroup> – a disabled select (nuDisable) keeps its value)
		if (el.tagName === 'SELECT') { var so = el.options[el.selectedIndex]; return !so || so.disabled
			|| (so.parentNode && so.parentNode.tagName === 'OPTGROUP' && so.parentNode.disabled) ? '' : so.value; }
		var f = el.getAttribute('data-nu-format');
		return f ? rawFormatted(el.value, f, el.id) : el.value;
	}
	N.rawValue = rawValue;

	/** Number without its format; nuBuilder's nuOnRawValueFormatted(id, rawValue, format) may change it. */
	function rawFormatted(v, f, id) {
		var r = removeFormatting(v, f);
		if (String(f || '').charAt(0).toUpperCase() === 'N' && typeof window.nuOnRawValueFormatted === 'function') {
			var m = callHook('nuOnRawValueFormatted', id || '', r, f);
			if (m !== undefined) r = m;
		}
		return r;
	}

	function isBtn(el) { return el.tagName === 'BUTTON' || (el.tagName === 'INPUT' && el.type === 'button'); }
	function btnLabel(el) { return el.querySelector ? el.querySelector('.nuButtonLabel') : null; }
	api('nuGetValue', function (id, method) {
		var el = g(id);
		if (!el) return null;
		if (el.type === 'checkbox' || el.type === 'radio') return el.checked;
		// nuBuilder Forte: a button gives its label, a label its text (method for / html), a WYSIWYG field the editor's content
		if (!method && isBtn(el)) return el.tagName === 'INPUT' ? el.value : (btnLabel(el) || el).textContent;
		if (el.tagName === 'LABEL') return method === 'for' ? (el.getAttribute('for') || null) : (method === 'html' ? el.innerHTML : el.textContent);
		if (!method && window.nuGetTinyMCEEditor && nuGetTinyMCEEditor(id)) return nuTinyMCEGetContent(id);
		if (el.tagName === 'SELECT' && !el.multiple && (method === undefined || method === 'val')) return el.value === undefined || el.selectedIndex < 0 ? '' : el.value;
		if (method === 'text' && el.tagName === 'SELECT') return $(el).find('option:selected').text();
		if (method === 'code') return (g(id + 'code') || {}).value;
		if (method === 'description') return (g(id + 'description') || {}).value;
		if (method === 'html') return el.innerHTML;
		if (el.tagName === 'SELECT' && el.multiple) return $(el).val();
		if ('value' in el && el.tagName !== 'DIV') return el.value;
		return el.textContent;
	});

	// change: false = no change event and, as in nuBuilder Forte, the field is not marked as edited
	api('nuSetValue', function (id, value, method, change) {
		var el = g(id);
		if (!el) return false;
		change = change !== false;
		// a lookup: the id is looked up and code / description follow (nuBuilder Forte: nuGetLookupId)
		if (el.getAttribute('data-nu-type') === 'lookup' && g(id + 'code')) { nuGetLookupId(value, id); return true; }
		if (method === undefined && isBtn(el)) {
			if (el.tagName === 'INPUT') el.value = value; else { var lb = btnLabel(el); (lb || el).textContent = value; if (!lb && el.classList.contains('nuActionButton')) el.value = value; }
			return true;
		}
		if (el.tagName === 'LABEL') {
			if (method === 'for') el.setAttribute('for', value); else if (method === 'text') el.textContent = value; else nuSetLabelText(id, value, change);
			return true;
		}
		if (method !== 'html' && method !== 'text' && window.nuGetTinyMCEEditor && nuGetTinyMCEEditor(id)) {
			nuTinyMCESetContent(id, value); el.value = value;
			if (change) markEdited(el);
			return true;
		}
		if (method === 'html' || (!('value' in el) || el.tagName === 'DIV')) { if (method === 'text') el.textContent = value; else el.innerHTML = value; return true; }
		if (el.type === 'checkbox' || el.type === 'radio') el.checked = value === true || value === 1 || value === '1' || value === 'true';
		else if (el.tagName === 'SELECT' && (value === null || value === undefined || (value === '' && !$(el).find('option[value=""]').length))) el.selectedIndex = -1;
		else if (method === 'text' && el.tagName === 'SELECT') $(el).find('option').filter(function () { return $(this).text().replace(/\u00a0/g, ' ') === value; }).first().prop('selected', true);
		else $(el).val(value);
		if (el.tomselect) el.tomselect.sync();
		if (change) { $(el).addClass('nuEdited'); $(el).trigger('change'); }
		return true;
	});

	api('nuGetText', function (id) { return nuGetValue(id, 'text'); });
	api('nuSetText', function (id, v) { return nuSetValue(id, v, 'text'); });
	// placeholder: without text the label is used; translated unless translate === false
	api('nuSetPlaceholder', function (id, t, translate) {
		// without text: the field's format (dd.mm.yyyy, 1000.00 – Forte's data-nu-format minus "D|" / "N|"), else its label
		if (t === undefined || t === null || t === '') {
			var fmt = String($('#' + id).attr('data-nu-format') || '');
			t = fmt.length > 2 ? fmt.substring(2) : labelText(id);
		}
		$('#' + id + ($('#' + id).attr('data-nu-type') === 'lookup' ? 'code' : '')).attr('placeholder', translate === false ? t : nuTranslate(t));
	});
	// keeps the validation markers (* / key) after the new text
	api('nuSetLabelText', function (id, t, translate) {
		if (/^label_/.test(id) && g(id) && g(id).tagName === 'LABEL') id = id.slice(6);   // the label's own id, as nuBuilder Forte
		var l = $('#label_' + id), marks = l.children('.nuMarks').detach();
		l.empty().append(safeFrag(translate ? nuTranslate(t) : t)).append(marks);
	});
	// nuBuilder Forte: the title shows while the mouse is over the field (translated); labelHover = also over its label;
	// an options object = nuSetAdvancedToolTip
	api('nuSetToolTip', function (id, t, labelHover) {
		if (t && typeof t === 'object') { nuSetAdvancedToolTip(id, t); return; }
		var sel = '#' + id + (labelHover ? ', #label_' + id : '');
		$(sel).off('.nuTip').on('mouseenter.nuTip', function () { this.setAttribute('title', nuTranslate(t)); })
			.on('mouseleave.nuTip', function () { this.removeAttribute('title'); });
	});

	/** Values of the main form (unformatted), for save and server calls. */
	function mainValues() {
		var v = {};
		$('#nuEditForm [data-nu-field]').each(function () {
			if (this.getAttribute('data-nu-prefix') || this.classList.contains('nuLookupCode') || this.classList.contains('nuLookupDescription')) return;
			// form controls only – sub-form column titles (th) carry data-nu-field too (they came as null fields)
			if (this.type === 'button' || ['INPUT', 'SELECT', 'TEXTAREA'].indexOf(this.tagName) < 0) return;
			v[this.getAttribute('data-nu-field')] = rawValue(this);
		});
		return v;
	}
	N.mainValues = mainValues;

	api('nuHashFromEditForm', function () {
		var v = mainValues(), out = [];
		Object.keys(v).forEach(function (k) { out.push([k, v[k]]); });
		return out;
	});

