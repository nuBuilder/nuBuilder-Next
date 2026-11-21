	// =================================================================== print view settings (nuBuilder Forte's nuform.js)
	// The same properties as Forte – read by the server for the print view and its CSV / Excel export (core/form/nuprintview.php).
	api('nuPrintIncludeColumns', function (arr) { nuSetProperty('nuPrintIncludedColumns', nuEncode(arr)); });
	api('nuPrintExcludeColumns', function (arr) { nuSetProperty('nuPrintExcludedColumns', nuEncode(arr)); });
	api('nuPrintIncludeHiddenColumns', function (include) { nuSetProperty('nuPrintIncludeHiddenColumns', include ? '1' : '0'); });
	api('nuPrintUseBrowseFormats', function (value) { nuSetProperty('nuPrintUseBrowseFormats', value ? '1' : '0'); });
	api('nuPrintCSVExportOptions', function (options) { nuSetProperty('nuPrintCSVExportOptions', nuEncode(JSON.stringify(options || {}))); });
	/** The print view's address with the properties form code set just before (nuPrintIncludeColumns() in nuOnLoad …). */
	function printUrl(href) {
		var u = new URL(href, window.location.href);
		u.searchParams.set('ps', N.ps || '');
		return u.toString();
	}
	$(document).on('click', 'a.nuExpHtml', function (e) {
		if (N.mode !== 'browse' || !N.form) return;
		e.preventDefault();
		var href = this.getAttribute('href');
		go(function () { return printUrl(href); }, 'blank');
	});
	// in the print view: Ctrl+Shift+C copy, Ctrl+Shift+D download CSV, Ctrl+Shift+O the CSV options – as Forte's nurunhtml.php
	$(function () {
		if (!document.body.classList.contains('nuPrintView')) return;
		var box = document.getElementById('nuPrintOpts'), opts = {};
		try { opts = JSON.parse(box ? box.getAttribute('data-opts') || '{}' : '{}') || {}; } catch (x) { opts = {}; }
		var form = document.querySelector('#nuCsvDialog .js-csv-form');
		if (form) {
			var SEP = {';': 'semicolon', ',': 'comma', '\t': 'tab', '\\t': 'tab', '|': 'pipe'};
			var sep = form.querySelector('[name=sep]'), name = form.querySelector('#nuCsvName');
			if (opts.delimiter !== undefined && SEP[opts.delimiter] && sep) sep.value = SEP[opts.delimiter];
			if (opts.fileName && name) name.value = String(opts.fileName).replace(/\.csv$/i, '');
			if (opts.includeHeaders !== undefined) { var hd = form.querySelector('#nuCsvHead'); if (hd) hd.checked = !!opts.includeHeaders; }
			if (opts.bom !== undefined) { var bm = form.querySelector('#nuCsvBom'); if (bm) bm.checked = !!opts.bom; }
		}
		var download = function () { if (!form) return; if (form.requestSubmit) form.requestSubmit(); else form.submit(); };
		document.addEventListener('keydown', function (e) {
			if (!(e.ctrlKey || e.metaKey) || !e.shiftKey) return;
			var k = (e.key || '').toLowerCase();
			if (k === 'd') { e.preventDefault(); download(); }
			else if (k === 'o') { e.preventDefault(); var b = document.getElementById('nuCsvButton'); if (b) b.click(); }
			else if (k === 'c') { var c = document.querySelector('[data-nu-copy-table]'); if (c) { e.preventDefault(); c.click(); } }
		});
		if (opts.autoDownload) {
			download();
			if (opts.autoClose) setTimeout(function () { window.close(); }, 1500);
		}
	});

