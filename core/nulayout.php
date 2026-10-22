<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * nuBuilder's $nuConfigTitle (nuconfig.php or Setup → Settings) – browser tab and login page; 'nuBuilder Next' when not set (0.8.248).
 * Never fails: also used on error pages and in the installer (no nuconfig.php yet).
 */
function nu_app_title(bool $configuredOnly = false): string
{
	static $v = null, $busy = false;
	if ($v === null) {
		if ($busy) return $configuredOnly ? '' : 'nuBuilder Next';
		$busy = true;
		try {
			$v = is_file(nu_config_path()) ? trim((string)(nu_cfg('nuConfigTitle') ?? '')) : '';
		} catch (Throwable) {
			$v = '';
		}
		$busy = false;
	}
	return $v !== '' || $configuredOnly ? $v : 'nuBuilder Next';
}

function nu_head(string $title, string $extraHead = ''): void
{
	$env = NuBranding::get()['env_label'];             // branding (0.8.297): environment label in the tab title and as a coloured line
	?><!doctype html>
<html lang="<?= h(nu_lang_choice()) ?>"<?= nu_lang_rtl() ? ' dir="rtl"' : '' ?> data-bs-theme="light"<?= $env !== '' ? ' class="nu-env"' : '' ?>>
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title><?= $env !== '' ? '[' . h($env) . '] ' : '' ?><?= h($title) ?> · <?= h(nu_app_title()) ?></title>
	<script src="<?= nu_asset('assets/js/nutheme.js') ?>"></script>
	<link rel="stylesheet" href="<?= nu_asset(NU_BS . (nu_lang_rtl() ? '/css/bootstrap.rtl.min.css' : '/css/bootstrap.min.css')) ?>">
	<link rel="stylesheet" href="<?= nu_asset(NU_BI . '/bootstrap-icons.min.css') ?>">
	<link rel="stylesheet" href="<?= nu_asset('assets/css/nuapp.css') ?>">
	<?= NuBranding::headTags() ?>
	<?= $extraHead ?>
</head>
<?php
}

/** Query string of the current request, used to come back after switching language. */
/** The nuBuilder Next logo (icon + name): a light and a dark version, CSS shows the one of the current theme. $size: '' (top bar) | 'lg' (sign-in pages). */
function nu_brand_logo(string $size = ''): string
{
	if (($own = NuBranding::logo($size)) !== '') return $own;      // own logo of this installation (Admin tools → Branding, 0.8.297)
	$cls = 'nu-brand' . ($size === 'lg' ? ' nu-brand-lg' : '');
	return '<span class="' . $cls . '"><img class="nu-brand-light" src="' . h(nu_asset('assets/img/nunext-logo.png')) . '" alt="nuBuilder Next" width="446" height="96">'
		. '<img class="nu-brand-dark" src="' . h(nu_asset('assets/img/nunext-logo-dark.png')) . '" alt="nuBuilder Next" width="446" height="96"></span>'   // only one is shown (0.8.368)
		. ($size === '' ? NuBranding::titleOnly() : '');
}

function nu_return_query(): string
{
	return (string)($_SERVER['QUERY_STRING'] ?? '');
}

function nu_lang_menu(string $btnClass = 'btn btn-sm btn-outline-secondary'): void
{
	$r = nu_return_query();
	?>
	<div class="dropdown">
		<button class="<?= $btnClass ?> dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="<?= h(t('nav_language')) ?>">
			<i class="bi bi-translate"></i> <?= h(strtoupper(nu_lang()) . (nu_lang_variant() !== '' ? '-' . substr(nu_lang_variant(), 3) : '')) ?>
		</button>
		<ul class="dropdown-menu dropdown-menu-end">
			<?php foreach (nu_lang_choices() as $code => $own): ?>
				<li><a class="dropdown-item<?= nu_lang_choice() === $code ? ' active' : '' ?>" href="<?= h(nu_url('lang', ['l' => $code, 'r' => $r])) ?>" lang="<?= h($code) ?>"><?= h($own) ?></a></li>
			<?php endforeach; ?>
		</ul>
	</div>
	<?php
}

function nu_theme_menu(string $btnClass = 'btn btn-sm btn-outline-secondary'): void
{
	?>
	<div class="dropdown">
		<button class="<?= $btnClass ?> dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="<?= h(t('nav_theme')) ?>">
			<i class="bi bi-circle-half js-theme-icon"></i>
		</button>
		<ul class="dropdown-menu dropdown-menu-end">
			<li><button class="dropdown-item" type="button" data-theme-set="light"><i class="bi bi-sun me-2"></i><?= h(t('theme_light')) ?></button></li>
			<li><button class="dropdown-item" type="button" data-theme-set="dark"><i class="bi bi-moon-stars me-2"></i><?= h(t('theme_dark')) ?></button></li>
			<li><button class="dropdown-item" type="button" data-theme-set="auto"><i class="bi bi-circle-half me-2"></i><?= h(t('theme_auto')) ?></button></li>
		</ul>
	</div>
	<?php
}

function nu_layout_start(string $title, string $active = '', array $opt = []): void
{
	$u = NuAuth::user();
	nu_head($title, $opt['head'] ?? '');
	if (!empty($opt['bare'])) {
		echo '<body class="nu-app nu-bare' . h(' ' . ($opt['body_class'] ?? '')) . '"><main id="main" class="' . h($opt['container'] ?? 'container-fluid') . ' py-2">';
		nu_render_flashes();
		return;
	}
	$nav = [
		'home'   => ['bi-house', t('nav_home'), true],
		'users'  => ['bi-people', t('nav_users'), NuAcl::can(NuAcl::FORM_USER)],
		'access' => ['bi-shield-lock', t('nav_access'), NuAcl::can(NuAcl::FORM_ACCESS)],
		'forms'  => ['bi-ui-checks-grid', t('nav_forms'), NuAcl::can(NuAcl::FORM_FORM)],
		'log'    => ['bi-journal-text', t('nav_log'), NuAcl::can(NuAcl::FORM_LOG) || NuAcl::can(NuAcl::FORM_DEBUG), NuAcl::can(NuAcl::FORM_DEBUG) ? nu_url('debug') : nu_url('log')],
		'email'  => ['bi-envelope', t('nav_email'), NuAcl::can(NuAcl::FORM_SETUP) || NuAcl::can(NuAcl::FORM_EMAILLOG) || NuAcl::can(NuAcl::FORM_EMAILTEMPLATE)],
		'notes'  => ['bi-journal-richtext', t('nav_notes'), isset(nu_forms()['nunotes']) && NuAcl::can('nunotes'), nu_url('form', ['f' => 'nunotes'])],
	];
	// globeadmin: the start page shows all areas as tiles – no links in the bar
	if ($u['globeadmin']) $nav = [];
	// "Start" only next to other links – alone it repeats the logo, which also opens the start page
	$nav = array_filter($nav, static fn($n) => (bool)$n[2]);
	if (array_keys($nav) === ['home']) $nav = [];
	?>
<body class="nu-app<?= h(' ' . ($opt['body_class'] ?? '')) ?><?= NuAdmin::$viewOnly ? ' nuViewOnly' : '' ?>"<?= NuAdmin::$viewOnly ? ' data-nu-view-only="' . h(t('adm_view_only')) . '"' : '' ?>>
<a class="visually-hidden-focusable" href="#main"><?= h(t('skip_to_content')) ?></a>
<nav class="navbar navbar-expand-md nu-navbar border-bottom sticky-top<?= NuImpersonate::active() ? ' flex-wrap' : '' ?>">
	<?= NuImpersonate::banner() ?>
	<div class="container-xl">
		<a class="navbar-brand d-flex align-items-center gap-2" href="<?= h(nu_url('home')) ?>">
			<?= nu_brand_logo() ?>
		</a>
		<?= NuBranding::envBadge('me-auto me-md-3') ?>
		<button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#nuNav" aria-controls="nuNav" aria-expanded="false" aria-label="Menu">
			<span class="navbar-toggler-icon"></span>
		</button>
		<div class="collapse navbar-collapse" id="nuNav">
			<ul class="navbar-nav me-auto">
				<?php foreach ($nav as $page => $n): [$icon, $label, $show] = $n; if (!$show) continue; ?>
					<li class="nav-item">
						<a class="nav-link<?= $active === $page ? ' active' : '' ?>"<?= $active === $page ? ' aria-current="page"' : '' ?> href="<?= h($n[3] ?? nu_url($page)) ?>">
							<i class="bi <?= $icon ?> me-1"></i><?= h($label) ?>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>
			<div class="d-flex align-items-center gap-2 py-2 py-md-0">
				<?php if (!empty($u['demo'])): // demo mode (core/nudemo.php) ?>
					<span class="badge nuDemoBadge" title="<?= h(t('demo_badge_hint')) ?>"><i class="bi bi-eye me-1"></i><?= h(t('demo_badge')) ?></span>
				<?php endif; ?>
				<?php if (NuAdmin::any()): // search, also Ctrl+K (assets/js/nupalette.js, 0.8.321) – also for admin roles (0.8.323)
					$palT = []; foreach (['pal_title', 'pal_placeholder', 'pal_none', 'pal_hint', 'pal_recent', 'pal_keys', 'pal_loading', 'pal_failed'] as $pk) $palT[$pk] = t($pk); ?>
					<button type="button" class="btn btn-sm btn-outline-secondary" id="nuPaletteBtn" title="<?= h(t('pal_title')) ?> (Ctrl+K)" aria-label="<?= h(t('pal_title')) ?>"
						data-url="<?= h(nu_url('palette')) ?>" data-vendor="<?= h(nu_url('vendor')) ?>" data-csrf="<?= h(nu_csrf_token()) ?>" data-t="<?= h(json_encode($palT, JSON_UNESCAPED_UNICODE)) ?>"><i class="bi bi-search"></i></button>
				<?php endif; ?>
				<?php nu_bell(); ?>
				<?php nu_theme_menu(); ?>
				<?php nu_lang_menu(); ?>
				<div class="dropdown">
					<button class="btn btn-sm btn-outline-secondary dropdown-toggle d-flex align-items-center gap-2" type="button" data-bs-toggle="dropdown" aria-expanded="false">
						<i class="bi <?= $u['globeadmin'] ? 'bi-person-gear' : 'bi-person-circle' ?>"></i>
						<span class="text-truncate nu-user-name"><?= h($u['display']) ?></span>
					</button>
					<ul class="dropdown-menu dropdown-menu-end">
						<li><h6 class="dropdown-header"><?= h($u['login']) ?></h6></li>
						<?php if (NuImpersonate::active()): ?>
							<li>
								<form method="post" action="<?= h(nu_url('impersonate')) ?>">
									<?= nu_csrf_field() ?><input type="hidden" name="action" value="stop">
									<button class="dropdown-item" type="submit"><i class="bi bi-arrow-return-left me-2"></i><?= h(t('imp_back')) ?></button>
								</form>
							</li>
						<?php else: ?>
						<li><a class="dropdown-item" href="<?= h(nu_url('security')) ?>"><i class="bi bi-shield-lock me-2"></i><?= h(t('sec_title')) ?></a></li>
						<?php endif; ?>
						<?php if ($u['globeadmin'] && NuHome::globeadminForm() !== null): // $nuConfigGlobeadminHome opens a form – the tile page stays reachable here ?>
							<li><a class="dropdown-item" href="<?= h(nu_url('home', ['tiles' => '1'])) ?>"><i class="bi bi-grid me-2"></i><?= h(t('home_tiles_link')) ?></a></li>
						<?php endif; ?>
						<li><a class="dropdown-item" href="<?= h(nu_url('help')) ?>"><i class="bi bi-question-circle me-2"></i><?= h(t('help_title')) ?></a></li>
						<?php if (NuTutorial::allowed()): ?><li><a class="dropdown-item" href="<?= h(nu_url('tutorial')) ?>"><i class="bi bi-mortarboard me-2"></i><?= h(t('tut_title')) ?></a></li><?php endif; ?>
						<?php if (!$u['globeadmin'] && $u['via'] !== 'nubuilder' && !NuImpersonate::active()): ?>
							<li><a class="dropdown-item" href="<?= h(nu_url('password')) ?>"><i class="bi bi-key me-2"></i><?= h(t('nav_change_password')) ?></a></li>
						<?php endif; ?>
						<?php if (NuAdmin::any() && (nu_db()->type === 'mysql' || NuVendor::available('ADM'))): // admin roles (0.8.323): only what the role allows ?>
							<li><hr class="dropdown-divider"></li>
							<?php if (NuAdmin::can('status')): ?><li><a class="dropdown-item" href="<?= h(nu_url('vendor')) ?>"><i class="bi bi-activity me-2"></i><?= h(t('status_title')) ?></a></li><?php endif; ?>
							<?php // database tools (0.8.282): phpMyAdmin / Adminer, the primary one first ($nuConfigPrimaryDatabaseManagement); then File Manager
							$vItems = [];
							if (NuAdmin::can('dbtool', 'change')) {
								foreach (NuVendor::dbTools() as $va) $vItems[] = [$va, 'bi-database', t('vendor_db_with', NuVendor::DB_TOOLS[$va]), true];
								if (!$vItems) $vItems[] = ['DB', 'bi-database', t('vendor_pma'), false];
							}
							if (nu_db()->type === 'mysql' && NuAdmin::can('filemanager', 'change')) $vItems[] = ['TFM', 'bi-folder2-open', t('vendor_tfm'), NuVendor::available('TFM')];
							foreach ($vItems as [$va, $vi, $vl, $vok]): ?>
								<li>
									<?php if ($vok): ?>
										<form method="post" action="<?= h(nu_url('vendor')) ?>" target="_blank">
											<?= nu_csrf_field() ?><input type="hidden" name="app" value="<?= $va ?>">
											<button class="dropdown-item" type="submit"><i class="bi <?= $vi ?> me-2"></i><?= h($vl) ?></button>
										</form>
									<?php else: ?>
										<a class="dropdown-item text-body-secondary" href="<?= h(nu_url('vendor')) ?>"><i class="bi <?= $vi ?> me-2"></i><?= h($vl) ?></a>
									<?php endif; ?>
								</li>
							<?php endforeach; ?>
						<?php endif; ?>
						<?php if (NuAdmin::can('update')): // version and change history (Update page) ?>
							<li><hr class="dropdown-divider"></li>
							<li><a class="dropdown-item small text-body-secondary" href="<?= h(nu_url('update')) ?>#updHistory" id="nuVersionLink"><i class="bi bi-clock-history me-2"></i><?= h(t('upd_version_link', NU_VERSION)) ?></a></li>
						<?php endif; ?>
						<?php if (NuSharedLogin::url() !== ''): ?>
							<li><a class="dropdown-item" href="<?= h(NuSharedLogin::url()) ?>"><i class="bi bi-box-arrow-up-right me-2"></i>nuBuilder</a></li>
						<?php endif; ?>
						<li><hr class="dropdown-divider"></li>
						<li>
							<form method="post" action="<?= h(nu_url('logout')) ?>">
								<?= nu_csrf_field() ?>
								<button class="dropdown-item text-danger" type="submit"><i class="bi bi-box-arrow-right me-2"></i><?= h(t('nav_logout')) ?></button>
							</form>
						</li>
					</ul>
				</div>
			</div>
		</div>
	</div>
</nav>
<main id="main" class="<?= h($opt['container'] ?? 'container-xl') ?> py-4">
	<?php nu_render_flashes(); ?>
<?php
}

function nu_render_flashes(): void
{
	foreach (nu_flashes() as [$type, $msg]) {
		$icon = ['success' => 'bi-check-circle', 'danger' => 'bi-exclamation-octagon', 'warning' => 'bi-exclamation-triangle'][$type] ?? 'bi-info-circle';
		echo '<div class="alert alert-' . h($type) . ' alert-dismissible fade show d-flex align-items-start gap-2" role="alert">'
			. '<i class="bi ' . $icon . ' mt-1"></i><div>' . h($msg) . '</div>'
			. '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="' . h(t('close')) . '"></button></div>';
	}
}

function nu_layout_end(string $extraBody = '', bool $bare = false): void
{
	?>
</main>

<div class="modal fade" id="nuLeave" tabindex="-1" aria-labelledby="nuLeaveTitle" aria-hidden="true" data-text-nosave="<?= h(t('leave_text_nosave')) ?>" data-text="<?= h(t('leave_text')) ?>">
	<div class="modal-dialog modal-dialog-centered">
		<div class="modal-content">
			<div class="modal-header">
				<h1 class="modal-title fs-5 d-flex align-items-center gap-2" id="nuLeaveTitle"><i class="bi bi-exclamation-triangle text-warning"></i><?= h(t('leave_title')) ?></h1>
				<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= h(t('leave_stay')) ?>"></button>
			</div>
			<div class="modal-body js-leave-text"></div>
			<div class="modal-footer">
				<button type="button" class="btn btn-outline-secondary me-auto" data-bs-dismiss="modal" data-nu-leave="stay"><?= h(t('leave_stay')) ?></button>
				<button type="button" class="btn btn-outline-danger" data-nu-leave="discard"><i class="bi bi-x-lg me-1"></i><?= h(t('leave_discard')) ?></button>
				<button type="button" class="btn btn-primary" data-nu-leave="save"><i class="bi bi-check-lg me-1"></i><?= h(t('leave_save')) ?></button>
			</div>
		</div>
	</div>
</div>

<div class="modal fade" id="nuConfirm" tabindex="-1" aria-labelledby="nuConfirmTitle" aria-hidden="true">
	<div class="modal-dialog modal-dialog-centered">
		<div class="modal-content">
			<div class="modal-header">
				<h1 class="modal-title fs-5" id="nuConfirmTitle"><?= h(t('confirm_title')) ?></h1>
				<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= h(t('close')) ?>"></button>
			</div>
			<div class="modal-body js-confirm-text"></div>
			<div class="modal-footer">
				<button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><?= h(t('cancel')) ?></button>
				<button type="button" class="btn btn-primary js-confirm-ok"><?= h(t('ok')) ?></button>
			</div>
		</div>
	</div>
</div>
<script src="<?= nu_asset(NU_BS . '/js/bootstrap.bundle.min.js') ?>"></script>
<script src="<?= nu_asset('assets/js/nubs.js') ?>"></script>
<script src="<?= nu_asset('assets/js/nuapp.js') ?>"></script>
<?php if (!$bare && NuAdmin::any()): ?><script src="<?= nu_asset('assets/js/nupalette.js') ?>"></script><?php endif; ?>
<?php if (!$bare && !empty($GLOBALS['nu_bell_on'])): ?><script src="<?= nu_asset('assets/js/nunotify.js') ?>"></script><?php endif; ?>
<?= $extraBody ?>
</body>
</html>
<?php
}

/**
 * The bell of the notification centre (0.8.357, core/nunotify.php · assets/js/nunotify.js): red badge with the number of unread
 * notifications, the panel is filled by the script (checks every NuNotify::POLL seconds).
 */
function nu_bell(): void
{
	try {
		if (!NuAuth::user() || !NuNotify::ready()) return;
		$s = NuNotify::summary(0);
	} catch (Throwable) {
		return;
	}
	$GLOBALS['nu_bell_on'] = true;
	$keys = ['ntf_title', 'ntf_mark_all', 'ntf_show_all', 'ntf_none', 'ntf_mark_read', 'ntf_mark_unread', 'ntf_hide', 'ntf_open', 'ntf_got_it', 'ntf_new_toast',
		'ntf_manage', 'ntf_must_confirm', 'ntf_pinned', 'ntf_unread_n', 'close'];
	$tx = [];
	foreach ($keys as $k) $tx[$k] = t($k);
	$n = $s['unread'];
	$label = $n ? t('ntf_unread_n', $n) : t('ntf_title');
	?>
				<div class="dropdown nuBell" id="nuBell" data-url="<?= h(nu_url('notifyapi')) ?>" data-page="<?= h(nu_url('notifications')) ?>"
					data-manage="<?= NuNotify::canManage() ? h(nu_url('notifications', ['tab' => 'manage'])) : '' ?>" data-csrf="<?= h(nu_csrf_token()) ?>"
					data-poll="<?= NuNotify::POLL ?>" data-t="<?= h(json_encode($tx, JSON_UNESCAPED_UNICODE)) ?>">
					<button type="button" class="btn btn-sm btn-outline-secondary nuBellBtn<?= $n ? ' nuBellHas' : '' ?>" id="nuBellBtn" data-bs-toggle="dropdown" data-bs-auto-close="outside"
						aria-expanded="false" aria-label="<?= h($label) ?>" title="<?= h($label) ?>"><i class="bi <?= $n ? 'bi-bell-fill' : 'bi-bell' ?>"></i><span class="nuBellBadge"<?= $n ? '' : ' hidden' ?>><?= $n > 99 ? '99+' : $n ?></span></button>
					<div class="dropdown-menu dropdown-menu-end p-0 shadow nuBellPanel" aria-labelledby="nuBellBtn">
						<div class="d-flex align-items-center gap-2 px-3 py-2 border-bottom"><span class="fw-semibold me-auto"><?= h(t('ntf_title')) ?></span>
							<button type="button" class="btn btn-link btn-sm p-0 text-decoration-none nuBellAll"<?= $n ? '' : ' hidden' ?>><?= h(t('ntf_mark_all')) ?></button></div>
						<div class="nuBellList" aria-live="polite"><div class="px-3 py-4 text-center small text-body-secondary">…</div></div>
						<div class="d-flex align-items-center gap-3 px-3 py-2 border-top small"><a class="text-decoration-none" href="<?= h(nu_url('notifications')) ?>"><?= h(t('ntf_show_all')) ?></a>
							<?php if (NuNotify::canManage()): ?><a class="text-decoration-none ms-auto" href="<?= h(nu_url('notifications', ['tab' => 'manage'])) ?>"><i class="bi bi-gear me-1"></i><?= h(t('ntf_manage')) ?></a><?php endif; ?></div>
					</div>
				</div>
	<?php
}

/** Standalone page for errors – needs neither DB nor login. */
function nu_page_minimal(string $title, string $detail = '', bool $withHome = false): void
{
	NuBranding::$off = true;                           // error pages: no database access – the default look
	nu_head($title);
	?>
<body class="nu-center">
<main class="nu-card-narrow">
	<div class="card shadow-sm">
		<div class="card-body p-4">
			<h1 class="h4 d-flex align-items-center gap-2"><i class="bi bi-exclamation-triangle text-warning"></i><?= h($title) ?></h1>
			<?php if ($detail !== ''): ?><p class="text-body-secondary mb-0"><?= h($detail) ?></p><?php endif; ?>
			<?php if ($withHome): ?><a class="btn btn-primary mt-3" href="<?= h(nu_url('home')) ?>"><?= h(t('back_home')) ?></a><?php endif; ?>
		</div>
	</div>
</main>
<script src="<?= nu_asset(NU_BS . '/js/bootstrap.bundle.min.js') ?>"></script>
<script src="<?= nu_asset('assets/js/nubs.js') ?>"></script>
<script src="<?= nu_asset('assets/js/nuapp.js') ?>"></script>
</body>
</html>
<?php
}

// ----------------------------------------------------------------- small UI helpers

/**
 * Footer of a list: total · first / previous / page x of y / next / last · rows per page. Always shown (also with one page)
 * and sticky at the bottom of the window while the list is longer than the screen. $class: extra classes (card-footer in a card).
 */
function nu_list_footer(int $total, int $page, int $per, string $route, array $params, string $class = 'card-footer', string $param = 'pg', string $anchor = ''): void
{
	// $param: name of the page parameter (two lists on one page); $anchor: '#id' the links jump to
	$pages = (int)max(1, ceil($total / max(1, $per)));
	$page  = max(1, min($page, $pages));
	$link  = static fn(int $p) => h(nu_url($route, [$param => $p > 1 ? $p : null] + $params) . $anchor);
	$btn   = static function (bool $on, int $p, string $cls, string $html, string $label) use ($link): string {
		return $on ? '<a class="nuPagerBtn ' . $cls . '" href="' . $link($p) . '" aria-label="' . h($label) . '" title="' . h($label) . '">' . $html . '</a>'
			: '<span class="nuPagerBtn ' . $cls . ' disabled" role="link" aria-disabled="true" aria-label="' . h($label) . '">' . $html . '</span>';   // aria-label needs a role (0.8.368)
	};
	?>
	<div class="nuListFooter <?= h($class) ?>">
		<div class="nuLfTotal"><?= h(t('lf_total')) ?> <b><?= number_format($total, 0, '.', "'") ?></b> <span class="nuLfUnit"><?= h(t($total === 1 ? 'lf_record' : 'lf_records')) ?></span></div>
		<nav class="nuPager" aria-label="<?= h(t('pagination')) ?>">
			<?= $btn($page > 1, 1, 'nuPagerFirst', '<i class="bi bi-chevron-double-left"></i>', t('sc_page_first')) ?>
			<?= $btn($page > 1, $page - 1, 'nuPagerPrev', '<i class="bi bi-chevron-left"></i><span class="nuPagerTxt">' . h(t('prev')) . '</span>', t('prev')) ?>
			<?php if ($pages > 1): // click: enter a page number, Enter jumps there (assets/js/nuapp.js) ?>
				<button type="button" class="nuPagerCur nuPagerJump" aria-current="page" title="<?= h(t('lf_goto')) ?>" data-nu-page="<?= $page ?>" data-nu-pages="<?= $pages ?>"
					data-nu-url="<?= h(nu_url($route, [$param => '__PG__'] + $params) . $anchor) ?>" data-nu-label="<?= h(t('lf_goto')) ?>"><?= h(t('lf_page', $page, $pages)) ?></button>
			<?php else: ?>
				<span class="nuPagerCur" aria-current="page"><?= h(t('lf_page', $page, $pages)) ?></span>
			<?php endif; ?>
			<?= $btn($page < $pages, $page + 1, 'nuPagerNext', '<span class="nuPagerTxt">' . h(t('next')) . '</span><i class="bi bi-chevron-right"></i>', t('next')) ?>
			<?= $btn($page < $pages, $pages, 'nuPagerLast', '<i class="bi bi-chevron-double-right"></i>', t('sc_page_last')) ?>
		</nav>
		<div class="nuLfPer"><?= h(t('lf_per_page', $per)) ?></div>
	</div>
	<?php
}

function nu_pagination(int $total, int $page, int $per, string $route, array $params): void
{
	$pages = (int)max(1, ceil($total / $per));
	if ($pages <= 1) return;
	$link = static fn(int $p) => h(nu_url($route, ['pg' => $p] + $params));
	$from = max(1, $page - 2);
	$to   = min($pages, $page + 2);
	?>
	<nav aria-label="<?= h(t('pagination')) ?>">
		<ul class="pagination pagination-sm mb-0">
			<li class="page-item<?= $page <= 1 ? ' disabled' : '' ?>"><a class="page-link" href="<?= $link($page - 1) ?>" aria-label="<?= h(t('prev')) ?>">&lsaquo;</a></li>
			<?php if ($from > 1): ?><li class="page-item"><a class="page-link" href="<?= $link(1) ?>">1</a></li><?php if ($from > 2): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; endif; ?>
			<?php for ($i = $from; $i <= $to; $i++): ?>
				<li class="page-item<?= $i === $page ? ' active' : '' ?>"><a class="page-link" href="<?= $link($i) ?>"<?= $i === $page ? ' aria-current="page"' : '' ?>><?= $i ?></a></li>
			<?php endfor; ?>
			<?php if ($to < $pages): if ($to < $pages - 1): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; ?><li class="page-item"><a class="page-link" href="<?= $link($pages) ?>"><?= $pages ?></a></li><?php endif; ?>
			<li class="page-item<?= $page >= $pages ? ' disabled' : '' ?>"><a class="page-link" href="<?= $link($page + 1) ?>" aria-label="<?= h(t('next')) ?>">&rsaquo;</a></li>
		</ul>
	</nav>
	<?php
}

/** Sortable column header link. */
/**
 * Search field of a list page (no label, placeholder "Search…"; $hint – what is searched – as tooltip):
 * search button at the right end of the field and, when $filterId is given, the filter
 * button (funnel) right of it that shows / hides the element #$filterId (a Bootstrap .collapse holding the filters).
 * $filtered = a filter is set: the filters start open and the button is blue.
 */
function nu_list_search(string $value, string $hint = '', string $filterId = '', bool $filtered = false): string
{
	$out = '<div class="d-flex gap-2 nuListSearch"><div class="input-group">'
		. '<input type="search" class="form-control" id="q" name="q" value="' . h($value) . '" placeholder="' . h(t('search_ph')) . '" aria-label="' . h(t('search')) . '" data-nu-autofocus'
		. ($hint !== '' ? ' title="' . h($hint) . '"' : '') . '>'
		. '<button class="btn btn-outline-secondary nuSearchBtn" type="submit" title="' . h(t('search')) . '" aria-label="' . h(t('search')) . '"><i class="bi bi-search"></i></button></div>';
	if ($filterId !== '') {
		$out .= '<button class="btn btn-outline-secondary nuSearchBtn nuFilterToggle' . ($filtered ? ' active' : '') . '" type="button" data-bs-toggle="collapse" data-bs-target="#' . h($filterId) . '"'
			. ' aria-expanded="' . ($filtered ? 'true' : 'false') . '" aria-controls="' . h($filterId) . '" title="' . h(t('filter')) . '" aria-label="' . h(t('filter')) . '">'
			. '<i class="bi bi-funnel' . ($filtered ? '-fill' : '') . '"></i></button>';
	}
	return $out . '</div>';
}

/** × (reset) button of a list filter row, shown when a search or filter is set. */
/**
 * Back button of nuBuilder Next's own pages (0.8.256): one step up – the parent page of the breadcrumb, otherwise the start page.
 * $route: the page (default ?p=); $url: an own target (e.g. a list with its search).
 */
function nu_back_button(?string $route = null, ?string $url = null, string $class = ''): string
{
	static $parent = [
		// the tools of the former Admin tools page go back to the start page (0.8.316)
		'adminroles' => 'home',
		'cloner' => 'forms', 'procedures' => 'forms', 'reports' => 'forms', 'prompts' => 'forms', 'formats' => 'forms', 'items' => 'forms',
		'files' => 'forms', 'translations' => 'forms', 'sqlbuilder' => 'reports', 'apiplayground' => 'apiclients', 'permissions' => 'users',
	];
	$route ??= (string)($_GET['p'] ?? '');
	$url ??= nu_url($parent[$route] ?? 'home');
	return '<a class="btn nuBtnAct nuBackBtn' . ($class !== '' ? ' ' . $class : '') . '" href="' . h($url) . '"><i class="bi bi-arrow-left me-1"></i>' . h(t('back')) . '</a>';
}

function nu_list_reset(string $page, bool $active, array $params = []): string
{
	return $active ? '<div class="col-md-1"><a class="btn btn-outline-secondary nuToolBtn w-100" href="' . h(nu_url($page, $params)) . '" title="' . h(t('reset')) . '" aria-label="' . h(t('reset')) . '"><i class="bi bi-x-lg"></i></a></div>' : '';
}

function nu_sort_th(string $label, string $col, string $sort, string $dir, string $route, array $params, string $class = ''): string
{
	$isCur = $sort === $col;
	$next  = ($isCur && $dir === 'asc') ? 'desc' : 'asc';
	$icon  = $isCur ? ($dir === 'asc' ? ' <i class="bi bi-caret-up-fill"></i>' : ' <i class="bi bi-caret-down-fill"></i>') : '';
	$aria  = $isCur ? ' aria-sort="' . ($dir === 'asc' ? 'ascending' : 'descending') . '"' : '';
	return '<th scope="col"' . ($class !== '' ? ' class="' . h($class) . '"' : '') . $aria . '><a class="nu-sort" href="' . h(nu_url($route, ['sort' => $col, 'dir' => $next] + $params)) . '">' . h($label) . $icon . '</a></th>';
}

function nu_invalid(array $errors, string $field): string
{
	return isset($errors[$field]) ? ' is-invalid' : '';
}

function nu_feedback(array $errors, string $field): string
{
	return isset($errors[$field]) ? '<div class="invalid-feedback">' . h($errors[$field]) . '</div>' : '';
}

/** Centered card page (login, 2FA, password reset) – no navigation, no login required. */
function nu_center_start(string $title, string $subtitle = '', string $icon = ''): void
{
	nu_head($title);
	?>
<body class="nu-center nu-login">
<main class="nu-card-narrow">
	<div class="d-flex justify-content-end align-items-center gap-2 mb-3">
		<?= NuBranding::envBadge('me-auto') ?>
		<?php nu_theme_menu(); ?>
		<?php nu_lang_menu(); ?>
	</div>
	<div class="card shadow-sm border-0">
		<div class="card-body p-4 p-sm-5">
			<div class="text-center mb-4">
				<?php if ($icon !== ''): ?>
					<span class="nu-logo nu-logo-lg mb-3"><i class="bi <?= h($icon) ?>"></i></span>
				<?php else: ?>
					<div class="mb-3"><?= nu_brand_logo('lg') ?></div>
				<?php endif; ?>
				<h1 class="h4 mb-1"><?= h($title) ?></h1>
				<?php if ($subtitle !== ''): ?><p class="text-body-secondary small mb-0"><?= h($subtitle) ?></p><?php endif; ?>
			</div>
			<?php nu_render_flashes(); ?>
<?php
}

function nu_center_end(string $extraBody = ''): void
{
	?>
		</div>
	</div>
	<p class="text-center small text-body-secondary mt-3 mb-0">nuBuilder Next <?= NU_VERSION ?></p>
</main>
<script src="<?= nu_asset(NU_BS . '/js/bootstrap.bundle.min.js') ?>"></script>
<script src="<?= nu_asset('assets/js/nubs.js') ?>"></script>
<script src="<?= nu_asset('assets/js/nuapp.js') ?>"></script>
<?= $extraBody ?>
</body>
</html>
<?php
}
