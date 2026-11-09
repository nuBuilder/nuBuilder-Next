/* global nuBs */
/* User help pages – editor (0.8.1023, pages/nuhelppages.php): TinyMCE for HTML pages (library pack), a plain text field for Markdown,
   the Insert menu (access level parts, links to other help pages, hash variables) and the AI dialog (copy a prompt, paste the answer). */
(function () {
	'use strict';
	var form = document.getElementById('uhForm');
	if (!form) return;
	var body = document.getElementById('uhBody'), texts = document.getElementById('uhTexts');
	var fmtHtml = document.getElementById('uhFmtHtml'), fmtMd = document.getElementById('uhFmtMd');
	var tinyUrl = form.getAttribute('data-tiny'), readonly = form.getAttribute('data-readonly') === '1';
	var links = [];
	try { links = JSON.parse(form.getAttribute('data-links') || '[]'); } catch (e) { links = []; }
	var editor = null, loading = null;
	var tx = function (k) { return texts ? texts.getAttribute('data-' + k) || '' : ''; };
	var isMd = function () { return fmtMd && fmtMd.checked; };
	function changed() { body.dispatchEvent(new Event('input', {bubbles: true})); body.dispatchEvent(new Event('change', {bubbles: true})); }

	function loadTiny() {
		if (window.tinymce) return Promise.resolve();
		if (loading) return loading;
		loading = new Promise(function (ok, fail) {
			var sc = document.createElement('script');
			sc.src = tinyUrl; sc.onload = ok; sc.onerror = fail;
			document.head.appendChild(sc);
		});
		return loading;
	}
	// pictures are stored as files on the server (0.8.1024): POST action upload_image → {location}
	function upload(blob, name) {
		var fd = new FormData();
		fd.append('_csrf', (form.querySelector('input[name=_csrf]') || {}).value || '');
		fd.append('action', 'upload_image');
		fd.append('file', blob, name || 'image');
		return fetch(form.getAttribute('data-ai-url'), {method: 'POST', body: fd, credentials: 'same-origin'}).then(function (r) {
			return r.json().then(function (j) { if (!r.ok || !j.location) throw new Error(j.error || 'upload'); return j.location; });
		});
	}
	// the HTML source stays hidden (class nuTmceWait, set by the page) until TinyMCE is there or cannot come (0.8.1040)
	function shown() { body.classList.remove('nuTmceWait'); }
	setTimeout(shown, 10000);
	function startEditor() {
		if (!tinyUrl || isMd() || editor) { shown(); return; }
		loadTiny().then(function () {
			if (isMd() || editor) return;
			var dark = document.documentElement.getAttribute('data-bs-theme') === 'dark';
			window.tinymce.init({
				target: body, base_url: form.getAttribute('data-base'), suffix: '.min', license_key: 'gpl', promotion: false, branding: false,
				plugins: 'lists link autolink table image code', menubar: false, statusbar: true, resize: true, height: 520, toolbar_mode: 'wrap', readonly: readonly,
				toolbar: 'blocks | bold italic underline | bullist numlist | alignleft aligncenter alignright | link image table | blockquote hr | removeformat code | undo redo',
				block_formats: 'Paragraph=p; Heading 2=h2; Heading 3=h3; Heading 4=h4; Preformatted=pre',
				link_list: links, link_default_target: '', link_title: false, convert_urls: false, relative_urls: false, image_caption: true, image_advtab: false,
				paste_data_images: true, automatic_uploads: true,
				images_upload_handler: function (info) { return upload(info.blob(), info.filename()); }, images_reuse_filename: false,
				file_picker_types: 'image',
				file_picker_callback: function (cb) {
					var inp = document.createElement('input');
					inp.type = 'file'; inp.accept = 'image/*';
					inp.addEventListener('change', function () { if (inp.files && inp.files[0]) upload(inp.files[0], inp.files[0].name).then(function (u) { cb(u, {alt: ''}); }, function (e) { if (editor) editor.notificationManager.open({text: e.message, type: 'error', timeout: 6000}); }); });
					inp.click();
				},
				skin: dark ? 'oxide-dark' : 'oxide', content_css: dark ? 'dark' : 'default',
				setup: function (ed) {
					editor = ed;
					ed.on('init', function () { quiet = false; shown(); });
					ed.on('input change undo redo SetContent', function () { ed.save(); if (!quiet) changed(); });
				}
			});
		}).catch(function () { shown(); var n = document.getElementById('uhNoTiny'); if (n) n.classList.remove('d-none'); });
	}
	function stopEditor() { if (editor) { editor.save(); editor.remove(); editor = null; } shown(); }   // after remove(): TinyMCE puts the class back
	// light / dark switch (0.8.1041): TinyMCE cannot change its skin while it runs – started again with the other skin, the text kept,
	// the page not marked as changed (quiet until the new editor is ready)
	var quiet = false;
	new MutationObserver(function () {
		if (!editor) return;
		quiet = true;
		editor.save(); editor.remove(); editor = null;
		body.classList.add('nuTmceWait');
		startEditor();
	}).observe(document.documentElement, {attributes: true, attributeFilter: ['data-bs-theme']});
	function getText() { if (editor) editor.save(); return body.value; }
	function setText(v) { if (editor) { editor.setContent(v); editor.save(); } else body.value = v; changed(); }
	function insert(html, md) {
		if (editor) { editor.insertContent(html); editor.save(); changed(); return; }
		var v = isMd() ? md : html, s = body.selectionStart || body.value.length, e = body.selectionEnd || s;
		body.value = body.value.slice(0, s) + v + body.value.slice(e);
		body.focus(); body.selectionStart = body.selectionEnd = s + v.length;
		changed();
	}
	if (fmtHtml && fmtMd) {
		[fmtHtml, fmtMd].forEach(function (r) { r.addEventListener('change', function () { if (isMd()) stopEditor(); else startEditor(); changed(); }); });
	}
	startEditor();
	// pictures still waiting in the editor (blob:) are uploaded before the page is sent
	var uploaded = false;
	form.addEventListener('submit', function (e) {
		if (!editor) return;
		editor.save();
		if (uploaded || body.value.indexOf('blob:') < 0) return;
		e.preventDefault();
		editor.uploadImages().then(function () {
			editor.save(); uploaded = true;
			var b = e.submitter || document.getElementById('uhSave');
			if (form.requestSubmit) form.requestSubmit(b && b.form === form ? b : undefined); else form.submit();
		});
	});

	// ---------------------------------------------------------------- Insert menu
	var esc = function (s) { return String(s).replace(/[&<>"]/g, function (c) { return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;'}[c]; }); };
	var levelPh = function () { return (tx('levels').split(',')[0] || '').trim() || tx('level-ph'); };
	function linkPicker() {
		var dlg = document.getElementById('uhLinkDlg');
		if (!dlg) {
			dlg = document.createElement('div');
			dlg.className = 'modal fade'; dlg.id = 'uhLinkDlg'; dlg.tabIndex = -1;
			dlg.innerHTML = '<div class="modal-dialog modal-dialog-scrollable"><div class="modal-content"><div class="modal-header"><h2 class="modal-title h6"></h2>'
				+ '<button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><input class="form-control form-control-sm mb-2" type="search" id="uhLinkFilter">'
				+ '<div class="list-group" id="uhLinkList"></div></div></div></div>';
			dlg.querySelector('.modal-title').textContent = tx('pick');
			document.body.appendChild(dlg);
			var list = dlg.querySelector('#uhLinkList'), filter = dlg.querySelector('#uhLinkFilter');
			var draw = function () {
				var q = filter.value.trim().toLowerCase();
				list.textContent = '';
				links.filter(function (l) { return !q || l.title.toLowerCase().indexOf(q) >= 0; }).forEach(function (l) {
					var b = document.createElement('button');
					b.type = 'button'; b.className = 'list-group-item list-group-item-action'; b.textContent = l.title;
					b.addEventListener('click', function () {
						var t = l.title.replace(/\s*\([^)]*\)$/, '');
						insert('<a href="' + esc(l.value) + '">' + esc(t) + '</a>', '[' + t + '](' + l.value + ')');
						nuBs.modal(dlg).hide();
					});
					list.appendChild(b);
				});
			};
			filter.addEventListener('input', draw);
			dlg.addEventListener('shown.bs.modal', function () { filter.focus(); });
			draw();
		}
		nuBs.modal(dlg).show();
	}
	document.querySelectorAll('[data-uh-insert]').forEach(function (b) {
		b.addEventListener('click', function () {
			var k = b.getAttribute('data-uh-insert'), lv = levelPh(), only = tx('only'), other = tx('other');
			if (k === 'level') insert('<p>[[level: ' + esc(lv) + ']]</p><p>' + esc(only) + '</p><p>[[/level]]</p>', '\n[[level: ' + lv + ']]\n' + only + '\n[[/level]]\n');
			else if (k === 'levelelse') insert('<p>[[level: ' + esc(lv) + ']]</p><p>' + esc(only) + '</p><p>[[else]]</p><p>' + esc(other) + '</p><p>[[/level]]</p>',
				'\n[[level: ' + lv + ']]\n' + only + '\n[[else]]\n' + other + '\n[[/level]]\n');
			else if (k === 'not') insert('<p>[[not: ' + esc(lv) + ']]</p><p>' + esc(other) + '</p><p>[[/not]]</p>', '\n[[not: ' + lv + ']]\n' + other + '\n[[/not]]\n');
			else if (k === 'hash') insert(esc(tx('hash')), tx('hash'));
			else if (k === 'link') linkPicker();
		});
	});

	// ---------------------------------------------------------------- AI: copy a prompt, paste the answer
	var ai = document.getElementById('uhAiBody');
	var copyBtn = document.getElementById('uhAiCopy'), promptBox = document.getElementById('uhAiPrompt'), answer = document.getElementById('uhAiAnswer');
	// related forms of the chosen form (0.8.1025): sub-forms ticked, lookups and buttons offered
	var relBox = document.getElementById('uhAiRelBox'), relList = document.getElementById('uhAiRel'), aiForm = document.getElementById('uhAiForm');
	var relSeq = 0;
	function loadRelated() {
		if (!relBox || !aiForm) return;
		var seq = ++relSeq;                                        // a later choice wins over an answer still on its way
		relList.textContent = '';
		relBox.classList.add('d-none');
		if (!aiForm.value) return;
		var fd = new FormData();
		fd.append('_csrf', (form.querySelector('input[name=_csrf]') || {}).value || '');
		fd.append('action', 'related');
		fd.append('form', aiForm.value);
		fetch(form.getAttribute('data-ai-url'), {method: 'POST', body: fd, credentials: 'same-origin'}).then(function (r) { return r.json(); }).then(function (j) {
			if (seq !== relSeq || !j.ok || !j.related.length) return;
			j.related.forEach(function (r, i) {
				var d = document.createElement('div'), c = document.createElement('input'), l = document.createElement('label'), k = document.createElement('span');
				d.className = 'form-check';
				c.className = 'form-check-input uhAiRelChk'; c.type = 'checkbox'; c.value = r.id; c.id = 'uhAiRel' + i; c.checked = !!r.on;
				c.setAttribute('data-kind', r.kind);
				l.className = 'form-check-label'; l.htmlFor = c.id; l.textContent = r.name + ' ';
				k.className = 'badge text-bg-light border fw-normal'; k.textContent = r.kind_text + (r.via.length ? ': ' + r.via.join(', ') : '');
				l.appendChild(k); d.append(c, l); relList.appendChild(d);
			});
			relBox.classList.remove('d-none');
		});
	}
	if (aiForm) { aiForm.addEventListener('change', loadRelated); if (aiForm.value) loadRelated(); }
	// language of a draft (0.8.1037): the last choice is kept for the next draft, a change hides the old prompt
	var aiLang = document.getElementById('uhAiLang');
	if (aiLang) {
		try { var keep = localStorage.getItem('nunext-uh-ai-lang'); if (keep && aiLang.querySelector('option[value="' + keep + '"]')) aiLang.value = keep; } catch (e) { /* no storage */ }
		aiLang.addEventListener('change', function () {
			try { localStorage.setItem('nunext-uh-ai-lang', aiLang.value); } catch (e) { /* no storage */ }
			var pb = document.getElementById('uhAiPrompt'), cp = document.getElementById('uhAiCopied');
			if (pb) { pb.value = ''; pb.classList.add('d-none'); }
			if (cp) cp.classList.add('d-none');
		});
	}
	if (ai && copyBtn) {
		copyBtn.addEventListener('click', function () {
			var fd = new FormData();
			fd.append('_csrf', (form.querySelector('input[name=_csrf]') || {}).value || '');
			fd.append('action', 'ai_prompt');
			fd.append('mode', ai.getAttribute('data-mode'));
			fd.append('code', ai.getAttribute('data-code'));
			var lg = document.getElementById('uhAiLang');               // draft: the chosen language (0.8.1037), translation: its own
			fd.append('lang', lg ? lg.value : ai.getAttribute('data-lang'));
			var f = document.getElementById('uhAiForm'), n = document.getElementById('uhAiNote');
			if (f) fd.append('form', f.value);
			document.querySelectorAll('#uhAiRel input:checked').forEach(function (c) { fd.append('related[]', c.value); });
			if (n) fd.append('note', n.value);
			fetch(form.getAttribute('data-ai-url'), {method: 'POST', body: fd, credentials: 'same-origin'}).then(function (r) { return r.json(); }).then(function (j) {
				if (!j.ok) return;
				promptBox.value = j.prompt;
				promptBox.classList.remove('d-none');
				var done = function () { document.getElementById('uhAiCopied').classList.remove('d-none'); };
				if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(j.prompt).then(done, function () { promptBox.select(); });
				else { promptBox.select(); }
			});
		});
		document.getElementById('uhAiInsert').addEventListener('click', function () {
			var a = (answer.value || '').trim();
			if (!a) return;
			a = a.replace(/^```(?:html|markdown|md)?\s*/i, '').replace(/```\s*$/, '').trim();
			if (ai.getAttribute('data-mode') === 'translate') {
				var m = a.match(/^([^\n<]{1,200})\n\s*\n([\s\S]*)$/);
				if (m) { document.getElementById('uhTitle').value = m[1].replace(/^title:\s*/i, '').trim(); a = m[2].trim(); }
			}
			var isHtml = /<(p|h[1-6]|ul|ol|li|table|div|strong|br)\b[^>]*>/i.test(a);
			var put = function (v) { setText(v); nuBs.modal(document.getElementById('uhAiDlg')).hide(); };
			var go = function () {
				if (isHtml) {
					if (fmtHtml && !fmtHtml.checked) { fmtHtml.checked = true; startEditor(); }
					put(a);
					return;
				}
				// Markdown or plain text (0.8.1038): kept as it is on a Markdown page, converted to HTML for the visual editor
				if (fmtMd && fmtMd.checked) { put(a); return; }
				var fd = new FormData();
				fd.append('_csrf', (form.querySelector('input[name=_csrf]') || {}).value || '');
				fd.append('action', 'md_html');
				fd.append('text', a);
				fetch(form.getAttribute('data-ai-url'), {method: 'POST', body: fd, credentials: 'same-origin'}).then(function (r) { return r.json(); })
					.then(function (j) { put(j.ok ? j.html : a); }, function () { put(a); });
			};
			if (getText().trim() && window.nuConfirmAsk) window.nuConfirmAsk(tx('replace'), go); else go();
		});
	}

	// "Used on" (0.8.1054): link the page to a form, a tab or a field from here, × removes a link – POST actions link_targets / link
	var usage = document.getElementById('uhUsage'), linkDlg = document.getElementById('uhLinkDlg');
	function linkPost(data) {
		var fd = new FormData();
		fd.append('_csrf', (form.querySelector('input[name=_csrf]') || {}).value || '');
		Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
		return fetch(form.getAttribute('data-ai-url'), {method: 'POST', body: fd, credentials: 'same-origin'}).then(function (r) { return r.json(); });
	}
	function drawUsage(list) {
		if (!usage) return;
		var ul = document.createElement(list.length ? 'ul' : 'div');
		ul.id = 'uhUsage';
		ul.className = list.length ? 'list-unstyled small mb-0' : 'small text-body-secondary';
		if (!list.length) ul.textContent = tx('none');
		list.forEach(function (u) {
			var li = document.createElement('li'), sp = document.createElement('span'), a = document.createElement('a'), pl = document.createElement('span'), b = document.createElement('button');
			li.className = 'd-flex align-items-start gap-1';
			sp.className = 'flex-grow-1';
			a.href = u.url; a.target = '_blank'; a.rel = 'noopener'; a.textContent = u.name;
			pl.className = 'text-body-secondary'; pl.textContent = ' – ' + u.place;
			sp.append(a, pl);
			b.type = 'button'; b.className = 'btn btn-link btn-sm p-0 text-body-secondary uhUnlink';
			b.setAttribute('data-form', u.form); b.setAttribute('data-place', u.v); b.setAttribute('data-what', u.name + ' – ' + u.place);
			b.title = tx('unlink'); b.setAttribute('aria-label', tx('unlink'));
			b.innerHTML = '<i class="bi bi-x-lg"></i>';
			li.append(sp, b);
			ul.appendChild(li);
		});
		usage.replaceWith(ul);
		usage = ul;
	}
	document.addEventListener('click', function (e) {
		var b = e.target.closest('.uhUnlink');
		if (!b) return;
		var code = (document.getElementById('uhLinkBody') || {getAttribute: function () { return ''; }}).getAttribute('data-code');
		var go = function () {
			linkPost({action: 'link', unlink: '1', code: code, form: b.getAttribute('data-form'), place: b.getAttribute('data-place')}).then(function (j) {
				if (j.usage) drawUsage(j.usage); else if (j.error) window.alert(j.error);
			});
		};
		if (window.nuConfirmAsk) window.nuConfirmAsk(tx('unlink-ask').replace('%s', b.getAttribute('data-what')), go, tx('unlink-ok'), 'btn btn-danger', {cancelFirst: true}); else if (window.confirm(tx('unlink-ask').replace('%s', b.getAttribute('data-what')))) go();
	});
	if (linkDlg) {
		var lf = document.getElementById('uhLinkForm'), lp = document.getElementById('uhLinkPlace'), lgo = document.getElementById('uhLinkGo'),
			lcur = document.getElementById('uhLinkCur'), lerr = document.getElementById('uhLinkErr'), lcode = document.getElementById('uhLinkBody').getAttribute('data-code'), lseq = 0;
		var showErr = function (t) { lerr.textContent = t || ''; lerr.classList.toggle('d-none', !t); };
		// another page at the chosen place: the note says it will be replaced and the button turns into "Replace" – that is the question
		var goLabel = lgo.innerHTML, replaceMode = false;
		var setReplace = function (cur) {
			replaceMode = !!cur;
			lcur.textContent = cur ? tx('link-now').replace('%s', cur) : '';
			lcur.classList.toggle('d-none', !cur);
			lgo.className = 'btn ' + (cur ? 'btn-warning' : 'btn-primary');
			lgo.innerHTML = cur ? '<i class="bi bi-arrow-repeat me-1"></i>' + tx('link-replace-ok') : goLabel;
		};
		var showCur = function () {
			var o = lp.options[lp.selectedIndex], cur = o ? o.getAttribute('data-cur') || '' : '';
			setReplace(cur && cur !== lcode ? cur : '');
			lgo.disabled = !o || cur === lcode;
		};
		lf.addEventListener('change', function () {
			var my = ++lseq;
			showErr('');
			lp.innerHTML = ''; lp.disabled = true; lgo.disabled = true; lcur.classList.add('d-none');
			if (!lf.value) return;
			linkPost({action: 'link_targets', form: lf.value}).then(function (j) {
				if (my !== lseq) return;
				if (!j.places) { showErr(j.error || ''); return; }
				var groups = {};
				j.places.forEach(function (p) {
					var o = document.createElement('option');
					o.value = p.v; o.setAttribute('data-cur', p.cur || '');
					o.textContent = p.label + (p.cur === lcode ? ' ✓' : (p.cur ? ' · ' + p.cur : ''));
					if (!p.group) { lp.appendChild(o); return; }
					if (!groups[p.group]) { groups[p.group] = document.createElement('optgroup'); groups[p.group].label = p.group; lp.appendChild(groups[p.group]); }
					groups[p.group].appendChild(o);
				});
				lp.disabled = false;
				showCur();
			});
		});
		lp.addEventListener('change', showCur);
		var send = function () {
			lgo.disabled = true;
			linkPost({action: 'link', code: lcode, form: lf.value, place: lp.value, replace: replaceMode ? '1' : ''}).then(function (j) {
				lgo.disabled = false;
				if (j.ask) { setReplace(j.ask); return; }      // another page was linked there meanwhile: ask again
				
				if (j.error) { showErr(j.error); return; }
				drawUsage(j.usage || []);
				nuBs.modal(linkDlg).hide();
			}).catch(function () { lgo.disabled = false; });
		};
		lgo.addEventListener('click', send);
		linkDlg.addEventListener('show.bs.modal', function () { showErr(''); if (lf.value) lf.dispatchEvent(new Event('change')); });
	}
})();

