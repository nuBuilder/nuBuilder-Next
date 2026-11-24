<?php
defined('NU_PAGE') || exit;
/** @var NuPageContext $nu @var bool $ro */
$menu = PizzaShop::menu($nu);
$extras = PizzaShop::extras($nu);
$open = PizzaShop::openState($nu)['open'];
?>
<div class="pz-shop">
	<section class="pz-menu" aria-label="Menu">
		<?php if (!$open): ?><div class="alert alert-warning py-2"><i class="bi bi-moon-stars me-1"></i>We are closed right now – have a look, ordering opens later.</div><?php endif; ?>
		<div class="pz-filter btn-group" role="group" aria-label="Filter">
			<input type="radio" class="btn-check" name="pzf" id="pzfAll" value="" checked><label class="btn btn-outline-secondary btn-sm" for="pzfAll">All</label>
			<input type="radio" class="btn-check" name="pzf" id="pzfVeg" value="veggie"><label class="btn btn-outline-secondary btn-sm" for="pzfVeg"><i class="bi bi-flower1"></i> Vegetarian</label>
			<input type="radio" class="btn-check" name="pzf" id="pzfHot" value="spicy"><label class="btn btn-outline-secondary btn-sm" for="pzfHot"><i class="bi bi-thermometer-sun"></i> Spicy</label>
		</div>
		<div class="pz-grid">
			<?php foreach ($menu as $p): ?>
			<article class="pz-card<?= $p['soldout'] ? ' is-soldout' : '' ?>" data-id="<?= $nu->h($p['pizza_menu_id']) ?>" data-name="<?= $nu->h($p['pm_name']) ?>"
				data-veggie="<?= (int)$p['pm_veggie'] ?>" data-spicy="<?= (int)$p['pm_spicy'] ?>"
				data-prices="<?= $nu->h(json_encode(['S' => (float)$p['pm_price_s'], 'M' => (float)$p['pm_price_m'], 'L' => (float)$p['pm_price_l']])) ?>">
				<div class="pz-pic"><?= PizzaSvg::draw($p['pm_name'], $p['toppings']) ?></div>
				<div class="pz-body">
					<h2><?= $nu->h($p['pm_name']) ?>
						<?= $p['pm_veggie'] ? '<i class="bi bi-flower1 pz-veg" title="Vegetarian"></i>' : '' ?>
						<?= $p['pm_spicy'] ? '<i class="bi bi-thermometer-sun pz-hot" title="Spicy"></i>' : '' ?></h2>
					<p><?= $nu->h($p['pm_description']) ?></p>
					<?php if ($p['soldout']): ?>
						<div class="pz-soldout">Sold out today</div>
					<?php else: ?>
					<div class="pz-sizes btn-group" role="group" aria-label="Size">
						<?php foreach (PizzaShop::SIZES as $z => $inch): $rid = 'sz' . $p['pizza_menu_id'] . $z; ?>
						<input type="radio" class="btn-check" name="sz<?= $nu->h($p['pizza_menu_id']) ?>" id="<?= $nu->h($rid) ?>" value="<?= $z ?>"<?= $z === 'M' ? ' checked' : '' ?>>
						<label class="btn btn-outline-secondary btn-sm" for="<?= $nu->h($rid) ?>"><b><?= $z ?></b> <small><?= $inch ?>″</small><br><?= $nu->h(PizzaShop::money($nu, PizzaShop::price($p, $z))) ?></label>
						<?php endforeach; ?>
					</div>
					<details class="pz-extras"><summary>Extras</summary>
						<?php foreach ($extras as $e => $price): $eid = 'ex' . md5($p['pizza_menu_id'] . $e); ?>
						<div class="form-check"><input class="form-check-input" type="checkbox" id="<?= $eid ?>" value="<?= $nu->h($e) ?>">
							<label class="form-check-label" for="<?= $eid ?>"><?= $nu->h($e) ?> <span class="text-body-secondary">+<?= $nu->h(number_format($price, 2)) ?></span></label></div>
						<?php endforeach; ?>
					</details>
					<button type="button" class="btn pz-add"<?= ($ro || !$open) ? ' disabled' : '' ?>><i class="bi bi-plus-lg"></i> Add</button>
					<?php endif; ?>
				</div>
			</article>
			<?php endforeach; ?>
		</div>
	</section>

	<aside class="pz-cart" id="pzCart" aria-label="Your order">
		<h2><i class="bi bi-basket2"></i> Your order <span class="badge rounded-pill" id="pzCount">0</span></h2>
		<ul class="pz-lines" id="pzLines"></ul>
		<p class="pz-empty" id="pzEmpty">Nothing here yet – pick a pizza.</p>
		<form id="pzOrder" class="pz-orderform" novalidate>
			<div class="btn-group w-100 mb-2" role="group" aria-label="Delivery or pickup">
				<input type="radio" class="btn-check" name="kind" id="pzKindD" value="delivery" checked><label class="btn btn-outline-secondary" for="pzKindD"><i class="bi bi-scooter"></i> Delivery</label>
				<input type="radio" class="btn-check" name="kind" id="pzKindP" value="pickup"><label class="btn btn-outline-secondary" for="pzKindP"><i class="bi bi-shop"></i> Pickup</label>
			</div>
			<div class="mb-2" id="pzAddrBox"><label class="form-label" for="pzAddr">Delivery address</label>
				<textarea class="form-control" id="pzAddr" name="address" rows="2" maxlength="300"><?= $nu->h($nu->session()['address'] ?? '') ?></textarea></div>
			<div class="mb-2"><label class="form-label" for="pzNote">Note for the kitchen</label>
				<input class="form-control" id="pzNote" name="note" maxlength="300" placeholder="e.g. well done, ring twice"></div>
			<dl class="pz-sum">
				<dt>Pizzas</dt><dd id="pzSub">–</dd>
				<dt id="pzFeeL">Delivery</dt><dd id="pzFee">–</dd>
				<dt class="pz-total">Total</dt><dd class="pz-total" id="pzTotal">–</dd>
			</dl>
			<div class="small text-body-secondary mb-2" id="pzMin"></div>
			<button type="submit" class="btn pz-go w-100" id="pzGo" disabled><i class="bi bi-check2-circle"></i> Order now</button>
			<div class="text-danger small mt-2" id="pzErr" role="alert"></div>
		</form>
	</aside>
</div>
<button type="button" class="btn pz-cartbtn" id="pzCartBtn"><i class="bi bi-basket2"></i> <span id="pzCartBtnText">0 · –</span></button>
