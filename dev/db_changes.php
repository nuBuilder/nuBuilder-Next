<?php
declare(strict_types=1);

/*
 * Writes install/db_changes.json (0.8.346): the database steps of this version (NuMigrate::catalog – id, description, the tables
 * they touch and how) and the definitions of nuBuilder Next's own tables (NuSchema::definitions). The update page of an older
 * installation reads the file from a package and shows which steps are still to run and which own tables / columns are new.
 * Run by dev/release.py; CLI only.
 */
if (PHP_SAPI !== 'cli') exit(1);
define('NU_ROOT', dirname(__DIR__));
require NU_ROOT . '/core/nuinfra.php';
require NU_ROOT . '/core/nuupdate.php';

$app = (string)file_get_contents(NU_ROOT . '/core/nuapp.php');
preg_match("/define\\('NU_VERSION', '([^']+)'\\);/", $app, $m);
foreach (NuMigrate::catalog() as $st) if (!$st['changes']) { fwrite(STDERR, "NuMigrate::CHANGES has no entry for step {$st['id']}\n"); exit(1); }
$out = ['format' => 1, 'version' => $argv[1] ?? ($m[1] ?? ''), 'steps' => NuMigrate::catalog(), 'own_tables' => NuSchema::definitions()];
$file = NU_ROOT . '/install/db_changes.json';
file_put_contents($file, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
echo 'install/db_changes.json: ' . count($out['steps']) . ' steps, ' . count($out['own_tables']) . " own tables\n";
