<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * Design mode on the form page (0.8.662, stage 1 of the new form designer): the globeadmin changes a form where it runs –
 * ?p=form&f=<id>&r=<record>&design=1. The page loads the builder preview's designer (assets/js/nu/nudesigner.js: select, move, resize,
 * Arrange) and assets/js/nu/nudesign.js (bar, properties panel), which saves through the builder API (pages/nubuilderapi.php).
 * A published form is changed in a draft: the first change starts one (draft.start with preview = this session shows the draft), an open
 * draft is continued; users see the published form until Publish. A form that was never published (sfo_status 0) is changed directly.
 */
final class NuDesign
{
	/**
	 * Who may use it (0.8.667): everybody with the save right on the form builder (nuform – admin-equivalent, NuSecurity::restricted), on
	 * edit screens of own forms (and nuBuilder Forte's User Home), not in demo mode. The builder API keeps its limits: only the globeadmin
	 * renames or deletes tabs, publishing needs the admin right formpublish, others work in a draft when form_drafts_required is on.
	 */
	public static function allowed(NuFormMeta $meta): bool
	{
		$u = NuAuth::user();
		return $u !== null && empty($u['demo']) && !NuDemo::active() && (!$meta->isSystem() || $meta->isUserHome())
			&& $meta->type() !== 'page' && NuAcl::can(NuAcl::FORM_FORM, 'save');
	}

	/**
	 * Where an old builder link (?p=builder&f=…) leads (0.8.670): the list designer for list-only forms and ?open=browse, else the edit
	 * screen with the first record (as the builder's preview) or a new one; forms that are not designed here open normally.
	 * @param array<string, mixed> $q the builder link's query
	 */
	public static function fromBuilder(NuFormMeta $meta, array $q): string
	{
		$open = (string)($q['open'] ?? '');
		if (!self::allowed($meta)) return nu_url('form', ['f' => $meta->id, 'r' => $meta->hasBrowse() ? null : '-1']);
		$extra = ['dsobj' => is_string($q['obj'] ?? null) ? $q['obj'] : null, 'dscolchg' => is_string($q['colchg'] ?? null) ? $q['colchg'] : null,
			'dsdelete' => ($q['delete'] ?? '') === '1' ? '1' : null,
			'dswork' => in_array($open, ['code', 'history'], true) ? $open : (($q['draft'] ?? '') === '1' && $open === 'compare' ? 'changes' : null),
			'dsfly' => $open === 'add' ? 'add' : ($open === 'objects' ? 'structure' : null)];
		if ((!$meta->hasEdit() && $meta->type() !== 'subform') || ($open === 'browse' && $meta->hasBrowse())) return nu_url('form', ['f' => $meta->id, 'design' => '1'] + $extra);
		$r = '-1';
		if ($meta->table() !== '' && $meta->primaryKey() !== '' && $meta->type() !== 'launch') {
			try {
				$pk = nu_db()->page('SELECT ' . NuDB::ident($meta->primaryKey()) . ' AS pk FROM ' . NuDB::ident($meta->table()), [], NuDB::ident($meta->primaryKey()), 1, 0);
				if ($pk) $r = (string)$pk[0]['pk'];
			} catch (Throwable) {
			}
		}
		return nu_url('form', ['f' => $meta->id, 'r' => $r, 'design' => '1'] + $extra);
	}

	/** Safe mode (0.8.667): ?safe=1 opens the form without its own code – JavaScript, CSS, Before Edit PHP, Setup → Header / Include, HTML scripts. */
	public static function safe(): bool
	{
		return ($_GET['safe'] ?? '') === '1';
	}

	/** Before the form is rendered: an open draft of the form is shown in this session (its preview). @return bool true = meta must be reloaded */
	public static function previewOpenDraft(NuFormMeta $meta): bool
	{
		if (!NuFormDraft::ready() || NuFormDraft::previewDraft($meta->id)) return false;
		$open = NuFormDraft::openFor($meta->id);
		if (!$open) return false;
		try {
			NuFormDraft::startPreview((string)$open['zzzzsys_nunext_form_draft_id']);
		} catch (Throwable) {
			return false;
		}
		return true;
	}

	/** Client data of design mode (nuNext.design). */
	public static function client(NuFormMeta $meta, string $record): array
	{
		$open = NuFormDraft::ready() ? NuFormDraft::openFor($meta->id) : null;
		// texts (0.8.669): every ds_* / dsg_* key and the builder's (builder_*, bf_*, form_type_*, form_layout*, fai_button_*, da_title) – the panel,
		// the format designer and the attribute editor use them
		$texts = [];
		foreach (array_keys(require NU_ROOT . '/lang/en.php') as $k) {
			if (preg_match('/^(ds_|dsg_|builder_|bf_|form_type_|form_layout|fai_button_|om_|fmt_type_)/', $k)) $texts[$k] = t($k);
		}
		foreach (['cancel', 'close', 'delete', 'yes', 'no', 'save', 'please_select', 'ok', 'form_code_hint', 'form_code_discard', 'da_title', 'add', 'remove', 'search'] as $k) $texts[$k] = t($k);
		$db = nu_db();
		// lists of the panel (stage 4, as the builder: pages/nubuilder.php)
		$forms = array_map(static fn($f) => [$f['zzzzsys_form_id'], $f['sfo_code'] . ' – ' . $f['sfo_description'], $f['sfo_type']],
			$db->all("SELECT zzzzsys_form_id, sfo_code, sfo_description, sfo_type FROM zzzzsys_form WHERE zzzzsys_form_id NOT LIKE 'nu%' ORDER BY sfo_code"));
		$procs = array_map(static fn($p) => [$p['zzzzsys_php_id'], $p['sph_code'] . ($p['sph_description'] ? ' – ' . $p['sph_description'] : '')],
			array_values(array_filter($db->all("SELECT zzzzsys_php_id, sph_code, sph_description FROM zzzzsys_php WHERE zzzzsys_php_id NOT LIKE 'nu%' AND COALESCE(sph_system, '') <> '1' ORDER BY sph_code"),
				static fn($p) => !preg_match('/_(BB|BE|BS|AS|BD|AD|AB)$/', (string)$p['zzzzsys_php_id']))));
		$reports = NuReport::available() ? array_map(static fn($r) => [(string)$r['zzzzsys_report_id'], $r['sre_code'] . ($r['sre_description'] ? ' – ' . $r['sre_description'] : '')],
			$db->all('SELECT zzzzsys_report_id, sre_code, sre_description FROM zzzzsys_report ORDER BY sre_code')) : [];
		$formats = static fn(string $l) => array_map(static fn($f) => [(string)$f['value'], (string)$f['value'] . ($f['description'] !== '' ? ' – ' . $f['description'] : '')], NuFormat::choices($l));
		return [
			'forms' => $forms, 'procedures' => $procs, 'reports' => $reports, 'formats' => ['D' => $formats('D'), 'N' => $formats('N')],
			// the code window of the panel (0.8.665): CodeMirror is loaded on first use
			'cm' => [nu_asset(NU_CODEMIRROR . '/codemirror.css'), nu_asset(NU_CODEMIRROR . '/codemirror-bundle.js'), nu_asset(NU_CODEMIRROR . '/codemirror-addons.js')],
			'draft' => NuFormDraft::info($open),
			'unpublished' => (string)($meta->form['sfo_status'] ?? '') === '0',
			'can_publish' => NuFormDraft::canPublish(),
			'ga' => !empty(NuAuth::user()['globeadmin']),
			'userhome' => $meta->isUserHome(),                      // User Home: only its Before Edit PHP is code of its own (0.8.605)           // tabs: only the globeadmin renames or deletes them (as the builder API)
			'safe' => self::safe(),
			'self' => nu_url('form', ['f' => $meta->id, 'r' => $record, 'design' => '1', 'safe' => self::safe() ? '1' : null]),
			'safe_url' => nu_url('form', ['f' => $meta->id, 'r' => $record, 'design' => '1', 'safe' => self::safe() ? null : '1']),
			'exit' => nu_url('form', ['f' => $meta->id, 'r' => $record, 'draft' => 'off']),
			'texts' => $texts,
			'uppy_template' => NuUpload::htmlTemplate(),                // file objects with target "File System"
			'files' => $db->tableExists('zzzzsys_file') ? array_map(static fn($f) => [$f['zzzzsys_file_id'], $f['sfi_code'] . ($f['sfi_description'] ? ' – ' . $f['sfi_description'] : '')],
				$db->all('SELECT zzzzsys_file_id, sfi_code, sfi_description FROM zzzzsys_file ORDER BY sfi_code')) : [],
			'formats_all' => NuFormat::choices(), 'format_designer' => nu_format_designer_data(),
			'ai' => $meta->isSystem() ? '' : nu_url('formai', ['f' => $meta->id]),
			'dataaccess' => $meta->isSystem() ? '' : nu_url('dataaccess', ['f' => $meta->id]),
			'translations' => !$meta->isSystem() && NuAcl::can(NuAcl::FORM_TRANSLATE) ? ['url' => nu_url('translations', ['view' => 'missing', 'f' => $meta->id]),
				'missing' => NuTranslate::summary($meta->id)] : null,               // Tools → Translations with the missing ones per language (0.8.754)
			'formaccess' => NuAcl::can(NuAcl::FORM_ACCESS),            // Tools → Access levels (0.8.683, the dialog of the ⋮ menu)
			'can_delete_form' => !empty(NuAuth::user()['globeadmin']) && !$meta->isSystem(),
			'live_url' => nu_url('form', ['f' => $meta->id, 'r' => $record]),
			'list_url' => $meta->hasBrowse() ? nu_url('form', ['f' => $meta->id, 'design' => '1']) : '',          // the list's columns (0.8.669)
			'edit_url' => $meta->hasEdit() ? nu_url('form', ['f' => $meta->id, 'r' => '-1', 'design' => '1']) : '',
		];
	}
}
