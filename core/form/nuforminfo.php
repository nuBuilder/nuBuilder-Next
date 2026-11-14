<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * Form info for the globeadmin (0.8.435): the (i) button next to Save / Delete of a form opens a panel with title, code, description,
 * type, form ID, table, primary key, browse SQL and – in edit mode – the record ID with created / last changed from the audit trail.
 * Every value has its own copy button, "Copy all" copies everything as "Label: value" lines (nuapp.js: data-nu-copy-text).
 */
final class NuFormInfo
{
	/** Section cards (0.8.1184): icon per group, form type shown by name with an icon (Copy copies the stored type). */
	private const GROUP_ICON = ['form' => 'bi-file-earmark-text', 'data' => 'bi-database', 'list' => 'bi-list-ul', 'record' => 'bi-clock-history'];
	private const TYPE_ICON = ['browse' => 'bi-list-ul', 'browseedit' => 'bi-window', 'edit' => 'bi-pencil-square', 'launch' => 'bi-rocket-takeoff', 'subform' => 'bi-table', 'page' => 'bi-file-earmark-code'];

	/** @return list<array{key: string, label: string, value: string, code: bool, long?: bool, edit?: string, group?: string, pane?: bool, field?: string}> */
	public static function rows(NuFormMeta $meta, string $mode, ?string $recordId): array
	{
		$f = $meta->form;
		$rows = [];
		$grp = 'form';                                           // sections of the panel (0.8.1183): form · data · list · record
		$add = static function (string $key, string $value, bool $code = true, bool $long = false) use (&$rows, &$grp): void {
			$rows[] = ['key' => $key, 'label' => t('fi_' . $key), 'value' => $value, 'code' => $code, 'long' => $long, 'group' => $grp];
		};
		$add('description', $meta->description(), false);
		$add('code', $meta->code());
		if (NuDesign::allowed($meta) && $meta->type() !== 'page') {     // changed right in the panel (0.8.1185): builder API form.save
			$rows[0]['field'] = 'sfo_description';
			$rows[1]['field'] = 'sfo_code';
		}
		$add('form_id', $meta->id);
		$add('type', $meta->type());
		$add('name', trim((string)($f['sfo_breadcrumb_title'] ?? '')), false);
		$grp = 'data';
		if ($meta->table() !== '') {
			$add('table', $meta->table());
			$add('pk', $meta->primaryKey());
		}
		$grp = 'list';
		$sql = trim((string)($f['sfo_browse_sql'] ?? ''));
		$editSql = NuDesign::allowed($meta) ? nu_url('form', ['f' => $meta->id, 'design' => '1', 'dswork' => 'code', 'dsitem' => 'sfo_browse_sql']) : '';
		$run = $mode === 'browse' && NuBrowse::$shown !== null;
		// the stored browse SQL only where the list did not run (edit pages) – on the list the SQL as run says more (0.8.1182)
		if (!$run && $meta->hasBrowse() && $sql !== '') {
			$add('browse_sql', $sql, true, true);
			// larger view + "Edit the SQL" in design mode, new tab (0.8.676): the list designer opens Code on the browse SQL
			if ($editSql !== '') $rows[count($rows) - 1]['edit'] = $editSql;
		}
		// the SQL as run with its hash variables: in the list's side pane, Browse SQL tab → As run (0.8.1187)

		$grp = 'record';
		if ($mode === 'edit' && $meta->type() !== 'launch') {
			$new = $recordId === null || $recordId === '' || $recordId === '-1';
			$add('record_id', $new ? '-1' : $recordId);
			if (!$new && $meta->table() !== '') {
				[$created, $changed] = self::audit($meta->table(), (string)$recordId);
				$add('created', $created, false);
				$add('changed', $changed, false);
			}
		}
		return $rows;
	}

	/** Created / last changed of a record from the audit trail: "dd.mm.yyyy hh:mm:ss · login", '' when not recorded. @return array{string, string} */
	public static function audit(string $table, string $recordId): array
	{
		$db = nu_db();
		if (!$db->tableExists(NuAudit::TABLE)) return ['', ''];
		$one = static function (string $where, string $order) use ($db, $table, $recordId): string {
			$r = $db->page('SELECT changed_at, login FROM ' . NuAudit::TABLE . ' WHERE table_name = ? AND record_id = ?' . $where, [$table, $recordId], 'changed_at ' . $order, 1, 0)[0] ?? null;
			if (!$r) return '';
			return date(nu_dfmt('d.m.Y H:i:s'), (int)strtotime((string)$r['changed_at'])) . ((string)$r['login'] !== '' ? ' · ' . $r['login'] : '');
		};
		return [$one(" AND action IN ('insert', 'clone')", 'ASC'), $one('', 'DESC')];
	}

	/** Log events that only name a record without creating it (a login link for a user, a playground request). */
	private const NOT_CREATED = ['login_token_created', 'apg_request_created'];

	/**
	 * Created / last changed of a record from nuBuilder Next's log (0.8.553): first "…_created" / "…_added" event, last "…_updated" /
	 * "…_changed" / "user_permissions" event of that table + id – "dd.mm.yyyy hh:mm:ss · login", '' when not logged (e.g. made in nuBuilder,
	 * or the log row was purged by the retention). @return array{string, string}
	 */
	public static function logged(string $table, string $recordId): array
	{
		[$c, $u] = self::loggedRaw($table, $recordId);
		$f = static fn(?array $x): string => $x ? date(nu_dfmt('d.m.Y H:i:s'), (int)strtotime($x['t'])) . ($x['by'] !== '' ? ' · ' . $x['by'] : '') : '';
		return [$f($c), $f($u)];
	}

	/** As logged(), as data: [created, changed] each ['t' => 'Y-m-d H:i:s', 'by' => login] or null (0.8.759). */
	public static function loggedRaw(string $table, string $recordId): array
	{
		try {
			$db = nu_db();
			if ($recordId === '' || !$db->tableExists('zzzzsys_nunext_log')) return [null, null];
			$in = implode(', ', array_fill(0, count(self::NOT_CREATED), '?'));
			$one = static function (string $where, array $args, string $order) use ($db, $table, $recordId): ?array {
				$r = $db->page('SELECT log_time, login FROM zzzzsys_nunext_log WHERE object = ? AND record_id = ? AND (' . $where . ')',
					array_merge([$table, $recordId], $args), 'log_time ' . $order, 1, 0)[0] ?? null;
				if (!$r) return null;
				return ['t' => date('Y-m-d H:i:s', (int)strtotime((string)$r['log_time'])), 'by' => (string)$r['login']];
			};
			return [
				$one("(event LIKE '%_created' OR event LIKE '%_added') AND event NOT IN ($in)", self::NOT_CREATED, 'ASC'),
				$one("event LIKE '%_updated' OR event LIKE '%_changed' OR event = 'user_permissions'", [], 'DESC'),
			];
		} catch (Throwable) {
			return [null, null];
		}
	}

	/** The button with its panel (a Bootstrap dropdown: closes on Esc and on a click outside). */
	public static function html(NuFormMeta $meta, string $mode, ?string $recordId): string
	{
		return self::panel(self::rows($meta, $mode, $recordId), t('fi_title'), 'nuFormInfo');
	}

	/**
	 * Record info of nuBuilder Next's own record pages (0.8.500: user, procedure): the record ID, the table and created / last changed
	 * from the audit trail, plus $extra rows ['key' => value] (labels fi_<key>). The same (i) panel as the form info.
	 */
	/** $after: more rows [key => value] after created / changed (plain text, label t('fi_<key>'), 0.8.587) */
	/** @param list<array{0: string, 1: string, 2: string}> $links [icon, label, href] shown below the rows (0.8.687: user form – log, sessions) */
	public static function record(string $table, string $id, array $extra = [], string $idBase = 'nuRecInfo', array $after = [], array $links = []): string
	{
		$rows = [];
		$grp = 'record';
		$add = static function (string $key, string $value, bool $code = true) use (&$rows, &$grp): void {
			$rows[] = ['key' => $key, 'label' => t('fi_' . $key), 'value' => $value, 'code' => $code, 'group' => $grp];
		};
		$add('record_id', $id);
		foreach ($extra as $k => $v) $add((string)$k, (string)$v);
		[$created, $changed] = $table === 'zzzzsys_user' ? NuUserJson::stamps($id) : ['', ''];   // users: the stamps in sus_json (0.8.759)
		if ($created === '' || $changed === '') { [$ac, $au] = self::audit($table, $id); $created = $created ?: $ac; $changed = $changed ?: $au; }   // audit trail
		if ($created === '' || $changed === '') {                    // own record pages write the log instead (0.8.553)
			[$lc, $lu] = self::logged($table, $id);
			if ($created === '') $created = $lc;
			if ($changed === '') $changed = $lu;
		}
		if ($created !== '') $add('created', $created, false);
		if ($changed !== '') $add('changed', $changed, false);
		foreach ($after as $k => $v) $add((string)$k, (string)$v, false);
		$grp = 'data';
		$add('table', $table);
		return self::panel($rows, t('fi_record_title'), $idBase, $links);
	}

	/**
	 * The (i) button with its panel (0.8.1183, layout "grouped sections"): a head with the title and Copy all, then one section per
	 * group (Form · Data · List · Record – row key 'group', in the order the rows come) with a small upper-case heading; each row =
	 * label · value (codes as chips) · copy button. Long values (browse SQL, hash variables) take the full width: label, four lines
	 * of the value (a click opens the dialogue with all of it), then a row of buttons Show all · Edit in design mode · Copy.
	 * @param list<array{key: string, label: string, value: string, code: bool, long?: bool, edit?: string, group?: string, pane?: bool, field?: string}> $rows
	 */
	public static function panel(array $rows, string $title, string $idBase, array $links = []): string
	{
		$all = [];
		$groups = [];
		$dialogs = '';
		foreach ($rows as $r) {
			$v = $r['value'];
			$long = !empty($r['long']) && $v !== '';
			$dlg = $idBase . 'Dlg_' . preg_replace('/[^a-z0-9_]/i', '', $r['key']);
			if ($r['key'] === 'record_id' && $v === '-1') $shown = '<span class="text-body-secondary">' . h(t('fi_new_record')) . '</span>';
			elseif ($v === '') $shown = '<span class="text-body-secondary">' . h(in_array($r['key'], ['created', 'changed'], true) ? t('fi_no_audit') : '–') . '</span>';
			// long values: four lines in a box with its Copy, a click opens the dialogue with all of it (0.8.1182 / 0.8.1184)
			elseif ($long) $shown = '<div class="nuFiCode"><pre class="nuFormInfoSql mb-0" role="button" tabindex="0" data-bs-toggle="modal" data-bs-target="#' . h($dlg) . '" title="' . h(t('fi_sql_view')) . '">' . h($v) . '</pre>'
				. '<button type="button" class="btn btn-sm nuFormInfoCopy nuFiCodeCopy" data-nu-copy-text="' . h($v) . '" aria-label="' . h(t('copy') . ': ' . $r['label']) . '"><i class="bi bi-clipboard me-1" aria-hidden="true"></i>' . h(t('copy')) . '</button></div>';
			elseif ($r['key'] === 'type' && isset(self::TYPE_ICON[$v])) $shown = '<span class="nuFiType" title="' . h($v) . '"><i class="bi ' . self::TYPE_ICON[$v] . ' me-1" aria-hidden="true"></i>' . h(t('form_type_' . $v)) . '</span>';
			else $shown = $r['code'] ? '<code class="nuFiChip">' . h($v) . '</code>' : h($v);
			$copyBtn = static fn(string $cls, string $inner): string => '<button type="button" class="btn btn-sm ' . $cls . ' nuFormInfoCopy" data-nu-copy-text="' . h($v) . '" title="' . h(t('copy')) . '" aria-label="' . h(t('copy') . ': ' . $r['label']) . '">' . $inner . '</button>';
			if ($long) {                                             // browse SQL / hash variables: Copy inside the box, Show all · Edit below (0.8.1184)
				$copy = '<div class="nuFormInfoActs">'
					. '<button type="button" class="btn btn-sm nuFiSoft nuFormInfoView" data-bs-toggle="modal" data-bs-target="#' . h($dlg) . '" aria-label="' . h(t('fi_sql_view') . ': ' . $r['label']) . '"><i class="bi bi-chevron-down me-1" aria-hidden="true"></i>' . h(t('fi_show_all')) . '</button>'
					. (!empty($r['edit']) ? '<a class="btn btn-sm btn-outline-secondary nuFormInfoEdit" href="' . h($r['edit']) . '" target="_blank" rel="noopener"' . ' title="' . h(t('fi_sql_edit')) . '"' . '><i class="bi bi-pencil me-1" aria-hidden="true"></i>' . h(t('edit')) . '</a>' : '')
					. '</div>';
				$dialogs .= '<div class="modal fade" id="' . h($dlg) . '" tabindex="-1" aria-labelledby="' . h($dlg) . 'T" aria-hidden="true"><div class="modal-dialog modal-xl modal-dialog-scrollable"><div class="modal-content">'
					. '<div class="modal-header"><h2 class="modal-title fs-5" id="' . h($dlg) . 'T">' . h($r['label']) . '</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="' . h(t('close')) . '"></button></div>'
					. '<div class="modal-body"><pre class="nuFormInfoSqlBig mb-0" tabindex="0">' . h($v) . '</pre></div>'
					. '<div class="modal-footer">'
					. (!empty($r['edit']) ? '<a class="btn btn-outline-primary me-auto nuFormInfoEdit" href="' . h($r['edit']) . '" target="_blank" rel="noopener"' . '><i class="bi bi-pencil-square me-1"></i>' . h(t('edit')) . '</a>' : '')
					. '<button type="button" class="btn btn-outline-secondary" data-nu-copy-text="' . h($v) . '"><i class="bi bi-clipboard me-1"></i>' . h(t('copy')) . '</button>'
					. '<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">' . h(t('close')) . '</button></div>'
					. '</div></div></div>';
			} else {
				$copy = $v !== '' && !($r['key'] === 'record_id' && $v === '-1') ? $copyBtn('btn-link', '<i class="bi bi-clipboard"></i>') : '';
				if (!empty($r['field'])) {                            // pencil: the value becomes a field, Enter saves, Esc cancels (0.8.1185)
					$shown = '<span class="nuFiVal">' . $shown . '</span>';
					$copy = '<span class="nuFiBtns"><button type="button" class="btn btn-sm btn-link nuFiChange" data-nu-fi-field="' . h($r['field']) . '" data-nu-fi-value="' . h($v) . '" title="' . h(t('fi_change')) . '" aria-label="' . h(t('fi_change') . ': ' . $r['label']) . '"><i class="bi bi-pencil"></i></button>' . $copy . '</span>';
				}
			}
			if ($v !== '') $all[] = $r['label'] . ': ' . $v;
			$g = (string)($r['group'] ?? '');
			$groups[$g] = ($groups[$g] ?? '') . '<div class="nuFormInfoRow' . ($long ? ' nuFiLong' : '') . '" data-key="' . h($r['key']) . '"><dt>' . h($r['label']) . '</dt><dd>' . $shown . '</dd>' . $copy . '</div>';
		}
		$body = '';
		foreach ($groups as $g => $html) {
			$hid = $idBase . 'G_' . preg_replace('/[^a-z0-9_]/i', '', $g);
			$body .= '<section class="nuFiSec" data-group="' . h($g) . '"' . ($g !== '' ? ' aria-labelledby="' . h($hid) . '"' : '') . '>'
				. ($g !== '' ? '<h3 class="nuFiSecHead" id="' . h($hid) . '"><i class="bi ' . h(self::GROUP_ICON[$g] ?? 'bi-info-circle') . '" aria-hidden="true"></i>' . h(t('fi_grp_' . $g)) . '</h3>' : '')
				. '<dl class="nuFormInfoList small mb-0">' . $html . '</dl></section>';
		}
		return '<div class="dropdown">'
			. '<button type="button" class="btn btn-outline-secondary" id="' . h($idBase) . 'Btn" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" title="' . h($title) . '" aria-label="' . h($title) . '"><i class="bi bi-info-circle"></i></button>'
			. '<div class="dropdown-menu dropdown-menu-end p-0 nuFormInfo" id="' . h($idBase) . '" data-nu-fi-texts="' . h((string)json_encode(['save' => t('save'), 'cancel' => t('cancel'), 'saved' => t('fi_saved'), 'required' => t('builder_err_required')])) . '">'
			. '<div class="nuFiHead"><span class="nuFiTitle"><span class="nuFiTitleIcon" aria-hidden="true"><i class="bi bi-info-lg"></i></span>' . h($title) . '</span>'
			. '<button type="button" class="btn btn-sm btn-outline-secondary" id="' . h($idBase) . 'CopyAll" data-nu-copy-text="' . h(implode("\n", $all)) . '"><i class="bi bi-clipboard me-1"></i>' . h(t('fi_copy_all')) . '</button></div>'
			. '<div class="nuFiBody">' . $body . '</div>'
			. ($links ? '<div class="border-top py-1 nuFormInfoLinks">' . implode('', array_map(static fn($l) => '<a class="dropdown-item small" href="' . h((string)$l[2]) . '"><i class="bi ' . h((string)$l[0]) . ' me-2" aria-hidden="true"></i>' . h((string)$l[1]) . '</a>', $links)) . '</div>' : '')
			. '</div></div>' . $dialogs;
	}
}
