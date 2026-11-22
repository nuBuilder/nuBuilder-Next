<?php
declare(strict_types=1);

/*
 * Plugins: signing key and signature (for plugin developers – not part of the install package).
 *     php dev/plugin_sign.php --keygen <key file>             a new Ed25519 key pair: the secret key into <key file>, the public key printed
 *                                                             (give it to the installations: Update → Plugins → Trusted signing keys)
 *     php dev/plugin_sign.php <plugin folder> <key file> [--zip=<out.zip>]
 *         writes "files" (sha256 + size of every file but plugin.json) and "signature" into plugin.json – NuPlugin::payload();
 *         --zip: also the package to upload (the folder named after the plugin's id)
 * Change no file of the folder after signing: the installation then shows "Files changed since signing" and refuses to switch it on.
 */
if (PHP_SAPI !== 'cli') exit;
define('NU_ROOT', dirname(__DIR__));
function t(string $k, mixed ...$a): string { return $k . ($a ? ' ' . implode(' ', array_map('strval', $a)) : ''); }
require NU_ROOT . '/core/nuplugin.php';

$args = array_slice($argv, 1);
if (($args[0] ?? '') === '--keygen' && isset($args[1])) {
	if (file_exists($args[1])) { fwrite(STDERR, "$args[1] exists – not overwritten\n"); exit(1); }
	$kp = sodium_crypto_sign_keypair();
	file_put_contents($args[1], "# nuBuilder Next plugin signing key – keep it secret\n" . base64_encode(sodium_crypto_sign_secretkey($kp)) . "\n");
	@chmod($args[1], 0600);
	echo 'public key: ', base64_encode(sodium_crypto_sign_publickey($kp)), "\n";
	exit(0);
}
$dir = rtrim((string)($args[0] ?? ''), '/');
$keyFile = (string)($args[1] ?? '');
if ($dir === '' || $keyFile === '' || !is_dir($dir) || !is_file($keyFile)) {
	fwrite(STDERR, "usage: php dev/plugin_sign.php --keygen <key file> | <plugin folder> <key file> [--zip=<out.zip>]\n");
	exit(2);
}
$m = json_decode((string)file_get_contents($dir . '/plugin.json'), true);
if (!is_array($m)) { fwrite(STDERR, "plugin.json missing or not valid JSON\n"); exit(1); }
[$clean, $err] = NuPlugin::cleanManifest($m);
if ($clean === null) { fwrite(STDERR, "plugin.json: $err\n"); exit(1); }
$lines = file($keyFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
$sk = base64_decode((string)end($lines), true);
if ($sk === false || strlen($sk) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) { fwrite(STDERR, "not a key file of --keygen\n"); exit(1); }
$files = [];
foreach (NuPlugin::files($dir) as $rel) $files[$rel] = ['sha256' => hash_file('sha256', $dir . '/' . $rel), 'size' => filesize($dir . '/' . $rel)];
$m['files'] = $files;
unset($m['signature']);
$m['signature'] = base64_encode(sodium_crypto_sign_detached(NuPlugin::payload($m), $sk));
file_put_contents($dir . '/plugin.json', json_encode($m, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
echo $m['id'], ' ', $m['version'], ': ', count($files), " file(s) signed\n";
echo 'public key: ', base64_encode(sodium_crypto_sign_publickey_from_secretkey($sk)), "\n";
foreach ($args as $a) {
	if (!str_starts_with($a, '--zip=')) continue;
	$out = substr($a, 6);
	@unlink($out);
	$z = new ZipArchive();
	if ($z->open($out, ZipArchive::CREATE) !== true) { fwrite(STDERR, "cannot write $out\n"); exit(1); }
	$z->addFile($dir . '/plugin.json', $m['id'] . '/plugin.json');
	foreach (array_keys($files) as $rel) $z->addFile($dir . '/' . $rel, $m['id'] . '/' . $rel);
	$z->close();
	echo "package: $out\n";
}
