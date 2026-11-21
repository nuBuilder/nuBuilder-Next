<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/** The work of pages/nulibpack.php (code review R7): its POST actions – the page reads the record, renders and shows the result. */
final class NuLibpackPage
{
	/** The POST actions of pages/nulibpack.php – $nuIn: the page's variables before them; returned: what the page shows afterwards. */
	public static function post(array $nuIn): array
	{
		$outBase = $nuIn['outBase'] ?? null; $secure = $nuIn['secure'] ?? null; $src = $nuIn['src'] ?? null;
		if (nu_is_post()) {
			$action = nu_post('action');
			if ($action === 'discard') {
				foreach (glob($outBase . '/*', GLOB_ONLYDIR) ?: [] as $old) { foreach (glob($old . '/*') ?: [] as $x) @unlink($x); @rmdir($old); }
				unset($_SESSION[NuSess::LIBPACK]);
				nu_redirect(nu_url('libpack'));
			}
			if ($action === 'build') {
				$sk = '';
				try {
					if (!$secure) throw new RuntimeException(t('lp_err_https'));
					$kf = $_FILES['key'] ?? null;
					if (!is_array($kf) || (int)($kf['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$kf['tmp_name'])) throw new RuntimeException(t('lp_err_key'));
					$text = (string)file_get_contents((string)$kf['tmp_name'], false, null, 0, 4096);
					@unlink((string)$kf['tmp_name']);                                       // the key never stays on the server
					$sk = NuLibs::secretKey($text);
					$text = '';
					$slots = array_values(array_intersect(array_map('strval', (array)($_POST['slots'] ?? [])), array_keys(NuLibs::SLOTS)));
					if (!$slots) throw new RuntimeException(t('lp_err_none'));
					nu_ignore_abort();
					nu_time_limit(0);
					foreach (glob($outBase . '/*', GLOB_ONLYDIR) ?: [] as $old) { foreach (glob($old . '/*') ?: [] as $x) @unlink($x); @rmdir($old); }   // only the newest build
					NuSecurity::protectDir($outBase);
					$id = bin2hex(random_bytes(6));
					$dir = $outBase . '/' . $id;
					$packs = [];
					foreach ($slots as $sl) {
						$src = trim((string)($_POST['src'][$sl] ?? ''));
						$src = $src === '' ? NuLibs::source($sl)[0] : NuBuilderLib::absPath($src);   // empty: ../libsrc/<slot>/, else the library in use
						$ver = trim((string)($_POST['ver'][$sl] ?? ''));
						try {
							$packs[] = NuLibs::build($sl, $src, $sk, $dir, $ver);
						} catch (Throwable $e) {
							throw new RuntimeException(NuLibs::SLOTS[$sl]['title'] . ': ' . $e->getMessage());
						}
					}
					$keep = nu_check('keep') === '1' ? NuLibs::ghState()['libs'] : [];
					file_put_contents($dir . '/' . NuLibs::INDEX, NuLibs::buildIndex($packs, $sk, $keep));
					NuLibs::parseIndex((string)file_get_contents($dir . '/' . NuLibs::INDEX));           // the index checks out with the public key
					$bundle = 'nunext-libs-' . date('Ymd-Hi') . '.zip';
					$z = new ZipArchive();
					if ($z->open($dir . '/' . $bundle, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new RuntimeException(t('lp_err_write', $bundle));
					foreach ($packs as $p) $z->addFile($p['file'], basename($p['file']));
					$z->addFile($dir . '/' . NuLibs::INDEX, NuLibs::INDEX);
					$z->close();
					$_SESSION[NuSess::LIBPACK] = ['id' => $id, 'bundle' => $bundle, 'kept' => array_values(array_diff(array_column($keep, 'name'), $slots)),
						'packs' => array_map(static fn($p) => ['name' => $p['name'], 'version' => $p['version'], 'file' => basename($p['file']), 'files' => $p['files'], 'size' => $p['size'], 'sha256' => $p['sha256']], $packs)];
					NuLog::write('nunext_lib_build', 'third_party', implode(',', $slots), ['versions' => array_column($packs, 'version', 'name')]);
					nu_flash('success', t('lp_built', count($packs)), true);
				} catch (Throwable $e) {
					nu_flash('danger', $e->getMessage());
				} finally {
					if ($sk !== '') sodium_memzero($sk);
				}
				nu_redirect(nu_url('libpack'));
			}
		}
		return compact('secure', 'src');
	}
}
