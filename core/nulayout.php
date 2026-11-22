<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * nuBuilder's $nuConfigTitle (nuconfig.php or Setup → Settings) – browser tab and login page; 'nuBuilder Next' when not set.
 * Never fails: also used on error pages and in the installer (no nuconfig.php yet).
 */
function nu_app_title(bool $configuredOnly = false): string
{
	static $v = null, $busy = false;
	if ($v === null) {
		if ($busy) return $configuredOnly ? '' : 'nuBuilder Next';
		$busy = true;
		try {
			// Branding → Title first, then nuBuilder Forte's $nuConfigTitle (nuconfig.php / Settings)
			$v = is_file(nu_config_path()) ? NuBranding::get()['title'] : '';
			if ($v === '' && is_file(nu_config_path())) $v = trim((string)(nu_cfg('nuConfigTitle') ?? ''));
		} catch (Throwable) {
			$v = '';
		}
		$busy = false;
	}
	return $v !== '' || $configuredOnly ? $v : 'nuBuilder Next';
}

function nu_head(string $title, string $extraHead = ''): void
{
	$env = NuBranding::get()['env_label'];             // branding: environment label in the tab title and as a coloured line
	$pb = NuDB::$connected ? NuSettings::phoneBack() : '';   // shared setting nuPhoneBackButton: Back on phones as icon / hidden
	$hc = trim(($env !== '' ? 'nu-env' : '') . ($pb !== '' ? ' nuBack-' . $pb : ''));
	?><!doctype html>
<html lang="<?= h(nu_lang_choice()) ?>"<?= nu_lang_rtl() ? ' dir="rtl"' : '' ?>
	data-bs-theme="light"<?= $hc !== '' ? ' class="' . h($hc) . '"' : '' ?><?= NuPageWidth::htmlAttr() /* page width default – nutheme.js */ ?>
	data-nu-dfmt="<?= h(nu_dfmt('d.m.Y')) ?>"
	data-nu-date-texts="<?= h((string)json_encode(['ph' => t('date_ph_tokens'), 'pick' => t('date_pick'), 'bad' => t('date_invalid')], JSON_UNESCAPED_UNICODE)) ?>">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<?php if (session_status() === PHP_SESSION_ACTIVE): // the form token for page scripts (assets/js/nuutil.js NuUtil.csrf) ?><meta name="nu-csrf" content="<?= h(nu_csrf_token()) ?>"><?php endif; ?>
	<title><?= $env !== '' ? '[' . h($env) . '] ' : '' ?><?= h($title) ?> · <?= h(nu_app_title()) ?></title>
	<script src="<?= nu_asset('assets/js/nutheme.js') ?>"></script>
	<link rel="stylesheet" href="<?= nu_asset(NU_BS . (nu_lang_rtl() ? '/css/bootstrap.rtl.min.css' : '/css/bootstrap.min.css')) ?>">
	<link rel="stylesheet" href="<?= nu_asset(NU_BI . '/bootstrap-icons.min.css') ?>">
	<link rel="stylesheet" href="<?= nu_asset('assets/css/nuapp.css') ?>">
	<?= NuBranding::headTags() ?>
	<?= NuDB::$connected ? NuPwa::headTags() : '' /* installable app */ ?>
	<?= NuTheme::headTag() /* theme: after branding, before Global CSS */ ?>
	<?= NuCustomCode::cssTag() /* Global CSS: before the page's own styles */ ?>
	<?= nu_plugin_tags('css') /* styles of the switched-on plugins */ ?>
	<?= $extraHead ?>
</head>
<?php
}

/** Query string of the current request, used to come back after switching language. */
/** The nuBuilder Next logo (icon + name): a light and a dark version, CSS shows the one of the current theme. $size: '' (top bar) | 'lg' (sign-in pages). */
function nu_brand_logo(string $size = ''): string
{
	if (!NuBranding::logoShown($size)) return $size === '' ? NuBranding::titleNoLogo() : '';   // Branding → Show logo
	if (($own = NuBranding::logo($size)) !== '') return $own;      // own logo of this installation (Admin tools → Branding)
	$cls = 'nu-brand' . ($size === 'lg' ? ' nu-brand-lg' : '');
	return '<span class="' . $cls . '"><img class="nu-brand-light" src="' . h(nu_asset('assets/img/nunext-logo.png')) . '" alt="nuBuilder Next" width="446" height="96">'
		. '<img class="nu-brand-dark" src="' . h(nu_asset('assets/img/nunext-logo-dark.png')) . '" alt="nuBuilder Next" width="446" height="96"></span>'   // only one is shown
		. ($size === '' ? NuBranding::titleOnly() : '');
}

function nu_return_query(): string
{
	return (string)($_SERVER['QUERY_STRING'] ?? '');
}

function nu_lang_menu(string $btnClass = 'btn btn-sm btn-outline-secondary', string $wrapClass = ''): void
{
	if (count(nu_lang_choices()) < 2) return;                  // one installed language without variants: nothing to choose
	$r = nu_return_query();
	?>
	<div class="dropdown<?= $wrapClass !== '' ? ' ' . h($wrapClass) : '' ?>">
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

function nu_theme_menu(string $btnClass = 'btn btn-sm btn-outline-secondary', string $wrapClass = ''): void
{
	?>
	<div class="dropdown<?= $wrapClass !== '' ? ' ' . h($wrapClass) : '' ?>">
		<button class="<?= $btnClass ?> dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="<?= h(t('nav_theme')) ?>">
			<i class="bi bi-circle-half js-theme-icon"></i>
		</button>
		<ul class="dropdown-menu dropdown-menu-end">
			<li><button class="dropdown-item" type="button" data-theme-set="light"><i class="bi bi-sun me-2"></i><?= h(t('theme_light')) ?></button></li>
			<li><button class="dropdown-item" type="button" data-theme-set="dark"><i class="bi bi-moon-stars me-2"></i><?= h(t('theme_dark')) ?></button></li>
			<li><button class="dropdown-item" type="button" data-theme-set="auto"><i class="bi bi-circle-half me-2"></i><?= h(t('theme_auto')) ?></button></li>
			<?php $themes = NuAuth::user() ? NuTheme::choices() : []; if ($themes): $pick = NuTheme::userPick(); if (!isset($themes[$pick])) $pick = ''; // own theme ?>
			<li><hr class="dropdown-divider"></li>
			<li><h6 class="dropdown-header"><?= h(t('theme_title')) ?></h6></li>
			<?php foreach (['' => t('theme_pick_default')] + array_map(static fn($x) => $x['name'], $themes) as $tid => $tname): ?>
			<li><form method="post" action="<?= h(nu_url('themes')) ?>" class="m-0"><?= nu_csrf_field() ?><input type="hidden" name="action"
				value="pick"><input type="hidden" name="theme" value="<?= h((string)$tid) ?>"><input type="hidden" name="back"
				value="<?= h('index.php?' . nu_return_query()) ?>">
				<button class="dropdown-item<?= $pick === (string)$tid ? ' active' : '' ?>" type="submit"
					data-nu-theme-pick="<?= h((string)$tid) ?>"><i class="bi bi-brush me-2"></i><?= h($tname) ?></button></form></li>
			<?php endforeach; endif; ?>
		</ul>
	</div>
	<?php
}

/**
 * Theme and language at the top of the user menu – compact top bar on phones: the bar's own theme / language buttons are hidden
 * below 768 px so the bar fits (an environment label took the room)
 */
function nu_menu_prefs(): void
{
	$r = nu_return_query();
	?>
	<li class="d-md-none nuMenuPrefs px-3 pb-2" id="nuMenuPrefs">
		<div class="btn-group w-100 nuSeg" role="group" aria-label="<?= h(t('nav_theme')) ?>">
			<button type="button" class="btn btn-sm btn-outline-secondary" data-theme-set="light" title="<?= h(t('theme_light')) ?>" aria-label="<?= h(t('theme_light')) ?>"><i class="bi bi-sun"></i></button>
			<button type="button" class="btn btn-sm btn-outline-secondary" data-theme-set="dark" title="<?= h(t('theme_dark')) ?>"
				aria-label="<?= h(t('theme_dark')) ?>"><i class="bi bi-moon-stars"></i></button>
			<button type="button" class="btn btn-sm btn-outline-secondary" data-theme-set="auto" title="<?= h(t('theme_auto')) ?>"
				aria-label="<?= h(t('theme_auto')) ?>"><i class="bi bi-circle-half"></i></button>
		</div>
		<?php if (count(nu_lang_choices()) > 1): ?>
		<form method="get" action="index.php" class="mt-2 d-flex align-items-center gap-2" id="nuMenuLangForm">
			<input type="hidden" name="p" value="lang"><input type="hidden" name="r" value="<?= h($r) ?>">
			<label class="small text-body-secondary text-nowrap" for="nuMenuLang"><i class="bi bi-translate me-1"></i><?= h(t('nav_language')) ?></label>
			<select class="form-select form-select-sm js-autosubmit" name="l" id="nuMenuLang">
				<?php foreach (nu_lang_choices() as $code => $own): ?><option value="<?= h($code) ?>"
					lang="<?= h($code) ?>"<?= nu_lang_choice() === $code ? ' selected' : '' ?>><?= h($own) ?></option><?php endforeach; ?>
			</select>
		</form>
		<?php endif; ?>
		<?php $themes = NuAuth::user() ? NuTheme::choices() : []; if ($themes): $pick = NuTheme::userPick(); if (!isset($themes[$pick])) $pick = ''; ?>
		<form method="post" action="<?= h(nu_url('themes')) ?>" class="mt-2 d-flex align-items-center gap-2" id="nuMenuThemeForm">
			<?= nu_csrf_field() ?><input type="hidden" name="action" value="pick"><input type="hidden" name="back" value="<?= h('index.php?' . $r) ?>">
			<label class="small text-body-secondary text-nowrap" for="nuMenuTheme"><i class="bi bi-brush me-1"></i><?= h(t('theme_title')) ?></label>
			<select class="form-select form-select-sm js-autosubmit" name="theme" id="nuMenuTheme">
				<?php foreach (['' => t('theme_pick_default')] + array_map(static fn($x) => $x['name'], $themes) as $tid => $tname): ?><option value="<?= h((string)$tid) ?>"<?= $pick === (string)$tid ? ' selected' : '' ?>><?= h($tname) ?></option><?php endforeach; ?>
			</select>
		</form>
		<?php endif; ?>
	</li>
	<li class="d-md-none"><hr class="dropdown-divider"></li>
	<?php
}

/**
 * A class for the <body> of the page that nu_layout_start() opens next. 'nuRecPage' = a record page – one whose title bar is
 * .nuRecHead (nuapp.css draws these pages compact): call nu_body_class('nuRecPage') before nu_layout_start() wherever a page prints
 * that title bar (dev/tests/run.php checks it). The style sheet asked body:has(.nuRecHead) before – with :has() in front of the
 * styled element the browser works out the styles of the whole page again on every change of the page.
 */
function nu_body_class(?string $add = null): string
{
	static $cls = '';
	if ($add !== null && $add !== '' && !str_contains($cls . ' ', ' ' . $add . ' ')) $cls .= ' ' . $add;
	return $cls;
}

function nu_layout_start(string $title, string $active = '', array $opt = []): void
{
	$u = NuAuth::user();
	nu_head($title, $opt['head'] ?? '');
	nu_layout_buffer();
	if (!empty($opt['bare'])) {
		echo '<body' . (isset($opt['nu_form']) ? ' id="nubody"' : '') . ' class="nu-app nu-bare' . h(' ' . ($opt['body_class'] ?? '') . nu_body_class()) . '"'
			. NuCustomCode::bodyAttrs($opt['nu_form'] ?? null) . '><main id="main" class="' . h($opt['container'] ?? 'container-fluid') . ' py-2">';
		if (!empty($_SESSION[NuSess::FORM_PREVIEW])) echo NuFormDraft::banner(true);       // form drafts: "DRAFT" badge in iframes
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
	// compact top bar: no logo in the bar (Branding → Show logo) – on phones the icons stay in the bar, ☰ opens the user menu
	$compact = !NuBranding::logoShown('');
	$hf = empty($opt['bare']) && NuHomeForms::allowed();   // Home forms of the access levels (globeadmin)
	$sidebar = NuSidebar::enabled() && empty($opt['no_sidebar']);   // side bar (core/nusidebar.php)
	if (empty($opt['bare'])) NuSidebar::record(NuSidebar::currentKey());   // recently used: side bar and start page
	?>
<body<?= isset($opt['nu_form']) ? ' id="nubody"' : '' /* form pages: nuBuilder Forte's body id – form code reads $('#nubody') */ ?> class="nu-app<?= $sidebar ? ' nuHasSidebar' : '' ?><?= $compact ? ' nuNavCrumbsOn' : '' /* the bar has the breadcrumb slot (nuapp.css hides the page's own at once) */ ?><?= h(' ' . ($opt['body_class'] ?? '') . nu_body_class()) ?><?= array_intersect(explode(' ', (string)($opt['container'] ?? 'nuContainer')), ['nuContainer', 'nuFormContainer', 'container', 'container-xl']) ? ' nuSheetMain' : '' /* the page's content as a sheet on wide screens (nuapp.css) */ ?><?= NuAdmin::$viewOnly ? ' nuViewOnly' : '' ?>"<?= NuAdmin::$viewOnly ? ' data-nu-view-only="' . h(t('adm_view_only')) . '"' : '' ?><?= NuCustomCode::bodyAttrs($opt['nu_form'] ?? null) /* page hook */ ?>>
<a class="visually-hidden-focusable" href="#main"><?= h(t('skip_to_content')) ?></a>
<?php nu_layout_nav($u, $nav, $compact, $hf, $sidebar, $active); ?>
<?= !empty($_SESSION[NuSess::FORM_PREVIEW]) ? NuFormDraft::banner(false) : '' /* form drafts: preview banner */ ?>
<?= NuReady::bar($active) /* Getting ready checklist: slim bar until done or hidden */ ?>
<?php if ($sidebar) { echo '<div class="nuShell' . (NuSidebar::pinned() ? ' nuSbPinned' : '') . '">'; nu_sidebar(); NuSidebar::$drawn = true; }   // pinned: a column from 992 px ?>
<main id="main" class="<?= h($opt['container'] ?? 'nuContainer') ?> py-4<?= $sidebar ? ' nuShellMain' : '' ?><?= isset($opt['settings']) ? ' nuSetPage' : '' ?>">
	<?php nu_render_flashes(); ?>
<?php if (isset($opt['settings'])) NuSettingsHub::frameStart((string)$opt['settings'], (string)($opt['settings_page'] ?? ''));   // settings centre frame ?>
<?php
}

/** The top bar of nu_layout_start(): logo or Home button, links, side bar button, bell, theme / language, user menu. */
function nu_layout_nav(array $u, array $nav, bool $compact, bool $hf, bool $sidebar, string $active): void
{
	?>
<nav class="navbar navbar-expand-md nu-navbar border-bottom sticky-top<?= NuImpersonate::active() ? ' flex-wrap' : '' ?><?= $compact ? ' nuNavCompact' : '' ?>">
	<?= NuImpersonate::banner() ?>
	<div class="container-fluid nuNavInner"><?php // full width: the logo stays at the left edge on every page, the menus at the right ?>
		<?php if ($compact): // no logo in the bar: a Home button at the very left ?>
			<?php // the Start pages ▾ sits on the start page next to Customise (the owner); right-click / long press on Home opens it there (data-nu-hf-url) ?>
			<a class="btn btn-sm btn-outline-secondary me-2 nuHomeBtn" id="nuHomeBtn" href="<?= h(nu_url('home')) ?>" title="<?= h(t('nav_home')) ?>"
				aria-label="<?= h(t('nav_home')) ?>"<?= $active === 'home' ? ' aria-current="page"' : '' ?><?= $hf ? ' data-nu-hf-url="' . h(nu_url('home', ['hf' => '1'])) . '"' : '' ?>><i class="bi bi-house"></i></a>
		<?php endif; ?>
		<?php if ($sidebar): // side bar: a drawer opened here, left of the logo, on every screen width ?>
			<button type="button" class="btn btn-sm btn-outline-secondary me-2 nuSbOpenBtn" id="nuSbOpen" aria-controls="nuSidebar" aria-expanded="false"
				title="<?= h(t('sb_open')) ?>" aria-label="<?= h(t('sb_open')) ?>"><i class="bi bi-layout-sidebar"></i></button>
		<?php endif; ?>
		<?php $logoHide = !$compact && !empty($u['globeadmin']) && empty($u['demo']); // right-click on the logo: hide it (the globeadmin) ?>
		<a class="navbar-brand d-flex align-items-center gap-2" href="<?= h(nu_url('home')) ?>"<?= $logoHide ? ' data-nu-logo-hide="nuLogoHideForm"' : '' ?>>
			<?= nu_brand_logo() ?>
		</a>
		<?php if ($logoHide): ?>
		<form method="post" action="<?= h(nu_url('branding')) ?>" id="nuLogoHideForm" hidden data-confirm="<?= h(t('brand_hide_confirm')) ?>"
			data-confirm-ok="<?= h(t('brand_hide_ok')) ?>" data-confirm-class="btn btn-primary">
			<?= nu_csrf_field() ?><input type="hidden" name="action" value="hide_logo"><input type="hidden" name="back" value="<?= h('index.php?' . (string)($_SERVER['QUERY_STRING'] ?? '')) ?>">
		</form>
		<?php endif; ?>
		<?= NuBranding::envBadge('me-auto me-md-3') ?>
		<?php if ($compact): // no logo: on desktop the page's breadcrumb moves here, next to the Home button (assets/js/nuapp.js) ?>
			<nav class="nuNavCrumbs" id="nuNavCrumbs" aria-label="breadcrumb" hidden></nav>
		<?php endif; ?>
		<?php if (!$compact): ?>
		<button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#nuNav" aria-controls="nuNav" aria-expanded="false" aria-label="Menu">
			<span class="navbar-toggler-icon"></span>
		</button>
		<?php endif; ?>
		<div class="collapse navbar-collapse" id="nuNav">
			<ul class="navbar-nav me-auto">
				<?php foreach ($nav as $page => $n): [$icon, $label, $show] = $n; if (!$show || ($compact && $page === 'home')) continue;   // compact: Home is the button at the left ?>
					<li class="nav-item">
						<a class="nav-link<?= $active === $page ? ' active' : '' ?>"<?= $active === $page ? ' aria-current="page"' : '' ?> href="<?= h($n[3] ?? nu_url($page)) ?>">
							<i class="bi <?= $icon ?> me-1"></i><?= h($label) ?>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php if ($compact): ?></div><?php endif; // compact: the icons and the user menu outside the collapsed part ?>
			<div class="d-flex align-items-center gap-2<?= $compact ? ' ms-auto flex-nowrap nuNavIcons' : ' py-2 py-md-0' ?>">
				<?php if (!empty($u['demo'])): // demo mode (core/nudemo.php) ?>
					<span class="badge nuDemoBadge" title="<?= h(t('demo_badge_hint')) ?>"><i class="bi bi-eye me-1"></i><?= h(t('demo_badge')) ?></span>
				<?php endif; ?>
				<?php if (NuAdmin::any()): // search, also Ctrl+K (assets/js/nupalette.js) – also for admin roles
					$palT = []; foreach (['pal_title', 'pal_placeholder', 'pal_none', 'pal_hint', 'pal_recent', 'pal_keys', 'pal_loading', 'pal_failed'] as $pk) $palT[$pk] = t($pk); ?>
					<button type="button" class="btn btn-sm btn-outline-secondary" id="nuPaletteBtn" title="<?= h(t('pal_title')) ?> (Ctrl+K)" aria-label="<?= h(t('pal_title')) ?>"
						data-url="<?= h(nu_url('palette')) ?>" data-vendor="<?= h(nu_url('vendor')) ?>" data-csrf="<?= h(nu_csrf_token()) ?>"
							data-t="<?= h(json_encode($palT, JSON_UNESCAPED_UNICODE)) ?>"><i class="bi bi-search"></i></button>
				<?php endif; ?>
				<?php nu_bell(); ?>
				<?php // compact bar on phones: theme and language move into the user menu (nu_menu_prefs) – the bar fits also with an environment label ?>
				<?php nu_theme_menu('btn btn-sm btn-outline-secondary', $compact ? 'd-none d-md-block' : ''); ?>
				<?php nu_lang_menu('btn btn-sm btn-outline-secondary', $compact ? 'd-none d-md-block' : ''); ?>
				<?php if (!$compact): ?><button type="button" class="btn btn-sm btn-outline-secondary d-md-none" id="nuNavPin" aria-pressed="false"
					data-on="<?= h(t('nav_pin_on')) ?>" data-off="<?= h(t('nav_pin_off')) ?>"
					title="<?= h(t('nav_pin_off')) ?>"
						aria-label="<?= h(t('nav_pin_off')) ?>"><i class="bi bi-pin-angle"></i></button><?php endif; // phones / tablets: keep the ☰ menu open on every page (nuapp.js, localStorage nunext-nav-pin) ?>
				<div class="dropdown">
					<button class="btn btn-sm btn-outline-secondary dropdown-toggle d-flex align-items-center gap-2 nuUserBtn" type="button"
						data-bs-toggle="dropdown" aria-expanded="false" title="<?= h($u['display']) ?>" aria-label="<?= h($u['display']) ?>">
						<?php if ($compact): ?><i class="bi bi-list d-md-none nuUserHam" aria-hidden="true"></i><?php endif; ?>
						<i class="bi <?= $u['globeadmin'] ? 'bi-person-gear' : 'bi-person-circle' ?><?= $compact ? ' d-none d-md-inline' : '' ?>"></i>
						<span class="text-truncate nu-user-name d-none <?= $compact ? 'd-md-inline' : 'd-sm-inline' ?>"><?= h($u['display']) ?></span><?php // phones: the icon only, the name stays the tooltip ?>
					</button>
					<ul class="dropdown-menu dropdown-menu-end">
						<li><h6 class="dropdown-header"><?= h($u['login']) ?></h6></li>
						<?php if ($compact) nu_menu_prefs(); ?>
						<?php if ($compact && array_diff(array_keys($nav), ['home'])): // compact top bar on phones: the bar's links ?>
							<?php foreach ($nav as $page => $n): if ($page === 'home') continue; ?><li class="d-md-none"><a class="dropdown-item<?= $active === $page ? ' active' : '' ?>"
								href="<?= h($n[3] ?? nu_url($page)) ?>"><i class="bi <?= $n[0] ?> me-2"></i><?= h($n[1]) ?></a></li><?php endforeach; ?>
							<li class="d-md-none"><hr class="dropdown-divider"></li>
						<?php endif; ?>
						<?php if (NuImpersonate::active()): ?>
							<li>
								<form method="post" action="<?= h(nu_url('impersonate')) ?>">
									<?= nu_csrf_field() ?><input type="hidden" name="action" value="stop">
									<button class="dropdown-item" type="submit"><i class="bi bi-arrow-return-left me-2"></i><?= h(t('imp_back')) ?></button>
								</form>
							</li>
						<?php elseif (!NuAdmin::any()): // admins reach Security through the start page tile ?>
						<li><a class="dropdown-item" href="<?= h(nu_url('security')) ?>"><i class="bi bi-shield-lock me-2"></i><?= h(t('sec_title')) ?></a></li>
						<?php endif; ?>
						<?php if (!$hf && NuUserHome::available() && NuAcl::can(NuUserHome::FORM)): // nuBuilder Forte's User Home; not with the Start pages panel – it has User Home in its head; window icon as its tile ?>
							<li><a class="dropdown-item" href="<?= h(nu_url('form', ['f' => NuUserHome::FORM])) ?>" id="nuMenuUserHome"><i class="bi bi-window me-2"></i><?= h(t('home_userhome')) ?></a></li>
						<?php endif; ?>
						<?php if ($u['globeadmin'] ? NuHome::globeadminForm() !== null : NuHome::startForm($u) !== null): // a form opens instead of the tile page ($nuConfigGlobeadminHome, start page "Home form" of the access level) – the tile page stays reachable here ?>
							<li><a class="dropdown-item" id="nuMenuTiles" href="<?= h(nu_url('home', ['tiles' => '1'])) ?>"><i class="bi bi-grid me-2"></i><?= h(t('home_tiles_link')) ?></a></li>
						<?php endif; ?>
						<?php // side bar on / off: start page → Customise → Appearance; "for everyone": Settings → Look
						ob_start();   // the user's own items (password, import, account): a divider only when there are any ?>
						<?php if (!$u['globeadmin'] && $u['via'] !== 'nubuilder' && !NuImpersonate::active()): ?>
							<li><a class="dropdown-item" href="<?= h(nu_url('password')) ?>"><i class="bi bi-key me-2"></i><?= h(t('nav_change_password')) ?></a></li>
						<?php endif; ?>
						<?php if (!$u['globeadmin'] && !NuAdmin::can('import', 'change') && !NuImpersonate::active() && NuImport::itemRules()): // permission items of type Data import ?>
							<li><a class="dropdown-item" href="<?= h(nu_url('import')) ?>" id="nuMenuImport"><i class="bi bi-file-earmark-arrow-up me-2"></i><?= h(t('imp_title')) ?></a></li>
						<?php endif; ?>
						<?php if (!$u['globeadmin'] && NuUserFields::editable('self')): // custom fields the user fills in himself ?>
							<li><a class="dropdown-item" href="<?= h(nu_url('account')) ?>" id="nuMyAccount"><i class="bi bi-person-lines-fill me-2"></i><?= h(t('acc_title')) ?></a></li>
						<?php endif; ?>
						<?php $own = trim((string)ob_get_clean()); if ($own !== ''): ?><li><hr class="dropdown-divider"></li><?= $own ?><?php endif; ?>
						<?php if (NuAdmin::any() && (nu_db()->type === 'mysql' || NuVendor::available('ADM') || NuFileManager::visible())): // admin roles: only what the role allows ?>
							<li><hr class="dropdown-divider"></li>
							<?php // database tools: phpMyAdmin / Adminer, the primary one first ($nuConfigPrimaryDatabaseManagement); then File Manager
							$vItems = [];
							if (NuAdmin::can('dbtool', 'change')) {
								foreach (NuVendor::dbTools() as $va) $vItems[] = [$va, 'bi-database', t('vendor_db_with', NuVendor::DB_TOOLS[$va]), true];
								if (!$vItems) $vItems[] = ['DB', 'bi-database', t('vendor_pma'), false];
							}
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
							<?php if (NuFileManager::visible()): // File Manager = the page nuFiles ?>
								<li><a class="dropdown-item" href="<?= h(NuFileManager::url()) ?>" target="_blank" rel="noopener" id="nuMenuFiles"><i class="bi bi-folder2-open me-2"></i><?= h(t('vendor_tfm')) ?></a></li>
							<?php endif; ?>
						<?php endif; ?>
						<?php $plgMenu = nu_plugin_menu(); if ($plgMenu): // entries of the switched-on plugins ?>
							<li><hr class="dropdown-divider"></li>
							<?php foreach ($plgMenu as [$pl, $pi, $pu]): ?><li><a class="dropdown-item nuPlgMenu" href="<?= h($pu) ?>"><i class="bi <?= h($pi) ?> me-2"></i><?= h($pl) ?></a></li><?php endforeach; ?>
						<?php endif; ?>
						<?php // help and learning after the tools (the owner): documentation, tutorial, help pages, install as app ?>
						<li><hr class="dropdown-divider"></li>
						<?php if (NuUserHelp::any() && !NuUserHelp::canManage()): // user help pages of this installation; not for those who manage them (tile Help pages, the Help button of a form) ?><li><a class="dropdown-item"
							href="<?= h(nu_url('userhelp')) ?>" id="nuMenuUserHelp"><i class="bi bi-journal-bookmark me-2"></i><?= h(t('uh_title')) ?></a></li><?php endif; ?>
						<li hidden id="nuMenuInstallLi"><button type="button" class="dropdown-item" id="nuMenuInstall" data-ios="<?= h(t('pwa_ios_hint')) ?>"
							data-title="<?= h(t('pwa_install')) ?>"><i class="bi bi-phone me-2"></i><?= h(t('pwa_install')) ?></button></li>
						<li><a class="dropdown-item" href="<?= h(nu_url('help')) ?>" target="_blank" rel="noopener" id="nuMenuDocs"><i class="bi bi-book me-2"></i><?= h(t('help_title')) ?></a></li>
						<?php if (NuTutorial::allowed()): ?><li><a class="dropdown-item" href="<?= h(nu_url('tutorial')) ?>"><i class="bi bi-mortarboard me-2"></i><?= h(t('tut_title')) ?></a></li><?php endif; ?>
						<?php if (NuSysInfo::allowed()): // System info dialog (was the version link to the Update page) ?>
							<li><hr class="dropdown-divider"></li>
							<li><button type="button" class="dropdown-item" id="nuSysInfoBtn" data-bs-toggle="modal"
								data-bs-target="#nuSysInfoDlg"><i class="bi bi-info-circle me-2"></i><?= h(t('si_menu')) ?></button></li>
						<?php endif; ?>
						<?php if (NuSharedLogin::url() !== ''): ?>
							<li><a class="dropdown-item" href="<?= h(NuSharedLogin::url()) ?>"><i class="bi bi-box-arrow-up-right me-2"></i>nuBuilder Forte</a></li>
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
		<?php if (!$compact): ?></div><?php endif; ?>
	</div>
</nav>
<?php
}

/** The side bar (core/nusidebar.php): favourites and recently used; a drawer on every screen width. */
function nu_sidebar(): void
{
	$d = NuSidebar::data();
	$pinned = NuSidebar::pinned();
	$back = 'index.php?' . (string)($_SERVER['QUERY_STRING'] ?? '');
	$post = static fn(string $action, string $inner, array $extra = [], string $cls = '') => '<form method="post" action="' . h(nu_url('sidebar'))
		. '" class="nuSbForm' . ($cls !== '' ? ' ' . $cls : '') . '">'
		. nu_csrf_field() . '<input type="hidden" name="action" value="' . h($action) . '"><input type="hidden" name="back" value="' . h($back) . '">'
		. implode('', array_map(static fn($k, $v) => '<input type="hidden" name="' . h($k) . '" value="' . h($v) . '">', array_keys($extra), $extra)) . $inner . '</form>';
	$item = static function (array $it) use ($post): string {
		$body = '<span class="nuSbIcon"' . ($it['color'] !== '' ? ' data-nu-color="' . h($it['color']) . '"' : '') . '><i class="bi ' . h($it['icon']) . '" aria-hidden="true"></i></span>'
			. '<span class="nuSbLabel">' . h($it['label']) . '</span>'
			. (!empty($it['badge']) ? '<span class="badge text-bg-' . h((string)$it['badge'][1]) . ' nuSbBadge">' . h((string)$it['badge'][0]) . '</span>' : '');   // a form in maintenance
		$cur = $it['current'] ? ' aria-current="page"' : '';
		$link = $it['post'] !== null
			? '<form method="post" action="' . h(nu_url('vendor')) . '" target="_blank" class="nuSbForm nuSbGrow">' . nu_csrf_field() . '<input type="hidden" name="app" value="' . h((string)$it['post']) . '">'
				. '<button type="submit" class="nuSbLink" title="' . h($it['label']) . '">' . $body . '</button></form>'
			: '<a class="nuSbLink nuSbGrow" href="' . h((string)$it['href']) . '"' . ($it['newtab'] ? ' target="_blank" rel="noopener"' : '') . ' title="' . h($it['label']) . '"' . $cur . '>' . $body . '</a>';
		$star = '';
		if ($it['canFav']) {
			$tip = t($it['fav'] ? 'sb_unfav' : 'sb_fav', $it['label']);
			$star = $post('fav', '<button type="submit" class="nuSbFav' . ($it['fav'] ? ' on' : '') . '" title="' . h($tip) . '" aria-label="' . h($tip)
				. '" aria-pressed="' . ($it['fav'] ? 'true' : 'false') . '">'
				. '<i class="bi ' . ($it['fav'] ? 'bi-star-fill' : 'bi-star') . '" aria-hidden="true"></i></button>', ['key' => $it['key']], 'nuSbStar');
		} elseif ($it['fav']) {
			$star = '<span class="nuSbFav on nuSbStar" role="img" aria-label="' . h(t('sb_favourites')) . '"><i class="bi bi-star-fill" aria-hidden="true"></i></span>';
		}
		return '<li class="nuSbItem' . ($it['current'] ? ' active' : '') . ($it['fav'] ? ' fav' : '') . '" data-nu-sb="' . h($it['key']) . '">' . $link . $star . '</li>';
	};
	$section = static function (string $id, string $icon, string $title, array $items, string $empty, string $extra = '') use ($item): string {
		return '<section class="nuSbSec nuSbSec-' . $id . '"'
			. ($id === 'recent' ? ' data-t-clear="' . h(t('sb_recent_clear_menu')) . '" data-t-remove="' . h(t('sb_recent_remove')) . '" data-csrf="'
				. h(nu_csrf_token()) . '" data-url="' . h(nu_url('sidebar')) . '"' : '')
			. ' aria-labelledby="nuSbH-' . $id . '"><h2 class="nuSbSecTitle" id="nuSbH-' . $id . '"><i class="bi ' . $icon . '" aria-hidden="true"></i>'
			. '<span class="nuSbText">' . h($title) . '</span>' . ($items ? '<span class="nuSbCount">' . count($items) . '</span>' : '') . $extra . '</h2>'
			. ($items ? '<ul class="nuSbList">' . implode('', array_map($item, $items)) . '</ul>' : '<p class="nuSbEmpty">' . h($empty) . '</p>') . '</section>';
	};
	$clear = $d['recent'] ? $post('clear', '<button type="submit" class="nuSbClear" title="' . h(t('sb_recent_clear')) . '" aria-label="'
		. h(t('sb_recent_clear')) . '"><i class="bi bi-x-circle" aria-hidden="true"></i></button>', [], 'nuSbClearForm') : '';
	echo '<aside id="nuSidebar" class="nuSidebar" aria-label="' . h(t('sb_title')) . '">'
		// × on the left: right where the open button was – opened and closed again without moving the mouse
		. '<div class="nuSbHead"><button type="button" class="nuSbClose" id="nuSbClose" title="' . h(t('close')) . '" aria-label="' . h(t('close'))
			. '"><i class="bi bi-x-lg" aria-hidden="true"></i></button>'
		. '<span class="nuSbTitle"><i class="bi bi-compass" aria-hidden="true"></i><span class="nuSbText">' . h(t('sb_title')) . '</span></span>'
		// pin: keep it open next to the page – the button is shown from 992 px only (CSS), the state is the user's (POST action pin)
		. '<button type="button" class="nuSbPin" id="nuSbPin" data-url="' . h(nu_url('sidebar')) . '" data-csrf="' . h(nu_csrf_token()) . '" data-t-pin="'
			. h(t('sb_pin')) . '" data-t-unpin="' . h(t('sb_unpin')) . '"'
		. ' title="' . h(t($pinned ? 'sb_unpin' : 'sb_pin')) . '" aria-label="' . h(t($pinned ? 'sb_unpin' : 'sb_pin')) . '" aria-pressed="'
			. ($pinned ? 'true' : 'false') . '"><i class="bi ' . ($pinned ? 'bi-pin-angle-fill' : 'bi-pin-angle') . '" aria-hidden="true"></i></button></div>'
		. '<nav class="nuSbBody" aria-label="' . h(t('sb_title')) . '">'
		. $section('fav', 'bi-star-fill', t('sb_favourites'), $d['fav'], t($d['canFav'] ? 'sb_fav_none' : 'sb_fav_none_ro'))
		. $section('recent', 'bi-clock-history', t('sb_recent'), $d['recent'], t('sb_recent_none'), $clear)
		. '</nav></aside>';
}

/**
 * How long a message at the top of the page stays before it closes by itself, in ms; 0 = it stays until closed.
 * - "done" messages (success) close by themselves: the longer the text, the later (4 to 12 seconds);
 * - notes (info) close by themselves only when short (up to 90 characters; 7 to 12 seconds) – a longer note is advice to read in peace;
 * - warnings and errors stay; so does every message with a link or with something to act on.
 * assets/js/nuapp.js runs the timer on signed-in pages only and holds it while the pointer or the focus is on the message.
 */
function nu_flash_autoclose(string $type, string $msg, bool $acts = false): int
{
	if ($acts) return 0;
	$n = mb_strlen($msg);
	if ($type === 'success') return (int)min(12000, max(4000, 2500 + $n * 55));
	if ($type === 'info' && $n <= 90) return (int)min(12000, max(7000, 4000 + $n * 70));
	return 0;
}

function nu_render_flashes(): void
{
	$toasts = '';
	if (isset($_SESSION[NuSess::PW_SOON]) || isset($_SESSION[NuSess::PW_GA])) NuPwPolicy::flashes();   // password policy reminders after the sign-in
	foreach (nu_flashes() as $f) {
		[$type, $msg] = $f;
		$icon = ['success' => 'bi-check-circle', 'danger' => 'bi-exclamation-octagon', 'warning' => 'bi-exclamation-triangle'][$type] ?? 'bi-info-circle';
		if (!empty($f[2])) {                                  // toast: assets/js/nuapp.js shows it and hides it after a few seconds
			$toasts .= '<div class="toast align-items-center text-bg-' . h($type) . ' border-0 nuFlashToast" role="' . ($type === 'danger' ? 'alert' : 'status') . '" aria-live="'
				. ($type === 'danger' ? 'assertive' : 'polite') . '" aria-atomic="true" data-bs-delay="' . ($type === 'danger' ? 10000 : 6000) . '"><div class="d-flex"><div class="toast-body d-flex gap-2">'
				. '<i class="bi ' . $icon . '"></i><span>' . h($msg) . '</span></div><button type="button" class="btn-close' . (in_array($type, ['warning', 'info', 'light'], true) ? '' : ' btn-close-white')
				. ' me-2 m-auto" data-bs-dismiss="toast" aria-label="' . h(t('close')) . '"></button></div></div>';
			continue;
		}
		$check = $msg === t('err_check_form');                // "Please check the highlighted fields": nuapp.js lists them on a click
		$auto = nu_flash_autoclose((string)$type, (string)$msg, isset($f[3][0], $f[3][1]) || $check);
		echo '<div class="alert alert-' . h($type) . ' alert-dismissible fade show d-flex align-items-start gap-2' . ($check ? ' nuCheckForm" data-tip="' . h(t('err_check_list')) : '')
			. ($auto ? '" data-nu-autoclose="' . $auto : '') . '" role="' . ($auto ? 'status' : 'alert') . '">'
			. '<i class="bi ' . $icon . ' mt-1"></i><div>' . h($msg) . (isset($f[3][0], $f[3][1]) ? ' <a class="alert-link" href="' . h((string)$f[3][0]) . '">' . h((string)$f[3][1]) . '</a>' : '') . '</div>'
			. '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="' . h(t('close')) . '"></button></div>';
	}
	if ($toasts !== '') echo '<div class="toast-container position-fixed end-0 p-3 nuFlashToasts">' . $toasts . '</div>';
}

function nu_layout_end(string $extraBody = '', bool $bare = false): void
{
	if (class_exists('NuSettingsHub', false)) NuSettingsHub::frameEnd();
	?>
</main>
<?= NuSidebar::$drawn ? '</div>' : '' /* side bar wrapper .nuShell */ ?>

<?php if (!$bare && NuAuth::user() && NuSysInfo::allowed()): // System info: filled by assets/js/nusysinfo.js from ?p=sysinfo + the browser ?>
<div class="modal fade" id="nuSysInfoDlg" tabindex="-1" aria-labelledby="nuSysInfoTitle" aria-hidden="true" data-url="<?= h(nu_url('sysinfo')) ?>"
	data-t="<?= h((string)json_encode(['browser' => t('si_browser'), 'b_browser' => t('si_b_browser'), 'b_screen' => t('si_b_screen'), 'b_screen_v' => t('si_b_screen_v'), 'b_lang' => t('si_b_lang'),
		'b_theme' => t('si_b_theme'), 'pw' => ['' => t('pgw_standard'), 'standard' => t('pgw_standard'), 'wide' => t('pgw_wide'), 'full' => t('pgw_full')], 'copied' => t('si_copied'), 'error' => t('form_error'), 'user' => t('si_user')], JSON_UNESCAPED_UNICODE)) ?>"
	data-user="<?= h((string)(NuAuth::user()['login'] ?? '')) ?>">
	<div class="modal-dialog modal-dialog-scrollable modal-lg nuSysInfoDialog">
		<div class="modal-content">
			<div class="modal-header gap-2">
				<h1 class="modal-title fs-5 me-auto" id="nuSysInfoTitle"><?= h(t('si_title')) ?></h1>
				<button type="button" class="btn btn-outline-secondary btn-sm" id="nuSysInfoCopy"><i class="bi bi-clipboard me-1"></i><?= h(t('si_copy')) ?></button>
				<button type="button" class="btn-close ms-1" data-bs-dismiss="modal" aria-label="<?= h(t('close')) ?>"></button>
			</div>
			<div class="modal-body" id="nuSysInfoBody"><div class="text-center text-body-secondary py-4"><span class="spinner-border spinner-border-sm me-2"
				aria-hidden="true"></span><?= h(t('loading')) ?></div></div>
			<div class="modal-footer justify-content-start gap-3 small">
				<a href="<?= h(nu_url('update')) ?>"><?= h(t('upd_title')) ?></a>
				<?php if (NuAdmin::can('securitycheck')): ?><a href="<?= h(nu_url('securitycheck')) ?>"><?= h(t('secchk_title')) ?></a><?php endif; ?>
				<?php if (NuHash::devMode() && !empty(NuAuth::user()['globeadmin'])): ?><a href="<?= h(nu_url('selftest')) ?>"><?= h(t('st_title')) ?></a><?php endif; ?>
				<a href="<?= h(nu_url('update')) ?>#updHistory"><?= h(t('si_changes')) ?></a>
				<span class="ms-auto text-body-secondary"><?= h(t('si_no_secrets')) ?></span>
			</div>
		</div>
	</div>
</div>
<?php endif; ?>
<div class="modal fade" id="nuLeave" tabindex="-1" aria-labelledby="nuLeaveTitle" aria-hidden="true" data-text-nosave="<?= h(t('leave_text_nosave')) ?>" data-text="<?= h(t('leave_text')) ?>">
	<div class="modal-dialog modal-dialog-centered">
		<div class="modal-content">
			<div class="modal-header">
				<h1 class="modal-title fs-5 d-flex align-items-center gap-2" id="nuLeaveTitle"><i class="bi bi-exclamation-triangle text-warning"></i><?= h(t('leave_title')) ?></h1>
				<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= h(t('leave_stay')) ?>"></button>
			</div>
			<div class="modal-body js-leave-text"></div>
			<div class="modal-footer nuLeaveFoot">
				<div class="nuLeaveDiscardRow"><button type="button" class="btn btn-link nuLeaveDiscard" data-nu-leave="discard"><i class="bi bi-x-lg me-1"></i><?= h(t('leave_discard_leave')) ?></button></div>
				<div class="nuLeaveActs">
					<button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" data-nu-leave="stay"><?= h(t('leave_stay')) ?></button>
					<button type="button" class="btn btn-primary" data-nu-leave="save"><i class="bi bi-check-lg me-1"></i><?= h(t('leave_save')) ?></button>
				</div>
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
<script src="<?= nu_asset('assets/js/nuutil.js') ?>"></script>
<script src="<?= nu_asset('assets/js/nuapp.js') ?>"></script>
<?= NuCustomCode::page() !== 'form' ? nu_plugin_tags('js') . NuCustomCode::jsTag() : '' /* plugins + Global JS – form pages load them in pages/nuform.php before Setup → Header */ ?>
<?php if (!$bare && NuAdmin::any()): ?><script src="<?= nu_asset('assets/js/nupalette.js') ?>"></script><?php endif; ?>
<?php if (!$bare && !empty($GLOBALS['nu_bell_on'])): ?><script src="<?= nu_asset('assets/js/nunotify.js') ?>"></script><?php endif; ?>
<?php if (NuSidebar::$drawn): ?><script src="<?= nu_asset('assets/js/nusidebar.js') ?>"></script><?php endif; ?>
<?php if (!$bare && NuAuth::user() && NuSysInfo::allowed()): ?><script src="<?= nu_asset('assets/js/nusysinfo.js') ?>"></script><?php endif; /* System info dialog */ ?>
<?php if (!$bare && NuAuth::user() && NuHomeForms::allowed()): ?><script src="<?= nu_asset('assets/js/nuhomeforms.js') ?>"></script><?php endif; /* Home forms panel */ ?>
<?= $extraBody ?>
<?= nu_perf_mark() ?>
</body>
</html>
<?php
	nu_layout_buffer(true);
}

/**
 * Classes of the <body> that depend on what the page printed (review P4) – instead of style rules with :has() in front of the
 * element they style (body:has(…) …, main:has(…) …), which make the browser work out the styles of the whole page on every change:
 *   nuRecKeep  the record's heading stays visible (it holds a badge, a link or a button) – the breadcrumb does not repeat its name
 *   nuListBack a system list whose head has a Back button – on phones the breadcrumb is left out (Back leads to the same place)
 * nu_layout_start() keeps the page from <body> on; nu_layout_end() adds the classes to the body tag and sends it. A page that sends
 * its output early (flush) or leaves a buffer open is sent unchanged.
 */
function nu_layout_buffer(bool $end = false): void
{
	static $level = null;
	if (!$end) {
		if (PHP_SAPI === 'cli') return;
		ob_start();
		$level = ob_get_level();
		return;
	}
	if ($level === null || ob_get_level() !== $level) return;
	$level = null;
	$html = (string)ob_get_clean();
	$add = '';
	$p = strpos($html, 'nuRecHead');
	if ($p !== false && preg_match('/^[^>]*>\s*<h1\b[^>]*>(.*?)<\/h1>/s', substr($html, $p), $m)
		&& preg_match('/class="(?:[^"]*\s)?badge(?:\s[^"]*)?"|<a\b|<button\b/', $m[1])) $add .= ' nuRecKeep';   // badge as a class of its own (not bi-person-badge)
	$p = strpos($html, 'nuListCard');
	if ($p !== false) {
		$q = strpos($html, 'nuListHead', $p);
		$stop = $q === false ? false : strpos($html, '</h1>', $q);
		$head = $q === false ? '' : substr($html, $q, ($stop === false ? 4000 : $stop - $q + 4000));
		// the head ends where the filter row or the body of the card starts
		foreach (['nuListFilter', 'card-body', '<table'] as $end2) { $e = strpos($head, $end2); if ($e !== false) $head = substr($head, 0, $e); }
		if (str_contains($head, 'nuBackBtn')) $add .= ' nuListBack';
	}
	if ($add !== '') $html = (string)preg_replace('/(<body\b[^>]*\bclass=")/', '$1' . ltrim($add) . ' ', $html, 1);
	echo $html;
}

/**
 * Developer mode: what the page cost on the server – database statements, the time they took and the time until here –
 * as a hidden element at the end of the page: <span hidden id="nuPerf" data-statements data-db-ms data-ms>. Read it in the browser's
 * inspector, or run dev/tests/perf.js, which compares every page with its budget (dev/tests/perf-budget.json).
 */
function nu_perf_mark(): string
{
	if (empty(nu_cfg('nuConfigDevMode'))) return '';
	$ms = isset($_SERVER['REQUEST_TIME_FLOAT']) ? (microtime(true) - (float)$_SERVER['REQUEST_TIME_FLOAT']) * 1000 : 0.0;
	return '<span hidden id="nuPerf" data-statements="' . NuDB::$statements . '" data-db-ms="' . number_format(NuDB::$nanos / 1e6, 1, '.', '')
		. '" data-ms="' . number_format($ms, 1, '.', '') . '"></span>';
}

/**
 * The bell of the notification centre (core/nunotify.php · assets/js/nunotify.js): red badge with the number of unread
 * notifications, the panel is filled by the script (checks every NuNotify::POLL seconds).
 */
function nu_bell(): void
{
	try {
		if (!NuAuth::user() || !NuNotify::ready()) return;
		$s = NuNotify::counts();                              // the count without the texts
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
					data-poll="<?= NuNotify::POLL ?>" data-t="<?= h(json_encode($tx, JSON_UNESCAPED_UNICODE)) ?>"
					data-ids="<?= h(json_encode(array_slice($s['unread_ids'], 0, 200))) ?>"<?= $s['confirm'] ? ' data-confirm="1"' : '' ?>>
					<button type="button" class="btn btn-sm btn-outline-secondary nuBellBtn<?= $n ? ' nuBellHas' : '' ?>" id="nuBellBtn" data-bs-toggle="dropdown" data-bs-auto-close="outside"
						aria-expanded="false" aria-label="<?= h($label) ?>"
							title="<?= h($label) ?>"><i class="bi <?= $n ? 'bi-bell-fill' : 'bi-bell' ?>"></i><span class="nuBellBadge"<?= $n ? '' : ' hidden' ?>><?= $n > 99 ? '99+' : $n ?></span></button>
					<div class="dropdown-menu dropdown-menu-end p-0 shadow nuBellPanel" aria-labelledby="nuBellBtn">
						<div class="d-flex align-items-center gap-2 px-3 py-2 border-bottom"><span class="fw-semibold me-auto"><?= h(t('ntf_title')) ?></span>
							<button type="button" class="btn btn-link btn-sm p-0 text-decoration-none nuBellAll"<?= $n ? '' : ' hidden' ?>><?= h(t('ntf_mark_all')) ?></button></div>
						<div class="nuBellList" aria-live="polite"><div class="px-3 py-4 text-center small text-body-secondary">…</div></div>
						<div class="d-flex align-items-center gap-3 px-3 py-2 border-top small"><a class="text-decoration-none" href="<?= h(nu_url('notifications')) ?>"><?= h(t('ntf_show_all')) ?></a>
							<?php if (NuNotify::canManage()): ?><a class="text-decoration-none ms-auto"
								href="<?= h(nu_url('notifications', ['tab' => 'manage'])) ?>"><i class="bi bi-gear me-1"></i><?= h(t('ntf_manage')) ?></a><?php endif; ?></div>
					</div>
				</div>
	<?php
}

/** Standalone page for errors – needs neither DB nor login. */
function nu_page_minimal(string $title, string $detail = '', bool $withHome = false, bool $withLogout = false): void
{
	NuBranding::$off = true;                           // error pages: no database access – the default look
	NuCustomCode::$context = 'none';                   // and no Global JS & CSS
	nu_head($title);
	?>
<body class="nu-center">
<main class="nu-card-narrow">
	<div class="card shadow-sm">
		<div class="card-body p-4">
			<h1 class="h4 d-flex align-items-center gap-2"><i class="bi bi-exclamation-triangle text-warning"></i><?= h($title) ?></h1>
			<?php if ($detail !== ''): ?><p class="text-body-secondary mb-0"><?= h($detail) ?></p><?php endif; ?>
			<?php if ($withHome): ?><a class="btn btn-primary mt-3" href="<?= h(nu_url('home')) ?>"><?= h(t('back_home')) ?></a><?php endif; ?>
			<?php if ($withLogout): ?><form method="post" action="<?= h(nu_url('logout')) ?>"
				class="mt-3"><?= nu_csrf_field() ?><button class="btn btn-outline-secondary"
				type="submit"><i class="bi bi-box-arrow-right me-1"></i><?= h(t('nav_logout')) ?></button></form><?php endif; ?>
		</div>
	</div>
</main>
<script src="<?= nu_asset(NU_BS . '/js/bootstrap.bundle.min.js') ?>"></script>
<script src="<?= nu_asset('assets/js/nubs.js') ?>"></script>
<script src="<?= nu_asset('assets/js/nuutil.js') ?>"></script>
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
/** Page sizes of the footer's menu; a list reads the choice with nu_per_param(). */
const NU_PER_MENU = [10, 25, 50, 100, 250, 500];

/** ?per= of a list when it is one of NU_PER_MENU, otherwise 0 (= the list's own page size). $any: any size 1–1000 (form browse lists,
 *  whose menu nuBrowseRowsPerPageFilter may change). */
function nu_per_param(bool $any = false): int
{
	$v = (int)($_GET['per'] ?? 0);
	if ($any) return $v >= 1 && $v <= 1000 ? $v : 0;
	return in_array($v, NU_PER_MENU, true) ? $v : 0;
}

/** $perMenu: the page size is a menu (the list reads ?per= through nu_per_param() and keeps it in $params). */
function nu_list_footer(int $total, int $page, int $per, string $route, array $params, string $class = 'card-footer', string $param = 'pg',
	string $anchor = '', bool $perMenu = false, string $id = ''): void
{
	// $param: name of the page parameter (two lists on one page); $anchor: '#id' the links jump to
	$pages = (int)max(1, ceil($total / max(1, $per)));
	$page  = max(1, min($page, $pages));
	$link  = static fn(int $p) => h(nu_url($route, [$param => $p > 1 ? $p : null] + $params) . $anchor);
	$btn   = static function (bool $on, int $p, string $cls, string $html, string $label) use ($link): string {
		return $on ? '<a class="nuPagerBtn ' . $cls . '" href="' . $link($p) . '" aria-label="' . h($label) . '" title="' . h($label) . '">' . $html . '</a>'
			: '<span class="nuPagerBtn ' . $cls . ' disabled" role="link" aria-disabled="true" aria-label="' . h($label) . '">' . $html . '</span>';   // aria-label needs a role
	};
	?>
	<div class="nuListFooter <?= h($class) ?><?= $pages <= 1 ? ' nuListFooterOne' : '' ?>"<?= $id !== '' ? ' id="' . h($id) . '"' : '' ?>>
		<div class="nuLfTotal"><?= h(t('lf_total')) ?> <b><?= number_format($total, 0, '.', "'") ?></b> <span class="nuLfUnit"><?= h(t($total === 1 ? 'lf_record' : 'lf_records')) ?></span></div>
		<?php if ($pages > 1): // everything on one page: only the total – no disabled pager, no page size ?>
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
		<?php if ($perMenu):
			$choices = array_unique(array_merge(NU_PER_MENU, [$per])); sort($choices); ?>
		<div class="nuLfPer dropup">
			<button type="button" class="btn btn-sm nuLfPerBtn dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false" title="<?= h(t('lf_per_choose')) ?>"><?= h(t('lf_per_page', $per)) ?></button>
			<ul class="dropdown-menu dropdown-menu-end nuLfPerMenu">
				<?php foreach ($choices as $c): ?>
					<li><a class="dropdown-item<?= $c === $per ? ' active' : '' ?>"
						href="<?= h(nu_url($route, ['per' => $c, $param => null] + $params) . $anchor) ?>"<?= $c === $per ? ' aria-current="true"' : '' ?>><?= h(t('lf_per_page', $c)) ?></a></li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php else: ?>
		<div class="nuLfPer"><?= h(t('lf_per_page', $per)) ?></div>
		<?php endif; ?>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * Search field of a list page (no label, placeholder "Search…"; $hint – what is searched – as tooltip):
 * search button at the right end of the field and, when $filterId is given, the filter
 * button (funnel) right of it that shows / hides the element #$filterId (a Bootstrap .collapse holding the filters).
 * $filtered = a filter is set: the filters start open and the button is blue.
 * $resetUrl: a search or filter is set – the × (reset) button right of the funnel (or of the search field), same size.
 */
/** $inGroup: HTML after the search button inside the input group (the ▾ of the advanced search). */
function nu_list_search(string $value, string $hint = '', string $filterId = '', bool $filtered = false, ?string $resetUrl = null, string $inGroup = ''): string
{
	$out = '<div class="d-flex gap-2 nuListSearch"><div class="input-group">'
		. '<input type="search" class="form-control" id="q" name="q" value="' . h($value) . '" placeholder="' . h(t('search_ph')) . '" aria-label="' . h(t('search')) . '" data-nu-autofocus'
		. ($hint !== '' ? ' title="' . h($hint) . '"' : '') . '>'
		. '<button class="btn btn-outline-secondary nuSearchBtn" type="submit" title="' . h(t('search')) . '" aria-label="' . h(t('search'))
			. '"><i class="bi bi-search"></i></button>' . $inGroup . '</div>';
	if ($filterId !== '') {
		$out .= '<button class="btn btn-outline-secondary nuSearchBtn nuFilterToggle' . ($filtered ? ' active' : '') . '" type="button" data-bs-toggle="collapse" data-bs-target="#' . h($filterId) . '"'
			. ' aria-expanded="' . ($filtered ? 'true' : 'false') . '" aria-controls="' . h($filterId) . '" title="' . h(t('filter')) . '" aria-label="' . h(t('filter')) . '">'
			. '<i class="bi bi-funnel' . ($filtered ? '-fill' : '') . '"></i></button>';
	}
	if ($resetUrl !== null) {
		$out .= '<a class="btn btn-outline-secondary nuSearchBtn nuListResetBtn" href="' . h($resetUrl) . '" title="' . h(t('reset')) . '" aria-label="' . h(t('reset')) . '"><i class="bi bi-x-lg"></i></a>';
	}
	return $out . '</div>';
}

/**
 * Back button of nuBuilder Next's own pages: one step up – the parent page of the breadcrumb, otherwise the start page.
 * $route: the page (default ?p=); $url: an own target (e.g. a list with its search).
 */
/** "Or install it as a library pack: Update → Libraries" – a link for users with the update right, plain text otherwise. */
function nu_lp_hint(string $key = 'lp_get_hint'): string
{
	return NuAdmin::can('update') ? '<a href="' . h(nu_url('update') . '#libraries') . '" class="nuLpHint">' . h(t($key)) . '</a>' : h(t($key));
}

/** Searchable selects (Tom Select): <select class="nuListTomSelect"> on own pages – CSS for the head, scripts for the end of the page
 *  (assets/js/nulistselect.js turns every such select into a Tom Select; options may carry data-sub as a second line). */
function nu_ts_head(): string
{
	return '<link rel="stylesheet" href="' . nu_asset(NU_TOMSELECT . '/tom-select.bootstrap5.min.css') . '">';
}
function nu_ts_foot(): string
{
	return '<script src="' . nu_asset(NU_TOMSELECT . '/tom-select.complete.min.js') . '"></script><script src="' . nu_asset('assets/js/nulistselect.js') . '"></script>';
}

/**
 * Key after the label of a field whose value must be unique: small neutral Bootstrap icon, focusable, with its own tooltip –
 * "Must be unique …" or, with $required (after the red *), "Required, and must be unique …". Used by the form runtime (NuRender::marks)
 * and nuBuilder Next's own record pages. Tooltips are initialised globally (assets/js/nuapp.js).
 */
function nu_unique_mark(bool $required = false, bool $hidden = false): string
{
	$tip = t($required ? 'val_unique_req_tip' : 'val_unique_tip');
	return '<span class="nuMark nuMarkUnique"' . ($hidden ? ' hidden' : '') . ' tabindex="0" role="img" data-bs-toggle="tooltip" aria-label="' . h($tip) . '" title="' . h($tip) . '"><i class="bi bi-key" aria-hidden="true"></i></span>';
}

/**
 * "Download context ▾" of Form with AI (NuAiContext): main button = one Markdown file, menu = .md / zip + switch "Full function
 * reference". Buttons carry data-nu-ctx="md|zip", the switch data-nu-ctx-full; assets/js/nuformai.js posts action=context.
 */
function nu_ai_context_menu(string $id, string $tipKey = 'fai_ctx_tip'): string
{
	$tip = t($tipKey);
	return '<div class="btn-group" id="' . h($id) . '">'
		. '<button type="button" class="btn btn-outline-primary" data-nu-ctx="md" id="' . h($id) . 'Md"><i class="bi bi-file-earmark-arrow-down me-1" aria-hidden="true"></i>' . h(t('fai_ctx')) . '</button>'
		. '<button type="button" class="btn btn-outline-primary dropdown-toggle dropdown-toggle-split" data-bs-toggle="dropdown" aria-expanded="false" id="'
			. h($id) . 'More" aria-label="' . h(t('fai_ctx_more')) . '"></button>'
		. '<ul class="dropdown-menu" aria-labelledby="' . h($id) . 'More">'
		. '<li><button type="button" class="dropdown-item" data-nu-ctx="md"><i class="bi bi-filetype-md me-2" aria-hidden="true"></i>' . h(t('fai_ctx_md')) . '</button></li>'
		. '<li><button type="button" class="dropdown-item" data-nu-ctx="zip"><i class="bi bi-file-zip me-2" aria-hidden="true"></i>' . h(t('fai_ctx_zip')) . '</button></li>'
		. '<li><hr class="dropdown-divider"></li>'
		. '<li><div class="dropdown-item-text"><div class="form-check form-switch mb-0"><input class="form-check-input" type="checkbox" role="switch" id="' . h($id) . 'Full" data-nu-ctx-full>'
		. '<label class="form-check-label" for="' . h($id) . 'Full">' . h(t('fai_ctx_full')) . '</label></div></div></li>'
		. '</ul></div>'
		. nu_info_tip($tip, '');
}

function nu_back_button(?string $route = null, ?string $url = null, string $class = ''): string
{
	static $parent = [
		// the tools of the former Admin tools page go back to the start page
		'adminroles' => 'home',
		'cloner' => 'forms', 'formai' => 'forms', 'procedures' => 'forms', 'reports' => 'forms', 'prompts' => 'forms', 'formats' => 'forms', 'items' => 'forms',
		'files' => 'forms', 'translations' => 'forms', 'sqlbuilder' => 'reports', 'apiplayground' => 'apiclients', 'permissions' => 'users', 'userfields' => 'users',
	];
	$route ??= (string)($_GET['p'] ?? '');
	$url ??= nu_url($parent[$route] ?? 'home');
	return '<a class="btn nuBtnAct nuBackBtn' . ($class !== '' ? ' ' . $class : '') . '" href="' . h($url) . '"><i class="bi bi-arrow-left me-1"></i>' . h(t('back')) . '</a>';
}

/**
 * The header of an own page (review E1) – one pattern for every page that is not a list card: breadcrumb · icon + h1
 * (+ (i) tip) · subtitle · the buttons row (Back first). Options: icon (bi-…), sub (text), sub_html, tip (text of an (i) icon),
 * buttons (HTML after Back), back (null = nu_back_button(), false = none, string = its URL), crumbs ([[label, url], …] between
 * Home and the page – default: Settings / section inside the settings centre, else the Back target's title), id (of the bar),
 * h1_id, class (more classes of the bar). List pages keep NuListView's card with the title bar inside.
 */
/**
 * (i) tooltip next to a label or title – the one markup of the info icon (UI pattern, code review R3). Outside the <label>, so a
 * click does not toggle a switch. $cls: classes of the span (ms-1 by default); $wide: the wide tooltip; $trigger: e.g. 'hover focus click'.
 */
/**
 * (i) with a tooltip. $doc = id of a section of the documentation (docs/pages): a long tap on a touch screen opens it (assets/js/nuapp.js);
 * without one the long tap opens the page's own documentation (F1, <body data-nu-help>).
 */
function nu_info_tip(string $text, string $cls = 'ms-1', bool $wide = true, string $trigger = '', string $doc = ''): string
{
	return '<span class="nuInfoTip' . ($cls !== '' ? ' ' . h($cls) : '') . '" tabindex="0" role="' . ($trigger !== '' ? 'button' : 'img') . '" data-bs-toggle="tooltip"'
		. ($doc !== '' ? ' data-nu-doc="' . h(nu_url('help') . '#' . $doc) . '"' : '')
		. ($trigger !== '' ? ' data-bs-trigger="' . h($trigger) . '"' : '') . ($wide ? ' data-bs-custom-class="nuTipWide"' : '')
		. ' aria-label="' . h($text) . '" title="' . h($text) . '"><i class="bi bi-info-circle"></i></span>';
}

function nu_page_header(string $title, array $o = []): string
{
	static $label = ['forms' => 'nav_forms', 'users' => 'nav_users', 'reports' => 'rpt_title_list', 'apiclients' => 'api_title', 'dashboards' => 'dash_title',
		'settings' => 'set_title', 'update' => 'upd_title', 'email' => 'nav_email', 'themes' => 'theme_title', 'webhooks' => 'wh_title', 'dataexport' => 'dxp_title'];
	$crumbs = $o['crumbs'] ?? null;
	if ($crumbs === null) {
		$crumbs = [];
		$sec = NuSettingsHub::current();
		if ($sec !== null) {
			$crumbs[] = [t('set_title'), nu_url('settings')];
			if ($sec !== '' && isset(NuSettingsHub::SECTIONS[$sec])) {
				$pages = NuSettingsHub::pages()[$sec] ?? [];
				$first = $pages[0] ?? null;
				$secTitle = t(NuSettingsHub::SECTIONS[$sec][1]);
				if ($first && $first[1] !== $title && $secTitle !== $title) $crumbs[] = [$secTitle, (string)$first[2]];
			}
		} elseif (isset($o['parent'], $label[$o['parent']])) {
			$crumbs[] = [t($label[$o['parent']]), nu_url($o['parent'])];
		}
	}
	$bc = '<nav aria-label="breadcrumb"><ol class="breadcrumb small mb-2"><li class="breadcrumb-item"><a href="' . h(nu_url('home')) . '">' . h(t('nav_home')) . '</a></li>';
	foreach ($crumbs as [$l, $u]) $bc .= '<li class="breadcrumb-item"><a href="' . h((string)$u) . '">' . h((string)$l) . '</a></li>';
	$bc .= '<li class="breadcrumb-item active" aria-current="page">' . h($title) . '</li></ol></nav>';
	$back = array_key_exists('back', $o) ? $o['back'] : null;
	if ($back === null && ($secB = NuSettingsHub::current()) !== null) {      // inside the settings centre: back to the section (or the overview)
		$first = $secB !== '' ? ((NuSettingsHub::pages()[$secB] ?? [])[0] ?? null) : null;
		$back = $first && (string)$first[1] !== $title && t(NuSettingsHub::SECTIONS[$secB][1]) !== $title ? (string)$first[2] : nu_url('settings');
		if ($secB === '' && ($_GET['p'] ?? '') === 'settings' && ($_GET['s'] ?? '') === '') $back = null;
	}
	$btn = $back === false ? '' : (is_string($back) ? nu_back_button(null, $back) : nu_back_button());
	$tip = isset($o['tip']) && $o['tip'] !== '' ? NuListView::infoTip((string)$o['tip']) : '';
	$sub = isset($o['sub_html']) ? (string)$o['sub_html'] : (isset($o['sub']) && $o['sub'] !== '' ? h((string)$o['sub']) : '');
	return $bc . '<div class="nuTitleBar nuPageHead mb-3' . (isset($o['class']) ? ' ' . h((string)$o['class']) : '') . '"' . (isset($o['id']) ? ' id="' . h((string)$o['id']) . '"' : '') . '>'
		. '<h1 class="h4 mb-0 d-inline-flex align-items-center gap-1"' . (isset($o['h1_id']) ? ' id="' . h((string)$o['h1_id']) . '"' : '') . '>'
		. (!empty($o['icon']) ? '<i class="bi ' . h((string)$o['icon']) . ' me-1" aria-hidden="true"></i>' : '') . h($title) . $tip . (string)($o['title_html'] ?? '') . '</h1>'
		. ($sub !== '' ? '<div class="nuTitleSub text-body-secondary small">' . $sub . '</div>' : '')
		. '<div class="d-flex flex-wrap gap-2">' . $btn . (string)($o['buttons'] ?? '') . '</div></div>';
}

/** Empty state of an own list (review M10): icon, one sentence, "Add the first one" – as NuListView::emptyState. */
function nu_empty_state(string $icon, string $text, ?string $addUrl = null): string
{
	return '<div class="text-center py-5 px-3 nuLvEmpty nuLvEmptyIntro"><i class="bi ' . h($icon) . ' fs-2 d-block mb-2 text-body-secondary" aria-hidden="true"></i>'
		. '<p class="mb-3 mx-auto nuLvEmptyText">' . h($text) . '</p>'
		. ($addUrl !== null ? '<a class="btn btn-primary" href="' . h($addUrl) . '"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>' . h(t('lv_empty_first')) . '</a>' : '') . '</div>';
}

/** $attrs: more attributes of the th, already escaped (e.g. ' data-nu-col="name"' for the column widths of NuListView) */
/** Sortable column header link. */
function nu_sort_th(string $label, string $col, string $sort, string $dir, string $route, array $params, string $class = '', string $attrs = ''): string
{
	$isCur = $sort === $col;
	$next  = ($isCur && $dir === 'asc') ? 'desc' : 'asc';
	$icon  = $isCur ? ($dir === 'asc' ? ' <i class="bi bi-caret-up-fill"></i>' : ' <i class="bi bi-caret-down-fill"></i>') : '';
	$aria  = $isCur ? ' aria-sort="' . ($dir === 'asc' ? 'ascending' : 'descending') . '"' : '';
	return '<th scope="col"' . ($class !== '' ? ' class="' . h($class) . '"' : '') . $attrs . $aria . '><a class="nu-sort" href="'
		. h(nu_url($route, ['sort' => $col, 'dir' => $next] + $params)) . '">' . h($label) . $icon . '</a></th>';
}

function nu_invalid(array $errors, string $field): string
{
	return isset($errors[$field]) ? ' is-invalid' : '';
}

function nu_feedback(array $errors, string $field): string
{
	return isset($errors[$field]) ? '<div class="invalid-feedback">' . h($errors[$field]) . '</div>' : '';
}

/** Head, body and the theme / language bar of the centered sign-in pages (shared by nu_center_start() and the sign-in page, code review D5). */
function nu_center_top(string $title): void
{
	NuCustomCode::$context = 'login';                  // Global JS & CSS: scope "sign-in pages"
	nu_head($title);
	?>
<body class="nu-center nu-login"<?= NuCustomCode::bodyAttrs() ?>>
<main class="nu-card-narrow">
	<div class="d-flex justify-content-end align-items-center gap-2 mb-3">
		<?= NuBranding::envBadge('me-auto') ?>
		<?php nu_theme_menu(); ?>
		<?php nu_lang_menu(); ?>
	</div>
<?php
}

/** Centered card page (login, 2FA, password reset) – no navigation, no login required. */
function nu_center_start(string $title, string $subtitle = '', string $icon = ''): void
{
	nu_center_top($title);
	?>
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
<script src="<?= nu_asset('assets/js/nuutil.js') ?>"></script>
<script src="<?= nu_asset('assets/js/nuapp.js') ?>"></script>
<?= NuCustomCode::jsTag() ?>
<?= $extraBody ?>
</body>
</html>
<?php
}

/**
 * Help and tutorial: a notice when the daily GitHub check found a newer version – the texts here belong to the installed
 * version, the newer ones come with the update. Only for users who may update; no request to GitHub here (stored result).
 */
function nu_docs_update_notice(string $key): string
{
	if (!NuAdmin::can('update', 'view')) return '';
	try { $n = NuGitHub::newer(); } catch (Throwable) { return ''; }
	if (!$n) return '';
	return '<div class="alert alert-info small py-2 d-flex flex-wrap gap-2 align-items-center" id="nuDocsNewer" role="status"><i class="bi bi-cloud-arrow-down"></i>'
		. '<span class="me-auto">' . h(t($key, NU_VERSION, (string)$n['version'])) . '</span>'
		. '<a class="alert-link" href="' . h(nu_url('update')) . '">' . h(t('docs_newer_link')) . '</a></div>';
}

/**
 * The access levels of a procedure or report as a ticked list with search and All / None (code review D5: shared by both record pages;
 * assets/js/nuprocedures.js / nureports.js filter it). $access: rows of zzzzsys_access with 'granted'.
 */
function nu_access_checklist(string $searchId, string $listId, array $access, string $hint): string
{
	$o = '<div class="d-flex flex-wrap gap-2 mb-2"><input type="search" class="form-control form-control-sm w-auto" id="' . h($searchId) . '" placeholder="'
		. h(t('search')) . '" aria-label="' . h(t('search')) . '">'
		. '<button class="btn btn-sm btn-outline-secondary" type="button" data-access-all="1">' . h(t('proc_access_all')) . '</button>'
		. '<button class="btn btn-sm btn-outline-secondary" type="button" data-access-all="0">' . h(t('proc_access_none')) . '</button></div>'
		. '<div class="list-group procAccessList" id="' . h($listId) . '">';
	if (!$access) $o .= '<div class="text-body-secondary small">' . h(t('no_records')) . '</div>';
	foreach ($access as $a) {
		$o .= '<label class="list-group-item d-flex gap-2 align-items-center" data-text="' . h(strtolower($a['sal_code'] . ' ' . $a['sal_description'] . ' ' . $a['sal_group'])) . '">'
			. '<input class="form-check-input mt-0" type="checkbox" name="access[]" value="' . h($a['zzzzsys_access_id']) . '"' . ($a['granted'] ? ' checked' : '') . '>'
			. '<span class="fw-semibold">' . h($a['sal_code']) . '</span><span class="small text-body-secondary me-auto">' . h($a['sal_description']) . '</span>'
			. ((string)$a['sal_group'] !== '' ? '<span class="badge bg-secondary-subtle text-secondary-emphasis">' . h($a['sal_group']) . '</span>' : '')
			. '</label>';
	}
	return $o . '</div><p class="small text-body-secondary mt-2 mb-0">' . h($hint) . '</p>';
}

/** <script> / <link> tags of the switched-on plugins for the kind of page (form pages or the others); '' while none or on failure. */
function nu_plugin_tags(string $kind): string
{
	if (!NuDB::$connected) return '';
	try {
		$where = NuCustomCode::page() === 'form' ? 'form' : 'app';
		return $kind === 'js' ? NuPlugin::jsTags($where) : NuPlugin::cssTags($where);
	} catch (Throwable $ig) {
		nu_ignored($ig, 'plugin assets');
		return '';
	}
}

/** Entries of the user menu the plugins add: [label, icon, url]. */
function nu_plugin_menu(): array
{
	try {
		return NuPlugin::menuItems();
	} catch (Throwable $ig) {
		nu_ignored($ig, 'plugin menu');
		return [];
	}
}
