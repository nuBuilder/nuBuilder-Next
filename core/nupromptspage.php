<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/** The work of pages/nuprompts.php (code review R7): its POST actions – the page reads the record, renders and shows the result. */
final class NuPromptsPage
{
	/** The POST actions of pages/nuprompts.php – $nuIn: the page's variables before them; returned: what the page shows afterwards. */
	public static function post(array $nuIn): array
	{
		$T = $nuIn['T'] ?? null;
		$rec = $nuIn['rec'] ?? null;
		$id = $nuIn['id'] ?? null;
		$src = $nuIn['src'] ?? null;
		$errors = $nuIn['errors'] ?? null;
		$db = $nuIn['db'] ?? null;
		$cloneId = $nuIn['cloneId'] ?? null;
		if (nu_is_post()) {
			$action = nu_post('action');
			// live preview of the complete prompt (editor, while typing)
			if ($action === 'build') {
				$row = NuPrompt::fromInput($_POST);
				$prompt = NuPrompt::build($row);
				header('Content-Type: application/json; charset=utf-8');
				exit(json_encode(['prompt' => $prompt, 'chars' => mb_strlen($prompt)], JSON_UNESCAPED_UNICODE));
			}
			// AI context file (as Form with AI): functions, hash variables, guide and the chosen tables – attached to the chat with the prompt
			if ($action === 'context') {
				$tables = array_values(array_filter(array_map('strval', (array)($_POST['tables'] ?? [])), static fn($x) => $x !== ''));
				$full = nu_post('full') === '1';
				$secs = NuAiContext::codeSections($tables, $full);
				$zip = nu_post('fmt') === 'zip' ? NuAiContext::zip($secs, 'code') : null;
				if ($zip !== null) { header('Content-Type: application/zip'); $name = NuAiContext::fileName('code', 'zip'); $body = $zip; }
				else { header('Content-Type: text/markdown; charset=utf-8'); $name = NuAiContext::fileName('code', 'md'); $body = NuAiContext::markdown($secs, 'code'); }
				header('Content-Disposition: attachment; filename="' . $name . '"');
				header('X-Nu-File-Name: ' . $name);
				header('Cache-Control: no-store');
				NuLog::write('form_ai_context', $T, $rec ? $id : '', ['kind' => 'code', 'fmt' => $zip !== null ? 'zip' : 'md', 'full' => $full]);
				echo $body;
				exit;
			}
			// an AI answer in Markdown → HTML for the answer box (escaped first, nothing runs; nothing stored)
			if ($action === 'md') nu_json(['ok' => true, 'html' => NuHtmlClean::doc(NuMarkdown::toWeb(mb_substr((string)($_POST['text'] ?? ''), 0, 400000)))]);
			if ($action === 'save') {
				NuAcl::require(NuAcl::FORM_PROMPT, $rec ? 'save' : ($src ? 'clone' : 'create'));
				$row = NuPrompt::fromInput($_POST);
				[$newId, $errors] = NuPrompt::save($row, $rec ? $id : '');
				if (!$errors) {
					$after = $db->one("SELECT * FROM $T WHERE zzzzsys_prompt_generator_id = ?", [$newId]) ?? [];
					NuLog::write($rec ? 'prompt_changed' : 'prompt_created', $T, (string)$newId,
						$rec ? ['fields' => NuLog::diff($rec, $after)] : ['code' => $row['pge_code']] + ($src ? ['clone_of' => $cloneId] : []));
					nu_flash('success', t('saved'));
					nu_redirect(nu_url('prompts', ['id' => $newId]));
				}
			}
			if ($action === 'delete' && $rec) {
				NuAcl::require(NuAcl::FORM_PROMPT, 'delete');
				$db->delete($T, 'zzzzsys_prompt_generator_id', $id);
				NuLog::write('prompt_deleted', $T, $id, ['code' => (string)$rec['pge_code']]);
				nu_flash('success', t('pg_deleted'));
				nu_redirect(nu_url('prompts'));
			}
		}
		return compact('rec', 'id', 'src', 'errors', 'cloneId');
	}
}
