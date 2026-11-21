	// =================================================================== edited state
	function markEdited(el) {
		if (!el || !el.getAttribute) return;
		el.classList.add('nuEdited');
		el.classList.remove('is-invalid');
		if (!N.edited) callHook('nuOnSetSaved', false);                     // nuBuilder: nuSetSaved(false) when the record gets edited
		N.edited = true;
		$('#nuSaveButton').addClass('nuSaveEdited').prop('disabled', false);   // a change makes the record saveable again
		var row = el.closest ? el.closest('.nuSubformRow') : null;
		if (row) {
			row.classList.add('nuRowEdited');
			// typing into the blank last row adds the next blank row (nuBuilder behaviour)
			var sf = row.getAttribute('data-nu-subform');
			if (row.classList.contains('nuNewRow') && !el.classList.contains('nuSubformDelete')) {
				// the blank row's delete box is ticked, as in nuBuilder Forte – it becomes a real row now
				var nd = row.getAttribute('data-nu-prefix') ? g(row.getAttribute('data-nu-prefix') + 'nuDelete') : null;
				if (nd && nd.checked) nd.checked = false;
			}
			var cancel = !el.classList.contains('nuSubformDelete') && beforeInsert(sf, el) === true;   // Forte: on every change of a sub-form field
			if (row.classList.contains('nuNewRow') && !row.nextElementSibling && !el.classList.contains('nuSubformDelete')) {
				row.classList.remove('nuNewRow');
				if (!cancel) appendRow(sf, true);
			} else row.classList.remove('nuNewRow');
		}
	}

	// as nuBuilder Forte: only the record's edited state (Save button, leave question) is reset – the fields keep their nuEdited mark,
	// so values the form's code set while loading (and then called nuHasNotBeenEdited) are still saved
	api('nuHasNotBeenEdited', function () {
		callHook('nuOnSetSaved', true);
		N.edited = false;
		$('#nuSaveButton').removeClass('nuSaveEdited');
	});
	api('nuSetSaved', function () { nuHasNotBeenEdited(); });

	api('nuChange', function (e) {
		var el = e && (e.target || e);
		if (!el || el.classList.contains('nuLookupCode') || el.classList.contains('nuLookupDescription')) return;
		applyFormat(el, false);
		markEdited(el);
		if (!el.classList.contains('nuCalculator')) calculate();
	});

	function calculate() {
		if (typeof window.nuCalculateForm === 'function') {
			try { window.nuCalculateForm(); } catch (x) { reportError(x.message, 'nuCalculateForm'); }
		}
	}

	$(document).on('change', '#nuEditForm [data-nu-field], #nuEditForm .nuSubformDelete', function (e) {
		// a control of form code inside an HTML / Word / Run object (a div with data-nu-field) is no field of the record:
		// nuBuilder Forte never marks the record as edited for it (a filter select of an HTML object asked "unsaved changes")
		if (e.target !== this) return;
		if (this.classList.contains('nuSubformDelete')) {
			$(this).closest('.nuSubformRow').toggleClass('nuRowDeleted', this.checked);
			markEdited(this);
			calculate();
			return;
		}
		nuChange(e);
	});
	$(document).on('input', '#nuEditForm input[data-nu-field]:not(.nuLookupCode), #nuEditForm textarea[data-nu-field]', function () { markEdited(this); });
	// object event onenter (nuBuilder: nuOnEnterKey – keydown Enter runs the code with this = the object)
	$(document).on('keydown', '#nuEditForm [onenter]', function (e) {
		if (e.key !== 'Enter' || this.closest('template')) return;
		runSnippet(this.getAttribute('onenter'), e.originalEvent || e, this);
	});

