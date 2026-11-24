<?php
defined('NU_PAGE') || exit;
/** @var NuPageContext $nu */
$d = PizzaShop::today($nu);
$clock24 = $nu->setting('clock', '12h') === '24h';
$chart = ['hours' => array_map(fn($h) => $clock24 ? sprintf('%02d:00', $h) : date('g A', mktime($h, 0, 0)), array_keys($d['hours'])), 'orders' => array_values($d['hours']),
	'top' => array_map(fn($r) => $r['name'], $d['top']), 'qty' => array_map(fn($r) => (int)$r['qty'], $d['top'])];
?>
<div class="pz-pagehead d-flex flex-wrap align-items-end gap-2">
	<div class="me-auto"><h1>Today</h1><p><?= $nu->h(date('l, F j, Y')) ?></p></div>
	<a class="btn btn-outline-secondary" href="<?= $nu->h($nu->url(['action' => 'export'])) ?>"><i class="bi bi-file-earmark-excel"></i> Excel</a>
</div>
<div class="pz-kpis">
	<div class="pz-kpi"><span>Orders</span><b><?= (int)$d['orders'] ?></b></div>
	<div class="pz-kpi"><span>Revenue</span><b><?= $nu->h(PizzaShop::money($nu, $d['revenue'])) ?></b></div>
	<div class="pz-kpi"><span>Average order</span><b><?= $nu->h(PizzaShop::money($nu, $d['average'])) ?></b></div>
	<div class="pz-kpi"><span>Cancelled</span><b><?= (int)$d['cancelled'] ?></b></div>
</div>
<div class="pz-charts">
	<section class="pz-chartcard"><h2>Orders per hour</h2><div id="pzChartHours"></div></section>
	<section class="pz-chartcard"><h2>Top pizzas</h2><div id="pzChartTop"></div></section>
</div>
<script type="application/json" id="pzData"><?= json_encode($chart, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?></script>
