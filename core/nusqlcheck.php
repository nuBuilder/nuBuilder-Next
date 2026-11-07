<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * SQL check of the code editors (0.8.999, the owner picked "check against the database"): the database parses the statement without
 * running it – MySQL / MariaDB: a server-side prepare (PDO with emulated prepares off: syntax, tables, columns, GROUP BY), SQL Server:
 * SET NOEXEC ON (compiled, not executed). Only statements nuBuilder runs as a query (NuSelectSource::kind = query: SELECT, WITH, EXEC,
 * SHOW …) – lists, JSON, %LANGUAGES% and empty code are not SQL and give no errors.
 *
 * Hash variables are filled in first so the database does not trip over them: inside a string literal they stay as they are (the
 * literal is valid), elsewhere #TABLE_ID# becomes a placeholder table name (its "does not exist" error is dropped) and any other
 * #NAME# becomes NULL. Line numbers stay, as no replacement holds a line break.
 */
final class NuSqlCheck
{
	public const TABLE_PH = 'nu_chk_table_id';

	/** Who may use it: it tells whether tables and columns exist – form designers (save right on nuform / nuselect) and the globeadmin. */
	public static function allowed(): bool
	{
		$u = NuAuth::user();
		if (!$u || !empty($u['demo'])) return false;
		return !empty($u['globeadmin']) || NuAcl::can(NuAcl::FORM_FORM, 'save') || NuAcl::can(NuAcl::FORM_SELECT, 'save');
	}

	/** The SQL with the hash variables filled in (see the class comment); also used by the unit tests. */
	public static function fill(string $sql): string
	{
		$out = '';
		$n = strlen($sql);
		for ($i = 0; $i < $n; $i++) {
			$c = $sql[$i];
			if ($c === "'" || $c === '"' || $c === '`') {                  // string / quoted name: copied unchanged
				$j = $i + 1;
				while ($j < $n && $sql[$j] !== $c) { if ($sql[$j] === '\\' && $c !== '`') $j++; $j++; }
				$out .= substr($sql, $i, $j - $i + 1);
				$i = $j;
				continue;
			}
			if ($c === '-' && ($sql[$i + 1] ?? '') === '-') {                 // -- comment up to the line end
				$e = strpos($sql, "\n", $i);
				$e = $e === false ? $n : $e;
				$out .= substr($sql, $i, $e - $i);
				$i = $e - 1;
				continue;
			}
			if ($c === '/' && ($sql[$i + 1] ?? '') === '*') {                 // /* … */
				$e = strpos($sql, '*/', $i + 2);
				$e = $e === false ? $n : $e + 2;
				$out .= substr($sql, $i, $e - $i);
				$i = $e - 1;
				continue;
			}
			if ($c === '#' && preg_match('/\G#([A-Za-z_][A-Za-z0-9_]*)#/', $sql, $m, 0, $i)) {
				$out .= strcasecmp($m[1], 'TABLE_ID') === 0 ? self::TABLE_PH : 'NULL';
				$i += strlen($m[0]) - 1;
				continue;
			}
			$out .= $c;
		}
		return $out;
	}

	/**
	 * Problems [{line, col, msg}] – [] = fine or not a query; null = no check here (no database support, not allowed).
	 * @return list<array{line: int, col: int, msg: string}>|null
	 */
	public static function check(string $sql): ?array
	{
		if (trim($sql) === '' || NuSelectSource::kind($sql) !== 'query') return [];
		$db = nu_db();
		if (!in_array($db->type, ['mysql', 'sqlsrv'], true)) return null;
		$filled = self::fill($sql);
		$pdo = $db->pdo();
		try {
			if ($db->type === 'mysql') {
				$st = $pdo->prepare($filled);                              // emulated prepares are off (NuDB): MySQL parses and resolves names
				$st->closeCursor();
				unset($st);
			} else {
				$pdo->exec('SET NOEXEC ON');
				try { $pdo->query($filled); } finally { $pdo->exec('SET NOEXEC OFF'); }
			}
			return [];
		} catch (PDOException $e) {
			$msg = self::message($e);
			if (stripos($msg, self::TABLE_PH) !== false) return [];       // #TABLE_ID# – made by the procedure at run time
			if ((int)($e->errorInfo[1] ?? 0) === 1295) return [];          // a statement MySQL cannot prepare: nothing to say
			return [['line' => self::lineOf($sql, $msg), 'col' => 0, 'msg' => $msg]];
		}
	}

	/** The database's own message without driver prefixes ("SQLSTATE[42000]: Syntax error or access violation: 1064 …"). */
	private static function message(PDOException $e): string
	{
		$m = (string)($e->errorInfo[2] ?? '') !== '' ? (string)$e->errorInfo[2] : $e->getMessage();
		$m = (string)preg_replace('/^SQLSTATE\[\w+\]:?\s*(\[[^\]]*\]\s*)*/', '', $m);
		return trim((string)preg_replace('/\s+/', ' ', $m));
	}

	/** The line the message names ("… at line 3"), else the first line holding a name quoted in the message, else 1. */
	public static function lineOf(string $sql, string $msg): int
	{
		$lines = max(1, substr_count($sql, "\n") + 1);
		if (preg_match('/\bat line (\d+)\b/i', $msg, $m)) return max(1, min($lines, (int)$m[1]));
		if (preg_match_all("/'([^'\\s]{1,80})'/", $msg, $mm)) {
			foreach ($mm[1] as $name) {
				if (preg_match('/^(where|select|having|order|group|on|field|from|join)$/i', $name)) continue;   // the clause a message names
				foreach (array_unique([$name, substr((string)strrchr('.' . $name, '.'), 1)]) as $try) {   // db.table → table, alias.column → column
					if ($try === '') continue;
					if (preg_match('/(?<![A-Za-z0-9_$])' . preg_quote($try, '/') . '(?![A-Za-z0-9_$])/i', $sql, $hit, PREG_OFFSET_CAPTURE)) {
						return substr_count(substr($sql, 0, (int)$hit[0][1]), "\n") + 1;
					}
				}
			}
		}
		return 1;
	}
}
