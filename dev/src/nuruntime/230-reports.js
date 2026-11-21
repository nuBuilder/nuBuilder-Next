	// =================================================================== reports (nuBuilder's nuRunReport …, PDF made by ?p=reportpdf)
	/** Prepares a run with this form's values (criteria) → {id: token, code}; rejects after showing the error. */
	function reportRun(code) {
		return call('report', {code: String(code || ''), values: N.mode === 'edit' ? mainValues() : {}}).then(function (res) {
			if (!res || !res.ok) { nuDisplayError(res || {errors: [{message: 'Report'}]}); throw new Error('report'); }
			return res;
		});
	}
	function reportUrl(id) { return N.base + '?p=reportpdf&i=' + encodeURIComponent(id); }
	function reportPost(data) {
		var fd = new FormData();
		Object.keys(data).forEach(function (k) { if (data[k] !== undefined && data[k] !== null) fd.append(k, data[k]); });
		fd.append('_csrf', N.csrf);
		return fetch(N.base + '?p=reportpdf', {method: 'POST', credentials: 'same-origin', body: fd})
			.then(function (r) { return r.json().catch(function () { return {ok: false, errors: [{message: 'HTTP ' + r.status}]}; }); });
	}
	/** nuBuilder: nuRunReport(code[, iframeId]) – the PDF in a new tab, or in an iframe of the (parent) form. */
	api('nuRunReport', function (code, iFrame) {
		var w = iFrame === undefined || iFrame === null || iFrame === '' ? window.open('', '_blank') : null;   // opened right away: no popup blocker
		if (w) { try { w.document.title = (M.report_running
			|| 'Report …'); w.document.body.innerHTML = '<p style="font:14px system-ui,sans-serif;padding:1rem">' + esc(M.report_running
			|| 'Report …') + '</p>'; } catch (x) {} }
		return reportRun(code).then(function (res) {
			var url = reportUrl(res.id);
			if (w) { w.location.href = url; return res; }
			if (iFrame === undefined || iFrame === null || iFrame === '') { window.open(url, '_blank'); return res; }
			var f = null;
			try { f = (parent && parent.document.getElementById(iFrame)) || g(iFrame); } catch (x) { f = g(iFrame); }
			if (f) f.setAttribute('src', url);
			return res;
		}, function () { if (w) w.close(); });
	});
	/** nuBuilder Next: the PDF in a dialog on this page. */
	api('nuPreviewReport', function (code) {
		return reportRun(code).then(function (res) {
			var m = g('nuReportModal');
			if (!m) { window.open(reportUrl(res.id), '_blank'); return res; }
			$(m).find('iframe').attr('src', reportUrl(res.id));
			$(m).find('.nuReportOpen').attr('href', reportUrl(res.id));
			nuBs.modal(m).show();
			return res;
		}, function () {});
	});
	/** nuBuilder: nuRunReportSave(code, tag, callback(filename, id), fileName) – the PDF is written to the server's temp folder (pdf_temp). */
	api('nuRunReportSave', function (code, tag, callback, fileName) {
		return reportRun(code).then(function (res) {
			return reportPost({action: 'save', i: res.id, tag: tag === null || tag === undefined ? '' : tag, file_name: fileName || ''});
		}).then(function (data) {
			if (data && data.ok === false) nuDisplayError(data);
			if (typeof callback === 'function') callback(data.filename, data.id, data);
			return data;
		}, function () {});
	});
	/** nuBuilder Next: nuEmailReportPDF(code, to, subject, body, fileName[, callback(ok, data)]) – runs the report and e-mails it as PDF attachment. */
	api('nuEmailReportPDF', function (code, to, subject, body, fileName, callback) {
		return reportRun(code).then(function (res) {
			return reportPost({action: 'email', i: res.id, to: to || '', subject: subject || '', body: body || '', file_name: fileName || ''});
		}).then(function (data) {
			if (data && data.ok === false) nuDisplayError(data.errors ? data : {errors: [{message: (data.message || '') + ' ' + (data.info || '')}]});
			else if (window.nuToast) nuToast(M.report_emailed || 'E-mail sent.', 'success');
			if (typeof callback === 'function') callback(!!(data && data.ok), data);
			return data;
		}, function () {});
	});
	$(document).on('click', '#nuRunReportButton', function () { nuRunReport(this.getAttribute('data-nu-report')); });
	/** nuBuilder Next: nuRunReportXlsx(code) – the report with this form's values as Excel download (sheets Report + Data). */
	api('nuRunReportXlsx', function (code) {
		return reportRun(code).then(function (res) {
			var a = document.createElement('a');
			a.href = reportUrl(res.id) + '&fmt=xlsx';
			a.setAttribute('download', '');
			a.hidden = true;
			document.body.appendChild(a);
			a.click();
			a.remove();
			return res;
		}, function () {});
	});
	$(document).on('click', '#nuRunReportXlsx', function () { nuRunReportXlsx(this.getAttribute('data-nu-report')); });
	api('nuRunReportAction', function (code) { return nuRunReport(code); });

