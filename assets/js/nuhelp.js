/* Documentation page: index (from h2/h3) and full-text search with highlighting. */
(function () {
	'use strict';
	var body = document.getElementById('helpBody');
	if (!body) return;
	var toc = document.getElementById('helpToc');
	var input = document.getElementById('helpSearch');
	var count = document.getElementById('helpCount');
	var none = document.getElementById('helpNone');
	var M = {};
	try { M = JSON.parse(document.getElementById('helpData').textContent); } catch (e) { /* ignore */ }

	var sections = Array.prototype.slice.call(body.querySelectorAll(':scope > section'));

	// ------------------------------------------------------------------ index
	var ul = document.createElement('ul');
	ul.className = 'list-unstyled mb-0';
	sections.forEach(function (sec) {
		var h2 = sec.querySelector('h2');
		if (!h2) return;
		var li = document.createElement('li');
		li.appendChild(link(h2, 'nuHelpToc2'));
		var subs = sec.querySelectorAll('.doc-sub');
		if (subs.length) {
			var sul = document.createElement('ul');
			sul.className = 'list-unstyled';
			Array.prototype.forEach.call(subs, function (sub) {
				var h3 = sub.querySelector('h3');
				if (!h3) return;
				var sli = document.createElement('li');
				sli.appendChild(link(h3, 'nuHelpToc3'));
				sul.appendChild(sli);
				sub.nuTocItem = sli;
			});
			li.appendChild(sul);
		}
		ul.appendChild(li);
		sec.nuTocItem = li;
	});
	toc.appendChild(ul);

	function link(h, cls) {
		var a = document.createElement('a');
		a.href = '#' + h.id;
		a.className = cls;
		a.textContent = h.textContent;
		return a;
	}

	// ------------------------------------------------------------------ search
	function clearMarks() {
		Array.prototype.forEach.call(body.querySelectorAll('mark.nuHelpHit'), function (m) {
			var p = m.parentNode;
			p.replaceChild(document.createTextNode(m.textContent), m);
			p.normalize();
		});
	}

	function escapeRe(s) { return s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); }

	function highlight(root, words) {
		var re = new RegExp('(' + words.map(escapeRe).join('|') + ')', 'gi');
		var walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, null);
		var nodes = [];
		while (walker.nextNode()) nodes.push(walker.currentNode);
		nodes.forEach(function (n) {
			if (!re.test(n.nodeValue)) return;
			re.lastIndex = 0;
			var frag = document.createDocumentFragment();
			n.nodeValue.split(re).forEach(function (part, i) {
				if (i % 2) { var m = document.createElement('mark'); m.className = 'nuHelpHit'; m.textContent = part; frag.appendChild(m); }
				else if (part) frag.appendChild(document.createTextNode(part));
			});
			n.parentNode.replaceChild(frag, n);
		});
	}

	function matches(text, words) {
		text = text.toLowerCase();
		return words.every(function (w) { return text.indexOf(w) >= 0; });
	}

	/** Text of a section without its sub-sections (heading and intro). */
	function ownText(sec) {
		var t = '';
		Array.prototype.forEach.call(sec.childNodes, function (n) {
			if (!(n.classList && (n.classList.contains('doc-sub') || n.hasAttribute('data-nu-search-skip')))) t += ' ' + n.textContent;
		});
		return t;
	}

	function run() {
		var q = input.value.trim();
		var words = q.toLowerCase().split(/\s+/).filter(Boolean);
		clearMarks();
		var hits = 0;
		sections.forEach(function (sec) {
			var subs = Array.prototype.slice.call(sec.querySelectorAll('.doc-sub'));
			fnFilter(sec, words);
			if (!words.length) {
				sec.hidden = false; subs.forEach(function (s) { s.hidden = false; show(s, true); });
				show(sec, true);
				return;
			}
			var own = matches(ownText(sec), words);
			var any = false;
			subs.forEach(function (s) {
				var ok = own || (s.nuFnShown !== undefined ? s.nuFnShown > 0 : matches(s.textContent, words));
				s.hidden = !ok; show(s, ok);
				if (ok && !own) hits++;
				any = any || ok;
			});
			var visible = own || any || (!subs.length && matches(sec.textContent, words));
			if (visible && (own || !subs.length)) hits++;
			sec.hidden = !visible; show(sec, visible);
		});
		if (words.length) highlight(body, words);
		none.classList.toggle('d-none', !words.length || hits > 0);
		count.textContent = words.length ? (M.found || '%d found').replace('%d', hits) : '';
		try {
			var u = new URL(window.location.href);
			if (q) u.searchParams.set('q', q); else u.searchParams.delete('q');
			history.replaceState(null, '', u.toString());
		} catch (e) { /* ignore */ }
	}

	function show(el, on) { if (el.nuTocItem) el.nuTocItem.hidden = !on; }

	// ------------------------------------------------------------------ function references (0.8.399, core/nufnref.php)
	/** Global search: in a group of a function reference only the matching functions (all when the group title matches). */
	function fnFilter(sec, words) {
		Array.prototype.forEach.call(sec.querySelectorAll('[data-nu-search-skip]'), function (el) { el.hidden = words.length > 0; });   // own search + A–Z index
		Array.prototype.forEach.call(sec.querySelectorAll('.nuFnGroup'), function (grp) {
			var fns = grp.querySelectorAll('.nuFn'), h = grp.querySelector('h3'), all = !words.length || matches(h ? h.textContent : '', words), n = 0;
			Array.prototype.forEach.call(fns, function (f) {
				var ok = all || matches(f.textContent, words);
				f.classList.toggle('nuFnOutG', !ok);
				if (ok) n++;
			});
			grp.nuFnShown = words.length ? n : undefined;
		});
	}

	/** The search field of one reference: filters its functions (name, signature, description, example). */
	Array.prototype.forEach.call(body.querySelectorAll('[data-nu-fn-search]'), function (box) {
		var sec = box.closest('section'), cnt = sec.querySelector('[data-nu-fn-count]'), noneEl = sec.querySelector('.nuFnNone');
		var fns = Array.prototype.slice.call(sec.querySelectorAll('.nuFn')), tmr;
		function filter() {
			var words = box.value.trim().toLowerCase().split(/\s+/).filter(Boolean), shown = 0;
			fns.forEach(function (f) {
				var ok = !words.length || matches(f.getAttribute('data-nu-fn') + ' ' + f.textContent, words);
				f.classList.toggle('nuFnOutL', !ok);
				if (ok) shown++;
			});
			Array.prototype.forEach.call(sec.querySelectorAll('.nuFnGroup'), function (grp) {
				grp.classList.toggle('nuFnOutL', !grp.querySelector('.nuFn:not(.nuFnOutL)'));
			});
			cnt.textContent = words.length ? cnt.getAttribute('data-nu-fn-count').replace('%s', shown).replace('%s', fns.length) : '';
			noneEl.hidden = !words.length || shown > 0;
		}
		box.addEventListener('input', function () { clearTimeout(tmr); tmr = setTimeout(filter, 120); });
		box.addEventListener('keydown', function (e) {
			if (e.key === 'Escape') { box.value = ''; filter(); }
			if (e.key === 'Enter') {
				e.preventDefault();
				var first = sec.querySelector('.nuFn:not(.nuFnOutL):not(.nuFnOutG)');
				if (first) first.scrollIntoView({block: 'start'});
			}
		});
		box.nuFnFilter = filter;
	});

	/** A link to a function (index A–Z, links in the text) clears a search that hides it. */
	body.addEventListener('click', function (e) {
		var a = e.target.closest('a[href^="#fn-"]');
		if (!a) return;
		var t = document.getElementById(a.getAttribute('href').slice(1));
		if (!t) return;
		if (t.classList.contains('nuFnOutL')) {
			var box = t.closest('section').querySelector('[data-nu-fn-search]');
			if (box) { box.value = ''; box.nuFnFilter(); }
		}
		if (t.classList.contains('nuFnOutG') || t.closest('[hidden]')) { input.value = ''; run(); }
	});

	var timer;
	input.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(run, 150); });
	input.addEventListener('keydown', function (e) {
		if (e.key === 'Escape') { input.value = ''; run(); }
		if (e.key === 'Enter') {   // jump to the first hit
			e.preventDefault();
			var m = body.querySelector('mark.nuHelpHit');
			if (m) m.scrollIntoView({block: 'center'});
		}
	});
	if (input.value.trim()) run();

	// "/" focuses the search (like many documentation sites)
	document.addEventListener('keydown', function (e) {
		if (e.key === '/' && document.activeElement !== input && !/INPUT|TEXTAREA|SELECT/.test(document.activeElement.tagName)) {
			e.preventDefault(); input.focus();
		}
	});

	// active index entry
	toc.addEventListener('click', function (e) {
		var a = e.target.closest('a');
		if (!a) return;
		Array.prototype.forEach.call(toc.querySelectorAll('a.active'), function (x) { x.classList.remove('active'); });
		a.classList.add('active');
	});
	if (location.hash) {
		var t = document.getElementById(location.hash.slice(1));
		if (t) setTimeout(function () { t.scrollIntoView(); }, 0);
	}
})();
