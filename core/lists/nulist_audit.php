<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/** The system list "audit" – part of NuListDefs (one file per list, code review L3; the format is described there). */
trait NuListAudit
{
	private static function audit(): array
	{
		$T = NuAudit::TABLE;
		$cols = [
			'time' => ['label' => t('log_time'), 'select' => ['changed_at' => 'changed_at'], 'sort' => 'changed_at', 'class' => 'text-nowrap small', 'locked' => true,
				'text' => static fn($r) => date(nu_dfmt('d.m.Y H:i:s'), strtotime((string)$r['changed_at']))],
			'user' => self::col('login', 'login', t('user'), 'small'),
			'action' => ['label' => t('aud_action'), 'select' => ['action' => 'action'], 'sort' => 'action', 'text' => static fn($r) => self::auditAction((string)$r['action']),
				'html' => static fn($r) => self::auditBadge((string)$r['action'])],
			'table' => self::col('table_name', 'table_name', t('aud_table'), 'small text-nowrap'),
			'record' => ['label' => t('aud_record'), 'select' => ['record_id' => 'record_id', 'parent_table' => 'parent_table', 'parent_id' => 'parent_id', 'form_id' => 'form_id'],
				'sort' => 'record_id', 'class' => 'small text-break',
				'text' => static fn($r) => $r['record_id'] . ((string)$r['parent_id'] !== '' ? ' (' . $r['parent_table'] . ' #' . $r['parent_id'] . ')' : ''),
				'html' => static fn($r) => h((string)$r['record_id']) . ((string)$r['parent_id'] !== '' ? '<div class="text-body-secondary">' . h(t('aud_in', (string)$r['parent_table'], (string)$r['parent_id'])) . '</div>' : '')],
			'form' => self::col('form_code', 'form_code', t('aud_form'), 'small'),
			'column' => self::col('column_name', 'column_name', t('aud_column'), 'small fw-semibold text-break'),
			'old' => ['label' => t('aud_old'), 'select' => ['old_value' => 'old_value'], 'sort' => null, 'class' => 'small text-break',
				'text' => static fn($r) => (string)($r['old_value'] ?? ''), 'html' => static fn($r) => self::auditValue($r['old_value'], true)],
			'new' => ['label' => t('aud_new'), 'select' => ['new_value' => 'new_value'], 'sort' => null, 'class' => 'small text-break',
				'text' => static fn($r) => (string)($r['new_value'] ?? ''), 'html' => static fn($r) => self::auditValue($r['new_value'])],
			'source' => ['label' => t('aud_source'), 'select' => ['source' => 'source'], 'sort' => 'source', 'class' => 'small',
				'text' => static fn($r) => ($l = t('aud_src_' . $r['source'])) === 'aud_src_' . $r['source'] ? (string)$r['source'] : $l],
			'ip' => ['label' => 'IP', 'select' => ['ip' => 'ip'], 'sort' => 'ip', 'class' => 'small text-body-secondary', 'text' => static fn($r) => NuDemo::ip($r['ip'])],
			'change' => self::col('change_id', 'change_id', t('aud_change'), 'small text-body-secondary'),
			'id' => self::idCol('zzzzsys_nunext_audit_id'),
		];
		$distinct = static function (string $col) use ($T): array {
			$o = [];
			try { foreach (nu_db()->all("SELECT DISTINCT $col AS v FROM $T WHERE $col <> '' ORDER BY $col") as $r) $o[(string)$r['v']] = (string)$r['v']; } catch (Throwable $ig) { nu_ignored($ig); }
			return $o;
		};
		$filters = [
			'action' => ['label' => t('aud_action'), 'type' => 'select',
				'options' => static fn(): array => array_combine(NuAudit::ACTIONS, array_map([self::class, 'auditAction'], NuAudit::ACTIONS)),
				'where' => static fn(string $v) => ['action = ?', [$v]]],
			'table' => ['label' => t('aud_table'), 'type' => 'select', 'tom' => true, 'options' => static fn(): array => $distinct('table_name'),
				'where' => static fn(string $v) => ['(table_name = ? OR parent_table = ?)', [$v, $v]]],
			'form' => ['label' => t('aud_form'), 'type' => 'select', 'tom' => true, 'options' => static fn(): array => $distinct('form_code'),
				'where' => static fn(string $v) => ['form_code = ?', [$v]]],
			// user as a searchable select of the logins in the audit trail (as the log; was a text field)
			'login' => ['label' => t('user'), 'type' => 'select', 'tom' => true,
				'options' => static function () use ($T): array { $o = []; foreach (nu_db()->all("SELECT DISTINCT login FROM $T WHERE login IS NOT NULL AND login <> '' ORDER BY login") as $r) $o[(string)$r['login']] = (string)$r['login']; return $o; },
				'where' => static fn(string $v) => ['login = ?', [$v]]],
			'record' => ['label' => t('aud_record'), 'type' => 'text', 'expr' => 'record_id'],
			'column' => ['label' => t('aud_column'), 'type' => 'text', 'expr' => 'column_name'],
			'from' => ['label' => t('log_from'), 'type' => 'datefrom', 'expr' => 'changed_at'],
			'to' => ['label' => t('log_to'), 'type' => 'dateto', 'expr' => 'changed_at'],
			'when' => ['label' => t('lv_period'), 'type' => 'date', 'expr' => 'changed_at', 'presets' => ['d7', 'd30', 'd90']],
		];
		return [
			'key' => 'audit', 'title' => t('aud_title'), 'route' => 'audit', 'empty_icon' => 'bi-clock-history', 'form' => NuAcl::FORM_AUDIT, 'from' => " FROM $T", 'where' => [], 'params' => [],
			'id' => 'zzzzsys_nunext_audit_id', 'always' => ['record'],
			'link' => static fn($r) => (string)$r['form_id'] !== '' ? nu_url('audithistory', ['f' => $r['form_id'], 'r' => (string)$r['parent_id'] !== '' ? $r['parent_id'] : $r['record_id']]) : '',
			'search' => ['table_name', 'record_id', 'parent_id', 'column_name', 'old_value', 'new_value', 'form_code'], 'search_ph' => t('aud_search_ph'),
			'columns' => $cols, 'filters' => $filters,
			'builtin' => [
				'default' => ['columns' => ['time', 'user', 'action', 'table', 'record', 'column', 'old', 'new'],
					'card' => ['title' => 'column', 'badge' => 'action', 'lines' => ['time', 'user', 'table', 'record', 'old', 'new']],
					'sort' => ['time', 'desc'], 'per' => 50, 'filters' => ['shown' => ['action', 'table', 'login', 'from', 'to'], 'values' => []]],
				'compact' => ['columns' => ['time', 'user', 'action', 'table', 'record'], 'card' => ['title' => 'table', 'badge' => 'action', 'lines' => ['time', 'user', 'record']],
					'sort' => ['time', 'desc'], 'per' => 50, 'filters' => ['shown' => ['action', 'table', 'when'], 'values' => []]],
				'all' => ['columns' => array_keys($cols), 'card' => ['title' => 'column', 'badge' => 'action', 'lines' => ['time', 'user', 'table', 'record', 'old', 'new']],
					'sort' => ['time', 'desc'], 'per' => 50, 'filters' => ['shown' => array_keys($filters), 'values' => []]],
			],
		];
	}
}
