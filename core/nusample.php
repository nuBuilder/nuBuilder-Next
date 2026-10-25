<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * Sample data for developers (Admin tools → Sample data, globeadmin): users, access levels, forms with every object type,
 * formats, procedures, form events, an e-mail template, code snippets and a picture – all with ids starting with "sample_".
 * The forms work on their own tables (sample_customer, sample_order …), written in nuBuilder's format, so nuBuilder can open them too.
 *
 *   status()     → ['installed' => bool, 'version' => int, 'current' => VERSION, 'when' => ..., 'counts' => [...]]
 *   install()    → [login => password] (random, shown once). Removes an older sample set first ("refresh").
 *   remove()     → deletes every sample_ row of the system tables and drops the sample tables.
 *   newPasswords() → [login => password]
 *
 * Raise VERSION whenever the definitions change: the page then offers "Refresh".
 */
final class NuSample
{
	public const VERSION = 17;             // 17: international sample names · 16: all objects – tab Upload (Uppy, file system) · 15: start pages of users for sample_sales, sample_manager, sample_editor · 14: four dashboards (start page, customer form tab Statistics, start form with country filter) · 13: orders – more room below the items · 12: all objects – tabs Run / Subform and Charts / Miscellaneous split · 11: reports (PDF), SQL builder query, auto numbers · 2: neutral number formats · 3: hash cookie demo · 4: lookup After Browse with #ROW#, more hash variables · 5: permission items · 6: all objects – HTML no longer overlaps the image · 7: tab New features · 8: charts · 9: start form redesigned (tiles)
	public const PREFIX = 'sample_';
	public const HOME = 'sample_home';            // form code; the id is samplehome (see fid())

	/** Data forms (codes). Their ids have no underscore after "sample": nuBuilder splits hook ids like <form id>_BE at "_". */
	private const FORMS = ['sample_home', 'sample_customer', 'sample_product', 'sample_order', 'sample_order_item', 'sample_all', 'sample_all_line'];
	private const HOOKS = ['BB', 'BE', 'BS', 'AS', 'BD', 'AD'];
	/** Objects with a lookup After Browse hook (<object id>_AB): ids without underscore after "sample", like the forms. */
	private const AB_OBJECTS = ['sampleorilookup'];

	/** Form id of a sample form code: sample_order_item → sampleorderitem. */
	public static function fid(string $code): string
	{
		return 'sample' . str_replace('_', '', substr($code, 7));
	}

	/** SQL list of the sample form ids, or of their PHP hook ids. */
	private static function idList(bool $hooks = false): string
	{
		$ids = [];
		foreach (self::FORMS as $f) {
			if (!$hooks) $ids[] = self::fid($f);
			else foreach (self::HOOKS as $h) $ids[] = self::fid($f) . '_' . $h;
		}
		return "'" . implode("', '", $ids) . "'";
	}

	/** System tables with sample rows: table => primary key. Children first (removal order). */
	private const SYSTEM = [
		'zzzzsys_nunext_widget' => 'zzzzsys_nunext_widget_id', 'zzzzsys_nunext_dashboard' => 'zzzzsys_nunext_dashboard_id',   // dashboards (0.8.299)
		'zzzzsys_nunext_list_view' => 'zzzzsys_nunext_list_view_id',                                                              // start pages of users (0.8.425)
		'zzzzsys_user_permission' => 'zzzzsys_user_permission_id', 'zzzzsys_permission_item' => 'zzzzsys_permission_item_id',
		'zzzzsys_event' => 'zzzzsys_event_id', 'zzzzsys_object' => 'zzzzsys_object_id', 'zzzzsys_browse' => 'zzzzsys_browse_id',
		'zzzzsys_tab' => 'zzzzsys_tab_id', 'zzzzsys_access_form' => 'zzzzsys_access_form_id', 'zzzzsys_access_php' => 'zzzzsys_access_php_id',
		'zzzzsys_access_report' => 'zzzzsys_access_report_id', 'zzzzsys_report' => 'zzzzsys_report_id', 'zzzzsys_select_clause' => 'zzzzsys_select_clause_id',
		'zzzzsys_select' => 'zzzzsys_select_id',
		'zzzzsys_user' => 'zzzzsys_user_id', 'zzzzsys_access' => 'zzzzsys_access_id', 'zzzzsys_php' => 'zzzzsys_php_id',
		'zzzzsys_email_template' => 'zzzzsys_email_template_id', 'zzzzsys_code_snippet' => 'zzzzsys_code_snippet_id',
		'zzzzsys_format' => 'zzzzsys_format_id', 'zzzzsys_file' => 'zzzzsys_file_id', 'zzzzsys_form' => 'zzzzsys_form_id',
	];

	/** Data tables, in creation order (removed in reverse). */
	private const TABLES = ['sample_customer', 'sample_product', 'sample_order', 'sample_order_item', 'sample_all', 'sample_all_line'];
	/** Link columns of the sample tables (sub-forms, lookups) – indexed (0.8.366). */
	private const INDEXES = ['sample_order_item' => ['ori_sample_order_id', 'ori_sample_product_id'], 'sample_order' => ['ord_sample_customer_id'], 'sample_all_line' => ['sal_sample_all_id']];

	/** Indexes on the link columns (also for a sample application installed before 0.8.366 – called on every install / refresh). */
	public static function indexes(): void
	{
		foreach (self::INDEXES as $t => $cols) foreach ($cols as $c) { try { NuDDL::ensureIndex($t, $c); } catch (Throwable $e) { error_log('nuBuilder Next sample index: ' . $e->getMessage()); } }
	}

	// ================================================================== status

	/** Sample version inside an update package (0 = not found). */
	public static function versionInZip(string $zipFile, string $prefix): int
	{
		if (!class_exists('ZipArchive')) return 0;
		$z = new ZipArchive();
		if ($z->open($zipFile) !== true) return 0;
		$src = $z->getFromName($prefix . 'core/nusample.php');
		$z->close();
		return is_string($src) && preg_match('/public const VERSION = (\d+);/', $src, $m) ? (int)$m[1] : 0;
	}

	public static function status(): array
	{
		$db = nu_db();
		$form = $db->one('SELECT sfo_json FROM zzzzsys_form WHERE zzzzsys_form_id = ?', [self::fid(self::HOME)]);
		$info = $form ? ((json_decode((string)$form['sfo_json'], true) ?: [])['nunext']['sample'] ?? []) : [];
		$counts = [];
		foreach (['zzzzsys_user' => 'users', 'zzzzsys_access' => 'access', 'zzzzsys_form' => 'forms', 'zzzzsys_object' => 'objects', 'zzzzsys_php' => 'php', 'zzzzsys_format' => 'formats'] as $t => $k) {
			$counts[$k] = $db->tableExists($t) ? (int)$db->value('SELECT COUNT(*) FROM ' . $t . ' WHERE ' . self::condition($t)) : 0;
		}
		$tables = array_values(array_filter(self::TABLES, static fn($t) => $db->tableExists($t)));
		return [
			'installed' => (bool)$form, 'version' => (int)($info['version'] ?? 0), 'current' => self::VERSION,
			'when' => (string)($info['installed'] ?? ''), 'counts' => $counts, 'tables' => $tables,
			'leftovers' => !$form && (array_sum($counts) > 0 || $tables),
			'blocking' => self::blockingUsers(),
		];
	}

	/** Users that are not sample users but have a sample access level (removal would leave them without access). */
	public static function blockingUsers(): array
	{
		$db = nu_db();
		return array_column($db->all('SELECT sus_login_name FROM zzzzsys_user WHERE ' . self::isSample('sus_zzzzsys_access_id') . ' AND NOT ' . self::isSample('zzzzsys_user_id') . ' ORDER BY sus_login_name'), 'sus_login_name');
	}

	/** SQL condition "column starts with sample_" (no LIKE: "_" is a wildcard and escaping differs per database). */
	private static function isSample(string $col): string
	{
		return 'SUBSTRING(' . $col . ', 1, 7) = \'' . self::PREFIX . '\'';
	}

	// ================================================================== install / remove

	/** @return array login => password */
	public static function install(): array
	{
		nu_time_limit(300);
		$db = nu_db();
		$st = self::status();
		if ($st['installed'] || $st['leftovers']) self::remove(true);
		foreach (self::TABLES as $t) if ($db->tableExists($t)) throw new RuntimeException(t('smp_err_table_exists', $t));
		NuFile::ensureTable();                                   // older nuBuilder Next install scripts did not create these
		NuFormat::ensureTable();
		NuPermission::ensureTables();
		NuReport::ensureTables();                                // reports and SQL builder (sample reports)
		NuSchema::create();                                      // nuBuilder Next's own tables (dashboards)
		try { NuUpload::ensureTemplates(); } catch (Throwable $e) { error_log('nuBuilder Next sample upload template: ' . $e->getMessage()); }   // tab Upload of sample_all

		$def = self::definition();
		$created = [];
		try {
			foreach ($def['tables'] as $name => $spec) {               // DDL outside the transaction (MySQL commits it anyway)
				NuDDL::createTable($name, $name . '_id', $spec['columns'], !empty($spec['audit']));
				$created[] = $name;
			}
			self::indexes();
			$passwords = [];
			$db->transaction(static function (NuDB $db) use ($def, &$passwords) {
				foreach ($def['rows'] as [$table, $row]) self::insert($db, $table, $row);
				foreach ($def['users'] as $u) {
					$pw = self::password();
					$passwords[$u['sus_login_name']] = $pw;
					self::insert($db, 'zzzzsys_user', $u + ['sus_login_password' => NuAuth::hash($pw)]);
				}
			});
		} catch (Throwable $e) {
			foreach (array_reverse($created) as $t) { try { NuDDL::dropTable($t); } catch (Throwable) {} }
			try { self::deleteRows(); } catch (Throwable) {}
			throw $e;
		}
		NuLog::write('sample_installed', 'sample', '', ['version' => self::VERSION]);
		return $passwords;
	}

	public static function remove(bool $forRefresh = false): void
	{
		if (!$forRefresh && ($b = self::blockingUsers())) throw new RuntimeException(t('smp_err_blocking', implode(', ', $b)));
		$db = nu_db();
		$known = self::status();
		self::deleteRows();
		// own tables only when the sample set was installed (a stranger's table called sample_customer is never dropped)
		if ($known['installed'] || $known['leftovers']) {
			foreach (array_reverse(self::TABLES) as $t) if ($db->tableExists($t) && self::looksLikeOurs($t)) NuDDL::dropTable($t);
		}
		if (!$forRefresh) NuLog::write('sample_removed', 'sample');
	}

	/** New random passwords for the sample users. @return array login => password */
	public static function newPasswords(): array
	{
		$db = nu_db();
		$out = [];
		foreach ($db->all('SELECT zzzzsys_user_id, sus_login_name FROM zzzzsys_user WHERE ' . self::isSample('zzzzsys_user_id') . ' ORDER BY sus_login_name') as $u) {
			$pw = self::password();
			$db->update('zzzzsys_user', ['sus_login_password' => NuAuth::hash($pw)], 'zzzzsys_user_id', (string)$u['zzzzsys_user_id']);
			$out[(string)$u['sus_login_name']] = $pw;
		}
		return $out;
	}

	private static function deleteRows(): void
	{
		$db = nu_db();
		$db->transaction(static function (NuDB $db) {
			// rights of other access levels on sample forms / procedures go with them
			if ($db->tableExists('zzzzsys_access_form')) $db->run('DELETE FROM zzzzsys_access_form WHERE slf_zzzzsys_form_id IN (' . self::idList() . ')');
			if ($db->tableExists('zzzzsys_access_php')) $db->run('DELETE FROM zzzzsys_access_php WHERE ' . self::isSample('slp_zzzzsys_php_id'));
			foreach (array_keys(self::SYSTEM) as $t) if ($db->tableExists($t)) $db->run('DELETE FROM ' . $t . ' WHERE ' . self::condition($t));
		});
	}

	/** Rows of a system table that belong to the sample set. */
	private static function condition(string $t): string
	{
		$c = self::isSample(self::SYSTEM[$t]);
		if ($t === 'zzzzsys_form') $c .= ' OR zzzzsys_form_id IN (' . self::idList() . ')';
		if ($t === 'zzzzsys_php') $c .= ' OR zzzzsys_php_id IN (' . self::idList(true) . ", '" . implode("_AB', '", self::AB_OBJECTS) . "_AB')";
		if ($t === 'zzzzsys_object') $c .= " OR zzzzsys_object_id IN ('" . implode("', '", self::AB_OBJECTS) . "')";
		// assignments of sample items to other users, and of other items to sample users
		if ($t === 'zzzzsys_user_permission') $c .= ' OR ' . self::isSample('upe_ident') . ' OR ' . self::isSample('upe_zzzzsys_user_id');
		if ($t === 'zzzzsys_event') $c .= " OR sev_zzzzsys_object_id IN ('" . implode("', '", self::AB_OBJECTS) . "')";
		// start pages saved in the editor for a sample access level (their ids are random)
		if ($t === 'zzzzsys_nunext_list_view') $c .= " OR (list_key = '" . NuHome::LIST_USER . "' AND SUBSTRING(access_ids, 1, 9) = '[\"" . self::PREFIX . "')";
		return '(' . $c . ')';
	}

	/** The table has the primary key the sample set gives it. */
	private static function looksLikeOurs(string $t): bool
	{
		return isset(nu_db()->columns($t)[$t . '_id']);
	}

	private static function password(): string
	{
		$chars = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
		$pw = '';
		for ($i = 0; $i < 14; $i++) $pw .= $chars[random_int(0, strlen($chars) - 1)];
		return substr($pw, 0, 7) . '-' . substr($pw, 7);
	}

	/** Insert with the columns this database has (older nuBuilder versions lack a few). */
	private static function insert(NuDB $db, string $table, array $row): void
	{
		static $cols = [];
		$cols[$table] ??= array_change_key_case(array_fill_keys(array_keys($db->columns($table)), true));
		// only columns this database has; null = leave out (the column default applies – some nuBuilder columns are NOT NULL DEFAULT '0')
		$row = array_filter($row, static fn($v, $k) => $v !== null && isset($cols[$table][strtolower((string)$k)]), ARRAY_FILTER_USE_BOTH);
		foreach ($row as $k => $v) if (is_bool($v)) $row[$k] = $v ? 1 : 0;
		$db->insert($table, $row);
	}

	// ================================================================== definition

	/** Everything the sample set consists of: ['tables' => [name => spec], 'rows' => [[table, row], …], 'users' => [row, …]]. */
	public static function definition(): array
	{
		$rows = [];
		$add = static function (string $table, array $row) use (&$rows) { $rows[] = [$table, $row]; };

		// ------------------------------------------------------------ formats, picture, procedures, snippets, template
		foreach (self::formats() as $f) $add('zzzzsys_format', $f);
		$add('zzzzsys_file', ['zzzzsys_file_id' => 'sample_file_logo', 'sfi_code' => 'sample_logo', 'sfi_description' => 'Sample logo (SVG)', 'sfi_group' => 'sample',
			'sfi_system' => '0', 'sfi_json' => json_encode(['file' => base64_encode('data:image/svg+xml;base64,' . base64_encode(self::logoSvg())), 'name' => 'sample_logo.svg', 'size' => strlen(self::logoSvg()), 'type' => 'image/svg+xml'])]);
		foreach (self::procedures() as $p) $add('zzzzsys_php', $p);
		foreach (self::snippets() as $s) $add('zzzzsys_code_snippet', $s);
		foreach (self::reports() as [$t, $r]) $add($t, $r);
		$add('zzzzsys_email_template', [
			'zzzzsys_email_template_id' => 'sample_emt_order', 'emt_form_id' => self::fid('sample_order'), 'emt_group' => 'sample', 'emt_category' => 'Sample',
			'emt_template' => '', 'emt_language' => '', 'emt_code' => 'sample_order_confirmation', 'emt_description' => 'Sample: order confirmation',
			'emt_subject' => 'Your order #ord_number#', 'emt_to' => '#cus_email#', 'emt_cc' => '', 'emt_bcc' => '',
			'emt_body' => "<p>Dear #cus_name#,</p>\n<p>thank you for order <b>#ord_number#</b> of #ord_date#.</p>\n<p>Kind regards<br>#USER_FIRST_NAME#</p>",
		]);

		// ------------------------------------------------------------ forms
		$tables = [];
		foreach (self::forms() as $f) {
			$formRows = self::formRows($f);
			foreach ($formRows as $r) $rows[] = $r;
			if (!empty($f['create'])) $tables[$f['table']] = ['columns' => $f['create'], 'audit' => !empty($f['audit'])];
		}
		// sample_all: one column per data object
		$all = self::allObjectsForm();
		foreach (self::formRows($all) as $r) $rows[] = $r;
		$tables['sample_all'] = ['columns' => self::columnsFor($all), 'audit' => false];
		$line = self::allLineForm();
		foreach (self::formRows($line) as $r) $rows[] = $r;
		$tables['sample_all_line'] = ['columns' => array_merge([['name' => 'sal_sample_all_id', 'type' => 'id', 'length' => '']], self::columnsFor($line)), 'audit' => false];
		$tables = array_merge(array_flip(self::TABLES), $tables);          // creation order

		// ------------------------------------------------------------ data
		foreach (self::data() as [$t, $r]) $add($t, $r);

		// ------------------------------------------------------------ dashboards (Admin tools → Dashboards)
		foreach (self::dashboards() as [$t, $r]) $add($t, $r);
		foreach (self::userHomes() as [$t, $r]) $add($t, $r);

		// ------------------------------------------------------------ access levels + users
		[$access, $users] = self::accessAndUsers();
		foreach ($access as $r) $rows[] = $r;

		return ['tables' => $tables, 'rows' => $rows, 'users' => $users];
	}

	/** Columns of a form's table from its data objects (types as the form builder chooses them). */
	private static function columnsFor(array $f): array
	{
		$cols = [];
		foreach ($f['objects'] as $o) {
			if (in_array($o['type'], ['subform', 'word', 'html', 'run', 'image', 'contentbox', 'display'], true)) continue;
			if (($o['target'] ?? '0') === '1') continue;   // Uppy upload (file system): no column
			$type = NuDDL::typeForObject(['sob_all_type' => $o['type'], 'sob_input_type' => $o['input'] ?? '', 'sob_input_format' => $o['format'] ?? '', 'sob_select_multiple' => !empty($o['multiple']) ? '1' : '0']);
			if ($type) $cols[] = ['name' => $o['id'], 'type' => $type[0], 'length' => $type[1]];
		}
		return $cols;
	}

	/**
	 * zzzzsys_form / tab / browse / object / event / php rows of a compact form definition:
	 *   id, type, table, desc, layout (null = nuBuilder positions | auto | grid), tabs [title, …], browse [[title, column, align, format, width]],
	 *   objects [['tab' => 0, 'type' => …, 'id' => field, 'label' => …, …]], php ['BE' => code, …], js, browse_js, edit_js, style
	 */
	private static function formRows(array $f): array
	{
		$rows = [];
		$id = $f['id'];                       // code (sample_order)
		$fid = self::fid($id);                // id (sampleorder)
		$short = substr($id, 7, 10);
		$nunext = array_filter(['layout' => $f['layout'] ?? null, 'audit' => !empty($f['audit']) ?: null]);
		if ($id === self::HOME) $nunext['sample'] = ['version' => self::VERSION, 'installed' => date('Y-m-d H:i')];
		$pk = $f['table'] !== '' ? $f['table'] . '_id' : '';
		$rows[] = ['zzzzsys_form', [
			'zzzzsys_form_id' => $fid, 'sfo_type' => $f['type'], 'sfo_code' => $id, 'sfo_description' => $f['desc'], 'sfo_group' => 'sample', 'sfo_status' => '1',
			'sfo_breadcrumb_title' => $f['breadcrumb'] ?? '|' . $f['desc'], 'sfo_table' => $f['table'] ?: null, 'sfo_primary_key' => $pk ?: null,
			'sfo_browse_rows_per_page' => $f['rows_per_page'] ?? 20, 'sfo_browse_row_height' => 0, 'sfo_browse_title_multiline' => '0', 'sfo_browse_autoresize_columns' => '1',
			'sfo_browse_sql' => $f['browse_sql'] ?? ($f['table'] !== '' ? 'SELECT * FROM ' . $f['table'] : null),
			'sfo_browse_redirect_form_id' => '', 'sfo_browse_target' => '0', 'sfo_mobile_view' => '0',
			'sfo_javascript' => $f['js'] ?? null, 'sfo_browse_javascript' => $f['browse_js'] ?? null, 'sfo_edit_javascript' => $f['edit_js'] ?? null, 'sfo_style' => $f['style'] ?? null,
			'sfo_json' => $nunext ? json_encode(['nunext' => $nunext]) : null,
		]];
		$tabIds = [];
		foreach ($f['tabs'] ?? ['Main'] as $i => $title) {
			$tabIds[$i] = $id === self::HOME && $i === 0 ? 'sample_home_t1' : 'sample_' . $short . '_t' . ($i + 1);
			$rows[] = ['zzzzsys_tab', ['zzzzsys_tab_id' => $tabIds[$i], 'syt_zzzzsys_form_id' => $fid, 'syt_title' => $title, 'syt_order' => ($i + 1) * 10, 'syt_access' => '0', 'syt_help' => '']];
		}
		foreach ($f['browse'] ?? [] as $i => $b) {
			$rows[] = ['zzzzsys_browse', ['zzzzsys_browse_id' => 'sample_' . $short . '_b' . ($i + 1), 'sbr_zzzzsys_form_id' => $fid, 'sbr_title' => $b[0], 'sbr_display' => $b[1],
				'sbr_align' => $b[2] ?? 'l', 'sbr_format' => $b[3] ?? '', 'sbr_order' => ($i + 1) * 10, 'sbr_width' => $b[4] ?? 150]];
		}
		$top = [];
		$ev = 0;
		foreach ($f['objects'] as $n => $o) {
			$tab = (int)($o['tab'] ?? 0);
			$h = (int)($o['h'] ?? match ($o['type']) { 'textarea', 'editor' => 70, 'subform' => 220, 'html' => 60, 'chart' => 240, 'image' => 64, 'run' => ($o['method'] ?? 'b') === 'i' ? 260 : 30, 'contentbox' => 120, default => 22 });
			$w = (int)($o['w'] ?? match ($o['type']) { 'subform' => 600, 'textarea', 'editor', 'html', 'chart' => 400, 'run' => ($o['method'] ?? 'b') === 'i' ? 600 : 160, 'image' => 64, default => 220 });
			$y = $o['top'] ?? ($top[$tab] ?? 20);
			$top[$tab] = max($top[$tab] ?? 20, $y + $h + 14);
			$oid = $o['oid'] ?? 'sample_' . $short . '_o' . ($n + 1);
			$isData = !in_array($o['type'], ['word', 'html', 'chart', 'run', 'image', 'contentbox', 'subform', 'display'], true) && ($o['input'] ?? '') !== 'button';
			$rows[] = ['zzzzsys_object', [
				'zzzzsys_object_id' => $oid, 'sob_all_zzzzsys_form_id' => $fid, 'sob_all_table' => $f['table'], 'sob_all_type' => $o['type'], 'sob_all_id' => $o['id'],
				'sob_all_label' => $o['label'] ?? '', 'sob_all_zzzzsys_tab_id' => $tabIds[$tab], 'sob_all_order' => ($n + 1) * 10,
				'sob_all_top' => $y, 'sob_all_left' => $o['left'] ?? 170, 'sob_all_width' => $w, 'sob_all_height' => $h,
				'sob_all_cloneable' => ($o['clone'] ?? $isData) ? '1' : '0', 'sob_all_align' => $o['align'] ?? 'left',
				'sob_all_validate' => (string)($o['validate'] ?? '0'), 'sob_all_access' => (string)($o['access'] ?? '0'), 'sob_all_access_condition' => '',
				'sob_all_event' => !empty($o['events']) ? '1' : '0', 'sob_all_style_type' => $o['style_type'] ?? '', 'sob_all_style' => $o['style'] ?? '',
				'sob_input_type' => $o['type'] === 'input' ? ($o['input'] ?? 'text') : null, 'sob_input_format' => $o['format'] ?? '',
				'sob_input_count' => $o['count'] ?? null, 'sob_input_javascript' => $o['scroll'] ?? null, 'sob_input_datalist' => $o['datalist'] ?? null,
				'sob_input_attribute' => $o['attribute'] ?? null, 'sob_input_icon' => null, 'sob_input_file_target' => $o['type'] === 'input' && ($o['input'] ?? '') === 'file' ? (string)($o['target'] ?? '0') : null,
				'sob_select_multiple' => $o['type'] === 'select' ? (!empty($o['multiple']) ? '1' : '0') : null, 'sob_select_2' => $o['type'] === 'select' ? (!empty($o['select2']) ? '1' : '0') : null,
				'sob_select_sql' => $o['options'] ?? null,
				'sob_display_sql' => $o['sql'] ?? null,
				'sob_calc_formula' => $o['formula'] ?? null, 'sob_calc_format' => $o['type'] === 'calc' ? ($o['format'] ?? '') : null,
				'sob_lookup_zzzzsys_form_id' => isset($o['lookup_form']) ? self::fid($o['lookup_form']) : null, 'sob_lookup_code' => $o['lookup_code'] ?? null, 'sob_lookup_description' => $o['lookup_desc'] ?? null,
				'sob_lookup_description_width' => isset($o['lookup_form']) ? (string)($o['lookup_width'] ?? 200) : null, 'sob_lookup_autocomplete' => isset($o['lookup_form']) ? (!empty($o['autocomplete']) ? '1' : '0') : null,
				'sob_lookup_table' => $o['lookup_form'] ?? null,
				'sob_subform_zzzzsys_form_id' => isset($o['subform']) ? self::fid($o['subform']) : null, 'sob_subform_foreign_key' => $o['foreign_key'] ?? null, 'sob_subform_table' => $o['subform'] ?? null,
				'sob_subform_add' => isset($o['subform']) ? '1' : null, 'sob_subform_delete' => isset($o['subform']) ? '1' : null, 'sob_subform_type' => isset($o['subform']) ? ($o['sf_type'] ?? 'g') : null,
				'sob_run_zzzzsys_form_id' => isset($o['run']) ? (($o['run_type'] ?? 'F') === 'F' ? self::fid($o['run']) : $o['run']) : null, 'sob_run_method' => isset($o['run']) ? ($o['method'] ?? 'b') : null, 'sob_run_target' => isset($o['run']) ? ($o['target'] ?? '0') : null,
				'sob_run_type' => isset($o['run']) ? ($o['run_type'] ?? 'F') : null, 'sob_run_id' => $o['run_id'] ?? null, 'sob_run_filter' => $o['run_filter'] ?? null,
				'sob_html_code' => $o['html'] ?? null, 'sob_image_zzzzsys_file_id' => $o['file'] ?? null,
				'sob_html_chart_type' => $o['chart'] ?? null, 'sob_html_javascript' => $o['chart_data'] ?? null, 'sob_html_title' => $o['chart_title'] ?? null,
				'sob_html_horizontal_label' => $o['chart_h'] ?? null, 'sob_html_vertical_label' => $o['chart_v'] ?? null,
				'sob_all_json' => isset($o['grid']) ? json_encode(['nunext' => $o['grid']]) : null,
			]];
			if (isset($o['ab'])) {                    // lookup After Browse (nuLookupRecord(), nuSetFormValue('#ROW#…'))
				$rows[] = ['zzzzsys_php', ['zzzzsys_php_id' => $oid . '_AB', 'sph_code' => $oid . '_AB', 'sph_description' => 'System PHP', 'sph_group' => 'nubuilder',
					'sph_php' => $o['ab'], 'sph_run' => '', 'sph_zzzzsys_form_id' => '', 'sph_system' => '1', 'sph_global' => '0', 'sph_hide' => '', 'sph_javascript' => '', 'sph_template' => '0']];
			}
			foreach ($o['events'] ?? [] as $event => $js) {
				$ev++;
				$rows[] = ['zzzzsys_event', ['zzzzsys_event_id' => 'sample_' . $short . '_e' . $ev, 'sev_zzzzsys_object_id' => $oid, 'sev_event' => $event, 'sev_javascript' => $js]];
			}
		}
		foreach ($f['php'] ?? [] as $hook => $code) {
			$rows[] = ['zzzzsys_php', ['zzzzsys_php_id' => $fid . '_' . $hook, 'sph_code' => $fid . '_' . $hook, 'sph_description' => 'System PHP', 'sph_group' => 'nubuilder',
				'sph_php' => $code, 'sph_run' => '', 'sph_zzzzsys_form_id' => '', 'sph_system' => '1', 'sph_global' => '0', 'sph_hide' => '', 'sph_javascript' => '', 'sph_template' => '0']];
		}
		return $rows;
	}

	// ------------------------------------------------------------------ the forms

	private static function forms(): array
	{
		$cust = [
			['name' => 'cus_code', 'type' => 'text', 'length' => '20'], ['name' => 'cus_name', 'type' => 'text', 'length' => '100'],
			['name' => 'cus_email', 'type' => 'text', 'length' => '100'], ['name' => 'cus_phone', 'type' => 'text', 'length' => '30'],
			['name' => 'cus_country', 'type' => 'text', 'length' => '2'], ['name' => 'cus_active', 'type' => 'bool', 'length' => ''],
			['name' => 'cus_since', 'type' => 'date', 'length' => ''], ['name' => 'cus_credit', 'type' => 'decimal', 'length' => '15,2'],
			['name' => 'cus_notes', 'type' => 'longtext', 'length' => ''],
		];
		$countries = 'US|United States|GB|United Kingdom|CA|Canada|AU|Australia|DE|Germany';
		return [
			// ---------------------------------------------------- 1. launch form: start page of the sample access levels
			[
				'id' => self::HOME, 'type' => 'launch', 'table' => '', 'desc' => 'Sample: start', 'layout' => 'auto',
				// tiles: run objects with the class sampleTile; icons and look in the form's CSS (sfo_style, below)
				'objects' => [
					['type' => 'image', 'id' => 'home_logo', 'label' => '', 'file' => 'sample_file_logo', 'left' => 20, 'w' => 72, 'h' => 72, 'top' => 20, 'style_type' => 'Class', 'style' => 'sampleLogo'],
					['type' => 'html', 'id' => 'home_intro', 'label' => '', 'left' => 108, 'top' => 20, 'w' => 560, 'h' => 72,
						'html' => '<div class="sampleIntro"><h1>nuBuilder Next sample application</h1><p>Logged in as <b>#USER_FIRST_NAME# #USER_LAST_NAME#</b> · user id <code>#USER_ID#</code> · access level <code>#ACCESS_LEVEL_CODE#</code></p></div>'],
					['type' => 'word', 'id' => 'home_open', 'label' => 'Open a form', 'left' => 20, 'top' => 112, 'w' => 760, 'style_type' => 'Class', 'style' => 'sampleSection'],
					['type' => 'run', 'id' => 'home_customers', 'label' => 'Customers', 'run' => 'sample_customer', 'left' => 20, 'top' => 140, 'w' => 180, 'h' => 120, 'style_type' => 'Class', 'style' => 'sampleTile'],
					['type' => 'run', 'id' => 'home_products', 'label' => 'Products', 'run' => 'sample_product', 'left' => 216, 'top' => 140, 'w' => 180, 'h' => 120, 'style_type' => 'Class', 'style' => 'sampleTile'],
					['type' => 'run', 'id' => 'home_orders', 'label' => 'Orders', 'run' => 'sample_order', 'left' => 412, 'top' => 140, 'w' => 180, 'h' => 120, 'style_type' => 'Class', 'style' => 'sampleTile'],
					['type' => 'run', 'id' => 'home_all', 'label' => 'All objects', 'run' => 'sample_all', 'left' => 608, 'top' => 140, 'w' => 180, 'h' => 120, 'style_type' => 'Class', 'style' => 'sampleTile'],
					['type' => 'word', 'id' => 'home_more', 'label' => 'Run options', 'left' => 20, 'top' => 284, 'w' => 760, 'style_type' => 'Class', 'style' => 'sampleSection'],
					['type' => 'run', 'id' => 'home_new_order', 'label' => 'New order', 'run' => 'sample_order', 'run_id' => '-1', 'left' => 20, 'top' => 312, 'w' => 180, 'h' => 120, 'style_type' => 'Class', 'style' => 'sampleTile'],
					['type' => 'run', 'id' => 'home_window', 'label' => 'Customers', 'run' => 'sample_customer', 'target' => '2', 'left' => 216, 'top' => 312, 'w' => 180, 'h' => 120, 'style_type' => 'Class', 'style' => 'sampleTile'],
					['type' => 'run', 'id' => 'home_hello', 'label' => 'Procedure', 'run' => 'sample_proc_hello', 'run_type' => 'P', 'left' => 412, 'top' => 312, 'w' => 180, 'h' => 120, 'style_type' => 'Class', 'style' => 'sampleTile'],
					['type' => 'run', 'id' => 'home_hidden', 'label' => 'Hidden', 'run' => 'sample_proc_hidden', 'run_type' => 'P', 'left' => 608, 'top' => 312, 'w' => 180, 'h' => 120, 'style_type' => 'Class', 'style' => 'sampleTile'],
					['type' => 'word', 'id' => 'home_hashword', 'label' => 'Hash cookies', 'left' => 20, 'top' => 456, 'w' => 760, 'style_type' => 'Class', 'style' => 'sampleSection'],
					['type' => 'html', 'id' => 'home_hash', 'label' => '', 'left' => 20, 'top' => 484, 'w' => 768, 'h' => 80,
						'html' => '<div class="sampleNote">Global hash cookie <code>&#35;sample_visits&#35;</code> (counted in Before Edit PHP): this start page was opened <b>#sample_visits#</b> times since login.<br>'
							. '<code>&#35;sample_colour&#35;</code> = <b>#sample_colour#</b> – set it with the button, then open <i>All objects</i> → Display.</div>'],
					['type' => 'word', 'id' => 'home_reportword', 'label' => 'Reports (PDF)', 'left' => 20, 'top' => 656, 'w' => 760, 'style_type' => 'Class', 'style' => 'sampleSection'],
					['type' => 'run', 'id' => 'home_rpt_customers', 'label' => 'Customer list', 'run' => 'sample_rpt_customers', 'run_type' => 'R', 'left' => 20, 'top' => 684, 'w' => 180, 'h' => 120, 'style_type' => 'Class', 'style' => 'sampleTile'],
					['type' => 'run', 'id' => 'home_rpt_orders', 'label' => 'Orders by customer', 'run' => 'sample_rpt_orders', 'run_type' => 'R', 'left' => 216, 'top' => 684, 'w' => 180, 'h' => 120, 'style_type' => 'Class', 'style' => 'sampleTile'],
					['type' => 'word', 'id' => 'home_dashword', 'label' => 'Dashboard (nuBuilder Next)', 'left' => 20, 'top' => 828, 'w' => 760, 'style_type' => 'Class', 'style' => 'sampleSection'],
					['type' => 'select', 'id' => 'home_country', 'label' => 'Country', 'left' => 120, 'top' => 860, 'w' => 200, 'options' => '|All countries|' . $countries,
						'events' => ['onchange' => "// a launch form's field as criterion: the dashboard reads #home_country# again\nif (window.nuNextDashboardRefresh) nuNextDashboardRefresh('sample_country');"]],
					['type' => 'html', 'id' => 'home_dashboard', 'label' => '', 'left' => 20, 'top' => 900, 'w' => 900, 'h' => 560,
						'html' => '<div data-nu-dashboard="sample_country"></div>'],
					['type' => 'input', 'input' => 'button', 'id' => 'home_colour', 'label' => 'Set hash cookie sample_colour', 'left' => 20, 'top' => 604, 'w' => 260, 'style_type' => 'Class', 'style' => 'sampleButton',
						'events' => ['onclick' => "var c = ['red', 'green', 'blue'][Math.floor(Math.random() * 3)];\nnuSetProperty('sample_colour', c, true).then(function () {\n\tnuMessage(['Global hash cookie sample_colour = ' + nuGetProperty('sample_colour'), 'It is now replaced in every form until you log out.']);\n});"]],
				],
				'php' => ['BE' => "// Global hash cookie: lives until logout, available as #sample_visits# in every form\nnuSetProperty('sample_visits', (int)nuGetProperty('sample_visits') + 1, true);"],
				'style' => self::homeCss(),
			],
			// ---------------------------------------------------- 2. customers: nuBuilder positions, two tabs, validation, BD hook
			[
				'id' => 'sample_customer', 'type' => 'browseedit', 'table' => 'sample_customer', 'desc' => 'Sample: customers', 'layout' => null,
				'breadcrumb' => '#cus_name#|New customer', 'rows_per_page' => 10, 'create' => $cust, 'tabs' => ['Customer', 'Details', 'Statistics'],
				'browse' => [['Code', 'cus_code', 'l', '', 90], ['Name', 'cus_name', 'l', '', 220], ['Country', 'cus_country', 'c', '', 80], ['Since', 'cus_since', 'l', 'D|dd.mm.yyyy', 110], ['Credit', 'cus_credit', 'r', "N|USD 1,000.00", 130]],
				'objects' => [
					['type' => 'input', 'id' => 'cus_code', 'label' => 'Code', 'w' => 100, 'validate' => 3],
					['type' => 'input', 'id' => 'cus_name', 'label' => 'Name', 'w' => 300, 'validate' => 1],
					['type' => 'input', 'input' => 'email', 'id' => 'cus_email', 'label' => 'E-mail', 'w' => 300],
					['type' => 'input', 'id' => 'cus_phone', 'label' => 'Phone', 'w' => 180, 'format' => 'T|+99 99 999 99 99'],
					['type' => 'select', 'id' => 'cus_country', 'label' => 'Country', 'w' => 180, 'options' => $countries,
						'events' => ['onchange' => "nuToast('Country: ' + nuGetText('cus_country'));"]],
					['type' => 'input', 'input' => 'checkbox', 'id' => 'cus_active', 'label' => 'Active', 'w' => 20, 'h' => 20],
					['tab' => 1, 'type' => 'input', 'input' => 'nuDate', 'id' => 'cus_since', 'label' => 'Customer since', 'w' => 110, 'format' => 'D|dd.mm.yyyy'],
					['tab' => 1, 'type' => 'input', 'input' => 'nuNumber', 'id' => 'cus_credit', 'label' => 'Credit limit', 'w' => 140, 'format' => "N|USD 1,000.00", 'align' => 'right'],
					['tab' => 1, 'type' => 'textarea', 'id' => 'cus_notes', 'label' => 'Notes', 'w' => 400, 'h' => 80],
					['tab' => 1, 'type' => 'display', 'id' => 'cus_orders', 'label' => 'Orders', 'w' => 80, 'align' => 'right',
						'sql' => "SELECT COUNT(*) FROM sample_order WHERE ord_sample_customer_id = '#RECORD_ID#'"],
					// dashboard sample_customer (nuBuilder Next): an HTML object with data-nu-dashboard – nuBuilder shows an empty box
					['tab' => 2, 'type' => 'html', 'id' => 'cus_dashboard', 'label' => '', 'left' => 20, 'top' => 20, 'w' => 900, 'h' => 520,
						'html' => '<div data-nu-dashboard="sample_customer"></div>'],
				],
				'edit_js' => "if (nuIsNewRecord()) { nuSetValue('cus_active', true); nuSetValue('cus_country', 'US'); }",
				'php' => ['BD' => "\$n = db_fetch_row(nuRunQuery(\"SELECT COUNT(*) FROM sample_order WHERE ord_sample_customer_id = ?\", ['#RECORD_ID#']))[0] ?? 0;\nif (\$n > 0) nuDisplayError(\"This customer has \$n orders and cannot be deleted.\");"],
			],
			// ---------------------------------------------------- 3. products: grid layout, browse formats, select2
			[
				'id' => 'sample_product', 'type' => 'browseedit', 'table' => 'sample_product', 'desc' => 'Sample: products', 'layout' => 'grid',
				'breadcrumb' => '#pro_name#|New product',
				'create' => [['name' => 'pro_code', 'type' => 'text', 'length' => '20'], ['name' => 'pro_name', 'type' => 'text', 'length' => '100'],
					['name' => 'pro_category', 'type' => 'text', 'length' => '30'], ['name' => 'pro_price', 'type' => 'decimal', 'length' => '15,2'], ['name' => 'pro_active', 'type' => 'bool', 'length' => '']],
				'browse' => [['Code', 'pro_code', 'l', '', 90], ['Product', 'pro_name', 'l', '', 240], ['Category', 'pro_category', 'l', '', 130], ['Price', 'pro_price', 'r', "N|USD 1,000.00", 120], ['Active', "CASE WHEN pro_active = 1 THEN 'yes' ELSE 'no' END", 'c', '', 70]],
				'objects' => [
					['type' => 'input', 'id' => 'pro_code', 'label' => 'Code', 'validate' => 3, 'grid' => ['row' => 1, 'span' => 4]],
					['type' => 'input', 'id' => 'pro_name', 'label' => 'Product', 'validate' => 1, 'grid' => ['row' => 1, 'span' => 8]],
					['type' => 'select', 'id' => 'pro_category', 'label' => 'Category', 'select2' => true, 'options' => 'Hardware|Hardware|Software|Software|Service|Service|Training|Training', 'grid' => ['row' => 2, 'span' => 4]],
					['type' => 'input', 'input' => 'nuNumber', 'id' => 'pro_price', 'label' => 'Price', 'format' => "N|USD 1,000.00", 'align' => 'right', 'grid' => ['row' => 2, 'span' => 4]],
					['type' => 'input', 'input' => 'checkbox', 'id' => 'pro_active', 'label' => 'Active', 'w' => 20, 'h' => 20, 'grid' => ['row' => 2, 'span' => 4]],
				],
			],
			// ---------------------------------------------------- 4. orders: auto layout, audit columns, lookup, subform, PHP hooks
			[
				'id' => 'sample_order', 'type' => 'browseedit', 'table' => 'sample_order', 'desc' => 'Sample: orders', 'layout' => 'auto', 'audit' => true,
				'breadcrumb' => 'Order #ord_number#|New order',
				'browse_sql' => 'SELECT sample_order.*, sample_customer.cus_name FROM sample_order LEFT JOIN sample_customer ON sample_customer_id = ord_sample_customer_id',
				'create' => [['name' => 'ord_number', 'type' => 'text', 'length' => '20'], ['name' => 'ord_sample_customer_id', 'type' => 'id', 'length' => ''],
					['name' => 'ord_date', 'type' => 'date', 'length' => ''], ['name' => 'ord_status', 'type' => 'text', 'length' => '20'],
					['name' => 'ord_total', 'type' => 'decimal', 'length' => '15,2'], ['name' => 'ord_note', 'type' => 'longtext', 'length' => '']],
				'browse' => [['Number', 'ord_number', 'l', '', 100], ['Date', 'ord_date', 'l', 'D|dd.mm.yyyy', 110], ['Customer', 'cus_name', 'l', '', 220], ['Status', 'ord_status', 'l', '', 100], ['Total', 'ord_total', 'r', "N|USD 1,000.00", 130]],
				'objects' => [
					['type' => 'input', 'id' => 'ord_number', 'label' => 'Number', 'w' => 120, 'validate' => 2, 'top' => 20],
					['type' => 'input', 'input' => 'nuDate', 'id' => 'ord_date', 'label' => 'Date', 'w' => 110, 'format' => 'D|dd.mm.yyyy', 'left' => 470, 'top' => 20],
					['type' => 'lookup', 'id' => 'ord_sample_customer_id', 'label' => 'Customer', 'w' => 100, 'lookup_form' => 'sample_customer', 'lookup_code' => 'cus_code', 'lookup_desc' => 'cus_name', 'autocomplete' => true, 'validate' => 1, 'top' => 56],
					['type' => 'select', 'id' => 'ord_status', 'label' => 'Status', 'w' => 140, 'options' => 'new|New|confirmed|Confirmed|shipped|Shipped|paid|Paid', 'left' => 470, 'top' => 56],
					['type' => 'subform', 'id' => 'sample_items', 'label' => 'Items', 'subform' => 'sample_order_item', 'foreign_key' => 'ori_sample_order_id', 'w' => 640, 'h' => 220, 'top' => 92],
					['type' => 'calc', 'id' => 'ord_qty_total', 'label' => 'Quantity', 'formula' => "nuTotal('sample_items.ori_qty')", 'format' => 'N|1,000', 'w' => 100, 'align' => 'right', 'top' => 340],
					['type' => 'display', 'id' => 'ord_total_display', 'label' => 'Total (after save)', 'w' => 140, 'align' => 'right', 'left' => 470, 'top' => 340,
						'sql' => "SELECT CAST(COALESCE(SUM(ori_qty * ori_price), 0) AS DECIMAL(15,2)) FROM sample_order_item WHERE ori_sample_order_id = '#RECORD_ID#'"],
					['type' => 'textarea', 'id' => 'ord_note', 'label' => 'Note', 'w' => 440, 'h' => 60, 'top' => 384],
				],
				'php' => [
					'BE' => "// Before Edit: shows a message on new records\nif ('#RECORD_ID#' === '-1') nuAddJavascript(\"nuMessage(['New order: the number is suggested, the date is today.']);\");",
					'BS' => "// Before Save: the status needs the permission item sample_order_status (checked in the browser as well)\nif (!nuUserHasPermission('sample_order_status') && '#RECORD_ID#' !== '-1') {\n\t\$old = db_fetch_row(nuRunQuery('SELECT ord_status FROM sample_order WHERE sample_order_id = ?', ['#RECORD_ID#']))[0] ?? '';\n\tif (\$old !== '#ord_status#') nuDisplayError('You may not change the status (permission item sample_order_status).');\n}\n// Before Save: at least one item\n\$sf = nuSubformObject('sample_items');\n\$n = 0;\nforeach (\$sf->rows as \$i => \$r) if (empty(\$sf->deleted[\$i])) \$n++;\nif (\$n === 0) nuDisplayError('Please add at least one item.');",
					'AS' => "// After Save: stores the order total\nnuRunQuery(\"UPDATE sample_order SET ord_total = (SELECT COALESCE(SUM(ori_qty * ori_price), 0) FROM sample_order_item WHERE ori_sample_order_id = ?) WHERE sample_order_id = ?\", ['#RECORD_ID#', '#RECORD_ID#']);",
				],
				'edit_js' => "if (nuIsNewRecord()) {\n\tif (nuGetValue('ord_number') === '') nuSetValue('ord_number', 'S-' + String(Date.now()).slice(-6));\n\tif (nuGetValue('ord_date') === '') nuSetDateValue('ord_date');\n\tnuSetValue('ord_status', 'new');\n}\n// permission item: only users with sample_order_status may change the status\nif (!nuIsNewRecord() && !nuUserHasPermission('sample_order_status')) nuDisable('ord_status');",
			],
			// ---------------------------------------------------- 5. order items: grid subform
			[
				'id' => 'sample_order_item', 'type' => 'subform', 'table' => 'sample_order_item', 'desc' => 'Sample: order items',
				'create' => [['name' => 'ori_sample_order_id', 'type' => 'id', 'length' => ''], ['name' => 'ori_sample_product_id', 'type' => 'id', 'length' => ''],
					['name' => 'ori_qty', 'type' => 'decimal', 'length' => '15,2'], ['name' => 'ori_price', 'type' => 'decimal', 'length' => '15,2']],
				'objects' => [
					['type' => 'lookup', 'id' => 'ori_sample_product_id', 'label' => 'Product', 'w' => 90, 'lookup_form' => 'sample_product', 'lookup_code' => 'pro_code', 'lookup_desc' => 'pro_name', 'lookup_width' => 180, 'left' => 10, 'top' => 10,
						'oid' => 'sampleorilookup', 'ab' => "// After Browse: price and quantity of the chosen product go into the same subform row (#ROW#)\n\$p = nuLookupRecord();\nnuSetFormValue('#ROW#ori_price', \$p->pro_price ?? '');\nnuSetFormValue('#ROW#ori_qty', '1');"],
					['type' => 'input', 'input' => 'nuNumber', 'id' => 'ori_qty', 'label' => 'Qty', 'w' => 70, 'format' => 'N|1,000', 'align' => 'right', 'left' => 300, 'top' => 10],
					['type' => 'input', 'input' => 'nuNumber', 'id' => 'ori_price', 'label' => 'Price', 'w' => 110, 'format' => 'N|1,000.00', 'align' => 'right', 'left' => 380, 'top' => 10],
				],
			],
		];
	}

	/** One form with every object type nuBuilder Next knows, in several variants. */
	private static function allObjectsForm(): array
	{
		$i = static fn(string $input, string $id, string $label, array $more = []) => ['type' => 'input', 'input' => $input, 'id' => $id, 'label' => $label] + $more;
		return [
			'id' => 'sample_all', 'type' => 'browseedit', 'table' => 'sample_all', 'desc' => 'Sample: all objects', 'layout' => null,
			'breadcrumb' => '#all_text#|New record', 'tabs' => ['Inputs', 'Numbers & dates', 'Choices', 'Display', 'Run', 'Subform', 'Access', 'Charts', 'Miscellaneous', 'Upload'],
			'browse' => [['Text', 'all_text', 'l', '', 200], ['Number', 'all_nunumber', 'r', "N|USD 1,000.00", 120], ['Date', 'all_nudate', 'l', 'D|dd.mm.yyyy', 110], ['Select', 'all_select', 'l', '', 120], ['Checkbox', 'all_checkbox', 'c', '', 80]],
			'objects' => [
				// --- Inputs
				$i('text', 'all_text', 'Text', ['w' => 300, 'validate' => 1]),
				$i('text', 'all_datalist', 'Text with datalist', ['datalist' => '["Apple","Banana","Cherry"]']),
				$i('text', 'all_mask', 'Text mask (T|)', ['format' => 'T|AA99 9999 9999 9', 'w' => 200]),
				$i('password', 'all_password', 'Password'),
				$i('email', 'all_email', 'E-mail'),
				$i('url', 'all_url', 'URL'),
				$i('tel', 'all_tel', 'Phone (tel)'),
				$i('search', 'all_search', 'Search'),
				$i('color', 'all_color', 'Colour', ['w' => 60]),
				$i('checkbox', 'all_checkbox', 'Checkbox', ['w' => 20, 'h' => 20]),
				$i('file', 'all_file', 'File'),
				$i('nuScroll', 'all_scroll', 'nuScroll (arrow keys)', ['scroll' => "['Low','Medium','High']", 'w' => 140]),
				$i('button', 'all_button', 'Input button', ['w' => 140, 'events' => ['onclick' => "nuMessage(['Button clicked', 'Text is: ' + nuGetValue('all_text')]);"]]),
				$i('text', 'all_attribute', 'With attributes', ['attribute' => 'placeholder="max. 10 characters",maxlength="10"']),
				// --- Numbers & dates
				$i('number', 'all_number', 'number', ['tab' => 1, 'w' => 120, 'align' => 'right']),
				$i('nuNumber', 'all_nunumber', "nuNumber USD 1,000.00", ['tab' => 1, 'format' => "N|USD 1,000.00", 'w' => 140, 'align' => 'right']),
				$i('nuNumber', 'all_nunumber_eur', 'nuNumber € 1.000,00', ['tab' => 1, 'format' => 'N|€ 1.000,00', 'w' => 140, 'align' => 'right']),
				$i('nuNumber', 'all_percent', 'nuNumber 1000.0 %', ['tab' => 1, 'format' => 'N|1000.0 %', 'w' => 110, 'align' => 'right']),
				['tab' => 1, 'type' => 'calc', 'id' => 'all_calc', 'label' => 'calc (USD + EUR)', 'formula' => "nuTotal('all_nunumber') + nuTotal('all_nunumber_eur')", 'format' => "N|USD 1,000.00", 'w' => 140, 'align' => 'right'],
				$i('range', 'all_range', 'range', ['tab' => 1, 'w' => 160]),
				$i('nuAutoNumber', 'all_autonumber', 'nuAutoNumber', ['tab' => 1, 'w' => 100, 'count' => '1002']),
				$i('nuDate', 'all_nudate', 'nuDate dd.mm.yyyy', ['tab' => 1, 'format' => 'D|dd.mm.yyyy', 'w' => 110]),
				$i('nuDate', 'all_nudate_long', 'nuDate (long)', ['tab' => 1, 'format' => 'D|dddd, dd mmmm yyyy', 'w' => 230]),
				$i('nuDate', 'all_nudatetime', 'nuDate with time', ['tab' => 1, 'format' => 'D|dd.mm.yyyy hh:nn', 'w' => 150]),
				$i('date', 'all_date', 'date (browser)', ['tab' => 1, 'w' => 150]),
				$i('datetime-local', 'all_datetime', 'datetime-local', ['tab' => 1, 'w' => 200]),
				$i('time', 'all_time', 'time', ['tab' => 1, 'w' => 120]),
				$i('month', 'all_month', 'month', ['tab' => 1, 'w' => 160]),
				$i('week', 'all_week', 'week', ['tab' => 1, 'w' => 160]),
				// --- Choices
				['tab' => 2, 'type' => 'select', 'id' => 'all_select', 'label' => 'Select (list)', 'options' => 'a|Option A|b|Option B|c|Option C', 'events' => ['onchange' => "nuToast('Selected: ' + nuGetText('all_select'));"]],
				['tab' => 2, 'type' => 'select', 'id' => 'all_select_sql', 'label' => 'Select (SQL)', 'options' => 'SELECT sample_customer_id, cus_name FROM sample_customer ORDER BY cus_name'],
				['tab' => 2, 'type' => 'select', 'id' => 'all_select_multi', 'label' => 'Select (multiple)', 'multiple' => true, 'h' => 50, 'options' => 'red|Red|green|Green|blue|Blue|yellow|Yellow'],
				['tab' => 2, 'type' => 'select', 'id' => 'all_select2', 'label' => 'Select2 (search)', 'select2' => true, 'options' => 'SELECT pro_code, pro_name FROM sample_product ORDER BY pro_name'],
				['tab' => 2, 'type' => 'lookup', 'id' => 'all_lookup', 'label' => 'Lookup', 'w' => 100, 'lookup_form' => 'sample_customer', 'lookup_code' => 'cus_code', 'lookup_desc' => 'cus_name'],
				['tab' => 2, 'type' => 'lookup', 'id' => 'all_lookup_auto', 'label' => 'Lookup (autocomplete)', 'w' => 100, 'lookup_form' => 'sample_product', 'lookup_code' => 'pro_code', 'lookup_desc' => 'pro_name', 'autocomplete' => true],
				['tab' => 2, 'type' => 'textarea', 'id' => 'all_textarea', 'label' => 'Textarea'],
				['tab' => 2, 'type' => 'editor', 'id' => 'all_editor', 'label' => 'Editor (TinyMCE)', 'w' => 500, 'h' => 240],
				// --- Display
				['tab' => 3, 'type' => 'contentbox', 'id' => 'all_box', 'label' => 'Content box', 'left' => 20, 'top' => 20, 'w' => 640, 'h' => 130],
				['tab' => 3, 'type' => 'display', 'id' => 'all_display', 'label' => 'Display (SQL)', 'top' => 50, 'w' => 260, 'sql' => "SELECT CONCAT(COUNT(*), ' customers') FROM sample_customer"],
				['tab' => 3, 'type' => 'word', 'id' => 'all_word', 'label' => 'Word: a plain label', 'top' => 86, 'w' => 260],
				['tab' => 3, 'type' => 'html', 'id' => 'all_html', 'label' => 'HTML', 'top' => 170, 'w' => 500, 'h' => 100,
					'html' => '<div class="alert alert-info mb-0 py-2">HTML object with hash variables: user <b>#USER_ID#</b>, record <b>#RECORD_ID#</b>, new record <b>#NEW_RECORD#</b>, global hash cookie <code>&#35;sample_colour&#35;</code> = <b>#sample_colour#</b>.</div>'],
				['tab' => 3, 'type' => 'image', 'id' => 'all_image', 'label' => 'Image', 'file' => 'sample_file_logo', 'top' => 284],
				['tab' => 3, 'type' => 'input', 'input' => 'text', 'id' => 'all_styled', 'label' => 'Styled (CSS)', 'top' => 366, 'style_type' => 'CSS', 'style' => 'background-color:#fff3cd;font-weight:bold;'],
				['tab' => 3, 'type' => 'display', 'id' => 'all_display_hash', 'label' => 'Hash variables', 'top' => 402, 'w' => 460,
					'sql' => "SELECT '#nu_date# · #OBJECT_ID# · home #HOME_ID# · #LANGUAGE# · dev #DEV_MODE#'"],
				// --- Run (tab 4) · Subform (tab 5)
				['tab' => 4, 'type' => 'run', 'id' => 'all_run_form', 'label' => 'Run: form (browse)', 'run' => 'sample_customer', 'w' => 200],
				['tab' => 4, 'type' => 'run', 'id' => 'all_run_record', 'label' => 'Run: new record', 'run' => 'sample_order', 'run_id' => '-1', 'w' => 200],
				['tab' => 4, 'type' => 'run', 'id' => 'all_run_proc', 'label' => 'Run: procedure', 'run' => 'sample_proc_hello', 'run_type' => 'P', 'w' => 200],
				['tab' => 4, 'type' => 'run', 'id' => 'all_run_report', 'label' => 'Run: report (PDF)', 'run' => 'sample_rpt_customers', 'run_type' => 'R', 'w' => 200],
				['tab' => 4, 'type' => 'run', 'id' => 'all_iframe', 'label' => 'Run: iframe (products)', 'run' => 'sample_product', 'method' => 'i', 'w' => 640, 'h' => 300],
				['tab' => 5, 'type' => 'subform', 'id' => 'sample_all_lines', 'label' => 'Subform (form layout)', 'subform' => 'sample_all_line', 'foreign_key' => 'sal_sample_all_id', 'sf_type' => 'f', 'w' => 640, 'h' => 300, 'top' => 50],
				// --- Access
				$i('text', 'all_readonly', 'Read only (access 1)', ['tab' => 6, 'access' => 1]),
				$i('text', 'all_hidden', 'Hidden (access 2)', ['tab' => 6, 'access' => 2]),
				$i('text', 'all_admin_only', 'Globeadmin only (access 3)', ['tab' => 6, 'access' => 3]),
				$i('text', 'all_unique', 'No duplicates (validate 2)', ['tab' => 6, 'validate' => 2]),
				// --- Miscellaneous (tooltips, info bar, button spinner) and Charts
				['tab' => 8, 'type' => 'html', 'id' => 'all_new_intro', 'label' => '', 'left' => 20, 'top' => 20, 'w' => 660, 'h' => 112,
					'html' => '<div class="alert alert-secondary small mb-0 py-2"><b>Also new:</b> <b>Inspect record</b> – options menu <i class="bi bi-three-dots-vertical"></i> → Inspect record (globeadmin): all columns of this record with type and value, in a new tab. '
						. '<b>Attribute editor</b> – in the builder select an object and click <i class="bi bi-sliders"></i> next to “HTML attributes”. '
						. '<b>Ctrl+Shift+S</b> saves on nuBuilder Next\'s own edit pages too.</div>'],
				['tab' => 8, 'type' => 'html', 'id' => 'all_new_h_tip', 'label' => '', 'left' => 20, 'top' => 146, 'w' => 660, 'h' => 26, 'html' => '<h6 class="fw-semibold mb-0 border-bottom pb-1">Tooltip icons – attribute nu-tooltip-icon</h6>'],
				$i('text', 'all_tip_text', 'Code', ['tab' => 8, 'top' => 182, 'w' => 200, 'attribute' => 'nu-tooltip-icon="<b>Code</b><br>4–20 letters or digits, e.g. <code>AB12</code>."']),
				$i('text', 'all_tip_theme', 'Theme blue, right', ['tab' => 8, 'top' => 218, 'w' => 200, 'attribute' => 'nu-tooltip-icon="Tooltip on the right in the blue theme.", nu-tooltip-position="right", nu-tooltip-theme="blue"']),
				['tab' => 8, 'type' => 'select', 'id' => 'all_tip_select', 'label' => 'nuOnToolTipIcon', 'top' => 254, 'w' => 200, 'options' => 'a|Normal|b|Urgent',
					'attribute' => 'nu-tooltip-icon="Icon, colour and size changed in nuOnToolTipIcon(); a click calls onClick.", nu-tooltip-position="bottom"'],
				$i('text', 'all_tip_field', 'Tooltip on the field', ['tab' => 8, 'top' => 290, 'w' => 200, 'attribute' => 'nu-tooltip="nu-tooltip: the tooltip is on the field itself, no icon.", placeholder="Point at me"']),
				['tab' => 8, 'type' => 'html', 'id' => 'all_new_h_bar', 'label' => '', 'left' => 20, 'top' => 334, 'w' => 660, 'h' => 26, 'html' => '<h6 class="fw-semibold mb-0 border-bottom pb-1">Info bar – $.nuInfoBar</h6>'],
				$i('button', 'all_ib_types', 'Info / success / warning / error', ['tab' => 8, 'left' => 20, 'top' => 370, 'w' => 220, 'h' => 30, 'events' => ['onclick' =>
					"['info', 'success', 'warning', 'error'].forEach(function (t, n) {\n\tsetTimeout(function () { $.nuInfoBar[t]('Message of type <b>' + t + '</b>.', {allowMultiple: true, autoCloseDelay: 4000 + n * 500}); }, n * 250);\n});"]]),
				$i('button', 'all_ib_dark', 'Dark, shake, no auto-close', ['tab' => 8, 'left' => 250, 'top' => 370, 'w' => 210, 'h' => 30, 'events' => ['onclick' =>
					"$.nuInfoBar.error('Connection failed!', {theme: 'dark', autoClose: false, shakeOnShow: true, shakeIntensity: 8});"]]),
				$i('button', 'all_ib_confirm', 'Yes / No buttons', ['tab' => 8, 'left' => 470, 'top' => 370, 'w' => 210, 'h' => 30, 'events' => ['onclick' =>
					"$.nuInfoBar({\n\thtml: '<i class=\"bi bi-question-circle\"></i> Do you want to continue? <button id=\"ibYes\" class=\"nuActionButton\">Yes</button> <button id=\"ibNo\" class=\"nuActionButton\">No</button>',\n\ttype: 'warning', autoClose: false, showCloseButton: false,\n\tonShow: function () {\n\t\t$('#ibYes').on('click', function () { $.nuInfoBar.closeAll(); nuToast('You clicked Yes', 'success'); });\n\t\t$('#ibNo').on('click', function () { $.nuInfoBar.closeAll(); nuToast('You clicked No'); });\n\t}\n});"]]),
				$i('button', 'all_ib_modal', 'Modal (click outside = shake)', ['tab' => 8, 'left' => 20, 'top' => 408, 'w' => 220, 'h' => 30, 'events' => ['onclick' =>
					"$.nuInfoBar.modal.warning('Please confirm before you continue. Close with the X or Escape.');"]]),
				$i('button', 'all_ib_bottom', 'Bottom, fade, light', ['tab' => 8, 'left' => 250, 'top' => 408, 'w' => 210, 'h' => 30, 'events' => ['onclick' =>
					"$.nuInfoBar.success('Saved at the bottom of the window.', {position: 'bottom', animationType: 'fade', autoCloseDelay: 3000});"]]),
				$i('button', 'all_ib_close', 'Close all', ['tab' => 8, 'left' => 470, 'top' => 408, 'w' => 210, 'h' => 30, 'events' => ['onclick' => "$.nuInfoBar.closeAll();"]]),
				['tab' => 8, 'type' => 'html', 'id' => 'all_new_h_spin', 'label' => '', 'left' => 20, 'top' => 456, 'w' => 660, 'h' => 26, 'html' => '<h6 class="fw-semibold mb-0 border-bottom pb-1">Button spinner – nuButtonLoading()</h6>'],
				$i('button', 'all_spin_auto', 'Spinner, enabled after 3 s', ['tab' => 8, 'left' => 20, 'top' => 492, 'w' => 220, 'h' => 30, 'events' => ['onclick' => "nuButtonLoading('all_spin_auto', true, 3);"]]),
				$i('button', 'all_spin_wait', 'Run a task (2 s)', ['tab' => 8, 'left' => 250, 'top' => 492, 'w' => 210, 'h' => 30, 'events' => ['onclick' =>
					"nuButtonLoading('all_spin_wait', true);\nsetTimeout(function () {\n\tnuButtonLoading('all_spin_wait', false);\n\t$.nuInfoBar.success('Task finished.', {autoCloseDelay: 2500});\n}, 2000);"]]),
				['tab' => 8, 'type' => 'run', 'id' => 'all_spin_run', 'label' => 'Procedure with spinner', 'run' => 'sample_proc_hello', 'run_type' => 'P', 'left' => 470, 'top' => 492, 'w' => 210,
					'events' => ['onclick' => "nuButtonLoading('all_spin_run', true, 2);"]],
				['tab' => 7, 'type' => 'html', 'id' => 'all_new_h_chart', 'label' => '', 'left' => 20, 'top' => 20, 'w' => 660, 'h' => 26, 'html' => '<h6 class="fw-semibold mb-0 border-bottom pb-1">Charts – object type chart (Google Charts)</h6>'],
				['tab' => 7, 'type' => 'chart', 'id' => 'all_chart_bars', 'label' => '', 'left' => 20, 'top' => 56, 'w' => 400, 'h' => 240, 'chart' => 'b', 'chart_data' => 'sampleMonths',
					'chart_title' => 'Sales and costs (array in the form JavaScript)', 'chart_h' => 'Month', 'chart_v' => 'USD'],
				['tab' => 7, 'type' => 'chart', 'id' => 'all_chart_pie', 'label' => '', 'left' => 430, 'top' => 56, 'w' => 250, 'h' => 240, 'chart' => 'p', 'chart_data' => 'sampleOrderStatus',
					'chart_title' => 'Orders per status (database)'],
				['tab' => 7, 'type' => 'chart', 'id' => 'all_chart_team', 'label' => '', 'left' => 20, 'top' => 310, 'w' => 660, 'h' => 200, 'chart' => 'bhs',
					'chart_data' => "[['Team', 'Done', 'Open'], ['Sales', 8, 3], ['Support', 5, 6], ['Admin', 2, 1]]", 'chart_title' => 'Tasks per team (array in the object, stacked)', 'chart_h' => 'Tasks'],
				// --- Upload: nuBuilder's file object with target "File System" (Uppy, 0.8.506)
				['tab' => 9, 'type' => 'html', 'id' => 'all_upload_intro', 'label' => '', 'left' => 20, 'top' => 20, 'w' => 660, 'h' => 150,
					'html' => '<div class="alert alert-secondary small mb-0 py-2"><b>Upload to the server</b> – a <i>File</i> object with <i>File target</i> = <b>File System</b>: '
						. 'the files go into a folder on the server, not into the record. nuBuilder\'s procedure <code>nu_upload_file_template</code> stores them in '
						. '<code>nu_uploads/</code> next to the web root (a second file with the same name gets <code>-1</code>, <code>-2</code> …; .php, .html and other '
						. 'dangerous types are refused). Copy the procedure to change the folder or the allowed types. Needs the library pack <b>Uppy</b> '
						. '(Update → Libraries); without it the form shows a notice.</div>'],
				['tab' => 9, 'type' => 'input', 'input' => 'file', 'id' => 'all_upload', 'label' => '', 'left' => 20, 'top' => 190, 'w' => 660, 'h' => 320,
					'target' => '1', 'html' => NuUpload::HTML],
			],
			'edit_js' => "// sfo_edit_javascript runs when the edit form opens\nif (nuIsNewRecord()) nuSetValue('all_text', 'New sample record');\n\n"
				. "// tooltip icons: own settings for one field (tab Miscellaneous)\nwindow.nuOnToolTipIcon = function (element, settings) {\n\tif (element.id === 'all_tip_select') {\n"
				. "\t\tsettings.theme = 'red';\n\t\tsettings.iconSize = 20;\n\t\tsettings.iconClasses = 'bi bi-exclamation-triangle-fill';\n"
				. "\t\tsettings.onClick = function (el, text) { nuMessage(['Tooltip text', text]); };\n\t}\n};\n\n"
				. "// data of the chart all_chart_bars (first row = column titles)\nwindow.sampleMonths = [['Month', 'Sales', 'Costs'], ['Jan', 120, 80], ['Feb', 140, 95], ['Mar', 110, 70], ['Apr', 165, 100], ['May', 150, 90]];",
			'php' => ['BS' => "// Before Save\nif (strlen('#all_text#') > 100) nuDisplayError('Text: at most 100 characters.');",
				'BE' => "// Before Edit: data of the chart all_chart_pie from the database\n\$rows = [['Status', 'Orders']];\n"
					. "\$s = nuRunQuery('SELECT ord_status, COUNT(*) FROM sample_order GROUP BY ord_status ORDER BY ord_status');\n"
					. "while (\$r = db_fetch_row(\$s)) \$rows[] = [(string)(\$r[0] ?: '–'), (int)\$r[1]];\n"
					. "nuAddJavascript('window.sampleOrderStatus = ' . json_encode(\$rows) . ';');"],
		];
	}

	private static function allLineForm(): array
	{
		return [
			'id' => 'sample_all_line', 'type' => 'subform', 'table' => 'sample_all_line', 'desc' => 'Sample: all objects – lines',
			'objects' => [
				['type' => 'input', 'input' => 'text', 'id' => 'sal_text', 'label' => 'Line', 'w' => 260, 'left' => 10, 'top' => 10],
				['type' => 'input', 'input' => 'nuNumber', 'id' => 'sal_qty', 'label' => 'Qty', 'w' => 80, 'format' => 'N|1,000', 'align' => 'right', 'left' => 280, 'top' => 10],
				['type' => 'input', 'input' => 'checkbox', 'id' => 'sal_done', 'label' => 'Done', 'w' => 20, 'h' => 20, 'left' => 370, 'top' => 10],
			],
		];
	}

	// ------------------------------------------------------------------ rows

	private static function data(): array
	{
		$out = [];
		$customers = [
			['C001', 'Bluepeak Systems', 'US', 50000], ['C002', 'Maple Ridge Supply', 'CA', 25000], ['C003', 'Harbour Lane Ltd', 'GB', 15000], ['C004', 'Coral Bay Imports', 'AU', 30000],
			['C005', 'Brightwater Labs', 'US', 20000], ['C006', 'Ironwood Manufacturing Inc', 'US', 75000], ['C007', 'Silverline Logistics Ltd', 'GB', 10000], ['C008', 'Sunfield Organics', 'AU', 5000],
			['C009', 'Northgate Analytics', 'CA', 40000], ['C010', 'Café Aurora Ltd', 'GB', 12000], ['C011', 'Redstone Printing', 'US', 8000], ['C012', 'Eastwind Trading', 'DE', -2500],
		];
		foreach ($customers as $n => [$code, $name, $country, $credit]) {
			$out[] = ['sample_customer', ['sample_customer_id' => sprintf('sample_cus_%02d', $n + 1), 'cus_code' => $code, 'cus_name' => $name,
				'cus_email' => strtolower(preg_replace('/[^a-z]/i', '', explode(' ', $name)[0])) . '@example.com', 'cus_phone' => '+1 555 ' . sprintf('%03d', 110 + $n) . ' ' . sprintf('%04d', 2000 + $n),
				'cus_country' => $country, 'cus_active' => $n !== 10, 'cus_since' => date('Y-m-d', strtotime('-' . (30 + $n * 97) . ' days')), 'cus_credit' => $credit,
				'cus_notes' => $n === 0 ? 'Key account. Delivery only on weekdays.' : null]];
		}
		$products = [['P100', 'Laptop 14"', 'Hardware', 1290], ['P101', 'Monitor 27"', 'Hardware', 389.9], ['P102', 'Docking station', 'Hardware', 249],
			['P200', 'Office licence', 'Software', 149], ['P201', 'Antivirus (1 year)', 'Software', 39.5], ['P300', 'Installation per hour', 'Service', 140],
			['P301', 'Support package', 'Service', 990], ['P400', 'nuBuilder workshop', 'Training', 1800]];
		foreach ($products as $n => [$code, $name, $cat, $price]) {
			$out[] = ['sample_product', ['sample_product_id' => 'sample_pro_' . ($n + 1), 'pro_code' => $code, 'pro_name' => $name, 'pro_category' => $cat, 'pro_price' => $price, 'pro_active' => $code !== 'P201']];
		}
		$orders = [[1, 'confirmed', [[1, 2], [2, 2], [6, 3]]], [2, 'shipped', [[4, 10]]], [6, 'paid', [[1, 5], [3, 5], [7, 1]]], [4, 'new', [[8, 1]]], [9, 'confirmed', [[2, 4], [5, 4]]], [1, 'paid', [[6, 8]]]];
		$it = 0;
		foreach ($orders as $n => [$cus, $status, $items]) {
			$oid = 'sample_ord_' . ($n + 1);
			$total = 0.0;
			foreach ($items as [$pro, $qty]) {
				$price = (float)$products[$pro - 1][3];
				$total += $price * $qty;
				$out[] = ['sample_order_item', ['sample_order_item_id' => 'sample_ori_' . (++$it), 'ori_sample_order_id' => $oid, 'ori_sample_product_id' => 'sample_pro_' . $pro, 'ori_qty' => $qty, 'ori_price' => $price]];
			}
			$out[] = ['sample_order', ['sample_order_id' => $oid, 'ord_number' => 'S-' . (1001 + $n), 'ord_sample_customer_id' => sprintf('sample_cus_%02d', $cus),
				'ord_date' => date('Y-m-d', strtotime('-' . (60 - $n * 9) . ' days')), 'ord_status' => $status, 'ord_total' => round($total, 2), 'ord_note' => null]];
		}
		$out[] = ['sample_all', ['sample_all_id' => 'sample_all_1', 'all_text' => 'First sample record', 'all_autonumber' => 1001, 'all_datalist' => 'Banana', 'all_mask' => 'AB12 3456 7890 1',
			'all_email' => 'anna@example.com', 'all_url' => 'https://www.nubuilder.cloud', 'all_tel' => '+1 555 123 4567', 'all_color' => '#2f6fd6', 'all_checkbox' => 1, 'all_scroll' => 'Medium',
			'all_number' => 42, 'all_nunumber' => 1234.5, 'all_nunumber_eur' => -99.95, 'all_percent' => 12.5, 'all_calc' => 1134.55, 'all_range' => 30,
			'all_nudate' => date('Y-m-d'), 'all_nudate_long' => date('Y-m-d'), 'all_nudatetime' => date('Y-m-d H:i:00'), 'all_date' => date('Y-m-d'), 'all_datetime' => date('Y-m-d 08:30:00'), 'all_time' => '13:45:00',
			'all_month' => date('Y-m'), 'all_week' => date('o-\WW'),
			'all_select' => 'b', 'all_select_sql' => 'sample_cus_02', 'all_select_multi' => json_encode(['red', 'blue']), 'all_select2' => 'P101', 'all_lookup' => 'sample_cus_01', 'all_lookup_auto' => 'sample_pro_2',
			'all_textarea' => "Line 1\nLine 2", 'all_editor' => '<p>Some <b>formatted</b> text.</p>', 'all_styled' => 'Styled text',
			'all_readonly' => 'Cannot be changed', 'all_hidden' => 'Hidden value', 'all_admin_only' => 'Only the globeadmin sees this', 'all_unique' => 'unique-1']];
		$out[] = ['sample_all', ['sample_all_id' => 'sample_all_2', 'all_text' => 'Second sample record', 'all_autonumber' => 1002, 'all_nunumber' => -500, 'all_nudate' => date('Y-m-d', strtotime('-1 year')), 'all_select' => 'c', 'all_unique' => 'unique-2']];
		foreach ([['First line', 3, 1], ['Second line', 1, 0]] as $n => [$t, $q, $d]) {
			$out[] = ['sample_all_line', ['sample_all_line_id' => 'sample_sal_' . ($n + 1), 'sal_sample_all_id' => 'sample_all_1', 'sal_text' => $t, 'sal_qty' => $q, 'sal_done' => $d]];
		}
		return $out;
	}

	/**
	 * Start pages of users (0.8.425) – three ways to build one; sample_viewer and sample_secure keep the automatic page (their forms and
	 * reports). Only access levels of the sample set: the default for all access levels ("*") would change the start page of real users.
	 *   sample_sales   "Sales desk": cards, favourites row, a new-order tile (record -1), a report, a group only for the permission item
	 *                  sample_discount (Dario has it)
	 *   sample_manager "Manager": two tabs (Daily work, Insights), group colours, a procedure tile, a group only for team "Lead" (Carla)
	 *   sample_editor  "Data entry": a list in two columns, "new record" tiles, a report only for positions Clerk / Manager, the help page
	 */
	private static function userHomes(): array
	{
		$f = static fn(string $label, string $form, string $icon, string $hint = '', string $record = '', string $color = '', array $cond = []) => array_filter([
			'label' => $label, 'hint' => $hint, 'icon' => $icon, 'color' => $color, 'kind' => 'form', 'target' => self::fid($form), 'record' => $record,
			'run' => 'window', 'newtab' => false, 'cond' => $cond], static fn($v) => $v !== []);
		$rep = static fn(string $label, string $id, string $hint = '', array $cond = []) => array_filter(['label' => $label, 'hint' => $hint, 'icon' => 'bi-file-earmark-richtext',
			'color' => '', 'kind' => 'report', 'target' => $id, 'record' => '', 'run' => 'window', 'newtab' => true, 'cond' => $cond], static fn($v) => $v !== []);
		$g = static function (string $id, string $title, array $tiles, string $tab = '', array $cond = [], string $color = ''): array {
			$o = ['id' => $id, 'title' => $title, 'tab' => $tab, 'tiles' => $tiles];
			if ($cond) $o['cond'] = $cond;
			if ($color !== '') $o['color'] = $color;
			return $o;
		};
		$pages = [
			'sample_sales' => ['Sample: sales desk', [
				'display' => 'groups', 'view' => ['style' => 'cards', 'favStrip' => true, 'desc' => 'inline'],
				'custom' => [
					'c_neworder' => $f('New order', 'sample_order', 'bi-cart-plus', 'Start a new order', '-1', '#0f8f86'),
					'c_orders' => $f('Orders', 'sample_order', 'bi-receipt', 'All orders – open, paid, shipped'),
					'c_customers' => $f('Customers', 'sample_customer', 'bi-people', 'Look up a customer (view only)'),
					'c_rptorders' => $rep('Orders by customer', 'sample_rpt_orders', 'PDF report with sums per customer'),
					'c_products' => $f('Products and prices', 'sample_product', 'bi-tags', 'Only with the permission item sample_discount'),
				],
				'groups' => [$g('g_today', 'Today', ['c_neworder', 'c_orders', 'c_customers']), $g('g_reports', 'Reports', ['c_rptorders']),
					$g('g_pricing', 'Pricing', ['c_products'], '', ['permission' => ['sample_discount']])],
				'fav' => ['c_neworder', 'c_orders']]],
			'sample_manager' => ['Sample: manager', [
				'display' => 'tabs', 'view' => ['style' => 'large', 'nav' => 'tabs', 'groupColors' => true],
				'tabs' => [['id' => 't_daily', 'title' => 'Daily work'], ['id' => 't_insights', 'title' => 'Insights']],
				'custom' => [
					'c_customers' => $f('Customers', 'sample_customer', 'bi-people', 'Customers with their orders and statistics'),
					'c_newcust' => $f('New customer', 'sample_customer', 'bi-person-plus', '', '-1'),
					'c_orders' => $f('Orders', 'sample_order', 'bi-receipt', 'Orders with their items'),
					'c_products' => $f('Products', 'sample_product', 'bi-box-seam', 'The product catalogue'),
					'c_allobj' => $f('All object types', 'sample_all', 'bi-ui-checks-grid', 'Every nuBuilder object type in one form'),
					'c_rptcust' => $rep('Customers by country', 'sample_rpt_customers'),
					'c_rptorders' => $rep('Orders by customer', 'sample_rpt_orders'),
					'c_hello' => ['label' => 'Run a procedure', 'hint' => 'sample_hello – output in a new tab', 'icon' => 'bi-play-circle', 'color' => '', 'kind' => 'procedure',
						'target' => 'sample_proc_hello', 'record' => '', 'run' => 'window', 'newtab' => false],
					'c_notify' => ['label' => 'Notifications', 'hint' => 'Messages to the team', 'icon' => 'bi-bell', 'color' => '', 'kind' => 'page', 'target' => 'notifications',
						'record' => '', 'run' => 'window', 'newtab' => false],
				],
				'groups' => [$g('g_sales', 'Sales', ['c_customers', 'c_newcust', 'c_orders'], 't_daily'), $g('g_catalogue', 'Catalogue', ['c_products', 'c_allobj'], 't_daily'),
					$g('g_reports', 'Reports', ['c_rptcust', 'c_rptorders'], 't_insights'),
					$g('g_lead', 'Team lead', ['c_hello', 'c_notify'], 't_insights', ['team' => ['Lead']], '#c2417a')],
				'fav' => []]],
			'sample_editor' => ['Sample: data entry', [
				'display' => 'groups', 'view' => ['style' => 'list', 'listCols' => '2', 'desc' => 'inline'],
				'custom' => [
					'c_newcust' => $f('New customer', 'sample_customer', 'bi-person-plus', '', '-1', '#2f6fd6'),
					'c_neworder' => $f('New order', 'sample_order', 'bi-cart-plus', '', '-1', '#2f6fd6'),
					'c_newprod' => $f('New product', 'sample_product', 'bi-plus-square', '', '-1', '#2f6fd6'),
					'c_customers' => $f('Customers', 'sample_customer', 'bi-people'),
					'c_orders' => $f('Orders', 'sample_order', 'bi-receipt'),
					'c_products' => $f('Products', 'sample_product', 'bi-box-seam'),
					'c_rptcust' => $rep('Customers by country', 'sample_rpt_customers', 'Only for the positions Clerk and Manager', ['position' => ['Clerk', 'Manager']]),
					'c_help' => ['label' => 'Help', 'hint' => 'How to use nuBuilder Next', 'icon' => 'bi-question-circle', 'color' => '', 'kind' => 'page', 'target' => 'help',
						'record' => '', 'run' => 'window', 'newtab' => true],
				],
				'groups' => [$g('g_enter', 'Enter', ['c_newcust', 'c_neworder', 'c_newprod']), $g('g_lookup', 'Look up', ['c_customers', 'c_orders', 'c_products']),
					$g('g_more', 'More', ['c_rptcust', 'c_help'])],
				'fav' => []]],
		];
		$rows = [];
		$now = date('Y-m-d H:i:s');
		$i = 0;
		foreach ($pages as $access => [$name, $l]) {
			$def = $l + ['v' => NuHome::VERSION, 'size' => 'large', 'tabs' => [], 'hidden' => []];
			$rows[] = ['zzzzsys_nunext_list_view', ['zzzzsys_nunext_list_view_id' => 'sample_uh_' . substr($access, 7), 'list_key' => NuHome::LIST_USER, 'scope' => 'shared', 'owner' => '',
				'name' => $name, 'access_ids' => json_encode([$access]), 'definition' => json_encode(NuHome::clean($def, false)[0], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
				'sort_order' => ++$i, 'updated_at' => $now, 'updated_by' => 'sample']];
		}
		return $rows;
	}

	/**
	 * Four dashboards (0.8.299): sample_sales and sample_products on the start page of the sample access levels, sample_customer on the
	 * customer form (tab Statistics, #RECORD_ID#), sample_country on the start form (launch form field #home_country# as criterion).
	 * SQL that runs on MySQL and SQL Server.
	 */
	private static function dashboards(): array
	{
		$rows = [];
		$now = date('Y-m-d H:i:s');
		$dash = static function (string $code, string $title, string $desc, bool $home, array $access, string $range, int $sort, array $widgets) use (&$rows, $now): void {
			$id = 'sample_dash_' . substr($code, 7);
			$rows[] = ['zzzzsys_nunext_dashboard', ['zzzzsys_nunext_dashboard_id' => $id, 'code' => $code, 'title' => $title, 'description' => $desc, 'on_home' => $home ? '1' : '0',
				'access' => json_encode($access), 'date_range' => $range, 'sort_order' => $sort, 'updated_at' => $now, 'updated_by' => 'sample']];
			foreach ($widgets as $n => [$type, $title2, $source, $query, $opt]) {
				$rows[] = ['zzzzsys_nunext_widget', ['zzzzsys_nunext_widget_id' => $id . '_w' . ($n + 1), 'dashboard_id' => $id, 'sort_order' => ($n + 1) * 10, 'type' => $type,
					'title' => $title2, 'source' => $source, 'query' => $query, 'options' => json_encode($opt, JSON_UNESCAPED_SLASHES)]];
			}
		};
		$inRange = "o.ord_date BETWEEN '#DATE_FROM#' AND '#DATE_TO#'";
		$usd = ['prefix' => 'USD', 'decimals' => 2];
		$dash('sample_sales', 'Sales', 'Sample: start page of sample_manager and sample_sales – KPIs, charts, latest orders, date filter', true, ['sample_manager', 'sample_sales'], '90d', 10, [
			['kpi', 'Orders', 'sql', "SELECT COUNT(*) FROM sample_order o WHERE $inRange", ['width' => 3, 'low' => 2, 'high' => 5]],
			['kpi', 'Revenue', 'sql', "SELECT COALESCE(SUM(o.ord_total), 0) FROM sample_order o WHERE $inRange", ['width' => 3] + $usd],
			['kpi', 'Average order', 'sql', "SELECT AVG(CASE WHEN $inRange THEN o.ord_total END), AVG(o.ord_total), 'compared with all orders' FROM sample_order o", ['width' => 3] + $usd],
			['kpi', 'Open orders', 'sql', "SELECT COUNT(*), NULL, 'status new or confirmed' FROM sample_order WHERE ord_status IN ('new', 'confirmed')", ['width' => 3, 'low' => 1, 'high' => 3, 'higher_better' => false, 'link_type' => 'form', 'link' => self::fid('sample_order')]],
			['chart', 'Revenue by day', 'sql', "SELECT o.ord_date AS Day, SUM(o.ord_total) AS Revenue FROM sample_order o WHERE $inRange GROUP BY o.ord_date ORDER BY o.ord_date", ['width' => 8, 'chart' => 'bar', 'height' => 260] + $usd],
			['chart', 'Orders by status', 'sql', "SELECT o.ord_status, COUNT(*) FROM sample_order o WHERE $inRange GROUP BY o.ord_status", ['width' => 4, 'chart' => 'donut', 'height' => 260]],
			['chart', 'Top products', 'sql', "SELECT p.pro_name, SUM(i.ori_qty * i.ori_price) AS Revenue FROM sample_order_item i JOIN sample_product p ON p.sample_product_id = i.ori_sample_product_id"
				. " JOIN sample_order o ON o.sample_order_id = i.ori_sample_order_id WHERE $inRange GROUP BY p.pro_name ORDER BY 2 DESC", ['width' => 6, 'chart' => 'barh', 'height' => 280] + $usd],
			['table', 'Latest orders', 'sql', "SELECT o.ord_number AS Number, c.cus_name AS Customer, o.ord_date AS Date, o.ord_status AS Status, o.ord_total AS Total FROM sample_order o"
				. " JOIN sample_customer c ON c.sample_customer_id = o.ord_sample_customer_id WHERE $inRange ORDER BY o.ord_date DESC", ['width' => 6, 'rows' => 6, 'decimals' => 2, 'link_type' => 'form', 'link' => self::fid('sample_order')]],
		]);
		$dash('sample_products', 'Products', 'Sample: start page of every sample access level – data from SQL, a SQL builder query and a PHP procedure', true,
			['sample_manager', 'sample_sales', 'sample_editor', 'sample_viewer', 'sample_secure'], '90d', 20, [
			['kpi', 'Active products', 'sql', "SELECT SUM(CASE WHEN pro_active = 1 THEN 1 ELSE 0 END), NULL, 'products with Active ticked' FROM sample_product", ['width' => 4, 'link_type' => 'form', 'link' => self::fid('sample_product')]],
			['chart', 'Products by category', 'sql', 'SELECT pro_category, COUNT(*) FROM sample_product GROUP BY pro_category', ['width' => 4, 'chart' => 'pie', 'height' => 240]],
			['chart', 'Revenue by category (PHP procedure)', 'php', 'sample_proc_dash', ['width' => 4, 'chart' => 'bar', 'height' => 240] + $usd],
			['table', 'Orders (SQL builder query sample_orders)', 'select', 'sample_sql_orders', ['width' => 12, 'rows' => 6, 'decimals' => 2]],
		]);
		$dash('sample_customer', '', 'Sample: on the customer form, tab Statistics – figures of the open customer (#RECORD_ID#)', false, [], '90d', 30, [
			['kpi', 'Orders of #cus_name#', 'sql', "SELECT COUNT(*) FROM sample_order WHERE ord_sample_customer_id = '#RECORD_ID#'", ['width' => 4]],
			['kpi', 'Revenue', 'sql', "SELECT COALESCE(SUM(ord_total), 0) FROM sample_order WHERE ord_sample_customer_id = '#RECORD_ID#'", ['width' => 4] + $usd],
			['kpi', 'Credit limit used', 'sql', "SELECT CASE WHEN MAX(c.cus_credit) > 0 THEN 100.0 * COALESCE(SUM(o.ord_total), 0) / MAX(c.cus_credit) END FROM sample_customer c"
				. " LEFT JOIN sample_order o ON o.ord_sample_customer_id = c.sample_customer_id WHERE c.sample_customer_id = '#RECORD_ID#'", ['width' => 4, 'format' => 'percent', 'decimals' => 1, 'low' => 50, 'high' => 90, 'higher_better' => false]],
			['chart', 'Orders', 'sql', "SELECT ord_number, ord_total AS Total FROM sample_order WHERE ord_sample_customer_id = '#RECORD_ID#' ORDER BY ord_date", ['width' => 6, 'chart' => 'bar', 'height' => 240] + $usd],
			['table', 'Products bought', 'sql', "SELECT p.pro_name AS Product, SUM(i.ori_qty) AS Quantity, SUM(i.ori_qty * i.ori_price) AS Amount FROM sample_order_item i"
				. " JOIN sample_order o ON o.sample_order_id = i.ori_sample_order_id JOIN sample_product p ON p.sample_product_id = i.ori_sample_product_id"
				. " WHERE o.ord_sample_customer_id = '#RECORD_ID#' GROUP BY p.pro_name ORDER BY 3 DESC", ['width' => 6, 'rows' => 10, 'decimals' => 2]],
		]);
		$country = "('#home_country#' = '' OR c.cus_country = '#home_country#')";
		$dash('sample_country', '', 'Sample: on the start form – the field home_country (a launch form criterion) filters, nuNextDashboardRefresh() reads again', false, [], '90d', 40, [
			['kpi', 'Customers', 'sql', "SELECT COUNT(*) FROM sample_customer c WHERE $country", ['width' => 4]],
			['kpi', 'Orders', 'sql', "SELECT COUNT(*) FROM sample_order o JOIN sample_customer c ON c.sample_customer_id = o.ord_sample_customer_id WHERE $country", ['width' => 4]],
			['kpi', 'Revenue', 'sql', "SELECT COALESCE(SUM(o.ord_total), 0) FROM sample_order o JOIN sample_customer c ON c.sample_customer_id = o.ord_sample_customer_id WHERE $country", ['width' => 4] + $usd],
			['chart', 'Credit limit by customer', 'sql', "SELECT c.cus_name, c.cus_credit AS Credit FROM sample_customer c WHERE $country ORDER BY c.cus_credit DESC", ['width' => 12, 'chart' => 'barh', 'height' => 300] + $usd],
		]);
		return $rows;
	}

	private static function formats(): array
	{
		$f = static fn(string $id, string $type, string $format, ?array $cur, ?array $opt = null) => [
			'zzzzsys_format_id' => $id, 'srm_type' => $type, 'srm_default' => '0', 'srm_format' => $format,
			'srm_currency' => $cur ? json_encode($cur) : null, 'srm_json' => $opt ? json_encode(['nunext' => $opt]) : null];
		return [
			$f('sample_fmt_usd', 'Number', 'USD 1,000.00', ['USD', ',', '.', '2'], ['negative' => 'red_brackets']),
			$f('sample_fmt_eur', 'Number', '€ 1.000,00', ['€', '.', ',', '2'], ['negative' => 'red']),
			$f('sample_fmt_int', 'Number', '1,000', ['', ',', '', '0']),
			$f('sample_fmt_dec', 'Number', '1,000.00', ['', ',', '.', '2']),
			$f('sample_fmt_pct', 'Number', '1000.0 %', ['', '', '.', '1']),
			$f('sample_fmt_dlong', 'Date', 'dddd, dd mmmm yyyy', null),
			$f('sample_fmt_dtime', 'Date', 'dd.mm.yyyy hh:nn', null),
			$f('sample_fmt_iban', 'Text', 'AA99 9999 9999 9', null, ['store' => 'raw', 'complete' => true]),
			$f('sample_fmt_phone', 'Text', '+99 99 999 99 99', null, ['store' => 'masked', 'complete' => false]),
		];
	}

	private static function procedures(): array
	{
		$p = static fn(string $id, string $code, string $desc, string $run, string $php) => [
			'zzzzsys_php_id' => $id, 'sph_code' => $code, 'sph_description' => $desc, 'sph_group' => 'sample', 'sph_category' => '', 'sph_status' => '1',
			'sph_php' => $php, 'sph_javascript' => '', 'sph_javascript_active' => '0', 'sph_run' => $run, 'sph_zzzzsys_form_id' => self::fid(self::HOME),
			'sph_system' => '0', 'sph_global' => '0', 'sph_demo' => '0', 'sph_template' => '0', 'sph_hide' => '0'];
		return [
			$p('sample_proc_hello', 'sample_hello', 'Sample: procedure with output (window)', 'window',
				"// Procedure that prints HTML (run object \"Procedure (window)\")\n\$n = db_fetch_row(nuRunQuery('SELECT COUNT(*) FROM sample_customer'))[0] ?? 0;\necho '<h3>Hello #USER_FIRST_NAME#</h3>';\necho '<p>There are ' . (int)\$n . ' sample customers. Record: #RECORD_ID#</p>';\n// permission items of the user (Users → Permission items)\necho '<p>Your permission items: ' . htmlspecialchars('#USER_PERMISSIONS#' ?: '–') . '</p>';\nif (nuUserHasPermission('sample_export')) echo '<p>You may export sample data (sample_export).</p>';"),
			$p('sample_proc_hidden', 'sample_hidden', 'Sample: hidden procedure (JavaScript back)', 'hide',
				"// Hidden procedure: no window, sends JavaScript back to the form\n\$n = db_fetch_row(nuRunQuery('SELECT COUNT(*) FROM sample_order'))[0] ?? 0;\nnuAddJavascript(\"nuMessage(['Hidden procedure finished', 'Sample orders: \" . (int)\$n . \"']);\");"),
			$p('sample_proc_dash', 'sample_dashboard_data', 'Sample: data of a dashboard widget (nuDashboardData)', 'hide', <<<'PHP'
// Source of the widget "Revenue by category" of dashboard sample_products (nuBuilder Next, Admin tools → Dashboards):
// the rows are computed in PHP and handed over with nuDashboardData() – first column = categories, the others = series
$t = nuRunQuery('SELECT p.pro_category, i.ori_qty, i.ori_price FROM sample_order_item i JOIN sample_product p ON p.sample_product_id = i.ori_sample_product_id');
$sum = [];
while ($r = db_fetch_object($t)) $sum[$r->pro_category] = ($sum[$r->pro_category] ?? 0) + $r->ori_qty * $r->ori_price;
arsort($sum);
$rows = [];
foreach ($sum as $category => $revenue) $rows[] = ['Category' => $category, 'Revenue' => round($revenue, 2)];
if (function_exists('nuDashboardData')) nuDashboardData($rows);
PHP),
		];
	}

	/**
	 * Two reports: customers grouped by country (data source: table) and orders by customer (data source: a query of the SQL builder),
	 * with sums per group, page header / footer, a box line and page numbers.
	 */
	private static function reports(): array
	{
		$n = 0;
		$o = static function (string $type, string $field, int $left, int $top, int $w, array $more = []) use (&$n): array {
			return $more + ['id' => 'sample_o' . (++$n), 'name' => 'sample_o' . $n, 'objectType' => $type, 'fieldName' => $field, 'left' => $left, 'top' => $top, 'width' => $w,
				'height' => 20, 'backgroundColor' => '#FFFFFF', 'borderColor' => '#000000', 'borderWidth' => '0', 'fontColor' => '#000000', 'fontFamily' => 'Helvetica',
				'fontSize' => '10', 'fontWeight' => '', 'format' => '', 'textAlign' => 'left', 'minRows' => 0, 'maxRows' => 0, 'zIndex' => 1];
		};
		$sec = static fn(string $label, int $h, array $objs = [], int $break = 0) => ['id' => '', 'top' => 0, 'height' => $h, 'label' => $label, 'page_break' => $break, 'margins' => 0, 'color' => '#FFFFFF', 'objects' => $objs];
		$layout = static function (array $detail, array $rh, array $ph, array $groups, array $rf) use ($sec, $o): string {
			$g = [
				['sortField' => 'Detail', 'sortBy' => 'a', 'sections' => [$sec('Detail', 24, $detail)]],
				['sortField' => 'Report', 'sortBy' => 'a', 'sections' => [$sec('Report Header', 80, $rh), $sec('Report Footer', 40, $rf)]],
				['sortField' => 'Page', 'sortBy' => 'a', 'sections' => [$sec('Page Header', 60, $ph), $sec('Page Footer', 40, [$o('label', 'Page #page# of #pages#', 60, 10, 720, ['textAlign' => 'right', 'fontSize' => '8', 'fontColor' => '#666666'])])]],
			];
			foreach ($groups as [$field, $head, $foot]) $g[] = ['sortField' => $field, 'sortBy' => 'a', 'sections' => [$sec($field . ' Header', 28, $head), $sec($field . ' Footer', 30, $foot)]];
			while (count($g) < 8) $g[] = ['sortField' => '', 'sortBy' => 'a', 'sections' => [$sec(' Header', 0), $sec(' Footer', 0)]];
			return (string)json_encode(['top' => 30, 'left' => 30, 'bottom' => 260, 'right' => 30, 'width' => 210, 'height' => 297, 'paper' => 'A4', 'orientation' => 'P', 'ishtml' => 'N', 'groups' => $g]);
		};
		$money = "N|1'000.00";
		$line = static fn(int $top, string $color = '#336699') => $o('label', 'KEEP EXACT HEIGHT', 60, $top, 720, ['height' => 2, 'backgroundColor' => $color]);
		$head = static fn(array $cols) => array_merge(array_map(static fn($c) => $o('label', $c[0], $c[1], 30, $c[2], ['fontWeight' => 'b', 'textAlign' => $c[3] ?? 'left']), $cols), [$line(52)]);
		$rows = [];
		$rows[] = ['zzzzsys_report', ['zzzzsys_report_id' => 'sample_rpt_customers', 'sre_code' => 'sample_customers', 'sre_description' => 'Sample: customers by country', 'sre_group' => 'sample',
			'sre_zzzzsys_php_id' => 'TABLE:sample_customer', 'sre_zzzzsys_form_id' => null, 'sre_layout' => $layout(
				[$o('field', 'cus_code', 60, 2, 100), $o('field', 'cus_name', 170, 2, 330), $o('field', 'cus_since', 510, 2, 110, ['format' => 'D|dd.mm.yyyy']), $o('field', 'cus_credit', 630, 2, 150, ['format' => $money, 'textAlign' => 'right'])],
				[$o('image', 'Image:sample_logo', 60, 10, 60, ['height' => 60]), $o('label', 'Customers', 140, 14, 500, ['height' => 36, 'fontSize' => '18', 'fontWeight' => 'b']), $o('label', '#description# – #nu_date#', 140, 50, 600, ['fontColor' => '#666666'])],
				$head([['Code', 60, 100], ['Name', 170, 330], ['Since', 510, 110], ['Credit', 630, 150, 'right']]),
				[['cus_country', [$o('field', 'cus_country', 60, 6, 720, ['fontWeight' => 'b', 'backgroundColor' => '#E8EEF7'])],
					[$o('label', 'Total', 170, 4, 300, ['fontColor' => '#336699']), $o('field', 'SUM(cus_credit)', 630, 4, 150, ['format' => $money, 'textAlign' => 'right', 'fontColor' => '#336699'])]]],
				[$line(4, '#000000'), $o('label', 'Grand total', 60, 10, 300, ['fontWeight' => 'b']), $o('field', 'SUM(cus_credit)', 630, 10, 150, ['format' => $money, 'textAlign' => 'right', 'fontWeight' => 'b'])]
			)]];
		$rows[] = ['zzzzsys_select', ['zzzzsys_select_id' => 'sample_sql_orders', 'sse_code' => 'sample_orders', 'sse_description' => 'Sample: orders with their customer', 'sse_edit' => '0', 'sse_system' => null,
			'sse_json' => (string)json_encode(['tables' => [
				['id' => 'boxsamplec', 'position' => ['top' => 20, 'left' => 20], 'tablename' => 'sample_customer', 'alias' => '', 'checkall' => false, 'checkboxes' => [false, true, true, false, false, true, false, false, false, false]],
				['id' => 'boxsampleo', 'position' => ['top' => 20, 'left' => 320], 'tablename' => 'sample_order', 'alias' => '', 'checkall' => false, 'checkboxes' => [false, true, false, true, true, true, false, false, false, false, false]],
			], 'joins' => ['field_0_boxsamplec--field_2_boxsampleo' => '']]),
			'sse_sql' => "SELECT
 sample_customer.cus_code,
    sample_customer.cus_name,
    sample_customer.cus_country,
    sample_order.ord_number,
    sample_order.ord_date,
    sample_order.ord_status,
    sample_order.ord_total

FROM
    sample_customer
        JOIN sample_order ON sample_customer.sample_customer_id = sample_order.ord_sample_customer_id

ORDER BY
    sample_order.ord_date

"]];
		$rows[] = ['zzzzsys_select_clause', ['zzzzsys_select_clause_id' => 'sample_sqc_1', 'ssc_zzzzsys_select_id' => 'sample_sql_orders', 'ssc_type' => '3', 'ssc_field' => 'sample_order.ord_date', 'ssc_clause' => '', 'ssc_sort' => '', 'ssc_order' => '10']];
		$rows[] = ['zzzzsys_report', ['zzzzsys_report_id' => 'sample_rpt_orders', 'sre_code' => 'sample_orders', 'sre_description' => 'Sample: orders by customer', 'sre_group' => 'sample',
			'sre_zzzzsys_php_id' => 'SQL:sample_sql_orders', 'sre_zzzzsys_form_id' => null, 'sre_layout' => $layout(
				[$o('field', 'ord_number', 60, 2, 110), $o('field', 'ord_date', 180, 2, 120, ['format' => 'D|dd.mm.yyyy']), $o('field', 'ord_status', 310, 2, 200), $o('field', 'ord_total', 630, 2, 150, ['format' => $money, 'textAlign' => 'right'])],
				[$o('label', 'Orders by customer', 60, 14, 600, ['height' => 36, 'fontSize' => '18', 'fontWeight' => 'b']), $o('label', 'Printed #nu_date# by #USER_NAME#', 60, 50, 600, ['fontColor' => '#666666'])],
				$head([['Order', 60, 110], ['Date', 180, 120], ['Status', 310, 200], ['Total', 630, 150, 'right']]),
				[['cus_name', [$o('field', 'cus_name', 60, 6, 500, ['fontWeight' => 'b']), $o('field', 'cus_code', 570, 6, 210, ['textAlign' => 'right', 'fontColor' => '#666666'])],
					[$o('label', 'Orders of the customer', 180, 4, 300, ['fontColor' => '#336699']), $o('field', 'SUM(ord_total)', 630, 4, 150, ['format' => $money, 'textAlign' => 'right', 'fontColor' => '#336699'])]]],
				[$line(4, '#000000'), $o('label', 'All orders', 60, 10, 300, ['fontWeight' => 'b']), $o('field', 'SUM(ord_total)', 630, 10, 150, ['format' => $money, 'textAlign' => 'right', 'fontWeight' => 'b'])]
			)]];
		return $rows;
	}

	private static function snippets(): array
	{
		$s = static fn(string $id, string $code, string $lang, string $desc, string $src) => [
			'zzzzsys_code_snippet_id' => $id, 'cot_code' => $code, 'cot_description' => $desc, 'cot_group' => 'sample', 'cot_url' => '',
			'cot_source_code' => $src, 'cot_language' => $lang, 'cot_template' => '0', 'cot_updated_on' => date('Y-m-d H:i:s')];
		return [
			$s('sample_snip_js', 'sample_js_new_record', 'JavaScript', 'Sample: default values on a new record', "if (nuIsNewRecord()) {\n\tnuSetValue('field_id', 'value');\n}"),
			$s('sample_snip_php', 'sample_php_error', 'PHP', 'Sample: stop saving with a message (Before Save)', "if ('#field_id#' === '') {\n\tnuDisplayError('Please fill in field_id.');\n}"),
			$s('sample_snip_sql', 'sample_sql_count', 'SQL', 'Sample: display object with a count', "SELECT COUNT(*) FROM sample_order WHERE ord_sample_customer_id = '#RECORD_ID#'"),
		];
	}

	/** Five access levels with different rights and one user each. */
	private static function accessAndUsers(): array
	{
		$forms = self::FORMS;
		// rights: [add, save, delete, clone, new, print] per form; null = no access
		$view = [0, 0, 0, 0, 0, 1];
		$edit = [1, 1, 0, 0, 1, 1];
		$full = [1, 1, 1, 1, 1, 1];
		$levels = [
			'sample_viewer'  => ['Sample: view only', array_fill_keys($forms, $view), [], '0'],
			'sample_editor'  => ['Sample: add and change (no delete)', array_fill_keys($forms, $edit), ['sample_proc_hello'], '0'],
			'sample_manager' => ['Sample: everything', array_fill_keys($forms, $full), ['sample_proc_hello', 'sample_proc_hidden'], '0'],
			'sample_sales'   => ['Sample: orders only', ['sample_home' => $view, 'sample_customer' => $view, 'sample_product' => $view, 'sample_order' => $full, 'sample_order_item' => $full], ['sample_proc_hello'], '0'],
			'sample_secure'  => ['Sample: everything, with two-factor login', array_fill_keys($forms, $full), ['sample_proc_hello', 'sample_proc_hidden'], '1'],
		];
		$rows = [];
		$af = 0; $ap = 0;
		foreach ($levels as $code => [$desc, $rights, $procs, $twofa]) {
			$rows[] = ['zzzzsys_access', ['zzzzsys_access_id' => $code, 'sal_code' => $code, 'sal_description' => $desc, 'sal_group' => 'sample', 'sal_zzzzsys_form_id' => self::fid(self::HOME), 'sal_use_2fa' => $twofa]];
			foreach ($rights as $form => [$add, $save, $del, $clone, $new, $print]) {
				$rows[] = ['zzzzsys_access_form', ['zzzzsys_access_form_id' => 'sample_af_' . (++$af), 'slf_zzzzsys_access_id' => $code, 'slf_zzzzsys_form_id' => self::fid($form),
					'slf_add_button' => (string)$add, 'slf_save_button' => (string)$save, 'slf_delete_button' => (string)$del, 'slf_clone_button' => (string)$clone,
					'slf_new_button' => (string)$new, 'slf_print_button' => (string)$print]];
			}
			foreach ($procs as $p) $rows[] = ['zzzzsys_access_php', ['zzzzsys_access_php_id' => 'sample_ap_' . (++$ap), 'slp_zzzzsys_access_id' => $code, 'slp_zzzzsys_php_id' => $p]];
			foreach (['sample_rpt_customers', 'sample_rpt_orders'] as $rp) $rows[] = ['zzzzsys_access_report', ['zzzzsys_access_report_id' => 'sample_ar_' . (++$ap), 'sre_zzzzsys_access_id' => $code, 'sre_zzzzsys_report_id' => $rp]];
		}
		$u = static fn(string $login, string $first, string $last, string $access, string $lang, array $more = []) => $more + [
			'zzzzsys_user_id' => $login, 'sus_zzzzsys_access_id' => $access, 'sus_language' => $lang, 'sus_name' => $first . ' ' . $last,
			'sus_first_name' => $first, 'sus_last_name' => $last, 'sus_code' => strtoupper(substr($first, 0, 1) . substr($last, 0, 2)),
			'sus_position' => '', 'sus_department' => 'Sample', 'sus_team' => '', 'sus_email' => strtolower($first) . '@example.com',
			'sus_login_name' => $login, 'sus_expires_on' => null, 'sus_change_password' => '0', 'sus_accessibility_features' => '0'];
		$users = [
			$u('sample_anna', 'Anna', 'Viewer', 'sample_viewer', 'English', ['sus_position' => 'Trainee']),
			$u('sample_ben', 'Ben', 'Editor', 'sample_editor', 'German', ['sus_position' => 'Clerk']),
			$u('sample_carla', 'Carla', 'Manager', 'sample_manager', '', ['sus_position' => 'Manager', 'sus_team' => 'Lead']),
			$u('sample_dario', 'Dario', 'Sales', 'sample_sales', 'English', ['sus_position' => 'Sales', 'sus_change_password' => '1']),
			$u('sample_eva', 'Eva', 'Secure', 'sample_secure', 'English', ['sus_position' => 'Auditor', 'sus_expires_on' => date('Y-m-d 00:00:00', strtotime('+1 year'))]),
		];
		// permission items (Users → Permission items) and who has them
		$items = ['sample_order_status' => 'Sample: may change the status of an order', 'sample_export' => 'Sample: may export sample data', 'sample_discount' => 'Sample: may give discounts'];
		foreach ($items as $ident => $desc) {
			$rows[] = ['zzzzsys_permission_item', ['zzzzsys_permission_item_id' => 'sample_pi_' . substr($ident, 7), 'pme_ident' => $ident, 'pme_group' => 'sample', 'pme_description' => $desc]];
		}
		$up = 0;
		foreach (['sample_ben' => ['sample_export'], 'sample_carla' => array_keys($items), 'sample_dario' => ['sample_order_status', 'sample_discount'], 'sample_eva' => ['sample_export']] as $user => $idents) {
			foreach ($idents as $ident) {
				$rows[] = ['zzzzsys_user_permission', ['zzzzsys_user_permission_id' => 'sample_up_' . (++$up), 'upe_zzzzsys_user_id' => $user, 'upe_ident' => 'sample_pi_' . substr($ident, 7), 'upe_active' => '1']];
			}
		}
		return [$rows, $users];
	}

	/** CSS of the start form (sfo_style): header, section titles, tiles with icons (Bootstrap Icons), note and button. */
	private static function homeCss(): string
	{
		return <<<'CSS'
/* sample start form – tiles are run objects with the class sampleTile */
.sampleLogo, .sampleLogo img { border-radius: 1rem; box-shadow: 0 2px 6px rgba(15, 23, 42, .12); }
.sampleIntro h1 { font-size: 1.6rem; font-weight: 700; margin: .35rem 0 .3rem; letter-spacing: -.01em; }
.sampleIntro p { margin: 0; color: var(--bs-secondary-color); }
.sampleIntro p b { color: var(--bs-body-color); font-weight: 600; }
.nuWord.sampleSection { text-transform: uppercase; letter-spacing: .07em; font-size: .82rem; font-weight: 700; color: var(--bs-secondary-color); padding-top: .25rem; }
.btn.nuRunButton.sampleTile { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: .65rem; width: 100%; height: 100%; min-height: 7.5rem;
	padding: 1rem .75rem; border: 1px solid var(--bs-border-color); border-radius: .85rem; background: var(--bs-body-bg); color: var(--bs-body-color);
	font-size: 1.1rem; font-weight: 500; box-shadow: 0 1px 2px rgba(15, 23, 42, .05); transition: border-color .15s, box-shadow .15s, transform .15s; }
.btn.nuRunButton.sampleTile:hover, .btn.nuRunButton.sampleTile:focus-visible { border-color: #2563eb; color: var(--bs-body-color); background: var(--bs-body-bg);
	box-shadow: 0 6px 16px rgba(37, 99, 235, .14); transform: translateY(-2px); }
.btn.nuRunButton.sampleTile:active { transform: none; }
.btn.nuRunButton.sampleTile > i { margin: 0 !important; font-size: 1.75rem; line-height: 1; color: #2563eb; }
.btn.nuRunButton.sampleTile::before { font-family: bootstrap-icons; font-size: 1.75rem; line-height: 1; color: #2563eb; }
[data-bs-theme="dark"] .btn.nuRunButton.sampleTile > i, [data-bs-theme="dark"] .btn.nuRunButton.sampleTile::before { color: #6ea8fe; }
#home_customers::before { content: "\f4cf"; }   /* people-fill */
#home_products::before { content: "\f7d3"; }    /* box-seam-fill */
#home_orders::before { content: "\f23d"; }      /* cart-fill */
#home_all::before { content: "\f3d8"; }         /* folder2-open */
#home_new_order::before { content: "\f64d"; }   /* plus-lg */
#home_hidden > i::before { content: "\f33f"; }  /* eye-slash-fill instead of the play icon */
.sampleNote { padding: .75rem 1rem; border: 1px solid var(--bs-border-color); border-radius: .75rem; background: var(--bs-tertiary-bg); font-size: .92rem; }
.btn.sampleButton { border-radius: .6rem; }
/* layout Auto / Grid: no label space above these objects, logo next to the title, two tiles per row on phones */
.nuWrap_run:has(.sampleTile) > .nuLabelSpacer, .nuWrap_word:has(.sampleSection) > .nuLabelSpacer,
.nuWrap_html:has(.sampleIntro, .sampleNote) > .nuLabelSpacer, .nuWrap_input:has(.sampleButton) > .nuLabelSpacer { display: none; }
.nuGridRow > #nuw_home_logo { flex: 0 0 auto; width: auto; }
.nuGridRow > #nuw_home_intro { flex: 1 1 0; width: auto; min-width: 0; }
.nuGridRow .nuWord.sampleSection { margin-top: .9rem; }
@media (max-width: 767.98px) {
	.nuGridRow > .nuWrap_run:has(.sampleTile) { width: 50%; }
	.btn.nuRunButton.sampleTile { min-height: 6.5rem; height: 6.5rem !important; font-size: 1rem; }
	.sampleIntro h1 { font-size: 1.3rem; }
}
CSS;
	}

	private static function logoSvg(): string
	{
		return '<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 64 64"><defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1">'
			. '<stop offset="0" stop-color="#14a38b"/><stop offset="1" stop-color="#0b6e60"/></linearGradient></defs>'
			. '<rect width="64" height="64" rx="14" fill="url(#g)"/><text x="32" y="41" font-family="Arial,sans-serif" font-size="15" font-weight="700" fill="#fff" text-anchor="middle">sample</text></svg>';
	}
}
