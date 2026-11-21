<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/** Functions of the page pages/nuemailtemplates.php (code review R7: a page defines no global function). */
final class NuEmailTemplatesPage
{
	public static function json(array $d, int $status = 200): never
	{
		nu_json($d, $status);
	}

	/** The POST actions of pages/nuemailtemplates.php – $nuIn: the page's variables before them; returned: what the page shows afterwards. */
	public static function post(array $nuIn): array
	{
		$tpl = $nuIn['tpl'] ?? null;
		$db = $nuIn['db'] ?? null;
		$T = $nuIn['T'] ?? null;
		$src = $nuIn['src'] ?? null;
		$errors = $nuIn['errors'] ?? null;
		$id = $nuIn['id'] ?? null;
		$cloneId = $nuIn['cloneId'] ?? null;
		$k = $nuIn['k'] ?? null;
		if (nu_is_post()) {
			$action = nu_post('action');
			$in = [];
			foreach (array_keys(NuEmailTemplate::LEN) as $k) $in[$k] = nu_post($k);
			$in['emt_body'] = is_string($_POST['emt_body'] ?? null) ? str_replace("\r\n", "\n", $_POST['emt_body']) : '';
			$in['emt_template'] = false;                                        // set only by nuBuilder (its shipped templates), never here

			if ($action === 'preview' || $action === 'test') {
				// a record of the template's form: only with rights on that form (before, any record of any form could be shown)
				$fid = (string)$in['emt_form_id'];
				if ($fid !== '' && trim(nu_post('record')) !== '' && !NuAcl::can($fid)) NuEmailTemplatesPage::json(['ok' => false, 'error' => t('emt_err_form_right')]);
				if ($action === 'test') {
					// a test e-mail sends any text to any address: right to change templates, at most 10 per hour and user
					if (!NuAcl::can(NuAcl::FORM_EMAILTEMPLATE, $tpl ? 'save' : 'create')) NuEmailTemplatesPage::json(['ok' => false, 'error' => t('err_forbidden')]);
					$sent = (int)$db->value('SELECT COUNT(*) FROM zzzzsys_nunext_log WHERE event = ? AND user_id = ? AND log_time > ?',
						['email_template_test', (string)NuAuth::user()['id'], date('Y-m-d H:i:s', time() - 3600)]);
					if ($sent >= 10) NuEmailTemplatesPage::json(['ok' => false, 'error' => t('emt_err_test_limit', 10)]);
				}
				$out = NuEmailTemplate::render($in, trim(nu_post('record')));
				if ($action === 'preview') NuEmailTemplatesPage::json(['ok' => true] + $out);
				$to = trim(nu_post('test_to'));
				if (!NuMailer::validAddress($to)) NuEmailTemplatesPage::json(['ok' => false, 'error' => t('user_err_email')]);
				$r = nuSendEmailEx(['to' => $to, 'subject' => $out['subject'], 'body' => $out['body'], 'html' => true],
					['tag' => 'nunext_template_test', 'form_id' => $in['emt_form_id'] ?: null, 'record_id' => trim(nu_post('record')) ?: null,
					 'json' => ['template' => $in['emt_code']]]);
				NuLog::write('email_template_test', $T, (string)($tpl['zzzzsys_email_template_id'] ?? ''), ['to' => $to, 'code' => (string)$in['emt_code'], 'ok' => (bool)$r[0]]);
				NuEmailTemplatesPage::json(['ok' => (bool)$r[0], 'error' => $r[0] ? '' : trim(strip_tags($r[1] . ' ' . $r[2]))]);
			}

			if ($action === 'save') {
				$need = $tpl ? 'save' : ($src ? 'clone' : 'create');
				if (!NuAcl::can(NuAcl::FORM_EMAILTEMPLATE, $need)) NuAcl::require(NuAcl::FORM_EMAILTEMPLATE, $need);
				if ($tpl && (string)$tpl['emt_template'] === '1') {                // nuBuilder's templates are read-only – clone them
					$errors['_'] = t('emt_err_template');
				} else {
					[$newId, $errors] = NuEmailTemplate::save($in, $tpl ? $id : '');
					if (!$errors) {
						NuLog::write($tpl ? 'email_template_changed' : 'email_template_created', $T, (string)$newId,
							$tpl ? ['fields' => NuLog::diff($tpl, $db->one("SELECT * FROM $T WHERE zzzzsys_email_template_id = ?", [$newId]) ?? [])] : ['code' => $in['emt_code']] + ($src ? ['clone_of' => $cloneId] : []));
						nu_flash('success', t('saved'));
						nu_redirect(nu_url('emailtemplates', ['id' => $newId]));
					}
				}
			}

			if ($action === 'delete' && $tpl) {
				NuAcl::require(NuAcl::FORM_EMAILTEMPLATE, 'delete');
				if ((string)$tpl['emt_template'] === '1') { nu_flash('danger', t('emt_err_template')); nu_redirect(nu_url('emailtemplates', ['id' => $id])); }
				$db->delete($T, 'zzzzsys_email_template_id', $id);
				NuLog::write('email_template_deleted', $T, $id, ['code' => (string)$tpl['emt_code']]);
				nu_flash('success', t('emt_deleted'));
				nu_redirect(nu_url('emailtemplates'));
			}
		}
		return compact('k', 'tpl', 'db', 'T', 'src', 'errors', 'id');
	}
}
