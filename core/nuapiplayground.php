<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * API playground (pages/nuapiplayground.php): saved requests, shared by the administrators, and the settings of each login
 * (client, scope, variables, If-Match option) in zzzzsys_nunext_api_request.
 */
final class NuApiPlayground
{
	public const TABLE = 'zzzzsys_nunext_api_request';
	public const METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];

	public static function ensure(): void
	{
		NuApiClient::ensure();
		if (!nu_db()->tableExists(self::TABLE)) NuSchema::create();
	}

	/** Saved requests, by folder and name. */
	public static function requests(): array
	{
		self::ensure();
		$rows = nu_db()->all('SELECT * FROM ' . self::TABLE . " WHERE kind = 'request' ORDER BY folder, name");
		return array_map(static fn(array $r) => self::out($r), $rows);
	}

	private static function out(array $r): array
	{
		$h = json_decode((string)$r['headers'], true);
		$s = json_decode((string)$r['settings'], true);
		return ['id' => (string)$r['zzzzsys_nunext_api_request_id'], 'name' => (string)$r['name'], 'folder' => (string)($r['folder'] ?? ''),
			'method' => (string)$r['method'], 'path' => (string)$r['path'], 'headers' => is_array($h) ? $h : [], 'body' => (string)($r['body'] ?? ''),
			'settings' => is_array($s) ? $s : [], 'updated_at' => (string)($r['updated_at'] ?? ''), 'created_by' => (string)($r['created_by'] ?? '')];
	}

	/** @return array{0: ?array, 1: array} [saved request, errors] */
	public static function save(array $in, string $login): array
	{
		self::ensure();
		$db = nu_db();
		$errors = [];
		$name = trim((string)($in['name'] ?? ''));
		$folder = trim((string)($in['folder'] ?? ''));
		$method = strtoupper((string)($in['method'] ?? 'GET'));
		$path = trim((string)($in['path'] ?? ''));
		if ($name === '' || mb_strlen($name) > 100) $errors['name'] = $name === '' ? t('err_required') : t('err_too_long', 100);
		if (mb_strlen($folder) > 100) $errors['folder'] = t('err_too_long', 100);
		if (!in_array($method, self::METHODS, true)) $errors['method'] = 'GET POST PUT PATCH DELETE';
		if ($path === '' || mb_strlen($path) > 1000 || !str_starts_with($path, '/')) $errors['path'] = t('apg_err_path');
		$headers = [];
		foreach ((array)($in['headers'] ?? []) as $h) {
			$k = trim((string)($h['name'] ?? ''));
			if ($k === '') continue;
			if (!preg_match('/^[A-Za-z0-9\-]{1,60}$/', $k)) { $errors['headers'] = t('apg_err_header', $k); continue; }
			$headers[] = ['name' => $k, 'value' => mb_substr((string)($h['value'] ?? ''), 0, 1000), 'on' => !isset($h['on']) || !empty($h['on'])];
		}
		$headersJson = json_encode($headers, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		if (strlen($headersJson) > 4000) $errors['headers'] = t('err_too_long', 4000);
		$body = (string)($in['body'] ?? '');
		if (strlen($body) > 1_000_000) $errors['body'] = t('err_too_long', 1000000);
		$settings = self::cleanSettings((array)($in['settings'] ?? []), false);
		if ($errors) return [null, $errors];

		$row = ['name' => $name, 'folder' => $folder === '' ? null : $folder, 'method' => $method, 'path' => $path, 'headers' => $headersJson,
			'body' => $body === '' ? null : $body, 'settings' => json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'updated_at' => date('Y-m-d H:i:s')];
		$id = (string)($in['id'] ?? '');
		$old = $id !== '' ? $db->one('SELECT * FROM ' . self::TABLE . " WHERE zzzzsys_nunext_api_request_id = ? AND kind = 'request'", [$id]) : null;
		if ($old) {
			$db->update(self::TABLE, $row, 'zzzzsys_nunext_api_request_id', $id);
			NuLog::write('apg_request_changed', self::TABLE, $id, ['name' => $name]);
		} else {
			$id = nu_id();
			$db->insert(self::TABLE, ['zzzzsys_nunext_api_request_id' => $id, 'kind' => 'request', 'created_by' => $login, 'created_at' => date('Y-m-d H:i:s')] + $row);
			NuLog::write('apg_request_created', self::TABLE, $id, ['name' => $name]);
		}
		return [self::out((array)$db->one('SELECT * FROM ' . self::TABLE . ' WHERE zzzzsys_nunext_api_request_id = ?', [$id])), []];
	}

	public static function delete(string $id): bool
	{
		self::ensure();
		$db = nu_db();
		$name = $db->value('SELECT name FROM ' . self::TABLE . " WHERE zzzzsys_nunext_api_request_id = ? AND kind = 'request'", [$id]);
		if ($name === null) return false;
		$db->run('DELETE FROM ' . self::TABLE . ' WHERE zzzzsys_nunext_api_request_id = ?', [$id]);
		NuLog::write('apg_request_deleted', self::TABLE, $id, ['name' => (string)$name]);
		return true;
	}

	/** Client, scope, If-Match option, variables. $full: also the variables (only kept in the settings of a login). */
	private static function cleanSettings(array $s, bool $full = true): array
	{
		$out = ['client_ref' => mb_substr((string)($s['client_ref'] ?? ''), 0, 25), 'scope' => mb_substr(trim((string)($s['scope'] ?? '')), 0, 500),
			'auto_etag' => !array_key_exists('auto_etag', $s) || !empty($s['auto_etag'])];
		if ($full) {
			$vars = [];
			foreach ((array)($s['vars'] ?? []) as $k => $v) {
				$k = (string)$k;
				if (preg_match('/^\w{1,40}$/', $k) && count($vars) < 50) $vars[$k] = mb_substr((string)$v, 0, 500);
			}
			$out['vars'] = (object)$vars;
		}
		return $out;
	}

	private static function settingsId(string $login): string
	{
		return 's' . substr(hash('sha256', 'nunext-apg|' . $login), 0, 24);
	}

	public static function settings(string $login): array
	{
		self::ensure();
		$s = nu_db()->value('SELECT settings FROM ' . self::TABLE . ' WHERE zzzzsys_nunext_api_request_id = ?', [self::settingsId($login)]);
		$d = json_decode((string)$s, true);
		return self::cleanSettings(is_array($d) ? $d : []);
	}

	public static function saveSettings(array $in, string $login): array
	{
		self::ensure();
		$db = nu_db();
		$s = self::cleanSettings($in);
		$json = json_encode($s, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		if (strlen($json) > 4000) { $s['vars'] = (object)[]; $json = json_encode($s, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }
		$id = self::settingsId($login);
		if ($db->value('SELECT COUNT(*) FROM ' . self::TABLE . ' WHERE zzzzsys_nunext_api_request_id = ?', [$id])) {
			$db->update(self::TABLE, ['settings' => $json, 'updated_at' => date('Y-m-d H:i:s')], 'zzzzsys_nunext_api_request_id', $id);
		} else {
			$db->insert(self::TABLE, ['zzzzsys_nunext_api_request_id' => $id, 'kind' => 'settings', 'name' => mb_substr($login, 0, 100), 'settings' => $json,
				'created_by' => $login, 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
		}
		return $s;
	}
}
