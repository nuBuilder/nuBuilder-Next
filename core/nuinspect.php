<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * Inspect record (globeadmin, form options menu): every column of the record that is open in an edit form, with data type,
 * keys and the stored value – as nuBuilder's Tools → "Inspect Record". Column details for MySQL / MariaDB, MSSQL and SQLite.
 */
final class NuInspect
{
	/**
	 * Columns of $table in table order: [name, type (full, e.g. varchar(25)), nullable, default, comment, pk, unique, index,
	 * fk (referenced "table.column" from a real foreign key, or ''), binary].
	 */
	public static function columns(NuDB $db, string $table): array
	{
		NuDB::ident($table);
		$cols = [];
		$add = static function (string $n, string $type, bool $null, ?string $def, string $comment) use (&$cols): void {
			$cols[strtolower($n)] = ['name' => $n, 'type' => $type, 'nullable' => $null, 'default' => $def, 'comment' => $comment,
				'pk' => false, 'unique' => false, 'index' => false, 'fk' => '', 'binary' => (bool)preg_match('/blob|binary|image|bytea/i', $type)];
		};
		$mark = static function (string $n, string $what, string $val = '1') use (&$cols): void {
			$k = strtolower($n);
			if (isset($cols[$k])) $cols[$k][$what] = $what === 'fk' ? $val : true;
		};

		if ($db->type === 'mysql') {
			foreach ($db->all("SELECT COLUMN_NAME n, COLUMN_TYPE t, IS_NULLABLE nl, COLUMN_DEFAULT d, COLUMN_COMMENT cm, COLUMN_KEY k FROM information_schema.COLUMNS
				WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION", [$table]) as $r) {
				$add((string)$r['n'], (string)$r['t'], $r['nl'] === 'YES', $r['d'] === null ? null : (string)$r['d'], (string)$r['cm']);
				$k = (string)$r['k'];
				if ($k === 'PRI') $mark((string)$r['n'], 'pk');
				if ($k === 'UNI') $mark((string)$r['n'], 'unique');
				if ($k === 'MUL') $mark((string)$r['n'], 'index');
			}
			foreach ($db->all('SELECT COLUMN_NAME n, REFERENCED_TABLE_NAME rt, REFERENCED_COLUMN_NAME rc FROM information_schema.KEY_COLUMN_USAGE
				WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL', [$table]) as $r) {
				$mark((string)$r['n'], 'fk', $r['rt'] . '.' . $r['rc']);
			}
		} elseif ($db->type === 'sqlsrv') {
			foreach ($db->all("SELECT c.COLUMN_NAME n, c.DATA_TYPE t, c.CHARACTER_MAXIMUM_LENGTH l, c.NUMERIC_PRECISION p, c.NUMERIC_SCALE s, c.IS_NULLABLE nl, c.COLUMN_DEFAULT d
				FROM INFORMATION_SCHEMA.COLUMNS c WHERE c.TABLE_NAME = ? ORDER BY c.ORDINAL_POSITION", [$table]) as $r) {
				$t = (string)$r['t'];
				if ($r['l'] !== null) $t .= '(' . ((int)$r['l'] === -1 ? 'max' : (int)$r['l']) . ')';
				elseif (in_array($t, ['decimal', 'numeric'], true)) $t .= '(' . (int)$r['p'] . ',' . (int)$r['s'] . ')';
				$add((string)$r['n'], $t, $r['nl'] === 'YES', $r['d'] === null ? null : (string)$r['d'], '');
			}
			foreach ($db->all("SELECT col.name n, i.is_primary_key pk, i.is_unique u FROM sys.indexes i
				JOIN sys.index_columns ic ON ic.object_id = i.object_id AND ic.index_id = i.index_id
				JOIN sys.columns col ON col.object_id = ic.object_id AND col.column_id = ic.column_id
				WHERE i.object_id = OBJECT_ID(?)", [$table]) as $r) {
				$mark((string)$r['n'], $r['pk'] ? 'pk' : ($r['u'] ? 'unique' : 'index'));
			}
			foreach ($db->all("SELECT pc.name n, OBJECT_NAME(fkc.referenced_object_id) rt, rc.name rcn FROM sys.foreign_key_columns fkc
				JOIN sys.columns pc ON pc.object_id = fkc.parent_object_id AND pc.column_id = fkc.parent_column_id
				JOIN sys.columns rc ON rc.object_id = fkc.referenced_object_id AND rc.column_id = fkc.referenced_column_id
				WHERE fkc.parent_object_id = OBJECT_ID(?)", [$table]) as $r) {
				$mark((string)$r['n'], 'fk', $r['rt'] . '.' . $r['rcn']);
			}
		} else {
			$q = static fn(string $s) => '"' . str_replace('"', '""', $s) . '"';
			foreach ($db->all('PRAGMA table_info(' . $q($table) . ')') as $r) {
				$add((string)$r['name'], strtolower((string)$r['type']), !$r['notnull'] && !$r['pk'], $r['dflt_value'] === null ? null : (string)$r['dflt_value'], '');
				if ($r['pk']) $mark((string)$r['name'], 'pk');
			}
			foreach ($db->all('PRAGMA index_list(' . $q($table) . ')') as $ix) {
				foreach ($db->all('PRAGMA index_info(' . $q((string)$ix['name']) . ')') as $c) $mark((string)$c['name'], $ix['unique'] ? 'unique' : 'index');
			}
			foreach ($db->all('PRAGMA foreign_key_list(' . $q($table) . ')') as $r) $mark((string)$r['from'], 'fk', $r['table'] . '.' . $r['to']);
		}
		return array_values($cols);
	}

	/**
	 * nuBuilder has no real foreign keys: a column named …_<table>_id (e.g. sus_zzzzsys_access_id) points to that table.
	 * Returns the table name or ''.
	 */
	public static function guessReference(string $column, array $tables, string $self): string
	{
		$c = strtolower($column);
		if (!str_ends_with($c, '_id')) return '';
		$best = '';
		foreach ($tables as $t) {
			$lt = strtolower($t);
			if ($lt === strtolower($self)) continue;
			if (str_ends_with($c, '_' . $lt . '_id') || $c === $lt . '_id') { if (strlen($lt) > strlen($best)) $best = $t; }
		}
		return $best;
	}

	/** Table names of the database. */
	public static function tables(NuDB $db): array
	{
		return array_map('strval', array_column(match ($db->type) {
			'mysql'  => $db->all('SELECT TABLE_NAME n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()'),
			'sqlsrv' => $db->all("SELECT TABLE_NAME n FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_TYPE = 'BASE TABLE'"),
			default  => $db->all("SELECT name n FROM sqlite_master WHERE type = 'table'"),
		}, 'n'));
	}

	/** Column names that hold secrets: the value is masked until the eye is clicked. */
	public static function isSecret(string $column): bool
	{
		return (bool)preg_match('/pass(word)?|secret|token|api_?key|totp|otp_?key/i', $column);
	}
}
