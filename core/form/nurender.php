<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * Renders the edit view of a nuBuilder form with nuBuilder's element IDs, so existing custom code finds its targets:
 *   field            #<id>              label            #label_<id>
 *   lookup           #<id> (pk, hidden) #<id>code #<id>button #<id>description
 *   subform row      #<sf><row3><field> delete checkbox  #<sf><row3>nuDelete (data-nu-checkbox=<sf>)
 *   subform title    #title_<sf><field> scroll area      #<sf>scrollDiv
 *   tabs             #nuTab0 …          wrapper (nuBuilder Next) #nuw_<id>
 */
final class NuRender
{
	private array $selectCache = [];
	public array $clientObjects = [];    // per main field: what the JS runtime needs
	public array $clientSubforms = [];

	public function __construct(
		private NuFormMeta $meta,
		private string $recordId,
		private array $row,
		private array $hash,
		private string $layout,
	) {
		NuRecord::$accessHash = $hash;                     // access conditions (access 9) of this form use its hash variables (0.8.382)
		$this->pageHash = NuFieldAccess::scrubHash($meta, $hash);   // HTML objects: no withheld / masked values (0.8.480)
	}

	/** The hash for what goes into the page as text (HTML objects): withheld fields empty, masked ones masked. */
	private array $pageHash = [];

	// ================================================================= tabs

	public function tabs(): string
	{
		$tabs = $this->meta->tabs ?: [['zzzzsys_tab_id' => '', 'syt_title' => $this->meta->description(), 'syt_access' => '0']];
		$isAdmin = (bool)NuAuth::user()['globeadmin'];
		$nav = '';
		$panes = '';
		foreach ($tabs as $i => $t) {
			$access = (string)$t['syt_access'];
			$hidden = $access === '2' || ($access === '3' && !$isAdmin);
			$nav .= '<li class="nav-item" role="presentation"' . ($hidden ? ' style="display:none"' : '') . '>'
				. '<button type="button" class="nav-link nuTab' . ($i === 0 ? ' active' : '') . '" id="nuTab' . $i . '" data-nu-tab="' . $i . '"'
				. ' data-nu-tab-id="' . h($t['zzzzsys_tab_id']) . '" data-bs-toggle="tab" data-bs-target="#nuTabPane' . $i . '" role="tab"'
				. ' aria-controls="nuTabPane' . $i . '" aria-selected="' . ($i === 0 ? 'true' : 'false') . '">' . h($t['syt_title']) . '</button></li>';
			$objects = $this->meta->objectsOfTab((string)$t['zzzzsys_tab_id']);
			$panes .= '<div class="tab-pane nuTabPane' . ($i === 0 ? ' show active' : '') . '" id="nuTabPane' . $i . '" role="tabpanel" aria-labelledby="nuTab' . $i . '" data-nu-tab-id="' . h($t['zzzzsys_tab_id']) . '">'
				. ($this->layout === 'absolute' ? $this->absolute($objects) : $this->flow($objects)) . '</div>';
		}
		$navHtml = count($tabs) > 1 || $this->meta->tabs === [] ? '<ul class="nav nav-tabs nuTabHolder" id="nuTabHolder" role="tablist">' . $nav . '</ul>' : '<ul class="nav nav-tabs nuTabHolder d-none" id="nuTabHolder" role="tablist">' . $nav . '</ul>';
		return $navHtml . '<div class="tab-content nuLayout-' . $this->layout . '" id="nuRECORD">' . $panes . '</div>';   // no legend below the form any more (0.8.627)
	}

	// ================================================================= layouts

	private function absolute(array $objects): string
	{
		$height = 40;
		$width  = 0;
		$html   = '';
		foreach ($objects as $o) {
			$top  = (int)$o['sob_all_top'];
			$left = (int)$o['sob_all_left'];
			$w    = LayoutSize::width($o);
			$h    = (int)$o['sob_all_height'];
			$height = max($height, $top + $h + ($o['sob_all_type'] === 'subform' ? 40 : 20));
			$width  = max($width, $left + $w + 20);
			// stacking as in nuBuilder (0.8.567): objects get no z-index (they stack in drawing order, and a z-index:-1 inside an HTML object
			// sends that element behind the others – before, z-index:1 on every wrapper trapped it), content boxes -1; the canvas is isolated
			$z = $o['sob_all_type'] === 'contentbox' ? 'z-index:-1' : '';
			// input buttons keep their height like run buttons (as in nuBuilder, 0.8.518) – other inputs size themselves
			$isBtn = $o['sob_all_type'] === 'input' && (string)($o['sob_input_type'] ?? '') === 'button';
			$style = "top:{$top}px;left:{$left}px;width:{$w}px;" . ($h > 0 && ($isBtn || !in_array($o['sob_all_type'], ['input', 'select', 'calc', 'display', 'lookup', 'word'], true)) ? "height:{$h}px;" : '') . $z;
			$html .= $this->wrapper($o, 'nuAbs', $style, $this->object($o, 'absolute'));
		}
		return '<div class="nuAbsoluteCanvas" style="height:' . $height . 'px;min-width:' . $width . 'px">' . $html . '</div>';
	}

	private function flow(array $objects): string
	{
		return $this->rows(NuLayout::build($objects, $this->layout));
	}

	private function rows(array $tree): string
	{
		$html = '';
		foreach ($tree['rows'] as $items) {
			$html .= '<div class="row g-3 nuGridRow">';
			foreach ($items as $it) {
				$o   = $it['obj'];
				$col = 'col-12 col-md-' . $it['span'];
				if ($o['sob_all_type'] === 'contentbox') {
					$html .= $this->wrapper($o, $col, '', $this->contentbox($o, $this->rows($it['children'] ?? ['rows' => []])));
				} else {
					$html .= $this->wrapper($o, $col . ' nuCell', '', $this->object($o, 'flow'));
				}
			}
			$html .= '</div>';
		}
		return $html;
	}

	private function wrapper(array $o, string $class, string $style, string $inner): string
	{
		$hidden = NuRecord::hiddenFor($o);
		if ($hidden) $style .= ($style !== '' ? ';' : '') . 'display:none';
		return '<div id="nuw_' . h($o['sob_all_id']) . '" class="nuWrap nuWrap_' . h($o['sob_all_type']) . ' ' . $class . '"'
			. ' data-nu-object-id="' . h($o['zzzzsys_object_id']) . '"' . ($style !== '' ? ' style="' . h($style) . '"' : '') . '>' . $inner . '</div>';
	}

	// ================================================================= objects

	/** Label + control of a main-form object. */
	private function object(array $o, string $mode): string
	{
		$type = (string)$o['sob_all_type'];
		$id   = (string)$o['sob_all_id'];
		if (NuFieldAccess::withheld($o)) return '';              // field data access (0.8.480): no label, no control, no value
		$value = $this->valueOf($o);

		$this->clientObjects[$id] = [
			'type' => $type, 'object_id' => $o['zzzzsys_object_id'], 'input' => (string)$o['sob_input_type'],
			'format' => $this->formatOf($o), 'cloneable' => (string)$o['sob_all_cloneable'] !== '0', 'label' => trim(strip_tags((string)$o['sob_all_label'])),
		];
		// globeadmin: what the label menu (quick changes of the object) shows as current values
		if (!empty(NuAuth::user()['globeadmin'])) {
			$this->clientObjects[$id] += ['access' => (string)$o['sob_all_access'], 'align' => (string)$o['sob_all_align'], 'validate' => (string)$o['sob_all_validate'],
				'tab' => (string)$o['sob_all_zzzzsys_tab_id']];
		}

		if ($type === 'contentbox') return $this->contentbox($o, '');
		if ($type === 'subform') return $this->label($o, $mode, true) . $this->subform($o);

		$control = $this->control($o, '', $value, $mode === 'absolute' ? LayoutSize::controlStyle($o) : LayoutSize::flowStyle($o));
		$noLabel = in_array($type, ['word', 'html', 'run'], true) || ($type === 'input' && in_array($o['sob_input_type'], ['button'], true));
		if ($type === 'input' && $o['sob_input_type'] === 'checkbox' && $mode !== 'absolute') {
			return '<div class="form-check nuCheckWrap">' . $control . $this->label($o, 'check') . '</div>';
		}
		return ($noLabel ? ($mode === 'absolute' ? '' : '<div class="nuLabelSpacer"></div>') : $this->label($o, $mode)) . $control;
	}

	/** Labels nuBuilder Next does not show (nuBuilder's own forms stay unchanged in the database): form id => object ids. */
	private const HIDDEN_LABELS = ['nunotes' => ['not_content']];

	private function label(array $o, string $mode, bool $isTitle = false): string
	{
		$text = (string)$o['sob_all_label'];
		if (trim($text) === '') return '';
		if (in_array((string)$o['sob_all_id'], self::HIDDEN_LABELS[$this->meta->id] ?? [], true)) return '';   // e.g. "Content" above the note editor
		$id  = (string)$o['sob_all_id'];
		$for = $o['sob_all_type'] === 'lookup' ? $id . 'code' : $id;
		$cls = match ($mode) {
			'absolute' => $isTitle ? 'nuLabel nuLabelTop' : 'nuLabel nuLabelLeft',
			'check'    => 'form-check-label nuLabel',
			default    => 'form-label nuLabel',
		};
		$marks = $o['sob_all_type'] !== 'subform' ? $this->marks($o) : '';
		return '<label id="label_' . h($id) . '" for="' . h($for) . '" class="' . $cls . '">' . $text . $marks . '</label>';
	}

	// ================================================================= validation markers (sob_all_validate) – display only


	/** 1 = no blanks, 2 = no duplicates, 3 = both: [required, unique]. */
	private static function rules(array $o): array
	{
		$v = (string)$o['sob_all_validate'];
		return [in_array($v, ['1', '3'], true), in_array($v, ['2', '3'], true)];
	}

	private static function marksOn(): bool
	{
		return nu_cfg('nuConfigValidationMarkers') !== false;
	}

	/**
	 * Red * (no blanks, title "Required field") and a key (no duplicates, 0.8.627 – before a blue ◆) after a label; the key has its own
	 * tooltip (nu_unique_mark: unique / required and unique) and is focusable. Screen readers get "required" and the key's name. Not on
	 * forms the user cannot edit. The validation itself (NuRecord::validate) is unchanged.
	 */
	private function marks(array $o): string
	{
		if (!self::marksOn() || !$this->editable() || in_array($o['sob_all_type'], ['contentbox', 'subform', 'word', 'html', 'run'], true)) return '';
		[$req, $uni] = self::rules($o);
		if (!$req && !$uni) return '';
		$out = '';
		if ($req) $out .= '<span class="nuMark nuMarkRequired" title="' . h(t('val_required')) . '" aria-hidden="true">*</span><span class="visually-hidden"> (' . h(t('val_required_sr')) . ')</span>';
		if ($uni) $out .= nu_unique_mark($req);
		return '<span class="nuMarks">' . $out . '</span>';
	}

	private function ariaRequired(array $o, bool $ro): string
	{
		$editableType = in_array($o['sob_all_type'], ['input', 'textarea', 'select', 'lookup'], true) && (string)$o['sob_input_type'] !== 'button';
		return self::marksOn() && !$ro && $editableType && self::rules($o)[0] ? ' aria-required="true"' : '';
	}

	private function contentbox(array $o, string $inner): string
	{
		$classes = $this->styleClasses($o);
		$title   = trim((string)$o['sob_all_label']);
		return '<div id="' . h($o['sob_all_id']) . '" class="nuContentBoxContainer ' . h($classes['content']) . '"' . $this->styleAttr($o) . '>'
			. ($title !== '' ? '<div class="nuContentBoxTitle ' . h($classes['title']) . '" id="label_' . h($o['sob_all_id']) . '">' . $title . '</div>' : '')
			. ($inner !== '' ? '<div class="nuContentBoxBody">' . $inner . '</div>' : '') . '</div>';
	}

	/**
	 * The control itself. $prefix is the subform row prefix ("sub_dates000") or ''.
	 */
	public function control(array $o, string $prefix, mixed $value, string $sizeStyle, ?string $events = null): string
	{
		$type  = (string)$o['sob_all_type'];
		$field = (string)$o['sob_all_id'];
		$id    = $prefix . $field;
		$acc   = $this->access($o);
		$events ??= $this->eventsOf($o, $prefix !== '' ? 'sub' : 'main');
		$align = in_array($o['sob_all_align'], ['right', 'center'], true) ? 'text-align:' . $o['sob_all_align'] . ';' : '';
		$style = $sizeStyle . $align . (($o['sob_all_style_type'] ?? '') === 'CSS' ? (string)($o['sob_all_style'] ?? '') : '');
		$cls   = 'nuObject nu_' . $type . (($o['sob_all_style_type'] ?? '') === 'Class' && $type !== 'contentbox' ? ' ' . ($o['sob_all_style'] ?? '') : '');
		$common = ' id="' . h($id) . '" data-nu-field="' . h($field) . '" data-nu-prefix="' . h($prefix) . '" data-nu-object-id="' . h($o['zzzzsys_object_id']) . '"'
			. ' data-nu-type="' . h($type) . '"' . ($o['sob_all_validate'] ? ' data-nu-validate="' . h($o['sob_all_validate']) . '"' : '')
			. $this->attributes($o) . ($type === 'lookup' ? '' : $events) . ($style !== '' && $type !== 'lookup' ? ' style="' . h($style) . '"' : '');
		$ro = $acc['readonly'];
		if ($type !== 'lookup') $common .= $this->ariaRequired($o, $ro);
		// field data access (0.8.480): a masked field is a locked text with the mask only – the value never reaches the page
		if (NuFieldAccess::masked($o)) {
			$mStyle = $style !== '' && $type === 'lookup' ? ' style="' . h($style) . '"' : '';
			return '<input type="text" class="form-control nuReadonly nuMasked ' . h($cls) . '"' . $common . $mStyle . ' readonly tabindex="-1" data-nu-masked="1" value="'
				. h(NuFieldAccess::maskValue($type === 'display' ? $this->display($o) : $value, NuFieldAccess::maskOf($o))) . '">';
		}

		switch ($type) {
			case 'input':
				return $this->input($o, $id, $value, $common, $cls, $ro);

			case 'textarea':
				return '<textarea class="form-control ' . h($cls) . '"' . $common . ($ro ? ' readonly' : '') . '>' . h($value) . '</textarea>';

			case 'editor':
				// nuBuilder WYSIWYG (TinyMCE): the textarea holds the HTML, TinyMCE runs in #<id>_container
				return '<div class="nuEditorWrap" id="' . h($id) . '_parent_container">'
					. '<textarea class="form-control nuEditor ' . h($cls) . '"' . $common . ($ro ? ' readonly data-nu-readonly="1"' : '') . '>' . h($value) . '</textarea>'
					. '<div class="nuTinyMCE" id="' . h($id) . '_container"></div></div>';

			case 'select':
				$multi = (string)$o['sob_select_multiple'] === '1';
				$sel   = $multi ? (json_decode((string)$value, true) ?: array_filter(explode(',', (string)$value), static fn($s) => (string)$s !== '')) : [(string)$value];
				$opts  = $multi ? '' : '<option value=""></option>';
				$found = false;
				foreach ($this->options($o) as [$v, $t]) {
					$is = in_array((string)$v, array_map('strval', (array)$sel), true);
					$found = $found || $is;
					$opts .= '<option value="' . h($v) . '"' . ($is ? ' selected' : '') . '>' . h($t) . '</option>';
				}
				if (!$multi && !$found && (string)$value !== '') $opts .= '<option value="' . h($value) . '" selected>' . h($value) . '</option>';
				$ts = $multi || (string)$o['sob_select_2'] === '1' ? ' nuSelect2' : '';
				return '<select class="form-select ' . h($cls) . $ts . '"' . $common . ($multi ? ' multiple' : '') . ($ro ? ' disabled data-nu-readonly="1"' : '') . '>' . $opts . '</select>';

			case 'display':
				return '<input type="text" class="form-control nuReadonly ' . h($cls) . '"' . $common . ' readonly tabindex="-1" value="' . h($this->display($o)) . '">';

			case 'calc':
				$fmt = (string)$o['sob_calc_format'];
				// nuBuilder (nuform.js nuCALC): in a sub-form row nuTotal('qty') means this row's field – "al('" gets the row prefix
				$formula = $prefix !== '' ? str_replace("al('", "al('" . $prefix, (string)$o['sob_calc_formula']) : (string)$o['sob_calc_formula'];
				return '<input type="text" class="form-control nuReadonly nuCalculator ' . h(trim($cls . ' ' . NuFormat::cssClass($value, $fmt))) . '"' . $common . ' readonly tabindex="-1"'
					. ' data-nu-formula="' . h($formula) . '" data-nu-format="' . h($fmt) . '" data-nu-calc-order="' . (int)$o['sob_all_order'] . '"'
					. ' value="' . h(NuFormat::format($value, $fmt)) . '">';

			case 'lookup':
				return $this->lookup($o, $prefix, $value, $common, $cls, $ro, $sizeStyle, $events);

			case 'word':
				return '<div class="nuWord ' . h($cls) . '"' . $common . self::iconAttr($o) . '>' . self::withIcon((string)$o['sob_all_label'], $o) . '</div>';

			case 'html':
				return '<div class="nuHtml ' . h($cls) . '"' . $common . '>' . self::deferScripts(NuHash::replace((string)$o['sob_html_code'], $this->pageHash, NuHash::HTML)) . '</div>';

			case 'run':
				return $this->run($o, $common, $cls);

			case 'chart':
				return $this->chart($o, $common, $cls);

			case 'image':
				$src = nu_db()->tableExists('zzzzsys_file') ? self::fileDataUrl((string)nu_db()->value('SELECT sfi_json FROM zzzzsys_file WHERE zzzzsys_file_id = ?', [(string)$o['sob_image_zzzzsys_file_id']])) : '';
				return '<img class="nuImage ' . h($cls) . '"' . $common . ' alt="' . h(strip_tags((string)$o['sob_all_label'])) . '"' . ($src !== '' && str_starts_with($src, 'data:image/') ? ' src="' . h($src) . '"' : '') . '>';
		}
		return '<div class="nuUnsupported"' . $common . '>' . h($type) . '</div>';
	}

	private function input(array $o, string $id, mixed $value, string $common, string $cls, bool $ro): string
	{
		$it  = (string)($o['sob_input_type'] ?: 'text');
		$fmt = $this->formatOf($o);
		switch ($it) {
			case 'button':
				// a <button> with the label as HTML in .nuButtonLabel and the object's icon inside, as nuBuilder 4.9 (nuINPUT, 0.8.559 –
				// before: <input type="button"> with the label as plain text, no icon)
				return '<button type="button" class="btn nuFormBtn nuButton ' . h($cls) . '"' . $common . self::iconAttr($o) . ' data-nu-org-label="' . h((string)$o['sob_all_label']) . '"'
					. self::nameIfIconOnly((string)$o['sob_all_label'], '') . ($ro ? ' disabled' : '') . '>'
					. self::withIcon('<span class="nuButtonLabel">' . (string)$o['sob_all_label'] . '</span>', $o, (string)$o['sob_all_label']) . '</button>';
			case 'checkbox':
				return '<input type="checkbox" class="form-check-input ' . h($cls) . '"' . $common . ' value="1"' . ((string)$value === '1' ? ' checked' : '') . ($ro ? ' disabled data-nu-readonly="1"' : '') . '>';
			case 'nuDate':
				return '<input type="text" class="form-control nuDate ' . h($cls) . '"' . $common . ' autocomplete="off" data-nu-format="' . h($fmt) . '"'
					. ($ro ? ' readonly' : ' onclick="nuPopupCalendar(this);"') . ' value="' . h(NuFormat::format($value, $fmt)) . '">';
			case 'nuNumber':
				return '<input type="text" class="form-control nuNumber ' . h(trim($cls . ' ' . NuFormat::cssClass($value, $fmt))) . '"' . $common . ' inputmode="decimal" autocomplete="off" data-nu-format="' . h($fmt) . '"'
					. ($ro ? ' readonly' : '') . ' value="' . h(NuFormat::format($value, $fmt)) . '">';
			case 'nuAutoNumber':
				return '<input type="text" class="form-control nuReadonly ' . h($cls) . '"' . $common . ' readonly value="' . h($value) . '"'
					. ((string)$value === '' && $this->recordId === '-1' ? ' placeholder="' . h(t('form_autonumber_new')) . '"' : '') . '>';
			case 'nuScroll':
				return '<input type="text" class="form-control nuScroll ' . h($cls) . '"' . $common . ' data-nu-scroll="' . h($o['sob_input_javascript']) . '"' . ($ro ? ' readonly' : '')
					. (str_contains($common, ' title="') ? '' : ' title="' . h(t('form_scroll_hint')) . '"') . ' value="' . h($value) . '">';   // nuBuilder's hint (0.8.369)
			case 'file':
				if (self::isUppy($o)) return $this->uppyField($o, $id, $common, $cls);   // target "File System" (0.8.459)
				return $this->fileField($o, $id, (string)$value, $common, $ro);
		}
		$allowed = ['text', 'number', 'date', 'datetime-local', 'email', 'month', 'password', 'range', 'search', 'tel', 'time', 'url', 'week', 'color'];
		$type    = in_array($it, $allowed, true) ? $it : 'text';
		if ($type === 'datetime-local' && $value) $value = str_replace(' ', 'T', substr((string)$value, 0, 16));
		if ($type === 'date' && $value) $value = substr((string)$value, 0, 10);
		$list = trim((string)($o['sob_input_datalist'] ?? '')) !== '' ? ' list="nuDatalist_' . h($id) . '"' : '';
		$mask = str_starts_with($fmt, 'T|') && in_array($type, ['text', 'tel', 'search'], true)
			? ' data-nu-format="' . h($fmt) . '" placeholder="' . h(self::maskPlaceholder(substr($fmt, 2))) . '"' : '';
		if ($mask !== '') $value = NuFormat::format($value, $fmt);
		return '<input type="' . $type . '" class="form-control' . ($type === 'color' ? ' form-control-color' : '') . ' ' . h($cls) . '"' . $common . $list . $mask . ' autocomplete="off"'
			. ($type === 'number' ? ' step="any"' : '') . ($ro ? ' readonly' : '') . ' value="' . h($value) . '">' . $this->datalist($o, $id);
	}

	/** nuBuilder's file object with target "File System" (sob_input_file_target 1): uploaded with Uppy, nothing stored in the record. */
	public static function isUppy(array $o): bool
	{
		return ($o['sob_all_type'] ?? '') === 'input' && ($o['sob_input_type'] ?? '') === 'file' && (string)($o['sob_input_file_target'] ?? '0') === '1';
	}

	/**
	 * File object with target "File System" (0.8.459) as nuBuilder's nuINPUTfileFileSystem: a div with the object's HTML code – usually
	 * the Uppy template (snippet nu_upload_file_uppy_code_template) – after nuBuilder's replacements: #uppy_div# → <id>_uppy_div,
	 * #this_object_id# → <id>, nuInitUppy() → nuInitUppy_<id>() (several objects on one form), .cssNumber( → .nuCSSNumber(,
	 * new Uppy.Core() → new Uppy.Uppy() (Uppy 1 code). Its scripts run like those of HTML objects (deferScripts).
	 */
	private function uppyField(array $o, string $id, string $common, string $cls): string
	{
		$html = strtr((string)$o['sob_html_code'], ['#uppy_div#' => $id . '_uppy_div', '#this_object_id#' => $id, 'nuInitUppy()' => 'nuInitUppy_' . $id . '()',
			'.cssNumber(' => '.nuCSSNumber(', 'new Uppy.Core()' => 'new Uppy.Uppy()']);
		$common = (string)preg_replace('/ data-nu-field="[^"]*"/', '', $common, 1);   // nothing of it is a field of the record (not saved, never "edited")
		return '<div class="nuFileUppy ' . h($cls) . '"' . $common . '>' . self::deferScripts(NuHash::replace($html, $this->hash, NuHash::HTML)) . '</div>';
	}

	/**
	 * File stored in the field like nuBuilder: JSON {"file": base64(dataURL), "name", "size", "type"}.
	 * #<id> (hidden textarea) holds the JSON, #<id>_file is the file picker.
	 */
	private function fileField(array $o, string $id, string $value, string $common, bool $ro): string
	{
		$info = '';
		$d = json_decode($value, true);
		if (is_array($d) && isset($d['file'])) {
			$url  = self::fileDataUrl($value);
			$name = (string)($d['name'] ?? 'file');
			$img  = str_starts_with((string)($d['type'] ?? ''), 'image/') && str_starts_with($url, 'data:image/');
			$info = ($img ? '<img class="nuFileThumb" src="' . h($url) . '" alt="">' : '<i class="bi bi-file-earmark fs-4 text-body-secondary"></i>')
				. '<span class="nuFileName text-truncate">' . h($name) . '</span><span class="small text-body-secondary">' . h(self::fileSize((int)($d['size'] ?? 0))) . '</span>'
				. ($url !== '' ? '<a class="btn btn-sm btn-outline-secondary nuFileDownload" href="' . h($url) . '" download="' . h($name) . '" title="' . h(t('file_download')) . '" aria-label="' . h(t('file_download')) . '"><i class="bi bi-download"></i></a>' : '');
		}
		$remove = $ro ? '' : '<button type="button" class="btn btn-sm btn-outline-danger nuFileRemove" title="' . h(t('remove')) . '" aria-label="' . h(t('remove')) . '"><i class="bi bi-x-lg"></i></button>';
		return '<div class="nuFileField" data-nu-file-for="' . h($id) . '">'
			. '<textarea class="d-none nuFileData"' . $common . '>' . h($value) . '</textarea>'
			. '<div class="nuFileInfo' . ($info === '' ? ' d-none' : '') . '">' . $info . $remove . '</div>'
			. ($ro ? '' : '<input type="file" class="form-control form-control-sm nuFileInput" id="' . h($id) . '_file" data-nu-prefix="' . h(substr($id, 0, strlen($id) - strlen((string)$o['sob_all_id']))) . '" aria-label="' . h(strip_tags((string)$o['sob_all_label']) ?: t('file_choose')) . '">')
			. '</div>';
	}

	/**
	 * nuBuilder inserts HTML objects with jQuery after its libraries are loaded, so their <script> may use $ and every nu… function.
	 * Here the HTML is part of the page: its JavaScript scripts get type="text/nu-deferred" and run in order once the runtime has
	 * loaded (nuNextRunDeferred in assets/js/nu/nuruntime.js, before the form's own JavaScript). Other script types stay as they are.
	 */
	public static function deferScripts(string $html): string
	{
		if (stripos($html, '<script') === false) return $html;
		return (string)preg_replace_callback('/<script\b([^>]*)>/i', static function (array $m): string {
			$attrs = $m[1];
			if (preg_match('/\btype\s*=\s*(["\']?)([^"\'\s>]*)\1/i', $attrs, $t)) {
				$type = strtolower(trim($t[2]));
				if (!in_array($type, ['', 'text/javascript', 'application/javascript', 'module'], true)) return $m[0];
				$attrs = str_replace($t[0], 'data-nu-type="' . h($type) . '"', $attrs);
			}
			return '<script type="text/nu-deferred"' . $attrs . '>';
		}, $html);
	}

	/** data: URL from a nuBuilder file JSON (its "file" is base64 of the data URL). */
	public static function fileDataUrl(string $json): string
	{
		$d = json_decode($json, true);
		if (!is_array($d) || !isset($d['file'])) return '';
		$f = (string)$d['file'];
		if (str_starts_with($f, 'data:')) return $f;
		$dec = base64_decode($f, true);
		return ($dec !== false && str_starts_with($dec, 'data:')) ? $dec : '';
	}

	private static function fileSize(int $bytes): string
	{
		if ($bytes <= 0) return '';
		return $bytes < 1024 ? $bytes . ' B' : ($bytes < 1048576 ? round($bytes / 1024) . ' KB' : round($bytes / 1048576, 1) . ' MB');
	}

	/** Width of a lookup's description: 0 = hidden (as nuBuilder: visibility hidden), empty = 150. */
	public static function lookupDescWidth(array $o): int
	{
		$w = trim((string)($o['sob_lookup_description_width'] ?? ''));
		return $w === '' ? 150 : max(0, (int)$w);
	}

	private function lookup(array $o, string $prefix, mixed $value, string $common, string $cls, bool $ro, string $sizeStyle, string $events): string
	{
		$id    = $prefix . $o['sob_all_id'];
		[$code, $desc] = $this->lookupDisplay($o, (string)$value);
		$descW = self::lookupDescWidth($o);
		$codeW = (int)$o['sob_all_width'];
		$attrs = ' data-nu-prefix="' . h($prefix) . '" data-nu-object-id="' . h($o['zzzzsys_object_id']) . '" data-nu-target="' . h($id) . '"'
			. ' data-nu-lookup-form="' . h($o['sob_lookup_zzzzsys_form_id']) . '"';
		$hidden = '<input type="hidden" class="nuLookupId"' . $common . ' value="' . h($value) . '">';
		$flow  = $sizeStyle === '' || str_contains($sizeStyle, '100%');
		return $hidden . '<div class="input-group input-group-sm nuLookupGroup' . ($flow ? '' : ' nuLookupFixed') . '">'
			. '<input type="text" class="form-control nuLookupCode ' . h($cls) . '" id="' . h($id) . 'code" data-nu-field="' . h($o['sob_all_id']) . 'code"' . $attrs
			. $events . $this->ariaRequired($o, $ro) . ' autocomplete="off"' . ($flow ? ' style="max-width:' . max(70, $codeW) . 'px"' : ' style="width:' . $codeW . 'px;flex:none"') . ($ro ? ' readonly' : '') . ' value="' . h($code) . '">'
			. '<button type="button" class="btn btn-outline-secondary nuLookupButton" id="' . h($id) . 'button"' . $attrs . ($ro ? ' disabled' : '') . ' aria-label="' . h(t('form_lookup_search')) . '"><i class="bi bi-search"></i></button>'
			. '<input type="text" class="form-control nuLookupDescription nuReadonly' . ($descW === 0 ? ' d-none' : '') . '" id="' . h($id) . 'description" data-nu-field="' . h($o['sob_all_id']) . 'description"' . $attrs
			. ' readonly tabindex="-1"' . ($flow || $descW === 0 ? '' : ' style="width:' . $descW . 'px;flex:none"') . ' value="' . h($desc) . '">'
			. '</div>';
	}

	/**
	 * nuBuilder "run" object: a button (or iframe) that opens a form or runs a procedure.
	 *   sob_run_zzzzsys_form_id  form id, procedure id (zzzzsys_php) or report id
	 *   sob_run_type             F form, P procedure, R report ('' = detected from the id)
	 *   sob_run_id               record id: '' = browse, -1 = new record, value or #hash# = that record
	 *   sob_run_filter           browse filter (always-applied search words)
	 *   sob_run_method           b button, i iframe
	 *   sob_run_target           0 new breadcrumb, 1 current breadcrumb, 2 new tab, 3 popup
	 */
	/**
	 * nuBuilder's object icon (sob_input_icon, nuAddInputIcon – 0.8.559) inside input buttons, run buttons and words: "fa …" / "bi …" = an
	 * icon class, anything else = HTML (the developer's, like the label); "left|right" = one before and one after the label. With
	 * visible text a non-breaking space separates them (as nuBuilder's nuAttachFontAwesome / nuAttachHTML).
	 */
	public static function withIcon(string $inner, array $o, ?string $label = null): string
	{
		$icon = trim((string)($o['sob_input_icon'] ?? ''));
		if ($icon === '') return $inner;
		$parts = explode('|', $icon, 2);
		$hasText = trim(html_entity_decode(strip_tags($label ?? $inner), ENT_QUOTES, 'UTF-8')) !== '';
		$one = static function (string $p) : string {
			$p = trim($p);
			if ($p === '') return '';
			if (preg_match('/^(fa|bi)[\w\s-]*$/', $p)) {
				$cls = preg_match('/^bi-/', $p) ? 'bi ' . $p : $p;                       // "bi-table" as nuBuilder Next's nuAddInputIcon
				return '<i class="nuObjIcon fa-width-auto ' . h($cls) . '" aria-hidden="true"></i>';
			}
			return '<span class="nuObjIcon">' . $p . '</span>';
		};
		$l = $one($parts[0]);
		$r = $one($parts[1] ?? '');
		$sep = $hasText ? '&nbsp;' : '';
		return ($l !== '' ? $l . $sep : '') . $inner . ($r !== '' ? $sep . $r : '');
	}

	/** nuBuilder's nu-data-icon attribute (form code reads it). */
	private static function iconAttr(array $o): string
	{
		$icon = trim((string)($o['sob_input_icon'] ?? ''));
		return $icon !== '' ? ' nu-data-icon="' . h($icon) . '"' : '';
	}

	/** A button without visible text (only an icon, or nothing): aria-label + title, from $name (empty: nothing). */
	private static function nameIfIconOnly(string $label, string $name): string
	{
		if (trim(html_entity_decode(strip_tags($label), ENT_QUOTES, 'UTF-8')) !== '' || $name === '') return '';
		return ' aria-label="' . h($name) . '" title="' . h($name) . '"';
	}

	public static function runInfo(array $o, array $hash): array
	{
		$target = trim((string)$o['sob_run_zzzzsys_form_id']);
		$type   = strtoupper(trim((string)($o['sob_run_type'] ?? '')));
		$db     = nu_db();
		$php    = $target !== '' && $type !== 'F' && $type !== 'R' ? $db->one('SELECT zzzzsys_php_id, sph_code, sph_run FROM zzzzsys_php WHERE zzzzsys_php_id = ?', [$target]) : null;
		if ($type === '' || $type === 'P') $type = $php ? 'P' : ($type === 'P' ? 'P' : '');
		if ($type === '' && $target !== '' && $db->tableExists('zzzzsys_report') && $db->value('SELECT COUNT(*) FROM zzzzsys_report WHERE zzzzsys_report_id = ?', [$target])) $type = 'R';
		if ($type === '') $type = 'F';
		$method = (string)$o['sob_run_method'];
		$runTarget = (string)($o['sob_run_target'] ?? '');
		if ($method === 'w') { $method = 'b'; $runTarget = '2'; }       // nuBuilder Next 0.2 "window" option
		return [
			'type'   => $type,
			'form'   => $type === 'F' ? $target : '',
			'code'   => $type === 'P' ? (string)($php['sph_code'] ?? $target) : ($type === 'R' ? $target : ''),
			'hidden' => $type === 'P' && strtolower((string)($php['sph_run'] ?? '')) === 'hide',
			'record' => NuHash::replace((string)($o['sob_run_id'] ?? ''), $hash, NuHash::RAW),
			'filter' => NuHash::replace((string)($o['sob_run_filter'] ?? ''), $hash, NuHash::RAW),
			'method' => $method === 'i' ? 'i' : 'b',
			'target' => in_array($runTarget, ['0', '1', '2', '3'], true) ? $runTarget : '0',
		];
	}

	/** nuBuilder's chart types (sob_html_chart_type) → Google Charts type, series type, stacked (as nuBuilder's nuform.php). */
	public const CHART_TYPES = [
		'p'   => ['PieChart', 'pie', false],
		'l'   => ['ComboChart', 'lines', false],
		'b'   => ['ComboChart', 'bars', false],
		'bs'  => ['ComboChart', 'bars', true],
		'bh'  => ['BarChart', 'bars', false],
		'bhs' => ['BarChart', 'bars', true],
	];

	/**
	 * Chart object: a box that nuChart() (assets/js/nu/nuruntime.js) fills with a Google chart when the form is shown.
	 * sob_html_javascript = the data: name of a JavaScript array (set in the form's JS or with nuAddJavascript) or an array literal.
	 * Title and axis labels may contain hash variables. (nuBuilder reads the vertical label from a misspelt column – here it works.)
	 */
	private function chart(array $o, string $common, string $cls): string
	{
		$t = self::CHART_TYPES[(string)($o['sob_html_chart_type'] ?? '')] ?? null;
		$attr = $t ? ' data-nu-chart-type="' . h($t[0]) . '" data-nu-chart-series="' . h($t[1]) . '" data-nu-chart-stacked="' . ($t[2] ? '1' : '0') . '"'
			. ' data-nu-chart-data="' . h(trim((string)($o['sob_html_javascript'] ?? ''))) . '"'
			. ' data-nu-chart-title="' . h(NuHash::replace((string)($o['sob_html_title'] ?? ''), $this->hash)) . '"'
			. ' data-nu-chart-htitle="' . h(NuHash::replace((string)($o['sob_html_horizontal_label'] ?? ''), $this->hash)) . '"'
			. ' data-nu-chart-vtitle="' . h(NuHash::replace((string)($o['sob_html_vertical_label'] ?? ''), $this->hash)) . '"' : '';
		return '<div class="nuChart ' . h($cls) . '"' . $common . $attr . ' role="img" aria-label="' . h(strip_tags((string)($o['sob_html_title'] ?? '') ?: (string)$o['sob_all_label'])) . '">'
			. '<div class="nuChartMsg"><span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>' . h(t('chart_loading')) . '</div></div>';
	}

	private function run(array $o, string $common, string $cls): string
	{
		$label = strip_tags((string)$o['sob_all_label']);
		$run   = self::runInfo($o, $this->hash);
		// a page form (0.8.387) in an iframe: its own output directly, with the opening form and record (pf / pr)
		if ($run['method'] === 'i' && $run['type'] === 'F' && $run['form'] !== '' && ($pm = NuFormMeta::load($run['form'])) && $pm->isPage()) {
			$src = NuPage::rawUrl($pm, ['r' => $run['record'], 'ps' => self::$scope ?: null, 'pf' => $this->meta->id, 'pr' => $this->recordId !== '' && $this->recordId !== '-1' ? $this->recordId : null]);
			return '<iframe class="nuIframe nuPageFrame ' . h($cls) . '"' . $common . ' src="' . h($src) . '" title="' . h($label ?: $pm->description()) . '" data-nu-height="fixed"></iframe>';
		}
		if ($run['method'] === 'i' && $run['type'] === 'F' && $run['form'] !== '' && NuFormMeta::load($run['form'])) {
			$src = nu_url('form', ['f' => $run['form'], 'r' => $run['record'], 'rf' => $run['filter'], 'iframe' => '1', 'ps' => self::$scope ?: null]);
			return '<iframe class="nuIframe ' . h($cls) . '"' . $common . ' src="' . h($src) . '" title="' . h($label ?: 'iframe') . '"></iframe>';
		}
		if ($run['method'] === 'i' && $run['type'] === 'R' && $run['code'] !== '') {    // nuBuilder: the report's launch form, else the PDF
			$src = nu_url('report', ['c' => $run['code'], 'iframe' => '1']);
			return '<iframe class="nuIframe ' . h($cls) . '"' . $common . ' src="' . h($src) . '" title="' . h($label ?: t('rpt_title')) . '"></iframe>';
		}
		if ($run['method'] === 'i' && $run['type'] === 'P' && $run['code'] !== '') {
			return '<iframe class="nuIframe nuIframeProcedure ' . h($cls) . '"' . $common . ' srcdoc="' . h($this->iframeProcedure($o, $run['code'])) . '"'
				. ' data-nu-run-code="' . h($run['code']) . '" title="' . h($label ?: $run['code']) . '"></iframe>';
		}
		if ($run['type'] === 'F' && $run['form'] !== '' && $run['target'] === '0' && NuPage::opensInTab($run['form'])) $run['target'] = '2';   // page forms (0.8.387): new tab
		$data = ' data-nu-run-type="' . h($run['type']) . '" data-nu-run-form="' . h($run['form']) . '" data-nu-run-code="' . h($run['code']) . '"'
			. ' data-nu-run-record="' . h($run['record']) . '" data-nu-run-filter="' . h($run['filter']) . '" data-nu-run-target="' . h($run['target']) . '"'
			. ($run['hidden'] ? ' data-nu-run-hidden="1"' : '');
		// the label is HTML as in nuBuilder (nuRUN: .html(label)) – e.g. only an icon <i class="fa fa-table"></i> (0.8.558: before, the tags
		// were stripped and an icon-only button showed the target's id); the id / code only when the label is really empty
		// an empty label gives an empty button as in nuBuilder (0.8.559 – 0.8.558 showed the target's description); the object's icon
		// (sob_input_icon) goes inside. Without visible text the target's description is the name for screen readers and the tooltip.
		// No icon of our own any more (0.8.621, as nuBuilder Forte: the ▶ / page / ⧉ icons took room, labels wrapped); a new tab or a
		// popup is said in the tooltip instead (data-nu-run-hint – nuruntime.js adds the label when it is cut off).
		$raw = (string)$o['sob_all_label'];
		$fallback = $run['type'] === 'F' && $run['form'] !== '' && ($fm = NuFormMeta::load($run['form'])) ? $fm->description() : ($run['form'] ?: $run['code']);
		$hint = ['2' => t('run_hint_tab'), '3' => t('run_hint_popup')][$run['target']] ?? '';
		$name = self::nameIfIconOnly($raw, $fallback);
		if ($hint !== '' && !str_contains($common, ' title="'))                 // an own title (object attributes) stays
			$name = $name !== '' ? ' aria-label="' . h($fallback) . '" title="' . h($fallback . ' – ' . $hint) . '"' : ' title="' . h($hint) . '"';
		return '<button type="button" class="btn nuFormBtn nuRunButton ' . h($cls) . '"' . $common . $data . self::iconAttr($o) . $name
			. ($hint !== '' ? ' data-nu-run-hint="' . h($hint) . '"' : '') . '>'
			. self::withIcon('<span class="nuButtonLabel">' . $raw . '</span>', $o, $raw) . '</button>';
	}

	/**
	 * Output of a procedure shown in an iframe run object (nuBuilder: nurunphp.php in the iframe) – run while the form is rendered,
	 * with the form's hash variables, access rights checked as for a run button. Errors appear inside the iframe.
	 */
	private function iframeProcedure(array $o, string $code): string
	{
		$hash = ['OBJECT_ID' => (string)$o['zzzzsys_object_id']] + $this->hash;
		$r = NuPhp::isolated(static fn() => NuPhp::procedure($code, $hash));
		$err = !($r['result']['ok'] ?? false) ? (string)($r['result']['error'] ?? '') : ($r['errors'][0]['message'] ?? null);
		if ($err === null) return $r['output'];
		error_log('nuBuilder Next iframe procedure ' . $o['sob_all_id'] . ': ' . $err);
		return '<!doctype html><meta charset="utf-8"><body style="font:14px system-ui,sans-serif;color:#842029;background:#f8d7da;margin:0;padding:.75rem">' . h($err) . '</body>';
	}

	// ================================================================= subform grid

	private function subform(array $o): string
	{
		$sub = NuFormMeta::load((string)$o['sob_subform_zzzzsys_form_id']);
		$sf  = (string)$o['sob_all_id'];
		if (!$sub) return '<div class="alert alert-warning" id="' . h($sf) . '">' . h(t('form_err_subform_missing', (string)$o['sob_subform_zzzzsys_form_id'])) . '</div>';

		$cols = array_values(array_filter($sub->objects, static fn($so) => !in_array($so['sob_all_type'], ['contentbox', 'subform'], true) && !NuFieldAccess::withheld($so)));   // withheld: no column (0.8.480)
		usort($cols, static fn($a, $b) => [(int)$a['sob_all_order'], (int)$a['sob_all_left']] <=> [(int)$b['sob_all_order'], (int)$b['sob_all_left']]);
		$canAdd    = (string)$o['sob_subform_add'] !== '0' && $this->editable();     // a read-only record (no save right): no blank row, no delete boxes (review D6)
		$canDelete = (string)$o['sob_subform_delete'] !== '0' && $this->editable();
		$sfEvents  = $this->meta->events[$o['zzzzsys_object_id']] ?? [];

		$head = '';
		foreach ($cols as $c) {
			$hidden = NuRecord::hiddenFor($c);
			$w = max(30, (int)$c['sob_all_width'] + ($c['sob_all_type'] === 'lookup' ? self::lookupDescWidth($c) + 40 : 0));
			$head .= '<th id="title_' . h($sf . $c['sob_all_id']) . '" class="nuSubformTitle" data-nu-field="' . h($c['sob_all_id']) . '" style="min-width:' . $w . 'px;width:' . $w . 'px' . ($hidden ? ';display:none' : '') . '">'
				. (string)$c['sob_all_label'] . $this->marks($c) . '</th>';
		}
		if ($canDelete) $head .= '<th class="nuSubformDeleteTitle" title="' . h(t('delete')) . '"><i class="bi bi-trash"></i></th>';

		$isForm = strtolower(trim((string)$o['sob_subform_type'])) === 'f';
		$rowFn  = $isForm
			? fn(string $row, ?array $data) => $this->subformFormRow($sub, $sf, $cols, $row, $data, $canDelete, $sfEvents)
			: fn(string $row, ?array $data) => $this->subformRow($sub, $sf, $cols, $row, $data, $canDelete, $sfEvents);

		$rows = NuRecord::subformRows($o, $this->recordId);
		$body = '';
		foreach ($rows as $i => $r) $body .= $rowFn(sprintf('%03d', $i), $r);
		if ($canAdd) $body .= $rowFn(sprintf('%03d', count($rows)), null);
		$template = $canAdd ? '<template id="' . h($sf) . 'Template">' . $rowFn('__ROW__', null) . '</template>' : '';

		$fields = $formats = [];
		foreach ($cols as $c) {
			if (in_array($c['sob_all_type'], ['input', 'select', 'textarea', 'calc', 'lookup', 'display'], true) && $c['sob_input_type'] !== 'button') {
				$fields[] = (string)$c['sob_all_id'];
				$formats[(string)$c['sob_all_id']] = $this->formatOf($c);
			}
		}
		$this->clientSubforms[$sf] = [
			'object_id' => $o['zzzzsys_object_id'], 'form_id' => $sub->id, 'fields' => $fields, 'formats' => $formats,
			'add' => $canAdd, 'delete' => $canDelete, 'foreign_key' => (string)$o['sob_subform_foreign_key'], 'primary_key' => $sub->primaryKey(),
			'table' => trim((string)$o['sob_subform_table']) ?: $sub->table(), 'events' => array_keys($sfEvents),
		];

		$h = (int)$o['sob_all_height'];
		if ($isForm) {
			return '<div class="nuSubform nuSubformForm" id="' . h($sf) . '" data-nu-subform="' . h($sf) . '" data-nu-object-id="' . h($o['zzzzsys_object_id']) . '">'
				. '<div class="nuSubformScroll" id="' . h($sf) . 'scrollDiv"' . ($h > 0 ? ' style="max-height:' . max(120, $h) . 'px"' : '') . '>'
				. '<div class="nuSubformBody">' . $body . '</div></div>' . $template . '</div>';
		}
		return '<div class="nuSubform" id="' . h($sf) . '" data-nu-subform="' . h($sf) . '" data-nu-object-id="' . h($o['zzzzsys_object_id']) . '">'
			. '<div class="nuSubformScroll" id="' . h($sf) . 'scrollDiv"' . ($h > 0 ? ' style="max-height:' . max(80, $h) . 'px"' : '') . '>'
			. '<table class="table table-sm align-middle mb-0 nuSubformTable"><thead><tr>' . $head . '</tr></thead><tbody class="nuSubformBody">' . $body . '</tbody></table></div>'
			. $template . '</div>';
	}

	private function subformRow(NuFormMeta $sub, string $sf, array $cols, string $row, ?array $data, bool $canDelete, array $sfEvents): string
	{
		$prefix = $sf . $row;
		$pk     = $data !== null ? (string)($data[$sub->primaryKey()] ?? '') : '';
		$html   = '<tr id="' . h($prefix) . '" class="nuSubformRow' . ($data === null ? ' nuNewRow' : '') . '" data-nu-subform="' . h($sf) . '" data-nu-row="' . h($row) . '"'
			. ' data-nu-prefix="' . h($prefix) . '" data-nu-primary-key="' . h($pk) . '">';
		foreach ($cols as $c) {
			$value  = $data !== null ? $this->columnValue($data, (string)$c['sob_all_id']) : '';
			$hidden = NuRecord::hiddenFor($c);
			$events = $this->eventsOfMeta($sub, $c);
			$html  .= '<td class="nuSubformCell"' . ($hidden ? ' style="display:none"' : '') . '>'
				. $this->control($c, $prefix, $value, $c['sob_all_type'] === 'textarea' ? 'height:' . max(30, (int)$c['sob_all_height']) . 'px;' : '', $events) . '</td>';
		}
		if ($canDelete) {
			$click = isset($sfEvents['clickdelete']) ? ' onclick="' . h($sfEvents['clickdelete']) . '"' : '';
			$html .= '<td class="nuSubformDeleteCell"><input type="checkbox" class="form-check-input nuSubformDelete" id="' . h($prefix) . 'nuDelete"'
				. ' data-nu-checkbox="' . h($sf) . '" data-nu-prefix="' . h($prefix) . '"' . $click . ' aria-label="' . h(t('delete')) . '"></td>';
		}
		return $html . '</tr>';
	}

	/** One subform record as a small form (sob_subform_type = 'f'), laid out like the auto layout. */
	private function subformFormRow(NuFormMeta $sub, string $sf, array $cols, string $row, ?array $data, bool $canDelete, array $sfEvents): string
	{
		$prefix = $sf . $row;
		$pk     = $data !== null ? (string)($data[$sub->primaryKey()] ?? '') : '';
		$tree   = NuLayout::build($cols, 'auto');
		$inner  = '';
		foreach ($tree['rows'] as $items) {
			$inner .= '<div class="row g-2">';
			foreach ($items as $it) {
				$c      = $it['obj'];
				$field  = (string)$c['sob_all_id'];
				$value  = $data !== null ? $this->columnValue($data, $field) : '';
				$hidden = NuRecord::hiddenFor($c);
				$label  = trim((string)$c['sob_all_label']) !== '' && !in_array($c['sob_all_type'], ['word', 'html'], true) && $c['sob_input_type'] !== 'button'
					? '<label class="form-label nuLabel" id="label_' . h($prefix . $field) . '" for="' . h($prefix . $field . ($c['sob_all_type'] === 'lookup' ? 'code' : '')) . '">' . (string)$c['sob_all_label'] . $this->marks($c) . '</label>'
					: '';
				$control = $this->control($c, $prefix, $value, LayoutSize::flowStyle($c), $this->eventsOfMeta($sub, $c));
				if ($c['sob_all_type'] === 'input' && $c['sob_input_type'] === 'checkbox') {
					$control = '<div class="form-check nuCheckWrap">' . $control . str_replace('form-label nuLabel', 'form-check-label nuLabel', $label) . '</div>';
					$label = '';
				}
				$inner .= '<div class="col-12 col-md-' . $it['span'] . ' nuCell"' . ($hidden ? ' style="display:none"' : '') . ' id="nuw_' . h($prefix . $field) . '">'
					. $label . $control . '</div>';
			}
			$inner .= '</div>';
		}
		$del = '';
		if ($canDelete) {
			$click = isset($sfEvents['clickdelete']) ? ' onclick="' . h($sfEvents['clickdelete']) . '"' : '';
			$del = '<div class="form-check form-switch ms-auto mb-0"><input type="checkbox" class="form-check-input nuSubformDelete" role="switch" id="' . h($prefix) . 'nuDelete"'
				. ' data-nu-checkbox="' . h($sf) . '" data-nu-prefix="' . h($prefix) . '"' . $click . '>'
				. '<label class="form-check-label small" for="' . h($prefix) . 'nuDelete">' . h(t('delete')) . '</label></div>';
		}
		return '<div id="' . h($prefix) . '" class="nuSubformRow nuSubformCard' . ($data === null ? ' nuNewRow' : '') . '" data-nu-subform="' . h($sf) . '" data-nu-row="' . h($row) . '"'
			. ' data-nu-prefix="' . h($prefix) . '" data-nu-primary-key="' . h($pk) . '">'
			. '<div class="nuSubformCardHead d-flex align-items-center gap-2"><span class="small text-body-secondary nuSubformCardNo">'
			. ($data === null ? '<i class="bi bi-plus-circle me-1"></i>' . h(t('new')) : '#' . ((int)$row + 1)) . '</span>' . $del . '</div>'
			. $inner . '</div>';
	}

	// ================================================================= values & helpers

	private function valueOf(array $o): mixed
	{
		$f = (string)$o['sob_all_id'];
		if ($o['sob_all_type'] === 'display') return $this->display($o);
		return $this->columnValue($this->row, $f);
	}

	private function columnValue(array $row, string $field): string
	{
		// demo mode: password hashes and secret columns are never sent to the browser (NuSecurity::demoMask)
		if (array_key_exists($field, $row)) return (string)(NuSecurity::demoMask($field, $row[$field]) ?? '');
		foreach ($row as $k => $v) if (strcasecmp((string)$k, $field) === 0) return (string)(NuSecurity::demoMask($field, $v) ?? '');
		return '';
	}

	private function formatOf(array $o): string
	{
		if ($o['sob_all_type'] === 'calc') return (string)$o['sob_calc_format'];
		if ($o['sob_all_type'] !== 'input') return '';
		$f = trim((string)$o['sob_input_format']);
		return match ((string)$o['sob_input_type']) {
			'nuDate', 'nuNumber' => $f,
			'text', 'tel', 'search', '' => str_starts_with($f, 'T|') ? $f : '',
			default => '',
		};
	}

	/** Placeholder of a text mask: placeholders become _ (literals stay). */
	private static function maskPlaceholder(string $mask): string
	{
		return implode('', array_map(static fn($t) => $t[0] === 'L' ? $t[1] : '_', NuFormat::maskTokens($mask)));
	}

	/** readonly / hidden from sob_all_access (0 editable, 1 readonly, 2 hidden, 3 hidden for users, 4 hidden for users + readonly for globeadmin) */
	private function access(array $o): array
	{
		$a = NuRecord::accessOf($o);
		$isAdmin = (bool)NuAuth::user()['globeadmin'];
		$fa = NuFieldAccess::resolve($o)[1] !== null;          // a field data access rule decides (0.8.480), else nuBuilder's access as before
		$ro = $fa ? NuFieldAccess::readonly($o, $this->recordId === '-1') : ($a === '1' || ($a === '4' && $isAdmin));
		return ['readonly' => $ro || !$this->editable(), 'hidden' => NuRecord::hiddenFor($o)];
	}

	private bool $readonlyForm = false;

	public function setReadonly(bool $ro): void
	{
		$this->readonlyForm = $ro;
	}

	private function editable(): bool
	{
		return !$this->readonlyForm;
	}

	private function eventsOf(array $o, string $where): string
	{
		return $this->eventsOfMeta($this->meta, $o);
	}

	private function eventsOfMeta(NuFormMeta $meta, array $o): string
	{
		$out = '';
		foreach ($meta->events[$o['zzzzsys_object_id']] ?? [] as $ev => $js) {
			if (!preg_match('/^on[a-z]+$/', $ev) || $o['sob_all_type'] === 'subform') continue;     // subform events are handled by the runtime
			$out .= ' ' . $ev . '="' . h($js) . '"';
		}
		return $out;
	}

	private function attributes(array $o): string
	{
		$raw = trim((string)($o['sob_input_attribute'] ?? ''));   // older nuBuilder tables / drafts may lack the column
		if ($raw === '') return '';
		$out = '';
		foreach (preg_split('/,(?=\s*[A-Za-z_:][-A-Za-z0-9_:.]*\s*(=|,|$))/', $raw) as $part) {
			$part = trim($part);
			if ($part === '') continue;
			[$k, $v] = array_pad(explode('=', $part, 2), 2, null);
			$k = trim($k);
			if (!preg_match('/^[A-Za-z_:][-A-Za-z0-9_:.]*$/', $k) || preg_match('/^on/i', $k)) continue;
			if ($v !== null) { $v = trim($v); if (preg_match('/^(["\'])(.*)\1$/s', $v, $q)) $v = $q[2]; }   // one pair of quotes, not every quote at the ends
			$out .= ' ' . $k . ($v !== null ? '="' . h($v) . '"' : '');
		}
		return $out;
	}

	private function styleAttr(array $o): string
	{
		return $o['sob_all_style_type'] === 'CSS' && trim((string)$o['sob_all_style']) !== '' ? ' style="' . h($o['sob_all_style']) . '"' : '';
	}

	/** contentbox "Class" style: JSON {"nuContentBoxTitle": cls, "nuContentBoxContent": cls} or plain class names */
	private function styleClasses(array $o): array
	{
		$out = ['title' => '', 'content' => ''];
		if ($o['sob_all_style_type'] !== 'Class') return $out;
		$d = json_decode((string)$o['sob_all_style'], true);
		if (is_array($d)) {
			$out['title']   = (string)($d['nuContentBoxTitle'] ?? '');
			$out['content'] = (string)($d['nuContentBoxContent'] ?? '');
		} else {
			$out['content'] = (string)$o['sob_all_style'];
		}
		return $out;
	}

	/** Options of a select: "SELECT …" (hash-replaced) or static "v|t|v|t" / "v|t|\nv|t|". */
	public function options(array $o): array
	{
		$key = $o['zzzzsys_object_id'];
		if (isset($this->selectCache[$key])) return $this->selectCache[$key];
		$src = trim((string)$o['sob_select_sql']);
		$opts = [];
		if (preg_match('/^\s*(SELECT|WITH|\(|EXEC)\b/i', $src)) {
			try {
				foreach (nu_db()->run(NuHash::replace($src, ['OBJECT_ID' => (string)$o['zzzzsys_object_id']] + $this->hash, NuHash::SQL))->fetchAll(PDO::FETCH_NUM) as $r) {
					$opts[] = [(string)$r[0], (string)($r[1] ?? $r[0])];
				}
			} catch (Throwable $e) {
				error_log('nuBuilder Next select ' . $o['sob_all_id'] . ': ' . $e->getMessage());
				$opts[] = ['', '⚠ ' . (NuAuth::user()['globeadmin'] ? $e->getMessage() : t('form_err_options'))];
			}
		} elseif ($src !== '') {
			if (str_starts_with($src, '[')) {                              // JSON list
				foreach (json_decode($src, true) ?: [] as $v) $opts[] = is_array($v) ? [(string)$v[0], (string)($v[1] ?? $v[0])] : [(string)$v, (string)$v];
			} else {
				$parts = explode('|', str_replace(["\r\n", "\r", "\n"], '', $src));
				if (end($parts) === '') array_pop($parts);
				for ($i = 0, $n = count($parts); $i + 1 < $n; $i += 2) $opts[] = [$parts[$i], $parts[$i + 1]];
				if (count($parts) % 2 === 1) $opts[] = [end($parts), end($parts)];
			}
			// texts of a fixed list in the reader's language (zzzzsys_translate; 0.8.754 – nuBuilder Forte shows them as entered); the values stay
			if (NuTranslate::active()) foreach ($opts as $i => $op) $opts[$i][1] = NuTranslate::text($op[1]);
		}
		return $this->selectCache[$key] = $opts;
	}

	/**
	 * Value of a display object: its procedure (sob_display_procedure, like nuBuilder: what the PHP code echoes) or else the
	 * first column of the first row of sob_display_sql. Used on load and by nuRefreshDisplayObject.
	 */
	public function display(array $o): string
	{
		$hash = ['OBJECT_ID' => (string)$o['zzzzsys_object_id']] + $this->hash;
		$proc = trim((string)($o['sob_display_procedure'] ?? ''));
		if ($proc !== '') return $this->displayProcedure($o, $proc, $hash);
		$sql = trim((string)$o['sob_display_sql']);
		if ($sql === '') return '';
		try {
			$v = nu_db()->run(NuHash::replace($sql, $hash, NuHash::SQL))->fetchColumn();
			return $v === false || $v === null ? '' : (string)$v;
		} catch (Throwable $e) {
			error_log('nuBuilder Next display ' . $o['sob_all_id'] . ': ' . $e->getMessage());
			return NuAuth::user()['globeadmin'] ? '⚠ ' . $e->getMessage() : '';
		}
	}

	/**
	 * Runs the display procedure (by id or code) and returns its output. As in nuBuilder no access check – the procedure belongs to
	 * the form; demo mode still blocks writes. The procedure runs isolated: what it collects (errors, JavaScript, properties) does not
	 * leak into the form's own NuPhp state.
	 */
	private function displayProcedure(array $o, string $proc, array $hash): string
	{
		$admin = (bool)(NuAuth::user()['globeadmin'] ?? false);
		try {
			$php = nu_db()->one('SELECT sph_code, sph_php FROM zzzzsys_php WHERE zzzzsys_php_id = ? OR sph_code = ?', [$proc, $proc]);
		} catch (Throwable $e) {
			$php = null;
		}
		if (!$php) {
			error_log('nuBuilder Next display ' . $o['sob_all_id'] . ': procedure not found: ' . $proc);
			return $admin ? '⚠ ' . t('form_err_proc_missing', $proc) : '';
		}
		$r = NuPhp::isolated(static fn() => NuPhp::run((string)$php['sph_php'], $hash + ['PROCEDURE_CODE' => (string)$php['sph_code']], (string)$php['sph_code']));
		$out = $r['output'];
		$err = $r['errors'][0]['message'] ?? null;
		if ($err !== null) {
			error_log('nuBuilder Next display ' . $o['sob_all_id'] . ': ' . $err);
			return $admin ? '⚠ ' . $err : '';
		}
		return $out;
	}

	private function datalist(array $o, string $id): string
	{
		$src = trim((string)($o['sob_input_datalist'] ?? ''));
		if ($src === '') return '';
		$vals = [];
		if (preg_match('/^\s*SELECT\b/i', $src)) {
			try { $vals = nu_db()->run(NuHash::replace($src, $this->hash, NuHash::SQL))->fetchAll(PDO::FETCH_COLUMN); } catch (Throwable) {}
		} else {
			$d = json_decode($src, true);
			$vals = is_array($d) ? $d : preg_split('/\s*[,\n]\s*/', $src);
		}
		$html = '<datalist id="nuDatalist_' . h($id) . '">';
		foreach ($vals as $v) $html .= '<option value="' . h(is_array($v) ? ($v[0] ?? '') : $v) . '">';
		return $html . '</datalist>';
	}

	/** Code + description of the record a lookup points to. */
	private function lookupDisplay(array $o, string $pk): array
	{
		if ($pk === '') return ['', ''];
		$r = NuLookup::byId($o, $pk, $this->hash);
		return $r ? [$r['code'], $r['description']] : [$pk, ''];
	}

	/** Property scope of the page being rendered (iframes inherit it), see NuProps. */
	public static string $scope = '';

	/** Form properties handed over by the opening form (?ps=<scope key>, values kept in the session). */
	public static function props(): array
	{
		return NuProps::load(is_string($_GET['ps'] ?? null) ? $_GET['ps'] : '')[0];
	}
}

/** Pixel sizes for the absolute layout. */
final class LayoutSize
{
	public static function width(array $o): int
	{
		$w = (int)$o['sob_all_width'];
		if ($o['sob_all_type'] === 'lookup') $w += NuRender::lookupDescWidth($o) + 34;
		return max($w, 10);
	}

	public static function controlStyle(array $o): string
	{
		$type = $o['sob_all_type'];
		$h    = (int)$o['sob_all_height'];
		if ($type === 'lookup') return 'width:' . (int)$o['sob_all_width'] . 'px;';
		if ($type === 'input' && $o['sob_input_type'] === 'checkbox') return '';
		// Uppy: a width in px – nuBuilder's template reads it with nuCSSNumber('width'), also while its tab is hidden ('100%' gave 100 px, 0.8.506)
		if (NuRender::isUppy($o)) return 'width:' . max(10, (int)$o['sob_all_width']) . 'px;max-width:100%;height:' . max(18, $h) . 'px;';
		if (in_array($type, ['textarea', 'editor', 'html', 'run', 'word', 'chart'], true) || ($type === 'select' && $h > 40) || NuRender::isUppy($o)) return 'width:100%;height:' . max(18, $h) . 'px;';
		return 'width:100%;';
	}

	public static function flowStyle(array $o): string
	{
		$type = $o['sob_all_type'];
		$h    = (int)$o['sob_all_height'];
		if ($type === 'editor') return 'height:' . max(200, $h) . 'px;';
		if ($type === 'textarea' || $type === 'run' || ($type === 'select' && $h > 40)) return 'height:' . max(60, $h) . 'px;';
		if (($type === 'html' || NuRender::isUppy($o)) && $h > 0) return 'min-height:' . $h . 'px;';
		return '';
	}
}
