<?php
/*
 * Installer with a nubuilder4.sql of an older 4.x version (0.8.384): for each dump → empty scratch database, NuInstall::checkDump,
 * importFile, upgradeTo49 (twice – the second run must change nothing), then the structure compared with install/schema_expected.json
 * (nuBuilder's tables). Needs a MySQL / MariaDB database the user may drop and create.
 *
 *     DB=nuup DBUSER=nu DBPASS=nu php dev/tests/nub49/upgrade_check.php nubuilder4.sql [RELEASE_4.5.1.0.sql …]
 *
 * Old dumps: https://raw.githubusercontent.com/nuBuilder/nuBuilder/RELEASE_4.x.y.z/nubuilder4.sql (tags RELEASE_4.5.1.0 … 4.9.2.0).
 * Exit code 1 when a dump is refused or a table / column differs.
 */
declare(strict_types=1);

chdir(dirname(__DIR__, 3));
require 'core/nuapp.php';

$cfg = ['type' => 'mysql', 'host' => '127.0.0.1', 'port' => '3306', 'name' => getenv('DB') ?: 'nuup', 'user' => getenv('DBUSER') ?: 'nu', 'password' => getenv('DBPASS') ?: 'nu', 'trust' => true];
$expected = json_decode((string)file_get_contents('install/schema_expected.json'), true)['tables'];
$fail = 0;
foreach (array_slice($argv, 1) as $file) {
	$server = NuInstall::connect($cfg, false);
	$server->exec('DROP DATABASE IF EXISTS `' . $cfg['name'] . '`');
	$server->exec('CREATE DATABASE `' . $cfg['name'] . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
	$db = NuInstall::connect($cfg);
	$sql = NuInstall::dumpSql($file);
	$chk = $sql === null ? ['ok' => false, 'error' => 'unreadable'] : NuInstall::checkDump($sql);
	if (!$chk['ok']) { echo basename($file), ': REFUSED – ', $chk['error'], "\n"; $fail++; continue; }
	$tmp = tempnam(sys_get_temp_dir(), 'nub');
	file_put_contents($tmp, $sql);
	$n = NuInstall::importFile($db, $tmp);
	unlink($tmp);
	$first = NuInstall::upgradeTo49($db);
	$second = NuInstall::upgradeTo49($db);
	$cols = [];
	foreach ($db->all('SELECT TABLE_NAME AS t, COLUMN_NAME AS c, COLUMN_TYPE AS y FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()') as $r) $cols[$r['t']][$r['c']] = $r['y'];
	$diff = [];
	foreach ($expected as $t => $d) {
		if (!str_starts_with($t, 'zzzzsys_') || str_starts_with($t, 'zzzzsys_nunext_')) continue;
		if (!isset($cols[$t])) { $diff[] = "table $t missing"; continue; }
		foreach ($d['cols'] as $c => $info) {
			$norm = static fn(string $y) => (string)preg_replace('/^(tinyint|smallint|int|bigint)\(\d+\)/', '$1', strtolower($y));
			if (!isset($cols[$t][$c])) $diff[] = "$t.$c missing";
			elseif ($norm($cols[$t][$c]) !== $norm($info['type'])) $diff[] = "$t.$c {$cols[$t][$c]} ≠ {$info['type']}";
		}
	}
	$again = $second['tables'] + $second['columns'] + $second['changed'];
	printf("%s: %d forms, %d statements · upgrade: +%d tables, +%d columns, %d changed · second run: %d · %s\n", basename($file), $chk['forms'], $n,
		$first['tables'], $first['columns'], $first['changed'], $again, $diff ? 'DIFFERENT: ' . implode('; ', $diff) : 'same as 4.9');
	if ($diff || $again) $fail++;
}
exit($fail ? 1 : 0);
