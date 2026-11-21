<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/** The work of pages/nuformai.php (code review R7): its POST actions – the page reads the record, renders and shows the result. */
final class NuFormaiPage
{
	/** The POST actions of pages/nuformai.php – $nuIn: the page's variables before them; returned: what the page shows afterwards. */
	public static function post(array $nuIn): array
	{
		$appMode = $nuIn['appMode'] ?? null;
		$meta = $nuIn['meta'] ?? null;
		$draftSnap = $nuIn['draftSnap'] ?? null;
		$formId = $nuIn['formId'] ?? null;
		$openDraft = $nuIn['openDraft'] ?? null;
		$canCreate = $nuIn['canCreate'] ?? null;
		if (nu_is_post()) {
			header('Content-Type: application/json; charset=utf-8');
			$out = static function (array $d): never { nu_json($d); };
			$action = nu_post('action');
			if ($action === 'context') {                         // AI context pack: a file to attach to the chat together with the prompt
				$tables = array_values(array_filter(array_map('strval', (array)($_POST['tables'] ?? [])), static fn($t) => $t !== ''));
				$app = $appMode && nu_post('kind') === 'app';
				$kind = NuAiContext::kind($app, $meta);
				$secs = NuAiContext::sections($app, $meta, $tables, $draftSnap, nu_post('full') === '1');
				$zip = nu_post('fmt') === 'zip' ? NuAiContext::zip($secs, $kind) : null;
				header_remove('Content-Type');
				if ($zip !== null) { header('Content-Type: application/zip'); $name = NuAiContext::fileName($kind, 'zip'); $body = $zip; }
				else { header('Content-Type: text/markdown; charset=utf-8'); $name = NuAiContext::fileName($kind, 'md'); $body = NuAiContext::markdown($secs, $kind); }
				header('Content-Disposition: attachment; filename="' . $name . '"');
				header('X-Nu-File-Name: ' . $name);
				header('Cache-Control: no-store');
				NuLog::write('form_ai_context', 'zzzzsys_form', $formId, ['kind' => $kind, 'fmt' => $zip !== null ? 'zip' : 'md', 'full' => nu_post('full') === '1']);
				echo $body;
				exit;
			}
			try {
				if ($action === 'prompt') {
					$req = trim(nu_post('request'));
					if ($req === '') $out(['ok' => false, 'error' => t('fai_err_request')]);
					$tables = array_values(array_filter(array_map('strval', (array)($_POST['tables'] ?? [])), static fn($t) => $t !== ''));
					$text = $appMode && nu_post('kind') === 'app' ? NuFormSpec::promptApp($req, $tables) : NuFormSpec::prompt($req, $formId, $tables, $draftSnap);
					$out(['ok' => true, 'text' => $text]);
				}
				if ($action === 'publish_all') {                    // forms of an app just created (status Draft) → Active
					if (!NuFormDraft::canPublish()) $out(['ok' => false, 'errors' => [t('err_forbidden')]]);
					$n = 0;
					foreach (array_slice(array_map('strval', (array)($_POST['forms'] ?? [])), 0, NuFormSpec::MAX_APP_FORMS) as $fid) {
						if ((string)(nu_form($fid)['status'] ?? '') !== '0') continue;
						NuFormDraft::publishNewForm($fid);
						$n++;
					}
					$out(['ok' => true, 'published' => $n]);
				}
				if ($action === 'check' || $action === 'create') {
					$spec = NuFormSpec::parse(nu_post('answer'));
					if (is_string($spec)) $out(['ok' => false, 'errors' => [$spec], 'warnings' => []]);
					$isApp = NuFormSpec::isApp($spec);
					if ($isApp && !$appMode) $out(['ok' => false, 'errors' => [t('fai_err_app_change')], 'warnings' => []]);
					if ($action === 'check') {
						if ($isApp) {
							$r = NuFormSpec::checkApp($spec);
							$out(['ok' => !$r['errors'], 'app' => true, 'errors' => $r['errors'], 'warnings' => $r['warnings'], 'plan' => $r['plan']]);
						}
						$r = NuFormSpec::check($spec, $formId, $draftSnap);
						unset($r['plan']['snapshot']);
						$out(['ok' => !$r['errors'], 'errors' => $r['errors'], 'warnings' => $r['warnings'], 'plan' => $r['plan'], 'into_draft' => (bool)$openDraft]);
					}
					if (!$canCreate) $out(['ok' => false, 'errors' => [t('err_forbidden')]]);
					if ($isApp) {
						$made = NuFormSpec::createApp($spec, nu_post('ddl_ok') === '1', nu_post('note'));
						foreach ($made as &$m) { $mm = NuFormMeta::load($m['id'], true); $m['url'] = $mm ? NuDesign::fromBuilder($mm, []) : nu_url('forms'); }
						unset($m);
						$out(['ok' => true, 'app' => true, 'forms' => $made, 'can_publish' => NuFormDraft::canPublish()]);
					}
					$res = NuFormSpec::create($spec, $formId, nu_post('ddl_ok') === '1', nu_post('note'), $openDraft);
					$newMeta = NuFormMeta::load((string)$res['form_id'], true);            // design mode: the new form or the draft of the change
					$out(['ok' => true, 'url' => $newMeta ? NuDesign::fromBuilder($newMeta, []) : nu_url('forms')]);
				}
			} catch (Throwable $e) {
				$out(['ok' => false, 'errors' => explode("\n", $e->getMessage()), 'warnings' => []]);
			}
			$out(['ok' => false, 'errors' => ['?']]);
		}
		return compact('appMode', 'meta', 'formId', 'openDraft', 'canCreate');
	}
}
