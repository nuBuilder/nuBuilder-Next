<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * Admin tools → To do (?p=todo): TODO.md of the installation as a page – only in developer mode (globeadmin + $nuConfigDevMode = true,
 * nuBuilder's switch, NuHash::devMode()), never for demo users. Read only; the small Markdown renderer covers what TODO.md uses:
 * headings, lists with [ ] / [x], indented continuation lines, `code`, **bold**, *italic*.
 */

$u = NuAuth::user();
if (!NuHash::devMode() || !empty($u['demo'])) {
	http_response_code(404);
	nu_fatal(t('err_not_found'), '', true);
}

$file = NU_ROOT . '/TODO.md';
$md = is_file($file) ? (string)file_get_contents($file) : '';

/** Inline Markdown on escaped text: `code`, **bold**, *italic*. */
$inline = static function (string $s): string {
	$parts = preg_split('/(`[^`]*`)/', $s, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$s];
	$out = '';
	foreach ($parts as $p) {
		if ($p !== '' && $p[0] === '`' && str_ends_with($p, '`') && strlen($p) > 1) { $out .= '<code>' . h(substr($p, 1, -1)) . '</code>'; continue; }
		$p = h($p);
		$p = (string)preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $p);
		$p = (string)preg_replace('/(?<![\w*])\*(?!\s)(.+?)(?<!\s)\*(?![\w*])/', '<em>$1</em>', $p);
		$out .= $p;
	}
	return $out;
};

// parse: blocks of headings, paragraphs and (nested) list items; continuation lines belong to the item above
$html = ''; $open = 0; $done = 0;
$lines = preg_split('/\r?\n/', $md) ?: [];
$items = []; $para = [];
$flushPara = static function () use (&$para, &$html, $inline) {
	if ($para) $html .= '<p>' . $inline(implode(' ', $para)) . '</p>';
	$para = [];
};
$flushList = static function () use (&$items, &$html, $inline, &$open, &$done) {
	if (!$items) return;
	$depth = -1;
	foreach ($items as [$level, $state, $text]) {
		while ($depth < $level) { $html .= '<ul class="nuTodoList">'; $depth++; }
		while ($depth > $level) { $html .= '</ul>'; $depth--; }
		$box = '';
		if ($state !== null) {
			$state ? $done++ : $open++;
			$box = '<input class="form-check-input me-2" type="checkbox" disabled' . ($state ? ' checked' : '') . ' aria-label="' . h($state ? 'done' : 'open') . '">';
		}
		$html .= '<li class="' . ($state === null ? '' : 'nuTodoTask') . ($state ? ' nuTodoDone' : '') . '">' . $box . '<span>' . $inline($text) . '</span></li>';
	}
	while ($depth >= 0) { $html .= '</ul>'; $depth--; }
	$items = [];
};
foreach ($lines as $line) {
	if (trim($line) === '') { $flushPara(); continue; }
	if (preg_match('/^(#{1,4})\s+(.*)$/', $line, $m)) {
		$flushPara(); $flushList();
		$lvl = min(6, strlen($m[1]) + 1);
		$html .= '<h' . $lvl . ' class="nuTodoH' . strlen($m[1]) . '">' . $inline($m[2]) . '</h' . $lvl . '>';
		continue;
	}
	if (preg_match('/^(\s*)[-*]\s+(?:\[( |x|X)\]\s+)?(.*)$/', $line, $m)) {
		$flushPara();
		$items[] = [intdiv(strlen(str_replace("\t", '  ', $m[1])), 2), $m[2] === '' ? null : strtolower($m[2]) === 'x', $m[3]];
		continue;
	}
	if ($items && preg_match('/^\s+\S/', $line)) {                     // continuation of the list item above
		$items[count($items) - 1][2] .= ' ' . trim($line);
		continue;
	}
	$flushList();
	$para[] = trim($line);
}
$flushPara(); $flushList();

nu_layout_start(t('todo_title'), 'home', ['container' => 'container-xl']);
?>
<nav aria-label="breadcrumb"><ol class="breadcrumb small mb-2">
	<li class="breadcrumb-item"><a href="<?= h(nu_url('home')) ?>"><?= h(t('nav_home')) ?></a></li>
	<li class="breadcrumb-item active" aria-current="page"><?= h(t('todo_title')) ?></li>
</ol></nav>
<div class="nuTitleBar mb-3">
	<h1 class="h3 mb-0"><i class="bi bi-list-check me-2"></i><?= h(t('todo_title')) ?></h1>
	<?php if ($md !== ''): ?><div class="nuTitleSub text-body-secondary small"><?= h(t('todo_counts', $open, $done)) ?> · <code>TODO.md</code> · <?= h(date('d.m.Y H:i', (int)filemtime($file))) ?></div><?php endif; ?>
	<div class="d-flex flex-wrap gap-2 align-items-center"><?= nu_back_button() ?></div>
</div>
<?php if ($md === ''): ?>
	<div class="alert alert-warning"><?= h(t('todo_missing')) ?></div>
<?php else: ?>
	<div class="card shadow-sm"><div class="card-body nuTodo"><?= $html ?></div></div>
<?php endif; ?>
<?php nu_layout_end();
