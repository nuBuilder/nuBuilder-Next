<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/** The draft was changed by somebody else since the builder loaded it (optimistic locking, NuFormDraft::save). */
final class NuDraftRevException extends RuntimeException {}

/**
 * Form drafts (0.8.386): a working copy of a form in zzzzsys_nunext_form_draft (a NuFormSnapshot as JSON) – nuBuilder and the users
 * keep the live form until the draft is published. Lifecycle open → published | discarded (review: Phase 2). Publishing checks that
 * live was not changed since the draft started (hash of base_snapshot), writes the draft into nuBuilder's tables in one transaction
 * (NuFormSnapshot::apply) and records the version in the history ("before publish", "published vN"). A preview session
 * ($_SESSION['nu_form_preview']) makes NuFormMeta::load() return the draft for its author / designers – read-only unless the draft
 * allows saving.
 */
final class NuFormDraft
{
	public const TABLE = 'zzzzsys_nunext_form_draft';
	public const OPEN = 'open', REVIEW = 'review', PUBLISHED = 'published', DISCARDED = 'discarded';
	public const UNDO_MAX = 20;
	public const PREVIEW_TTL = 7200;                 // seconds a preview stays on in the session
	public const TYPES = ['input', 'select', 'textarea', 'editor', 'display', 'calc', 'lookup', 'subform', 'html', 'chart', 'word', 'contentbox', 'run', 'image'];
	private const SESSION = 'nu_form_preview';

	/** @var array<string, ?array> preview snapshots of this request */
	private static array $previewCache = [];

	// ---------------------------------------------------------------- rights
	public static function canEdit(): bool { return NuAcl::can(NuAcl::FORM_FORM, 'save') && !NuDemo::active(); }
	public static function canPublish(): bool { return NuAdmin::can('formpublish', 'change') && !NuDemo::active(); }
	/** Designers (view right on the form builder) may preview; the session entry alone is no permission. */
	public static function canPreview(): bool { return NuAcl::can(NuAcl::FORM_FORM, 'view'); }
	private static function login(): string { return mb_substr((string)(NuAuth::currentRaw()['login'] ?? ''), 0, 50); }
	private static function now(): string { return date('Y-m-d H:i:s'); }
	private static function json(mixed $v): string { return (string)json_encode($v, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE); }

	// ---------------------------------------------------------------- read
	public static function ready(): bool
	{
		try { return nu_db()->tableExists(self::TABLE); } catch (Throwable) { return false; }
	}

	public static function get(string $id): ?array
	{
		if ($id === '' || !self::ready()) return null;
		return nu_db()->one('SELECT * FROM ' . self::TABLE . ' WHERE zzzzsys_nunext_form_draft_id = ?', [$id]);
	}

	/** The open draft of a form (status open or review), null when there is none. */
	public static function openFor(string $formId): ?array
	{
		if ($formId === '' || !self::ready()) return null;
		return nu_db()->page('SELECT * FROM ' . self::TABLE . ' WHERE form_id = ? AND status IN (?, ?)', [$formId, self::OPEN, self::REVIEW], 'created_at DESC', 1, 0)[0] ?? null;
	}

	public static function snapshotOf(array $d, string $col = 'snapshot'): array
	{
		$s = json_decode((string)($d[$col] ?? ''), true);
		return is_array($s) ? $s : [];
	}

	/** For the builder / the forms list: who, when, how many changes, conflict. */
	public static function info(?array $d): ?array
	{
		if (!$d) return null;
		$live = NuFormSnapshot::capture((string)$d['form_id']);
		$snap = self::snapshotOf($d);
		$base = self::snapshotOf($d, 'base_snapshot');
		$undo = json_decode((string)($d['undo_list'] ?? '[]'), true) ?: [];
		return ['id' => (string)$d['zzzzsys_nunext_form_draft_id'], 'form_id' => (string)$d['form_id'], 'status' => (string)$d['status'], 'rev' => (int)$d['rev'],
			'note' => (string)($d['note'] ?? ''), 'created_by' => (string)$d['created_by'], 'created_at' => (string)$d['created_at'],
			'updated_by' => (string)($d['updated_by'] ?? ''), 'updated_at' => (string)($d['updated_at'] ?? ''), 'preview_saves' => (int)($d['preview_saves'] ?? 0) === 1,
			'changes' => $live && $snap ? NuFormSnapshot::diff($live, $snap)['counts']['total'] : 0,
			'conflict' => $live === null || ($base && NuFormSnapshot::hash($live, $base) !== (string)$d['base_hash']),
			'undo_count' => count($undo), 'undo_label' => (string)($undo[0]['label'] ?? ''),
			'redo_count' => count(json_decode((string)($d['redo_list'] ?? '[]'), true) ?: []),
			'mine' => (string)$d['created_by'] === self::login(),
			'preview_url' => nu_url('form', ['f' => (string)$d['form_id'], 'draft' => (string)$d['zzzzsys_nunext_form_draft_id']])];
	}

	// ---------------------------------------------------------------- write
	public static function start(string $formId, string $note = ''): array
	{
		if (!self::canEdit()) throw new RuntimeException(t('err_forbidden'));
		if (str_starts_with($formId, 'nu') && $formId !== NuUserHome::FORM) throw new RuntimeException(t('builder_err_system'));   // User Home: 0.8.605
		NuSchema::ensure();
		if ($open = self::openFor($formId)) throw new RuntimeException(t('builder_draft_exists', (string)$open['created_by']));
		$snap = NuFormSnapshot::capture($formId) ?? throw new RuntimeException(t('form_err_not_found'));
		$id = nu_id();
		$json = self::json($snap);
		nu_db()->insert(self::TABLE, ['zzzzsys_nunext_form_draft_id' => $id, 'form_id' => $formId, 'changeset_id' => $id, 'status' => self::OPEN,
			'note' => mb_substr(trim($note), 0, 1000), 'base_hash' => NuFormSnapshot::hash($snap), 'base_snapshot' => $json, 'snapshot' => $json, 'undo_list' => '[]',
			'rev' => 0, 'preview_saves' => NuSecurity::get('form_preview_saves') ? 1 : 0, 'testers' => null, 'ddl' => '[]',
			'created_at' => self::now(), 'created_by' => self::login(), 'updated_at' => self::now(), 'updated_by' => self::login()]);
		NuLog::write('form_draft_started', (string)($snap['form']['sfo_code'] ?? ''), $formId, ['draft' => $id]);
		return self::get($id) ?? [];
	}

	/** Every change of a draft: the previous snapshot goes to the undo list, rev + 1 – refused when rev is not the current one. */
	public static function save(string $id, int $rev, array $snap, string $label): int
	{
		return nu_db()->transaction(static function (NuDB $db) use ($id, $rev, $snap, $label): int {
			$d = $db->one('SELECT * FROM ' . self::TABLE . ' WHERE zzzzsys_nunext_form_draft_id = ?', [$id]);
			if (!$d || $d['status'] !== self::OPEN) throw new RuntimeException(t('builder_draft_not_open'));
			if ((int)$d['rev'] !== $rev) throw new NuDraftRevException(t('builder_draft_rev', (string)$d['updated_by']));
			$undo = json_decode((string)$d['undo_list'], true) ?: [];
			array_unshift($undo, ['at' => self::now(), 'login' => self::login(), 'label' => mb_substr($label, 0, 60), 'snapshot' => (string)$d['snapshot']]);
			// conditional on rev: two requests with the same rev – only one of them changes the row
			// a new change ends what could be redone (review H2)
			$n = $db->run('UPDATE ' . self::TABLE . ' SET snapshot = ?, undo_list = ?, redo_list = ?, rev = ?, updated_at = ?, updated_by = ? WHERE zzzzsys_nunext_form_draft_id = ? AND rev = ?',
				[self::json($snap), self::json(array_slice($undo, 0, self::UNDO_MAX)), '[]', $rev + 1, self::now(), self::login(), $id, $rev])->rowCount();
			if ($n !== 1) throw new NuDraftRevException(t('builder_draft_rev', (string)$d['updated_by']));
			unset(self::$previewCache[(string)$d['form_id']]);
			return $rev + 1;
		});
	}

	/** Back to the snapshot before the last change. */
	public static function undo(string $id, int $rev): int
	{
		return nu_db()->transaction(static function (NuDB $db) use ($id, $rev): int {
			$d = $db->one('SELECT * FROM ' . self::TABLE . ' WHERE zzzzsys_nunext_form_draft_id = ?', [$id]);
			if (!$d || $d['status'] !== self::OPEN) throw new RuntimeException(t('builder_draft_not_open'));
			if ((int)$d['rev'] !== $rev) throw new NuDraftRevException(t('builder_draft_rev', (string)$d['updated_by']));
			$undo = json_decode((string)$d['undo_list'], true) ?: [];
			$last = array_shift($undo);
			if (!$last) throw new RuntimeException(t('builder_history_empty'));
			$redo = json_decode((string)($d['redo_list'] ?? '[]'), true) ?: [];          // the state undone can be redone (review H2)
			array_unshift($redo, ['at' => self::now(), 'login' => self::login(), 'label' => (string)($last['label'] ?? ''), 'snapshot' => (string)$d['snapshot']]);
			$db->run('UPDATE ' . self::TABLE . ' SET snapshot = ?, undo_list = ?, redo_list = ?, rev = ?, updated_at = ?, updated_by = ? WHERE zzzzsys_nunext_form_draft_id = ? AND rev = ?',
				[(string)$last['snapshot'], self::json($undo), self::json(array_slice($redo, 0, self::UNDO_MAX)), $rev + 1, self::now(), self::login(), $id, $rev]);
			return $rev + 1;
		});
	}

	/** Again the change that undo() took back (review H2, 0.8.765). */
	public static function redo(string $id, int $rev): int
	{
		return nu_db()->transaction(static function (NuDB $db) use ($id, $rev): int {
			$d = $db->one('SELECT * FROM ' . self::TABLE . ' WHERE zzzzsys_nunext_form_draft_id = ?', [$id]);
			if (!$d || $d['status'] !== self::OPEN) throw new RuntimeException(t('builder_draft_not_open'));
			if ((int)$d['rev'] !== $rev) throw new NuDraftRevException(t('builder_draft_rev', (string)$d['updated_by']));
			$redo = json_decode((string)($d['redo_list'] ?? '[]'), true) ?: [];
			$next = array_shift($redo);
			if (!$next) throw new RuntimeException(t('builder_history_empty'));
			$undo = json_decode((string)$d['undo_list'], true) ?: [];
			array_unshift($undo, ['at' => self::now(), 'login' => self::login(), 'label' => (string)($next['label'] ?? ''), 'snapshot' => (string)$d['snapshot']]);
			$db->run('UPDATE ' . self::TABLE . ' SET snapshot = ?, undo_list = ?, redo_list = ?, rev = ?, updated_at = ?, updated_by = ? WHERE zzzzsys_nunext_form_draft_id = ? AND rev = ?',
				[(string)$next['snapshot'], self::json(array_slice($undo, 0, self::UNDO_MAX)), self::json($redo), $rev + 1, self::now(), self::login(), $id, $rev]);
			unset(self::$previewCache[(string)$d['form_id']]);
			return $rev + 1;
		});
	}

	/** A column added for the draft (DDL runs at once – additive and nullable); listed in the publish summary. */
	/** $change (0.8.623): a changed column, e.g. "varchar(25) → varchar(50)" ('' = a column added). */
	public static function addDdl(string $id, string $table, string $column, string $type, string $length, string $change = ''): void
	{
		$d = self::get($id);
		if (!$d) return;
		$ddl = json_decode((string)($d['ddl'] ?? '[]'), true) ?: [];
		$ddl[] = ['table' => $table, 'column' => $column, 'type' => $type, 'length' => $length, 'at' => self::now(), 'login' => self::login()] + ($change !== '' ? ['change' => $change] : []);
		nu_db()->update(self::TABLE, ['ddl' => self::json($ddl)], 'zzzzsys_nunext_form_draft_id', $id);
	}

	public static function setPreviewSaves(string $id, bool $on): void
	{
		$d = self::get($id);
		if (!$d || $d['status'] !== self::OPEN) throw new RuntimeException(t('builder_draft_not_open'));
		nu_db()->update(self::TABLE, ['preview_saves' => $on ? 1 : 0, 'updated_at' => self::now(), 'updated_by' => self::login()], 'zzzzsys_nunext_form_draft_id', $id);
		if ($on) NuLog::write('form_draft_preview_saves', (string)(self::snapshotOf($d)['form']['sfo_code'] ?? ''), (string)$d['form_id'], ['draft' => $id]);
	}

	/** Discard: the author or the globeadmin; the row stays (status discarded) for the log. */
	public static function discard(string $id): void
	{
		$d = self::get($id);
		if (!$d || !in_array($d['status'], [self::OPEN, self::REVIEW], true)) throw new RuntimeException(t('builder_draft_not_open'));
		if ((string)$d['created_by'] !== self::login() && empty(NuAuth::user()['globeadmin']) && !self::canPublish()) throw new RuntimeException(t('err_forbidden'));
		nu_db()->update(self::TABLE, ['status' => self::DISCARDED, 'updated_at' => self::now(), 'updated_by' => self::login()], 'zzzzsys_nunext_form_draft_id', $id);
		self::forgetPreview($id);
		NuLog::write('form_draft_discarded', (string)(self::snapshotOf($d)['form']['sfo_code'] ?? ''), (string)$d['form_id'], ['draft' => $id]);
	}

	/**
	 * Checks before publishing. Errors block: code empty / taken, object ids, types, tabs, PHP that does not parse, forms referred to
	 * that do not exist. Warnings need a tick: objects without a column, browse SQL changed, live changed but forced.
	 * @return array{errors: list<string>, warnings: list<string>}
	 */
	public static function validate(string $formId, array $snap, ?array $live = null): array
	{
		$db = nu_db();
		$e = $w = [];
		$f = $snap['form'] ?? [];
		$code = trim((string)($f['sfo_code'] ?? ''));
		if ($code === '' || (int)$db->value('SELECT COUNT(*) FROM zzzzsys_form WHERE sfo_code = ? AND zzzzsys_form_id <> ?', [$code, $formId])) $e[] = t('builder_draft_err_code', $code);
		$tabs = array_flip(array_map(static fn($t) => (string)$t['zzzzsys_tab_id'], $snap['tabs'] ?? []));
		if (!$tabs && ($snap['objects'] ?? [])) $e[] = t('builder_err_one_tab');
		$seen = [];
		$cols = trim((string)($f['sfo_table'] ?? '')) !== '' && ($f['sfo_type'] ?? '') !== 'launch' ? $db->columns((string)$f['sfo_table']) : [];
		$formExists = static function (string $id) use ($db): bool { return $id === '' || (bool)$db->value('SELECT COUNT(*) FROM zzzzsys_form WHERE zzzzsys_form_id = ?', [$id]); };
		foreach ($snap['objects'] ?? [] as $o) {
			$oid = (string)($o['sob_all_id'] ?? '');
			if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $oid)) $e[] = t('builder_err_id') . ' (' . $oid . ')';
			elseif (isset($seen[$oid])) $e[] = t('builder_err_id_taken', $oid);
			$seen[$oid] = true;
			if (!in_array((string)($o['sob_all_type'] ?? ''), self::TYPES, true)) $e[] = t('builder_err_type') . ' (' . $oid . ')';
			if (!isset($tabs[(string)($o['sob_all_zzzzsys_tab_id'] ?? '')])) $e[] = t('builder_draft_err_tab', $oid);
			foreach (['sob_subform_zzzzsys_form_id' => 'subform', 'sob_lookup_zzzzsys_form_id' => 'lookup'] as $col => $type) {
				if (($o['sob_all_type'] ?? '') === $type && !$formExists(trim((string)($o[$col] ?? '')))) $e[] = t('builder_draft_err_ref', $oid, (string)$o[$col]);
			}
			if (($o['sob_all_type'] ?? '') === 'run' && strtoupper((string)($o['sob_run_type'] ?? '')) === 'F' && !$formExists(trim((string)($o['sob_run_zzzzsys_form_id'] ?? '')))) {
				$e[] = t('builder_draft_err_ref', $oid, (string)$o['sob_run_zzzzsys_form_id']);
			}
			if ($cols && ($p = NuDDL::typeForObject($o)) !== null && NuRecord::column($cols, $oid) === null) $w[] = t('builder_draft_warn_column', $oid, (string)$f['sfo_table']);
		}
		if (!$formExists(trim((string)($f['sfo_browse_redirect_form_id'] ?? '')))) $e[] = t('builder_draft_err_ref', $code, (string)$f['sfo_browse_redirect_form_id']);
		foreach ($snap['php'] ?? [] as $p) {
			try { token_get_all('<?php ' . (string)($p['sph_php'] ?? ''), TOKEN_PARSE); }
			catch (Throwable $x) { $e[] = t('builder_draft_err_php', (string)$p['zzzzsys_php_id'], $x->getMessage()); }
		}
		if ($live && trim((string)($live['form']['sfo_browse_sql'] ?? '')) !== trim((string)($f['sfo_browse_sql'] ?? ''))) $w[] = t('builder_draft_warn_browse');
		return ['errors' => array_values(array_unique($e)), 'warnings' => array_values(array_unique($w))];
	}

	/** The highest published version of a form (history "published vN" and the drafts table). */
	public static function latestVersion(string $formId): int
	{
		$v = self::ready() ? (int)nu_db()->value('SELECT MAX(version) FROM ' . self::TABLE . ' WHERE form_id = ?', [$formId]) : 0;
		foreach (nu_db()->all("SELECT action FROM zzzzsys_nunext_form_history WHERE form_id = ? AND action LIKE 'published v%'", [$formId]) as $r) {
			if (preg_match('/^published v(\d+)/', (string)$r['action'], $m)) $v = max($v, (int)$m[1]);
		}
		return $v;
	}

	/**
	 * Publish: the draft becomes the live form. @return array{ok: bool, version?: int, conflict?: bool, changes?: array,
	 * errors?: list<string>, warnings?: list<string>}
	 */
	public static function publish(string $id, string $note, bool $force = false, bool $warningsOk = false): array
	{
		$d = self::get($id);
		if (!$d || !in_array($d['status'], [self::OPEN, self::REVIEW], true)) throw new RuntimeException(t('builder_draft_not_open'));
		if (!self::canPublish()) throw new RuntimeException(t('err_forbidden'));
		$note = trim($note);
		if ($note === '') return ['ok' => false, 'errors' => [t('builder_draft_note_required')]];
		$formId = (string)$d['form_id'];
		$live = NuFormSnapshot::capture($formId);
		if ($live === null) return ['ok' => false, 'errors' => [t('builder_draft_form_gone')]];
		$base = self::snapshotOf($d, 'base_snapshot');
		$snap = self::snapshotOf($d);
		$conflict = NuFormSnapshot::hash($live, $base) !== (string)$d['base_hash'];
		if ($conflict && !$force) return ['ok' => false, 'conflict' => true, 'changes' => NuFormSnapshot::diff($base, $live)];
		$v = self::validate($formId, $snap, $live);
		if ($v['errors']) return ['ok' => false, 'errors' => $v['errors']];
		if ($v['warnings'] && !$warningsOk) return ['ok' => false, 'warnings' => $v['warnings']];
		// only the globeadmin renames or deletes tabs (0.8.247) – also not through a draft
		if (empty(NuAuth::user()['globeadmin'])) {
			$tabs = [];
			foreach ($snap['tabs'] ?? [] as $t) $tabs[(string)$t['zzzzsys_tab_id']] = trim((string)$t['syt_title']);
			foreach ($live['tabs'] as $t) {
				$tid = (string)$t['zzzzsys_tab_id'];
				if (!array_key_exists($tid, $tabs) || $tabs[$tid] !== trim((string)$t['syt_title'])) return ['ok' => false, 'errors' => [t('builder_tabs_globeadmin')]];
			}
		}
		$before = NuFormHistory::snapshot($formId, 'before publish');
		nu_db()->transaction(static fn(NuDB $db) => NuFormSnapshot::apply($db, $formId, $snap));
		NuFormMeta::forget($formId);
		$version = self::latestVersion($formId) + 1;
		NuFormHistory::snapshot($formId, 'published v' . $version . ' – ' . mb_substr($note, 0, 40));
		nu_db()->update(self::TABLE, ['status' => self::PUBLISHED, 'note' => mb_substr($note, 0, 1000), 'published_at' => self::now(), 'published_by' => self::login(),
			'version' => $version, 'history_id' => $before, 'updated_at' => self::now(), 'updated_by' => self::login()], 'zzzzsys_nunext_form_draft_id', $id);
		$code = (string)($snap['form']['sfo_code'] ?? '');
		NuLog::write($conflict ? 'form_draft_forced' : 'form_published', $code, $formId, ['draft' => $id, 'version' => $version, 'note' => mb_substr($note, 0, 200)]);
		self::forgetPreview($id);
		return ['ok' => true, 'version' => $version];
	}

	/** A form that was never published (status Draft, sfo_status 0): Publish = status Active and version 1. */
	public static function publishNewForm(string $formId): int
	{
		if (!self::canPublish()) throw new RuntimeException(t('err_forbidden'));
		$f = nu_db()->one('SELECT sfo_code, sfo_status FROM zzzzsys_form WHERE zzzzsys_form_id = ?', [$formId]) ?? throw new RuntimeException(t('form_err_not_found'));
		if ((string)$f['sfo_status'] !== '0') throw new RuntimeException(t('builder_draft_not_open'));
		nu_db()->update('zzzzsys_form', ['sfo_status' => '1'], 'zzzzsys_form_id', $formId);
		NuFormMeta::forget($formId);
		$version = self::latestVersion($formId) + 1;
		NuFormHistory::snapshot($formId, 'published v' . $version);
		NuLog::write('form_published', (string)$f['sfo_code'], $formId, ['version' => $version, 'new' => true]);
		return $version;
	}

	/**
	 * Live edits in the builder (and the label menu) while drafts are in use: a form never published may always be edited; "changes only
	 * through drafts" – only the globeadmin; an open draft – non-globeadmins are refused, the globeadmin after a confirmation ($liveOk).
	 * @return array{code: string, message: string}|null refusal, null = allowed
	 */
	public static function liveEditRefused(string $formId, bool $liveOk): ?array
	{
		if (!self::ready()) return null;
		if ((string)nu_db()->value('SELECT sfo_status FROM zzzzsys_form WHERE zzzzsys_form_id = ?', [$formId]) === '0') return null;
		$ga = !empty(NuAuth::user()['globeadmin']);
		$open = self::openFor($formId);
		if ($open) {
			if (!$ga) return ['code' => 'draft_open', 'message' => t('builder_draft_open', (string)$open['created_by'])];
			return $liveOk ? null : ['code' => 'draft_open_confirm', 'message' => t('builder_draft_live_confirm')];
		}
		if (NuSecurity::get('form_drafts_required') && !$ga) return ['code' => 'draft_required', 'message' => t('builder_draft_required')];
		return null;
	}

	// ---------------------------------------------------------------- preview session
	/** Starts the preview of a draft in this session (plus the open drafts of the same author for the forms it shows inside). */
	public static function startPreview(string $id): void
	{
		$d = self::get($id);
		if (!$d || !in_array($d['status'], [self::OPEN, self::REVIEW], true)) throw new RuntimeException(t('builder_draft_not_open'));
		if (!self::canPreview()) throw new RuntimeException(t('draft_preview_denied'));
		$s = $_SESSION[self::SESSION] ?? [];
		$drafts = is_array($s['drafts'] ?? null) && (int)($s['until'] ?? 0) > time() ? $s['drafts'] : [];
		$drafts[(string)$d['form_id']] = $id;
		foreach (self::snapshotOf($d)['objects'] ?? [] as $o) {
			foreach (['sob_subform_zzzzsys_form_id', 'sob_run_zzzzsys_form_id', 'sob_lookup_zzzzsys_form_id'] as $col) {
				$ref = trim((string)($o[$col] ?? ''));
				if ($ref === '' || isset($drafts[$ref])) continue;
				$rd = self::openFor($ref);
				if ($rd && (string)$rd['created_by'] === (string)$d['created_by']) $drafts[$ref] = (string)$rd['zzzzsys_nunext_form_draft_id'];
			}
		}
		$_SESSION[self::SESSION] = ['until' => time() + self::PREVIEW_TTL, 'drafts' => $drafts, 'saves_ok' => (array)($s['saves_ok'] ?? [])];
		self::$previewCache = [];
	}

	public static function stopPreview(): void
	{
		unset($_SESSION[self::SESSION]);
		self::$previewCache = [];
	}

	private static function forgetPreview(string $draftId): void
	{
		$s = $_SESSION[self::SESSION] ?? null;
		if (!is_array($s)) return;
		$s['drafts'] = array_filter((array)($s['drafts'] ?? []), static fn($v) => $v !== $draftId);
		if ($s['drafts']) $_SESSION[self::SESSION] = $s; else unset($_SESSION[self::SESSION]);
		self::$previewCache = [];
	}

	/** The draft previewed for a form in this session (row), null when none (expired, closed, no right). */
	public static function previewDraft(string $formId): ?array
	{
		$s = $_SESSION[self::SESSION] ?? null;
		if (!is_array($s) || (int)($s['until'] ?? 0) < time()) return null;
		$id = (string)(($s['drafts'] ?? [])[$formId] ?? '');
		if ($id === '' || !self::canPreview()) return null;
		$d = self::get($id);
		return $d && in_array($d['status'], [self::OPEN, self::REVIEW], true) ? $d : null;
	}

	/** The snapshot NuFormMeta::load() uses instead of live (cached per request). */
	public static function previewSnapshot(string $formId): ?array
	{
		if (!isset($_SESSION[self::SESSION]) || $formId === '') return null;
		if (!array_key_exists($formId, self::$previewCache)) {
			$d = self::previewDraft($formId);
			self::$previewCache[$formId] = $d ? self::snapshotOf($d) : null;
		}
		return self::$previewCache[$formId];
	}

	public static function previewActive(): bool
	{
		$s = $_SESSION[self::SESSION] ?? null;
		if (!is_array($s)) return false;
		if ((int)($s['until'] ?? 0) < time()) { unset($_SESSION[self::SESSION]); return false; }
		return (bool)($s['drafts'] ?? []);
	}

	/** Client data of the form page: the draft shown (label menu, iframe badge). */
	public static function previewInfo(string $formId): ?array
	{
		$d = self::previewDraft($formId);
		if (!$d) return null;
		return ['id' => (string)$d['zzzzsys_nunext_form_draft_id'], 'rev' => (int)$d['rev'], 'author' => (string)$d['created_by'], 'saves' => self::previewSaves($formId)];
	}

	/** Save / Delete in the preview: the draft allows it and the user confirmed it once in this session. */
	public static function previewSaves(string $formId): bool
	{
		$d = self::previewDraft($formId);
		if (!$d || (int)$d['preview_saves'] !== 1) return false;
		return !empty(($_SESSION[self::SESSION]['saves_ok'] ?? [])[(string)$d['zzzzsys_nunext_form_draft_id']]);
	}

	public static function confirmPreviewSaves(string $formId): void
	{
		$d = self::previewDraft($formId);
		if ($d && (int)$d['preview_saves'] === 1) $_SESSION[self::SESSION]['saves_ok'][(string)$d['zzzzsys_nunext_form_draft_id']] = true;
	}

	/** The yellow banner on every page while a preview is on (bare pages / iframes: a small badge). */
	public static function banner(bool $bare): string
	{
		if (!self::previewActive()) return '';
		if ($bare) return '<div class="nuDraftBadge" role="status"><i class="bi bi-cone-striped me-1" aria-hidden="true"></i>' . h(t('draft_preview_badge')) . '</div>';
		$drafts = (array)($_SESSION[self::SESSION]['drafts'] ?? []);
		$formId = (string)array_key_first($drafts);
		$d = self::previewDraft($formId);
		if (!$d) return '';
		$info = self::info($d) ?? [];
		$desc = (string)(self::snapshotOf($d)['form']['sfo_description'] ?? $formId);
		$saves = self::previewSaves($formId) || (int)$d['preview_saves'] === 1;
		return '<div class="alert alert-warning rounded-0 border-0 border-bottom mb-0 py-2 nuDraftBanner" role="status"><div class="container-fluid d-flex flex-wrap align-items-center gap-2">'
			. '<i class="bi bi-cone-striped" aria-hidden="true"></i><span class="me-auto"><strong>' . h(t('draft_preview_banner', $desc, (string)$d['created_by'], (string)($info['changes'] ?? 0))) . '</strong> '
			. h(t($saves ? 'draft_preview_saves_on' : 'draft_preview_saves_off')) . (count($drafts) > 1 ? ' (+' . (count($drafts) - 1) . ')' : '') . '</span>'
			. (NuAcl::can(NuAcl::FORM_FORM, 'view') ? '<a class="btn btn-sm btn-outline-dark" href="' . h(nu_url('builder', ['f' => $formId, 'draft' => '1'])) . '"><i class="bi bi-tools me-1" aria-hidden="true"></i>' . h(t('draft_preview_builder')) . '</a>' : '')
			. '<a class="btn btn-sm btn-dark" id="nuDraftLeave" href="' . h(nu_url('form', ['f' => $formId, 'draft' => 'off'])) . '"><i class="bi bi-box-arrow-right me-1" aria-hidden="true"></i>' . h(t('draft_preview_leave')) . '</a>'
			. '</div></div>';
	}
}
