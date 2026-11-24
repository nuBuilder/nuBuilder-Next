<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/** The work of pages/nubuilder_page.php (code review R7): its POST actions – the page reads the record, renders and shows the result. */
final class NuBuilderPagePage
{
	/** The POST actions of pages/nubuilder_page.php – $nuIn: the page's variables before them; returned: what the page shows afterwards. */
	public static function post(array $nuIn): array
	{
		$meta = $nuIn['meta'] ?? null; $canSave = $nuIn['canSave'] ?? null; $formId = $nuIn['formId'] ?? null; $canDeleteForm = $nuIn['canDeleteForm'] ?? null; $errors = $nuIn['errors'] ?? null;
		if (nu_is_post()) {
			$action = nu_post('action');
			if ($action === 'prompt') {                       // "Copy AI prompt": the text as JSON
				header('Content-Type: application/json; charset=utf-8');
				echo json_encode(['ok' => true, 'text' => NuPage::aiPrompt($meta)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
				exit;
			}
			if (!$canSave) NuAcl::require(NuAcl::FORM_FORM, 'save');
			if ($action === 'template') {
				$r = NuPage::createFromTemplate($meta->code());
				nu_flash($r['ok'] ? 'success' : 'warning', $r['message']);
				nu_redirect(nu_url('builder', ['f' => $formId]));
			}
			// draft and versions of the page files (NuPageVersion)
			$verAct = ['draft_create' => 'createDraft', 'draft_publish' => 'publish', 'draft_discard' => 'discard', 'draft_data_reset' => 'resetData'];
			if (isset($verAct[$action]) || $action === 'version_restore') {
				$r = $action === 'version_restore' ? NuPageVersion::restore($meta->code(), nu_post('version')) : NuPageVersion::{$verAct[$action]}($meta->code());
				nu_flash($r['ok'] ? 'success' : 'warning', $r['message']);
				nu_redirect(nu_url('builder', ['f' => $formId]) . '#pgDraft');
			}
			if ($action === 'delete') {
				if (!$canDeleteForm) { http_response_code(403); nu_fatal(t('builder_delete_globeadmin'), '', true); }
				NuPage::deleteForm($meta);
				nu_flash('success', t('page_deleted', $meta->code()));
				nu_redirect(nu_url('forms'));
			}
			if ($action === 'save') {
				$db = nu_db();
				$desc = nu_post('description');
				$code = nu_post('code');
				$status = in_array(nu_post('status'), ['0', '1', '3'], true) ? nu_post('status') : '1';
				$group = nu_post('group');
				if ($desc === '') $errors['description'] = t('err_required');
				nu_maxlen($errors, 'description', $desc, 300);
				if (!NuPage::validCode($code)) $errors['code'] = t('page_err_code', $code);
				elseif ($code !== $meta->code()
					&& (int)$db->value('SELECT COUNT(*) FROM zzzzsys_form WHERE sfo_code = ? AND zzzzsys_form_id <> ?', [$code, $formId])) $errors['code'] = t('builder_err_code_taken');
				nu_maxlen($errors, 'group', $group, 100);
				$pageIn = ['open_mode' => nu_post('open_mode'), 'iframe_height' => nu_post('iframe_height'), 'settings' => (string)($_POST['settings'] ?? ''),
					'csp' => nu_post('csp'), 'note' => (string)($_POST['note'] ?? '')];
				if (!$errors) $errors = NuPage::save($formId, $pageIn);
				if (!$errors) {
					$row = ['sfo_description' => $desc, 'sfo_code' => $code, 'sfo_status' => $status, 'sfo_group' => $group !== '' ? $group : null,
						'sfo_breadcrumb_title' => '|' . $desc];
					$old = array_intersect_key($meta->form, $row);
					$db->update('zzzzsys_form', $row, 'zzzzsys_form_id', $formId);
					NuFormMeta::forget($formId);
					NuLog::write('form_saved', $code, $formId, NuLog::diff($old, $row));
					nu_flash('success', t('saved'), true);
					if ($code !== $meta->code()) nu_flash('warning', t('page_code_renamed', NuPage::shownFolder($meta->code()), NuPage::shownFolder($code)));
					nu_redirect(nu_url('builder', ['f' => $formId]));
				}
			}
		}
		return compact('meta', 'canSave', 'formId', 'canDeleteForm', 'errors');
	}
}
