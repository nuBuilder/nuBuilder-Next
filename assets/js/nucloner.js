/* Form cloner (pages/nucloner.php): tabs, subforms and iframe forms of the chosen form; options shown when they apply; SQL copy / download;
   confirmation before Delete (saved settings). */
(function () {
	'use strict';
	var form = document.getElementById('clForm');
	if (!form) return;
	var $ = function (s) { return document.querySelector(s); };
	var src = $('#clSource'), info = $('#clSourceInfo'), code = $('#clCode'), desc = $('#clDesc');
	var lastSuggest = '', lastDesc = '';
	var json = function (attr) { try { return JSON.parse(form.getAttribute(attr) || '[]'); } catch (e) { return []; } };
	var keep = {tabs: json('data-sel-tabs'), sub: json('data-sel-sub'), ifr: json('data-sel-ifr')};
	var posted = form.getAttribute('data-posted') === '1';

	function list(box, name, items, label, selected, allWhenEmpty) {
		box.textContent = '';
		if (!items.length) { var n = document.createElement('div'); n.className = 'text-body-secondary small fst-italic'; n.textContent = form.getAttribute('data-none'); box.appendChild(n); return; }
		items.forEach(function (it) {
			var l = document.createElement('label'); l.className = 'form-check small mb-1';
			var c = document.createElement('input'); c.type = 'checkbox'; c.className = 'form-check-input'; c.name = name + '[]'; c.value = it.id;
			c.checked = selected.length ? selected.indexOf(it.id) >= 0 : allWhenEmpty;
			var s = document.createElement('span'); s.className = 'form-check-label'; s.textContent = label(it);
			l.appendChild(c); l.appendChild(s); box.appendChild(l);
		});
	}

	function load(first) {
		var id = src.value;
		['#clTabs', '#clSub', '#clIfr'].forEach(function (s) { $(s).textContent = ''; });
		info.textContent = '';
		if (!id) { sync(); return; }
		fetch(form.getAttribute('data-info-url').replace('__ID__', encodeURIComponent(id)), {credentials: 'same-origin'})
			.then(function (r) { return r.json(); })
			.then(function (d) {
				if (d.error) { info.textContent = d.error; return; }
				info.textContent = [d.code, d.table, d.type, (form.getAttribute('data-objects') || '%s objects').replace('%s', d.objects)].filter(Boolean).join(' · ');
				var use = first && posted;
				list($('#clTabs'), 'tabs', d.tabs, function (t) { return t.title + ' (' + t.objects + ')'; }, use ? keep.tabs : [], true);
				list($('#clSub'), 'subform_ids', d.subforms, function (s) { return s.field + ' → ' + s.label; }, use ? keep.sub : [], true);
				list($('#clIfr'), 'iframe_ids', d.iframes, function (s) { return s.field + ' → ' + s.label; }, use ? keep.ifr : [], true);
				// suggestions for the new form, unless the user typed something
				if (!code.value || code.value === lastSuggest) { code.value = d.suggest; lastSuggest = d.suggest; }
				var nd = d.description + ' (' + form.getAttribute('data-copy') + ')';
				if (!desc.value || desc.value === lastDesc) { desc.value = nd; lastDesc = nd; }
				// the destination cannot be the source
				Array.prototype.forEach.call($('#clDest').options, function (o) { o.disabled = o.value !== '' && o.value === id; });
				if ($('#clDest').tomselect) $('#clDest').tomselect.sync();   // searchable select (0.8.536): take over the disabled option
				sync();
			}, function () { info.textContent = '?'; });
	}

	function sync() {
		var existing = form.querySelector('input[name="dest_mode"]:checked').value === 'existing';
		$('#clDestNew').hidden = existing;
		$('#clDestExisting').hidden = !existing;
		var objects = $('#clObjects').checked;
		['#clSubOn', '#clIfrOn'].forEach(function (s) { $(s).disabled = !objects; });
		$('#clSub').classList.toggle('opacity-50', !objects || !$('#clSubOn').checked);
		$('#clIfr').classList.toggle('opacity-50', !objects || !$('#clIfrOn').checked);
		var dump = $('#clDump').checked;
		$('#clDumpOpts').hidden = !dump;
		var run = $('#clRun');
		run.querySelector('span').textContent = run.getAttribute(dump ? 'data-label-dump' : 'data-label-clone');
		run.disabled = !src.value || (existing && !$('#clDest').value);
	}

	form.addEventListener('submit', function (e) {
		var b = e.submitter;
		if (b && b.hasAttribute('data-confirm-submit') && !window.confirm(b.getAttribute('data-confirm-submit'))) e.preventDefault();
	});
	src.addEventListener('change', function () { load(false); });
	form.addEventListener('change', function (e) { if (e.target !== src) sync(); });
	load(true);

	var sql = document.getElementById('clSql');
	if (sql) {
		$('#clCopySql').addEventListener('click', function () {
			var b = this, done = function () { b.innerHTML = '<i class="bi bi-check2 me-1"></i>' + b.getAttribute('data-copied'); };
			if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(sql.value).then(done);
			else { sql.select(); try { document.execCommand('copy'); done(); } catch (e) { /* ignore */ } }
		});
		$('#clDownloadSql').addEventListener('click', function () {
			var a = document.createElement('a');
			a.href = URL.createObjectURL(new Blob([sql.value], {type: 'application/sql'}));
			a.download = this.getAttribute('data-name') || 'clone.sql';
			document.body.appendChild(a); a.click(); a.remove();
			setTimeout(function () { URL.revokeObjectURL(a.href); }, 1000);
		});
		document.getElementById('clDumpCard').scrollIntoView({block: 'start'});
	}
})();
