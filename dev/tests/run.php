<?php
declare(strict_types=1);

/*
 * nuBuilder Next – unit tests without a database or web server (run from the nunext folder):  php dev/tests/run.php
 * Called by dev/release.py before every release. Exit code 1 when a test fails.
 * Browser tests against a test installation: dev/tests/smoke.js (see the comment there).
 */
if (PHP_SAPI !== 'cli') exit;
$cfg = tempnam(sys_get_temp_dir(), 'nucfg');
file_put_contents($cfg, "<?php\n\$nuConfigDBName = 'none'; \$nuConfigTrustProxy = true; \$nuConfigSecretKey = 'test-key';\n");
putenv('NU_CONFIG=' . $cfg);
require dirname(__DIR__, 2) . '/core/nuapp.php';

$fails = 0;
$n = 0;
function check(string $name, bool $ok, string $info = ''): void
{
	global $fails, $n;
	$n++;
	if (!$ok) { $fails++; echo "FAIL $name" . ($info !== '' ? " – $info" : '') . "\n"; }
}

// ---------------------------------------------------------------- SQL classification (demo mode, SQL builder preview)
foreach (['SELECT 1' => true, "SELECT * FROM a JOIN b ON a.x = b.y WHERE s = 'DELETE'" => true, 'WITH x AS (SELECT 1) SELECT * FROM x' => true,
	'SELECT `update` FROM t' => true, '(SELECT 1) UNION (SELECT 2)' => true,
	'WITH x AS (SELECT 1) DELETE FROM t' => false, 'SELECT * INTO new_t FROM a' => false, 'SELECT 1; DROP TABLE x' => false,
	"SELECT LOAD_FILE('/etc/passwd')" => false, 'SELECT * FROM t FOR UPDATE' => false, 'UPDATE t SET a = 1' => false, 'CALL p()' => false,
	"SELECT * FROM t INTO OUTFILE '/tmp/x'" => false] as $sql => $want) {
	check("readOnlySql: $sql", NuDemo::readOnlySql($sql) === $want);
}
check('tablesOf', NuDemo::tablesOf('SELECT * FROM zzzzsys_user u JOIN `Orders` o ON 1') === ['zzzzsys_user', 'orders']);
check('sqlAllowed select', NuDemo::sqlAllowed('SELECT 1'));
check('sqlAllowed update', !NuDemo::sqlAllowed('UPDATE customer SET a = 1'));

// ---------------------------------------------------------------- English variants (0.8.314)
foreach (['en-US' => 'en-US', 'en' => 'en-US', 'en_CA' => 'en-US', 'en-AU' => 'en-AU', 'en-nz' => 'en-AU', 'en-GB' => 'en-GB', 'en-IE' => 'en-GB', 'en-IN' => 'en-GB', 'de-CH' => 'de-CH', 'de' => 'de-DE', 'de-AT' => 'de-DE', 'fr-CH' => null] as $tag => $want) {
	check("variant of $tag", nu_lang_variant_of($tag) === $want, (string)nu_lang_variant_of($tag));
}
$_SESSION = [];
nu_set_lang('en-US');
check('set en-US', nu_lang() === 'en' && nu_lang_variant() === 'en-US' && nu_locale() === 'en-US');
check('en-US text', t('home_tile_color') === 'Color' && t('save') === (require NU_ROOT . '/lang/en.php')['save']);
nu_set_lang('en');
check('plain en keeps the variant', nu_lang_variant() === 'en-US');
nu_set_lang('en-GB');
check('en-GB text (base)', t('home_tile_color') === (require NU_ROOT . '/lang/en.php')['home_tile_color'] && nu_locale() === 'en-GB');
nu_set_lang('fr');
check('other language drops the variant', nu_lang_variant() === '' && !isset($_SESSION['nu_lang_variant']) && nu_lang_choice() === 'fr');
check('menu choices', array_slice(array_keys(nu_lang_choices()), 0, 5) === ['de-DE', 'de-CH', 'en-GB', 'en-AU', 'en-US'] && !isset(nu_lang_choices()['en']) && !isset(nu_lang_choices()['de']));
// German (0.8.315): de.php = de-DE (ß, „“), de-CH generated (ss, «»)
nu_set_lang('de');
check('plain de = de-DE', nu_lang_variant() === 'de-DE' && nu_locale() === 'de-DE' && t('close') === 'Schließen');
nu_set_lang('de-CH');
check('de-CH text', nu_lang() === 'de' && nu_locale() === 'de-CH' && t('close') === 'Schliessen' && !str_contains(t('home_delete_tile_confirm'), '„'));
$_SESSION = [];

// ---------------------------------------------------------------- client IP (last X-Forwarded-For entry), HTTPS behind a proxy
$_SERVER['REMOTE_ADDR'] = '10.0.0.1';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '1.2.3.4, 203.0.113.9';
check('client ip = last forwarded entry', nu_client_ip() === '203.0.113.9', nu_client_ip());
$_SERVER['HTTP_X_FORWARDED_FOR'] = 'not-an-ip';
check('client ip ignores junk', nu_client_ip() === '10.0.0.1');
$_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
check('https behind trusted proxy', nu_https());
unset($_SERVER['HTTP_X_FORWARDED_PROTO']);
check('http', !nu_https());

// ---------------------------------------------------------------- API IP list
check('ip in /24', NuApiClient::ipAllowed('192.168.1.0/24', '192.168.1.77'));
check('ip outside /24', !NuApiClient::ipAllowed('192.168.1.0/24', '192.168.2.1'));
check('ipv6 /64', NuApiClient::ipAllowed('2001:db8::/64', '2001:db8::1'));
check('empty list = all', NuApiClient::ipAllowed('', '8.8.8.8'));

// ---------------------------------------------------------------- update package paths and signature payload
$safe = new ReflectionMethod(NuUpdate::class, 'safePath');
foreach (['core/nuapp.php' => true, '.htaccess' => true, 'core/.htaccess' => true, '../x.php' => false, '/etc/passwd' => false, 'a\\b' => false,
	'.git/config' => false, 'c:/x' => false] as $p => $want) check("safePath $p", $safe->invoke(null, $p) === $want);
check('protected nuconfig', NuUpdate::isProtected('nuconfig.php') && NuUpdate::isProtected('_backup/x.zip'));
$m = json_decode((string)file_get_contents(NU_ROOT . '/nunext-manifest.json'), true);
if (!empty($m['signature'])) check('manifest signature valid', NuUpdate::signatureState($m) === 'valid');
$m2 = ['version' => '1', 'files' => ['b' => ['sha256' => 'x', 'size' => 1], 'a' => ['sha256' => 'y', 'size' => 2]]];
$m3 = ['version' => '1', 'files' => ['a' => ['sha256' => 'y', 'size' => 2], 'b' => ['sha256' => 'x', 'size' => 1]]];
check('signed payload independent of order', NuUpdate::signedPayload($m2) === NuUpdate::signedPayload($m3));
check('tampered package is invalid', NuUpdate::signatureState($m2 + ['signature' => base64_encode(str_repeat('a', 64))]) === 'invalid');

// ---------------------------------------------------------------- passwords, 2FA
[$ok] = NuAuth::verifyPassword('secret', password_hash('secret', PASSWORD_DEFAULT));
check('bcrypt', $ok);
check('md5 legacy', NuAuth::verifyPassword('secret', md5('secret')) === [true, true]);
check('wrong password', NuAuth::verifyPassword('x', md5('secret'))[0] === false);
$b32 = NuTwoFA::base32Encode('12345678901234567890');
check('base32', $b32 === 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', $b32);
check('TOTP RFC 6238 (59 s)', NuTwoFA::code($b32, 1) === '287082');
check('TOTP RFC 6238 (1111111109 s)', NuTwoFA::code($b32, intdiv(1111111109, 30)) === '081804');
check('auto-login hash changes with expiry', NuAuth::urlHash('h') !== NuAuth::urlHash('h', time() + 60));
check('auto-login hash old format', NuAuth::urlHash('h') === hash_hmac('sha256', 'nunext-autologin|h', 'test-key'));

// ---------------------------------------------------------------- secrets, next URLs
check('secret names', NuSecurity::secretName('sus_login_password') && NuSecurity::secretName('smtp_secret') && !NuSecurity::secretName('cus_name')
	&& !NuSecurity::secretName('nuConfigPasswordMinLength'));
check('hash values', NuSecurity::hashValue('$2y$10$abcdefghijklmnopqrstuv') && !NuSecurity::hashValue('hello'));
check('validNext local', NuAuth::validNext('index.php?p=users') === 'index.php?p=users');
check('validNext no other host', NuAuth::validNext('https://evil.example/') === null && NuAuth::validNext('index.php?p=login') === null
	&& NuAuth::validNext('index.php?p=x&u=a') === null && NuAuth::validNext('index.php?p=reauth') === null);

// ---------------------------------------------------------------- protected folders
$tmp = sys_get_temp_dir() . '/nunext-test-' . bin2hex(random_bytes(4));
NuSecurity::protectDir($tmp);
check('protectDir', is_file("$tmp/.htaccess") && (string)file_get_contents("$tmp/.htaccess") === NuSecurity::DENY && is_file("$tmp/index.html"));
file_put_contents("$tmp/.htaccess", "# own rules\n");                                // an existing .htaccess is left alone
NuSecurity::protectDir($tmp);
check('protectDir keeps an existing .htaccess', (string)file_get_contents("$tmp/.htaccess") === "# own rules\n");
foreach (["$tmp/.htaccess", "$tmp/index.html"] as $f) @unlink($f);
@rmdir($tmp);
foreach (['core', 'pages', 'lang', 'install', 'docs', 'dev'] as $d) check(".htaccess in $d/", is_file(NU_ROOT . "/$d/.htaccess"));

// ---------------------------------------------------------------- include guards and class loading
foreach (array_merge(glob(NU_ROOT . '/core/*.php'), glob(NU_ROOT . '/core/form/*.php'), glob(NU_ROOT . '/pages/*.php'), glob(NU_ROOT . '/lang/*.php')) as $f) {
	if (basename($f) === 'nuapp.php') continue;
	check('include guard ' . substr($f, strlen(NU_ROOT) + 1), str_contains(substr((string)file_get_contents($f), 0, 300), "defined('NU_ROOT') || exit;"));
}
foreach (array_merge(glob(NU_ROOT . '/core/*.php'), glob(NU_ROOT . '/core/form/*.php')) as $f) {
	if (!preg_match_all('/^(?:final |abstract )?class (\w+)/m', (string)file_get_contents($f), $mm)) continue;
	foreach ($mm[1] as $c) {
		if ($c === 'NuTCPDF') continue;                                    // needs TCPDF first (NuReport::loadTcpdf)
		$where = substr($f, strlen(NU_ROOT) + 1);
		$ok = class_exists($c, false) || (isset(NU_AUTOLOAD[$c]) && NU_AUTOLOAD[$c] === $where) || in_array($where, ['core/form/nuphp_compat.php', 'core/form/nuphp_api.php'], true);
		check("class $c is loaded or in NU_AUTOLOAD", $ok, $where);
	}
}
foreach (NU_AUTOLOAD as $c => $file) check("autoload $c", class_exists($c), $file);

// ---------------------------------------------------------------- branding (0.8.297): colours and logo types
check('brand hex short', NuBranding::hex('#ABC') === '#aabbcc');
check('brand hex invalid', NuBranding::hex('red') === '' && NuBranding::hex('#12345') === '');
check('brand contrast black/white', abs(NuBranding::contrast('#000000', '#ffffff') - 21.0) < 0.01);
check('brand text on dark accent', NuBranding::onColor('#7b2cbf') === '#fff');
check('brand text on light accent', NuBranding::onColor('#ffe066') === '#000');
check('brand readable link', NuBranding::contrast(NuBranding::readable('#ffcc00', '#ffffff', 4.5, '#000000'), '#ffffff') >= 4.5);
check('brand svg accepted', NuBranding::imageType('<?xml version="1.0"?>' . "\n" . '<svg xmlns="http://www.w3.org/2000/svg"></svg>', 'a.svg') === 'image/svg+xml');
check('brand html refused', NuBranding::imageType('<html><svg></svg></html>', 'a.svg') === '');
check('brand text refused', NuBranding::imageType('hello', 'a.png') === '');

// ---------------------------------------------------------------- dashboards (0.8.298)
$today = date('Y-m-d');
check('dash range today', NuDashboard::range('today') === [$today, $today]);
check('dash range month', NuDashboard::range('month') === [date('Y-m-01'), date('Y-m-t')]);
check('dash range custom', NuDashboard::range('custom', '2026-01-05', '2026-02-01') === ['2026-01-05', '2026-02-01']);
check('dash range custom invalid', NuDashboard::range('custom', '2026-02-30', 'x')[1] === $today);
check('dash range quarter', strlen(NuDashboard::range('quarter')[1]) === 10 && NuDashboard::range('quarter')[0] <= $today && NuDashboard::range('quarter')[1] >= $today);
$o = NuDashboard::options(['width' => 5, 'height' => 5000, 'chart' => 'x', 'decimals' => 9, 'low' => 'abc', 'refresh' => 3], 'kpi');
check('dash options limits', $o['width'] === 3 && $o['height'] === 800 && $o['chart'] === 'bar' && $o['decimals'] === 6 && $o['low'] === '' && $o['refresh'] === 10);
check('dash value numbers', NuDashboard::value('12') === 12 && NuDashboard::value('1.50') === 1.5 && NuDashboard::value('007a') === '007a' && NuDashboard::value(null) === null);
check('dash value long number stays text', NuDashboard::value('12345678901234567890') === '12345678901234567890');
check('dash uses dates', NuDashboard::usesDates([['source' => 'sql', 'query' => "SELECT 1 WHERE d >= '#DATE_FROM#'"]]) && !NuDashboard::usesDates([['source' => 'sql', 'query' => 'SELECT 1']]));

// ---------------------------------------------------------------- negative numbers as nuBuilder: minus after the prefix (0.8.364)
foreach (['N|€ 1.000,00' => ['-99.95', '€ -99,95'], 'N|USD 1,000.00' => ['-1234.5', 'USD -1,234.50'], 'N|1000.00' => ['-5', '-5.00'], 'N|1000.0 %' => ['-7.25', '-7.3 %']] as $f => [$v, $want]) {
	$num = (new ReflectionMethod('NuFormat', 'number'))->invoke(null, $v, substr($f, 2), 'minus');      // without the format registry (no database)
	check("number $f $v", $num === $want, $num);
}

// ---------------------------------------------------------------- PHP2Shell fixed releases (0.8.362)
foreach (['8.3.6' => '8.3.36', '8.3.35' => '8.3.36', '8.3.36' => '', '8.3.40' => '', '8.2.34' => '8.2.35', '8.2.35' => '', '8.4.26' => '8.4.27',
	'8.4.27' => '', '8.5.11' => '8.5.12', '8.5.12' => '', '8.1.33' => '8.2.35', '7.4.33' => '8.2.35', '8.6.0' => '', '9.0.0' => '',
	'8.3.36-dev' => '', 'x' => ''] as $v => $want) {
	check("phpFixNeeded $v", NuSecurity::phpFixNeeded($v) === $want, NuSecurity::phpFixNeeded($v));
}
check('phpFixNote', NuSecurity::phpFixNote('8.5.12') === '' && str_contains(NuSecurity::phpFixNote('8.3.6'), '8.3.36') && str_contains(NuSecurity::phpFixNote('8.1.2'), '8.2.35'));

// ---------------------------------------------------------------- hash variables outside quotes (lint)
$lint = static fn(string $code, string $lang) => NuCompat::unquotedVars($code, $lang);
check('lint sql', $lint("SELECT * FROM t WHERE id = '#RECORD_ID#' AND x = #cus_id#", 'sql') === ['cus_id']);
check('lint sql comment', $lint('SELECT 1 -- #x#', 'sql') === []);
check('lint php', $lint('$a = #amount#; $b = "#name#";', 'php') === ['amount']);
check('lint sql in php string', $lint('nuRunQuery("SELECT * FROM t WHERE id = #x#");', 'php') === ['x']);
check('lint record id unquoted', $lint('SELECT * FROM t WHERE id = #RECORD_ID#', 'sql') === ['RECORD_ID']);

// ---------------------------------------------------------------- GitHub releases (0.8.374)
$asset = static fn(string $v, string $host = 'github.com') => ['name' => 'nunext.zip', 'size' => 10, 'browser_download_url' => "https://$host/o/r/releases/download/v$v/nunext.zip"];
$rels = [
	['tag_name' => 'v0.8.380', 'draft' => true, 'prerelease' => false, 'assets' => [$asset('0.8.380')]],
	['tag_name' => 'v0.8.376', 'draft' => false, 'prerelease' => true, 'assets' => [$asset('0.8.376')]],
	['tag_name' => 'v0.8.375', 'draft' => false, 'prerelease' => false, 'assets' => [$asset('0.8.375')]],
	['tag_name' => 'v0.8.377', 'draft' => false, 'prerelease' => false, 'assets' => [['name' => 'other.zip'] + $asset('0.8.377')]],
	['tag_name' => 'v0.8.378', 'draft' => false, 'prerelease' => false, 'assets' => [$asset('0.8.378', 'evil.example')]],
	['tag_name' => 'latest', 'draft' => false, 'prerelease' => false, 'assets' => [$asset('9.9.9')]],
];
check('github pick with pre-releases', (NuGitHub::pick($rels, true)['version'] ?? '') === '0.8.376');
check('github pick without pre-releases', (NuGitHub::pick($rels, false)['version'] ?? '') === '0.8.375');
check('github pick none', NuGitHub::pick([], true) === null);
check('github hosts', NuGitHub::hostAllowed('https://objects.githubusercontent.com/x') && !NuGitHub::hostAllowed('http://github.com/x')
	&& !NuGitHub::hostAllowed('https://github.com.evil.example/x') && !NuGitHub::hostAllowed('https://u@github.com/x'));
check('github repo names', NuGitHub::validRepo('nuBuilder/nuBuilder-Next') && !NuGitHub::validRepo('a/../b') && !NuGitHub::validRepo('a/b/c') && !NuGitHub::validRepo('x'));

// ---------------------------------------------------------------- nuRunQuery result with PDOStatement methods (0.8.378)
$pdo = new PDO('sqlite::memory:');
$mk = static function () use ($pdo) { return new NuResult($pdo->query("SELECT 1 AS a, 'x' AS b UNION ALL SELECT 2, 'y'")); };
check('NuResult fetchColumn', $mk()->fetchColumn() == 1);
check('NuResult fetch assoc', ($mk()->fetch(PDO::FETCH_ASSOC)['b'] ?? '') === 'x');
check('NuResult fetchObject', $mk()->fetchObject()->b === 'x');
check('NuResult fetchAll column', $mk()->fetchAll(PDO::FETCH_COLUMN, 1) === ['x', 'y']);
check('NuResult rowCount', $mk()->rowCount() === 2);

// ---------------------------------------------------------------- start page hooks (0.8.378)
NuHomeHook::reset();
NuHomeHook::addTile('bad_key', ['label' => 'x', 'page' => 'help']);
NuHomeHook::addGroup('g_no', 'x');
check('hook: x_ keys enforced', count(NuHomeHook::$problems) === 2 && !NuHomeHook::$tiles && !NuHomeHook::$groups);
NuHomeHook::reset();
NuHomeHook::addTile('x_js', ['label' => 'x', 'url' => 'javascript:alert(1)']);
NuHomeHook::addTile('x_two', ['label' => 'x', 'url' => 'https://example.com/', 'page' => 'help']);
NuHomeHook::addTile('x_nopage', ['label' => 'x', 'page' => 'nosuchpage']);
NuHomeHook::addTile('x_nolabel', ['page' => 'help']);
check('hook: targets refused like the tile editor', count(NuHomeHook::$problems) === 4 && !NuHomeHook::$tiles, implode(' | ', NuHomeHook::$problems));
NuHomeHook::reset();
NuHomeHook::addTile('x_ok', ['label' => '<b>Hi</b>', 'url' => 'index.php?p=help', 'icon' => 'bi-x" onclick="y', 'color' => 'red']);
NuHomeHook::setBadge('users', 'n', 'evil');
NuHomeHook::banner('Hello', 'nope');
check('hook: values cleaned', !NuHomeHook::$problems && NuHomeHook::$tiles['x_ok']['tile']['icon'] === 'bi-star' && NuHomeHook::$tiles['x_ok']['tile']['color'] === ''
	&& NuHomeHook::$badges['users'][1] === 'secondary' && NuHomeHook::$banners[0][1] === 'info');
NuHomeHook::setData('bad name', 1);
NuHomeHook::setData('ok', ['a' => 1]);
NuHomeHook::cacheFor(99999);
check('hook: data and cache', NuHomeHook::$data === ['ok' => ['a' => 1]] && NuHomeHook::$cache === 3600 && count(NuHomeHook::$problems) === 1);
NuHomeHook::reset();

// ---------------------------------------------------------------- browse query the nuBuilder way (0.8.382)
$cols = [['index' => 0, 'expr' => 'c.cus_name'], ['index' => 1, 'expr' => "CONCAT(cus_code, ' - ', cus_name) AS full_name"], ['index' => 2, 'expr' => 'CAST(x AS CHAR)']];
[$in, $ord] = NuBrowse::build("SELECT c.*, t.name AS tname FROM tcustomer c LEFT JOIN tcountry t ON t.id = c.cid WHERE c.a = 'x FROM y' GROUP BY c.id HAVING COUNT(*) > 0 ORDER BY tname DESC, 2 LIMIT 5", 'customer_id', $cols);
check('browse: field list replaced', str_starts_with($in, 'SELECT customer_id AS nu_pk, c.cus_name AS nu_c0, CONCAT(cus_code, \' - \', cus_name) AS nu_c1, CAST(x AS CHAR) AS nu_c2'), $in);
check('browse: from / where / group / having kept', str_contains($in, "FROM tcustomer c LEFT JOIN tcountry t ON t.id = c.cid WHERE c.a = 'x FROM y' GROUP BY c.id HAVING COUNT(*) > 0") && !str_contains($in, 'LIMIT'), $in);
check('browse: order by alias and position', str_contains($in, 't.name AS nu_o0') && str_contains($in, 't.name AS nu_o1') && $ord === 'nuT.nu_o0 DESC, nuT.nu_o1', $ord . ' | ' . $in);
[$in2] = NuBrowse::build('SELECT cus_code FROM (SELECT * FROM t) x WHERE y IN (SELECT 1 FROM z)', 'id', []);
check('browse: sub-queries', $in2 === 'SELECT id AS nu_pk FROM (SELECT * FROM t) x WHERE y IN (SELECT 1 FROM z)', $in2);
[$in3] = NuBrowse::build('SELECT a FROM t UNION SELECT a FROM u', 'id', [['index' => 0, 'expr' => 'a']]);
check('browse: UNION wrapped as before', str_contains($in3, 'FROM (SELECT a FROM t UNION SELECT a FROM u) nuX'), $in3);
check('browse: nu_filter_match expanded', str_contains(NuBrowse::expandFilterMatch("SELECT * FROM t WHERE nu_filter_match('cus_type', 'abc')"), "FIND_IN_SET(cus_type, '#abc_filter#')"));

// ---------------------------------------------------------------- installer: nubuilder4.sql of any 4.x version (0.8.384)
$core = implode(";\n", array_map(static fn($t) => "CREATE TABLE `$t` (`{$t}_id` varchar(25) NOT NULL) ENGINE=MyISAM", NuInstall::DUMP_CORE)) . ";\n";
$okDump = "SET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\nSTART TRANSACTION;\n/*!40101 SET NAMES utf8mb4 */;\n" . $core
	. "INSERT INTO `zzzzsys_form` (`zzzzsys_form_id`) VALUES\n('a'),\n('b');\nALTER TABLE `zzzzsys_form` ADD PRIMARY KEY (`zzzzsys_form_id`);\n"
	. "CREATE ALGORITHM=UNDEFINED SQL SECURITY DEFINER VIEW `zzzzsys_run_list` AS select 1 AS id;\nCOMMIT;\n";
$r = NuInstall::checkDump($okDump);
check('dump: nuBuilder dump accepted', $r['ok'] && $r['tables'] === 5 && $r['forms'] === 2, json_encode($r));
check('dump: core tables required', !NuInstall::checkDump("CREATE TABLE `zzzzsys_form` (a int);")['ok']);
foreach (['other table' => 'CREATE TABLE customers (a int)', 'insert into other table' => 'INSERT INTO users VALUES (1)', 'USE' => 'USE mysql',
	'DROP DATABASE' => 'DROP DATABASE x', 'GRANT' => "GRANT ALL ON *.* TO 'x'@'%'", 'CREATE USER' => "CREATE USER 'x'@'%'", 'SET PASSWORD' => "SET PASSWORD = 'x'",
	'SET GLOBAL' => 'SET GLOBAL general_log = 1', 'OUTFILE' => "SELECT * FROM zzzzsys_user INTO OUTFILE '/tmp/x'", 'LOAD DATA' => "LOAD DATA INFILE '/etc/passwd' INTO TABLE zzzzsys_form",
	'trigger' => 'CREATE TRIGGER t BEFORE INSERT ON zzzzsys_form FOR EACH ROW SET @a = 1', 'DEFINER view' => "CREATE DEFINER=`root`@`%` VIEW zzzzsys_v AS SELECT 1",
	'view on other name' => 'CREATE VIEW users AS SELECT 1', 'INSERT … SELECT' => 'INSERT INTO zzzzsys_form SELECT * FROM mysql.user',
	'conditional comment' => "/*!50003 CREATE PROCEDURE p() BEGIN END */",
	'function in VALUES' => "INSERT INTO zzzzsys_form (a) VALUES ((SELECT LOAD_FILE('/etc/passwd')))", 'sub-query in VALUES' => "INSERT INTO zzzzsys_form VALUES ('a'), ((SELECT 1))"] as $what => $stmt) {
	check('dump: refused ' . $what, !NuInstall::checkDump($okDump . $stmt . ";\n")['ok']);
}
check('dump: strings with SQL in VALUES', NuInstall::checkDump($okDump . "INSERT INTO `zzzzsys_object` VALUES ('x', 'SELECT * FROM t; DROP TABLE y', 'it''s', 'a\\'b', NULL, -1.5, 0x1F);\n")['ok']);
check('dump: 4.9 table definitions', count(NuInstall::tables49()) === 35 && isset(NuInstall::tables49()['zzzzsys_object']['sob_all_access_condition']));

// ---------------------------------------------------------------- form drafts: snapshot canonical form, hash, diff (0.8.386)
$snapA = ['v' => 2, 'form' => ['zzzzsys_form_id' => 'f1', 'sfo_code' => 'c', 'sfo_javascript' => "a\nb"],
	'tabs' => [['zzzzsys_tab_id' => 't2', 'syt_title' => 'B', 'syt_order' => 20], ['zzzzsys_tab_id' => 't1', 'syt_title' => 'A', 'syt_order' => 10]],
	'objects' => [['zzzzsys_object_id' => 'o1', 'sob_all_id' => 'x', 'sob_all_label' => 'X', 'sob_all_top' => 10, 'sob_all_type' => 'input']],
	'events' => [], 'browse' => [], 'php' => [['zzzzsys_php_id' => 'f1_BE', 'sph_php' => 'echo 1;']], 'selects' => [], 'clauses' => []];
$snapB = $snapA;
$snapB['tabs'] = array_reverse($snapB['tabs']);                                   // another order, same content
$snapB['objects'][0]['sob_all_top'] = '10';                                       // number vs string
check('snapshot: hash ignores order and value types', NuFormSnapshot::hash($snapA) === NuFormSnapshot::hash($snapB));
$snapC = $snapA;
$snapC['objects'][0]['sob_all_label'] = 'Y';
check('snapshot: hash sees a change', NuFormSnapshot::hash($snapA) !== NuFormSnapshot::hash($snapC));
$snapD = $snapA;
$snapD['objects'][0]['sob_new_column'] = null;                                    // a column added by an update since
check('snapshot: column-stable hash', NuFormSnapshot::hash($snapD, $snapA) === NuFormSnapshot::hash($snapA) && NuFormSnapshot::hash($snapD) !== NuFormSnapshot::hash($snapA));
$snapE = $snapC;
$snapE['objects'][] = ['zzzzsys_object_id' => 'o2', 'sob_all_id' => 'y', 'sob_all_type' => 'select'];
$snapE['objects'][0]['sob_all_top'] = 99;                                         // placement only on o1? no: label changed too
$snapE['php'][0]['sph_php'] = 'echo 2;';
$snapE['form']['sfo_javascript'] = "a\nc";
$d = NuFormSnapshot::diff($snapA, $snapE);
check('snapshot: diff', $d['counts']['objects'] === 2 && count($d['objects']['added']) === 1 && $d['objects']['changed'][0]['sob_all_id'] === 'x'
	&& $d['counts']['php'] === 1 && $d['form'][0][0] === 'sfo_javascript' && $d['counts']['total'] === 4, json_encode($d['counts']));
$snapF = $snapA;
$snapF['objects'][0]['sob_all_top'] = 50;
$d = NuFormSnapshot::diff($snapA, $snapF);
check('snapshot: placement only = moved', $d['objects']['moved'] === 1 && !$d['objects']['changed'] && $d['counts']['total'] === 1);
check('snapshot: no change', NuFormSnapshot::diff($snapA, $snapB)['counts']['total'] === 0);

@unlink($cfg);
echo ($fails ? "$fails of $n tests FAILED" : "all $n tests passed") . "\n";
exit($fails ? 1 : 0);
