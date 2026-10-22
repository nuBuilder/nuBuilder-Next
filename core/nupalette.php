<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * Search (0.8.321) – the list behind Ctrl+K (assets/js/nupalette.js, every page of the globeadmin) and the search field of the start
 * page (assets/js/nuhome.js): tiles of the start page, the pages and actions behind them (NuHome::subTiles), own tiles, forms,
 * procedures and reports. ?p=palette answers it as JSON. Each entry also carries its English words, so "Benutzer" and "users" both find
 * the users page.
 */
final class NuPalette
{
	/** @return list<array{id: string, label: string, group: string, icon: string, url?: string, post?: string, kw: string}> */
	public static function items(): array
	{
		$items = self::build();
		// English words of the same entries (searching in English works in every language)
		$prev = [$_SESSION['nu_lang'] ?? null, $_SESSION['nu_lang_variant'] ?? null];
		if (nu_lang() !== 'en') {
			nu_set_lang('en-GB');
			try {
				$en = array_column(self::build(true), 'label', 'id');
			} finally {
				if ($prev[0] === null) unset($_SESSION['nu_lang']); else $_SESSION['nu_lang'] = $prev[0];
				if ($prev[1] === null) unset($_SESSION['nu_lang_variant']); else $_SESSION['nu_lang_variant'] = $prev[1];
			}
			foreach ($items as &$it) if (isset($en[$it['id']]) && $en[$it['id']] !== $it['label']) $it['kw'] = trim($it['kw'] . ' ' . $en[$it['id']]);
			unset($it);
		}
		return $items;
	}

	private static function build(bool $labelsOnly = false): array
	{
		$out = [];
		$add = static function (string $id, string $label, string $group, string $icon, array $target, string $kw = '') use (&$out): void {
			$out[] = ['id' => $id, 'label' => $label, 'group' => $group, 'icon' => $icon, 'kw' => $kw] + $target;
		};
		$cat = NuHome::catalog();
		$gPages = t('pal_group_pages');
		$layout = NuHome::layout();
		$ga = (bool)NuAuth::user()['globeadmin'];                // admin roles (0.8.323): only what the role and the access level allow
		foreach ($cat as $k => [$route, $icon, $label, $hint]) {
			if (!$ga && !NuHome::visible($k, $layout)) continue;
			$target = is_array($route) && isset($route['post']) ? ['post' => (string)$route['post']] : ['url' => is_array($route) ? nu_url($route[0], $route[1]) : nu_url($route)];
			$add('t:' . $k, $label, $gPages, $icon, $target, $hint);
		}
		foreach (NuHome::subTiles() as $k => [$url, $icon, $label, $parent]) {
			if (isset($cat[$k])) continue;                          // a tile of its own (CSV import, logged-in users): listed above
			if (!$ga && !NuHome::visible($k, $layout)) continue;
			$add('t:' . $k, $label, $cat[$parent][2] ?? $gPages, $icon, ['url' => $url]);
		}
		if ($labelsOnly) return $out;
		foreach ((array)$layout['custom'] as $k => $c) {
			if (!$ga && !NuHome::visible((string)$k, $layout)) continue;
			$r = NuHome::resolve((string)$k, $layout);
			if ($r && $r['href']) $add('t:' . $k, (string)$r['label'], t('pal_group_own'), (string)$r['icon'], ['url' => (string)$r['href']], (string)$r['hint']);
		}
		$db = nu_db();
		foreach (nu_forms() as $id => $f) {
			if ($f['type'] === 'subform' || $f['system']) continue;    // nuBuilder's own forms: reached through the pages above
			if (!$ga && !NuAcl::can((string)$id)) continue;
			$add('f:' . $id, $f['description'] !== '' ? $f['description'] : $f['code'], t('pal_group_forms'), 'bi-ui-checks-grid', ['url' => nu_url('form', ['f' => (string)$id])], $f['code']);
		}
		if (!$ga && !NuAcl::can(NuAcl::FORM_PROCEDURE)) return self::reports($out, $ga);
		try {
			foreach ($db->all('SELECT zzzzsys_php_id, sph_code, sph_description FROM zzzzsys_php WHERE ' . NuProcedure::listCondition() . ' ORDER BY sph_code') as $p) {
				$add('p:' . $p['zzzzsys_php_id'], (string)($p['sph_description'] ?: $p['sph_code']), t('pal_group_procedures'), 'bi-filetype-php',
					['url' => nu_url('procedures', ['id' => (string)$p['zzzzsys_php_id']])], (string)$p['sph_code']);
			}
		} catch (Throwable) { /* no procedures table */ }
		return self::reports($out, $ga);
	}

	private static function entry(string $id, string $label, string $group, string $icon, array $target, string $kw = ''): array
	{
		return ['id' => $id, 'label' => $label, 'group' => $group, 'icon' => $icon, 'kw' => $kw] + $target;
	}

	private static function reports(array $out, bool $ga): array
	{
		if (!$ga && !NuAcl::can(NuAcl::FORM_REPORT)) return $out;
		$db = nu_db();
		try {
			if ($db->tableExists(NuReport::TABLE)) {
				foreach ($db->all('SELECT zzzzsys_report_id, sre_code, sre_description FROM zzzzsys_report ORDER BY sre_code') as $r) {
					$out[] = self::entry('r:' . $r['zzzzsys_report_id'], (string)($r['sre_description'] ?: $r['sre_code']), t('pal_group_reports'), 'bi-file-earmark-richtext',
						['url' => nu_url('reports', ['id' => (string)$r['zzzzsys_report_id']])], (string)$r['sre_code']);
				}
			}
		} catch (Throwable) { /* no reports */ }
		return $out;
	}
}
