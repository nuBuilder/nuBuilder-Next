<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * Files stored in the database (zzzzsys_file, nuBuilder's form "nufile"): small images and documents used by image
 * objects (sob_image_zzzzsys_file_id), reports and custom code ("Image:<code>", nuImages['<code>']).
 * sfi_json has nuBuilder's format: {"file": base64(dataURL), "name": "logo.png", "size": 3716, "type": "image/png"}.
 */
final class NuFile
{
	public const TABLE = 'zzzzsys_file';
	public const LEN = ['sfi_code' => 300, 'sfi_description' => 300, 'sfi_group' => 300];

	public static function ensureTable(): void
	{
		$db = nu_db();
		if ($db->tableExists(self::TABLE)) return;
		$T = static fn(string $t, string $l = '') => NuDDL::sqlType($t, $l);
		$db->run('CREATE TABLE zzzzsys_file (zzzzsys_file_id ' . $T('id') . ' NOT NULL PRIMARY KEY, sfi_code ' . $T('text', '300') . ' NULL, '
			. 'sfi_description ' . $T('text', '300') . ' NULL, sfi_group ' . $T('text', '300') . ' NULL, sfi_json ' . $T('longtext') . ' NULL, '
			. 'sfi_system ' . $T('text', '1') . ' NULL)' . ($db->type === 'mysql' ? NuDDL::mysqlTableOptions() : ''));
	}

	/** Maximum upload size in bytes ($nuConfigFileMaxKB, default 300 KB – the whole file is kept in the database). */
	public static function maxBytes(): int
	{
		return max(1, (int)(nu_cfg('nuConfigFileMaxKB') ?: 300)) * 1024;
	}

	/** nuBuilder JSON for a file's content. The MIME type comes from the content, not from the browser. */
	public static function encode(string $content, string $name): string
	{
		$type = '';
		if (class_exists('finfo')) $type = (string)(new finfo(FILEINFO_MIME_TYPE))->buffer($content);
		if ($type === '' || $type === 'application/octet-stream') {
			$ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
			$type = ['svg' => 'image/svg+xml', 'css' => 'text/css', 'js' => 'text/javascript', 'json' => 'application/json', 'csv' => 'text/csv'][$ext] ?? ($type ?: 'application/octet-stream');
		}
		if ($type === 'text/plain' && strtolower(pathinfo($name, PATHINFO_EXTENSION)) === 'svg') $type = 'image/svg+xml';
		$dataUrl = 'data:' . $type . ';base64,' . base64_encode($content);
		return json_encode(['file' => base64_encode($dataUrl), 'name' => self::cleanName($name), 'size' => strlen($content), 'type' => $type],
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	}

	public static function cleanName(string $name): string
	{
		$name = basename(str_replace('\\', '/', $name));
		$name = preg_replace('/[\x00-\x1f"<>|:*?]/u', '', $name) ?? '';
		return mb_substr($name !== '' ? $name : 'file', 0, 200);
	}

	/** ['name', 'size', 'type', 'url' (data URL), 'image' (bool)] of a stored file; empty array if there is none. */
	public static function info(?string $json): array
	{
		$d = json_decode((string)$json, true);
		if (!is_array($d) || !isset($d['file'])) return [];
		$url = self::dataUrl($d);
		$type = (string)($d['type'] ?? '');
		if ($type === '' && preg_match('#^data:([\w.+-]+/[\w.+-]+)#', $url, $m)) $type = $m[1];
		return ['name' => (string)($d['name'] ?? 'file'), 'size' => (int)($d['size'] ?? 0), 'type' => $type, 'url' => $url,
			'image' => str_starts_with($type, 'image/') && str_starts_with($url, 'data:image/')];
	}

	/** The data URL inside nuBuilder's JSON ("file" holds base64 of the data URL; older rows the data URL itself). */
	public static function dataUrl(array $d): string
	{
		$f = (string)($d['file'] ?? '');
		if (str_starts_with($f, 'data:')) return $f;
		$dec = base64_decode($f, true);
		return ($dec !== false && str_starts_with($dec, 'data:')) ? $dec : '';
	}

	/** Sends the file itself (images inline, everything else as download) and ends the request; 404 without a file. */
	public static function serve(?array $row, bool $download = false): never
	{
		$info = $row ? self::info((string)$row['sfi_json']) : [];
		if (!$info || !preg_match('#^data:([^;,]*)(;base64)?,#', $info['url'], $m)) { http_response_code(404); exit; }
		$body = substr($info['url'], strlen($m[0]));
		$body = $m[2] !== '' ? (string)base64_decode($body) : rawurldecode($body);
		$type = preg_match('#^[\w.+-]+/[\w.+-]+$#', $m[1]) ? $m[1] : 'application/octet-stream';
		$inline = !$download && in_array($type, ['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/svg+xml', 'image/x-icon', 'image/vnd.microsoft.icon', 'image/bmp'], true);
		header('Content-Type: ' . $type);
		header('X-Content-Type-Options: nosniff');
		header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; sandbox");   // an SVG cannot run script
		header('Cache-Control: private, max-age=300');
		header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . str_replace('"', '', $info['name']) . '"; filename*=UTF-8\'\'' . rawurlencode($info['name']));
		header('Content-Length: ' . strlen($body));
		echo $body;
		exit;
	}

	/**
	 * May the signed-in user fetch this file through ?p=file (0.8.478, security assessment K3)? A file shown by image objects only by
	 * users who can open one of those forms (or a form that has such a form as sub-form); files no image object uses (nuAttachImage …,
	 * report images, branding) stay open to every signed-in user, as in nuBuilder. The globeadmin may always.
	 */
	public static function mayRead(string $id): bool
	{
		if (!empty(NuAuth::user()['globeadmin'])) return true;
		$forms = array_values(array_unique(array_filter(array_map(static fn($u) => (string)$u['sob_all_zzzzsys_form_id'], self::usage($id)))));
		if (!$forms) return true;
		foreach ($forms as $f) if (NuAcl::can($f)) return true;
		$in = implode(',', array_fill(0, count($forms), '?'));
		$parents = nu_db()->all("SELECT DISTINCT sob_all_zzzzsys_form_id AS f FROM zzzzsys_object WHERE sob_all_type = 'subform' AND sob_subform_zzzzsys_form_id IN ($in)", $forms);
		foreach ($parents as $p) if (NuAcl::can((string)$p['f'])) return true;
		return false;
	}

	/** Image objects that show this file: [form code, object id]. */
	public static function usage(string $id): array
	{
		$db = nu_db();
		if (!isset($db->columns('zzzzsys_object')['sob_image_zzzzsys_file_id'])) return [];
		return $db->all('SELECT f.sfo_code, o.sob_all_id, o.sob_all_zzzzsys_form_id FROM zzzzsys_object o LEFT JOIN zzzzsys_form f ON f.zzzzsys_form_id = o.sob_all_zzzzsys_form_id
			WHERE o.sob_image_zzzzsys_file_id = ? ORDER BY f.sfo_code, o.sob_all_id', [$id]);
	}

	public static function size(int $bytes): string
	{
		if ($bytes <= 0) return '';
		// 0.8.272: also GB / TB (free disk space, large backups) – one decimal from MB up, none below 10 KB
		if ($bytes < 1024) return $bytes . ' B';
		foreach (['TB' => 1024 ** 4, 'GB' => 1024 ** 3, 'MB' => 1024 ** 2] as $unit => $f) {
			if ($bytes >= $f) { $v = $bytes / $f; return ($v >= 100 ? (string)round($v) : (string)round($v, 1)) . ' ' . $unit; }
		}
		return round($bytes / 1024) . ' KB';
	}

	/**
	 * Saves code, description, group and – when given – new content. $upload = ['content' => …, 'name' => …] or null (keep the file).
	 * @return array{0: ?string, 1: array} [id, errors]
	 */
	public static function save(array $in, ?array $upload, string $id = '', ?string $keepJson = null): array
	{
		$db = nu_db();
		$row = [];
		$errors = [];
		foreach (self::LEN as $k => $len) {
			$v = trim((string)($in[$k] ?? ''));
			nu_maxlen($errors, $k, $v, $len);
			$row[$k] = $v === '' ? null : $v;
		}
		if ($row['sfi_code'] === null) $errors['sfi_code'] = t('err_required');
		elseif ($db->value('SELECT COUNT(*) FROM zzzzsys_file WHERE sfi_code = ? AND zzzzsys_file_id <> ?', [$row['sfi_code'], $id])) $errors['sfi_code'] = t('file_err_code');
		if ($upload !== null) {
			if (strlen($upload['content']) > self::maxBytes()) $errors['file'] = t('file_err_size', (int)(self::maxBytes() / 1024));
			else $row['sfi_json'] = self::encode($upload['content'], (string)$upload['name']);
		} elseif ($keepJson === null || $keepJson === '') {
			$errors['file'] = t('file_err_missing');
		}
		if ($errors) return [null, $errors];
		if ($id !== '') {
			$db->update(self::TABLE, $row, 'zzzzsys_file_id', $id);
		} else {
			$id = nu_id();
			if ($upload === null) $row['sfi_json'] = $keepJson;   // clone
			$db->insert(self::TABLE, ['zzzzsys_file_id' => $id] + $row + ['sfi_system' => '0']);
		}
		return [$id, []];
	}
}
