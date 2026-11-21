	// =================================================================== files in fields (nuBuilder format)
	function fileSize(b) { return !b ? '' : (b < 1024 ? b + ' B' : (b < 1048576 ? Math.round(b / 1024) + ' KB' : (b / 1048576).toFixed(1) + ' MB')); }

	function renderFileInfo(field, json) {
		var box = $(field).find('.nuFileInfo');
		box.children(':not(.nuFileRemove)').remove();   // children only: find() also took the remove button's icon
		var d = null;
		try { d = json ? JSON.parse(json) : null; } catch (e) { d = null; }
		if (!d || !d.file) { box.addClass('d-none'); return; }
		var url = '';
		try { url = String(d.file).indexOf('data:') === 0 ? d.file : atob(d.file); } catch (e) { url = ''; }
		var isImg = /^image\//.test(d.type || '') && url.indexOf('data:image/') === 0;
		var parts = [isImg ? $('<img class="nuFileThumb" alt="">').attr('src', url) : $('<i class="bi bi-file-earmark fs-4 text-body-secondary"></i>'),
			$('<span class="nuFileName text-truncate"></span>').text(d.name || 'file'), $('<span class="small text-body-secondary"></span>').text(fileSize(d.size))];
		if (url) parts.push($('<a class="btn btn-sm btn-outline-secondary nuFileDownload"><i class="bi bi-download"></i></a>').attr({href: url, download: d.name || 'file'}));
		box.prepend(parts).removeClass('d-none');
	}

	api('nuChangeFile', function (event) {
		var input = event.target, dataId = input.id.replace(/_file$/, ''), data = g(dataId);
		var file = input.files && input.files[0];
		if (!file || !data) return;
		var maxKB = +(N.fileMaxKB || 300);
		var reader = new FileReader();
		reader.onload = function () {
			var json = JSON.stringify({file: btoa(reader.result), name: file.name, size: file.size, type: file.type});
			if (window.nuOnFileLoad) {
				if (window.nuOnFileLoad(event, input.id, json) === false) return;
			} else if (file.size > maxKB * 1024) {
				nuMessage(M.error || 'Error', [(M.file_too_large || 'File too large').replace('%d', maxKB)]);
				input.value = '';
				return;
			}
			data.value = json;
			markEdited(data);
			$(data).trigger('change');
			renderFileInfo(input.closest('.nuFileField'), json);
			input.value = '';
			if (window.nuOnFileLoaded) window.nuOnFileLoaded(event, input.id, json);
		};
		reader.readAsDataURL(file);
	});
	$(document).on('change', '.nuFileInput', function (e) { window.nuChangeFile(e); });
	$(document).on('click', '.nuFileRemove', function () {
		var field = this.closest('.nuFileField'), data = g(field.getAttribute('data-nu-file-for'));
		if (!data) return;
		data.value = '';
		markEdited(data);
		$(data).trigger('change');
		renderFileInfo(field, '');
	});

