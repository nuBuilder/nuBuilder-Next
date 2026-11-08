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
	function startEditor() {
		if (!tinyUrl || isMd() || editor) return;
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
					ed.on('input change undo redo SetContent', function () { ed.save(); changed(); });
				}
			});
		}).catch(function () { var n = document.getElementById('uhNoTiny'); if (n) n.classList.remove('d-none'); });
	}
	function stopEditor() { if (editor) { editor.save(); editor.remove(); editor = null; } }
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
	if (ai && copyBtn) {
		copyBtn.addEventListener('click', function () {
			var fd = new FormData();
			fd.append('_csrf', (form.querySelector('input[name=_csrf]') || {}).value || '');
			fd.append('action', 'ai_prompt');
			fd.append('mode', ai.getAttribute('data-mode'));
			fd.append('code', ai.getAttribute('data-code'));
			fd.append('lang', ai.getAttribute('data-lang'));
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
			var go = function () {
				if (/<[a-z][\s\S]*>/i.test(a) && fmtHtml && !fmtHtml.checked) { fmtHtml.checked = true; startEditor(); }
				setText(a);
				nuBs.modal(document.getElementById('uhAiDlg')).hide();
			};
			if (getText().trim() && window.nuConfirmAsk) window.nuConfirmAsk(tx('replace'), go); else go();
		});
	}
})();
