<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * File Manager (0.8.479, handover "nuFiles" C1 / C9): the page form nuFiles (apps/nufiles/ since 0.8.481, shipped with nuBuilder Next) replaces
 * Tiny File Manager. Every entry point (admin menu, start page tile, ⋮ menu of forms, nuVendorLogin('TFM')) opens ?p=filemanager, which
 * redirects to the page – or, while the page form does not exist yet, lets the globeadmin create it with default settings.
 * Admin right 'filemanager' (change) shows the entries; the page itself follows the form's access levels (the globeadmin always).
 */
final class NuFileManager
{
	public const CODE = 'nufiles';

	/** Id of the page form nufiles, null = not set up. */
	public static function formId(): ?string
	{
		try {
			$v = nu_db()->value("SELECT zzzzsys_form_id FROM zzzzsys_form WHERE sfo_code = ? AND sfo_type = 'page'", [self::CODE]);
			return is_string($v) && $v !== '' ? $v : null;
		} catch (Throwable) {
			return null;
		}
	}

	/** The page's files are there (apps/nufiles/index.php, or an own copy in custom/pages/nufiles/). */
	public static function shipped(): bool
	{
		return NuPage::file(self::CODE) !== null;
	}

	/** May this user see the File Manager entries? */
	public static function visible(): bool
	{
		return self::shipped() && NuAdmin::can('filemanager', 'change');
	}

	public static function url(): string
	{
		return nu_url('filemanager');
	}

	/** Folder of the upload template's files (as nu_upload_file_template: next to the web root, else inside it – closed). */
	public static function uploadsDir(): string
	{
		$docRoot = rtrim(str_replace('\\', '/', (string)($_SERVER['DOCUMENT_ROOT'] ?? '')), '/');
		if ($docRoot === '') $docRoot = str_replace('\\', '/', dirname(NU_ROOT));
		$out = dirname($docRoot) . '/nu_uploads';
		if (is_dir($out) || @mkdir($out, 0750, true)) return $out;
		$in = $docRoot . '/nu_uploads';
		NuSecurity::protectDir($in);
		return $in;
	}

	/** Settings of a new File Manager: the uploads folder (files) and the web root (code: globeadmin, password confirmation). */
	public static function defaultSettings(): array
	{
		return [
			'shares' => [
				['id' => 'uploads', 'label' => 'Uploads', 'path' => self::uploadsDir(), 'type' => 'files', 'max_upload_mb' => 100, 'grants' => []],
				['id' => 'webroot', 'label' => 'Web root (code)', 'path' => '{DOCUMENT_ROOT}', 'type' => 'code', 'deny' => ['.git', 'nuconfig.php', 'secrets.php']],
			],
			'reauth' => 'code', 'max_upload_mb' => 200, 'chunk_mb' => 2, 'trash_days' => 30, 'versions_keep' => 10, 'log_table' => 'nufiles_log', 'log_reads' => false,
		];
	}

	/** Creates the page form nufiles with default settings (globeadmin). @return string the form id */
	public static function setup(): string
	{
		$id = self::formId();
		if ($id !== null) return $id;
		if (!self::shipped()) throw new RuntimeException(t('fm_missing_files', NuPage::shownFolder(self::CODE)));
		$db = nu_db();
		$id = nu_id();
		$row = ['zzzzsys_form_id' => $id, 'sfo_type' => 'page', 'sfo_code' => self::CODE, 'sfo_description' => t('vendor_tfm'), 'sfo_status' => '1',
			'sfo_breadcrumb_title' => '|' . t('vendor_tfm')];
		$db->insert('zzzzsys_form', array_intersect_key($row, $db->columns('zzzzsys_form') ?: $row));
		$errors = NuPage::save($id, ['open_mode' => 'tab', 'iframe_height' => 'auto',
			'settings' => (string)json_encode(self::defaultSettings(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'csp' => '', 'note' => '']);
		if ($errors) throw new RuntimeException(implode(' ', $errors));
		NuLog::write('filemanager_setup', 'zzzzsys_form', $id, ['code' => self::CODE]);
		return $id;
	}

	/**
	 * Update step 0023-retire-tfm: Tiny File Manager is removed – its library packs (vendor/packs/tinyfilemanager-*, third_party/
	 * tinyfilemanager-*) and the hand copy third_party/tinyfilemanager/ of nuBuilder Next (never nuBuilder's own copy). @return string what was done
	 */
	public static function retireTfm(): string
	{
		$done = NuLibs::retire('tinyfilemanager');
		$hand = NU_ROOT . '/third_party/tinyfilemanager';
		if (is_dir($hand) && !is_link($hand)) {
			self::rmTree($hand);
			$done[] = 'third_party/tinyfilemanager';
		}
		return $done ? 'removed: ' . implode(', ', $done) : '';
	}

	private static function rmTree(string $dir): void
	{
		$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
		foreach ($it as $f) {
			if ($f->isDir() && !$f->isLink()) @rmdir($f->getPathname());
			else @unlink($f->getPathname());
		}
		@rmdir($dir);
	}
}
