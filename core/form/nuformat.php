<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * Display formats, stored like nuBuilder in zzzzsys_format and referenced as "<type letter>|<srm_format>":
 *
 *   D|dd.mm.yyyy hh:nn       date / time       tokens yyyy yy mmmm mmm mm dddd ddd dd hh nn ss
 *   N|$ 1,000.00             number            prefix, thousands separator, decimal separator, places, suffix ("1'000.0 %")
 *   T|AA99 9999 9999 9       text mask (nuBuilder Next) 9 digit, a letter, A letter (upper case), * letter or digit, \ literal
 *
 * Stored values stay ISO dates (yyyy-mm-dd [hh:nn:ss]) and plain numbers (1234.5); masked text is stored with or
 * without the mask characters (option "store"). nuBuilder Next options live in srm_json {"nunext": {...}}:
 *   negative: minus | brackets | red | red_brackets   (numbers)
 *   store: masked | raw, complete: true|false          (text masks)
 *
 * Mirrors assets/js/nu/nuformat.js.
 */
final class NuFormat
{
	public const TYPES = ['D' => 'Date', 'N' => 'Number', 'T' => 'Text'];
	public const MAX_KEY = 30;                  // sob_input_format / sob_calc_format are varchar(30) in nuBuilder
	public const NEGATIVE = ['minus', 'brackets', 'red', 'red_brackets'];

	/** Common formats offered even when they are not (yet) in zzzzsys_format. */
	public const BUILTIN = [
		'D|dd.mm.yyyy', 'D|dd.mm.yyyy hh:nn', 'D|dd.mm.yy', 'D|yyyy-mm-dd', 'D|yyyy-mm-dd hh:nn:ss', 'D|dd-mmm-yyyy', 'D|ddd, dd.mm.yyyy',
		'D|mm/dd/yyyy', 'D|hh:nn',
		'N|1000', 'N|1000.00', 'N|1,000', 'N|1,000.00', 'N|$ 1,000.00', 'N|€ 1.000,00', 'N|1000.0 %',
	];

	private const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
	private const MONTHS_LONG = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
	private const DAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
	private const DAYS_LONG = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

	private static ?array $registry = null;

	// ================================================================== formatting

	public static function format(mixed $value, ?string $fmt): string
	{
		$value = $value === null ? '' : (string)$value;
		$fmt   = trim((string)$fmt);
		if ($value === '' || strlen($fmt) < 3 || $fmt[1] !== '|') return $value;

		return match (strtoupper($fmt[0])) {
			'D'     => self::date($value, substr($fmt, 2)),
			'N'     => self::number($value, substr($fmt, 2), self::options($fmt)['negative']),
			'T'     => self::maskDisplay($value, substr($fmt, 2)),
			default => $value,
		};
	}

	/** CSS class for a formatted value ('nuNegative' for negative numbers shown in red). */
	public static function cssClass(mixed $value, ?string $fmt): string
	{
		$fmt = trim((string)$fmt);
		if (!str_starts_with(strtoupper($fmt), 'N|') || !is_numeric((string)$value) || (float)$value >= 0) return '';
		return str_starts_with(self::options($fmt)['negative'], 'red') ? 'nuNegative' : '';
	}

	// ----------------------------------------------------------------- dates

	private static function date(string $value, string $pattern): string
	{
		if (preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', $value, $t)) {          // time only
			$m = [0, '0000', '00', '00', $t[1], $t[2], $t[3] ?? '00'];
		} elseif (!preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::(\d{2}))?)?/', $value, $m)) {
			return $value;
		} elseif ($m[1] === '0000') {
			return '';
		}
		[$y, $mo, $d] = [(int)$m[1], (int)$m[2], (int)$m[3]];
		$h = (int)($m[4] ?? 0); $n = (int)($m[5] ?? 0); $s = (int)($m[6] ?? 0);
		$dow = $y > 0 ? (int)date('w', mktime(0, 0, 0, $mo, $d, $y)) : 0;

		$tokens = [
			'yyyy' => sprintf('%04d', $y), 'yy' => sprintf('%02d', $y % 100),
			'mmmm' => self::MONTHS_LONG[$mo - 1] ?? '', 'mmm' => self::MONTHS[$mo - 1] ?? '',
			'mm' => sprintf('%02d', $mo), 'dddd' => self::DAYS_LONG[$dow], 'ddd' => self::DAYS[$dow],
			'dd' => sprintf('%02d', $d), 'hh' => sprintf('%02d', $h), 'nn' => sprintf('%02d', $n), 'ss' => sprintf('%02d', $s),
		];
		return preg_replace_callback('/yyyy|yy|mmmm|mmm|mm|dddd|ddd|dd|hh|nn|ss/', static fn($t) => $tokens[$t[0]], $pattern);
	}

	// ----------------------------------------------------------------- numbers

	/** @return array{prefix:string,sep:string,dec:string,places:int,suffix:string} */
	public static function numberSpec(string $pattern): array
	{
		if (preg_match('/^(.*?)1([^0-9]?)000([^0-9]?)(0*)(.*)$/u', $pattern, $m)) {
			$dec = $m[3];
			$places = strlen($m[4]);
			if ($places === 0 && $dec !== '') { $m[5] = $dec . $m[5]; $dec = ''; }
			return ['prefix' => $m[1], 'sep' => $m[2], 'dec' => $dec !== '' ? $dec : '.', 'places' => $places, 'suffix' => $m[5]];
		}
		return ['prefix' => '', 'sep' => '', 'dec' => '.', 'places' => 2, 'suffix' => ''];
	}

	/** srm_format of a number spec: prefix + 1 + sep + 000 + dec + places zeros + suffix. */
	public static function numberPattern(array $s): string
	{
		$places = max(0, min(10, (int)($s['places'] ?? 0)));
		return (string)($s['prefix'] ?? '') . '1' . (string)($s['sep'] ?? '') . '000'
			. ($places > 0 ? (string)(($s['dec'] ?? '.') ?: '.') . str_repeat('0', $places) : '') . (string)($s['suffix'] ?? '');
	}

	private static function number(string $value, string $pattern, string $negative = 'minus'): string
	{
		if (!is_numeric($value)) return $value;
		$s = self::numberSpec($pattern);
		$n = (float)$value;
		$body = $s['prefix'] . number_format(abs($n), $s['places'], $s['dec'], $s['sep']) . $s['suffix'];
		if ($n >= 0 || round(abs($n), $s['places']) == 0) return $body;
		// minus after the prefix, as nuBuilder's addFormatting (€ -99,95 – 0.8.364); brackets around the whole value
		return str_ends_with($negative, 'brackets') ? "($body)" : $s['prefix'] . '-' . substr($body, strlen($s['prefix']));
	}

	// ----------------------------------------------------------------- text masks

	/** Mask tokens: [[kind, char]] with kind 'L' literal, '9', 'a', 'A', '*'. */
	public static function maskTokens(string $mask): array
	{
		$out = [];
		$chars = mb_str_split($mask);
		for ($i = 0, $n = count($chars); $i < $n; $i++) {
			$c = $chars[$i];
			if ($c === '\\' && $i + 1 < $n) { $out[] = ['L', $chars[++$i]]; continue; }
			$out[] = in_array($c, ['9', 'a', 'A', '*'], true) ? [$c, ''] : ['L', $c];
		}
		return $out;
	}

	private static function fits(string $kind, string $c): bool
	{
		return match ($kind) {
			'9' => ctype_digit($c),
			'a', 'A' => (bool)preg_match('/^\p{L}$/u', $c),
			'*' => (bool)preg_match('/^[\p{L}\p{N}]$/u', $c),
			default => false,
		};
	}

	/**
	 * Applies a mask to a value (raw or already masked).
	 * @return array{0:string,1:string,2:bool,3:bool}  [masked, raw (placeholder characters only), complete, fits]
	 *         fits = complete and no letter/digit of the value was dropped (the value really matches the mask)
	 */
	public static function mask(string $value, string $mask): array
	{
		$tokens = self::maskTokens($mask);
		$in = mb_str_split($value);
		$i = 0; $n = count($in);
		$out = ''; $raw = ''; $pending = ''; $filled = 0; $slots = 0; $dropped = 0;
		foreach ($tokens as [$kind, $lit]) {
			if ($kind === 'L') {
				if ($i < $n && mb_strtolower($in[$i]) === mb_strtolower($lit)) $i++;
				$pending .= $lit;
				continue;
			}
			$slots++;
			while ($i < $n && !self::fits($kind, $in[$i])) {        // skip separators the user typed
				if (preg_match('/^[\p{L}\p{N}]$/u', $in[$i])) $dropped++;
				$i++;
			}
			if ($i >= $n) continue;
			$c = $kind === 'A' ? mb_strtoupper($in[$i]) : $in[$i];
			$out .= $pending . $c;
			$pending = '';
			$raw .= $c;
			$filled++;
			$i++;
		}
		if ($filled === $slots) $out .= $pending;                    // trailing literals once complete
		for (; $i < $n; $i++) if (preg_match('/^[\p{L}\p{N}]$/u', $in[$i])) $dropped++;
		return [$filled ? $out : $value, $raw, $filled === $slots, $filled === $slots && $dropped === 0];
	}

	/** Display value: masked when the value matches the mask, otherwise unchanged (existing data is never cut). */
	public static function maskDisplay(string $value, string $mask): string
	{
		$m = self::mask($value, $mask);
		return $m[3] ? $m[0] : $value;
	}

	/** Value to store for a masked input ("store": masked|raw). */
	public static function maskStoreValue(string $value, string $fmt): string
	{
		if ($value === '') return '';
		[$masked, $raw, , $fits] = self::mask($value, substr($fmt, 2));
		if (!$fits) return $value;
		return self::options($fmt)['store'] === 'raw' ? $raw : $masked;
	}

	/** False when a text mask must be complete and the value is not. */
	public static function maskComplete(string $value, string $fmt): bool
	{
		if ($value === '' || !str_starts_with(strtoupper($fmt), 'T|') || !self::options($fmt)['complete']) return true;
		return self::mask($value, substr($fmt, 2))[3];
	}

	// ================================================================== zzzzsys_format

	/** All formats of zzzzsys_format, keyed by "<letter>|<srm_format>". */
	public static function registry(bool $fresh = false): array
	{
		if (self::$registry !== null && !$fresh) return self::$registry;
		self::$registry = [];
		try {
			$db = nu_db();
			if (!$db->tableExists('zzzzsys_format')) return self::$registry;
			foreach ($db->all('SELECT * FROM zzzzsys_format') as $r) {
				$letter = strtoupper(substr((string)$r['srm_type'], 0, 1));
				if (!isset(self::TYPES[$letter]) || trim((string)$r['srm_format']) === '') continue;
				$key = $letter . '|' . trim((string)$r['srm_format']);
				$j = json_decode((string)($r['srm_json'] ?? ''), true);
				$o = is_array($j) && is_array($j['nunext'] ?? null) ? $j['nunext'] : [];
				self::$registry[$key] = [
					'id' => (string)$r['zzzzsys_format_id'], 'key' => $key, 'type' => self::TYPES[$letter], 'format' => trim((string)$r['srm_format']),
					'default' => (string)($r['srm_default'] ?? '') === '1', 'system' => str_starts_with((string)$r['zzzzsys_format_id'], 'nu'),
					'description' => (string)($o['description'] ?? ''),
				] + self::normaliseOptions($o);
			}
		} catch (Throwable $e) {
			error_log('nuBuilder Next formats: ' . $e->getMessage());
		}
		return self::$registry;
	}

	public static function normaliseOptions(array $o): array
	{
		return [
			'negative' => in_array($o['negative'] ?? '', self::NEGATIVE, true) ? $o['negative'] : 'minus',
			'store'    => ($o['store'] ?? '') === 'raw' ? 'raw' : 'masked',
			'complete' => !array_key_exists('complete', $o) || (bool)$o['complete'],
		];
	}

	/** nuBuilder Next options of a format (defaults when it is not in zzzzsys_format). */
	public static function options(string $fmt): array
	{
		$r = self::registry()[trim($fmt)] ?? null;
		return $r ?? self::normaliseOptions([]);
	}

	/** Options the browser needs: {key: {negative, store, complete}} for formats that differ from the defaults. */
	public static function clientRegistry(): array
	{
		$out = [];
		$def = self::normaliseOptions([]);
		foreach (self::registry() as $k => $r) {
			$o = array_intersect_key($r, $def);
			if ($o != $def) $out[$k] = $o;
		}
		return $out;
	}

	/**
	 * Formats for a picker: table formats first (default first), then the built-ins that are not in the table.
	 * @param string|null $letters  e.g. 'D', 'N', 'T', 'DN' – null = all
	 */
	public static function choices(?string $letters = null): array
	{
		$out = [];
		$reg = self::registry();
		uasort($reg, static fn($a, $b) => [$a['type'], !$a['default'], $a['format']] <=> [$b['type'], !$b['default'], $b['format']]);
		foreach ($reg as $k => $r) {
			if ($letters !== null && !str_contains($letters, $k[0])) continue;
			$out[] = ['value' => $k, 'type' => $r['type'], 'default' => $r['default'], 'saved' => true, 'id' => $r['id'], 'description' => $r['description']];
		}
		foreach (self::BUILTIN as $k) {
			if (isset($reg[$k]) || ($letters !== null && !str_contains($letters, $k[0]))) continue;
			$out[] = ['value' => $k, 'type' => self::TYPES[$k[0]], 'default' => false, 'saved' => false, 'id' => '', 'description' => ''];
		}
		return $out;
	}

	/** Creates zzzzsys_format when it is missing (nuBuilder Next without nuBuilder). */
	public static function ensureTable(): void
	{
		$db = nu_db();
		if ($db->tableExists('zzzzsys_format')) return;
		$T = static fn(string $t, string $l = '') => NuDDL::sqlType($t, $l);
		$db->run('CREATE TABLE zzzzsys_format (zzzzsys_format_id ' . $T('id') . ' NOT NULL PRIMARY KEY, srm_type ' . $T('text', '10') . ' NULL, '
			. 'srm_default ' . $T('text', '1') . ' NULL, srm_format ' . $T('text', '300') . ' NULL, srm_currency ' . $T('text', '25') . ' NULL, srm_json ' . $T('longtext') . ' NULL)'
			. ($db->type === 'mysql' ? NuDDL::mysqlTableOptions() : ''));
	}

	/**
	 * Validates and saves a format.
	 * $in: id ('' = new), type (Date|Number|Text), format, default (bool), description,
	 *      negative (numbers), store / complete (masks)
	 * @return array{0: ?string, 1: array}  [id or null, errors field => message]
	 */
	public static function save(array $in): array
	{
		$errors = [];
		$type   = (string)($in['type'] ?? '');
		$letter = array_search($type, self::TYPES, true);
		$format = trim((string)($in['format'] ?? ''));
		$id     = trim((string)($in['id'] ?? ''));
		if ($letter === false) $errors['type'] = t('err_required');
		if ($format === '') $errors['format'] = t('err_required');
		elseif (mb_strlen($format) > self::MAX_KEY - 2) $errors['format'] = t('fmt_err_length', self::MAX_KEY - 2);
		elseif (preg_match('/[|\r\n]/', $format)) $errors['format'] = t('fmt_err_chars');
		elseif ($letter === 'D' && !preg_match('/yyyy|yy|mmmm|mmm|mm|dddd|ddd|dd|hh|nn|ss/', $format)) $errors['format'] = t('fmt_err_date');
		elseif ($letter === 'N' && !preg_match('/^(.*?)1([^0-9]?)000([^0-9]?)(0*)(.*)$/u', $format)) $errors['format'] = t('fmt_err_number');
		elseif ($letter === 'N' && ($ns = self::numberSpec($format))['places'] > 0 && $ns['sep'] !== '' && $ns['sep'] === $ns['dec']) $errors['format'] = t('fmt_err_sep');
		elseif ($letter === 'T' && !array_filter(self::maskTokens($format), static fn($t) => $t[0] !== 'L')) $errors['format'] = t('fmt_err_mask');
		$desc = trim((string)($in['description'] ?? ''));
		nu_maxlen($errors, 'description', $desc, 100);
		if ($errors) return [null, $errors];

		self::ensureTable();
		$db  = nu_db();
		$key = $letter . '|' . $format;
		$dup = $db->value("SELECT zzzzsys_format_id FROM zzzzsys_format WHERE srm_type = ? AND srm_format = ?" . ($id !== '' ? ' AND zzzzsys_format_id <> ?' : ''),
			$id !== '' ? [$type, $format, $id] : [$type, $format]);
		if ($dup !== null) return [null, ['format' => t('fmt_err_exists')]];

		$existing = $id !== '' ? $db->one('SELECT * FROM zzzzsys_format WHERE zzzzsys_format_id = ?', [$id]) : null;
		if ($id !== '' && !$existing) return [null, ['_' => t('err_not_found')]];

		$opts = ['description' => $desc];
		if ($letter === 'N') $opts['negative'] = in_array($in['negative'] ?? '', self::NEGATIVE, true) ? $in['negative'] : 'minus';
		if ($letter === 'T') { $opts['store'] = ($in['store'] ?? '') === 'raw' ? 'raw' : 'masked'; $opts['complete'] = !empty($in['complete']); }
		$json = NuFormMeta::mergeNunextJson($existing['srm_json'] ?? null, $opts);

		$currency = null;
		if ($letter === 'N') {
			$s = self::numberSpec($format);
			$currency = json_encode([trim($s['prefix']), $s['sep'], $s['places'] > 0 ? $s['dec'] : '', (string)$s['places']], JSON_UNESCAPED_UNICODE);
			if (mb_strlen($currency) > 25) $currency = null;          // column size; nuBuilder then derives it from the format
		}
		$row = ['srm_type' => $type, 'srm_format' => $format, 'srm_currency' => $currency, 'srm_json' => $json, 'srm_default' => !empty($in['default']) ? '1' : '0'];

		$db->transaction(static function () use ($db, $row, &$id, $existing, $type, $key) {
			if ($row['srm_default'] === '1') $db->run("UPDATE zzzzsys_format SET srm_default = '0' WHERE srm_type = ?", [$type]);
			if ($existing) {
				$oldKey = strtoupper(substr((string)$existing['srm_type'], 0, 1)) . '|' . trim((string)$existing['srm_format']);
				$db->update('zzzzsys_format', $row, 'zzzzsys_format_id', $id);
				if ($oldKey !== $key) self::renameUsage($oldKey, $key);       // objects and browse columns follow the edited format
			} else {
				$id = nu_id();
				$db->insert('zzzzsys_format', ['zzzzsys_format_id' => $id] + $row);
			}
		});
		self::$registry = null;
		return [$id, []];
	}

	/** Adds a built-in format to zzzzsys_format so nuBuilder knows it too. */
	public static function ensureSaved(?string $key): void
	{
		$key = trim((string)$key);
		if ($key === '' || !in_array($key, self::BUILTIN, true) || isset(self::registry()[$key])) return;
		try {
			self::save(['type' => self::TYPES[$key[0]], 'format' => substr($key, 2)]);
		} catch (Throwable $e) {
			error_log('nuBuilder Next format: ' . $e->getMessage());
		}
	}

	/** Where a format is used: [['form' => …, 'object' => …], …]. */
	public static function usage(string $key): array
	{
		$db = nu_db();
		$out = [];
		if ($db->tableExists('zzzzsys_object')) {
			foreach ($db->all('SELECT o.sob_all_zzzzsys_form_id AS f, o.sob_all_id AS id FROM zzzzsys_object o WHERE o.sob_input_format = ? OR o.sob_calc_format = ? ORDER BY o.sob_all_zzzzsys_form_id, o.sob_all_id', [$key, $key]) as $r) {
				$out[] = ['form' => (string)$r['f'], 'object' => (string)$r['id']];
			}
		}
		if ($db->tableExists('zzzzsys_browse')) {
			foreach ($db->all('SELECT sbr_zzzzsys_form_id AS f, sbr_title AS id FROM zzzzsys_browse WHERE sbr_format = ?', [$key]) as $r) {
				$out[] = ['form' => (string)$r['f'], 'object' => (string)$r['id'] . ' (' . t('fmt_browse_column') . ')'];
			}
		}
		return $out;
	}

	private static function renameUsage(string $old, string $new): void
	{
		$db = nu_db();
		if ($db->tableExists('zzzzsys_object')) {
			$db->run('UPDATE zzzzsys_object SET sob_input_format = ? WHERE sob_input_format = ?', [$new, $old]);
			$db->run('UPDATE zzzzsys_object SET sob_calc_format = ? WHERE sob_calc_format = ?', [$new, $old]);
		}
		if ($db->tableExists('zzzzsys_browse')) $db->run('UPDATE zzzzsys_browse SET sbr_format = ? WHERE sbr_format = ?', [$new, $old]);
	}

	public static function delete(string $id): ?string
	{
		$db = nu_db();
		$row = $db->tableExists('zzzzsys_format') ? $db->one('SELECT * FROM zzzzsys_format WHERE zzzzsys_format_id = ?', [$id]) : null;
		if (!$row) return t('err_not_found');
		$key = strtoupper(substr((string)$row['srm_type'], 0, 1)) . '|' . trim((string)$row['srm_format']);
		if ($n = count(self::usage($key))) return t('fmt_err_in_use', $n);
		$db->delete('zzzzsys_format', 'zzzzsys_format_id', $id);
		self::$registry = null;
		return null;
	}
}
