<?php
defined('NU_PAGE') || exit;
$nu->api(1);

/*
 * Pizza shop – an example custom page of nuBuilder Next (folder custom/pages/pizza/).
 *   Customers: menu, cart, order, "My orders" with a live status.
 *   Back office (permission item PIZZA_BACKOFFICE): kitchen board, the pizzas of the menu, today in numbers + Excel.
 * index.php is the router: it answers the JSON / POST actions and otherwise draws one view of views/.
 */

/** @var NuPageContext $nu */
require __DIR__ . '/lib/schema.php';
require __DIR__ . '/lib/shop.php';
require __DIR__ . '/lib/svg.php';

PizzaSchema::ensure($nu);
$staff = PizzaShop::isStaff($nu);
$ro = $nu->readOnly();                       // demo mode, or the globeadmin looking as another user: show, never write

// ---------------------------------------------------------------- actions (JSON for the browser, POST + redirect for forms)
$action = $nu->action();
$needStaff = ['board', 'move', 'item_save', 'item_flag', 'item_move', 'export'];
if (in_array($action, $needStaff, true)) $nu->require($staff, 'Only the back office may do this.');
if ($nu->isPost() && $ro) $nu->deny('Read only.');

switch ($action) {
	case 'order':                          // POST: cart (JSON text), kind, address, note
		if (!$nu->isPost()) $nu->notFound();
		$cart = json_decode((string)$nu->param('cart', '[]'), true);
		$r = PizzaShop::place($nu, is_array($cart) ? $cart : [], (string)$nu->param('kind', ''), (string)$nu->param('address', ''), (string)$nu->param('note', ''));
		if (isset($r['error'])) $nu->json($r, 422);
		$nu->session()['address'] = (string)$nu->param('address', '');   // offered again next time
		foreach ($nu->users(['permission' => PizzaShop::PERMISSION, 'active' => true]) as $s) {
			$nu->notify($s['id'], 'New order #' . $r['number'], $nu->user()['display'], $nu->url(['view' => 'board']));
		}
		$nu->json($r);
	case 'mine':                           // GET: my orders (polled)
		$nu->json(['orders' => PizzaShop::mine($nu)]);
	case 'cancel':
		if (!$nu->isPost()) $nu->notFound();
		$nu->json(['ok' => PizzaShop::cancel($nu, (string)$nu->param('id', ''))]);
	case 'board':                          // GET: the kitchen board (polled)
		$nu->json(['orders' => PizzaShop::board($nu), 'now' => date('Y-m-d H:i:s')]);
	case 'move':
		if (!$nu->isPost()) $nu->notFound();
		$ok = PizzaShop::move($nu, (string)$nu->param('id', ''), (string)$nu->param('from', ''), (string)$nu->param('to', ''));
		$nu->json(['ok' => $ok] + ($ok ? [] : ['error' => 'Someone else moved this order already.']));
	case 'item_flag':                      // POST: id, flag active|soldout, on 0|1
		if (!$nu->isPost()) $nu->notFound();
		$id = (string)$nu->param('id', '');
		$on = $nu->param('on') === '1';
		$col = ['active' => 'pm_active', 'soldout' => 'pm_soldout_on'][(string)$nu->param('flag', '')] ?? $nu->notFound();
		$val = $col === 'pm_active' ? ($on ? 1 : 0) : ($on ? date('Y-m-d') : null);
		$nu->update('pizza_menu', $id, [$col => $val]);
		$nu->audit('pizza_menu', $id, 'update', [], [$col => $val]);
		$nu->json(['ok' => true]);
	case 'item_move':                      // POST: id, dir -1|1 – swaps the sort numbers with the neighbour
		if (!$nu->isPost()) $nu->notFound();
		$list = PizzaShop::menu($nu, true);
		$i = array_search((string)$nu->param('id', ''), array_column($list, 'pizza_menu_id'), true);
		$j = $i === false ? false : $i + ((int)$nu->param('dir', '0') < 0 ? -1 : 1);
		if ($i !== false && isset($list[$j])) {
			$nu->transaction(function (NuPageContext $nu) use ($list, $i, $j): void {
				foreach ($list as $k => $p) $nu->update('pizza_menu', $p['pizza_menu_id'], ['pm_sort' => ($k === $i ? $j : ($k === $j ? $i : $k)) * 10 + 10]);
			});
		}
		$nu->redirect($nu->url(['view' => 'items']));
	case 'item_save':                      // POST form of views/item.php
		if (!$nu->isPost()) $nu->notFound();
		$id = (string)$nu->param('id', '');
		$tops = array_values(array_intersect(PizzaSchema::TOPPINGS, (array)($_POST['toppings'] ?? [])));
		$row = ['pm_name' => trim(mb_substr((string)$nu->param('name', ''), 0, 80)), 'pm_description' => trim(mb_substr((string)$nu->param('description', ''), 0, 300)),
			'pm_toppings' => implode(',', $tops), 'pm_veggie' => $nu->param('veggie') ? 1 : 0, 'pm_spicy' => $nu->param('spicy') ? 1 : 0,
			'pm_active' => $nu->param('active') ? 1 : 0];
		foreach (['s', 'm', 'l'] as $z) $row['pm_price_' . $z] = round(max(0, (float)str_replace(',', '.', (string)$nu->param('price_' . $z, '0'))), 2);
		$s = &$nu->session();
		if ($row['pm_name'] === '' || $row['pm_price_s'] <= 0) {
			$s['flash'] = ['danger', 'A name and the prices are needed.'];
			$nu->redirect($nu->url(['view' => 'item', 'id' => $id !== '' ? $id : 'new']));
		}
		if ($id === '') {
			$row['pm_sort'] = (int)$nu->value('SELECT COALESCE(MAX(pm_sort), 0) FROM pizza_menu') + 10;
			$id = $nu->insert('pizza_menu', $row);
			$nu->audit('pizza_menu', $id, 'insert', [], $row);
		} else {
			$before = $nu->one('SELECT * FROM pizza_menu WHERE pizza_menu_id = ?', [$id]) ?? $nu->notFound();
			$nu->update('pizza_menu', $id, $row);
			$nu->audit('pizza_menu', $id, 'update', array_intersect_key($before, $row), $row);
		}
		$s['flash'] = ['success', $row['pm_name'] . ' saved.'];
		$nu->redirect($nu->url(['view' => 'items']));
	case 'export':                         // GET: today's orders as Excel
		$x = $nu->excel('Orders ' . date('Y-m-d'));
		$rows = [];
		foreach (PizzaShop::orders($nu, 'po_created >= ?', [date('Y-m-d')], 1000) as $o) {
			$rows[] = [$o['number'], substr($o['created'], 11, 5), $o['customer'], $o['kind'], $o['status'],
				implode(', ', array_map(fn($i) => $i['qty'] . '× ' . $i['name'] . ' ' . $i['size'], $o['items'])),
				(float)preg_replace('/[^0-9.]/', '', $o['total'])];
		}
		$x->sheet('Orders')->header(['No.', 'Time', 'Customer', 'Kind', 'Status', 'Pizzas', 'Total'])->rows($rows)->style('G2:G9999', ['format' => '#,##0.00']);
		$x->download('pizza-orders-' . date('Y-m-d') . '.xlsx');
	case 'svg':                            // GET: a drawing for the back office's preview while ticking toppings
		header('Content-Type: image/svg+xml');
		echo PizzaSvg::draw((string)$nu->param('name', 'Pizza'), array_intersect(PizzaSchema::TOPPINGS, explode(',', (string)$nu->param('t', ''))), 200);
		exit;
	case '':
		break;
	default:
		$nu->notFound();
}

// ---------------------------------------------------------------- views
$views = ['menu' => ['Menu', 'bi-journal-text'], 'mine' => ['My orders', 'bi-bag-check']];
if ($staff) $views += ['board' => ['Kitchen', 'bi-fire'], 'items' => ['Pizzas', 'bi-pencil-square'], 'today' => ['Today', 'bi-graph-up']];
$view = (string)$nu->param('view', $staff ? 'board' : 'menu');
if ($view === 'item' && $staff) $file = 'item';
elseif (isset($views[$view])) $file = $view;
else $nu->notFound();

$s = &$nu->session();
$flash = $s['flash'] ?? null;
unset($s['flash']);
require __DIR__ . '/views/layout.php';
