<?php
defined('NU_PAGE') || exit;
/** @var NuPageContext $nu */
/** @var array $views @var string $view @var string $file @var bool $staff @var bool $ro @var ?array $flash */
$open = PizzaShop::openState($nu);
// what the script needs – one JSON block instead of many data-* attributes
$boot = [
	'view' => $file, 'staff' => $staff, 'readOnly' => $ro, 'open' => $open['open'],
	'currency' => (string)$nu->setting('currency', '$'), 'clock' => (string)$nu->setting('clock', '12h'), 'fee' => (float)$nu->setting('delivery_fee', 5), 'min' => (float)$nu->setting('min_order', 20),
	'poll' => max(5, (int)$nu->setting('poll_seconds', 15)) * 1000, 'extras' => PizzaShop::extras($nu),
	'urls' => ['order' => $nu->url(['action' => 'order']), 'mine' => $nu->url(['action' => 'mine']), 'cancel' => $nu->url(['action' => 'cancel']),
		'board' => $nu->url(['action' => 'board']), 'move' => $nu->url(['action' => 'move']), 'flag' => $nu->url(['action' => 'item_flag']),
		'svg' => $nu->url(['action' => 'svg']), 'myOrders' => $nu->url(['view' => 'mine'])],
];
$theme = $nu->theme();
?><!doctype html>
<html lang="<?= $nu->h($nu->lang()) ?>" data-bs-theme="<?= $nu->h($theme === 'dark' ? 'dark' : 'light') ?>" data-theme-pref="<?= $nu->h($theme) ?>">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title><?= $nu->h($nu->setting('shop_name', 'Pizzeria Forno')) ?></title>
	<link rel="stylesheet" href="<?= $nu->h($nu->vendor('bootstrap', 'css/bootstrap.min.css')) ?>">
	<link rel="stylesheet" href="<?= $nu->h($nu->vendor('bootstrap-icons', 'bootstrap-icons.min.css')) ?>">
	<link rel="stylesheet" href="<?= $nu->h($nu->asset('assets/app.css')) ?>">
</head>
<body class="pz-view-<?= $nu->h($file) ?>">
<header class="pz-head">
	<div class="pz-wrap pz-headin">
		<div class="pz-brand"><span class="pz-logo" aria-hidden="true"><?= PizzaSvg::draw('logo', ['tomato', 'mozzarella', 'basil'], 40) ?></span>
			<div><div class="pz-name"><?= $nu->h($nu->setting('shop_name', 'Pizzeria Forno')) ?></div>
			<div class="pz-hours <?= $open['open'] ? 'is-open' : 'is-closed' ?>"><i class="bi bi-circle-fill"></i>
				<?= $open['open'] ? 'Open until ' . $nu->h(PizzaShop::time($nu, $open['to'])) : 'Closed · opens ' . $nu->h(PizzaShop::time($nu, $open['from'])) ?></div></div></div>
		<nav class="pz-tabs" aria-label="Views">
			<?php foreach ($views as $k => [$label, $icon]): ?>
			<a href="<?= $nu->h($nu->url(['view' => $k])) ?>" class="<?= $k === $view || ($k === 'items' && $file === 'item') ? 'active' : '' ?>"
				<?= $k === $view ? 'aria-current="page"' : '' ?>><i class="bi <?= $icon ?>"></i><span><?= $nu->h($label) ?></span></a>
			<?php endforeach; ?>
		</nav>
		<div class="pz-who"><i class="bi bi-person-circle"></i> <?= $nu->h($nu->user()['display']) ?><?= $staff ? ' <span class="badge pz-staff">Back office</span>' : '' ?></div>
	</div>
</header>
<main class="pz-wrap pz-main">
	<?php if ($flash): ?><div class="alert alert-<?= $nu->h($flash[0]) ?> py-2"><?= $nu->h($flash[1]) ?></div><?php endif; ?>
	<?php if ($ro): ?><div class="alert alert-info py-2"><i class="bi bi-eye me-1"></i>Read only – ordering and changes are off.</div><?php endif; ?>
	<?php require __DIR__ . '/' . $file . '.php'; ?>
</main>
<div class="pz-toasts" id="pzToasts" aria-live="polite"></div>
<script type="application/json" id="pzBoot"><?= json_encode($boot, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?></script>
<?= $nu->sdk() ?>
<?php if ($file === 'today'): ?><script src="<?= $nu->h($nu->vendor('apexcharts', 'apexcharts.min.js')) ?>"></script><?php endif; ?>
<script src="<?= $nu->h($nu->asset('assets/app.js')) ?>"></script>
</body>
</html>
