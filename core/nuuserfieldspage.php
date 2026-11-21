<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/** The work of pages/nuuserfields.php (code review R7): its POST actions – the page reads the record, renders and shows the result. */
final class NuUserfieldsPage
{
	/** The POST actions of pages/nuuserfields.php (part 1) – $nuIn: the page's variables before them; returned: what the page shows afterwards. */
	public static function post1(array $nuIn): array
	{
		if (nu_is_post()) NuSecurity::requireRecentAuth(nu_url('userfields'));
		return [];
	}

	/** The POST actions of pages/nuuserfields.php (part 2) – $nuIn: the page's variables before them; returned: what the page shows afterwards. */
	public static function post2(array $nuIn): array
	{
		$id = $nuIn['id'] ?? null; $errors = $nuIn['errors'] ?? null; $in = $nuIn['in'] ?? null; $e = $nuIn['e'] ?? null;
		if (nu_is_post()) {
			$action = nu_post('action');
			if ($action === 'default_preview') {   // example of a worked-out default + its check (JSON for nuuserfields.js)
				header('Content-Type: application/json; charset=utf-8');
				$fd = ['type' => (string)nu_post('type'), 'options' => (string)nu_post('options'), 'code' => (string)nu_post('code'), 'is_unique' => '0', 'required' => '0'];
				if (!isset(NuUserFields::TYPES[$fd['type']])) $fd['type'] = 'text';
				$d = trim((string)nu_post('default_value'));
				$old = $id !== 'new' ? NuUserFields::byId($id) : null;
				$kept = $old && $d === trim((string)$old['default_value']);
				$ex = NuUserDefault::example($fd, $d);
				$err = $d === '' ? null : (NuUserDefault::dynamic($d) ? NuUserDefault::check($fd, $d) : NuUserFields::checkValue($fd, $d, null));
				if ($kept && $err === t('ufd_err_ga')) $err = null;
				exit((string)json_encode($ex + ['error' => $err ?? ($ex['error'] ?? null)], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
			}
			try {
				if ($action === 'move') {
					NuUserFields::move(nu_post('id'), nu_post('dir') === 'up' ? -1 : 1);
					nu_redirect(nu_url('userfields'));
				}
				if ($action === 'delete') {
					$f = NuUserFields::byId(nu_post('id'));
					if ($f) {
						$n = NuUserFields::delete((string)$f['zzzzsys_nunext_user_field_id']);
						nu_flash('success', t('uf_deleted', NuUserFields::label($f), $n));
					}
					nu_redirect(nu_url('userfields'));
				}
				if ($action === 'save') {
					$old = $id !== 'new' ? NuUserFields::byId($id) : null;
					if ($id !== 'new' && !$old) throw new RuntimeException(t('record_not_found'));
					$in = $_POST;
					[$row, $errors] = NuUserFields::check($in, $old);
					if (!$errors) {
						if ($old) {
							NuUserFields::update($id, $row);
						} else {
							$id = NuUserFields::add($row);
						}
						nu_flash('success', t('saved'));
						nu_redirect(nu_url('userfields'));
					}
					nu_flash('danger', t('err_check_form'));
				}
			} catch (Throwable $e) {
				error_log('nuBuilder Next user fields: ' . $e->getMessage());
				nu_flash('danger', t('uf_err_save') . ' ' . (NuAuth::user()['globeadmin'] || nu_debug() ? $e->getMessage() : ''));
				if ($action !== 'save') nu_redirect(nu_url('userfields'));
			}
		}
		return compact('id', 'in', 'errors', 'e');
	}
}
