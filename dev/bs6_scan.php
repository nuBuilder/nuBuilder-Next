<?php
declare(strict_types=1);
/**
 * Bootstrap 6 readiness of nuBuilder Next's own files (0.8.740): php dev/bs6_scan.php [--files] [--rule=responsive|offcanvas|accordion|global]
 * Counts what Bootstrap 6 changes (rules in core/nubs6.php): totals, then the files with most hits (--files: all of them).
 * Only reads – a preparation for the migration script that comes with the Bootstrap 6 beta (TODO.md "Bootstrap 6").
 */
if (PHP_SAPI !== 'cli') exit;
define('NU_ROOT', dirname(__DIR__));
require NU_ROOT . '/core/nubs6.php';

$all = in_array('--files', $argv, true);
$only = null;
foreach ($argv as $a) if (str_starts_with($a, '--rule=')) $only = substr($a, 7);
if ($only !== null && !isset(NuBs6::RULES[$only])) { fwrite(STDERR, "unknown rule $only\n"); exit(2); }

$r = NuBs6::own();
$rules = $only !== null ? [$only] : array_keys(NuBs6::RULES);
echo "Bootstrap 6 readiness – nuBuilder Next's own files\n\n";
foreach ($rules as $k) printf("  %-11s %5d\n", $k, $r['total'][$k]);
$files = array_filter(array_map(static fn($c) => array_intersect_key($c, array_flip($rules)), $r['files']));
uasort($files, static fn($a, $b) => array_sum($b) <=> array_sum($a));
printf("\n  %d files\n\n", count($files));
foreach (($all ? $files : array_slice($files, 0, 25, true)) as $f => $c) {
	printf("  %4d  %s  (%s)\n", array_sum($c), $f, implode(', ', array_map(static fn($k, $v) => "$k $v", array_keys($c), $c)));
}
if (!$all && count($files) > 25) echo "  … --files lists all\n";
