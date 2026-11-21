<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/** The work of pages/nunotifications.php (code review R7): its POST actions – the page reads the record, renders and shows the result. */
final class NuNotificationsPage
{
	/** The POST actions of pages/nunotifications.php – $nuIn: the page's variables before them; returned: what the page shows afterwards. */
	public static function post(array $nuIn): array
	{
		$filter = $nuIn['filter'] ?? null; $canManage = $nuIn['canManage'] ?? null; $own = $nuIn['own'] ?? null; $u = $nuIn['u'] ?? null; $canSend = $nuIn['canSend'] ?? null; $k = $nuIn['k'] ?? null;
		if (nu_is_post()) {
			$a = nu_post('action');
			$nid = nu_post('id');
			if ($a === 'state') {
				NuNotify::setState($nid, nu_post('what'));
				nu_redirect(nu_url('notifications', ['f' => $filter !== 'all' ? $filter : null]) . '#n' . $nid);
			}
			if ($a === 'readall') {
				NuNotify::readAll();
				nu_redirect(nu_url('notifications', ['f' => $filter !== 'all' ? $filter : null]));
			}
			if (!$canManage) NuAcl::require('-');
			if ($a === 'count') {                                       // live number of recipients of the edit form (JSON)
				header('Content-Type: application/json; charset=utf-8');
				$aud = NuNotify::cleanAudience(['all' => !$own && nu_check('aud_all') === '1'] + (is_array($_POST['audience'] ?? null) ? $_POST['audience'] : []), $own);
				$ids = NuNotify::audienceEmpty($aud) ? [] : NuNotify::recipients($aud);
				$people = NuNotify::people();
				// recipients for the "Show" dialogue: full name, login name, department, team, position – sorted by name
				$rows = array_map(static fn($i) => $i === 'globeadmin' ? ['Globeadmin', 'globeadmin', '', '', '']
					: [($people[$i]['name'] ?? '') !== '' ? $people[$i]['name'] : ($people[$i]['login'] ?? $i), $people[$i]['login'] ?? $i, $people[$i]['department'] ?? '', $people[$i]['team'] ?? '', $people[$i]['position'] ?? ''], $ids);
				usort($rows, static fn($a, $b) => strnatcasecmp($a[0], $b[0]));
				exit(json_encode(['ok' => true, 'n' => count($ids), 'text' => t('ntf_reaches', count($ids)), 'people' => array_slice($rows, 0, 2000)], JSON_UNESCAPED_UNICODE));
			}
			if ($a === 'settings') {
				if (!$u['globeadmin'] && !NuAdmin::can('notifications', 'change')) NuAcl::require('-');
				NuNotify::saveSettings((int)nu_post('keep_days'));
				nu_flash('success', t('ntf_settings_saved'));
				nu_redirect(nu_url('notifications', ['tab' => 'manage']));
			}
			if (!$canSend) NuAcl::require('-');
			$n = $nid !== '' ? NuNotify::load($nid) : null;
			if ($nid !== '' && (!$n || !NuNotify::canEdit($n))) NuAcl::require('-');
			if ($a === 'save') {
				$in = $_POST + ['audience' => []];
				foreach (['pinned', 'must_confirm', 'email'] as $k) $in[$k] = nu_check($k);
				$in['audience']['all'] = !$own && nu_check('aud_all') === '1';
				[$newId, $errors] = NuNotify::save($nid, $in, nu_post('publish') === '1');
				if ($errors) {
					$_SESSION[NuSess::NOTIFY_FORM] = ['in' => $in, 'errors' => $errors];
					nu_flash('danger', t('err_check_form'));
					nu_redirect(nu_url('notifications', ['id' => $nid !== '' ? $nid : 'new']));
				}
				nu_flash('success', t(nu_post('publish') === '1' ? 'ntf_published' : 'ntf_saved'));
				nu_redirect(nu_url('notifications', ['tab' => 'manage']));
			}
			if ($n && in_array($a, ['publish', 'withdraw'], true)) {
				NuNotify::setStatus($nid, $a === 'publish' ? 'published' : 'withdrawn');
				nu_flash('success', t($a === 'publish' ? 'ntf_published' : 'ntf_withdrawn'));
				nu_redirect(nu_url('notifications', ['tab' => 'manage']));
			}
			if ($n && $a === 'delete') {
				NuNotify::delete($nid);
				nu_flash('success', t('ntf_deleted'));
				nu_redirect(nu_url('notifications', ['tab' => 'manage']));
			}
			if ($n && $a === 'copy') {
				$_SESSION[NuSess::NOTIFY_FORM] = ['in' => ['title' => $n['title'], 'body' => $n['body'], 'level' => $n['level'], 'link' => $n['link'], 'audience' => $n['aud'],
					'mode' => $n['mode'], 'pinned' => $n['pinned'], 'must_confirm' => $n['must_confirm'], 'email' => $n['email'], 'format' => (string)($n['format'] ?? '')], 'errors' => []];
				nu_redirect(nu_url('notifications', ['id' => 'new']));
			}
			nu_redirect(nu_url('notifications', ['tab' => 'manage']));
		}
		return compact('filter', 'canManage', 'own', 'u', 'canSend', 'k');
	}
}
