<?php
defined('NU_PAGE') || exit;
/** @var NuPageContext $nu */
$cols = ['new' => ['Received', 'bi-inbox'], 'oven' => ['In the oven', 'bi-fire'], 'ready' => ['Ready', 'bi-bell'], 'done' => ['Done today', 'bi-check2-all']];
?>
<div class="pz-pagehead"><h1>Kitchen</h1><p>New orders appear by themselves. One click moves an order on – the customer sees it at once.</p></div>
<div class="pz-board" id="pzBoard">
	<?php foreach ($cols as $k => [$label, $icon]): ?>
	<section class="pz-col pz-col-<?= $k ?>" data-status="<?= $k ?>" aria-label="<?= $nu->h($label) ?>">
		<h2><i class="bi <?= $icon ?>"></i> <?= $nu->h($label) ?> <span class="badge rounded-pill" data-count>0</span></h2>
		<div class="pz-colbody" data-list></div>
	</section>
	<?php endforeach; ?>
</div>
<script type="application/json" id="pzData"><?= json_encode(['orders' => PizzaShop::board($nu), 'now' => date('Y-m-d H:i:s')], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?></script>
