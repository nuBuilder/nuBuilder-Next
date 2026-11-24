<?php
defined('NU_PAGE') || exit;

/**
 * The pizza shop's own tables – created on the first visit, with a sample menu.
 * Table and column names follow nuBuilder's habit: <table>_id is the primary key, so $nu->insert() / update() / delete() work.
 */
final class PizzaSchema
{
	/** Raise it when a table changes: every session runs ensure() once per version. */
	public const VERSION = 1;

	/** Toppings the drawing knows (lib/svg.php) and the back office offers. */
	public const TOPPINGS = ['tomato', 'mozzarella', 'basil', 'salami', 'ham', 'mushroom', 'olive', 'pepper', 'onion', 'pineapple', 'chili', 'rucola', 'corn'];

	public static function ensure(NuPageContext $nu): void
	{
		$s = &$nu->session();
		if (($s['schema'] ?? 0) === self::VERSION) return;
		$ms = $nu->dbType() === 'sqlsrv';
		$text = $ms ? 'NVARCHAR(MAX)' : 'TEXT';
		$tables = [
			'pizza_menu' => "pizza_menu_id VARCHAR(25) NOT NULL PRIMARY KEY, pm_name VARCHAR(80) NOT NULL, pm_description VARCHAR(300) NULL,
				pm_toppings VARCHAR(300) NULL, pm_price_s DECIMAL(8,2) NOT NULL, pm_price_m DECIMAL(8,2) NOT NULL, pm_price_l DECIMAL(8,2) NOT NULL,
				pm_veggie INT NOT NULL DEFAULT 0, pm_spicy INT NOT NULL DEFAULT 0, pm_active INT NOT NULL DEFAULT 1, pm_soldout_on DATE NULL,
				pm_sort INT NOT NULL DEFAULT 0",
			'pizza_order' => "pizza_order_id VARCHAR(25) NOT NULL PRIMARY KEY, po_number INT NOT NULL, po_user_id VARCHAR(25) NOT NULL,
				po_user_name VARCHAR(120) NOT NULL, po_status VARCHAR(12) NOT NULL, po_kind VARCHAR(10) NOT NULL, po_address VARCHAR(300) NULL,
				po_note VARCHAR(300) NULL, po_total DECIMAL(10,2) NOT NULL, po_created DATETIME NOT NULL, po_changed DATETIME NOT NULL",
			'pizza_order_item' => "pizza_order_item_id VARCHAR(25) NOT NULL PRIMARY KEY, poi_order_id VARCHAR(25) NOT NULL, poi_menu_id VARCHAR(25) NOT NULL,
				poi_name VARCHAR(80) NOT NULL, poi_size VARCHAR(1) NOT NULL, poi_extras $text NULL, poi_qty INT NOT NULL, poi_price DECIMAL(10,2) NOT NULL",
		];
		foreach ($tables as $t => $cols) {
			// a table name never comes from the request – it is one of the three fixed names above
			$nu->exec($ms ? "IF OBJECT_ID('$t', 'U') IS NULL CREATE TABLE $t ($cols)" : "CREATE TABLE IF NOT EXISTS $t ($cols)");
		}
		if ((int)$nu->value('SELECT COUNT(*) FROM pizza_menu') === 0) self::seed($nu);
		$s['schema'] = self::VERSION;
	}

	/** The sample menu: name, description, toppings, prices S / M / L, veggie, spicy. */
	private static function seed(NuPageContext $nu): void
	{
		$menu = [
			['Margherita', 'Tomato, mozzarella, fresh basil', 'tomato,mozzarella,basil', 12, 16, 20, 1, 0],
			['Salame', 'Tomato, mozzarella, Italian salami', 'tomato,mozzarella,salami', 14, 18.5, 23, 0, 0],
			['Prosciutto e funghi', 'Ham and mushrooms', 'tomato,mozzarella,ham,mushroom', 15, 19.5, 24, 0, 0],
			['Diavola', 'Spicy salami and chili', 'tomato,mozzarella,salami,chili', 15, 19.5, 24, 0, 1],
			['Vegetariana', 'Peppers, mushrooms, onions, olives', 'tomato,mozzarella,pepper,mushroom,onion,olive', 14.5, 19, 23.5, 1, 0],
			['Hawaii', 'Ham and pineapple', 'tomato,mozzarella,ham,pineapple', 14.5, 19, 23.5, 0, 0],
			['Rucola', 'Tomato, mozzarella, rocket after baking', 'tomato,mozzarella,rucola', 14, 18.5, 23, 1, 0],
			['Messicana', 'Peppers, corn, onions and chili', 'tomato,mozzarella,pepper,corn,onion,chili', 15, 19.5, 24, 1, 1],
		];
		$nu->transaction(function (NuPageContext $nu) use ($menu): void {
			foreach ($menu as $i => $m) {
				$nu->insert('pizza_menu', ['pm_name' => $m[0], 'pm_description' => $m[1], 'pm_toppings' => $m[2], 'pm_price_s' => $m[3],
					'pm_price_m' => $m[4], 'pm_price_l' => $m[5], 'pm_veggie' => $m[6], 'pm_spicy' => $m[7], 'pm_active' => 1, 'pm_sort' => ($i + 1) * 10]);
			}
		});
	}
}
