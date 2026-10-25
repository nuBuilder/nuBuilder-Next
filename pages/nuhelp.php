<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * Documentation (English): one file per page in docs/pages/, listed in reading order in docs/toc.json (see the comment there).
 * Filtered on the server so every user receives only what is relevant for him.
 *   ?p=help            everything the current user may see (globeadmin: all)
 *   ?p=help&doc=user   globeadmin: preview of the documentation of a user without special rights
 *   ?p=help&q=…        opens with a search; #id jumps to a page or section (in either tab)
 *   ?p=help&tab=ref    opens the tab Code reference (chapters with "tab": "ref"); ?p=help&part=ref = its HTML for nuhelp.js
 * Chapters and pages are numbered here after filtering (1, 1.1 …); index (h2/h3), search and the switch
 * "Show advanced topics" (data-level="advanced") are handled in the browser by assets/js/nuhelp.js.
 */

$u = NuAuth::user();
$doc = (string)($_GET['doc'] ?? '');
$admin = NuAdmin::any() && $doc !== 'user';             // globeadmin and users with an admin role
$preview = NuAdmin::any() && $doc === 'user';

/** Visible for the current reader? data-doc="admin" → admin only; data-doc="dev" → globeadmin in developer mode; data-acl="a,b" → view right on one of the forms. */
$dev = $admin && NuHash::devMode();
$visible = static function (DOMElement $el) use ($admin, $preview, $dev): bool {
	if ($el->getAttribute('data-doc') === 'dev') return $dev;
	if ($el->getAttribute('data-doc') === 'admin') return $admin;
	$acl = trim($el->getAttribute('data-acl'));
	if ($acl === '' || $admin) return true;
	if ($preview) return false;   // preview = user without any extra rights
	foreach (array_filter(array_map('trim', explode(',', $acl))) as $f) {
		if (NuAcl::can($f)) return true;
	}
	return false;
};

/** The table of contents (docs/toc.json): list of chapters {id, title, tab, file, pages: [{id, title, file}]}. */
$toc = (static function (): array {
	$f = NU_ROOT . '/docs/toc.json';
	$j = is_file($f) ? json_decode((string)file_get_contents($f), true) : null;
	return is_array($j['chapters'] ?? null) ? $j['chapters'] : [];
})();

/** The source of one tab: its chapter and page files in order, each <section> marked with its level (1 chapter, 2 page). */
$source = static function (string $tab) use ($toc): string {
	$dir = NU_ROOT . '/docs/pages/';
	$read = static function (string $file, int $level) use ($dir): string {
		if (!preg_match('/^[A-Za-z0-9._-]+\.htm$/', $file) || !is_file($dir . $file)) return '';
		return preg_replace('/<section\b/', '<section data-nu-level="' . $level . '"', (string)file_get_contents($dir . $file), 1) ?? '';
	};
	$src = '';
	foreach ($toc as $ch) {
		if (($ch['tab'] ?? 'guide') !== $tab) continue;
		$src .= $read((string)($ch['file'] ?? ''), 1);
		foreach ((array)($ch['pages'] ?? []) as $pg) $src .= $read((string)($pg['file'] ?? ''), 2);
	}
	return $src === '' ? '' : '<main>' . $src . '</main>';
};

/**
 * One tab, filtered for the reader and numbered. $full = false: without the function reference (only to see whether anything
 * is left). @return string the HTML inside <main>, '' when nothing is left
 */
$render = static function (string $tab, bool $full = true) use ($visible, $admin, $source): string {
	$src = $source($tab);
	if ($src === '') return '';
	// function reference: docs/nunext-functions.json, admin sections only
	$tag = '<div data-nu-fnref="tabs"></div>';
	if (str_contains($src, $tag)) $src = str_replace($tag, $admin && $full ? NuFnRef::tabs() : '', $src);
	$dom = new DOMDocument();
	libxml_use_internal_errors(true);
	$dom->loadHTML('<?xml encoding="utf-8"?>' . $src, LIBXML_NONET);
	libxml_clear_errors();
	$xp = new DOMXPath($dom);
	foreach (iterator_to_array($xp->query('//*[@data-doc or @data-acl]')) as $el) {
		if ($el->parentNode && !$visible($el)) $el->parentNode->removeChild($el);
	}
	foreach (iterator_to_array($xp->query('//*[@data-doc or @data-acl]')) as $el) {   // the reader needs no hints about the filter
		$el->removeAttribute('data-doc');
		$el->removeAttribute('data-acl');
	}
	$main = $dom->getElementsByTagName('main')->item(0);
	if (!$main || !$xp->query('./section', $main)->length) return '';
	// numbers of what is left: chapters 1, 2 … and their pages 1.1, 1.2 … (a page without a chapter before it keeps none)
	$c = 0; $p = 0;
	foreach (iterator_to_array($xp->query('./section', $main)) as $sec) {
		$h2 = $xp->query('./h2', $sec)->item(0);
		if (!$sec instanceof DOMElement || !$h2) continue;
		if ($sec->getAttribute('data-nu-level') === '1') { $c++; $p = 0; $num = (string)$c; }
		elseif ($c > 0) { $p++; $num = $c . '.' . $p; }
		else continue;
		$span = $dom->createElement('span', $num);
		$span->setAttribute('class', 'doc-num');
		$h2->insertBefore($dom->createTextNode(' '), $h2->firstChild);
		$h2->insertBefore($span, $h2->firstChild);
	}
	$html = '';
	foreach ($main->childNodes as $n) $html .= $dom->saveHTML($n);
	return $html;
};

// the code reference is fetched by assets/js/nuhelp.js when its tab (or a link / search into it) needs it
if (($_GET['part'] ?? '') === 'ref') {
	header('Content-Type: text/html; charset=utf-8');
	header('Cache-Control: private, no-store');
	echo $render('ref');
	exit;
}

$html = $render('guide');
/** Does the reader see anything in the code reference? (the tab is shown only then) – without building the function list. */
$hasRef = $render('ref', false) !== '';
$tab = $hasRef && ($_GET['tab'] ?? '') === 'ref' ? 'ref' : 'guide';

nu_layout_start(t('help_title'), '');
?>
<div class="nuTitleBar mb-3">
	<h1 class="h3 mb-0"><?= h(t('help_title')) ?></h1>
	<div class="nuTitleSub text-body-secondary small"><?= h(t($admin ? 'help_for_admin' : 'help_for_user')) ?></div>
	<div class="d-flex flex-wrap gap-2 align-items-center"><?= nu_back_button() ?>
	<?php if (NuAdmin::any()): ?>
		<div class="btn-group" role="group" aria-label="<?= h(t('help_view')) ?>">
			<a class="btn btn-outline-secondary<?= $admin ? ' active' : '' ?>" href="<?= h(nu_url('help')) ?>"><?= h(t('help_view_admin')) ?></a>
			<a class="btn btn-outline-secondary<?= $preview ? ' active' : '' ?>" href="<?= h(nu_url('help', ['doc' => 'user'])) ?>"><?= h(t('help_view_user')) ?></a>
		</div>
	<?php endif; ?></div>
</div>
<?= nu_docs_update_notice('docs_newer_help') ?>
<?php if ($html === ''): ?>
	<div class="alert alert-warning"><?= h(t('help_missing')) ?></div>
<?php else: ?>
<?php if ($hasRef): ?>
<ul class="nav nav-tabs mb-3" id="helpTabs" role="tablist">
	<li class="nav-item" role="presentation"><button type="button" class="nav-link<?= $tab === 'guide' ? ' active' : '' ?>" id="helpTabGuide" data-help-tab="guide" role="tab" aria-controls="helpPaneGuide" aria-selected="<?= $tab === 'guide' ? 'true' : 'false' ?>"><i class="bi bi-book me-1"></i><?= h(t('help_tab_guide')) ?></button></li>
	<li class="nav-item" role="presentation"><button type="button" class="nav-link<?= $tab === 'ref' ? ' active' : '' ?>" id="helpTabRef" data-help-tab="ref" role="tab" aria-controls="helpPaneRef" aria-selected="<?= $tab === 'ref' ? 'true' : 'false' ?>"><i class="bi bi-code-slash me-1"></i><?= h(t('help_tab_ref')) ?></button></li>
</ul>
<?php endif; ?>
<div class="row g-4">
	<aside class="col-lg-3">
		<div class="nuHelpSide">
			<label class="visually-hidden" for="helpSearch"><?= h(t('search')) ?></label>
			<input type="search" class="form-control mb-2" id="helpSearch" placeholder="<?= h(t('help_search')) ?>" value="<?= h((string)($_GET['q'] ?? '')) ?>" autocomplete="off">
			<?php if ($hasRef): ?>
			<div class="form-check form-switch small mb-2"><input class="form-check-input" type="checkbox" role="switch" id="helpAll"<?= ($_GET['all'] ?? '') === '1' ? ' checked' : '' ?>><label class="form-check-label" for="helpAll"><?= h(t('help_search_all')) ?></label></div>
			<?php endif; ?>
			<?php if ($hasRef || str_contains($html, 'data-level="advanced"')): ?>
			<div class="form-check form-switch small mb-2"><input class="form-check-input" type="checkbox" role="switch" id="helpAdvanced"><label class="form-check-label" for="helpAdvanced"><?= h(t('help_show_advanced')) ?></label></div>
			<?php endif; ?>
			<div class="small text-body-secondary mb-2" id="helpCount" aria-live="polite"></div>
			<nav id="helpToc" class="nuHelpToc" aria-label="<?= h(t('help_index')) ?>"<?= $tab === 'ref' ? ' hidden' : '' ?>></nav>
			<?php if ($hasRef): ?><nav id="helpTocRef" class="nuHelpToc" aria-label="<?= h(t('help_index')) ?>"<?= $tab === 'ref' ? '' : ' hidden' ?>></nav><?php endif; ?>
		</div>
	</aside>
	<div class="col-lg-9">
		<div class="alert alert-secondary d-none" id="helpNone"><?= h(t('help_no_match')) ?></div>
		<div id="helpPaneGuide" role="tabpanel" aria-labelledby="helpTabGuide"<?= $tab === 'ref' ? ' hidden' : '' ?>><article id="helpBody" class="nuHelpBody" lang="en"><?= $html ?></article></div>
		<?php if ($hasRef): ?>
		<div id="helpPaneRef" role="tabpanel" aria-labelledby="helpTabRef"<?= $tab === 'ref' ? '' : ' hidden' ?>>
			<div class="text-body-secondary small py-4" id="helpRefLoading"><span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span><?= h(t('help_ref_loading')) ?></div>
			<article id="helpRef" class="nuHelpBody" lang="en" data-src="<?= h(nu_url('help', ['part' => 'ref', 'doc' => $preview ? 'user' : null])) ?>"></article>
		</div>
		<?php endif; ?>
	</div>
</div>
<script type="application/json" id="helpData"><?= json_encode(['found' => t('help_found'), 'index' => t('help_index'), 'failed' => t('help_ref_failed'), 'advanced' => t('help_advanced')], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?></script>
<?php endif; ?>
<?php
nu_layout_end('<script src="' . nu_asset('assets/js/nuhelp.js') . '"></script>');
