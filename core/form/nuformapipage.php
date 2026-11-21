<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/** Functions of the page pages/nuformapi.php (code review R7: a page defines no global function). */
final class NuFormApiPage
{
	public static function out(array $data, int $status = 200): never
	{
		global $ps;
		if (class_exists('NuPhp', false)) {                // properties set / deleted in PHP stay with the page's scope
			if (NuPhp::$props) NuProps::lock($ps ?? '', NuPhp::$props);
			if (NuPhp::$deleted) NuProps::forget($ps ?? '', array_keys(NuPhp::$deleted));
		}
		nu_json($data, $status);
	}

	public static function error(string $msg, int $status = 400): never
	{
		NuFormApiPage::out(['ok' => false, 'errors' => [['message' => $msg, 'field' => '']]], $status);
	}

	/**
	 * Save / Delete in a draft preview: refused unless the draft allows it; then the user confirms once per session
	 * ("writes to the live data") – the browser asks on code preview_confirm and sends preview_confirm.
	 */
	public static function previewGuard(NuFormMeta $meta, array $in): void
	{
		if (!$meta->isDraft() || NuFormDraft::previewSaves($meta->id)) return;
		$d = NuFormDraft::previewDraft($meta->id);
		if (!$d || (int)$d['preview_saves'] !== 1) NuFormApiPage::error(t('draft_preview_no_save'), 403);
		if (empty($in['preview_confirm'])) NuFormApiPage::out(['ok' => false, 'code' => 'preview_confirm', 'errors' => [['message' => t('builder_draft_saves_confirm'), 'field' => '']]], 409);
		NuFormDraft::confirmPreviewSaves($meta->id);
	}

	/** The object (of this form or one of its subforms) a lookup request refers to. */
	public static function object(NuFormMeta $meta, string $objectId): array
	{
		// from the form's metadata (and its sub-forms') – live, or the draft in a preview; only objects of these forms
		$o = $meta->object($objectId);
		if (!$o) {
			foreach ($meta->objects as $so) {
				if ($so['sob_all_type'] !== 'subform') continue;
				$o = NuFormMeta::load((string)$so['sob_subform_zzzzsys_form_id'])?->object($objectId);
				if ($o) break;
			}
		}
		if (!$o) {
			$exists = (bool)nu_db()->value('SELECT COUNT(*) FROM zzzzsys_object WHERE zzzzsys_object_id = ?', [$objectId]);
			$exists ? NuFormApiPage::error(t('err_forbidden'), 403) : NuFormApiPage::error('Unknown object', 404);
		}
		return $o;
	}

	/** sob_all_id of the subform object that shows form $formId ('' for the form itself). */
	public static function subformId(NuFormMeta $meta, string $formId): string
	{
		if ($formId === $meta->id) return '';
		foreach ($meta->objects as $so) if ($so['sob_all_type'] === 'subform' && $so['sob_subform_zzzzsys_form_id'] === $formId) return (string)$so['sob_all_id'];
		return '';
	}
}
