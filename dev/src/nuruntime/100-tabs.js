	// =================================================================== tabs
	function tabKey() { return 'nuNextTab:' + N.form.id; }
	api('nuSelectTab', function (tab) {
		var el = typeof tab === 'number' ? g('nuTab' + tab) : (typeof tab === 'string' ? (g(tab) || $('[data-nu-tab-id="' + tab + '"].nuTab')[0]) : tab);
		if (el && nuBs.ready()) nuBs.tab(el).show();
	});
	api('nuHideTab', function (i) { $('#nuTab' + i).parent().hide(); });
	api('nuShowTab', function (i) { $('#nuTab' + i).parent().show(); });
	api('nuHideTabById', function (id) { $('.nuTab[data-nu-tab-id="' + id + '"]').parent().hide(); });
	// nuBuilder Forte: visible === false hides the tab; then the tab bar follows nuSetHideTabIfOnlyOne
	api('nuShowTabById', function (id, visible) {
		var t = $('.nuTab[data-nu-tab-id="' + id + '"]').parent();
		if (visible === false) t.hide(); else t.show();
		if (window.nuSetHideTabIfOnlyOne) nuSetHideTabIfOnlyOne();
	});

