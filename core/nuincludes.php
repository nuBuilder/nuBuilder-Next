<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * nuBuilder's own include files in nuconfig.php (0.8.443): $nuConfigIncludeJS / $nuConfigIncludeCSS (form pages, where nuBuilder code runs)
 * and $nuConfigIncludePHP (core/form/nuphp.php). A string or a list, as in nuBuilder.
 *   JS / CSS: "https://…", "//…" and "/…" are addresses and used as they are; a relative path is looked for in the nuBuilder Next folder
 *             first, then in nuBuilder's folder (NuBuilderLib::path – reached as "../" when nuBuilder Next is its subfolder, else through
 *             $nuConfigNuBuilderURL); the file's change time is added (?v=) so a changed file reaches the browser at once.
 *   PHP:      an absolute path as it is; a relative one in the nuBuilder Next folder, then in nuBuilder's folder (nuBuilder reads it from
 *             there, so lines copied from nuBuilder's nuconfig.php work unchanged).
 * Files not found are reported by missing() (Security check) and left out.
 */
final class NuIncludes
{
	public const VARS = ['js' => 'nuConfigIncludeJS', 'css' => 'nuConfigIncludeCSS', 'php' => 'nuConfigIncludePHP'];

	/** @return list<string> the entries of $nuConfigInclude<kind> (empty ones dropped) */
	public static function entries(string $kind): array
	{
		$v = nu_cfg(self::VARS[$kind] ?? '');
		$out = [];
		if (is_array($v)) array_walk_recursive($v, static function ($x) use (&$out) { if (is_string($x) && trim($x) !== '') $out[] = trim($x); });
		elseif (is_string($v) && trim($v) !== '') $out = [trim($v)];
		return array_values(array_unique($out));
	}

	public static function isUrl(string $e): bool
	{
		return (bool)preg_match('~^(https?:)?//~i', $e) || str_starts_with($e, '/');
	}

	/** File of a PHP include, or null when it is not found. */
	public static function phpFile(string $e): ?string
	{
		if (str_starts_with($e, '/') || preg_match('~^[A-Za-z]:[/\\\\]~', $e)) return is_file($e) ? $e : null;
		foreach ([NU_ROOT, NuBuilderLib::path()] as $base) if (is_file($base . '/' . $e)) return $base . '/' . $e;
		return null;
	}

	/** File on this server of a JS / CSS include (nuBuilder Next's folder, then nuBuilder's), null for an address or a file not found (0.8.578). */
	public static function localFile(string $e): ?string
	{
		if (self::isUrl($e)) return null;
		$path = (string)preg_replace('/[?#].*$/', '', $e);
		if ($path === '' || str_contains($path, "\0")) return null;
		foreach ([NU_ROOT, NuBuilderLib::path()] as $base) if ($base !== '' && is_file($base . '/' . $path)) return $base . '/' . $path;
		return null;
	}

	/** Address of a JS / CSS include for the browser (relative to nuBuilder Next's index.php), or null when the file is not found / not reachable. */
	public static function url(string $e): ?string
	{
		if (self::isUrl($e)) return $e;
		$path = (string)preg_replace('/[?#].*$/', '', $e);
		if (is_file(NU_ROOT . '/' . $path)) return $e . (str_contains($e, '?') ? '&' : '?') . 'v=' . base_convert((string)(int)filemtime(NU_ROOT . '/' . $path), 10, 36);
		$nub = NuBuilderLib::path();
		if (!is_file($nub . '/' . $path)) return null;
		$v = 'v=' . base_convert((string)(int)filemtime($nub . '/' . $path), 10, 36);
		$sep = str_contains($e, '?') ? '&' : '?';
		if ($nub === rtrim(dirname(NU_ROOT), '/\\')) return '../' . $e . $sep . $v;
		$base = trim((string)nu_cfg('nuConfigNuBuilderURL'));
		return $base !== '' ? rtrim($base, '/') . '/' . ltrim($e, '/') . $sep . $v : null;
	}

	/** <link> tags of $nuConfigIncludeCSS (after nuBuilder Next's own styles, so they can override them). */
	public static function cssTags(): string
	{
		$out = '';
		foreach (self::entries('css') as $e) if (($u = self::url($e)) !== null) $out .= '<link rel="stylesheet" href="' . h($u) . '" data-nu-source="nuConfigIncludeCSS">';
		return $out;
	}

	/** <script> tags of $nuConfigIncludeJS (after the runtime, before Setup → Header and the form's code – as in nuBuilder). */
	public static function jsTags(): string
	{
		$out = '';
		foreach (self::entries('js') as $e) if (($u = self::url($e)) !== null) $out .= '<script src="' . h($u) . '" data-nu-source="nuConfigIncludeJS"></script>';
		return $out;
	}

	/** Other origins of JS / CSS includes for the form page's Content-Security-Policy: ['script' => ' https://a …', 'style' => …]. */
	public static function cspSources(): array
	{
		$src = ['script' => [], 'style' => []];
		foreach (['js' => 'script', 'css' => 'style'] as $kind => $dir) {
			foreach (self::entries($kind) as $e) {
				if (!preg_match('~^(https?:)?//([^/?#]+)~i', $e, $m)) continue;
				$src[$dir][] = ($m[1] !== '' ? strtolower($m[1]) : 'https:') . '//' . strtolower($m[2]);
			}
			foreach (self::setupElements() as $el) {           // Setup → Include (0.8.444): <script src> / <link href> on other hosts
				if (($el['tag'] === 'script') !== ($dir === 'script') || $el['tag'] === 'style') continue;
				if (preg_match('~^(https?:)?//([^/?#]+)~i', $el['url'], $m)) $src[$dir][] = ($m[1] !== '' ? strtolower($m[1]) : 'https:') . '//' . strtolower($m[2]);
			}
			foreach ([trim((string)nu_cfg('nuConfigNuBuilderURL'))] as $b) {   // includes from nuBuilder's folder on another host
				if (preg_match('~^(https?:)//([^/?#]+)~i', $b, $m) && self::entries($kind)) $src[$dir][] = strtolower($m[1]) . '//' . strtolower($m[2]);
			}
		}
		return array_map(static fn($l) => $l ? ' ' . implode(' ', array_unique($l)) : '', $src);
	}

	/**
	 * nuBuilder's Setup → Include (zzzzsys_setup.set_include, 0.8.444): only its <link>, <script> and <style> elements – the default text
	 * ("// Add external CSS/JS file here", an HTML comment with examples) and other text are dropped. Read once per request.
	 * @return list<array{tag: string, html: string, url: string}>
	 */
	public static function setupElements(): array
	{
		static $out = null;
		if ($out !== null) return $out;
		$out = [];
		try {
			$db = nu_db();
			if (!$db->tableExists('zzzzsys_setup') || !in_array('set_include', array_map('strtolower', array_keys($db->columns('zzzzsys_setup'))), true)) return $out;
			$text = (string)($db->page('SELECT set_include FROM zzzzsys_setup', [], 'zzzzsys_setup_id', 1, 0)[0]['set_include'] ?? '');
		} catch (Throwable) {
			return $out;
		}
		$text = (string)preg_replace(['/<!--.*?-->/s', '~/\*.*?\*/~s'], '', $text);
		if (!preg_match_all('~<script\b[^>]*>.*?</script\s*>|<style\b[^>]*>.*?</style\s*>|<link\b[^>]*>~is', $text, $m)) return $out;
		foreach ($m[0] as $html) {
			$tag = strtolower((string)preg_replace('/^<([a-z]+).*$/is', '$1', $html));
			$url = preg_match('~\b(?:src|href)\s*=\s*["\']?([^"\'\s>]+)~i', $html, $u) ? $u[1] : '';
			$out[] = ['tag' => $tag, 'html' => $html, 'url' => $url];
		}
		return $out;
	}

	/** The Include elements for the form page (after the runtime, before Setup → Header). */
	public static function setupTags(): string
	{
		$html = '';
		foreach (self::setupElements() as $e) $html .= $e['html'] . "\n";
		return $html;
	}

	/** Include files that are not found (Security check). @return list<array{var: string, file: string}> */
	public static function missing(): array
	{
		$out = [];
		foreach (self::VARS as $kind => $var) {
			foreach (self::entries($kind) as $e) {
				$ok = $kind === 'php' ? self::phpFile($e) !== null : self::url($e) !== null;
				if (!$ok) $out[] = ['var' => '$' . $var, 'file' => $e];
			}
		}
		return $out;
	}
}
