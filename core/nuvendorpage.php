<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/** The work of pages/nuvendor.php (code review R7): its POST actions – the page reads the record, renders and shows the result. */
final class NuVendorPage
{
	/** The POST actions of pages/nuvendor.php (part 1) – $nuIn: the page's variables before them; returned: what the page shows afterwards. */
	public static function post1(array $nuIn): array
	{
		if (nu_is_post()) {
			$reqApp = strtoupper((string)($_POST['app'] ?? ''));
			if (nu_post('action') === 'db_primary' || in_array($reqApp, ['PMA', 'ADM', 'DB'], true)) NuAdmin::require('dbtool', 'change');
			elseif ($reqApp === 'TFM') { NuAdmin::require('filemanager', 'change'); nu_redirect(NuFileManager::url()); }
			else NuAdmin::require('status', 'change');
		} elseif ((string)($_GET['open'] ?? '') === '') {           // ?open=<token>: the database tool's own right, checked below
			NuAdmin::require('status', 'view');
		}
		return [];
	}

	/** The POST actions of pages/nuvendor.php (part 2) – $nuIn: the page's variables before them; returned: what the page shows afterwards. */
	public static function post2(array $nuIn): array
	{
		$appReq = $nuIn['appReq'] ?? null;
		if (nu_is_post() && nu_post('open') === 'link' && in_array($appReq, ['PMA', 'ADM', 'DB'], true)) {
			header('Content-Type: application/json; charset=utf-8');
			$tok = bin2hex(random_bytes(16));
			$_SESSION[NuSess::VENDOR_OPEN] = array_filter((array)($_SESSION[NuSess::VENDOR_OPEN] ?? []), static fn($x) => (int)($x['exp'] ?? 0) > time());
			$_SESSION[NuSess::VENDOR_OPEN][$tok] = ['app' => $appReq, 'table' => (string)($_POST['table'] ?? ''), 'exp' => time() + 120];
			$go = nu_url('vendor', ['open' => $tok]);
			if (!NuSecurity::recentAuth() && empty(NuAuth::user()['demo'])) { $_SESSION[NuSess::REAUTH_NEXT] = NuAuth::validNext($go) ?? nu_url('home'); $go = nu_url('reauth'); }
			exit(json_encode(['url' => $go]));
		}
		return [];
	}

	/** The POST actions of pages/nuvendor.php (part 3) – $nuIn: the page's variables before them; returned: what the page shows afterwards. */
	public static function post3(array $nuIn): array
	{
		$e = $nuIn['e'] ?? null;
		if (nu_is_post()) NuSecurity::requireRecentAuth(nu_url('vendor'));   // phpMyAdmin / File Manager / InnoDB: password confirmed recently

		// demo mode: snapshot / reset of the tables of the allowed forms, reset interval (core/nudemodata.php)
		if (nu_is_post() && in_array(nu_post('action'), ['demo_snapshot', 'demo_reset', 'demo_interval'], true)) {
			$by = (string)(NuAuth::user()['login'] ?? '');
			try {
				switch (nu_post('action')) {
					case 'demo_snapshot':
						$info = NuDemoData::snapshot($by);
						nu_flash('success', t('demo_snap_done', count($info['tables']), array_sum($info['tables'])));
						break;
					case 'demo_reset':
						nu_flash('success', t('demo_reset_done', NuDemoData::reset($by)));
						break;
					case 'demo_interval':
						$hrs = (int)nu_post('hours');
						NuDemoData::saveSetting(['hours' => in_array($hrs, NuDemoData::HOURS, true) ? $hrs : 24] + NuDemoData::setting());
						nu_flash('success', t('set_saved', 1));
						break;
				}
			} catch (RuntimeException $e) {
				nu_flash('danger', $e->getMessage());
			}
			nu_redirect(nu_url('vendor') . '#demodata');
		}

		// star in the ▾ menu of the Database tile / card: this tool becomes the default
		if (nu_is_post() && nu_post('action') === 'db_primary') {
			$pa = strtoupper(nu_post('app'));
			$err = NuVendor::available($pa) ? NuVendor::setPrimaryDb($pa) : t('vendor_failed');
			if ($err === '') nu_flash('success', t('vendor_db_default_saved', NuVendor::DB_TOOLS[$pa]));
			else nu_flash('danger', t('vendor_db_default_failed') . ' ' . $err);
			nu_redirect(nu_url(nu_post('back') === 'home' ? 'home' : 'vendor'));
		}
		return compact('e');
	}

	/** The POST actions of pages/nuvendor.php (part 4) – $nuIn: the page's variables before them; returned: what the page shows afterwards. */
	public static function post4(array $nuIn): array
	{
		$app = $nuIn['app'] ?? null; $table = $nuIn['table'] ?? null; $e = $nuIn['e'] ?? null;
		if (nu_is_post() && isset(NuVendor::APPS[$app]) && NuVendor::available($app)) {
			try {
				nu_redirect(NuVendor::launch($app, $table));
			} catch (Throwable $e) {
				error_log('nuBuilder Next vendor login: ' . $e->getMessage());
				nu_flash('danger', t('vendor_failed') . (nu_debug() ? ' ' . $e->getMessage() : ''));
			}
		}
		return compact('e');
	}
}
