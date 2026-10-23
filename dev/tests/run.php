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

// ---------------------------------------------------------------- page forms (0.8.387)
check('page: folder names', NuPage::validCode('sales-board_2') && NuPage::validCode('A') && !NuPage::validCode('_template') && !NuPage::validCode('../x')
	&& !NuPage::validCode('a b') && !NuPage::validCode('a.b') && !NuPage::validCode('') && !NuPage::validCode(str_repeat('a', 65)) && NuPage::validCode(str_repeat('a', 64)));
check('page: asset paths', NuPage::validAssetPath('assets/app.css') && NuPage::validAssetPath('assets/img/logo 2.svg') && !NuPage::validAssetPath('assets/../index.php')
	&& !NuPage::validAssetPath('index.php') && !NuPage::validAssetPath('/etc/passwd') && !NuPage::validAssetPath('assets/.env') && !NuPage::validAssetPath('assets\\a.css')
	&& !NuPage::validAssetPath('assets//a.css') && !NuPage::validAssetPath('assets/./a.css') && !NuPage::validAssetPath('assets/c:x.css'));
check('page: guard line', NuPage::hasGuard("<?php\ndefined('NU_PAGE') || exit;\n") && NuPage::hasGuard("<?php\n/* app */\n// x\ndeclare(strict_types=1);\ndefined(\"NU_PAGE\") or die;")
	&& !NuPage::hasGuard("<?php\necho 1;\ndefined('NU_PAGE') || exit;") && !NuPage::hasGuard("<html><?php defined('NU_PAGE') || exit; ?>"));
check('page: heights', NuPage::validHeight('auto') && NuPage::validHeight('800px') && NuPage::validHeight('90vh') && NuPage::validHeight('100%') && !NuPage::validHeight('1px; x') && !NuPage::validHeight('calc(1px)'));
check('update: custom pages never touched', NuUpdate::isProtected('custom/pages/sales/index.php') && NuUpdate::isProtected('custom/lib/x.php') && NuUpdate::isProtected('custom/pages/sales/secrets.php')
	&& !NuUpdate::isProtected('custom/.htaccess') && !NuUpdate::isProtected('custom/PAGE_GUIDE.md') && !NuUpdate::isProtected('custom/pages/_template/index.php')
	&& !NuUpdate::isProtected('custom/pages/_template/assets/app.js'));
// ---------------------------------------------------------------- Excel writer (NuXlsx, 0.8.398)
check('xlsx: column names', NuXlsx::colName(1) === 'A' && NuXlsx::colName(26) === 'Z' && NuXlsx::colName(27) === 'AA' && NuXlsx::colName(16384) === 'XFD'
	&& NuXlsx::colNumber('AA') === 27 && NuXlsx::range('B3:A1') === [1, 1, 3, 2]);
$bad = 0;
foreach (['A0', '1A', 'A1:', 'ZZZZ1', 'A1;B2', 'A99999999'] as $r) { try { NuXlsx::range($r); } catch (InvalidArgumentException) { $bad++; } }
check('xlsx: invalid ranges refused', $bad === 6);
$x = NuXlsx::create('T <&>');
$s = $x->sheet('a/b:c*?[d]' . str_repeat('x', 40));
$s->header(['Text', 'Num', 'Date', 'Bool'])
	->row(['=1+1', 1.5, new DateTime('2026-03-01'), true])
	->row(["<b>&\x01\nx", -7, new DateTime('2026-03-01 10:15:00'), false])
	->row(['Sum', NuXlsx::formula('=SUM(B2:B3)'), null, NuXlsx::cell('x', ['bold' => true, 'bg' => '#FF0000', 'color' => 'nope', 'border' => 'thin'])])
	->style('B2:B4', ['format' => '0.00']);
$x->sheet('a/b:c*?[d]' . str_repeat('x', 40));
$x->sheet('')->numbersFromText()->row(['12', '007', '1e5', '-3.25']);
$bin = $x->toString();
$tmp = tempnam(sys_get_temp_dir(), 'xt');
file_put_contents($tmp, $bin);
$z = new ZipArchive();
$okZip = $z->open($tmp) === true;
$parts = [];
for ($i = 0; $okZip && $i < $z->numFiles; $i++) $parts[$z->getNameIndex($i)] = (string)$z->getFromIndex($i);
@unlink($tmp);
$wellFormed = true;
foreach ($parts as $pn => $xml) { $d = new DOMDocument(); if (!@$d->loadXML($xml)) { $wellFormed = false; echo "  bad XML: $pn\n"; } }
check('xlsx: zip with all parts, well-formed XML', $okZip && $wellFormed && isset($parts['[Content_Types].xml'], $parts['xl/workbook.xml'], $parts['xl/styles.xml'], $parts['xl/sharedStrings.xml'], $parts['xl/worksheets/sheet3.xml']));
$wb = $parts['xl/workbook.xml'] ?? '';
preg_match_all('/<sheet name="([^"]*)"/', $wb, $m);
check('xlsx: sheet names valid and unique', count($m[1]) === 3 && mb_strlen(html_entity_decode($m[1][0])) <= 31 && !preg_match('/[\/:*?\[\]]/', $m[1][0]) && $m[1][0] !== $m[1][1], implode(' | ', $m[1]));
$s1 = $parts['xl/worksheets/sheet1.xml'] ?? '';
check('xlsx: a string starting with = stays text', str_contains($s1, '<c r="A2" s="') === false && preg_match('/<c r="A2"[^>]* t="s">/', $s1) && substr_count($s1, '<f>') === 1 && str_contains($s1, '<f>SUM(B2:B3)</f>'));
check('xlsx: numbers, dates, booleans typed', (bool)preg_match('/<c r="B2"[^>]*><v>1\.5<\/v>/', $s1) && (bool)preg_match('/<c r="C2"[^>]*><v>46082<\/v>/', $s1)
	&& (bool)preg_match('/<c r="D2"[^>]*t="b"><v>1<\/v>/', $s1) && (bool)preg_match('/<c r="C3"[^>]*><v>46082\.427083/', $s1));
$ss = $parts['xl/sharedStrings.xml'] ?? '';
check('xlsx: text escaped, control characters removed', str_contains($ss, '&lt;b&gt;&amp;' . "\n" . 'x') && !str_contains($ss, "\x01"));
check('xlsx: header frozen with autofilter', str_contains($s1, 'state="frozen"') && str_contains($s1, '<autoFilter ref="A1:D1"/>') && str_contains($wb, '_xlnm._FilterDatabase'));
$st = $parts['xl/styles.xml'] ?? '';
check('xlsx: styles (bold, fill, border, number format; invalid colour ignored)', str_contains($st, '<b/>') && str_contains($st, 'FFFF0000') && str_contains($st, 'style="thin"')
	&& str_contains($st, 'numFmtId="2"') && !str_contains($st, 'nope'));
$s3 = $parts['xl/worksheets/sheet3.xml'] ?? '';
check('xlsx: numbersFromText', (bool)preg_match('/<c r="A1"><v>12<\/v>/', $s3) && (bool)preg_match('/<c r="B1" t="s">/', $s3) && (bool)preg_match('/<c r="C1" t="s">/', $s3) && (bool)preg_match('/<c r="D1"><v>-3\.25<\/v>/', $s3));
$err = 0;
try { NuXlsx::formula('  '); } catch (InvalidArgumentException) { $err++; }
try { $x->sheet()->freeze('A1:B2'); } catch (InvalidArgumentException) { $err++; }
check('xlsx: empty formula / freeze range refused', $err === 2);

// page API versions (0.8.388)
$js = (string)file_get_contents(NU_ROOT . '/assets/js/nupage.js');
preg_match('/var NuPage = \{(.*?)\n\t\};/s', $js, $mm);
preg_match_all('/^\t\t([A-Za-z_]\w*)\s*:/m', $mm[1] ?? '', $keys);
check('page: NuPage::JS_API = the names of nupage.js', ($a = $keys[1]) && !array_diff($a, NuPage::JS_API) && !array_diff(NuPage::JS_API, $a), implode(',', $keys[1]));
$info = NuPage::apiInfo();
check('page: apiInfo', $info['current'] === NuPageContext::API_VERSION && in_array(NuPageContext::API_VERSION, $info['supported'], true)
	&& in_array('user', $info['methods'], true) && in_array('api', $info['methods'], true) && !in_array('__construct', $info['methods'], true));
check('page: declared version', NuPage::declaredApi("<?php\ndefined('NU_PAGE') || exit;\n\$nu->api(1);") === 1 && NuPage::declaredApi('<?php echo 1;') === null
	&& NuPage::declaredApi('$nu -> api( 2 )') === 2);
$scan = ['declared' => 2, 'php' => ['user' => ['index.php'], 'oldThing' => ['x.php'], 'json' => ['index.php']], 'js' => ['fetch' => ['assets/app.js'], 'gone' => ['assets/app.js']]];
$iss = NuPage::check($scan, ['supported' => [1], 'methods' => ['user', 'json'], 'deprecated' => ['json' => ['0.9.1', 'send()']], 'js' => ['fetch'], 'js_deprecated' => []]);
$lv = array_count_values(array_column($iss, 'level'));
check('page: check() – version, unknown, deprecated', count($iss) === 4 && ($lv['error'] ?? 0) === 3 && ($lv['warning'] ?? 0) === 1, json_encode($iss));
check('page: check() – a clean page', NuPage::check(['declared' => null, 'php' => ['user' => ['index.php']], 'js' => ['toast' => ['a.js']]], $info) === []);
check('page: context class', NuPage::contextClass(null) === 'NuPageContext' && NuPage::contextClass(1) === 'NuPageContext');
foreach (['custom/pages/_template/index.php'] as $f) check('page: shipped file has the guard: ' . $f, NuPage::hasGuard((string)file_get_contents(NU_ROOT . '/' . $f)));

// HTML cleaner of formatted notification texts (0.8.400)
$c = NuHtmlClean::clean('<p onclick="x()" style="color:red">Hi <b>you</b> <script>alert(1)</script><img src=x onerror=alert(1)></p><iframe src="//e"></iframe>'
	. '<a href="javascript:alert(1)">bad</a> <a href=" JaVaScRiPt:alert(1)">bad2</a> <a href="&#106;avascript:alert(1)">bad3</a> <a href="https://example.com/a?b=1&amp;c=2" onmouseover="x">ok</a>'
	. '<span style="x">span</span><ul><li>one</li></ul><!-- c --><svg><script>1</script></svg><a href="index.php?p=home">in</a><a href="mailto:a@b.c">m</a>');
check('htmlclean: no scripts, handlers, styles, iframes, images', !preg_match('/script|onclick|onerror|onmouseover|style=|iframe|<img|<svg|javascript|<!--|<span/i', $c), $c);
check('htmlclean: allowed tags and safe links stay', str_contains($c, '<p>Hi <b>you</b>') && str_contains($c, '<ul><li>one</li></ul>') && str_contains($c, '>span')
	&& str_contains($c, 'href="https://example.com/a?b=1&amp;c=2" target="_blank" rel="noopener noreferrer">ok</a>') && str_contains($c, 'href="index.php?p=home"')
	&& str_contains($c, 'href="mailto:a@b.c"') && str_contains($c, 'bad') && !str_contains($c, 'href="javascript'), $c);
check('htmlclean: text, umlauts, empty', NuHtmlClean::clean('Grüße & <i>mehr</i>') === 'Grüße &amp; <i>mehr</i>' && NuHtmlClean::clean('  ') === ''
	&& NuHtmlClean::toText('<p>a<br>b</p><ul><li>c</li></ul>') === "a\nb\n• c", NuHtmlClean::clean('Grüße & <i>mehr</i>'));
check('notify: bodyHtml by format', NuNotify::bodyHtml('<b>x</b>') === '&lt;b&gt;x&lt;/b&gt;' && NuNotify::bodyHtml('<b>x</b><script>1</script>', 'html') === '<b>x</b>'
	&& NuNotify::bodyText('<p>a</p><p>b</p>', 'html') === "a\nb");

// concurrent edits (0.8.401)
check('conflict: signatures', NuConflict::sig(null) === 'n' && NuConflict::sig('') !== 'n' && NuConflict::sig('1') === NuConflict::sig(1)
	&& NuConflict::rowHash(['a' => 1, 'B' => 'x']) === NuConflict::rowHash(['b' => 'x', 'A' => '1']) && NuConflict::rowHash(['a' => 1]) !== NuConflict::rowHash(['a' => 2])
	&& NuConflict::rowSigs(['Cus_Name' => null]) === ['cus_name' => 'n']);

// form with AI (0.8.403): the JSON of an AI answer
$pa = NuFormSpec::parse("Here it is:\n```json\n{\"format\": \"nunext-form-1\", \"form\": {\"code\": \"x\"}}\n```\nBye");
$pb = NuFormSpec::parse('Text before {"form": {"code": "y"}, "tabs": []} and after');
$pc = NuFormSpec::parse("{\n  \"form\": {\"code\": \"z\", \"u\": \"https://x.ch\"},   // code\n  // a comment the AI copied\n  \"n\": 1 // one\n}");
check('formspec: parse fenced / plain / with comments / errors', is_array($pa) && $pa['form']['code'] === 'x' && is_array($pb) && $pb['form']['code'] === 'y'
	&& is_array($pc) && $pc['form']['code'] === 'z' && $pc['form']['u'] === 'https://x.ch' && is_string(NuFormSpec::parse('no json here')) && is_string(NuFormSpec::parse('{"a": ')));
$help = preg_replace('~//.*$~m', '', explode("\nRules:", NuFormSpec::formatHelp())[0]);
check('formspec: the example of the prompt is valid JSON', is_array(json_decode((string)$help, true)), json_last_error_msg());

// function reference of the Help page (0.8.399): every function of the code is documented, and nothing that is gone
$ref = NuFnRef::load();
check('fnref: docs/nunext-functions.json is valid', isset($ref['php']['items'], $ref['js']['items']) && count($ref['php']['items']) > 150 && count($ref['js']['items']) > 300);
foreach (['php', 'js'] as $lang) {
	$code = NuFnRef::codeNames($lang);
	$doc = NuFnRef::documented($lang);
	check("fnref $lang: every function of the code has an entry", !array_diff($code, $doc), implode(', ', array_diff($code, $doc)));
	check("fnref $lang: every entry is a function of the code", !array_diff($doc, $code), implode(', ', array_diff($doc, $code)));
	$names = array_column($ref[$lang]['items'], 'n');
	check("fnref $lang: names unique", count($names) === count(array_unique($names)), implode(', ', array_keys(array_filter(array_count_values($names), static fn($c) => $c > 1))));
	$groups = array_column($ref[$lang]['groups'], 'id');
	$bad = array_filter($ref[$lang]['items'], static fn($it) => !in_array($it['g'] ?? '', $groups, true) || trim((string)($it['d'] ?? '')) === '' || trim((string)($it['s'] ?? '')) === ''
		|| !in_array($it['o'] ?? '', ['nb', 'next'], true) || !in_array($it['k'] ?? 'fn', ['fn', 'method', 'hook'], true));
	check("fnref $lang: entries complete (group, signature, description, origin)", !$bad, implode(', ', array_column($bad, 'n')));
}
// methods of page forms ($nu) and NuXlsx: the documented ones exist (and every public $nu method is documented)
$nuDoc = array_map(static fn($n) => substr($n, 5), array_filter(array_column($ref['php']['items'], 'n'), static fn($n) => str_starts_with($n, '$nu->')));
$nuCode = array_values(array_diff(NuPage::apiInfo()['methods'], ['deprecated']));
check('fnref: $nu methods = NuPageContext', !array_diff($nuCode, $nuDoc) && !array_diff($nuDoc, $nuCode), implode(', ', array_merge(array_diff($nuCode, $nuDoc), array_diff($nuDoc, $nuCode))));
$miss = [];
foreach (array_column($ref["php"]["items"], "n") as $nm) {
	if (preg_match('/^(?:NuXlsx::|\$x->)(\w+)$/', $nm, $m) && !method_exists('NuXlsx', $m[1])) $miss[] = $nm;
	if (preg_match('/^\$sheet->(\w+)$/', $nm, $m) && !method_exists('NuXlsxSheet', $m[1])) $miss[] = $nm;
}
check('fnref: documented NuXlsx methods exist', !$miss, implode(', ', $miss));
$jsDoc = array_map(static fn($n) => substr($n, 7), array_filter(array_column($ref['js']['items'], 'n'), static fn($n) => str_starts_with($n, 'NuPage.')));
check('fnref: NuPage entries = NuPage::JS_API', !array_diff(NuPage::JS_API, $jsDoc) && !array_diff($jsDoc, NuPage::JS_API));
check('fnref: render', str_contains(NuFnRef::render('php'), 'id="fn-php-nuRunQuery"') && str_contains(NuFnRef::render('js'), 'id="ref-js-hooks"')
	&& NuFnRef::letter('nuRunQuery') === 'R' && NuFnRef::letter('db_fetch_row') === 'F' && NuFnRef::letter('$nu->user') === 'U');

// ---------------------------------------------------------------- library packs (0.8.405): build, check, install, roll back, index
$lpKp = sodium_crypto_sign_keypair();
$lpSk = sodium_crypto_sign_secretkey($lpKp);
$lpPk = base64_encode(sodium_crypto_sign_publickey($lpKp));
$lpTmp = sys_get_temp_dir() . '/nulp-' . bin2hex(random_bytes(4));
mkdir($lpTmp . '/src/sub', 0755, true);
file_put_contents($lpTmp . '/src/PHPMailer.php', "<?php\nnamespace PHPMailer\\PHPMailer;\nclass PHPMailer { const VERSION = '6.9.3'; }\n");
file_put_contents($lpTmp . '/src/sub/a.txt', 'a');
file_put_contents($lpTmp . '/src/nuconfig.php', '<?php // never packed');
NuLibs::$root = $lpTmp . '/site';
NuLibs::$log = false;
mkdir(NuLibs::$root, 0755);
check('libpack: version detected', NuLibs::detectVersion('phpmailer', $lpTmp . '/src') === '6.9.3');
$lpA = NuLibs::build('phpmailer', $lpTmp . '/src', $lpSk, $lpTmp . '/out', '', $lpPk);
$lpB = NuLibs::build('phpmailer', $lpTmp . '/src', $lpSk, $lpTmp . '/out', '6.9.4+nu1', $lpPk);
check('libpack: built', basename($lpA['file']) === 'nunext-lib-phpmailer-6.9.3.zip' && $lpA['files'] === 2 && basename($lpB['file']) === 'nunext-lib-phpmailer-6.9.4_nu1.zip');
$i = NuLibs::inspect($lpA['file'], $lpPk);
check('libpack: inspect ok', $i['ok'] && $i['signature'] === 'valid' && $i['name'] === 'phpmailer', implode(' ', $i['errors']));
check('libpack: nuconfig.php left out', !isset($i['manifest']['files']['nuconfig.php']));
check('libpack: other key = invalid', NuLibs::inspect($lpA['file'])['signature'] === 'invalid');
copy($lpA['file'], $lpTmp . '/same.zip');
$z = new ZipArchive(); $z->open($lpTmp . '/same.zip'); $z->addFromString('lib/sub/a.txt', 'b'); $z->close();       // same size, other content
check('libpack: changed file passes the listing', NuLibs::inspect($lpTmp . '/same.zip', $lpPk)['ok']);
$err = '';
try { NuLibs::install($lpTmp . '/same.zip', 'test', $lpPk); } catch (Throwable $e) { $err = $e->getMessage(); }
check('libpack: changed file refused at install', $err === t('lp_err_hash', 'sub/a.txt') && !NuLibs::installed('phpmailer') && !glob(NuLibs::$root . '/vendor/packs/.nunext-tmp-*'), $err);
copy($lpA['file'], $lpTmp . '/extra.zip');
$z = new ZipArchive(); $z->open($lpTmp . '/extra.zip'); $z->addFromString('lib/evil.php', '<?php'); $z->close();
check('libpack: extra file refused', !NuLibs::inspect($lpTmp . '/extra.zip', $lpPk)['ok']);
copy($lpA['file'], $lpTmp . '/path.zip');
$z = new ZipArchive(); $z->open($lpTmp . '/path.zip'); $m = json_decode((string)$z->getFromName('nunext-lib.json'), true); $m['files']['../x.php'] = $m['files']['PHPMailer.php']; $z->addFromString('nunext-lib.json', json_encode($m)); $z->close();
check('libpack: path outside refused', !NuLibs::inspect($lpTmp . '/path.zip', $lpPk)['ok']);
$r1 = NuLibs::install($lpA['file'], 'test', $lpPk);
$r2 = NuLibs::install($lpB['file'], 'test', $lpPk);
check('libpack: installed side by side', $r1['version'] === '6.9.3' && $r2['prev'] === '6.9.3' && NuLibs::activeVersion('phpmailer') === '6.9.4+nu1'
	&& is_file(NuLibs::$root . '/vendor/packs/phpmailer-6.9.4_nu1/PHPMailer.php') && (string)file_get_contents(NuLibs::$root . '/vendor/packs/phpmailer-6.9.4_nu1/.htaccess') === NuSecurity::DENY
	&& (string)file_get_contents(NuLibs::$root . '/vendor/packs/.htaccess') === NuSecurity::DENY && NuLibs::webPath('phpmailer') === 'vendor/packs/phpmailer-6.9.4_nu1');
check('libpack: state file not readable as PHP output', str_starts_with((string)file_get_contents(NuLibs::$root . '/' . NuLibs::STATE), '<?php exit; ?>'));
check('libpack: roll back', NuLibs::rollback('phpmailer') === '6.9.3' && NuLibs::activeVersion('phpmailer') === '6.9.3' && NuLibs::state()['phpmailer']['prev'] === '6.9.4+nu1');
$idx = NuLibs::buildIndex([$lpB], $lpSk);
$pi = NuLibs::parseIndex($idx, $lpPk);
check('libpack: index', count($pi) === 1 && $pi[0]['file'] === 'nunext-lib-phpmailer-6.9.4_nu1.zip' && $pi[0]['sha256'] === hash_file('sha256', $lpB['file']));
$bad = json_decode($idx, true); $bad['libs'][0]['sha256'] = str_repeat('0', 64);
$err = '';
try { NuLibs::parseIndex(json_encode($bad), $lpPk); } catch (Throwable $e) { $err = $e->getMessage(); }
check('libpack: changed index refused', $err === t('lp_err_index_sig'));
check('libpack: newer', NuLibs::newer('6.9.4', '6.9.3') && NuLibs::newer('2.6+git.1.nu2', '2.6+git.1.nu1') && !NuLibs::newer('6.9.3', '6.10.0'));
check('libpack: key check', (static function () use ($lpSk, $lpPk) { try { NuLibs::secretKey("x\n" . base64_encode($lpSk), $lpPk); } catch (Throwable) { return false; } try { NuLibs::secretKey("x\n" . base64_encode($lpSk)); } catch (Throwable) { return true; } return false; })());
// a browser pack gets the allow .htaccess; an old pack of 0.8.405–0.8.408 in third_party/ counts only while vendor/packs/ has no entry (0.8.409)
mkdir($lpTmp . '/tsrc', 0755, true);
file_put_contents($lpTmp . '/tsrc/tinymce.min.js', '/* TinyMCE version 7.9.1 */');
$lpT = NuLibs::build('tinymce', $lpTmp . '/tsrc', $lpSk, $lpTmp . '/out', '', $lpPk);
NuLibs::install($lpT['file'], 'test', $lpPk);
check('libpack: browser pack opened', (string)file_get_contents(NuLibs::$root . '/vendor/packs/tinymce-7.9.1/.htaccess') === NuLibs::ALLOW && NuLibs::tinymce() === 'vendor/packs/tinymce-7.9.1');
NuLibs::remove('tinymce');
mkdir(NuLibs::$root . '/third_party/tinymce-7.0.1', 0755, true);
file_put_contents(NuLibs::$root . '/third_party/tinymce-7.0.1/tinymce.min.js', '/* TinyMCE version 7.0.1 */');
file_put_contents(NuLibs::$root . '/third_party/nunext-libs.php', "<?php exit; ?>\n" . json_encode(['tinymce' => ['active' => '7.0.1'], 'adminer' => ['active' => '5.0.0']]));
(static function () { $r = new ReflectionProperty('NuLibs', 'legacy'); $r->setValue(null, null); })();
check('libpack: old pack in third_party/', NuLibs::tinymce() === 'third_party/tinymce-7.0.1' && NuLibs::isLegacy('tinymce') && NuLibs::activeVersion('tinymce') === '7.0.1'
	&& NuLibs::activeDir('adminer') === null && !NuLibs::installed('tinymce'));
NuLibs::install($lpT['file'], 'test', $lpPk);
check('libpack: a new pack wins over the old one', NuLibs::tinymce() === 'vendor/packs/tinymce-7.9.1' && !NuLibs::isLegacy('tinymce') && NuLibs::state()['tinymce']['prev'] === '');
NuLibs::remove('tinymce');
check('libpack: after remove the old pack counts again', NuLibs::tinymce() === 'third_party/tinymce-7.0.1');
check('update: vendor/packs protected, vendor/bundled a library', NuUpdate::isProtected('vendor/packs/x') && !NuUpdate::isProtected('vendor/bundled/x') && NuUpdate::isLibrary('vendor/bundled/jquery/jquery.min.js') && NuUpdate::isLibrary('assets/vendor/x') && !NuUpdate::isLibrary('assets/js/nuapp.js'));
mkdir($lpTmp . '/libsrc/nudb', 0755, true);
mkdir($lpTmp . '/libsrc/PHPMailer', 0755, true);
check('libpack: ../libsrc/ found (phpMyAdmin also as nudb)', NuLibs::libsrcDir('phpmyadmin', $lpTmp . '/proj') === $lpTmp . '/libsrc/nudb' && NuLibs::libsrcDir('tcpdf', $lpTmp . '/proj') === '' && NuLibs::libsrcDir('phpmailer', $lpTmp . '/proj') === $lpTmp . '/libsrc/PHPMailer'
	&& NuLibs::shortPath(dirname(NU_ROOT) . '/libsrc/tcpdf') === '../libsrc/tcpdf' && NuLibs::shortPath(NU_ROOT . '/vendor/packs/x') === 'vendor/packs/x');
mkdir($lpTmp . '/proj2/_libsrc/tcpdf', 0755, true);
check('libpack: _libsrc/ inside when ../libsrc/ is not there', NuLibs::libsrcDir('tcpdf', $lpTmp . '/proj2') === $lpTmp . '/proj2/_libsrc/tcpdf' && NuUpdate::isProtected('_libsrc/tcpdf/tcpdf.php'));
NuLibs::remove('phpmailer');
check('libpack: removed', !NuLibs::installed('phpmailer') && NuLibs::activeDir('phpmailer') === null && !NuLibs::state());
check('libpack: pack names', NuLibs::isPackName('nunext-lib-tcpdf-6.8.2.zip') && !NuLibs::isPackName('nunext-lib-tcpdf-../x.zip') && !NuLibs::validRel('a/../b') && NuLibs::validRel('a/b.php'));
NuLibs::$root = NU_ROOT;
(static function (string $d): void { $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($d, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST); foreach ($it as $f) { $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname()); } rmdir($d); })($lpTmp);

// ---------------------------------------------------------------- library versions a release needs (0.8.406)
$rq = NuLibs::required(['requires_libs' => ['tcpdf' => ['min' => '7.0.0', 'needed' => true], 'phpmyadmin' => ['min' => '5.2.0', 'needed' => false],
	'tinymce' => ['min' => '6.0.0'], 'adminer' => ['min' => '5.0.0'], 'nosuch' => ['min' => '1'], 'phpmailer' => ['min' => '../x']]]);
check('required: parsed', array_keys($rq) === ['tcpdf', 'phpmyadmin', 'tinymce', 'adminer'] && $rq['tcpdf']['needed'] && !$rq['tinymce']['needed']);
$av = ['tcpdf' => [['version' => '6.9.0', 'ok' => true, 'where' => 'local'], ['version' => '7.0.19', 'ok' => true, 'where' => 'github'], ['version' => '8.0', 'ok' => false]]];
$um = NuLibs::unmet($rq, ['tcpdf' => '6.8.2', 'phpmyadmin' => '', 'tinymce' => '8.0.0', 'adminer' => '4.8.1'], $av);
check('unmet: old needed, installed optional below', array_keys($um) === ['tcpdf', 'adminer'] && $um['tcpdf']['best']['version'] === '7.0.19' && $um['adminer']['best'] === null);
check('unmet: missing needed', isset(NuLibs::unmet($rq, ['tcpdf' => ''], [])['tcpdf']) && !NuLibs::unmet($rq, ['tcpdf' => '7.0.1', 'adminer' => '5.0.0+nu1'], []));
check('required: release file valid', NuLibs::required() !== [] && isset(NuLibs::required()['tcpdf']));
check('required: no manifest entry = nothing', NuLibs::required(['version' => '0.8.400']) === []);

@unlink($cfg);
echo ($fails ? "$fails of $n tests FAILED" : "all $n tests passed") . "\n";
exit($fails ? 1 : 0);
