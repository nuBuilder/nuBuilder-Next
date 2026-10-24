<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * nuBuilder PHP API, available inside form hooks, lookup AB code and procedures.
 * Only loaded when such code runs. Function names are case-insensitive in PHP,
 * so nuAddJavascript() and nuAddJavaScript() are the same function.
 */

if (!function_exists('nuRunQuery')) {

	/** @return NuResult|string  result, or the insert id when $isInsert is true */
	function nuRunQuery(string $sql, array $params = [], bool $isInsert = false): NuResult|string
	{
		$db = nu_db();
		$st = $db->run($sql, $params);
		if ($isInsert) return (string)$db->lastInsertId();
		return new NuResult($st);
	}

	function db_fetch_row($r): array|false
	{
		if (!$r instanceof NuResult) return false;
		return $r->next() ?? false;
	}

	function db_fetch_array($r): array|false
	{
		if (!$r instanceof NuResult) return false;
		$row = $r->next();
		return $row === null ? false : $r->assoc($row) + $row;
	}

	function db_fetch_object($r): object|false
	{
		if (!$r instanceof NuResult) return false;
		$row = $r->next();
		return $row === null ? false : (object)$r->assoc($row);
	}

	function db_num_rows($r): int
	{
		return $r instanceof NuResult ? count($r->rows) : 0;
	}

	function db_affected_rows($r): int
	{
		return $r instanceof NuResult ? $r->affected : 0;
	}

	function db_fetch_all_column($r, int $col = 0): array
	{
		return $r instanceof NuResult ? array_column($r->rows, $col) : [];
	}

	function db_columns($r): array
	{
		return $r instanceof NuResult ? $r->columns : [];
	}

	function nuHash(): array
	{
		return NuPhp::$hash;
	}

	function nuDisplayError(string $message, string $field = ''): void
	{
		NuPhp::$errors[] = ['message' => $message, 'field' => $field];
	}

	/** nuBuilder: in After Save – the form opens this record instead of the saved one (-1 = a new, empty record; 0.8.443). */
	function nuReturnNewRecord(mixed $i = -1): void
	{
		NuPhp::$openRecord = (string)$i;
		NuPhp::$hash['RECORD_ID'] = (string)$i;
	}

	function nuAddJavascript(string $js): void
	{
		NuPhp::$js[] = $js;
	}

	function nuSetFormValue(string $field, mixed $value): void
	{
		NuPhp::$formValues[$field] = $value;
	}

	/** nuBuilder: a value of nuFORMdata (#nuFORMdata#, Before / After Save) – $formId '' = the edit form, else the sub-form object id. */
	function nuGetNuDataValue(mixed $nudata, string $formId, string $field): mixed
	{
		if ($formId === '') $formId = 'nuBuilder4EditForm';
		foreach ((array)$nudata as $sf) {
			if (!is_object($sf) || ($sf->id ?? null) !== $formId) continue;
			$key = array_search($field, (array)($sf->fields ?? []), true);
			if ($key !== false) return $sf->rows[0][$key] ?? null;
		}
		return null;
	}

	/** nuBuilder: changes a value of nuFORMdata and marks it edited – in Before Save the new value is saved. */
	function nuSetNuDataValue(mixed &$nudata, string $formId, string $field, mixed $value): bool
	{
		if ($formId === '') $formId = 'nuBuilder4EditForm';
		foreach ((array)$nudata as $i => $sf) {
			if (!is_object($sf) || ($sf->id ?? null) !== $formId) continue;
			$key = array_search($field, (array)($sf->fields ?? []), true);
			if ($key === false) continue;
			$rows = (array)$sf->rows;
			$rows[0][$key] = $value;
			$sf->rows = $rows;
			$ed = (array)($sf->edited ?? []);
			$ed[0][$key] = 1;
			$sf->edited = $ed;
			return true;
		}
		return false;
	}

	function nuLookupRecord(): object
	{
		return NuPhp::$lookupRow ?? new stdClass();
	}

	/** Form property (this form and the forms opened from it) or, with $global, a hash cookie until logout. */
	function nuSetProperty(string $name, mixed $value, bool $global = false): void
	{
		if (!preg_match('/^\w{1,100}$/', $name)) return;
		if (NuHash::isSystem($name)) {                     // #USER_ID#, #RECORD_ID# … cannot be replaced
			NuPhp::$debug[] = "nuSetProperty('$name'): system variable – ignored";
			return;
		}
		if ($global) {
			NuHashCookies::set($name, $value);
			NuPhp::$globals[$name] = $value;
		} else {
			NuPhp::$props[$name] = $value;
		}
		unset(NuPhp::$deleted[$name]);
		NuPhp::$hash[$name] = $value;
	}

	function nuGetProperty(string $name): mixed
	{
		return NuPhp::$hash[$name] ?? NuHashCookies::get($name);
	}

	/** nuBuilder: true (and $value) when the property exists (and is not empty with $allowEmpty = false). */
	function nuHasProperty(string $name, mixed &$value = null, bool $allowEmpty = true): bool
	{
		$value = nuGetProperty($name);
		if ($value === null || $value === false) return false;
		return $allowEmpty || trim((string)$value) !== '';
	}

	/** All global hash cookies (nuSetProperty(…, true)). */
	function nuGetGlobalProperties(): array
	{
		return NuHashCookies::all();
	}

	/** Removes a form property and a global hash cookie of that name. */
	function nuDeleteProperty(string $name): void
	{
		if (NuHash::isSystem($name)) return;
		unset(NuPhp::$hash[$name], NuPhp::$props[$name], NuPhp::$globals[$name]);
		NuPhp::$deleted[$name] = true;
		if (NuHashCookies::get($name) !== null) NuHashCookies::delete($name);
	}

	function nuID(): string
	{
		return nu_id();
	}

	function nuGlobalAccess(): bool
	{
		return (bool)NuAuth::user()['globeadmin'];
	}

	// ------------------------------------------------------------- per-user JSON data (zzzzsys_user.sus_json), as nuBuilder

	/** nuBuilder: the globeadmin is stored under its user name (a zzzzsys_user row is created for it when needed). */
	function nu_user_json_id(string $userId): string
	{
		if ($userId !== '') return $userId;
		$u = NuAuth::user();
		if (!$u['globeadmin']) return (string)$u['id'];
		$name = (string)nu_cfg('nuConfigDBGlobeadminUsername');
		$db = nu_db();
		if ($name !== '' && NuUserJson::available() && !$db->value('SELECT COUNT(*) FROM zzzzsys_user WHERE zzzzsys_user_id = ?', [$name])) {
			try { $db->insert('zzzzsys_user', ['zzzzsys_user_id' => $name]); } catch (Throwable) { /* columns without default – nothing stored */ }
		}
		return $name;
	}

	function nuSetUserJSONData($key, $value, $userId = ''): void
	{
		NuUserJson::set(nu_user_json_id((string)$userId), (string)$key, $value);
	}

	function nuGetUserJSONData($key, $userId = ''): mixed
	{
		return NuUserJson::get(nu_user_json_id((string)$userId), (string)$key);
	}

	/**
	 * nuBuilder Next (0.8.339): new single-use login token for a user (nuBuilder's sus_json LOGIN_TOKEN) and the link
	 * index.php?u=<login name>&p=<token>[&f=&r=]. $minutes 0 = no expiry; '' on error (e.g. API service user, no sus_json).
	 */
	function nuCreateLoginToken($userId, $minutes = 0, $formId = '', $recordId = ''): string
	{
		try { return NuLoginToken::create((string)$userId, (int)$minutes, (string)$formId, (string)$recordId, 'procedure'); }
		catch (RuntimeException) { return ''; }
	}

	/**
	 * nuBuilder Next (0.8.357): notification for the bell. $args: title (required), body, level (info|success|warning|danger), link,
	 * all, users (ids or login names), departments, teams, positions, access_levels (ids), mode (live|fixed), publish_at, expires_at,
	 * pinned, must_confirm, email. Published at once (or at publish_at). Returns the id, '' when not valid.
	 */
	function nuNextNotify(array $args): string
	{
		$aud = [];
		foreach (['all', 'users', 'departments', 'teams', 'positions', 'access', 'access_levels'] as $k) if (array_key_exists($k, $args)) $aud[$k] = $args[$k];
		$u = NuAuth::currentRaw();
		[$id, $errors] = NuNotify::save('', ['audience' => $aud] + $args, true, ['id' => (string)($u['id'] ?? ''), 'login' => (string)($u['login'] ?? 'procedure')], false);
		return $errors ? '' : $id;
	}

	function nuAccessLevelCode(): string
	{
		return (string)(NuHash::base()['ACCESS_LEVEL_CODE'] ?? '');
	}

	/** nuBuilder: language of the user ('' = English), set_language for the globeadmin. */
	function nuUserLanguage(): string
	{
		return NuTranslate::language();
	}

	/** nuBuilder: text in the user's language (zzzzsys_translate); extra arguments fill %s / %s1, %s2 … */
	function nuTranslate($s, ...$args): string
	{
		return NuTranslate::format((string)$s, $args);
	}

	function nuGetHttpOrigin(): string
	{
		$https = nu_https();
		$dir   = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
		return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $dir;
	}

	/** PHP code of another procedure, to be used as eval(nuProcedure('code')). */
	function nuProcedure(string $code): string
	{
		$php = nu_db()->value('SELECT sph_php FROM zzzzsys_php WHERE sph_code = ?', [$code]);
		return NuHash::replace((string)$php, NuPhp::$hash, NuHash::PHP);
	}

	/** Posted subform, shaped like nuBuilder's object: rows, fields, deleted, edited. */
	function nuSubformObject(string $id): object
	{
		return (object)(NuPhp::$subforms[$id] ?? ['id' => $id, 'fields' => [], 'rows' => [], 'deleted' => [], 'edited' => []]);
	}

	function nuCreateTableFromSelect(string $table, string $sql): void
	{
		$db = nu_db();
		if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) throw new RuntimeException("Invalid table name $table");
		if ($db->type === 'sqlsrv') {
			$db->run("IF OBJECT_ID('$table') IS NOT NULL DROP TABLE $table");
			$db->run("SELECT * INTO $table FROM ($sql) nuSrc");
		} else {
			$db->run("DROP TABLE IF EXISTS $table");
			$db->run("CREATE TABLE $table" . ($db->type === 'mysql' ? ' ENGINE=InnoDB' : '') . " AS $sql");
		}
	}

	function nuEncodeQueryRowResults(string $sql, array $params = [], array $prepend = []): string
	{
		$r = nu_db()->all($sql, $params);
		$rows = array_merge($prepend, array_map(static fn($row) => count($row) === 1 ? array_values($row)[0] : array_values($row), $r));
		return base64_encode(json_encode($rows, JSON_UNESCAPED_UNICODE));
	}

	function nuSetValidationErrorTitle(string $title): void
	{
	}

	function nuHasErrors(): bool
	{
		return (bool)NuPhp::$errors;
	}

	function nuObjectsOnForm(string $formId): array
	{
		return array_keys(NuFormMeta::load($formId)?->byId ?? []);
	}

	// ------------------------------------------------------------- context (used by nuSendEmailEx)

	function nuFormId(): string
	{
		return (string)(NuPhp::$hash['FORM_ID'] ?? '');
	}

	function nuRecordId(): string
	{
		return (string)(NuPhp::$hash['RECORD_ID'] ?? '');
	}

	function nuUserId(): string
	{
		return (string)(NuAuth::currentRaw()['id'] ?? '');
	}

	/** nuBuilder: active permission items (pme_ident) of a user, default the current user. */
	function nuGetUserPermissions($userId = null): array
	{
		return NuHash::permissions($userId === null ? (string)(NuAuth::user()['id'] ?? '') : (string)$userId);
	}

	/** Custom user field (0.8.454): the value of $code for the signed-in user or $userId; null when there is no such field. */
	function nuNextUserField(string $code, ?string $userId = null): ?string
	{
		return NuUserFields::get($code, $userId);
	}

	/** Custom user field (0.8.454): sets $code of a user ('' = empty). Checked like in the user form; logged. @return string '' or the error */
	function nuNextSetUserField(string $userId, string $code, string $value): string
	{
		$code = strtolower($code);
		$f = NuUserFields::all()[$code] ?? null;
		if (!$f) return 'unknown field';
		if (!nu_db()->value('SELECT COUNT(*) FROM zzzzsys_user WHERE zzzzsys_user_id = ?', [$userId])) return 'unknown user';
		[$vals, $err] = NuUserFields::validate([$code => $value], $userId, 'api');
		if ($err) return (string)reset($err);
		if (!isset($vals[$code])) return 'not allowed';
		$diff = NuUserFields::save($userId, $vals);
		if ($diff) NuLog::write('user_updated', 'zzzzsys_user', $userId, ['fields' => $diff, 'via' => 'php']);
		return '';
	}

	/** nuBuilder: true when the user has the permission item – or the current user is the globeadmin. */
	function nuUserHasPermission($item, $userId = null): bool
	{
		return in_array((string)$item, nuGetUserPermissions($userId), true) || nuGlobalAccess();
	}

	function nuFormTable(): string
	{
		return (string)(NuMailer::context()['table_name'] ?? '');
	}

	function nuIsValidEmail($email): bool
	{
		return NuMailer::validAddress(trim((string)$email));
	}

	// ------------------------------------------------------------- e-mail templates (zzzzsys_email_template)

	function nuGetEmailTemplateData($code, $language = '', $group = ''): array|false
	{
		$db = nu_db();
		if (!$db->tableExists('zzzzsys_email_template')) return false;
		$rows = $db->page("SELECT * FROM zzzzsys_email_template WHERE emt_code = ? AND COALESCE(emt_language,'') = ? AND COALESCE(emt_group,'') = ?",
			[(string)$code, (string)$language, (string)$group], 'zzzzsys_email_template_id', 1, 0);
		if (!$rows) return false;
		$r = $rows[0];
		return [
			'description' => $r['emt_description'] ?? null, 'body' => $r['emt_body'] ?? null, 'subject' => $r['emt_subject'] ?? null,
			'to' => $r['emt_to'] ?? null, 'group' => $r['emt_group'] ?? null, 'code' => $r['emt_code'] ?? null,
			'form_id' => $r['emt_form_id'] ?? null, 'cc' => $r['emt_cc'] ?? null, 'bcc' => $r['emt_bcc'] ?? null,
			'language' => $r['emt_language'] ?? null, 'id' => $r['zzzzsys_email_template_id'],
		];
	}

	function nuReplaceHashVariables($s): string
	{
		return NuHash::replace((string)$s, NuPhp::$hash, NuHash::RAW);
	}

	function nuSendEmailFromTemplate($template): array
	{
		$params = ['to' => $template['to'] ?? '', 'cc' => $template['cc'] ?? '', 'bcc' => $template['bcc'] ?? '',
			'body' => nl2br((string)($template['body'] ?? '')), 'subject' => $template['subject'] ?? ''];
		foreach ($params as $k => $v) $params[$k] = nuReplaceHashVariables($v);
		return nuSendEmail($params);
	}
}

if (!function_exists('nuDashboardData')) {
	/**
	 * nuBuilder Next (0.8.401), in Before Edit: switch the check for concurrent edits (another user saved the same field since the form
	 * was opened) or the "also open by …" hint off – or on again – for this form.
	 */
	function nuNextConflictCheck(bool $on = true): void { NuConflict::$formCheck = $on; }
	function nuNextPresence(bool $on = true): void { NuConflict::$formPresence = $on; }

	/**
	 * nuBuilder Next dashboards (0.8.298): a procedure used as the source of a dashboard widget hands over its rows –
	 * a list of arrays, e.g. [['month' => 'Jan', 'sales' => 120], …] (first column = categories, the others = series).
	 */
	function nuDashboardData(array $rows): void
	{
		NuDashboard::$procRows = $rows;
	}
}

if (!function_exists('nuHomeSetBadge')) {
	/*
	 * Start page hooks (0.8.378, core/nuhomehook.php): in the procedure nu_home – they change only the tile start page being
	 * built (badges, descriptions, hidden tiles, dynamic groups / tiles with keys x_…, banners, data for JavaScript).
	 */
	function nuHomeSetBadge($key, $text, $color = 'secondary'): void { NuHomeHook::setBadge((string)$key, (string)$text, (string)$color); }
	function nuHomeSetHint($key, $text): void { NuHomeHook::setHint((string)$key, (string)$text); }
	function nuHomeHideTile($key): void { NuHomeHook::hideTile((string)$key); }
	function nuHomeAddGroup($id, $title, $tab = ''): void { NuHomeHook::addGroup((string)$id, (string)$title, (string)$tab); }
	function nuHomeAddTile($key, $tile): void { NuHomeHook::addTile((string)$key, is_array($tile) ? $tile : []); }
	function nuHomeBanner($text, $level = 'info'): void { NuHomeHook::banner((string)$text, (string)$level); }
	function nuHomeSetData($name, $value): void { NuHomeHook::setData((string)$name, $value); }
	function nuHomeCache($seconds): void { NuHomeHook::cacheFor((int)$seconds); }
}

require_once __DIR__ . '/nuphp_compat.php';   // more nuBuilder functions (database, strings, procedures …)
