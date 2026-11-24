<?php
defined('NU_PAGE') || exit;

/**
 * The shop's rules: who is staff, opening hours, prices, orders and their way through the kitchen.
 * Every function takes $nu as its first argument (custom/PAGE_GUIDE.md: keep the variable name $nu).
 */
final class PizzaShop
{
	public const SIZES = ['S' => 10, 'M' => 12, 'L' => 14];   // inches – shown next to the size
	public const STATUSES = ['new', 'oven', 'ready', 'done', 'cancelled'];
	/** Allowed steps on the kitchen board: from → [to …]. */
	public const FLOW = ['new' => ['oven', 'cancelled'], 'oven' => ['ready'], 'ready' => ['done']];
	public const PERMISSION = 'PIZZA_BACKOFFICE';

	/** The back office: a permission item (the globeadmin always has it). */
	public static function isStaff(NuPageContext $nu): bool
	{
		return $nu->hasPermission(self::PERMISSION);
	}

	public static function money(NuPageContext $nu, float $v): string
	{
		// a symbol ("$", "€") goes right before the number, a code ("USD") with a space
		$c = (string)$nu->setting('currency', '$');
		return $c . (mb_strlen($c) > 1 ? ' ' : '') . number_format($v, 2, '.', ',');
	}

	/** Extras with their price, from the page settings: {"Extra cheese": 2, …}. */
	public static function extras(NuPageContext $nu): array
	{
		$out = [];
		foreach ((array)$nu->setting('extras', ['Extra mozzarella' => 2, 'Olives' => 1.5, 'Chili' => 1, 'Rocket' => 2, 'Gluten-free base' => 3]) as $k => $v) {
			if (is_string($k) && is_numeric($v)) $out[$k] = (float)$v;
		}
		return $out;
	}

	/** A time of the settings ("22:00") as the shop shows it: 10:00 PM, or 22:00 with "clock": "24h". */
	public static function time(NuPageContext $nu, string $hm): string
	{
		return $nu->setting('clock', '12h') === '24h' ? $hm : date('g:i A', (int)strtotime('2000-01-01 ' . $hm));
	}

	/** Open now? ['open' => bool, 'from' => 'HH:MM', 'to' => 'HH:MM']. Closed days: weekday numbers 1 (Monday) … 7. */
	public static function openState(NuPageContext $nu): array
	{
		$from = (string)$nu->setting('hours.from', '11:00');
		$to = (string)$nu->setting('hours.to', '22:00');
		$now = date('H:i');
		$closedDay = in_array((int)date('N'), array_map('intval', (array)$nu->setting('closed_weekdays', [])), true);
		return ['open' => !$closedDay && $now >= $from && $now < $to, 'from' => $from, 'to' => $to];
	}

	/** The menu; $all = also pizzas that are switched off (back office). */
	public static function menu(NuPageContext $nu, bool $all = false): array
	{
		$rows = $nu->all('SELECT * FROM pizza_menu' . ($all ? '' : ' WHERE pm_active = 1') . ' ORDER BY pm_sort, pm_name');
		$today = date('Y-m-d');
		foreach ($rows as &$r) {
			$r['soldout'] = substr((string)$r['pm_soldout_on'], 0, 10) === $today;   // "sold out today" ends by itself at midnight
			$r['toppings'] = array_values(array_filter(explode(',', (string)$r['pm_toppings'])));
		}
		return $rows;
	}

	public static function price(array $pizza, string $size): float
	{
		return (float)$pizza['pm_price_' . strtolower($size)];
	}

	/**
	 * Places an order. The browser sends only ids, sizes, extras and quantities – every price is taken from the
	 * database again, so a changed price in the browser does nothing. Returns ['id' => …, 'number' => …] or ['error' => text].
	 */
	public static function place(NuPageContext $nu, array $cart, string $kind, string $address, string $note): array
	{
		if (!self::openState($nu)['open']) return ['error' => 'Sorry, we are closed right now.'];
		if (!in_array($kind, ['delivery', 'pickup'], true)) return ['error' => 'Choose delivery or pickup.'];
		$address = trim(mb_substr($address, 0, 300));
		if ($kind === 'delivery' && $address === '') return ['error' => 'Please enter the delivery address.'];
		if (!$cart || count($cart) > 30) return ['error' => 'The cart is empty.'];
		$menu = array_column(self::menu($nu), null, 'pizza_menu_id');
		$extras = self::extras($nu);
		$lines = [];
		$total = 0.0;
		foreach ($cart as $c) {
			$p = $menu[(string)($c['id'] ?? '')] ?? null;
			$size = (string)($c['size'] ?? '');
			$qty = (int)($c['qty'] ?? 0);
			if (!$p || !isset(self::SIZES[$size]) || $qty < 1 || $qty > 20) return ['error' => 'A pizza in the cart is no longer on the menu.'];
			if ($p['soldout']) return ['error' => $p['pm_name'] . ' is sold out today.'];
			$ex = array_values(array_intersect(array_keys($extras), (array)($c['extras'] ?? [])));
			$each = self::price($p, $size) + array_sum(array_map(fn($e) => $extras[$e], $ex));
			$lines[] = ['poi_menu_id' => $p['pizza_menu_id'], 'poi_name' => $p['pm_name'], 'poi_size' => $size,
				'poi_extras' => json_encode($ex, JSON_UNESCAPED_UNICODE), 'poi_qty' => $qty, 'poi_price' => round($each * $qty, 2)];
			$total += $each * $qty;
		}
		$min = (float)$nu->setting('min_order', 20);
		if ($kind === 'delivery' && $total < $min) return ['error' => 'Delivery from ' . self::money($nu, $min) . ' on.'];
		if ($kind === 'delivery') $total += (float)$nu->setting('delivery_fee', 5);
		$u = $nu->user();
		$now = date('Y-m-d H:i:s');
		return $nu->transaction(function (NuPageContext $nu) use ($u, $kind, $address, $note, $total, $lines, $now): array {
			$number = (int)$nu->value('SELECT COALESCE(MAX(po_number), 100) FROM pizza_order') + 1;
			$id = $nu->insert('pizza_order', ['po_number' => $number, 'po_user_id' => $u['id'], 'po_user_name' => $u['display'],
				'po_status' => 'new', 'po_kind' => $kind, 'po_address' => $address, 'po_note' => trim(mb_substr($note, 0, 300)),
				'po_total' => round($total, 2), 'po_created' => $now, 'po_changed' => $now]);
			foreach ($lines as $l) $nu->insert('pizza_order_item', $l + ['poi_order_id' => $id]);
			$nu->audit('pizza_order', $id, 'insert', [], ['po_number' => $number, 'po_total' => round($total, 2)]);
			return ['id' => $id, 'number' => $number];
		});
	}

	/** Orders with their items. $where / $params pick them. */
	public static function orders(NuPageContext $nu, string $where, array $params, int $limit = 60): array
	{
		$orders = $nu->all("SELECT * FROM pizza_order WHERE $where ORDER BY po_created DESC", $params);
		$orders = array_slice($orders, 0, $limit);
		if (!$orders) return [];
		$ids = array_column($orders, 'pizza_order_id');
		$items = $nu->all('SELECT * FROM pizza_order_item WHERE poi_order_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids);
		$by = [];
		foreach ($items as $i) $by[$i['poi_order_id']][] = ['name' => $i['poi_name'], 'size' => $i['poi_size'], 'qty' => (int)$i['poi_qty'],
			'extras' => json_decode((string)$i['poi_extras'], true) ?: []];
		return array_map(fn($o) => ['id' => $o['pizza_order_id'], 'number' => (int)$o['po_number'], 'status' => $o['po_status'],
			'kind' => $o['po_kind'], 'address' => (string)$o['po_address'], 'note' => (string)$o['po_note'], 'customer' => $o['po_user_name'],
			'total' => self::money($nu, (float)$o['po_total']), 'created' => $o['po_created'], 'changed' => $o['po_changed'],
			'items' => $by[$o['pizza_order_id']] ?? []], $orders);
	}

	public static function mine(NuPageContext $nu): array
	{
		return self::orders($nu, 'po_user_id = ? AND po_created >= ?', [$nu->userId(), date('Y-m-d', strtotime('-14 days'))], 12);
	}

	/** The kitchen board: everything open, plus what was finished today. */
	public static function board(NuPageContext $nu): array
	{
		return self::orders($nu, "po_status IN ('new', 'oven', 'ready') OR (po_status = 'done' AND po_changed >= ?)", [date('Y-m-d')], 80);
	}

	/**
	 * Moves an order on the board. The UPDATE names the old status, so two cooks clicking at once cannot move it twice.
	 * Ready → the customer gets a notification in the bell.
	 */
	public static function move(NuPageContext $nu, string $id, string $from, string $to): bool
	{
		if (!in_array($to, self::FLOW[$from] ?? [], true)) return false;
		$n = $nu->exec('UPDATE pizza_order SET po_status = ?, po_changed = ? WHERE pizza_order_id = ? AND po_status = ?', [$to, date('Y-m-d H:i:s'), $id, $from]);
		if ($n !== 1) return false;
		$nu->audit('pizza_order', $id, 'update', ['po_status' => $from], ['po_status' => $to]);
		$o = $nu->one('SELECT po_number, po_user_id, po_kind FROM pizza_order WHERE pizza_order_id = ?', [$id]);
		if ($o && $to === 'ready') {
			$nu->notify((string)$o['po_user_id'], 'Order #' . $o['po_number'] . ' is ready',
				$o['po_kind'] === 'delivery' ? 'Your pizza is on its way.' : 'Your pizza is waiting at the counter.', $nu->url(['view' => 'mine']));
		}
		return true;
	}

	/** A customer may cancel an order only while it was not started. */
	public static function cancel(NuPageContext $nu, string $id): bool
	{
		$n = $nu->exec("UPDATE pizza_order SET po_status = 'cancelled', po_changed = ? WHERE pizza_order_id = ? AND po_user_id = ? AND po_status = 'new'",
			[date('Y-m-d H:i:s'), $id, $nu->userId()]);
		if ($n === 1) $nu->audit('pizza_order', $id, 'update', ['po_status' => 'new'], ['po_status' => 'cancelled']);
		return $n === 1;
	}

	/** Today in numbers: orders, revenue, average, cancelled, per hour and the top pizzas. */
	public static function today(NuPageContext $nu): array
	{
		$day = date('Y-m-d');
		$rows = $nu->all('SELECT po_status, po_total, po_created FROM pizza_order WHERE po_created >= ?', [$day]);
		$ok = array_filter($rows, fn($r) => $r['po_status'] !== 'cancelled');
		$hours = array_fill(11, 12, 0);
		foreach ($ok as $r) { $h = (int)substr((string)$r['po_created'], 11, 2); $hours[$h] = ($hours[$h] ?? 0) + 1; }
		ksort($hours);
		$top = $nu->all("SELECT poi_name AS name, SUM(poi_qty) AS qty FROM pizza_order_item i JOIN pizza_order o ON o.pizza_order_id = i.poi_order_id
			WHERE o.po_created >= ? AND o.po_status <> 'cancelled' GROUP BY poi_name ORDER BY SUM(poi_qty) DESC", [$day]);
		$revenue = array_sum(array_map(fn($r) => (float)$r['po_total'], $ok));
		return ['orders' => count($ok), 'revenue' => $revenue, 'average' => $ok ? $revenue / count($ok) : 0,
			'cancelled' => count($rows) - count($ok), 'hours' => $hours, 'top' => array_slice($top, 0, 6)];
	}
}
