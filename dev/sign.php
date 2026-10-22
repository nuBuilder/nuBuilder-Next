<?php
declare(strict_types=1);

/*
 * nuBuilder Next – signs a release manifest (called by dev/release.py):  php dev/sign.php <manifest.json> <signing key file>
 * Prints the base64 Ed25519 signature of NuUpdate::signedPayload() and checks it against NuUpdate::SIGN_PUBLIC.
 * The key file (second line = base64 secret key) is the owner's and never part of the project or a package.
 */
if (PHP_SAPI !== 'cli' || $argc !== 3) { fwrite(STDERR, "usage: php dev/sign.php <manifest.json> <key file>\n"); exit(2); }
define('NU_ROOT', dirname(__DIR__));
require NU_ROOT . '/core/nuupdate.php';
$m = json_decode((string)file_get_contents($argv[1]), true);
$lines = file($argv[2], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
$sk = base64_decode((string)end($lines), true);
if (!is_array($m) || $sk === false || strlen($sk) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) { fwrite(STDERR, "bad manifest or key file\n"); exit(1); }
$sig = sodium_crypto_sign_detached(NuUpdate::signedPayload($m), $sk);
if (!sodium_crypto_sign_verify_detached($sig, NuUpdate::signedPayload($m), base64_decode(NuUpdate::SIGN_PUBLIC))) {
	fwrite(STDERR, "the key does not match NuUpdate::SIGN_PUBLIC\n");
	exit(1);
}
echo base64_encode($sig), "\n";
