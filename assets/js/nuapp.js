/* nuBuilder Next UI behaviour – no inline scripts (CSP: script-src 'self') */
(function () {
	'use strict';

	var $ = function (sel, root) { return (root || document).querySelector(sel); };
	var $$ = function (sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); };

	// ---------------------------------------------------------------- rows marked for removal (0.8.139)
	// Instead of removing a row at once, the remove button crosses it out (class nuRowRemoved, nuapp.css) and turns into
	// "Undo removal"; the page leaves crossed-out rows out when it saves. Used by the access level's form rights, the form
	// builder lists and the attribute editor. labels: {remove, undo, pending}; on: true / false, or omitted to toggle.
	// The button's <i> switches between bi-x-circle / bi-trash and bi-arrow-counterclockwise. Returns the new state.
	window.nuToggleRemoved = function (row, btn, labels, on) {
		labels = labels || {};
		on = on === undefined ? !row.classList.contains('nuRowRemoved') : !!on;
		row.classList.toggle('nuRowRemoved', on);
		if (on) row.title = labels.pending || ''; else row.removeAttribute('title');
		$$('input, select, textarea, button, a[href]', row).forEach(function (c) {      // crossed-out fields leave the tab order
			if (btn && (c === btn || btn.contains(c))) return;
			if (on) { if (!c.hasAttribute('data-nu-tab')) c.setAttribute('data-nu-tab', c.getAttribute('tabindex') || ''); c.setAttribute('tabindex', '-1'); }
			else if (c.hasAttribute('data-nu-tab')) { var t = c.getAttribute('data-nu-tab'); if (t === '') c.removeAttribute('tabindex'); else c.setAttribute('tabindex', t); c.removeAttribute('data-nu-tab'); }
		});
		if (btn) {
			var icon = btn.querySelector('i');
			if (icon) {
				if (!btn.hasAttribute('data-nu-icon')) btn.setAttribute('data-nu-icon', icon.className);
				icon.className = on ? 'bi bi-arrow-counterclockwise' : btn.getAttribute('data-nu-icon');
			}
			if (!btn.hasAttribute('data-nu-danger')) btn.setAttribute('data-nu-danger', btn.className);
			btn.className = on ? btn.getAttribute('data-nu-danger').replace(/\bbtn-outline-danger\b/, 'btn-outline-primary').replace(/\btext-danger\b/, 'text-primary') : btn.getAttribute('data-nu-danger');
			var label = on ? (labels.undo || 'Undo') : (labels.remove || 'Remove');
			btn.title = label;
			btn.setAttribute('aria-label', label);
			btn.setAttribute('aria-pressed', on ? 'true' : 'false');
		}
		return on;
	};

	// ---------------------------------------------------------------- list search: focus on desktop (not on phones/tablets – no keyboard pops up)
	(function () {
		var q = $('[data-nu-autofocus]');
		if (!q || !matchMedia('(min-width: 992px) and (hover: hover) and (pointer: fine)').matches) return;
		var a = document.activeElement;
		if (a && a !== document.body && a !== document.documentElement) return;   // something else already has the focus
		q.focus({ preventScroll: true });
		try { q.setSelectionRange(q.value.length, q.value.length); } catch (e) { /* type=search may not support it */ }
	})();

	// ---------------------------------------------------------------- edit forms: focus the first field (0.8.145)
	// Only with mouse / trackpad: on touch screens a focused field would pop up the on-screen keyboard, so there nothing gets the
	// focus automatically (also autofocus attributes are dropped). nuFocusFirst(root) is used by the form runtime after nuOnLoad.
	window.nuHasKeyboard = function () { return matchMedia('(hover: hover) and (pointer: fine)').matches; };
	var focusSkip = /^(hidden|button|submit|reset|image|file|range|color)$/;
	window.nuFocusFirst = function (root) {
		if (!window.nuHasKeyboard()) return false;
		var a = document.activeElement;
		if (a && a !== document.body && a !== document.documentElement) return false;   // already focused (autofocus, nuSetFocus, …)
		root = root || document;
		var auto = $('[autofocus]', root), list = auto ? [auto] : $$('input, select, textarea', root);
		for (var i = 0; i < list.length; i++) {
			var el = list[i], ts = el.tomselect, box = ts ? ts.wrapper : el;
			if (focusSkip.test(el.type) || el.disabled || el.readOnly || el.classList.contains('nuReadonly')) continue;
			if (!ts && el.tabIndex < 0) continue;
			if (el.closest('.CodeMirror, .nuSubform, .nuSubformTable, .nuRowRemoved, .modal, .dropdown-menu, [data-nu-prefix]')) continue;
			if (!(box.offsetWidth || box.offsetHeight || box.getClientRects().length)) continue;               // hidden (other tab, d-none, …)
			if (ts) {                                   // searchable select: focus without opening its list
				var open = ts.settings.openOnFocus;
				ts.settings.openOnFocus = false;
				ts.focus();
				setTimeout(function () { ts.settings.openOnFocus = open; }, 50);
			} else el.focus({ preventScroll: true });
			return true;
		}
		return false;
	};
	(function () {
		if (!window.nuHasKeyboard()) {
			$$('[autofocus]').forEach(function (el) { el.removeAttribute('autofocus'); });
			var a = document.activeElement;
			if (a && a.matches && a.matches('input, select, textarea')) a.blur();
			return;
		}
		var f = $('form[data-nu-focus-first]');
		if (f) window.nuFocusFirst(f);
	})();

	// ---------------------------------------------------------------- unsaved changes: ask before leaving (0.8.146)
	// Sources register with nuLeaveGuard.add({dirty(), save(then) | null, discard()}). A click on a link (Back, breadcrumbs, menu, …)
	// while a source is dirty opens the dialog #nuLeave (core/nulayout.php): Save (then go on), Discard changes, Keep editing.
	// Browser back / reload / closing the tab can only show the browser's own question (beforeunload).
	// form[data-nu-guard] registers itself: dirty after the user changed a field (also fields outside with form="id");
	// form.nuGuardDirty = function () {…} replaces that test. Save submits the form (through its Save button) with _nu_then = the link,
	// and the server goes there instead of back to the record when the save succeeded (nu_redirect, core/nuapp.php).
	var guards = [], leaving = false, userActed = false;
	['pointerdown', 'keydown'].forEach(function (ev) { document.addEventListener(ev, function () { userActed = true; }, true); });
	function runGo(go) { leaving = true; if (typeof go === 'function') go(); else window.location.href = go; setTimeout(function () { leaving = false; }, 1500); }
	window.nuLeaveGuard = {
		add: function (g) { guards.push(g); return g; },
		isDirty: function () { return !leaving && guards.some(function (g) { return g.dirty(); }); },
		leave: function () { leaving = true; setTimeout(function () { leaving = false; }, 1500); },   // next navigation without asking
		/** go: URL or function. opt.nosave: no Save button (e.g. another form of the page is sent). */
		ask: function (go, opt) {
			opt = opt || {};
			var d = leaving ? [] : guards.filter(function (g) { return g.dirty(); });
			if (!d.length) { runGo(go); return; }
			var m = $('#nuLeave'), canSave = !opt.nosave && d.length === 1 && typeof d[0].save === 'function';
			var discard = function () { d.forEach(function (g) { if (g.discard) g.discard(); }); runGo(go); };
			if (!m || !nuBs.ready()) { if (window.confirm(m ? m.getAttribute('data-text-nosave') : 'Leave without saving?')) discard(); return; }
			$('.js-leave-text', m).textContent = m.getAttribute(canSave ? 'data-text' : 'data-text-nosave');
			var sb = $('[data-nu-leave="save"]', m), db = $('[data-nu-leave="discard"]', m);
			sb.hidden = !canSave;
			var modal = nuBs.modal(m);
			sb.onclick = function () { modal.hide(); d[0].save(go); };
			db.onclick = function () { modal.hide(); discard(); };
			m.addEventListener('shown.bs.modal', function () { (canSave ? sb : db).focus(); }, { once: true });
			modal.show();
		}
	};
	// links of the page (not new tabs, downloads, anchors, Ctrl/Shift-click)
	document.addEventListener('click', function (e) {
		if (e.defaultPrevented || e.button !== 0 || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) return;
		var a = e.target.closest && e.target.closest('a[href]');
		if (!a || a.hasAttribute('download') || a.hasAttribute('data-nu-noguard') || (a.target && a.target !== '_self')) return;
		var href = a.getAttribute('href');
		if (!href || href.charAt(0) === '#' || /^(javascript|mailto|tel):/i.test(href) || a.hasAttribute('data-bs-toggle')) return;
		var url = new URL(a.href, window.location.href);
		if (url.origin !== window.location.origin) return;
		if (!window.nuLeaveGuard.isDirty()) return;
		e.preventDefault();
		window.nuLeaveGuard.ask(url.href);
	});
	window.addEventListener('beforeunload', function (e) {
		if (window.nuLeaveGuard.isDirty()) { e.preventDefault(); e.returnValue = ''; }
	});
	// sending a form leaves the page on purpose (also form.submit() from scripts, which fires no submit event)
	var nativeSubmit = HTMLFormElement.prototype.submit;
	HTMLFormElement.prototype.submit = function () { if ((this.getAttribute('target') || '_self') === '_self') window.nuLeaveGuard.leave(); return nativeSubmit.call(this); };
	document.addEventListener('submit', function (e) {
		var f = e.target;
		if (e.defaultPrevented || (f.getAttribute('target') || '_self') !== '_self' || (f.method || '').toLowerCase() === 'dialog') return;
		if (!f.hasAttribute('data-nu-guard') && window.nuLeaveGuard.isDirty()) {   // e.g. Clone while the record has unsaved changes
			e.preventDefault();
			var sub = e.submitter;
			window.nuLeaveGuard.ask(function () {
				if (sub && sub.name) { var i = document.createElement('input'); i.type = 'hidden'; i.name = sub.name; i.value = sub.value; f.appendChild(i); }
				nativeSubmit.call(f);
			}, { nosave: true });
			return;
		}
		window.nuLeaveGuard.leave();
	});
	$$('form[data-nu-guard]').forEach(function (f) {
		var changed = false;
		var mark = function (e) {
			var el = e.target;
			if (!el || !(f.contains(el) || el.form === f) || (el.closest && el.closest('[data-nu-noguard]'))) return;
			if (e.isTrusted || userActed) changed = true;
		};
		document.addEventListener('input', mark, true);
		document.addEventListener('change', mark, true);
		f.addEventListener('click', function (e) { if (e.target.closest('.js-remove-row, .js-add-form, .js-add-row')) changed = true; });
		window.nuLeaveGuard.add({
			dirty: function () { return typeof f.nuGuardDirty === 'function' ? !!f.nuGuardDirty() : changed; },
			discard: function () { changed = false; f.nuGuardDirty = null; },
			save: f.hasAttribute('data-nu-guard-nosave') ? null : function (then) {
				var t = f.querySelector('input[name="_nu_then"]') || f.appendChild(Object.assign(document.createElement('input'), { type: 'hidden', name: '_nu_then' }));
				t.value = typeof then === 'string' ? then.replace(/^https?:\/\/[^/]+/i, '') : '';
				var btn = $('[data-nu-save][form="' + f.id + '"]') || $('[data-nu-save]', f) || $('button[type="submit"][form="' + f.id + '"]') || $('button[type="submit"]', f);
				if (f.requestSubmit) f.requestSubmit(btn && btn.form === f ? btn : undefined); else f.submit();
			}
		});
	});

	// ---------------------------------------------------------------- datalist inputs: the arrow (right padding area) opens the FULL list
	// Browsers only list the options that match the current value, so with a value the list looked empty. As nuBuilder's
	// nuDatalistShowAllOnArrowClick: the value is moved into the placeholder, the field emptied and the list opened. Picking or
	// typing keeps the new value; otherwise (click into the text, Escape, leaving the field, submitting) the old value comes back.
	function dlOnArrow(el, x) {
		var r = el.getBoundingClientRect();
		return x >= r.right - parseFloat(getComputedStyle(el).paddingRight || '0');
	}
	function dlUsable(el) {
		return el instanceof HTMLInputElement && el.list && !el.disabled && !el.readOnly;
	}
	function dlRestore(el) {
		var st = el._nuDl;
		if (!st) return;
		el._nuDl = null;
		if (el.value === '') el.value = st.value;   // set directly: no input / change event, the form does not become "changed"
		if (st.placeholder === null) el.removeAttribute('placeholder'); else el.setAttribute('placeholder', st.placeholder);
	}
	document.addEventListener('click', function (e) {
		var el = e.target;
		if (!dlUsable(el)) return;
		if (!dlOnArrow(el, e.clientX)) { dlRestore(el); return; }   // click into the text: back to the value
		if (el._nuDl) { dlRestore(el); return; }                     // second click on the arrow closes it again
		if (el.value !== '') {
			el._nuDl = { value: el.value, placeholder: el.getAttribute('placeholder') };
			el.setAttribute('placeholder', el.value);
			el.value = '';
		}
		if (typeof el.showPicker === 'function') { try { el.showPicker(); } catch (err) { /* not supported for datalists */ } }
	});
	document.addEventListener('input', function (e) {                // option picked or typed: keep it
		var el = e.target;
		if (el instanceof HTMLInputElement && el._nuDl) {
			var ph = el._nuDl.placeholder;
			el._nuDl = null;
			if (ph === null) el.removeAttribute('placeholder'); else el.setAttribute('placeholder', ph);
		}
	});
	document.addEventListener('keydown', function (e) {
		if (e.key === 'Escape' && e.target instanceof HTMLInputElement && e.target._nuDl) dlRestore(e.target);
	});
	document.addEventListener('focusout', function (e) { if (e.target instanceof HTMLInputElement) dlRestore(e.target); });
	document.addEventListener('submit', function (e) {               // Enter while the list is open
		$$('input[list]', e.target).forEach(dlRestore);
	}, true);
	document.addEventListener('mousemove', function (e) {            // pointer over the arrow
		var el = e.target;
		if (el instanceof HTMLInputElement && el.list) el.style.cursor = dlUsable(el) && dlOnArrow(el, e.clientX) ? 'pointer' : '';
	});

	// ---------------------------------------------------------------- F1 = the documentation page of this page (0.8.528, core/nuhelplink.php)
	// <body data-nu-help="index.php?p=help#…">, opened in a new tab; pages without it leave F1 to the browser
	document.addEventListener('keydown', function (e) {
		if (e.key !== 'F1' || e.ctrlKey || e.metaKey || e.altKey || e.shiftKey) return;
		var url = document.body && document.body.getAttribute('data-nu-help');
		if (!url) return;
		e.preventDefault();
		window.open(url, '_blank', 'noopener');
	}, true);

	// ---------------------------------------------------------------- Ctrl+Shift+S (Mac: ⌃⇧S / ⌘⇧S) = Save on nuBuilder Next's own edit pages
	// (the button marked data-nu-save; nuBuilder form pages have their own shortcuts in nuruntime.js). Same key as in nuBuilder.
	(function () {
		var mac = /Mac|iPhone|iPad/i.test(navigator.platform || navigator.userAgent || '');
		var usable = function (b) { return b && !b.disabled && b.offsetParent !== null && !b.closest('fieldset[disabled]'); };
		$$('[data-nu-save]').forEach(function (b) {
			var label = (b.getAttribute('title') || b.textContent.trim()) + ' (' + (mac ? '⌃⇧S' : 'Ctrl+Shift+S') + ')';
			b.setAttribute('title', label);
		});
		document.addEventListener('keydown', function (e) {
			if (!(e.ctrlKey || e.metaKey) || !e.shiftKey || e.altKey || (e.key || '').toLowerCase() !== 's') return;
			var modal = $('.modal.show');
			var btn = modal ? $('[data-nu-save]', modal) : $$('[data-nu-save]').filter(function (b) { return !b.closest('.modal'); }).filter(usable)[0];
			if (!usable(btn)) return;
			e.preventDefault();
			if (document.activeElement && document.activeElement.blur) document.activeElement.blur();   // commit the field being edited
			btn.click();                                                                                 // same as a click: validation, confirm, busy state
		}, true);
	})();

	// ---------------------------------------------------------------- theme
	var THEME_ICONS = { light: 'bi-sun', dark: 'bi-moon-stars', auto: 'bi-circle-half' };

	function applyTheme(mode) {
		var dark = mode === 'dark' || (mode === 'auto' && matchMedia('(prefers-color-scheme: dark)').matches);
		document.documentElement.setAttribute('data-bs-theme', dark ? 'dark' : 'light');
		document.documentElement.setAttribute('data-nu-theme', mode);
		$$('.js-theme-icon').forEach(function (i) { i.className = 'bi ' + THEME_ICONS[mode] + ' js-theme-icon'; });
		$$('[data-theme-set]').forEach(function (b) { b.classList.toggle('active', b.getAttribute('data-theme-set') === mode); });
	}

	$$('[data-theme-set]').forEach(function (btn) {
		btn.addEventListener('click', function () {
			var mode = btn.getAttribute('data-theme-set');
			try { localStorage.setItem('nunext-theme', mode); } catch (e) {}
			applyTheme(mode);
		});
	});
	applyTheme(document.documentElement.getAttribute('data-nu-theme') || 'light');
	if (window.matchMedia) {
		matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function () {
			if (document.documentElement.getAttribute('data-nu-theme') === 'auto') applyTheme('auto');
		});
	}

	// ---------------------------------------------------------------- show / hide password
	$$('[data-pw-toggle]').forEach(function (btn) {
		btn.addEventListener('click', function () {
			var input = $(btn.getAttribute('data-pw-toggle'));
			if (!input) return;
			var show = input.type === 'password';
			input.type = show ? 'text' : 'password';
			var icon = $('i', btn);
			if (icon) icon.className = 'bi ' + (show ? 'bi-eye-slash' : 'bi-eye');
		});
	});

	// ---------------------------------------------------------------- clickable list rows (tr[data-href])
	document.addEventListener('click', function (e) {
		var tr = e.target.closest('tr[data-href]');
		if (!tr || e.target.closest('a, button, input, select, label, form')) return;   // own controls keep their action
		if (window.getSelection && String(window.getSelection()).length) return;          // user is selecting text
		if (e.ctrlKey || e.metaKey) window.nuOpenTabFront(tr.dataset.href);       // Ctrl+click: new tab in front (middle click: auxclick below, background)
		else window.location.href = tr.dataset.href;
	});
	document.addEventListener('auxclick', function (e) {
		var tr = e.target.closest('tr[data-href]');
		if (tr && e.button === 1 && !e.target.closest('a, button, form')) window.open(tr.dataset.href, '_blank');
	});

	// ---------------------------------------------------------------- filters submit on change
	$$('.js-autosubmit').forEach(function (sel) {
		sel.addEventListener('change', function () { if (sel.form) sel.form.submit(); });
	});

	// ---------------------------------------------------------------- buttons shown only once a file is chosen (Update page: Check package)
	$$('[data-nu-needs-file]').forEach(function (b) {
		var input = $(b.getAttribute('data-nu-needs-file'));
		if (!input) { b.classList.remove('d-none'); return; }
		var sync = function () { b.classList.toggle('d-none', !(input.files && input.files.length)); };
		input.addEventListener('change', sync);
		sync();   // also after the browser restores a chosen file (back button)
	});

	// ---------------------------------------------------------------- height of the sticky top bar as --nu-nav-h (0.8.318): sticky
	// bars below it (start page edit bar) stop under it instead of sliding behind it
	(function () {
		var nav = $('.nu-navbar');
		if (!nav) return;
		var set = function () { document.documentElement.style.setProperty('--nu-nav-h', nav.offsetHeight + 'px'); };
		set();
		if (window.ResizeObserver) new ResizeObserver(set).observe(nav); else window.addEventListener('resize', set);
	})();

	// ---------------------------------------------------------------- menus of the collapsed top bar (phones, 0.8.324): Bootstrap places them
	// without Popper (data-bs-popper="static") at the button's left edge, so the user menu ran off the right side – shift it into the screen
	document.addEventListener('shown.bs.dropdown', function (e) {
		var menu = e.target && e.target.parentNode ? e.target.parentNode.querySelector('.dropdown-menu.show') : null;
		if (!menu || menu.getAttribute('data-bs-popper') !== 'static') return;
		menu.style.left = '';
		menu.style.right = '';
		var vw = document.documentElement.clientWidth, gap = 8, r = menu.getBoundingClientRect();
		var shift = r.right > vw - gap ? r.right - (vw - gap) : (r.left < gap ? r.left - gap : 0);
		if (!shift) return;
		var left = parseFloat(window.getComputedStyle(menu).left) || 0;
		menu.style.right = 'auto';
		menu.style.left = (left - shift) + 'px';
		menu.style.maxWidth = (vw - 2 * gap) + 'px';
	});

	// ---------------------------------------------------------------- view only (admin role with "view", 0.8.323): the buttons of forms that
	// send something are switched off and a note says why – the server refuses the change anyway (NuAdmin::require)
	if (document.body.classList.contains('nuViewOnly')) {
		var voMain = $('main');
		if (voMain) {
			$$('form[method="post" i] [type="submit"], form[method="post" i] button:not([type]), [data-nu-save]', voMain).forEach(function (b) {
				b.disabled = true;
				b.title = document.body.getAttribute('data-nu-view-only') || '';
			});
			var voNote = document.createElement('div');
			voNote.className = 'alert alert-secondary small py-2 nuViewOnlyNote';
			voNote.innerHTML = '<i class="bi bi-eye me-1"></i>';
			voNote.appendChild(document.createTextNode(document.body.getAttribute('data-nu-view-only') || ''));
			voMain.insertBefore(voNote, voMain.firstChild);
		}
	}

	// ---------------------------------------------------------------- copy button: data-nu-copy="#field" puts the field's value on the clipboard (0.8.312)
	$$('[data-nu-copy]').forEach(function (b) {
		b.addEventListener('click', function () {
			var f = $(b.getAttribute('data-nu-copy'));
			if (!f) return;
			var icon = b.querySelector('.bi');
			var ok = function () { if (icon) { icon.className = 'bi bi-clipboard-check'; setTimeout(function () { icon.className = 'bi bi-clipboard'; }, 1500); } };
			if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(f.value).then(ok, function () { f.select(); });
			else { f.select(); try { if (document.execCommand('copy')) ok(); } catch (e) { /* selected – Ctrl+C */ } }
		});
	});

	// ---------------------------------------------------------------- copy a text: data-nu-copy-text="…" (form info, 0.8.435)
	function nuCopyText(text) {
		if (navigator.clipboard && window.isSecureContext) return navigator.clipboard.writeText(text).then(function () { return true; }, function () { return fallback(); });
		return Promise.resolve(fallback());
		function fallback() {   // http without TLS: a hidden text area and execCommand
			var ta = document.createElement('textarea');
			ta.value = text; ta.setAttribute('readonly', ''); ta.className = 'nuCopyBuffer';
			document.body.appendChild(ta); ta.select();
			var ok = false;
			try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
			ta.remove();
			return ok;
		}
	}
	document.addEventListener('click', function (e) {
		var b = e.target.closest && e.target.closest('[data-nu-copy-text]');
		if (!b) return;
		e.preventDefault();
		nuCopyText(b.getAttribute('data-nu-copy-text') || '').then(function (ok) {
			var icon = b.querySelector('.bi');
			if (!icon) return;
			var was = icon.className.replace(/\bbi-clipboard(-check|-x)?\b/, '').trim();
			icon.className = was + ' ' + (ok ? 'bi-clipboard-check text-success' : 'bi-clipboard-x text-danger');
			b.classList.add('nuCopied');
			setTimeout(function () { icon.className = was + ' bi-clipboard'; b.classList.remove('nuCopied'); }, 1500);
		});
	});

	// ---------------------------------------------------------------- print view: Copy puts the table on the clipboard
	// as HTML (pastes as a table into Excel, Word, e-mail) and as tab-separated text; only visible columns, no filter row
	$$('[data-nu-copy-table]').forEach(function (b) {
		b.addEventListener('click', function () {
			var table = $(b.getAttribute('data-nu-copy-table'));
			if (!table) return;
			var esc = function (s) { return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); };
			var rows = [];
			table.querySelectorAll('tr').forEach(function (tr) {
				if (tr.classList.contains('nuFilterRow') || tr.offsetParent === null) return;
				var cells = [];
				tr.querySelectorAll('th, td').forEach(function (c) {
					if (c.offsetParent !== null) cells.push({ th: c.tagName === 'TH', text: c.innerText.replace(/\s+/g, ' ').trim() });
				});
				if (cells.length) rows.push(cells);
			});
			var text = rows.map(function (r) { return r.map(function (c) { return c.text; }).join('\t'); }).join('\r\n');
			var html = '<table border="1" cellspacing="0" cellpadding="3">' + rows.map(function (r) {
				return '<tr>' + r.map(function (c) { var t = c.th ? 'th' : 'td'; return '<' + t + '>' + esc(c.text) + '</' + t + '>'; }).join('') + '</tr>';
			}).join('') + '</table>';
			var label = b.querySelector('span'), orig = label.textContent;
			var done = function (ok) {
				label.textContent = b.getAttribute(ok ? 'data-nu-copied' : 'data-nu-copy-failed') || orig;
				b.classList.toggle('btn-success', ok); b.classList.toggle('btn-primary', !ok);
				setTimeout(function () { label.textContent = orig; b.classList.remove('btn-success'); b.classList.add('btn-primary'); }, 1800);
			};
			var fallback = function () {   // http without TLS or older browsers: copy the rendered table through a selection
				var box = document.createElement('div');
				box.style.cssText = 'position:fixed;left:-10000px;top:0';
				box.innerHTML = html;
				document.body.appendChild(box);
				var range = document.createRange(), sel = window.getSelection();
				range.selectNodeContents(box); sel.removeAllRanges(); sel.addRange(range);
				var ok = false;
				try { ok = document.execCommand('copy'); } catch (x) { ok = false; }
				sel.removeAllRanges(); box.remove();
				done(ok);
			};
			if (navigator.clipboard && window.ClipboardItem && window.isSecureContext) {
				navigator.clipboard.write([new ClipboardItem({
					'text/html': new Blob([html], { type: 'text/html' }), 'text/plain': new Blob([text], { type: 'text/plain' })
				})]).then(function () { done(true); }, fallback);
			} else fallback();
		});
	});

	// ---------------------------------------------------------------- CSV dialog of the print view: remember the options per browser, close after starting the download
	$$('form.js-csv-form').forEach(function (f) {
		var key = 'nunext.csv', saved = null;
		try { saved = JSON.parse(localStorage.getItem(key) || 'null'); } catch (x) { saved = null; }
		if (saved) {
			if (saved.sep && f.elements.sep.querySelector('option[value="' + saved.sep + '"]')) f.elements.sep.value = saved.sep;
			if (typeof saved.head === 'boolean') $('#nuCsvHead').checked = saved.head;
			if (typeof saved.bom === 'boolean') $('#nuCsvBom').checked = saved.bom;
		}
		f.addEventListener('submit', function () {
			try { localStorage.setItem(key, JSON.stringify({ sep: f.elements.sep.value, head: $('#nuCsvHead').checked, bom: $('#nuCsvBom').checked })); } catch (x) {}
			var m = f.closest('.modal');
			if (m && nuBs.ready()) setTimeout(function () { nuBs.modal(m).hide(); }, 150);
		});
	});

	// ---------------------------------------------------------------- record pages (user, access level): Save turns red on changes
	var recForm = $('#nuRecordForm');
	if (recForm) {
		var markDirty = function () { $$('button[form="nuRecordForm"]').forEach(function (b) { b.classList.add('nuSaveEdited'); }); };
		recForm.addEventListener('input', markDirty);
		recForm.addEventListener('change', markDirty);
		recForm.addEventListener('click', function (e) { if (e.target.closest('.js-remove-row, .js-add-form')) markDirty(); });
	}

	// ---------------------------------------------------------------- fields remembered in this browser (localStorage)
	// <input data-nu-remember="key">: filled with the last value when the page opens (not after a post: data-nu-remember-keep),
	// stored when it changes and when its form is sent. E.g. the recipient of the test e-mail.
	$$('[data-nu-remember]').forEach(function (f) {
		var key = f.getAttribute('data-nu-remember');
		var store = function () { try { if (f.value.trim() !== '') localStorage.setItem(key, f.value.trim()); } catch (e) { /* storage blocked */ } };
		if (!f.hasAttribute('data-nu-remember-keep')) {
			try { var v = localStorage.getItem(key); if (v) f.value = v; } catch (e) { /* storage blocked */ }
		}
		f.addEventListener('change', store);
		if (f.form) f.form.addEventListener('submit', store);
	});

	// ---------------------------------------------------------------- flash messages shown as toasts (nu_flash(type, msg, true), 0.8.377)
	if (nuBs.ready()) $$('.nuFlashToast').forEach(function (t) { nuBs.toast(t).show(); });

	// ---------------------------------------------------------------- tabs restored by a script (0.8.444): shown once every script of the
	// page has run (the hash tabs below, a remembered tab in nuprocedures.js …) – nutheme.js hid [data-nu-tab-restore] in the meantime
	(function () {
		var done = function () { document.documentElement.classList.remove('nuTabsPending'); };
		if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', done); else done();
	})();

	// ---------------------------------------------------------------- tabs kept in the address (0.8.392, update page: ul[data-nu-hash-tabs])
	// #<tab> opens that tab, #<element id> the tab that holds the element (old anchors such as #updHistory); a tab shown by a click
	// writes its name into the address (the first tab: none), so reloads, links and redirects after a form post come back to it
	$$('[data-nu-hash-tabs]').forEach(function (nav) {
		if (!nuBs.ready()) return;
		var first = nav.querySelector('[data-nu-tab]');
		function show(hash) {
			var name = (hash || '').replace(/^#/, '');
			if (!name) return;
			var btn = null, target = null;
			try { btn = nav.querySelector('[data-nu-tab="' + CSS.escape(name) + '"]'); } catch (e) { return; }
			if (!btn) {
				target = document.getElementById(name);
				var pane = target && target.closest('.tab-pane');
				if (pane) btn = nav.querySelector('[data-bs-target="#' + pane.id + '"]');
			}
			if (!btn) return;
			nuBs.tab(btn).show();
			if (target) setTimeout(function () { target.scrollIntoView({block: 'start'}); }, 60);
		}
		nav.addEventListener('shown.bs.tab', function (e) {
			var n = e.target.getAttribute('data-nu-tab');
			if (!n || ('#' + n) === location.hash) return;
			var target = location.hash && document.getElementById(location.hash.slice(1));
			if (target && e.target.getAttribute('data-bs-target') === '#' + (target.closest('.tab-pane') || {}).id) return;   // #updHistory inside it: keep
			// ?tab= of the server (0.8.451) follows the tab clicked, so a reload opens the same tab without hiding the page
			var u = new URL(location.href);
			if (u.searchParams.has('tab')) { if (e.target === first) u.searchParams.delete('tab'); else u.searchParams.set('tab', n); }
			u.hash = e.target === first ? '' : n;
			history.replaceState(null, '', u.pathname + u.search + u.hash);
		});
		show(location.hash);
		window.addEventListener('hashchange', function () { show(location.hash); });
	});

	// ---------------------------------------------------------------- collapse whose open / closed state is remembered in this browser
	// <div class="collapse" id="x" data-nu-remember-open="key">: opened at once when it was open last time (localStorage key = '1');
	// data-nu-force-open: the server opened it (e.g. a package waits in _update/) – the stored state does not close it. (0.8.376)
	$$('.collapse[data-nu-remember-open]').forEach(function (el) {
		var key = el.getAttribute('data-nu-remember-open');
		var toggles = el.id ? $$('[data-bs-target="#' + el.id + '"]') : [];
		var saved = null;
		try { saved = localStorage.getItem(key); } catch (e) { /* storage blocked */ }
		if (saved === '1' && !el.classList.contains('show')) {
			el.classList.add('show');
			toggles.forEach(function (b) { b.classList.remove('collapsed'); b.setAttribute('aria-expanded', 'true'); });
		}
		// open by default (0.8.450, update page → GitHub): folded last time → folded again, unless the server forces it open
		if (saved === '0' && el.classList.contains('show') && !el.hasAttribute('data-nu-force-open')) {
			el.classList.remove('show');
			toggles.forEach(function (b) { b.classList.add('collapsed'); b.setAttribute('aria-expanded', 'false'); });
		}
		var store = function (v) { return function (e) { if (e.target !== el) return; try { localStorage.setItem(key, v); } catch (x) { /* storage blocked */ } }; };
		el.addEventListener('shown.bs.collapse', store('1'));
		el.addEventListener('hidden.bs.collapse', store('0'));
	});

	// a button inside a folded area's header that opens something inside it: data-nu-open-first="#area" unfolds the area first (0.8.450)
	$$('[data-nu-open-first]').forEach(function (b) {
		b.addEventListener('click', function () {
			var area = $(b.getAttribute('data-nu-open-first'));
			if (area && !area.classList.contains('show') && nuBs.ready()) nuBs.collapse(area, {toggle: false}).show();
		});
	});

	// ---------------------------------------------------------------- list footer: click on "Page x / y" → enter a page, Enter jumps there
	$$('.nuPagerJump').forEach(function (btn) {
		btn.addEventListener('click', function () {
			var pages = parseInt(btn.getAttribute('data-nu-pages'), 10) || 1, cur = parseInt(btn.getAttribute('data-nu-page'), 10) || 1;
			var box = document.createElement('span'), inp = document.createElement('input'), of = document.createElement('span');
			box.className = 'nuPagerJumpBox';
			inp.type = 'number'; inp.min = '1'; inp.max = String(pages); inp.step = '1'; inp.value = String(cur);
			inp.className = 'form-control form-control-sm'; inp.inputMode = 'numeric';
			inp.setAttribute('aria-label', btn.getAttribute('data-nu-label') || 'Page');
			of.textContent = '/ ' + pages;
			box.appendChild(inp); box.appendChild(of);
			btn.hidden = true;
			btn.parentNode.insertBefore(box, btn.nextSibling);
			inp.focus(); inp.select();
			var done = false;
			var close = function () { if (done) return; done = true; box.remove(); btn.hidden = false; };
			var go = function () {
				var n = parseInt(inp.value, 10);
				if (isNaN(n)) { close(); btn.focus(); return; }
				n = Math.max(1, Math.min(pages, n));
				if (n === cur) { close(); btn.focus(); return; }
				done = true;
				inp.disabled = true;
				window.location.href = btn.getAttribute('data-nu-url').replace('__PG__', String(n));
			};
			inp.addEventListener('keydown', function (e) {
				if (e.key === 'Enter') { e.preventDefault(); go(); }
				else if (e.key === 'Escape') { e.preventDefault(); close(); btn.focus(); }
			});
			inp.addEventListener('blur', close);
		});
	});

	// ---------------------------------------------------------------- list filters shown at once on wide screens
	// <div class="collapse" data-nu-open-min="800">: open when the window is at least that wide (the filter button still hides them)
	$$('.collapse[data-nu-open-min]').forEach(function (c) {
		var min = parseInt(c.getAttribute('data-nu-open-min'), 10) || 0;
		if (window.innerWidth < min || c.classList.contains('show')) return;
		c.classList.add('show');
		$$('[data-bs-target="#' + c.id + '"]').forEach(function (b) { b.setAttribute('aria-expanded', 'true'); b.classList.remove('collapsed'); });
	});

	// ---------------------------------------------------------------- confirm dialogs (form[data-confirm])
	var modalEl = $('#nuConfirm');
	var pending = null;
	if (modalEl && nuBs.ready()) {
		var modal = nuBs.modal(modalEl);
		var okBtn = $('.js-confirm-ok', modalEl), okText = okBtn.textContent, okClass = okBtn.className;
		$('.js-confirm-ok', modalEl).addEventListener('click', function () {
			var f = pending; pending = null;
			modal.hide();
			if (!f) return;
			f.dataset.confirmed = '1';
			// send-once forms: requestSubmit fires the submit event again, so the button shows its spinner (form[data-nu-once] below)
			if (f.hasAttribute('data-nu-once') && f.requestSubmit) f.requestSubmit(f.querySelector('[type="submit"][data-nu-busy]') || undefined);
			else f.submit();
		});
		$$('form[data-confirm]').forEach(function (f) {
			f.addEventListener('submit', function (e) {
				if (f.dataset.confirmed === '1') return;
				if (e.submitter && e.submitter.hasAttribute('data-no-confirm')) return;   // e.g. "Save rule" next to "Delete now" (0.8.451)
				e.preventDefault();
				pending = f;
				$('.js-confirm-text', modalEl).textContent = f.getAttribute('data-confirm');
				// optional own button text / style: data-confirm-ok="Refresh" data-confirm-class="btn btn-primary"
				okBtn.textContent = f.getAttribute('data-confirm-ok') || okText;
				okBtn.className = (f.getAttribute('data-confirm-class') || okClass.replace(/\bjs-confirm-ok\b/, '').trim()) + ' js-confirm-ok';
				modal.show();
			});
		});
	}

	/** Opens url in a new tab that comes to the front, also from a Ctrl+click (see below why setTimeout). */
	window.nuOpenTabFront = function (url) {
		setTimeout(function () {
			var w = window.open(url, '_blank');
			if (w) { try { w.opener = null; w.focus(); } catch (x) { /* ignore */ } }
			else window.location.href = url;              // blocked as pop-up: open it here instead
		}, 0);
	};

	// ---------------------------------------------------------------- start page: Ctrl / Cmd + click on a tile opens it in a new tab that gets the focus
	// The browser's own Ctrl+click opens a background tab – and Chrome / Firefox 130+ apply the same to window.open() called while the
	// Ctrl+click event is running (they read the modifier keys of the current event). Opened a moment later (setTimeout) there is no
	// current event any more, but still the user's "transient activation" (pop-ups allowed for a few seconds), so the tab opens in front.
	document.addEventListener('click', function (e) {
		if (e.defaultPrevented || e.button !== 0 || !(e.ctrlKey || e.metaKey) || e.shiftKey || e.altKey) return;
		var a = e.target.closest && e.target.closest('a.nu-tile[href], a.nuHomeTile[href], .nuHomeAction a[href]');
		if (!a) return;
		var href = a.getAttribute('href');
		if (!href || href.charAt(0) === '#') return;
		e.preventDefault();
		window.nuOpenTabFront(a.href);
	});

	// ---------------------------------------------------------------- buttons that cannot act: [data-nu-info] shows why (in the confirm dialog, OK hidden)
	// e.g. Delete of an access level that users still have – a disabled button would just do nothing.
	document.addEventListener('click', function (e) {
		var b = e.target.closest && e.target.closest('[data-nu-info]');
		if (!b) return;
		e.preventDefault();
		var m = $('#nuConfirm'), msg = b.getAttribute('data-nu-info');
		if (!m || !nuBs.ready()) { window.alert(msg); return; }
		var ok = $('.js-confirm-ok', m);
		$('.js-confirm-text', m).textContent = msg;
		if (ok) ok.hidden = true;
		m.addEventListener('hidden.bs.modal', function () { if (ok) ok.hidden = false; }, { once: true });
		nuBs.modal(m).show();
	});

	// ---------------------------------------------------------------- row choice dialog (users list: Delete / Archive)
	// the row button carries data-choice-action (form URL), data-choice-name and data-choice-archived
	$$('.nuRowChoice').forEach(function (m) {
		m.addEventListener('show.bs.modal', function (e) {
			var b = e.relatedTarget;
			if (!b) return;
			$('form', m).setAttribute('action', b.getAttribute('data-choice-action'));
			$$('.js-choice-name', m).forEach(function (n) { n.textContent = b.getAttribute('data-choice-name') || ''; });
			var archived = b.getAttribute('data-choice-archived') === '1';
			var arch = $('.js-choice-archive', m), note = $('.js-choice-archived-note', m);
			if (arch) arch.disabled = archived;
			if (note) note.hidden = !archived;
		});
	});

	// ---------------------------------------------------------------- tabs that remember the open tab (0.8.484, access level form)
	// <ul class="nav-tabs" data-nu-tabfield="<hidden input id>">: the open tab's data-nu-tab goes into that input (sent with Save, so the
	// page after saving shows the same tab) and into the address (?tab=…, kept on reload); a field the browser finds invalid opens its tab.
	$$('ul[data-nu-tabfield]').forEach(function (tabs) {
		var field = document.getElementById(tabs.getAttribute('data-nu-tabfield'));
		if (!field) return;
		tabs.addEventListener('shown.bs.tab', function (e) {
			var v = e.target.getAttribute('data-nu-tab') || '';
			field.value = v;
			try {
				var u = new URL(window.location.href);
				if (v) u.searchParams.set('tab', v); else u.searchParams.delete('tab');
				window.history.replaceState(window.history.state, '', u.toString());
			} catch (x) { /* old browser: the address stays */ }
		});
		if (field.form) field.form.addEventListener('invalid', function (e) {
			var pane = e.target.closest && e.target.closest('.tab-pane');
			if (!pane || pane.classList.contains('active')) return;
			var btn = tabs.querySelector('[data-bs-target="#' + pane.id + '"]');
			if (btn) nuBs.tab(btn).show();
		}, true);
	});

	// ---------------------------------------------------------------- access level: form rights grid
	var body = $('.js-rights-body');
	if (body) {
		var tpl = $('#rightRowTpl');
		var addSel = $('.js-add-form');
		var counter = 0;

		var rows = function () { return $$('tr[data-form]', body).filter(function (r) { return r.getAttribute('data-form') !== ''; }); };
		var removed = function (el) { return !!el.closest('tr.nuRowRemoved'); };
		var labels = {remove: body.getAttribute('data-label-remove'), undo: body.getAttribute('data-label-undo'), pending: body.getAttribute('data-label-pending')};

		var refresh = function () {
			var all = rows(), n = all.filter(function (r) { return !r.classList.contains('nuRowRemoved'); }).length;
			$$('.js-rights-count').forEach(function (b) { b.textContent = n; });                       // rows that stay after saving
			var empty = $('.js-rights-empty', body);
			if (empty) empty.classList.toggle('d-none', all.length > 0);
			syncColumnToggles();
		};

		var syncColumnToggles = function () {
			$$('.js-col-toggle').forEach(function (t) {
				var col = t.getAttribute('data-col');
				var boxes = $$('input[type=checkbox][data-col="' + col + '"]', body).filter(function (b) { return !removed(b); });
				var on = boxes.filter(function (b) { return b.checked; }).length;
				t.checked = boxes.length > 0 && on === boxes.length;
				t.indeterminate = on > 0 && on < boxes.length;
			});
		};

		var setOptionUsed = function (formId, used) {
			if (!addSel) return;
			$$('option', addSel).forEach(function (o) { if (o.value === formId) o.disabled = used; });
		};

		var addRow = function (formId, label) {
			if (!formId || body.querySelector('tr[data-form="' + CSS.escape(formId) + '"]')) return;
			var key = 'n' + Date.now().toString(36) + (counter++);
			var frag = tpl.content.cloneNode(true);
			var tr = $('tr', frag);
			tr.setAttribute('data-form', formId);
			$$('input', tr).forEach(function (i) { i.name = i.name.replace('__KEY__', key); });
			$('input[name$="[form]"]', tr).value = formId;
			$('.js-form-label', tr).textContent = label;
			var adm = $('.js-admin-right', tr);                 // admin-equivalent form (NuSecurity::ADMIN_FORMS): warning icon
			if (adm && (' ' + (body.getAttribute('data-admin-forms') || '') + ' ').indexOf(' ' + formId + ' ') >= 0) adm.hidden = false;
			body.insertBefore(frag, $('.js-rights-empty', body));
			setOptionUsed(formId, true);
		};

		var labelOf = function (formId) {
			var o = addSel && $$('option', addSel).filter(function (x) { return x.value === formId; })[0];
			return o ? o.textContent.trim() : formId;
		};

		if (addSel) {
			$('.js-add-form-btn').addEventListener('click', function () {
				addRow(addSel.value, labelOf(addSel.value));
				addSel.value = '';
				refresh();
			});
			addSel.addEventListener('change', function () {
				if (addSel.value) { addRow(addSel.value, labelOf(addSel.value)); addSel.value = ''; refresh(); }
			});
			// "Several…": dialog to add many forms at once – filter by search (code, description, table, id), group, type, own / system
			var am = $('#addFormsModal'), amData = [];
			try { amData = JSON.parse(($('#addFormsData') || {}).textContent || '[]'); } catch (x) { amData = []; }
			if (am && amData.length) {
				var amList = $('#amList'), amSel = {};
				var inList = function (id) { return !!body.querySelector('tr[data-form="' + CSS.escape(id) + '"]'); };
				var amMatches = function (all) {
					var q = $('#amSearch').value.trim().toLowerCase(), gr = $('#amGroup').value, ty = $('#amType').value, kind = $('#amKind').value, hide = !all && $('#amHideUsed').checked;
					return amData.filter(function (f) {
						if (gr !== '*' && f.group !== gr) return false;
						if (ty !== '*' && f.type !== ty) return false;
						if (kind !== '*' && f.system !== (kind === 'system')) return false;
						if (hide && inList(f.id)) return false;
						return q === '' || (f.code + ' ' + f.desc + ' ' + f.table + ' ' + f.id + ' ' + f.group).toLowerCase().indexOf(q) >= 0;
					});
				};
				var amRender = function () {
					var list = amMatches(), sel = Object.keys(amSel).length, open = 0;
					amList.textContent = '';
					list.forEach(function (f) {
						var used = inList(f.id); if (!used) open++;
						var lab = document.createElement('label');
						lab.className = 'list-group-item list-group-item-action d-flex align-items-center gap-2 py-1 small' + (used ? ' text-body-secondary' : '');
						var cb = document.createElement('input');
						cb.type = 'checkbox'; cb.className = 'form-check-input flex-none m-0'; cb.value = f.id;
						cb.checked = used || !!amSel[f.id]; cb.disabled = used;
						var txt = document.createElement('span'); txt.className = 'flex-grow-1 text-truncate'; txt.textContent = f.label;
						lab.appendChild(cb); lab.appendChild(txt);
						if (f.group) { var g = document.createElement('span'); g.className = 'badge bg-secondary-subtle text-secondary-emphasis border fw-normal'; g.textContent = f.group; lab.appendChild(g); }
						if (f.table) { var tb = document.createElement('code'); tb.className = 'small text-body-secondary d-none d-md-inline'; tb.textContent = f.table; lab.appendChild(tb); }
						if (used) { var u = document.createElement('span'); u.className = 'small fst-italic'; u.textContent = am.getAttribute('data-in-list'); lab.appendChild(u); }
						amList.appendChild(lab);
					});
					var em = $('#amEmpty');
					em.classList.toggle('d-none', list.length > 0);
					em.textContent = am.getAttribute(list.length === 0 && amMatches(true).length > 0 ? 'data-all-used' : 'data-none');
					amList.classList.toggle('d-none', list.length === 0);
					$('#amCount').textContent = (am.getAttribute('data-found') || '%s · %s').replace('%s', list.length).replace('%s', sel);
					var add = $('#amAdd'); add.disabled = sel === 0;
					$('span', add).textContent = (am.getAttribute('data-add') || 'Add %s').replace('%s', sel);
					$('#amAll').disabled = open === 0;
				};
				amList.addEventListener('change', function (e) {
					if (e.target.type !== 'checkbox') return;
					if (e.target.checked) amSel[e.target.value] = true; else delete amSel[e.target.value];
					amRender();
				});
				['amSearch', 'amGroup', 'amType', 'amKind', 'amHideUsed'].forEach(function (id) { $('#' + id).addEventListener(id === 'amSearch' ? 'input' : 'change', amRender); });
				$('#amAll').addEventListener('click', function () { amMatches().forEach(function (f) { if (!inList(f.id)) amSel[f.id] = true; }); amRender(); });
				$('#amNone').addEventListener('click', function () { amSel = {}; amRender(); });
				$('#amSearch').addEventListener('keydown', function (e) { if (e.key === 'Enter') e.preventDefault(); });
				am.addEventListener('show.bs.modal', function () { amSel = {}; amRender(); });
				am.addEventListener('shown.bs.modal', function () { $('#amSearch').focus(); });
				$('#amAdd').addEventListener('click', function () {
					var rights = {};
					$$('.js-am-right', am).forEach(function (r) { rights[r.getAttribute('data-col')] = r.checked; });
					var added = 0;
					amData.forEach(function (f) {                  // in the order of the list (by code)
						if (!amSel[f.id] || inList(f.id)) return;
						addRow(f.id, f.label);
						var tr = body.querySelector('tr[data-form="' + CSS.escape(f.id) + '"]');
						if (tr) $$('input[type=checkbox][data-col]', tr).forEach(function (c) { c.checked = rights[c.getAttribute('data-col')] !== false; });
						added++;
					});
					refresh();
					if (added) body.dispatchEvent(new Event('change', {bubbles: true}));   // Save turns red
					nuBs.modal(am).hide();
				});
			}
		}

		// remove (0.8.139): the row is crossed out and deleted when the access level is saved; the same button undoes it.
		// The form stays taken in "Add form" meanwhile – undo brings the row back instead of adding it twice.
		var markRemoved = function (tr, on) {
			var flag = $('.js-remove-flag', tr);
			on = window.nuToggleRemoved(tr, $('.js-remove-row', tr), labels, on);
			if (flag) flag.value = on ? '1' : '';
		};
		rows().forEach(function (tr) { var f = $('.js-remove-flag', tr); if (f && f.value === '1') markRemoved(tr, true); });   // after a failed save
		refresh();
		body.addEventListener('click', function (e) {
			var btn = e.target.closest('.js-remove-row');
			if (!btn) return;
			markRemoved(btn.closest('tr'));
			refresh();
		});

		body.addEventListener('change', function (e) {
			if (e.target.matches('input[type=checkbox][data-col]')) syncColumnToggles();
		});

		$$('.js-col-toggle').forEach(function (t) {
			t.addEventListener('change', function () {
				var col = t.getAttribute('data-col');
				$$('input[type=checkbox][data-col="' + col + '"]', body).forEach(function (b) {
					if (!b.closest('tr').classList.contains('d-none') && !removed(b)) b.checked = t.checked;   // only visible (filtered) rows, not crossed out
				});
				syncColumnToggles();
			});
		});

		var filter = $('.js-rights-filter');
		if (filter) {
			filter.addEventListener('input', function () {
				var q = filter.value.trim().toLowerCase();
				rows().forEach(function (r) {
					var text = ($('.js-form-label', r).textContent + ' ' + r.getAttribute('data-form')).toLowerCase();
					r.classList.toggle('d-none', q !== '' && text.indexOf(q) === -1);
				});
			});
		}

		refresh();
	}
})();

// phones show Save / Clone / Delete as icons only (nuapp.css): their text becomes the tooltip
(function () {
	'use strict';
	document.querySelectorAll('.nuTitleBar .btn, #nuSaveButton, #nuCloneButton, #nuDeleteButton').forEach(function (b) {
		if (b.title || !b.querySelector(':scope > .bi')) return;
		if (!(b.hasAttribute('data-nu-save') || /^nu(Save|Clone|Delete)Button$/.test(b.id) || b.querySelector(':scope > .bi-copy, :scope > .bi-trash'))) return;
		var txt = b.textContent.trim();
		if (txt) b.title = txt;
	});
})();

// info icons (i) with a hover / focus tooltip: <span data-bs-toggle="tooltip" title="…">
(function () {
	'use strict';
	if (!nuBs.ready()) return;
	document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function (el) { nuBs.tooltip(el); });
	// only one tooltip at a time (0.8.243): showing one hides the others; a tap / click elsewhere closes them (touch screens keep
	// click-triggered tooltips open otherwise)
	var hideOthers = function (keep) {
		document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function (el) {
			if (el === keep) return;
			var t = nuBs.tooltipOf(el);
			if (t && el.getAttribute('aria-describedby')) t.hide();
		});
	};
	document.addEventListener('show.bs.tooltip', function (e) { hideOthers(e.target); });
	document.addEventListener('click', function (e) {
		var on = e.target.closest && e.target.closest('[data-bs-toggle="tooltip"]');
		if (!on) hideOthers(null);
	}, true);
})();

// Update page: change history – open / close all on this page; clearing the search (×) shows everything again
(function () {
	'use strict';
	var list = document.getElementById('updHistoryList');
	if (!list) return;
	var items = Array.prototype.slice.call(list.querySelectorAll('.updHistoryItem'));
	var search = document.getElementById('updHistorySearch');
	var all = document.getElementById('updHistoryAll');
	if (search) search.addEventListener('search', function () { if (search.value === '' && search.defaultValue !== '') search.form.submit(); });
	if (all) all.addEventListener('click', function () {
		var open = items.some(function (d) { return !d.open; });
		items.forEach(function (d) { d.open = open; });
		all.textContent = all.getAttribute(open ? 'data-close' : 'data-open');
	});
})();

// form[data-nu-once]: sent only once – a double click or a second Enter does nothing; the button shows data-nu-busy with a spinner.
// (The server must still check – e.g. a one-time token, see pages/nuupdate.php "Back up now".) Back / forward cache: reset.
(function () {
	'use strict';
	document.addEventListener('submit', function (e) {
		var f = e.target;
		if (!f.hasAttribute || !f.hasAttribute('data-nu-once')) return;
		if (f.dataset.nuSent === '1') { e.preventDefault(); return; }
		if (e.defaultPrevented) return;                                         // e.g. a confirm dialog still open
		f.dataset.nuSent = '1';
		var b = e.submitter || f.querySelector('[type="submit"]');
		setTimeout(function () {                                                  // after the browser took the form (a disabled submitter is not sent)
			if (!b) return;
			b.dataset.nuLabel = b.innerHTML;
			b.disabled = true;
			// data-nu-lock="<selector>": more buttons that must not be used while the form is sent (update page: Cancel during Install)
			var lock = f.getAttribute('data-nu-lock');
			if (lock) document.querySelectorAll(lock).forEach(function (x) { x.disabled = true; x.setAttribute('aria-busy', 'true'); });
			b.setAttribute('aria-busy', 'true');
			var busy = b.getAttribute('data-nu-busy');
			if (busy) {
				b.textContent = '';
				var sp = document.createElement('span');
				sp.className = 'spinner-border spinner-border-sm me-1';
				sp.setAttribute('aria-hidden', 'true');
				b.appendChild(sp);
				b.appendChild(document.createTextNode(busy));
			}
			// data-nu-progress="#modal" (0.8.308): a modal that cannot be closed while the request runs – e.g. Install on the update page
			var pm = f.getAttribute('data-nu-progress');
			var pel = pm ? document.querySelector(pm) : null;
			if (pel && window.nuBs && nuBs.ready()) nuBs.modal(pel, {backdrop: 'static', keyboard: false}).show();
		}, 0);
	});
	window.addEventListener('pageshow', function (e) {
		if (!e.persisted) return;
		document.querySelectorAll('form[data-nu-once]').forEach(function (f) {
			f.dataset.nuSent = '';
			f.querySelectorAll('[aria-busy="true"]').forEach(function (b) { b.disabled = false; b.removeAttribute('aria-busy'); if (b.dataset.nuLabel) b.innerHTML = b.dataset.nuLabel; });
			var lock = f.getAttribute('data-nu-lock');
			if (lock) document.querySelectorAll(lock).forEach(function (x) { x.disabled = false; x.removeAttribute('aria-busy'); });
			var pm = f.getAttribute('data-nu-progress'), pel = pm ? document.querySelector(pm) : null;
			if (pel && window.nuBs && nuBs.ready()) nuBs.modal(pel).hide();
		});
	});
})();

// Save buttons (.nuBtnSave, 0.8.199): red while their form has unsaved changes – for every page; record pages, forms and the editors also
// set .nuSaveEdited themselves. Changes made by scripts while the page loads do not count (only after the user did something).
(function () {
	'use strict';
	var acted = false;
	['pointerdown', 'keydown'].forEach(function (ev) { document.addEventListener(ev, function () { acted = true; }, true); });
	function mark(e) {
		var el = e.target;
		if (!el || !(e.isTrusted || acted) || (el.closest && el.closest('[data-nu-noguard]'))) return;
		var f = el.form || (el.closest && el.closest('form'));
		if (!f) return;
		document.querySelectorAll('.nuBtnSave').forEach(function (b) {
			if (b.form === f || (f.id && b.getAttribute('form') === f.id)) b.classList.add('nuSaveEdited');
		});
	}
	document.addEventListener('input', mark, true);
	document.addEventListener('change', mark, true);
})();

// accessibility (0.8.368, dev/tests/a11y.js): fields without a name get one from their label, the sub-form column title or the
// lookup's label; selects behind Tom Select and CodeMirror's hidden input keep a name; tables that scroll sideways can be focused.
(function () {
	function text(el) { return el ? el.textContent.replace(/\s+/g, ' ').trim() : ''; }
	function labelFor(id) { return id ? text(document.querySelector('label[for="' + CSS.escape(id) + '"]')) : ''; }
	function named(el) {
		return el.getAttribute('aria-label') || el.getAttribute('aria-labelledby') || el.getAttribute('title') || (el.id && document.querySelector('label[for="' + CSS.escape(el.id) + '"]')) || el.closest('label');
	}
	function name(el) {
		var field = el.getAttribute('data-nu-field') || '', prefix = el.getAttribute('data-nu-prefix') || '';
		var base = field.replace(/(code|description)$/, ''), part = field !== base ? field.slice(base.length) : '';
		var n = '';
		if (prefix) n = text(document.getElementById('title_' + prefix.slice(0, -3) + base));        // sub-form cell: its column title
		var idBase = el.id.replace(/(code|description)$/, '');
		if (!n) n = labelFor(base !== field ? base : '') || labelFor(idBase) || labelFor(idBase + 'code') || labelFor(el.id + '-ts-control');
		if (!n && el.classList.contains('tomselected')) n = el.getAttribute('placeholder') || '';
		if (!n && el.closest('.CodeMirror')) { var w = el.closest('.CodeMirror'), ta = w.previousElementSibling; n = (ta && (labelFor(ta.id) || ta.getAttribute('aria-label'))) || 'Code'; }
		if (n && part) n += ' (' + part + ')';
		return n;
	}
	function run() {
		document.querySelectorAll('input:not([type=hidden]):not([type=button]):not([type=submit]), select, textarea').forEach(function (el) {
			if (named(el)) return;
			var n = name(el);
			if (n) el.setAttribute('aria-label', n);
		});
		// everything that scrolls (tables, code blocks, lists with a fixed height) can be reached with the keyboard
		document.querySelectorAll('div, pre, ul, ol, section, article, details, td, code').forEach(function (t) {
			if (t.hasAttribute('tabindex') || t.closest('.CodeMirror') || /^(INPUT|TEXTAREA|SELECT)$/.test(t.tagName)) return;
			var cs = getComputedStyle(t), x = /(auto|scroll)/.test(cs.overflowX) && t.scrollWidth > t.clientWidth + 1, y = /(auto|scroll)/.test(cs.overflowY) && t.scrollHeight > t.clientHeight + 1;
			if (x || y) t.setAttribute('tabindex', '0');
		});
	}
	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { setTimeout(run, 0); }); else setTimeout(run, 0);
	window.addEventListener('load', function () { setTimeout(run, 300); });
	['shown.bs.tab', 'shown.bs.collapse', 'shown.bs.modal'].forEach(function (e) { document.addEventListener(e, function () { setTimeout(run, 50); }); });
	var rt = null;
	window.addEventListener('resize', function () { clearTimeout(rt); rt = setTimeout(run, 200); });
	window.nuA11y = run;                                           // pages that draw later (sub-form rows, dialogs) may call it again
})();

/*
 * Search highlighting (0.8.427): marks the words of a search in text – form browse lists (nuHighlightSearch() / $.fn.nuHighlight in
 * assets/js/nu/nucompat.js, run after loading as in nuBuilder) and the system lists (data-nu-highlight on their rows). Better than
 * nuBuilder's nuHighlight: every occurrence (not only the first of a text node), all terms in one pass (longest first, no marks inside
 * marks), case- and accent-insensitive like the database's collation (é = e, ß = ss) with exact positions in the original text, and
 * the marked part only (nuBuilder's accent mode marked the rest of the text too). Skips script / style / form fields / buttons, existing
 * marks and anything inside .nuNoHighlight. API: nuHighlightText(root, terms, {className, accents}) → number of marks;
 * nuHighlightClear(root, className).
 */
(function () {
	'use strict';
	var SKIP = /^(SCRIPT|STYLE|TEXTAREA|INPUT|SELECT|OPTION|BUTTON|SVG|MARK)$/;
	var MAX_NODES = 20000;                                        // a print view with thousands of rows stays fast
	function fold(s, accents) {
		s = s.toLowerCase();
		if (accents === false) return s;
		return s.normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/ß/g, 'ss').replace(/æ/g, 'ae').replace(/œ/g, 'oe').replace(/ø/g, 'o').replace(/đ/g, 'd').replace(/ł/g, 'l');
	}
	/** The folded text and, for every folded character, the index of its original character (+ the end as the last entry). */
	function folded(text, accents) {
		var f = '', map = [], i = 0;
		Array.from(text).forEach(function (ch) {
			var x = fold(ch, accents);
			for (var k = 0; k < x.length; k++) map.push(i);
			f += x;
			i += ch.length;
		});
		map.push(i);
		return {f: f, map: map, len: i};
	}
	function ranges(F, keys) {
		var out = [], pos = 0;
		while (pos < F.f.length) {
			var best = -1, blen = 0;
			for (var k = 0; k < keys.length; k++) {               // keys: longest first – the first found at the smallest index wins
				var at = F.f.indexOf(keys[k], pos);
				if (at >= 0 && (best < 0 || at < best)) { best = at; blen = keys[k].length; }
			}
			if (best < 0) break;
			var s = F.map[best], last = F.map[best + blen - 1], e = F.len;
			for (var j = best + blen; j < F.map.length; j++) if (F.map[j] > last) { e = F.map[j]; break; }
			if (!out.length || s >= out[out.length - 1][1]) out.push([s, e]);
			pos = best + blen;
		}
		return out;
	}
	function highlight(root, terms, opt) {
		opt = opt || {};
		var cls = opt.className || 'nuHighlight', accents = opt.accents;
		if (!root || !terms) return 0;
		var keys = (Array.isArray(terms) ? terms : [terms]).map(function (t) { return fold(String(t == null ? '' : t).trim(), accents); })
			.filter(function (t, i, a) { return t !== '' && a.indexOf(t) === i; }).sort(function (a, b) { return b.length - a.length; });
		if (!keys.length) return 0;
		var nodes = [], walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, {acceptNode: function (n) {
			if (!n.data || !n.data.trim()) return NodeFilter.FILTER_REJECT;
			for (var p = n.parentNode; p && p !== root.parentNode; p = p.parentNode) {
				if (p.nodeType !== 1) continue;
				if (SKIP.test(p.tagName.toUpperCase()) || (p.classList && (p.classList.contains('nuNoHighlight') || p.classList.contains(cls)))) return NodeFilter.FILTER_REJECT;
			}
			return NodeFilter.FILTER_ACCEPT;
		}});
		while (walker.nextNode() && nodes.length < MAX_NODES) nodes.push(walker.currentNode);
		var count = 0;
		nodes.forEach(function (n) {
			var text = n.data, rs = ranges(folded(text, accents), keys);
			if (!rs.length) return;
			var frag = document.createDocumentFragment(), at = 0;
			rs.forEach(function (r) {
				if (r[0] > at) frag.appendChild(document.createTextNode(text.slice(at, r[0])));
				var m = document.createElement('mark');
				m.className = cls;
				m.textContent = text.slice(r[0], r[1]);
				frag.appendChild(m);
				at = r[1];
				count++;
			});
			if (at < text.length) frag.appendChild(document.createTextNode(text.slice(at)));
			n.parentNode.replaceChild(frag, n);
		});
		return count;
	}
	function clear(root, cls) {
		if (!root) return;
		Array.prototype.slice.call(root.querySelectorAll('mark.' + (cls || 'nuHighlight'))).forEach(function (m) {
			var p = m.parentNode;
			p.replaceChild(document.createTextNode(m.textContent), m);
			p.normalize();
		});
	}
	window.nuHighlightText = highlight;
	window.nuHighlightClear = clear;
	// system lists: rows / cards carrying data-nu-highlight="<the search>" (words separated by spaces, as the lists search)
	function lists() {
		document.querySelectorAll('[data-nu-highlight]').forEach(function (box) {
			var q = box.getAttribute('data-nu-highlight') || '';
			box.removeAttribute('data-nu-highlight');
			highlight(box, q.split(/\s+/));
		});
	}
	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', lists); else lists();
})();

/*
 * Search fields of lists (0.8.428, form browse lists and nuBuilder Next's own lists): the browser's × in the field and Esc empty the field
 * and show the list without the search words at once (before, the × only emptied the box). Esc in an empty field does nothing here, so
 * a lookup window can still be closed with it.
 */
(function () {
	'use strict';
	function reload(f, input) {
		if (input.defaultValue === '') return;                    // nothing was searched: emptying the box is enough
		if (typeof f.requestSubmit === 'function') f.requestSubmit(); else f.submit();
	}
	document.addEventListener('search', function (e) {        // the × of type="search" (Chrome, Edge, Safari); Enter submits anyway
		var input = e.target, f = input && input.form;
		if (!f || input.type !== 'search' || !f.classList.contains('nuListFilter') || input.value !== '') return;
		reload(f, input);
	}, true);
	document.addEventListener('keydown', function (e) {
		var input = e.target, f = input && input.form;
		if (e.key !== 'Escape' || !f || input.type !== 'search' || !f.classList.contains('nuListFilter') || input.value === '') return;
		e.preventDefault();
		e.stopPropagation();
		input.value = '';
		reload(f, input);
	}, true);
})();

/*
 * Page hook (0.8.523, Admin tools → Custom code → Global JS): every page names itself on <body> (data-nu-page = the ?p= route,
 * data-nu-mode / data-nu-form on form pages, data-nu-admin="1" for admins). Once the page is ready nuOnPageLoad(page, info) is called
 * when defined, then the event nu:pageload ({detail: {page, mode, form, admin}}) lets several snippets react. Form pages: the runtime
 * calls it after nuOnLoad (nuruntime.js); everywhere else: here, when the DOM is ready. Errors are logged, the page keeps working.
 */
(function () {
	'use strict';
	var done = false;
	function run() {
		if (done || !document.body) return;
		done = true;
		var d = document.body.dataset, page = d.nuPage || '';
		var info = {page: page, mode: d.nuMode || null, form: d.nuForm || null, admin: d.nuAdmin === '1'};
		if (typeof window.nuOnPageLoad === 'function') {
			try { window.nuOnPageLoad(page, info); } catch (e) { if (window.console) console.error('nuOnPageLoad:', e); }
		}
		try { document.dispatchEvent(new CustomEvent('nu:pageload', {detail: info})); } catch (e) { if (window.console) console.error('nu:pageload:', e); }
	}
	window.nuNextPageHook = run;
	if (document.body && document.body.dataset.nuPage === 'form') return;   // the form runtime calls it after nuOnLoad
	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', run); else setTimeout(run, 0);
})();
