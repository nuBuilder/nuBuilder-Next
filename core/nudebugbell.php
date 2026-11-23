<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * The debug icon of the top bar: a red bug with the number of new rows of zzzzsys_debug (nuDebug(), errors of form code,
 * suggestions …) – shown only while there are new ones, to users who may open Debug results. "New" = written after the user last
 * opened Debug results or chose "Mark as read" (setting debug_seen {user id: unix time}); without a mark the last day counts.
 * Drawn by nu_debug_bell() (core/nulayout.php), kept current by assets/js/nudebugbell.js through the JSON route ?p=debugbell.
 */
final class NuDebugBell
{
	public const SETTING = 'debug_seen';
	public const LIST = 6;
	public const DEFAULT_HOURS = 24;

	public static function allowed(): bool
	{
		$u = NuAuth::user();
		if (!$u || !empty($u['demo'])) return false;
		return NuAcl::can(NuAcl::FORM_DEBUG);
	}

	private static function who(): string
	{
		$u = NuAuth::user() ?? [];
		return !empty($u['globeadmin']) ? 'globeadmin' : (string)($u['id'] ?? '');
	}

	public static function seen(): int
	{
		$s = nu_setting_json(self::SETTING);
		return isset($s[self::who()]) ? (int)$s[self::who()] : time() - self::DEFAULT_HOURS * 3600;
	}

	/** @return array{n: int, last: int} */
	public static function summary(): array
	{
		$db = nu_db();
		if (!$db->tableExists(NuDebugLog::TABLE)) return ['n' => 0, 'last' => 0];
		$r = $db->one('SELECT COUNT(*) AS n, MAX(deb_added) AS m FROM ' . NuDebugLog::TABLE . ' WHERE deb_added > ?', [self::seen()]) ?? [];
		return ['n' => (int)($r['n'] ?? 0), 'last' => (int)($r['m'] ?? 0)];
	}

	/** The newest rows for the panel. @return list<array{id: string, at: string, title: string, text: string, new: bool}> */
	public static function latest(): array
	{
		$db = nu_db();
		if (!$db->tableExists(NuDebugLog::TABLE)) return [];
		$seen = self::seen();
		$out = [];
		foreach ($db->page('SELECT zzzzsys_debug_id, deb_message, deb_flag, deb_added FROM ' . NuDebugLog::TABLE, [], 'deb_added DESC, zzzzsys_debug_id DESC', self::LIST, 0) as $r) {
			[$title, $text] = self::parts((string)$r['deb_message']);
			if ($title === '' && (string)($r['deb_flag'] ?? '') !== '') $title = (string)$r['deb_flag'];
			$at = (int)$r['deb_added'];
			$out[] = ['id' => (string)$r['zzzzsys_debug_id'], 'at' => $at ? date(date('Y-m-d', $at) === date('Y-m-d') ? 'H:i' : nu_dfmt('d.m. H:i'), $at) : '',
				'title' => $title, 'text' => $text, 'new' => $at > $seen];
		}
		return $out;
	}

	/** First line (where, without the date and "line n") and the first line of the text. @return array{0: string, 1: string} */
	public static function parts(string $message): array
	{
		$lines = array_values(array_filter(array_map('trim', explode("\n", NuDebugPage::text($message))), static fn($l) => $l !== ''));
		if (!$lines) return ['', ''];
		$first = (string)preg_replace('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d\s*-\s*/', '', $lines[0]);
		$first = rtrim((string)preg_replace('/\s+line \d+$/', '', $first), ': ');
		$text = '';
		foreach (array_slice($lines, 1) as $l) {
			$l = trim((string)preg_replace('/^\[\d+\]\s*:\s*/', '', $l));
			if ($l !== '') { $text = $l; break; }
		}
		if ($first === '') { $first = $text; $text = ''; }
		return [mb_substr($first, 0, 120), mb_substr($text, 0, 200)];
	}

	public static function markRead(): void
	{
		$s = nu_setting_json(self::SETTING);
		$s[self::who()] = time();
		nu_setting_json_save(self::SETTING, $s, (string)(NuAuth::user()['login'] ?? ''));
	}
}
