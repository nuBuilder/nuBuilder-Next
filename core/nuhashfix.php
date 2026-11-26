<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * Hash variables outside quotes (Compatibility check): finds every #NAME# that stands outside a string quote in PHP and SQL and puts it
 * in quotes where that is safe – '#NAME#' in SQL and PHP, \'#NAME#\' inside a single-quoted PHP string. Places where quotes would change
 * the meaning stay for a person: a number (LIMIT / OFFSET / TOP), a table or column name (FROM, JOIN, ORDER BY, GROUP BY, next to a name
 * or a dot), a list (IN (#ids#)), arithmetic. Writing goes through the batches of the code search (NuCodeSearch), so a run can be undone.
 */
final class NuHashFix
{
	/** marker of the code search batches written here (column search) */
	public const MARK = '#…# → \'#…#\'';
	/** places of the code search whose code is scanned */
	public const PLACES = ['proc', 'hook', 'browsesql', 'objsql', 'object'];

	/**
	 * Spots of hash variables outside quotes: [pos (byte offset of the first #), len, var, why ('' = can be fixed), ins (quote to insert), line].
	 * Variables the server sets (NuCompat::SERVER_VARS, nu_…) are left out.
	 * @return list<array{pos: int, len: int, var: string, why: string, ins: string, line: int, note: string}>
	 */
	public static function spots(string $code, string $lang): array
	{
		$out = [];
		self::scan($code, $lang, null, "'", $out);
		usort($out, static fn($a, $b) => $a['pos'] <=> $b['pos']);
		foreach ($out as &$s) $s['line'] = substr_count($code, "\n", 0, $s['pos']) + 1;
		return $out;
	}

	private static function safeVar(string $n): bool
	{
		return in_array($n, NuCompat::SERVER_VARS, true) || str_starts_with($n, 'nu_');
	}

	/**
	 * Scans $code (lang php | sql). $map: offsets of $code in the stored text (code decoded from a PHP string), $ins: the quote to put
	 * around a variable there.
	 * @param array<int, int>|null $map
	 */
	private static function scan(string $code, string $lang, ?array $map, string $ins, array &$out): void
	{
		$n = strlen($code);
		$q = '';
		$from = 0;
		for ($i = 0; $i < $n; $i++) {
			$c = $code[$i];
			if ($q !== '') {
				if ($c === '\\' && $q !== '`') { $i++; continue; }
				if ($c === $q) {
					// PHP string with SQL in it: the PHP quotes do not protect the SQL – scan its text (escapes decoded) as SQL
					$inner = substr($code, $from + 1, $i - $from - 1);
					if ($lang === 'php' && $q !== '`' && preg_match('/\b(SELECT|UPDATE|DELETE|INSERT|WHERE|FROM)\b/i', $inner)) {
						[$dec, $dmap] = self::decode($inner, $q);
						$abs = [];
						foreach ($dmap as $k => $o) $abs[$k] = $from + 1 + $o;
						if ($map !== null) foreach ($abs as $k => $o) $abs[$k] = $map[$o];
						self::scan($dec, 'sql', $abs, $q === '"' ? "'" : "\\'", $out);
					}
					$q = '';
				}
				continue;
			}
			if ($c === '#' && preg_match('/\G#([A-Za-z_][A-Za-z0-9_]*)#/', $code, $m, 0, $i)) {
				$len = strlen($m[0]);
				if (!self::safeVar($m[1])) {
					$why = $lang === 'sql' ? self::sqlWhy($code, $i, $len) : self::phpWhy($code, $i, $len);
					// a calculation in SQL: a quoted value is read as a number there ('2025' - 1 = 2024 in MySQL and SQL Server) – fixable, with a note
					$note = $lang === 'sql' && $why === 'math' ? 'math' : '';
					if ($note !== '') $why = '';
					$out[] = ['pos' => $map !== null ? $map[$i] : $i, 'len' => $len, 'var' => $m[1], 'why' => $why, 'ins' => $ins, 'line' => 0, 'note' => $note];
				}
				$i += $len - 1;
				continue;
			}
			if ($c === "'" || $c === '"' || $c === '`') { $q = $c; $from = $i; continue; }
			$next = $i + 1 < $n ? $code[$i + 1] : '';
			if (($c === '/' && $next === '/' && $lang === 'php') || ($c === '-' && $next === '-' && $lang === 'sql') || $c === '#') {
				$e = strpos($code, "\n", $i);
				$i = $e === false ? $n : $e;
				continue;
			}
			if ($c === '/' && $next === '*') {
				$e = strpos($code, '*/', $i + 2);
				$i = $e === false ? $n : $e + 1;
			}
		}
	}

	/** The text of a PHP string as PHP reads it (\' or \" and \\), with the offset of each character in the stored text. @return array{0: string, 1: list<int>} */
	public static function decode(string $s, string $q): array
	{
		$out = '';
		$map = [];
		$n = strlen($s);
		for ($i = 0; $i < $n; $i++) {
			if ($s[$i] === '\\' && $i + 1 < $n && ($s[$i + 1] === $q || $s[$i + 1] === '\\')) {
				$out .= $s[$i + 1];
				$map[] = $i + 1;
				$i++;
				continue;
			}
			$out .= $s[$i];
			$map[] = $i;
		}
		return [$out, $map];
	}

	/** Why a variable in SQL must be fixed by a person ('' = quotes are safe). */
	public static function sqlWhy(string $s, int $p, int $len): string
	{
		$before = substr($s, 0, $p);
		$after = substr($s, $p + $len);
		if (preg_match('/[\w.]$/', $before) || preg_match('/^[\w.]/', $after)) return 'name';
		if (preg_match('/\b([A-Za-z_]+)\s*$/', $before, $w)) {
			$word = strtoupper($w[1]);
			if (in_array($word, ['LIMIT', 'OFFSET', 'TOP', 'FETCH', 'NEXT'], true)) return 'number';
			if (in_array($word, ['FROM', 'JOIN', 'INTO', 'UPDATE', 'TABLE', 'BY'], true)) return 'name';
		}
		$prev = substr(rtrim($before), -1);
		$next = substr(ltrim($after), 0, 1);
		if (($prev !== '' && strpbrk($prev, '+-*/%') !== false) || ($next !== '' && strpbrk($next, '+-*/%') !== false)) return 'math';
		if ($prev === '(' && $next === ')' && preg_match('/\bIN\s*\(\s*$/i', $before)) return 'list';
		if (preg_match_all('/\b(SELECT|WHERE|ON|HAVING|SET|VALUES|ORDER\s+BY|GROUP\s+BY|LIMIT|FROM|JOIN)\b/i', $before, $k)) {
			$last = strtoupper((string)preg_replace('/\s+/', ' ', end($k[1])));
			if (in_array($last, ['ORDER BY', 'GROUP BY', 'LIMIT'], true)) return 'order';
			if (in_array($last, ['FROM', 'JOIN'], true)) return 'name';
		}
		return '';
	}

	/** Why a variable in PHP code must be fixed by a person ('' = quotes are safe). */
	public static function phpWhy(string $s, int $p, int $len): string
	{
		$before = substr($s, 0, $p);
		$after = substr($s, $p + $len);
		if (preg_match('/[\w$]$/', $before) || preg_match('/^\w/', $after)) return 'name';
		$prev = substr(rtrim($before), -1);
		$next = substr(ltrim($after), 0, 1);
		if (($prev !== '' && strpbrk($prev, '+-*/%') !== false) || ($next !== '' && strpbrk($next, '+-*/%') !== false)) return 'math';
		return '';
	}

	/** $code with quotes around the spots at the given positions. */
	public static function applyTo(string $code, string $lang, array $positions): string
	{
		$want = array_fill_keys(array_map('intval', $positions), true);
		$spots = array_filter(self::spots($code, $lang), static fn($s) => $s['why'] === '' && isset($want[$s['pos']]));
		usort($spots, static fn($a, $b) => $b['pos'] <=> $a['pos']);
		foreach ($spots as $s) {
			$code = substr($code, 0, $s['pos']) . $s['ins'] . substr($code, $s['pos'], $s['len']) . $s['ins'] . substr($code, $s['pos'] + $s['len']);
		}
		return $code;
	}

	/** One line with the variable marked, before and after the fix (HTML). @return array{0: string, 1: string} */
	public static function lines(string $code, array $spot): array
	{
		$ls = strrpos(substr($code, 0, $spot['pos']), "\n");
		$ls = $ls === false ? 0 : $ls + 1;
		$le = strpos($code, "\n", $spot['pos']);
		$le = $le === false ? strlen($code) : $le;
		$a = substr($code, $ls, $spot['pos'] - $ls);
		$v = substr($code, $spot['pos'], $spot['len']);
		$z = substr($code, $spot['pos'] + $spot['len'], $le - $spot['pos'] - $spot['len']);
		$cut = static function (string $a, string $z): array {
			if (mb_strlen($a) > 80) $a = '… ' . mb_substr($a, -80);
			if (mb_strlen($z) > 80) $z = mb_substr($z, 0, 80) . ' …';
			return [$a, $z];
		};
		[$a, $z] = $cut(ltrim($a), rtrim($z));
		$before = h($a) . '<mark>' . h($v) . '</mark>' . h($z);
		$after = h($a) . '<mark class="nuHfNew">' . h($spot['ins'] . $v . $spot['ins']) . '</mark>' . h($z);
		return [$before, $after];
	}

	/**
	 * The parts of the application with hash variables outside quotes: code search sources (PHP / SQL) with their spots, the reason a part
	 * cannot be written here (open draft, no writable place), the hash of the code shown.
	 * @return list<array>
	 */
	public static function parts(): array
	{
		$drafts = NuCodeSearch::draftForms();
		$out = [];
		foreach (NuCodeSearch::sources(['drafts' => false, 'pages' => false]) as $src) {
			if (!in_array($src['lang'], ['php', 'sql'], true) || !empty($src['sys'])) continue;
			if (!in_array($src['place'], self::PLACES, true) && $src['place'] !== 'select') continue;
			$code = (string)$src['code'];
			if (!str_contains($code, '#')) continue;
			$spots = self::spots($code, $src['lang']);
			if (!$spots) continue;
			$block = $src['place'] === 'select' ? t('hqf_why_select') : NuCodeSearch::blockReason($src, $drafts);
			$out[] = $src + ['spots' => $spots, 'block' => $block, 'key' => empty($src['rw']) ? '' : NuCodeSearch::key($src['rw']), 'hash' => sha1($code)];
		}
		return $out;
	}

	/**
	 * Puts the ticked spots in quotes. $ticks: key => list of positions, $hashes: key => sha1 of the code shown. Forms get a history snapshot
	 * first; the run is a code search batch (undo there or on the fix page).
	 * @return array{ok: bool, error: string, parts: int, spots: int, skipped: list<string>, batch: string}
	 */
	public static function fix(array $ticks, array $hashes): array
	{
		$out = ['ok' => false, 'error' => '', 'parts' => 0, 'spots' => 0, 'skipped' => [], 'batch' => ''];
		if (!NuCodeSearch::canReplace()) { $out['error'] = t('err_forbidden'); return $out; }
		$ticks = array_filter(array_map(static fn($l) => array_values(array_filter(array_map('intval', (array)$l), static fn($n) => $n >= 0)), $ticks));
		if (!$ticks) { $out['error'] = t('cs_rp_none_ticked'); return $out; }
		$byKey = [];
		foreach (self::parts() as $p) if ($p['key'] !== '') $byKey[$p['key']] = $p;
		$db = nu_db();
		NuSchema::ensure();
		$plan = [];
		$forms = [];
		foreach ($ticks as $key => $pos) {
			$p = $byKey[(string)$key] ?? null;
			if (!$p) { $out['skipped'][] = (string)$key . ': ' . t('cs_rp_changed'); continue; }
			$name = $p['gtitle'] . ' · ' . $p['label'];
			if ($p['block'] !== '') { $out['skipped'][] = $name . ': ' . $p['block']; continue; }
			[$tb, $pk, $id, $col] = $p['rw'];
			$cur = $db->value('SELECT ' . NuDB::ident($col) . ' FROM ' . NuDB::ident($tb) . ' WHERE ' . NuDB::ident($pk) . ' = ?', [$id]);
			$cur = $cur === null ? '' : (string)$cur;
			if (!isset($hashes[$key]) || !hash_equals(sha1($cur), (string)$hashes[$key])) { $out['skipped'][] = $name . ': ' . t('cs_rp_changed'); continue; }
			$okPos = array_column(array_filter(self::spots($cur, $p['lang']), static fn($s) => $s['why'] === '' && in_array($s['pos'], $pos, true)), 'pos');
			if (!$okPos) continue;
			$new = self::applyTo($cur, $p['lang'], $okPos);
			if ($new === $cur) continue;
			if ($p['lang'] === 'php' && self::phpOk($cur) && !self::phpOk($new)) { $out['skipped'][] = $name . ': ' . t('cs_rp_php'); continue; }
			$plan[] = [$p, $cur, $new, count($okPos)];
			if (($p['form'] ?? '') !== '') $forms[(string)$p['form']] = true;
		}
		if (!$plan) { $out['ok'] = !$out['skipped']; if ($out['ok']) $out['error'] = t('cs_none'); return $out; }
		foreach (array_keys($forms) as $fid) NuFormHistory::snapshot((string)$fid, 'hash variables: quotes');
		$changes = [];
		$db->transaction(static function (NuDB $db) use ($plan, &$changes, &$out): void {
			foreach ($plan as [$p, $cur, $new, $n]) {
				[$tb, $pk, $id, $col] = $p['rw'];
				$db->run('UPDATE ' . NuDB::ident($tb) . ' SET ' . NuDB::ident($col) . ' = ? WHERE ' . NuDB::ident($pk) . ' = ?', [$new, $id]);
				$changes[] = ['rw' => $p['rw'], 'before' => $cur, 'after' => $new, 'label' => $p['gtitle'] . ' · ' . $p['label'], 'form' => (string)($p['form'] ?? ''), 'lines' => $n];
				$out['parts']++;
				$out['spots'] += $n;
			}
		});
		$out['batch'] = NuCodeSearch::saveBatch(self::MARK, '', $changes, $out['parts'], $out['spots']);
		NuLog::write('hash_quotes_fixed', 'compat', $out['batch'], ['parts' => $out['parts'], 'spots' => $out['spots'],
			'where' => array_slice(array_column($changes, 'label'), 0, 30)]);
		$out['ok'] = true;
		return $out;
	}

	private static function phpOk(string $code): bool
	{
		try { return token_get_all('<?php ' . $code, TOKEN_PARSE) !== []; } catch (Throwable) { return false; }
	}

	/** The runs of this page (latest first). @return list<array> */
	public static function batches(int $n = 10): array
	{
		return array_values(array_filter(NuCodeSearch::batches(50), static fn($b) => (string)$b['search'] === self::MARK));
	}
}
