<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/** Metadata of one nuBuilder form: zzzzsys_form + tabs + objects + events + browse columns. */
final class NuFormMeta
{
	public readonly string $id;
	public array $form;
	/** @var array<int,array> tabs ordered by syt_order */
	public array $tabs = [];
	/** @var array<int,array> objects ordered by tab then sob_all_order */
	public array $objects = [];
	/** sob_all_id => object */
	public array $byId = [];
	/** zzzzsys_object_id => [event => js] */
	public array $events = [];
	/** nuBuilder system forms whose browse columns (sbr_display) have a filter unless the form builder says otherwise. */
	public const DEFAULT_FILTERS = ['nunotes' => ['noc_name']];

	/** browse columns ordered by sbr_order */
	public array $browse = [];

	private static array $cache = [];
	/** Forms the live forms loaded so far name as sub-form or lookup (0.8.1343, review P6): loaded together on the first miss. */
	private static array $wanted = [];
	/** Live forms whose hook code (<form>_BB … _AD) is not read yet: read together on the first hook asked for. */
	private static array $phpWanted = [];
	/** Hook suffixes of a form read with one statement (phpById). */
	private const HOOKS = ['BB', 'BE', 'BS', 'AS', 'BD', 'AD'];
	/** From a snapshot (form drafts, 0.8.386): zzzzsys_php_id => sph_php; null = read from the database. */
	private ?array $phpRows = null;

	/**
	 * The form for the runtime – the draft when this session previews one (form drafts, 0.8.386); $live: always the live form
	 * (form builder, publish).
	 */
	public static function load(string $formId, bool $live = false): ?self
	{
		if (!$live && class_exists('NuFormDraft') && ($snap = NuFormDraft::previewSnapshot($formId)) !== null) {
			return self::$cache['draft:' . $formId] ??= self::fromSnapshot($snap);
		}
		if (array_key_exists($formId, self::$cache)) return self::$cache[$formId];
		// a sub-form or lookup form of a form already loaded: all of them in one statement per table (0.8.1343, review P6 – an order
		// record with a lookup, a sub-form and a lookup inside it read 4 × 5 statements, now 5 + 5 + 5)
		if (isset(self::$wanted[$formId])) {
			self::prime(array_keys(self::$wanted));
			if (array_key_exists($formId, self::$cache)) return self::$cache[$formId];
		}
		$db   = nu_db();
		$form = $db->one('SELECT * FROM zzzzsys_form WHERE zzzzsys_form_id = ?', [$formId]);
		return self::$cache[$formId] = $form ? new self($form) : null;
	}

	/**
	 * The live definitions of several forms with one statement per table (form, tabs, objects, events, list columns) – the forms a
	 * page shows inside another (sub-forms, lookups). Forms already loaded or shown as a draft preview are left out.
	 * @param list<string> $ids
	 */
	public static function prime(array $ids): void
	{
		$todo = [];
		foreach ($ids as $id) {
			$id = (string)$id;
			unset(self::$wanted[$id]);
			if ($id === '' || array_key_exists($id, self::$cache)) continue;
			if (class_exists('NuFormDraft') && NuFormDraft::previewSnapshot($id) !== null) continue;
			$todo[$id] = true;
		}
		if (!$todo) return;
		$todo = array_keys($todo);
		$db   = nu_db();
		$in   = implode(',', array_fill(0, count($todo), '?'));
		$by   = static function (array $rows, string $col): array {
			$out = [];
			foreach ($rows as $r) $out[(string)$r[$col]][] = $r;
			return $out;
		};
		$forms = $by($db->all("SELECT * FROM zzzzsys_form WHERE zzzzsys_form_id IN ($in)", $todo), 'zzzzsys_form_id');
		foreach ($todo as $id) if (!isset($forms[$id])) self::$cache[$id] = null;
		$ids = array_keys($forms);
		if (!$ids) return;
		$in  = implode(',', array_fill(0, count($ids), '?'));
		$tabs    = $by($db->all("SELECT * FROM zzzzsys_tab WHERE syt_zzzzsys_form_id IN ($in) ORDER BY syt_order, zzzzsys_tab_id", $ids), 'syt_zzzzsys_form_id');
		$objects = $by($db->all("SELECT * FROM zzzzsys_object WHERE sob_all_zzzzsys_form_id IN ($in)", $ids), 'sob_all_zzzzsys_form_id');
		$events  = $by($db->all("SELECT e.sev_zzzzsys_object_id, e.sev_event, e.sev_javascript, o.sob_all_zzzzsys_form_id AS nu_form FROM zzzzsys_event e"
			. " JOIN zzzzsys_object o ON o.zzzzsys_object_id = e.sev_zzzzsys_object_id WHERE o.sob_all_zzzzsys_form_id IN ($in)", $ids), 'nu_form');
		$browse  = $by($db->all("SELECT * FROM zzzzsys_browse WHERE sbr_zzzzsys_form_id IN ($in) ORDER BY sbr_order, zzzzsys_browse_id", $ids), 'sbr_zzzzsys_form_id');
		foreach ($ids as $id) {
			self::$cache[$id] = new self($forms[$id][0], null, ['tabs' => $tabs[$id] ?? [], 'objects' => $objects[$id] ?? [],
				'events' => $events[$id] ?? [], 'browse' => $browse[$id] ?? []]);
		}
	}

	/** A form from a NuFormSnapshot (a draft) – the same as load() gives for the live rows. */
	public static function fromSnapshot(array $snap): self
	{
		return new self((array)$snap['form'], $snap);
	}

	public static function byCode(string $code): ?self
	{
		// nuBuilder Forte: nuForm('code:<form code>', …) opens a form by its code
		if (strncasecmp($code, 'code:', 5) === 0) $code = trim(substr($code, 5));
		if ($code === '') return null;
		$id = nu_db()->value('SELECT zzzzsys_form_id FROM zzzzsys_form WHERE sfo_code = ?', [$code]);
		return $id ? self::load((string)$id) : null;
	}

	public static function forget(string $formId): void
	{
		unset(self::$cache[$formId], self::$cache['draft:' . $formId], self::$phpWanted[$formId]);
	}

	/** Forgets every form of this request (tests). */
	public static function reset(): void
	{
		self::$cache = self::$wanted = self::$phpWanted = [];
	}

	/** @param array{tabs: list<array>, objects: list<array>, events: list<array>, browse: list<array>}|null $pre live rows read by prime() */
	private function __construct(array $form, ?array $snap = null, ?array $pre = null)
	{
		$db         = nu_db();
		$this->id   = (string)$form['zzzzsys_form_id'];
		$this->form = $form;

		if ($snap !== null) {
			$this->tabs = array_values((array)($snap['tabs'] ?? []));
			usort($this->tabs, static fn($a, $b) => [(int)$a['syt_order'], (string)$a['zzzzsys_tab_id']] <=> [(int)$b['syt_order'], (string)$b['zzzzsys_tab_id']]);
			$this->phpRows = [];
			foreach ($snap['php'] ?? [] as $p) $this->phpRows[(string)$p['zzzzsys_php_id']] = $p['sph_php'] ?? null;
		} else {
			$this->tabs = $pre['tabs'] ?? $db->all('SELECT * FROM zzzzsys_tab WHERE syt_zzzzsys_form_id = ? ORDER BY syt_order, zzzzsys_tab_id', [$this->id]);
		}
		$tabOrder   = [];
		foreach ($this->tabs as $i => $t) $tabOrder[$t['zzzzsys_tab_id']] = $i;

		$objects = $snap !== null ? array_values((array)($snap['objects'] ?? []))
			: ($pre['objects'] ?? $db->all('SELECT * FROM zzzzsys_object WHERE sob_all_zzzzsys_form_id = ?', [$this->id]));
		usort($objects, static fn($a, $b) =>
			[$tabOrder[$a['sob_all_zzzzsys_tab_id']] ?? 999, (int)($a['sob_all_order'] ?? 0), (int)($a['sob_all_top'] ?? 0), (int)($a['sob_all_left'] ?? 0)]
			<=> [$tabOrder[$b['sob_all_zzzzsys_tab_id']] ?? 999, (int)($b['sob_all_order'] ?? 0), (int)($b['sob_all_top'] ?? 0), (int)($b['sob_all_left'] ?? 0)]);

		// a draft's new rows hold only the columns that were set (NuDraftStore::row): the others are NULL, as in the table (0.8.663)
		$blank = $snap !== null ? array_fill_keys(array_keys($db->columns('zzzzsys_object') ?: []), null) : [];
		foreach ($objects as $o) {
			if ($blank) $o += $blank;
			$o['nunext'] = self::nunextJson($o['sob_all_json'] ?? null);
			$o['tab_index'] = $tabOrder[$o['sob_all_zzzzsys_tab_id']] ?? 0;
			$this->objects[] = $o;
			$this->byId[(string)$o['sob_all_id']] = $o;
		}

		if ($objects && $snap !== null) {
			$evs = array_values((array)($snap['events'] ?? []));
			usort($evs, static fn($a, $b) => strcmp((string)$a['zzzzsys_event_id'], (string)$b['zzzzsys_event_id']));
			foreach ($evs as $e) {
				$ev = strtolower(trim((string)$e['sev_event']));
				if ($ev === '') continue;
				$prev = $this->events[$e['sev_zzzzsys_object_id']][$ev] ?? '';
				$this->events[$e['sev_zzzzsys_object_id']][$ev] = ltrim($prev . "\n" . $e['sev_javascript']);
			}
		} elseif ($objects) {
			$ids = array_column($objects, 'zzzzsys_object_id');
			foreach ($pre !== null ? [[]] : array_chunk($ids, 500) as $chunk) {
				$in = implode(',', array_fill(0, count($chunk), '?'));
				foreach ($pre['events'] ?? $db->all("SELECT sev_zzzzsys_object_id, sev_event, sev_javascript FROM zzzzsys_event WHERE sev_zzzzsys_object_id IN ($in)", $chunk) as $e) {
					$ev = strtolower(trim((string)$e['sev_event']));
					if ($ev === '') continue;
					$prev = $this->events[$e['sev_zzzzsys_object_id']][$ev] ?? '';
					$this->events[$e['sev_zzzzsys_object_id']][$ev] = ltrim($prev . "\n" . $e['sev_javascript']);
				}
			}
		}

		if ($snap !== null) {
			$this->browse = array_values((array)($snap['browse'] ?? []));
			usort($this->browse, static fn($a, $b) => [(int)$a['sbr_order'], (string)$a['zzzzsys_browse_id']] <=> [(int)$b['sbr_order'], (string)$b['zzzzsys_browse_id']]);
		} else {
			$this->browse = $pre['browse'] ?? $db->all('SELECT * FROM zzzzsys_browse WHERE sbr_zzzzsys_form_id = ? ORDER BY sbr_order, zzzzsys_browse_id', [$this->id]);
			// live: its sub-forms and lookup forms are loaded together when the first is asked for, its hooks with the first hook
			foreach ($this->objects as $o) {
				$t = (string)($o['sob_all_type'] ?? '');
				$f = $t === 'subform' ? (string)($o['sob_subform_zzzzsys_form_id'] ?? '') : ($t === 'lookup' ? (string)($o['sob_lookup_zzzzsys_form_id'] ?? '') : '');
				if ($f !== '' && !array_key_exists($f, self::$cache)) self::$wanted[$f] = true;
			}
			self::$phpWanted[$this->id] = true;
		}
		// column filters switched on by default for a few of nuBuilder's own lists (0.8.307) – only while the column has no setting of its
		// own: the form builder can switch them off, and a nuBuilder update (which rewrites its system rows) brings the default back
		foreach ($this->browse as $i => $b) {
			if (in_array(strtolower(trim((string)$b['sbr_display'])), self::DEFAULT_FILTERS[$this->id] ?? [], true)
				&& !array_key_exists('filter', self::nunextJson($b['sbr_json'] ?? null))) {
				$this->browse[$i]['sbr_json'] = self::mergeNunextJson($b['sbr_json'] ?? null, ['filter' => true]);
			}
		}
		if (NuTranslate::active()) $this->translate();
	}

	/** Form pages: texts in the reader's language (zzzzsys_translate), like nuBuilder. The builder keeps the originals. */
	private function translate(): void
	{
		$tr = static fn($s) => NuTranslate::text($s === null ? null : (string)$s);
		$this->form['sfo_description'] = $tr($this->form['sfo_description'] ?? '');
		foreach ($this->tabs as $i => $t) $this->tabs[$i]['syt_title'] = $tr($t['syt_title']);
		foreach ($this->objects as $i => $o) {
			$o['sob_all_label'] = $tr($o['sob_all_label']);
			$this->objects[$i] = $o;
			$this->byId[(string)$o['sob_all_id']] = $o;
		}
		foreach ($this->browse as $i => $b) $this->browse[$i]['sbr_title'] = $tr($b['sbr_title']);
	}

	/** The "nunext" part of a JSON column ([] when absent or invalid). */
	public static function nunextJson(?string $json): array
	{
		if ($json === null || trim($json) === '') return [];
		$d = json_decode($json, true);
		return is_array($d) && isset($d['nunext']) && is_array($d['nunext']) ? $d['nunext'] : [];
	}

	/**
	 * Badge of a list column (0.8.688, sbr_json nunext.badge – set in design mode): true = nuBrowseStyleBadge's look, or a list of rules
	 * of nuBrowseStyleBadge(column, rules) in their JSON form. clean() keeps the keys the runtime knows and drops the rest; null = no badge.
	 */
	public const BADGE_KEYS = ['equals', 'contains', 'startsWith', 'endsWith', 'regex', 'default', 'ignoreCase', 'bg', 'color', 'border', 'label', 'icon', 'title', 'badge'];
	public static function cleanBadge(mixed $v): bool|array|null
	{
		if ($v === null || $v === false || $v === '' || $v === '0' || $v === 0) return null;
		if (!is_array($v)) return true;
		$out = [];
		foreach (array_slice(array_values($v), 0, 30) as $r) {
			if (!is_array($r)) continue;
			$x = [];
			foreach (self::BADGE_KEYS as $k) {
				if (!array_key_exists($k, $r)) continue;
				$val = $r[$k];
				if (in_array($k, ['default', 'ignoreCase', 'badge'], true)) $x[$k] = (bool)$val;
				elseif (is_scalar($val) && (string)$val !== '') $x[$k] = mb_substr((string)$val, 0, 200);
			}
			if (isset($x['icon']) && !preg_match('/^(bi-)?[a-z0-9-]+$/', $x['icon'])) unset($x['icon']);
			if ($x) $out[] = $x;
		}
		return $out ?: true;
	}
	/**
	 * Click action of a list column (0.8.872, sbr_json nunext.action – design mode → column → On click): a click on the cell opens another
	 * form (record of the row, a column value or a new one), another list in the context of the row, runs a procedure / report or calls
	 * a JavaScript function – instead of opening the row's edit form. {t, id, rec, open, pass, look, icon, label}; null = none.
	 * nuBuilder Forte ignores it (the row opens its edit form there).
	 */
	public const ACTION_TYPES = ['form', 'list', 'proc', 'report', 'js'];
	public static function cleanAction(mixed $a, bool $needTarget = false): ?array
	{
		if (!is_array($a)) return null;
		$t = (string)($a['t'] ?? '');
		$id = trim((string)($a['id'] ?? ''));
		if (!in_array($t, self::ACTION_TYPES, true) || strlen($id) > 100) return null;
		if ($id !== '' && ($t === 'js' ? !preg_match('/^[A-Za-z_$][\w$]*(\.[A-Za-z_$][\w$]*)*$/', $id) : !preg_match('/^[A-Za-z0-9_.\-]+$/', $id))) $id = '';
		if ($id === '' && $needTarget) return null;          // stored while the target is still being chosen; inactive until then
		$out = ['t' => $t, 'id' => $id];
		if ($t === 'form') $out['rec'] = in_array($a['rec'] ?? '', ['row', 'value', 'new'], true) ? (string)$a['rec'] : 'row';
		if ($t === 'form' || $t === 'list') $out['open'] = in_array((string)($a['open'] ?? ''), ['0', '2', '3'], true) ? (string)$a['open'] : '0';
		if ($t !== 'js') {
			$p = trim((string)($a['pass'] ?? ''));
			$out['pass'] = preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,59}$/', $p) && !NuHash::isSystem($p) ? $p : 'PARENT_ID';
		}
		$out['look'] = in_array($a['look'] ?? '', ['link', 'button', 'icon'], true) ? (string)$a['look'] : 'link';
		$icon = trim((string)($a['icon'] ?? ''));
		if ($out['look'] !== 'link') $out['icon'] = preg_match('/^(bi-)?[a-z0-9-]{1,60}$/', $icon) ? (str_starts_with($icon, 'bi-') ? $icon : 'bi-' . $icon) : ($out['look'] === 'icon' ? 'bi-box-arrow-up-right' : '');
		$label = trim((string)($a['label'] ?? ''));
		if ($out['look'] !== 'link' && $label !== '') $out['label'] = mb_substr($label, 0, 60);
		return $out;
	}

	/**
	 * Total of a list column (sbr_json nunext.total – design mode → column → Total): a row below the list shows the sum / average /
	 * minimum / maximum / count of the column over ALL rows the search and filters match (NuBrowse::query). nuBuilder Forte ignores it.
	 */
	public const TOTALS = ['sum', 'avg', 'min', 'max', 'count'];
	public static function cleanTotal(mixed $v): ?string
	{
		return is_string($v) && in_array($v, self::TOTALS, true) ? $v : null;
	}

	/** The total function of a browse row's sbr_json, or null. */
	public static function totalOf(?string $json): ?string
	{
		return self::cleanTotal(self::nunextJson($json)['total'] ?? null);
	}

	/** Is the list grouped by this column (sbr_json nunext.group – the first column that says so)? Heading and subtotal row per group. */
	public static function groupOf(?string $json): bool
	{
		return !empty(self::nunextJson($json)['group']);
	}

	/** The click action of a browse row's sbr_json, or null. */
	public static function actionOf(?string $json): ?array
	{
		return self::cleanAction(self::nunextJson($json)['action'] ?? null, true);
	}

	/** The badge of a browse row's sbr_json: null, [] (default look) or the rules. */
	public static function badgeOf(?string $json): ?array
	{
		$b = self::cleanBadge(self::nunextJson($json)['badge'] ?? null);
		return $b === null ? null : ($b === true ? [] : $b);
	}

	/**
	 * Department and team of a form (0.8.1020, sfo_json nunext.department / nunext.team): free text for grouping and filtering the forms
	 * list only – no effect on rights or behaviour, no link to the users' fields. nuBuilder Forte ignores the nunext key.
	 */
	public const FORM_TAGS = ['department', 'team'];
	public const TAG_MAX = 60;

	/** Trimmed, inner white space folded, at most TAG_MAX characters; '' → null (= key removed). */
	public static function cleanTag(mixed $v): ?string
	{
		if (!is_scalar($v)) return null;
		$s = trim((string)preg_replace('/\s+/u', ' ', (string)$v));
		return $s === '' ? null : mb_substr($s, 0, self::TAG_MAX);
	}

	/** The tags present in a field list of form.save, cleaned (null = remove). */
	/**
	 * "Records only through the form's list" per form (0.8.1234, the owner: A + B): sfo_json nunext.record_check = false skips the
	 * check of NuRecord::visible() on this form (open, save, delete); set in design mode → Form → Data. design API field
	 * record_check '1' (default, checked) | '0' (not checked). nuBuilder Forte ignores the key.
	 */
	public static function recordCheckIn(array $fields): array
	{
		if (!array_key_exists('record_check', $fields)) return [];
		return ['record_check' => in_array((string)$fields['record_check'], ['0', 'false', 'off'], true) ? false : null];   // null = default (checked): key removed
	}

	public static function recordCheckOff(?string $json): bool
	{
		return (self::nunextJson($json)['record_check'] ?? null) === false;
	}

	public static function tagsIn(array $fields): array
	{
		$out = [];
		foreach (self::FORM_TAGS as $k) if (array_key_exists($k, $fields)) $out[$k] = self::cleanTag($fields[$k]);
		return $out;
	}

	/** department / team of a form's sfo_json ('' when not set). */
	public static function tagsOf(?string $json): array
	{
		$n = self::nunextJson($json);
		$out = [];
		foreach (self::FORM_TAGS as $k) $out[$k] = (string)(self::cleanTag($n[$k] ?? null) ?? '');
		return $out;
	}

	/** Values already used on forms – the suggestions of the fields (sorted, unique, case kept of the first one). */
	public static function tagValues(): array
	{
		$seen = array_fill_keys(self::FORM_TAGS, []);
		foreach (nu_db()->all("SELECT sfo_json FROM zzzzsys_form WHERE sfo_json LIKE '%nunext%'") as $r) {
			foreach (self::tagsOf((string)$r['sfo_json']) as $k => $v) if ($v !== '') $seen[$k][mb_strtolower($v)] ??= $v;
		}
		foreach ($seen as $k => $vals) { $vals = array_values($vals); natcasesort($vals); $seen[$k] = array_values($vals); }
		return $seen;
	}

	/** Merges $nunext into the existing JSON value without touching other keys. */
	public static function mergeNunextJson(?string $json, array $nunext): ?string
	{
		$d = ($json !== null && trim($json) !== '') ? json_decode($json, true) : [];
		if (!is_array($d)) return $json;                     // foreign non-JSON content: leave it alone
		$d['nunext'] = array_filter(array_merge($d['nunext'] ?? [], $nunext), static fn($v) => $v !== null && $v !== '');
		if (!$d['nunext']) unset($d['nunext']);
		return $d ? json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
	}

	// ----------------------------------------------------------------- convenience

	public function type(): string        { return (string)($this->form['sfo_type'] ?: 'browseedit'); }
	public function table(): string       { return trim((string)$this->form['sfo_table']); }
	public function primaryKey(): string  { return trim((string)$this->form['sfo_primary_key']); }
	public function code(): string        { return (string)$this->form['sfo_code']; }
	public function description(): string { return (string)$this->form['sfo_description']; }
	public function isSystem(): bool      { return str_starts_with($this->id, 'nu'); }
	/** nuBuilder Forte's User Home (0.8.605): a system form whose tabs, objects and Before Edit belong to the application – the builder edits those. */
	public function isUserHome(): bool    { return $this->id === NuUserHome::FORM; }
	public function hasBrowse(): bool     { return in_array($this->type(), ['browse', 'browseedit'], true); }
	public function hasEdit(): bool       { return in_array($this->type(), ['edit', 'browseedit', 'launch'], true); }
	public function isPage(): bool        { return $this->type() === 'page'; }   // form type "page" (0.8.387, core/nupage.php)

	public function settings(): array     { return self::nunextJson($this->form['sfo_json'] ?? null); }

	/** absolute | auto | grid (default absolute = like nuBuilder) */
	public function layout(): string
	{
		$l = (string)($this->settings()['layout'] ?? 'absolute');
		return in_array($l, ['absolute', 'auto', 'grid'], true) ? $l : 'absolute';
	}

	public function objectsOfTab(string $tabId): array
	{
		return array_values(array_filter($this->objects, static fn($o) => (string)$o['sob_all_zzzzsys_tab_id'] === $tabId));
	}

	public function php(string $suffix): ?string
	{
		return $this->phpById($this->id . '_' . $suffix);
	}

	/** PHP of an object, e.g. a lookup's After Browse code (<object id>_AB) – from the draft in a preview. */
	public function objectPhp(string $objectId, string $suffix): ?string
	{
		return $this->phpById($objectId . '_' . $suffix);
	}

	private function phpById(string $id): ?string
	{
		// the live row once per request (0.8.1341: a record page read its Before Edit code four times) – dropped by a write to zzzzsys_php (NuMemo)
		if ($this->phpRows === null && self::$phpWanted && !NuMemo::has('php:' . $id)) self::primePhp();
		$code = $this->phpRows !== null ? ($this->phpRows[$id] ?? null)
			: NuMemo::get('php:' . $id, ['zzzzsys_php'], static fn() => nu_db()->value('SELECT sph_php FROM zzzzsys_php WHERE zzzzsys_php_id = ?', [$id]));
		return ($code !== null && trim((string)$code) !== '') ? (string)$code : null;
	}

	/** The hooks (BB … AD) of every live form loaded so far, in one statement (0.8.1343) – kept in NuMemo, missing ones as null. */
	private static function primePhp(): void
	{
		$ids = [];
		foreach (array_keys(self::$phpWanted) as $f) foreach (self::HOOKS as $h) if (!NuMemo::has('php:' . $f . '_' . $h)) $ids[] = $f . '_' . $h;
		self::$phpWanted = [];
		if (!$ids) return;
		$found = [];
		foreach (array_chunk($ids, 500) as $chunk) {
			$in = implode(',', array_fill(0, count($chunk), '?'));
			foreach (nu_db()->all("SELECT zzzzsys_php_id, sph_php FROM zzzzsys_php WHERE zzzzsys_php_id IN ($in)", $chunk) as $r) $found[(string)$r['zzzzsys_php_id']] = $r['sph_php'];
		}
		foreach ($ids as $id) NuMemo::put('php:' . $id, ['zzzzsys_php'], $found[$id] ?? null);
	}

	/** sfo_status of the live form – from the row loaded already (0.8.1343), else read. */
	public static function liveStatus(string $formId): string
	{
		$m = self::$cache[$formId] ?? null;
		if ($m instanceof self && !$m->isDraft()) return (string)($m->form['sfo_status'] ?? '');
		return (string)nu_db()->value('SELECT sfo_status FROM zzzzsys_form WHERE zzzzsys_form_id = ?', [$formId]);
	}

	/** An object by its zzzzsys_object_id. */
	public function object(string $objectId): ?array
	{
		foreach ($this->objects as $o) if ((string)$o['zzzzsys_object_id'] === $objectId) return $o;
		return null;
	}

	/** Built from a draft (preview or builder) – not the live form. */
	public function isDraft(): bool { return $this->phpRows !== null; }

	/** First / second part of sfo_breadcrumb_title ("#field#|New record"). */
	public function breadcrumb(bool $new, array $hash): string
	{
		$parts = explode('|', (string)$this->form['sfo_breadcrumb_title']);
		if ($this->type() === 'launch') $new = false;        // a launch form has no record: its title, not "New record" (0.8.605)
		$t     = $new ? ($parts[1] ?? '') : $parts[0];
		$t     = trim(NuHash::replace($t, $hash));
		if ($t === '' || preg_match('/^#[^#]+#$/', $t)) $t = $new ? t('form_new_record') : $this->description();
		return $t;
	}
}
