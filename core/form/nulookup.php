<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * Lookup objects: the stored value is the lookup form's primary key; the user sees sob_lookup_code and
 * sob_lookup_description. Records come from the lookup form's browse SQL (or sob_lookup_table).
 */
final class NuLookup
{
	public static function form(array $o): ?NuFormMeta
	{
		return NuFormMeta::load((string)$o['sob_lookup_zzzzsys_form_id']);
	}

	/** [inner sql, pk column] */
	private static function source(array $o, array $hash): ?array
	{
		$lf = self::form($o);
		if (!$lf) return null;
		$sql = trim((string)$lf->form['sfo_browse_sql']);
		if ($sql === '') {
			$t = trim((string)$o['sob_lookup_table']) ?: $lf->table();
			if ($t === '') return null;
			$sql = 'SELECT * FROM ' . NuDB::ident($t);
		}
		[$inner] = NuBrowse::splitOrder(NuHash::replace($sql, NuHash::forForm($lf, '', [], $hash) + $hash, NuHash::SQL));
		return [$inner, NuDB::ident($lf->primaryKey())];
	}

	private static function expr(string $col): string
	{
		$col = trim($col);
		if ($col === '') return "''";
		return preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $col) ? "nuT.$col" : $col;
	}

	/** @return array{pk:string,code:string,description:string,row:array}|null */
	public static function byId(array $o, string $pk, array $hash): ?array
	{
		$src = self::source($o, $hash);
		if (!$src || $pk === '') return null;
		[$inner, $pkCol] = $src;
		try {
			$row = nu_db()->one("SELECT nuT.*, " . self::expr((string)$o['sob_lookup_code']) . " AS nu_code, " . self::expr((string)$o['sob_lookup_description'])
				. " AS nu_description FROM ($inner) nuT WHERE nuT.$pkCol = ?", [$pk]);
		} catch (Throwable $e) {
			error_log('nuBuilder Next lookup ' . $o['sob_all_id'] . ': ' . $e->getMessage());
			return null;
		}
		return $row ? self::shape($row, $pkCol) : null;
	}

	/** Records whose code equals $code (exact, then prefix) – at most 2, to tell "unique" from "ambiguous". */
	public static function byCode(array $o, string $code, array $hash): array
	{
		$src = self::source($o, $hash);
		if (!$src || trim($code) === '') return [];
		[$inner, $pkCol] = $src;
		$codeExpr = self::expr((string)$o['sob_lookup_code']);
		$sel = "SELECT nuT.*, $codeExpr AS nu_code, " . self::expr((string)$o['sob_lookup_description']) . " AS nu_description FROM ($inner) nuT";
		$db  = nu_db();
		$rows = $db->page("$sel WHERE $codeExpr = ?", [$code], "nuT.$pkCol", 2, 0);
		if (!$rows) $rows = $db->page("$sel WHERE $codeExpr LIKE ? ESCAPE '!'", [substr(NuDB::like($code), 1)], "nuT.$pkCol", 2, 0);
		return array_map(static fn($r) => self::shape($r, $pkCol), $rows);
	}

	private static function shape(array $row, string $pkCol): array
	{
		$pk = (string)($row[$pkCol] ?? '');
		$code = (string)($row['nu_code'] ?? '');
		$desc = (string)($row['nu_description'] ?? '');
		unset($row['nu_code'], $row['nu_description']);
		return ['pk' => $pk, 'code' => $code, 'description' => $desc, 'row' => $row];
	}

	/** Runs the lookup's After Browse code (<object id>_AB) with nuLookupRecord() = the chosen row. */
	public static function afterBrowse(array $o, array $record, array $hash): array
	{
		NuPhp::reset();
		NuPhp::$lookupRow = (object)$record['row'];
		// through NuFormMeta: in a draft preview the draft's code (0.8.386)
		$code = NuFormMeta::load((string)$o['sob_all_zzzzsys_form_id'])?->objectPhp((string)$o['zzzzsys_object_id'], 'AB');
		NuPhp::run($code, $hash, 'Lookup ' . $o['sob_all_id']);
		return NuPhp::result();
	}
}
