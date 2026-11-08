<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * User help pages – the reader (0.8.1023, core/nuuserhelp.php). Everyone signed in; what a user sees depends on the page's access
 * levels and the [[level: …]] parts of its text.
 *   ?p=userhelp[&c=<code>]           contents + one page (no code: the first page)
 *   ?p=userhelp&q=<words>            search
 *   &frag=1                          JSON for the side panel of the form pages (assets/js/nuuserhelp.js): {ok, code, title, html, crumbs, children, url, edit}
 *   &as=<access id> / &marks=0       editors: read as an access level / without the marked parts
 */
NuSchema::ensure();
$editor = NuUserHelp::canManage();
$as = $editor ? (string)($_GET['as'] ?? '') : '';
$marks = $editor && ($_GET['marks'] ?? '') === '0' ? false : null;
$ctx = $as !== '' || $marks === false ? NuUserHelp::ctx($as, $marks ?? true) : NuUserHelp::ctx();
$code = trim((string)($_GET['c'] ?? ''));
$q = trim((string)($_GET['q'] ?? ''));
$tree = NuUserHelp::tree($ctx);
if ($code === '' && $q === '' && $tree) $code = (string)$tree[0]['code'];
$page = $code !== '' ? NuUserHelp::page($code, $ctx) : null;

if (($_GET['frag'] ?? '') === '1') {
	session_write_close();
	header('Content-Type: application/json; charset=utf-8');
	if ($q !== '') { echo json_encode(['ok' => true, 'results' => NuUserHelp::search($q, $ctx)]); exit; }
	if (!$page) { http_response_code(404); echo json_encode(['ok' => false, 'error' => t('uh_not_found')]); exit; }
	echo json_encode(['ok' => true, 'code' => $page['code'], 'title' => $page['title'], 'html' => $page['html'], 'crumbs' => $page['crumbs'], 'children' => $page['children'],
		'url' => nu_url('userhelp', ['c' => $page['code']]), 'edit' => NuUserHelp::canEdit() ? nu_url('helppages', ['id' => $page['id']]) : '']);
	exit;
}

// pictures of the help pages may come from other sites (https) – the rest of the strict policy stays
header("Content-Security-Policy: default-src 'self'; img-src 'self' data: https:; style-src 'self'; script-src 'self'; font-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
$results = $q !== '' ? NuUserHelp::search($q, $ctx) : null;
$keep = array_filter(['as' => $as, 'marks' => $marks === false ? '0' : null], static fn($x) => $x !== null && $x !== '');
$navTree = static function (array $items, int $depth = 0) use (&$navTree, $code, $keep): string {
	if (!$items) return '';
	$o = '<ul class="nuUhNavList' . ($depth ? ' nuUhNavSub' : '') . '">';
	foreach ($items as $it) {
		$cur = $it['code'] === $code;
		$o .= '<li><a class="nuUhNavLink' . ($cur ? ' active' : '') . (!$it['active'] ? ' nuUhInactive' : '') . '" href="' . h(nu_url('userhelp', ['c' => $it['code']] + $keep)) . '"' . ($cur ? ' aria-current="page"' : '')
			. ' data-code="' . h($it['code']) . '">' . h($it['title']) . '</a>' . $navTree($it['children'], $depth + 1) . '</li>';
	}
	return $o . '</ul>';
};
$title = $results !== null ? t('uh_search_title', $q) : ($page['title'] ?? t('uh_title'));
nu_layout_start($title, 'home', ['container' => 'container-xl']);
$crumbs = [[t('uh_title'), nu_url('userhelp', $keep)]];
if ($page && $results === null) { foreach ($page['crumbs'] as $c) $crumbs[] = [$c['title'], nu_url('userhelp', ['c' => $c['code']] + $keep)]; $crumbs[] = [$page['title']]; }
elseif ($results !== null) $crumbs[] = [$title];
echo NuListView::crumbs($crumbs);
?>
<div class="nuUhLayout" id="nuUhReader">
	<aside class="nuUhNav" aria-label="<?= h(t('uh_contents')) ?>">
		<form method="get" action="index.php" class="mb-3" role="search" id="nuUhSearch">
			<input type="hidden" name="p" value="userhelp"><?php foreach ($keep as $k => $kv): ?><input type="hidden" name="<?= h($k) ?>" value="<?= h((string)$kv) ?>"><?php endforeach; ?>
			<div class="input-group"><input class="form-control" type="search" name="q" value="<?= h($q) ?>" placeholder="<?= h(t('uh_search_ph')) ?>" aria-label="<?= h(t('uh_search_ph')) ?>" id="nuUhQ">
				<button class="btn btn-outline-secondary" type="submit" aria-label="<?= h(t('search')) ?>"><i class="bi bi-search" aria-hidden="true"></i></button></div>
		</form>
		<details class="nuUhNavFold" open>
			<summary class="fw-semibold small text-uppercase text-body-secondary mb-2"><?= h(t('uh_contents')) ?></summary>
			<?= $tree ? $navTree($tree) : '<div class="small text-body-secondary">' . h(t('uh_empty')) . '</div>' ?>
		</details>
		<?php if ($editor): ?>
		<form method="get" action="index.php" class="nuUhEditorBox mt-4 small d-none d-md-block" id="nuUhViewAs">
			<input type="hidden" name="p" value="userhelp"><input type="hidden" name="c" value="<?= h($code) ?>">
			<div class="fw-semibold mb-1"><i class="bi bi-eye me-1"></i><?= h(t('uh_view_as')) ?><?= NuListView::infoTip(t('uh_view_as_tip')) ?></div>
			<select class="form-select form-select-sm mb-2 js-autosubmit" name="as" id="nuUhAs">
				<option value=""><?= h(t('uh_view_editor')) ?></option>
				<option value="*"<?= $as === '*' ? ' selected' : '' ?>><?= h(t('uh_view_none')) ?></option>
				<?php foreach (nu_db()->all('SELECT zzzzsys_access_id, sal_code, sal_description FROM zzzzsys_access ORDER BY sal_code') as $a): ?>
					<option value="<?= h((string)$a['zzzzsys_access_id']) ?>"<?= $as === (string)$a['zzzzsys_access_id'] ? ' selected' : '' ?>><?= h((string)$a['sal_code']) ?> – <?= h((string)$a['sal_description']) ?></option>
				<?php endforeach; ?>
			</select>
			<?php if ($as === ''): ?><div class="form-check form-switch"><input class="form-check-input js-autosubmit" type="checkbox" role="switch" name="marks" value="0" id="nuUhMarks"<?= $marks === false ? ' checked' : '' ?>>
				<label class="form-check-label" for="nuUhMarks"><?= h(t('uh_hide_marks')) ?></label></div><?php endif; ?>
			<a class="btn btn-sm btn-outline-secondary mt-2" href="<?= h(nu_url('helppages')) ?>" id="nuUhManage"><i class="bi bi-journal-bookmark me-1"></i><?= h(t('uh_admin_title')) ?></a>
		</form>
		<?php endif; ?>
	</aside>
	<main class="nuUhMain">
	<?php if ($results !== null): ?>
		<h1 class="h4 mb-3"><?= h($title) ?></h1>
		<?php if (!$results): ?><p class="text-body-secondary" id="nuUhNoHits"><?= h(t('uh_no_hits')) ?></p><?php endif; ?>
		<div class="list-group" id="nuUhHits">
			<?php foreach ($results as $r): ?><a class="list-group-item list-group-item-action" href="<?= h(nu_url('userhelp', ['c' => $r['code']] + $keep)) ?>">
				<div class="fw-semibold"><?= h($r['title']) ?></div><?php if ($r['snippet'] !== ''): ?><div class="small text-body-secondary"><?= h($r['snippet']) ?></div><?php endif; ?></a><?php endforeach; ?>
		</div>
	<?php elseif ($page): ?>
		<div class="d-flex flex-wrap align-items-start gap-2 mb-3">
			<h1 class="h3 mb-0 me-auto" id="nuUhTitle"><?= h($page['title']) ?></h1>
			<?php if (!$page['active']): ?><span class="badge text-bg-secondary"><?= h(t('uh_inactive')) ?></span><?php endif; ?>
			<button type="button" class="btn btn-sm btn-outline-secondary d-print-none" id="nuUhPrint" title="<?= h(t('print')) ?>"><i class="bi bi-printer" aria-hidden="true"></i><span class="visually-hidden"><?= h(t('print')) ?></span></button>
			<?php if (NuUserHelp::canEdit()): ?><a class="btn btn-sm btn-outline-primary d-print-none" href="<?= h(nu_url('helppages', ['id' => $page['id']])) ?>" id="nuUhEdit"><i class="bi bi-pencil me-1"></i><?= h(t('edit')) ?></a>
				<a class="btn btn-sm btn-outline-secondary d-print-none" href="<?= h(nu_url('helppages', ['id' => 'new', 'parent' => $page['code']])) ?>" id="nuUhSub"><i class="bi bi-plus-lg me-1"></i><?= h(t('uh_new_sub')) ?></a><?php endif; ?>
		</div>
		<article class="nuUhArticle" id="nuUhArticle"><?= $page['html'] ?></article>
		<?php if ($page['children']): ?>
			<nav class="nuUhChildren mt-4" aria-label="<?= h(t('uh_in_section')) ?>"><h2 class="h6 text-uppercase text-body-secondary"><?= h(t('uh_in_section')) ?></h2>
				<ul><?php foreach ($page['children'] as $ch): ?><li><a href="<?= h(nu_url('userhelp', ['c' => $ch['code']] + $keep)) ?>"><?= h($ch['title']) ?></a></li><?php endforeach; ?></ul></nav>
		<?php endif; ?>
	<?php else: ?>
		<div class="text-center text-body-secondary py-5" id="nuUhNone">
			<i class="bi bi-journal-bookmark display-5 d-block mb-2" aria-hidden="true"></i>
			<p class="mb-3"><?= h($code !== '' ? t('uh_not_found') : t('uh_empty')) ?></p>
			<?php if (NuUserHelp::canEdit()): ?><a class="btn btn-primary" href="<?= h(nu_url('helppages', ['id' => 'new', 'code' => $code !== '' && preg_match(NuUserHelp::CODE_RE, $code) ? $code : null])) ?>" id="nuUhCreate"><i class="bi bi-plus-lg me-1"></i><?= h(t('uh_new')) ?></a><?php endif; ?>
		</div>
	<?php endif; ?>
	</main>
</div>
<?php nu_layout_end('<script src="' . nu_asset('assets/js/nuuserhelp.js') . '"></script>');
