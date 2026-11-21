	// =================================================================== save / delete / clone
	/** The value the server drew into a field ('' when it came empty) – the browser fills colour, range and selects by itself. */
	function startValue(el) {
		if (el.type === 'checkbox') return el.defaultChecked ? '1' : '';
		if (el.tagName === 'SELECT') { for (var i = 0; i < el.options.length; i++) if (el.options[i].defaultSelected) return el.options[i].value; return ''; }
		if (el.tagName === 'TEXTAREA') return el.defaultValue;
		return el.getAttribute('value') || '';
	}
	/** nuBuilder saves a field of a new record only when it was edited or came with a value (core/nuform.js marks those as edited):
	 *  an untouched empty field is not sent, so its column keeps the default / NULL. */
	function untouchedNew(el) { return !el.classList.contains('nuEdited') && startValue(el) === ''; }

	function subformPayload() {
		var out = {};
		Object.keys(N.subforms || {}).forEach(function (sf) {
			var info = N.subforms[sf], rows = [];
			$('#' + sf + ' .nuSubformRow').each(function () {
				var p = this.getAttribute('data-nu-prefix'), values = {}, edited = [], newRow = !this.getAttribute('data-nu-primary-key');
				info.fields.forEach(function (f) {
					var el = g(p + f);
					if (!el) return;
					if (newRow && untouchedNew(el)) return;                     // new row: as nuBuilder
					values[f] = rawValue(el);
					if (el.classList.contains('nuEdited')) edited.push(f);
				});
				var del = g(p + 'nuDelete');
				var pk = this.getAttribute('data-nu-primary-key') || '';
				if (pk === '' && !edited.length) return;                      // untouched blank row
				rows.push({pk: pk, values: values, edited: edited, deleted: del && del.checked ? 1 : 0});
			});
			out[sf] = {rows: rows};
		});
		return out;
	}

	api('nuSaveAction', function (close) {      // nuBuilder: nuSaveAction(true) = Save & Close (back to the previous breadcrumb)
		syncEditors();
		var then = N.afterSave; N.afterSave = null;       // set by the leave dialog: where to go after a successful save
		var incomplete = $('#nuEditForm input[data-nu-format^="T|"]').filter(function () {
			if (this.closest('.nuSubformRow.nuRowDeleted')) return false;
			applyFormat(this, false);
			return !NuFormatLib.maskComplete(this.value, this.getAttribute('data-nu-format'));
		});
		if (incomplete.length) {
			var el0 = incomplete[0], o = (N.objects || {})[el0.id] || {};
			nuMessage([esc(String(M.mask_incomplete || '').replace('%s', o.label || el0.getAttribute('data-nu-field') || el0.id))]);
			try { el0.focus(); } catch (x) { /* ignore */ }
			return;
		}
		if (window.nuBeforeSaveGlobal) {                                    // nuBuilder: the global hook (Setup → header) first, false cancels
			var okG;
			try { okG = window.nuBeforeSaveGlobal(); } catch (x) { reportError(x.message, 'nuBeforeSaveGlobal'); return; }
			if (okG === false) return;
		}
		if (window.nuBeforeSave) {
			var ok;
			try { ok = window.nuBeforeSave(); } catch (x) { reportError(x.message, 'nuBeforeSave'); return; }
			if (ok === false) return;                                       // only false stops the save – a nuBeforeSave without return saves (Forte)
		}
		var btn = $('#nuSaveButton').prop('disabled', true);
		var edited = $('#nuEditForm .nuEdited').map(function () { return this.getAttribute('data-nu-prefix') ? null : this.getAttribute('data-nu-field'); }).get();
		var values = mainValues(), untouched = [];
		if (N.record_id === '-1' && !N.cloning) {                          // new record: untouched empty fields are not sent (as nuBuilder)
			Object.keys(values).forEach(function (f) {
				var el = g(f), o = (N.objects || {})[f];
				if (o && o.type === 'editor') { if (edited.indexOf(f) < 0) edited.push(f); return; }   // nuBuilder's editor marks itself edited: saved as ''
				if (el && untouchedNew(el)) { delete values[f]; untouched.push(f); }
			});
		}
		var subs = subformPayload();
		// untouched: names only – still hash variables ('') of Before / After Save, as Forte's hash holds every field
		var payload = {values: values, edited: edited, untouched: untouched, subforms: subs, clone: !!N.cloning, clone_from: N.cloning ? (N.cloneFrom || '') : ''};
		if (N.cc && !N.cloning && N.record_id !== '-1') payload.cc = ccPayload(edited, subs);        // concurrent edits
		if (window.nuNextRules) {                                           // validation rules: errors at once, the server checks again
			var pre = window.nuNextRules.check(values, subs);
			if (pre.errors.length) { btn.prop('disabled', false); nuDisplayError({errors: pre.errors}); return; }
		}
		var send = function () { call('save', payload).then(function (res) {
			btn.prop('disabled', false);
			if (!res.ok && res.conflict) { N.afterSave = then; showConflict(res.conflict, close); return; }
			if (!res.ok && res.rules_warn && res.rules_warn.length && !(res.errors || []).length && window.nuNextRules) {   // warnings: save anyway?
				window.nuNextRules.askWarnings(res.rules_warn, function () {
					payload.rules_ok = (payload.rules_ok || []).concat(res.rules_warn.map(function (w) { return w.id; }));
					btn.prop('disabled', true);
					send();
				});
				return;
			}
			if (!res.ok) { applyResult(res); return; }
			try { if (res.js && res.js.length) sessionStorage.setItem('nuNextPendingJs', JSON.stringify(res.js)); } catch (x) { /* ignore */ }
			N.edited = false;
			var afterSaveNow = function () {
				callHook('nuAfterSaveGlobal');
				if (window.nuAfterSave) { try { window.nuAfterSave(); } catch (x) { reportError(x.message, 'nuAfterSave'); } }
			};
			if (N.lookup && N.is_new && window.parent !== window && window.parent.nuNextLookupChosen) {   // added from a lookup: choose it
				afterSaveNow();
				try { window.parent.nuNextLookupChosen(N.lookup, res.record_id); return; } catch (x) { reportError(x.message, 'lookup'); }
			}
			if (then) { afterSaveNow(); if (typeof then === 'function') then(); else window.location.href = then; return; }
			if (close === true) {
				afterSaveNow();
				var stay = false;                                              // nuBuilder: nuCloseAfterSaveGlobal() returning false keeps the form open
				if (typeof window.nuCloseAfterSaveGlobal === 'function') { try { stay = window.nuCloseAfterSaveGlobal(true) === false; } catch (x) { reportError(x.message, 'nuCloseAfterSaveGlobal'); } }
				var bc = N.breadcrumbs || [];
				if (!stay) for (var bi = bc.length - 2; bi >= 0; bi--) if (bc[bi].url) { window.location.replace(bc[bi].url); return; }
				if (stay) return;
			}
			// nuBuilder runs nuAfterSaveGlobal / nuAfterSave on the rebuilt form: the reloaded page calls them after nuOnLoad
			var openId = res.open_id !== undefined && res.open_id !== null ? String(res.open_id) : res.record_id;   // nuReturnNewRecord() in After Save
			nuStore.session.set('nuNextAfterSave', N.form.id + '|' + openId);
			winCarrySave(openId);
			var u = new URL(window.location.href);
			u.searchParams.set('r', openId);
			u.searchParams.set('saved', '1');
			window.location.replace(u.toString());
		}, function (err) { btn.prop('disabled', false); nuMessage([esc(err.message)]); }); };
		send();
	});

	// nuBuilder Forte keeps the page after a save (one-page app): variables form code put on window while the user worked (window.x = …
	// in an event) are still there for nuAfterSave. Here the save reloads the page – such variables with plain values (text, number,
	// yes / no, small JSON) travel with it; what the page's own scripts define again is left alone.
	var winKeys0 = null;
	function winCarrySave(openId) {
		if (!winKeys0) return;
		var vars = {}, size = 0;
		Object.keys(window).forEach(function (k) {
			if (winKeys0[k] || /^(on|webkit|nuNext|Nu[A-Z]|CodeMirror|tinymce|tinyMCE|Uppy|ApexCharts|google)/.test(k)) return;   // libraries loaded later (NuCodeTools …) are no form variables
			var v; try { v = window[k]; } catch (x) { return; }
			var t = typeof v;
			if (t === 'function' || t === 'undefined' || t === 'symbol' || t === 'bigint') return;
			if (v !== null && t === 'object') { var proto = Object.getPrototypeOf(v); if (proto !== Object.prototype && proto !== Array.prototype) return; }
			if (v !== null && t === 'object' && hasFn(v, 0)) return;            // an object with functions would come back without them
			var j; try { j = JSON.stringify(v); } catch (x) { return; }
			if (j === undefined || (size += j.length) > 50000) return;
			vars[k] = v;
		});
		try { if (Object.keys(vars).length) sessionStorage.setItem('nuNextWinCarry', JSON.stringify({to: N.form.id + '|' + openId, vars: vars})); } catch (x) { /* storage full / blocked */ }
	}
	function hasFn(v, d) {
		if (d > 4) return false;
		for (var k in v) { if (!Object.prototype.hasOwnProperty.call(v, k)) continue; var x = v[k]; if (typeof x === 'function') return true; if (x
			&& typeof x === 'object' && hasFn(x, d + 1)) return true; }
		return false;
	}
	function winCarryRestore() {
		winKeys0 = {};
		Object.keys(window).forEach(function (k) { winKeys0[k] = 1; });
		var raw = null;
		try { raw = sessionStorage.getItem('nuNextWinCarry'); if (raw !== null) sessionStorage.removeItem('nuNextWinCarry'); } catch (x) { return; }
		if (!raw || N.mode !== 'edit') return;
		try {
			var c = JSON.parse(raw);
			if (!c || c.to !== N.form.id + '|' + N.record_id) return;
			Object.keys(c.vars || {}).forEach(function (k) { if (!(k in window)) window[k] = c.vars[k]; });
		} catch (x) { /* broken entry */ }
	}

	api('nuDeleteAction', function () {
		$.confirm || typeof window.nuConfirmAsk === 'function' ? confirmDelete() : (window.confirm(M.delete_confirm) && doDelete());
	});
	function confirmDelete() {
		// our own dialog (review E4): names the record and the sub-form rows deleted with it, Cancel has the focus
		if (typeof window.nuConfirmAsk === 'function') {
			var bc = N.breadcrumbs || [], name = bc.length ? String(bc[bc.length - 1].title || '').trim() : '';
			var details = [];
			Object.keys(N.subforms || {}).forEach(function (sf) {
				var n = $('#' + sf + ' .nuSubformRow[data-nu-primary-key]').filter(function () { return this.getAttribute('data-nu-primary-key') !== ''; }).length;
				if (!n) return;
				var lbl = $('#label_' + sf).text().trim() || sf;
				details.push(ccFmt(M.delete_with, String(n), lbl));
			});
			window.nuConfirmAsk(name && name !== (N.form && N.form.description) ? ccFmt(M.delete_named, name) : M.delete_confirm, doDelete, M.delete, 'btn btn-danger',
				{title: M.delete_title, details: details, note: M.delete_undo, cancelFirst: true});
			return;
		}
		$.confirm({
			title: M.delete, content: M.delete_confirm, useBootstrap: false, boxWidth: '360px', type: 'red',
			buttons: {confirm: {text: M.delete, btnClass: 'btn-red', action: doDelete}, cancel: {text: M.cancel}}
		});
	}
	function doDelete(force) {
		if (force !== true && window.nuBeforeDelete && window.nuBeforeDelete() === false) return;
		call('delete', N.cc ? (force === true ? {cc_force: 1} : {cc_h: N.cc.h}) : {}).then(function (res) {
			if (!res.ok && res.conflict && res.conflict.delete) {            // changed since it was opened
				var q = res.conflict.by ? ccFmt(M.cc_delete_by, res.conflict.by, res.conflict.at) : M.cc_delete_anon;
				if (window.confirm(q)) doDelete(true);
				return;
			}
			if (!res.ok) { applyResult(res); return; }
			N.edited = false;
			window.last_action = 'delete';                                    // nuBuilder sets it before its after-delete hooks
			callHook('nuAfterDeleteGlobal');
			callHook('nuAfterDelete');
			try { sessionStorage.setItem('nuNextToast', JSON.stringify([M.deleted || 'Record deleted.', 'success', res.recycle ? {undo: res.recycle, t: Date.now()} : null])); } catch (x) { /* ignore */ }
			var bc = N.breadcrumbs || [];
			var back = bc.length > 1 && bc[bc.length - 2].url ? bc[bc.length - 2].url : N.base + '?p=home';
			window.location.replace(back);
		});
	}

