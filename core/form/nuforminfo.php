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
	/** @return list<array{key: string, label: string, value: string, code: bool, long?: bool}> */
	public static function rows(NuFormMeta $meta, string $mode, ?string $recordId): array
	{
		$f = $meta->form;
		$rows = [];
		$add = static function (string $key, string $value, bool $code = true, bool $long = false) use (&$rows): void {
			$rows[] = ['key' => $key, 'label' => t('fi_' . $key), 'value' => $value, 'code' => $code, 'long' => $long];
		};
		$add('name', trim((string)($f['sfo_breadcrumb_title'] ?? '')), false);
		$add('code', $meta->code());
		$add('description', $meta->description(), false);
		$add('type', $meta->type());
		$add('form_id', $meta->id);
		if ($meta->table() !== '') {
			$add('table', $meta->table());
			$add('pk', $meta->primaryKey());
		}
		$sql = trim((string)($f['sfo_browse_sql'] ?? ''));
		if ($meta->hasBrowse() && $sql !== '') $add('browse_sql', $sql, true, true);

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
			return date('d.m.Y H:i:s', (int)strtotime((string)$r['changed_at'])) . ((string)$r['login'] !== '' ? ' · ' . $r['login'] : '');
		};
		return [$one(" AND action IN ('insert', 'clone')", 'ASC'), $one('', 'DESC')];
	}

	/** The button with its panel (a Bootstrap dropdown: closes on Esc and on a click outside). */
	public static function html(NuFormMeta $meta, string $mode, ?string $recordId): string
	{
		$rows = self::rows($meta, $mode, $recordId);
		$all = [];
		$items = '';
		foreach ($rows as $r) {
			$v = $r['value'];
			if ($r['key'] === 'record_id' && $v === '-1') $shown = '<span class="text-body-secondary">' . h(t('fi_new_record')) . '</span>';
			elseif ($v === '') $shown = '<span class="text-body-secondary">' . h(in_array($r['key'], ['created', 'changed'], true) ? t('fi_no_audit') : '–') . '</span>';
			elseif (!empty($r['long'])) $shown = '<pre class="nuFormInfoSql mb-0">' . h($v) . '</pre>';
			else $shown = $r['code'] ? '<code>' . h($v) . '</code>' : h($v);
			$copy = $v !== '' && !($r['key'] === 'record_id' && $v === '-1')
				? '<button type="button" class="btn btn-sm btn-link nuFormInfoCopy" data-nu-copy-text="' . h($v) . '" title="' . h(t('copy')) . '" aria-label="' . h(t('copy') . ': ' . $r['label']) . '"><i class="bi bi-clipboard"></i></button>'
				: '';
			if ($v !== '') $all[] = $r['label'] . ': ' . $v;
			$items .= '<div class="nuFormInfoRow" data-key="' . h($r['key']) . '"><dt>' . h($r['label']) . '</dt><dd>' . $shown . '</dd>' . $copy . '</div>';
		}
		return '<div class="dropdown">'
			. '<button type="button" class="btn btn-outline-secondary" id="nuFormInfoBtn" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" title="' . h(t('fi_title')) . '" aria-label="' . h(t('fi_title')) . '"><i class="bi bi-info-circle"></i></button>'
			. '<div class="dropdown-menu dropdown-menu-end p-0 nuFormInfo" id="nuFormInfo">'
			. '<div class="px-3 py-2 border-bottom fw-semibold small">' . h(t('fi_title')) . '</div>'
			. '<dl class="nuFormInfoList small mb-0">' . $items . '</dl>'
			. '<div class="border-top px-3 py-2 text-end"><button type="button" class="btn btn-sm btn-outline-secondary" id="nuFormInfoCopyAll" data-nu-copy-text="' . h(implode("\n", $all)) . '"><i class="bi bi-clipboard me-1"></i>' . h(t('fi_copy_all')) . '</button></div>'
			. '</div></div>';
	}
}
