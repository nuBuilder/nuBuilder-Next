<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * Report designer (?p=reportdesigner&id=<report id>) – edits sre_layout in nuBuilder's format (positions in px, 4 px = 1 mm).
 *   POST action=save, layout                stores the layout (JSON answer)
 *   POST action=fields                      columns of the data source + launch form fields (JSON answer)
 *   POST form action=preview, layout        the PDF with this (unsaved) layout, criteria empty
 */

NuAcl::require(NuAcl::FORM_REPORT);
$db     = nu_db();
$rights = NuAcl::rights(NuAcl::FORM_REPORT);
$id     = (string)($_GET['id'] ?? '');
$report = NuReport::load($id);
if (!$report || (string)$report['zzzzsys_report_id'] !== $id) { http_response_code(404); nu_fatal(t('record_not_found'), '', true); }
$canSave = $rights['save'];

// ------------------------------------------------------------------ preview (form post into the preview frame)
if (nu_is_post() && nu_post('action') === 'preview') {
	try {
		$layout = NuReport::layoutJson((string)($_POST['layout'] ?? ''));
		$hash = NuHash::plain();
		$launch = trim((string)$report['sre_zzzzsys_form_id']);
		if ($launch !== '') foreach ($db->all('SELECT sob_all_id FROM zzzzsys_object WHERE sob_all_zzzzsys_form_id = ?', [$launch]) as $o) $hash[(string)$o['sob_all_id']] ??= '';
		$crit = json_decode((string)($_POST['criteria'] ?? '{}'), true);
		if (is_array($crit)) foreach ($crit as $k => $v) if (is_string($k) && preg_match('/^\w{1,100}$/', $k) && is_scalar($v)) $hash[$k] = (string)$v;
		$pdf = NuReport::pdf($report, $hash, $layout);
	} catch (Throwable $e) {
		error_log('nuBuilder Next report preview ' . $report['sre_code'] . ': ' . $e->getMessage());
		$u = NuAuth::user();                                 // details (SQL, file paths) only for the globeadmin or with $nuConfigDebug
		NuReport::fail(t('rpt_err_run', (string)$report['sre_code']) . (($u['globeadmin'] && empty($u['demo'])) || nu_cfg('nuConfigDebug') ? ' ' . $e->getMessage() : ''));
	}
	NuReport::send($pdf, (string)$report['sre_code']);
}

// ------------------------------------------------------------------ background actions (form data: action, layout)
if (nu_is_post() && in_array(nu_post('action'), ['save', 'fields'], true)) {
	$json = ['action' => nu_post('action'), 'layout' => (string)($_POST['layout'] ?? '')];
	header('Content-Type: application/json; charset=utf-8');
	$out = static function (array $d, int $status = 200): never { http_response_code($status); echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE); exit; };
	try {
		switch ((string)($json['action'] ?? '')) {
			case 'save':
				if (!$canSave) $out(['ok' => false, 'message' => t('err_forbidden')], 403);
				$layout = NuReport::keepTypes(NuReport::layoutJson($json['layout'] ?? null), (string)$report['sre_layout']);
				if ($layout !== (string)$report['sre_layout']) {
					$db->update(NuReport::TABLE, ['sre_layout' => $layout], 'zzzzsys_report_id', $id);
					NuLog::write('report_changed', NuReport::TABLE, $id, ['code' => (string)$report['sre_code'], 'fields' => ['sre_layout' => ['…', '…']]]);
				}
				$out(['ok' => true, 'layout' => json_decode($layout)]);
			case 'fields':
				$out(['ok' => true] + NuReport::fields((string)$report['sre_zzzzsys_php_id'], trim((string)$report['sre_zzzzsys_form_id'])));
		}
		$out(['ok' => false, 'message' => 'Unknown action'], 400);
	} catch (Throwable $e) {
		error_log('nuBuilder Next report designer ' . $report['sre_code'] . ': ' . $e->getMessage());
		$u = NuAuth::user();
		$out(['ok' => false, 'message' => ($u['globeadmin'] && empty($u['demo'])) || nu_cfg('nuConfigDebug') ? $e->getMessage() : t('err_unexpected')], 200);
	}
}

// ------------------------------------------------------------------ page
$images = array_map(static fn($i) => ['code' => $i[0], 'description' => $i[1], 'url' => NuAcl::can(NuAcl::FORM_FILE) ? nu_url('files', ['raw' => $i[2]]) : ''], NuReport::images());
$keys = ['save', 'saved', 'unsaved', 'undo', 'redo', 'zoom', 'grid', 'snap', 'preview', 'back', 'report', 'fields', 'insert', 'search', 'no_fields', 'fields_error',
	'add_field', 'add_label', 'add_box', 'add_line', 'add_image', 'align_left', 'align_center', 'align_right', 'align_top', 'align_middle', 'align_bottom',
	'same_width', 'same_height', 'dist_h', 'dist_v', 'delete', 'duplicate', 'bring_front', 'send_back', 'props', 'nothing', 'selected_n',
	'type', 'type_field', 'type_label', 'type_image', 'content_field', 'content_label', 'content_image', 'summary', 'sum_none', 'sum_sum', 'sum_avg',
	'format', 'font', 'size', 'bold', 'italic', 'align', 'colors', 'color_text', 'color_back', 'color_border', 'border', 'position', 'left', 'top', 'width', 'height',
	'rows_min', 'rows_max', 'rows_hint', 'zindex', 'band', 'band_height', 'band_break', 'band_color', 'page', 'paper', 'orientation', 'portrait', 'landscape',
	'html', 'html_hint', 'groups', 'groups_hint', 'group_field', 'group_dir', 'asc', 'desc', 'group_add', 'group_remove', 'up', 'down', 'header', 'footer',
	'b_report_header', 'b_report_footer', 'b_page_header', 'b_page_footer', 'b_detail', 'b_group_header', 'b_group_footer', 'criteria', 'system', 'sys_page',
	'sys_pages', 'sys_page_of', 'sys_code', 'sys_description', 'sys_date', 'sys_datetime', 'sys_user', 'box_hint', 'image_none', 'images', 'leave',
	'preview_title', 'open_tab', 'help', 'help_text', 'saving', 'save_failed', 'readonly', 'mm', 'pt', 'drop_hint', 'copy', 'paste', 'select_all', 'id', 'summary_hint'];
$msg = [];
foreach ($keys as $k) $msg[$k] = t('rd_' . $k);
$data = [
	'id' => $id, 'code' => (string)$report['sre_code'], 'description' => (string)$report['sre_description'],
	'source' => NuReport::sourceLabel((string)$report['sre_zzzzsys_php_id']), 'canSave' => $canSave,
	'layout' => NuReport::layout((string)$report['sre_layout']), 'fonts' => NuReport::fonts(), 'formats' => NuReport::formats(), 'images' => $images,
	'papers' => NuReport::PAPER, 'url' => nu_url('reportdesigner', ['id' => $id]), 'back' => nu_url('reports', ['id' => $id]), 'csrf' => nu_csrf_token(),
	'tcpdf' => NuReport::tcpdfDir() !== null, 'msg' => $msg,
];
header("Content-Security-Policy: default-src 'self'; img-src 'self' data: blob:; style-src 'self'; script-src 'self'; font-src 'self'; connect-src 'self'; frame-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
$head = '<link rel="stylesheet" href="' . nu_asset('assets/css/nureportdesigner.css') . '">';
$foot = '<script type="application/json" id="rdData">' . json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) . '</script>'
	. '<script src="' . nu_asset('assets/js/nureportdesigner.js') . '"></script>';
nu_layout_start(t('rd_title') . ' · ' . $report['sre_code'], 'forms', ['head' => $head, 'container' => 'container-fluid rdContainer', 'body_class' => 'rdBody']);
?>
<?php if (!NuReport::tcpdfDir()): ?><div class="alert alert-warning small py-2 mb-2"><i class="bi bi-exclamation-triangle me-1"></i><?= h(t('rpt_tcpdf_missing')) ?></div><?php endif; ?>
<div id="rdApp" class="rdApp" aria-busy="true">
	<div class="rdLoading text-body-secondary small p-3"><span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span><?= h(t('loading')) ?></div>
</div>
<form method="post" action="<?= h(nu_url('reportdesigner', ['id' => $id])) ?>" target="rdPreviewFrame" id="rdPreviewForm" class="d-none">
	<?= nu_csrf_field() ?><input type="hidden" name="action" value="preview"><input type="hidden" name="layout" value=""><input type="hidden" name="criteria" value="{}">
</form>
<div class="modal fade" id="rdPreviewModal" tabindex="-1" aria-hidden="true" aria-labelledby="rdPreviewTitle">
	<div class="modal-dialog modal-xl modal-dialog-scrollable">
		<div class="modal-content">
			<div class="modal-header py-2"><h2 class="modal-title fs-6 me-auto" id="rdPreviewTitle"><i class="bi bi-file-earmark-pdf me-1"></i><?= h(t('rd_preview_title')) ?></h2>
				<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= h(t('close')) ?>"></button></div>
			<div class="modal-body p-0"><iframe name="rdPreviewFrame" class="rdPreviewFrame" title="<?= h(t('rd_preview_title')) ?>"></iframe></div>
		</div>
	</div>
</div>
<?php
nu_layout_end($foot);
