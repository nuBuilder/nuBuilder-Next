<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * Defaults of custom user fields that are worked out when a user is created (0.8.1074).
 *
 *   fixed text           a value as before ("Zurich")
 *   expression           date keywords:  today, yesterday, tomorrow, now, month_start, month_end, year_start, year_end,
 *                                        each with an offset ±N d|w|m|y ("today+30d", "month_end-1m")
 *                        counter:        next:EMP-{0000}  → the highest number of this field + 1, padded
 *                        text template:  {first_name}.{last_name}, {email}, {<custom field code>} – the new user's values
 *                                        (sus_ may be left out) – and hash variables (#USER_NAME# = the admin creating the user)
 *   = SELECT …           SQL (read only, first column of the first row; hash variables bound) – globeadmin only
 *   proc:<code>          a procedure; its $result is the value – globeadmin only
 *
 * The form shows the worked-out value when it opens (templates fill in while the fields above are typed, nuuser.js);
 * data import and REST API work it out on save.
 */
final class NuUserDefault
{
	public const KEYWORDS = ['today', 'yesterday', 'tomorrow', 'now', 'month_start', 'month_end', 'year_start', 'year_end'];
	private const DATE_RE = '/^(today|yesterday|tomorrow|now|month_start|month_end|year_start|year_end)\s*(?:([+-])\s*(\d{1,4})\s*([dwmy]))?$/i';
	private const NEXT_RE = '/^next:(.*)\{(0+|#+)\}(.*)$/s';

	/** fixed | date | next | template | sql | proc */
	public static function kind(string $d): string
	{
		$d = trim($d);
		if ($d === '') return 'fixed';
		if (preg_match('/^=\s*(SELECT|WITH)\b/i', $d)) return 'sql';
		if (preg_match('/^proc:\s*[\w.-]+$/i', $d)) return 'proc';
		if (preg_match(self::DATE_RE, $d)) return 'date';
		if (preg_match(self::NEXT_RE, $d)) return 'next';
		if (preg_match('/\{[A-Za-z_]\w*\}|#[A-Za-z_]\w*#/', $d)) return 'template';
		return 'fixed';
	}

	public static function dynamic(string $d): bool
	{
		return self::kind($d) !== 'fixed';
	}

	/** Only the globeadmin may write SQL or procedure defaults. */
	public static function needsGlobeadmin(string $d): bool
	{
		return in_array(self::kind($d), ['sql', 'proc'], true);
	}

	/** Worked out only when the user is saved (templates with {fields}, procedures – they get the new user's values as #NEW_…#). */
	public static function onSave(string $d): bool
	{
		return self::usesFields($d) || self::kind($d) === 'proc';
	}

	/** Uses values of the new user ({…}) – known only once the user's fields are filled in. */
	public static function usesFields(string $d): bool
	{
		return self::kind($d) === 'template' && (bool)preg_match('/\{[A-Za-z_]\w*\}/', $d);
	}

	/**
	 * The value for a new user. $ctx = the new user's values (sus_* columns and custom field codes); $f = the field definition.
	 * @throws Throwable on SQL / procedure errors (check() reports them)
	 */
	public static function value(array $f, string $d, array $ctx = [], ?int $now = null): string
	{
		$d = trim($d);
		$now ??= time();
		return match (self::kind($d)) {
			'fixed' => $d,
			'date' => self::date($d, (string)$f['type'], $now),
			'next' => self::next($f, $d),
			'template' => self::template($d, $ctx),
			'sql' => self::sql(substr($d, 1)),
			'proc' => self::proc(trim(substr($d, 5)), $ctx),
		};
	}

	public static function date(string $d, string $type, int $now): string
	{
		if (!preg_match(self::DATE_RE, trim($d), $m)) return $d;
		$k = strtolower($m[1]);
		$t = (new DateTimeImmutable('@' . $now))->setTimezone(new DateTimeZone(date_default_timezone_get()));
		$t = match ($k) {
			'yesterday' => $t->modify('-1 day'),
			'tomorrow' => $t->modify('+1 day'),
			'month_start' => $t->modify('first day of this month'),
			'month_end' => $t->modify('last day of this month'),
			'year_start' => $t->setDate((int)$t->format('Y'), 1, 1),
			'year_end' => $t->setDate((int)$t->format('Y'), 12, 31),
			default => $t,
		};
		if (!empty($m[2])) {
			$unit = ['d' => 'day', 'w' => 'week', 'm' => 'month', 'y' => 'year'][strtolower($m[4])];
			$t = $t->modify($m[2] . (int)$m[3] . ' ' . $unit);
			if (in_array($k, ['month_end'], true) && $unit !== 'day' && $unit !== 'week') $t = $t->modify('last day of this month');
		}
		return ($k === 'now' && !in_array($type, ['date'], true)) ? $t->format('Y-m-d H:i') : $t->format('Y-m-d');
	}

	/** next:PRE{0000}SUF – the highest number already stored in this field with that prefix / suffix, + 1. */
	public static function next(array $f, string $d): string
	{
		if (!preg_match(self::NEXT_RE, trim($d), $m)) return $d;
		[, $pre, $pad, $suf] = $m;
		$max = 0;
		$code = (string)($f['code'] ?? '');
		if ($code !== '' && NuUserFields::validCode($code) && nu_db()->tableExists(NuUserFields::EXT)) {
			$db = nu_db();
			$col = NuDB::ident($code);
			try { $rows = $db->all('SELECT ' . $col . ' AS v FROM ' . NuUserFields::EXT . ' WHERE ' . $col . " LIKE ? ESCAPE '!'", [NuDB::likeRaw($pre) . '%']); } catch (Throwable) { $rows = []; }   // a new field: its column comes on save
			foreach ($rows as $r) {
				$v = (string)$r['v'];
				if ($suf !== '' && !str_ends_with($v, $suf)) continue;
				$mid = substr($v, strlen($pre), strlen($v) - strlen($pre) - strlen($suf));
				if ($mid !== '' && ctype_digit($mid)) $max = max($max, (int)$mid);
			}
		}
		$n = (string)($max + 1);
		if ($pad[0] === '0') $n = str_pad($n, strlen($pad), '0', STR_PAD_LEFT);
		return $pre . $n . $suf;
	}

	/** {field} = the new user's value (sus_ may be left out), then hash variables as text. */
	public static function template(string $d, array $ctx): string
	{
		$s = (string)preg_replace_callback('/\{([A-Za-z_]\w*)\}/', static function ($m) use ($ctx) {
			$k = strtolower($m[1]);
			foreach ([$k, 'sus_' . $k] as $key) if (array_key_exists($key, $ctx)) return trim((string)$ctx[$key]);
			return '';
		}, $d);
		return str_contains($s, '#') ? (string)NuHash::replace($s, NuHash::plain()) : $s;
	}

	private static function sql(string $sql): string
	{
		$sql = trim($sql);
		if (!NuDemo::readOnlySql($sql)) throw new RuntimeException(t('ufd_err_readonly'));
		$q = NuHash::sql($sql, NuHash::plain());
		$v = nu_db()->readOnly(static fn() => nu_db()->value($q));
		return $v === null || $v === false ? '' : trim((string)$v);
	}

	private static function proc(string $code, array $ctx): string
	{
		$hash = NuHash::plain();
		foreach ($ctx as $k => $v) if (is_scalar($v) || $v === null) {   // #NEW_LOGIN_NAME#, #NEW_EMAIL#, #NEW_<custom field code># …
			$key = strtoupper((string)preg_replace('/^sus_/', '', (string)$k));
			$hash['NEW_' . $key] = (string)$v;
		}
		$r = NuPhp::procedure($code, $hash);
		if (empty($r['ok'])) throw new RuntimeException((string)($r['error'] ?? 'procedure'));
		$v = NuPhp::$result;
		return is_scalar($v) ? trim((string)$v) : '';
	}

	/** Error text of a default for this field, or null. Example values for the {fields} check the type only when the template has text around them. */
	public static function check(array $f, string $d): ?string
	{
		$d = trim($d);
		$kind = self::kind($d);
		if ($kind === 'fixed') return null;
		if (self::needsGlobeadmin($d) && empty(NuAuth::user()['globeadmin'])) return t('ufd_err_ga');
		if ($kind === 'proc' && !nu_db()->value('SELECT COUNT(*) FROM zzzzsys_php WHERE sph_code = ?', [trim(substr($d, 5))])) return t('form_err_proc_missing', trim(substr($d, 5)));
		if ($kind === 'date' && !in_array((string)$f['type'], ['date', 'text', 'longtext'], true)) return t('ufd_err_type');
		if ($kind === 'next' && !in_array((string)$f['type'], ['text', 'integer'], true)) return t('ufd_err_type');
		if ($kind === 'proc' || self::usesFields($d)) return null;   // procedures run only when a user is created
		try {
			$v = self::value($f, $d);
		} catch (Throwable $e) {
			return t('uf_err_sql') . ' ' . mb_substr($e->getMessage(), 0, 200);
		}
		if ($v === '') return null;
		return NuUserFields::checkValue(['is_unique' => '0', 'required' => '0'] + $f, $v, null);
	}

	/** Example result for the editor ("→ 2026-11-10"); fields of a template are shown with sample values. */
	public static function example(array $f, string $d): array
	{
		$kind = self::kind($d);
		if ($kind === 'fixed') return ['kind' => $kind, 'value' => trim($d)];
		if ($kind === 'proc') return ['kind' => $kind, 'value' => '', 'note' => t('ufd_ex_proc')];
		$sample = ['sus_login_name' => 'jdoe', 'sus_name' => 'Jane Doe', 'sus_first_name' => 'Jane', 'sus_last_name' => 'Doe', 'sus_email' => 'jane.doe@example.com', 'sus_code' => 'JD'];
		try {
			return ['kind' => $kind, 'value' => self::value($f, $d, $sample)];
		} catch (Throwable $e) {
			return ['kind' => $kind, 'value' => '', 'error' => mb_substr($e->getMessage(), 0, 200)];
		}
	}

	/**
	 * Values for a new user: every field without a value that has a default. $given = values already set, $ctx = the user's columns.
	 * Errors of one default leave that field empty (logged as debug).
	 */
	public static function forNew(array $given, array $ctx): array
	{
		$out = [];
		foreach (NuUserFields::all() as $code => $f) {
			if (($given[$code] ?? '') !== '' || (string)$f['default_value'] === '') continue;
			try {
				$v = self::value($f, (string)$f['default_value'], $ctx + $given);
			} catch (Throwable $e) {
				error_log('nuBuilder Next: default of user field ' . $code . ': ' . $e->getMessage());
				$v = '';
			}
			if ($v !== '') $out[$code] = $v;
		}
		return $out;
	}
}
