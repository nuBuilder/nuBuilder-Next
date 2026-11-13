<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * Calendars (0.8.1165, FullCalendar 6 standard – vendor/bundled/fullcalendar-*, assets/js/nu/nucalendar.js). Three places:
 *  - list view: a form list with sfo_json nunext.calendar {start, end, title, color, group: list column index, allday, view, edit, drag,
 *    min, max} – page ?p=calendar&f=<form>, the list's search / filters / advanced search / column access apply (NuBrowse::filtered);
 *  - calendar object: an HTML object with sob_all_json nunext.calendar {src: form | sql, form, filter, sql, edit_form, start_field,
 *    end_field, view, height, edit, drag} – nuBuilder Forte shows the HTML object's own code (empty);
 *  - dashboard widget type "calendar" (NuDashboard): SQL / query / procedure rows, read-only, a click opens the record (link form).
 * SQL sources return columns by name: id, start, end, title, color, group, allday. Hash variables of the record (#RECORD_ID# …) work.
 * Editing (edit / drag) goes through NuRecord::save of the edit form: its rights, validation rules, Before / After Save, audit trail.
 */
final class NuCalendar
{
	public const VIEWS = ['month' => 'dayGridMonth', 'week' => 'timeGridWeek', 'day' => 'timeGridDay', 'list' => 'listWeek'];
	public const MAX = 2000;
	/** Colours of the groups (one per group value, by its hash) – readable with white text in both themes. */
	public const PALETTE = ['#2563eb', '#15803d', '#c2410c', '#7e22ce', '#0e7490', '#be185d', '#4d7c0f', '#a16207', '#4338ca', '#0f766e'];
	private const TIME_RE = '/^([01]\d|2[0-4]):[0-5]\d$/';

	// ------------------------------------------------------------------ settings

	/** List view setting (sfo_json nunext.calendar) cleaned; null = off. Column indexes are checked against the list later. */
	public static function cleanList(mixed $c): ?array
	{
		$col = static fn($v) => (is_string($v) || is_int($v)) && preg_match('/^[\w.-]{1,50}$/', (string)$v) ? (string)$v : '';
		if (!is_array($c) || $col($c['start'] ?? '') === '') return null;
		$out = [];
		foreach (self::COLS as $k) if (($v = $col($c[$k] ?? '')) !== '') $out[$k] = $v;
		return $out + self::common($c);
	}

	/** Calendar object setting (sob_all_json nunext.calendar) cleaned; null = not a calendar. */
	public static function cleanObject(mixed $c): ?array
	{
		if (!is_array($c)) return null;
		$src = ($c['src'] ?? '') === 'sql' ? 'sql' : 'form';
		$out = ['src' => $src];
		$id = static fn($v) => is_string($v) && preg_match('~^[\w.-]{1,100}$~', $v) ? $v : '';
		if ($src === 'form') {
			$out['form'] = $id($c['form'] ?? '');
			$out['filter'] = mb_substr(trim((string)($c['filter'] ?? '')), 0, 500);
		} else {
			$out['sql'] = mb_substr(trim((string)($c['sql'] ?? '')), 0, 20000);
			$out['edit_form'] = $id($c['edit_form'] ?? '');
			$out['start_field'] = $id($c['start_field'] ?? '');
			$out['end_field'] = $id($c['end_field'] ?? '');
		}
		$h = (int)($c['height'] ?? 0);
		$out['height'] = $h > 0 ? max(250, min(2000, $h)) : 0;
		return $out + self::common($c);
	}

	private static function common(array $c): array
	{
		$b = static fn($v) => !empty($v) && $v !== '0' && $v !== 'false';
		$view = isset(self::VIEWS[(string)($c['view'] ?? '')]) ? (string)$c['view'] : 'month';
		$t = static fn($v, $d) => is_string($v) && preg_match(self::TIME_RE, $v) ? $v : $d;
		return ['allday' => $b($c['allday'] ?? false), 'view' => $view, 'edit' => $b($c['edit'] ?? true), 'drag' => $b($c['drag'] ?? false),
			'min' => $t($c['min'] ?? '', '00:00'), 'max' => $t($c['max'] ?? '', '24:00')];
	}

	/** Column keys of the list view setting: the list columns' ids (zzzzsys_browse_id) – also a column number from 0 (written by hand). */
	public const COLS = ['start', 'end', 'title', 'color', 'group'];

	/** The stored list view setting of a form (column ids), or null. */
	public static function storedOf(NuFormMeta $meta): ?array
	{
		return self::cleanList(NuFormMeta::nunextJson((string)($meta->form['sfo_json'] ?? ''))['calendar'] ?? null);
	}

	/** The list view setting of a form with column numbers (positions in the list), or null – also when the start column is gone. */
	public static function ofForm(NuFormMeta $meta): ?array
	{
		$c = $meta->hasBrowse() ? self::storedOf($meta) : null;
		if (!$c) return null;
		$ids = array_map(static fn($b) => (string)$b['zzzzsys_browse_id'], array_values($meta->browse));
		foreach (self::COLS as $k) {
			if (!isset($c[$k])) continue;
			$i = array_search($c[$k], $ids, true);
			if ($i === false && ctype_digit($c[$k]) && (int)$c[$k] < count($ids)) $i = (int)$c[$k];
			if ($i === false) unset($c[$k]); else $c[$k] = (int)$i;
		}
		return isset($c['start']) ? $c : null;
	}

	/** The design API's form.save field "calendar" → the key of sfo_json nunext (null removes it). */
	public static function keysIn(array $fields): array
	{
		if (!array_key_exists('calendar', $fields)) return [];
		$c = is_string($fields['calendar']) ? json_decode($fields['calendar'], true) : $fields['calendar'];
		return ['calendar' => self::cleanList($c)];   // null removes the key
	}

	/** Forms with a list calendar the user may browse: [[id, "code – description"], …] (design mode: source of calendar objects). */
	public static function forms(): array
	{
		$out = [];
		try {
			foreach (nu_db()->all("SELECT zzzzsys_form_id, sfo_code, sfo_description FROM zzzzsys_form WHERE sfo_json LIKE ? ORDER BY sfo_code", ['%"calendar"%']) as $r) {
				$m = NuFormMeta::load((string)$r['zzzzsys_form_id']);
				if ($m && self::ofForm($m) && NuAcl::can($m->id, 'browse')) $out[] = [$m->id, trim($r['sfo_code'] . ' – ' . $r['sfo_description'], ' –')];
			}
		} catch (Throwable) {
		}
		return $out;
	}

	/** The calendar setting of an HTML object, or null. */
	public static function ofObject(array $o): ?array
	{
		if (($o['sob_all_type'] ?? '') !== 'html') return null;
		$n = $o['nunext'] ?? NuFormMeta::nunextJson($o['sob_all_json'] ?? null);
		return isset($n['calendar']) ? self::cleanObject($n['calendar']) : null;
	}

	/** Object of a form by its id (sob_all_id) that is a calendar. */
	public static function object(NuFormMeta $meta, string $id): ?array
	{
		foreach ($meta->objects as $o) if ((string)$o['sob_all_id'] === $id && self::ofObject($o)) return $o;
		return null;
	}

	/**
	 * The edit form's field (object id) behind a list column – only for a plain column name in the browse SQL whose object is on the
	 * form (input / select / calc …). Moving and creating need it.
	 */
	public static function fieldOf(NuFormMeta $meta, int $index): string
	{
		$b = $meta->browse[$index] ?? null;
		if (!$b) return '';
		$disp = trim((string)$b['sbr_display']);
		if (!preg_match('/^(?:[A-Za-z_]\w*\.)?([A-Za-z_]\w*)$/', $disp, $m)) return '';
		foreach ($meta->objects as $o) if (strcasecmp((string)$o['sob_all_id'], $m[1]) === 0 && in_array($o['sob_all_type'], ['input', 'select', 'calc', 'display', 'textarea'], true)) return (string)$o['sob_all_id'];
		return '';
	}

	// ------------------------------------------------------------------ events

	/** Range of the request (FullCalendar's start / end) as 'Y-m-d H:i:s' – the time zone part is dropped (the server's local time). */
	public static function rangeOf(string $start, string $end): array
	{
		$p = static function (string $v): string {
			$v = substr(str_replace('T', ' ', trim($v)), 0, 19);
			return preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}(:\d{2})?)?$/', $v) ? (strlen($v) === 10 ? $v . ' 00:00:00' : (strlen($v) === 16 ? $v . ':00' : $v)) : '';
		};
		$s = $p($start); $e = $p($end);
		if ($s === '' || $e === '') { $s = date('Y-m-01 00:00:00'); $e = date('Y-m-d 00:00:00', strtotime('+2 months', strtotime($s))); }
		return [$s, $e];
	}

	/** Events of a form list's calendar view (A1) or of a calendar object with source "form" (A2: $filter = run filter, hash filled). */
	public static function listEvents(NuFormMeta $meta, array $cfg, array $hash, array $opt, string $from, string $to): array
	{
		$F = NuBrowse::filtered($meta, $hash, $opt);
		$col = $F['col'];
		$c = static fn(?int $i) => $i !== null && isset($F['cols'][$i]) && $F['cols'][$i]['expr'] !== 'NULL' && $F['cols'][$i]['mask'] === null ? $col($F['cols'][$i]) : null;
		$sx = $c($cfg['start']);
		if ($sx === null) return ['events' => [], 'error' => t('cal_err_start')];
		$ex = $c($cfg['end'] ?? null);
		$where = $F['where']; $params = $F['params'];
		$where[] = "$sx IS NOT NULL";
		$where[] = "$sx < ?"; $params[] = $to;
		$where[] = 'COALESCE(' . ($ex ?? $sx) . ", $sx) >= ?"; $params[] = substr($from, 0, 10);
		$sel = "SELECT nuT.nu_pk AS id, $sx AS nu_s" . ($ex ? ", $ex AS nu_e" : '');
		foreach (['title', 'color', 'group'] as $k) if (($x = $c($cfg[$k] ?? null)) !== null) $sel .= ", $x AS nu_$k";
		$rows = nu_db()->page($sel . " FROM ({$F['inner']}) nuT WHERE " . implode(' AND ', $where), $params, 'nu_s', self::MAX + 1, 0);
		$title = isset($cfg['title'], $F['cols'][$cfg['title']]) ? $F['cols'][$cfg['title']]['format'] : '';
		$out = [];
		self::$taken = [];
		foreach ($rows as $r) {
			$t = array_key_exists('nu_title', $r) ? NuHtmlClean::cellText((string)NuFormat::format(NuSecurity::demoMask('', $r['nu_title']), $title)) : (string)$r['id'];
			$out[] = self::event((string)$r['id'], $r['nu_s'], $r['nu_e'] ?? null, $t, $r['nu_color'] ?? null, $r['nu_group'] ?? null, $cfg['allday']);
		}
		return self::limit($out);
	}

	/** Events of an SQL source (calendar object A2): columns id, start, end, title, color, group, allday – read-only, with the hash. */
	public static function sqlEvents(string $sql, array $hash, string $from, string $to, bool $allday): array
	{
		$sql = rtrim(trim($sql), "; \n\r\t");
		if ($sql === '') return ['events' => [], 'error' => t('dash_err_no_source')];
		$res = NuDashboard::runSql('SELECT * FROM (' . $sql . ') nuC', $hash, self::MAX * 5);
		return self::fromRows($res['columns'], $res['rows'], $from, $to, $allday);
	}

	/** Rows with named columns (SQL source, dashboard widget) → events inside [from, to). */
	public static function fromRows(array $cols, array $rows, string $from = '', string $to = '', bool $allday = false): array
	{
		$ix = [];
		self::$taken = [];
		foreach ($cols as $i => $n) $ix[strtolower((string)$n)] = $i;
		if (!isset($ix['start'])) return ['events' => [], 'error' => t('cal_err_columns')];
		$g = static fn(array $r, string $k) => isset($ix[$k]) ? $r[$ix[$k]] : null;
		$out = [];
		foreach ($rows as $r) {
			$s = $g($r, 'start');
			if ($s === null || $s === '') continue;
			$e = $g($r, 'end');
			if ($from !== '' && ((string)$s >= $to || (string)($e ?: $s) < substr($from, 0, 10))) continue;
			$ad = $g($r, 'allday');
			$out[] = self::event((string)($g($r, 'id') ?? ''), $s, $e, (string)($g($r, 'title') ?? ''), $g($r, 'color'), $g($r, 'group'), $ad === null ? $allday : !empty($ad) && $ad !== '0');
		}
		return self::limit($out);
	}

	private static function limit(array $events): array
	{
		$more = count($events) > self::MAX;
		return ['events' => array_slice($events, 0, self::MAX), 'more' => $more];
	}

	/** One event for FullCalendar. A date without a time (or allday) = an all-day event; its end is exclusive there, so one day is added. */
	public static function event(string $id, mixed $start, mixed $end, string $title, mixed $color, mixed $group, bool $allday): array
	{
		$s = self::norm((string)$start);
		$e = $end === null || $end === '' ? '' : self::norm((string)$end);
		$isDay = $allday || strlen($s) === 10;
		if ($isDay) {
			$s = substr($s, 0, 10);
			$e = $e !== '' ? date('Y-m-d', strtotime(substr($e, 0, 10) . ' +1 day')) : '';
		}
		$ev = ['id' => $id, 'title' => $title !== '' ? $title : '–', 'start' => $s, 'allDay' => $isDay];
		if ($e !== '' && $e > $s) $ev['end'] = $e;
		$grp = $group === null ? '' : trim(NuHtmlClean::cellText((string)$group));
		$col = self::color((string)($color ?? ''), $grp);
		if ($col !== '') { $ev['backgroundColor'] = $ev['borderColor'] = $col; }
		if ($grp !== '') $ev['extendedProps'] = ['group' => $grp];
		return $ev;
	}

	/** 'Y-m-d' or 'Y-m-dTH:i:s' (a time of 00:00:00 stays: the column holds a time). */
	private static function norm(string $v): string
	{
		$v = trim($v);
		if (preg_match('/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2})(:\d{2})?/', $v, $m)) return $m[1] . 'T' . $m[2] . ($m[3] ?? ':00');
		return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : substr($v, 0, 10);
	}

	/** A colour column value (#rgb / #rrggbb / a CSS colour name) wins; else the group's colour from the palette. */
	public static function color(string $c, string $group): string
	{
		$c = trim($c);
		if (preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', $c) || preg_match('/^[a-z]{3,20}$/i', $c)) return $c;
		if ($group === '') return '';
		if (isset(self::$taken[$group])) return self::$taken[$group];
		// by the value's hash – the next free colour when another group of this answer has it (up to ten groups without a double)
		$n = count(self::PALETTE);
		$i = (int)(hexdec(substr(md5($group), 0, 6)) % $n);
		for ($k = 0; $k < $n && in_array(self::PALETTE[($i + $k) % $n], self::$taken, true); $k++);
		return self::$taken[$group] = self::PALETTE[($i + ($k < $n ? $k : 0)) % $n];
	}

	/** Group colours given in the current answer. */
	private static array $taken = [];

	// ------------------------------------------------------------------ moving (drag and drop)

	/**
	 * Moves / resizes the record $pk of $meta: the start and end fields get the new values, saved through NuRecord::save (rights,
	 * field access, validation, rules, Before / After Save, audit). $end '' = no end field or none sent.
	 */
	public static function move(NuFormMeta $meta, string $pk, string $startField, string $endField, string $start, string $end, bool $allDay): array
	{
		// the same checks as Save on the form (pages/nuformapi.php)
		$rights = NuAcl::rights($meta->id);
		if (!empty($rights['no_edits']) || empty($rights['edit']) || empty($rights['save']) || NuSecurity::runtimeBlocked($meta) || $meta->isDraft() || NuDemo::active()) return ['ok' => false, 'error' => t('err_forbidden')];
		if (NuFormStatus::blocked(NuFormStatus::of($meta->id))) return ['ok' => false, 'error' => t('err_forbidden')];
		if ($startField === '') return ['ok' => false, 'error' => t('cal_err_move')];
		$row = NuRecord::load($meta, $pk);
		if (!$row) return ['ok' => false, 'error' => t('record_not_found')];
		$val = static function (string $v, bool $day, bool $isEnd): string {
			$v = str_replace('T', ' ', substr(trim($v), 0, 19));
			if ($day) {
				$d = substr($v, 0, 10);
				return $isEnd ? date('Y-m-d', strtotime($d . ' -1 day')) : $d;   // FullCalendar's all-day end is exclusive
			}
			return strlen($v) === 16 ? $v . ':00' : $v;
		};
		// the record as stored (its fields and sub-form rows, unchanged – Before Save and validation see the whole record), only start / end edited
		$plain = static function (NuFormMeta $m, array $r): array {
			$v = [];
			foreach ($m->objects as $o) {
				$f = (string)$o['sob_all_id'];
				if (($c = NuRecord::column($r, $f)) !== null) $v[$f] = $r[$c] === null ? '' : (string)$r[$c];
			}
			return $v;
		};
		$values = $plain($meta, $row);
		$values[$startField] = $val($start, $allDay, false);
		$edited = [$startField];
		if ($endField !== '' && $end !== '') { $values[$endField] = $val($end, $allDay, true); $edited[] = $endField; }
		$subforms = [];
		foreach ($meta->objects as $o) {
			if ($o['sob_all_type'] !== 'subform' || !($sub = NuFormMeta::load((string)$o['sob_subform_zzzzsys_form_id']))) continue;
			$rows = [];
			foreach (array_slice(NuRecord::subformRows($o, $pk), 0, 2000) as $sr) {
				$pc = NuRecord::column($sr, $sub->primaryKey());
				$rows[] = ['pk' => $pc !== null ? (string)$sr[$pc] : '', 'values' => $plain($sub, $sr), 'edited' => []];
			}
			$subforms[(string)$o['sob_all_id']] = ['rows' => $rows];
		}
		$res = NuRecord::save($meta, ['record_id' => $pk, 'values' => $values, 'edited' => $edited, 'subforms' => $subforms]);
		if (empty($res['ok'])) {
			$msg = $res['error'] ?? (isset($res['errors'][0]['message']) ? strip_tags((string)$res['errors'][0]['message']) : t('form_error'));
			return ['ok' => false, 'error' => $msg];
		}
		NuLog::write('calendar_moved', $meta->table(), $pk, ['form' => $meta->code(), 'start' => $values[$startField]]);
		return ['ok' => true];
	}

	/**
	 * Values of a new record opened from the calendar (?p=form&r=-1&nv={"field": "value"}): only input / select fields of the form, dates
	 * and times only – the user still sees and can change them before saving.
	 */
	public static function newValues(NuFormMeta $meta, string $json): array
	{
		if ($json === '' || strlen($json) > 1000) return [];
		$nv = json_decode($json, true);
		if (!is_array($nv)) return [];
		$out = [];
		foreach ($meta->objects as $o) {
			$f = (string)$o['sob_all_id'];
			if (!isset($nv[$f]) || !is_string($nv[$f]) || !in_array($o['sob_all_type'], ['input', 'select', 'calc'], true)) continue;
			$v = str_replace('T', ' ', $nv[$f]);
			if (preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}(:\d{2})?)?$/', $v)) $out[$f] = strlen($v) === 16 ? $v . ':00' : $v;
		}
		return $out;
	}

	// ------------------------------------------------------------------ browser

	/** What the browser needs of the list view of $meta (button #nuCalendarOpen): no SQL, no column expressions. */
	public static function listClient(NuFormMeta $meta, array $cfg, array $state): array
	{
		return ['f' => $meta->id, 'state' => $state, 'title' => t('cal_title') . ' – ' . ($meta->description() ?: $meta->code())] + self::editClient($meta, $cfg,
			NuCalendar::fieldOf($meta, $cfg['start']), isset($cfg['end']) ? NuCalendar::fieldOf($meta, $cfg['end']) : '');
	}

	/** What the browser needs of a calendar object (A2); null when its source is missing or not readable. */
	public static function objectClient(NuFormMeta $host, array $o, string $recordId): ?array
	{
		$c = self::ofObject($o);
		if (!$c) return null;
		$base = ['f' => $host->id, 'o' => (string)$o['sob_all_id'], 'r' => $recordId, 'height' => $c['height']];
		if ($c['src'] === 'sql') {
			$edit = $c['edit_form'] !== '' ? NuFormMeta::load($c['edit_form']) : null;
			$ok = $edit && NuAcl::can($edit->id);
			return $base + ($ok ? self::editClient($edit, $c, $edit->object($c['start_field']) ? $c['start_field'] : '', $c['end_field'] !== '' && $edit->object($c['end_field']) ? $c['end_field'] : '')
				: ['view' => $c['view'], 'min' => $c['min'], 'max' => $c['max'], 'drag' => false, 'add' => false, 'open' => '']);
		}
		$list = $c['form'] !== '' ? NuFormMeta::load($c['form']) : null;
		$lc = $list ? self::ofForm($list) : null;
		if (!$list || !$lc || !NuAcl::can($list->id, 'browse')) return null;
		$lc = array_replace($lc, array_intersect_key($c, ['edit' => 1, 'drag' => 1, 'view' => 1]));
		return $base + self::editClient($list, $lc, self::fieldOf($list, $lc['start']), isset($lc['end']) ? self::fieldOf($list, $lc['end']) : '');
	}

	/** Opening, adding and moving – each only with the edit form's rights. */
	private static function editClient(NuFormMeta $m, array $cfg, string $sf, string $ef): array
	{
		$r = NuAcl::rights($m->id);
		$edit = $m->hasEdit() && !empty($r['edit']) && !empty($cfg['edit']);
		return ['view' => $cfg['view'], 'min' => $cfg['min'], 'max' => $cfg['max'], 'open' => $m->hasEdit() ? $m->id : '',
			'add' => $edit && !empty($r['add']) && !empty($r['create']) && $sf !== '', 'drag' => $edit && !empty($cfg['drag']) && !empty($r['save']) && empty($r['no_edits']) && $sf !== '',
			'sf' => $sf, 'ef' => $ef];
	}

	/** Texts of the calendar script. */
	public static function texts(): array
	{
		$k = ['cal_title', 'cal_today', 'cal_month', 'cal_week', 'cal_day', 'cal_list', 'cal_moved', 'cal_open', 'cal_all_groups', 'cal_group',
			'cal_no_events', 'cal_too_many', 'close', 'form_error'];
		$out = [];
		foreach ($k as $x) $out[$x] = t($x);
		return $out;
	}

	/** <script> of the calendar (loads FullCalendar on first use). */
	public static function scriptTag(): string
	{
		return '<script src="' . nu_asset('assets/js/nu/nucalendar.js') . '" data-fc="' . h(nu_asset(NU_FULLCALENDAR . '/fullcalendar.min.js'))
			. '" data-fc-css="' . h(nu_asset(NU_FULLCALENDAR . '/fullcalendar.css')) . '" data-fc-locales="' . h(nu_asset(NU_FULLCALENDAR . '/locales-all.min.js'))
			. '" data-url="' . h(nu_url('calendar')) . '" data-csrf="' . h(nu_csrf_token()) . '" data-texts="' . h(json_encode(self::texts(), JSON_UNESCAPED_UNICODE)) . '"></script>';
	}

	/** The host element of a calendar: data-nu-cal = what the script needs (never the SQL). */
	public static function host(array $client, string $id = '', string $cls = ''): string
	{
		return '<div class="nuCalHost ' . h($cls) . '"' . ($id !== '' ? ' id="' . h($id) . '"' : '') . ' data-nu-cal="' . h(json_encode($client, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '"></div>';
	}
}
