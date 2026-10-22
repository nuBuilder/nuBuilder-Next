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

	/** Everything that defines a form, as plain rows. */
	public static function capture(string $formId): array
	{
		$db      = nu_db();
		$objects = $db->all('SELECT * FROM zzzzsys_object WHERE sob_all_zzzzsys_form_id = ?', [$formId]);
		$ids     = array_column($objects, 'zzzzsys_object_id');
		$events  = [];
		$php     = [];
		foreach (array_chunk($ids, 500) as $chunk) {
			$in = implode(',', array_fill(0, count($chunk), '?'));
			$events = array_merge($events, $db->all("SELECT * FROM zzzzsys_event WHERE sev_zzzzsys_object_id IN ($in)", $chunk));
			$php    = array_merge($php, $db->all("SELECT * FROM zzzzsys_php WHERE zzzzsys_php_id IN ($in)", array_map(static fn($i) => $i . '_AB', $chunk)));
		}
		$hooks = array_map(static fn($h) => $formId . '_' . $h, ['BB', 'BE', 'BS', 'AS', 'BD', 'AD']);
		$php   = array_merge($php, $db->all('SELECT * FROM zzzzsys_php WHERE zzzzsys_php_id IN (' . implode(',', array_fill(0, count($hooks), '?')) . ')', $hooks));
		return [
			'form'    => $db->one('SELECT * FROM zzzzsys_form WHERE zzzzsys_form_id = ?', [$formId]),
			'tabs'    => $db->all('SELECT * FROM zzzzsys_tab WHERE syt_zzzzsys_form_id = ?', [$formId]),
			'objects' => $objects,
			'events'  => $events,
			'browse'  => $db->all('SELECT * FROM zzzzsys_browse WHERE sbr_zzzzsys_form_id = ?', [$formId]),
			'php'     => $php,
		];
	}

	public static function snapshot(string $formId, string $action): void
	{
		NuSchema::ensure();
		$db = nu_db();
		$u  = NuAuth::currentRaw();
		$db->insert('zzzzsys_nunext_form_history', [
			'zzzzsys_nunext_form_history_id' => nu_id(), 'form_id' => $formId, 'created_at' => date('Y-m-d H:i:s'),
			'login' => mb_substr($u['login'], 0, 50), 'action' => mb_substr($action, 0, 60),
			'snapshot' => json_encode(self::capture($formId), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
		]);
		NuLog::write('form_changed', (string)$db->value('SELECT sfo_code FROM zzzzsys_form WHERE zzzzsys_form_id = ?', [$formId]), $formId, ['action' => $action]);

		// keep the newest KEEP snapshots per form
		$old = $db->page('SELECT zzzzsys_nunext_form_history_id FROM zzzzsys_nunext_form_history WHERE form_id = ?', [$formId], 'created_at DESC, zzzzsys_nunext_form_history_id DESC', 1000, self::KEEP);
		foreach ($old as $r) $db->delete('zzzzsys_nunext_form_history', 'zzzzsys_nunext_form_history_id', $r['zzzzsys_nunext_form_history_id']);
	}

	public static function list(string $formId, int $limit = 50): array
	{
		NuSchema::ensure();
		return nu_db()->page('SELECT zzzzsys_nunext_form_history_id AS id, created_at, login, action FROM zzzzsys_nunext_form_history WHERE form_id = ?', [$formId],
			'created_at DESC, zzzzsys_nunext_form_history_id DESC', $limit, 0);
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
		$current = self::capture($formId);

		$db->transaction(static function (NuDB $db) use ($formId, $snap, $current) {
			foreach ($current['events'] as $e) $db->delete('zzzzsys_event', 'zzzzsys_event_id', (string)$e['zzzzsys_event_id']);
			foreach ($current['php'] as $p) $db->delete('zzzzsys_php', 'zzzzsys_php_id', (string)$p['zzzzsys_php_id']);
			$db->delete('zzzzsys_object', 'sob_all_zzzzsys_form_id', $formId);
			$db->delete('zzzzsys_tab', 'syt_zzzzsys_form_id', $formId);
			$db->delete('zzzzsys_browse', 'sbr_zzzzsys_form_id', $formId);

			$form = self::only('zzzzsys_form', $snap['form']);
			unset($form['zzzzsys_form_id']);
			$db->update('zzzzsys_form', $form, 'zzzzsys_form_id', $formId);
			foreach (['tabs' => 'zzzzsys_tab', 'objects' => 'zzzzsys_object', 'events' => 'zzzzsys_event', 'browse' => 'zzzzsys_browse', 'php' => 'zzzzsys_php'] as $key => $table) {
				foreach ($snap[$key] ?? [] as $r) $db->insert($table, self::only($table, $r));
			}
		});
		NuFormMeta::forget($formId);
		NuLog::write('form_restored', (string)($snap['form']['sfo_code'] ?? ''), $formId, ['snapshot' => $historyId]);
	}

	/** Only the columns the table has today (the schema may have changed since the snapshot). */
	private static function only(string $table, array $row): array
	{
		$cols = nu_db()->columns($table);
		$out  = [];
		foreach ($row as $k => $v) if (($c = NuRecord::column($cols, (string)$k)) !== null) $out[$c] = $v;
		return $out;
	}
}
