<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/** The work of pages/nutranslations.php (code review R7): its POST actions – the page reads the record, renders and shows the result. */
final class NuTranslationsPage
{
	/** The POST actions of pages/nutranslations.php (part 1) – $nuIn: the page's variables before them; returned: what the page shows afterwards. */
	public static function post1(array $nuIn): array
	{
		$isNu = $nuIn['isNu'] ?? null; $row = $nuIn['row'] ?? null; $errors = $nuIn['errors'] ?? null; $id = $nuIn['id'] ?? null; $T = $nuIn['T'] ?? null; $db = $nuIn['db'] ?? null;
		if (nu_is_post()) {
			$action = nu_post('action');
			if ($action === 'save' && !$isNu) {
				NuAcl::require(NuAcl::FORM_TRANSLATE, $row ? 'save' : 'create');
				$in = ['trl_language' => nu_post('trl_language'), 'trl_english' => (string)($_POST['trl_english'] ?? ''), 'trl_translation' => (string)($_POST['trl_translation'] ?? '')];
				$in = array_map(static fn($v) => str_replace("\r\n", "\n", (string)$v), $in);
				[$newId, $errors] = NuTranslate::save($in, $row ? $id : '');
				if (!$errors) {
					NuLog::write($row ? 'translation_changed' : 'translation_created', $T, (string)$newId, ['language' => $in['trl_language'], 'english' => mb_substr($in['trl_english'], 0, 100)]);
					nu_flash('success', t('saved'));
					$back = nu_post('back');
					$bf = nu_post('back_f');
					nu_redirect($back === 'missing' ? nu_url('translations', ['view' => 'missing', 'lang' => $bf !== '' ? null : $in['trl_language'], 'f' => $bf ?: null]) : nu_url('translations', ['id' => $newId]));
				}
			}
			if ($action === 'delete' && $row && !$isNu) {
				NuAcl::require(NuAcl::FORM_TRANSLATE, 'delete');
				$db->delete($T, 'zzzzsys_translate_id', $id);
				NuLog::write('translation_deleted', $T, $id, ['language' => (string)$row['trl_language'], 'english' => mb_substr((string)$row['trl_english'], 0, 100)]);
				nu_flash('success', t('trl_deleted'));
				nu_redirect(nu_url('translations', ['lang' => (string)$row['trl_language']]));
			}
		}
		return compact('isNu', 'row', 'errors', 'id', 'T', 'db');
	}

	/** The POST actions of pages/nutranslations.php (part 2) – $nuIn: the page's variables before them; returned: what the page shows afterwards. */
	public static function post2(array $nuIn): array
	{
		$id = $nuIn['id'] ?? null;
		$aiForm = $nuIn['aiForm'] ?? null;
		$rights = $nuIn['rights'] ?? null;
		$aiPick = $nuIn['aiPick'] ?? null;
		$targets = $nuIn['targets'] ?? null;
		$T = $nuIn['T'] ?? null;
		$aiPreview = $nuIn['aiPreview'] ?? null;
		$n = $nuIn['n'] ?? null;
		if (nu_is_post() && $id === '' && $aiForm !== '' && in_array(nu_post('action'), ['ai_prompt', 'ai_check', 'ai_save'], true)) {
			if (!$rights['create'] && !$rights['save']) NuAcl::require(NuAcl::FORM_TRANSLATE, 'create');
			$action = nu_post('action');
			if ($action === 'ai_prompt') {                                // JSON: the prompt for the chosen languages (nothing stored)
				header('Content-Type: application/json; charset=utf-8');
				header('Cache-Control: no-store');
				$pl = $aiPick();
				if (!$pl) exit(json_encode(['ok' => false, 'message' => t('trl_ai_pick_lang')]));
				$r = NuTranslate::aiPrompt($aiForm, $pl, nu_check('ai_only') === '1');
				exit(json_encode(['ok' => $r['count'] > 0, 'prompt' => $r['prompt'], 'count' => $r['count'], 'message' => $r['count'] ? t('trl_ai_copied', $r['count']) : t('trl_ai_nothing')],
					JSON_UNESCAPED_UNICODE));
			}
			$answer = (string)($_POST['ai_answer'] ?? '');
			$aiPreview = NuTranslate::aiCheck($answer, $aiForm, $targets);
			if ($action === 'ai_save' && $aiPreview['error'] === '') {
				$n = NuTranslate::aiSave($aiPreview['rows'], (array)($_POST['pick'] ?? []), (bool)$rights['create'], (bool)$rights['save']);
				NuLog::write('translations_ai', $T, $aiForm, $n);
				nu_flash('success', t('trl_ai_saved', $n['created'], $n['changed'], $n['skipped']));
				nu_redirect(nu_url('translations', ['view' => 'missing', 'f' => $aiForm]));
			}
		}
		return compact('id', 'aiForm', 'rights', 'aiPreview', 'targets', 'n', 'T');
	}
}
