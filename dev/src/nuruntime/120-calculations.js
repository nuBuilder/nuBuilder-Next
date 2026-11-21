	// =================================================================== calculations
	api('nuTotal', function (path) {
		path = String(path);
		var dot = path.indexOf('.');
		var toNum = function (el) {
			if (!el) return 0;
			var f = el.getAttribute('data-nu-format');
			var v = f ? removeFormatting(el.value, f) : el.value;
			var n = parseFloat(String(v).replace(',', '.'));
			return isNaN(n) ? 0 : n;
		};
		if (dot < 0) return toNum(g(path));
		var sf = path.substring(0, dot), field = path.substring(dot + 1), sum = 0;
		$('#' + sf + ' .nuSubformRow').each(function () {
			var p = this.getAttribute('data-nu-prefix');
			var del = g(p + 'nuDelete');
			if (del && del.checked) return;
			sum += toNum(g(p + field));
		});
		return Math.round(sum * 1e6) / 1e6;
	});

	api('nuCalculateForm', function (setAsEdited) {
		if (window.nuEnableCalculation === false) return;
		var f = $('[data-nu-formula]').toArray().sort(function (a, b) {
			var A = a.closest('.nuSubform') ? 0 : 1000, B = b.closest('.nuSubform') ? 0 : 1000;
			return (parseInt(a.getAttribute('data-nu-calc-order'), 10) + A) - (parseInt(b.getAttribute('data-nu-calc-order'), 10) + B);
		});
		f.forEach(function (el) {
			var formula = el.getAttribute('data-nu-formula');
			if (!formula) return;
			var v = 0;
			try { v = new Function('return (' + formula + ');')(); } catch (x) { reportError(x.message, 'calc ' + el.id); }   // eslint-disable-line no-new-func
			var fixed = addFormatting(v, el.getAttribute('data-nu-format'));
			// the blank new row of a sub-form stays blank: its calc value does not make it an edited row (nuBuilder 4.9)
			var blankRow = el.closest('.nuNewRow');
			if (el.value !== fixed) { el.value = fixed; if (setAsEdited !== false && !blankRow) markEdited(el); }
			el.classList.toggle('nuNegative', NuFormatLib.cssClass(v, el.getAttribute('data-nu-format')) !== '');
			if (window.nuCalculated) { try { window.nuCalculated(el, v, fixed); } catch (x) { reportError(x.message, 'nuCalculated'); } }
		});
	});

