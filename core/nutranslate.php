<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * Translations of form texts (zzzzsys_translate, nuBuilder's form "nutranslate"), as in nuBuilder:
 *   - a text is replaced when trl_english matches it exactly for the reader's language;
 *   - own rows win over nuBuilder's ("nu…" ids);
 *   - a text starting with "|" is never translated (the "|" is removed);
 *   - English (or no language) = no translation.
 * Applied on form pages to object labels, tab titles, list column titles and form descriptions (NuFormMeta), and
 * through nuTranslate() in PHP and JavaScript. nuBuilder Next's own screens use lang/*.php instead.
 *
 * Reader's language: globeadmin → zzzzsys_setup.set_language, user → zzzzsys_user.sus_language (nuBuilder);
 * when empty English, as in nuBuilder (the nuBuilder Next menu language does not decide, since 0.8.179).
 */
final class NuTranslate
{
	public const TABLE = 'zzzzsys_translate';

	private static bool $active = false;
	private static ?string $lang = null;
	private static array $dict = [];

	/** Form pages switch translation on; the builder never does (it must see and save the original texts). */
	public static function activate(): void
	{
		self::$active = true;
	}

	public static function active(): bool
	{
		return self::$active;
	}

	/** nuBuilder language name of the reader ('' = English / none). */
	public static function language(): string
	{
		if (self::$lang !== null) return self::$lang;
		$l = '';
		try {
			$u = NuAuth::user();
			if ($u && $u['globeadmin']) {
				$l = (string)(NuMailer::setupRow()['set_language'] ?? '');
			} elseif ($u) {
				$l = (string)nu_db()->value('SELECT sus_language FROM zzzzsys_user WHERE zzzzsys_user_id = ?', [(string)$u['id']]);
			}
		} catch (Throwable) {
		}
		$l = trim($l);
		if (strcasecmp($l, 'English') === 0) $l = '';
		return self::$lang = $l;
	}

	/** english => translation for a language (own rows before nuBuilder's). */
	public static function dictionary(?string $lang = null): array
	{
		$lang ??= self::language();
		if ($lang === '') return [];
		if (isset(self::$dict[$lang])) return self::$dict[$lang];
		$d = [];
		try {
			$db = nu_db();
			if ($db->tableExists(self::TABLE)) {
				foreach ($db->all("SELECT trl_english, trl_translation FROM zzzzsys_translate WHERE trl_language = ?
					ORDER BY CASE WHEN zzzzsys_translate_id LIKE 'nu%' THEN 1 ELSE 0 END, zzzzsys_translate_id", [$lang]) as $r) {
					$e = (string)$r['trl_english'];
					if ($e !== '' && !isset($d[$e]) && (string)$r['trl_translation'] !== '') $d[$e] = (string)$r['trl_translation'];
				}
			}
		} catch (Throwable) {
		}
		return self::$dict[$lang] = $d;
	}

	/** Translates one text ("|text" stays as "text"). */
	public static function text(?string $s, ?string $lang = null): string
	{
		$s = (string)$s;
		if ($s === '') return '';
		if ($s[0] === '|') return substr($s, 1);
		$d = self::dictionary($lang);
		return $d[$s] ?? $d[trim($s)] ?? $s;
	}

	/** nuBuilder's nuTranslate($str, ...$args): placeholders %s or %s1, %s2 … */
	public static function format(string $s, array $args): string
	{
		$s = self::text($s);
		if (!$args || !str_contains($s, '%')) return $s;
		try {
			if (preg_match_all('/%s(\d+)/', $s, $m)) {
				$vals = [];
				foreach ($m[1] as $n) if (array_key_exists((int)$n - 1, $args)) $vals[] = $args[(int)$n - 1];
				return vsprintf((string)preg_replace('/%s(\d+)/', '%s', $s), $vals);
			}
			return vsprintf($s, $args);
		} catch (Throwable) {
			return $s;
		}
	}

	// ------------------------------------------------------------------ administration

	public static function ensureTable(): void
	{
		$db = nu_db();
		if ($db->tableExists(self::TABLE)) return;
		$T = static fn(string $t, string $l = '') => NuDDL::sqlType($t, $l);
		$db->run('CREATE TABLE zzzzsys_translate (zzzzsys_translate_id ' . $T('id') . ' NOT NULL PRIMARY KEY, trl_language ' . $T('text', '20') . ' NULL, '
			. 'trl_english ' . $T('text', '500') . ' NULL, trl_translation ' . $T('text', '500') . ' NULL, trl_json ' . $T('longtext') . ' NULL)'
			. ($db->type === 'mysql' ? NuDDL::mysqlTableOptions() : ''));
	}

	/** Languages to offer: zzzzsys_setup.set_languages_included plus the ones in use (without English). */
	public static function languages(): array
	{
		$l = [];
		$inc = json_decode((string)(NuMailer::setupRow()['set_languages_included'] ?? ''), true);
		if (is_array($inc)) foreach ($inc as $x) if (is_string($x) && trim($x) !== '') $l[] = trim($x);
		try {
			foreach (nu_db()->all("SELECT DISTINCT trl_language FROM zzzzsys_translate WHERE trl_language IS NOT NULL AND trl_language <> ''") as $r) $l[] = (string)$r['trl_language'];
		} catch (Throwable) {
		}
		$l = array_values(array_unique(array_filter($l, static fn($x) => strcasecmp($x, 'English') !== 0)));
		sort($l, SORT_NATURAL | SORT_FLAG_CASE);
		return $l;
	}

	/** @return array{0: ?string, 1: array} [id, errors] – nuBuilder's own rows ("nu…") cannot be changed. */
	public static function save(array $in, string $id = ''): array
	{
		$db = nu_db();
		$row = ['trl_language' => trim((string)($in['trl_language'] ?? '')), 'trl_english' => (string)($in['trl_english'] ?? ''), 'trl_translation' => (string)($in['trl_translation'] ?? '')];
		$errors = [];
		if ($row['trl_language'] === '') $errors['trl_language'] = t('err_required');
		if (trim($row['trl_english']) === '') $errors['trl_english'] = t('err_required');
		if (trim($row['trl_translation']) === '') $errors['trl_translation'] = t('err_required');
		nu_maxlen($errors, 'trl_language', $row['trl_language'], 20);
		nu_maxlen($errors, 'trl_english', $row['trl_english'], 500);
		nu_maxlen($errors, 'trl_translation', $row['trl_translation'], 500);
		if (!isset($errors['trl_english']) && $db->value("SELECT COUNT(*) FROM zzzzsys_translate WHERE trl_language = ? AND trl_english = ? AND zzzzsys_translate_id <> ? AND zzzzsys_translate_id NOT LIKE 'nu%'",
			[$row['trl_language'], $row['trl_english'], $id])) $errors['trl_english'] = t('trl_err_duplicate');
		if ($errors) return [null, $errors];
		if ($id !== '') {
			$db->update(self::TABLE, $row, 'zzzzsys_translate_id', $id);
		} else {
			$id = nu_id();
			$db->insert(self::TABLE, ['zzzzsys_translate_id' => $id] + $row);
		}
		unset(self::$dict[$row['trl_language']]);
		return [$id, []];
	}

	/**
	 * Texts of the application's forms (not nuBuilder's) without a translation in $lang:
	 * [text => [where, …]] – object labels, tab titles, list column titles, form descriptions.
	 */
	public static function missing(string $lang): array
	{
		$db = nu_db();
		$have = [];
		foreach ($db->all('SELECT trl_english FROM zzzzsys_translate WHERE trl_language = ?', [$lang]) as $r) $have[(string)$r['trl_english']] = true;
		$texts = [];
		$add = static function (?string $s, string $where) use (&$texts, $have) {
			$s = (string)$s;
			if (trim($s) === '' || $s[0] === '|' || isset($have[$s]) || preg_match('/^#[^#]+#$/', trim($s)) || mb_strlen($s) > 500) return;
			if (trim(strip_tags($s)) === '' || is_numeric(trim(strip_tags($s)))) return;
			if (!isset($texts[$s]) || count($texts[$s]) < 5) $texts[$s][] = $where;
		};
		foreach ($db->all("SELECT zzzzsys_form_id, sfo_code, sfo_description FROM zzzzsys_form WHERE zzzzsys_form_id NOT LIKE 'nu%'") as $f) $add($f['sfo_description'], $f['sfo_code']);
		foreach ($db->all("SELECT o.sob_all_label, o.sob_all_id, o.sob_all_type, f.sfo_code FROM zzzzsys_object o JOIN zzzzsys_form f ON f.zzzzsys_form_id = o.sob_all_zzzzsys_form_id
			WHERE f.zzzzsys_form_id NOT LIKE 'nu%' AND o.sob_all_type NOT IN ('html')") as $o) $add($o['sob_all_label'], $o['sfo_code'] . ' · ' . $o['sob_all_id']);
		foreach ($db->all("SELECT t.syt_title, f.sfo_code FROM zzzzsys_tab t JOIN zzzzsys_form f ON f.zzzzsys_form_id = t.syt_zzzzsys_form_id WHERE f.zzzzsys_form_id NOT LIKE 'nu%'") as $t) $add($t['syt_title'], $t['sfo_code'] . ' · ' . t('trl_where_tab'));
		foreach ($db->all("SELECT b.sbr_title, f.sfo_code FROM zzzzsys_browse b JOIN zzzzsys_form f ON f.zzzzsys_form_id = b.sbr_zzzzsys_form_id WHERE f.zzzzsys_form_id NOT LIKE 'nu%'") as $b) $add($b['sbr_title'], $b['sfo_code'] . ' · ' . t('trl_where_column'));
		ksort($texts, SORT_NATURAL | SORT_FLAG_CASE);
		return $texts;
	}
}
