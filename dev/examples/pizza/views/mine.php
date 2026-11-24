<?php
defined('NU_PAGE') || exit;
/** @var NuPageContext $nu */
// the first answer comes with the page; app.js asks ?action=mine again every few seconds and draws only what changed
?>
<div class="pz-pagehead"><h1>My orders</h1><p>The status changes by itself – no need to reload.</p></div>
<div class="pz-mine" id="pzMine" aria-live="polite"></div>
<p class="pz-empty" id="pzMineEmpty" hidden>No orders in the last two weeks. <a href="<?= $nu->h($nu->url(['view' => 'menu'])) ?>">See the menu</a></p>
<script type="application/json" id="pzData"><?= json_encode(['orders' => PizzaShop::mine($nu)], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?></script>
