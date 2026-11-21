	// =================================================================== features nuBuilder Next does not implement (yet)
	// (nuEnableMultiSelect, nuShowFile, nuSetDateFormat, nuSetTabHelpButtons, nuSetSideBarStyle are not in nuBuilder Forte 4.9's JavaScript –
	// kept so old code does not fail; nuSetFormValue is PHP only in nuBuilder Forte and supported there)
	var warned = {};
	['nuResizeBrowseColumns', 'nuSetBrowseColumnWidth', 'nuOpenWiki',
		'nuSetSnippetFormFilter', 'nuEnableMultiSelect', 'nuShowFile',
		'nuSetDateFormat', 'nuCreateDialog', 'nuAccessFormSetButtonIcons', 'nuBrowseStyleStatusColumn',
		'nuSetTabHelpButtons', 'nuDragElement', 'nuSetSideBarStyle', 'nuShowVersionInfo', 'nuHasHiddenModalDragDialog'
	].forEach(function (name) {
		if (window[name]) return;
		window[name] = function () {
			if (!warned[name]) { warned[name] = true; console.warn('nuBuilder Next: ' + name + '() is not supported yet'); }
			return $();
		};
	});
	if (!$.fn.nuSearchablePopup) $.fn.nuSearchablePopup = function () { return this; };
	if (!$.fn.nuSearchableMultiPopup) $.fn.nuSearchableMultiPopup = function () { return this; };
	if (!$.fn.nuCSSNumber) $.fn.nuCSSNumber = function (p) { return parseInt(this.css(p), 10) || 0; };
	// older nuBuilder Forte name – only when no code of the site defines it
	if (!$.fn.cssNumber) $.fn.cssNumber = function (p) { return parseInt(this.css(p), 10) || 0; };

