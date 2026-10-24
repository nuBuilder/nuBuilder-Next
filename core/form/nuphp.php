<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * Runs nuBuilder PHP code (form hooks BB/BE/BS/AS/BD/AD, lookup AB, procedures) and collects
 * what the code asks for: errors, JavaScript, form values and properties.
 */
final class NuPhp
{
	public static array $hash         = [];
	public static array $errors       = [];     // [['message' => …, 'field' => …]]
	public static array $js           = [];
	public static array $formValues   = [];     // nuSetFormValue
	public static array $props        = [];     // nuSetProperty
	public static array $globals      = [];     // nuSetProperty(…, true)
	public static array $deleted      = [];     // nuDeleteProperty
	public static ?object $lookupRow  = null;   // nuLookupRecord
	public static array $subforms     = [];     // posted subform data for nuSubformObject
	public static array $debug        = [];
	public static string $output      = '';
	public static ?string $openRecord = null;   // nuReturnNewRecord(): the record the form opens after the save (0.8.443)
	/** The procedure's own variable $result after it ran (nuBuilder's nuEvalSafe → nuRunPHPHiddenResult; the upload procedure's answer, 0.8.459). */
	public static mixed $result       = null;
	/** The code's variable $error after it ran – nuBuilder's login hooks (nu_startup / nu_invalid_login) stop with it (0.8.462). */
	public static string $error       = '';
	/** Variables a hook's code reads (0.8.462): nu_check_password_policy gets $newPassword …, its $check comes back here. */
	public static array $vars         = [];

	public static function reset(): void
	{
		self::$errors = self::$js = self::$formValues = self::$props = self::$globals = self::$deleted = self::$debug = [];
		self::$lookupRow = null;
		self::$output = '';
		self::$openRecord = null;
		self::$result = null;
		self::$error = '';
		self::$vars = [];
	}

	/** Loads $nuConfigIncludePHP files once (global helper functions of an installation). */
	public static function includes(): void
	{
		static $done = false;
		if ($done) return;
		$done = true;
		foreach (NuIncludes::entries('php') as $entry) {   // nuBuilder Next's folder, then nuBuilder's (0.8.443, NuIncludes::phpFile)
			$file = NuIncludes::phpFile($entry);
			if ($file !== null) require_once $file;
			else error_log("nuBuilder Next: \$nuConfigIncludePHP file not found: $entry");
		}
	}

	/**
	 * @return bool  false when the code reported an error (nuDisplayError) or failed
	 */
	public static function run(?string $code, array $hash, string $label, array $vars = []): bool
	{
		self::$hash = $hash;
		if ($code === null || trim($code) === '') return true;

		require_once __DIR__ . '/nuphp_api.php';
		self::includes();

		$errorsBefore = count(self::$errors);
		$prevContext = NuDebugLog::$context;
		NuDebugLog::$context = $label;
		$code = NuHash::replace($code, $hash, NuHash::PHP);
		$code = preg_replace('/^\s*<\?php/', '', $code);

		set_error_handler(static function (int $no, string $str, string $file, int $line) use ($label): bool {
			if (!(error_reporting() & $no)) return false;
			self::$debug[] = "$label: $str" . (str_contains($file, 'eval') ? " (line $line)" : '');
			return true;                                   // warnings/notices are logged, not fatal (like nuBuilder)
		});
		ob_start();
		try {
			self::evaluate($code, $vars);
		} catch (NuDemoBlocked $e) {                         // demo mode: the code tried to change data – no line, it is not an error in the code
			self::$errors[] = ['message' => "$label: " . $e->getMessage(), 'field' => ''];
		} catch (Throwable $e) {
			error_log("nuBuilder Next PHP $label: " . $e->getMessage() . ' line ' . $e->getLine());
			$u = NuAuth::user();
			self::$errors[] = [
				'message' => ($u && $u['globeadmin']) || nu_cfg('nuConfigDebug')
					? "$label: " . $e->getMessage() . ' (line ' . $e->getLine() . ')'
					: t('form_err_php', $label),
				'field' => '',
			];
		} finally {
			self::$output .= (string)ob_get_clean();
			restore_error_handler();
		}
		NuDebugLog::$context = $prevContext;
		if (self::$debug) self::logDebug();
		return count(self::$errors) === $errorsBefore;
	}

	private static function evaluate(string $__code, array $__vars = []): void
	{
		$result = null;                                    // like nuBuilder's nuEvalSafe: what the code leaves in $result is its answer
		$error = '';                                       // nuBuilder's login hooks: a text here stops the login
		extract($__vars, EXTR_SKIP);
		try {
			eval($__code);
		} finally {
			self::$result = $result;
			self::$error = is_string($error) ? $error : '';
			if ($__vars) self::$vars = get_defined_vars();   // e.g. $check of nu_check_password_policy
		}
	}

	private static function logDebug(): void
	{
		foreach (self::$debug as $msg) error_log('nuBuilder Next PHP notice: ' . $msg);
		self::$debug = [];
	}

	/** What a client request gets back. */
	public static function result(): array
	{
		return [
			'errors' => self::$errors,
			'js'     => self::$js,
			'values' => (object)self::$formValues,
			'props'  => (object)self::$props,
			'globals' => (object)self::$globals,
			'deleted' => array_keys(self::$deleted),
		];
	}

	/**
	 * Runs $fn with a fresh state and puts the previous state back afterwards – for procedures that run while a form is being
	 * rendered (display objects, iframe run objects), so what they collect does not leak into the request's own result.
	 * @return array{result: mixed, output: string, errors: array}
	 */
	public static function isolated(callable $fn): array
	{
		$keep = [self::$hash, self::$errors, self::$js, self::$formValues, self::$props, self::$globals, self::$deleted, self::$lookupRow, self::$debug, self::$output];
		self::reset();
		try {
			$result = $fn();
			return ['result' => $result, 'output' => self::$output, 'errors' => self::$errors];
		} finally {
			[self::$hash, self::$errors, self::$js, self::$formValues, self::$props, self::$globals, self::$deleted, self::$lookupRow, self::$debug, self::$output] = $keep;
		}
	}

	/** Runs a procedure (zzzzsys_php) by code or id, checking zzzzsys_access_php. */
	public static function procedure(string $codeOrId, array $hash): array
	{
		// nuBuilder's nuSetProperty(name, value, true) in JavaScript calls this system procedure
		if ($codeOrId === 'nu_set_hash_cookie') {
			$name = (string)($hash['nu_set_hash_cookie_name'] ?? '');
			if (preg_match('/^\w{1,100}$/', $name) && !NuHash::isSystem($name)) {
				NuHashCookies::set($name, $hash['nu_set_hash_cookie_value'] ?? '');
				self::$globals[$name] = $hash['nu_set_hash_cookie_value'] ?? '';
			}
			return ['ok' => true];
		}
		$db  = nu_db();
		$php = $db->one('SELECT * FROM zzzzsys_php WHERE sph_code = ? OR zzzzsys_php_id = ?', [$codeOrId, $codeOrId]);
		if (!$php) return ['ok' => false, 'error' => t('form_err_proc_missing', $codeOrId)];

		$u = NuAuth::user();
		if (NuImpersonate::readOnly()) return ['ok' => false, 'error' => t('imp_err_view_only')];
		// demo mode: only procedures with "Demo access" (nuBuilder's sph_demo, on when the column is missing) – and they cannot write
		if (NuDemo::active() && (string)($php['sph_demo'] ?? '1') !== '1') return ['ok' => false, 'error' => t('demo_not_available')];
		if (!$u['globeadmin'] && !NuAdmin::can('procedures', 'change') && (string)$php['sph_global'] !== '1') {   // admin role "Procedures: change": every one
			$granted = (int)$db->value('SELECT COUNT(*) FROM zzzzsys_access_php WHERE slp_zzzzsys_access_id = ? AND slp_zzzzsys_php_id = ?',
				[$u['access_id'], $php['zzzzsys_php_id']]);
			if (!$granted) return ['ok' => false, 'error' => t('form_err_proc_denied', $codeOrId)];
		}
		self::run((string)$php['sph_php'], $hash + ['PROCEDURE_CODE' => $php['sph_code']], (string)$php['sph_code']);
		return ['ok' => true];
	}
}

/** Pre-fetched query result, so db_num_rows() works the same on MySQL and MSSQL. */
final class NuResult
{
	public array $rows;
	public int $pos = 0;
	public int $affected;
	public array $columns = [];

	public function __construct(PDOStatement $st)
	{
		// numeric rows keep duplicate column names (SELECT a.id, b.id) intact
		$this->rows = $st->columnCount() > 0 ? $st->fetchAll(PDO::FETCH_NUM) : [];
		$this->affected = $st->rowCount();
		for ($i = 0; $i < $st->columnCount(); $i++) {
			$m = $st->getColumnMeta($i);
			$this->columns[] = $m['name'] ?? (string)$i;
		}
	}

	/** @return array|null numeric row */
	public function next(): ?array
	{
		return $this->rows[$this->pos++] ?? null;
	}

	/** Numeric row → name => value (later duplicate names win, like MySQL assoc fetch). */
	public function assoc(array $row): array
	{
		$a = [];
		foreach ($row as $i => $v) $a[$this->columns[$i] ?? $i] = $v;
		return $a;
	}

	// PDOStatement methods that nuBuilder code calls on the result of nuRunQuery() (nuBuilder returns a PDOStatement) – 0.8.378

	private function shaped(array $row, int $mode): array|object
	{
		return match ($mode) {
			PDO::FETCH_NUM => $row,
			PDO::FETCH_ASSOC => $this->assoc($row),
			PDO::FETCH_OBJ => (object)$this->assoc($row),
			default => $this->assoc($row) + $row,                         // FETCH_BOTH
		};
	}

	public function fetch(int $mode = PDO::FETCH_BOTH): array|object|false
	{
		$row = $this->next();
		return $row === null ? false : $this->shaped($row, $mode);
	}

	public function fetchColumn(int $column = 0): mixed
	{
		$row = $this->next();
		return $row === null ? false : ($row[$column] ?? null);
	}

	public function fetchObject(): object|false
	{
		$row = $this->next();
		return $row === null ? false : (object)$this->assoc($row);
	}

	public function fetchAll(int $mode = PDO::FETCH_BOTH, int $column = 0): array
	{
		$out = [];
		while (($row = $this->next()) !== null) $out[] = $mode === PDO::FETCH_COLUMN ? ($row[$column] ?? null) : $this->shaped($row, $mode);
		return $out;
	}

	public function rowCount(): int
	{
		return $this->rows ? count($this->rows) : $this->affected;
	}

	public function columnCount(): int
	{
		return count($this->columns);
	}
}
