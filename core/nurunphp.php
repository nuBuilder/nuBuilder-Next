<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * Tokens of nuRunPHP calls (pages/nurunphp.php): what the form page sent – procedure, form, hash variables – kept in the session,
 * so the output page has an address that can be reloaded or linked to with more parameters, as nuBuilder Forte's nurunphp.php?i=.
 */
final class NuRunPhp
{
	public const KEEP  = 30;          // calls kept per session
	public const HOURS = 6;           // a token is valid this long
	public const MAX_OUTPUT = 4194304; // larger output is written into the window as before (no address of its own)

	public static function token(string $formId, string $code, array $hash): string
	{
		$t = bin2hex(random_bytes(12));
		$list = $_SESSION[NuSess::RUN_PHP] ?? [];
		$list[$t] = ['form' => $formId, 'code' => $code, 'hash' => $hash, 'time' => time(), 'output' => null];
		if (count($list) > self::KEEP) $list = array_slice($list, -self::KEEP, null, true);
		$_SESSION[NuSess::RUN_PHP] = $list;
		return $t;
	}

	/** @return array{form: string, code: string, hash: array}|null */
	public static function fromToken(string $t): ?array
	{
		if (!preg_match('/^[0-9a-f]{24}$/', $t)) return null;
		$run = $_SESSION[NuSess::RUN_PHP][$t] ?? null;
		if (!is_array($run) || time() - (int)($run['time'] ?? 0) > self::HOURS * 3600) return null;
		return ['form' => (string)$run['form'], 'code' => (string)$run['code'], 'hash' => (array)$run['hash']];
	}

	/** What the first run printed: the page shows it once instead of running the procedure a second time. */
	public static function keepOutput(string $t, string $output): void
	{
		if (isset($_SESSION[NuSess::RUN_PHP][$t])) $_SESSION[NuSess::RUN_PHP][$t]['output'] = $output;
	}

	/**
	 * Runs $fn (the first run, inside formapi) as if on the output page: $_SERVER['REQUEST_URI'] / QUERY_STRING and $_GET are the
	 * page's, so a link the procedure builds from them leads back to it. Put back afterwards.
	 */
	public static function asPage(string $t, callable $fn): mixed
	{
		$keep = [$_SERVER['REQUEST_URI'] ?? null, $_SERVER['QUERY_STRING'] ?? null, $_GET];
		$dir = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/index.php'))), '/');
		$url = self::url($t);
		$_SERVER['REQUEST_URI'] = $dir . '/' . $url;
		$_SERVER['QUERY_STRING'] = substr($url, strpos($url, '?') + 1);
		$_GET = ['p' => 'runphp', 'i' => $t];
		try {
			return $fn();
		} finally {
			[$_SERVER['REQUEST_URI'], $_SERVER['QUERY_STRING'], $_GET] = $keep;
		}
	}

	/** The output of the first run, once – null afterwards (then the page runs the procedure). */
	public static function takeOutput(string $t): ?string
	{
		$o = $_SESSION[NuSess::RUN_PHP][$t]['output'] ?? null;
		if ($o === null) return null;
		$_SESSION[NuSess::RUN_PHP][$t]['output'] = null;
		return (string)$o;
	}

	public static function url(string $t): string
	{
		return nu_url('runphp', ['i' => $t]);
	}
}
