<?php
defined('NU_PAGE') || exit;

/*
 * Template of a nuBuilder Next page (form type "page"). "Create from template" in the form builder copies this folder to
 * custom/pages/<form code>/. Read custom/PAGE_GUIDE.md before changing it.
 */

/** @var NuPageContext $nu */
$nu->api(1);                // the page API version this page is written for (custom/PAGE_GUIDE.md, "API versions")
$u = $nu->user();

if ($nu->action() === 'hello' && $nu->isPost()) {
	// page settings (form builder → Settings JSON; settings.example.json shows the keys): greeting = the first word of the answer
	$nu->json(['message' => $nu->setting('greeting', 'Hello') . ' ' . $u['display'], 'record' => $nu->recordId()]);
}
?><!doctype html>
<html lang="<?= $nu->h($nu->lang()) ?>" data-theme="<?= $nu->h($nu->theme()) ?>">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title><?= $nu->h($nu->form()['description']) ?></title>
	<link rel="stylesheet" href="<?= $nu->h($nu->asset('assets/app.css')) ?>">
</head>
<body>
	<main>
		<h1><?= $nu->h($nu->form()['description']) ?></h1>
		<p>Signed in as <strong><?= $nu->h($u['display']) ?></strong><?= $u['team'] !== '' ? ' (' . $nu->h($u['team']) . ')' : '' ?></p>
		<?php if ($nu->setting('show_context', true)): ?>
		<p>Record: <?= $nu->h($nu->recordId() ?? '-') ?> · opened from: <?= $nu->h($nu->parent()['form_code'] ?? '-') ?></p>
		<?php endif; ?>
		<button type="button" id="hello" data-url="<?= $nu->h($nu->url(['action' => 'hello'])) ?>">Say hello</button>
		<p id="answer" aria-live="polite"></p>
	</main>
	<?= $nu->sdk() ?>
	<script src="<?= $nu->h($nu->asset('assets/app.js')) ?>"></script>
</body>
</html>
