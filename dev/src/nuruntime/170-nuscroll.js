	// =================================================================== nuScroll
	// nuBuilder's input type nuScroll (nuFORM.scrollList): ↑ / ↓ step through the object's list (JavaScript, usually ['a', 'b']), text
	// that is not in the list can still be typed. The list is also offered as suggestions (<datalist>): phones show it when the field
	// is tapped, desktops as a small arrow in the field. One datalist per field, shared by the rows of a sub-form.
	function scrollList(el) {
		if (el._nuScroll !== undefined) return el._nuScroll;
		var src = el.getAttribute('data-nu-scroll') || '', l = [];
		if (src.trim() !== '') {
			try { l = (new Function('return (' + src + '\n);'))(); } catch (x) { reportError(x.message, 'nuScroll ' + el.id); }
		}
		if (!Array.isArray(l)) l = [];
		return (el._nuScroll = l.map(function (v) { return v === null || v === undefined ? '' : String(v); }));
	}
	function initScroll(el) {
		if (el._nuScrollInit) return;
		el._nuScrollInit = true;
		var l = scrollList(el);
		if (!l.length || el.hasAttribute('list')) return;
		var id = 'nuScrollList_' + (el.getAttribute('data-nu-field') || el.id);
		if (!g(id)) {
			var dl = document.createElement('datalist');
			dl.id = id;
			l.forEach(function (v) { var o = document.createElement('option'); o.value = v; dl.appendChild(o); });
			document.body.appendChild(dl);
		}
		el.setAttribute('list', id);
		el.setAttribute('autocomplete', 'off');
	}
	$(document).on('keydown', '.nuScroll', function (e) {
		if ((e.key !== 'ArrowUp' && e.key !== 'ArrowDown') || e.altKey || e.ctrlKey || e.metaKey || this.readOnly || this.disabled) return;
		var l = scrollList(this);
		if (!l.length) return;
		e.preventDefault();
		var i = l.indexOf(this.value);
		// a value that is not in the list: ↓ starts at the first entry, ↑ at the last (nuBuilder skipped the first one)
		i = i < 0 ? (e.key === 'ArrowDown' ? 0 : l.length - 1) : (i + (e.key === 'ArrowDown' ? 1 : l.length - 1)) % l.length;
		$(this).val(l[i]).trigger('change');
	});
	$(document).on('focusin', '.nuScroll', function () { initScroll(this); });
	$(function () { $('.nuScroll').each(function () { initScroll(this); }); });

