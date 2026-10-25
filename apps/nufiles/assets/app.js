/* nuFiles – file manager page for nuBuilder Next. Vanilla JavaScript + Bootstrap 5 + CodeMirror 5 (all shipped with nuBuilder Next).
 * Every name from the server is put into the page with textContent / attributes – never as HTML. */
(function () {
	'use strict';

	var boot = JSON.parse(document.getElementById('nufBoot').textContent || '{}');
	var S = boot.strings || {};
	var $ = function (id) { return document.getElementById(id); };
	var TEXT_EXT = ['txt', 'md', 'csv', 'tsv', 'log', 'json', 'xml', 'html', 'htm', 'css', 'scss', 'less', 'js', 'mjs', 'ts', 'php', 'phtml', 'inc', 'sql', 'ini',
		'conf', 'cfg', 'yml', 'yaml', 'env', 'htaccess', 'sh', 'py', 'rb', 'pl', 'java', 'c', 'h', 'cpp', 'cs', 'go', 'vue', 'svg', 'tpl', 'twig', 'bat', 'ps1',
		'properties', 'gitignore', 'lock', 'dist', 'example', 'sample', 'editorconfig', 'map'];
	var IMG_EXT = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'bmp', 'avif', 'ico'];
	var VIDEO_EXT = ['mp4', 'webm', 'ogv', 'mov', 'm4v'];
	var AUDIO_EXT = ['mp3', 'ogg', 'oga', 'wav', 'm4a', 'flac', 'aac', 'opus'];

	var state = {
		view: 'files',          // files | search | trash | log
		share: null,            // share object of boot.shares
		path: '',
		items: [],
		sel: new Set(),
		sort: {key: 'name', dir: 1},
		query: '',
		listing: null
	};

	// ================================================================== small helpers

	function t(key) {
		var s = Object.prototype.hasOwnProperty.call(S, key) ? S[key] : key;
		for (var i = 1; i < arguments.length; i++) s = s.split('%' + i).join(String(arguments[i]));
		return s;
	}

	function el(tag, attrs, children) {
		var e = document.createElement(tag);
		if (attrs) Object.keys(attrs).forEach(function (k) {
			var v = attrs[k];
			if (v === null || v === undefined || v === false) return;
			if (k === 'text') e.textContent = v;
			else if (k === 'class') e.className = v;
			else if (k.slice(0, 2) === 'on') e.addEventListener(k.slice(2), v);
			else e.setAttribute(k, v === true ? '' : v);
		});
		(children || []).forEach(function (c) { if (c !== null && c !== undefined) e.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); });
		return e;
	}

	function icon(name) { return el('i', {'class': 'bi bi-' + name, 'aria-hidden': 'true'}); }

	function size(n) {
		if (n === null || n === undefined) return '';
		if (n < 1024) return n + ' B';
		var u = ['KB', 'MB', 'GB', 'TB'], i = -1;
		do { n /= 1024; i++; } while (n >= 1024 && i < u.length - 1);
		return n.toFixed(n < 10 ? 1 : 0) + ' ' + u[i];
	}

	var dateFmt = (function () {
		try { return new Intl.DateTimeFormat(boot.lang || undefined, {dateStyle: 'medium', timeStyle: 'short'}); } catch (e) { return null; }
	}());
	function when(ts) {
		if (!ts) return '';
		var d = new Date(ts * 1000);
		return dateFmt ? dateFmt.format(d) : d.toLocaleString();
	}

	function ext(name) {
		var i = name.lastIndexOf('.');
		return i > 0 ? name.slice(i + 1).toLowerCase() : (name.charAt(0) === '.' ? name.slice(1).toLowerCase() : '');
	}

	function join(a, b) { return a ? (b ? a + '/' + b : a) : b; }
	function parent(p) { var i = p.lastIndexOf('/'); return i < 0 ? '' : p.slice(0, i); }
	function base(p) { var i = p.lastIndexOf('/'); return i < 0 ? p : p.slice(i + 1); }

	function can(right, share) {
		share = share || state.share;
		return !!share && share.rights.indexOf(right) >= 0 && !boot.readonly;
	}

	function fileIcon(it) {
		if (it.type === 'dir') return it.link ? 'folder-symlink-fill' : 'folder-fill';
		if (it.outside) return 'link-45deg';
		var e = it.ext || ext(it.name);
		if (IMG_EXT.indexOf(e) >= 0 || e === 'svg') return 'file-earmark-image';
		if (e === 'pdf') return 'file-earmark-pdf';
		if (['zip', 'gz', 'tgz', 'tar', '7z', 'rar', 'bz2', 'xz'].indexOf(e) >= 0) return 'file-earmark-zip';
		if (VIDEO_EXT.indexOf(e) >= 0) return 'file-earmark-play';
		if (AUDIO_EXT.indexOf(e) >= 0) return 'file-earmark-music';
		if (['xls', 'xlsx', 'ods', 'csv', 'tsv'].indexOf(e) >= 0) return 'file-earmark-spreadsheet';
		if (['doc', 'docx', 'odt', 'rtf'].indexOf(e) >= 0) return 'file-earmark-word';
		if (['ppt', 'pptx', 'odp'].indexOf(e) >= 0) return 'file-earmark-slides';
		if (['php', 'js', 'mjs', 'ts', 'css', 'scss', 'less', 'html', 'htm', 'json', 'xml', 'sql', 'sh', 'py', 'vue', 'twig', 'tpl', 'phtml', 'inc'].indexOf(e) >= 0) return 'file-earmark-code';
		if (TEXT_EXT.indexOf(e) >= 0) return 'file-earmark-text';
		return 'file-earmark';
	}

	function kind(name) {
		var e = ext(name);
		if (IMG_EXT.indexOf(e) >= 0) return 'image';
		if (e === 'pdf') return 'pdf';
		if (VIDEO_EXT.indexOf(e) >= 0) return 'video';
		if (AUDIO_EXT.indexOf(e) >= 0) return 'audio';
		if (e === 'zip') return 'zip';
		if (TEXT_EXT.indexOf(e) >= 0 || e === '') return 'text';
		return 'other';
	}

	// ================================================================== toasts and dialogs

	function toast(text, level) {
		var cls = {success: 'text-bg-success', danger: 'text-bg-danger', warning: 'text-bg-warning', info: 'text-bg-secondary'}[level || 'info'] || 'text-bg-secondary';
		var box = el('div', {'class': 'toast align-items-center border-0 ' + cls, role: level === 'danger' ? 'alert' : 'status'}, [
			el('div', {'class': 'd-flex'}, [el('div', {'class': 'toast-body', text: text}),
				el('button', {type: 'button', 'class': 'btn-close me-2 m-auto' + (level === 'warning' ? '' : ' btn-close-white'), 'data-bs-dismiss': 'toast', 'aria-label': t('close')})])
		]);
		$('nufToasts').appendChild(box);
		var tt = new bootstrap.Toast(box, {delay: level === 'danger' ? 8000 : 3500});
		box.addEventListener('hidden.bs.toast', function () { box.remove(); });
		tt.show();
	}

	/** Bootstrap ignores hide() while a modal is still fading in (a quick Enter): hide as soon as it is shown. */
	function hideModal(m, elId) {
		var node = $(elId);
		if (node.classList.contains('show') && !node.hasAttribute('data-nuf-showing')) { m.hide(); return; }
		node.addEventListener('shown.bs.modal', function f() { node.removeEventListener('shown.bs.modal', f); m.hide(); });
	}
	['nufDlg', 'nufPick', 'nufPreview', 'nufEditor'].forEach(function (id) {
		var node = $(id);
		node.addEventListener('show.bs.modal', function () { node.setAttribute('data-nuf-showing', '1'); });
		node.addEventListener('shown.bs.modal', function () { node.removeAttribute('data-nuf-showing'); });
	});

	var dlg = {modal: null, resolve: null, value: null};
	function dialog(opt) {
		// opt: {title, text, input (default value), buttons: [{label, value, cls}], validate(value) → error text|''}
		return new Promise(function (resolve) {
			if (!dlg.modal) dlg.modal = new bootstrap.Modal($('nufDlg'));
			dlg.resolve = resolve;
			dlg.value = null;
			dlg.validate = opt.validate || null;
			$('nufDlgTitle').textContent = opt.title || '';
			var txt = $('nufDlgText');
			txt.textContent = '';
			if (opt.node) txt.appendChild(opt.node); else txt.textContent = opt.text || '';
			var inp = $('nufDlgInput');
			inp.classList.toggle('d-none', opt.input === undefined);
			inp.classList.remove('is-invalid');
			inp.value = opt.input === undefined ? '' : opt.input;
			var foot = $('nufDlgFoot');
			foot.textContent = '';
			(opt.buttons || [{label: t('ok'), value: true, cls: 'btn-primary'}]).forEach(function (b, i, all) {
				var isSubmit = i === all.length - 1;
				foot.appendChild(el('button', {type: isSubmit ? 'submit' : 'button', 'class': 'btn ' + (b.cls || 'btn-secondary'), text: b.label,
					onclick: isSubmit ? null : function () { dlg.value = b.value; hideModal(dlg.modal, 'nufDlg'); }}));
				if (isSubmit) dlg.submitValue = b.value;
			});
			dlg.modal.show();
			if (opt.input !== undefined) {
				$('nufDlg').addEventListener('shown.bs.modal', function f() {
					$('nufDlg').removeEventListener('shown.bs.modal', f);
					inp.focus();
					var dot = inp.value.lastIndexOf('.');
					if (opt.selectStem && dot > 0) inp.setSelectionRange(0, dot); else inp.select();
				});
			}
		});
	}
	$('nufDlgForm').addEventListener('submit', function (e) {
		e.preventDefault();
		var inp = $('nufDlgInput');
		if (!inp.classList.contains('d-none')) {
			var err = dlg.validate ? dlg.validate(inp.value) : '';
			if (err) { $('nufDlgErr').textContent = err; inp.classList.add('is-invalid'); return; }
			dlg.value = dlg.submitValue === true ? inp.value : dlg.submitValue;
		} else {
			dlg.value = dlg.submitValue;
		}
		hideModal(dlg.modal, 'nufDlg');
	});
	$('nufDlg').addEventListener('hidden.bs.modal', function () {
		var r = dlg.resolve;
		dlg.resolve = null;
		if (r) r(dlg.value);
	});

	function prompt(title, value, selectStem) {
		return dialog({title: title, input: value || '', selectStem: selectStem, validate: function (v) { return v.trim() === '' ? t('err_name_empty') : ''; },
			buttons: [{label: t('cancel'), value: null}, {label: t('ok'), value: true, cls: 'btn-primary'}]}).then(function (v) { return v === null || v === undefined ? null : v.trim(); });
	}

	function confirmBox(title, text, okLabel, danger) {
		return dialog({title: title, text: text, buttons: [{label: t('cancel'), value: false}, {label: okLabel || t('ok'), value: true, cls: danger ? 'btn-danger' : 'btn-primary'}]})
			.then(function (v) { return v === true; });
	}

	// ================================================================== server calls

	function url(action, params) {
		var u = boot.base + (boot.base.indexOf('?') >= 0 ? '&' : '?') + 'action=' + encodeURIComponent(action);
		Object.keys(params || {}).forEach(function (k) {
			if (params[k] !== undefined && params[k] !== null) u += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(params[k]);
		});
		return u;
	}

	function ApiError(status, data) {
		this.status = status;
		this.data = data || {};
		this.key = this.data.error || 'err_server';
		this.message = this.data.message || t('unknown_error');
	}

	function call(action, params, post, extra) {
		var opts = {method: post ? 'POST' : 'GET'};
		var u;
		if (post) {
			var fd = new FormData();
			Object.keys(params || {}).forEach(function (k) {
				var v = params[k];
				if (v === undefined || v === null) return;
				if (v instanceof Blob) fd.append(k, v, k + '.bin'); else fd.append(k, typeof v === 'object' ? JSON.stringify(v) : String(v));
			});
			opts.body = fd;
			u = url(action);
		} else {
			u = url(action, params);
		}
		if (extra && extra.signal) opts.signal = extra.signal;
		return NuPage.fetch(u, opts).then(function (r) {
			return r.text().then(function (txt) {
				var d = null;
				try { d = JSON.parse(txt); } catch (e) { d = {error: 'err_server', message: t('unknown_error') + ' (HTTP ' + r.status + ')'}; }
				if (r.ok && d && d.ok) return d;
				if (d && d.error === 'csrf') d.message = d.message || t('unknown_error');
				throw new ApiError(r.status, d);
			});
		});
	}

	/** call() that handles the password confirmation (code shares) and asks to retry. */
	function api(action, params, post, extra) {
		return call(action, params, post, extra).catch(function (e) {
			if (e instanceof ApiError && e.key === 'err_reauth' && e.data.reauth) {
				return reauth(e.data.reauth).then(function (ok) {
					if (!ok) throw e;
					return api(action, params, post, extra);
				});
			}
			throw e;
		});
	}

	function fail(e) {
		if (e && e.name === 'AbortError') return;
		var msg = e instanceof ApiError ? e.message : (e && e.message) || t('unknown_error');
		toast(msg, 'danger');
		if (window.console && !(e instanceof ApiError)) window.console.error(e);
	}

	// password confirmation in a small window (core page ?p=reauth); the window tells us when it is done (assets/reauth.js)
	var reauthWait = null;
	function reauth(href) {
		if (reauthWait) return reauthWait;
		reauthWait = new Promise(function (resolve) {
			var done = false;
			function finish(ok) {
				if (done) return;
				done = true;
				window.removeEventListener('message', onMsg);
				resolve(ok);
				reauthWait = null;
			}
			function onMsg(ev) {
				if (ev.origin === window.location.origin && ev.data && ev.data.nufReauth) { finish(true); if (dlg.modal) hideModal(dlg.modal, 'nufDlg'); }
			}
			window.addEventListener('message', onMsg);
			var btn = el('button', {type: 'button', 'class': 'btn btn-primary mt-2', onclick: function () {
				var w = window.open(href, 'nufReauth', 'width=560,height=640');
				if (!w) window.location.href = href;
			}}, [icon('shield-lock'), ' ' + t('reauth_open')]);
			dialog({title: t('reauth_title'), node: el('div', {}, [el('p', {text: t('reauth_text')}), btn]),
				buttons: [{label: t('cancel'), value: false}, {label: t('retry'), value: true, cls: 'btn-outline-primary'}]})
				.then(function (v) { finish(v === true); });
		});
		return reauthWait;
	}

	// ================================================================== shares, navigation

	function shareById(id) { return (boot.shares || []).filter(function (s) { return s.id === id; })[0] || null; }

	function renderShares() {
		var nav = $('nufShares');
		nav.textContent = '';
		(boot.shares || []).forEach(function (s) {
			var a = el('a', {href: '#/' + encodeURIComponent(s.id) + '/', 'class': 'nav-link nuf-share' + (state.view === 'files' && state.share && state.share.id === s.id ? ' active' : ''),
				'data-share': s.id, title: s.root || s.label}, [
				icon(s.type === 'code' ? 'code-square' : 'folder2'), el('span', {'class': 'nuf-share-label', text: s.label}),
				s.type === 'code' ? el('span', {'class': 'badge bg-danger-subtle text-danger-emphasis ms-1', text: t('code_badge')}) : null,
				s.readonly || boot.readonly || s.rights.length === 1 ? el('i', {'class': 'bi bi-lock ms-1 small', title: t('readonly_badge')}) : null,
				!s.ok ? el('i', {'class': 'bi bi-exclamation-triangle text-warning ms-1', title: t('err_share_broken')}) : null
			]);
			nav.appendChild(a);
		});
		if (!(boot.shares || []).length) nav.appendChild(el('div', {'class': 'px-3 py-2 small text-body-secondary', text: t('no_shares')}));
		$('nufTrashBtn').classList.toggle('active', state.view === 'trash');
		$('nufLogBtn').classList.toggle('active', state.view === 'log');
	}

	function hashFor(share, path) {
		return '#/' + encodeURIComponent(share) + '/' + path.split('/').filter(Boolean).map(encodeURIComponent).join('/');
	}

	function readHash() {
		var h = window.location.hash.replace(/^#\/?/, '');
		if (h === 'trash') return {view: 'trash'};
		if (h === 'log') return {view: 'log'};
		var parts = h.split('/');
		var id = '';
		try { id = decodeURIComponent(parts.shift() || ''); } catch (e) { id = ''; }
		var path = parts.filter(Boolean).map(function (p) { try { return decodeURIComponent(p); } catch (e) { return p; } }).join('/');
		return {view: 'files', share: id, path: path};
	}

	function go(share, path) {
		var h = hashFor(share, path || '');
		if (window.location.hash === h) route(); else window.location.hash = h;
	}

	function route() {
		var r = readHash();
		if (r.view === 'trash') return showTrash();
		if (r.view === 'log') return boot.features.log ? showLog() : go((boot.shares[0] || {}).id || '', '');
		var s = shareById(r.share) || (boot.shares || [])[0] || null;
		if (!s) { state.share = null; renderShares(); message(t('no_shares')); toolbar(); return; }
		if (s.id !== r.share) { go(s.id, ''); return; }
		state.view = 'files';
		state.share = s;
		state.path = r.path;
		state.query = '';
		$('nufSearch').value = '';
		load();
	}
	window.addEventListener('hashchange', route);
	// a link to the place already in the address (share in the side bar, breadcrumb during a search): no hashchange – load again
	document.addEventListener('click', function (e) {
		var a = e.target.closest('a[href^="#/"]');
		if (a && a.getAttribute('href') === window.location.hash && !e.ctrlKey && !e.metaKey) { e.preventDefault(); route(); }
	});

	function message(text) {
		var m = $('nufMsg');
		m.textContent = text || '';
		m.classList.toggle('d-none', !text);
	}

	function crumbs() {
		var ol = $('nufCrumbs');
		ol.textContent = '';
		if (state.view === 'trash' || state.view === 'log') {
			ol.appendChild(el('li', {'class': 'breadcrumb-item active', text: t(state.view === 'trash' ? 'trash' : 'log')}));
			NuPage.setTitle(t('title') + ' – ' + t(state.view === 'trash' ? 'trash' : 'log'));
			return;
		}
		if (!state.share) return;
		var parts = state.path ? state.path.split('/') : [];
		var li = el('li', {'class': 'breadcrumb-item' + (parts.length || state.view === 'search' ? '' : ' active')});
		li.appendChild(parts.length || state.view === 'search' ? el('a', {href: hashFor(state.share.id, '')}, [icon(state.share.type === 'code' ? 'code-square' : 'folder2'), ' ', state.share.label])
			: el('span', {}, [icon(state.share.type === 'code' ? 'code-square' : 'folder2'), ' ', state.share.label]));
		ol.appendChild(li);
		parts.forEach(function (p, i) {
			var last = i === parts.length - 1 && state.view !== 'search';
			var item = el('li', {'class': 'breadcrumb-item' + (last ? ' active' : '')});
			item.appendChild(last ? el('span', {text: p}) : el('a', {href: hashFor(state.share.id, parts.slice(0, i + 1).join('/')), text: p}));
			ol.appendChild(item);
		});
		if (state.view === 'search') ol.appendChild(el('li', {'class': 'breadcrumb-item active', text: t('search_results', state.query)}));
		NuPage.setTitle(t('title') + ' – ' + state.share.label + (state.path ? '/' + state.path : ''));
	}

	function toolbar() {
		var files = state.view === 'files' || state.view === 'search';
		$('nufTools').classList.toggle('d-none', !files || !state.share);
		$('nufSearchForm').classList.toggle('d-none', !files || !state.share);
		$('nufTable').classList.toggle('d-none', !files);
		$('nufTable2').classList.toggle('d-none', files);
		$('nufRoBadge').classList.toggle('d-none', !(boot.readonly || (state.share && state.share.rights.length === 1)));
		if (!state.share) return;
		var nsel = state.sel.size;
		document.querySelectorAll('#nufTools [data-need]').forEach(function (b) {
			var need = b.getAttribute('data-need').split('|');
			var ok = need.some(function (r) { return r === 'view' ? !!state.share : can(r); });
			if (b.id === 'nufCopyBtn') ok = (boot.shares || []).some(function (s) { return can(s.id === state.share.id ? 'copy' : 'upload', s); });
			b.classList.toggle('d-none', !ok);
		});
		document.querySelectorAll('#nufTools [data-sel]').forEach(function (b) { b.disabled = nsel === 0; });
		$('nufMkdirBtn').disabled = state.view === 'search';
		$('nufNewFileBtn').disabled = state.view === 'search';
		$('nufUploadBtn').disabled = state.view === 'search';
	}

	// ================================================================== the list

	function load(keepSel) {
		if (!state.share) return Promise.resolve();
		var share = state.share, path = state.path;
		state.view = 'files';
		renderShares();
		crumbs();
		message(t('loading'));
		$('nufRows').textContent = '';
		return api('list', {share: share.id, path: path}).then(function (d) {
			if (state.share !== share || state.path !== path || state.view !== 'files') return;
			state.path = d.path;
			state.items = d.items;
			state.listing = d;
			if (!keepSel) state.sel.clear();
			else state.sel.forEach(function (n) { if (!d.items.some(function (i) { return i.name === n; })) state.sel.delete(n); });
			render();
		}).catch(function (e) {
			message(e.message || t('unknown_error'));
			if (e instanceof ApiError && (e.key === 'err_not_found' || e.key === 'err_not_folder') && path !== '') { toast(e.message, 'warning'); go(share.id, parent(path)); return; }
			if (!(e instanceof ApiError)) fail(e);
			toolbar();
		});
	}

	function sorted(items) {
		var k = state.sort.key, dir = state.sort.dir;
		var coll = typeof Intl !== 'undefined' ? new Intl.Collator(boot.lang || undefined, {numeric: true, sensitivity: 'base'}) : null;
		return items.slice().sort(function (a, b) {
			if (a.type !== b.type && state.view === 'files') return a.type === 'dir' ? -1 : 1;
			var r = 0;
			if (k === 'size') r = (a.size || 0) - (b.size || 0);
			else if (k === 'mtime') r = (a.mtime || 0) - (b.mtime || 0);
			if (r === 0) r = coll ? coll.compare(a.name, b.name) : (a.name < b.name ? -1 : a.name > b.name ? 1 : 0);
			return r * dir;
		});
	}

	function keyOf(it) { return state.view === 'search' ? join(it.dir, it.name) : it.name; }
	function pathOf(it) { return state.view === 'search' ? join(it.dir, it.name) : join(state.path, it.name); }

	function render() {
		toolbar();
		crumbs();
		var tb = $('nufRows');
		tb.textContent = '';
		var items = sorted(state.items);
		message(items.length ? '' : (state.view === 'search' ? t('no_results') : t('empty_folder')));
		if (state.view === 'files' && state.path !== '') {
			tb.appendChild(el('tr', {'class': 'nuf-up', ondblclick: function () { go(state.share.id, parent(state.path)); }}, [
				el('td'), el('td', {colspan: '4'}, [el('a', {href: hashFor(state.share.id, parent(state.path)), 'class': 'nuf-name'}, [icon('arrow-90deg-up'), ' ..'])])
			]));
		}
		var frag = document.createDocumentFragment();
		items.forEach(function (it) { frag.appendChild(row(it)); });
		tb.appendChild(frag);
		document.querySelectorAll('.nuf-sort').forEach(function (b) {
			b.classList.toggle('nuf-sorted', b.getAttribute('data-sort') === state.sort.key);
			b.setAttribute('data-dir', state.sort.dir > 0 ? 'asc' : 'desc');
		});
		status();
	}

	function row(it) {
		var key = keyOf(it);
		var p = pathOf(it);
		var chk = el('input', {type: 'checkbox', 'class': 'form-check-input', 'aria-label': it.name, checked: state.sel.has(key) ? true : null});
		chk.addEventListener('change', function () { if (chk.checked) state.sel.add(key); else state.sel.delete(key); tr.classList.toggle('table-active', chk.checked); status(); toolbar(); });
		var nameLink = el('a', {href: it.type === 'dir' ? hashFor(state.share.id, p) : '#', 'class': 'nuf-name', title: it.outside ? t('link_outside') : it.name}, [
			el('i', {'class': 'bi bi-' + fileIcon(it) + (it.type === 'dir' ? ' nuf-ico-dir' : ''), 'aria-hidden': 'true'}), el('span', {text: it.name})]);
		if (it.type !== 'dir') nameLink.addEventListener('click', function (e) { e.preventDefault(); open(it, p); });
		var nameCell = el('td', {'class': 'nuf-c-name'}, [nameLink]);
		if (it.link) nameCell.appendChild(el('i', {'class': 'bi bi-link-45deg ms-1 text-body-secondary', title: it.outside ? t('link_outside') : 'symlink'}));
		if (state.view === 'search') nameCell.appendChild(el('div', {'class': 'small text-body-secondary text-truncate', text: '/' + it.dir}));
		var tr = el('tr', {'class': state.sel.has(key) ? 'table-active' : null, 'data-key': key}, [
			el('td', {'class': 'nuf-c-check'}, [chk]),
			nameCell,
			el('td', {'class': 'nuf-c-size text-end text-nowrap', text: it.type === 'dir' ? '' : size(it.size)}),
			el('td', {'class': 'nuf-c-time text-nowrap', text: when(it.mtime)}),
			el('td', {'class': 'nuf-c-act text-end'}, [menu(it, p)])
		]);
		tr.addEventListener('dblclick', function (e) {
			if (e.target.closest('input, button, a, .dropdown-menu')) return;
			if (it.type === 'dir') go(state.share.id, p); else open(it, p);
		});
		tr.addEventListener('click', function (e) {
			if (e.target.closest('input, button, a, .dropdown-menu')) return;
			if (e.ctrlKey || e.metaKey) {
				chk.checked = !chk.checked;
			} else {
				state.sel.clear();
				document.querySelectorAll('#nufRows tr.table-active').forEach(function (r) { r.classList.remove('table-active'); var c = r.querySelector('input'); if (c) c.checked = false; });
				chk.checked = true;
			}
			if (chk.checked) state.sel.add(key); else state.sel.delete(key);
			tr.classList.toggle('table-active', chk.checked);
			status(); toolbar();
		});
		return tr;
	}

	function menu(it, p) {
		var items = [];
		function add(label, ico, fn, cls) {
			items.push(el('li', {}, [el('button', {type: 'button', 'class': 'dropdown-item' + (cls ? ' ' + cls : ''), onclick: fn}, [icon(ico), ' ' + label])]));
		}
		var k = kind(it.name);
		if (it.type === 'dir') add(t('open'), 'folder2-open', function () { go(state.share.id, p); });
		else if (k === 'text') add(can('edit') ? t('edit') : t('open'), can('edit') ? 'pencil-square' : 'eye', function () { open(it, p); });
		else if (['image', 'pdf', 'video', 'audio'].indexOf(k) >= 0) add(t('preview'), 'eye', function () { preview(p); });
		if (it.type === 'dir') add(t('download_zip'), 'file-earmark-zip', function () { download([p]); });
		else add(t('download'), 'download', function () { download([p]); });
		if (it.type !== 'dir' && k === 'zip' && can('zip') && boot.features.zip) add(t('unzip'), 'box-arrow-up', function () { unzip(p); });
		if (can('rename')) add(t('rename'), 'input-cursor-text', function () { rename(it, p); });
		items.push(el('li', {}, [el('hr', {'class': 'dropdown-divider'})]));
		add(t('copy'), 'copy', function () { transfer([p], true); });
		if (can('rename') || can('delete')) add(t('move'), 'arrow-right-square', function () { transfer([p], false); });
		add(t('info'), 'info-circle', function () { info(p); });
		if (can('delete')) { items.push(el('li', {}, [el('hr', {'class': 'dropdown-divider'})])); add(t('delete'), 'trash3', function () { remove([p]); }, 'text-danger'); }
		return el('div', {'class': 'dropdown'}, [
			el('button', {type: 'button', 'class': 'btn btn-sm btn-link text-body nuf-more', 'data-bs-toggle': 'dropdown', 'aria-expanded': 'false', 'aria-label': t('actions'), 'data-bs-popper-config': '{"strategy":"fixed"}'}, [icon('three-dots-vertical')]),
			el('ul', {'class': 'dropdown-menu dropdown-menu-end'}, items)
		]);
	}

	function status() {
		var l = $('nufStatusLeft'), r = $('nufStatusRight');
		if (state.view === 'trash' || state.view === 'log') { l.textContent = ''; r.textContent = ''; return; }
		var dirs = 0, files = 0, bytes = 0;
		state.items.forEach(function (i) { if (i.type === 'dir') dirs++; else { files++; bytes += i.size || 0; } });
		var txt = t('count_line', dirs, files, size(bytes));
		if (state.sel.size) txt += ' · ' + t('selected_n', state.sel.size);
		if (state.listing && state.listing.truncated) txt += ' · ' + t('entries_truncated', state.items.length);
		if (state.view === 'search' && state.listing && state.listing.partial) txt += ' · ' + t('partial_results');
		l.textContent = txt;
		r.textContent = state.view === 'files' && state.listing && state.listing.free ? t('free_space', size(state.listing.free)) : '';
		var all = $('nufAll');
		all.checked = state.items.length > 0 && state.sel.size === state.items.length;
		all.indeterminate = state.sel.size > 0 && state.sel.size < state.items.length;
	}

	function selectedPaths() {
		return state.items.filter(function (i) { return state.sel.has(keyOf(i)); }).map(pathOf);
	}

	$('nufAll').addEventListener('change', function () {
		var on = this.checked;
		state.sel.clear();
		if (on) state.items.forEach(function (i) { state.sel.add(keyOf(i)); });
		render();
	});

	document.querySelectorAll('.nuf-sort').forEach(function (b) {
		b.addEventListener('click', function () {
			var k = b.getAttribute('data-sort');
			if (state.sort.key === k) state.sort.dir = -state.sort.dir; else { state.sort.key = k; state.sort.dir = k === 'name' ? 1 : -1; }
			try { localStorage.setItem('nufSort', JSON.stringify(state.sort)); } catch (e) { /* private mode */ }
			if (state.view === 'files' || state.view === 'search') render();
		});
	});
	try { var savedSort = JSON.parse(localStorage.getItem('nufSort') || 'null'); if (savedSort && savedSort.key) state.sort = savedSort; } catch (e) { /* none */ }

	// ================================================================== search

	$('nufSearchForm').addEventListener('submit', function (e) {
		e.preventDefault();
		var q = $('nufSearch').value.trim();
		if (!q) { go(state.share.id, state.path); return; }
		var share = state.share, path = state.path;
		state.view = 'search';
		state.query = q;
		state.sel.clear();
		state.items = [];
		crumbs();
		message(t('loading'));
		$('nufRows').textContent = '';
		api('search', {share: share.id, path: path, q: q}).then(function (d) {
			if (state.view !== 'search' || state.query !== q) return;
			state.items = d.items;
			state.listing = d;
			render();
		}).catch(function (e) { message(''); fail(e); });
	});
	$('nufSearch').addEventListener('search', function () { if (this.value === '' && state.view === 'search') go(state.share.id, state.path); });

	// ================================================================== actions

	function after(promise, okText) {
		// the selection is cleared afterwards: a later Delete must not hit what was just copied or moved
		return promise.then(function (d) { if (okText) toast(typeof okText === 'function' ? okText(d) : okText, 'success'); return load().then(function () { return d; }); })
			.catch(function (e) { fail(e); load(true); });
	}

	function mkdir() {
		prompt(t('prompt_folder'), '').then(function (name) {
			if (!name) return;
			after(api('mkdir', {share: state.share.id, path: state.path, name: name}, true));
		});
	}

	function newFile() {
		prompt(t('prompt_file'), '.txt', true).then(function (name) {
			if (!name) return;
			var share = state.share;
			api('newfile', {share: share.id, path: state.path, name: name}, true).then(function (d) {
				return load(true).then(function () { editor(share, d.path); });
			}).catch(fail);
		});
	}

	function rename(it, p) {
		prompt(t('prompt_rename'), it.name, it.type !== 'dir').then(function (name) {
			if (!name || name === it.name) return;
			after(api('rename', {share: state.share.id, path: p, name: name}, true));
		});
	}

	function remove(paths) {
		if (!paths.length) { toast(t('nothing_selected'), 'warning'); return; }
		var trash = state.share.trash && boot.features.trash;
		var names = paths.slice(0, 8).map(base).join(', ') + (paths.length > 8 ? ' …' : '');
		confirmBox(t('confirm_delete_n', paths.length), names + '\n\n' + t(trash ? 'confirm_delete_trash' : 'confirm_delete_final'), t('delete'), true).then(function (ok) {
			if (!ok) return;
			after(api('delete', {share: state.share.id, paths: paths}, true), function (d) { return t('deleted_n', d.done); });
		});
	}

	function transfer(paths, copy) {
		if (!paths.length) { toast(t('nothing_selected'), 'warning'); return; }
		var from = state.share;
		pickFolder(copy ? t('copy') : t('move'), function (s) {
			if (s.id === from.id) return can(copy ? 'copy' : 'rename', s);
			return can('upload', s) && (copy || can('delete', from));
		}).then(function (target) {
			if (!target) return;
			after(api(copy ? 'copy' : 'move', {share: from.id, paths: paths, to_share: target.share.id, to_path: target.path}, true),
				function (d) { return t(copy ? 'copied_n' : 'moved_n', d.done); });
		});
	}

	function zip(paths) {
		if (!paths.length) { toast(t('nothing_selected'), 'warning'); return; }
		var def = paths.length === 1 ? base(paths[0]).replace(/\.[^.]*$/, '') + '.zip' : (state.path ? base(state.path) : state.share.id) + '.zip';
		prompt(t('prompt_zip'), def, true).then(function (name) {
			if (!name) return;
			after(api('zip', {share: state.share.id, path: state.view === 'search' ? '' : state.path, paths: paths, name: name}, true), function (d) { return t('zip_done', d.name); });
		});
	}

	function unzip(p) {
		api('unzip', {share: state.share.id, path: p}, true).then(function (d) {
			toast(t('unzip_done', d.files, d.folder), 'success');
			if (d.skipped && d.skipped.length) {
				var ul = el('ul', {'class': 'small mb-0 nuf-skipped'});
				d.skipped.slice(0, 200).forEach(function (s) { ul.appendChild(el('li', {}, [el('code', {text: s.name}), ' – ' + t(s.reason)])); });
				dialog({title: t('files_skipped', d.skipped.length), node: ul});
			}
			return load(true);
		}).catch(function (e) { fail(e); load(true); });
	}

	function download(paths) {
		if (!paths.length) { toast(t('nothing_selected'), 'warning'); return; }
		var share = state.share;
		var single = paths.length === 1 && state.items.some(function (i) { return pathOf(i) === paths[0] && i.type !== 'dir'; });
		// first a cheap call: it asks for the password confirmation if the share needs one (a plain link could not)
		api('info', {share: share.id, path: paths[0]}).then(function () {
			if (single) { window.location.href = url('download', {share: share.id, path: paths[0]}); return; }
			// several entries: a POST form (the list may be long for a URL)
			var f = el('form', {method: 'post', action: url('download'), 'class': 'd-none'}, [
				el('input', {type: 'hidden', name: '_csrf', value: NuPage.csrf}),
				el('input', {type: 'hidden', name: 'share', value: share.id}),
				el('input', {type: 'hidden', name: 'paths', value: JSON.stringify(paths)})
			]);
			document.body.appendChild(f);
			f.submit();
			setTimeout(function () { f.remove(); }, 1000);
		}).catch(fail);
	}

	function info(p) {
		api('info', {share: state.share.id, path: p}).then(function (d) {
			var i = d.info;
			var rows = [[t('name'), i.name], [t('type'), i.type === 'dir' ? t('folder') : (i.mime || t('file'))], [t('log_path'), '/' + i.path]];
			if (i.absolute) rows.push([t('absolute_path'), i.absolute]);
			rows.push([t('size'), i.type === 'dir' ? size(i.bytes) + ' · ' + t('n_files', i.files) : size(i.size)]);
			rows.push([t('modified'), when(i.mtime)], [t('permissions'), i.perms + (i.writable ? '' : ' · ' + t('readonly_badge'))]);
			if (i.link) rows.push(['symlink', i.outside ? t('link_outside') : '✓']);
			var tbl = el('table', {'class': 'table table-sm mb-0'}, [el('tbody', {}, rows.map(function (r) {
				return el('tr', {}, [el('th', {'class': 'text-nowrap', text: r[0]}), el('td', {'class': 'text-break', text: r[1]})]);
			}))]);
			var copyBtn = el('button', {type: 'button', 'class': 'btn btn-sm btn-outline-secondary mt-2', onclick: function () {
				var txt = i.absolute || ('/' + i.path);
				if (navigator.clipboard) navigator.clipboard.writeText(txt).then(function () { toast(t('path_copied'), 'success'); }).catch(function () {});
			}}, [icon('clipboard'), ' ' + t('copy_path')]);
			dialog({title: t('info'), node: el('div', {}, [tbl, copyBtn])});
		}).catch(fail);
	}

	$('nufMkdirBtn').addEventListener('click', mkdir);
	$('nufNewFileBtn').addEventListener('click', newFile);
	$('nufRefreshBtn').addEventListener('click', function () {
		if (state.view === 'trash') showTrash(); else if (state.view === 'log') showLog(); else if (state.view === 'search') $('nufSearchForm').requestSubmit(); else load(true);
	});
	$('nufDownloadBtn').addEventListener('click', function () { download(selectedPaths()); });
	$('nufCopyBtn').addEventListener('click', function () { transfer(selectedPaths(), true); });
	$('nufMoveBtn').addEventListener('click', function () { transfer(selectedPaths(), false); });
	$('nufZipBtn').addEventListener('click', function () { zip(selectedPaths()); });
	$('nufDeleteBtn').addEventListener('click', function () { remove(selectedPaths()); });

	// keyboard in the list
	document.addEventListener('keydown', function (e) {
		if (state.view !== 'files' && state.view !== 'search') return;
		if (document.querySelector('.modal.show') || e.target.closest('input, textarea, select, .CodeMirror')) return;
		var paths = selectedPaths();
		if (e.key === 'Delete' && can('delete') && paths.length) { e.preventDefault(); remove(paths); }
		else if (e.key === 'F2' && can('rename') && paths.length === 1) {
			e.preventDefault();
			var it = state.items.filter(function (i) { return pathOf(i) === paths[0]; })[0];
			if (it) rename(it, paths[0]);
		} else if (e.key === 'Backspace' && state.view === 'files' && state.path) { e.preventDefault(); go(state.share.id, parent(state.path)); }
		else if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'a') { e.preventDefault(); $('nufAll').checked = true; $('nufAll').dispatchEvent(new Event('change')); }
	});

	// ================================================================== folder picker (copy / move)

	var pick = {modal: null, share: null, path: '', resolve: null, result: null};
	function pickFolder(title, allowed) {
		return new Promise(function (resolve) {
			if (!pick.modal) pick.modal = new bootstrap.Modal($('nufPick'));
			var shares = (boot.shares || []).filter(function (s) { return s.ok && allowed(s); });
			if (!shares.length) { toast(t('err_right', ''), 'warning'); resolve(null); return; }
			pick.resolve = resolve;
			pick.result = null;
			$('nufPickTitle').textContent = title + ' – ' + t('choose_folder');
			var sel = $('nufPickShare');
			sel.textContent = '';
			shares.forEach(function (s) { sel.appendChild(el('option', {value: s.id, text: s.label})); });
			var start = shares.some(function (s) { return s.id === state.share.id; }) ? state.share.id : shares[0].id;
			sel.value = start;
			pickLoad(shareById(start), shares.some(function (s) { return s.id === state.share.id; }) ? state.path : '');
			pick.modal.show();
		});
	}
	function pickLoad(share, path) {
		pick.share = share;
		pick.path = path;
		var pathBox = $('nufPickPath');
		pathBox.textContent = '';
		pathBox.appendChild(el('span', {'class': 'fw-semibold', text: share.label}));
		pathBox.appendChild(document.createTextNode(' /' + path));
		var list = $('nufPickList');
		list.textContent = '';
		list.appendChild(el('div', {'class': 'text-body-secondary small p-2', text: t('loading')}));
		api('list', {share: share.id, path: path}).then(function (d) {
			if (pick.share !== share || pick.path !== path) return;
			list.textContent = '';
			if (path) list.appendChild(el('button', {type: 'button', 'class': 'list-group-item list-group-item-action', onclick: function () { pickLoad(share, parent(path)); }}, [icon('arrow-90deg-up'), ' ..']));
			d.items.filter(function (i) { return i.type === 'dir' && !i.outside; }).forEach(function (i) {
				list.appendChild(el('button', {type: 'button', 'class': 'list-group-item list-group-item-action', onclick: function () { pickLoad(share, join(path, i.name)); }},
					[el('i', {'class': 'bi bi-folder-fill nuf-ico-dir', 'aria-hidden': 'true'}), ' ', i.name]));
			});
		}).catch(function (e) { list.textContent = ''; list.appendChild(el('div', {'class': 'text-danger small p-2', text: e.message || t('unknown_error')})); });
	}
	$('nufPickShare').addEventListener('change', function () { pickLoad(shareById(this.value), ''); });
	$('nufPickOk').addEventListener('click', function () { pick.result = {share: pick.share, path: pick.path}; hideModal(pick.modal, 'nufPick'); });
	$('nufPick').addEventListener('hidden.bs.modal', function () { var r = pick.resolve; pick.resolve = null; if (r) r(pick.result); });

	// ================================================================== open, preview

	function open(it, p) {
		var k = kind(it.name);
		if (k === 'text') editor(state.share, p);
		else if (['image', 'pdf', 'video', 'audio'].indexOf(k) >= 0) preview(p);
		else if (k === 'zip' && can('zip')) {
			confirmBox(t('unzip'), it.name, t('unzip')).then(function (ok) { if (ok) unzip(p); });
		} else download([p]);
	}

	var previewModal = null;
	function preview(p) {
		var share = state.share;
		api('info', {share: share.id, path: p}).then(function () {          // password confirmation first, if needed
			if (!previewModal) {
				previewModal = new bootstrap.Modal($('nufPreview'));
				$('nufPreview').addEventListener('hidden.bs.modal', function () { $('nufPreviewBody').textContent = ''; });
			}
			var src = url('raw', {share: share.id, path: p});
			var body = $('nufPreviewBody');
			body.textContent = '';
			$('nufPreviewTitle').textContent = base(p);
			$('nufPreviewDl').setAttribute('href', url('download', {share: share.id, path: p}));
			var k = kind(p);
			if (k === 'image') body.appendChild(el('img', {src: src, alt: base(p), 'class': 'nuf-preview-img'}));
			else if (k === 'pdf') body.appendChild(el('iframe', {src: src, title: base(p), 'class': 'nuf-preview-frame'}));
			else if (k === 'video') body.appendChild(el('video', {src: src, controls: true, 'class': 'nuf-preview-media'}));
			else if (k === 'audio') body.appendChild(el('audio', {src: src, controls: true, 'class': 'w-100'}));
			else body.appendChild(el('p', {text: t('preview_unavailable')}));
			previewModal.show();
		}).catch(fail);
	}

	// ================================================================== editor

	var ed = {modal: null, cm: null, share: null, path: '', sha: '', clean: 0, readOnly: false};

	function cmMode(name) {
		var e = ext(name);
		if (['php', 'phtml', 'inc'].indexOf(e) >= 0) return 'application/x-httpd-php';
		if (['js', 'mjs'].indexOf(e) >= 0) return 'text/javascript';
		if (e === 'ts') return 'text/typescript';
		if (e === 'json' || e === 'map') return 'application/json';
		if (e === 'css') return 'text/css';
		if (e === 'scss') return 'text/x-scss';
		if (e === 'less') return 'text/x-less';
		if (['html', 'htm', 'vue', 'tpl', 'twig'].indexOf(e) >= 0) return 'text/html';
		if (['xml', 'svg', 'xsl', 'xslt'].indexOf(e) >= 0) return 'application/xml';
		if (e === 'sql') return 'text/x-mysql';
		return 'text/plain';
	}

	function editor(share, p) {
		api('read', {share: share.id, path: p}).then(function (d) {
			if (!d.text) {
				var why = {too_big: 'text_too_big', binary: 'binary_file', encoding: 'encoding_file'}[d.reason] || 'err_not_text';
				dialog({title: base(p), text: t(why), buttons: [{label: t('close'), value: false}, {label: t('download'), value: true, cls: 'btn-primary'}]})
					.then(function (v) { if (v === true) window.location.href = url('download', {share: share.id, path: p}); });
				return;
			}
			if (!ed.modal) {
				ed.modal = new bootstrap.Modal($('nufEditor'));
				ed.cm = CodeMirror.fromTextArea($('nufCode'), {
					lineNumbers: true, indentUnit: 4, tabSize: 4, indentWithTabs: true, matchBrackets: true, styleActiveLine: true,
					extraKeys: {
						'Ctrl-S': function () { save(false, false); }, 'Cmd-S': function () { save(false, false); },
						'Ctrl-/': 'toggleComment', 'Cmd-/': 'toggleComment', 'Alt-G': 'jumpToLine',
						'Esc': function () { closeEditor(); }
					}
				});
				ed.cm.on('changes', editorState);
				ed.cm.on('cursorActivity', function () { var c = ed.cm.getCursor(); $('nufEditorPos').textContent = (c.line + 1) + ':' + (c.ch + 1); });
				$('nufEditor').addEventListener('shown.bs.modal', function () { ed.cm.refresh(); ed.cm.focus(); });
				try { $('nufWrap').checked = localStorage.getItem('nufWrap') === '1'; } catch (e) { /* none */ }
				ed.cm.setOption('lineWrapping', $('nufWrap').checked);
			}
			ed.share = share;
			ed.path = p;
			ed.sha = d.sha;
			ed.readOnly = !d.editable;
			// CodeMirror joins lines with \n: keep the file's own line ends (Windows files stay CRLF)
			var crlf = (d.content.match(/\r\n/g) || []).length;
			var lf = (d.content.match(/\n/g) || []).length;
			ed.cm.setOption('lineSeparator', crlf > 0 && crlf * 2 >= lf ? '\r\n' : null);
			ed.cm.setOption('mode', cmMode(p));
			ed.cm.setOption('readOnly', ed.readOnly);
			ed.cm.setValue(d.content);
			ed.cm.clearHistory();
			ed.clean = ed.cm.changeGeneration();
			$('nufEditorTitle').textContent = share.label + ' / ' + p;
			$('nufEditorTitle').title = share.label + ' / ' + p;
			// nuBuilder Next's tool buttons for this file's language (undo / redo, search, replace, comment, format, copy / paste)
			if (window.NuCodeTools) {
				if (ed.tools && ed.tools.nuDetach) ed.tools.nuDetach();
				var ext = (p.split('.').pop() || '').toLowerCase();
				var lang = {php: 'PHP', phtml: 'PHP', inc: 'PHP', js: 'JS', mjs: 'JS', json: 'JS', sql: 'SQL', html: 'HTML', htm: 'HTML', css: 'CSS'}[ext] || '';
				ed.tools = window.NuCodeTools.attach(ed.cm, $('nufCodeTools'), {lang: lang, readOnly: ed.readOnly, noFull: true, compact: true});
			}
			$('nufEditorRo').classList.toggle('d-none', !ed.readOnly);
			$('nufSaveBtn').classList.toggle('d-none', ed.readOnly);
			$('nufVersionsBtn').parentNode.classList.toggle('d-none', ed.readOnly);
			editorState();
			ed.modal.show();
		}).catch(fail);
	}

	function dirty() { return ed.cm && !ed.cm.isClean(ed.clean); }

	function editorState() {
		$('nufSaveBtn').disabled = !dirty();
		$('nufEditorTitle').classList.toggle('nuf-dirty', dirty());
	}

	function save(force, forceLint) {
		if (ed.readOnly || !ed.cm) return;
		var text = ed.cm.getValue();
		var gen = ed.cm.changeGeneration();
		$('nufSaveBtn').disabled = true;
		// the text goes as a file: nuBuilder Next rewrites CRLF in posted fields, files arrive byte for byte
		api('save', {share: ed.share.id, path: ed.path, sha: ed.sha, force: force ? '1' : null, force_lint: forceLint ? '1' : null,
			content: new Blob([text], {type: 'text/plain'})}, true).then(function (d) {
			ed.sha = d.sha;
			ed.clean = gen;
			editorState();
			toast(t('saved') + ' – ' + base(ed.path), 'success');
			if (state.view === 'files') load(true);
		}).catch(function (e) {
			editorState();
			if (e instanceof ApiError && e.key === 'err_conflict') {
				dialog({title: t('conflict_title'), text: t('conflict_text'), buttons: [{label: t('cancel'), value: null}, {label: t('reload'), value: 'reload', cls: 'btn-outline-secondary'},
					{label: t('overwrite'), value: 'overwrite', cls: 'btn-danger'}]}).then(function (v) {
					if (v === 'overwrite') save(true, forceLint);
					else if (v === 'reload') { ed.cm.markClean(); editor(ed.share, ed.path); }
				});
			} else if (e instanceof ApiError && e.key === 'err_lint') {
				var line = e.data.lint && e.data.lint.line ? e.data.lint.line : 0;
				if (line > 0) { ed.cm.setCursor({line: line - 1, ch: 0}); ed.cm.scrollIntoView(null, 120); }
				dialog({title: t('lint_title'), text: e.message, buttons: [{label: t('cancel'), value: false}, {label: t('save_anyway'), value: true, cls: 'btn-warning'}]})
					.then(function (v) { if (v === true) save(force, true); else ed.cm.focus(); });
			} else fail(e);
		});
	}

	function closeEditor() {
		if (!dirty()) { hideModal(ed.modal, 'nufEditor'); return; }
		confirmBox(t('close'), t('unsaved'), t('close'), true).then(function (ok) { if (ok) { ed.cm.markClean(); ed.clean = ed.cm.changeGeneration(); hideModal(ed.modal, 'nufEditor'); } });
	}

	$('nufSaveBtn').addEventListener('click', function () { save(false, false); });
	$('nufEditorClose').addEventListener('click', closeEditor);
	$('nufWrap').addEventListener('change', function () {
		if (ed.cm) ed.cm.setOption('lineWrapping', this.checked);
		try { localStorage.setItem('nufWrap', this.checked ? '1' : '0'); } catch (e) { /* none */ }
	});
	$('nufVersionsBtn').addEventListener('show.bs.dropdown', function () {
		var ul = $('nufVersions');
		ul.textContent = '';
		ul.appendChild(el('li', {}, [el('span', {'class': 'dropdown-item-text small text-body-secondary', text: t('loading')})]));
		api('versions', {share: ed.share.id, path: ed.path}).then(function (d) {
			ul.textContent = '';
			if (!d.versions.length) { ul.appendChild(el('li', {}, [el('span', {'class': 'dropdown-item-text small text-body-secondary', text: t('no_versions')})])); return; }
			d.versions.forEach(function (v) {
				ul.appendChild(el('li', {}, [el('button', {type: 'button', 'class': 'dropdown-item small', title: t('restore_version'), onclick: function () {
					api('version', {share: ed.share.id, path: ed.path, id: v.id}).then(function (r) {
						ed.cm.setValue(r.content);   // an unsaved change – Save writes it back (the current text becomes a version itself)
						toast(t('version_loaded'), 'info');
					}).catch(fail);
				}}, [when(v.mtime) + ' · ' + size(v.size)])]));
			});
		}).catch(function (e) { ul.textContent = ''; ul.appendChild(el('li', {}, [el('span', {'class': 'dropdown-item-text small text-danger', text: e.message})])); });
	});
	window.addEventListener('beforeunload', function (e) { if (ed.modal && $('nufEditor').classList.contains('show') && dirty()) { e.preventDefault(); e.returnValue = ''; } });

	// ================================================================== uploads (in chunks, resumable after a hiccup)

	var up = {queue: [], running: 0, max: 2, total: 0, done: 0, failed: 0, dirs: {}};

	function chooseFiles() { $('nufFileInput').click(); }
	$('nufUploadBtn').addEventListener('click', chooseFiles);
	$('nufFileInput').addEventListener('change', function () {
		var files = Array.prototype.slice.call(this.files || []);
		this.value = '';
		enqueue(files.map(function (f) { return {file: f, dir: ''}; }));
	});

	function enqueue(list) {
		if (!list.length || !state.share) return;
		if (!can('upload')) { toast(t('err_right', t('upload')), 'warning'); return; }
		var share = state.share, path = state.view === 'files' ? state.path : '';
		var overwrite = $('nufOverwrite').checked && can('delete');
		$('nufUploads').classList.remove('d-none');
		list.forEach(function (x) {
			var li = el('li', {'class': 'list-group-item small'}, [
				el('div', {'class': 'd-flex gap-2'}, [el('span', {'class': 'text-truncate me-auto', text: join(x.dir, x.file.name)}), el('span', {'class': 'text-nowrap nuf-up-state', text: size(x.file.size)})]),
				el('div', {'class': 'progress mt-1', role: 'progressbar', 'aria-label': x.file.name}, [el('div', {'class': 'progress-bar', style: 'width:0%'})])
			]);
			$('nufUploadList').appendChild(li);
			up.queue.push({file: x.file, share: share, dir: join(path, x.dir), sub: x.dir, overwrite: overwrite, li: li});
			up.total++;
		});
		uploadTitle();
		pump();
	}

	function uploadTitle() {
		$('nufUploadsTitle').textContent = up.running || up.queue.length ? t('uploading') + ' ' + up.done + ' / ' + up.total : t('upload_done') + ' ' + up.done + ' / ' + up.total;
	}

	function pump() {
		while (up.running < up.max && up.queue.length) {
			var job = up.queue.shift();
			up.running++;
			uploadOne(job).then(function () { up.done++; }, function (e) { up.failed++; return e; }).then(function () {
				up.running--;
				uploadTitle();
				if (!up.running && !up.queue.length) {
					if (state.view === 'files') load(true);
					if (!up.failed) setTimeout(function () { if (!up.running && !up.queue.length) { $('nufUploads').classList.add('d-none'); $('nufUploadList').textContent = ''; up.total = 0; up.done = 0; } }, 4000);
				}
				pump();
			});
		}
	}

	function ensureDir(share, dir) {
		// folders of a dropped folder: created one level at a time (an existing one is fine)
		if (!dir) return Promise.resolve();
		var key = share.id + ':' + dir;
		if (up.dirs[key]) return up.dirs[key];
		up.dirs[key] = ensureDir(share, parent(dir)).then(function () {
			return api('mkdir', {share: share.id, path: parent(dir), name: base(dir)}, true).catch(function (e) { if (!(e instanceof ApiError && e.key === 'err_exists')) throw e; });
		});
		return up.dirs[key];
	}

	function uploadOne(job) {
		var bar = job.li.querySelector('.progress-bar'), st = job.li.querySelector('.nuf-up-state');
		var chunk = Math.max(65536, boot.limits.chunk || 2097152);
		function setP(n) { var pc = job.file.size ? Math.round(n * 100 / job.file.size) : 100; bar.style.width = pc + '%'; }
		function failed(e) {
			bar.classList.add('bg-danger');
			bar.style.width = '100%';
			st.textContent = t('upload_failed');
			job.li.appendChild(el('div', {'class': 'text-danger', text: e.message || t('unknown_error')}));
			throw e;
		}
		var base0 = job.sub ? job.dir : null;
		return (base0 ? ensureDir(job.share, job.dir) : Promise.resolve()).then(function () {
			return api('upload_init', {share: job.share.id, path: job.dir, name: job.file.name, size: job.file.size, overwrite: job.overwrite ? '1' : null}, true);
		}).then(function (d) {
			if (d.done) return d;
			var offset = 0, retries = 0;
			function next() {
				var part = job.file.slice(offset, Math.min(offset + chunk, job.file.size));
				return call('upload_chunk', {token: d.token, offset: offset, chunk: part}, true).then(function (r) {
					retries = 0;
					offset = r.received;
					setP(offset);
					return r.done ? r : next();
				}, function (e) {
					if (e instanceof ApiError && e.key === 'err_upload_offset' && e.data.args && retries < 5) { retries++; offset = Number(e.data.args[0]) || 0; return next(); }
					if ((!(e instanceof ApiError) || e.status >= 500 || e.status === 0) && retries < 4) {
						retries++;
						st.textContent = t('chunk_retry');
						return new Promise(function (res) { setTimeout(res, 800 * retries * retries); }).then(next);
					}
					throw e;
				});
			}
			return next();
		}).then(function (r) {
			bar.style.width = '100%';
			bar.classList.add('bg-success');
			st.textContent = r.replaced ? t('replaced') : (r.name && r.name !== job.file.name ? '→ ' + r.name : '✓');
			return r;
		}).catch(failed);
	}

	$('nufUploadsClose').addEventListener('click', function () {
		if (up.running || up.queue.length) return;
		$('nufUploads').classList.add('d-none');
		$('nufUploadList').textContent = '';
		up.total = 0; up.done = 0; up.failed = 0; up.dirs = {};
	});

	// drag and drop – files and whole folders (webkitGetAsEntry)
	var dragDepth = 0;
	var view = $('nufView');
	function dropOk(e) {
		return state.view === 'files' && can('upload') && e.dataTransfer && Array.prototype.indexOf.call(e.dataTransfer.types || [], 'Files') >= 0;
	}
	view.addEventListener('dragenter', function (e) { if (!dropOk(e)) return; e.preventDefault(); dragDepth++; view.classList.add('nuf-dragging'); });
	view.addEventListener('dragover', function (e) { if (!dropOk(e)) return; e.preventDefault(); e.dataTransfer.dropEffect = 'copy'; });
	view.addEventListener('dragleave', function () { if (--dragDepth <= 0) { dragDepth = 0; view.classList.remove('nuf-dragging'); } });
	view.addEventListener('drop', function (e) {
		if (!dropOk(e)) return;
		e.preventDefault();
		dragDepth = 0;
		view.classList.remove('nuf-dragging');
		var items = Array.prototype.slice.call(e.dataTransfer.items || []);
		var entries = items.map(function (i) { return i.webkitGetAsEntry ? i.webkitGetAsEntry() : null; }).filter(Boolean);
		if (!entries.length || !can('mkdir')) { enqueue(Array.prototype.slice.call(e.dataTransfer.files || []).map(function (f) { return {file: f, dir: ''}; })); return; }
		var out = [];
		function walk(entry, dir) {
			if (entry.isFile) return new Promise(function (res) { entry.file(function (f) { out.push({file: f, dir: dir}); res(); }, function () { res(); }); });
			if (!entry.isDirectory) return Promise.resolve();
			var reader = entry.createReader(), all = [];
			return new Promise(function (res) {
				(function readMore() {
					reader.readEntries(function (batch) {
						if (!batch.length) { Promise.all(all.map(function (c) { return walk(c, join(dir, entry.name)); })).then(res); return; }
						all = all.concat(Array.prototype.slice.call(batch));
						readMore();
					}, function () { res(); });
				}());
			});
		}
		Promise.all(entries.map(function (en) { return walk(en, ''); })).then(function () { enqueue(out); });
	});
	// a file dropped outside the list must not open in the browser
	window.addEventListener('dragover', function (e) { e.preventDefault(); });
	window.addEventListener('drop', function (e) { if (!e.target.closest('#nufView')) e.preventDefault(); });

	// ================================================================== recycle bin

	function showTrash() {
		state.view = 'trash';
		state.sel.clear();
		renderShares();
		crumbs();
		toolbar();
		var tb = $('nufRows2');
		tb.textContent = '';
		$('nufHead2').textContent = '';
		message(t('loading'));
		api('trash', {}).then(function (d) {
			if (state.view !== 'trash') return;
			setHead([t('name'), t('original_path'), t('deleted_at'), t('size'), '']);
			tb.textContent = '';
			message(d.items.length ? '' : t('empty_trash'));
			var bar = $('nufStatusLeft');
			bar.textContent = d.days > 0 ? t('trash_days', d.days) : '';
			$('nufStatusRight').textContent = '';
			if (d.can_purge && d.items.length) {
				$('nufStatusRight').appendChild(el('button', {type: 'button', 'class': 'btn btn-sm btn-outline-danger py-0', onclick: function () {
					confirmBox(t('purge_all'), t('confirm_delete_final'), t('purge_all'), true).then(function (ok) {
						if (ok) api('purge', {all: '1'}, true).then(function () { toast(t('purged'), 'success'); showTrash(); }).catch(fail);
					});
				}}, [icon('trash3'), ' ' + t('purge_all')]));
			}
			d.items.forEach(function (m) {
				var acts = [];
				var sh = shareById(m.share);
				if (sh && can('delete', sh)) acts.push(el('button', {type: 'button', 'class': 'btn btn-sm btn-outline-primary py-0', onclick: function () {
					api('restore', {id: m.id}, true).then(function (r) { toast(t('restored', r.path), 'success'); showTrash(); }).catch(fail);
				}}, [icon('arrow-counterclockwise'), ' ' + t('restore')]));
				if (d.can_purge) acts.push(el('button', {type: 'button', 'class': 'btn btn-sm btn-outline-danger py-0 ms-1', onclick: function () {
					confirmBox(t('purge'), m.name + '\n\n' + t('confirm_delete_final'), t('purge'), true).then(function (ok) {
						if (ok) api('purge', {id: m.id}, true).then(function () { toast(t('purged'), 'success'); showTrash(); }).catch(fail);
					});
				}}, [icon('x-octagon'), ' ' + t('purge')]));
				tb.appendChild(el('tr', {}, [
					el('td', {}, [el('i', {'class': 'bi bi-' + (m.type === 'dir' ? 'folder-fill nuf-ico-dir' : fileIcon({name: m.name, type: 'file'})), 'aria-hidden': 'true'}), ' ', el('span', {text: m.name})]),
					el('td', {'class': 'small text-break', text: (m.share_label || m.share) + ' / ' + m.path}),
					el('td', {'class': 'small text-nowrap', text: when(m.deleted_at) + ' · ' + (m.user || '')}),
					el('td', {'class': 'text-end text-nowrap small', text: size(m.bytes) + (m.type === 'dir' ? ' · ' + t('n_files', m.files) : '')}),
					el('td', {'class': 'text-end text-nowrap'}, acts)
				]));
			});
		}).catch(function (e) { message(e.message); });
	}

	/** Head of the second table (recycle bin, log). */
	function setHead(labels) {
		var tr = $('nufHead2');
		tr.textContent = '';
		labels.forEach(function (l, i) { tr.appendChild(el('th', {'class': i === labels.length - 1 && l === '' ? 'text-end' : null, text: l})); });
	}

	$('nufTrashBtn').addEventListener('click', function () { window.location.hash = '#/trash'; });

	// ================================================================== log (globeadmin)

	function showLog() {
		state.view = 'log';
		renderShares();
		crumbs();
		toolbar();
		var tb = $('nufRows2');
		tb.textContent = '';
		$('nufHead2').textContent = '';
		message(t('loading'));
		var q = (showLog.q || '');
		api('logview', {limit: 500, q: q}).then(function (d) {
			if (state.view !== 'log') return;
			setHead([t('log_when'), t('log_user'), t('log_action'), t('log_path'), t('log_target')]);
			tb.textContent = '';
			message(d.items.length ? '' : t('no_results'));
			var filter = el('input', {type: 'search', 'class': 'form-control form-control-sm d-inline-block w-auto', placeholder: t('filter'), value: q});
			filter.addEventListener('change', function () { showLog.q = filter.value.trim(); showLog(); });
			$('nufStatusLeft').textContent = '';
			$('nufStatusLeft').appendChild(filter);
			$('nufStatusRight').textContent = t('n_entries', d.items.length);
			d.items.forEach(function (r) {
				tb.appendChild(el('tr', {'class': String(r.ok) === '0' ? 'table-danger' : null}, [
					el('td', {'class': 'small text-nowrap', text: r.log_at}),
					el('td', {'class': 'small', text: (r.login || r.user_id || '') + (r.real_login ? ' (' + r.real_login + ')' : '')}),
					el('td', {'class': 'small', text: r.action}),
					el('td', {'class': 'small text-break', text: (r.share_id || '') + ' / ' + (r.path || '')}),
					el('td', {'class': 'small text-break', text: [r.target, r.detail, r.bytes !== null && r.bytes !== undefined && r.bytes !== '' ? size(Number(r.bytes)) : ''].filter(Boolean).join(' · ')})
				]));
			});
		}).catch(function (e) { message(e.message); });
	}
	$('nufLogBtn').addEventListener('click', function () { window.location.hash = '#/log'; });

	// ================================================================== side bar (small screens), theme, start

	$('nufSideToggle').addEventListener('click', function () { $('nufApp').classList.toggle('nuf-side-open'); });
	$('nufShares').addEventListener('click', function (e) { if (e.target.closest('a')) $('nufApp').classList.remove('nuf-side-open'); });

	// theme: nuBuilder Next's choice (localStorage "nunext-theme", the server got it as cookie); follows a change in another tab
	(function theme() {
		var root = document.documentElement;
		var mq = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;
		function pref() {
			var p = null;
			try { p = localStorage.getItem('nunext-theme'); } catch (e) { p = null; }
			return p || root.getAttribute('data-nuf-theme') || 'light';
		}
		function set() { var p = pref(); root.setAttribute('data-bs-theme', p === 'dark' || (p === 'auto' && mq && mq.matches) ? 'dark' : 'light'); }
		set();
		if (mq && mq.addEventListener) mq.addEventListener('change', set);
		window.addEventListener('storage', function (e) { if (e.key === 'nunext-theme') set(); });
	}());

	if (boot.problems && boot.problems.length) {
		var pb = $('nufProblems');
		pb.appendChild(el('strong', {text: t('problems')}));
		pb.appendChild(el('ul', {'class': 'mb-0 small'}, boot.problems.map(function (p) { return el('li', {text: p}); })));
		pb.classList.remove('d-none');
	}
	if (boot.readonly) $('nufRoBadge').classList.remove('d-none');
	$('nufLogBtn').classList.toggle('d-none', !boot.features.log);
	$('nufTrashBtn').classList.toggle('d-none', !boot.features.trash || !(boot.shares || []).some(function (s) { return s.trash && s.rights.indexOf('delete') >= 0; }));
	if (!window.location.hash && boot.shares && boot.shares.length) {
		var last = null;
		try { last = localStorage.getItem('nufLast'); } catch (e) { last = null; }
		history.replaceState(null, '', '#/' + encodeURIComponent(shareById(last) ? last : boot.shares[0].id) + '/');
	}
	window.addEventListener('hashchange', function () { if (state.share) try { localStorage.setItem('nufLast', state.share.id); } catch (e) { /* none */ } });
	route();
}());
