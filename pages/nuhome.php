<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

$u  = NuAuth::user();
$db = nu_db();

$tiles = [];
$admin = NuAdmin::any();                                   // globeadmin or a user with an admin role (0.8.323): the admin tile page
// user start pages (0.8.425): users without an admin role get the tile page of their access level (NuHome::forLevel); admins with
// "Start page layout" edit them on ?p=home&for=<access id | *> – a preview with the rights of that access level
$for = $admin && isset($_GET['for']) ? (string)$_GET['for'] : null;
if ($for !== null) {
	if (!NuHome::canManage()) { http_response_code(403); nu_fatal(t('err_forbidden'), t('err_forbidden_hint'), true); }
	if ($for !== NuHome::ALL_LEVELS && !(int)$db->value('SELECT COUNT(*) FROM zzzzsys_access WHERE zzzzsys_access_id = ?', [$for])) nu_redirect(nu_url('home', ['for' => NuHome::ALL_LEVELS]));
	NuHome::forLevel($for, true);
} elseif (!$admin) {
	NuHome::forLevel((string)($u['access_id'] ?? ''), false);
}
$userPage = NuHome::target() !== null;                     // a user start page or its editor
$editorPage = $for !== null;
$selfUrl = $editorPage ? nu_url('home', ['for' => $for]) : nu_url('home', ['tiles' => '1']);
if ($userPage) NuHomeHook::useUserPage();                  // its hook: the procedure nu_home_user
// Customize: the current layout when it may be changed (0.8.333 saved layouts: own ones always, shared ones with "Start page layout")
$canLayout = $admin && NuHome::current()['editable'];
// other users: tiles with counts for the areas they have rights for (above their start page)
if (!$admin) {
	if (NuAcl::can(NuAcl::FORM_USER)) {
		$tiles[] = ['users', 'bi-people', t('nav_users'), (int)$db->value("SELECT COUNT(*) FROM zzzzsys_user WHERE COALESCE(sus_login_name, '') <> ''"), t('home_users_hint')];
	}
	if (NuAcl::can(NuAcl::FORM_ACCESS)) {
		$tiles[] = ['access', 'bi-shield-lock', t('nav_access'), (int)$db->value('SELECT COUNT(*) FROM zzzzsys_access'), t('home_access_hint')];
	}
}

// ------------------------------------------------------------------ layout (Customize mode), View panel, user's own tiles
if (nu_is_post()) {
	// editor of a user start page: the access level's own page goes – it shows the default again (form post, 0.8.425)
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
		// saved layouts (0.8.333): switch, save as, name / visibility, delete, default for everyone – admin tile page only
		$act = (string)($in['action'] ?? '');
		if (!$admin && !in_array($act, ['fav_only', 'view_me', 'view_me_clear', 'mine', 'mine_clear'], true)) { http_response_code(403); exit(json_encode(['ok' => false, 'message' => t('err_forbidden')])); }
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
		if ($act === 'fav_only') {                             // "Favourites only" (0.8.353): a setting of the login, not of the layout
			NuDemo::permitted(static fn() => NuHome::setFavOnly(!empty($in['on'])));
			exit(json_encode(['ok' => true]));
		}
		// View panel (0.8.420): the personal view of the current layout – everyone who sees this page (shared layouts stay as they are)
		if ($act === 'view_me' || $act === 'view_me_clear') {
			$curId = (string)NuHome::current()['id'];
			$ov = $act === 'view_me' ? ['view' => is_array($in['view'] ?? null) ? $in['view'] : [], 'sizes' => is_array($in['sizes'] ?? null) ? $in['sizes'] : []] : null;
			if (!NuDemo::permitted(static fn() => NuHome::setOverlay($curId, $ov))) { http_response_code(400); exit(json_encode(['ok' => false, 'message' => t('home_v_too_large')], JSON_UNESCAPED_UNICODE)); }
			exit(json_encode(['ok' => true, 'overlay' => NuHome::overlay($curId)], JSON_UNESCAPED_UNICODE));
		}
		// a user's own favourites and hidden tiles on his start page (0.8.425): mine = set, mine_clear = the layout's again
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
		if (($in['action'] ?? '') === 'pin' && !$userPage) {                // pin in a tile's ▾ menu: that page as a tile of its own (0.8.321)
			$p = NuHome::togglePin((string)($in['key'] ?? ''), (string)$u['login']);
			exit(json_encode($p === null ? ['ok' => false] : ['ok' => true, 'pinned' => $p]));
		}
		if (($in['action'] ?? '') === 'size' && !$userPage) {                // deprecated (0.8.420, the former ▾ size menu): drop with the home_size_* texts
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
// nuBuilder's $nuConfigGlobeadminHome: open that launch form instead (the user menu links to this page with tiles=1)
if ($u['globeadmin'] && !$editorPage && ($_GET['tiles'] ?? '') !== '1' && ($homeForm = NuHome::globeadminForm()) !== null) nu_redirect(nu_url('form', ['f' => $homeForm]));
$layout = NuHome::layout();

// "My forms and rights" (0.8.425): only for the globeadmin acting as this user – a quick look at the rights of the access level
$access = null;
$forms  = [];
$showRights = !$u['globeadmin'] && NuImpersonate::active() !== null;
if (!$u['globeadmin']) {
	$access = $db->one('SELECT sal_code, sal_description, sal_group, sal_zzzzsys_form_id FROM zzzzsys_access WHERE zzzzsys_access_id = ?', [$u['access_id']]);
}
if ($showRights) {
	// main + additional access levels (0.8.585): one row per form, a right ticked when any level grants it
	[$in, $lv] = NuUserAccess::inSql('slf_zzzzsys_access_id', $u);
	$forms = [];
	foreach ($db->all('SELECT slf_zzzzsys_form_id, ' . implode(', ', NuAcl::RIGHTS) . " FROM zzzzsys_access_form WHERE $in", $lv) as $r) {
		$fk = (string)$r['slf_zzzzsys_form_id'];
		if (!isset($forms[$fk])) { $forms[$fk] = $r; continue; }
		foreach (NuAcl::RIGHTS as $col) if ((string)$r[$col] === '1') $forms[$fk][$col] = '1';
	}
	$forms = array_values($forms);
	usort($forms, static fn($a, $b) => strcasecmp(nu_form_label($a['slf_zzzzsys_form_id']), nu_form_label($b['slf_zzzzsys_form_id'])));
}

$homeDash = NuDashboard::forHome();   // dashboards of the user's access level (Admin tools → Dashboards, 0.8.298)
if ($homeDash) NuDashboard::csp();
nu_layout_start($editorPage ? t('home_u_title') : t('nav_home'), 'home', ['head' => '<link rel="stylesheet" href="' . nu_asset('assets/css/nuhome.css') . '">']);   // tile styles and View panel (0.8.420)
?>
<?php if ($editorPage): // the editor of the user start pages (0.8.425): which access level, where its page comes from
	$choices = NuHome::levelChoices();
	$curSrc = (string)NuHome::current()['source'];
	$allLv = $for === NuHome::ALL_LEVELS;
	$own = $curSrc === 'level' || ($allLv && $curSrc === 'default');   // saved for this choice: "Use the default again" / "Remove the default"
	$srcKey = $allLv ? ($own ? 'home_u_src_saved_default' : 'home_u_src_auto_all') : ['level' => 'home_u_src_level', 'default' => 'home_u_src_default', 'auto' => 'home_u_src_auto'][$curSrc]; ?>
<div class="nuTitleBar mb-3">
	<h1 class="h3 mb-0 d-flex align-items-center gap-2"><i class="bi bi-grid-1x2" aria-hidden="true"></i><?= h(t('home_u_title')) ?></h1>
	<div class="d-flex flex-wrap gap-2">
		<?= nu_back_button(null, $for !== NuHome::ALL_LEVELS ? nu_url('accesslevel', ['id' => $for]) : nu_url('home', ['tiles' => '1'])) ?>
		<?php if ($own): ?>
		<form method="post" action="<?= h($selfUrl) ?>" data-confirm="<?= h(t($for === NuHome::ALL_LEVELS ? 'home_u_reset_default_confirm' : 'home_u_reset_confirm')) ?>" data-confirm-ok="<?= h(t('home_u_reset')) ?>" data-confirm-class="btn btn-danger">
			<?= nu_csrf_field() ?><input type="hidden" name="action" value="layout_reset">
			<button class="btn nuBtnAct" type="submit" id="nuHomeUseDefault"><i class="bi bi-arrow-counterclockwise me-1" aria-hidden="true"></i><?= h(t($for === NuHome::ALL_LEVELS ? 'home_u_reset_default' : 'home_u_reset')) ?></button></form>
		<?php endif; ?>
	</div>
</div>
<div class="card card-body mb-3 nuHomeForBar">
	<div class="d-flex flex-wrap align-items-center gap-2">
		<label class="fw-semibold" for="nuHomeFor"><?= h(t('home_u_for')) ?></label>
		<select class="form-select w-auto" id="nuHomeFor">
			<?php foreach ($choices as $cid => $c): ?>
				<option value="<?= h($cid) ?>" data-url="<?= h(nu_url('home', ['for' => $cid])) ?>"<?= $cid === $for ? ' selected' : '' ?>><?= h($c['label'] . ($cid !== NuHome::ALL_LEVELS ? ' (' . t('home_u_short_' . $c['source']) . ')' : '')) ?></option>
				<?php if ($cid === NuHome::ALL_LEVELS && count($choices) > 1): ?><option disabled>──────────</option><?php endif; ?>
			<?php endforeach; ?>
		</select>
		<span class="small text-body-secondary" id="nuHomeForSrc"><i class="bi <?= $own ? 'bi-check-circle text-success' : 'bi-arrow-return-right' ?> me-1" aria-hidden="true"></i><?= h(t($srcKey)) ?></span>
	</div>
	<p class="small text-body-secondary mb-0 mt-2"><i class="bi bi-eye me-1" aria-hidden="true"></i><?= h(t($for === NuHome::ALL_LEVELS ? 'home_u_preview_all' : 'home_u_preview')) ?></p>
</div>
<?php elseif ($u['globeadmin']): // no greeting – the page is the tiles; Customize at the bottom (0.8.303) ?>
<h1 class="visually-hidden"><?= h(t('nav_home')) ?></h1>
<?php else: ?>
<div class="mb-4">
	<h1 class="h3 mb-1"><?= h(t('home_welcome', $u['display'])) ?></h1>
	<?php if ($access): ?>
		<p class="text-body-secondary mb-0"><?= h(t('home_your_access')) ?> <span class="badge text-bg-primary"><?= h($access['sal_code']) ?></span>
			<?php if ($access['sal_description']): ?><span class="ms-1"><?= h($access['sal_description']) ?></span><?php endif; ?></p>
	<?php endif; ?>
</div>
<?php endif; ?>

<?php if ($u['globeadmin'] && !$editorPage && NuInstall::existingWarn()): // an existing nuBuilder database (0.8.380, NuInstall) ?>
<div class="alert alert-warning d-flex align-items-start gap-2" role="note" id="nuExistingDbWarn"><i class="bi bi-exclamation-triangle mt-1" aria-hidden="true"></i><div><?= h(t('existing_db_warn')) ?></div></div>
<?php endif; ?>
<?php // tutorial (0.8.328): until hidden or finished; right after the setup wizard also when hidden
$tutBanner = $editorPage ? null : NuTutorial::banner();
$tutOffer = !$editorPage && !empty($_SESSION['nu_tut_offer']) && NuTutorial::allowed();
unset($_SESSION['nu_tut_offer']);
if ($tutOffer && !$tutBanner) { [$tutD, $tutN] = NuTutorial::counts(); if ($tutD < $tutN) $tutBanner = ['done' => $tutD, 'total' => $tutN, 'next' => NuTutorial::nextOpen()]; }
if ($tutBanner): ?>
<div class="alert alert-info d-flex flex-wrap align-items-center gap-2 nuTutBanner" role="note">
	<i class="bi bi-mortarboard fs-4 me-1" aria-hidden="true"></i>
	<div class="me-auto"><div class="fw-semibold"><?= h(t($tutOffer ? 'tut_banner_wizard' : 'tut_banner_title')) ?></div>
		<div class="small"><?= h(t('tut_banner')) ?><?php if ($tutBanner['done']): ?> · <?= h(t('tut_progress', $tutBanner['done'], $tutBanner['total'])) ?><?php endif; ?></div></div>
	<a class="btn btn-primary btn-sm" href="<?= h(nu_url('tutorial', $tutBanner['next'] !== '' ? ['l' => $tutBanner['next']] : [])) ?>"><i class="bi bi-play-fill me-1"></i><?= h(t($tutBanner['done'] ? 'tut_continue_short' : 'tut_start')) ?></a>
	<form method="post" action="<?= h(nu_url('tutorial')) ?>" class="d-inline"><?= nu_csrf_field() ?><input type="hidden" name="back" value="home">
		<button class="btn btn-outline-secondary btn-sm" type="submit" name="action" value="hide" title="<?= h(t('tut_banner_hide_tip')) ?>"><?= h(t('tut_banner_hide')) ?></button></form>
</div>
<?php endif; ?>

<?php foreach ($homeDash as $hd): ?>
<div class="mb-4" data-nu-dashboard="<?= h((string)$hd['code']) ?>"></div>
<?php endforeach; ?>
<?php if ($tiles): ?>
<div class="row g-3 mb-4">
	<?php foreach ($tiles as [$route, $icon, $label, $count, $hint]): ?>
		<div class="col-sm-6 col-lg-4">
			<a class="card nu-tile h-100 text-decoration-none shadow-sm" href="<?= h(nu_url($route)) ?>"><div class="card-body d-flex align-items-center gap-3">
				<span class="nu-tile-icon"><i class="bi <?= $icon ?>"></i></span><div class="flex-grow-1"><div class="text-body-secondary small"><?= h($label) ?></div>
				<div class="fs-3 fw-semibold lh-1 text-body"><?= $count ?></div><div class="small text-body-secondary mt-1"><?= h($hint) ?></div></div>
				<i class="bi bi-chevron-right text-body-secondary"></i></div></a>
		</div>
	<?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($admin || $userPage):
	/** "+" (straight to a new record) or "▾" (menu of the pages behind the tile) on the right of a built-in tile – NuHome::actions(). */
	$pinnedKeys = array_flip(NuHome::placed($layout));
	$actionHtml = static function (array $a) use ($pinnedKeys, $canLayout): string {
		if ($a[0] === 'add') {
			return '<a class="btn btn-outline-secondary nuRowBtn nuHomeAction" href="' . h($a[2]) . '" title="' . h($a[1]) . '" aria-label="' . h($a[1]) . '"><i class="bi bi-plus-lg"></i></a>';
		}
		$items = '';
		if ($a[0] === 'dbmenu') $a[2] = [];                    // Database tile: the entries come from NuVendor::dbMenuItems() (0.8.284)
		if ($a[0] === 'dbmenu') $items = NuVendor::dbMenuItems('home');
		foreach ($a[2] as $it) {
			[$ic, $lb, $url] = $it;
			$key = $it[3] ?? null;
			if ($key !== null && !is_array($url) && $canLayout) {   // with a pin: put this page on the start page as a tile (again: take it off)
				$on = isset($pinnedKeys[$key]);
				$pt = t($on ? 'home_unpin' : 'home_pin');
				$items .= '<li class="d-flex align-items-center nuPinItem"><a class="dropdown-item flex-grow-1" href="' . h($url) . '"><i class="bi ' . h($ic) . ' me-2 text-body-secondary"></i>' . h($lb) . '</a>'
					. '<button type="button" class="btn btn-sm nuPinBtn' . ($on ? ' nuPinned' : '') . '" data-nu-pin="' . h($key) . '" title="' . h($pt) . '" aria-label="' . h($pt . ': ' . $lb) . '" aria-pressed="' . ($on ? 'true' : 'false') . '">'
					. '<i class="bi ' . ($on ? 'bi-pin-fill' : 'bi-pin-angle') . '"></i></button></li>';
				continue;
			}
			$items .= is_array($url)          // ['post' => app]: phpMyAdmin / Adminer (POST, new tab – as the tile)
				? '<li><form method="post" action="' . h(nu_url('vendor')) . '" target="_blank">' . nu_csrf_field() . '<input type="hidden" name="app" value="' . h($url['post']) . '">'
					. '<button type="submit" class="dropdown-item"><i class="bi ' . h($ic) . ' me-2 text-body-secondary"></i>' . h($lb) . '</button></form></li>'
				: '<li><a class="dropdown-item" href="' . h($url) . '"><i class="bi ' . h($ic) . ' me-2 text-body-secondary"></i>' . h($lb) . '</a></li>';
		}
		$tip = t('home_more_of', $a[1]);
		return '<div class="dropdown nuHomeAction"><button class="btn btn-outline-secondary nuRowBtn nuHomeMenuBtn dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false"'
			. ' title="' . h($tip) . '" aria-label="' . h($tip) . '"></button><ul class="dropdown-menu dropdown-menu-end shadow-sm">' . $items . '</ul></div>';
	};
	/**
	 * One tile (0.8.420): the same markup for every tile style – the style, the descriptions and the navigation are classes set by
	 * assets/js/nuhome.js (View panel), so changing the view needs no reload. Kinds: link, POST form (database tool, File Manager),
	 * procedure button. The data-nu-* attributes are read by the nuHome JavaScript API (0.8.379).
	 */
	$condMark = static function (array $t) use ($editorPage): string {    // editor of user start pages: the tile is shown only to some users
		return $editorPage && !empty($t['cond']) ? '<i class="bi bi-funnel-fill nuHomeCondMark" role="img" aria-label="' . h(NuHome::condText($t['cond'])) . '" title="' . h(NuHome::condText($t['cond'])) . '"></i>' : '';
	};
	$tile = static function (string $key, array $t) use ($actionHtml, $condMark): string {
		$act = $t['action'] ?? null;
		$kind = $t['newtab'] || $t['post'] ? 'bi-box-arrow-up-right' : ($t['proc'] ? 'bi-play-fill' : '');
		$color = $t['color'] !== '' ? ' data-nu-color="' . h($t['color']) . '"' : '';
		$bd = $t['badge'] ?? null;                           // status of a tool (version, sample data, System status – 0.8.316)
		$badge = $bd ? '<span class="badge text-bg-' . h((string)$bd[1]) . ' nuHomeBadge" title="' . h((string)$bd[0]) . '">' . h((string)$bd[0]) . '</span>' : '';
		$body = '<span class="nu-tile-icon nuHomeIcon"' . $color . '><i class="bi ' . h($t['icon']) . '"></i></span>'
			. '<span class="nuHomeText"><span class="nuHomeLabelRow"><span class="nuHomeLabel">' . h($t['label']) . '</span>'
			. (!empty($t['fav']) ? '<i class="bi bi-star-fill nuHomeFavMark" aria-hidden="true"></i>' : '') . $condMark($t) . $badge . '</span>'
			. '<span class="nuHomeHint">' . h($t['hint']) . '</span></span>'
			. '<span class="nuHomeGroupName">' . h((string)($t['groupTitle'] ?? '')) . '</span>'
			. ($kind !== '' && !$act ? '<i class="bi ' . $kind . ' nuHomeKind" aria-hidden="true"></i>' : '');
		$attrs = ' data-nu-tile="' . h($key) . '" data-nu-hint="' . h($t['label'] . ($t['hint'] !== '' ? ' – ' . $t['hint'] : '')) . '"' . (!empty($t['fav']) ? ' data-nu-fav="1"' : '')
			. ' data-nu-source="' . h((string)($t['source'] ?? 'builtin')) . '" data-nu-kind="' . h((string)($t['kind'] ?? 'page')) . '" data-nu-group="' . h((string)($t['group'] ?? ''))
			. '" data-nu-tab="' . h((string)($t['tab'] ?? '')) . '" data-nu-label="' . h($t['label']) . '" data-nu-desc="' . h($t['hint']) . '"';
		if ($t['post']) {
			$el = '<form method="post" action="' . h(nu_url('vendor')) . '" target="_blank" class="nuHomeTileForm">' . nu_csrf_field() . '<input type="hidden" name="app" value="' . h($t['post']) . '">'
				. '<button type="submit" class="nuHomeTile"' . $attrs . '>' . $body . '</button></form>';
		} elseif ($t['proc']) {
			$el = '<button type="button" class="nuHomeTile nuHomeProc"' . $attrs . ' data-nu-proc="' . h($t['proc']) . '" data-nu-run="' . h($t['run']) . '">' . $body . '</button>';
		} else {
			$el = '<a class="nuHomeTile" href="' . h((string)$t['href']) . '"' . ($t['newtab'] ? ' target="_blank" rel="noopener"' : '') . $attrs . '>' . $body . '</a>';
		}
		$info = '<button type="button" class="nuHomeInfo" data-nu-descinfo aria-label="' . h(t('home_v_info_aria', $t['label'])) . '"><i class="bi bi-info-circle" aria-hidden="true"></i></button>';
		return '<div class="nuHomeTileWrap" data-nu-wrap="' . h($key) . '">' . $el . $info . ($act ? $actionHtml($act) : '') . '</div>';
	};
	/** A favourite as a chip in the row on top (0.8.420): opens like the tile. */
	$chip = static function (string $key, array $t): string {
		$color = $t['color'] !== '' ? ' data-nu-color="' . h($t['color']) . '"' : '';
		$body = '<span class="nu-tile-icon nuHomeIcon"' . $color . '><i class="bi ' . h($t['icon']) . '"></i></span><span class="nuHomeChipLabel">' . h($t['label']) . '</span>';
		$attrs = ' data-nu-chip="' . h($key) . '" data-nu-cgroup="' . h((string)($t['group'] ?? '')) . '" title="' . h($t['label'] . ($t['hint'] !== '' ? ' – ' . $t['hint'] : '')) . '"';
		if ($t['post']) return '<form method="post" action="' . h(nu_url('vendor')) . '" target="_blank" class="nuHomeTileForm">' . nu_csrf_field() . '<input type="hidden" name="app" value="' . h($t['post']) . '">'
			. '<button type="submit" class="nuHomeChip"' . $attrs . '>' . $body . '</button></form>';
		if ($t['proc']) return '<button type="button" class="nuHomeChip nuHomeProc"' . $attrs . ' data-nu-proc="' . h($t['proc']) . '" data-nu-run="' . h($t['run']) . '">' . $body . '</button>';
		return '<a class="nuHomeChip" href="' . h((string)$t['href']) . '"' . ($t['newtab'] ? ' target="_blank" rel="noopener"' : '') . $attrs . '>' . $body . '</a>';
	};
	// user start pages (0.8.425): the user's own favourites (replace the layout's) and hidden tiles; groups with conditions
	$mine = $userPage && !$editorPage ? (NuHome::overlay((string)NuHome::current()['id']) ?? []) : [];
	$favList = array_key_exists('fav', $mine) ? (array)$mine['fav'] : (array)($layout['fav'] ?? []);
	$hideList = (array)($mine['hide'] ?? []);
	$mineHidden = [];                                          // tiles the user hid: listed while he personalises his page
	$groups = [];
	foreach ($layout['groups'] as $g) {
		if (!NuHome::condOk((array)($g['cond'] ?? []))) continue;
		$items = [];
		foreach ($g['tiles'] as $k) {
			if (!NuHome::visible($k, $layout) || ($r = NuHome::resolve($k, $layout)) === null) continue;
			$r += ['fav' => in_array($k, $favList, true), 'source' => NuHome::source($k)];
			if (in_array($k, $hideList, true)) { $mineHidden[$k] = $r; continue; }
			$items[$k] = $r;
		}
		$groups[] = ['id' => $g['id'], 'title' => $g['title'], 'tab' => $g['tab'] ?? '', 'items' => $items, 'color' => (string)($g['color'] ?? ''), 'cond' => (array)($g['cond'] ?? [])];
	}
	// start page hook nu_home (0.8.378, core/nuhomehook.php): badges, descriptions, hidden and dynamic tiles of this page view
	NuHomeHook::run($layout);
	$groups = NuHomeHook::apply($groups, $layout);
	$groups = array_values(array_filter($groups, static fn($g) => $g['items'] !== []));
	foreach ($groups as $gi => &$g) {
		$g['color'] = ($g['color'] ?? '') !== '' ? $g['color'] : NuHome::GROUP_COLORS[$gi % count(NuHome::GROUP_COLORS)];   // "Colour per group" (0.8.420)
		foreach ($g['items'] as &$it) { $it['group'] = $g['id']; $it['tab'] = $g['tab']; $it['groupTitle'] = $g['title']; }   // data-nu-group / data-nu-tab (0.8.379)
	}
	unset($g, $it);
	// View (0.8.420): the desktop view is drawn on the server (no flash); tablet and phone sizes are set by assets/js/nuhome.js
	$hlCur = NuHome::current(); $hlAll = NuHome::layouts(); $hlDef = NuListView::listDefault(NuHome::LIST); $hlManage = NuHome::canManage();
	$myView = NuHome::overlay((string)$hlCur['id']);
	$view = NuHome::effectiveView($layout, $myView);
	$units = NuHome::tabsOf($layout, $groups);                // tabs with their groups; without tabs every group is a tab of its own
	$tabbed = in_array($view['nav'], ['tabs', 'sidebar'], true);
	$favCount = 0; foreach ($groups as $g) foreach ($g['items'] as $it) if (!empty($it['fav'])) $favCount++;
	// row "Recently used" (0.8.492): the same list as the side bar (NuSidebar, per user in the database) – not in the layout editor
	$recentItems = $editorPage ? [] : NuSidebar::data(8)['recent'];
	$favOn = $favCount > 0 && NuHome::favOnly();
	$viewCls = 'nuHomeNav-' . $view['nav'] . ' nuHomeDesc-' . $view['desc'] . ' nuHomeDens-' . $view['density'] . ' nuHomeIcn-' . $view['icon']
		. ($view['groupColors'] ? ' nuHomeGcol' : '') . (!$view['badges'] ? ' nuHomeNoBadges' : '') . (!$view['actions'] ? ' nuHomeNoActions' : '') . ($favOn ? ' nuHomeFavOnly' : '')
		. ($view['style'] === 'list' ? ' nuHomeListCols-' . $view['listCols'] . ($view['listWidth'] === 'full' ? ' nuHomeListFull' : '') : '');
	?>
<?php // start page hook (0.8.378): safe mode note, the error for the globeadmin, the hook's banners
if (NuHomeHook::safeMode()): ?>
<div class="alert alert-secondary d-flex flex-wrap align-items-center gap-2 py-2" role="note" id="nuHomeSafe"><i class="bi bi-shield-check" aria-hidden="true"></i>
	<span class="me-auto"><?= h(t('hook_safe_on')) ?></span><a class="btn btn-sm btn-outline-secondary" href="<?= h(nu_url('home', ['tiles' => '1'])) ?>"><?= h(t('hook_safe_off')) ?></a></div>
<?php elseif (NuHomeHook::$error !== '' && $u['globeadmin']): ?>
<div class="alert alert-danger d-flex flex-wrap align-items-start gap-2" role="alert" id="nuHomeHookError"><i class="bi bi-exclamation-octagon mt-1" aria-hidden="true"></i>
	<div class="me-auto"><div class="fw-semibold"><?= h(t('hook_error_title')) ?></div><div class="small text-break"><?= h(NuHomeHook::$error) ?></div></div>
	<a class="btn btn-sm btn-outline-danger" href="<?= h(nu_url('procedures', ['id' => (string)(NuHomeHook::procedure()['id'] ?? '')])) ?>"><?= h(t('hook_error_open')) ?></a></div>
<?php endif;
foreach (NuHomeHook::$banners as [$bText, $bLevel]): ?>
<div class="alert alert-<?= h($bLevel) ?> d-flex align-items-start gap-2 nuHomeHookBanner" role="note"><i class="bi <?= ['success' => 'bi-check-circle', 'warning' => 'bi-exclamation-triangle', 'danger' => 'bi-exclamation-octagon'][$bLevel] ?? 'bi-info-circle' ?> mt-1" aria-hidden="true"></i><div><?= h($bText) ?></div></div>
<?php endforeach; ?>
<div class="nuHomeWrap" id="nuHomeWrap">
<div class="nuHomeSearchRow" role="search">
	<div class="input-group nuHomeSearchBox">
		<span class="input-group-text"><i class="bi bi-search" aria-hidden="true"></i></span>
		<input type="search" class="form-control" id="nuHomeSearch" placeholder="<?= h(t('search_ph')) ?>" aria-label="<?= h(t('home_search')) ?>" autocomplete="off" spellcheck="false">
		<?php // favourites only (0.8.353): tiles marked ☆ in Customise; the choice is kept per login
		$favTip = $favCount ? t('home_fav_only') : t('home_fav_none'); ?>
		<button type="button" class="btn btn-outline-secondary nuHomeFavBtn<?= $favOn ? ' active' : '' ?>" id="nuHomeFavOnly" aria-pressed="<?= $favOn ? 'true' : 'false' ?>"<?= $favCount ? '' : ' disabled' ?>
			title="<?= h($favTip) ?>" aria-label="<?= h($favTip) ?>"><i class="bi <?= $favOn ? 'bi-star-fill' : 'bi-star' ?>"></i></button>
	</div>
	<?php // one Customise button (0.8.452): the panel #nuHomeViewPanel with the tabs Content (edit mode / Personalise – the two hidden
	// buttons below start them), Appearance (how the tiles look, before: View) and Layouts (choose, save, share) ?>
	<div class="nuHomeTools">
		<button type="button" class="btn btn-outline-secondary" id="nuHomeViewBtn" aria-expanded="false" aria-controls="nuHomeViewPanel" title="<?= h(t('home_c_tip')) ?>" aria-label="<?= h(t('home_customize')) ?>">
			<i class="bi bi-sliders2" aria-hidden="true"></i><span class="nuHomeBtnTx"><?= h(t('home_customize')) ?></span></button>
		<?php if ($canLayout): ?><button type="button" hidden class="btn btn-outline-secondary" id="nuHomeCustomize" title="<?= h(t($editorPage ? 'home_u_customise_tip' : 'home_v_customise_tip')) ?>">
			<i class="bi bi-pencil" aria-hidden="true"></i><span class="nuHomeBtnTx"><?= h(t('home_customize')) ?></span></button><?php endif; ?>
		<?php if ($userPage && !$editorPage && !NuImpersonate::readOnly()): // a user's own favourites and hidden tiles (0.8.425) ?><button type="button" hidden class="btn btn-outline-secondary" id="nuHomeMine" title="<?= h(t('home_u_mine_tip')) ?>">
			<i class="bi bi-person-gear" aria-hidden="true"></i><span class="nuHomeBtnTx"><?= h(t('home_u_mine')) ?></span></button><?php endif; ?>
	</div>
</div>
<div class="small text-body-secondary mb-3" id="nuHomeSearchNone" hidden><?= h(t('home_search_none')) ?></div>
<div class="mb-4" id="nuHomeSearchMore" hidden><h2 class="nuHomeGroupTitle"><?= h(t('home_search_more')) ?></h2><div class="list-group shadow-sm nuHomeList nuHomeSearchList"></div></div>
<?php if ($userPage && !$editorPage): // personalise (0.8.425): the tiles the user hid, shown while he personalises ?>
<section class="nuHomeMineHidden mb-3" id="nuHomeMineHidden" hidden aria-label="<?= h(t('home_u_mine_hidden')) ?>">
	<h2 class="nuHomeGroupTitle"><i class="bi bi-eye-slash me-2" aria-hidden="true"></i><?= h(t('home_u_mine_hidden')) ?></h2>
	<div class="nuHomeChips"><?php foreach ($mineHidden as $k => $it): ?>
		<button type="button" class="nuHomeChip" data-nu-unhide="<?= h((string)$k) ?>" title="<?= h(t('home_show')) ?>"><span class="nu-tile-icon nuHomeIcon"><i class="bi <?= h($it['icon']) ?>"></i></span><span class="nuHomeChipLabel"><?= h($it['label']) ?></span><i class="bi bi-eye ms-1" aria-hidden="true"></i></button>
	<?php endforeach; ?></div>
	<p class="small text-body-secondary mb-0 nuHomeMineNone"<?= $mineHidden ? ' hidden' : '' ?>><?= h(t('home_u_mine_none')) ?></p>
</section>
<?php endif; ?>
<div id="nuHomeView" class="<?= h($viewCls) ?>">
	<?php if (!$groups): ?>
		<div class="alert alert-info"><?= h(t($userPage ? ($editorPage ? 'home_u_empty_editor' : ($mineHidden ? 'home_u_all_mine_hidden' : 'home_u_empty')) : 'home_all_hidden')) ?></div>
	<?php else: ?>
	<section class="nuHomeStrip" data-nu-strip="fav"<?= $view['favStrip'] && $favCount ? '' : ' hidden' ?> aria-label="<?= h(t('home_v_fav_row')) ?>">
		<h2 class="nuHomeGroupTitle"><button type="button" class="nuHomeStripToggle" aria-expanded="true" aria-controls="nuHomeStripFav"><i class="bi bi-chevron-down nuHomeStripChev" aria-hidden="true"></i><i class="bi bi-star-fill nuHomeStripStar" aria-hidden="true"></i><?= h(t('home_v_fav_row')) ?><span class="badge rounded-pill nuHomeStripCount"></span></button></h2>
		<div class="nuHomeChips" id="nuHomeStripFav"><?php foreach ($groups as $g) foreach ($g['items'] as $k => $it) if (!empty($it['fav'])) echo $chip((string)$k, $it); ?></div>
	</section>
	<section class="nuHomeStrip" data-nu-strip="recent" hidden aria-label="<?= h(t('home_v_recent_row')) ?>">
		<h2 class="nuHomeGroupTitle"><button type="button" class="nuHomeStripToggle" aria-expanded="true" aria-controls="nuHomeStripRecent"><i class="bi bi-chevron-down nuHomeStripChev" aria-hidden="true"></i><i class="bi bi-clock-history" aria-hidden="true"></i><?= h(t('home_v_recent_row')) ?><span class="badge rounded-pill nuHomeStripCount"></span></button><?php if ($recentItems): ?><form method="post" action="<?= h(nu_url('sidebar')) ?>" class="nuHomeStripClearForm"><?= nu_csrf_field() ?><input type="hidden" name="action" value="clear"><input type="hidden" name="back" value="<?= h(nu_url('home')) ?>"><button type="submit" class="nuHomeStripClear" id="nuHomeRecentClear" title="<?= h(t('sb_recent_clear_menu')) ?>" aria-label="<?= h(t('sb_recent_clear_menu')) ?>"><i class="bi bi-x-circle" aria-hidden="true"></i></button></form><?php endif; ?></h2>
		<div class="nuHomeChips" id="nuHomeStripRecent"><?php foreach ($recentItems as $it):
			$body = '<span class="nu-tile-icon nuHomeIcon"' . ($it['color'] !== '' ? ' data-nu-color="' . h($it['color']) . '"' : '') . '><i class="bi ' . h($it['icon']) . '"></i></span><span class="nuHomeChipLabel">' . h($it['label']) . '</span>';
			$attrs = ' data-nu-recent="' . h($it['key']) . '" title="' . h($it['label']) . '"' . ($it['color'] !== '' ? ' data-nu-gcolor="' . h($it['color']) . '"' : '');
			if ($it['post']) echo '<form method="post" action="' . h(nu_url('vendor')) . '" target="_blank" class="nuHomeTileForm">' . nu_csrf_field() . '<input type="hidden" name="app" value="' . h((string)$it['post']) . '"><button type="submit" class="nuHomeChip"' . $attrs . '>' . $body . '</button></form>';
			else echo '<a class="nuHomeChip" href="' . h((string)$it['href']) . '"' . ($it['newtab'] ? ' target="_blank" rel="noopener"' : '') . $attrs . '>' . $body . '</a>';
		endforeach; ?></div>
	</section>
	<?php if (count($units) > 1): ?>
	<ul class="nav nav-tabs nuHomeTabs" role="tablist">
		<?php foreach ($units as $i => $tb): ?>
			<li class="nav-item" role="presentation"><button class="nav-link<?= $i === 0 ? ' active' : '' ?>" id="nuHomeTab_<?= h($tb['id']) ?>" data-nu-tabbtn="<?= h($tb['id']) ?>"
				type="button" role="tab" aria-controls="nuHomePane_<?= h($tb['id']) ?>" aria-selected="<?= $i === 0 ? 'true' : 'false' ?>"<?= $i === 0 ? '' : ' tabindex="-1"' ?>><?= h($tb['title'] !== '' ? $tb['title'] : t('home_group_untitled')) ?></button></li>
		<?php endforeach; ?>
	</ul>
	<?php endif; ?>
	<div class="nuHomeBody">
		<nav class="nuHomeSide" aria-label="<?= h(t('home_v_groups_nav')) ?>">
			<?php $first = true; foreach ($units as $tb): ?>
				<?php if ($layout['tabs'] && count($units) > 1): ?><div class="nuHomeSideHead"><?= h($tb['title'] !== '' ? $tb['title'] : t('home_group_untitled')) ?></div><?php endif; ?>
				<?php foreach ($tb['groups'] as $g): ?>
					<button type="button" class="nuHomeSideItem<?= $first ? ' active' : '' ?>" data-nu-side="<?= h($g['id']) ?>" data-nu-unit="<?= h($tb['id']) ?>" data-nu-gcolor="<?= h($g['color']) ?>"<?= $first ? ' aria-current="true"' : '' ?>>
						<span class="nuHomeGDot" aria-hidden="true"></span><span class="nuHomeSideLabel"><?= h($g['title'] !== '' ? $g['title'] : t('home_group_untitled')) ?></span><span class="nuHomeGCount"><?= count($g['items']) ?></span></button>
				<?php $first = false; endforeach; ?>
			<?php endforeach; ?>
		</nav>
		<select class="form-select nuHomeSideSelect" aria-label="<?= h(t('home_v_groups_nav')) ?>">
			<?php foreach ($units as $tb): ?>
				<?php if ($layout['tabs'] && count($units) > 1): ?><optgroup label="<?= h($tb['title'] !== '' ? $tb['title'] : t('home_group_untitled')) ?>"><?php endif; ?>
				<?php foreach ($tb['groups'] as $g): ?><option value="<?= h($g['id']) ?>"><?= h($g['title'] !== '' ? $g['title'] : t('home_group_untitled')) ?></option><?php endforeach; ?>
				<?php if ($layout['tabs'] && count($units) > 1): ?></optgroup><?php endif; ?>
			<?php endforeach; ?>
		</select>
		<div class="nuHomeMain<?= $tabbed ? '' : ' nuHomeStyle-' . h($view['style']) ?>">
			<?php $firstG = true; foreach ($units as $i => $tb): ?>
				<div class="nuHomePane<?= $i === 0 ? ' nuHomeActive' : '' ?><?= $tabbed ? ' nuHomeStyle-' . h(NuHome::styleFor($layout, $myView, $tb['id'])) : '' ?>" id="nuHomePane_<?= h($tb['id']) ?>" data-nu-tab="<?= h($tb['id']) ?>"
					<?= !empty($tb['groups'][0]['plain']) ? ' data-nu-plain="1"' : '' ?><?= count($units) > 1 ? ' role="tabpanel" aria-labelledby="nuHomeTab_' . h($tb['id']) . '"' : '' ?>>
					<?php foreach ($tb['groups'] as $g): ?>
						<section class="nuHomeGroup<?= $firstG ? ' nuHomeSideOn' : '' ?>" data-nu-group="<?= h($g['id']) ?>" data-nu-gcolor="<?= h($g['color']) ?>" aria-label="<?= h($g['title'] !== '' ? $g['title'] : t('home_group_untitled')) ?>">
							<?php if ($g['title'] !== '' || ($editorPage && !empty($g['cond']))): ?><div class="nuHomeGroupHead"><span class="nuHomeGDot" aria-hidden="true"></span><h2 class="nuHomeGroupTitle"><?= h($g['title']) ?></h2><?= $condMark($g) ?><span class="nuHomeGCount"><?= count($g['items']) ?></span></div><?php endif; ?>
							<div class="nuHomeTiles"><?php foreach ($g['items'] as $k => $it) echo $tile((string)$k, $it); ?></div>
						</section>
					<?php $firstG = false; endforeach; ?>
				</div>
			<?php endforeach; ?>
		</div>
	</div>
	<?php endif; ?>
</div>
<div id="nuHomeEdit" hidden></div>
</div>
<aside id="nuHomeViewPanel" class="nuHomeVp" hidden aria-labelledby="nuHomeVpTitle"></aside>
<?php endif; ?>

<?php if ($showRights): // the globeadmin acting as this user (0.8.425): the rights of the access level, collapsed ?>
<details class="card shadow-sm mt-4 nuHomeRights" id="nuHomeRights">
	<summary class="card-header d-flex justify-content-between align-items-center">
		<span class="fw-semibold"><i class="bi bi-ui-checks-grid me-2" aria-hidden="true"></i><?= h(t('home_u_rights')) ?><?php if ($access): ?> <span class="badge text-bg-primary ms-1"><?= h($access['sal_code']) ?></span><?php endif; ?></span>
		<span class="badge text-bg-secondary"><?= count($forms) ?></span>
	</summary>
	<?php if (!$forms): ?>
		<div class="card-body text-body-secondary"><?= h(t('home_no_forms')) ?></div>
	<?php else: ?>
		<div class="table-responsive">
			<table class="table table-sm align-middle mb-0 nu-table">
				<thead><tr>
					<th scope="col"><?= h(t('form')) ?></th>
					<?php foreach (array_keys(NuAcl::RIGHTS) as $r): ?><th scope="col" class="text-center"><?= h(t('right_' . $r)) ?></th><?php endforeach; ?>
				</tr></thead>
				<tbody>
				<?php foreach ($forms as $f): ?>
					<tr>
						<td>
							<?php $fid = (string)$f['slf_zzzzsys_form_id']; $ft = nu_forms()[$fid]['type'] ?? ''; ?>
							<?php if (!str_starts_with($fid, 'nu') && $ft !== 'subform' && isset(nu_forms()[$fid])): ?>
								<a class="fw-semibold text-decoration-none" href="<?= h(nu_url('form', ['f' => $fid])) ?>"<?= $ft === 'page' && NuPage::opensInTab($fid) ? ' target="_blank" rel="noopener"' : '' ?>><?= h(nu_form_label($fid)) ?></a>
							<?php else: ?><?= h(nu_form_label($fid)) ?><?php endif; ?>
							<?php if ($access && $access['sal_zzzzsys_form_id'] === $f['slf_zzzzsys_form_id']): ?><span class="badge text-bg-info ms-1"><?= h(t('home_form')) ?></span><?php endif; ?>
						</td>
						<?php foreach (NuAcl::RIGHTS as $col): ?>
							<td class="text-center"><?= (string)$f[$col] === '1'
								? '<i class="bi bi-check-circle-fill text-success" role="img" aria-label="' . h(t('yes')) . '"></i>'
								: '<i class="bi bi-dash text-body-tertiary" role="img" aria-label="' . h(t('no')) . '"></i>' ?></td>
						<?php endforeach; ?>
					</tr>
				<?php endforeach; ?>
				</tbody>
				</table>
			</div>
		<?php endif; ?>
</details>
<?php endif; ?>
<?php
$foot = $homeDash ? NuDashboard::scriptTag() : '';
if ($admin || $userPage) {
	$cat = [];
	if (!$userPage) {                                       // built-in tiles: the admin tile page only
		foreach (NuHome::catalog() as $k => [$route, $icon, $label, $hint]) $cat[$k] = ['icon' => $icon, 'label' => $label, 'hint' => $hint];
		$catMain = NuHome::catalog();
		foreach (NuHome::subTiles() as $k => [$sUrl, $sIcon, $sLabel, $sParent]) $cat[$k] ??= ['icon' => $sIcon, 'label' => $sLabel, 'hint' => $catMain[$sParent][2] ?? '', 'sub' => true];
	}
	// what the tile editor offers: forms, procedures (+ reports, condition values on user start pages) – not sent to users
	$procs = []; $formList = []; $reportList = []; $condOpts = (object)[];
	if ($admin) {
		try {
			foreach ($db->all('SELECT zzzzsys_php_id, sph_code, sph_description FROM zzzzsys_php WHERE ' . NuProcedure::listCondition() . ' ORDER BY sph_code') as $p) {
				$procs[] = ['id' => (string)$p['zzzzsys_php_id'], 'label' => $p['sph_code'] . ($p['sph_description'] ? ' – ' . $p['sph_description'] : '')];
			}
		} catch (Throwable) { /* no procedures table */ }
		foreach (nu_forms() as $id => $f) if ($f['type'] !== 'subform') $formList[] = ['id' => (string)$id, 'label' => $f['code'] . ($f['description'] !== '' ? ' – ' . $f['description'] : '')];
		try {
			if ($db->tableExists(NuReport::TABLE)) foreach ($db->all('SELECT zzzzsys_report_id, sre_code, sre_description FROM zzzzsys_report ORDER BY sre_code') as $rp) {
				$reportList[] = ['id' => (string)$rp['zzzzsys_report_id'], 'label' => $rp['sre_code'] . ($rp['sre_description'] ? ' – ' . $rp['sre_description'] : '')];
			}
		} catch (Throwable) { /* no reports */ }
	}
	if ($editorPage) $condOpts = NuHome::condOptions();
	$vLayouts = [];
	if (!$userPage) foreach ($hlAll as $hl) $vLayouts[] = ['id' => $hl['id'], 'name' => $hl['name'], 'scope' => $hl['scope'], 'isDefault' => $hl['id'] === $hlDef || ($hl['id'] === 'default' && $hlDef === 'default')];
	$hlName = $editorPage ? (string)(($choices ?? [])[$for]['label'] ?? $hlCur['name']) : (string)$hlCur['name'];
	$mode = $editorPage ? 'level' : ($userPage ? 'user' : 'admin');
	$myFav = []; foreach ($groups as $g) foreach ($g['items'] as $k => $it) if (!empty($it['fav'])) $myFav[] = (string)$k;
	foreach ($mineHidden as $k => $it) if (!empty($it['fav'])) $myFav[] = (string)$k;
	$data = [
		'url' => $selfUrl, 'csrf' => nu_csrf_token(), 'layout' => $layout, 'mode' => $mode, 'usedUrl' => $editorPage ? '' : nu_url('sidebar'),
		'favUrl' => !$editorPage && NuSidebar::canFav() ? nu_url('sidebar') : '',   // right-click / long press → add to / remove from favourites (0.8.497)
		// a user's own favourites and hidden tiles (0.8.425)
		'mine' => $mode === 'user' ? ['fav' => $myFav, 'hide' => array_values(array_map('strval', array_keys($mineHidden))), 'own' => array_key_exists('fav', $mine) || $hideList !== []] : null,
		// View panel (0.8.420): the layout's view, this person's own changes, where a change may go, the saved layouts
		'view' => ['layout' => $layout['view'], 'overlay' => $myView, 'editable' => (bool)$hlCur['editable'], 'scope' => $mode === 'admin' ? (string)$hlCur['scope'] : ($mode === 'level' ? 'level' : 'shared'), 'id' => (string)$hlCur['id'],
			'name' => $hlName, 'isDefault' => $hlCur['id'] === $hlDef, 'layouts' => $vLayouts, 'defaults' => NuHome::VIEW_DEFAULTS, 'groupColors' => NuHome::GROUP_COLORS,
			'units' => array_map(static fn($tb) => ['id' => $tb['id'], 'title' => $tb['title'] !== '' ? $tb['title'] : t('home_group_untitled')], $units),
			'canMeta' => !$userPage && $hlCur['id'] !== 'default' && $hlCur['editable'], 'canDelete' => !$userPage && $hlCur['id'] !== 'default' && $hlCur['editable'] && !($hlCur['scope'] === 'shared' && $hlCur['id'] === $hlDef),
			'canDefault' => !$userPage && $hlManage && $hlCur['scope'] === 'shared' && $hlCur['id'] !== $hlDef], 'hookData' => (object)NuHomeHook::$data, 'ga' => (bool)$u['globeadmin'],
		'saved' => ['current' => ['id' => $hlCur['id'], 'name' => $hlCur['name'], 'scope' => $hlCur['scope'], 'roles' => $hlCur['roles']], 'manage' => $hlManage && !$userPage,
			'roles' => $hlManage && !$userPage ? array_map(static fn($r) => ['id' => $r['id'], 'name' => $r['name']], NuAdmin::roles()) : []], 'presets' => $userPage ? (object)[] : NuHome::presets(), 'catalog' => (object)$cat,
		'forms' => $formList, 'pages' => $userPage ? NuHome::USER_PAGES : NuHome::PAGES, 'procedures' => $procs, 'reports' => $reportList, 'cond' => $condOpts, 'icons' => NuHome::ICONS,
		'procUrl' => $userPage ? nu_url('home', ['for' => $for, 'run' => '__ID__']) : nu_url('procedures', ['id' => '__ID__']),
		't' => array_combine($k = ['home_customize', 'home_display', 'home_display_groups', 'home_display_tabs', 'home_size', 'home_size_large', 'home_size_compact', 'home_fav_add', 'home_unfav', 'sb_recent_remove', 'sb_recent_clear_menu', 'home_fav', 'home_fav_only', 'home_fav_none_edit', 'home_size_list', 'home_size_listwide', 'home_size_list2',
			'home_add_group', 'home_add_tile', 'home_kind_builtin', 'home_tile_builtin', 'home_builtin_tiles', 'home_builtin_none', 'home_pinned', 'home_unpinned', 'home_hidden', 'home_hidden_hint', 'home_group_title', 'home_group_new', 'home_group_untitled',
			'home_group_delete', 'home_move_up', 'home_move_down', 'home_move_left', 'home_move_right', 'home_hide', 'home_show', 'home_edit_tile', 'home_delete_tile',
			'home_tile_new', 'home_tile_label', 'home_tile_hint', 'home_tile_icon', 'home_tile_color', 'home_tile_no_color', 'home_tile_kind', 'home_kind_form', 'home_kind_page',
			'home_kind_procedure', 'home_kind_url', 'home_tile_form', 'home_tile_record', 'home_tile_record_hint', 'home_tile_page', 'home_tile_procedure', 'home_tile_run',
			'home_run_window', 'home_run_hidden', 'home_tile_url', 'home_tile_url_hint', 'home_tile_newtab', 'home_drop_here', 'home_edit_hint', 'home_saved_err',
			'home_proc_done', 'home_proc_failed', 'home_tabs', 'home_add_tab', 'home_tab_new', 'home_tab_title', 'home_tab_up', 'home_tab_down',
			'home_tab_delete', 'home_tab_delete_confirm', 'home_tabs_hint', 'home_layout_saveas', 'home_start_from', 'home_start_from_tip', 'home_pf_menu_head', 'home_pf_n_tiles', 'home_pf_keep_own', 'home_pf_loaded', 'home_pf_loaded_own', 'home_pf_not_saved', 'home_pf_counts', 'home_pf_counts_tabs', 'home_pf_undo', 'home_pf_g_own', 'home_layout_based_on', 'home_layout_based_current', 'home_layout_based_profiles', 'home_layout_based_copy', 'home_layout_keep_own', 'home_layout_meta', 'home_layout_name', 'home_layout_scope', 'home_layout_scope_user',
			'home_layout_scope_shared', 'home_layout_roles', 'home_layout_roles_all', 'home_layout_delete_confirm', 'home_layout_delete', 'home_layout_make_default', 'home_layout_copy', 'home_group_tab', 'home_running', 'home_done', 'home_editing', 'home_discard_confirm', 'home_drag', 'home_delete_tile_confirm', 'home_group_delete_confirm', 'save', 'cancel', 'ok', 'delete', 'close',
			// user start pages (0.8.425)
			'home_kind_report', 'home_tile_report', 'home_u_cond', 'home_u_cond_hint', 'home_u_cond_group', 'home_u_cond_permission', 'home_u_cond_team', 'home_u_cond_department',
			'home_u_cond_position', 'home_u_cond_values', 'home_u_store_level', 'home_u_store_user', 'home_u_mine', 'home_u_mine_bar', 'home_u_mine_reset', 'home_u_mine_reset_confirm',
			'home_u_mine_fav', 'home_u_mine_unfav', 'home_u_mine_hide', 'home_show',
			// one Customise panel (0.8.452): tabs Content · Appearance · Layouts
			'home_layouts', 'home_c_tab_content', 'home_c_tab_appearance', 'home_c_edit', 'home_c_edit_hint', 'home_c_edit_btn', 'home_c_mine_hint'], array_map('t', $k))
			// View panel (0.8.420)
			+ array_combine($k = array_map(static fn($x) => 'home_v_' . $x, ['view', 'personal', 'fav_row', 'fav_row_hint', 'recent_row', 'recent_row_hint', 'sort_name', 'sort_group', 'sort_status', 'col_desc', 'hc_open',
			'saved_everyone', 'size_desktop', 'size_tablet', 'size_phone', 'style', 'list_cols', 'list_width', 'desc', 'lines', 'delay', 'nav', 'density',
			'icons', 'group_colors', 'badges', 'badges_hint', 'actions', 'actions_hint', 'own_one', 'default', 'more', 'layout', 'store_personal',
			'store_shared_user', 'undo_mine', 'store_shared_admin', 'applies_to', 'only_me', 'everyone', 'everyone_note', 'mine_count', 'mine_count_one', 'make_layout', 'make_layout_title', 'make_layout_text', 'make_layout_ok', 'undo_mine_title', 'undo_mine_text', 'undo_mine_ok',
			'settings_for', 'all_sizes', 'only_size', 'own_settings', 'same_all_sizes', 'only_size_note', 'style_large', 'style_large_hint', 'style_cards',
			'style_cards_hint', 'style_compact', 'style_compact_hint', 'style_list', 'style_list_hint', 'style_launcher', 'style_launcher_hint', 'style_panels',
			'style_panels_hint', 'style_table', 'style_table_hint', 'auto', 'readable', 'full', 'use_for', 'all_tabs', 'only_tab', 'same_all_tabs', 'tab_owner',
			'size_beats_tab', 'desc_auto', 'desc_auto_hint', 'desc_inline', 'desc_inline_hint', 'desc_tooltip', 'desc_tooltip_hint', 'desc_hovercard',
			'desc_hovercard_hint', 'desc_info', 'desc_info_hint', 'desc_off', 'desc_off_hint', 'all', 'delay_none', 'delay_short', 'delay_long', 'nav_groups',
			'nav_groups_hint', 'nav_tabs', 'nav_tabs_hint', 'nav_sidebar', 'nav_sidebar_hint', 'nav_none', 'nav_none_hint', 'on_top', 'look', 'roomy', 'medium',
			'dense', 'icon_soft', 'icon_solid', 'icon_outline', 'icon_mono', 'icon_none', 'reset', 'saved', 'reset_done', 'reset_everyone']), array_map('t', $k)),
	];
	$foot .= '<script type="application/json" id="nuHomeData">' . json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>'
		. '<script src="' . nu_asset('assets/js/nuhome.js') . '"></script>'
		// JavaScript of the start page hook (0.8.379): the procedure nu_home's JavaScript, served as a file (CSP: no inline scripts)
		. (($hookJs = NuHomeHook::jsUrl()) !== null ? '<script src="' . h($hookJs) . '"></script>' : '');
}
nu_layout_end($foot);
