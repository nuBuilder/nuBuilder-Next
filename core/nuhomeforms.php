<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * Home forms of the access levels (0.8.1264): the globeadmin's quick list next to the Home button (▾, or right-click / long press on
 * Home) – one row per Home form (sal_zzzzsys_form_id) with the access levels that have it and whether they start on it or on the tile
 * page (NuHome::startMode). Data for assets/js/nuhomeforms.js through the JSON route ?p=homeforms (pages/nuhomeforms.php).
 */
final class NuHomeForms
{
	/** Only the globeadmin (also the demo globeadmin – the list only reads). */
	public static function allowed(): bool
	{
		$u = NuAuth::user();
		return $u !== null && !empty($u['globeadmin']);
	}

	/**
	 * forms: [{id, title, code, open, design, levels: [{id, code, title, mode form|tiles, users, url}]}] sorted by title;
	 * tiles: access levels without a Home form (or nuhome) [{id, code, title, users, url}]; levels = number of access levels.
	 */
	public static function data(?NuDB $db = null): array
	{
		$db ??= nu_db();
		if (!$db->tableExists('zzzzsys_access')) return ['forms' => [], 'tiles' => [], 'pages' => [], 'levels' => 0, 'userhome' => null];
		$users = [];
		if ($db->tableExists('zzzzsys_user')) {
			foreach ($db->all('SELECT sus_zzzzsys_access_id AS a, COUNT(*) AS n FROM zzzzsys_user GROUP BY sus_zzzzsys_access_id') as $r) $users[(string)$r['a']] = (int)$r['n'];
		}
		$forms = nu_forms();
		$canDesign = NuAcl::can(NuAcl::FORM_FORM, 'save') && !NuDemo::active() && empty(NuAuth::user()['demo']);
		$out = [];
		$tiles = [];
		$all = [];
		$n = 0;
		foreach ($db->all('SELECT zzzzsys_access_id, sal_code, sal_description, sal_zzzzsys_form_id FROM zzzzsys_access ORDER BY sal_code') as $r) {
			$n++;
			$id = (string)$r['zzzzsys_access_id'];
			$lv = ['id' => $id, 'code' => (string)$r['sal_code'], 'title' => (string)$r['sal_description'], 'users' => $users[$id] ?? 0,
				'url' => nu_url('accesslevel', ['id' => $id, 'tab' => 'start']), 'tiles' => nu_url('home', ['for' => $id])];   // tiles: its tile page (editor, 0.8.1274)
			$lv['mode'] = 'tiles';
			$all[$id] = $lv;
			$fid = trim((string)($r['sal_zzzzsys_form_id'] ?? ''));
			if ($fid === '' || $fid === 'nuhome') { $tiles[] = $lv; continue; }
			if ($fid === 'nublank') continue;   // nuBuilder Forte's empty "Blank" form: not an application's Home form (0.8.1268)
			if (!isset($forms[$fid])) continue;   // a Home form that no longer exists is not listed (0.8.1270)
			if ($fid === NuUserHome::FORM) continue;   // User Home: the button in the panel head (0.8.1282), not a row
			if (!isset($out[$fid])) {
				$f = $forms[$fid];
				$design = $canDesign && $f['type'] !== 'page' && (!$f['system'] || $fid === 'nuuserhome');
				$out[$fid] = ['id' => $fid, 'title' => $f['description'] !== '' ? $f['description'] : $f['code'],
					'code' => $f['code'],
					'open' => nu_url('form', ['f' => $fid]), 'design' => $design ? nu_url('form', ['f' => $fid, 'design' => '1']) : '',
					'levels' => []];
			}
			$lv['mode'] = NuHome::startMode($id);
			$all[$id]['mode'] = $lv['mode'];
			$out[$fid]['levels'][] = $lv;
		}
		$out = array_values($out);
		usort($out, static fn($a, $b) => strcasecmp($a['title'], $b['title']));
		// User Home (0.8.1282, the owner picked H): always one tap away in the panel head, whether a level uses it or not
		$uh = null;
		if (NuUserHome::available() && isset($forms[NuUserHome::FORM])) {
			$uh = ['open' => nu_url('form', ['f' => NuUserHome::FORM]), 'design' => $canDesign ? nu_url('form', ['f' => NuUserHome::FORM, 'design' => '1']) : ''];
		}
		return ['forms' => $out, 'tiles' => $tiles, 'pages' => self::tilePages($db, $all), 'levels' => $n, 'userhome' => $uh];
	}

	/**
	 * The tile pages of users (0.8.1276, tab Tile pages): the default one first (saved for '*', else the automatic one) with every access
	 * level that has no tile page of its own, then each saved tile page with its levels –
	 * [{id, title, kind default|auto|level, open, levels: [{id, code, title, users, url, mode}]}]; url = the level's tile page in the editor.
	 */
	private static function tilePages(NuDB $db, array $all): array
	{
		$rows = [];
		try {
			if ($db->tableExists(NuListView::TABLE)) {
				$rows = $db->all('SELECT zzzzsys_nunext_list_view_id AS id, name, access_ids, definition FROM ' . NuListView::TABLE . ' WHERE list_key = ? ORDER BY sort_order, name', [NuHome::LIST_USER]);
			}
		} catch (Throwable) { $rows = []; }
		$own = [];
		$def = null;
		$pages = [];
		foreach ($rows as $r) {
			$ids = json_decode((string)$r['access_ids'], true);
			$ids = array_values(array_filter(is_array($ids) ? $ids : [], 'is_string'));
			if (in_array(NuHome::ALL_LEVELS, $ids, true) && $def === null) { $def = $r; $ids = array_values(array_diff($ids, [NuHome::ALL_LEVELS])); }
			$lv = [];
			foreach ($ids as $a) {
				if (isset($own[$a]) || !isset($all[$a])) continue;   // a level counts for its first tile page (as NuHome::userRows); unknown ids left out
				$own[$a] = true;
				$lv[] = $all[$a];
			}
			if ($lv) $pages[] = ['id' => (string)$r['id'], 'title' => trim((string)$r['name']) !== '' ? (string)$r['name'] : $lv[0]['code'], 'kind' => 'level',
				'open' => nu_url('home', ['for' => $lv[0]['id']]), 'levels' => $lv, 'empty' => NuHome::layoutEmpty((string)$r['definition'])];
		}
		usort($pages, static fn($a, $b) => strcasecmp($a['title'], $b['title']));
		$rest = array_values(array_filter($all, static fn($l) => !isset($own[$l['id']])));
		array_unshift($pages, ['id' => NuHome::ALL_LEVELS, 'title' => t($def ? 'home_u_default' : 'home_u_auto'), 'kind' => $def ? 'default' : 'auto',
			'open' => nu_url('home', ['for' => NuHome::ALL_LEVELS]), 'levels' => $rest]);
		return $pages;
	}

	/** Texts of the panel (sent with the data, assets/js/nuhomeforms.js). */
	public static function texts(): array
	{
		$k = ['hf_title', 'hf_panel_title', 'hf_tab_tiles', 'hf_search', 'hf_search_tiles', 'hf_open', 'hf_design', 'hf_mode_form',
			'hf_mode_tiles', 'hf_users', 'hf_none', 'hf_nomatch', 'hf_tiles_default', 'hf_tiles_auto', 'hf_levels_link', 'hf_keys', 'hf_keys_tiles', 'hf_empty', 'hf_empty_tip', 'home_userhome'];
		$t = [];
		foreach ($k as $key) $t[$key] = t($key);
		return $t;
	}

	/** The ▾ button + its empty panel (core/nulayout.php). */
	/** The Start pages button with its panel – on the start page next to Customise (0.8.1289, the owner; was a split button next to Home). */
	public static function button(): string
	{
		$lbl = h(t('hf_panel_btn'));
		return '<div class="dropdown nuHfGroup"><button type="button" class="btn btn-outline-secondary dropdown-toggle nuHfToggle" id="nuHfToggle" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" title="' . $lbl . '" aria-label="' . $lbl . '" data-url="' . h(nu_url('homeforms')) . '">'
			. '<i class="bi bi-house-gear" aria-hidden="true"></i><span class="nuHomeBtnTx">' . $lbl . '</span></button>'
			. '<div class="dropdown-menu dropdown-menu-end nuHfPanel" id="nuHfPanel" aria-labelledby="nuHfToggle"><div class="p-3 text-body-secondary small">…</div></div></div>';
	}
}
