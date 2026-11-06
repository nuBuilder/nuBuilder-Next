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
// source map comments whose map is not delivered (0.8.574): removed on install, kept when the map is there or inline / external
$no = static fn($r) => false; $yes = static fn($r) => $r === 'js/a.min.js.map';
check('stripSourceMap js', NuLibs::stripSourceMap('js/a.min.js', "x();\n//# sourceMappingURL=a.min.js.map", $no) === "x();\n");
check('stripSourceMap css', NuLibs::stripSourceMap('c/a.css', "a{}\n/*# sourceMappingURL=a.css.map */\n", $no) === "a{}\n");
check('stripSourceMap map delivered', NuLibs::stripSourceMap('js/a.min.js', "x();\n//# sourceMappingURL=a.min.js.map", $yes) === null);
check('stripSourceMap inline / other files', NuLibs::stripSourceMap('a.js', "x();\n//# sourceMappingURL=data:application/json;base64,e30=", $no) === null
	&& NuLibs::stripSourceMap('a.php', "<?php //# sourceMappingURL=a.map", $no) === null && NuLibs::stripSourceMap('a.js', 'var s = "//# sourceMappingURL=x.map"; y();', $no) === null);
// bundled libraries: no source map comment pointing to a map that is not in the package
$badMaps = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(NU_ROOT . '/vendor/bundled', FilesystemIterator::SKIP_DOTS)) as $bf) {
	$rel = substr($bf->getPathname(), strlen(NU_ROOT) + 1);
	if (preg_match('/\.(js|css)$/', $rel) && NuLibs::stripSourceMap($rel, (string)file_get_contents($bf->getPathname()), static fn($r) => is_file(NU_ROOT . '/' . $r)) !== null) $badMaps[] = $rel;
}
check('bundled: no missing source maps', $badMaps === [], implode(', ', $badMaps));
// Global CSS (0.8.557): a surrounding <style>…</style> is removed, plain CSS and CSS that merely mentions style stay as they are
check('unwrapStyle wrapped', NuCustomCode::unwrapStyle("  <style>\n.a { color: red; }\n</style>\n") === ".a { color: red; }\n");
check('unwrapStyle attributes, two blocks', NuCustomCode::unwrapStyle('<style type="text/css">.a{}</style><STYLE>.b{}</STYLE>') === ".a{}.b{}\n");
check('unwrapStyle plain', NuCustomCode::unwrapStyle('.a { color: red; }') === null && NuCustomCode::unwrapStyle('/* <style> */ .a {}') === null);
// NuListDefs (0.8.553): self::col(…) + ['select' / 'sort' / 'text' / 'label' / 'class' => …] keeps col()'s own key – use array_replace()
preg_match_all("/self::(?:col|badgeCol)\\([^;\\n]*?\\) \\+ \\[[^\\n]*?'(?:select|sort|text|label|class)' =>/", (string)file_get_contents(NU_ROOT . '/core/nulistdefs.php'), $plusCol);
check('list columns: no key dropped by +', $plusCol[0] === [], implode(' | ', $plusCol[0]));

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
		if ($c === 'NuTCPDF' || $c === 'NuSpreadsheetFilter') continue;     // need TCPDF / the PhpSpreadsheet pack first (loaded by hand)
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
foreach (['N|€ 1.000,00' => ['-99.95', '€ -99,95'], 'N|USD 1,000.00' => ['-1234.5', 'USD -1,234.50'], 'N|1000.00' => ['-5', '-5.00'], 'N|1000.0 %' => ['-7.25', '-7.3 %'],
	'N|1000.0' => ['-0.04', '-0.0']] as $f => [$v, $want]) {   // a negative value that rounds to 0 keeps its minus, as nuBuilder Forte (0.8.965)
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
// 0.8.930: comments at the end of the browse SQL are taken off (the wrapped SQL would be commented out)
[$in4] = NuBrowse::build("SELECT a FROM t\n-- note\n/* x */", 'id', [['index' => 0, 'expr' => 'a']]);
check('browse: trailing comments', $in4 === 'SELECT id AS nu_pk, a AS nu_c0 FROM t', $in4);
check('browse: comment marks inside a string kept', NuBrowse::trimTrailingComments("SELECT 'a -- b'") === "SELECT 'a -- b'");
// nuBuilder Forte 4.x announcements (0.8.958): 2FA safe addresses, nuOnProcess…() bodies, print settings
check('2fa safe ip: exact', NuAuth::ipPatternMatches('192.168.1.10', '192.168.1.10') && !NuAuth::ipPatternMatches('192.168.1.10', '192.168.1.11'));
check('2fa safe ip: wildcard', NuAuth::ipPatternMatches('192.168.*.*', '192.168.7.3') && !NuAuth::ipPatternMatches('192.168.*.*', '10.0.0.1'));
check('2fa safe ip: cidr v4 / v6', NuAuth::ipPatternMatches('10.0.0.0/8', '10.20.30.40') && NuAuth::ipPatternMatches('2001:db8::/32', '2001:db8:1::5') && !NuAuth::ipPatternMatches('2001:db8::/32', '2001:dead::1'));
check('2fa safe ip: v6 exact + wildcard', NuAuth::ipPatternMatches('::1', '::1') && NuAuth::ipPatternMatches('2001:db8::*', '2001:db8::abcd') && !NuAuth::ipPatternMatches('2001:db8::*', '192.168.0.1'));
check('onprocess: body found', NuOnProcess::body("x();\nfunction nuOnProcessObjects() {\n if (1) { \$a = 1; }\n}\n", 'nuOnProcessObjects') === "{\n if (1) { \$a = 1; }\n}");
check('onprocess: none', NuOnProcess::body('function other() {}', 'nuOnProcessObjects') === null);
check('print: numbers', NuPrintView::numbers(base64_encode('0,2')) === [0, 2] && NuPrintView::numbers('1,3') === [1, 3] && NuPrintView::numbers('[4]') === [4]);
$pc = [0 => ['index' => 0, 'width' => 90], 1 => ['index' => 1, 'width' => 0], 2 => ['index' => 2, 'width' => 50]];
check('print: default = shown columns', array_keys(NuPrintView::columns($pc, [])) === [0, 2]);
check('print: include hidden', array_keys(NuPrintView::columns($pc, ['nuPrintIncludeHiddenColumns' => '1'])) === [0, 1, 2]);
check('print: included only', array_keys(NuPrintView::columns($pc, ['nuPrintIncludedColumns' => base64_encode('1')])) === [1]);
check('print: excluded', array_keys(NuPrintView::columns($pc, ['nuPrintExcludedColumns' => base64_encode('2')])) === [0]);
check('print: csv options', NuPrintView::csvOptions(['nuPrintCSVExportOptions' => base64_encode('{"delimiter":";","autoDownload":1,"x":2}')]) === ['delimiter' => ';', 'autoDownload' => true]);
// responsive containers (0.8.959)
check('responsive: stack cleaned', NuResponsive::cleanStack(['dir' => 'h', 'gap' => '99', 'align' => 'x', 'wrap' => '1']) === ['dir' => 'h', 'gap' => 64, 'wrap' => true, 'phone_v' => true]);
check('responsive: grid columns', NuResponsive::cleanStack(['dir' => 'g', 'cols' => ['lg' => 3]]) === ['dir' => 'g', 'cols' => ['lg' => 3, 'md' => 3, 'sm' => 1]]);
check('responsive: no direction = none', NuResponsive::cleanStack(['dir' => '']) === null && NuResponsive::cleanStack(null) === null);
check('responsive: size', NuResponsive::cleanSize(['w' => 'fixed', 'px' => '5', 'grow' => 3]) === ['w' => 'fixed', 'px' => 10] && NuResponsive::cleanSize(['w' => 'fill', 'px' => 100, 'grow' => 2]) === ['w' => 'fill', 'grow' => 2]);
check('responsive: hide', NuResponsive::cleanHide(['sm', 'x']) === ['sm'] && NuResponsive::cleanHide(['sm', 'md', 'lg']) === null);
check('responsive: hide class', NuResponsive::hideClass(['nunext' => ['hide' => ['sm', 'lg']]]) === ' nuHideSm nuHideLg');
[$rc, $rs] = NuResponsive::box(['dir' => 'g', 'gap' => 8, 'cols' => ['lg' => 3, 'md' => 2, 'sm' => 1]]);
check('responsive: box', $rc === 'nuStack nuStack-g' && $rs === '--nu-stack-gap:8px;--nu-stack-cols-lg:3;--nu-stack-cols-md:2;--nu-stack-cols-sm:1;', $rc . ' ' . $rs);
[$ic, $is] = NuResponsive::item(['nunext' => []], ['dir' => 'h']);
check('responsive: item default in a row = fill', $ic === 'nuStackItem nuStackW-fill' && $is === '--nu-stack-grow:1;');
check('responsive: grid keys only sent ones', NuResponsive::gridKeys(['box' => 'b1', 'stack' => ['dir' => 'v']], 'contentbox') === ['box' => 'b1', 'stack' => ['dir' => 'v']]
	&& NuResponsive::gridKeys(['stack' => ['dir' => 'v']], 'input') === ['stack' => null]);
// AI context pack (0.8.961): help page → Markdown, function reference compact / full
$md = NuAiContext::htmlToMd('<section><h2>Title</h2><p class="doc-lead"><span class="badge doc-adv">Advanced</span> Lead <code>nuX()</code>.</p><p class="doc-for">For: x</p>'
	. '<ul><li>one</li><li><strong>two</strong></li></ul><figure><svg></svg></figure><table><tr><th>A</th><th>B</th></tr><tr><td>1</td><td>2|3</td></tr></table></section>');
check('ai context: help page as Markdown', $md === "### Title\n\nLead `nuX()`.\n\n- one\n- **two**\n\n| A | B |\n| --- | --- |\n| 1 | 2\\|3 |", $md);
$fc = NuAiContext::functions('js', false); $ff = NuAiContext::functions('js', true);
check('ai context: compact reference smaller, no custom pages', strlen($fc) < strlen($ff) && str_contains($fc, '`nuGetValue(') && !str_contains($fc, 'NuPage.fetch') && str_contains($ff, 'NuPage.fetch'));
check('ai context: file name', NuAiContext::fileName('form-x', 'md') === 'nunext-context-form-x-' . NU_VERSION . '.md');
// HTML objects: an unclosed element must not swallow the following objects (0.8.957)
$cf = NuRender::closeFragment('<big>&#9878; Balances<big>');
check('label fragment: unclosed big closed inside the label', $cf === '<big>&#9878; Balances<big></big></big>', $cf);
check('flow layout: tiny HTML object = code holder', NuRender::isCodeHolder(['sob_all_type' => 'html', 'sob_all_width' => 20, 'sob_all_height' => 20])
	&& !NuRender::isCodeHolder(['sob_all_type' => 'html', 'sob_all_width' => 300, 'sob_all_height' => 20]) && !NuRender::isCodeHolder(['sob_all_type' => 'word', 'sob_all_width' => 20, 'sob_all_height' => 20]));
check('flow layout: run button keeps its height', LayoutSize::flowStyle(['sob_all_type' => 'run', 'sob_all_height' => 30, 'sob_run_method' => 'b']) === 'height:30px;'
	&& LayoutSize::flowStyle(['sob_all_type' => 'run', 'sob_all_height' => 30, 'sob_run_method' => 'i']) === 'height:60px;');
$cf = NuRender::closeFragment('<iframe src="x">');
check('html fragment: unclosed iframe closed', $cf === '<iframe src="x"></iframe>', $cf);
$cf = NuRender::closeFragment('<div><b>a</div></span>');
check('html fragment: containers balanced, stray end tag dropped', $cf === '<div><b>a</b></div>', $cf);
$cf = NuRender::closeFragment('<n><small>Note<s/mall></n></small>');
check('html fragment: "<s/mall>" (an s tag) is closed – no strike-through after the object (0.8.978)', str_contains($cf, '</s>') && substr_count($cf, '<s') === substr_count($cf, '</s>') + 1, $cf);
$cf = NuRender::closeFragment('<p>x<br><img src="a.png"/></p><!-- open');
check('html fragment: void tags kept, comment closed', $cf === '<p>x<br><img src="a.png"/></p><!-- open-->', $cf);
$cf = NuRender::closeFragment('<script>if (a < b) "</div>";</script><div>ok</div>');
check('html fragment: script content untouched', $cf === '<script>if (a < b) "</div>";</script><div>ok</div>', $cf);
// 0.8.731: a column naming an alias of the field list gets the alias's expression – unless it is a real column or a source is unknown
$bCols = static fn(string $t): array => ['tcustomer' => ['customer_id', 'cus_code', 'cus_name', 'cid'], 'tcountry' => ['id', 'name']][$t] ?? [];
$bSql = "SELECT c.*, CONCAT(c.cus_code, ' ', c.cus_name) AS full_name, t.name AS tname, c.cus_code AS name, COUNT(*) AS n FROM tcustomer c LEFT JOIN tcountry t ON t.id = c.cid GROUP BY c.customer_id ORDER BY full_name";
[$in4, $ord4] = NuBrowse::build($bSql, 'customer_id', [['index' => 0, 'expr' => 'full_name'], ['index' => 1, 'expr' => '`tname`'], ['index' => 2, 'expr' => 'name'], ['index' => 3, 'expr' => 'n'], ['index' => 4, 'expr' => 'c.full_name'], ['index' => 5, 'expr' => 'UPPER(full_name)']], $bCols);
check('browse: alias of the field list resolved', str_contains($in4, "CONCAT(c.cus_code, ' ', c.cus_name) AS nu_c0") && str_contains($in4, 't.name AS nu_c1') && str_contains($in4, 'COUNT(*) AS nu_c3'), $in4);
check('browse: real column wins, qualified / other expressions unchanged', str_contains($in4, 'name AS nu_c2') && !str_contains($in4, 'c.cus_code AS nu_c2')
	&& str_contains($in4, 'c.full_name AS nu_c4') && str_contains($in4, 'UPPER(full_name) AS nu_c5') && str_contains($in4, "CONCAT(c.cus_code, ' ', c.cus_name) AS nu_o0"), $in4);
[$in5] = NuBrowse::build("SELECT x.a AS al FROM (SELECT a FROM t) x", 'id', [['index' => 0, 'expr' => 'al']], $bCols);
[$in6] = NuBrowse::build("SELECT u.a AS al FROM tunknown u", 'id', [['index' => 0, 'expr' => 'al']], $bCols);
check('browse: alias kept with a derived or unknown table', str_contains($in5, 'al AS nu_c0') && !str_contains($in5, 'x.a AS nu_c0') && str_contains($in6, 'al AS nu_c0') && !str_contains($in6, 'u.a AS nu_c0'), $in5 . ' | ' . $in6);
[$in7] = NuBrowse::build('SELECT *, CONCAT(prefix, number, postfix) AS anumber FROM apartment', 'apartment_id', [['index' => 0, 'expr' => 'anumber']],
	static fn(string $t): array => $t === 'apartment' ? ['apartment_id', 'prefix', 'number', 'postfix'] : []);
check('browse: the tester\'s case (SELECT *, … AS anumber)', $in7 === 'SELECT apartment_id AS nu_pk, CONCAT(prefix, number, postfix) AS nu_c0 FROM apartment', $in7);
check('browse: sources of FROM', NuBrowse::sourceColumns('FROM tcustomer c, tcountry t', $bCols) !== null && NuBrowse::sourceColumns('FROM db2.tcustomer c', $bCols) === null
	&& NuBrowse::sourceColumns('FROM tcustomer c JOIN (SELECT 1) z ON 1 = 1', $bCols) === null);
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
// Reset database (0.8.628): a complete dump – the application's tables allowed, the other rules stay
$full = $okDump . "CREATE TABLE `customers` (`id` int);\nLOCK TABLES `customers` WRITE;\nINSERT INTO `customers` VALUES (1),(2);\nUNLOCK TABLES;\nINSERT INTO `zzzzsys_user` (`zzzzsys_user_id`) VALUES ('u1'),('u2'),('u3');\n";
check('dump: application tables refused for the installer', !NuInstall::checkDump($full)['ok']);
$r = NuInstall::checkDump($full, true);
check('dump: complete dump for Reset database', $r['ok'] && $r['app'] === 1 && $r['tables'] === 6 && $r['users'] === 3, json_encode($r));
foreach (['USE' => 'USE mysql', 'GRANT' => "GRANT ALL ON *.* TO 'x'@'%'", 'OUTFILE' => "SELECT * FROM customers INTO OUTFILE '/tmp/x'", 'trigger' => 'CREATE TRIGGER t BEFORE INSERT ON customers FOR EACH ROW SET @a = 1',
	'other database' => 'CREATE TABLE mysql.x (a int)', 'INSERT … SELECT' => 'INSERT INTO customers SELECT * FROM mysql.user'] as $what => $stmt) {
	check('dump (any table): refused ' . $what, !NuInstall::checkDump($full . $stmt . ";\n", true)['ok']);
}
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
check('library CVE status: fixed / patched count as dealt with', NuSecurity::cveFixed('fixed in 2.7') && NuSecurity::cveFixed('patched locally 2026-10-23') && NuSecurity::cveFixed('Patched')
	&& !NuSecurity::cveFixed('open – no fix') && !NuSecurity::cveFixed('unpatched') && !NuSecurity::cveFixed(''));
// ---------------------------------------------------------------- start page view (NuHome, 0.8.420)
$lv = NuHome::legacyView(['size' => 'list2', 'display' => 'tabs']);
check('home view: a layout of before 0.8.420 keeps its look', $lv['style'] === 'list' && $lv['listCols'] === '2' && $lv['nav'] === 'tabs' && $lv['delay'] === 800
	&& NuHome::compat($lv) === ['list2', 'tabs'] && NuHome::legacyView(['size' => 'listwide'])['listWidth'] === 'full' && NuHome::legacyView([])['style'] === 'large');
$cv = NuHome::cleanView(['style' => 'bogus', 'desc' => 'hovercard', 'lines' => '3', 'delay' => 5, 'groupColors' => '1', 'x' => 1, 'tabStyle' => ['t_a' => 'panels', 't_z' => 'cards', 't_b' => 'nope'],
	'sizes' => ['phone' => ['nav' => 'sidebar', 'style' => 'nope'], 'watch' => ['style' => 'list'], 'tablet' => []]], ['t_a' => true, 't_b' => true], false);
check('home view: cleaned (wrong values → default, unknown keys / tabs / sizes dropped)', $cv['style'] === 'large' && $cv['desc'] === 'hovercard' && $cv['lines'] === 3 && $cv['delay'] === 300
	&& $cv['groupColors'] === true && !isset($cv['x']) && (array)$cv['tabStyle'] === ['t_a' => 'panels'] && json_encode($cv['sizes']) === '{"phone":{"nav":"sidebar"}}', json_encode($cv));
$map = ['large' => ['large', 'tabs'], 'cards' => ['large', 'tabs'], 'compact' => ['compact', 'tabs'], 'launcher' => ['compact', 'tabs'], 'panels' => ['list', 'tabs'], 'table' => ['listwide', 'tabs']];
$okMap = true;
foreach ($map as $st => $want) $okMap = $okMap && NuHome::compat(['style' => $st, 'nav' => 'sidebar']) === $want;
check('home view: size / display derived (hook variables, Customise)', $okMap && NuHome::compat(['style' => 'list', 'listCols' => '1', 'listWidth' => 'full', 'nav' => 'none']) === ['listwide', 'groups']
	&& NuHome::compat(['style' => 'list', 'listCols' => 'auto', 'nav' => 'groups'])[0] === 'list2');
$oldDef = ['v' => 2, 'display' => 'tabs', 'size' => 'compact', 'tabs' => [['id' => 't_a', 'title' => 'A']], 'groups' => [['id' => 'g_a', 'title' => 'G', 'tab' => 't_a', 'tiles' => ['users'], 'color' => '#ABCDEF']], 'hidden' => [], 'custom' => [], 'fav' => []];
$c1 = NuHome::clean($oldDef, false)[0];
$newDef = $c1; $newDef['view'] = ['style' => 'panels', 'nav' => 'sidebar', 'tabStyle' => ['t_a' => 'table', 'g_a' => 'cards'], 'sizes' => ['tablet' => ['style' => 'cards']]];
$c2 = NuHome::clean($newDef, false)[0];
check('home view: clean() is stable for old and new layouts', json_encode(NuHome::clean($c1, false)[0]) === json_encode($c1) && json_encode(NuHome::clean($c2, false)[0]) === json_encode($c2)
	&& $c1['view']['style'] === 'compact' && $c1['groups'][0]['color'] === '#abcdef' && $c2['size'] === 'list' && $c2['display'] === 'tabs' && json_encode($c2['view']['tabStyle']) === '{"t_a":"table"}');
$ov = NuHome::cleanOverlay(['view' => ['style' => 'cards', 'tabStyle' => ['t_a' => 'list'], 'nav' => 'x'], 'sizes' => ['phone' => ['desc' => 'off'], 'tablet' => ['nav' => 'nope']], 'at' => 'yesterday']);
check('home view: personal view cleaned', $ov['view'] === ['style' => 'cards'] && $ov['sizes'] === ['phone' => ['desc' => 'off']] && preg_match('/^\d{4}-/', $ov['at']) === 1
	&& NuHome::cleanOverlay(['view' => ['style' => 'nope']]) === []);
// user start pages (0.8.425): conditions, a user's own favourites / hidden tiles, report tiles
$cc = NuHome::cleanCond(['team' => 'Sales, Support ,,Sales', 'department' => [' HR ', '', ['x']], 'position' => str_repeat('p', 80), 'colour' => ['red'], 'permission' => []]);
check('user start page: conditions cleaned', $cc === ['team' => ['Sales', 'Support'], 'department' => ['HR'], 'position' => [str_repeat('p', 60)]]
	&& NuHome::cleanCond('team') === [] && count(NuHome::cleanCond(['team' => range(1, 30)])['team']) === 20);
$ovm = NuHome::cleanOverlay(['fav' => ['c_a', 'c_a', 'BAD KEY', 7], 'hide' => array_map(static fn($i) => 'c_' . $i, range(1, 80))]);
check('user start page: own favourites and hidden tiles', $ovm['fav'] === ['c_a'] && count($ovm['hide']) === NuHome::MINE_MAX && $ovm['view'] === []
	&& array_key_exists('fav', NuHome::cleanOverlay(['fav' => []])) && !array_key_exists('fav', NuHome::cleanOverlay(['hide' => ['c_b']])));
[$ct] = NuHome::cleanTile(['label' => 'R', 'kind' => 'report', 'target' => 'x', 'cond' => ['team' => 'A']], false);
[$ct2] = NuHome::cleanTile(['label' => 'L', 'kind' => 'url', 'target' => 'https://x'], false);
$cl = NuHome::clean(['groups' => [['id' => 'g_a', 'tiles' => [], 'cond' => ['position' => 'Lead']], ['id' => 'g_b', 'tiles' => []]]], false)[0];
check('user start page: report kind and conditions kept', $ct['kind'] === 'report' && $ct['cond'] === ['team' => ['A']] && !isset($ct2['cond'])
	&& $cl['groups'][0]['cond'] === ['position' => ['Lead']] && !isset($cl['groups'][1]['cond']));
// PHP 8.1 is the minimum (0.8.430): functions and constants of PHP 8.2+ only behind a check – PHPStan's phpVersion does not know constants
// ("Undefined constant CURLOPT_XFERINFOFUNCTION" stopped the GitHub download on PHP 8.1). Quoted names (defined('…'), constant('…')) and comments are fine.
$newer = '/(?<![\'"\w$>:])(json_validate|mb_str_pad|mb_trim|mb_ltrim|mb_rtrim|mb_ucfirst|mb_lcfirst|str_increment|str_decrement|array_find|array_find_key|array_any|array_all'
	. '|memory_reset_peak_usage|ini_parse_quantity|curl_upkeep|openssl_cipher_key_length|mysqli_execute_query|stream_context_set_options|http_get_last_response_headers'
	. '|request_parse_body|bcround|bcceil|bcfloor|grapheme_str_split)\s*\(|(?<![\'"\w])(CURLOPT_XFERINFOFUNCTION|CURLOPT_AWS_SIGV4|CURLOPT_PREREQFUNCTION|CURLOPT_DEBUGFUNCTION'
	. '|CURLOPT_SERVER_RESPONSE_TIMEOUT|CURLINFO_CAINFO|CURLINFO_CAPATH|CURLINFO_CONN_ID|CURLINFO_QUEUE_TIME_T|CURLINFO_USED_PROXY|CURLINFO_HTTPAUTH_USED|CURLINFO_POSTTRANSFER_TIME_T)\b'
	. '|\breadonly\s+class\b|#\[\\?Override\]/';
$hits = [];
$files = array_merge(glob(NU_ROOT . '/{core,core/form,pages,install}/*.php', GLOB_BRACE) ?: [], array_map(static fn($f) => NU_ROOT . '/' . $f, ['index.php', 'api.php', 'nucron.php', 'nudbadmin.php']));
foreach ($files as $f) {
	foreach (file($f) ?: [] as $no => $line) {
		if (preg_match('~^\s*(\*|/\*|//)~', $line)) continue;                 // comment lines
		if (preg_match($newer, (string)preg_replace('~//.*$~', '', $line), $m)) $hits[] = basename($f) . ':' . ($no + 1) . ' ' . trim($m[0]);
	}
}
check('PHP 8.1: no PHP 8.2+ functions / constants without a check', !$hits, implode(', ', array_slice($hits, 0, 5)));
check('PHP 8.1: cURL progress option', nu_curl_progress_opt() === (defined('CURLOPT_XFERINFOFUNCTION') ? constant('CURLOPT_XFERINFOFUNCTION') : CURLOPT_PROGRESSFUNCTION));

// browse search terms as nuBuilder reads them (0.8.427): words, "phrases", -exclusions
$bt = NuBrowse::terms('  Alpen  "Mont Blanc" -GmbH -"Lago Verde" - x x "" "open');
check('browse: search terms (words, phrases, exclusions)', $bt === ['in' => ['Alpen', 'Mont Blanc', '-', 'x', '"open'], 'out' => ['GmbH', 'Lago Verde']]
	&& NuBrowse::terms('') === ['in' => [], 'out' => []] && NuBrowse::terms('Zürich -') === ['in' => ['Zürich', '-'], 'out' => []]);
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
check('xlsx: header frozen with autofilter', str_contains($s1, 'state="frozen"') && str_contains($s1, '<autoFilter ref="A1:D4"/>') && str_contains($wb, '_xlnm._FilterDatabase'));
// 0.8.416: revised writer – _x escaping, 15 significant digits, recalculation, _xlfn., old dates as text, false removes a row style, merges
$x2 = NuXlsx::create('T');
$s2 = $x2->sheet('History');
$s2->row(['ABC_x0041_', 1e-20, NuXlsx::formula('TEXTJOIN(",",TRUE,A1:A2)', [], 'a'), new DateTime('1899-06-15'), NAN])
	->row([NuXlsx::cell('plain', ['bold' => false])], ['bold' => true])->height(3, 30)->merge('A5:A5');
$mergeErr = false;
try { $s2->merge('A6:B7')->merge('B7:C8'); } catch (InvalidArgumentException) { $mergeErr = true; }
$arrErr = false;
try { $s2->row([[1, 2]]); } catch (InvalidArgumentException) { $arrErr = true; }
$tmp = tempnam(sys_get_temp_dir(), 'xt'); file_put_contents($tmp, $x2->toString());
$z = new ZipArchive(); $p2 = [];
if ($z->open($tmp) === true) { for ($i = 0; $i < $z->numFiles; $i++) $p2[$z->getNameIndex($i)] = (string)$z->getFromIndex($i); $z->close(); }
@unlink($tmp);
$w2 = $p2['xl/worksheets/sheet1.xml'] ?? ''; $ss2 = $p2['xl/sharedStrings.xml'] ?? ''; $wb2 = $p2['xl/workbook.xml'] ?? '';
check('xlsx: revised writer (escaping, numbers, formulas, dates, styles, merges, History, arrays)',
	str_contains($ss2, 'ABC_x005F_x0041_') && str_contains($w2, '<v>1.0E-20</v>') && str_contains($w2, '_xlfn.TEXTJOIN') && str_contains($wb2, 'fullCalcOnLoad="1"')
	&& str_contains($ss2, '1899-06-15') && !str_contains($ss2, 'NAN') && str_contains($wb2, 'name="History 2"') && (bool)preg_match('/<row r="3"[^>]*customHeight="1"/', $w2)
	&& !str_contains($w2, 'ref="A5:A5"') && $mergeErr && $arrErr && NuXlsx::rangeRef(1, 1, 3, 2) === 'A1:B3');
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
// list cells / titles as HTML (0.8.945)
$c = NuHtmlClean::cell('<i class="fas fa-times" onclick="x()"></i> <b style="color:red">Ok</b><script>alert(1)</script><img src=x onerror=alert(1)><a href="javascript:alert(1)">j</a><a href="index.php?p=x" target="_blank">l</a><span style="background:url(x)">s</span>');
check('htmlclean cell: icons and formatting kept, scripts / events / bad links removed', str_contains($c, '<i class="fas fa-times"></i>') && str_contains($c, '<b style="color:red">Ok</b>')
	&& !str_contains($c, 'script') && !str_contains($c, 'onerror') && !str_contains($c, 'onclick') && !str_contains($c, 'javascript') && str_contains($c, '<img src="x">')
	&& str_contains($c, 'rel="noopener noreferrer"') && str_contains($c, '<span>s</span>'), $c);
check('htmlclean cell: plain text stays text', NuHtmlClean::cell('a < b & c') === 'a &lt; b &amp; c' && NuHtmlClean::cell('AT&T') === 'AT&amp;T'
	&& NuHtmlClean::cellText('<i class="x"></i> Name &amp; more') === 'Name & more' && NuHtmlClean::cellText('a < b') === 'a < b', NuHtmlClean::cell('a < b & c'));
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

// help: one file per page in docs/pages/, listed in docs/toc.json (0.8.493) – ids unique over all files, every #link points to an id
$helpIds = []; $helpLinks = []; $tocFiles = []; $pageProblems = [];
try { $fnHtml = NuFnRef::tabs(); } catch (Throwable) { $fnHtml = ''; }
$toc = json_decode((string)@file_get_contents(NU_ROOT . '/docs/toc.json'), true);
check('help: docs/toc.json is valid', is_array($toc) && !empty($toc['chapters']));
$rank = static function (string $sec): int {                  // how restrictive a section is: 0 everybody, 1 acl, 2 admin, 3 dev
	if (preg_match('/<section\b[^>]*\bdata-doc="dev"/', $sec)) return 3;
	if (preg_match('/<section\b[^>]*\bdata-doc="admin"/', $sec)) return 2;
	return preg_match('/<section\b[^>]*\bdata-acl="/', $sec) ? 1 : 0;
};
foreach ((array)($toc['chapters'] ?? []) as $ch) {
	$chSrc = (string)@file_get_contents(NU_ROOT . '/docs/pages/' . ($ch['file'] ?? ''));
	$chRank = $rank($chSrc);
	foreach (array_merge([$ch], (array)($ch['pages'] ?? [])) as $pg) {
		$f = (string)($pg['file'] ?? '');
		$tocFiles[] = $f;
		$src = (string)@file_get_contents(NU_ROOT . '/docs/pages/' . $f);
		if ($src === '') { $pageProblems[] = "$f missing"; continue; }
		$src = (string)preg_replace('/<!--.*?-->/s', '', $src);
		if (preg_match_all('/<section\b/', $src) !== 1 || preg_match_all('/<h2\b/', $src) !== 1) $pageProblems[] = "$f: not one section / h2";
		if (preg_match('/\b0\.8\.\d+/', $src)) $pageProblems[] = "$f: version number";
		if ($pg !== $ch && $rank($src) < $chRank) $pageProblems[] = "$f: chapter more restrictive than the page";
		$src = str_replace('<div data-nu-fnref="tabs"></div>', $fnHtml, $src);
		preg_match_all('/\sid="([^"]+)"/', $src, $m);
		foreach ($m[1] as $id) $helpIds[$id][] = $f;
		preg_match_all('/href="#([^"]+)"/', $src, $m);
		foreach ($m[1] as $l) $helpLinks[$l] = $f;
	}
}
// 0.8.884: reading level Essentials – data-level="detail" only on passages (p / li / tr / pre / div without heading), never on the lead /
// audience / see-also lines, the first paragraph after a heading, or the only content of a topic: every page and sub-section stays readable
$detBad = [];
foreach (glob(NU_ROOT . '/docs/pages/*.htm') ?: [] as $f) {
	$src = (string)file_get_contents($f);
	if (!str_contains($src, 'data-level="detail"')) continue;
	$d = new DOMDocument();
	@$d->loadHTML('<?xml encoding="utf-8"?><div id="nuRoot">' . $src . '</div>', LIBXML_NOERROR | LIBXML_NOWARNING);
	$xp = new DOMXPath($d);
	$b = basename($f);
	foreach ($xp->query('//*[@data-level="detail"]') ?: [] as $el) {
		/** @var DOMElement $el */
		$cls = ' ' . $el->getAttribute('class') . ' ';
		if (!in_array($el->nodeName, ['p', 'li', 'tr', 'pre', 'div', 'ul', 'ol', 'table'], true) || $xp->query('.//h2 | .//h3', $el)->length
			|| preg_match('/ doc-(lead|for|see|sub) /', $cls)) { $detBad[] = "$b: <{$el->nodeName}>"; continue; }
		$prev = $el->previousSibling;
		while ($prev && !($prev instanceof DOMElement)) $prev = $prev->previousSibling;
		if ($prev && in_array($prev->nodeName, ['h2', 'h3'], true)) $detBad[] = "$b: first paragraph after " . trim($prev->textContent);
	}
	foreach ($xp->query('//section | //div[contains(concat(" ", @class, " "), " doc-sub ")]') ?: [] as $box) {
		/** @var DOMElement $box */
		if ($box->getAttribute('data-level') === 'advanced') continue;
		$kept = 0;
		foreach ($box->childNodes as $c) {
			if ($c instanceof DOMElement && in_array($c->nodeName, ['p', 'ul', 'ol', 'table', 'pre', 'figure', 'dl', 'div'], true)
				&& $c->getAttribute('data-level') !== 'detail' && !preg_match('/\bdoc-(for|see|sub)\b/', $c->getAttribute('class'))) $kept++;
		}
		if ($kept === 0) $detBad[] = "$b: #" . $box->getAttribute('id') . ' empty in Essentials';
	}
}
check('help: detail passages keep every topic readable in Essentials', !$detBad, implode(' | ', array_slice($detBad, 0, 6)));

// 0.8.885: no column of an own table is a reserved word of SQL Server or MySQL (CREATE TABLE writes the names unquoted – "backup"
// broke the installation on SQL Server)
$reserved = array_flip(explode(' ', 'ADD ALL ALTER AND ANY AS ASC AUTHORIZATION BACKUP BEGIN BETWEEN BREAK BROWSE BULK BY CASCADE CASE CHECK '
	. 'CHECKPOINT CLOSE CLUSTERED COALESCE COLLATE COLUMN COMMIT COMPUTE CONSTRAINT CONTAINS CONTAINSTABLE CONTINUE CONVERT CREATE CROSS CURRENT '
	. 'CURRENT_DATE CURRENT_TIME CURRENT_TIMESTAMP CURRENT_USER CURSOR DATABASE DBCC DEALLOCATE DECLARE DEFAULT DELETE DENY DESC DISK DISTINCT '
	. 'DISTRIBUTED DOUBLE DROP DUMP ELSE END ERRLVL ESCAPE EXCEPT EXEC EXECUTE EXISTS EXIT EXTERNAL FETCH FILE FILLFACTOR FOR FOREIGN FREETEXT '
	. 'FREETEXTTABLE FROM FULL FUNCTION GOTO GRANT GROUP HAVING HOLDLOCK IDENTITY IDENTITY_INSERT IDENTITYCOL IF IN INDEX INNER INSERT INTERSECT '
	. 'INTO IS JOIN KEY KILL LEFT LIKE LINENO LOAD MERGE NATIONAL NOCHECK NONCLUSTERED NOT NULL NULLIF OF OFF OFFSETS ON OPEN OPENDATASOURCE '
	. 'OPENQUERY OPENROWSET OPENXML OPTION OR ORDER OUTER OVER PERCENT PIVOT PLAN PRECISION PRIMARY PRINT PROC PROCEDURE PUBLIC RAISERROR READ '
	. 'READTEXT RECONFIGURE REFERENCES REPLICATION RESTORE RESTRICT RETURN REVERT REVOKE RIGHT ROLLBACK ROWCOUNT ROWGUIDCOL RULE SAVE SCHEMA '
	. 'SECURITYAUDIT SELECT SESSION_USER SET SETUSER SHUTDOWN SOME STATISTICS SYSTEM_USER TABLE TABLESAMPLE TEXTSIZE THEN TO TOP TRAN TRANSACTION '
	. 'TRIGGER TRUNCATE TSEQUAL UNION UNIQUE UNPIVOT UPDATE UPDATETEXT USE USER VALUES VARYING VIEW WAITFOR WHEN WHERE WHILE WITH WITHIN WRITETEXT '
	. 'ACCESSIBLE ANALYZE BEFORE BIGINT BINARY BLOB BOTH CALL CHANGE CHAR CHARACTER CONDITION DATABASES DEC DECIMAL DELAYED DESCRIBE DIV DUAL '
	. 'EACH ELSEIF ENCLOSED ESCAPED EXPLAIN FALSE FLOAT FORCE FULLTEXT GENERATED GET GROUPS IGNORE INFILE INOUT INT INTEGER INTERVAL ITERATE KEYS '
	. 'LEADING LEAVE LIMIT LINES LOCK LONG LOOP MATCH MOD MODIFIES NATURAL NUMERIC OUT PARTITION PURGE RANGE RANK READS REAL REGEXP RELEASE RENAME '
	. 'REPEAT REPLACE REQUIRE RLIKE ROW ROWS SIGNAL SMALLINT SPATIAL SQL TRAILING TRUE UNDO UNLOCK UNSIGNED USAGE USING VARCHAR WINDOW WRITE XOR'));
$resBad = [];
foreach ((new ReflectionClassConstant('NuSchema', 'TABLES'))->getValue() as $tbl => $def) {
	foreach (array_merge([(string)$def['pk']], array_map(static fn($c) => (string)$c[0], (array)$def['cols'])) as $col) {
		if (isset($reserved[strtoupper($col)])) $resBad[] = "$tbl.$col";
	}
}
check('own tables: no column is a reserved word of SQL Server / MySQL', !$resBad, implode(', ', $resBad));

$onDisk = array_map('basename', glob(NU_ROOT . '/docs/pages/*.htm') ?: []);
check('help: every page file is in toc.json and the other way round', !array_diff($onDisk, $tocFiles) && !array_diff($tocFiles, $onDisk) && count($tocFiles) === count(array_unique($tocFiles)),
	implode(', ', array_merge(array_diff($onDisk, $tocFiles), array_diff($tocFiles, $onDisk))));
check('help: pages well formed (one section and h2, no version numbers, chapter not more restrictive)', !$pageProblems, implode('; ', array_slice($pageProblems, 0, 10)));
check('help: pages have chapters', count($helpIds) > 200 && count($tocFiles) > 50);
$dupIds = array_keys(array_filter($helpIds, static fn($f) => count($f) > 1));
check('help: ids unique over all pages', !$dupIds, implode(', ', array_slice($dupIds, 0, 10)));
$deadLinks = array_values(array_filter(array_keys($helpLinks), static fn($l) => !isset($helpIds[$l])));
check('help: every #link of the help points to an id', !$deadLinks, implode(', ', array_slice($deadLinks, 0, 15)));
preg_match_all('/data-help="([^"]+)"/', (string)preg_replace('/<!--.*?-->/s', '', (string)@file_get_contents(NU_ROOT . '/docs/nunext-tutorial.htm')), $m);
$deadTut = array_values(array_filter(array_unique($m[1]), static fn($l) => !isset($helpIds[$l])));
check('help: every help link of the tutorial points to an id', !$deadTut, implode(', ', $deadTut));
// F1 help (0.8.528): every route of NuHelpLink::MAP exists in index.php and points to a section of the help
preg_match_all("/^\t'([a-z0-9_]+)'\s*=>\s*\[/m", (string)file_get_contents(NU_ROOT . '/index.php'), $m);
$f1Bad = [];
foreach (NuHelpLink::MAP + ['home' => 'home-page', 'home ' => 'navigation-p'] as $route => $id) {
	if (!isset($helpIds[$id]) || (trim($route) !== 'home' && !in_array($route, $m[1], true))) $f1Bad[] = $route . ' → ' . $id;
}
check('help: F1 links of the pages (NuHelpLink)', !$f1Bad && count(NuHelpLink::MAP) > 40, implode(', ', $f1Bad));
// page numbers (0.8.804): every route of NuPageNo::MAP exists, numbers are P<nnn>[.<n>], parse() reads the typed forms
$pnoBad = array_keys(array_filter(NuPageNo::MAP, static fn($e, $n) => !in_array($e[0], $m[1], true) || !preg_match('/^\d{3}(\.\d)?$/', (string)$n), ARRAY_FILTER_USE_BOTH));
check('page numbers: every route exists (NuPageNo)', !$pnoBad && count(NuPageNo::MAP) > 60, implode(', ', $pnoBad));
check('page numbers: parse', NuPageNo::parse('P100') === '100' && NuPageNo::parse(' p 1 ') === '001' && NuPageNo::parse('P700.2') === '700.2' && NuPageNo::parse('100') === null && NuPageNo::parse('P1000') === null);

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
	&& str_contains(NuFnRef::tabs(), 'data-nu-fn-tab="ref-js"') && str_contains(NuFnRef::render('js'), 'nuFnBlock nuFnOut')
	&& NuFnRef::params('nuX(string $a, int $b = 2, ...$c)')[1][2] === '2' && NuFnRef::params('nuX(string $a, int $b = 2, ...$c)')[2][3] === true
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
// Custom code templates (0.8.524): every template has a title and a description in English, a known tab and category, unique ids
$tplEn = require NU_ROOT . '/lang/en.php';
$tplIds = [];
$tplOk = true;
foreach (NuCodeTemplates::all() as $tab => $list) {
	if (!in_array($tab, ['header', 'style', 'include', 'gjs', 'gcss'], true)) $tplOk = false;
	foreach ($list as $tpl) {
		if (!isset($tplEn['ctpl_' . $tpl['id']], $tplEn['ctpl_' . $tpl['id'] . '_d'], NuCodeTemplates::CATS[$tpl['cat']]) || trim($tpl['code']) === '' || isset($tplIds[$tpl['id']])) { $tplOk = false; echo "  template: {$tpl['id']}\n"; }
		$tplIds[$tpl['id']] = true;
	}
}
check('custom code templates: texts, tabs, categories, unique ids', $tplOk && count($tplIds) >= 20);

// 0.8.714: code snippets by nuBuilder Forte's scope (cot_scope), without a scope by language
check('NuSnippets::scopeOf: JSON, separator, empty', NuSnippets::scopeOf('["0","3"]') === [0, 3] && NuSnippets::scopeOf('2#nuSep#1') === [1, 2] && NuSnippets::scopeOf('[""]') === [] && NuSnippets::scopeOf(null) === []);
$snRows = [['cot_code' => 'b_php', 'cot_source_code' => 'x', 'cot_language' => 'PHP', 'cot_scope' => '["3"]'],
	['cot_code' => 'a_both', 'cot_source_code' => 'x', 'cot_language' => 'JavaScript', 'cot_scope' => '["0","2"]'],
	['cot_code' => 'c_sql', 'cot_source_code' => 'x', 'cot_language' => 'SQL', 'cot_scope' => null],
	['cot_code' => 'd_js', 'cot_source_code' => 'x', 'cot_language' => 'JavaScript', 'cot_scope' => '[""]'],
	['cot_code' => 'e_empty', 'cot_source_code' => '', 'cot_language' => 'PHP', 'cot_scope' => '["3"]']];
$snNames = static fn(int $sc, string $l) => array_column(NuSnippets::pick($snRows, $sc, $l), 0);
check('NuSnippets::pick: scope wins, unscoped by language, sorted, no empty code', $snNames(2, 'SQL') === ['a_both', 'c_sql'] && $snNames(3, 'PHP') === ['b_php']
	&& $snNames(0, 'JS') === ['a_both', 'd_js'] && $snNames(1, 'JS') === ['d_js'] && $snNames(1, 'CSS') === []);

// 0.8.722: IP access rules
[$ipOk, $ipBad] = NuIpAccess::parse("10.0.0.0/8, 203.0.113.7 # office\n2001:db8::/32;nope 10.0.0.0/33\n");
check('NuIpAccess::parse: lists, comments, invalid entries', $ipOk === ['10.0.0.0/8', '203.0.113.7', '2001:db8::/32'] && $ipBad === ['nope', '10.0.0.0/33']);
$ipS = NuIpAccess::clean(['on' => true, 'default' => ['10.0.0.0/8', 'bad'], 'levels' => ['lvA' => ['mode' => 'any'], 'lvR' => ['mode' => 'ranges', 'ranges' => ['192.168.1.0/24']], 'lvD' => ['mode' => 'default']], 'ga' => ['mode' => 'ranges', 'ranges' => ['127.0.0.1']]]);
check('NuIpAccess::clean: invalid rules and default entries dropped', $ipS['default'] === ['10.0.0.0/8'] && array_keys($ipS['levels']) === ['lvA', 'lvR']);
check('NuIpAccess: level rules (default list, any, own ranges)', NuIpAccess::levelAllows('lvX', '10.1.2.3', $ipS) && !NuIpAccess::levelAllows('lvX', '8.8.8.8', $ipS)
	&& NuIpAccess::levelAllows('lvA', '8.8.8.8', $ipS) && NuIpAccess::levelAllows('lvR', '192.168.1.9', $ipS) && !NuIpAccess::levelAllows('lvR', '10.1.2.3', $ipS));
check('NuIpAccess: any level allows; no level = default list', NuIpAccess::levelsAllow(['lvX', 'lvR'], '192.168.1.9', $ipS) && !NuIpAccess::levelsAllow(['lvX', 'lvR'], '8.8.8.8', $ipS)
	&& NuIpAccess::levelsAllow([], '10.9.9.9', $ipS) && !NuIpAccess::levelsAllow([], '8.8.8.8', $ipS));
check('NuIpAccess: globeadmin rule, IPv6, mapped IPv4', NuIpAccess::gaAllows('127.0.0.1', $ipS) && !NuIpAccess::gaAllows('10.1.1.1', $ipS)
	&& NuIpAccess::gaAllows('10.1.1.1', ['ga' => ['mode' => 'default', 'ranges' => []]] + $ipS) && NuIpAccess::gaAllows('8.8.8.8', ['default' => []] + ['ga' => ['mode' => 'default', 'ranges' => []]] + $ipS)
	&& NuIpAccess::matches(['2001:db8::/32'], '2001:db8:5::1') && !NuIpAccess::matches(['2001:db8::/32'], '10.0.0.1') && NuIpAccess::ip('::ffff:10.2.3.4') === '10.2.3.4');

// 0.8.740: Bootstrap 6 readiness (NuBs6)
$bs = NuBs6::scan('<div class="d-none d-md-block col-lg-6 btn-sm modal-lg form-control-sm navbar-expand-lg bi-list-md nuX-md-y offcanvas-end accordion-item">'
	. '<script>window.bootstrap.Modal; bootstrap.Tooltip.getInstance(x); nuBs.modal(y)</script>');
check('NuBs6::scan: responsive classes, not sizes / icons / own classes', ($bs['responsive'] ?? []) === ['d-md-block', 'col-lg-6', 'navbar-expand-lg']);
check('NuBs6::scan: offcanvas, accordion, window.bootstrap', ($bs['offcanvas'] ?? []) === ['offcanvas-end'] && ($bs['accordion'] ?? []) === ['accordion-item']
	&& ($bs['global'] ?? []) === ['window.bootstrap', 'bootstrap.Tooltip']);
check('NuBs6::scan: plain Bootstrap 5 markup without breakpoints finds nothing', NuBs6::scan('<div class="d-none btn btn-sm modal-lg row g-2">') === []);
$bsOwn = NuBs6::own();
check('NuBs6::own: counts the own files, nubs.js may use bootstrap', $bsOwn['total']['responsive'] > 100 && !isset($bsOwn['files']['assets/js/nubs.js']));

// 0.8.745: password policy (NuPwPolicy) – defaults without notices, invalid values, the online check through the test transport
// 0.8.754: texts for translations – nuTranslate('…') literals in code, texts of fixed select options
check('NuTranslate::codeStrings: literals of nuTranslate, not template literals with values', NuTranslate::codeStrings("x = nuTranslate('Saved'); nuMessage(nuTranslate(\"It's done\")); nuTranslate(`Hi \${n}`); nuTranslate(v); nuTranslate('Row %s1', 3)")
	=== ['Saved', "It's done", 'Row %s1']);
check('NuTranslate::optionTexts: pairs, lines, JSON, odd end; SQL ignored', NuTranslate::optionTexts("a|Apple|b|Banana|") === ['Apple', 'Banana']
	&& NuTranslate::optionTexts("1|One|\n2|Two|\n3") === ['One', 'Two', '3'] && NuTranslate::optionTexts('[["x","Ex"],"Why"]') === ['Ex', 'Why']
	&& NuTranslate::optionTexts('SELECT a, b FROM t') === [] && NuTranslate::optionTexts("-- the countries\nSELECT a FROM t") === [] && NuTranslate::optionTexts('%LANGUAGES%') === []);

// 0.8.879: select options as nuBuilder Forte reads them (NuSelectSource)
$sk = static fn(string $s) => NuSelectSource::kind($s);
check('select source: kinds', $sk('') === '' && $sk('SELECT a FROM t') === 'query' && $sk("-- note\n  select a from t") === 'query' && $sk("/* x */ WITH c AS (SELECT 1) SELECT * FROM c") === 'query'
	&& $sk('(SELECT a FROM t) UNION (SELECT b FROM u)') === 'query' && $sk('SHOW TABLES') === 'tables' && $sk('show full tables where Table_type = "BASE TABLE"') === 'tables'
	&& $sk('%LANGUAGES%') === 'languages' && $sk('["a","b"]') === 'array' && $sk('[a, b') === 'list' && $sk('a|A|b|B') === 'list'
	&& $sk('WITH|With|WITHOUT|Without') === 'list' && $sk('SELECTED|Chosen') === 'list' && $sk('#USER_ID#|Me|x|Other') === 'list');
$so = static fn(string $s, array $h = [], bool $x = false) => NuSelectSource::options($s, $h, $x);
check('select source: pipe list – line ends, tabs, closing |, odd end, hash variables as text', $so("a|Apple|\n\tb|Banana|") === [['a', 'Apple'], ['b', 'Banana']]
	&& $so('1|One|2') === [['1', 'One'], ['2', '2']] && $so('#USER_ID#|Me|x|Other', ['USER_ID' => "u'1"]) === [["u'1", 'Me'], ['x', 'Other']]);
check('select source: nuBuilder Forte\'s own result (exact)', $so('1|One|2', [], true) === [['1', 'One'], ['2', 'Undefined']] && $so('a|A|', [], true) === [['a', 'A'], ['', 'Undefined']]
	&& $so('[1, "b"]', [], true) === [[1, 1], ['b', 'b']]);
check('select source: JSON list, pairs, hash variables, invalid JSON = pipe list', $so('["first", "second"]') === [['first', 'first'], ['second', 'second']]
	&& $so('[["1","One"],"Two"]') === [['1', 'One'], ['Two', 'Two']] && $so('["#CODE#"]', ['CODE' => 'X7']) === [['X7', 'X7']]
	&& $so('[#LIST#]', ['LIST' => '"p","q"']) === [['p', 'p'], ['q', 'q']] && $so('[a|A') === [['[a', 'A']]);
$slC = NuDB::$connected;                                      // a test connection must not count as the real one (NuLanguages)
NuDB::$override = new NuDB(['type' => 'sqlite', 'name' => ':memory:', 'host' => '', 'user' => '', 'pass' => '']);
NuDB::$override->exec("CREATE TABLE sel_t (v TEXT, t TEXT)"); NuDB::$override->exec("INSERT INTO sel_t VALUES ('a', 'Apple'), ('b', 'Banana')");
NuDB::$override->exec("CREATE TABLE __sel_hidden (x TEXT)"); NuDB::$override->exec("CREATE VIEW sel_v AS SELECT v FROM sel_t");
check('select source: SQL after a comment line, hash variable bound', $so("-- fruit\nSELECT v, t FROM sel_t WHERE v <> '#NOT#' ORDER BY v", ['NOT' => 'b']) === [['a', 'Apple']]
	&& $so("SELECT v FROM sel_t ORDER BY v") === [['a', 'a'], ['b', 'b']]);
check('select source: SHOW TABLES without "__" tables, BASE TABLE = tables only', $so('SHOW TABLES') === [['sel_t', 'sel_t'], ['sel_v', 'sel_v']]
	&& $so('SHOW FULL TABLES WHERE Table_type = "BASE TABLE"') === [['sel_t', 'sel_t']]);
NuDB::$override = null; NuDB::$connected = $slC;
$slDir = sys_get_temp_dir() . '/nunext-sel-' . getmypid();
@mkdir($slDir . '/core/languages', 0777, true);
foreach (['Slovak', 'Afrikaans', 'Portuguese (Brazil)'] as $f) file_put_contents($slDir . '/core/languages/' . $f . '.sql', '');
check('select source: %LANGUAGES% = nuBuilder Forte\'s language files', NuSelectSource::languages($slDir) === ['Afrikaans', 'Portuguese (Brazil)', 'Slovak']);
array_map('unlink', glob($slDir . '/core/languages/*.sql')); @rmdir($slDir . '/core/languages'); @rmdir($slDir . '/core'); @rmdir($slDir);
$sl = NuSelectSource::languages($slDir);                       // NuLanguages::installed(): every language on the command line
check('select source: %LANGUAGES% without nuBuilder Forte = installed languages by English name, no English', in_array('German', $sl, true) && in_array('French', $sl, true) && !in_array('English', $sl, true), implode(',', $sl));
$pwD = NuPwPolicy::clean([]);
check('NuPwPolicy::clean: defaults (no change, leak both, name rule on)', $pwD['expire'] === 0 && $pwD['ga_expire'] === 0 && $pwD['warn'] === 14 && $pwD['leak'] === 'both' && $pwD['not_name'] && !$pwD['digit']);
check('NuPwPolicy::clean: invalid values dropped', NuPwPolicy::clean(['expire' => 45, 'warn' => 99, 'leak' => 'x', 'levels' => ['lvA' => 30, 'lvB' => '', 'bad id' => 30, 'lvC' => 7]])['levels'] === ['lvA' => 30]
	&& NuPwPolicy::clean(['expire' => 45])['expire'] === 0);
$pwSha = strtoupper(sha1('secret-e2e-pw'));
$pwSent = [];
NuPwPolicy::$transport = static function (string $prefix) use ($pwSha, &$pwSent) { $pwSent[] = $prefix; return "0000000000000000000000000000000000A:0\r\n" . substr($pwSha, 5) . ":17\r\n"; };
check('NuPwPolicy::online: k-anonymity (5 characters sent), count of the suffix', NuPwPolicy::online('secret-e2e-pw') === 17 && $pwSent === [substr($pwSha, 0, 5)] && NuPwPolicy::online('other-e2e-pw') === 0);
NuPwPolicy::$transport = static fn(string $prefix) => null;   // not reachable
check('NuPwPolicy::leaked: offline list as fallback, online only finds nothing', NuPwPolicy::leaked('password', 'both') === -1 && NuPwPolicy::leaked('password', 'online') === 0
	&& NuPwPolicy::leaked('Xq7!vLm2#pRt9zWk', 'offline') === 0);
NuPwPolicy::$transport = null;

// 0.8.761 (review D1): the Security check's web server rules cover every protected folder; the help shows the same nginx rules
$srv = NuSecurity::serverRules('/var/www/html', '/nunext');
$helpSec = (string)file_get_contents(NU_ROOT . '/docs/pages/07-07-security-check.htm');
$srvMiss = [];
foreach (['_update', '_libsrc', 'custom', 'apps', 'nu_uploads', 'vendor/packs/', 'phpspreadsheet', 'third_party/'] as $w) if (!str_contains($srv, $w)) $srvMiss[] = $w;
foreach (array_filter(explode("\n", $srv), static fn($l) => str_starts_with($l, 'location')) as $l) if (!str_contains($helpSec, h($l))) $srvMiss[] = 'help: ' . $l;
check('NuSecurity::serverRules: complete and identical to the help', !$srvMiss, implode(' | ', $srvMiss));
// 0.8.761 (review D2): every API scope has its description text
$scMiss = [];
$enT = require NU_ROOT . '/lang/en.php';
foreach (array_keys(NuApiClient::SCOPES) as $sc) if (!isset($enT['api_scope_' . str_replace(':', '_', $sc)])) $scMiss[] = $sc;
check('NuApiClient::SCOPES: a text api_scope_* for each', !$scMiss, implode(', ', $scMiss));

// 0.8.761 (review D8): dates in the language's order – only the day part of the pattern changes
$df = nu_dfmt('d.m.Y H:i');
check('nu_dfmt: day pattern by language, time kept', in_array($df, ['d.m.Y H:i', 'd/m/Y H:i', 'm/d/Y H:i', 'd-m-Y H:i', 'Y-m-d H:i'], true) && nu_dfmt('H:i') === 'H:i', $df);

// 0.8.744: phpMyAdmin only on MySQL / MariaDB – the installer's / update page's pack list leaves it out on SQL Server
check('NuLibs::forDb: phpMyAdmin MySQL only, Adminer everywhere', NuLibs::forDb('phpmyadmin', 'mysql') && !NuLibs::forDb('phpmyadmin', 'sqlsrv') && NuLibs::forDb('adminer', 'sqlsrv'));
try { $lpMs = NuLibs::overview(true, ['libs' => []], 'sqlsrv'); $lpMy = NuLibs::overview(true, ['libs' => []], 'mysql'); }
catch (Throwable $e) { $lpMs = $lpMy = ['err' => $e->getMessage()]; }
check('NuLibs::overview: no phpMyAdmin on SQL Server', !isset($lpMs['phpmyadmin']) && isset($lpMs['adminer']) && isset($lpMy['phpmyadmin']), json_encode(array_keys($lpMs)));

// 0.8.741: jQuery 4 preparation (NuJquery)
$jqF = NuJquery::scan("var a = \$.trim(x); if (jQuery.isArray(y)) z = \$.parseJSON(s); // \$.type(q)\n\$(el).toggleClass(); \$(b).toggleClass('on', true); \$(c).unbind('click'); f.bind(this); \$.fx.interval = 5;");
check('NuJquery::scan: removed functions, toggleClass(), unbind, fx.interval – not comments, not Function.bind',
	$jqF === ['$.trim()', '$.isArray()', '$.parseJSON()', '$.fx.interval', '.toggleClass() / .toggleClass(boolean)', '.unbind()'], implode(' | ', $jqF));
check('NuJquery::scan: modern code finds nothing', NuJquery::scan("\$('#a').on('click', fn); s.trim(); Array.isArray(x); \$('#b').toggleClass('x', on);") === []);
check('NuJquery::clean: unknown values fall back', NuJquery::clean(['jq' => 'b9', 'migrate' => 'loud', 'ga_jq' => 'c:../x.js', 'ga_warn' => 1])
	=== ['jq' => 'b3', 'migrate' => 'off', 'ga_jq' => '', 'ga_warn' => true]
	&& NuJquery::clean(['jq' => 'c:jquery-4.1.0.min.js', 'migrate' => 'restore'])['jq'] === 'c:jquery-4.1.0.min.js');
// 0.8.891: every library in the package names its project on GitHub (Update → Libraries, the name opens it)
$noUrl = array_values(array_map(static fn($l) => (string)$l['name'], array_filter(json_decode((string)file_get_contents(NU_ROOT . '/vendor/bundled/libraries.json'), true), static fn($l) => NuLibraries::projectUrl($l) === '')));
check('libraries.json: every library has a GitHub project url', $noUrl === [], implode(', ', $noUrl));
check('NuLibraries::projectUrl refuses other hosts', NuLibraries::projectUrl(['url' => 'https://evil.example/x/y']) === '' && NuLibraries::projectUrl(['url' => 'javascript:alert(1)']) === '');
check('NuLibs::projectUrl: every pack slot names its repository', array_keys(array_filter(NuLibs::SLOTS, static fn($v, $k) => NuLibs::projectUrl($k) === '', ARRAY_FILTER_USE_BOTH)) === []);
check('NuLibs::ghReleaseUrl', NuLibs::ghReleaseUrl('nuBuilder/nuBuilder-Next') === 'https://github.com/nuBuilder/nuBuilder-Next/releases/tag/libraries' && NuLibs::ghReleaseUrl('x"/><script>') === '');
check('NuJquery: bundled files exist', is_file(NU_ROOT . '/' . NU_JQUERY4 . '/jquery.min.js') && is_file(NU_ROOT . '/' . NU_JQMIGRATE3 . '/jquery-migrate.js')
	&& is_file(NU_ROOT . '/' . NU_JQMIGRATE4 . '/jquery-migrate.min.js') && !str_contains((string)file_get_contents(NU_ROOT . '/' . NU_JQCONFIRM . '/jquery-confirm.min.js'), '$.trim('));

// 0.8.622: UUID v7 keys for 36-character primary key columns
$u1 = nu_uuid(); usleep(2000); $u2 = nu_uuid();
check('nu_uuid: format, version 7, variant', (bool)preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $u1) && $u1 !== $u2);
check('nu_uuid: sorted by time', strcmp($u1, $u2) < 0 && abs(hexdec(substr(str_replace('-', '', $u1), 0, 12)) - (int)(microtime(true) * 1000)) < 5000);
check('nu_new_key: UUID for (n)(var)char(36) / uniqueidentifier, else nu_id', strlen(nu_new_key(['type' => 'varchar', 'length' => 36])) === 36
	&& strlen(nu_new_key(['type' => 'nchar', 'length' => 36])) === 36 && strlen(nu_new_key(['type' => 'uniqueidentifier', 'length' => null])) === 36
	&& strlen(nu_new_key(['type' => 'varchar', 'length' => 25])) < 36 && strlen(nu_new_key(['type' => 'varchar', 'length' => 40])) < 36 && strlen(nu_new_key(null)) < 36);

// 0.8.789: the installer's nuconfig.php for SQL Server sets $nuConfigDBType (it ended inside the header comment) and the SQL Server
// install script has every table of the MySQL ones (the form tables were missing)
$bc = NuInstall::buildConfig(['db' => ['type' => 'sqlsrv', 'host' => 'pc\\SQLEXPRESS', 'port' => '', 'name' => 'nunext', 'user' => 'sa', 'password' => 'x', 'trust' => true],
	'admin' => ['user' => 'globeadmin', 'password' => 'pw', 'email' => '', 'twofa' => false], 'opt' => ['timeout' => 120, 'timezone' => 'Europe/Zurich', 'base_url' => '', 'shared' => false, 'nub_path' => ''],
	'detect' => []]);
$bcFile = tempnam(sys_get_temp_dir(), 'nucfg');
file_put_contents($bcFile, $bc);
$bcVars = (static function (string $f): array { require $f; return get_defined_vars(); })($bcFile);
@unlink($bcFile);
check('installer config: SQL Server type and host set', ($bcVars['nuConfigDBType'] ?? '') === 'sqlsrv' && ($bcVars['nuConfigDBHost'] ?? '') === 'pc\\SQLEXPRESS'
	&& ($bcVars['nuConfigDBTrustServerCertificate'] ?? null) === true && str_contains($bc, 'means "mysql")'), substr($bc, 0, 400));
$myT = []; foreach (NuInstall::schemaFiles('nunext', 'mysql', null) as $f) { preg_match_all('/CREATE TABLE (?:IF NOT EXISTS )?`?(zzzzsys_\w+)/', (string)file_get_contents($f), $mm); $myT = array_merge($myT, $mm[1]); }
preg_match_all("/OBJECT_ID\('(zzzzsys_\w+)'\) IS NULL CREATE TABLE/", (string)file_get_contents(NU_ROOT . '/install/schema_mssql.sql'), $mm);
check('SQL Server install script: every table of the MySQL scripts', !array_diff(array_unique($myT), $mm[1]), implode(', ', array_diff(array_unique($myT), $mm[1])));

// 0.8.791: MySQL-only SQL for the Compatibility check on SQL Server
$ms = NuCompat::mssqlScan(false, [
	['where' => 'A', 'form' => 'f1', 'code' => "SELECT `name`, IFNULL(x, 0), DATE_FORMAT(d, '%d.%m.%Y') FROM t WHERE note = 'LIMIT 5 IFNULL(' LIMIT 10"],
	['where' => 'B', 'form' => 'f2', 'code' => "SELECT TOP (10) ISNULL(x, 0), DATEDIFF(day, a, b), IIF(a > 1, 1, 0) FROM t -- IFNULL( in a comment\nORDER BY a OFFSET 0 ROWS FETCH NEXT 10 ROWS ONLY"],
	['where' => 'C', 'form' => '', 'code' => "SELECT DATEDIFF(NOW(), d), GROUP_CONCAT(a) FROM t WHERE a = \"x\" AND b <=> c AND flag = TRUE"],
]);
check('mssql scan: MySQL-only parts found', isset($ms['backtick'], $ms['ifnull'], $ms['datefmt'], $ms['limit'], $ms['datediff'], $ms['now'], $ms['gconcat'], $ms['dquote'], $ms['nullsafe'], $ms['boolean']), implode(',', array_keys($ms)));
check('mssql scan: T-SQL, strings and comments are no hit', !array_filter($ms, static fn($l) => in_array('B', array_column($l, 'where'), true)) && count($ms['ifnull']) === 1 && count($ms['limit']) === 1);
$phpSql = NuCompat::phpSqlStrings('$s = nuRunQuery("SELECT * FROM t WHERE id = \'$id\' LIMIT 1"); $t = \'just a text\'; nuRunQuery(\'UPDATE t SET a = NOW()\');');
check('mssql scan: SQL strings of PHP code', count($phpSql) === 2 && str_contains($phpSql[0], 'LIMIT 1') && str_contains($phpSql[1], 'NOW()'), json_encode($phpSql));

// 0.8.792: ORDER BY without repeated items (SQL Server refuses them)
check('NuDB::uniqueOrder', NuDB::uniqueOrder('nu_pk, nu_pk') === 'nu_pk' && NuDB::uniqueOrder('nu_c1 DESC, nu_pk, NU_C1 asc') === 'nu_c1 DESC, nu_pk'
	&& NuDB::uniqueOrder("COALESCE(a, b), CONCAT(a, ','), a") === "COALESCE(a, b), CONCAT(a, ','), a", NuDB::uniqueOrder('nu_c1 DESC, nu_pk, NU_C1 asc'));

// 0.8.794: form cloner dump for MySQL or SQL Server
check('cloner literal: MySQL', NuCloner::literal("it's a \\ path", 'mysql') === "'it''s a \\\\ path'", NuCloner::literal("it's a \\ path", 'mysql'));
check('cloner literal: SQL Server', NuCloner::literal("it's ä \\ path", 'sqlsrv') === "N'it''s ä \\ path'");

// 0.8.812: validation rules – expressions and evaluation (NuFormRules)
$vp = NuFormRules::parse("status = 'shipped' AND (ship_date IS EMPTY OR qty <= 0) AND code IN ('a', 'b') AND COUNT(items) >= 1 AND d BEFORE TODAY AND n BETWEEN 1 AND 9 AND x MATCHES '^\\d+$' AND a != b");
check('rules parse', is_array($vp) && $vp['op'] === 'and' && count($vp['items']) === 8 && $vp['items'][1]['op'] === 'or' && $vp['items'][3]['f'] === 'count:items'
	&& $vp['items'][2]['v'] === ['a', 'b'] && $vp['items'][4]['v'] === 'today' && $vp['items'][5]['v'] === ['1', '9'] && $vp['items'][7]['v'] === ['f' => 'b'], json_encode($vp));
check('rules parse errors', is_string(NuFormRules::parse("a = 'x")) && is_string(NuFormRules::parse('a = 1 AND')) && is_string(NuFormRules::parse('(a = 1')) && is_string(NuFormRules::parse('a BETWEEN 1')));
check('rules text round trip', NuFormRules::parse(NuFormRules::text(['op' => 'and', 'items' => $vp['items']])) == ['op' => 'and', 'items' => $vp['items']], NuFormRules::text(['op' => 'and', 'items' => $vp['items']]));
$vk = ['status' => 'text', 'qty' => 'number', 'd' => 'date', 'ok' => 'checkbox'];
$vh = static fn(string $e, array $v, array $c = []) => NuFormRules::holds(NuFormRules::parse($e), $v, $vk, $c);
check('rules evaluate', $vh("status = 'Shipped'", ['status' => 'shipped ']) && !$vh('qty > 10', ['qty' => '9.5']) && $vh('qty > 10', ['qty' => '10.5']) && $vh('qty < 100', ['qty' => '20'])
	&& $vh('ok IS EMPTY', ['ok' => '0']) && $vh("d AFTER '2020-01-01'", ['d' => '2020-02-01']) && !$vh('d BEFORE TODAY', ['d' => '']) && $vh('COUNT(items) >= 2', [], ['items' => 2])
	&& $vh("status NOT IN ('x', 'y')", ['status' => 'z']) && $vh("status MATCHES '^[A-Z]{2}\\d+$'", ['status' => 'ab12']) && $vh('qty = status', ['qty' => '3', 'status' => '3.0'])
	&& $vh("status CONTAINS 'IPP'", ['status' => 'shipped']) && $vh("status ENDS WITH 'PED'", ['status' => 'shipped']) && !$vh("status STARTS WITH 'x'", ['status' => 'shipped']));

// 0.8.814: hash variables in rules
$hp = NuFormRules::parse("qty <= #MAX_QTY# AND status IN ('a', #ST#)");
check('rules parse hash', is_array($hp) && $hp['items'][0]['v'] === ['h' => 'MAX_QTY'] && $hp['items'][1]['v'][1] === ['h' => 'ST'] && NuFormRules::text($hp) === "qty <= #MAX_QTY# AND status IN ('a', #ST#)", json_encode($hp));
NuFormRules::$hash = ['MAX_QTY' => '10', 'st' => 'b'];
check('rules hash values', NuFormRules::holds($hp, ['qty' => '9', 'status' => 'b'], ['qty' => 'number']) && !NuFormRules::holds($hp, ['qty' => '11', 'status' => 'b'], ['qty' => 'number'])
	&& NuFormRules::hashValue('max_qty') === '10' && NuFormRules::hashValue('NOPE') === null && NuFormRules::hashNames([['check' => $hp, 'msg' => 'over #MAX_QTY# (#qty#)']]) === ['MAX_QTY', 'ST', 'qty']);
NuFormRules::$hash = [];

// 0.8.815: hash variable on the left side
$hl = NuFormRules::parse("#MY_COOKIE# = 123 AND #ACCESS_LEVEL_CODE# IN ('sales', 'boss')");
NuFormRules::$hash = ['MY_COOKIE' => '123', 'ACCESS_LEVEL_CODE' => 'boss'];
check('rules hash on the left', is_array($hl) && $hl['items'][0]['f'] === '#MY_COOKIE#' && NuFormRules::holds($hl, [], [])
	&& NuFormRules::text($hl) === "#MY_COOKIE# = 123 AND #ACCESS_LEVEL_CODE# IN ('sales', 'boss')" && in_array('MY_COOKIE', NuFormRules::hashNames([['check' => $hl, 'msg' => '']]), true), json_encode($hl));
NuFormRules::$hash = ['MY_COOKIE' => '124'];
check('rules hash on the left fails', !NuFormRules::holds($hl, [], []));
NuFormRules::$hash = [];

// 0.8.863: single sign-on – JWK → PEM and the id_token checks
$sk = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
$sd = openssl_pkey_get_details($sk);
$jwk = ['kty' => 'RSA', 'kid' => 'k1', 'use' => 'sig', 'n' => NuSso::b64url($sd['rsa']['n']), 'e' => NuSso::b64url($sd['rsa']['e'])];
check('sso jwkToPem = the key', trim(NuSso::jwkToPem($jwk)) === trim($sd['key']));
$mk = static function (array $c, $key = null, string $alg = 'RS256') use ($sk): string {
	$h = NuSso::b64url((string)json_encode(['alg' => $alg, 'kid' => 'k1'])) . '.' . NuSso::b64url((string)json_encode($c));
	openssl_sign($h, $sig, $key ?? $sk, OPENSSL_ALGO_SHA256);
	return $h . '.' . NuSso::b64url($sig);
};
$cl = ['iss' => 'https://idp.example', 'aud' => 'cid', 'exp' => 2000, 'iat' => 1000, 'nonce' => 'n1', 'email' => 'A@Example.com'];
$ssoErr = static function (callable $f): string { try { $f(); return 'ok'; } catch (RuntimeException $e) { return $e->getMessage(); } };
check('sso verify ok', $ssoErr(fn() => NuSso::verify($mk($cl), 'https://idp.example', 'cid', 'n1', [$jwk], 1500)) === 'ok');
$other = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
$bad = ['signature' => $mk($cl, $other), 'issuer' => $mk(['iss' => 'https://evil'] + $cl), 'audience' => $mk(['aud' => ['x', 'y']] + $cl),
	'expired' => $mk(['exp' => 1000] + $cl), 'nonce' => $mk(['nonce' => 'n2'] + $cl), 'alg none' => $mk($cl, null, 'none')];
foreach ($bad as $why => $tok) check('sso verify refuses: ' . $why, str_contains($ssoErr(fn() => NuSso::verify($tok, 'https://idp.example', 'cid', 'n1', [$jwk], 1500)), explode(' ', $why)[0]) || $why === 'alg none' && $ssoErr(fn() => NuSso::verify($tok, 'https://idp.example', 'cid', 'n1', [$jwk], 1500)) !== 'ok');
check('sso email', NuSso::email($cl) === 'a@example.com' && NuSso::email(['email_verified' => false] + $cl) === '' && NuSso::email(['preferred_username' => 'u@x.org']) === 'u@x.org' && NuSso::email(['preferred_username' => 'nomail']) === '');
check('sso issuer rule', NuSso::validIssuer('https://login.microsoftonline.com/t/v2.0') && NuSso::validIssuer('http://127.0.0.1:9000') && !NuSso::validIssuer('http://idp.example') && !NuSso::validIssuer('https://a?b=1'));
check('sso group map: first match, any case', NuSso::mappedAccess(['Staff', 'admins'], ['group_map' => [['group' => 'ADMINS', 'access' => 'lvA'], ['group' => 'staff', 'access' => 'lvS']]]) === 'lvA'
	&& NuSso::mappedAccess(['x'], ['group_map' => [['group' => 'y', 'access' => 'lvY']]]) === '');
$ssc = NuSso::clean(['scopes' => 'email  profile', 'domains' => 'Example.com, @b.org, bad', 'group_claim' => 'roles']);
check('sso clean', $ssc['scopes'] === 'openid email profile' && $ssc['domains'] === ['example.com', 'b.org'] && $ssc['group_claim'] === 'roles' && $ssc['create'] === false && $ssc['sync'] === true, json_encode($ssc));

// 0.8.867: #ACCESS_LEVEL_IDS# – quoted id list, inserted unescaped into SQL only while it has that form
check('hash idList', NuHash::idList(['a1', 'b_2', "x'y", '']) === "'a1','b_2'" && NuHash::idList([]) === "''");
check('hash ACCESS_LEVEL_IDS in SQL', NuHash::replace('IN (#ACCESS_LEVEL_IDS#)', ['ACCESS_LEVEL_IDS' => "'a1','b2'"], NuHash::SQL) === "IN ('a1','b2')"
	&& str_contains(NuHash::replace('x = #FOO#', ['FOO' => "'a1','b2'"], NuHash::HTML), '&#039;'));

// 0.8.873: bound hash variables ($nuDevSelectQueryRunParameterised)
NuHash::$bindTest = true; NuHash::$bound = [];
NuDB::$override = new NuDB(['type' => 'sqlite', 'name' => ':memory:', 'host' => '', 'user' => '', 'pass' => '']);
$bh = ['PARENT_ID' => "o'1", 'USER_ID' => 'u7', 'TABLE_ID' => 'nut1', 'ACCESS_LEVEL_IDS' => "'a','b'"];
$bs = NuHash::sql("SELECT * FROM #TABLE_ID# t WHERE t.p = '#PARENT_ID#' AND u = #USER_ID# AND a IN (#ACCESS_LEVEL_IDS#) AND x = '#NOPE#' -- #USER_ID#\n AND y = ?", $bh);
check('bound sql: markers, table / list as text, unknown kept, comment untouched', $bs === "SELECT * FROM nut1 t WHERE t.p = __nuhp_0__ AND u = __nuhp_1__ AND a IN ('a','b') AND x = '#NOPE#' -- #USER_ID#\n AND y = ?" && NuHash::$bound === ["o'1", 'u7'], $bs);
[$rs, $rp] = NuHash::resolveMarks("SELECT COUNT(*) FROM ($bs) nuT WHERE nuT.c LIKE ? AND z = '?'", ['first', 'second']);
check('bound sql: markers and ? in order', $rp === ["o'1", 'u7', 'first', 'second'] && substr_count($rs, '?') === 5 && !str_contains($rs, '__nuhp'), json_encode($rp));
[$rs2, $rp2] = NuHash::resolveMarks('SELECT __nuhp_1__ AS a WHERE b = :b', ['b' => 2]);
check('bound sql: named parameters', $rs2 === 'SELECT :nuhp1 AS a WHERE b = :b' && $rp2 === ['b' => 2, 'nuhp1' => 'u7']);
check('bound sql: cache key follows the values', NuHash::boundKey($bs) !== '' && NuHash::boundKey('SELECT 1') === '');
$bdb = NuDB::$override;
$bdb->exec("CREATE TABLE tb (id TEXT, p TEXT)"); $bdb->exec("INSERT INTO tb VALUES ('1', 'o''1'), ('2', 'x'), ('3', 'o''1')");
$bq = NuHash::sql("SELECT id FROM tb WHERE p = '#PARENT_ID#' AND '#PARENT_ID#' LIKE 'o%'", $bh);
check('bound sql: runs with the value as a parameter', array_column($bdb->all($bq . ' ORDER BY id'), 'id') === ['1', '3'] && str_contains($bq, '__nuhp_'), $bq);
NuDB::$override = null;
NuHash::$bindTest = null; NuHash::$bound = [];

// 0.8.874: Markdown in report fields, HTML made safe for TCPDF
$mdh = NuMarkdown::toHtml("# Title\nA **b** *i* `c` ~~d~~ [l](https://x.org) [j](javascript:x) <b>x</b>\nnext\n\n- one\n- two\n  - deep\n1. first\n\n| a | b |\n|---|--:|\n| 1 | 2 |\n\n> q\n\n---");
check('markdown: blocks and inline', str_contains($mdh, '<h1>Title</h1>') && str_contains($mdh, '<b>b</b>') && str_contains($mdh, '<i>i</i>') && str_contains($mdh, '<font face="courier">c</font>')
	&& str_contains($mdh, '<del>d</del>') && str_contains($mdh, '<a href="https://x.org">l</a>') && !str_contains($mdh, 'javascript') && str_contains($mdh, '&lt;b&gt;x&lt;/b&gt;')
	&& str_contains($mdh, '<br>next') && str_contains($mdh, '<ul><li>one</li><li>two<ul><li>deep</li></ul></li></ul>') && str_contains($mdh, '<ol><li>first</li></ol>')
	&& str_contains($mdh, '<td align="right">2</td>') && str_contains($mdh, '<blockquote><p>q</p></blockquote>') && str_contains($mdh, '<hr>'), $mdh);
check('markdown: hash names keep their underscores', NuMarkdown::toHtml('#USER_NAME# and #nu_pages#') === '<p>#USER_NAME# and #nu_pages#</p>');
$sh = NuReportEngine::safeHtml('<link rel="stylesheet" href="http://x/a.css"><img src="/p.jpg"><img src="phar://a.jpg"><img src="../x.png"><img src="data:image/png;base64,AA"><img src=\'/a.png\' src="file:///b"><a href="javascript:x">j</a><script>s()</script><style>b{color:red}</style>');
check('report html: unsafe parts removed', $sh === '<img src="/p.jpg"><img src="data:image/png;base64,AA"><a>j</a><style>b{color:red}</style>', $sh);

// 0.8.877: passkeys – a software authenticator signs registration and sign-in like a browser would (ES256, Ed25519, RS256)
$cbor = static function (mixed $v) use (&$cbor): string {
	$head = static function (int $major, int $len): string {
		if ($len < 24) return chr(($major << 5) | $len);
		if ($len < 256) return chr(($major << 5) | 24) . chr($len);
		if ($len < 65536) return chr(($major << 5) | 25) . pack('n', $len);
		return chr(($major << 5) | 26) . pack('N', $len);
	};
	if (is_array($v) && array_key_exists('__b', $v)) return $head(2, strlen($v['__b'])) . $v['__b'];
	if (is_int($v)) return $v >= 0 ? $head(0, $v) : $head(1, -1 - $v);
	if (is_string($v)) return $head(3, strlen($v)) . $v;
	if (is_array($v)) { $o = $head(5, count($v)); foreach ($v as $k => $x) $o .= $cbor($k) . $cbor($x); return $o; }
	throw new RuntimeException('cbor');
};
check('cbor decode', NuCbor::decode($cbor([1 => 2, 3 => -7, -1 => 1, -2 => ['__b' => "\x01\x02"], 'fmt' => 'none'])) === [1 => 2, 3 => -7, -1 => 1, -2 => "\x01\x02", 'fmt' => 'none']);
check('cbor refuses trailing bytes', (static function () { try { NuCbor::decode("\x01\x02"); return false; } catch (RuntimeException) { return true; } })());
NuPasskey::$testOrigin = 'http://localhost:8099';
$_SESSION['nu_schema'] = NuSchema::VERSION . '/' . NU_VERSION;   // no table creation on the test's SQLite database (NuLog::write)
NuDB::$override = new NuDB(['type' => 'sqlite', 'name' => ':memory:', 'host' => '', 'user' => '', 'pass' => '']);
NuDB::$override->exec('CREATE TABLE zzzzsys_nunext_passkey (zzzzsys_nunext_passkey_id TEXT PRIMARY KEY, user_id TEXT, cred_id TEXT, public_key TEXT, alg INTEGER, '
	. 'sign_count INTEGER, user_handle TEXT, name TEXT, aaguid TEXT, transports TEXT, backed_up TEXT, created_at TEXT, last_used_at TEXT)');
NuDB::$override->exec('CREATE TABLE zzzzsys_nunext_log (zzzzsys_nunext_log_id TEXT, log_time TEXT, user_id TEXT, login TEXT, ip TEXT, event TEXT, object TEXT, record_id TEXT, details TEXT)');
$pkB = static fn(string $b): string => NuPasskey::b64($b);
$pkAuth = static function (string $flags, int $count, string $att = '') { return hash('sha256', 'localhost', true) . $flags . pack('N', $count) . $att; };
$pkClient = static fn(string $type, string $c, string $origin = 'http://localhost:8099') => json_encode(['type' => $type, 'challenge' => $c, 'origin' => $origin]);
$pkKeys = [];
$ec = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
if ($ec) { $d = openssl_pkey_get_details($ec)['ec']; $pkKeys['ES256'] = [[1 => 2, 3 => -7, -1 => 1, -2 => ['__b' => $d['x']], -3 => ['__b' => $d['y']]], static fn($m) => openssl_sign($m, $sg, $ec, OPENSSL_ALGO_SHA256) ? $sg : '']; }
$rsa = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
if ($rsa) { $d = openssl_pkey_get_details($rsa)['rsa']; $pkKeys['RS256'] = [[1 => 3, 3 => -257, -1 => ['__b' => $d['n']], -2 => ['__b' => $d['e']]], static fn($m) => openssl_sign($m, $sg, $rsa, OPENSSL_ALGO_SHA256) ? $sg : '']; }
if (function_exists('sodium_crypto_sign_keypair')) { $kp = sodium_crypto_sign_keypair(); $pkKeys['EdDSA'] = [[1 => 1, 3 => -8, -1 => 6, -2 => ['__b' => sodium_crypto_sign_publickey($kp)]], static fn($m) => sodium_crypto_sign_detached($m, sodium_crypto_sign_secretkey($kp))]; }
check('passkey test keys', count($pkKeys) === 3, implode(',', array_keys($pkKeys)));
$pkUser = ['id' => 'u_pk', 'login' => 'pkuser', 'display' => 'PK User'];
foreach ($pkKeys as $alg => [$cose, $sign]) {
	$credId = random_bytes(16);
	$reg = NuPasskey::registrationOptions($pkUser);
	$attObj = $cbor(['fmt' => 'none', 'attStmt' => [], 'authData' => ['__b' => $pkAuth("\x45", 0, str_repeat("\0", 16) . pack('n', strlen($credId)) . $credId . $cbor($cose))]]);
	$r = NuPasskey::register($pkUser, ['id' => $pkB($credId), 'rawId' => $pkB($credId), 'type' => 'public-key',
		'response' => ['clientDataJSON' => $pkB($pkClient('webauthn.create', $reg['challenge'])), 'attestationObject' => $pkB($attObj), 'transports' => ['internal', 'bad value!']]], "Key $alg");
	check("passkey $alg registered", $r['ok'] === true, json_encode($r));
	$row = NuDB::$override->one('SELECT * FROM zzzzsys_nunext_passkey WHERE zzzzsys_nunext_passkey_id = ?', [hash('sha256', $credId)]);
	check("passkey $alg stored", $row && $row['user_id'] === 'u_pk' && $row['transports'] === 'internal' && $row['name'] === "Key $alg" && $row['user_handle'] === $reg['user']['id']);
	$get = static function (string $kind, int $count, string $flags = "\x05", ?string $user = 'u_pk', string $origin = 'http://localhost:8099') use ($credId, $sign, $pkAuth, $pkClient, $pkB, $reg) {
		$o = NuPasskey::assertionOptions($kind, $user);
		$ad = $pkAuth($flags, $count);
		$cd = $pkClient('webauthn.get', $o['challenge'], $origin);
		return NuPasskey::verify($kind, ['id' => $pkB($credId), 'rawId' => $pkB($credId), 'type' => 'public-key',
			'response' => ['clientDataJSON' => $pkB($cd), 'authenticatorData' => $pkB($ad), 'signature' => $pkB($sign($ad . hash('sha256', $cd, true))), 'userHandle' => $reg['user']['id']]], $err) ? 'ok' : $err;
	};
	check("passkey $alg sign-in", $get('login', 5, "\x05", null) === 'ok');
	check("passkey $alg counter must grow", $get('login', 5, "\x05", null) === 'pk_err_clone');
	check("passkey $alg without user verification: second factor yes, sign-in no", $get('2fa', 6, "\x01") === 'ok' && $get('login', 7, "\x01", null) === 'pk_err_uv');
	check("passkey $alg other origin refused", $get('login', 8, "\x05", null, 'https://evil.example') === 'pk_err_check');
	check("passkey $alg of another user refused", $get('2fa', 9, "\x05", 'u_other') === 'pk_err_unknown');
	check("passkey $alg ceremony used up", NuPasskey::verify('login', ['rawId' => $pkB($credId), 'response' => []], $e2) === null && $e2 === 'pk_err_expired');
}
$reg = NuPasskey::registrationOptions($pkUser);
check('passkey: second passkey keeps the user handle, excludes the first ones', count($reg['excludeCredentials']) === count($pkKeys)
	&& $reg['user']['id'] === (string)NuDB::$override->value("SELECT user_handle FROM zzzzsys_nunext_passkey WHERE user_id = 'u_pk' LIMIT 1"));
NuPasskey::testCeremony('reg', NuPasskey::b64(random_bytes(32)), 'u_pk');
check('passkey: registration with a wrong challenge refused', NuPasskey::register($pkUser, ['rawId' => 'AAAA', 'response' => ['clientDataJSON' => $pkB($pkClient('webauthn.create', 'xyz'))]], 'x')['ok'] === false);
check('passkey log: added, failures with a reason', (int)NuDB::$override->value("SELECT COUNT(*) FROM zzzzsys_nunext_log WHERE event = 'passkey_added'") === count($pkKeys)
	&& (string)NuDB::$override->value("SELECT details FROM zzzzsys_nunext_log WHERE event = 'passkey_failed' AND details LIKE '%origin%' LIMIT 1") !== '');
check('passkey remove', NuPasskey::removeAll('u_pk') === count($pkKeys) && NuPasskey::count('u_pk') === 0);
NuDB::$override = null;
NuPasskey::$testOrigin = null;
unset($_SESSION['nu_schema']);

@unlink($cfg);
echo ($fails ? "$fails of $n tests FAILED" : "all $n tests passed") . "\n";
exit($fails ? 1 : 0);
