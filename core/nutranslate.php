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

	// ------------------------------------------------------------------ texts of the forms, missing translations, AI round trip (0.8.754)

	/** Kinds of texts: where a text was found. */
	public const KINDS = ['form', 'tab', 'label', 'column', 'option', 'code'];
	public const MAX_LEN = 500;
	public const AI_FORMAT = 'nunext-translate-1';

	/**
	 * Languages to translate into (nuBuilder names, without English): the installed interface languages, the languages that have
	 * translations already and those of users – not every language of nuBuilder Forte's setup list (set_languages_included).
	 */
	public static function targetLanguages(): array
	{
		$l = [];
		try {
			foreach (nu_db()->all("SELECT DISTINCT trl_language FROM zzzzsys_translate WHERE trl_language IS NOT NULL AND trl_language <> '' AND zzzzsys_translate_id NOT LIKE 'nu%'") as $r) $l[] = trim((string)$r['trl_language']);
			foreach (nu_db()->all("SELECT DISTINCT sus_language FROM zzzzsys_user WHERE sus_language IS NOT NULL AND sus_language <> ''") as $r) $l[] = trim((string)$r['sus_language']);
		} catch (Throwable) {
		}
		$l = array_values(array_filter($l, static fn($x) => $x !== '' && strcasecmp($x, 'English') !== 0));
		foreach (NuLanguages::installed() as $code) {
			$name = NU_LANGS[$code][1] ?? '';
			if ($name !== '' && strcasecmp($name, 'English') !== 0) $l[] = $name;
		}
		$l = array_values(array_unique($l));
		sort($l, SORT_NATURAL | SORT_FLAG_CASE);
		return $l;
	}

	/** The application's forms that can be chosen (not nuBuilder Forte's): id => "code – description". */
	public static function forms(): array
	{
		$out = [];
		foreach (nu_db()->all("SELECT zzzzsys_form_id, sfo_code, sfo_description FROM zzzzsys_form WHERE zzzzsys_form_id NOT LIKE 'nu%' ORDER BY sfo_code") as $f) {
			$out[(string)$f['zzzzsys_form_id']] = trim((string)$f['sfo_code'] . ' – ' . (string)$f['sfo_description'], ' –');
		}
		return $out;
	}

	/** A form and the forms of its sub-form objects (also nested, at most 5 levels). */
	public static function formFamily(string $formId): array
	{
		$ids = [$formId];
		$todo = [$formId];
		for ($depth = 0; $todo && $depth < 5; $depth++) {
			$in = implode(',', array_fill(0, count($todo), '?'));
			$these = $todo;
			$todo = [];
			foreach (nu_db()->all("SELECT DISTINCT sob_subform_zzzzsys_form_id AS f FROM zzzzsys_object WHERE sob_all_type = 'subform' AND sob_all_zzzzsys_form_id IN ($in)", $these) as $r) {
				$f = trim((string)$r['f']);
				if ($f !== '' && !in_array($f, $ids, true)) { $ids[] = $f; $todo[] = $f; }
			}
		}
		return $ids;
	}

	/** Texts of a static select (nuBuilder's "v|t|v|t", lines, or a JSON list); [] for SQL options. */
	public static function optionTexts(string $src): array
	{
		$src = trim($src);
		if ($src === '' || preg_match('/^\s*(SELECT|WITH|\(|EXEC)\b/i', $src)) return [];
		$out = [];
		if (str_starts_with($src, '[')) {
			foreach (json_decode($src, true) ?: [] as $v) $out[] = is_array($v) ? (string)($v[1] ?? $v[0] ?? '') : (string)$v;
		} else {
			$parts = explode('|', str_replace(["\r\n", "\r", "\n"], '', $src));
			if (end($parts) === '') array_pop($parts);
			for ($i = 1, $n = count($parts); $i < $n; $i += 2) $out[] = $parts[$i];
			if (count($parts) % 2 === 1) $out[] = (string)end($parts);
		}
		return $out;
	}

	/** First arguments of nuTranslate('…') calls written as plain string literals (JavaScript and PHP). */
	public static function codeStrings(?string $code): array
	{
		$code = (string)$code;
		if ($code === '' || stripos($code, 'nuTranslate') === false) return [];
		$out = [];
		if (preg_match_all('/nuTranslate\s*\(\s*([\'"`])((?:\\\\.|(?!\1).)*?)\1\s*[,)]/s', $code, $m, PREG_SET_ORDER)) {
			foreach ($m as $x) {
				if ($x[1] === '`' && str_contains($x[2], '${')) continue;            // template literal with a value: not a fixed text
				$out[] = stripcslashes($x[2]);
			}
		}
		return array_values(array_unique($out));
	}

	/** Is a text worth translating? */
	private static function translatable(?string $s): bool
	{
		$s = (string)$s;
		if (trim($s) === '' || $s[0] === '|' || preg_match('/^#[^#]+#$/', trim($s)) || mb_strlen($s) > self::MAX_LEN) return false;
		$plain = trim(strip_tags($s));
		return $plain !== '' && !is_numeric($plain);
	}

	/**
	 * The texts of the application's forms – or of one form and its sub-forms – that translations apply to:
	 * [text => ['where' => [up to 5 places], 'kinds' => [kind, …]]], sorted.
	 */
	public static function texts(string $formId = ''): array
	{
		$db = nu_db();
		$ids = $formId !== '' ? self::formFamily($formId) : array_keys(self::forms());
		$texts = [];
		if (!$ids) return [];
		$add = static function (?string $s, string $where, string $kind) use (&$texts) {
			if (!self::translatable($s)) return;
			$s = (string)$s;
			if (!isset($texts[$s])) $texts[$s] = ['where' => [], 'kinds' => []];
			if (count($texts[$s]['where']) < 5 && !in_array($where, $texts[$s]['where'], true)) $texts[$s]['where'][] = $where;
			if (!in_array($kind, $texts[$s]['kinds'], true)) $texts[$s]['kinds'][] = $kind;
		};
		foreach (array_chunk($ids, 200) as $chunk) {
			$in = implode(',', array_fill(0, count($chunk), '?'));
			$code = [];
			foreach ($db->all("SELECT zzzzsys_form_id, sfo_code, sfo_description, sfo_javascript, sfo_browse_javascript, sfo_edit_javascript FROM zzzzsys_form WHERE zzzzsys_form_id IN ($in)", $chunk) as $f) {
				$fc = (string)$f['sfo_code'];
				$code[(string)$f['zzzzsys_form_id']] = $fc;
				$add($f['sfo_description'], $fc, 'form');
				foreach (['sfo_javascript', 'sfo_browse_javascript', 'sfo_edit_javascript'] as $c) foreach (self::codeStrings($f[$c]) as $x) $add($x, $fc . ' · ' . t('trl_where_code'), 'code');
			}
			$objIds = [];
			foreach ($db->all("SELECT zzzzsys_object_id, sob_all_zzzzsys_form_id, sob_all_id, sob_all_type, sob_all_label, sob_select_sql, sob_html_code, sob_input_javascript, sob_lookup_javascript
				FROM zzzzsys_object WHERE sob_all_zzzzsys_form_id IN ($in)", $chunk) as $o) {
				$where = ($code[(string)$o['sob_all_zzzzsys_form_id']] ?? '') . ' · ' . $o['sob_all_id'];
				$objIds[(string)$o['zzzzsys_object_id']] = $where;
				if ($o['sob_all_type'] !== 'html') $add($o['sob_all_label'], $where, 'label');
				if ($o['sob_all_type'] === 'select') foreach (self::optionTexts((string)$o['sob_select_sql']) as $x) $add($x, $where, 'option');
				foreach (['sob_html_code', 'sob_input_javascript', 'sob_lookup_javascript'] as $c) foreach (self::codeStrings($o[$c]) as $x) $add($x, $where, 'code');
			}
			foreach ($db->all("SELECT t.syt_title, t.syt_zzzzsys_form_id FROM zzzzsys_tab t WHERE t.syt_zzzzsys_form_id IN ($in)", $chunk) as $tb) $add($tb['syt_title'], ($code[(string)$tb['syt_zzzzsys_form_id']] ?? '') . ' · ' . t('trl_where_tab'), 'tab');
			foreach ($db->all("SELECT b.sbr_title, b.sbr_zzzzsys_form_id FROM zzzzsys_browse b WHERE b.sbr_zzzzsys_form_id IN ($in)", $chunk) as $b) $add($b['sbr_title'], ($code[(string)$b['sbr_zzzzsys_form_id']] ?? '') . ' · ' . t('trl_where_column'), 'column');
			// code: object events, the form's PHP hooks (<form id>_BE …) and the lookups' After Browse (<object id>_AB)
			foreach (array_chunk(array_keys($objIds), 300) as $oc) {
				$oin = implode(',', array_fill(0, count($oc), '?'));
				foreach ($db->all("SELECT sev_zzzzsys_object_id, sev_javascript FROM zzzzsys_event WHERE sev_zzzzsys_object_id IN ($oin)", $oc) as $e) {
					foreach (self::codeStrings($e['sev_javascript']) as $x) $add($x, $objIds[(string)$e['sev_zzzzsys_object_id']] ?? '', 'code');
				}
				foreach ($db->all("SELECT zzzzsys_php_id, sph_php FROM zzzzsys_php WHERE zzzzsys_php_id IN (" . implode(',', array_fill(0, count($oc), '?')) . ")", array_map(static fn($x) => $x . '_AB', $oc)) as $ph) {
					foreach (self::codeStrings($ph['sph_php']) as $x) $add($x, ($objIds[substr((string)$ph['zzzzsys_php_id'], 0, -3)] ?? '') . ' · PHP', 'code');
				}
			}
			$hooks = [];
			foreach ($chunk as $fid) foreach (['BB', 'BE', 'BS', 'AS', 'BD', 'AD'] as $h) $hooks[] = $fid . '_' . $h;
			foreach (array_chunk($hooks, 300) as $hc) {
				foreach ($db->all("SELECT zzzzsys_php_id, sph_php FROM zzzzsys_php WHERE zzzzsys_php_id IN (" . implode(',', array_fill(0, count($hc), '?')) . ")", $hc) as $ph) {
					$fid = substr((string)$ph['zzzzsys_php_id'], 0, -3);
					foreach (self::codeStrings($ph['sph_php']) as $x) $add($x, ($code[$fid] ?? '') . ' · PHP ' . substr((string)$ph['zzzzsys_php_id'], -2), 'code');
				}
			}
		}
		uksort($texts, static fn($a, $b) => strnatcasecmp($a, $b));
		return $texts;
	}

	/** Is a text translated in a language (own or nuBuilder Forte's row, as the form pages read them)? */
	public static function has(string $text, string $lang): bool
	{
		$d = self::dictionary($lang);
		return isset($d[$text]) || isset($d[trim($text)]);
	}

	/** Texts without a translation in $lang: [text => [where, …]] – all forms, or one form with its sub-forms. */
	public static function missing(string $lang, string $formId = ''): array
	{
		$out = [];
		foreach (self::texts($formId) as $s => $x) if (!self::has($s, $lang)) $out[$s] = $x['where'];
		return $out;
	}

	/** One form in several languages: [text => ['where', 'kinds', 'tr' => [lang => translation | null]]] and the missing counts per language. */
	public static function matrix(string $formId, array $langs): array
	{
		$rows = [];
		$counts = array_fill_keys($langs, 0);
		foreach (self::texts($formId) as $s => $x) {
			$tr = [];
			foreach ($langs as $l) {
				$d = self::dictionary($l);
				$tr[$l] = $d[$s] ?? $d[trim($s)] ?? null;
				if ($tr[$l] === null) $counts[$l]++;
			}
			$rows[$s] = $x + ['tr' => $tr];
		}
		return ['rows' => $rows, 'missing' => $counts];
	}

	/** Missing translations of a form per target language (design mode): [lang => count] of the languages with gaps. */
	public static function summary(string $formId): array
	{
		$langs = self::targetLanguages();
		if (!$langs) return [];
		return array_filter(self::matrix($formId, $langs)['missing']);
	}

	// ------------------------------------------------------------------ AI round trip: prompt with JSON → answer → check → save

	/** The JSON the AI gets: the texts (only the missing ones, or all), their context and the translations there are. */
	public static function aiPayload(string $formId, array $langs, bool $onlyMissing): array
	{
		$m = self::matrix($formId, $langs);
		$texts = [];
		foreach ($m['rows'] as $s => $r) {
			$have = array_filter($r['tr'], static fn($v) => $v !== null);
			if ($onlyMissing && count($have) === count($langs)) continue;
			$e = ['en' => $s, 'context' => implode('; ', array_map(static fn($k) => self::kindLabel($k), $r['kinds'])) . ' – ' . implode(', ', $r['where'])];
			if ($have) $e['existing'] = $have;
			$texts[] = $e;
		}
		$f = nu_db()->one('SELECT sfo_code, sfo_description FROM zzzzsys_form WHERE zzzzsys_form_id = ?', [$formId]) ?: [];
		return ['format' => self::AI_FORMAT, 'form' => trim((string)($f['sfo_code'] ?? '') . ' – ' . (string)($f['sfo_description'] ?? ''), ' –'),
			'source_language' => 'English', 'target_languages' => array_values($langs), 'texts' => $texts];
	}

	/** English name of a kind for the prompt (the prompt is always English). */
	private static function kindLabel(string $k): string
	{
		return ['form' => 'form title', 'tab' => 'tab title', 'label' => 'field label or button text', 'column' => 'list column title', 'option' => 'choice of a drop-down list',
			'code' => 'message shown by code'][$k] ?? $k;
	}

	/** The whole prompt (English, for any AI chat): instructions, the answer format and the JSON. */
	public static function aiPrompt(string $formId, array $langs, bool $onlyMissing): array
	{
		$p = self::aiPayload($formId, $langs, $onlyMissing);
		$example = ['format' => self::AI_FORMAT, 'translations' => array_fill_keys($langs ?: ['German'], ['<English text exactly as given>' => '<translation>'])];
		$prompt = "You translate the user interface texts of a database application (nuBuilder Next) from English into "
			. implode(', ', $langs) . ".\n\n"
			. "The JSON below lists every text with its context (where it appears) and, under \"existing\", translations that already exist – keep their terminology consistent.\n\n"
			. "Rules:\n"
			. "- Translate each text in \"texts\" into every target language. The texts are short labels and messages in forms: translate them as a native speaker would label a business application – short, clear, neutral (no slang). Use the formal register where a language distinguishes it, or avoid addressing the user directly.\n"
			. "- Keep exactly as they are: placeholders such as %s, %s1, %d, hash variables such as #RECORD_ID#, HTML tags and entities, line breaks, and leading or trailing spaces.\n"
			. "- Keep a translation at most " . self::MAX_LEN . " characters long.\n"
			. "- Use the English text EXACTLY as given as the key – do not change, trim or correct it.\n"
			. "- Leave out a text only if it must not be translated (a name, a code); do not invent texts.\n"
			. "- Answer with ONE JSON object and nothing else (no explanation), in this format:\n\n"
			. json_encode($example, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n"
			. "The texts:\n\n" . json_encode($p, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
		return ['prompt' => $prompt, 'count' => count($p['texts'])];
	}

	/** Placeholders and hash variables a translation must keep. */
	private static function tokens(string $s): array
	{
		preg_match_all('/%s\d*|%d|#[A-Za-z0-9_]+#/', $s, $m);
		$t = $m[0];
		sort($t);
		return $t;
	}

	/**
	 * Reads an AI answer (the JSON may be wrapped in text or ``` fences) and checks it against the form's texts:
	 * ['rows' => [[i, lang, en, tr, current, status, note]], 'error' => ''] – status new / change / same / skip (with note).
	 */
	public static function aiCheck(string $answer, string $formId, array $langs): array
	{
		$answer = trim($answer);
		$a = strpos($answer, '{');
		$b = strrpos($answer, '}');
		$d = $a !== false && $b !== false && $b > $a ? json_decode(substr($answer, $a, $b - $a + 1), true) : null;
		if (!is_array($d)) return ['rows' => [], 'error' => t('trl_ai_err_json')];
		$tr = $d['translations'] ?? null;
		if (!is_array($tr) || !$tr) return ['rows' => [], 'error' => t('trl_ai_err_format')];
		$texts = self::texts($formId);
		$targets = self::targetLanguages();
		$byLower = [];
		foreach ($targets as $l) $byLower[mb_strtolower($l)] = $l;
		$rows = [];
		$i = 0;
		foreach ($tr as $lang => $list) {
			if (!is_array($list)) continue;
			$langName = $byLower[mb_strtolower(trim((string)$lang))] ?? null;
			foreach ($list as $en => $text) {
				$en = (string)$en;
				$text = is_scalar($text) ? (string)$text : '';
				$cur = $langName !== null ? (self::ownRow($en, $langName)['trl_translation'] ?? (self::dictionary($langName)[$en] ?? null)) : null;
				$row = ['i' => $i++, 'lang' => $langName ?? (string)$lang, 'en' => $en, 'tr' => $text, 'current' => $cur, 'status' => 'new', 'note' => ''];
				if ($langName === null) { $row['status'] = 'skip'; $row['note'] = t('trl_ai_err_lang'); }
				elseif (!isset($texts[$en])) { $row['status'] = 'skip'; $row['note'] = t('trl_ai_err_text'); }
				elseif (trim($text) === '') { $row['status'] = 'skip'; $row['note'] = t('trl_ai_err_empty'); }
				elseif (mb_strlen($text) > self::MAX_LEN) { $row['status'] = 'skip'; $row['note'] = t('err_too_long', self::MAX_LEN); }
				elseif ($cur !== null && $cur === $text) $row['status'] = 'same';
				elseif ($cur !== null) $row['status'] = 'change';
				if ($row['status'] !== 'skip' && self::tokens($en) !== self::tokens($text)) $row['note'] = t('trl_ai_warn_tokens');
				$rows[] = $row;
			}
		}
		if (!$rows) return ['rows' => [], 'error' => t('trl_ai_err_format')];
		return ['rows' => $rows, 'error' => ''];
	}

	/** The own (not nuBuilder Forte's) row of a text and language, or null. */
	private static function ownRow(string $en, string $lang): ?array
	{
		return nu_db()->one("SELECT zzzzsys_translate_id, trl_translation FROM zzzzsys_translate WHERE trl_language = ? AND trl_english = ? AND zzzzsys_translate_id NOT LIKE 'nu%'", [$lang, $en]) ?: null;
	}

	/**
	 * Saves the picked rows of aiCheck(): a new own row, or the own row changed – nuBuilder Forte's rows are never changed, an own row
	 * overrides them. $canCreate / $canSave: the user's rights on the translations form. @return array{created: int, changed: int, skipped: int}
	 */
	public static function aiSave(array $rows, array $pick, bool $canCreate, bool $canSave): array
	{
		$out = ['created' => 0, 'changed' => 0, 'skipped' => 0];
		$pick = array_flip(array_map('intval', $pick));
		foreach ($rows as $r) {
			if (!isset($pick[(int)$r['i']])) continue;
			if (!in_array($r['status'], ['new', 'change'], true)) { $out['skipped']++; continue; }
			$own = self::ownRow((string)$r['en'], (string)$r['lang']);
			if ($own && !$canSave || !$own && !$canCreate) { $out['skipped']++; continue; }
			[$id, $err] = self::save(['trl_language' => $r['lang'], 'trl_english' => $r['en'], 'trl_translation' => $r['tr']], $own ? (string)$own['zzzzsys_translate_id'] : '');
			if ($err || $id === null) { $out['skipped']++; continue; }
			$own ? $out['changed']++ : $out['created']++;
		}
		return $out;
	}
}
