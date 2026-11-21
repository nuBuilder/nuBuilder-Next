<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/** The work of pages/nuhelppages.php (code review R7): its POST actions – the page reads the record, renders and shows the result. */
final class NuHelppagesPage
{
	/** The POST actions of pages/nuhelppages.php – $nuIn: the page's variables before them; returned: what the page shows afterwards. */
	public static function post(array $nuIn): array
	{
		$canEdit = $nuIn['canEdit'] ?? null;
		$isTr = $nuIn['isTr'] ?? null;
		$row = $nuIn['row'] ?? null;
		$base = $nuIn['base'] ?? null;
		$errors = $nuIn['errors'] ?? null;
		$id = $nuIn['id'] ?? null;
		$fid = $nuIn['fid'] ?? null;
		$l = $nuIn['l'] ?? null;
		$f = $nuIn['f'] ?? null;
		if (nu_is_post()) {
			$action = nu_post('action');
			if ($action === 'related') {                               // JSON: sub-forms, lookups and buttons of a form for the AI prompt
				header('Content-Type: application/json; charset=utf-8');
				$kinds = ['subform' => t('uh_rel_subform'), 'lookup' => t('uh_rel_lookup'), 'button' => t('uh_rel_button')];
				echo json_encode(['ok' => true, 'related' => array_map(static fn($r) => $r + ['kind_text' => $kinds[$r['kind']]], NuUserHelp::related((string)($_POST['form'] ?? '')))]);
				exit;
			}
			if ($action === 'link_targets' || $action === 'link') {     // JSON: link / unlink a page at a form, a tab or a field ("Used on")
				header('Content-Type: application/json; charset=utf-8');
				$fid = (string)($_POST['form'] ?? '');
				if (!NuUserHelp::canLink()) { http_response_code(403); echo json_encode(['error' => t('err_forbidden')]); exit; }
				if ($action === 'link_targets') {
					$tg = NuUserHelp::targets($fid);
					echo json_encode($tg === null ? ['error' => t('form_err_not_found')] : ['ok' => true, 'places' => $tg]);
					exit;
				}
				$code = (string)($_POST['code'] ?? '');
				$r = NuUserHelp::link($fid, (string)($_POST['place'] ?? ''), ($_POST['unlink'] ?? '') === '1' ? null : $code, ($_POST['replace'] ?? '') === '1', ($_POST['unlink'] ?? '') === '1' ? $code : '');
				if (isset($r['error'])) http_response_code(400);
				if (!empty($r['ok'])) $r['usage'] = array_map(static fn($u) => ['form' => $u[0], 'name' => $u[1], 'place' => $u[2], 'v' => $u[3] === 'form' ? 'form' : $u[3] . ':' . $u[4],
					'url' => nu_url('form', ['f' => $u[0], 'design' => '1'])], NuUserHelp::usage()[$code] ?? []);
				echo json_encode($r);
				exit;
			}
			if ($action === 'md_html') {                               // JSON: an AI answer in Markdown → HTML for the visual editor
				header('Content-Type: application/json; charset=utf-8');
				echo json_encode(['ok' => true, 'html' => NuHtmlClean::doc(NuMarkdown::toWeb(mb_substr((string)($_POST['text'] ?? ''), 0, 200000)))]);
				exit;
			}
			if ($action === 'ai_prompt') {                             // JSON: nothing stored
				header('Content-Type: application/json; charset=utf-8');
				$l = (string)($_POST['lang'] ?? '');
				if (($_POST['mode'] ?? '') === 'translate') {
					$src = NuUserHelp::row((string)($_POST['code'] ?? ''), '');
					$prompt = $src ? "Translate this help page into " . (NU_LANGS[$l][1] ?? $l)
						. ".\n\nRules:\n- Keep the HTML tags, the markers in double square brackets (like [[level: …]], [[else]], [[/level]]) and the links starting with #help= exactly as they are.\n- Keep words between # signs (like #USER_NAME#) unchanged.\n- Answer with the translated title on the first line, then an empty line, then the translated HTML only.\n\nTitle: "
						. (string)$src['title'] . "\n\n" . (string)$src['body'] : '';
				} else {
					$prompt = NuUserHelp::aiPrompt((string)($_POST['form'] ?? ''), $l !== '' ? $l : nu_lang(), mb_substr(trim((string)($_POST['note'] ?? '')), 0, 1000),
						array_map('strval', (array)($_POST['related'] ?? [])));
				}
				echo json_encode(['ok' => $prompt !== '', 'prompt' => $prompt]);
				exit;
			}
			if (!$canEdit) { nu_flash('danger', t('uh_err_rights')); nu_redirect(nu_url('helppages')); }
			if ($action === 'upload_image') {                          // a picture of the editor: stored as a file, JSON {location}
				header('Content-Type: application/json; charset=utf-8');
				$f = $_FILES['file'] ?? null;
				$url = is_array($f) && (int)($f['error'] ?? 1) === 0 && (int)$f['size'] <= NuUserHelp::IMG_MAX ? NuUserHelp::storeImage((string)file_get_contents((string)$f['tmp_name'])) : null;
				if ($url === null) { http_response_code(400); echo json_encode(['error' => t('uh_err_image')]); exit; }
				NuLog::write('userhelp_image', NuUserHelp::T, '', ['file' => basename($url)]);
				echo json_encode(['location' => $url]);
				exit;
			}
			if ($action === 'import') {
				$f = $_FILES['file'] ?? null;
				$json = is_array($f) && (int)($f['error'] ?? 1) === 0 && (int)$f['size'] <= 30 * 1024 * 1024 ? (string)file_get_contents((string)$f['tmp_name']) : '';
				$r = NuUserHelp::import($json, nu_check('replace') === '1');
				nu_flash($r['errors'] ? 'warning' : 'success', t('uh_import_done', $r['added'], $r['replaced'], $r['skipped'])
					. ($r['unknown'] ? ' ' . t('uh_import_unknown', implode(', ', $r['unknown'])) : '') . ($r['errors'] ? ' ' . implode(' · ', array_slice($r['errors'], 0, 5)) : ''));
				nu_redirect(nu_url('helppages'));
			}
			if ($action === 'save') {
				$in = ['code' => nu_post('code'), 'lang' => $isTr ? (string)($_POST['lang'] ?? '') : '', 'title' => nu_post('title'), 'body' => (string)($_POST['body'] ?? ''),
					'format' => nu_post('format'), 'parent' => nu_post('parent'), 'sort_order' => nu_post('sort_order'), 'access' => (array)($_POST['access'] ?? []),
					'keywords' => nu_post('keywords'), 'active' => nu_check('active') === '1'];
				if ($isTr && !$row) $in['code'] = (string)$base['code'];
				[$newId, $errors] = NuUserHelp::save($in, $row ? $id : '');
				if (!$errors) {
					nu_flash('success', t('saved'));
					nu_redirect(nu_url('helppages', ['id' => $newId]));
				}
				nu_flash('danger', t('err_check_form'));
			}
			if ($row && $action === 'delete') {
				NuUserHelp::delete($id);
				nu_flash('success', t('uh_deleted', (string)$row['title'] ?: (string)$row['code']));
				nu_redirect($isTr && $base ? nu_url('helppages', ['id' => (string)$base['id']]) : nu_url('helppages'));
			}
		}
		return compact('fid', 'l', 'canEdit', 'f', 'isTr', 'row', 'base', 'errors', 'id');
	}
}
