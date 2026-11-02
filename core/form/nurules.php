<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * Validation rules of a form (0.8.812) – checked on the server before Before Save, and the same rules in the browser while typing
 * (assets/js/nu/nurules.js; the server stays the authority).
 *
 * Stored with the form: zzzzsys_form.sfo_json → "nunext": {"rules": [...]} (nuBuilder Forte ignores the "nunext" key) – so rules go
 * through drafts, the form history, the Form cloner and the App cloner with the form.
 *
 * rule  = {id, on, name, kind: error|warn, scope: "" (record) | <sub-form object id> (every row), when: cond|null, check: cond,
 *          msg, field (object marked), levels: [access ids] (only for), perm: permission item code (only for)}
 * cond  = {op: and|or, items: [cond | leaf], expr?: "text the user typed"}
 * leaf  = {f: field | "count:<sub-form id>" | "#HASH_NAME#" (0.8.815: a hash variable on the left), o: operator, v: value | [a, b] | {f: other field} | {h: hash variable} | "today"}
 * Hash variables (0.8.814): #NAME# – form properties / hash cookies, user, access level, record (NuHash::forForm, as in Before Save).
 * The rule fails when "when" holds (or is missing) and "check" does not: check = what must be true.
 */
final class NuFormRules
{
	public const OPS = ['eq', 'ne', 'contains', 'ncontains', 'starts', 'ends', 'empty', 'nempty', 'lt', 'gt', 'le', 'ge', 'between', 'in', 'nin', 'regex', 'before', 'after'];
	public const NO_VALUE = ['empty', 'nempty'];
	public const MAX_RULES = 100;
	private const MAX_DEPTH = 6;
	public const HASH_NAME = '/^[A-Za-z_][A-Za-z0-9_]{0,80}$/';
	/** The hash of the save / test (NuHash::forForm) – hash variables of rules and messages are read from it. */
	public static array $hash = [];

	// ================================================================= stored rules

	/** @return list<array> the rules of a form (as stored, cleaned). */
	public static function of(NuFormMeta $meta): array
	{
		$r = $meta->settings()['rules'] ?? [];
		return is_array($r) ? array_values(array_filter($r, 'is_array')) : [];
	}

	/** The rules that apply to the current user. */
	public static function forUser(NuFormMeta $meta): array
	{
		$u = NuAuth::user();
		$levels = $u ? array_map('strval', NuUserAccess::levels($u)) : [];
		$perms = null;
		$out = [];
		foreach (self::of($meta) as $r) {
			if (empty($r['on'])) continue;
			if (!empty($r['levels']) && !array_intersect(array_map('strval', (array)$r['levels']), $levels)) continue;
			if (trim((string)($r['perm'] ?? '')) !== '') {
				$perms ??= $u ? NuHash::permissions((string)$u['id']) : [];
				if (!in_array((string)$r['perm'], $perms, true)) continue;
			}
			$out[] = $r;
		}
		return $out;
	}

	/**
	 * Cleans rules from the editor: expressions are parsed, fields and operators checked.
	 * @return array{rules: list<array>, errors: list<string>}
	 */
	public static function clean(array $in, NuFormMeta $meta): array
	{
		$fields = self::fieldsOf($meta);
		$subs = self::subformsOf($meta);
		$out = $errors = [];
		foreach (array_slice(array_values(array_filter($in, 'is_array')), 0, self::MAX_RULES) as $i => $r) {
			$label = trim((string)($r['name'] ?? '')) ?: '#' . ($i + 1);
			$scope = trim((string)($r['scope'] ?? ''));
			if ($scope !== '' && !isset($subs[$scope])) { $errors[] = t('vr_err_scope', $label, $scope); $scope = ''; }
			$known = $scope !== '' ? $subs[$scope] : $fields + array_fill_keys(array_map(static fn($s) => 'count:' . $s, array_keys($subs)), 'count');
			$rule = ['id' => preg_match('/^[A-Za-z0-9_]{1,40}$/', (string)($r['id'] ?? '')) ? (string)$r['id'] : nu_id(), 'on' => !array_key_exists('on', $r) || !empty($r['on']),
				'name' => mb_substr(trim((string)($r['name'] ?? '')), 0, 100), 'kind' => ($r['kind'] ?? '') === 'warn' ? 'warn' : 'error', 'scope' => $scope,
				'msg' => mb_substr(trim((string)($r['msg'] ?? '')), 0, 500), 'field' => '', 'levels' => [], 'perm' => mb_substr(trim((string)($r['perm'] ?? '')), 0, 100)];
			if ($rule['msg'] === '') $errors[] = t('vr_err_msg', $label);
			foreach (['when', 'check'] as $k) {
				$c = $r[$k] ?? null;
				if ($c === null || $c === '' || (is_array($c) && !$c)) { if ($k === 'check') $errors[] = t('vr_err_check', $label); continue; }
				if (is_string($c)) $c = ['expr' => $c];
				$tree = null;
				if (is_array($c) && trim((string)($c['expr'] ?? '')) !== '') {
					$p = self::parse((string)$c['expr']);
					if (is_string($p)) { $errors[] = $label . ': ' . $p; continue; }
					$tree = $p + ['expr' => trim((string)$c['expr'])];
				} elseif (is_array($c) && isset($c['items'])) $tree = $c;
				if ($tree === null) { if ($k === 'check') $errors[] = t('vr_err_check', $label); continue; }
				$err = self::checkTree($tree, $known, 0);
				if ($err !== null) { $errors[] = $label . ': ' . $err; continue; }
				if (!$tree['items'] && $k === 'when') continue;
				$rule[$k] = $tree;
			}
			$mark = trim((string)($r['field'] ?? ''));
			if ($mark !== '' && isset($known[$mark]) && !str_starts_with($mark, 'count:')) $rule['field'] = $mark;
			foreach (is_array($r['levels'] ?? null) ? $r['levels'] : [] as $lv) if (preg_match('/^[\w\-]{1,40}$/', (string)$lv)) $rule['levels'][] = (string)$lv;
			if (isset($rule['check'])) $out[] = $rule;
		}
		return ['rules' => $out, 'errors' => $errors];
	}

	/** Data fields of a form: object id => input kind (text, number, date, checkbox, select). */
	public static function fieldsOf(NuFormMeta $meta): array
	{
		$out = [];
		foreach ($meta->objects as $o) {
			$t = (string)$o['sob_all_type'];
			if (!in_array($t, ['input', 'textarea', 'select', 'lookup', 'calc', 'display'], true) || ($o['sob_input_type'] ?? '') === 'button') continue;
			$it = (string)($o['sob_input_type'] ?? '');
			$out[(string)$o['sob_all_id']] = match (true) {
				$it === 'checkbox' => 'checkbox',
				in_array($it, ['nuNumber', 'number', 'range', 'nuAutoNumber'], true) || $t === 'calc' => 'number',
				in_array($it, ['nuDate', 'date', 'datetime-local'], true) => 'date',
				$t === 'select' => 'select',
				default => 'text',
			};
		}
		return $out;
	}

	/** Sub-forms of a form: object id => its fields (fieldsOf the sub-form). */
	public static function subformsOf(NuFormMeta $meta): array
	{
		$out = [];
		foreach ($meta->objects as $o) {
			if ((string)$o['sob_all_type'] !== 'subform') continue;
			$sub = NuFormMeta::load((string)$o['sob_subform_zzzzsys_form_id']);
			if ($sub) $out[(string)$o['sob_all_id']] = self::fieldsOf($sub);
		}
		return $out;
	}

	private static function checkTree(array $c, array $known, int $depth): ?string
	{
		if ($depth > self::MAX_DEPTH) return t('vr_err_deep');
		if (!in_array($c['op'] ?? 'and', ['and', 'or'], true) || !is_array($c['items'] ?? null)) return t('vr_err_syntax', '?');
		foreach ($c['items'] as $it) {
			if (!is_array($it)) return t('vr_err_syntax', '?');
			if (isset($it['items'])) { if (($e = self::checkTree($it, $known, $depth + 1)) !== null) return $e; continue; }
			$f = (string)($it['f'] ?? '');
			$o = (string)($it['o'] ?? '');
			if (!isset($known[$f]) && !preg_match('/^#[A-Za-z_][A-Za-z0-9_]{0,80}#$/', $f)) return t('vr_err_field', $f);
			if (!in_array($o, self::OPS, true)) return t('vr_err_op', $o);
			$v = $it['v'] ?? null;
			if (is_array($v) && isset($v['f']) && !isset($known[(string)$v['f']])) return t('vr_err_field', (string)$v['f']);
			foreach (is_array($v) && !isset($v['f']) && !isset($v['h']) ? $v : [$v] as $x) if (is_array($x) && isset($x['h']) && !preg_match(self::HASH_NAME, (string)$x['h'])) return t('vr_err_syntax', '#' . (string)$x['h'] . '#');
			if ($o === 'between' && (!is_array($v) || count($v) !== 2 || isset($v['f']) || isset($v['h']))) return t('vr_err_between');
			if ($o === 'regex' && @preg_match(self::regex((string)$v), '') === false) return t('vr_err_regex', (string)$v);
		}
		return null;
	}

	// ================================================================= expressions

	/**
	 * Parses "status = 'shipped' AND (ship_date IS EMPTY OR qty <= 0)" into a cond tree – or returns the error text.
	 * Operators: = != <> < > <= >=, CONTAINS, NOT CONTAINS, STARTS WITH, ENDS WITH, IS EMPTY, IS NOT EMPTY, IN (…), NOT IN (…),
	 * BETWEEN a AND b, MATCHES 'regex', BEFORE, AFTER; values: 'text', numbers, TODAY, #HASH_VARIABLE#, another field's name; COUNT(sub-form) on the left.
	 */
	public static function parse(string $expr): array|string
	{
		$tok = self::tokens($expr);
		if (is_string($tok)) return $tok;
		if (!$tok) return t('vr_err_syntax', '');
		$pos = 0;
		try {
			$tree = self::pOr($tok, $pos);
			if ($pos < count($tok)) throw new RuntimeException(t('vr_err_syntax', $tok[$pos][1]));
		} catch (RuntimeException $e) {
			return $e->getMessage();
		}
		return isset($tree['items']) ? $tree : ['op' => 'and', 'items' => [$tree]];
	}

	private static function tokens(string $s): array|string
	{
		$out = [];
		$n = strlen($s);
		for ($i = 0; $i < $n;) {
			$c = $s[$i];
			if (ctype_space($c)) { $i++; continue; }
			if ($c === "'" || $c === '"') {
				$j = $i + 1; $v = '';
				while ($j < $n && $s[$j] !== $c) { if ($s[$j] === '\\' && $j + 1 < $n && ($s[$j + 1] === $c || $s[$j + 1] === '\\')) $j++; $v .= $s[$j]; $j++; }   // \' and \\ only: regex escapes stay
				if ($j >= $n) return t('vr_err_quote');
				$out[] = ['s', $v]; $i = $j + 1; continue;
			}
			if (preg_match('/\G#([A-Za-z_][A-Za-z0-9_]{0,80})#/', $s, $m, 0, $i)) { $out[] = ['h', $m[1]]; $i += strlen($m[0]); continue; }   // hash variable
			if (preg_match('/\G-?\d+(\.\d+)?/', $s, $m, 0, $i)) { $out[] = ['n', $m[0]]; $i += strlen($m[0]); continue; }
			if (preg_match('/\G(<=|>=|<>|!=|=|<|>|\(|\)|,)/', $s, $m, 0, $i)) { $out[] = ['p', $m[0]]; $i += strlen($m[0]); continue; }
			if (preg_match('/\G[A-Za-z_][A-Za-z0-9_]*/', $s, $m, 0, $i)) { $out[] = ['w', $m[0]]; $i += strlen($m[0]); continue; }
			return t('vr_err_syntax', $c);
		}
		return $out;
	}

	private static function kw(array $t, int $p, string ...$words): bool
	{
		foreach ($words as $k => $w) if (($t[$p + $k][0] ?? '') !== 'w' || strtoupper((string)$t[$p + $k][1]) !== $w) return false;
		return true;
	}

	private static function pOr(array $t, int &$p): array
	{
		$items = [self::pAnd($t, $p)];
		while (self::kw($t, $p, 'OR')) { $p++; $items[] = self::pAnd($t, $p); }
		return count($items) === 1 ? $items[0] : ['op' => 'or', 'items' => $items];
	}

	private static function pAnd(array $t, int &$p): array
	{
		$items = [self::pAtom($t, $p)];
		while (self::kw($t, $p, 'AND')) { $p++; $items[] = self::pAtom($t, $p); }
		return count($items) === 1 ? $items[0] : ['op' => 'and', 'items' => $items];
	}

	private static function pAtom(array $t, int &$p): array
	{
		if (($t[$p][1] ?? '') === '(' && ($t[$p][0] ?? '') === 'p') {
			$p++;
			$x = self::pOr($t, $p);
			if (($t[$p][1] ?? '') !== ')') throw new RuntimeException(t('vr_err_paren'));
			$p++;
			return isset($x['items']) ? $x : ['op' => 'and', 'items' => [$x]];
		}
		// left side: a field, COUNT(sub-form) or a hash variable #NAME# (0.8.815)
		if (($t[$p][0] ?? '') === 'h') $t[$p] = ['w', '#' . $t[$p][1] . '#'];
		if (($t[$p][0] ?? '') !== 'w') throw new RuntimeException(t('vr_err_syntax', (string)($t[$p][1] ?? '')));
		$f = (string)$t[$p][1];
		$p++;
		if (strtoupper($f) === 'COUNT' && ($t[$p][1] ?? '') === '(') {
			if (($t[$p + 1][0] ?? '') !== 'w' || ($t[$p + 2][1] ?? '') !== ')') throw new RuntimeException(t('vr_err_syntax', 'COUNT'));
			$f = 'count:' . $t[$p + 1][1];
			$p += 3;
		}
		$sym = ['=' => 'eq', '!=' => 'ne', '<>' => 'ne', '<' => 'lt', '>' => 'gt', '<=' => 'le', '>=' => 'ge'];
		$o = null;
		if (($t[$p][0] ?? '') === 'p' && isset($sym[$t[$p][1]])) { $o = $sym[$t[$p][1]]; $p++; }
		elseif (self::kw($t, $p, 'IS', 'NOT', 'EMPTY')) { $p += 3; return ['f' => $f, 'o' => 'nempty']; }
		elseif (self::kw($t, $p, 'IS', 'EMPTY')) { $p += 2; return ['f' => $f, 'o' => 'empty']; }
		elseif (self::kw($t, $p, 'NOT', 'CONTAINS')) { $p += 2; $o = 'ncontains'; }
		elseif (self::kw($t, $p, 'CONTAINS')) { $p++; $o = 'contains'; }
		elseif (self::kw($t, $p, 'STARTS', 'WITH')) { $p += 2; $o = 'starts'; }
		elseif (self::kw($t, $p, 'ENDS', 'WITH')) { $p += 2; $o = 'ends'; }
		elseif (self::kw($t, $p, 'MATCHES')) { $p++; $o = 'regex'; }
		elseif (self::kw($t, $p, 'BEFORE')) { $p++; $o = 'before'; }
		elseif (self::kw($t, $p, 'AFTER')) { $p++; $o = 'after'; }
		elseif (self::kw($t, $p, 'NOT', 'IN') || self::kw($t, $p, 'IN')) {
			$neg = self::kw($t, $p, 'NOT');
			$p += $neg ? 2 : 1;
			if (($t[$p][1] ?? '') !== '(') throw new RuntimeException(t('vr_err_syntax', 'IN'));
			$p++;
			$list = [];
			while (true) {
				$list[] = self::pValue($t, $p, false);
				if (($t[$p][1] ?? '') === ',') { $p++; continue; }
				if (($t[$p][1] ?? '') === ')') { $p++; break; }
				throw new RuntimeException(t('vr_err_paren'));
			}
			return ['f' => $f, 'o' => $neg ? 'nin' : 'in', 'v' => $list];
		} elseif (self::kw($t, $p, 'BETWEEN')) {
			$p++;
			$a = self::pValue($t, $p, false);
			if (!self::kw($t, $p, 'AND')) throw new RuntimeException(t('vr_err_between'));
			$p++;
			return ['f' => $f, 'o' => 'between', 'v' => [$a, self::pValue($t, $p, false)]];
		}
		if ($o === null) throw new RuntimeException(t('vr_err_syntax', (string)($t[$p][1] ?? $f)));
		return ['f' => $f, 'o' => $o, 'v' => self::pValue($t, $p, true)];
	}

	private static function pValue(array $t, int &$p, bool $fieldOk): mixed
	{
		$x = $t[$p] ?? null;
		if ($x === null) throw new RuntimeException(t('vr_err_syntax', ''));
		$p++;
		if ($x[0] === 's' || $x[0] === 'n') return (string)$x[1];
		if ($x[0] === 'h') return ['h' => (string)$x[1]];
		if ($x[0] === 'w') {
			if (strtoupper((string)$x[1]) === 'TODAY') return 'today';
			if ($fieldOk) return ['f' => (string)$x[1]];
		}
		throw new RuntimeException(t('vr_err_syntax', (string)$x[1]));
	}

	/** A cond tree as text (the rules list shows it). */
	public static function text(?array $c): string
	{
		if (!$c) return '';
		if (trim((string)($c['expr'] ?? '')) !== '') return (string)$c['expr'];
		$sym = ['eq' => '=', 'ne' => '!=', 'lt' => '<', 'gt' => '>', 'le' => '<=', 'ge' => '>=', 'contains' => 'CONTAINS', 'ncontains' => 'NOT CONTAINS', 'starts' => 'STARTS WITH',
			'ends' => 'ENDS WITH', 'regex' => 'MATCHES', 'before' => 'BEFORE', 'after' => 'AFTER'];
		$val = static fn($v) => is_array($v) && isset($v['h']) ? '#' . (string)$v['h'] . '#' : (is_array($v) && isset($v['f']) ? (string)$v['f'] : ($v === 'today' ? 'TODAY' : (is_numeric($v) ? (string)$v : "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], (string)$v) . "'")));
		$parts = [];
		foreach ($c['items'] ?? [] as $it) {
			if (isset($it['items'])) { $parts[] = '(' . self::text($it) . ')'; continue; }
			$f = str_starts_with((string)$it['f'], 'count:') ? 'COUNT(' . substr((string)$it['f'], 6) . ')' : (string)$it['f'];
			$o = (string)$it['o'];
			$v = $it['v'] ?? null;
			$parts[] = match ($o) {
				'empty' => "$f IS EMPTY", 'nempty' => "$f IS NOT EMPTY",
				'between' => "$f BETWEEN " . $val($v[0] ?? '') . ' AND ' . $val($v[1] ?? ''),
				'in', 'nin' => $f . ($o === 'nin' ? ' NOT IN (' : ' IN (') . implode(', ', array_map($val, (array)$v)) . ')',
				default => "$f " . ($sym[$o] ?? $o) . ' ' . $val($v),
			};
		}
		return implode(strtoupper(' ' . ($c['op'] ?? 'and') . ' '), $parts);
	}

	// ================================================================= checking

	private static function regex(string $pattern): string
	{
		return '/' . str_replace('/', '\/', $pattern) . '/iu';
	}

	/** Is the cond true for these values? $vals: field => value, $kinds: field => kind, $counts: sub-form id => rows. */
	public static function holds(array $c, array $vals, array $kinds, array $counts = []): bool
	{
		$or = ($c['op'] ?? 'and') === 'or';
		$items = $c['items'] ?? [];
		if (!$items) return true;
		foreach ($items as $it) {
			$r = isset($it['items']) ? self::holds($it, $vals, $kinds, $counts) : self::leaf($it, $vals, $kinds, $counts);
			if ($or && $r) return true;
			if (!$or && !$r) return false;
		}
		return !$or;
	}

	private static function leaf(array $it, array $vals, array $kinds, array $counts): bool
	{
		$f = (string)$it['f'];
		$isCount = str_starts_with($f, 'count:');
		$isHash = !$isCount && strlen($f) > 2 && $f[0] === '#' && str_ends_with($f, '#');
		$a = $isCount ? (string)($counts[substr($f, 6)] ?? 0) : ($isHash ? trim((string)(self::hashValue(substr($f, 1, -1)) ?? ''))
			: trim((string)(is_array($vals[$f] ?? null) ? implode(',', $vals[$f]) : ($vals[$f] ?? ''))));
		$kind = $isCount ? 'number' : ($kinds[$f] ?? 'text');
		$isEmpty = $a === '' || ($kind === 'checkbox' && $a === '0');
		$o = (string)$it['o'];
		if ($o === 'empty') return $isEmpty;
		if ($o === 'nempty') return !$isEmpty;
		$val = static function ($v) use ($vals): string {
			if (is_array($v) && isset($v['f'])) return trim((string)($vals[(string)$v['f']] ?? ''));
			if (is_array($v) && isset($v['h'])) return trim((string)(self::hashValue((string)$v['h']) ?? ''));
			if ($v === 'today') return date('Y-m-d');
			return trim((string)$v);
		};
		$cmp = static function (string $x, string $y) use ($kind): int {
			if (is_numeric($x) && is_numeric($y)) return (float)$x <=> (float)$y;
			if ($kind === 'date' || preg_match('/^\d{4}-\d{2}-\d{2}/', $x)) return strcmp(substr($x, 0, strlen($y) === 10 ? 10 : 19), $y);
			return strcmp(mb_strtolower($x), mb_strtolower($y));
		};
		$v = $it['v'] ?? '';
		switch ($o) {
			case 'eq': return $cmp($a, $val($v)) === 0;
			case 'ne': return $cmp($a, $val($v)) !== 0;
			case 'lt': case 'before': return $a !== '' && $cmp($a, $val($v)) < 0;
			case 'gt': case 'after': return $a !== '' && $cmp($a, $val($v)) > 0;
			case 'le': return $a !== '' && $cmp($a, $val($v)) <= 0;
			case 'ge': return $a !== '' && $cmp($a, $val($v)) >= 0;
			case 'between': return $a !== '' && $cmp($a, $val($v[0] ?? '')) >= 0 && $cmp($a, $val($v[1] ?? '')) <= 0;
			case 'contains': return $val($v) === '' || mb_stripos($a, $val($v)) !== false;
			case 'ncontains': return $val($v) === '' || mb_stripos($a, $val($v)) === false;
			case 'starts': return mb_stripos($a, $val($v)) === 0;
			case 'ends': $b = $val($v); return $b === '' || mb_strtolower(mb_substr($a, -mb_strlen($b))) === mb_strtolower($b);
			case 'in': case 'nin':
				$hit = false;
				foreach ((array)$v as $x) if ($cmp($a, $val($x)) === 0) { $hit = true; break; }
				return $o === 'in' ? $hit : !$hit;
			case 'regex': return @preg_match(self::regex((string)$v), $a) === 1;
		}
		return true;
	}

	/** Message of a failed rule: translated, #field# replaced by its value. */
	private static function message(array $r, array $vals): string
	{
		$m = NuTranslate::text((string)$r['msg']);
		return (string)preg_replace_callback('/#([A-Za-z_][A-Za-z0-9_]*)#/', static function ($x) use ($vals) {
			if (array_key_exists($x[1], $vals)) return (string)(is_array($vals[$x[1]]) ? implode(', ', $vals[$x[1]]) : $vals[$x[1]]);   // a field first
			$h = self::hashValue($x[1]);
			return $h !== null ? $h : $x[0];
		}, $m);
	}

	/** A hash variable of self::$hash (exact name, else ignoring case) – null when unknown. */
	public static function hashValue(string $name): ?string
	{
		$v = self::$hash[$name] ?? null;
		if ($v === null) foreach (self::$hash as $k => $x) if (is_string($k) && strcasecmp($k, $name) === 0) { $v = $x; break; }
		if ($v === null) return null;
		return is_scalar($v) ? (string)$v : (string)json_encode($v, JSON_UNESCAPED_UNICODE);
	}

	/** Names of the hash variables the rules use (values and messages). @return list<string> */
	public static function hashNames(array $rules): array
	{
		$out = [];
		$walk = static function ($c) use (&$walk, &$out): void {
			if (!is_array($c)) return;
			if (isset($c['h']) && is_string($c['h'])) { $out[$c['h']] = true; return; }
			if (isset($c['f']) && is_string($c['f']) && preg_match('/^#([A-Za-z_][A-Za-z0-9_]*)#$/', $c['f'], $hm)) $out[$hm[1]] = true;
			foreach ($c as $x) $walk($x);
		};
		foreach ($rules as $r) {
			$walk($r['when'] ?? null); $walk($r['check'] ?? null);
			if (preg_match_all('/#([A-Za-z_][A-Za-z0-9_]*)#/', (string)($r['msg'] ?? ''), $m)) foreach ($m[1] as $n) $out[$n] = true;
		}
		return array_keys($out);
	}

	/**
	 * The rules of the current user for a save (NuRecord::save): $vals = the record's values (stored values + posted ones),
	 * $posted = the sub-forms of the save payload. $accepted = warning rule ids the user confirmed.
	 * @return array{errors: list<array{message: string, field: string}>, warnings: list<array{id: string, message: string, field: string}>}
	 */
	public static function run(NuFormMeta $meta, array $vals, array $posted, array $accepted = [], ?array $hash = null): array
	{
		if ($hash !== null) self::$hash = $hash;
		$rules = self::forUser($meta);
		$out = ['errors' => [], 'warnings' => []];
		if (!$rules) return $out;
		$kinds = self::fieldsOf($meta);
		$subKinds = self::subformsOf($meta);
		$rowsOf = static function (string $sf) use ($posted): array {
			$rows = [];
			foreach (is_array($posted[$sf]['rows'] ?? null) ? $posted[$sf]['rows'] : [] as $r) if (is_array($r) && empty($r['deleted'])) $rows[] = is_array($r['values'] ?? null) ? $r['values'] : [];
			return $rows;
		};
		$counts = [];
		foreach (array_keys($subKinds) as $sf) $counts[$sf] = count($rowsOf((string)$sf));
		$accepted = array_flip(array_map('strval', $accepted));
		foreach ($rules as $r) {
			$sets = $r['scope'] !== '' ? $rowsOf((string)$r['scope']) : [$vals];
			$kk = $r['scope'] !== '' ? ($subKinds[$r['scope']] ?? []) : $kinds;
			foreach ($sets as $n => $v) {
				if (!empty($r['when']) && !self::holds($r['when'], $v, $kk, $counts)) continue;
				if (self::holds($r['check'], $v, $kk, $counts)) continue;
				$msg = self::message($r, $v + ($r['scope'] !== '' ? $vals : []));
				if ($r['scope'] !== '') $msg = t('vr_row', $n + 1) . ' ' . $msg;
				if ($r['kind'] === 'warn') { if (!isset($accepted[$r['id']])) $out['warnings'][] = ['id' => $r['id'], 'message' => $msg, 'field' => $r['scope'] === '' ? $r['field'] : '']; }
				else $out['errors'][] = ['message' => $msg, 'field' => $r['scope'] === '' ? $r['field'] : ''];
				break;                                              // one message per rule
			}
		}
		$out['warnings'] = array_values(array_unique($out['warnings'], SORT_REGULAR));
		return $out;
	}

	/** Test in the editor: the given rules against a saved record (and its sub-form rows) – every rule, its result. */
	public static function test(NuFormMeta $meta, array $rules, string $recordId): array
	{
		$row = NuRecord::load($meta, $recordId);
		if (!$row) return ['ok' => false, 'error' => t('record_not_found')];
		self::$hash = NuHash::forForm($meta, $recordId, $row);
		$vals = [];
		foreach (self::fieldsOf($meta) as $f => $k) { $c = NuRecord::column($row, $f); $vals[$f] = $c !== null ? (string)($row[$c] ?? '') : ''; }
		$posted = [];
		foreach ($meta->objects as $o) {
			if ((string)$o['sob_all_type'] !== 'subform') continue;
			$sub = NuFormMeta::load((string)$o['sob_subform_zzzzsys_form_id']);
			if (!$sub) continue;
			$rows = [];
			foreach (array_slice(NuRecord::subformRows($o, $recordId), 0, 500) as $sr) {
				$v = [];
				foreach (self::fieldsOf($sub) as $f => $k) { $c = NuRecord::column($sr, $f); $v[$f] = $c !== null ? (string)($sr[$c] ?? '') : ''; }
				$rows[] = ['values' => $v];
			}
			$posted[(string)$o['sob_all_id']] = ['rows' => $rows];
		}
		$res = [];
		foreach ($rules as $r) {
			$one = self::runOne($meta, $r, $vals, $posted);
			$res[] = ['id' => (string)$r['id'], 'name' => (string)$r['name'], 'ok' => $one === null, 'message' => $one ?? ''];
		}
		return ['ok' => true, 'results' => $res];
	}

	private static function runOne(NuFormMeta $meta, array $r, array $vals, array $posted): ?string
	{
		$kinds = self::fieldsOf($meta);
		$subKinds = self::subformsOf($meta);
		$counts = [];
		foreach ($posted as $sf => $p) $counts[$sf] = count($p['rows']);
		$sets = $r['scope'] !== '' ? array_map(static fn($x) => $x['values'], $posted[$r['scope']]['rows'] ?? []) : [$vals];
		foreach ($sets as $n => $v) {
			$kk = $r['scope'] !== '' ? ($subKinds[$r['scope']] ?? []) : $kinds;
			if (!empty($r['when']) && !self::holds($r['when'], $v, $kk, $counts)) continue;
			if (!self::holds($r['check'], $v, $kk, $counts)) return ($r['scope'] !== '' ? t('vr_row', $n + 1) . ' ' : '') . self::message($r, $v);
		}
		return null;
	}

	/** Design mode (nuNext.design.rules): the rules, the fields with labels, the sub-forms, access levels and permission items to choose from. */
	public static function designData(NuFormMeta $meta): array
	{
		$label = static function (NuFormMeta $m): array {
			$l = [];
			foreach ($m->objects as $o) $l[(string)$o['sob_all_id']] = trim(strip_tags((string)($o['sob_all_label'] ?? '')));
			return $l;
		};
		$list = static function (NuFormMeta $m) use ($label): array {
			$l = $label($m);
			$out = [];
			foreach (self::fieldsOf($m) as $f => $k) $out[] = [$f, $l[$f] ?? '', $k];
			return $out;
		};
		$subs = [];
		$lm = $label($meta);
		foreach ($meta->objects as $o) {
			if ((string)$o['sob_all_type'] !== 'subform') continue;
			$sub = NuFormMeta::load((string)$o['sob_subform_zzzzsys_form_id']);
			if ($sub) $subs[(string)$o['sob_all_id']] = ['label' => $lm[(string)$o['sob_all_id']] ?? '', 'fields' => $list($sub)];
		}
		$db = nu_db();
		$levels = array_map(static fn($r) => [(string)$r['zzzzsys_access_id'], (string)$r['sal_code'] . ($r['sal_description'] ? ' – ' . $r['sal_description'] : '')],
			$db->all('SELECT zzzzsys_access_id, sal_code, sal_description FROM zzzzsys_access ORDER BY sal_code'));
		$perms = $db->tableExists('zzzzsys_permission_item') ? array_map(static fn($r) => [(string)$r['pme_ident'], (string)$r['pme_ident'] . ($r['pme_description'] ? ' – ' . $r['pme_description'] : '')],
			$db->all('SELECT pme_ident, pme_description FROM zzzzsys_permission_item ORDER BY pme_ident')) : [];
		return ['list' => self::of($meta), 'fields' => $list($meta), 'subs' => (object)$subs, 'levels' => $levels, 'perms' => $perms, 'ops' => self::OPS];
	}

	/** For the form page (nuNext.rules): the user's rules, the field kinds, the texts – assets/js/nu/nurules.js. */
	public static function client(NuFormMeta $meta, array $hash = []): ?array
	{
		self::$hash = $hash;
		$rules = self::forUser($meta);
		if (!$rules) return null;
		foreach ($rules as &$r) $r['msg'] = NuTranslate::text((string)$r['msg']);
		unset($r);
		// hash variables the rules use: their values for the browser (only these names; field names are not hash variables here)
		$hv = [];
		$fieldNames = array_change_key_case(self::fieldsOf($meta));
		foreach (self::hashNames($rules) as $n) if (!isset($fieldNames[strtolower($n)]) && ($v = self::hashValue($n)) !== null) $hv[$n] = $v;
		return ['rules' => $rules, 'hash' => (object)$hv, 'kinds' => self::fieldsOf($meta), 'sub' => self::subformsOf($meta), 'today' => date('Y-m-d'),
			'texts' => ['row' => t('vr_row'), 'warn_title' => t('vr_warn_title'), 'warn_ok' => t('vr_warn_ok'), 'warn_intro' => t('vr_warn_intro')]];
	}
}
