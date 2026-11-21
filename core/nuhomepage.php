<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/** The work of pages/nuhome.php (code review R7): its POST actions – the page reads the record, renders and shows the result. */
final class NuHomePage
{
	/** The POST actions of pages/nuhome.php – $nuIn: the page's variables before them; returned: what the page shows afterwards. */
	public static function post(array $nuIn): array
	{
		$u = $nuIn['u'] ?? null;
		$editorPage = $nuIn['editorPage'] ?? null;
		$selfUrl = $nuIn['selfUrl'] ?? null;
		$userPage = $nuIn['userPage'] ?? null;
		$admin = $nuIn['admin'] ?? null;
		$canLayout = $nuIn['canLayout'] ?? null;
		$in = $nuIn['in'] ?? null;
		$lv = $nuIn['lv'] ?? null;
		if (nu_is_post() && nu_post('action') === 'hide_entry_notice' && !empty($u['globeadmin']) && empty($u['demo'])) {   // renamed entry files
			NuMigrate::entryNotice(false);
			nu_redirect(nu_url('home'));
		}
		if (nu_is_post()) {
			// editor of a user start page: the access level's own page goes – it shows the default again (form post)
			if ($editorPage && nu_post('action') === 'layout_reset') {
				$err = NuHome::resetLevel();
				nu_flash($err === '' ? 'success' : 'danger', $err === '' ? t('home_u_reset_done') : $err);
				nu_redirect($selfUrl);
			}
			// procedure tile of a user start page (like the procedure page's test run): runs with the user's rights (NuPhp::procedure)
			if ($userPage && isset($_GET['run']) && nu_post('action') === 'run') {
				header('Content-Type: application/json; charset=utf-8');
				session_write_close();
				$proc = NuProcedure::load((string)$_GET['run']);
				if (!$proc) exit(json_encode(['ok' => false, 'errors' => [['message' => t('record_not_found')]]]));
				$res = NuProcedure::run($proc, []);
				NuLog::write('procedure_run', NuProcedure::TABLE, (string)$proc['zzzzsys_php_id'], ['code' => (string)$proc['sph_code'], 'ok' => $res['ok'], 'from' => 'home']);
				exit(json_encode(['ok' => $res['ok'], 'output' => $res['output'], 'errors' => $res['errors']], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
			}
			{                                                      // JSON from assets/js/nuhome.js (CSRF checked in index.php)
				header('Content-Type: application/json; charset=utf-8');
				$in = json_decode((string)file_get_contents('php://input'), true);
				$in = is_array($in) ? $in : [];
				// saved layouts: switch, save as, name / visibility, delete, default for everyone – admin tile page only
				$act = (string)($in['action'] ?? '');
				if (!$admin && !in_array($act, ['view_me', 'view_me_clear', 'mine', 'mine_clear'], true)) { http_response_code(403); exit(json_encode(['ok' => false, 'message' => t('err_forbidden')])); }
				if (str_starts_with($act, 'layout_') && $userPage) { http_response_code(400); exit(json_encode(['ok' => false, 'message' => t('err_forbidden')])); }
				if (str_starts_with($act, 'layout_')) {
					$roles = is_array($in['roles'] ?? null) ? $in['roles'] : [];
					$err = match ($act) {
						'layout_select' => NuHome::select((string)($in['id'] ?? '')) ? '' : t('err_not_found'),
						'layout_saveas' => NuHome::saveAs((string)($in['name'] ?? ''), (string)($in['scope'] ?? 'user'), $roles, (string)($in['preset'] ?? ''), !empty($in['keep_own'])),
						'layout_meta' => NuHome::meta((string)($in['name'] ?? ''), (string)($in['scope'] ?? 'user'), $roles),
						'layout_delete' => NuHome::deleteCurrent(),
						'layout_default' => NuHome::makeDefault(),
						default => t('err_not_found'),
					};
					exit(json_encode($err === '' ? ['ok' => true] : ['ok' => false, 'message' => $err], JSON_UNESCAPED_UNICODE));
				}
				// View panel: the personal view of the current layout – everyone who sees this page (shared layouts stay as they are)
				if ($act === 'view_me' || $act === 'view_me_clear') {
					$curId = (string)NuHome::current()['id'];
					$ov = $act === 'view_me' ? ['view' => is_array($in['view'] ?? null) ? $in['view'] : [], 'sizes' => is_array($in['sizes'] ?? null) ? $in['sizes'] : []] : null;
					if (!NuDemo::permitted(static fn() => NuHome::setOverlay($curId,
						$ov))) { http_response_code(400); exit(json_encode(['ok' => false, 'message' => t('home_v_too_large')], JSON_UNESCAPED_UNICODE)); }
					exit(json_encode(['ok' => true, 'overlay' => NuHome::overlay($curId)], JSON_UNESCAPED_UNICODE));
				}
				// a user's own favourites and hidden tiles on his start page: mine = set, mine_clear = the layout's again
				if ($act === 'mine' || $act === 'mine_clear') {
					if (!$userPage || $editorPage) { http_response_code(400); exit(json_encode(['ok' => false, 'message' => t('err_forbidden')])); }
					$curId = (string)NuHome::current()['id'];
					$ok = NuDemo::permitted(static fn() => $act === 'mine'
						? NuHome::setMine($curId, is_array($in['fav'] ?? null) ? $in['fav'] : null, is_array($in['hide'] ?? null) ? $in['hide'] : [])
						: NuHome::setMine($curId, null, []));
					if (!$ok) { http_response_code(400); exit(json_encode(['ok' => false, 'message' => t('home_v_too_large')], JSON_UNESCAPED_UNICODE)); }
					exit(json_encode(['ok' => true]));
				}
				if (!$canLayout) { http_response_code(403); exit(json_encode(['ok' => false, 'message' => t('err_forbidden')])); }
				if ($act === 'view_layout') {                         // View panel: the layout's own view (personal layout, or "for everyone")
					if (!is_array($in['view'] ?? null)) { http_response_code(400); exit(json_encode(['ok' => false, 'message' => t('home_saved_err')], JSON_UNESCAPED_UNICODE)); }
					$errors = NuHome::saveView($in['view'], (string)$u['login']);
					exit(json_encode($errors ? ['ok' => false, 'errors' => $errors, 'message' => (string)reset($errors)] : ['ok' => true, 'view' => NuHome::layout()['view']], JSON_UNESCAPED_UNICODE));
				}
				if (($in['action'] ?? '') === 'pin' && !$userPage) {                // pin in a tile's ▾ menu: that page as a tile of its own
					$p = NuHome::togglePin((string)($in['key'] ?? ''), (string)$u['login']);
					exit(json_encode($p === null ? ['ok' => false] : ['ok' => true, 'pinned' => $p]));
				}
				if (($in['action'] ?? '') === 'size' && !$userPage) {                // deprecated (the former ▾ size menu): drop with the home_size_* texts
					$v = (array)NuHome::layout()['view'];
					$lv = NuHome::legacyView(['size' => (string)($in['size'] ?? '')]);
					foreach (['style', 'listCols', 'listWidth'] as $k) $v[$k] = $lv[$k];
					$errors = NuHome::saveView($v, (string)$u['login']);
					exit(json_encode($errors ? ['ok' => false, 'errors' => $errors] : ['ok' => true], JSON_UNESCAPED_UNICODE));
				}
				if ($act !== 'layout') { http_response_code(400); exit(json_encode(['ok' => false, 'message' => t('err_not_found')])); }
				$errors = NuHome::save(is_array($in['layout'] ?? null) ? $in['layout'] : [], (string)$u['login']);
				exit(json_encode($errors ? ['ok' => false, 'errors' => $errors] : ['ok' => true], JSON_UNESCAPED_UNICODE));
			}
		}
		return compact('u', 'editorPage', 'selfUrl', 'userPage', 'in', 'admin', 'canLayout', 'lv');
	}
}
