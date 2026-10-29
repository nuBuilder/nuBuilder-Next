<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/* Formats (zzzzsys_format): list, create, edit, delete – rights of nuBuilder's form "nuformat". */

NuAcl::require(NuAcl::FORM_FORMAT);
$type = (string)($_GET['type'] ?? '');
$q    = trim((string)($_GET['q'] ?? ''));

$rows = [];
foreach (NuFormat::choices() as $c) {
	if ($type !== '' && $c['type'] !== $type) continue;
	if ($q !== '' && mb_stripos($c['value'] . ' ' . $c['description'], $q) === false) continue;
	$rows[] = $c;
}
$reg = NuFormat::registry();
$usage = $usedBy = [];
foreach ($rows as $c) if ($c['saved']) { $usedBy[$c['value']] = NuFormat::usage($c['value']); $usage[$c['value']] = count($usedBy[$c['value']]); }
$canDesign = NuAcl::can(NuAcl::FORM_FORM);
/** The Used count: a menu of the forms / objects / list columns that use the format, each opening the form (design mode for designers, 0.8.688). */
$usedCell = static function (string $key) use ($usage, $usedBy, $canDesign): string {
	$n = (int)($usage[$key] ?? 0);
	if ($n === 0) return '0';
	$items = '';
	foreach ($usedBy[$key] as $u) {
		$label = trim((nu_forms()[$u['form']]['code'] ?? $u['form']) . ' · ' . $u['object']);
		$href = $canDesign ? nu_url('form', ['f' => $u['form'], 'r' => '-1', 'design' => '1']) : nu_url('form', ['f' => $u['form']]);
		$items .= '<li><a class="dropdown-item small" href="' . h($href) . '">' . h($label) . '</a></li>';
	}
	return '<div class="dropdown nuFmtUsed d-inline-block"><button type="button" class="btn btn-link btn-sm p-0 text-decoration-none" data-bs-toggle="dropdown" data-bs-popper-config="{&quot;strategy&quot;:&quot;fixed&quot;}" aria-expanded="false" title="' . h(t('fmt_used_show')) . '">' . $n . '</button>'
		. '<ul class="dropdown-menu dropdown-menu-end">' . $items . '</ul></div>';
};

/** Sample output for the list. */
function nu_format_examples(string $key): array
{
	return match ($key[0]) {
		'D' => [NuFormat::format(date('Y-m-d H:i:s'), $key)],
		'N' => [NuFormat::format('1234567.891', $key), NuFormat::format('-1234.5', $key)],
		default => [],
	};
}

NuListExport::handle(NuAcl::FORM_FORMAT, t('fmt_title'), [
	t('fmt_type')            => static fn($c) => t('fmt_type_' . strtolower($c['type'])),
	t('fmt_format')          => static fn($c) => substr($c['value'], 2) . ($c['default'] ? ' (' . t('fmt_default_badge') . ')' : '') . ($c['saved'] ? '' : ' (' . t('fmt_builtin') . ')'),
	t('fmt_example')         => static fn($c) => implode(' / ', nu_format_examples($c['value'])),
	t('fmt_description_col') => static fn($c) => $c['description'],
	t('fmt_used')            => static fn($c) => $c['saved'] ? (int)$usage[$c['value']] : '',
], static fn(int $limit) => array_slice($rows, 0, $limit), NuListExport::filterText([t('search') => $q, t('fmt_type') => $type]));

$designer = nu_format_designer_data();
$foot = '<script type="application/json" id="nuFormatData">' . json_encode($designer, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) . '</script>'
	. '<script src="' . nu_asset('assets/js/nu/nuformat.js') . '"></script>'
	. '<script src="' . nu_asset('assets/js/nuformatdesigner.js') . '"></script>'
	. '<script src="' . nu_asset('assets/js/nuformats.js') . '"></script>';

nu_layout_start(t('fmt_title'), 'forms');
?>
<nav aria-label="breadcrumb">
	<ol class="breadcrumb small mb-2">
		<li class="breadcrumb-item"><a href="<?= h(nu_url('forms')) ?>"><?= h(t('nav_forms')) ?></a></li>
		<li class="breadcrumb-item active" aria-current="page"><?= h(t('fmt_title')) ?></li>
	</ol>
</nav>
<div class="nuTitleBar mb-3">
	<h1 class="h3 mb-0"><?= h(t('fmt_title')) ?></h1>
	<div class="nuTitleSub text-body-secondary small"><?= h(t('fmt_subtitle')) ?></div>
	<div class="d-flex flex-wrap gap-2"><?= nu_back_button() ?>
		<?php if ($designer['rights']['add']): ?>
			<button type="button" class="btn nuAddBtn" title="<?= h(t('fmt_new')) ?>" id="fmtNew"><i class="bi bi-plus-lg me-1"></i><?= h(t('add')) ?></button>
		<?php endif; ?>
		<?= NuListExport::buttons(NuAcl::FORM_FORMAT, 'formats', ['q' => $q, 'type' => $type]) ?>
	</div>
</div>

<form class="card card-body shadow-sm mb-3 py-3" method="get" action="index.php" role="search">
	<input type="hidden" name="p" value="formats">
	<div class="row g-2 align-items-end">
		<div class="col-md-6"><?= nu_list_search($q, '', 'nuFmtFilters', $type !== '', $q !== '' || $type !== '' ? nu_url('formats') : null) ?></div>
		<div class="col-md-5 collapse<?= $type !== '' ? ' show' : '' ?>" id="nuFmtFilters">
			<label class="form-label small mb-1" for="f_type"><?= h(t('fmt_type')) ?></label>
			<select class="form-select js-autosubmit" id="f_type" name="type">
				<option value=""><?= h(t('all')) ?></option>
				<?php foreach (NuFormat::TYPES as $tp): ?><option value="<?= $tp ?>"<?= $tp === $type ? ' selected' : '' ?>><?= h(t('fmt_type_' . strtolower($tp))) ?></option><?php endforeach; ?>
			</select>
		</div>
	</div>
</form>

<div class="card shadow-sm">
	<div class="table-responsive">
		<table class="table table-hover align-middle mb-0 nu-table" id="fmtTable">
			<thead><tr>
				<th scope="col"><?= h(t('fmt_type')) ?></th>
				<th scope="col"><?= h(t('fmt_format')) ?></th>
				<th scope="col"><?= h(t('fmt_example')) ?></th>
				<th scope="col"><?= h(t('fmt_description_col')) ?></th>
				<th scope="col" class="text-end"><?= h(t('fmt_used')) ?></th>
			</tr></thead>
			<tbody>
			<?php if (!$rows): ?><tr><td colspan="5" class="text-center text-body-secondary py-5"><?= h(t('no_records')) ?></td></tr><?php endif; ?>
			<?php foreach ($rows as $c): $r = $reg[$c['value']] ?? null; ?>
				<tr<?= $c['saved'] ? ' data-format-id="' . h($c['id']) . '" tabindex="0"' : ' class="nuFmtBuiltin" data-format-key="' . h($c['value']) . '"' ?>>
					<td><span class="badge nuFmtType nuFmtType-<?= h($c['value'][0]) ?>"><?= h(t('fmt_type_' . strtolower($c['type']))) ?></span></td>
					<td>
						<code class="nuFmtKey"><?= h(substr($c['value'], 2)) ?></code>
						<?php if ($c['default']): ?><span class="badge text-bg-primary ms-1"><?= h(t('fmt_default_badge')) ?></span><?php endif; ?>
						<?php if (!$c['saved']): ?><span class="badge text-bg-light border ms-1"><?= h(t('fmt_builtin')) ?></span><?php endif; ?>
						<?php if ($r && $c['value'][0] === 'T'): ?><div class="small text-body-secondary"><?= h(t($r['store'] === 'raw' ? 'fmt_store_raw' : 'fmt_store_masked')) ?><?= $r['complete'] ? ' · ' . h(t('fmt_complete')) : '' ?></div><?php endif; ?>
					</td>
					<td class="small">
						<?php foreach (nu_format_examples($c['value']) as $i => $ex): ?>
							<div class="<?= $i === 1 && NuFormat::cssClass('-1234.5', $c['value']) ? 'nuNegative' : '' ?>"><?= h($ex) ?></div>
						<?php endforeach; ?>
						<?php if ($c['value'][0] === 'T'): ?><span class="text-body-secondary"><?= h(implode('', array_map(static fn($t) => $t[0] === 'L' ? $t[1] : '_', NuFormat::maskTokens(substr($c['value'], 2))))) ?></span><?php endif; ?>
					</td>
					<td class="small"><?= h($c['description']) ?></td>
					<td class="text-end small"><?= $c['saved'] ? $usedCell($c['value']) : '' ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	<div class="card-footer small text-body-secondary"><?= h(t('fmt_builtin_hint')) ?></div>
</div>
<?php
nu_layout_end($foot);
