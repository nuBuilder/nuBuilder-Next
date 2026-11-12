<?php
/*
 * nuFiles for nuBuilder Forte – a single-file file manager (the file manager of nuBuilder Next, adapted).
 * Version %%VERSION%% · built from nuBuilder Next %%NEXT%% · PHP 8.1 or newer · MySQL / MariaDB (SQL Server: log table only, untested)
 *
 * INSTALL
 *   Save this file as third_party/tinyfilemanager/tinyfilemanager.php of your nuBuilder Forte installation (it replaces Tiny File Manager).
 *   Forte's own File Manager entry (nuVendorLogin('TFM'), e.g. the File Manager button of the Setup / Home form) then opens it.
 *   A Forte update puts Tiny File Manager back – keep a copy of this file (your settings are at the top) and copy it again.
 *
 * WHO MAY USE IT (as Tiny File Manager)
 *   The globeadmin and the users listed in $nuConfigFileMangerUsers (nuconfig.php, user ids or login names, separated by commas).
 *   The sign-in is Forte's: Forte sets the cookie nu_TFM (the session id) when the File Manager is opened from Forte; this file checks
 *   that session in zzzzsys_session (not expired, no pending 2FA). Users in 'readonly_users' (below) can only view and download.
 *
 * SETTINGS – the array $NUF_SETTINGS right below. Keep a copy: a new version of this file brings the defaults again.
 *   An optional file nufiles-settings.php next to this one (returning an array) overrides them and survives replacing this file.
 *
 *   shares      folders the file manager shows. Each: id (a-z 0-9 _ -), label, path (absolute, or starting with {FORTE} = the folder of
 *               nuconfig.php, or {DOCUMENT_ROOT}), type 'files' (documents, with the protections below) or 'code' (globeadmin only, no
 *               name or content rules), readonly, extensions (allow-list), max_upload_mb, show_hidden, web_active_types (allow HTML / SVG
 *               / JS), content_check (refuse PHP code in uploads), editor, zip, trash (true / false), deny (hidden names: ".git",
 *               "nuconfig.php", "private/*"), grants (who besides the globeadmin): [{"tfm_users": true | "access": [access level codes]
 *               | "permission": [permission items] | "login": [login names], "rights": ["view","upload","mkdir","edit","rename","copy",
 *               "delete","zip"]}]
 *   readonly_users  login names or user ids that may only view / download (also the globeadmin's login, if you want)
 *   reauth      password again before: 'code' (any access to code shares, default), 'code_write', 'all_write', 'none';
 *               reauth_minutes how long a confirmation counts (default 15)
 *   data_dir    recycle bin, versions, upload chunks – best OUTSIDE the web root. Default: _nufiles_data next to this file (protected by
 *               an .htaccess on Apache; on nginx deny it or set data_dir to a folder outside the web root)
 *   limits      max_upload_mb, chunk_mb (below PHP's upload_max_filesize / post_max_size), zip_max_mb, zip_max_entries, copy_max_mb,
 *               editor_max_kb, search_max_results, list_max_entries, trash_days (0 = keep), versions_keep (0 = none)
 *   log_table   change log in Forte's database (created on first use), log_reads = also list / download / preview
 *   lang        '' = the Forte user's language (German → de, else en), or 'en' / 'de' / 'de-CH';  theme  'auto' | 'light' | 'dark'
 *   forte_root  '' = found by itself (the folder with nuconfig.php above this file)
 *
 * SECURITY: code shares are the program of the server – only the globeadmin sees them, always after the password confirmation.
 * Every path is checked (no "..", no symlinks out of a share, the data folder unreachable); uploads into "files" shares refuse names a
 * web server would run (.php, .phtml, .htaccess …) and PHP code inside files; zip / unzip are protected against zip-slip and zip bombs;
 * every change is logged. CSRF token on every change.
 */

declare(strict_types=1);

$NUF_SETTINGS = [
	'shares' => [
		['id' => 'uploads', 'label' => 'Uploads', 'path' => '{FORTE}/uploads', 'type' => 'files',
			'extensions' => 'pdf, png, jpg, jpeg, gif, webp, txt, csv, md, json, docx, xlsx, pptx, odt, ods, zip',
			'grants' => [['tfm_users' => true, 'rights' => ['view', 'upload', 'mkdir', 'edit', 'rename', 'copy', 'delete', 'zip']]]],
		['id' => 'forte', 'label' => 'nuBuilder Forte (code)', 'path' => '{FORTE}', 'type' => 'code', 'deny' => ['.git', 'nuconfig.php']],
	],
	'readonly_users' => [],
	'reauth' => 'code',
	'reauth_minutes' => 15,
	'data_dir' => '',
	'max_upload_mb' => 200,
	'chunk_mb' => 2,
	'zip_max_mb' => 500,
	'zip_max_entries' => 5000,
	'copy_max_mb' => 2000,
	'editor_max_kb' => 2048,
	'search_max_results' => 500,
	'list_max_entries' => 10000,
	'trash_days' => 30,
	'versions_keep' => 10,
	'log_table' => 'nufiles_log',
	'log_reads' => false,
	'lang' => '',
	'theme' => 'auto',
	'forte_root' => '',
];

// ====================================================================================================================== bootstrap

const NUF_VERSION = '%%VERSION%%';
const NUF_ASSET_V = '%%ASSETV%%';   // SHA-256 (start) of the script + style of this build: a new file is never shown with the browser's copy of the old ones
const NUF_FORTE_EDITION = true;

if (is_file(__DIR__ . '/nufiles-settings.php')) {
	$nufOwn = include __DIR__ . '/nufiles-settings.php';
	if (is_array($nufOwn)) $NUF_SETTINGS = array_replace($NUF_SETTINGS, $nufOwn);
}

/** The folder of nuBuilder Forte (nuconfig.php) and the URL prefix from this file to it. */
(static function () use ($NUF_SETTINGS): void {
	$root = trim((string)$NUF_SETTINGS['forte_root']);
	$up = '';
	if ($root === '') {
		$d = __DIR__;
		for ($i = 0; $i < 4; $i++) {
			if (is_file($d . '/nuconfig.php')) { $root = $d; break; }
			$d = dirname($d);
			$up .= '../';
		}
	} else {
		$up = '';
		$rel = trim(substr(str_replace('\\', '/', __DIR__), strlen(rtrim(str_replace('\\', '/', $root), '/'))), '/');
		if ($rel !== '') $up = str_repeat('../', substr_count($rel, '/') + 1);
	}
	if ($root === '' || !is_file($root . '/nuconfig.php')) {
		http_response_code(500);
		exit('nuFiles: nuconfig.php of nuBuilder Forte not found – set forte_root at the top of this file.');
	}
	define('NUF_FORTE_ROOT', rtrim(str_replace('\\', '/', (string)realpath($root)), '/'));
	define('NUF_FORTE_URL', $up);
})();

final class NufCtx
{
	public PDO $pdo;
	public string $type = 'mysql';
	private array $user = [];
	private bool $ga = false;
	private bool $ro = false;
	private array $perms = [];
	private string $lang = 'en';

	public function __construct(private array $set) {}

	// ------------------------------------------------------------------ sign-in (Forte's session)

	public function connect(): void
	{
		$c = (static function (): array {
			require NUF_FORTE_ROOT . '/nuconfig.php';
			return get_defined_vars();
		})();
		$this->type = ($c['nuConfigDBType'] ?? 'mysql') === 'sqlsrv' ? 'sqlsrv' : 'mysql';
		$host = (string)($c['nuConfigDBHost'] ?? 'localhost');
		$port = (string)($c['nuConfigDBPort'] ?? ($this->type === 'sqlsrv' ? '1433' : '3306'));
		$dsn = $this->type === 'sqlsrv'
			? 'sqlsrv:Server=' . $host . ($port !== '' ? ',' . $port : '') . ';Database=' . ($c['nuConfigDBName'] ?? '')
			: 'mysql:host=' . $host . ';port=' . $port . ';dbname=' . ($c['nuConfigDBName'] ?? '') . ';charset=utf8mb4';
		$this->pdo = new PDO($dsn, (string)($c['nuConfigDBUser'] ?? ''), (string)($c['nuConfigDBPassword'] ?? ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
		$this->cfg = $c;
	}
	public array $cfg = [];

	/** null = signed in; else the reason (session / access). */
	public function signIn(): ?string
	{
		$sid = (string)($_COOKIE['nu_TFM'] ?? '');
		if ($sid === '' || strlen($sid) > 64) return 'err_session';
		$row = $this->one('SELECT sss_access, sss_time FROM zzzzsys_session WHERE zzzzsys_session_id = ?', [$sid]);
		if (!$row) return 'err_session';
		$life = (isset($this->cfg['nuConfigTimeOut']) && is_int($this->cfg['nuConfigTimeOut']) && $this->cfg['nuConfigTimeOut'] > 0) ? 60 * $this->cfg['nuConfigTimeOut'] : 36000;
		if ($row['sss_time'] !== null && (int)$row['sss_time'] > 0 && (int)$row['sss_time'] < time() - $life) return 'err_session';
		$a = json_decode((string)$row['sss_access'], true);
		$s = is_array($a['session'] ?? null) ? $a['session'] : [];
		if (!$s) return 'err_session';
		$id = (string)($s['zzzzsys_user_id'] ?? '');
		$login = (string)($s['sus_login_name'] ?? '');
		$this->ga = (string)($s['global_access'] ?? '0') === '1';
		$listed = array_filter(array_map('trim', preg_split('/[\s,;]+/', (string)($this->cfg['nuConfigFileMangerUsers'] ?? '')) ?: []), 'strlen');
		$tfmUser = !$this->ga && ($id !== '' && in_array($id, $listed, true) || $login !== '' && in_array($login, $listed, true));
		if (!$this->ga && !$tfmUser) return 'err_access';
		$this->user = ['id' => $id !== '' ? $id : $login, 'login' => $login, 'display' => trim((string)($s['sus_name'] ?? '')) ?: $login,
			'access_code' => (string)($a['access_level_code'] ?? ''), 'tfm' => $tfmUser || $this->ga];
		$this->perms = array_values(array_filter(array_map('trim', explode(',', (string)($s['user_permissions'] ?? ''))), 'strlen'));
		$ro = array_map('strval', (array)$this->set['readonly_users']);
		$this->ro = in_array($login, $ro, true) || in_array($id, $ro, true);
		$l = strtolower(trim((string)$this->set['lang']));
		if ($l === '') $l = str_starts_with(strtolower((string)($s['language'] ?? '')), 'german') || str_starts_with(strtolower((string)($s['language'] ?? '')), 'deutsch') ? 'de' : 'en';
		$this->lang = $l;
		return null;
	}

	/** Password confirmation: the globeadmin against nuconfig.php, users against zzzzsys_user (bcrypt or Forte's md5). */
	public function checkPassword(string $pw): bool
	{
		if ($pw === '') return false;
		if ($this->ga) {
			$gp = (string)($this->cfg['nuConfigDBGlobeadminPassword'] ?? '');
			if ($gp !== '' && (hash_equals($gp, $pw) || (str_starts_with($gp, '$') && password_verify($pw, $gp)))) return true;
		}
		$hash = (string)($this->one('SELECT sus_login_password FROM zzzzsys_user WHERE zzzzsys_user_id = ?', [$this->user['id']])['sus_login_password'] ?? '');
		if ($hash === '') return false;
		if (str_starts_with($hash, '$')) return password_verify($pw, $hash);
		return hash_equals(strtolower($hash), md5($pw));
	}

	// ------------------------------------------------------------------ what lib/*.php asks for (nuBuilder Next's page context)

	public function action(): string { $a = $_GET['action'] ?? ''; return is_string($a) ? $a : ''; }
	public function param(string $name, ?string $default = null): ?string { $v = $_POST[$name] ?? $_GET[$name] ?? null; return is_string($v) ? $v : $default; }
	public function isPost(): bool { return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'; }
	public function setting(string $key, mixed $default = null): mixed { return array_key_exists($key, $this->set) ? $this->set[$key] : $default; }
	public function isGlobeadmin(): bool { return $this->ga; }
	public function readOnly(): bool { return $this->ro; }
	public function user(): array { return $this->user; }
	public function realUser(): ?array { return null; }
	public function permissions(): array { return $this->perms; }
	public function lang(): string { return $this->lang; }
	public function theme(): string { $t = (string)$this->set['theme']; return in_array($t, ['light', 'dark', 'auto'], true) ? $t : 'auto'; }
	public function dir(): string { return str_replace('\\', '/', __DIR__); }
	public function dataDir(): string { return $this->dir() . '/_nufiles_data'; }
	public function dbType(): string { return $this->type; }
	public function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
	public function url(array $params = []): string { return basename((string)$_SERVER['SCRIPT_NAME']) . ($params ? '?' . http_build_query($params) : ''); }
	public function homeUrl(): string { return NUF_FORTE_URL . 'index.php'; }
	public function asset(string $f): string { return $this->url(['asset' => $f === 'assets/app.css' ? 'css' : 'js', 'v' => NUF_ASSET_V]); }
	public function &session(): array { if (!isset($_SESSION['nuf']) || !is_array($_SESSION['nuf'])) $_SESSION['nuf'] = []; return $_SESSION['nuf']; }
	public function releaseSession(): void { if (session_status() === PHP_SESSION_ACTIVE) session_write_close(); }
	public function log(string $m): void { error_log($m); }
	public function recentAuth(): bool { $at = (int)($_SESSION['nuf']['auth_at'] ?? 0); return $at > time() - 60 * max(1, (int)$this->set['reauth_minutes']); }
	public function reauthUrl(string $back): string { return 'password'; }

	public function json(mixed $data, int $status = 200): never
	{
		while (ob_get_level() > 0) ob_end_clean();
		http_response_code($status);
		header('Content-Type: application/json; charset=utf-8');
		header('Cache-Control: no-store');
		echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
		exit;
	}

	public function one(string $sql, array $p = []): ?array { $st = $this->pdo->prepare($sql); $st->execute($p); $r = $st->fetch(PDO::FETCH_ASSOC); return $r === false ? null : $r; }
	public function all(string $sql, array $p = []): array { $st = $this->pdo->prepare($sql); $st->execute($p); return $st->fetchAll(PDO::FETCH_ASSOC); }
	public function exec(string $sql, array $p = []): int { $st = $this->pdo->prepare($sql); $st->execute($p); return $st->rowCount(); }
	public function insert(string $table, array $row): string
	{
		$pk = $table . '_id';
		$row = [$pk => substr(str_replace('.', '', uniqid('nf', true)), 0, 25)] + $row;
		$q = $this->type === 'sqlsrv' ? static fn($n) => '[' . $n . ']' : static fn($n) => '`' . $n . '`';
		$this->exec('INSERT INTO ' . $q($table) . ' (' . implode(', ', array_map($q, array_keys($row))) . ') VALUES (' . implode(', ', array_fill(0, count($row), '?')) . ')', array_values($row));
		return (string)$row[$pk];
	}
}
