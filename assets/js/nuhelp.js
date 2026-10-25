/*
 * Documentation page: two tabs (0.8.464) – Guide (#helpBody, docs/nunext-docs.htm) and Code reference (#helpRef, docs/nunext-ref.htm,
 * fetched from ?p=help&part=ref the first time it is needed). Each tab has its own index (from h2 / h3); the search runs in the open
 * tab, or in both with "Search both tabs". A link or #hash to an id of the other tab opens that tab. ?tab=ref is kept in the address.
 */
(function () {
	'use strict';
	var guideBody = document.getElementById('helpBody');
	if (!guideBody) return;
	var input = document.getElementById('helpSearch');
	var count = document.getElementById('helpCount');
	var none = document.getElementById('helpNone');
	var allBox = document.getElementById('helpAll');
	var refBody = document.getElementById('helpRef');
	var M = {};
	try { M = JSON.parse(document.getElementById('helpData').textContent); } catch (e) { /* ignore */ }

	function matches(text, words) {
		text = text.toLowerCase();
		return words.every(function (w) { return text.indexOf(w) >= 0; });
	}
	function escapeRe(s) { return s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); }

	// ================================================================== one tab: index, search, function reference
	function Pane(name, body, toc, wrap) {
		this.name = name; this.body = body; this.toc = toc; this.wrap = wrap;
		this.sections = Array.prototype.slice.call(body.querySelectorAll(':scope > section'));
		this.buildToc();
		this.initFnRef();
	}

	Pane.prototype.buildToc = function () {
		var ul = document.createElement('ul');
		ul.className = 'list-unstyled mb-0';
		this.sections.forEach(function (sec) {
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
		this.toc.textContent = '';
		this.toc.appendChild(ul);
		var toc = this.toc;
		toc.addEventListener('click', function (e) {   // active index entry
			var a = e.target.closest('a');
			if (!a) return;
			Array.prototype.forEach.call(toc.querySelectorAll('a.active'), function (x) { x.classList.remove('active'); });
			a.classList.add('active');
		});
	};

	function link(h, cls) {
		var a = document.createElement('a');
		a.href = '#' + h.id;
		a.className = cls;
		a.textContent = h.textContent;
		return a;
	}

	Pane.prototype.clearMarks = function () {
		Array.prototype.forEach.call(this.body.querySelectorAll('mark.nuHelpHit'), function (m) {
			var p = m.parentNode;
			p.replaceChild(document.createTextNode(m.textContent), m);
			p.normalize();
		});
	};

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

	/** Text of a section without its sub-sections (heading and intro). */
	function ownText(sec) {
		var t = '';
		Array.prototype.forEach.call(sec.childNodes, function (n) {
			if (!(n.classList && (n.classList.contains('doc-sub') || n.hasAttribute('data-nu-search-skip')))) t += ' ' + n.textContent;
		});
		return t;
	}
	function show(el, on) { if (el.nuTocItem) el.nuTocItem.hidden = !on; }

	/** Filters this tab by the words; @return number of hits ([] = everything shown again). */
	Pane.prototype.search = function (words) {
		var self = this, hits = 0;
		this.clearMarks();
		this.sections.forEach(function (sec) {
			var subs = Array.prototype.slice.call(sec.querySelectorAll('.doc-sub'));
			self.fnFilter(sec, words);
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
		this.body.classList.toggle('nuHelpSearching', words.length > 0);   // both tabs of the function reference are shown
		if (words.length) highlight(this.body, words);
		this.hits = hits;
		return hits;
	};

	// ------------------------------------------------------------------ function references (0.8.399, core/nufnref.php)
	/** Global search: in a group of a function reference only the matching functions (all when the group title matches). */
	Pane.prototype.fnFilter = function (sec, words) {
		Array.prototype.forEach.call(sec.querySelectorAll('[data-nu-search-skip]'), function (el) { el.hidden = words.length > 0; });   // own search + A–Z index
		Array.prototype.forEach.call(sec.querySelectorAll('.nuFnGroup'), function (grp) {
			var fns = grp.querySelectorAll('.nuFn'), h = grp.querySelector('h4'), all = !words.length || matches(h ? h.textContent : '', words), n = 0;
			Array.prototype.forEach.call(fns, function (f) {
				var ok = all || matches(fnText(f), words);
				f.classList.toggle('nuFnOutG', !ok);
				if (ok) n++;
			});
			grp.nuFnShown = words.length ? n : undefined;
			grp.classList.toggle('nuFnGroupOut', words.length > 0 && n === 0);
		});
		// the tabs PHP / JavaScript (0.8.456): a tab counts as a part of the index – its matches decide whether it is listed
		Array.prototype.forEach.call(sec.querySelectorAll('.nuFnPane'), function (pane) {
			var n = 0;
			Array.prototype.forEach.call(pane.querySelectorAll('.nuFnGroup'), function (g) { n += g.nuFnShown || 0; });
			pane.nuFnShown = words.length ? n : undefined;
		});
	};

	/** Text of a function card for the search – without "Also see" (names of other functions). */
	function fnText(f) {
		if (f.nuFnText === undefined) { var see = f.querySelector('.nuFnSee'); f.nuFnText = see ? f.textContent.replace(see.textContent, '') : f.textContent; }
		return f.nuFnText;
	}

	Pane.prototype.initFnRef = function () {
		var body = this.body;
		// the search field of one reference: filters its functions (name, signature, description, example)
		Array.prototype.forEach.call(body.querySelectorAll('[data-nu-fn-search]'), function (box) {
			var sec = box.closest('.nuFnPane') || box.closest('section'), cnt = sec.querySelector('[data-nu-fn-count]'), noneEl = sec.querySelector('.nuFnNone');
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
		// a link to a function (index A–Z, links in the text) clears a search that hides it
		body.addEventListener('click', function (e) {
			var a = e.target.closest('a[href^="#fn-"]');
			if (!a) return;
			var t = document.getElementById(a.getAttribute('href').slice(1));
			if (!t) return;
			if (t.classList.contains('nuFnOutL')) {
				var box = (t.closest('.nuFnPane') || t.closest('section')).querySelector('[data-nu-fn-search]');
				if (box) { box.value = ''; box.nuFnFilter(); }
			}
			if (t.classList.contains('nuFnOutG') || t.closest('[hidden]:not([role="tabpanel"])')) { input.value = ''; run(); }
		});
		// tabs PHP / JavaScript of the function reference (0.8.456)
		Array.prototype.forEach.call(body.querySelectorAll('[data-nu-fn-tab]'), function (b) {
			b.addEventListener('click', function () { fnTab(b.getAttribute('data-nu-fn-tab')); });
		});
		try {
			var last = localStorage.getItem('nunext-help-fntab');
			if (last && document.getElementById(last) && !location.hash) fnTab(last);
		} catch (e) { /* storage blocked */ }
	};

	function fnTab(paneId) {
		var pane = document.getElementById(paneId);
		if (!pane) return;
		Array.prototype.forEach.call(document.querySelectorAll('.nuFnPane'), function (x) { x.classList.toggle('active', x === pane); });
		Array.prototype.forEach.call(document.querySelectorAll('[data-nu-fn-tab]'), function (b) {
			var on = b.getAttribute('data-nu-fn-tab') === paneId;
			b.classList.toggle('active', on); b.setAttribute('aria-selected', on ? 'true' : 'false');
		});
		try { localStorage.setItem('nunext-help-fntab', paneId); } catch (e) { /* storage blocked */ }
	}

	// ================================================================== the two tabs
	var guide = new Pane('guide', guideBody, document.getElementById('helpToc'), document.getElementById('helpPaneGuide'));
	var ref = null, refLoading = null;
	var current = refBody && !document.getElementById('helpPaneRef').hidden ? 'ref' : 'guide';

	/** The code reference, fetched once (Promise of its Pane, or null without the tab). */
	function loadRef() {
		if (!refBody) return Promise.resolve(null);
		if (ref) return Promise.resolve(ref);
		if (refLoading) return refLoading;
		var wait = document.getElementById('helpRefLoading');
		refLoading = fetch(refBody.getAttribute('data-src'), {credentials: 'same-origin', headers: {'Accept': 'text/html'}})
			.then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.text(); })
			.then(function (html) {
				refBody.innerHTML = html;
				if (wait) wait.remove();
				ref = new Pane('ref', refBody, document.getElementById('helpTocRef'), document.getElementById('helpPaneRef'));
				var words = currentWords();
				if (words.length && (allBox && allBox.checked)) ref.search(words);
				return ref;
			})
			.catch(function (e) {
				if (wait) { wait.classList.add('text-danger'); wait.textContent = (M.failed || 'The code reference could not be loaded') + ' (' + e.message + ')'; }
				refLoading = null;
				return null;
			});
		return refLoading;
	}

	function setTab(name, keepScroll) {
		if (!refBody) name = 'guide';
		current = name;
		document.getElementById('helpPaneGuide').hidden = name !== 'guide';
		document.getElementById('helpPaneRef').hidden = name !== 'ref';
		document.getElementById('helpToc').hidden = name !== 'guide';
		document.getElementById('helpTocRef').hidden = name !== 'ref';
		Array.prototype.forEach.call(document.querySelectorAll('[data-help-tab]'), function (b) {
			var on = b.getAttribute('data-help-tab') === name;
			b.classList.toggle('active', on); b.setAttribute('aria-selected', on ? 'true' : 'false');
		});
		try {
			var u = new URL(window.location.href);
			if (name === 'ref') u.searchParams.set('tab', 'ref'); else u.searchParams.delete('tab');
			if (!keepScroll) u.hash = '';
			history.replaceState(null, '', u.toString());
		} catch (e) { /* ignore */ }
		var p = name === 'ref' ? loadRef() : Promise.resolve(guide);
		return p.then(function () { run(); return name; });
	}
	Array.prototype.forEach.call(document.querySelectorAll('[data-help-tab]'), function (b) {
		b.addEventListener('click', function () { setTab(b.getAttribute('data-help-tab')); window.scrollTo({top: 0, behavior: 'instant'}); });
	});

	// ------------------------------------------------------------------ search (open tab, or both)
	function currentWords() { return input.value.trim().toLowerCase().split(/\s+/).filter(Boolean); }

	function run() {
		var q = input.value.trim(), words = currentWords(), both = !!(allBox && allBox.checked && words.length);
		var hits = 0, other = 0;
		var open = current === 'ref' ? ref : guide;
		if (open) hits = open.search(words);
		var closed = current === 'ref' ? guide : ref;
		if (closed) other = closed.search(both ? words : []);
		if (both && !ref) loadRef().then(function () { if (ref && current !== 'ref') { other = ref.search(words); report(hits, other, words); } });
		report(hits, both ? other : 0, words);
		try {
			var u = new URL(window.location.href);
			if (q) u.searchParams.set('q', q); else u.searchParams.delete('q');
			if (allBox && allBox.checked) u.searchParams.set('all', '1'); else u.searchParams.delete('all');
			history.replaceState(null, '', u.toString());
		} catch (e) { /* ignore */ }
	}

	/** Count of the open tab; with "both tabs" the hits of the other tab too – no hits here but there: that tab opens. */
	function report(hits, other, words) {
		none.classList.toggle('d-none', !words.length || hits + other > 0);
		count.textContent = words.length ? (M.found || '%d found').replace('%d', hits + other) : '';
		var tabs = document.querySelectorAll('[data-help-tab]');
		Array.prototype.forEach.call(tabs, function (b) {
			var name = b.getAttribute('data-help-tab'), n = name === current ? hits : other, badge = b.querySelector('.nuHelpTabHits');
			var showIt = words.length > 0 && allBox && allBox.checked;
			if (!badge && showIt) { badge = document.createElement('span'); badge.className = 'badge rounded-pill text-bg-secondary ms-1 nuHelpTabHits'; b.appendChild(badge); }
			if (badge) { badge.hidden = !showIt; badge.textContent = String(n); }
		});
		if (words.length && hits === 0 && other > 0 && allBox && allBox.checked) setTab(current === 'ref' ? 'guide' : 'ref', true);
	}

	var timer;
	input.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(run, 150); });
	input.addEventListener('keydown', function (e) {
		if (e.key === 'Escape') { input.value = ''; run(); }
		if (e.key === 'Enter') {   // jump to the first hit
			e.preventDefault();
			var m = (current === 'ref' ? refBody : guideBody).querySelector('mark.nuHelpHit');
			if (m) m.scrollIntoView({block: 'center'});
		}
	});
	if (allBox) allBox.addEventListener('change', run);

	// "/" focuses the search (like many documentation sites)
	document.addEventListener('keydown', function (e) {
		if (e.key === '/' && document.activeElement !== input && !/INPUT|TEXTAREA|SELECT/.test(document.activeElement.tagName)) {
			e.preventDefault(); input.focus();
		}
	});

	// ------------------------------------------------------------------ links and #hash into either tab
	/** Opens the tab (and PHP / JavaScript pane) of an id; the reference is loaded when the id is not in the guide. */
	function locate(id) {
		var t = id ? document.getElementById(id) : null;
		if (t && guideBody.contains(t)) return (current === 'guide' ? Promise.resolve() : setTab('guide', true)).then(function () { return t; });
		if (t && refBody && refBody.contains(t)) return (current === 'ref' ? Promise.resolve() : setTab('ref', true)).then(function () { return t; });
		if (!refBody || ref) return Promise.resolve(null);
		return loadRef().then(function () {
			var r = document.getElementById(id);
			return r && refBody.contains(r) ? setTab('ref', true).then(function () { return r; }) : null;
		});
	}
	function reveal(el) {
		var pane = el && el.closest ? el.closest('.nuFnPane') : null;
		if (pane && !pane.classList.contains('active')) fnTab(pane.id);
	}
	function jump(t) {
		if (!t) return;
		reveal(t);
		var root = document.documentElement;
		root.style.scrollBehavior = 'auto';   // a long way on this page: no smooth scroll
		t.scrollIntoView({block: 'start', behavior: 'instant'});
		setTimeout(function () { t.scrollIntoView({block: 'start', behavior: 'instant'}); root.style.scrollBehavior = ''; }, 60);
	}
	document.addEventListener('click', function (e) {
		var a = e.target.closest && e.target.closest('a[href^="#"]');
		if (!a || a.closest('#helpTabs')) return;
		var id = a.getAttribute('href').slice(1), t = document.getElementById(id);
		if (t && t.closest('[role="tabpanel"]') && !t.closest('[role="tabpanel"]').hidden) { reveal(t); return; }   // same tab: the browser scrolls
		e.preventDefault();
		locate(id).then(function (x) { if (x) { history.replaceState(null, '', '#' + id); jump(x); } });
	}, true);
	window.addEventListener('hashchange', function () {
		locate(location.hash.slice(1)).then(jump);
	});

	// start: ?tab=ref, a #hash (in either file), a search from the address
	var start = location.hash ? locate(location.hash.slice(1)) : (current === 'ref' ? setTab('ref', true) : Promise.resolve(null));
	start.then(function (t) {
		if (input.value.trim()) run();
		if (t && t.nodeType) { jump(t); window.addEventListener('load', function () { setTimeout(function () { jump(t); }, 50); }); }
	});
})();
