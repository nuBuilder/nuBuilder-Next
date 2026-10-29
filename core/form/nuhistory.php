<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * Change history of forms in the builder (zzzzsys_nunext_form_history): a full snapshot of the form's metadata
 * is stored before every change, so any earlier state can be restored. Table structure (DDL) is not part of it.
 */
final class NuFormHistory
{
	private const KEEP = 100;

	/** Everything that defines a form, as plain rows (NuFormSnapshot, 0.8.386). */
	public static function capture(string $formId): array
	{
		return NuFormSnapshot::capture($formId) ?? [];
	}

	/** Stores the form's current state; @return string the id of the history row. Versions ("published vN") are never pruned. */
	public static function snapshot(string $formId, string $action): string
	{
		NuSchema::ensure();
		$db = nu_db();
		$u  = NuAuth::currentRaw();
		$hid = nu_id();
		$db->insert('zzzzsys_nunext_form_history', [
			'zzzzsys_nunext_form_history_id' => $hid, 'form_id' => $formId, 'created_at' => date('Y-m-d H:i:s'),
			'login' => mb_substr($u['login'], 0, 50), 'action' => mb_substr($action, 0, 60),
			'snapshot' => json_encode(self::capture($formId), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
		]);
		NuLog::write('form_changed', (string)$db->value('SELECT sfo_code FROM zzzzsys_form WHERE zzzzsys_form_id = ?', [$formId]), $formId, ['action' => $action]);

		// keep the newest KEEP snapshots per form
		$old = $db->page("SELECT zzzzsys_nunext_form_history_id FROM zzzzsys_nunext_form_history WHERE form_id = ? AND action NOT LIKE 'published v%'", [$formId], 'created_at DESC, zzzzsys_nunext_form_history_id DESC', 1000, self::KEEP);
		foreach ($old as $r) $db->delete('zzzzsys_nunext_form_history', 'zzzzsys_nunext_form_history_id', $r['zzzzsys_nunext_form_history_id']);
		return $hid;
	}

	public static function list(string $formId, int $limit = 50): array
	{
		NuSchema::ensure();
		return nu_db()->page('SELECT zzzzsys_nunext_form_history_id AS id, created_at, login, action FROM zzzzsys_nunext_form_history WHERE form_id = ?', [$formId],
			'created_at DESC, zzzzsys_nunext_form_history_id DESC', $limit, 0);
	}

	/** The stored snapshot of a history row (design mode's compare, 0.8.668). */
	public static function get(string $formId, string $historyId): array
	{
		NuSchema::ensure();
		$row = nu_db()->one('SELECT snapshot FROM zzzzsys_nunext_form_history WHERE zzzzsys_nunext_form_history_id = ? AND form_id = ?', [$historyId, $formId]);
		$snap = $row ? json_decode((string)$row['snapshot'], true) : null;
		if (!is_array($snap) || empty($snap['form'])) throw new RuntimeException(t('record_not_found'));
		return $snap;
	}

	/** Restores a snapshot (the current state is saved first, so a restore can be undone). */
	public static function restore(string $formId, string $historyId): void
	{
		NuSchema::ensure();
		$db  = nu_db();
		$row = $db->one('SELECT snapshot FROM zzzzsys_nunext_form_history WHERE zzzzsys_nunext_form_history_id = ? AND form_id = ?', [$historyId, $formId]);
		if (!$row) throw new RuntimeException(t('record_not_found'));
		$snap = json_decode((string)$row['snapshot'], true);
		if (!is_array($snap) || empty($snap['form'])) throw new RuntimeException('Invalid snapshot');
		// only the globeadmin may rename or delete tabs (0.8.247) – also not through a restore
		if (empty(NuAuth::user()['globeadmin'])) {
			$old = [];
			foreach ($snap['tabs'] ?? [] as $t) $old[(string)($t['zzzzsys_tab_id'] ?? '')] = trim((string)($t['syt_title'] ?? ''));
			foreach ($db->all('SELECT zzzzsys_tab_id, syt_title FROM zzzzsys_tab WHERE syt_zzzzsys_form_id = ?', [$formId]) as $cur) {
				$id = (string)$cur['zzzzsys_tab_id'];
				if (!array_key_exists($id, $old) || $old[$id] !== trim((string)$cur['syt_title'])) throw new RuntimeException(t('builder_tabs_globeadmin_restore'));
			}
		}

		self::snapshot($formId, 'before restore');
		$db->transaction(static fn(NuDB $db) => NuFormSnapshot::apply($db, $formId, $snap));
		NuFormMeta::forget($formId);
		NuLog::write('form_restored', (string)($snap['form']['sfo_code'] ?? ''), $formId, ['snapshot' => $historyId]);
	}
}
