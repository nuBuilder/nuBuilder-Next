<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * The form builder's actions on a draft (0.8.386): the same rules as the live code in pages/nubuilderapi.php, applied to the draft's
 * snapshot (NuFormSnapshot format) instead of nuBuilder's tables. New rows hold what the live code inserts, so publishing writes
 * what the live builder would have written. Every change ends with commit() = NuFormDraft::save()
 * (rev check, undo list).
 */
final class NuDraftStore
{
	private array $snap;
	private ?NuFormMeta $meta = null;

	private function __construct(public readonly string $draftId, public readonly string $formId, public int $rev, array $snap)
	{
		$this->snap = $snap;
	}

	/** The open draft $draftId of form $formId at revision $rev (the builder's copy) – checked here. */
	public static function open(string $draftId, string $formId, int $rev): self
	{
		$d = NuFormDraft::get($draftId);
		if (!$d || (string)$d['form_id'] !== $formId) throw new RuntimeException(t('record_not_found'));
		if ($d['status'] !== NuFormDraft::OPEN) throw new RuntimeException(t('builder_draft_not_open'));
		if ($rev >= 0 && (int)$d['rev'] !== $rev) throw new NuDraftRevException(t('builder_draft_rev', (string)$d['updated_by']));
		return new self($draftId, $formId, (int)$d['rev'], NuFormDraft::snapshotOf($d));
	}

	public function snapshot(): array { return $this->snap; }

	public function meta(): NuFormMeta
	{
		return $this->meta ??= NuFormMeta::fromSnapshot($this->snap);
	}

	public function commit(string $label): void
	{
		$this->rev = NuFormDraft::save($this->draftId, $this->rev, $this->snap, $label);
		$this->meta = null;
	}

	// ---------------------------------------------------------------- rows
	/**
	 * A new row: the values the live code inserts (columns of today's table only) – the other columns get their database defaults
	 * when the draft is published, as with the live insert (some of nuBuilder's columns are NOT NULL with a default).
	 */
	private static function row(string $table, array $values): array
	{
		$cols = nu_db()->columns($table);
		return $cols ? array_intersect_key($values, $cols) : $values;
	}

	/**
	 * Changed values only – as NuDB::update() on nuBuilder's tables (NULL and '' count as the same, 0.8.364): a value that is not
	 * really different keeps what the row has, so publishing writes the same as the live builder.
	 */
	private static function set(array $row, array $fields): array
	{
		foreach ($fields as $k => $v) {
			if (array_key_exists($k, $row) && (is_scalar($v) || $v === null) && (string)($row[$k] ?? '') === (string)($v ?? '')) continue;
			$row[$k] = $v;
		}
		return $row;
	}

	private function find(string $key, string $pk, string $id): ?int
	{
		foreach ($this->snap[$key] ?? [] as $i => $r) if ((string)($r[$pk] ?? '') === $id) return $i;
		return null;
	}

	public function object(string $objectId): ?array
	{
		$i = $this->find('objects', 'zzzzsys_object_id', $objectId);
		return $i === null ? null : $this->snap['objects'][$i];
	}

	public function php(string $id): ?string
	{
		$i = $this->find('php', 'zzzzsys_php_id', $id);
		return $i === null ? null : ($this->snap['php'][$i]['sph_php'] ?? null);
	}

	/** nb_upsert_php() semantics: an existing row gets the code (or null), a new row only when there is code. */
	public function upsertPhp(string $id, ?string $code): void
	{
		$code = ($code !== null && trim($code) !== '') ? $code : null;
		$i = $this->find('php', 'zzzzsys_php_id', $id);
		if ($i !== null) { $this->snap['php'][$i] = self::set($this->snap['php'][$i], ['sph_php' => $code]); return; }
		if ($code === null) return;
		$this->snap['php'][] = self::row('zzzzsys_php', ['zzzzsys_php_id' => $id, 'sph_code' => $id, 'sph_description' => 'System PHP', 'sph_group' => 'nubuilder',
			'sph_php' => $code, 'sph_system' => '1', 'sph_global' => '0', 'sph_hide' => '1']);
	}

	// ---------------------------------------------------------------- actions (pages/nubuilderapi.php, draft mode)
	public function formSave(array $fields, ?string $layout, ?array $php, array $hooks): void
	{
		if (isset($fields['sfo_code']) && (int)nu_db()->value('SELECT COUNT(*) FROM zzzzsys_form WHERE sfo_code = ? AND zzzzsys_form_id <> ?', [$fields['sfo_code'], $this->formId])) {
			throw new RuntimeException(t('builder_err_code_taken'));
		}
		if ($layout !== null) $fields['sfo_json'] = NuFormMeta::mergeNunextJson($this->snap['form']['sfo_json'] ?? null, ['layout' => $layout === 'absolute' ? null : $layout]);
		$this->snap['form'] = self::set($this->snap['form'], $fields);
		if ($php !== null) foreach ($hooks as $h) if (array_key_exists($h, $php)) $this->upsertPhp($this->formId . '_' . $h, $php[$h] === null ? null : (string)$php[$h]);
	}

	/** @return string the object id (new: nu_id()) */
	public function objectSave(string $objectId, array $fields, ?array $grid, ?array $events, bool $hasLookupPhp, ?string $lookupPhp): string
	{
		$existing = $objectId !== '' ? $this->object($objectId) : null;
		if ($objectId !== '' && !$existing) throw new RuntimeException(t('record_not_found'));
		if (isset($fields['sob_all_id'])) $fields['sob_all_id'] = trim((string)$fields['sob_all_id']);   // spaces at the ends (0.8.536)
		$type = (string)($fields['sob_all_type'] ?? $existing['sob_all_type'] ?? '');
		$fid = (string)($fields['sob_all_id'] ?? $existing['sob_all_id'] ?? '');
		if (!in_array($type, NuFormDraft::TYPES, true)) throw new RuntimeException(t('builder_err_type'));
		if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $fid)) throw new RuntimeException(t('builder_err_id'));
		foreach ($this->snap['objects'] as $o) if ((string)$o['sob_all_id'] === $fid && (string)$o['zzzzsys_object_id'] !== $objectId) throw new RuntimeException(t('builder_err_id_taken', $fid));
		if (isset($fields['sob_all_zzzzsys_tab_id']) && $fields['sob_all_zzzzsys_tab_id'] !== null && $this->find('tabs', 'zzzzsys_tab_id', (string)$fields['sob_all_zzzzsys_tab_id']) === null) {
			throw new RuntimeException(t('builder_err_tab'));
		}
		if ($grid !== null) {
			$fields['sob_all_json'] = NuFormMeta::mergeNunextJson($existing['sob_all_json'] ?? null, [
				'row' => isset($grid['row']) && $grid['row'] !== '' ? max(1, (int)$grid['row']) : null,
				'col' => isset($grid['col']) && $grid['col'] !== '' ? (int)$grid['col'] : null,
				'span' => isset($grid['span']) && $grid['span'] !== '' ? max(1, min(12, (int)$grid['span'])) : null,
				'box' => isset($grid['box']) && preg_match('/^\w+$/', (string)$grid['box']) ? (string)$grid['box'] : null,
			]);
		}
		$fields = array_intersect_key($fields, nu_db()->columns('zzzzsys_object') ?: $fields);
		if ($existing) {
			$i = (int)$this->find('objects', 'zzzzsys_object_id', $objectId);
			$this->snap['objects'][$i] = self::set($this->snap['objects'][$i], $fields);
		} else {
			$objectId = nu_id();
			$this->snap['objects'][] = self::row('zzzzsys_object', ['zzzzsys_object_id' => $objectId, 'sob_all_zzzzsys_form_id' => $this->formId,
				'sob_all_table' => trim((string)($this->snap['form']['sfo_table'] ?? '')), 'sob_all_cloneable' => '1', 'sob_all_access' => '0', 'sob_all_validate' => '0'] + $fields);
		}
		if ($events !== null) {
			$new = [];
			foreach ($events as $e) {
				$ev = strtolower(trim((string)($e['event'] ?? '')));
				$js = (string)($e['js'] ?? '');
				if ($ev === '' || trim($js) === '' || !preg_match('/^[a-z]+$/', $ev)) continue;
				$new[] = [$ev, $js];
			}
			$mine = array_values(array_filter($this->snap['events'] ?? [], static fn($r) => (string)$r['sev_zzzzsys_object_id'] === $objectId));
			usort($mine, static fn($a, $b) => strcmp((string)$a['zzzzsys_event_id'], (string)$b['zzzzsys_event_id']));
			$old = array_map(static fn($r) => [(string)$r['sev_event'], (string)$r['sev_javascript']], $mine);
			$key = static fn(array $l) => json_encode(array_map(static fn($x) => $x[0] . "\n" . $x[1], $l));
			$sort = static function (array $l) { usort($l, static fn($a, $b) => strcmp($a[0] . $a[1], $b[0] . $b[1])); return $l; };
			if ($key($sort($old)) !== $key($sort($new))) {       // unchanged events keep their rows (and ids) – as live (0.8.366)
				$this->snap['events'] = array_values(array_filter($this->snap['events'] ?? [], static fn($r) => (string)$r['sev_zzzzsys_object_id'] !== $objectId));
				foreach ($new as [$ev, $js]) $this->snap['events'][] = self::row('zzzzsys_event', ['zzzzsys_event_id' => nu_id(), 'sev_zzzzsys_object_id' => $objectId, 'sev_event' => $ev, 'sev_javascript' => $js]);
			}
		}
		if ($hasLookupPhp) $this->upsertPhp($objectId . '_AB', $lookupPhp);
		$this->meta = null;
		return $objectId;
	}

	/** Column proposal for an object without a column in the form's table (nb_missing_column() on the draft). */
	public function missingColumn(string $objectId): ?array
	{
		$table = trim((string)($this->snap['form']['sfo_table'] ?? ''));
		if ($table === '' || ($this->snap['form']['sfo_type'] ?? '') === 'launch') return null;
		$o = $this->object($objectId);
		if (!$o) return null;
		$proposal = NuDDL::typeForObject($o);
		if ($proposal === null) return null;
		$cols = nu_db()->columns($table);
		if (!$cols || NuRecord::column($cols, (string)$o['sob_all_id']) !== null) return null;
		return ['name' => (string)$o['sob_all_id'], 'type' => $proposal[0], 'length' => $proposal[1], 'table' => $table];
	}

	/** The sub-form's link column gets an index (shared and harmless – as live, 0.8.366). */
	public function subformIndex(string $objectId): void
	{
		$o = $this->object($objectId);
		if (!$o || $o['sob_all_type'] !== 'subform') return;
		$table = (string)nu_db()->value('SELECT sfo_table FROM zzzzsys_form WHERE zzzzsys_form_id = ?', [(string)($o['sob_subform_zzzzsys_form_id'] ?? '')]);
		$fk = trim((string)($o['sob_subform_foreign_key'] ?? ''));   // a new sub-form object of a draft has neither yet (0.8.679)
		if (trim($table) === '' || $fk === '') return;
		try { NuDDL::ensureIndex($table, $fk); } catch (Throwable $e) { error_log('nuBuilder Next index: ' . $e->getMessage()); }
	}

	public function objectDelete(string $objectId): void
	{
		$this->snap['objects'] = array_values(array_filter($this->snap['objects'], static fn($r) => (string)$r['zzzzsys_object_id'] !== $objectId));
		$this->snap['events'] = array_values(array_filter($this->snap['events'] ?? [], static fn($r) => (string)$r['sev_zzzzsys_object_id'] !== $objectId));
		$this->snap['php'] = array_values(array_filter($this->snap['php'] ?? [], static fn($r) => (string)$r['zzzzsys_php_id'] !== $objectId . '_AB'));
		$gone = [];
		$this->snap['selects'] = array_values(array_filter($this->snap['selects'] ?? [], static function ($r) use ($objectId, &$gone) {
			if (!str_starts_with((string)$r['zzzzsys_select_id'], $objectId . '_')) return true;
			$gone[] = (string)$r['zzzzsys_select_id'];
			return false;
		}));
		$this->snap['clauses'] = array_values(array_filter($this->snap['clauses'] ?? [], static fn($r) => !in_array((string)($r['ssc_zzzzsys_select_id'] ?? ''), $gone, true)));
		$this->meta = null;
	}

	public function objectOrder(array $ids): void
	{
		foreach (array_values($ids) as $n => $oid) {
			$i = $this->find('objects', 'zzzzsys_object_id', (string)$oid);
			if ($i !== null) $this->snap['objects'][$i] = self::set($this->snap['objects'][$i], ['sob_all_order' => (string)(($n + 1) * 10)]);
		}
		$this->meta = null;
	}

	/** tabs.save: the list replaces the tabs; objects of removed tabs move to the first tab. */
	public function tabsSave(array $rows): void
	{
		$tabs = [];
		foreach (array_values($rows) as $n => $t) {
			$data = ['syt_title' => trim((string)($t['title'] ?? '')) ?: 'Tab', 'syt_order' => (string)(($n + 1) * 10),
				'syt_access' => in_array((string)($t['access'] ?? '0'), ['0', '2', '3'], true) ? (string)$t['access'] : '0'];
			$id = (string)($t['id'] ?? '');
			$i = $id !== '' ? $this->find('tabs', 'zzzzsys_tab_id', $id) : null;
			$tabs[] = $i !== null ? self::set($this->snap['tabs'][$i], $data) : self::row('zzzzsys_tab', ['zzzzsys_tab_id' => nu_id(), 'syt_zzzzsys_form_id' => $this->formId] + $data);
		}
		$keep = array_map(static fn($t) => (string)$t['zzzzsys_tab_id'], $tabs);
		foreach ($this->snap['objects'] as $i => $o) if (!in_array((string)($o['sob_all_zzzzsys_tab_id'] ?? ''), $keep, true)) $this->snap['objects'][$i] = self::set($o, ['sob_all_zzzzsys_tab_id' => $keep[0]]);
		$this->snap['tabs'] = $tabs;
		$this->meta = null;
	}

	/** browse.save: the list replaces the browse columns (sbr_json keeps other keys; nuBuilder Next's default filters). */
	public function browseSave(array $rows): void
	{
		$out = [];
		foreach (array_values($rows) as $n => $c) {
			$data = ['sbr_title' => (string)($c['title'] ?? ''), 'sbr_display' => (string)($c['display'] ?? ''),
				'sbr_align' => in_array($c['align'] ?? 'l', ['l', 'c', 'r'], true) ? $c['align'] : 'l',
				'sbr_format' => trim((string)($c['format'] ?? '')) ?: null, 'sbr_order' => (string)(($n + 1) * 10), 'sbr_width' => (string)max(0, (int)($c['width'] ?? 100))];
			$id = (string)($c['id'] ?? '');
			$i = $id !== '' ? $this->find('browse', 'zzzzsys_browse_id', $id) : null;
			$defFilter = in_array(strtolower(trim($data['sbr_display'])), NuFormMeta::DEFAULT_FILTERS[$this->formId] ?? [], true);
			$data['sbr_json'] = NuFormMeta::mergeNunextJson($i !== null ? ($this->snap['browse'][$i]['sbr_json'] ?? null) : null, ['filter' => !empty($c['filter']) ? true : ($defFilter ? false : null)]);
			if (array_key_exists('badge', $c)) $data['sbr_json'] = NuFormMeta::mergeNunextJson($data['sbr_json'], ['badge' => NuFormMeta::cleanBadge($c['badge'])]);
			if (trim($data['sbr_display']) === '') continue;
			$out[] = $i !== null ? self::set($this->snap['browse'][$i], $data) : self::row('zzzzsys_browse', ['zzzzsys_browse_id' => nu_id(), 'sbr_zzzzsys_form_id' => $this->formId] + $data);
		}
		$this->snap['browse'] = $out;
		$this->meta = null;
	}

	/** layout.convert: absolute positions → grid placement (NuLayout), form layout grid. */
	public function layoutConvert(): void
	{
		$meta = $this->meta();
		$tabs = $meta->tabs ?: [['zzzzsys_tab_id' => '']];
		foreach ($tabs as $t) {
			foreach (NuLayout::placement($meta->objectsOfTab((string)$t['zzzzsys_tab_id'])) as $oid => $pl) {
				$i = $this->find('objects', 'zzzzsys_object_id', (string)$oid);
				if ($i === null) continue;
				$this->snap['objects'][$i] = self::set($this->snap['objects'][$i], ['sob_all_json' => NuFormMeta::mergeNunextJson($this->snap['objects'][$i]['sob_all_json'] ?? null,
					['row' => $pl['row'], 'col' => $pl['col'], 'span' => $pl['span'], 'box' => $pl['box'] ?: null])]);
			}
		}
		$this->snap['form'] = self::set($this->snap['form'], ['sfo_json' => NuFormMeta::mergeNunextJson($this->snap['form']['sfo_json'] ?? null, ['layout' => 'grid'])]);
		$this->meta = null;
	}

	/** The builder view of the draft (nb_state() of pages/nubuilderapi.php) + the draft's info. */
	public function state(array $hooks): array
	{
		$meta = $this->meta();
		$php = [];
		foreach ($hooks as $h) $php[$h] = (string)$this->php($this->formId . '_' . $h);
		$events = [];
		foreach ($meta->objects as $o) {
			$rows = array_values(array_filter($this->snap['events'] ?? [], static fn($r) => (string)$r['sev_zzzzsys_object_id'] === (string)$o['zzzzsys_object_id']));
			usort($rows, static fn($a, $b) => strcmp((string)$a['sev_event'], (string)$b['sev_event']));
			$events[$o['zzzzsys_object_id']] = array_map(static fn($r) => ['event' => $r['sev_event'], 'js' => $r['sev_javascript']], $rows);
		}
		$ab = [];
		foreach ($meta->objects as $o) if ($o['sob_all_type'] === 'lookup') $ab[$o['zzzzsys_object_id']] = (string)$this->php($o['zzzzsys_object_id'] . '_AB');
		$objects = array_map(static function ($o) { unset($o['tab_index']); return $o; }, $meta->objects);
		return ['form' => $meta->form + ['layout' => $meta->layout()], 'tabs' => $meta->tabs, 'objects' => $objects, 'browse' => $meta->browse,
			'events' => (object)$events, 'php' => $php, 'lookup_php' => (object)$ab, 'readonly' => false,
			'draft' => NuFormDraft::info(NuFormDraft::get($this->draftId))];
	}
}
