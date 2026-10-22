<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

$u  = NuAuth::user();
$db = nu_db();

$tiles = [];
$admin = NuAdmin::any();                                   // globeadmin or a user with an admin role (0.8.323): the tile page
// Customize: the current layout when it may be changed (0.8.333 saved layouts: own ones always, shared ones with "Start page layout")
$canLayout = NuAdmin::any() && NuHome::current()['editable'];
// other users: tiles with counts for the areas they have rights for (the admin's page: NuHome, below)
if (!$admin) {
	if (NuAcl::can(NuAcl::FORM_USER)) {
		$tiles[] = ['users', 'bi-people', t('nav_users'), (int)$db->value("SELECT COUNT(*) FROM zzzzsys_user WHERE COALESCE(sus_login_name, '') <> ''"), t('home_users_hint')];
	}
	if (NuAcl::can(NuAcl::FORM_ACCESS)) {
		$tiles[] = ['access', 'bi-shield-lock', t('nav_access'), (int)$db->value('SELECT COUNT(*) FROM zzzzsys_access'), t('home_access_hint')];
	}
}

// ------------------------------------------------------------------ globeadmin: launch form, layout (Customize mode)
if ($admin) {
	if (nu_is_post()) {                                    // JSON from assets/js/nuhome.js (CSRF checked in index.php)
		header('Content-Type: application/json; charset=utf-8');
		$in = json_decode((string)file_get_contents('php://input'), true);
		$in = is_array($in) ? $in : [];
		// saved layouts (0.8.333): switch, save as, name / visibility, delete, default for everyone
		$act = (string)($in['action'] ?? '');
		if (str_starts_with($act, 'layout_')) {
			$roles = is_array($in['roles'] ?? null) ? $in['roles'] : [];
			$err = match ($act) {
				'layout_select' => NuHome::select((string)($in['id'] ?? '')) ? '' : t('err_not_found'),
				'layout_saveas' => NuHome::saveAs((string)($in['name'] ?? ''), (string)($in['scope'] ?? 'user'), $roles),
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
		if (!$canLayout) { http_response_code(403); exit(json_encode(['ok' => false, 'message' => t('err_forbidden')])); }
		if (($in['action'] ?? '') === 'reset') {
			NuHome::reset();
			exit(json_encode(['ok' => true]));
		}
		if (($in['action'] ?? '') === 'pin') {                // pin in a tile's ▾ menu: that page as a tile of its own (0.8.321)
			$p = NuHome::togglePin((string)($in['key'] ?? ''), (string)$u['login']);
			exit(json_encode($p === null ? ['ok' => false] : ['ok' => true, 'pinned' => $p]));
		}
		if (($in['action'] ?? '') === 'size') {                // ▾ next to Customize: only the tile layout changes
			$l = NuHome::layout();
			$l['size'] = (string)($in['size'] ?? '');
			$errors = NuHome::save($l, (string)$u['login']);
			exit(json_encode($errors ? ['ok' => false, 'errors' => $errors] : ['ok' => true], JSON_UNESCAPED_UNICODE));
		}
		$errors = NuHome::save(is_array($in['layout'] ?? null) ? $in['layout'] : [], (string)$u['login']);
		exit(json_encode($errors ? ['ok' => false, 'errors' => $errors] : ['ok' => true], JSON_UNESCAPED_UNICODE));
	}
	// nuBuilder's $nuConfigGlobeadminHome: open that launch form instead (the user menu links to this page with tiles=1)
	if ($u['globeadmin'] && ($_GET['tiles'] ?? '') !== '1' && ($homeForm = NuHome::globeadminForm()) !== null) nu_redirect(nu_url('form', ['f' => $homeForm]));
	$layout = NuHome::layout();
}

$access = null;
$forms  = [];
if (!$u['globeadmin']) {
	$access = $db->one('SELECT sal_code, sal_description, sal_group, sal_zzzzsys_form_id FROM zzzzsys_access WHERE zzzzsys_access_id = ?', [$u['access_id']]);
	$forms  = $db->all('SELECT slf_zzzzsys_form_id, ' . implode(', ', NuAcl::RIGHTS) . ' FROM zzzzsys_access_form WHERE slf_zzzzsys_access_id = ?', [$u['access_id']]);
	usort($forms, static fn($a, $b) => strcasecmp(nu_form_label($a['slf_zzzzsys_form_id']), nu_form_label($b['slf_zzzzsys_form_id'])));
}

$homeDash = NuDashboard::forHome();   // dashboards of the user's access level (Admin tools → Dashboards, 0.8.298)
if ($homeDash) NuDashboard::csp();
nu_layout_start(t('nav_home'), 'home');
?>
<?php if ($u['globeadmin']): // no greeting – the page is the tiles; Customize at the bottom (0.8.303) ?>
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

<?php // tutorial (0.8.328): until hidden or finished; right after the setup wizard also when hidden
$tutBanner = NuTutorial::banner();
$tutOffer = !empty($_SESSION['nu_tut_offer']) && NuTutorial::allowed();
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

<?php if ($admin):
	/** One tile of the globeadmin page in the chosen size (large: icon, label, hint · compact: icon, label · list: a row). */
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
	$tile = static function (string $key, array $t, string $size) use ($actionHtml): string {
		if ($size === 'compact' && !empty($t['badge'])) $t['hint'] = trim($t['hint'] . ' · ' . $t['badge'][0], ' ·');
		$act = $t['action'] ?? null;
		$arrow = $t['newtab'] || $t['post'] ? 'bi-box-arrow-up-right' : ($t['proc'] ? 'bi-play-fill' : 'bi-chevron-right');
		$arrowHtml = $act ? '' : '<i class="bi ' . $arrow . ' text-body-secondary"></i>';     // the button takes the place of ">"
		$color = $t['color'] !== '' ? ' data-nu-color="' . h($t['color']) . '"' : '';
		$bd = $t['badge'] ?? null;                           // status of a tool (version, sample data, System status – 0.8.316)
		$badge = $bd ? ' <span class="badge text-bg-' . h((string)$bd[1]) . ' ms-1 align-middle nuHomeBadge">' . h((string)$bd[0]) . '</span>' : '';
		$body = match ($size) {
			'list' => '<span class="nu-tile-icon nuHomeIcon"' . $color . '><i class="bi ' . h($t['icon']) . '"></i></span><span class="flex-grow-1"><span class="d-block text-body">' . h($t['label']) . $badge . '</span>'
				. ($t['hint'] !== '' ? '<span class="d-block small text-body-secondary">' . h($t['hint']) . '</span>' : '') . '</span>' . $arrowHtml,
			'compact' => '<div class="card-body d-flex align-items-center gap-2 py-2"><span class="nu-tile-icon nuHomeIcon nuHomeIconSm"' . $color . '><i class="bi ' . h($t['icon']) . '"></i></span>'
				. '<span class="flex-grow-1 text-body text-truncate">' . h($t['label']) . '</span>' . ($bd && $bd[1] === 'warning' ? '<span class="badge text-bg-warning nuHomeBadge">!</span>' : '') . '</div>',
			default => '<div class="card-body d-flex align-items-center gap-3"><span class="nu-tile-icon nuHomeIcon"' . $color . '><i class="bi ' . h($t['icon']) . '"></i></span><div class="flex-grow-1">'
				. '<div class="fs-5 fw-normal lh-sm text-body">' . h($t['label']) . $badge . '</div>' . ($t['hint'] !== '' ? '<div class="small text-body-secondary mt-1">' . h($t['hint']) . '</div>' : '') . '</div>'
				. $arrowHtml . '</div>',
		};
		// compact tiles show no description (and may cut the name): a tooltip with name and description on mouse over (desktop) or a long tap (phones) shows it – assets/js/nuhome.js (0.8.355)
		$attrs = ' data-nu-tile="' . h($key) . '"' . ($size === 'compact' ? ' data-nu-hint="' . h($t['label'] . ($t['hint'] !== '' ? ' – ' . $t['hint'] : '')) . '"' : '') . (!empty($t['fav']) ? ' data-nu-fav="1"' : '');
		if ($act && $t['post']) {
			// POST tile (database tool) with ▾: the form button and the menu side by side (0.8.282)
			$form = static fn(string $cls) => '<form method="post" action="' . h(nu_url('vendor')) . '" target="_blank" class="' . ($size === 'list' ? 'flex-grow-1' : 'h-100') . '">' . nu_csrf_field()
				. '<input type="hidden" name="app" value="' . h($t['post']) . '"><button type="submit" class="' . $cls . '"' . $attrs . '>' . $body . '</button></form>';
			if ($size === 'list') {
				return '<div class="list-group-item d-flex align-items-center nuHomeTileWrap nuHomeListRow">' . $form('d-flex align-items-center gap-3 w-100 text-start border-0 bg-transparent nuHomeListLink nuHomeTile') . $actionHtml($act) . '</div>';
			}
			return '<div class="h-100 nuHomeTileWrap' . ($size === 'compact' ? ' nuHomeCompact' : '') . '">' . $form('card nu-tile h-100 w-100 text-start p-0 shadow-sm nuHomeTile nuHomeHasAction') . $actionHtml($act) . '</div>';
		}
		if ($act) {
			// link and button side by side in one wrapper (a button cannot sit inside a link)
			if ($size === 'list') {
				return '<div class="list-group-item d-flex align-items-center nuHomeTileWrap nuHomeListRow"><a class="d-flex align-items-center gap-3 flex-grow-1 text-decoration-none nuHomeListLink nuHomeTile" href="'
					. h((string)$t['href']) . '"' . $attrs . '>' . $body . '</a>' . $actionHtml($act) . '</div>';
			}
			return '<div class="h-100 nuHomeTileWrap' . ($size === 'compact' ? ' nuHomeCompact' : '') . '"><a class="card nu-tile h-100 text-decoration-none shadow-sm nuHomeTile nuHomeHasAction" href="'
				. h((string)$t['href']) . '"' . $attrs . '>' . $body . '</a>' . $actionHtml($act) . '</div>';
		}
		$cls = $size === 'list' ? 'list-group-item list-group-item-action d-flex align-items-center gap-3 nuHomeTile' : 'card nu-tile h-100 text-decoration-none shadow-sm nuHomeTile';
		if ($t['post']) {
			return '<form method="post" action="' . h(nu_url('vendor')) . '" target="_blank" class="' . ($size === 'list' ? '' : 'h-100') . '">' . nu_csrf_field() . '<input type="hidden" name="app" value="' . h($t['post']) . '">'
				. '<button type="submit" class="' . $cls . ' w-100 text-start' . ($size === 'list' ? '' : ' p-0') . '"' . $attrs . '>' . $body . '</button></form>';
		}
		if ($t['proc']) {
			return '<button type="button" class="' . $cls . ' w-100 text-start' . ($size === 'list' ? '' : ' p-0') . ' nuHomeProc"' . $attrs . ' data-nu-proc="' . h($t['proc']) . '" data-nu-run="' . h($t['run']) . '">' . $body . '</button>';
		}
		return '<a class="' . $cls . '" href="' . h((string)$t['href']) . '"' . ($t['newtab'] ? ' target="_blank" rel="noopener"' : '') . $attrs . '>' . $body . '</a>';
	};
	$tileOut = $tile;
	$groups = [];
	foreach ($layout['groups'] as $g) {
		$items = [];
		foreach ($g['tiles'] as $k) if (NuHome::visible($k, $layout) && ($r = NuHome::resolve($k, $layout)) !== null) $items[$k] = $r + ['fav' => in_array($k, (array)($layout['fav'] ?? []), true)];
		if ($items) $groups[] = ['id' => $g['id'], 'title' => $g['title'], 'tab' => $g['tab'] ?? '', 'items' => $items];
	}
	$size = $layout['size'];
	$col = $size === 'compact' ? 'col-6 col-md-4 col-xl-3' : 'col-sm-6 col-lg-4';
	$tilesOf = static function (array $items) use ($tileOut, $size, $col): string {
		if (in_array($size, ['list', 'listwide', 'list2'], true)) return '<div class="list-group shadow-sm nuHomeList' . ['list' => '', 'listwide' => ' nuHomeListWide', 'list2' => ' nuHomeListWide nuHomeList2'][$size] . '">' . implode('', array_map(static fn($k, $t) => $tileOut((string)$k, $t, 'list'), array_keys($items), $items)) . '</div>';
		return '<div class="row g-3">' . implode('', array_map(static fn($k, $t) => '<div class="' . $col . '">' . $tileOut((string)$k, $t, $size) . '</div>', array_keys($items), $items)) . '</div>';
	};
	?>
<?php // grid (nuapp.css): search on top; Customize at the bottom left on phones, at the top right next to the search on wider screens (0.8.320 / 0.8.321) ?>
<div class="nuHomeWrap">
<div class="nuHomeSearchRow mb-3" role="search">
	<div class="input-group nuHomeSearchBox">
		<span class="input-group-text"><i class="bi bi-search" aria-hidden="true"></i></span>
		<input type="search" class="form-control" id="nuHomeSearch" placeholder="<?= h(t('search_ph')) ?>" aria-label="<?= h(t('home_search')) ?>" autocomplete="off" spellcheck="false">
		<?php // favourites only (0.8.353): tiles marked ☆ in Customize; the choice is kept per login
		$favCount = 0; foreach ($groups as $g) foreach ($g['items'] as $it) if (!empty($it['fav'])) $favCount++;
		$favOn = $favCount > 0 && NuHome::favOnly(); $favTip = $favCount ? t('home_fav_only') : t('home_fav_none'); ?>
		<button type="button" class="btn btn-outline-secondary nuHomeFavBtn<?= $favOn ? ' active' : '' ?>" id="nuHomeFavOnly" aria-pressed="<?= $favOn ? 'true' : 'false' ?>"<?= $favCount ? '' : ' disabled' ?>
			title="<?= h($favTip) ?>" aria-label="<?= h($favTip) ?>"><i class="bi <?= $favOn ? 'bi-star-fill' : 'bi-star' ?>"></i><span class="d-none d-sm-inline ms-1"><?= h(t('home_fav')) ?></span></button>
	</div>
	<div class="small text-body-secondary mt-2" id="nuHomeSearchNone" hidden><?= h(t('home_search_none')) ?></div>
	<div class="mt-3" id="nuHomeSearchMore" hidden><h2 class="nuHomeGroupTitle"><?= h(t('home_search_more')) ?></h2><div class="list-group shadow-sm nuHomeList nuHomeSearchList"></div></div>
</div>
<div id="nuHomeView"<?= $favOn ? ' class="nuHomeFavOnly"' : '' ?>>
	<?php if (!$groups): ?>
		<div class="alert alert-info"><?= h(t('home_all_hidden')) ?></div>
	<?php elseif ($layout['display'] === 'tabs' && count($tabList = NuHome::tabsOf($layout, $groups)) > 1): ?>
		<ul class="nav nav-tabs nuHomeTabs" role="tablist">
			<?php foreach ($tabList as $i => $tb): ?>
				<li class="nav-item" role="presentation"><button class="nav-link<?= $i === 0 ? ' active' : '' ?>" id="nuHomeTab_<?= h($tb['id']) ?>" data-bs-toggle="tab" data-bs-target="#nuHomePane_<?= h($tb['id']) ?>"
					type="button" role="tab" aria-controls="nuHomePane_<?= h($tb['id']) ?>" aria-selected="<?= $i === 0 ? 'true' : 'false' ?>"><?= h($tb['title'] !== '' ? $tb['title'] : t('home_group_untitled')) ?></button></li>
			<?php endforeach; ?>
		</ul>
		<div class="tab-content pt-3 mb-4">
			<?php foreach ($tabList as $i => $tb): ?>
				<div class="tab-pane fade<?= $i === 0 ? ' show active' : '' ?>" id="nuHomePane_<?= h($tb['id']) ?>" role="tabpanel" aria-labelledby="nuHomeTab_<?= h($tb['id']) ?>">
					<?php foreach ($tb['groups'] as $g): ?>
						<section class="nuHomeGroup mb-4">
							<?php if (empty($g['plain']) && $g['title'] !== ''): ?><h2 class="nuHomeGroupTitle"><?= h($g['title']) ?></h2><?php endif; ?>
							<?= $tilesOf($g['items']) ?>
						</section>
					<?php endforeach; ?>
				</div>
			<?php endforeach; ?>
		</div>
	<?php else: ?>
		<?php foreach ($groups as $g): ?>
			<section class="nuHomeGroup mb-4" aria-label="<?= h($g['title'] !== '' ? $g['title'] : t('home_group_untitled')) ?>">
				<?php if ($g['title'] !== ''): ?><h2 class="nuHomeGroupTitle"><?= h($g['title']) ?></h2><?php endif; ?>
				<?= $tilesOf($g['items']) ?>
			</section>
		<?php endforeach; ?>
	<?php endif; ?>
</div>
<div id="nuHomeEdit" hidden></div>
<?php // Customize (current layout editable) and the ▾ menu: saved layouts (0.8.333) and – when editable – the tile size
	$hlCur = NuHome::current(); $hlAll = NuHome::layouts(); $hlDef = NuListView::listDefault(NuHome::LIST); $hlManage = NuHome::canManage(); ?>
<div class="d-flex justify-content-start justify-content-md-end mt-2 mt-md-0 mb-3 nuHomeCustomizeBar">
	<div class="btn-group dropup" id="nuHomeCustomizeGroup">
		<?php if ($canLayout): ?><button type="button" class="btn btn-sm btn-outline-secondary" id="nuHomeCustomize"><i class="bi bi-pencil me-1"></i><?= h(t('home_customize')) ?></button><?php endif; ?>
		<button type="button" class="btn btn-sm btn-outline-secondary dropdown-toggle<?= $canLayout ? ' dropdown-toggle-split' : '' ?>" id="nuHomeSizeMenu" data-bs-toggle="dropdown" aria-expanded="false"
			aria-label="<?= h(t('home_layouts')) ?>" title="<?= h(t('home_layouts') . ': ' . $hlCur['name']) ?>"><?php if (!$canLayout): ?><i class="bi bi-layout-text-window me-1"></i><?= h($hlCur['name']) ?><?php endif; ?></button>
		<ul class="dropdown-menu dropdown-menu-md-end nuHomeLayoutMenu" aria-labelledby="nuHomeSizeMenu">
			<li><h6 class="dropdown-header"><?= h(t('home_layouts')) ?></h6></li>
			<?php foreach ($hlAll as $hl): $on = $hl['id'] === $hlCur['id']; ?>
				<li><button type="button" class="dropdown-item d-flex align-items-center<?= $on ? ' active' : '' ?>" data-nu-layout="<?= h($hl['id']) ?>"<?= $on ? ' aria-current="true"' : '' ?>>
					<i class="bi <?= $hl['mine'] ? 'bi-person' : 'bi-people' ?> me-2" aria-hidden="true"></i><span class="flex-grow-1"><?= h($hl['name']) ?></span>
					<?php if ($hl['id'] === $hlDef || ($hl['id'] === 'default' && $hlDef === 'default')): ?><i class="bi bi-pin-angle-fill ms-2" title="<?= h(t('home_layout_is_default')) ?>"></i><?php endif; ?>
					<?php if ($hl['roles']): ?><i class="bi bi-person-badge ms-2" title="<?= h(t('home_layout_limited')) ?>"></i><?php endif; ?>
					<?php if ($on): ?><i class="bi bi-check-lg ms-3" aria-hidden="true"></i><?php endif; ?></button></li>
			<?php endforeach; ?>
			<li><hr class="dropdown-divider"></li>
			<li><button type="button" class="dropdown-item" data-nu-layout-act="saveas"><i class="bi bi-plus-lg me-2"></i><?= h(t('home_layout_saveas')) ?></button></li>
			<?php if ($hlCur['id'] !== 'default' && $hlCur['editable']): ?>
				<li><button type="button" class="dropdown-item" data-nu-layout-act="meta"><i class="bi bi-pencil-square me-2"></i><?= h(t('home_layout_meta')) ?></button></li>
				<?php if (!($hlCur['scope'] === 'shared' && $hlCur['id'] === $hlDef)): ?><li><button type="button" class="dropdown-item text-danger" data-nu-layout-act="delete"><i class="bi bi-trash me-2"></i><?= h(t('home_layout_delete')) ?></button></li><?php endif; ?>
			<?php endif; ?>
			<?php if ($hlManage && $hlCur['scope'] === 'shared' && $hlCur['id'] !== $hlDef): ?>
				<li><button type="button" class="dropdown-item" data-nu-layout-act="default"><i class="bi bi-pin-angle me-2"></i><?= h(t('home_layout_make_default')) ?></button></li>
			<?php endif; ?>
			<?php if ($canLayout): ?>
			<li><hr class="dropdown-divider"></li>
			<li><h6 class="dropdown-header"><?= h(t('home_size')) ?></h6></li>
			<?php foreach (['large' => 'bi-grid-1x2', 'compact' => 'bi-grid-3x3-gap', 'list' => 'bi-list-ul', 'listwide' => 'bi-view-list', 'list2' => 'bi-layout-split'] as $sz => $szIcon): $on = $layout['size'] === $sz; ?>
				<li><button type="button" class="dropdown-item d-flex align-items-center<?= $on ? ' active' : '' ?>" data-nu-size="<?= h($sz) ?>"<?= $on ? ' aria-current="true"' : '' ?>>
					<i class="bi <?= $szIcon ?> me-2" aria-hidden="true"></i><span class="flex-grow-1"><?= h(t('home_size_' . $sz)) ?></span>
					<?php if ($on): ?><i class="bi bi-check-lg ms-3" aria-hidden="true"></i><?php endif; ?></button></li>
			<?php endforeach; ?>
			<?php endif; ?>
		</ul>
	</div>
</div>
</div>
<?php endif; ?>

<?php if (!$u['globeadmin']): ?>
<div class="card shadow-sm">
	<div class="card-header d-flex justify-content-between align-items-center">
		<span class="fw-semibold"><i class="bi bi-ui-checks-grid me-2"></i><?= h(t('home_my_forms')) ?></span>
		<span class="badge text-bg-secondary"><?= count($forms) ?></span>
	</div>
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
								<a class="fw-semibold text-decoration-none" href="<?= h(nu_url('form', ['f' => $fid])) ?>"><?= h(nu_form_label($fid)) ?></a>
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
</div>
<?php endif; ?>
<?php
$foot = $homeDash ? NuDashboard::scriptTag() : '';
if ($admin) {
	$cat = [];
	foreach (NuHome::catalog() as $k => [$route, $icon, $label, $hint]) $cat[$k] = ['icon' => $icon, 'label' => $label, 'hint' => $hint];
	$catMain = NuHome::catalog();
	foreach (NuHome::subTiles() as $k => [$sUrl, $sIcon, $sLabel, $sParent]) $cat[$k] ??= ['icon' => $sIcon, 'label' => $sLabel, 'hint' => $catMain[$sParent][2] ?? '', 'sub' => true];
	$procs = [];
	try {
		foreach ($db->all('SELECT zzzzsys_php_id, sph_code, sph_description FROM zzzzsys_php WHERE ' . NuProcedure::listCondition() . ' ORDER BY sph_code') as $p) {
			$procs[] = ['id' => (string)$p['zzzzsys_php_id'], 'label' => $p['sph_code'] . ($p['sph_description'] ? ' – ' . $p['sph_description'] : '')];
		}
	} catch (Throwable) { /* no procedures table */ }
	$formList = [];
	foreach (nu_forms() as $id => $f) if ($f['type'] !== 'subform') $formList[] = ['id' => (string)$id, 'label' => $f['code'] . ($f['description'] !== '' ? ' – ' . $f['description'] : '')];
	$data = [
		'url' => nu_url('home', ['tiles' => '1']), 'csrf' => nu_csrf_token(), 'layout' => $layout,
		'saved' => ['current' => ['id' => $hlCur['id'], 'name' => $hlCur['name'], 'scope' => $hlCur['scope'], 'roles' => $hlCur['roles']], 'manage' => $hlManage,
			'roles' => $hlManage ? array_map(static fn($r) => ['id' => $r['id'], 'name' => $r['name']], NuAdmin::roles()) : []], 'default' => NuHome::defaultLayout(), 'catalog' => $cat,
		'forms' => $formList, 'pages' => NuHome::PAGES, 'procedures' => $procs, 'icons' => NuHome::ICONS, 'procUrl' => nu_url('procedures', ['id' => '__ID__']),
		't' => array_combine($k = ['home_customize', 'home_display', 'home_display_groups', 'home_display_tabs', 'home_size', 'home_size_large', 'home_size_compact', 'home_fav_add', 'home_unfav', 'home_fav', 'home_fav_only', 'home_fav_none_edit', 'home_size_list', 'home_size_listwide', 'home_size_list2',
			'home_add_group', 'home_add_tile', 'home_reset', 'home_kind_builtin', 'home_tile_builtin', 'home_builtin_tiles', 'home_builtin_none', 'home_pinned', 'home_unpinned', 'home_reset_confirm', 'home_hidden', 'home_hidden_hint', 'home_group_title', 'home_group_new', 'home_group_untitled',
			'home_group_delete', 'home_move_up', 'home_move_down', 'home_move_left', 'home_move_right', 'home_hide', 'home_show', 'home_edit_tile', 'home_delete_tile',
			'home_tile_new', 'home_tile_label', 'home_tile_hint', 'home_tile_icon', 'home_tile_color', 'home_tile_no_color', 'home_tile_kind', 'home_kind_form', 'home_kind_page',
			'home_kind_procedure', 'home_kind_url', 'home_tile_form', 'home_tile_record', 'home_tile_record_hint', 'home_tile_page', 'home_tile_procedure', 'home_tile_run',
			'home_run_window', 'home_run_hidden', 'home_tile_url', 'home_tile_url_hint', 'home_tile_newtab', 'home_drop_here', 'home_edit_hint', 'home_saved_err',
			'home_proc_done', 'home_proc_failed', 'home_tabs', 'home_add_tab', 'home_tab_new', 'home_tab_title', 'home_tab_up', 'home_tab_down',
			'home_tab_delete', 'home_tab_delete_confirm', 'home_tabs_hint', 'home_layout_saveas', 'home_layout_meta', 'home_layout_name', 'home_layout_scope', 'home_layout_scope_user',
			'home_layout_scope_shared', 'home_layout_roles', 'home_layout_roles_all', 'home_layout_delete_confirm', 'home_layout_copy', 'home_group_tab', 'home_running', 'home_done', 'home_editing', 'home_discard_confirm', 'home_drag', 'home_delete_tile_confirm', 'home_group_delete_confirm', 'save', 'cancel', 'ok', 'delete', 'close'], array_map('t', $k)),
	];
	$foot = '<script type="application/json" id="nuHomeData">' . json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>'
		. '<script src="' . nu_asset('assets/js/nuhome.js') . '"></script>';
}
nu_layout_end($foot);
