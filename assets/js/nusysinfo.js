/* global nuBs */
/* System info (core/nusysinfo.php): the dialog of the user menu – nuBuilder Next, server and database from ?p=sysinfo (loaded
   when the dialog opens), the browser from here; "Copy for support" copies all of it as plain text. */
(function () {
	'use strict';
	var dlg = document.getElementById('nuSysInfoDlg');
	if (!dlg) return;
	var T = {}; try { T = JSON.parse(dlg.getAttribute('data-t') || '{}'); } catch (e) { /* none */ }
	var body = document.getElementById('nuSysInfoBody'), copyBtn = document.getElementById('nuSysInfoCopy');
	var sections = null;

	function browser() {
		var ua = navigator.userAgent || '', b = '', m;
		if ((m = ua.match(/Edg\/(\d+)/))) b = 'Edge ' + m[1];
		else if ((m = ua.match(/OPR\/(\d+)/))) b = 'Opera ' + m[1];
		else if ((m = ua.match(/Firefox\/(\d+)/))) b = 'Firefox ' + m[1];
		else if ((m = ua.match(/Chrome\/(\d+)/))) b = 'Chrome ' + m[1];
		else if ((m = ua.match(/Version\/(\d+[.\d]*).*Safari/))) b = 'Safari ' + m[1];
		var os = /Windows NT 10/.test(ua) ? 'Windows 10/11' : /Windows/.test(ua) ? 'Windows' : /Android (\d+)/.test(ua) ? 'Android ' + ua.match(/Android (\d+)/)[1]
			: /(iPhone|iPad).*OS (\d+)/.test(ua) ? 'iOS ' + ua.match(/OS (\d+)/)[1] : /Mac OS X/.test(ua) ? 'macOS' : /Linux/.test(ua) ? 'Linux' : '';
		var tz = ''; try { tz = Intl.DateTimeFormat().resolvedOptions().timeZone || ''; } catch (e) { /* old browser */ }
		var pw = ''; try { pw = localStorage.getItem('nunext-page-width') || ''; } catch (e) { /* none */ }
		var pwT = /^\d+$/.test(pw) ? pw + ' px' : ((T.pw || {})[pw] || pw);
		var dpr = window.devicePixelRatio || 1;
		var theme = document.documentElement.getAttribute('data-bs-theme') || 'light';
		return {id: 'browser', title: T.browser, rows: [
			[T.b_browser, (b || '?') + (os ? ' · ' + os : '')],
			[T.b_screen, (T.b_screen_v || '%s').replace('%s', screen.width + ' × ' + screen.height).replace('%s', window.innerWidth + ' × ' + window.innerHeight).replace('%s', Math.round(dpr * 100) / 100)],
			[T.b_lang, (navigator.language || '') + (tz ? ' · ' + tz : '')],
			[T.b_theme, theme + ' · ' + pwT],
			[T.user, dlg.getAttribute('data-user') || '']
		]};
	}
	function el(tag, attrs, kids) { return NuUtil.el(tag, attrs, kids); }
	function draw(list) {
		body.textContent = '';
		list.forEach(function (s) {
			var sec = el('section', 'nuSiSec'); sec.setAttribute('data-si', s.id);
			sec.appendChild(el('h2', 'nuSiHead', s.title));
			var dl = el('dl', 'nuSiList');
			s.rows.forEach(function (r) {
				dl.appendChild(el('dt', '', r[0]));
				var dd = el('dd', r[2] ? 'nuSi-' + r[2] : '', r[1]);
				dl.appendChild(dd);
			});
			sec.appendChild(dl);
			body.appendChild(sec);
		});
	}
	function text() {
		return (sections || []).map(function (s) {
			return s.title + '\n' + s.rows.map(function (r) { return '  ' + r[0] + ': ' + r[1]; }).join('\n');
		}).join('\n\n');
	}
	dlg.addEventListener('show.bs.modal', function () {
		var b = browser();
		fetch(dlg.getAttribute('data-url'), {credentials: 'same-origin', headers: {'Accept': 'application/json'}}).then(function (r) { return r.json(); }).then(function (d) {
			sections = (d && d.ok ? d.sections : []).concat([b]);
			draw(sections);
		}).catch(function () {
			sections = [b];
			draw(sections);
			body.insertBefore(el('div', 'alert alert-warning py-2', T.error || 'Error'), body.firstChild);
		});
	});
	if (copyBtn) copyBtn.addEventListener('click', function () {
		var t = text(), done = function () {
			var o = copyBtn.innerHTML; copyBtn.textContent = T.copied || 'Copied';
			setTimeout(function () { copyBtn.innerHTML = o; }, 1500);
		};
		if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(t).then(done, function () { /* blocked */ });
		else {
			var ta = document.createElement('textarea'); ta.value = t; document.body.appendChild(ta); ta.select();
			try { document.execCommand('copy'); done(); } catch (e) { /* ignore */ }
			ta.remove();
		}
		dlg.setAttribute('data-copied', t);   // for the tests
	});
})();
