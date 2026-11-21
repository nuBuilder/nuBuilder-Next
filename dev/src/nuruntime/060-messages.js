	// =================================================================== messages
	// nuBuilder Forte: nuMessage(lines) · (lines, timeout[, callback]) · (title, lines[, timeout[, callback]]);
	// a first line <h1>…<h5> becomes the title; after timeout ms the message goes and callback runs
	var HEAD = /<h[1-5]>(.*?)<\/h[1-5]>/i;
	api('nuMessage', function () {
		var a = Array.prototype.slice.call(arguments).filter(function (x) { return x !== undefined; }), title = '', lines, timeout = null, callback = null;
		if (a.length === 1 && typeof a[0] === 'string' && HEAD.test(a[0])) { var mh = a[0].match(HEAD); a = [mh[1], a[0].replace(HEAD, '')]; }
		if (a.length >= 2 && Number.isInteger(a[1])) { lines = arr(a[0]); timeout = a[1]; callback = typeof a[2] === 'function' ? a[2] : null; }
		else if (a.length === 1 && Array.isArray(a[0])) lines = a[0];
		else if (a.length >= 2 && !Array.isArray(a[0])) {
			title = a[0];
			var rest = a.slice(1);
			if (rest.length && typeof rest[rest.length - 1] === 'function') callback = rest.pop();
			if (rest.length > 1 && Number.isInteger(rest[rest.length - 1])) timeout = rest.pop();
			lines = rest.reduce(function (x, y) { return x.concat(arr(y)); }, []);
		}
		else lines = a.reduce(function (x, y) { return x.concat(arr(y)); }, []);
		if (!title && lines.length
			&& HEAD.test(String(lines[0]))) { var m0 = String(lines[0]).match(HEAD); title = m0[1]; lines = lines.slice(1); if (String(lines[0] || '').trim() === '' && lines.length) lines = lines.slice(1); }
		if (designQuiet('message', (title ? title + ': ' : '') + lines.join(' · '))) return $('<div></div>');
		$('#nuMessageDiv').remove();
		// card with a tinted header (icon, title, close) and the lines below; without a title only the body and the close
		var box = $('<div id="nuMessageDiv" class="nuMessage" role="alertdialog" aria-live="assertive" tabindex="-1"></div>');
		var close = $('<button type="button" class="btn-close nuMessageClose" aria-label="Close"></button>').on('click', function () { box.remove(); });
		var put = function (div, h) { return safeMessages ? div.append(safeFrag(h)) : div.html(h); };
		var body = $('<div class="nuMessageBody"></div>');
		if (title) {
			var tid = 'nuMessageTitle' + Date.now();
			var head = $('<div class="nuMessageHead"><i class="bi bi-info-circle nuMessageIcon" aria-hidden="true"></i></div>');
			head.append(put($('<div class="nuMessageTitle"></div>').attr('id', tid), title)).append(close);
			box.attr('aria-labelledby', tid).addClass('nuMessageHasHead').append(head);
		} else {
			box.append(close);
		}
		lines.forEach(function (l) { body.append(put($('<div class="nuMessageLine"></div>'), String(l))); });
		if (lines.length) box.append(body);
		$('body').append(box);
		box.trigger('focus');
		if (timeout) setTimeout(function () { box.fadeOut('slow', function () { box.remove(); }); if (callback) callback(); }, timeout);
		return box;
	});
	api('nuMessageRemove', function () { $('#nuMessageDiv').remove(); });
	$(document).on('keydown', function (e) { if (e.key === 'Escape') $('#nuMessageDiv').remove(); });

	api('nuDisplayError', function (data) {
		var errs = (data && data.errors) || [];
		if (!errs.length) return false;
		var box = nuMessage(errs.map(function (e) { return esc(e.message || e); }));
		callHook('nuOnMessage', box, errs);                                  // nuBuilder: (messageDiv, errors)
		errs.forEach(function (e) { if (e.field) $('#' + e.field + ', #' + e.field + 'code').addClass('is-invalid'); });
		return true;
	});

