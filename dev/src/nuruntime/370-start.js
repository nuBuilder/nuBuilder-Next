	// =================================================================== start (after the form's own scripts)
	window.nuNextStart = function () {
		callHook('nuBeforeAddActionButtons');
		$('#nuSaveButton').on('click', function () { window.nuSaveAction(); });
		$('#nuDeleteButton').on('click', function () { window.nuDeleteAction(); });
		$('#nuCloneButton').on('click', function () { window.nuCloneAction(); });
		$('#nuNewButton, #nuAddButton').on('click', function () {
			// in a lookup window the new record stays in the lookup (and is chosen after saving)
			var lo = new URL(window.location.href).searchParams.get('lo');
			var extra = N.lookup ? $.extend({lookup: N.lookup}, lo ? {lo: lo} : {}) : (N.iframe ? {iframe: '1'} : {});
			go(function () { return formUrl(N.form.id, '-1', extra); });
		});
		$('#nuJsErrors').on('click', function () { nuMessage(M.js_errors || 'JS errors', N.jsErrors.map(esc)); });
		$(document).on('click', '.nuPhAdd', function () { $('#nuAddButton').trigger('click'); });   // phones: Add next to the search

		if (N.mode !== 'edit') document.documentElement.classList.remove('nuFormTabPending');   // a list page has no tabs to restore
		if (N.mode === 'edit') {
			if (window.nuOnEditorLoad) { try { window.nuOnEditorLoad(); } catch (x) { reportError(x.message, 'nuOnEditorLoad'); } }
			else $('#nuEditForm textarea.nuEditor').each(function () { nuInitTinyMCE(this.id); });
			// the last tab only for the same record shown again: after Save, a reload, back / forward or another layout of it –
			// a record opened anew (from the list, a link, a lookup …) starts on the first tab
			var sameRecord = function () {
				if (N.saved) return true;
				var nav = window.performance && performance.getEntriesByType ? performance.getEntriesByType('navigation')[0] : null;
				if (nav && (nav.type === 'reload' || nav.type === 'back_forward')) return true;
				try {
					var ref = new URL(document.referrer), here = new URL(window.location.href);
					return ref.origin === here.origin && ref.pathname === here.pathname && ref.searchParams.get('p') === 'form'
						&& ref.searchParams.get('f') === N.form.id && ref.searchParams.get('r') === String(N.record_id);
				} catch (x) { return false; }
			};
			try {
				var t = sessionStorage.getItem(tabKey());
				if (sameRecord() && t !== null && g('nuTab' + t) && $(g('nuTab' + t)).parent().is(':visible')) nuSelectTab(+t);
				else sessionStorage.removeItem(tabKey());
			} catch (x) { /* ignore */ }
			document.documentElement.classList.remove('nuFormTabPending');   // nutheme.js hid the tabs until here
			// nuBuilder's Notes form: an existing note with an empty editor but text on the tab Additional opens that tab
			var only = {nunotes: ['not_content', 'not_text']}[N.form.id];
			if (only && !N.is_new) {
				var empty = function (v) { return String(v || '').replace(/<[^>]*>/g, '').replace(/&nbsp;|\u00a0/g, ' ').trim() === ''; };
				var other = g(only[1]), pane = other && other.closest('.nuTabPane');
				var tb = pane && g(pane.getAttribute('aria-labelledby'));
				if (tb && !tb.classList.contains('active') && empty(nuGetValue(only[0])) && !empty(nuGetValue(only[1]))) { N.autoTab = true; nuSelectTab(tb); }
			}
			// the Notes form's text on the tab Additional takes the free height of the window like the editor on Notes
			if (N.form.id === 'nunotes') fitTextToWindow(g('not_text'), g('nuw_not_content'));
			try {
				var pending = JSON.parse(sessionStorage.getItem('nuNextPendingJs') || '[]');
				sessionStorage.removeItem('nuNextPendingJs');
				pending.forEach(function (js) { try { globalEval(js); } catch (e) { reportError(e.message, 'After Save'); } });
			} catch (x) { /* ignore */ }
			if (N.saved) {
				nuToast(M.saved || 'Saved.', 'success');
				var u = new URL(window.location.href);
				u.searchParams.delete('saved');
				history.replaceState(null, '', u.toString());
			}
		}
		try {   // notice handed over from the previous page (e.g. "deleted" before going back to the browse)
			var nt = JSON.parse(sessionStorage.getItem('nuNextToast') || 'null');
			sessionStorage.removeItem('nuNextToast');
			if (nt && nt[2] && nt[2].undo && Date.now() - (nt[2].t || 0) < 60000) undoToast(nt[0], nt[2].undo);   // recycle bin
			else if (nt) nuToast(nt[0], nt[1]);
		} catch (x) { /* ignore */ }
		if (N.mode === 'browse' && N.lookup && (!window.nuHasKeyboard || window.nuHasKeyboard())) $('#nuSearchField').trigger('focus');   // no on-screen keyboard on touch screens
		if (N.mode === 'browse' && N.lookup) lookupFrame();
		if (N.mode === 'browse') applyNoSearchMarks(currentNoSearch());
		if (N.mode === 'edit') markCodeFields(document);
		buildOptionsMenu();
		initSelects(document);
		// nuBuilder calculates calc objects once after building the edit form, without marking it as edited
		if (N.mode === 'edit' && typeof window.nuCalculateForm === 'function') {
			try { window.nuCalculateForm(false); } catch (x) { reportError(x.message, 'nuCalculateForm'); }
		}
		// object event onnuload (nuBuilder nuEvaluateOnLoadEvents): after the calc, before the load hooks; "(this)" / "this." name the
		// object by its id, as nuBuilder rewrites them – B7 of the gap analysis
		if (N.mode === 'edit') {
			$('#nuEditForm [onnuload]').each(function () {
				if (this.closest('template') || this.closest('.nuSubform')) return;
				var id = this.id, js = String(this.getAttribute('onnuload') || '');
				if (!id || !js.trim()) return;
				try { (0, eval)(js.split('(this)').join('("#' + id + '")').split('this.').join(id + '.')); }   // eslint-disable-line no-eval
				catch (x) { reportError(x.message, 'onnuload ' + id); }
			});
		}
		// nuBuilder marks the search words in the browse list before the load hooks (nuBrowseTable → nuHighlightSearch)
		if (N.mode === 'browse' && typeof window.nuHighlightSearch === 'function') {
			try { window.nuHighlightSearch(); } catch (x) { reportError(x.message, 'nuHighlightSearch'); }
		}
		// nuBuilder: global load hooks (setup header), then the form's nuOnLoad(formId, formCode)
		callHook(N.mode === 'browse' ? 'nuLoadBrowseGlobal' : 'nuLoadEditGlobal', N.form.id, N.form.code);
		// list columns shown as badges in design mode – before nuOnLoad, so the form's own nuBrowseStyleBadge call still wins
		if (N.browse && N.browse.badges) Object.keys(N.browse.badges).forEach(function (i) {
			try { window.nuBrowseStyleBadge(+i, N.browse.badges[i] && N.browse.badges[i].length ? N.browse.badges[i] : undefined); }
			catch (e) { if (N.dev_mode) console.warn('badge', i, e); }
		});
		winCarryRestore();
		try { callHook('nuOnLoad', N.form.id, N.form.code); } finally { $('#nuBrowseTable').removeClass('nuBrowseJsWait'); }   // rows shown after the form's code
		forteHidden();
		brRestore();                                                       // column widths dragged in this tab
		rdRestore();                                                       // rows whose details were open
		(function () {                                                    // PHP warnings: of this page (Before Edit …) and of the save that reloaded it
			var w = (N.php_warnings || []).slice(), kept = nuStore.session.get('nuNextPhpWarn');
			if (kept) { nuStore.session.remove('nuNextPhpWarn'); try { w = JSON.parse(kept).concat(w); } catch (x) { /* expected: not JSON */ } }
			phpWarnings(w);
		})();
		if (typeof window.nuNextPageHook === 'function') window.nuNextPageHook();   // Global JS: nuOnPageLoad / nu:pageload after nuOnLoad
		if (N.mode === 'edit') {                                            // nuAfterSave of the save that reloaded this page
			try {
				var as = sessionStorage.getItem('nuNextAfterSave');
				if (as !== null) {
					sessionStorage.removeItem('nuNextAfterSave');
					if (as === N.form.id + '|' + N.record_id) {
						window.nuTimesSaved = (window.nuTimesSaved || 0) + 1;
						callHook('nuAfterSaveGlobal');
						if (window.nuAfterSave) { try { window.nuAfterSave(); } catch (x) { reportError(x.message, 'nuAfterSave'); } }
					}
				}
			} catch (x) { /* ignore */ }
		}
		// nuBuilder ends nuBuildForm with nuSetSaved(true): values set by the form's own code while loading (nuSetValue …) do not
		// count as an edit – Save stays normal and leaving does not ask. The fields keep their nuEdited mark, so they are still saved.
		if (N.mode === 'edit' && N.edited) {
			N.edited = false;
			$('#nuSaveButton').removeClass('nuSaveEdited');
			callHook('nuOnSetSaved', true);
		}
		// tooltip icons (nu-tooltip-icon attribute, assets/js/nu/nutooltip.js) – after nuOnLoad, which may set nuOnToolTipIcon or attributes
		if (typeof window.nuAttachToolTip === 'function') { try { window.nuAttachToolTip(); } catch (x) { reportError(x.message, 'nuAttachToolTip'); } }
		// chart objects – after nuOnLoad, so the form's code can still set the data arrays
		if (N.mode === 'edit') initCharts(document);
		// form buttons: text colour on backgrounds of the form's CSS, tooltip for cut-off labels – after nuOnLoad, which may restyle them
		if (N.mode === 'edit') fitButtons(document);
		// read-only text fields cut their value: wider where the layout has room, else the value as tooltip – after nuOnLoad
		if (N.mode === 'edit') fitReadonly(document);
		if (N.mode === 'edit') { fitCanvases(); watchCanvases(); }   // canvas as tall as its objects show
		// code snippets: Source code as an inline code editor – after nuOnLoad, which may disable the fields
		if (N.mode === 'edit') inlineCodeFields();
		// phones (review F1): fixed-position forms stack their objects – after nuOnLoad, which may switch it off
		phoneStack();
		if (N.mode === 'edit') fitWide();   // wide fixed-layout forms: page margins away while the form would scroll sideways
		if (N.mode === 'edit') growFrames();
		$('#nuRECORD.nuEditJsWait').removeClass('nuEditJsWait');   // the record shown once the form's code and the layout ran (pages/nuform.php)
		if (typeof window.nuHScrollScan === 'function') window.nuHScrollScan();   // usability review G5: tabs of three rows or more on phones
		// first field gets the focus (mouse / trackpad only, see nuFocusFirst in assets/js/nuapp.js) – after nuOnLoad, which may set its own
		// (in a popup yes, in an iframe object embedded in another form no – it would take the focus away from that form)
		var embedded = false;
		if (N.iframe) { try { embedded = !window.frameElement || !window.frameElement.closest('.modal'); } catch (x) { embedded = true; } }
		if (N.mode === 'edit' && !embedded && window.nuFocusFirst) setTimeout(function () { window.nuFocusFirst(g('nuEditForm')); }, 0);
		$(document).on('change', '.nuColumnFilter', function () { if (this.form.requestSubmit) this.form.requestSubmit(); else this.form.submit(); });
		colFilterSelects(document);

		// unsaved changes: links, Back, breadcrumbs and run buttons ask Save / Discard / Keep editing; the browser's back button,
		// reload and closing the tab get the browser's own question (nuLeaveGuard, assets/js/nuapp.js)
		if (window.nuLeaveGuard) {
			// a launch form is never saved: leaving it does not ask, as nuBuilder Forte's nuGetBreadcrumb
			if (N.mode === 'edit' && !N.iframe && (N.form || {}).type !== 'launch') window.nuLeaveGuard.add({
				dirty: function () { return !!N.edited; },
				discard: function () { N.edited = false; },
				save: function (go) { N.afterSave = go; nuSaveAction(); }
			});
		} else window.addEventListener('beforeunload', function (e) {
			if (N.edited && N.mode === 'edit' && !N.iframe && (N.form || {}).type !== 'launch') { e.preventDefault(); e.returnValue = M.unsaved || ''; }
		});
	};
