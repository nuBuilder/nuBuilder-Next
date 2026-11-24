<?php
defined('NU_PAGE') || exit;
/** @var NuPageContext $nu @var bool $ro */
$menu = PizzaShop::menu($nu, true);
$csrf = $nu->csrfField();
?>
<div class="pz-pagehead d-flex flex-wrap align-items-end gap-2">
	<div class="me-auto"><h1>Pizzas</h1><p>What the menu shows, in this order. Switch a pizza off, or mark it sold out for today.</p></div>
	<?php if (!$ro): ?><a class="btn pz-go" href="<?= $nu->h($nu->url(['view' => 'item', 'id' => 'new'])) ?>"><i class="bi bi-plus-lg"></i> New pizza</a><?php endif; ?>
</div>
<div class="pz-table">
<table class="table align-middle mb-0">
	<thead><tr><th><span class="visually-hidden">Picture</span></th><th>Pizza</th><th class="text-end">S</th><th class="text-end">M</th><th class="text-end">L</th>
		<th>On the menu</th><th>Sold out today</th><th><span class="visually-hidden">Actions</span></th></tr></thead>
	<tbody>
	<?php foreach ($menu as $k => $p): $id = $p['pizza_menu_id']; ?>
	<tr class="<?= $p['pm_active'] ? '' : 'pz-off' ?>" data-id="<?= $nu->h($id) ?>">
		<td class="pz-thumb"><?= PizzaSvg::draw($p['pm_name'], $p['toppings'], 48) ?></td>
		<td><b><?= $nu->h($p['pm_name']) ?></b><?= $p['pm_veggie'] ? ' <i class="bi bi-flower1 pz-veg" title="Vegetarian"></i>' : '' ?><?= $p['pm_spicy'] ? ' <i class="bi bi-thermometer-sun pz-hot" title="Spicy"></i>' : '' ?>
			<div class="small text-body-secondary"><?= $nu->h(implode(', ', $p['toppings'])) ?></div></td>
		<?php foreach (['s', 'm', 'l'] as $z): ?><td class="text-end text-nowrap"><?= $nu->h(number_format((float)$p['pm_price_' . $z], 2)) ?></td><?php endforeach; ?>
		<td><div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" data-flag="active" aria-label="On the menu: <?= $nu->h($p['pm_name']) ?>"<?= $p['pm_active'] ? ' checked' : '' ?><?= $ro ? ' disabled' : '' ?>></div></td>
		<td><div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" data-flag="soldout" aria-label="Sold out today: <?= $nu->h($p['pm_name']) ?>"<?= $p['soldout'] ? ' checked' : '' ?><?= $ro ? ' disabled' : '' ?>></div></td>
		<td class="text-nowrap text-end">
			<?php if (!$ro): ?>
			<form method="post" action="<?= $nu->h($nu->url(['action' => 'item_move'])) ?>" class="d-inline"><?= $csrf ?><input type="hidden" name="id" value="<?= $nu->h($id) ?>">
				<button class="btn btn-sm btn-outline-secondary" name="dir" value="-1" title="Up"<?= $k === 0 ? ' disabled' : '' ?>><i class="bi bi-arrow-up"></i></button>
				<button class="btn btn-sm btn-outline-secondary" name="dir" value="1" title="Down"<?= $k === count($menu) - 1 ? ' disabled' : '' ?>><i class="bi bi-arrow-down"></i></button></form>
			<a class="btn btn-sm btn-outline-secondary" href="<?= $nu->h($nu->url(['view' => 'item', 'id' => $id])) ?>"><i class="bi bi-pencil"></i> Edit</a>
			<?php endif; ?>
		</td>
	</tr>
	<?php endforeach; ?>
	</tbody>
</table>
</div>
