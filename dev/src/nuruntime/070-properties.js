	// =================================================================== properties
	// Form properties travel with the forms opened from here (kept on the server, the URL carries only a key: N.ps).
	// persist = true: global hash cookie until logout (also visible in nuBuilder with shared login).
	api('nuSetProperty', function (k, v, persist) {
		k = String(k);
		if (N.locked.indexOf(k) >= 0 && N.props[k] !== v) console.warn('nuSetProperty: "' + k + '" was set in PHP – the server keeps its value.');
		N.props[k] = v;
		propsDirty = true;
		if (persist === true) {
			N.globals[k] = v;
			// nuBuilder Forte's hook nuOnPropertySet(name, value) – after the server stored a hash cookie (persist = true only)
			return call('global', {name: k, value: v}).then(function (res) { if (res && res.globals) { N.globals = res.globals; callHook('nuOnPropertySet', k, v); } else nuDisplayError(res); return res; });
		}
	});
	api('nuGetProperty', function (k) {
		if (k in N.props) return N.props[k];
		if (k in N.globals) return N.globals[k];
		var c = nuCurrentProperties();
		return k in c ? c[k] : undefined;
	});
	api('nuDeleteProperty', function (k) {
		delete N.props[k];
		propsDirty = true;
		if (k in N.globals) {
			delete N.globals[k];
			return call('global', {name: k, 'delete': true});
		}
	});
	api('nuGetGlobalProperties', function () { return $.extend({}, N.globals); });

	/** Runs fn(scopeKey) with a server scope that holds the current properties (stored first when they changed here). */
	// from an edit form the opened form also gets this record's fields as hash variables (below its own values), as nuBuilder
	// Forte's nuHashFromEditForm; from a list nothing – a scope that carries an older caller is replaced, so it is not passed on
	// in a frame (a run object showing a form) the fields come from the page around it, as Forte's getform requests take
	// parent.nuHashFromEditForm() – a framed list that reloads (nuGetBreadcrumb(0)) sees the current values of the outer form's filters
	function outerEdit() {
		try {
			var P = window.parent !== window ? window.parent.nuNext : null;
			return P && P.mode === 'edit' && P.form && typeof P.mainValues === 'function' ? P : null;
		} catch (x) { return null; }   // another origin
	}
	function withScope(fn) {
		var outer = outerEdit();
		var edit = (N.mode === 'edit' && !!N.form) || !!outer;
		if (!propsDirty && !edit && !N.caller) { fn(N.ps || ''); return; }
		var data = {};
		if (edit) { try { data.caller = outer ? outer.mainValues() : (N.mainValues ? N.mainValues() : {}); } catch (x) { data.caller = {}; } }
		call('props', data).then(function (res) {
			if (res && res.ps) {
				// the new scope while fn builds its URL (formUrl reads N.ps); this page keeps its own unless properties changed
				var keep = N.ps, dirty = propsDirty;
				N.ps = res.ps; propsDirty = false;
				try { fn(res.ps); } finally { if (!dirty) N.ps = keep; }
				return;
			}
			fn(N.ps || '');
		});
	}
	N.withScope = withScope;

	/** Opens a URL built with the current scope; target 'blank' opens the tab at once (pop-up blockers), a function gets the URL. */
	function go(build, target) {
		// 'blank': the tab is opened right after the click, not during it – during a Ctrl+click the browser would put it in the
		// background (it reads the Ctrl key of the running event); a moment later the click still allows the pop-up
		if (target === 'blank') {
			setTimeout(function () {
				var tab = window.open('', '_blank');
				if (!tab) { go(build, null); return; }        // pop-up blocked: open it here
				withScope(function () { tab.location.href = build(); });
			}, 0);
			return;
		}
		var w = null;
		withScope(function () {
			var url = build();
			if (typeof target === 'function') target(url);
			else if (w) w.location.href = url;
			else window.location.href = url;
		});
	}

