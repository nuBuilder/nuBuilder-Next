<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/** Helpers of the form page (pages/nuform.php, code review R7) – before, functions the page defined itself. */
final class NuFormPage
{
	/** The list of a form (code review R7): Before Browse, the rows, filter values – $c = what the page knows, returned = what it shows. */
	public static function browse(array $c): array
	{
		['meta' => $meta, 'props' => $props, 'locked' => $locked, 'caller' => $caller, 'rights' => $rights, 'lookupTo' => $lookupTo, 'u' => $u, 'errors' => $errors, 'breadcrumbs' => $breadcrumbs] = $c;
		// nuBuilder Forte's breadcrumb keys in nuHash() of Before Browse: the list's search, page and sort
		NuHash::$state += nu_browse_hash_state();
		$hash = NuHash::forForm($meta, '', [], $props);
		$hash['ADV_SEARCH'] = NuAdvSearch::encode(NuAdvSearch::parse($_GET['adv'] ?? ''));   // advanced search for Before Browse
		NuPhp::reset();
		// nuBuilder's global hook nu_before_save runs before every Before Browse too; an error stops the list's own hook
		$okBB = NuProcedure::hook('nu_before_save', $hash) && NuPhp::run($meta->php('BB'), $hash, 'Before Browse');
		$hash = NuPhp::$hash + $hash;
		$js   = NuPhp::$js;
		if (!$okBB) $errors = NuPhp::$errors ?: [['message' => NuPhp::$error, 'field' => '']];
		NuFormPage::phpProps($props, $locked);
		$pageScope = NuRender::$scope = NuProps::create($props, $locked, $caller);

		$state = nu_browse_request();
		$printMode = ($_GET['print'] ?? '') === '1' && $rights['print'];
		$data = null;
		$browseErr = '';                                         // browse SQL failed: shown to the globeadmin above the empty list
		if (!$errors) {
			try {
				$data = NuBrowse::query($meta, $hash, ['search' => $state['q'], 'runfilter' => $state['rf'], 'sort' => $state['sort'], 'dir' => $state['dir'], 'page' => $state['pg'], 'per' => $state['per'],
					'filters' => $state['flt'], 'adv' => $state['adv'], 'nosearch' => $state['nsc'], 'searchonly' => $state['sc'], 'all' => $printMode ? 5000 : 0,
					'process' => NuOnProcess::rowsHook($meta->php('BB'), $hash), 'raw' => $printMode && NuPrintView::raw($hash),   // nuOnProcessBrowseRows(), print settings
					'totals' => $lookupTo === '']);                        // row of column totals – not in lookup lists
				if ($printMode) $data['columns'] = NuPrintView::columns($data['columns'], $hash);   // nuPrintIncludeColumns() …
				foreach ($data['columns'] as &$col) {
					if ($col['filter'] && !$printMode) $col['values'] = NuBrowse::distinctValues($meta, $hash, $col);
				}
				unset($col);
			} catch (Throwable $e) {
				error_log('nuBuilder Next browse ' . $meta->code() . ': ' . $e->getMessage());
				// as nuBuilder Forte: the titles with no rows, the error in Debug results – the message only for the globeadmin / debug mode
				if (class_exists('NuDebugLog')) NuDebugLog::write('Browse SQL of ' . $meta->code() . ":\n" . $e->getMessage());
				$data = NuBrowse::emptyResult($meta, $hash, (int)$state['per'] ?: ((int)$meta->form['sfo_browse_rows_per_page'] ?: 20));
				if ($u['globeadmin'] || nu_debug()) $browseErr = t('form_err_browse_sql') . ' ' . $e->getMessage();
			}
			NuBrowse::dropTempTable();
		}
		$title = $meta->description();
		$breadcrumbs[] = ['title' => $title, 'url' => ''];
		return compact('hash', 'js', 'errors', 'props', 'locked', 'pageScope', 'state', 'printMode', 'data', 'browseErr', 'title', 'breadcrumbs');
	}

	/** One record of a form (code review R7): load, checks, Before Edit, the rendered tabs – $c = what the page knows, returned = what it shows. */
	public static function edit(array $c): array
	{
		['meta' => $meta, 'r' => $r, 'rights' => $rights, 'safe' => $safe, 'design' => $design, 'props' => $props, 'locked' => $locked, 'caller' => $caller,
			'layout' => $layout, 'mode' => $mode, 'errors' => $errors, 'u' => $u, 'breadcrumbs' => $breadcrumbs] = $c;
		$dbRow = [];
		$isNew = $r === '-1';
		if ($isNew && !$rights['create'] && $meta->type() !== 'launch') NuAcl::require($meta->id, 'create');
		$row = [];
		if ($isNew) $row = NuCalendar::newValues($meta, (string)($_GET['nv'] ?? ''));   // a new record from the calendar: start / end filled in
		if (!$isNew) {
			try {
				$row = NuRecord::load($meta, $r) ?? [];
			} catch (Throwable $e) {
				error_log('nuBuilder Next load ' . $meta->code() . ': ' . $e->getMessage());
				nu_fatal(t('form_err_load'), $u['globeadmin'] ? $e->getMessage() : '', true);
			}
			// as nuBuilder Forte: an id that is not in the table opens the form empty under that id (forms that only carry
			// sub-forms are opened with the parent's id) – its sub-forms list the rows of this id, a save writes no main record
			$ghost = !$row;
			// Security check "Records only through the form's list". a form whose Before Edit calls nuNextRecordCheck() decides
			// itself – its Before Edit runs first and the record is refused after it unless it called nuNextRecordCheck(false)
			$rcPending = false;
			if (!$ghost && !NuRecord::visible($meta, $r)) {
				if ($safe || !str_contains((string)($meta->php('BE') ?? ''), 'nuNextRecordCheck')) {
					http_response_code(404);
					nu_fatal(t('record_not_found'), '', true);
				}
				$rcPending = true;
			}
			if (!$design && !$ghost && !$rcPending) NuRecord::nulogViewed($meta, $r);   // <table>_nulog "viewed", as nuBuilder Forte
		}
		$dbRow = $row;                                          // as stored – for the check of concurrent edits
		$hash = NuHash::forForm($meta, $isNew ? '-1' : $r, $row, $props);
		NuPhp::reset();
		NuConflict::$formCheck = NuConflict::$formPresence = null;
		NuRecord::$formRecordCheck = null;
		// global hook nu_before_edit first (as nuBuilder 4.9); an error stops the form's Before Edit
		if (!NuProcedure::hook('nu_before_edit', $hash) || (!$safe && !NuPhp::run($meta->php('BE'), $hash, 'Before Edit'))) $errors = NuPhp::$errors ?: [['message' => NuPhp::$error, 'field' => '']];
		if (!empty($rcPending)) {                                // see above
			if (NuRecord::$formRecordCheck !== false) { http_response_code(404); nu_fatal(t('record_not_found'), '', true); }
			NuRecord::allowRecord($meta->id, $r);
			if (!$design) NuRecord::nulogViewed($meta, $r);
		}
		$js   = NuPhp::$js;
		$hash = NuPhp::$hash + $hash;
		foreach (NuPhp::$formValues as $k => $v) $row[$k] = $v;          // nuSetFormValue in BE
		// nuBuilder Forte's nuOnProcessObjects() in Before Edit: its body runs with $f->objects, the changes are taken back
		if (!$errors && !$safe && !NuOnProcess::objects($meta, $meta->php('BE'), $row, $hash)) $errors = NuPhp::$errors ?: [['message' => NuPhp::$error, 'field' => '']];
		NuFormPage::phpProps($props, $locked);
		$pageScope = NuRender::$scope = NuProps::create($props, $locked, $caller);

		$render = new NuRender($meta, $isNew ? '-1' : $r, $row, $hash, $layout);
		$render->setReadonly(!$isNew && !$rights['save']);
		$body  = $errors ? '' : $render->tabs();
		// the record's tabs stay hidden until nuruntime.js has run the form's code and laid the page out (nuNextStart removes the class) – objects
		// the code hides or moves, and the phone layout, no longer flash up in the first paint; after 3 s they show in any case (nuform.css)
		if ($mode === 'edit' && !$design && $body !== '') $body = preg_replace('/class="tab-content /', 'class="tab-content nuEditJsWait ', $body, 1) ?? $body;
		// no withheld / masked values in the title; a record Before Edit refused shows only the form's name
		$title = $errors ? $meta->description() : $meta->breadcrumb($isNew, NuFieldAccess::scrubHash($meta, $hash));
		if ($meta->hasBrowse()) $breadcrumbs[] = ['title' => $meta->description(), 'url' => nu_url('form', ['f' => $meta->id])];
		$breadcrumbs[] = ['title' => $title, 'url' => ''];
		return compact('isNew', 'dbRow', 'hash', 'js', 'errors', 'props', 'locked', 'pageScope', 'render', 'body', 'title', 'breadcrumbs');
	}

	/** Properties set in PHP (BB / BE) join the page's properties and are locked; nuDeleteProperty removes them. */
	public static function phpProps(array &$props, array &$locked): void
	{
		foreach (NuPhp::$props as $k => $v) { $props[$k] = $v; $locked[$k] = true; }
		foreach (array_keys(NuPhp::$deleted) as $k) unset($props[$k], $locked[$k]);
	}

	/** Content of a list cell: the value (with its class), or – with a click action – a real button: link look, button or icon. */
	public static function browseCell(string $v, string $cls, array $c, bool $act): string
	{
		$html = NuHtmlClean::cell($v);                                       // HTML as nuBuilder Forte, cleaned
		$inner = $cls !== '' ? '<span class="' . h($cls) . '">' . $html . '</span>' : $html;
		$v = NuHtmlClean::cellText($v);
		if (!$act) return $inner;
		$a = $c['action'];
		$lbl = (string)($a['label'] ?? '');
		$ic = !empty($a['icon']) ? '<i class="bi ' . h($a['icon']) . '" aria-hidden="true"></i>' : '';
		$d = ' data-nu-act="' . (int)$c['index'] . '" data-nu-v="' . h($v) . '"';
		return match ($a['look']) {
			'icon' => '<button type="button" class="btn btn-sm btn-outline-primary nuCellAct nuCellActIcon"' . $d . ' title="' . h($lbl !== '' ? $lbl : $v) . '" aria-label="'
				. h($lbl !== '' ? $lbl : ($v !== '' ? $v : (string)$c['title'])) . '">' . ($ic ?: '<i class="bi bi-box-arrow-up-right" aria-hidden="true"></i>') . '</button>',
			'button' => '<button type="button" class="btn btn-sm btn-outline-primary nuCellAct nuCellActBtn"' . $d . '>' . $ic
				. ($ic && ($lbl !== '' || $v !== '') ? ' ' : '') . ($lbl !== '' ? h($lbl) : $inner) . '</button>',
			default => $v === '' ? '' : '<button type="button" class="btn btn-link p-0 nuCellAct nuCellActLink"' . $d . '>' . $inner . '</button>',
		};
	}

	/** Labels of the options menu / shortcuts (keys: sc_*). */
	public static function shortcutLabels(): array
	{
		$out = [];
		foreach (array_keys(require NU_ROOT . '/lang/en.php') as $k) if (str_starts_with($k, 'sc_')) $out[substr($k, 3)] = t($k);
		$out['help'] = t('help_title');   // ⋮ → Documentation: the manual, as in the user menu – "Help" is the form's own help page (toolbar button)
		return $out;
	}

	/** nuBuilder's user details for nuUserCode(), nuUserTeam() … (blank for the globeadmin, like nuBuilder). */
	/** Address of nuBuilder Forte's folder relative to nuBuilder Next's index.php ('../' when Next sits inside it), else $nuConfigNuBuilderURL, else ''. */
	public static function forteBase(): string
	{
		$nub = NuBuilderLib::path();
		if ($nub !== '' && $nub === rtrim(dirname(NU_ROOT), '/\\')) return '../';
		$base = trim((string)nu_cfg('nuConfigNuBuilderURL'));
		return $base !== '' && preg_match('~^(https?:)?//|^/~i', $base) ? rtrim($base, '/') . '/' : '';
	}


	public static function userDetails(array $u): array
	{
		$map = ['name' => 'sus_name', 'code' => 'sus_code', 'position' => 'sus_position', 'department' => 'sus_department', 'team' => 'sus_team', 'first_name' => 'sus_first_name',
			'last_name' => 'sus_last_name', 'additional1' => 'sus_additional1', 'additional2' => 'sus_additional2', 'email' => 'sus_email',
			'accessibility' => 'sus_accessibility_features'];
		$out = array_fill_keys(array_keys($map), '');
		if ($u['globeadmin']) return $out;
		try {
			$row = NuAuth::userRow((string)$u['id']) ?? [];                   // the row kept for the request (code review R6)
			foreach ($map as $k => $c) $out[$k] = (string)($row[$c] ?? '');
		} catch (Throwable $ig) { nu_ignored($ig); }
		return $out;
	}

	/** Events of subform objects (afterinsertrow etc.) keyed by subform id. */
	public static function clientEvents(NuFormMeta $meta): object
	{
		$out = [];
		foreach ($meta->objects as $o) {
			if ($o['sob_all_type'] === 'subform' && isset($meta->events[$o['zzzzsys_object_id']])) $out[$o['sob_all_id']] = $meta->events[$o['zzzzsys_object_id']];
			if ($o['sob_all_type'] === 'lookup' && trim((string)$o['sob_lookup_javascript']) !== '') $out[$o['sob_all_id']]['lookup'] = (string)$o['sob_lookup_javascript'];
		}
		foreach ($meta->objects as $o) {
			if ($o['sob_all_type'] !== 'subform') continue;
			$sub = NuFormMeta::load((string)$o['sob_subform_zzzzsys_form_id']);
			foreach ($sub?->objects ?? [] as $so) {
				if ($so['sob_all_type'] === 'lookup' && trim((string)$so['sob_lookup_javascript']) !== '') $out[$o['sob_all_id'] . '.' . $so['sob_all_id']]['lookup'] = (string)$so['sob_lookup_javascript'];
			}
		}
		return (object)$out;
	}


	public static function script(string $code, string $label): string
	{
		if (trim($code) === '') return '';
		return '<script data-nu-source="' . h($label) . '">' . str_ireplace('</script', '<\/script', $code) . "\n</script>\n";
	}

	/**
	 * Setup → Header as nuBuilder outputs it: JavaScript inside <script id="nuheader"> (HTML such as <link> is added by closing
	 * the script with </script> … <script>), followed by Setup → Style. A header that starts with a tag (nuBuilder Next 0.8.3 and older
	 * expected HTML) is output unchanged.
	 */
	public static function setupHeader(string $header, string $style): string
	{
		$out = '';
		if (trim($header) !== '') {
			$out = preg_match('/^\s*<(script|link|style|meta|!--)/i', $header) ? $header : "<script id=\"nuheader\" data-nu-source=\"set_header\">\n" . $header . "\n</script>";
			$out = self::setupHeaderPaths($out);
		}
		$style = trim($style);
		if ($style !== '') $out .= "\n" . (preg_match('#</style>$#i', $style) ? $style : '<style data-nu-source="set_style">' . $style . '</style>');
		return $out;
	}

	/**
	 * Relative src / href of <script> and <link> tags in the Setup header (e.g. "libs/x.js") are written for nuBuilder Forte's folder: a file
	 * that is not in nuBuilder Next's folder but in Forte's is linked there (NuIncludes::url) – otherwise the browser asks
	 * nuBuilder Next's folder and gets 404. Addresses, absolute paths, data: and files of nuBuilder Next stay unchanged.
	 */
	public static function setupHeaderPaths(string $html): string
	{
		return (string)preg_replace_callback('/<(?:script|link)\b[^>]*>/i', static function (array $t): string {
			return (string)preg_replace_callback('/(\s(?:src|href)\s*=\s*)(["\'])([^"\']*)\2/i', static function (array $a): string {
				$v = trim(html_entity_decode($a[3], ENT_QUOTES));
				if ($v === '' || NuIncludes::isUrl($v) || preg_match('~^(data|javascript|blob|mailto):|^[#?]|\.\.~i', $v)) return $a[0];
				$path = (string)preg_replace('/[?#].*$/', '', $v);
				if ($path === '' || is_file(NU_ROOT . '/' . $path)) return $a[0];
				$url = NuIncludes::url($v);
				return $url === null ? $a[0] : $a[1] . $a[2] . h($url) . $a[2];
			}, $t[0]);
		}, $html);
	}
}
