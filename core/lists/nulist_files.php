<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/** The system list "files" – part of NuListDefs (one file per list, code review L3; the format is described there). */
trait NuListFiles
{
	private static function files(): array
	{
		NuFile::ensureTable();
		$ga = !empty(NuAuth::user()['globeadmin']);
		$denied = (string)(NuMailer::setupRow()['set_denied'] ?? '') === '1';
		$isSystem = static fn(array $f) => (string)($f['sfi_system'] ?? '') === '1' || str_starts_with((string)$f['__id'], 'nu');
		$info = static fn(array $r) => NuFile::info((string)($r['sfi_json'] ?? ''));
		$hasImg = isset(nu_db()->columns('zzzzsys_object')['sob_image_zzzzsys_file_id']);
		$nUsed = $hasImg ? '(SELECT COUNT(*) FROM zzzzsys_object o WHERE o.sob_image_zzzzsys_file_id = ' . NuFile::TABLE . '.zzzzsys_file_id)' : '0';
		$cols = [
			'preview' => ['label' => t('lv_preview'), 'select' => ['sfi_json' => 'sfi_json'], 'sort' => null, 'class' => 'nuLvThumbCell', 'text' => static fn($r) => '', 'noexport' => true,
				'html' => static fn($r) => ($info($r)['image'] ?? false) ? '<img class="nuLvThumb" src="' . h(nu_url('files', ['raw' => $r['__id']])) . '" alt="" loading="lazy">' : '<i class="bi bi-file-earmark text-body-secondary fs-4"></i>'],
			'code' => self::col('sfi_code', 'sfi_code', t('file_code')) + ['main' => true, 'locked' => true],
			'description' => self::col('sfi_description', 'sfi_description', t('file_description'), 'small text-break'),
			'group' => array_replace(self::badgeCol('sfi_group', 'sfi_group', t('file_group')), ['sort' => 'sfi_group, sfi_code']),
			'name' => ['label' => t('file_name'), 'select' => ['sfi_json' => 'sfi_json'], 'sort' => null, 'class' => 'small text-break', 'text' => static fn($r) => (string)($info($r)['name'] ?? '')],
			'type' => ['label' => t('file_type'), 'select' => ['sfi_json' => 'sfi_json'], 'sort' => null, 'class' => 'small', 'text' => static fn($r) => (string)($info($r)['type'] ?? '')],
			'size' => ['label' => t('file_size'), 'select' => ['sfi_json' => 'sfi_json'], 'sort' => null, 'align' => 'end', 'class' => 'small text-nowrap',
				'text' => static fn($r) => ($i = $info($r)) ? NuFile::size((int)$i['size']) : ''],
			// how many objects show the file (NuFile::usage); the number opens the file's "Used by"
			'used' => ['label' => t('file_used_by'), 'select' => ['lv_used' => $nUsed], 'sort' => $hasImg ? $nUsed : null, 'align' => 'end', 'class' => 'small',
				'text' => static fn($r) => (string)(int)$r['lv_used'],
				'html' => static fn($r) => (int)$r['lv_used'] > 0 ? '<a class="text-decoration-none" href="' . h(nu_url('files', ['id' => $r['__id']])) . '#fileUsedBy">' . (int)$r['lv_used'] . '</a>' : '0'],
			'system' => ['label' => t('file_system'), 'select' => ['sfi_system' => 'sfi_system'], 'sort' => null, 'align' => 'center',
				'text' => static fn($r) => $isSystem($r) ? t('yes') : '', 'html' => static fn($r) => $isSystem($r) ? self::badge(t('file_system'), 'bg-info-subtle text-info-emphasis') : ''],
			'id' => self::idCol('zzzzsys_file_id'),
		];
		return [
			'key' => 'files', 'title' => t('file_title'), 'route' => 'files', 'form' => NuAcl::FORM_FILE, 'from' => ' FROM ' . NuFile::TABLE,
			'where' => $denied && !$ga ? ["zzzzsys_file_id NOT LIKE 'nu%'"] : [], 'params' => [],
			'id' => 'zzzzsys_file_id', 'link' => static fn($r) => nu_url('files', ['id' => $r['__id']]), 'always' => ['preview', 'code', 'description', 'group', 'size', 'system', 'used'],
			'search' => ['sfi_code', 'sfi_description', 'sfi_group'], 'columns' => $cols,
			'filters' => ['group' => ['label' => t('file_group'), 'type' => 'distinct', 'expr' => 'sfi_group', 'none' => t('lv_empty')],
				// kind of file by its stored MIME type ("type" in sfi_json, also with an escaped slash)
				'type' => ['label' => t('file_type'), 'type' => 'select', 'options' => static fn() => ['image' => t('file_type_image'), 'pdf' => 'PDF', 'other' => t('file_type_other')],
					'where' => static fn(string $v) => match ($v) {
						'image' => ["sfi_json LIKE '%\"type\":\"image%'", []],
						'pdf' => ["sfi_json LIKE '%\"type\":\"application%pdf\"%'", []],
						'other' => ["sfi_json NOT LIKE '%\"type\":\"image%' AND sfi_json NOT LIKE '%\"type\":\"application%pdf\"%'", []],
						default => null,
					}],
				'used' => ['label' => t('file_flt_used'), 'type' => 'bool', 'expr' => $hasImg ? "$nUsed > 0" : '1 = 0']],
			'grid' => static function (array $r, string $href) use ($info, $isSystem): string {
				$i = $info($r);
				return '<div class="col-6 col-md-4 col-lg-3 col-xxl-2"><a class="card h-100 shadow-sm text-decoration-none nuFileCard" href="' . h($href) . '"><div class="nuFileThumbBox">'
					. (($i['image'] ?? false) ? '<img src="' . h(nu_url('files', ['raw' => $r['__id']])) . '" alt="" loading="lazy">' : '<i class="bi bi-file-earmark'
						. ($i ? '' : '-x') . ' display-5 text-body-secondary"></i>')
					. '</div><div class="card-body p-2"><div class="fw-semibold text-truncate" title="' . h($r['sfi_code']) . '">' . h($r['sfi_code']) . '</div>'
					. '<div class="small text-body-secondary text-truncate" title="' . h($r['sfi_description']) . '">' . h($r['sfi_description'] ?: ($i['name'] ?? ''))
						. '</div><div class="d-flex flex-wrap gap-1 mt-1">'
					. ((string)$r['sfi_group'] !== '' ? self::badge((string)$r['sfi_group']) : '') . ($isSystem($r) ? self::badge(t('file_system'), 'bg-info-subtle text-info-emphasis') : '')
					. ((int)($r['lv_used'] ?? 0) > 0 ? '<span class="small text-body-secondary" title="' . h(t('file_used_by')) . '"><i class="bi bi-link-45deg"></i>'
						. (int)$r['lv_used'] . '</span>' : '')   // used by N objects
					. ($i ? '<span class="small text-body-secondary ms-auto">' . h(NuFile::size((int)$i['size'])) . '</span>' : '') . '</div></div></a></div>';
			},
			'builtin' => [
				'default' => ['display' => 'grid', 'columns' => ['preview', 'code', 'description', 'group', 'size'], 'card' => ['title' => 'code', 'badge' => 'group', 'lines' => ['description', 'size']],
					'sort' => ['group', 'asc'], 'per' => 48, 'filters' => ['shown' => ['group', 'type', 'used'], 'values' => []]],
				'compact' => ['columns' => ['code', 'description', 'group'], 'card' => ['title' => 'code', 'badge' => 'group', 'lines' => ['description']],
					'sort' => ['code', 'asc'], 'per' => 50, 'filters' => ['shown' => ['group'], 'values' => []]],
				'all' => ['columns' => array_keys($cols), 'card' => ['title' => 'code', 'badge' => 'group', 'lines' => ['description', 'name', 'type', 'size']],
					'sort' => ['group', 'asc'], 'per' => 50, 'filters' => ['shown' => ['group'], 'values' => []]],
			],
		];
	}
}
