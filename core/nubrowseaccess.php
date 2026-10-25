<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * Browse column visibility (0.8.480, handover spec 07): per column of a form's list, sbr_json → nunext.access =
 * {"default": "show"|"hide", "rules": [{"type": access|user|perm|team|dept, "subject": …, "mode": "show"|"hide"}]} – the most specific
 * rule wins (NuSubject), a tie hides. Also field data access (spec 04): a column showing a withheld field is hidden, a masked one masked.
 * A hidden column is never selected, searched, filtered, sorted or exported (NuBrowse::query builds the list without it).
 * The globeadmin sees every column (unless "Apply data access to the globeadmin" is on).
 */
final class NuBrowseAccess
{
	/** 'show', 'hide' or 'mask:<pattern>' for a column of $meta->browse. */
	public static function state(NuFormMeta $meta, array $b, ?array $me = null): string
	{
		$me ??= NuSubject::me();
		if ($me['ga'] && !NuSecurity::get('field_access_ga')) return 'show';
		$acc = self::rules($b);
		if ($acc !== null) {
			$all = $acc['rules'];
			$all[] = ['type' => 'default', 'subject' => '', 'mode' => $acc['default']];
			$win = NuSubject::best($all, ['hide', 'show'], null, $me);
			if (($win['mode'] ?? 'show') === 'hide') return 'hide';
		}
		$o = NuFieldAccess::columnObject($meta, (string)$b['sbr_display']);
		if ($o !== null) {
			if (NuFieldAccess::withheld($o)) return 'hide';
			if (NuFieldAccess::masked($o)) return 'mask:' . NuFieldAccess::maskOf($o);
		}
		return 'show';
	}

	/** The column's rules, null = none (visible to everyone). @return ?array{default: string, rules: list<array>} */
	public static function rules(array $b): ?array
	{
		$a = NuFormMeta::nunextJson($b['sbr_json'] ?? null)['access'] ?? null;
		if (!is_array($a)) return null;
		$rules = [];
		foreach ((array)($a['rules'] ?? []) as $r) {
			if (!is_array($r) || !in_array($r['type'] ?? '', NuSubject::TYPES, true) || trim((string)($r['subject'] ?? '')) === '' || !in_array($r['mode'] ?? '', ['show', 'hide'], true)) continue;
			$rules[] = ['type' => (string)$r['type'], 'subject' => trim((string)$r['subject']), 'mode' => (string)$r['mode']];
		}
		$default = ($a['default'] ?? 'show') === 'hide' ? 'hide' : 'show';
		return $rules || $default === 'hide' ? ['default' => $default, 'rules' => $rules] : null;
	}

	/** Saves the rules of a column into its sbr_json (nunext.access); empty rules + default show = removed. */
	public static function save(string $browseId, string $default, array $rules): void
	{
		$db = nu_db();
		$row = $db->one('SELECT zzzzsys_browse_id, sbr_zzzzsys_form_id, sbr_json FROM zzzzsys_browse WHERE zzzzsys_browse_id = ?', [$browseId]);
		if (!$row) return;
		$clean = [];
		$seen = [];
		foreach ($rules as $r) {
			$type = (string)($r['type'] ?? '');
			$subject = mb_substr(trim((string)($r['subject'] ?? '')), 0, 100);
			$mode = (string)($r['mode'] ?? '');
			if (!in_array($type, NuSubject::TYPES, true) || $subject === '' || !in_array($mode, ['show', 'hide'], true)) continue;
			$k = $type . "\x1f" . mb_strtolower($subject);
			if (isset($seen[$k])) continue;
			$seen[$k] = true;
			$clean[] = ['type' => $type, 'subject' => $subject, 'mode' => $mode];
		}
		$default = $default === 'hide' ? 'hide' : 'show';
		$access = $clean || $default === 'hide' ? ['default' => $default, 'rules' => $clean] : null;
		$old = NuFormMeta::nunextJson($row['sbr_json'] ?? null)['access'] ?? null;
		$json = NuFormMeta::mergeNunextJson($row['sbr_json'] ?? null, ['access' => $access]);
		if ($old === $access) return;
		$db->update('zzzzsys_browse', ['sbr_json' => $json], 'zzzzsys_browse_id', $browseId);
		NuLog::write('column_access_saved', 'zzzzsys_browse', $browseId, ['form' => (string)$row['sbr_zzzzsys_form_id'], 'before' => $old, 'after' => $access]);
	}
}
