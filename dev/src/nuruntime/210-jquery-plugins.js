	// =================================================================== jQuery plugins (nuBuilder nucommon.js: $('#id').nuHide() …)
	// Each one calls the global function with the element id, as in nuBuilder.
	if (window.jQuery) jQuery.fn.extend({
		nuEnable: function (enable) {
			return this.each(function () {
				nuEnable(this.id, enable);
			});
		},
		nuReadonly: function (readonly) {
			return this.each(function () {
				nuReadonly(this.id, readonly);
			});
		},
		nuDisable: function () {
			return this.each(function () {
				nuDisable(this.id);
			});
		},
		nuShow: function (visible, openTab) {
			return this.each(function () {
				nuShow(this.id, visible, openTab);
			});
		},
		nuHide: function () {
			return this.each(function () {
				nuHide(this.id);
			});
		},
		nuRemove: function () {
			return this.each(function () {
				nuRemove(this.id);
			});
		},
		nuSelectRemoveEmpty: function (setIndex) {
			return this.each(function () {
				nuSelectRemoveEmpty(this.id, setIndex);
			});
		},
		nuGetValue: function (method) {
			return nuGetValue(this.attr('id'), method);
		},
		nuSetValue: function (v, method, change) {
			return this.each(function () {
				return nuSetValue(this.id, v, method, change);
			});
		},
		nuGetText: function () {
			return nuGetValue(this.attr('id'), 'text');
		},
		nuSetText: function (v) {
			return this.each(function () {
				return nuSetValue(this.id, v, 'text');
			});
		},
		nuGetHTML: function () {
			return nuGetValue(this.attr('id'), 'html');
		},
		nuTranslate: function (method) {
			return this.each(function () {
				nuSetValue(this.id, nuTranslate(nuGetValue(this.id, method)), method);
			});
		},
		nuRemoveFormatting: function () {
			return nuFORM.removeFormatting($(this).val(), $(this).attr('data-nu-format'));
		},
		nuIsVisible: function () {
			return nuIsVisible(this.attr('id'));
		},
		nuIsHidden: function () {
			return nuIsHidden(this.attr('id'));
		},
		nuIsDisabled: function () {
			return nuIsDisabled(this.attr('id'));
		},
		nuIsEnabled: function () {
			return nuIsEnabled(this.attr('id'));
		},
		nuSetFocus: function () {
			return nuSetFocus(this.attr('id'));
		},
		nuSetPlaceholder: function (placeholder, translate) {
			return this.each(function () {
				return nuSetPlaceholder(this.id, placeholder, translate);
			});
		},
		nuSetLabelText: function (str, translate) {
			return this.each(function () {
				nuSetLabelText(this.id, str, translate);
			});
		},
		nuTogglePassword: function (show) {
			return this.each(function () {
				var $input = $(this);
				var type = $input.attr('type') === 'password' ? 'text' : 'password';
				if (show !== undefined) {
					type = show ? 'text' : 'password';
				}
				$input.attr('type', type);
			});
		}
	});

