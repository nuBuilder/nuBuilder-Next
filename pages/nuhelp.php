<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * Documentation (English): docs/nunext-docs.htm, filtered on the server so every user receives only what is relevant
 * for him (see the comment at the top of that file).
 *   ?p=help            everything the current user may see (globeadmin: all)
 *   ?p=help&doc=user   globeadmin: preview of the documentation of a user without special rights
 *   ?p=help&q=…        opens with a search; #id jumps to a chapter
 * Index (h2/h3) and search are built in the browser by assets/js/nuhelp.js.
 */

$u = NuAuth::user();
$doc = (string)($_GET['doc'] ?? '');
$admin = NuAdmin::any() && $doc !== 'user';             // globeadmin and users with an admin role (0.8.323)
$preview = NuAdmin::any() && $doc === 'user';

/** Visible for the current reader? data-doc="admin" → admin only; data-acl="a,b" → view right on one of the forms. */
$visible = static function (DOMElement $el) use ($admin, $preview): bool {
	if ($el->getAttribute('data-doc') === 'admin') return $admin;
	$acl = trim($el->getAttribute('data-acl'));
	if ($acl === '' || $admin) return true;
	if ($preview) return false;   // preview = user without any extra rights
	foreach (array_filter(array_map('trim', explode(',', $acl))) as $f) {
		if (NuAcl::can($f)) return true;
	}
	return false;
};

$html = '';
$file = NU_ROOT . '/docs/nunext-docs.htm';
if (is_file($file)) {
	$dom = new DOMDocument();
	libxml_use_internal_errors(true);
	$dom->loadHTML('<?xml encoding="utf-8"?>' . (string)file_get_contents($file), LIBXML_NONET);
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
	if ($main) foreach ($main->childNodes as $n) $html .= $dom->saveHTML($n);
}

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
<div class="row g-4">
	<aside class="col-lg-3">
		<div class="nuHelpSide">
			<label class="visually-hidden" for="helpSearch"><?= h(t('search')) ?></label>
			<input type="search" class="form-control mb-2" id="helpSearch" placeholder="<?= h(t('help_search')) ?>" value="<?= h((string)($_GET['q'] ?? '')) ?>" autocomplete="off">
			<div class="small text-body-secondary mb-2" id="helpCount" aria-live="polite"></div>
			<nav id="helpToc" class="nuHelpToc" aria-label="<?= h(t('help_index')) ?>"></nav>
		</div>
	</aside>
	<div class="col-lg-9">
		<div class="alert alert-secondary d-none" id="helpNone"><?= h(t('help_no_match')) ?></div>
		<article id="helpBody" class="nuHelpBody" lang="en"><?= $html ?></article>
	</div>
</div>
<script type="application/json" id="helpData"><?= json_encode(['found' => t('help_found'), 'index' => t('help_index')], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?></script>
<?php endif; ?>
<?php
nu_layout_end('<script src="' . nu_asset('assets/js/nuhelp.js') . '"></script>');
