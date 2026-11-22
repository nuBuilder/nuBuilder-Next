	// =================================================================== server calls
	function call(action, data) {
		data = $.extend({action: action, form_id: N.form.id, record_id: N.record_id, props: N.props, ps: N.ps || ''}, data || {});
		return NuUtil.post(N.base + '?p=formapi', data, {raw: true}).then(function (r) {
			// a procedure that sends a file (header('Content-Disposition: attachment …'), NuXlsx::download, PhpSpreadsheet …):
			// nuBuilder Forte runs it in its own window, here the answer is the file itself, saved by nuRunPHP / nuRunPHPHidden
			var cd = r.headers.get('Content-Disposition') || '';
			if (r.ok && /attachment/i.test(cd)) {
				var m = /filename\*=UTF-8''([^;]+)/i.exec(cd), m2 = /filename="?([^";]+)"?/i.exec(cd);
				var name = m ? decodeURIComponent(m[1]) : (m2 ? m2[1] : 'download');
				return r.blob().then(function (b) { return {ok: true, file: {blob: b, name: name}}; });
			}
			return r.json().catch(function () { return {ok: false, errors: [{message: 'HTTP ' + r.status}]}; });
		})
			.then(function (res) {
				// draft preview: Save / Delete write live data – asked once per session, then sent again
				if (res && res.code === 'preview_confirm' && !data.preview_confirm) {
					if (!window.confirm((res.errors && res.errors[0] && res.errors[0].message) || '')) return {ok: false, errors: []};
					return call(action, $.extend({}, data, {preview_confirm: true}));
				}
				return res;
			});
	}
	N.call = call;

	/** Applies props, form values (in a subform row context when prefix is given) and JS returned by the server. */
	function applyResult(res, prefix) {
		if (!res) return;
		if (res.props) Object.keys(res.props).forEach(function (k) { N.props[k] = res.props[k]; if (N.locked.indexOf(k) < 0) N.locked.push(k); });
		if (res.globals) Object.keys(res.globals).forEach(function (k) { N.globals[k] = res.globals[k]; });
		(res.deleted || []).forEach(function (k) { delete N.props[k]; delete N.globals[k]; });
		if (res.values) Object.keys(res.values).forEach(function (k) {
			var name = String(k).replace(/#ROW#/g, prefix || '');          // nuBuilder: nuSetFormValue('#ROW#field', …) in a subform row
			var id = name !== k ? name : (prefix && g(prefix + k) ? prefix + k : k);
			if (g(id)) nuSetValue(id, res.values[k]);
		});
		(res.js || []).forEach(function (js) { try { globalEval(js); } catch (e) { reportError(e.message, 'PHP nuAddJavascript'); console.error(e); } });
		nuDisplayError(res);
	}
	N.applyResult = applyResult;

	function saveFile(f) {
		var url = URL.createObjectURL(f.blob), a = document.createElement('a');
		a.href = url; a.download = f.name; a.className = 'd-none';
		document.body.appendChild(a); a.click(); a.remove();
		setTimeout(function () { URL.revokeObjectURL(url); }, 60000);
	}
	// options === true: nuBeforeSave first, false stops it (nuBuilder Forte 4.9)
	function beforeSaveOk(run) {
		if (run !== true || typeof window.nuBeforeSave !== 'function') return true;
		try { return window.nuBeforeSave() !== false; } catch (x) { reportError(x.message, 'nuBeforeSave'); return false; }
	}
	/** What nuBuilder Forte posts with a procedure: the breadcrumb's plain values (nuHash()['title'], ['rows'] …) and, from an
	 *  edit form, nuFORM.data() (nuHash()['nuFORMdata']). */
	function procData(code) {
		var b = crumbBase(), crumb = {};
		Object.keys(b).forEach(function (k) { var v = b[k]; crumb[k] = (typeof v === 'string' || typeof v === 'number') ? v : ''; });
		crumb.params = '';
		var d = {code: code, values: N.mode === 'edit' ? mainValues() : {}, crumb: crumb};
		if (N.mode === 'edit') { try { d.formdata = nuFORM.data(); } catch (x) { /* a sub-form without data */ } }
		return d;
	}
	api('nuRunPHPHidden', function (code, options) {
		if (!beforeSaveOk(options)) return Promise.resolve(null);
		return call('php', procData(code)).then(function (res) {
			if (res && res.file) { saveFile(res.file); return res; }
			applyResult(res); return res;
		});
	});
	api('nuRunPHPHiddenWithParams', function (code, paramName, params, runBeforeSave) {
		if (!beforeSaveOk(runBeforeSave)) return Promise.resolve(null);
		nuSetProperty(paramName, btoa(unescape(encodeURIComponent(JSON.stringify(params)))));
		return nuRunPHPHidden(code);
	});
	// nuBuilder Forte: iFrame = id of an iframe that shows the output instead of a new window; runBeforeSave = nuBeforeSave() first
	api('nuRunPHP', function (code, iFrame, runBeforeSave) {
		if (!beforeSaveOk(runBeforeSave)) return Promise.resolve(null);
		var fr = iFrame ? (parent.document.getElementById(iFrame) || g(iFrame)) : null;
		var w = fr ? null : window.open('', '_blank');          // opened at once: a window opened after the answer is a blocked pop-up
		// the output gets a page of its own (?p=runphp&i=<token>, as nuBuilder Forte's nurunphp.php?i=): a procedure can link to
		// itself with more parameters ($_SERVER['REQUEST_URI'] . '&x=1') and a reload runs it again
		return call('php', $.extend(procData(code), {window: 1})).then(function (res) {
			if (res && res.file) { if (w) w.close(); saveFile(res.file); return; }       // a download: no window needed
			applyResult(res);
			if (res && res.url) {
				var url = new URL(res.url, location.href).href;
				if (fr) fr.src = url; else if (w) w.location.href = url;
				return;
			}
			if (fr) fr.srcdoc = (res && res.output) || '';
			else if (w) { w.document.open(); w.document.write((res && res.output) || ''); w.document.close(); }
		});
	});

	api('nuAjax', function (obj, success) {
		var map = {getlookupcode: 'lookupcode', getlookupid: 'lookupid', runhiddenphp: 'php'};
		var action = map[obj.call_type] || obj.call_type;
		var values = {};
		arr(obj.hash).forEach(function (p) { if (Array.isArray(p)) values[p[0]] = p[1]; });
		return call(action, {object_id: obj.object_id, code: obj.code, pk: obj.primary_key || obj.pk, values: values}).then(function (data) {
			data.target = obj.target;
			if (success) success(data);
		});
	});

