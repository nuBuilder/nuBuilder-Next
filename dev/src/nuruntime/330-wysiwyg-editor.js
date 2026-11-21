	// =================================================================== WYSIWYG editor (nuBuilder "editor" objects, TinyMCE)
	// TinyMCE is not bundled: a library pack (Update → Libraries, vendor/packs/tinymce-<version>/); nuNext.tinymceBase = its folder.
	function tiny() { return window.tinymce || null; }

	var tinyArgs = {};                                        // arguments of the last nuInitTinyMCE per field – the theme switch starts it again
	api('nuInitTinyMCE', function (id, options, mobile, toolbar, toolbarGroups, menubar, contextmenu, quickbars) {
		var ta = g(id), box = g(id + '_container');
		if (!ta || !box) return;
		tinyArgs[id] = Array.prototype.slice.call(arguments);
		if (!tiny()) {
			$(box).hide(); $(ta).show().addClass('nuEditorFallback');
			if (!warned.tinymce) { warned.tinymce = true; console.warn('nuBuilder Next: TinyMCE not found – install its library pack (Update → Libraries)'); }
			return;
		}
		var plugins = typeof options === 'string' ? options : null;
		if (plugins) options = undefined;
		var ro = ta.readOnly || ta.getAttribute('data-nu-readonly') === '1';
		var dark = document.documentElement.getAttribute('data-bs-theme') === 'dark';
		$(ta).hide();
		// height: the designed height; an editor that is the lowest object of its tab takes the free height of the
		// window instead (below it only the form's legend, which stays visible), 250–800 px – e.g. the notes form
		var wrap = box.closest('.nuWrap'), pane = box.closest('.nuTabPane') || document;
		var prect = box.parentNode.getBoundingClientRect();
		// designed height: the editor's own box, otherwise the space its wrapper reserves below the editor's top (absolute layout)
		var inWrap = wrap ? Math.floor(wrap.getBoundingClientRect().bottom - prect.top) : 0;
		var edH = Math.max(200, box.parentNode.clientHeight || inWrap || ta.offsetHeight || 300);
		if (wrap && wrap.offsetParent !== null) {
			var rect = wrap.getBoundingClientRect(), below = false;
			pane.querySelectorAll('.nuWrap').forEach(function (w) {
				if (w !== wrap && !wrap.contains(w) && w.offsetParent !== null && w.getBoundingClientRect().top >= rect.bottom - 2) below = true;
			});
			// everything that follows the editor on the page (padding of the tab, the page's bottom margin)
			// must stay visible too: free = window height − editor top − that rest
			var main = document.querySelector('main') || document.body;
			var rest = Math.max(0, main.getBoundingClientRect().bottom - Math.max(rect.bottom, prect.top + edH));
			var free = Math.floor(window.innerHeight - prect.top - rest - 8);
			if (!below) {
				// fits the window: as high as the free space allows (also smaller than designed, so the legend stays visible), 250–800 px
				edH = Math.max(250, Math.min(800, free));
				box.parentNode.style.height = edH + 'px';
				var needWrap = Math.ceil(prect.top - rect.top + edH);            // the wrapper ends where the editor ends
				var delta = needWrap - wrap.offsetHeight;
				if (wrap.style.height) wrap.style.height = needWrap + 'px';
				var area = wrap.offsetParent;                                    // absolute layout: the area of the tab follows
				if (delta && area && area !== document.body && area.style.height) area.style.height = Math.max(0, area.offsetHeight + delta) + 'px';
			}
		}
		var cfg = {
			target: box,
			base_url: N.tinymceBase || 'assets/vendor/tinymce', suffix: '.min', license_key: 'gpl', promotion: false, branding: false,
			plugins: plugins
				|| 'code preview searchreplace autolink autosave directionality visualblocks visualchars fullscreen image link media codesample table charmap pagebreak nonbreaking anchor insertdatetime advlist lists help emoticons',
			toolbar: toolbar === 'minimal'
				? 'undo redo | bold italic underline strikethrough | link | bullist numlist | blocks | alignleft aligncenter alignright | forecolor backcolor | fullscreen'
				: (toolbar
					|| 'undo redo | blocks fontfamily fontsize | bold italic underline strikethrough | forecolor backcolor removeformat | alignleft aligncenter alignright alignjustify | outdent indent | numlist bullist | link image table charmap emoticons | fullscreen preview code'),
			toolbar_groups: toolbarGroups || {}, menubar: menubar || 'file edit view insert format tools table help', contextmenu: contextmenu || 'link image table',
			mobile: mobile || {menubar: false}, toolbar_mode: 'sliding', resize: true, elementpath: false, image_advtab: true,
			height: edH, width: '100%',
			skin: dark ? 'oxide-dark' : 'oxide', content_css: dark ? 'dark' : 'default', content_style: 'p { margin: 0; }',
			autosave_ask_before_unload: false, readonly: ro, disabled: ro,
			link_default_target: '_blank',                                    // new links open in a new tab (form code can override it with options)
			setup: function (editor) {
				editor.addShortcut('ctrl+shift+S', 'Save', function () { syncEditors(); nuSaveAction(); });
				editor.on('init', function (e) {
					editor.setContent(ta.value);
					if (window.nuTinyMCEOnInit) { try { window.nuTinyMCEOnInit(e, editor, id); } catch (x) { reportError(x.message, 'nuTinyMCEOnInit'); } }
				});
				editor.on('SetContent', function (e) { if (window.nuTinyMCEOnSetContent) window.nuTinyMCEOnSetContent(e, editor, id); });
				editor.on('input change undo redo', function () {
					var html = editor.getContent();
					if (html !== ta.value) { ta.value = html; markEdited(ta); }
				});
			}
		};
		if (options && typeof options === 'object') $.extend(cfg, options);
		var old = tiny().get(box.id);
		if (old) old.remove();
		tiny().init(cfg);
	});

	// light / dark switch: TinyMCE cannot change its skin while it runs – each editor is started again with the other skin;
	// the field holds its HTML already (input / change handler) – copied only when the editor still has unsent changes, because TinyMCE's own
	// cleaning (<b> → <strong>) would otherwise mark an untouched record as edited; the new editor takes the field's HTML
	new MutationObserver(function () {
		if (!tiny()) return;
		Object.keys(tinyArgs).forEach(function (id) {
			var ed = tiny().get(id + '_container'), ta = g(id);
			if (!ed) return;
			if (ta && ed.isDirty()) { var html = ed.getContent(); if (html !== ta.value) { ta.value = html; markEdited(ta); } }
			window.nuInitTinyMCE.apply(null, tinyArgs[id]);
		});
	}).observe(document.documentElement, {attributes: true, attributeFilter: ['data-bs-theme']});

	/** Copies the editors' HTML into their fields (nuBuilder nuSaveEditor). */
	function syncEditors() {
		if (!tiny()) return;
		$('.nuTinyMCE').each(function () {
			var ed = tiny().get(this.id), ta = g(this.id.slice(0, -10));
			if (!ed || !ta) return;
			var html = ed.getContent();
			if (html !== ta.value) { ta.value = html; markEdited(ta); }
		});
	}
	api('nuSaveEditor', syncEditors);
	// nuBuilder Forte's change handler of its TinyMCE set-up – form code with a copied nuInitTinyMCE calls it on every change:
	// copies the editor's HTML into its field (editor id = field id + "_container") and marks the record as edited
	api('nuTinyMCEOnChangeHandler', function (editor) {
		var id = editor && editor.id ? String(editor.id) : '', ta = /_container$/.test(id) ? g(id.slice(0, -10)) : null;
		if (ta && typeof editor.getContent === 'function') { var html = editor.getContent(); if (html !== ta.value) { ta.value = html; markEdited(ta); return; } }
		nuHasBeenEdited();
	});
	api('nuGetTinyMCEEditor', function (id) { return tiny() ? tiny().get(id + '_container') : null; });
	api('nuTinyMCESetContent', function (id, html) { var e = nuGetTinyMCEEditor(id); if (e) e.setContent(html); else if (g(id)) g(id).value = html; });
	api('nuTinyMCEGetContent', function (id) { var e = nuGetTinyMCEEditor(id); return e ? e.getContent() : (g(id) ? g(id).value : ''); });
	api('nuTinyMCESetBounds', function (id, left, top, width, height) {
		var el = g(id + '_parent_container');
		if (!el) return;
		if (left) el.style.left = left + 'px';
		if (top) el.style.top = top + 'px';
		if (width) el.style.width = width + 'px';
		if (height) el.style.height = height + 'px';
	});
	api('nuIsIframe', function () { return !!N.iframe; });
	api('nuIsMobile', function () { return window.matchMedia('(max-width: 767.98px)').matches; });
	api('nuUseMobileView', function () { return false; });
	if (!$.fn.nuOnEnterKey) $.fn.nuOnEnterKey = function (fn) { return this.on('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); fn.call(this, e); } }); };
	if (!String.prototype.nuJustNumbers) String.prototype.nuJustNumbers = function () { return this.replace(/[^0-9]/g, ''); };
	// nuBuilder Forte: size small | medium | large | a CSS size; appendToEnd puts the icon after the text
	api('nuAttachFontAwesome', function (id, cls, size, appendToEnd) {
		var t = $(typeof id === 'string' ? '#' + id : id);
		if (!t.length) return;
		var text = t.text().trim(), i = $('<i>').addClass(cls);
		var fs = size === 'small' ? 'small' : size === 'large' ? 'x-large' : (!size || size === 'medium' ? '' : size);
		if (fs) i.css('font-size', fs);
		if (text) i.css(appendToEnd ? 'margin-left' : 'margin-right', '3px'); else t.css('padding', '0 9px');
		if (appendToEnd) t.append(i); else t.prepend(i);
	});


