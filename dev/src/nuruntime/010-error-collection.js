	// =================================================================== error collection (shown to globeadmin)
	function reportError(msg, where) {
		N.jsErrors.push((where ? where + ': ' : '') + msg);
		var b = g('nuJsErrors');
		if (b) { b.classList.remove('d-none'); b.querySelector('span').textContent = (M.js_errors || 'JS errors') + ' (' + N.jsErrors.length + ')'; }
	}
	window.addEventListener('error', function (e) { reportError(e.message + (e.lineno ? ' (line ' + e.lineno + ')' : ''), ''); });
	window.addEventListener('unhandledrejection', function (e) { reportError(String(e.reason && e.reason.message || e.reason), 'promise'); });

