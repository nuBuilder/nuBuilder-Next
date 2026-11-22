<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * Records of the application's forms through the REST API – api.php/v1/forms/{form}/records[/{id}]:
 * the same path as the form in the browser – NuRecord::save / delete (form rights, field data access, validation, validation rules,
 * Before / After Save and Delete, audit trail, webhooks, recycle bin), lists through the form's list query (Before Browse, browse SQL
 * = what the list shows, its search). Field names = the object ids of the form (sob_all_id), the primary key = "id"; sub-forms by
 * their object id as lists of rows. Scopes records:read / records:write AND the rights of the service user's access level.
 * Not available: nuBuilder Forte's own forms (ids nu…), forms on zzzzsys_ tables, admin-equivalent forms, custom pages, launch forms,
 * forms without table / primary key, drafts (status Draft), forms in maintenance or archived (503).
 * GET /v1/forms (+ /{form} = the form's fields) and GET /v1/openapi.json (OpenAPI 3.1 of the routes and the forms the client may use).
 */
final class NuApiRecords
{
	private const SAVED = ['input', 'select', 'textarea', 'calc', 'lookup', 'editor', 'display'];
	private const RESERVED = ['q', 'sort', 'limit', 'offset', 'fields', 'include', 'expand', 'path'];
	private const OPS = ['eq' => '=', 'ne' => '<>', 'gt' => '>', 'gte' => '>=', 'lt' => '<', 'lte' => '<='];

	// ================================================================= forms

	/** The form by id or code, if the API may serve it to this service user (else 404 / 403 / 503). */
	public static function form(string $ref): NuFormMeta
	{
		$ref = rawurldecode($ref);
		$meta = $ref !== '' ? (NuFormMeta::load($ref, true) ?? NuFormMeta::byCode($ref)) : null;
		if (!$meta || !self::eligible($meta)) throw new NuApiError(404, 'Unknown form.');
		if (!NuAcl::can($meta->id, 'view')) throw new NuApiError(403, "The service user's access level has no right on this form.");
		$st = NuFormStatus::norm($meta->form['sfo_status'] ?? '');
		if ($st === '0') throw new NuApiError(404, 'Unknown form.');                    // never published (status Draft)
		if (NuFormStatus::blocked($st)) throw new NuApiError(503, 'The form is in maintenance or archived.', [], ['Retry-After' => '600']);
		return $meta;
	}

	private static function eligible(NuFormMeta $meta): bool
	{
		$t = strtolower($meta->table());
		return $t !== '' && $meta->primaryKey() !== '' && !str_starts_with(strtolower($meta->id), 'nu') && !str_starts_with($t, 'zzzzsys_')
			&& !in_array($meta->id, NuSecurity::ADMIN_FORMS, true) && !in_array($meta->type(), ['page', 'launch'], true) && !NuSecurity::runtimeBlocked($meta);
	}

	/** What the service user may do: list, read, create, update, delete. */
	private static function rights(NuFormMeta $meta): array
	{
		$r = NuAcl::rights($meta->id);
		$read = !empty($r['view']) && (!empty($r['browse']) || !empty($r['edit']));
		return ['list' => !empty($r['view']) && !empty($r['browse']) && $meta->hasBrowse(), 'read' => $read,
			'create' => !empty($r['create']) && !empty($r['edit']), 'update' => !empty($r['save']) && !empty($r['edit']) && empty($r['no_edits']),
			'delete' => !empty($r['delete'])];
	}

	private static function need(NuFormMeta $meta, string $right): void
	{
		if (!self::rights($meta)[$right]) throw new NuApiError(403, "The service user's access level has no '$right' right on this form.");
		if ($right !== 'list' && $right !== 'read' && NuDemo::active()) throw new NuApiError(403, 'Not in demo mode.');
	}

	/** GET /v1/forms – the forms the client may use. */
	public static function formsList(): array
	{
		$out = [];
		foreach (nu_db()->all("SELECT zzzzsys_form_id FROM zzzzsys_form WHERE COALESCE(sfo_table, '') <> '' ORDER BY sfo_code") as $r) {
			$m = NuFormMeta::load((string)$r['zzzzsys_form_id'], true);
			if (!$m || !self::eligible($m) || !NuAcl::can($m->id, 'view') || NuFormStatus::norm($m->form['sfo_status'] ?? '') === '0') continue;
			$out[] = self::formOut($m, false);
		}
		return [200, ['data' => $out, 'meta' => ['total' => count($out)]], []];
	}

	/** GET /v1/forms/{form} – the form with its fields and sub-forms. */
	public static function formGet(string $ref): array
	{
		return [200, self::formOut(self::form($ref), true), []];
	}

	private static function formOut(NuFormMeta $m, bool $full): array
	{
		$o = ['id' => $m->id, 'code' => $m->code(), 'description' => $m->description(), 'table' => $m->table(), 'primary_key' => $m->primaryKey(),
			'rights' => self::rights($m), 'records' => '/v1/forms/' . rawurlencode($m->code() !== '' ? $m->code() : $m->id) . '/records'];
		if (!$full) return $o;
		$o['fields'] = array_values(array_map(static fn($f) => self::fieldInfo($m, $f), self::fields($m)));
		$o['subforms'] = [];
		foreach (self::subforms($m) as $id => [$so, $sub]) {
			$o['subforms'][] = ['name' => $id, 'label' => self::label($so), 'form' => $sub->code() ?: $sub->id, 'table' => self::subTable($so, $sub),
				'add' => (string)$so['sob_subform_add'] !== '0', 'delete' => (string)$so['sob_subform_delete'] !== '0',
				'fields' => array_values(array_map(static fn($f) => self::fieldInfo($sub, $f), self::fields($sub)))];
		}
		return $o;
	}

	// ================================================================= fields

	/**
	 * The record fields of a form the service user sees: object id => [object, column]. Objects whose value is saved to a column of the
	 * table (input, select, textarea, calc, lookup, editor, display) – not buttons, uploads, hidden ones (access 2 / 3 / 4, field data
	 * access hide / withheld) or objects without a column. The primary key is "id".
	 * @return array<string, array{0: array, 1: string}>
	 */
	private static function fields(NuFormMeta $meta, ?string $table = null): array
	{
		static $cache = [];
		$table ??= $meta->table();
		$k = $meta->id . '|' . $table;
		if (isset($cache[$k])) return $cache[$k];
		$cols = nu_db()->columns($table);
		$out = [];
		foreach ($meta->objects as $o) {
			$f = (string)$o['sob_all_id'];
			if ($f === '' || !in_array($o['sob_all_type'], self::SAVED, true) || ($o['sob_input_type'] ?? '') === 'button' || NuRender::isUppy($o)) continue;
			if (NuRecord::hiddenFor($o) || strcasecmp($f, $meta->primaryKey()) === 0 || $f === 'id') continue;
			$c = NuRecord::column($cols, $f);
			if ($c !== null) $out[$f] = [$o, $c];
		}
		return $cache[$k] = $out;
	}

	private static function fieldInfo(NuFormMeta $meta, array $f): array
	{
		[$o, $col] = $f;
		$cols = nu_db()->columns($meta->table());
		$v = (string)$o['sob_all_validate'];
		$i = ['name' => (string)$o['sob_all_id'], 'label' => self::label($o), 'type' => (string)$o['sob_all_type']
			. (($o['sob_input_type'] ?? '') !== '' && $o['sob_all_type'] === 'input' ? ':' . $o['sob_input_type'] : ''),
			'column' => $col, 'db_type' => (string)($cols[$col]['type'] ?? ''), 'required' => $v === '1' || $v === '3', 'unique' => $v === '2' || $v === '3',
			'read_only' => !NuFieldAccess::writable($o, false) || $o['sob_all_type'] === 'display' || $o['sob_all_type'] === 'calc',
			'masked' => NuFieldAccess::masked($o)];
		if ($o['sob_all_type'] === 'select') {
			$src = (string)($o['sob_select_sql'] ?? '');
			if (NuSelectSource::isFixed($src)) { try { $i['options'] = array_map(static fn($x) => ['value' => $x[0], 'text' => $x[1]],
				NuSelectSource::options($src)); } catch (Throwable $ig) { nu_ignored($ig, 'none'); } }
			$i['multiple'] = (string)($o['sob_select_multiple'] ?? '') === '1';
		}
		if ($o['sob_all_type'] === 'lookup' && ($lf = NuLookup::form($o))) $i['lookup'] = $lf->code() ?: $lf->id;
		if (trim((string)($o['sob_input_format'] ?? '')) !== '') $i['format'] = (string)$o['sob_input_format'];
		return $i;
	}

	/** @return array<string, array{0: array, 1: NuFormMeta}> sub-form object id => [object, sub-form] */
	private static function subforms(NuFormMeta $meta): array
	{
		$out = [];
		foreach ($meta->objects as $o) {
			if ($o['sob_all_type'] !== 'subform' || NuRecord::hiddenFor($o)) continue;
			$sub = NuFormMeta::load((string)$o['sob_subform_zzzzsys_form_id'], true);
			if ($sub && $sub->primaryKey() !== '' && trim((string)$o['sob_subform_foreign_key']) !== '') $out[(string)$o['sob_all_id']] = [$o, $sub];
		}
		return $out;
	}

	private static function subTable(array $o, NuFormMeta $sub): string
	{
		return trim((string)$o['sob_subform_table']) ?: $sub->table();
	}

	private static function label(array $o): string
	{
		return trim(html_entity_decode(strip_tags((string)$o['sob_all_label']), ENT_QUOTES)) ?: (string)$o['sob_all_id'];
	}

	/** A database row as the API shows it: id + the fields (masked values masked). */
	private static function out(NuFormMeta $meta, array $row, ?string $table = null, ?array $only = null): array
	{
		$pkCol = NuRecord::column($row, $meta->primaryKey());
		$r = ['id' => $pkCol !== null ? $row[$pkCol] : null];
		foreach (self::fields($meta, $table) as $f => [$o, $col]) {
			if ($only !== null && !in_array($f, $only, true)) continue;
			$v = $row[$col] ?? null;
			$r[$f] = NuFieldAccess::masked($o) ? NuFieldAccess::shown($o, $v) : $v;
		}
		return $r;
	}

	/** ?fields=a,b (null = all) – unknown names are an error. */
	private static function only(NuFormMeta $meta): ?array
	{
		$s = trim((string)($_GET['fields'] ?? ''));
		if ($s === '') return null;
		$all = self::fields($meta);
		$want = array_values(array_filter(array_map('trim', explode(',', $s)), static fn($x) => $x !== '' && $x !== 'id'));
		$bad = array_diff($want, array_keys($all));
		if ($bad) throw new NuApiError(422, 'Unknown field(s).', array_fill_keys($bad, 'not a field of this form'));
		return $want;
	}

	/** ?include=sub1,sub2 | none | all; $default = what an empty parameter means. */
	private static function includes(NuFormMeta $meta, bool $default): array
	{
		$subs = self::subforms($meta);
		$s = trim((string)($_GET['include'] ?? ''));
		if ($s === '') return $default ? array_keys($subs) : [];
		if ($s === 'none') return [];
		if ($s === 'all' || $s === 'subforms') return array_keys($subs);
		$want = array_values(array_filter(array_map('trim', explode(',', $s))));
		$bad = array_diff($want, array_keys($subs));
		if ($bad) throw new NuApiError(422, 'Unknown sub-form(s).', array_fill_keys($bad, 'not a sub-form of this form'));
		return $want;
	}

	/** ?expand=lookup1,lookup2 | all – lookup fields become {id, code, description}. */
	private static function expands(NuFormMeta $meta): array
	{
		$s = trim((string)($_GET['expand'] ?? ''));
		if ($s === '') return [];
		$lookups = array_keys(array_filter(self::fields($meta), static fn($f) => $f[0]['sob_all_type'] === 'lookup' && !NuFieldAccess::masked($f[0])));
		if ($s === 'all') return $lookups;
		$want = array_values(array_filter(array_map('trim', explode(',', $s))));
		$bad = array_diff($want, $lookups);
		if ($bad) throw new NuApiError(422, 'Not a lookup field.', array_fill_keys($bad, 'not a lookup field of this form'));
		return $want;
	}

	/** The record as answered: fields, expanded lookups, sub-form rows. */
	private static function full(NuFormMeta $meta, array $row, array $include, array $expand, ?array $only = null): array
	{
		$rec = self::out($meta, $row, null, $only);
		$id = (string)$rec['id'];
		if ($expand) {
			$hash = NuHash::forForm($meta, $id, $row, []);
			$fields = self::fields($meta);
			foreach ($expand as $f) {
				if (!array_key_exists($f, $rec) || $rec[$f] === null || $rec[$f] === '') continue;
				$x = NuLookup::byId($fields[$f][0], (string)$rec[$f], $hash);
				$rec[$f] = ['id' => $rec[$f], 'code' => $x['code'] ?? null, 'description' => $x['description'] ?? null];
			}
		}
		$subs = self::subforms($meta);
		foreach ($include as $sid) {
			[$so, $sub] = $subs[$sid];
			$rec[$sid] = array_map(static fn($r) => self::out($sub, $r, self::subTable($so, $sub)), $id === '' ? [] : NuRecord::subformRows($so, $id));
		}
		return $rec;
	}

	private static function etagOf(NuFormMeta $meta, array $row): string
	{
		return NuApi::etag(self::out($meta, $row));
	}

	/** The stored row, if the service user may see it (the form's list when "Records only through the form's list" is on). */
	private static function row(NuFormMeta $meta, string $id): array
	{
		$id = rawurldecode($id);
		$row = $id !== '' ? NuRecord::load($meta, $id) : null;
		if (!$row || !NuRecord::visible($meta, $id)) throw new NuApiError(404, 'Record not found.');
		return $row;
	}

	// ================================================================= read

	/** GET /v1/forms/{form}/records/{id} – Before Edit runs as in the browser; an error there refuses the record. */
	public static function recordGet(string $ref, string $id): array
	{
		$meta = self::form($ref);
		self::need($meta, 'read');
		$row = self::row($meta, $id);
		$hash = NuHash::forForm($meta, (string)rawurldecode($id), $row, []);
		NuPhp::reset();
		if (!NuProcedure::hook('nu_before_edit', $hash) || !NuPhp::run($meta->php('BE'), $hash, 'Before Edit')) self::phpFail(403);
		return [200, self::full($meta, $row, self::includes($meta, true), self::expands($meta), self::only($meta)), ['ETag' => self::etagOf($meta, $row)]];
	}

	/**
	 * GET /v1/forms/{form}/records – the rows the form's list shows (Before Browse and the browse SQL with the service user's hash
	 * variables; without browse SQL the table). q = the list's search; field=value / field[op]=value filters (eq ne gt gte lt lte like
	 * starts in null); sort=field,-field; limit (max 200) / offset; fields, include, expand.
	 */
	public static function recordsList(string $ref): array
	{
		$meta = self::form($ref);
		self::need($meta, 'list');
		$db = nu_db();
		[$limit, $offset] = NuApi::page();
		$fields = self::fields($meta);
		$table = NuDB::ident($meta->table());
		$pk = NuDB::ident($meta->primaryKey());
		$where = [];
		$params = [];
		// the list: Before Browse + browse SQL (+ search)
		$q = trim((string)($_GET['q'] ?? ''));
		if (trim((string)$meta->form['sfo_browse_sql']) !== '') {
			$hash = NuHash::forForm($meta, '', [], []);
			NuPhp::reset();
			if (!NuProcedure::hook('nu_before_save', $hash) || !NuPhp::run($meta->php('BB'), $hash, 'Before Browse')) self::phpFail(422);
			$hash = NuPhp::$hash + $hash;
			try {
				$f = NuBrowse::filtered($meta, $hash, ['search' => $q]);
			} catch (Throwable $e) {
				if (class_exists('NuDebugLog')) { try { NuDebugLog::write('API list of ' . $meta->code() . ":\n" . $e->getMessage()); } catch (Throwable $ig) { nu_ignored($ig); } }
				throw new NuApiError(500, "The form's list query failed (see Debug results).");
			}
			$where[] = "t.$pk IN (SELECT nuT.nu_pk FROM (" . $f['inner'] . ') nuT' . ($f['where'] ? ' WHERE ' . implode(' AND ', $f['where']) : '') . ')';
			array_push($params, ...$f['params']);
		} elseif ($q !== '') {
			$part = [];
			foreach (NuBrowse::terms($q)['in'] as $term) {
				$or = [];
				foreach ($fields as [$o, $col]) {
					if (NuFieldAccess::masked($o)) continue;
					$or[] = NuBrowse::castText('t.' . NuDB::ident($col)) . " LIKE ? ESCAPE '!'";
					$params[] = NuDB::like($term);
				}
				if ($or) $part[] = '(' . implode(' OR ', $or) . ')';
			}
			if ($part) $where[] = implode(' AND ', $part);
		}
		// filters on the fields
		$colOf = static function (string $f) use ($fields, $meta): string {
			if ($f === 'id') return 't.' . NuDB::ident($meta->primaryKey());
			if (!isset($fields[$f])) throw new NuApiError(422, 'Unknown parameter.', [$f => 'not a field of this form']);
			if (NuFieldAccess::masked($fields[$f][0])) throw new NuApiError(422, 'Masked field.', [$f => 'a masked field cannot be filtered or sorted']);
			return 't.' . NuDB::ident($fields[$f][1]);
		};
		foreach ($_GET as $k => $v) {
			$k = (string)$k;
			if (in_array($k, self::RESERVED, true)) continue;
			$c = $colOf($k);
			foreach (is_array($v) ? $v : ['eq' => $v] as $op => $val) {
				$op = (string)$op;
				if (is_array($val)) throw new NuApiError(422, 'Invalid filter.', [$k => 'one value per operator']);
				$val = (string)$val;
				if (isset(self::OPS[$op])) { $where[] = "$c " . self::OPS[$op] . ' ?'; $params[] = $val; }
				elseif ($op === 'like') { $where[] = NuBrowse::castText($c) . " LIKE ? ESCAPE '!'"; $params[] = NuDB::like($val); }
				elseif ($op === 'starts') { $where[] = NuBrowse::castText($c) . " LIKE ? ESCAPE '!'"; $params[] = NuDB::likeRaw($val) . '%'; }
				elseif ($op === 'in') {
					$list = array_values(array_filter(array_map('trim', explode(',', $val)), static fn($x) => $x !== ''));
					if (!$list || count($list) > 200) throw new NuApiError(422, 'Invalid filter.', [$k => '1 to 200 comma-separated values']);
					$where[] = "$c IN (" . implode(', ', array_fill(0, count($list), '?')) . ')';
					array_push($params, ...$list);
				} elseif ($op === 'null') { $where[] = in_array(strtolower($val), ['1', 'true', 'yes'], true) ? "$c IS NULL" : "$c IS NOT NULL"; }
				else throw new NuApiError(422, 'Invalid filter.', [$k => "unknown operator '$op' (eq ne gt gte lt lte like starts in null)"]);
			}
		}
		// sort
		$order = [];
		foreach (array_filter(array_map('trim', explode(',', (string)($_GET['sort'] ?? '')))) as $s) {
			$desc = str_starts_with($s, '-');
			$order[] = $colOf(ltrim($s, '-+')) . ($desc ? ' DESC' : ' ASC');
		}
		$order[] = "t.$pk ASC";
		$sqlWhere = $where ? ' WHERE ' . implode(' AND ', $where) : '';
		$only = self::only($meta);
		$include = self::includes($meta, false);
		$expand = self::expands($meta);
		$total = (int)$db->value("SELECT COUNT(*) FROM $table t$sqlWhere", $params);
		$rows = $db->page("SELECT t.* FROM $table t$sqlWhere", $params, NuDB::uniqueOrder(implode(', ', $order)), $limit, $offset);
		NuBrowse::dropTempTable();
		$data = array_map(static fn($r) => self::full($meta, $r, $include, $expand, $only), $rows);
		return [200, ['data' => $data, 'meta' => ['total' => $total, 'limit' => $limit, 'offset' => $offset]], []];
	}

	// ================================================================= write

	/** POST /v1/forms/{form}/records – 201 with the new record. ?accept_warnings=1 saves despite warnings of validation rules. */
	public static function recordCreate(string $ref): array
	{
		$meta = self::form($ref);
		self::need($meta, 'create');
		$in = NuApi::body();
		[$values, $edited, $subs] = self::input($meta, $in, null);
		$res = self::save($meta, ['record_id' => '-1', 'values' => $values, 'edited' => $edited, 'subforms' => $subs, 'untouched' => []]);
		$id = (string)$res['record_id'];
		$row = NuRecord::load($meta, $id) ?? [];
		$path = '/v1/forms/' . rawurlencode($meta->code() !== '' ? $meta->code() : $meta->id) . '/records/' . rawurlencode($id);
		return [201, self::full($meta, $row, self::includes($meta, true), [], null), ['Location' => $path, 'ETag' => self::etagOf($meta, $row)]];
	}

	/** PATCH /v1/forms/{form}/records/{id} – only the fields sent; sub-form rows: with id = change, without = new, "_delete": true. */
	public static function recordPatch(string $ref, string $id): array
	{
		$meta = self::form($ref);
		self::need($meta, 'update');
		$row = self::row($meta, $id);
		NuApi::ifMatchTag(self::etagOf($meta, $row));
		$in = NuApi::body();
		[$values, $edited, $subs] = self::input($meta, $in, $row);
		$rid = (string)rawurldecode($id);
		if ($edited || $subs) self::save($meta, ['record_id' => $rid, 'values' => $values, 'edited' => $edited, 'subforms' => $subs]);
		$row = NuRecord::load($meta, $rid) ?? [];
		return [200, self::full($meta, $row, self::includes($meta, true), [], null), ['ETag' => self::etagOf($meta, $row)]];
	}

	/** DELETE /v1/forms/{form}/records/{id} – 204 (the recycle bin keeps a copy when the form has one). */
	public static function recordDelete(string $ref, string $id): array
	{
		$meta = self::form($ref);
		self::need($meta, 'delete');
		$row = self::row($meta, $id);
		NuApi::ifMatchTag(self::etagOf($meta, $row));
		$res = NuRecord::delete($meta, (string)rawurldecode($id));
		if (empty($res['ok'])) self::refused($res);
		return [204, null, []];
	}

	/**
	 * The body → NuRecord::save's values / edited / sub-forms. Unknown names and fields the service user cannot write are errors.
	 * PATCH: the stored values of the other fields go along (validation sees the whole record), only the sent ones are written.
	 * @return array{0: array, 1: list<string>, 2: array}
	 */
	private static function input(NuFormMeta $meta, array $in, ?array $row): array
	{
		$isNew = $row === null;
		$fields = self::fields($meta);
		$subs = self::subforms($meta);
		$errors = [];
		$values = [];
		if (!$isNew) foreach ($fields as $f => [, $col]) $values[$f] = $row[$col] ?? null;
		$edited = [];
		$posted = [];
		foreach ($in as $k => $v) {
			$k = (string)$k;
			if ($k === 'id') { if (!$isNew && (string)$v === (string)($row[NuRecord::column($row, $meta->primaryKey()) ?? ''] ?? '')) continue; $errors[$k] = 'the id cannot be set or changed'; continue; }
			if (isset($subs[$k])) {
				if (!is_array($v) || !array_is_list($v)) { $errors[$k] = 'a list of rows'; continue; }
				$posted[$k] = ['rows' => self::subRows($k, $subs[$k], $v, $isNew ? '' : (string)$row[NuRecord::column($row, $meta->primaryKey())], $errors)];
				continue;
			}
			if (!isset($fields[$k])) { $errors[$k] = 'not a field of this form'; continue; }
			$o = $fields[$k][0];
			if (!NuFieldAccess::writable($o, $isNew) || in_array($o['sob_all_type'], ['calc', 'display'], true)) { $errors[$k] = 'read only'; continue; }
			if (is_array($v) && !($o['sob_all_type'] === 'select' && array_is_list($v))) { $errors[$k] = 'a single value'; continue; }
			if (is_bool($v)) $v = $v ? '1' : '0';
			$values[$k] = is_array($v) ? array_map('strval', $v) : ($v === null ? null : (string)$v);
			$edited[] = $k;
		}
		if ($errors) throw new NuApiError(422, 'Invalid fields.', $errors);
		return [$values, $edited, $posted];
	}

	/** Rows of one sub-form in the body → NuRecord's rows {pk, values, edited, deleted}. */
	private static function subRows(string $sid, array $sf, array $rows, string $parentId, array &$errors): array
	{
		[$so, $sub] = $sf;
		$fields = self::fields($sub, self::subTable($so, $sub));
		$stored = [];
		if ($parentId !== '') foreach (NuRecord::subformRows($so, $parentId) as $r) {
			$c = NuRecord::column($r, $sub->primaryKey());
			if ($c !== null) $stored[(string)$r[$c]] = $r;
		}
		$out = [];
		foreach ($rows as $i => $r) {
			$at = $sid . '[' . $i . ']';
			if (!is_array($r) || array_is_list($r)) { $errors[$at] = 'a row object'; continue; }
			$rid = isset($r['id']) ? (string)$r['id'] : '';
			if ($rid !== '' && !isset($stored[$rid])) { $errors[$at . '.id'] = 'not a row of this record'; continue; }
			if (!empty($r['_delete'])) {
				if ($rid === '') { $errors[$at] = '_delete needs the id of the row'; continue; }
				if ((string)$so['sob_subform_delete'] === '0') { $errors[$at] = 'rows of this sub-form cannot be deleted'; continue; }
				$out[] = ['pk' => $rid, 'values' => [], 'edited' => [], 'deleted' => true];
				continue;
			}
			if ($rid === '' && (string)$so['sob_subform_add'] === '0') { $errors[$at] = 'rows cannot be added to this sub-form'; continue; }
			$vals = [];
			if ($rid !== '') foreach ($fields as $f => [, $col]) $vals[$f] = $stored[$rid][$col] ?? null;
			$ed = [];
			foreach ($r as $k => $v) {
				$k = (string)$k;
				if ($k === 'id' || $k === '_delete') continue;
				if (!isset($fields[$k])) { $errors[$at . '.' . $k] = 'not a field of this sub-form'; continue; }
				$o = $fields[$k][0];
				if (!NuFieldAccess::writable($o, $rid === '') || in_array($o['sob_all_type'], ['calc', 'display'], true)) { $errors[$at . '.' . $k] = 'read only'; continue; }
				if (is_array($v)) { $errors[$at . '.' . $k] = 'a single value'; continue; }
				$vals[$k] = is_bool($v) ? ($v ? '1' : '0') : ($v === null ? null : (string)$v);
				$ed[] = $k;
			}
			$out[] = ['pk' => $rid, 'values' => $vals, 'edited' => $ed, 'deleted' => false];
		}
		return $out;
	}

	/** NuRecord::save; refused → 422 (warnings of validation rules: ?accept_warnings=1 saves anyway). */
	private static function save(NuFormMeta $meta, array $p): array
	{
		$res = NuRecord::save($meta, $p);
		if (empty($res['ok']) && !empty($res['rules_warn']) && empty($res['errors']) && in_array((string)($_GET['accept_warnings'] ?? ''), ['1', 'true'], true)) {
			$res = NuRecord::save($meta, $p + ['rules_ok' => array_column($res['rules_warn'], 'id')]);
		}
		if (empty($res['ok'])) self::refused($res);
		return $res;
	}

	/** @return never */
	private static function refused(array $res): void
	{
		$errors = [];
		foreach (array_merge((array)($res['errors'] ?? []), (array)($res['rules_warn'] ?? [])) as $e) {
			$f = (string)($e['field'] ?? '');
			$m = (string)($e['message'] ?? '');
			$errors[$f] = isset($errors[$f]) ? $errors[$f] . ' ' . $m : $m;
		}
		if (!empty($res['conflict'])) throw new NuApiError(409, 'The record was changed meanwhile.');
		$warnOnly = empty($res['errors']) && !empty($res['rules_warn']);
		throw new NuApiError(422, $warnOnly ? 'Warnings of the validation rules – send ?accept_warnings=1 to save anyway.' : 'The record was not saved.', $errors ?: ['' => 'refused']);
	}

	/** @return never */
	private static function phpFail(int $status): void
	{
		$errors = [];
		foreach (NuPhp::$errors as $e) $errors[(string)($e['field'] ?? '')] = trim(($errors[(string)($e['field'] ?? '')] ?? '') . ' ' . strip_tags((string)($e['message'] ?? '')));
		if (!$errors && NuPhp::$error !== '') $errors[''] = strip_tags(NuPhp::$error);
		throw new NuApiError($status, "The form's PHP code refused the request.", $errors);
	}

	// ================================================================= OpenAPI

	/** GET /v1/openapi.json – OpenAPI 3.1: the routes of the client's scopes and the forms it may use, with their fields. */
	public static function openapi(array $routes, array $scopes): array
	{
		$paths = [];
		$schemas = ['Problem' => ['type' => 'object', 'properties' => ['type' => ['type' => 'string'], 'title' => ['type' => 'string'], 'status' => ['type' => 'integer'],
			'errors' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['field' => ['type' => 'string'], 'message' => ['type' => 'string']]]]]]];
		$err = ['description' => 'Error (RFC 9457)', 'content' => ['application/problem+json' => ['schema' => ['$ref' => '#/components/schemas/Problem']]]];
		foreach ($routes as [$m, $re, , $scope]) {
			if ($scope !== null && !in_array($scope, $scopes, true)) continue;
			if (str_contains($re, '/v1/forms')) continue;                                   // below, per form
			$path = preg_replace(['/^#\^/', '/\$#$/', '/\(\[a-f0-9\]\{16\}\)/', '/\(\[\\\\w\\\\-\]\+\)/', '/\\\\\./'], ['', '', '{job}', '{id}', '.'], $re);
			$op = ['summary' => self::summaryOf($m, (string)$path), 'tags' => [explode('/', trim((string)$path, '/'))[1] ?? 'api'], 'responses' => ['200' => ['description' => 'OK'], 'default' => $err]];
			if ($scope !== null) $op['security'] = [['bearer' => []]];
			if (preg_match_all('/\{(\w+)\}/', (string)$path, $pm)) foreach ($pm[1] as $n) $op['parameters'][] = ['name' => $n, 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']];
			if (in_array($m, ['POST', 'PATCH', 'PUT'], true)) $op['requestBody'] = ['content' => ['application/json' => ['schema' => ['type' => 'object']]]];
			$paths[(string)$path][strtolower($m)] = $op;
		}
		if (array_intersect(['records:read', 'records:write'], $scopes)) {
			$read = in_array('records:read', $scopes, true);
			$write = in_array('records:write', $scopes, true);
			if ($read) $paths['/v1/forms']['get'] = ['summary' => 'Forms the client may use', 'tags' => ['forms'], 'responses' => ['200' => ['description' => 'OK'], 'default' => $err]];
			foreach (nu_db()->all("SELECT zzzzsys_form_id FROM zzzzsys_form WHERE COALESCE(sfo_table, '') <> '' ORDER BY sfo_code") as $r) {
				$meta = NuFormMeta::load((string)$r['zzzzsys_form_id'], true);
				if (!$meta || !self::eligible($meta) || !NuAcl::can($meta->id, 'view') || NuFormStatus::norm($meta->form['sfo_status'] ?? '') === '0') continue;
				$rt = self::rights($meta);
				$name = preg_replace('/\W+/', '_', $meta->code() ?: $meta->id);
				[$recSchema, $inSchema] = self::schemas($meta);
				$schemas[$name] = $recSchema;
				$schemas[$name . '_input'] = $inSchema;
				$ref = ['$ref' => '#/components/schemas/' . $name];
				$refIn = ['$ref' => '#/components/schemas/' . $name . '_input'];
				$base = '/v1/forms/' . rawurlencode($meta->code() ?: $meta->id) . '/records';
				$tag = [$meta->code() ?: $meta->id];
				$idp = [['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']]];
				$etag = ['name' => 'If-Match', 'in' => 'header', 'required' => true, 'schema' => ['type' => 'string'], 'description' => 'ETag of the record'];
				$one = ['200' => ['description' => 'The record', 'headers' => ['ETag' => ['schema' => ['type' => 'string']]], 'content' => ['application/json' => ['schema' => $ref]]], 'default' => $err];
				if ($read && $rt['list']) {
					$params = [];
					foreach (['q' => 'Search as in the list', 'sort' => 'field or -field, comma-separated', 'limit' => 'max 200 (default 50)', 'offset' => '',
						'fields' => 'comma-separated', 'include' => 'sub-forms: names, all or none', 'expand' => 'lookup fields: names or all'] as $n => $d) {
						$params[] = ['name' => $n, 'in' => 'query', 'schema' => ['type' => in_array($n, ['limit', 'offset'], true) ? 'integer' : 'string'], 'description' => $d];
					}
					$paths[$base]['get'] = ['summary' => 'List – ' . $meta->description(), 'tags' => $tag, 'parameters' => $params,
						'description' => 'Filters: <field>=value or <field>[op]=value with op eq, ne, gt, gte, lt, lte, like, starts, in (comma-separated), null (true / false).',
						'responses' => ['200' => ['description' => 'Rows', 'content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => [
							'data' => ['type' => 'array', 'items' => $ref], 'meta' => ['type' => 'object', 'properties' => ['total' => ['type' => 'integer'], 'limit' => ['type' => 'integer'], 'offset' => ['type' => 'integer']]]]]]]],
								'default' => $err]];
				}
				if ($write && $rt['create']) $paths[$base]['post'] = ['summary' => 'Create – ' . $meta->description(), 'tags' => $tag,
					'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => $refIn]]],
					'responses' => ['201' => ['description' => 'Created', 'content' => ['application/json' => ['schema' => $ref]]], 'default' => $err]];
				if ($read && $rt['read']) $paths[$base . '/{id}']['get'] = ['summary' => 'Read – ' . $meta->description(), 'tags' => $tag, 'parameters' => $idp, 'responses' => $one];
				if ($write && $rt['update']) $paths[$base . '/{id}']['patch'] = ['summary' => 'Change – ' . $meta->description(), 'tags' => $tag, 'parameters' => [...$idp, $etag],
					'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => $refIn]]], 'responses' => $one];
				if ($write && $rt['delete']) $paths[$base . '/{id}']['delete'] = ['summary' => 'Delete – ' . $meta->description(), 'tags' => $tag,
					'parameters' => [...$idp, $etag], 'responses' => ['204' => ['description' => 'Deleted'], 'default' => $err]];
			}
		}
		$base = nu_base_url() . '/api.php';
		return [200, ['openapi' => '3.1.0', 'info' => ['title' => nu_app_title() . ' API', 'version' => NU_VERSION, 'description' => 'nuBuilder Next REST API – generated for this client.'],
			'servers' => [['url' => $base]],
			'components' => ['securitySchemes' => [
				'bearer' => ['type' => 'http', 'scheme' => 'bearer'],
				'oauth2' => ['type' => 'oauth2', 'flows' => ['clientCredentials' => ['tokenUrl' => $base . '/v1/token', 'scopes' => array_map(static fn($k) => t('api_scope_' . str_replace(':', '_', $k)), array_combine(array_keys(NuApiClient::scopes()), array_keys(NuApiClient::scopes())))]]]],
				'schemas' => $schemas],
			'security' => [['bearer' => []], ['oauth2' => []]], 'paths' => $paths ?: (object)[]], []];
	}

	private static function summaryOf(string $method, string $path): string
	{
		return $method . ' ' . $path;
	}

	/** JSON schemas of a form's record (answer) and input. */
	private static function schemas(NuFormMeta $meta): array
	{
		$props = ['id' => ['type' => ['string', 'integer'], 'readOnly' => true]];
		$in = [];
		$req = [];
		foreach (self::fields($meta) as $f => $x) {
			$i = self::fieldInfo($meta, $x);
			$t = self::jsonType($i['db_type']);
			$p = ['type' => [$t, 'null'], 'title' => $i['label']];
			if (!empty($i['options'])) $p['enum'] = [...array_column($i['options'], 'value'), null];
			if ($i['read_only']) $p['readOnly'] = true;
			$props[$f] = $p;
			if (!$i['read_only']) { $in[$f] = $p; if ($i['required']) $req[] = $f; }
		}
		foreach (self::subforms($meta) as $sid => [$so, $sub]) {
			$rp = ['id' => ['type' => ['string', 'integer']]];
			foreach (self::fields($sub, self::subTable($so, $sub)) as $f => $x) $rp[$f] = ['type' => [self::jsonType((string)(nu_db()->columns(self::subTable($so, $sub))[$x[1]]['type'] ?? '')), 'null'],
				'title' => self::label($x[0])];
			$props[$sid] = ['type' => 'array', 'title' => self::label($so), 'items' => ['type' => 'object', 'properties' => $rp]];
			$in[$sid] = ['type' => 'array', 'items' => ['type' => 'object', 'properties' => $rp + ['_delete' => ['type' => 'boolean']]]];
		}
		$input = ['type' => 'object', 'additionalProperties' => false, 'properties' => $in ?: (object)[]];
		if ($req) $input['description'] = 'Required for a new record: ' . implode(', ', $req);
		return [['type' => 'object', 'title' => $meta->description(), 'properties' => $props], $input];
	}

	private static function jsonType(string $db): string
	{
		$db = strtolower($db);
		if (preg_match('/^(tinyint|smallint|mediumint|int|integer|bigint|bit)$/', $db)) return 'integer';
		if (preg_match('/^(decimal|numeric|float|double|real|money|smallmoney)$/', $db)) return 'number';
		return 'string';
	}
}
