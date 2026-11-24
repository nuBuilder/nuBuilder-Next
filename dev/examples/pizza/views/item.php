<?php
defined('NU_PAGE') || exit;
/** @var NuPageContext $nu */
$id = (string)$nu->param('id', 'new');
$p = $id === 'new' ? ['pizza_menu_id' => '', 'pm_name' => '', 'pm_description' => '', 'pm_toppings' => 'tomato,mozzarella', 'pm_price_s' => '', 'pm_price_m' => '',
	'pm_price_l' => '', 'pm_veggie' => 0, 'pm_spicy' => 0, 'pm_active' => 1]
	: ($nu->one('SELECT * FROM pizza_menu WHERE pizza_menu_id = ?', [$id]) ?? $nu->notFound());
$tops = explode(',', (string)$p['pm_toppings']);
?>
<div class="pz-pagehead"><a class="pz-back" href="<?= $nu->h($nu->url(['view' => 'items'])) ?>"><i class="bi bi-chevron-left"></i> Pizzas</a>
	<h1><?= $id === 'new' ? 'New pizza' : $nu->h($p['pm_name']) ?></h1></div>
<form method="post" action="<?= $nu->h($nu->url(['action' => 'item_save'])) ?>" class="pz-item" id="pzItem">
	<?= $nu->csrfField() ?>
	<input type="hidden" name="id" value="<?= $nu->h($p['pizza_menu_id']) ?>">
	<div class="pz-itemform">
		<div class="mb-3"><label class="form-label" for="pzName">Name</label>
			<input class="form-control" id="pzName" name="name" maxlength="80" required value="<?= $nu->h($p['pm_name']) ?>"></div>
		<div class="mb-3"><label class="form-label" for="pzDesc">Description</label>
			<input class="form-control" id="pzDesc" name="description" maxlength="300" value="<?= $nu->h($p['pm_description']) ?>"></div>
		<fieldset class="mb-3"><legend class="form-label fs-6">Toppings</legend>
			<div class="pz-tops">
			<?php foreach (PizzaSchema::TOPPINGS as $t): ?>
				<input type="checkbox" class="btn-check" name="toppings[]" id="tp_<?= $t ?>" value="<?= $t ?>"<?= in_array($t, $tops, true) ? ' checked' : '' ?>>
				<label class="btn btn-outline-secondary btn-sm" for="tp_<?= $t ?>"><?= $nu->h(ucfirst($t)) ?></label>
			<?php endforeach; ?>
			</div></fieldset>
		<div class="row g-3 mb-3">
			<?php foreach (PizzaShop::SIZES as $z => $inch): $k = strtolower($z); ?>
			<div class="col-12 col-sm-4"><label class="form-label" for="pzP<?= $z ?>">Price <?= $z ?> <small class="text-body-secondary"><?= $inch ?>″</small></label>
				<div class="input-group"><span class="input-group-text"><?= $nu->h($nu->setting('currency', '$')) ?></span>
				<input class="form-control text-end" id="pzP<?= $z ?>" name="price_<?= $k ?>" inputmode="decimal" required value="<?= $nu->h($p['pm_price_' . $k] === '' ? '' : number_format((float)$p['pm_price_' . $k], 2, '.', '')) ?>"></div></div>
			<?php endforeach; ?>
		</div>
		<div class="d-flex flex-wrap gap-4 mb-4">
			<div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" id="pzVeg" name="veggie" value="1"<?= $p['pm_veggie'] ? ' checked' : '' ?>><label class="form-check-label" for="pzVeg">Vegetarian</label></div>
			<div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" id="pzHot" name="spicy" value="1"<?= $p['pm_spicy'] ? ' checked' : '' ?>><label class="form-check-label" for="pzHot">Spicy</label></div>
			<div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" id="pzAct" name="active" value="1"<?= $p['pm_active'] ? ' checked' : '' ?>><label class="form-check-label" for="pzAct">On the menu</label></div>
		</div>
		<button class="btn pz-go" type="submit"><i class="bi bi-floppy"></i> Save</button>
	</div>
	<figure class="pz-preview"><img id="pzPrev" alt="Preview" width="220" height="220" src="<?= $nu->h($nu->url(['action' => 'svg', 'name' => $p['pm_name'] ?: 'new', 't' => $p['pm_toppings']])) ?>">
		<figcaption>Preview – drawn from the toppings</figcaption></figure>
</form>
