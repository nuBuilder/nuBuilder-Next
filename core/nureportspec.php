<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * Reports with AI (0.8.1097): a short description of a report layout – format "nunext-report-1", sizes in mm – that an AI chat can write
 * and change. It is converted to and from nuBuilder Forte's layout JSON (sre_layout: positions in px, 4 px = 1 mm), so the stored report
 * stays Forte's. Copy / paste as Form with AI (no API key): prompt() → the user's chat → parse() → check() (errors, warnings, plan) →
 * preview → apply(). Before a layout is replaced the old one is kept as a version (zzzzsys_nunext_report_version) – restore() puts it back.
 * Optionally the AI writes the query too (spec key "source": {"sql": …}) – checked as read-only SQL and saved as an SQL builder query.
 */
final class NuReportSpec
{
	public const FORMAT = 'nunext-report-1';
	public const VERSIONS = 'zzzzsys_nunext_report_version';
	/** Versions kept per report. */
	public const KEEP = 20;
	private const PX = 4;                                         // px per mm in the stored layout
	private const BOX = 'KEEP EXACT HEIGHT';
	/** Bands that always exist: key => [group, section] of the layout. */
	private const BANDS = ['report_header' => [1, 0], 'page_header' => [2, 0], 'detail' => [0, 0], 'page_footer' => [2, 1], 'report_footer' => [1, 1]];
	private const TYPES = ['field', 'text', 'sum', 'average', 'box', 'line', 'image'];
	/** Id of an SQL text that is not saved yet (NuReport::$sqlText) – the AI's query while it is checked / previewed. */
	public const TEMP_SQL = '__nunext_ai__';

	// ================================================================= layout ↔ spec

	/** [group, section] of a band key, null when it is none ("group_header_2" = header of the second sort group). */
	public static function bandPos(string $key): ?array
	{
		if (isset(self::BANDS[$key])) return self::BANDS[$key];
		if (preg_match('/^group_(header|footer)_([1-5])$/', $key, $m)) return [2 + (int)$m[2], $m[1] === 'header' ? 0 : 1];
		return null;
	}

	/** Band keys top to bottom as they print, for $groups sort groups. @return string[] */
	public static function bandKeys(int $groups): array
	{
		$out = ['report_header', 'page_header'];
		for ($i = 1; $i <= $groups; $i++) $out[] = "group_header_$i";
		$out[] = 'detail';
		for ($i = $groups; $i >= 1; $i--) $out[] = "group_footer_$i";
		$out[] = 'page_footer';
		$out[] = 'report_footer';
		return $out;
	}

	private static function mm(mixed $px): float
	{
		return round((float)$px / self::PX, 2);
	}

	/** "#fff", "white", "#FFFFFF" → "#ffffff"; '' when it is no colour. */
	public static function hex(mixed $c): string
	{
		$c = strtolower(trim((string)$c));
		$names = ['white' => '#ffffff', 'black' => '#000000', 'red' => '#ff0000', 'green' => '#008000', 'blue' => '#0000ff', 'gray' => '#808080', 'grey' => '#808080',
			'silver' => '#c0c0c0', 'yellow' => '#ffff00', 'orange' => '#ffa500', 'transparent' => '#ffffff'];
		if (isset($names[$c])) return $names[$c];
		if (preg_match('/^#([0-9a-f])([0-9a-f])([0-9a-f])$/', $c, $m)) return "#$m[1]$m[1]$m[2]$m[2]$m[3]$m[3]";
		return preg_match('/^#[0-9a-f]{6}$/', $c) ? $c : '';
	}

	/** The short description of a stored layout. */
	public static function fromLayout(object $L): array
	{
		$spec = ['format' => self::FORMAT, 'report' => ['paper' => (string)$L->paper, 'orientation' => $L->orientation === 'L' ? 'landscape' : 'portrait'], 'groups' => [], 'bands' => []];
		if (($L->ishtml ?? 'N') === 'Y') $spec['report']['html'] = true;
		$used = [];
		foreach (NuReport::SORT_GROUPS as $g) {
			if ((string)$L->groups[$g]->sortField === '') continue;
			$used[] = $g;
			$spec['groups'][] = ['field' => (string)$L->groups[$g]->sortField, 'dir' => $L->groups[$g]->sortBy === 'd' ? 'desc' : 'asc'];
		}
		foreach (self::bandKeys(count($used)) as $key) {
			[$g, $s] = self::bandPos($key);
			if ($g >= 3) $g = $used[$g - 3];
			$S = $L->groups[$g]->sections[$s] ?? null;
			if (!$S || ((float)$S->height <= 0 && !$S->objects)) continue;
			$band = ['height' => self::mm($S->height)];
			if ((int)($S->page_break ?? 0) === 1) $band['page_break'] = true;
			$band['objects'] = array_map([self::class, 'objectSpec'], $S->objects);
			$spec['bands'][$key] = $band;
		}
		return $spec;
	}

	private static function objectSpec(object $o): array
	{
		$name = (string)$o->fieldName;
		$out = ['id' => (string)$o->id];
		$isBox = $o->objectType === 'label' && preg_replace('/\s+/', ' ', $name) === self::BOX;
		if ($isBox) {
			$out['type'] = (float)$o->height <= self::PX ? 'line' : 'box';
		} elseif ($o->objectType === 'image') {
			$out['type'] = 'image';
			if (stripos($name, 'Image:') === 0) $out['image'] = substr($name, 6); else $out['field'] = $name;
		} elseif ($o->objectType === 'field') {
			if (preg_match('/^SUM\((.*)\)$/i', $name, $m)) { $out['type'] = 'sum'; $out['field'] = trim($m[1]); }
			elseif (preg_match('/^AVERAGE\((.*)\)$/i', $name, $m)) { $out['type'] = 'average'; $out['fields'] = array_map('trim', explode(',', $m[1])); }
			else { $out['type'] = 'field'; $out['field'] = $name; }
		} else {
			$out['type'] = 'text';
			$out['text'] = $name;
		}
		$out += ['x' => self::mm($o->left), 'y' => self::mm($o->top), 'w' => self::mm($o->width), 'h' => self::mm($o->height)];
		$back = self::hex($o->backgroundColor) ?: '#ffffff';
		if ($isBox) {
			$out['color'] = $back;
		} else {
			if ($out['type'] !== 'image') {
				if ((float)$o->fontSize != 10.0) $out['size'] = (float)$o->fontSize + 0;
				$w = strtolower((string)$o->fontWeight);
				if (str_contains($w, 'b')) $out['bold'] = true;
				if (str_contains($w, 'i')) $out['italic'] = true;
				if (!in_array((string)$o->textAlign, ['', 'left'], true)) $out['align'] = (string)$o->textAlign;
				if ((self::hex($o->fontColor) ?: '#000000') !== '#000000') $out['color'] = self::hex($o->fontColor);
				if ((string)$o->format !== '') $out['format'] = (string)$o->format;
				if (strcasecmp((string)$o->fontFamily, 'Helvetica') !== 0 && (string)$o->fontFamily !== '') $out['font'] = (string)$o->fontFamily;
				if (isset($o->contentType)) $out['content'] = (string)$o->contentType;
				if ((int)$o->minRows !== 0) $out['min_rows'] = (int)$o->minRows;
				if ((int)$o->maxRows !== 0) $out['max_rows'] = (int)$o->maxRows;
			}
			if ($back !== '#ffffff') $out['background'] = $back;
		}
		if ((float)$o->borderWidth > 0) $out['border'] = ['width' => (float)$o->borderWidth + 0, 'color' => self::hex($o->borderColor) ?: '#000000'];
		return $out;
	}

	/**
	 * The layout to store for a (checked) description. $base: the report's current layout – page settings the description does not know
	 * and the unknown keys of objects that keep their id stay as they are.
	 */
	public static function toLayout(array $spec, ?object $base = null): object
	{
		$L = NuReport::layout($base ? (string)json_encode($base) : null);
		$old = [];
		$max = 1000;
		foreach ($L->groups as $G) foreach ($G->sections as $S) {
			foreach ($S->objects as $o) {
				$old[(string)$o->id] = $o;
				if (preg_match('/(\d+)$/', (string)$o->id, $m)) $max = max($max, (int)$m[1]);
			}
			$S->objects = [];
			$S->height = 0.0;
			$S->page_break = 0;
		}
		$rep = (array)($spec['report'] ?? []);
		$L->paper = isset(NuReport::PAPER[(string)($rep['paper'] ?? '')]) ? (string)$rep['paper'] : 'A4';
		$L->orientation = strtolower((string)($rep['orientation'] ?? 'portrait'))[0] === 'l' ? 'L' : 'P';
		[$long, $short] = NuReport::PAPER[$L->paper];
		$L->height = $L->orientation === 'L' ? $short : $long;
		$L->width = $L->orientation === 'L' ? $long : $short;
		if (array_key_exists('html', $rep) || isset($L->ishtml)) $L->ishtml = !empty($rep['html']) ? 'Y' : 'N';

		$groups = array_values((array)($spec['groups'] ?? []));
		foreach (NuReport::SORT_GROUPS as $i => $g) {
			$grp = $groups[$i] ?? null;
			$field = is_array($grp) ? trim((string)($grp['field'] ?? '')) : '';
			$L->groups[$g]->sortField = $field;
			$L->groups[$g]->sortBy = is_array($grp) && strtolower((string)($grp['dir'] ?? 'asc'))[0] === 'd' ? 'd' : 'a';
			$L->groups[$g]->sections[0]->label = $field . ' Header';
			$L->groups[$g]->sections[1]->label = $field . ' Footer';
		}

		$seen = [];
		foreach ((array)($spec['bands'] ?? []) as $key => $band) {
			$pos = self::bandPos((string)$key);
			if (!$pos || !is_array($band)) continue;
			$S = $L->groups[$pos[0]]->sections[$pos[1]];
			$need = 0.0;
			foreach ((array)($band['objects'] ?? []) as $in) {
				if (!is_array($in)) continue;
				$id = (string)($in['id'] ?? '');
				if ($id === '' || isset($seen[$id]) || !preg_match('/^[\w-]{1,40}$/', $id)) $id = 'object' . (++$max);
				$seen[$id] = true;
				$o = self::objectLayout($in, $old[$id] ?? null, $id);
				$need = max($need, (float)$o->top + (float)$o->height);
				$S->objects[] = $o;
			}
			$S->height = max(round((float)($band['height'] ?? 0) * self::PX), ceil($need));
			$S->page_break = !empty($band['page_break']) && $pos[0] !== 2 ? 1 : 0;
		}
		return NuReport::layout((string)json_encode($L));
	}

	private static function objectLayout(array $in, ?object $old, string $id): object
	{
		$o = $old ? clone $old : new stdClass();
		$o->id = $id;
		if (!isset($o->name)) $o->name = $id;
		$type = (string)($in['type'] ?? 'field');
		$px = static fn(string $k, float $def): float => round((is_numeric($in[$k] ?? null) ? (float)$in[$k] : $def) * self::PX);
		$o->left = $px('x', 10);
		$o->top = $px('y', 1);
		$o->width = max(1.0, $px('w', 40));
		$o->height = max(1.0, $px('h', $type === 'line' ? 0.5 : 5));
		$o->backgroundColor = '#FFFFFF';
		$o->fontColor = '#000000';
		$o->borderColor = '#000000';
		$o->borderWidth = '0';
		$o->fontFamily = 'Helvetica';
		$o->fontSize = '10';
		$o->fontWeight = '';
		$o->format = '';
		$o->textAlign = 'left';
		$o->minRows = 0;
		$o->maxRows = 0;
		if (!isset($o->zIndex)) $o->zIndex = 1;
		unset($o->contentType);
		$col = static fn(string $k, string $def): string => strtoupper(self::hex($in[$k] ?? '') ?: $def);
		switch ($type) {
			case 'box': case 'line':
				$o->objectType = 'label';
				$o->fieldName = self::BOX;
				$o->backgroundColor = $col('color', $type === 'line' ? '#000000' : '#DDDDDD');
				break;
			case 'image':
				$o->objectType = 'image';
				$o->fieldName = (string)($in['image'] ?? '') !== '' ? 'Image:' . $in['image'] : (string)($in['field'] ?? '');
				break;
			case 'sum':
				$o->objectType = 'field';
				$o->fieldName = 'SUM(' . trim((string)($in['field'] ?? '')) . ')';
				break;
			case 'average':
				$o->objectType = 'field';
				$o->fieldName = 'AVERAGE(' . implode(',', array_map(static fn($f) => trim((string)$f), array_slice((array)($in['fields'] ?? []), 0, 2))) . ')';
				break;
			case 'text':
				$o->objectType = 'label';
				$o->fieldName = (string)($in['text'] ?? '');
				break;
			default:
				$o->objectType = 'field';
				$o->fieldName = (string)($in['field'] ?? '');
		}
		if (!in_array($type, ['box', 'line'], true)) {
			if ($type !== 'image') {
				if (is_numeric($in['size'] ?? null) && (float)$in['size'] > 0) $o->fontSize = (string)((float)$in['size'] + 0);
				$o->fontWeight = (!empty($in['bold']) ? 'b' : '') . (!empty($in['italic']) ? 'i' : '');
				if (in_array($in['align'] ?? '', ['left', 'right', 'center'], true)) $o->textAlign = (string)$in['align'];
				$o->fontColor = $col('color', '#000000');
				$o->format = (string)($in['format'] ?? '');
				if ((string)($in['font'] ?? '') !== '') $o->fontFamily = (string)$in['font'];
				if (in_array($in['content'] ?? '', ['text', 'html', 'md'], true)) $o->contentType = (string)$in['content'];
				$o->minRows = (int)($in['min_rows'] ?? 0);
				$o->maxRows = (int)($in['max_rows'] ?? 0);
			}
			$o->backgroundColor = $col('background', '#FFFFFF');
		}
		$b = $in['border'] ?? null;
		if (is_array($b) && is_numeric($b['width'] ?? null) && (float)$b['width'] > 0) {
			$o->borderWidth = (string)((float)$b['width'] + 0);
			$o->borderColor = strtoupper(self::hex($b['color'] ?? '') ?: '#000000');
		}
		elseif ($type === 'box' || $type === 'line') {                       // no border asked: the hairline frame both engines draw at width 0 gets the fill colour
			$same = $old && isset($old->backgroundColor, $old->borderColor) && strcasecmp((string)$old->backgroundColor, (string)$o->backgroundColor) === 0;
			$o->borderColor = $same ? $old->borderColor : $o->backgroundColor;
		}
		if ($old) foreach (['fontColor', 'backgroundColor', 'borderColor'] as $k) {      // the same colour keeps its stored spelling (#0f766e / #0F766E)
			if (isset($old->$k, $o->$k) && is_string($old->$k) && strcasecmp($old->$k, (string)$o->$k) === 0) $o->$k = $old->$k;
		}
		return $o;
	}

	// ================================================================= the AI's answer

	/** The JSON of an answer (a ```json block or the first { … }). @return array|string the description, or the error text */
	public static function parse(string $answer): array|string
	{
		$a = trim($answer);
		if ($a === '') return t('fai_err_no_json');
		if (preg_match('/```(?:json)?\s*(\{.*\})\s*```/s', $a, $m)) $a = $m[1];
		else {
			$s = strpos($a, '{');
			$e = strrpos($a, '}');
			if ($s === false || $e === false || $e < $s) return t('fai_err_no_json');
			$a = substr($a, $s, $e - $s + 1);
		}
		$a = (string)preg_replace('~^\s*//.*$~m', '', $a);
		$j = json_decode($a, true);
		if (!is_array($j)) return t('fai_err_json', json_last_error_msg());
		return $j;
	}

	/** May this user let the AI write the query? (rights on the SQL builder are admin-equivalent; never in demo mode) */
	public static function canSql(): bool
	{
		return !NuDemo::active() && NuSqlSelect::available() && NuAcl::can(NuAcl::FORM_SELECT, 'save') && NuAcl::can(NuAcl::FORM_SELECT, 'create');
	}

	/** Columns of a SELECT that is not saved yet – read-only, nothing fetched. @throws RuntimeException with the reason */
	public static function sqlColumns(string $sql): array
	{
		$sql = rtrim(trim($sql), "; \n\r\t");
		if ($sql === '' || !NuDemo::readOnlySql(NuHash::replace($sql, [], NuHash::RAW))) throw new RuntimeException(t('sqb_err_select_only'));
		try {
			return NuSqlSelect::preview($sql, 1)['columns'] ?? [];
		} catch (Throwable $e) {
			$u = NuAuth::user();
			throw new RuntimeException(t('rai_err_sql') . (($u && $u['globeadmin'] && empty($u['demo'])) || nu_debug() ? ' ' . $e->getMessage() : ''));
		}
	}

	/**
	 * What the lists of a report offer: columns of its source, criteria fields of the launch form, formats, fonts, images.
	 * 'known' false = the source could not be read (a procedure that fails with empty criteria) – fields are then not checked.
	 */
	public static function context(array $report): array
	{
		$ctx = ['columns' => [], 'criteria' => [], 'known' => true, 'error' => ''];
		try {
			$f = NuReport::fields((string)$report['sre_zzzzsys_php_id'], trim((string)($report['sre_zzzzsys_form_id'] ?? '')));
			$ctx['columns'] = $f['columns'];
			$ctx['criteria'] = $f['criteria'];
		} catch (Throwable $e) {
			$ctx['known'] = false;
			$ctx['error'] = $e->getMessage();
		}
		$ctx['formats'] = array_values(array_filter(array_column(NuReport::formats(), 0)));
		if (preg_match_all('/"format"\s*:\s*"((?:[^"\\\\]|\\\\.)+)"/', (string)($report['sre_layout'] ?? ''), $m)) {      // formats the layout already uses count as known
			foreach ($m[1] as $f) { $f = json_decode('"' . $f . '"'); if (is_string($f) && !in_array($f, $ctx['formats'], true)) $ctx['formats'][] = $f; }
		}
		$ctx['fonts'] = NuReport::fonts();
		$ctx['images'] = array_column(NuReport::images(), 0);
		return $ctx;
	}

	/**
	 * Checks a description against the report. @return array{errors: string[], warnings: string[], plan: array, spec: array}
	 * (spec = the cleaned description apply() / toLayout() take).
	 */
	public static function check(array $spec, array $report, ?array $ctx = null, bool $allowSql = false): array
	{
		$errors = [];
		$warn = [];
		$ctx ??= self::context($report);
		$base = NuReport::layout((string)($report['sre_layout'] ?? ''));
		if (($spec['format'] ?? '') !== self::FORMAT) return ['errors' => [t('rai_err_format', self::FORMAT)], 'warnings' => [], 'plan' => [], 'spec' => []];

		// the query, when the AI wrote one
		$source = null;
		$src = $spec['source'] ?? null;
		if (is_array($src) && trim((string)($src['sql'] ?? '')) !== '') {
			if (!$allowSql || !self::canSql()) { $errors[] = t('rai_err_sql_not_allowed'); $ctx['known'] = false; $quiet = true; }
			else {
				try {
					$cols = self::sqlColumns((string)$src['sql']);
					$ctx['columns'] = $cols;
					$ctx['known'] = true;
					$code = mb_substr(preg_replace('/[^\w]+/', '_', trim((string)($src['code'] ?? ''))) ?: 'rpt_' . preg_replace('/[^\w]+/', '_', (string)$report['sre_code']), 0, 50);
					$source = ['sql' => rtrim(trim((string)$src['sql']), "; \n\r\t"), 'code' => $code,
						'description' => mb_substr(trim((string)($src['description'] ?? '')) ?: (string)$report['sre_description'], 0, 300), 'columns' => $cols];
				} catch (Throwable $e) {
					$errors[] = $e->getMessage();
					$ctx['known'] = false;                              // its columns are unknown: no "no column" errors on top
					$quiet = true;
				}
			}
		}

		// names: exact, else ignoring case (the AI's spelling is put right)
		$names = [];
		foreach (array_merge($ctx['columns'], $ctx['criteria']) as $c) $names[strtolower((string)$c)] = (string)$c;
		$unknown = [];
		$field = static function (mixed $f, string $where) use (&$names, &$unknown, $ctx): string {
			$f = trim((string)$f);
			if ($f === '') return '';
			if (isset($names[strtolower($f)])) return $names[strtolower($f)];
			if ($ctx['known']) $unknown[$f][] = $where;
			return $f;
		};

		$rep = is_array($spec['report'] ?? null) ? $spec['report'] : [];
		$paper = (string)($rep['paper'] ?? 'A4');
		if (!isset(NuReport::PAPER[$paper])) { $warn[] = t('rai_warn_paper', $paper); $paper = 'A4'; }
		$land = strtolower((string)($rep['orientation'] ?? 'portrait'))[0] === 'l';
		[$long, $short] = NuReport::PAPER[$paper];
		$pageW = $land ? $long : $short;
		$clean = ['format' => self::FORMAT, 'report' => ['paper' => $paper, 'orientation' => $land ? 'landscape' : 'portrait'], 'groups' => [], 'bands' => []];
		if (array_key_exists('html', $rep)) $clean['report']['html'] = !empty($rep['html']);

		$groups = array_values(array_filter((array)($spec['groups'] ?? []), 'is_array'));
		if (count($groups) > 5) { $errors[] = t('rai_err_groups'); $groups = array_slice($groups, 0, 5); }
		foreach ($groups as $i => $g) {
			$f = $field($g['field'] ?? '', t('rai_w_group', $i + 1));
			if ($f === '') { $errors[] = t('rai_err_group_field', $i + 1); continue; }
			$clean['groups'][] = ['field' => $f, 'dir' => strtolower((string)($g['dir'] ?? 'asc'))[0] === 'd' ? 'desc' : 'asc'];
		}
		$nGroups = count($clean['groups']);

		$total = 0;
		$ids = [];
		$planBands = [];
		$bands = is_array($spec['bands'] ?? null) ? $spec['bands'] : [];
		foreach ($bands as $key => $band) {
			$key = (string)$key;
			$pos = self::bandPos($key);
			if (!$pos || !is_array($band)) { $errors[] = t('rai_err_band', $key); continue; }
			if ($pos[0] >= 3 && $pos[0] - 2 > $nGroups) { $errors[] = t('rai_err_band_group', $key); continue; }
			$out = ['height' => max(0.0, round((float)($band['height'] ?? 0), 2))];
			if (!empty($band['page_break'])) $out['page_break'] = true;
			$out['objects'] = [];
			foreach ((array)($band['objects'] ?? []) as $n => $o) {
				$where = $key . ' #' . ((int)$n + 1);
				if (!is_array($o)) { $errors[] = t('rai_err_object', $where); continue; }
				$type = (string)($o['type'] ?? '');
				if (!in_array($type, self::TYPES, true)) { $errors[] = t('rai_err_type', $where, $type); continue; }
				foreach (['x', 'y', 'w', 'h'] as $k) if (!is_numeric($o[$k] ?? null) || (float)$o[$k] < 0) { $errors[] = t('rai_err_size', $where, $k); continue 2; }
				if ((float)$o['w'] <= 0 || (float)$o['h'] <= 0) { $errors[] = t('rai_err_size', $where, 'w / h'); continue; }
				if ((float)$o['x'] + (float)$o['w'] > $pageW + 0.01) $warn[] = t('rai_warn_outside', $where, (string)$pageW);
				if ($type === 'field' || $type === 'sum') {
					$o['field'] = $field($o['field'] ?? '', $where);
					if ($o['field'] === '') { $errors[] = t('rai_err_no_field', $where); continue; }
				} elseif ($type === 'average') {
					$fs = array_values(array_filter(array_map(static fn($f) => $field($f, $where), (array)($o['fields'] ?? []))));
					if (count($fs) !== 2) { $errors[] = t('rai_err_average', $where); continue; }
					$o['fields'] = $fs;
				} elseif ($type === 'image') {
					$img = trim((string)($o['image'] ?? ''));
					if ($img !== '') { if (!in_array($img, $ctx['images'], true)) $warn[] = t('rai_warn_image', $where, $img); }
					else { $o['field'] = $field($o['field'] ?? '', $where); if ($o['field'] === '') { $errors[] = t('rai_err_no_field', $where); continue; } }
				} elseif ($type === 'text') {
					$o['text'] = (string)($o['text'] ?? '');
				}
				$fmt = (string)($o['format'] ?? '');
				if ($fmt !== '') {
					if (!preg_match('/^[ND]\|.+/', $fmt)) { $warn[] = t('rai_warn_format', $where, $fmt); unset($o['format']); }
					elseif (!in_array($fmt, $ctx['formats'], true)) $warn[] = t('rai_warn_format_new', $where, $fmt);
				}
				$font = (string)($o['font'] ?? '');
				if ($font !== '' && !in_array(strtolower($font), array_map('strtolower', $ctx['fonts']), true)) { $warn[] = t('rai_warn_font', $where, $font); unset($o['font']); }
				foreach (['color', 'background'] as $k) if (isset($o[$k]) && self::hex($o[$k]) === '') { $warn[] = t('rai_warn_color', $where, (string)$o[$k]); unset($o[$k]); }
				$id = (string)($o['id'] ?? '');
				if ($id !== '' && (isset($ids[$id]) || !preg_match('/^[\w-]{1,40}$/', $id))) unset($o['id']);
				elseif ($id !== '') $ids[$id] = true;
				$out['objects'][] = $o;
				$total++;
			}
			$clean['bands'][$key] = $out;
			$planBands[] = ['band' => $key, 'height' => $out['height'], 'objects' => count($out['objects'])];
		}
		foreach ($unknown as $f => $wheres) $errors[] = t('rai_err_field', $f, implode(', ', array_slice(array_unique($wheres), 0, 4)));
		if (!$total && !$errors) $errors[] = t('rai_err_empty');
		if (!$ctx['known'] && empty($quiet)) $warn[] = t('rai_warn_source');

		// against the current layout: objects that keep their id are changed, the others are new / go
		$had = [];
		foreach ($base->groups as $G) foreach ($G->sections as $S) foreach ($S->objects as $o) $had[(string)$o->id] = true;
		$kept = count(array_intersect_key($ids, $had));
		$plan = ['paper' => $paper, 'orientation' => $land ? 'landscape' : 'portrait', 'groups' => array_column($clean['groups'], 'field'), 'bands' => $planBands,
			'objects' => $total, 'had' => count($had), 'kept' => $kept, 'added' => $total - $kept, 'removed' => count($had) - $kept,
			'source' => $source ? ['code' => $source['code'], 'description' => $source['description'], 'sql' => $source['sql'], 'columns' => $source['columns']] : null];
		if ($source) $clean['source'] = ['sql' => $source['sql'], 'code' => $source['code'], 'description' => $source['description']];
		return ['errors' => array_values(array_unique($errors)), 'warnings' => array_values(array_unique($warn)), 'plan' => $plan, 'spec' => $clean];
	}

	// ================================================================= versions, apply, restore

	public static function versionsReady(): bool
	{
		try { NuSchema::ensure(); return nu_db()->tableExists(self::VERSIONS); } catch (Throwable) { return false; }
	}

	/** Keeps the report's layout and source as a version (the newest KEEP stay). */
	public static function snapshot(array $report, string $reason, string $note = ''): void
	{
		if (!self::versionsReady()) return;
		$db = nu_db();
		$u = NuAuth::user();
		$id = (string)$report['zzzzsys_report_id'];
		$db->insert(self::VERSIONS, ['zzzzsys_nunext_report_version_id' => nu_id(), 'report_id' => $id, 'saved_at' => date('Y-m-d H:i:s'), 'saved_by' => (string)($u['login'] ?? ''),
			'reason' => mb_substr($reason, 0, 20), 'note' => mb_substr($note, 0, 200), 'source' => (string)$report['sre_zzzzsys_php_id'], 'layout' => (string)$report['sre_layout']]);
		$all = $db->page('SELECT zzzzsys_nunext_report_version_id AS id FROM ' . self::VERSIONS . ' WHERE report_id = ?', [$id], 'saved_at DESC, zzzzsys_nunext_report_version_id DESC', 500, 0);
		foreach (array_slice($all, self::KEEP) as $r) $db->delete(self::VERSIONS, 'zzzzsys_nunext_report_version_id', (string)$r['id']);
	}

	/** Versions of a report, newest first (without the layouts). */
	public static function versions(string $reportId): array
	{
		if (!self::versionsReady()) return [];
		return nu_db()->page('SELECT zzzzsys_nunext_report_version_id AS id, saved_at, saved_by, reason, note, source FROM ' . self::VERSIONS . ' WHERE report_id = ?', [$reportId],
			'saved_at DESC, zzzzsys_nunext_report_version_id DESC', self::KEEP, 0);
	}

	public static function deleteVersions(string $reportId): void
	{
		if (self::versionsReady()) nu_db()->delete(self::VERSIONS, 'report_id', $reportId);
	}

	/**
	 * Writes a checked description: the query (when there is one) as an SQL builder query, the old layout as a version, the new layout.
	 * @return array{objects: int, source: string} @throws RuntimeException
	 */
	public static function apply(array $report, array $spec, bool $allowSql, string $note = ''): array
	{
		$r = self::check($spec, $report, null, $allowSql);
		if ($r['errors']) throw new RuntimeException(implode("\n", $r['errors']));
		$db = nu_db();
		$id = (string)$report['zzzzsys_report_id'];
		$sourceId = (string)$report['sre_zzzzsys_php_id'];
		$src = $r['spec']['source'] ?? null;
		if ($src) {
			// the report's own query (same code) is changed, else a new one is made – never another report's query
			$cur = str_starts_with($sourceId, 'SQL:') ? NuSqlSelect::load(substr($sourceId, 4)) : null;
			$selId = $cur && (string)$cur['sse_code'] === $src['code'] ? (string)$cur['zzzzsys_select_id'] : '';
			$code = $src['code'];
			if ($selId === '') for ($n = 2; (int)$db->value('SELECT COUNT(*) FROM zzzzsys_select WHERE sse_code = ?', [$code]); $n++) $code = mb_substr($src['code'], 0, 46) . '_' . $n;
			[$newSel, $errs] = NuSqlSelect::save(['sse_code' => $code, 'sse_description' => $src['description'], 'sse_sql' => $src['sql'], 'sse_edit' => '1',
				'sse_json' => $selId !== '' ? (string)($cur['sse_json'] ?? '') : ''], $selId);
			if ($errs) throw new RuntimeException(implode("\n", $errs));
			NuLog::write($selId !== '' ? 'sql_changed' : 'sql_created', 'zzzzsys_select', (string)$newSel, ['code' => $code, 'description' => $src['description'], 'via' => 'report_ai']);
			$sourceId = 'SQL:' . $newSel;
		}
		self::snapshot($report, 'ai', $note);
		$layout = NuReport::keepTypes(NuReport::layoutJson(self::toLayout($r['spec'], NuReport::layout((string)$report['sre_layout']))), (string)$report['sre_layout']);
		$db->update(NuReport::TABLE, ['sre_layout' => $layout, 'sre_zzzzsys_php_id' => $sourceId], 'zzzzsys_report_id', $id);
		$fields = ['sre_layout' => ['…', '…']];
		if ($sourceId !== (string)$report['sre_zzzzsys_php_id']) $fields['sre_zzzzsys_php_id'] = [(string)$report['sre_zzzzsys_php_id'], $sourceId];
		NuLog::write('report_ai', NuReport::TABLE, $id, ['code' => (string)$report['sre_code'], 'fields' => $fields, 'objects' => $r['plan']['objects'], 'note' => mb_substr($note, 0, 200)]);
		return ['objects' => (int)$r['plan']['objects'], 'source' => $sourceId];
	}

	/** Puts a version back (the current layout becomes a version first). */
	public static function restore(array $report, string $versionId): bool
	{
		if (!self::versionsReady()) return false;
		$db = nu_db();
		$id = (string)$report['zzzzsys_report_id'];
		$v = $db->one('SELECT * FROM ' . self::VERSIONS . ' WHERE zzzzsys_nunext_report_version_id = ? AND report_id = ?', [$versionId, $id]);
		if (!$v) return false;
		self::snapshot($report, 'restore');
		$row = ['sre_layout' => (string)$v['layout']];
		$src = (string)$v['source'];
		if ($src !== '' && preg_match('/^(PROCEDURE|TABLE|SQL):\S+$/', $src)) $row['sre_zzzzsys_php_id'] = $src;
		$db->update(NuReport::TABLE, $row, 'zzzzsys_report_id', $id);
		NuLog::write('report_restored', NuReport::TABLE, $id, ['code' => (string)$report['sre_code'], 'fields' => ['sre_layout' => ['…', '…']], 'version' => (string)$v['saved_at']]);
		return true;
	}

	// ================================================================= prompt and context file

	/** The format, explained for the AI (also part of the context file). */
	public static function formatHelp(): string
	{
		return <<<'TXT'
A report is described by ONE JSON object, format "nunext-report-1". All positions and sizes are millimetres.

{
  "format": "nunext-report-1",
  "report": {"paper": "A4", "orientation": "portrait"},      // paper: A4, A5, Letter, Legal; orientation: portrait or landscape
  "groups": [{"field": "cus_country", "dir": "asc"}],        // 0 to 5 sort groups, outermost first; the rows are sorted by them
  "bands": {
    "report_header": {"height": 22, "objects": [
      {"type": "text", "text": "Customers by country", "x": 15, "y": 5, "w": 120, "h": 9, "size": 18, "bold": true}]},
    "page_header": {"height": 8, "objects": [
      {"type": "text", "text": "Code", "x": 15, "y": 1, "w": 25, "h": 5, "bold": true},
      {"type": "text", "text": "Name", "x": 42, "y": 1, "w": 90, "h": 5, "bold": true},
      {"type": "text", "text": "Credit", "x": 160, "y": 1, "w": 35, "h": 5, "bold": true, "align": "right"},
      {"type": "line", "x": 15, "y": 7, "w": 180, "h": 0.5}]},
    "group_header_1": {"height": 8, "objects": [
      {"type": "field", "field": "cus_country", "x": 15, "y": 1.5, "w": 100, "h": 5, "bold": true, "size": 11}]},
    "detail": {"height": 6, "objects": [
      {"type": "field", "field": "cus_code", "x": 15, "y": 0.5, "w": 25, "h": 5},
      {"type": "field", "field": "cus_name", "x": 42, "y": 0.5, "w": 90, "h": 5},
      {"type": "field", "field": "cus_credit", "x": 160, "y": 0.5, "w": 35, "h": 5, "align": "right", "format": "N|1,000.00"}]},
    "group_footer_1": {"height": 8, "objects": [
      {"type": "sum", "field": "cus_credit", "x": 160, "y": 1, "w": 35, "h": 5, "align": "right", "bold": true, "format": "N|1,000.00"}]},
    "page_footer": {"height": 8, "objects": [
      {"type": "text", "text": "Page #page# of #pages#", "x": 15, "y": 1.5, "w": 180, "h": 5, "size": 8, "align": "right", "color": "#666666"}]},
    "report_footer": {"height": 8, "objects": [
      {"type": "sum", "field": "cus_credit", "x": 160, "y": 1, "w": 35, "h": 5, "align": "right", "bold": true, "format": "N|1,000.00"}]}
  }
}

Bands (print order): report_header (once, at the start), page_header (top of every page), group_header_1 … group_header_5 (when the
value of that sort group changes), detail (once per row), group_footer_5 … group_footer_1, page_footer (bottom of every page),
report_footer (once, right after the last row). Leave a band out when it is not needed. "group_header_2" needs two entries in
"groups". A band may have "page_break": true: a new page starts BEFORE the band (not for page_header / page_footer). For a new
page per group set it on that group's header (the first group still starts on page 1) – never on the group footer, which would
then print at the top of the next page.

Objects ("type"):
- "field": prints the column "field" of the current row.
- "text": prints "text". Hash variables are replaced: #page#, #pages#, #description# (the report's title), #code#, #nu_date#,
  #nu_date_time#, and the criteria fields of the launch form (#name#).
- "sum": the sum of column "field" – of the group when it stands in a group footer / header, of all rows in report_header / report_footer.
- "average": {"type": "average", "fields": ["a", "b"]} prints sum(a) / sum(b).
- "box": a filled rectangle ("color": "#dddddd"); "line": a thin filled rectangle (h 0.3 – 1). Draw them BEFORE the objects on top of them.
- "image": {"image": "<code of a stored image>"} or {"field": "<column with an image address / data URL>"}.

Every object needs x, y (from the left edge of the page / the top of its band), w, h. Optional: "size" (points, default 10), "bold",
"italic", "align" (left, right, center), "color" (text, "#rrggbb"), "background", "border": {"width": 1, "color": "#000000"},
"font", "format" (one of the listed formats – numbers "N|…", dates "D|…"), "content": "html" or "md" (the value is HTML / Markdown),
"min_rows" / "max_rows" (a field grows by lines; "min_rows": -1 hides an empty field), "id".

Rules:
- Use only the columns and criteria fields listed for this report – no other names, no expressions in "field".
- Keep the "id" of objects you keep or change; leave "id" out for new objects.
- Leave margins of about 15 mm left and right; x + w must stay inside the page (A4 portrait is 210 mm wide, landscape 297 mm).
- A text line of size 10 needs h 5; size 8: 4; size 12: 6; size 18: 9. The band's height must hold its objects (y + h).
- Right-align numbers and give them a number format; give date columns a date format.
- Return the WHOLE report (every band and object), not only the changes.
TXT;
	}

	/** The prompt for the user's AI chat. $opt: allow_sql (bool), tables (string[] – described when the AI may write the query). */
	public static function prompt(array $report, string $request, array $opt = []): string
	{
		$ctx = self::context($report);
		$L = NuReport::layout((string)$report['sre_layout']);
		$has = false;
		foreach ($L->groups as $G) foreach ($G->sections as $S) if ($S->objects) $has = true;
		$allowSql = !empty($opt['allow_sql']) && self::canSql();
		$p = "You design a PDF report for nuBuilder Next. Answer with ONE ```json code block that holds the complete report description – no other text, no follow-up questions.\n\n";
		$p .= "## Format\n\n" . self::formatHelp() . "\n\n";
		$p .= "## The report\n\n- Code: " . $report['sre_code'] . "\n- Title: " . $report['sre_description'] . "\n- Data source: " . NuReport::sourceLabel((string)$report['sre_zzzzsys_php_id']) . "\n";
		$p .= '- Columns of the data source: ' . ($ctx['columns'] ? implode(', ', $ctx['columns']) : ($ctx['known'] ? '(none)' : '(could not be read – use the names the request gives)')) . "\n";
		if ($ctx['criteria']) $p .= '- Criteria fields of the launch form (the same on every row; usable as "field" and as #name# in texts): ' . implode(', ', $ctx['criteria']) . "\n";
		$p .= '- Formats: ' . ($ctx['formats'] ? implode('   ', array_slice($ctx['formats'], 0, 60)) : '(none defined)') . "\n";
		$p .= '- Fonts: ' . implode(', ', array_slice($ctx['fonts'], 0, 30)) . "\n";
		if ($ctx['images']) $p .= '- Stored images (codes): ' . implode(', ', array_slice($ctx['images'], 0, 40)) . "\n";
		if ($allowSql) {
			$tables = array_values(array_filter(array_map('strval', (array)($opt['tables'] ?? []))));
			$p .= "\n## The query (optional)\n\nWhen the request needs other data than the columns above (joins, totals per row, other tables), add the key \"source\" to the JSON:\n"
				. '  "source": {"sql": "SELECT …", "code": "short_code", "description": "what the query returns"}' . "\n"
				. "Then use the column names of YOUR query in the report. Rules for the SQL: one SELECT statement, read only, " . (nu_db()->type === 'sqlsrv' ? 'Microsoft SQL Server (T-SQL)' : 'MySQL / MariaDB')
				. " syntax, give every expression an alias (AS name), unique column names, no SELECT *. Criteria fields can be used as '#name#' (in quotes). "
				. "Sorting is done by the report's \"groups\" – add ORDER BY only for the order inside the last group. Leave \"source\" out when the listed columns are enough.\n\n"
				. "Tables of the database:\n\n" . NuFormSpec::schemaText('', 40, $tables ?: []) . "\n";
		}
		if ($has) $p .= "\n## The current layout\n\nChange this report as the request says and return the whole description (keep the ids):\n\n```json\n"
			. json_encode(self::fromLayout($L), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n```\n";
		$p .= "\n## Request\n\n" . trim($request) . "\n";
		return $p;
	}

	/**
	 * The context file (Markdown) to attach to the chat: format, the report's fields (with types of a table source and – only when asked –
	 * a few sample rows), the current layout, the help page of the reports.
	 */
	public static function contextFile(array $report, bool $samples = false): string
	{
		$ctx = self::context($report);
		$src = (string)$report['sre_zzzzsys_php_id'];
		$md = '# nuBuilder Next – report context: ' . $report['sre_code'] . "\n\nAttach this file to the chat together with the prompt. nuBuilder Next " . NU_VERSION . ".\n\n";
		$md .= "## Report format\n\n```\n" . self::formatHelp() . "\n```\n\n";
		$md .= "## Data source\n\n" . NuReport::sourceLabel($src) . "\n\n";
		$types = [];
		if (str_starts_with($src, 'TABLE:')) { try { foreach (nu_db()->columns(substr($src, 6)) as $name => $c) $types[(string)$name] = (string)($c['type'] ?? ''); } catch (Throwable) {} }
		foreach ($ctx['columns'] as $c) $md .= '- `' . $c . '`' . (($types[$c] ?? '') !== '' ? ' – ' . $types[$c] : '') . "\n";
		if ($ctx['criteria']) $md .= "\nCriteria fields of the launch form: " . implode(', ', array_map(static fn($c) => "`$c`", $ctx['criteria'])) . "\n";
		if (str_starts_with($src, 'SQL:') && ($q = NuSqlSelect::load(substr($src, 4)))) $md .= "\nThe query:\n\n```sql\n" . trim((string)$q['sse_sql']) . "\n```\n";
		if ($samples && $ctx['columns']) {
			try {
				$hash = NuHash::plain();
				foreach ($ctx['criteria'] as $c) $hash[$c] ??= '';
				[, $rows] = NuReport::data($src, NuReport::runHash($report, $hash));
				$rows = array_slice($rows, 0, 3);
				if ($rows) {
					$cols = array_slice($ctx['columns'], 0, 20);
					$cell = static fn($v) => str_replace(['|', "\n", "\r"], ['\\|', ' ', ''], mb_strimwidth((string)NuSecurity::demoMask('', $v ?? ''), 0, 40, '…'));
					$md .= "\nSample rows (the first " . count($rows) . "):\n\n| " . implode(' | ', $cols) . " |\n|" . str_repeat(' --- |', count($cols)) . "\n";
					foreach ($rows as $r) $md .= '| ' . implode(' | ', array_map(static fn($c) => $cell($r[$c] ?? ''), $cols)) . " |\n";
				}
			} catch (Throwable) {
				// a source that needs criteria: no samples
			}
		}
		$md .= "\n## Formats\n\n" . ($ctx['formats'] ? implode("\n", array_map(static fn($f) => "- `$f`", $ctx['formats'])) : '(none defined)') . "\n";
		$md .= "\n## Fonts\n\n" . implode(', ', $ctx['fonts']) . "\n";
		if ($ctx['images']) $md .= "\n## Stored images\n\n" . implode(', ', $ctx['images']) . "\n";
		$L = NuReport::layout((string)$report['sre_layout']);
		$md .= "\n## The current layout\n\n```json\n" . json_encode(self::fromLayout($L), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n```\n";
		$help = NU_ROOT . '/docs/pages/06-02-reports.htm';
		if (is_file($help)) $md .= "\n## Guide: reports\n\n" . NuAiContext::htmlToMd((string)file_get_contents($help)) . "\n";
		return $md;
	}

	// ================================================================= the dialogue (report page and designer)

	/** The dialogue "Create / change with AI" + its data; assets/js/nureportai.js drives it. */
	public static function dialog(array $report, bool $canSave, bool $open = false): string
	{
		$L = NuReport::layout((string)$report['sre_layout']);
		$n = 0;
		foreach ($L->groups as $G) foreach ($G->sections as $S) $n += count($S->objects);
		$canSql = self::canSql();
		$keys = ['fai_copied', 'fai_copy_failed', 'fai_ok', 'fai_errors', 'fai_warnings', 'fai_paste_failed', 'rai_plan_page', 'rai_plan_groups', 'rai_plan_bands', 'rai_plan_objects',
			'rai_plan_changes', 'rai_plan_new', 'rai_plan_source', 'rai_plan_none', 'rai_dirty', 'rpt_portrait', 'rpt_landscape', 'rai_sql_ok'];
		$msg = [];
		foreach ($keys as $k) $msg[$k] = t($k);
		$data = ['url' => nu_url('reportai', ['id' => (string)$report['zzzzsys_report_id']]), 'csrf' => nu_csrf_token(), 'msg' => $msg, 'open' => $open, 'objects' => $n];
		$tables = '';
		if ($canSql) { try { foreach (NuFormSpec::appTables() as $tb) $tables .= '<option value="' . h($tb) . '">' . h($tb) . '</option>'; } catch (Throwable) {} }
		$tip = static fn(string $k) => '<span class="nuInfoTip ms-1" tabindex="0" role="img" data-bs-toggle="tooltip" data-bs-custom-class="nuTipWide" aria-label="' . h(t($k)) . '" title="' . h(t($k)) . '"><i class="bi bi-info-circle"></i></span>';
		$title = t($n ? 'rai_title_change' : 'rai_title_new');
		ob_start(); ?>
<div class="modal fade" id="raiDlg" tabindex="-1" aria-hidden="true" aria-labelledby="raiTitle" data-rai="<?= h((string)json_encode($data, JSON_UNESCAPED_UNICODE)) ?>">
	<div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content">
		<div class="modal-header py-2"><h2 class="modal-title fs-6" id="raiTitle"><i class="bi bi-stars me-1"></i><?= h($title) ?> · <code><?= h((string)$report['sre_code']) ?></code></h2>
			<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= h(t('close')) ?>"></button></div>
		<div class="modal-body">
			<label class="form-label fw-semibold" for="raiReq"><span class="badge rounded-pill text-bg-secondary me-1">1</span><?= h(t($n ? 'rai_describe_change' : 'rai_describe_new')) ?></label>
			<textarea class="form-control mb-1" id="raiReq" rows="5" placeholder="<?= h(t($n ? 'rai_req_ph_change' : 'rai_req_ph_new')) ?>"></textarea>
			<div class="invalid-feedback" id="raiReqErr"><?= h(t('rai_err_request')) ?></div>
			<?php if ($canSql): ?>
			<div class="form-check form-switch mt-2"><input class="form-check-input" type="checkbox" role="switch" id="raiSql">
				<label class="form-check-label" for="raiSql"><?= h(t('rai_sql')) ?></label><?= $tip('rai_sql_tip') ?></div>
			<div class="mt-2" id="raiTablesBox" hidden>
				<label class="form-label small" for="raiTables"><?= h(t('rai_tables')) ?></label>
				<select class="nuListTomSelect" id="raiTables" multiple placeholder="<?= h(t('fai_tables_pick_ph')) ?>"><?= $tables ?></select>
			</div>
			<?php endif; ?>
			<div class="d-flex flex-wrap gap-2 align-items-center mt-3">
				<button type="button" class="btn btn-primary" id="raiCopy"><i class="bi bi-clipboard me-1"></i><?= h(t('fai_copy')) ?></button>
				<div class="btn-group">
					<button type="button" class="btn btn-outline-primary" id="raiCtx"><i class="bi bi-file-earmark-arrow-down me-1"></i><?= h(t('fai_ctx')) ?></button>
					<button type="button" class="btn btn-outline-primary dropdown-toggle dropdown-toggle-split" data-bs-toggle="dropdown" aria-expanded="false" aria-label="<?= h(t('fai_ctx_more')) ?>"></button>
					<ul class="dropdown-menu"><li><div class="dropdown-item-text"><div class="form-check form-switch mb-0"><input class="form-check-input" type="checkbox" role="switch" id="raiCtxSamples">
						<label class="form-check-label" for="raiCtxSamples"><?= h(t('rai_ctx_samples')) ?></label></div></div></li></ul>
				</div><?= $tip('rai_ctx_tip') ?>
				<span class="small text-body-secondary" id="raiCopyMsg" aria-live="polite"></span>
			</div>
			<?= NuAiProvider::host('report', 'raiAsk') ?>
			<details class="mt-2" id="raiPromptBox" hidden><summary class="small"><?= h(t('fai_show_prompt')) ?></summary>
				<textarea class="form-control form-control-sm font-monospace mt-2" id="raiPrompt" rows="7" readonly aria-label="<?= h(t('fai_show_prompt')) ?>"></textarea></details>
			<hr>
			<label class="form-label fw-semibold" for="raiAns"><span class="badge rounded-pill text-bg-secondary me-1">2</span><?= h(t('rai_answer')) ?></label>
			<textarea class="form-control font-monospace small mb-2" id="raiAns" rows="6" placeholder="```json …```"></textarea>
			<div class="d-flex flex-wrap gap-2">
				<button type="button" class="btn btn-outline-secondary" id="raiPaste" title="<?= h(t('fai_paste_tip')) ?>"><i class="bi bi-clipboard-check me-1"></i><?= h(t('fai_paste')) ?></button>
				<button type="button" class="btn btn-primary" id="raiCheck"><i class="bi bi-check2-circle me-1"></i><?= h(t('fai_check')) ?></button>
			</div>
			<div class="mt-3" id="raiResult" hidden>
				<div id="raiMsgs"></div>
				<div id="raiPlan" class="small"></div>
			</div>
		</div>
		<div class="modal-footer" id="raiFoot" hidden>
			<span class="small text-body-secondary me-auto"><?= h(t('rai_apply_help')) ?></span>
			<button type="button" class="btn btn-outline-primary" id="raiPreview"><i class="bi bi-file-earmark-pdf me-1"></i><?= h(t('rai_preview')) ?></button>
			<?php if ($canSave): ?><button type="button" class="btn nuBtnAct nuBtnSave" id="raiApply"><i class="bi bi-check-lg me-1"></i><?= h(t('rai_apply')) ?></button><?php endif; ?>
		</div>
	</div></div>
</div>
<form method="post" action="<?= h($data['url']) ?>" target="_blank" id="raiPreviewForm" class="d-none">
	<?= nu_csrf_field() ?><input type="hidden" name="action" value="preview"><input type="hidden" name="answer" value=""><input type="hidden" name="allow_sql" value="0">
</form>
<?php
		return (string)ob_get_clean();
	}
}
