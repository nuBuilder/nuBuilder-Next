<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * Page numbers (0.8.804, option A of the owner's review): every own page has a number P<nnn> in blocks of 100 by area – P0xx start /
 * help / own account, P1xx users & access, P2xx development, P3xx data, P4xx communication & integration, P5xx logs, P6xx settings,
 * P7xx system. A suffix .<n> opens a tab or section. Typed into the start page search or the Ctrl+K search, Enter jumps to the page
 * (route ?p=pageno&n=P100 → url()); the page itself checks the rights. A record page shares the number of its list.
 * A new page = an entry here (and the table in docs/pages/03-01 "Page numbers").
 */
final class NuPageNo
{
	/** number => [route, params] */
	public const MAP = [
		'001' => ['home', []], '002' => ['home', ['for' => '*']], '010' => ['help', []], '010.1' => ['help', []], '010.2' => ['help', ['tab' => 'ref']],
		'020' => ['tutorial', []], '030' => ['setupwizard', []], '050' => ['account', []], '051' => ['security', []], '052' => ['password', []],
		'090' => ['login', []], '091' => ['twofa', []], '092' => ['forgot', []], '093' => ['reset', []], '094' => ['reauth', []], '095' => ['dbsetup', []],
		'100' => ['users', []], '110' => ['access', []], '120' => ['permissions', []], '130' => ['adminroles', []], '140' => ['userfields', []], '150' => ['sessions', []],
		'200' => ['forms', []], '205' => ['builder', []], '206' => ['formai', []], '210' => ['dataaccess', []], '215' => ['inspect', []],
		'220' => ['items', []], '230' => ['files', []], '240' => ['formats', []], '250' => ['translations', []], '260' => ['procedures', []],
		'270' => ['reports', []], '275' => ['reportdesigner', []], '280' => ['sqlbuilder', []], '290' => ['prompts', []],
		'300' => ['import', []], '310' => ['dataexport', []], '320' => ['cloner', []], '330' => ['appcloner', []], '340' => ['sample', []], '390' => ['dbreset', []],
		'400' => ['email', []], '400.1' => ['email', ['tab' => 'settings']], '400.2' => ['email', ['tab' => 'test']], '400.3' => ['email', ['tab' => 'log']],
		'410' => ['emailtemplates', []], '420' => ['notifications', []], '420.1' => ['notifications', []], '420.2' => ['notifications', ['tab' => 'manage']],
		'430' => ['dashboards', []], '440' => ['webhooks', []], '450' => ['apiclients', []], '455' => ['apiplayground', []],
		'500' => ['log', []], '510' => ['audit', []], '520' => ['debug', []], '590' => ['selftest', []],
		'600' => ['settings', []], '600.1' => ['settings', ['s' => 'general']], '600.2' => ['settings', ['s' => 'look']], '600.3' => ['settings', ['s' => 'users']],
		'600.4' => ['settings', ['s' => 'security']], '600.5' => ['settings', ['s' => 'mail']], '600.6' => ['settings', ['s' => 'data']],
		'600.7' => ['settings', ['s' => 'logs']], '600.8' => ['settings', ['s' => 'code']], '600.9' => ['settings', ['s' => 'updates']],
		'610' => ['branding', []], '620' => ['themes', []], '630' => ['securitycheck', []], '630.1' => ['securitycheck', []], '630.2' => ['securitycheck', ['tab' => 'settings']],
		'635' => ['ipaccess', []], '638' => ['pwpolicy', []], '640' => ['globalcode', []], '645' => ['jquery', []], '650' => ['configfile', []],
		'660' => ['settings', ['s' => 'forte']],
		'700' => ['update', []], '700.1' => ['update', []], '700.2' => ['update', ['tab' => 'backups']], '700.3' => ['update', ['tab' => 'history']],
		'700.4' => ['update', ['tab' => 'libraries']], '700.5' => ['update', ['tab' => 'system']],
		'710' => ['compat', []], '720' => ['vendor', []], '730' => ['filemanager', []], '780' => ['libpack', []], '790' => ['todo', []],
	];

	/** "P100", "p100", "P 100", "P100.2" → "100" / "100.2"; null = not a page number. */
	public static function parse(string $s): ?string
	{
		if (!preg_match('/^\s*p\s*(\d{1,3})(?:\.(\d))?\s*$/i', $s, $m)) return null;
		return str_pad($m[1], 3, '0', STR_PAD_LEFT) . (isset($m[2]) && $m[2] !== '' ? '.' . $m[2] : '');
	}

	/** The address of a page number ('' = unknown); a tab that is not listed falls back to the page itself. */
	public static function url(string $no): string
	{
		$n = self::parse($no) ?? (preg_match('/^\d{3}(\.\d)?$/', $no) ? $no : null);
		if ($n === null) return '';
		$e = self::MAP[$n] ?? self::MAP[substr($n, 0, 3)] ?? null;
		return $e ? nu_url($e[0], $e[1]) : '';
	}
}
