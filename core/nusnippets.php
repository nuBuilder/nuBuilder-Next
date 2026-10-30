<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * Code snippets in the code editors (0.8.714): nuBuilder Forte's zzzzsys_code_snippet, field cot_scope ("Selectable in", a multi
 * select stored as ["0","3"]): 0 = Forms / Custom Code, 1 = Setup / Header, 2 = SQL, 3 = Procedures/PHP – as Forte's
 * nuSetSnippetFormFilter(). An editor asks for its scope (assets/js/nu/nucodetools.js → ?p=snippetlist, pages/nusnippetlist.php).
 * A snippet with a scope is offered where its scope matches; one without a scope (NULL, [""]) by its language, as before.
 */
final class NuSnippets
{
	public const SCOPES = [0, 1, 2, 3];
	/** Language of the editor (NuCodeTools lang) → cot_language values of the snippets for it. */
	public const LANGS = ['JS' => ['javascript', 'js'], 'PHP' => ['php'], 'SQL' => ['sql'], 'CSS' => ['css'], 'HTML' => ['html']];

	/** The scopes stored in cot_scope: JSON array, "0#nuSep#3" or plain digits – [] when none is set. */
	public static function scopeOf(?string $v): array
	{
		$v = trim((string)$v);
		if ($v === '') return [];
		$j = json_decode($v, true);
		$parts = is_array($j) ? array_map('strval', $j) : (preg_split('/[^0-9]+/', $v) ?: []);
		$out = [];
		foreach ($parts as $p) if (preg_match('/^[0-3]$/', trim($p))) $out[(int)$p] = true;
		ksort($out);
		return array_keys($out);
	}

	/** Snippets for an editor: rows with cot_code, cot_description, cot_source_code, cot_language, cot_scope → [[name, description, code], …]. */
	public static function pick(array $rows, int $scope, string $lang): array
	{
		$langs = self::LANGS[strtoupper($lang)] ?? [];
		$out = [];
		foreach ($rows as $r) {
			if ((string)($r['cot_source_code'] ?? '') === '') continue;
			$sc = self::scopeOf(isset($r['cot_scope']) ? (string)$r['cot_scope'] : null);
			$ok = $sc ? in_array($scope, $sc, true) : in_array(strtolower(trim((string)($r['cot_language'] ?? ''))), $langs, true);
			if ($ok) $out[] = [(string)$r['cot_code'], (string)($r['cot_description'] ?? ''), (string)$r['cot_source_code']];
		}
		usort($out, static fn($a, $b) => strcasecmp($a[0], $b[0]));
		return $out;
	}

	/** May this user see the snippets (they are code): the people who work in a code editor. */
	public static function allowed(): bool
	{
		$u = NuAuth::user();
		if (!$u) return false;
		return !empty($u['globeadmin']) || NuAdmin::any() || NuAcl::can(NuAcl::FORM_FORM) || NuAcl::can(NuAcl::FORM_PROCEDURE) || NuAcl::can('nucodesnippet');
	}

	/** For the route: the snippets of one editor + the link to the snippets form (only with the right on it). */
	public static function forEditor(int $scope, string $lang): array
	{
		$db = nu_db();
		$rows = $db->tableExists('zzzzsys_code_snippet')
			? $db->all('SELECT cot_code, cot_description, cot_source_code, cot_language, cot_scope FROM zzzzsys_code_snippet')
			: [];
		$manage = isset(nu_forms()['nucodesnippet']) && NuAcl::can('nucodesnippet') ? nu_url('form', ['f' => 'nucodesnippet']) : '';
		return ['scope' => $scope, 'items' => self::pick($rows, $scope, $lang), 'manage' => $manage, 'manage_label' => t('snip_manage')];
	}
}
