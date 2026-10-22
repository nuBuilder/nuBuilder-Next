<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * "New form" generator: form + tab + one object per column + browse columns.
 * The table is either an existing one (columns read from the database) or created here from the column grid.
 */
final class NuBuilderGen
{
	public static function create(array $in): string
	{
		$db    = nu_db();
		$type  = in_array($in['type'] ?? '', ['browseedit', 'browse', 'edit', 'launch', 'subform'], true) ? $in['type'] : 'browseedit';
		$code  = trim((string)($in['code'] ?? ''));
		$desc  = trim((string)($in['description'] ?? ''));
		$mode  = $type === 'launch' ? 'none' : (($in['table_mode'] ?? '') === 'new' ? 'new' : 'existing');
		$table = trim((string)($in['table'] ?? ''));
		$pk    = trim((string)($in['primary_key'] ?? ''));
		$audit = $mode === 'new' && !empty($in['audit']);
		$nulog = $mode === 'new' && !empty($in['nulog']);                       // nuBuilder's <table>_nulog (0.8.349)

		if ($code === '' || $desc === '') nb_err(t('builder_err_code_desc'));
		if ((int)$db->value('SELECT COUNT(*) FROM zzzzsys_form WHERE sfo_code = ?', [$code])) nb_err(t('builder_err_code_taken'));

		$fields = [];                                 // what to generate objects for
		if ($mode === 'existing') {
			if ($table === '' || !NuDDL::validName($table)) nb_err(t('builder_err_table_name'));
			$cols = $db->columns($table);
			if (!$cols) nb_err(t('form_err_table_missing', $table));
			if ($pk === '') foreach ($cols as $n => $c) { if ($c['auto']) { $pk = $n; break; } }
			if ($pk === '') $pk = (string)array_key_first($cols);
			if (NuRecord::column($cols, $pk) === null) nb_err(t('builder_err_pk'));
			if (!empty($in['generate'])) {
				$prefix = self::commonPrefix(array_keys($cols));
				foreach ($cols as $name => $c) {
					if ($name === $pk && $c['auto']) continue;
					$fields[] = self::fromColumn($name, $c, self::label($name, $prefix));
				}
			}
		} elseif ($mode === 'new') {
			$spec   = self::validateNewTable($table, $pk, is_array($in['columns'] ?? null) ? $in['columns'] : [], $audit, $nulog);
			$pk     = $spec['pk'];
			$prefix = self::commonPrefix(array_column($spec['columns'], 'name'));
			foreach ($spec['columns'] as $c) $fields[] = self::fromSpec($c, $c['label'] !== '' ? $c['label'] : self::label($c['name'], $prefix));
			if ($audit) {
				foreach (array_keys(NuDDL::AUDIT) as $name) {
					$f = self::fromSpec(['name' => $name, 'type' => NuDDL::AUDIT[$name] === 'datetime' ? 'datetime' : 'text', 'length' => '50', 'required' => false], t('builder_audit_' . $name));
					$f['access'] = '1';
					$f['browse'] = false;
					$fields[] = $f;
				}
			}
			NuDDL::createTable($table, $pk, $spec['columns'], $audit, $nulog);   // DDL commits implicitly on MySQL – keep it outside the transaction
		} else {
			$table = '';
			$pk    = '';
		}

		$formId   = nu_id();
		$layout   = in_array($in['layout'] ?? '', ['auto', 'grid'], true) ? $in['layout'] : null;
		$settings = array_filter(['layout' => $layout, 'audit' => $audit ?: null]);
		try {
			$db->transaction(static function (NuDB $db) use ($formId, $type, $code, $desc, $table, $pk, $fields, $settings) {
				$db->insert('zzzzsys_form', [
					'zzzzsys_form_id' => $formId, 'sfo_type' => $type, 'sfo_code' => $code, 'sfo_description' => $desc,
					// changes only through drafts (0.8.386): a new form starts as Draft – only designers open it until it is published
					'sfo_status' => NuSecurity::get('form_drafts_required') ? '0' : '1',
					'sfo_table' => $table ?: null, 'sfo_primary_key' => $pk ?: null, 'sfo_browse_rows_per_page' => 20,
					'sfo_browse_sql' => $table !== '' ? 'SELECT * FROM ' . $table : null,
					'sfo_breadcrumb_title' => '|' . $desc, 'sfo_json' => $settings ? json_encode(['nunext' => $settings]) : null,
				]);
				$tabId = nu_id();
				$db->insert('zzzzsys_tab', ['zzzzsys_tab_id' => $tabId, 'syt_zzzzsys_form_id' => $formId, 'syt_title' => 'Main', 'syt_order' => 10, 'syt_access' => '0']);

				$top = 20; $order = 10; $browse = 0;
				foreach ($fields as $f) {
					$db->insert('zzzzsys_object', [
						'zzzzsys_object_id' => nu_id(), 'sob_all_zzzzsys_form_id' => $formId, 'sob_all_table' => $table, 'sob_all_type' => $f['otype'],
						'sob_all_id' => $f['name'], 'sob_all_label' => $f['label'], 'sob_all_zzzzsys_tab_id' => $tabId, 'sob_all_order' => $order,
						'sob_all_top' => $top, 'sob_all_left' => 170, 'sob_all_width' => $f['w'], 'sob_all_height' => $f['h'], 'sob_all_cloneable' => $f['access'] === '1' ? '0' : '1',
						'sob_all_align' => $f['numeric'] && $f['itype'] !== 'checkbox' ? 'right' : 'left', 'sob_all_validate' => $f['validate'], 'sob_all_access' => $f['access'],
						'sob_input_type' => $f['otype'] === 'input' ? $f['itype'] : null, 'sob_input_format' => $f['fmt'],
					]);
					$top += $f['h'] + 10; $order += 10;
					if ($f['browse'] && $browse < 6 && $f['otype'] !== 'textarea' && $type !== 'edit' && $type !== 'subform') {
						$db->insert('zzzzsys_browse', ['zzzzsys_browse_id' => nu_id(), 'sbr_zzzzsys_form_id' => $formId, 'sbr_title' => $f['label'],
							'sbr_display' => $f['name'], 'sbr_align' => $f['numeric'] ? 'r' : 'l', 'sbr_format' => $f['fmt'], 'sbr_order' => ($browse + 1) * 10, 'sbr_width' => min(250, max(80, $f['w']))]);
						$browse++;
					}
				}
			});
		} catch (Throwable $e) {
			if ($mode === 'new') {
				try { NuDDL::dropTable($table); } catch (Throwable) {}
			}
			throw $e;
		}
		return $formId;
	}

	/** Checks name, primary key and column grid of a new table. */
	private static function validateNewTable(string $table, string $pk, array $rows, bool $audit, bool $nulog = false): array
	{
		$db = nu_db();
		if ($table === '' || !NuDDL::validName($table)) nb_err(t('builder_err_table_name'));
		if (str_starts_with(strtolower($table), 'zzzzsys')) nb_err(t('builder_err_table_reserved'));
		if ($db->tableExists($table)) nb_err(t('builder_err_table_exists', $table));
		if ($pk === '') $pk = $table . '_id';
		if (!NuDDL::validName($pk)) nb_err(t('builder_err_column_name', $pk));

		$seen = [strtolower($pk) => true];
		if ($audit) foreach (array_keys(NuDDL::AUDIT) as $a) $seen[$a] = true;
		if ($nulog) $seen[strtolower($table . '_nulog')] = true;
		$columns = [];
		foreach ($rows as $r) {
			if (!is_array($r)) continue;
			$name = trim((string)($r['name'] ?? ''));
			if ($name === '') continue;
			if (!NuDDL::validName($name)) nb_err(t('builder_err_column_name', $name));
			if (isset($seen[strtolower($name)])) nb_err(t('builder_err_column_dup', $name));
			$seen[strtolower($name)] = true;
			$type = (string)($r['type'] ?? 'text');
			if (!in_array($type, NuDDL::TYPES, true)) nb_err(t('builder_err_column_type', $name));
			$length = trim((string)($r['length'] ?? ''));
			if ($length !== '' && !preg_match('/^\d{1,5}([,.]\d{1,2})?$/', $length)) nb_err(t('builder_err_column_length', $name));
			$columns[] = ['name' => $name, 'type' => $type, 'length' => $length, 'required' => !empty($r['required']), 'label' => trim((string)($r['label'] ?? ''))];
		}
		if (!$columns) nb_err(t('builder_err_no_columns'));
		return ['pk' => $pk, 'columns' => $columns];
	}

	private static function field(string $name, string $label, string $otype, ?string $itype, ?string $fmt, int $w, int $h, bool $numeric, bool $required = false): array
	{
		return ['name' => $name, 'label' => $label, 'otype' => $otype, 'itype' => $itype, 'fmt' => $fmt, 'w' => $w, 'h' => $h,
			'numeric' => $numeric, 'validate' => $required ? '1' : '0', 'access' => '0', 'browse' => true];
	}

	/** Object for a column of the new-table grid. */
	private static function fromSpec(array $c, string $label): array
	{
		$len = (int)$c['length'];
		$req = !empty($c['required']);
		return match ($c['type']) {
			'longtext'       => self::field($c['name'], $label, 'textarea', null, null, 400, 60, false, $req),
			'int', 'bigint'  => self::field($c['name'], $label, 'input', 'number', null, 100, 22, true, $req),
			'decimal'        => self::field($c['name'], $label, 'input', 'number', null, 120, 22, true, $req),
			'date'           => self::field($c['name'], $label, 'input', 'nuDate', 'D|dd.mm.yyyy', 110, 22, false, $req),
			'datetime'       => self::field($c['name'], $label, 'input', 'nuDate', 'D|dd.mm.yyyy hh:nn', 150, 22, false, $req),
			'time'           => self::field($c['name'], $label, 'input', 'time', null, 100, 22, false, $req),
			'bool'           => self::field($c['name'], $label, 'input', 'checkbox', null, 20, 20, false, false),
			default          => self::field($c['name'], $label, 'input', 'text', null, $len > 0 ? min(400, max(80, $len * 5)) : 250, 22, false, $req),
		};
	}

	/** Object for a column of an existing table. */
	private static function fromColumn(string $name, array $c, string $label): array
	{
		$t = $c['type'];
		if (in_array($t, ['text', 'mediumtext', 'longtext', 'ntext'], true)) return self::field($name, $label, 'textarea', null, null, 400, 60, false);
		if (in_array($t, ['tinyint', 'bit', 'boolean'], true)) return self::field($name, $label, 'input', 'checkbox', null, 20, 20, false);
		if ($t === 'date') return self::field($name, $label, 'input', 'nuDate', 'D|dd.mm.yyyy', 110, 22, false);
		if (in_array($t, ['datetime', 'datetime2', 'timestamp', 'smalldatetime'], true)) return self::field($name, $label, 'input', 'nuDate', 'D|dd.mm.yyyy hh:nn', 150, 22, false);
		if ($c['numeric']) return self::field($name, $label, 'input', 'number', null, 100, 22, true);
		return self::field($name, $label, 'input', 'text', null, 250, 22, false);
	}

	/** "tpemp_" when most columns start with it. */
	private static function commonPrefix(array $names): string
	{
		$count = [];
		foreach ($names as $n) if (preg_match('/^([A-Za-z0-9]+_)/', $n, $m)) $count[$m[1]] = ($count[$m[1]] ?? 0) + 1;
		arsort($count);
		$best = (string)array_key_first($count);
		return $best !== '' && $count[$best] >= max(2, count($names) * 0.6) ? $best : '';
	}

	private static function label(string $name, string $prefix): string
	{
		if ($prefix !== '' && str_starts_with($name, $prefix) && strlen($name) > strlen($prefix)) $name = substr($name, strlen($prefix));
		return ucwords(str_replace('_', ' ', $name));
	}
}
