<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/** The system list "log" – part of NuListDefs (one file per list, code review L3; the format is described there). */
trait NuListLog
{
	private static function log(): array
	{
		$plain = static fn(string $html) => trim(html_entity_decode(strip_tags(str_replace(['<br>', '</li>'], ' · ', $html)), ENT_QUOTES, 'UTF-8'), ' ·');
		$cols = [
			'time' => ['label' => t('log_time'), 'select' => ['log_time' => 'log_time'], 'sort' => 'log_time', 'class' => 'text-nowrap small', 'locked' => true,
				'text' => static fn($r) => date(nu_dfmt('d.m.Y H:i:s'), strtotime((string)$r['log_time']))],
			'user' => self::col('login', 'login', t('user'), 'small'),
			'event' => ['label' => t('log_event'), 'select' => ['event' => 'event'], 'sort' => 'event', 'text' => static fn($r) => self::eventLabel((string)$r['event']),
				'html' => static fn($r) => self::logBadge((string)$r['event'])],
			'object' => ['label' => t('log_object'), 'select' => ['object' => 'object', 'record_id' => 'record_id'], 'sort' => 'object', 'class' => 'small text-break',
				'text' => static fn($r) => $r['object'] . ((string)$r['record_id'] !== '' ? ' #' . $r['record_id'] : ''),
				'html' => static function ($r): string {
					$url = (string)$r['record_id'] !== '' ? self::logRecordUrl((string)$r['object'], (string)$r['record_id']) : '';
					$rec = (string)$r['record_id'] !== '' ? '<div class="text-body-secondary">'
						. ($url !== '' ? '<a class="nuLogRec" href="' . h($url) . '" title="' . h(t('log_open_record')) . '">#' . h($r['record_id']) . '</a>' : '#' . h($r['record_id']))
						. '</div>' : '';
					return h(self::logObjectLabel((string)$r['object'])) . $rec;
				}],
			'details' => ['label' => t('log_details'), 'select' => ['details' => 'details'], 'sort' => null, 'class' => 'small nuLogDetails',
				'text' => static fn($r) => $plain(self::logDetails($r['details'])),
				// long details fold to three lines with "more" (assets/js/nulistview.js)
				'html' => static fn($r) => '<div class="nuLvClamp" data-more="' . h(t('lv_more')) . '" data-less="' . h(t('lv_less')) . '">' . self::logDetails($r['details']) . '</div>'],
			'ip' => ['label' => 'IP', 'select' => ['ip' => 'ip'], 'sort' => 'ip', 'class' => 'small text-body-secondary', 'text' => static fn($r) => NuDemo::ip($r['ip'])],
			'id' => self::idCol('zzzzsys_nunext_log_id'),
		];
		$filters = [
			'event' => ['label' => t('log_event'), 'type' => 'select', 'tom' => true,
				'options' => static function (): array { $o = []; foreach (nu_db()->all('SELECT DISTINCT event FROM zzzzsys_nunext_log ORDER BY event') as $r) $o[(string)$r['event']] = self::eventLabel((string)$r['event']); asort($o); return $o; },
				'where' => static fn(string $v) => ['event = ?', [$v]]],
			// user as a searchable select of the logins in the log (was a text field)
			'login' => ['label' => t('user'), 'type' => 'select', 'tom' => true,
				'options' => static function (): array { $o = []; foreach (nu_db()->all("SELECT DISTINCT login FROM zzzzsys_nunext_log WHERE login IS NOT NULL AND login <> '' ORDER BY login") as $r) $o[(string)$r['login']] = (string)$r['login']; return $o; },
				'where' => static fn(string $v) => ['login = ?', [$v]]],
			// object = the table of the change: users, access levels, forms … with readable names
			'object' => ['label' => t('log_object'), 'type' => 'select', 'tom' => true,
				'options' => static function (): array { $o = []; foreach (nu_db()->all("SELECT DISTINCT object FROM zzzzsys_nunext_log WHERE object IS NOT NULL AND object <> ''") as $r) $o[(string)$r['object']] = self::logObjectLabel((string)$r['object']); asort($o); return $o; },
				'where' => static fn(string $v) => ['object = ?', [$v]]],
			'from' => ['label' => t('log_from'), 'type' => 'datefrom', 'expr' => 'log_time'],
			'to' => ['label' => t('log_to'), 'type' => 'dateto', 'expr' => 'log_time'],
			'when' => ['label' => t('lv_period'), 'type' => 'date', 'expr' => 'log_time', 'presets' => ['d7', 'd30', 'd90']],
		];
		return [
			'key' => 'log', 'title' => t('nav_log'), 'route' => 'log', 'form' => NuAcl::FORM_LOG, 'from' => ' FROM zzzzsys_nunext_log', 'where' => [], 'params' => [],
			'id' => 'zzzzsys_nunext_log_id', 'link' => static fn($r) => '',
			'search' => ['object', 'record_id', 'details', 'ip'], 'search_ph' => t('log_search_ph'), 'columns' => $cols, 'filters' => $filters,
			'builtin' => [
				'default' => ['columns' => ['time', 'user', 'event', 'object', 'details', 'ip'], 'card' => ['title' => 'event', 'badge' => '', 'lines' => ['time', 'user', 'object', 'details']],
					'sort' => ['time', 'desc'], 'per' => 50, 'filters' => ['shown' => ['event', 'login', 'object', 'from', 'to'], 'values' => []], 'open' => true],   // filters shown
				'compact' => ['columns' => ['time', 'user', 'event'], 'card' => ['title' => 'event', 'badge' => '', 'lines' => ['time', 'user']],
					'sort' => ['time', 'desc'], 'per' => 50, 'filters' => ['shown' => ['event', 'when'], 'values' => []]],
				'all' => ['columns' => array_keys($cols), 'card' => ['title' => 'event', 'badge' => '', 'lines' => ['time', 'user', 'object', 'details']],
					'sort' => ['time', 'desc'], 'per' => 50, 'filters' => ['shown' => array_keys($filters), 'values' => []], 'open' => true],
			],
		];
	}
}
