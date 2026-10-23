// Host side of page forms (form type "page", 0.8.387): listens to the messages of page iframes (class nuPageFrame) – sent by
// assets/js/nupage.js. Only messages of the same origin from one of this window's page iframes count; nothing from a message is run.
(function () {
	'use strict';
	var MIN = 200, MAX = 20000;
	var me = document.currentScript;
	var closeLabel = (me && me.getAttribute('data-close')) || '';

	function frames() { return Array.prototype.slice.call(document.querySelectorAll('iframe.nuPageFrame')); }

	function fixedHeights() {
		frames().forEach(function (f) {
			var h = f.getAttribute('data-nu-height') || 'auto';
			if (h !== 'auto' && /^\d{2,5}(px|vh|%)$/.test(h)) f.style.height = h;
		});
	}

	function toast(text, level) {
		level = ['success', 'info', 'warning', 'danger'].indexOf(level) >= 0 ? level : 'info';
		var box = document.getElementById('nuPageToasts');
		if (!box) {
			box = document.createElement('div');
			box.id = 'nuPageToasts';
			box.className = 'toast-container position-fixed top-0 end-0 p-3 nuPageToasts';
			document.body.appendChild(box);
		}
		var t = document.createElement('div');
		t.className = 'toast align-items-center border-0 text-bg-' + level;
		t.setAttribute('role', level === 'danger' || level === 'warning' ? 'alert' : 'status');
		t.setAttribute('aria-live', 'polite');
		var row = document.createElement('div');
		row.className = 'd-flex';
		var body = document.createElement('div');
		body.className = 'toast-body';
		body.textContent = String(text).slice(0, 500);
		var btn = document.createElement('button');
		btn.type = 'button';
		btn.className = 'btn-close btn-close-white me-2 m-auto';
		btn.setAttribute('data-bs-dismiss', 'toast');
		btn.setAttribute('aria-label', closeLabel);
		row.appendChild(body);
		row.appendChild(btn);
		t.appendChild(row);
		box.appendChild(t);
		if (window.nuBs && window.nuBs.ready()) {
			window.nuBs.toast(t, {delay: 4000}).show();
			t.addEventListener('hidden.bs.toast', function () { t.remove(); });
		} else {
			t.classList.add('show');
			setTimeout(function () { t.remove(); }, 4000);
		}
	}

	function formUrl(code, record) {
		return 'index.php?p=form&f=' + encodeURIComponent(code) + (record ? '&r=' + encodeURIComponent(record) : '');
	}

	window.addEventListener('message', function (e) {
		if (e.origin !== window.location.origin) return;
		var d = e.data;
		if (!d || typeof d !== 'object' || d.nuPage !== 1 || typeof d.type !== 'string') return;
		var frame = null;
		frames().forEach(function (f) { if (f.contentWindow === e.source) frame = f; });
		if (!frame) return;
		switch (d.type) {
			case 'resize':
				if ((frame.getAttribute('data-nu-height') || 'auto') === 'auto') {
					var h = Math.max(MIN, Math.min(MAX, parseInt(d.height, 10) || 0));
					frame.style.height = h + 'px';
				}
				break;
			case 'close':
				if (window.history.length > 1) window.history.back(); else window.location.href = 'index.php?p=home';
				break;
			case 'refresh':
				if (window.nuNext) window.location.reload();                 // a form holds the page: reload the form
				else frame.contentWindow.location.reload();
				break;
			case 'login':
				window.location.reload();
				break;
			case 'open':
				if (typeof d.form !== 'string' || !/^[\w-]{1,64}$/.test(d.form)) break;
				var url = formUrl(d.form, typeof d.record === 'string' ? d.record : '') + (typeof d.bt === 'string' && /^[0-9a-f]{12}$/.test(d.bt) ? '&bt=' + d.bt : '');   // Back → the page (0.8.422)
				if (d.target === 'tab') window.open(url, '_blank', 'noopener'); else window.location.href = url;
				break;
			case 'toast':
				toast(typeof d.text === 'string' ? d.text : '', String(d.level || 'info'));
				break;
			case 'title':
				if (typeof d.text !== 'string') break;
				var el = document.getElementById('nuPageTitle');
				if (el && frame.id === 'nuPageFrame') { el.textContent = d.text.slice(0, 200); document.title = d.text.slice(0, 200); }
				break;
		}
	});

	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', fixedHeights); else fixedHeights();
}());
