	// =================================================================== scripts of HTML objects
	// The server gives them type="text/nu-deferred" (NuRender::deferScripts): they run here, in page order, once jQuery and the
	// runtime exist – as in nuBuilder, which inserts HTML objects after loading its libraries. A script with src is loaded first;
	// the scripts after it wait for it.
	/** nuBuilder Forte runs form code after the page has loaded, so a handler that waits for the page (DOMContentLoaded, readystatechange,
	 *  window load, window.onload =) never runs there. Here form code runs while the page loads: between nuNextLoadGuard(true) and (false)
	 *  (pages/nuform.php: around the scripts of HTML objects and the form's JavaScript) such handlers are dropped as in Forte, with a
	 *  console note (the owner's decision; Compatibility check G11). Setup → Header is not guarded – it is Forte's page code. */
	var LG = {on: false, dropped: 0, onload: null, onrs: null};
	function lgNote(what) {
		LG.dropped++;
		try { console.info('nuBuilder Next: the form\'s code waits for "' + what + '" – as in nuBuilder Forte the page has already loaded, so the handler does not run. Put the code into nuOnLoad().'); } catch (x) { /* no console */ }
	}
	function lgAdd(target, original) {
		return function (type, fn, opt) {
			var t = String(type);
			if (LG.on && (t === 'DOMContentLoaded' || t === 'readystatechange' || (t === 'load' && target === window))) { lgNote(t); return undefined; }
			return original.call(this, type, fn, opt);
		};
	}
	api('nuNextLoadGuard', function (on) {
		if (on && !LG.on) {
			LG.on = true; LG.onload = window.onload; LG.onrs = document.onreadystatechange;
			window.addEventListener = lgAdd(window, EventTarget.prototype.addEventListener);
			document.addEventListener = lgAdd(document, EventTarget.prototype.addEventListener);
		} else if (!on && LG.on) {
			LG.on = false;
			delete window.addEventListener; delete document.addEventListener;
			if (window.onload !== LG.onload) { lgNote('window.onload'); window.onload = LG.onload; }
			if (document.onreadystatechange !== LG.onrs) { lgNote('document.onreadystatechange'); document.onreadystatechange = LG.onrs; }
		}
		return LG.dropped;
	});
	api('nuNextRunDeferred', function (root) {
		var list = Array.prototype.slice.call((root || document).querySelectorAll('script[type="text/nu-deferred"]:not([data-nu-ran])'));
		(function next() {
			var old = list.shift();
			if (!old) return;
			old.setAttribute('data-nu-ran', '1');
			var s = document.createElement('script');
			Array.prototype.forEach.call(old.attributes, function (a) { if (a.name !== 'type' && a.name !== 'data-nu-type' && a.name !== 'data-nu-ran') s.setAttribute(a.name, a.value); });
			var tp = old.getAttribute('data-nu-type');
			if (tp) s.type = tp;
			s.setAttribute('data-nu-source', 'html object');
			if (old.src) {
				s.async = false;
				s.onload = s.onerror = next;
				old.parentNode.insertBefore(s, old.nextSibling);
				return;
			}
			s.text = old.text;
			try { old.parentNode.insertBefore(s, old.nextSibling); } catch (e) { reportError(e.message, 'html object'); }
			next();
		})();
	});

