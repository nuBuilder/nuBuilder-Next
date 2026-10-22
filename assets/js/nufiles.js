/* File editor (zzzzsys_file): preview of the chosen file, drag & drop, paste an image (Ctrl+V), size check, delete confirmation. */
(function () {
	'use strict';
	var form = document.getElementById('fileForm');
	if (!form) return;
	var input = document.getElementById('file');
	var drop = document.getElementById('fileDrop');
	var prev = document.getElementById('filePreview');
	var meta = document.getElementById('fileMeta');
	var code = document.getElementById('sfi_code');
	var max = +form.getAttribute('data-max') || 0;

	function size(b) { return b < 1024 ? b + ' B' : (b < 1048576 ? Math.round(b / 1024) + ' KB' : (b / 1048576).toFixed(1) + ' MB'); }

	function show(file) {
		if (!file || !prev) return;
		var tooBig = max && file.size > max;
		input.classList.toggle('is-invalid', !!tooBig);
		if (drop) drop.classList.toggle('is-invalid', !!tooBig);   // shows the .invalid-feedback after the drop zone
		var fb = drop && drop.parentNode.querySelector('.invalid-feedback');
		if (fb) fb.textContent = tooBig ? form.getAttribute('data-too-big') : '';
		prev.innerHTML = '';
		if (/^image\//.test(file.type)) {
			var r = new FileReader();
			r.onload = function () { var img = document.createElement('img'); img.src = r.result; img.alt = file.name; prev.appendChild(img); };
			r.readAsDataURL(file);
		} else {
			var i = document.createElement('i'); i.className = 'bi bi-file-earmark display-3 text-body-secondary'; prev.appendChild(i);
		}
		if (meta) {
			meta.innerHTML = '';
			var n = document.createElement('div'); n.className = 'fw-semibold text-break'; n.textContent = file.name;
			var t = document.createElement('div'); t.className = 'text-body-secondary'; t.textContent = (file.type || '?') + ' · ' + size(file.size);
			meta.appendChild(n); meta.appendChild(t);
		}
		// a new file without a code: propose the file name without extension
		if (code && !code.value.trim()) code.value = file.name.replace(/\.[^.]+$/, '');
	}

	function setFile(file) {
		if (!input || !file) return;
		try { var dt = new DataTransfer(); dt.items.add(file); input.files = dt.files; } catch (e) { return; }
		show(file);
	}

	if (input) input.addEventListener('change', function () { show(input.files && input.files[0]); });

	if (drop && input && !input.disabled) {
		['dragenter', 'dragover'].forEach(function (ev) { drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.add('nuFileDropOver'); }); });
		['dragleave', 'drop'].forEach(function (ev) { drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.remove('nuFileDropOver'); }); });
		drop.addEventListener('drop', function (e) { var f = e.dataTransfer && e.dataTransfer.files[0]; if (f) setFile(f); });
		document.addEventListener('paste', function (e) {
			var items = (e.clipboardData && e.clipboardData.items) || [];
			for (var i = 0; i < items.length; i++) {
				if (items[i].kind === 'file') {
					var f = items[i].getAsFile();
					if (f) {
						e.preventDefault();
						var ext = (f.type.split('/')[1] || 'png').replace('jpeg', 'jpg');
						setFile(f.name && f.name !== 'image.png' ? f : new File([f], 'pasted-' + new Date().toISOString().slice(0, 19).replace(/[-:T]/g, '') + '.' + ext, {type: f.type}));
					}
					return;
				}
			}
		});
	}

	form.addEventListener('submit', function (e) {
		var f = input && input.files && input.files[0];
		var btn = e.submitter;
		if (f && max && f.size > max && (!btn || btn.value !== 'delete' && btn.value !== 'clone')) { e.preventDefault(); input.focus(); }
	});
	document.querySelectorAll('[data-confirm-submit]').forEach(function (b) {
		b.addEventListener('click', function (e) { if (!window.confirm(b.getAttribute('data-confirm-submit'))) e.preventDefault(); });
	});
})();
