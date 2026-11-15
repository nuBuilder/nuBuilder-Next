<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * Suggestions while typing (0.8.1212): a text input offers a list of values – a fixed list or the rows of a query – and a choice
 * fills the field, and other fields from the row's columns.
 *
 * Stored in sob_all_json nunext.suggest of the input object (nuBuilder Forte ignores it):
 *   {src: 'list' | 'sql', list: [text, …], sql: 'SELECT … #term# …', min: 1, max: 15, strict: false, fill: {column: field id}, width: px}
 * The browser gets client() – never the SQL; it asks ?p=formapi action suggest {object, term} and gets rows() of this object.
 * #term# in the query is the typed text: put in as a text value (quoted where it stands alone, escaped inside '…'), so it can never
 * change the query. The other hash variables work as in every query of a form.
 */
final class NuSuggest
{
	public const MAX      = 50;          // most rows of one answer
	public const MAX_LIST = 500;         // most entries of a fixed list
	public const TERM     = 200;         // longest typed text sent to the query
	private const MARK    = '__nu_suggest_term__';

	/** Input types that can offer suggestions. */
	public const INPUTS = ['', 'text', 'search', 'tel', 'email', 'url', 'number'];

	public static function offers(array $o): bool
	{
		return ($o['sob_all_type'] ?? '') === 'input' && in_array((string)($o['sob_input_type'] ?? ''), self::INPUTS, true);
	}

	/** The stored settings from what the design API got (array or JSON text); null = none. */
	public static function clean(mixed $c): ?array
	{
		if (is_string($c)) $c = json_decode($c, true);
		if (!is_array($c)) return null;
		$src = (string)($c['src'] ?? '');
		if (!in_array($src, ['list', 'sql'], true)) return null;
		$out = ['src' => $src];
		if ($src === 'list') {
			$list = $c['list'] ?? [];
			if (is_string($list)) $list = preg_split('/\r\n|\r|\n/', $list) ?: [];
			$list = array_values(array_unique(array_filter(array_map(static fn($x) => is_scalar($x) ? mb_substr(trim((string)$x), 0, 200) : '', is_array($list) ? $list : []), static fn($x) => $x !== '')));
			if (!$list) return null;
			$out['list'] = array_slice($list, 0, self::MAX_LIST);
		} else {
			$sql = trim((string)($c['sql'] ?? ''));
			if ($sql === '') return null;
			$out['sql'] = mb_substr($sql, 0, 20000);
		}
		$out['min'] = max(0, min(10, (int)($c['min'] ?? 1)));
		$out['max'] = max(1, min(self::MAX, (int)($c['max'] ?? 15) ?: 15));
		if (!empty($c['strict']) && $c['strict'] !== '0') $out['strict'] = true;
		$fill = $c['fill'] ?? null;
		if (is_string($fill)) $fill = self::parseFill($fill);
		if (is_array($fill)) {
			$f = [];
			foreach ($fill as $col => $field) {
				$col = trim((string)$col); $field = trim((string)$field);
				if (preg_match('/^[\w ]{1,64}$/u', $col) && preg_match('/^\w{1,100}$/', $field)) $f[$col] = $field;
			}
			if ($f) $out['fill'] = array_slice($f, 0, 20, true);
		}
		$w = (int)($c['width'] ?? 0);
		if ($w >= 80 && $w <= 1200) $out['width'] = $w;
		return $out;
	}

	/** "column = field, column = field" (also one per line) → [column => field]. */
	public static function parseFill(string $s): array
	{
		$out = [];
		foreach (preg_split('/[,;\r\n]+/', $s) ?: [] as $part) {
			if (!str_contains($part, '=')) continue;
			[$col, $field] = array_map('trim', explode('=', $part, 2));
			if ($col !== '' && $field !== '') $out[$col] = $field;
		}
		return $out;
	}

	public static function fillText(array $fill): string
	{
		return implode(', ', array_map(static fn($c, $f) => $c . ' = ' . $f, array_keys($fill), $fill));
	}

	/** A problem of the settings in words (design mode), or null. */
	public static function problem(array $cfg): ?string
	{
		if ($cfg['src'] !== 'sql') return null;
		if (!NuDemo::readOnlySql(self::withoutTerm($cfg['sql']))) return t('sg_err_select');
		return null;
	}

	/** The settings of an object, or null. */
	public static function of(array $o): ?array
	{
		if (!self::offers($o)) return null;
		$n = NuFormMeta::nunextJson($o['sob_all_json'] ?? null);
		return isset($n['suggest']) ? self::clean($n['suggest']) : null;
	}

	/** What the page gets (data-nu-suggest): never the query. */
	public static function client(array $o): ?array
	{
		$c = self::of($o);
		if (!$c) return null;
		$out = ['src' => $c['src'], 'min' => $c['min'], 'max' => $c['max']];
		if ($c['src'] === 'list') $out['list'] = $c['list'];
		foreach (['strict', 'fill', 'width'] as $k) if (isset($c[$k])) $out[$k] = $c[$k];
		if (!empty($c['strict'])) $out['msg'] = t('sg_not_in_list');
		return $out;
	}

	private static function withoutTerm(string $sql): string
	{
		return (string)preg_replace('/#term#/i', "''", $sql);
	}

	/**
	 * The query with the typed text put in as a value: where #term# stands alone it becomes a quoted text, inside '…' its escaped
	 * content (LIKE '#term#%'). Inside "…" or `…` it is left out.
	 */
	public static function bindTerm(string $sql, string $term, ?callable $escape = null): string
	{
		$escape ??= static fn(string $v): string => nu_db()->escapeString($v);
		$esc = $escape($term);
		$out = ''; $quote = ''; $n = strlen($sql); $len = strlen(self::MARK);
		for ($i = 0; $i < $n; $i++) {
			$ch = $sql[$i];
			if ($quote === '') {
				if (substr_compare($sql, self::MARK, $i, $len) === 0) { $out .= "'" . $esc . "'"; $i += $len - 1; continue; }
				if ($ch === "'" || $ch === '"' || $ch === '`') $quote = $ch;
				elseif ($ch === '-' && ($sql[$i + 1] ?? '') === '-') { $e = strpos($sql, "\n", $i); $e = $e === false ? $n : $e; $out .= substr($sql, $i, $e - $i); $i = $e - 1; continue; }
				elseif ($ch === '/' && ($sql[$i + 1] ?? '') === '*') { $e = strpos($sql, '*/', $i + 2); $e = $e === false ? $n : $e + 2; $out .= substr($sql, $i, $e - $i); $i = $e - 1; continue; }
				$out .= $ch;
				continue;
			}
			if (substr_compare($sql, self::MARK, $i, $len) === 0) { $out .= $quote === "'" ? $esc : ''; $i += $len - 1; continue; }
			if ($ch === '\\' && $i + 1 < $n) { $out .= $ch . $sql[++$i]; continue; }
			if ($ch === $quote) {
				if (($sql[$i + 1] ?? '') === $quote) { $out .= $ch . $sql[++$i]; continue; }
				$quote = '';
			}
			$out .= $ch;
		}
		return $out;
	}

	/**
	 * Rows of the object's query for the typed text: ['cols' => [names], 'rows' => [[text, …], …]].
	 * Read only, one SELECT, at most max rows; an error is written to the Debug results and gives no rows.
	 */
	public static function rows(array $o, string $term, array $hash): array
	{
		$c = self::of($o);
		$none = ['cols' => [], 'rows' => []];
		if (!$c || $c['src'] !== 'sql') return $none;
		$term = mb_substr($term, 0, self::TERM);
		if (mb_strlen($term) < $c['min']) return $none;
		$db = nu_db();
		if (!NuDemo::readOnlySql(self::withoutTerm($c['sql']))) return $none;
		// the other hash variables first (the marker keeps #term# apart, so a typed "#NAME#" is never replaced), then the text
		unset($hash['term'], $hash['TERM']);
		$sql = self::bindTerm(NuHash::sql((string)preg_replace('/#term#/i', self::MARK, $c['sql']), $hash), $term);
		$sql = rtrim(trim($sql), '; ');
		$max = $c['max'];
		try {
			$rows = $db->readOnly(static function () use ($db, $sql, $max): array {
				try {
					return $db->type === 'sqlsrv' ? $db->all("SELECT TOP $max * FROM ($sql) nuS") : $db->all("SELECT * FROM ($sql) nuS LIMIT $max");
				} catch (Throwable) {                               // two columns of one name, ORDER BY on SQL Server …: as written
					return array_slice($db->all($sql), 0, $max);
				}
			}, 5);
		} catch (Throwable $e) {
			if (class_exists('NuDebugLog')) NuDebugLog::write('Suggestions of ' . ($o['sob_all_id'] ?? '') . ":\n" . $e->getMessage());
			return $none + ['error' => true];
		}
		if (!$rows) return $none;
		$cols = array_map('strval', array_keys($rows[0]));
		$out = [];
		foreach ($rows as $r) $out[] = array_map(static fn($v) => $v === null ? '' : mb_substr((string)$v, 0, 300), array_values($r));
		return ['cols' => $cols, 'rows' => $out];
	}
}
