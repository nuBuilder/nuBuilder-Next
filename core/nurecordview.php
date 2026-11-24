<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * View mode of a record: a read-only data sheet of everything the record page shows – every tab at once, fields as text, sub-forms
 * as small tables, no inputs, buttons or links. Built in the browser from the page itself (assets/js/nuview.js), so form code that
 * hides or fills fields, field access (withheld / masked) and the user's rights are what the sheet shows. Form pages: the form's
 * setting sfo_json nunext.view {head: [object ids]} names the fields of the key-facts strip on top. Own record pages (user form)
 * opt in with button() and data-nu-view-root on the form.
 */
final class NuRecordView
{
	public const HEAD_MAX = 6;
	/** "Always open in view mode" of a user: the forms (form ids, 'user' for the user form) – sus_json, the globeadmin's in a setting row. */
	public const JSON       = 'NUNEXT_VIEW_FIRST';
	public const SETTING_GA = 'view_first_ga';
	public const FIRST_MAX  = 500;

	/** @return list<string> the forms whose records open in view mode first for the signed-in user */
	public static function firstForms(): array
	{
		$u = NuAuth::user();
		if (!$u) return [];
		try {
			if (!empty($u['demo'])) $v = $_SESSION[NuSess::VIEW_FIRST] ?? [];
			elseif (!empty($u['globeadmin'])) $v = nu_setting_json(self::SETTING_GA)['forms'] ?? [];
			else $v = NuUserJson::get((string)$u['id'], self::JSON) ?? [];
		} catch (Throwable $ig) {
			nu_ignored($ig, 'view mode first: no stored choice');
			$v = [];
		}
		return is_array($v) ? array_values(array_filter(array_map('strval', $v), [self::class, 'validKey'])) : [];
	}

	public static function first(string $key): bool
	{
		return in_array($key, self::firstForms(), true);
	}

	public static function validKey(string $key): bool
	{
		return preg_match('/^[\w.-]{1,100}$/u', $key) === 1;
	}

	/** Switches "Always open in view mode" of one form for the signed-in user. */
	public static function setFirst(string $key, bool $on): bool
	{
		$u = NuAuth::user();
		if (!$u || !self::validKey($key)) return false;
		$list = array_values(array_diff(self::firstForms(), [$key]));
		if ($on) $list[] = $key;
		$list = array_slice($list, -self::FIRST_MAX);
		if (!empty($u['demo'])) $_SESSION[NuSess::VIEW_FIRST] = $list;
		elseif (!empty($u['globeadmin'])) nu_setting_json_save(self::SETTING_GA, ['forms' => $list], 'globeadmin');
		else NuUserJson::set((string)$u['id'], self::JSON, $list);
		return true;
	}

	/** Object ids of the key-facts strip of a form. @return list<string> */
	public static function head(NuFormMeta $meta): array
	{
		$v = $meta->settings()['view'] ?? null;
		return is_array($v) ? self::cleanHead($v['head'] ?? []) : [];
	}

	/** @return list<string> */
	public static function cleanHead(mixed $v): array
	{
		if (is_string($v)) $v = preg_split('/[\s,;]+/', $v) ?: [];
		if (!is_array($v)) return [];
		$out = [];
		foreach ($v as $id) {
			$id = trim((string)$id);
			if ($id !== '' && preg_match('/^[\w.-]{1,100}$/u', $id) && !in_array($id, $out, true)) $out[] = $id;
			if (count($out) >= self::HEAD_MAX) break;
		}
		return $out;
	}

	/** The design API's form.save field "view_head" (ids separated by commas) → the key of sfo_json nunext (null removes it). */
	public static function keysIn(array $fields): array
	{
		if (!array_key_exists('view_head', $fields)) return [];
		$h = self::cleanHead((string)$fields['view_head']);
		return ['view' => $h ? ['head' => $h] : null];
	}

	/** Texts of the sheet (assets/js/nuview.js). */
	public static function texts(): array
	{
		$k = ['rv_mode', 'edit', 'close', 'rv_prev', 'rv_next', 'rv_pos', 'rv_empty_n', 'rv_show_empty', 'rv_hide_empty', 'rv_nothing',
			'rv_rows_n', 'yes', 'no', 'rv_keys', 'rv_first', 'rv_first_short', 'rv_first_tip', 'rv_first_on', 'rv_first_off'];
		$out = [];
		foreach ($k as $key) $out[$key] = t($key);
		return $out;
	}

	/**
	 * The View button. $opt: title (record title), kind (form description or page name), head (object ids), list (form id whose
	 * list order the ← / → keys follow), edit (false = the page cannot be edited: the sheet offers Close instead of Edit).
	 */
	public static function button(array $opt): string
	{
		$data = ['texts' => self::texts(), 'title' => (string)($opt['title'] ?? ''), 'kind' => (string)($opt['kind'] ?? ''),
			'head' => array_values($opt['head'] ?? []), 'list' => (string)($opt['list'] ?? ''), 'edit' => ($opt['edit'] ?? true) !== false];
		// "Always open in view mode": the key the choice is stored under (form id, 'user' …), whether it is on, where to switch it
		$key = (string)($opt['key'] ?? $opt['list'] ?? '');
		if (self::validKey($key)) $data += ['key' => $key, 'first' => self::first($key), 'url' => nu_url('viewfirst')];
		return '<button type="button" class="btn btn-outline-secondary nuActionButton nuIconPhone" id="nuViewButton" title="' . h(t('rv_tip')) . '"'
			. ' aria-keyshortcuts="Control+Shift+V" data-nu-view="' . h((string)json_encode($data, JSON_UNESCAPED_UNICODE)) . '">'
			. '<i class="bi bi-eye me-1" aria-hidden="true"></i>' . h(t('view')) . '</button>';
	}

	/** Will the page open in view mode by itself (?view=1, or "Always open in view mode" of $key without ?view=0)? */
	public static function pending(string $key): bool
	{
		$v = (string)($_GET['view'] ?? '');
		if ($v === '1') return true;
		return $v !== '0' && self::validKey($key) && self::first($key);
	}

	public static function script(): string
	{
		return '<script src="' . nu_asset('assets/js/nuview.js') . '"></script>';
	}
}
