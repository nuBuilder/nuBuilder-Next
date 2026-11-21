<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/** Functions of the page pages/nuaccesslevel.php (code review R7: a page defines no global function). */
final class NuAccessLevelPage
{
	/** Data mode and form type of a form row – two small selects, as nuBuilder's access level form. */
	public static function modeCells(string $k, string $dm, string $ft, string $label, bool $hasDm, bool $hasFt): string
	{
		$sel = static function (string $name, string $cur, array $opts, string $aria) use ($k): string {
			$o = '';
			foreach ($opts as $v => $txt) $o .= '<option value="' . h((string)$v) . '"' . ((string)$v === $cur ? ' selected' : '') . '>' . h($txt) . '</option>';
			return '<td><select class="form-select form-select-sm nuAccMode nuAccMode-' . $name . '" name="rights[' . $k . '][' . $name . ']" aria-label="' . h($aria) . '">' . $o . '</select></td>';
		};
		$out = '';
		if ($hasDm) $out .= $sel('dm', $dm, ['' => t('access_dm_normal'), '0' => t('access_dm_noedit')], t('access_col_dm') . ($label !== '' ? ' – ' . $label : ''));
		if ($hasFt) $out .= $sel('ft', $ft, ['' => t('access_ft_both'), '0' => t('access_ft_browse'), '1' => t('access_ft_edit')], t('access_col_ft') . ($label !== '' ? ' – ' . $label : ''));
		return $out;
	}

	/** The POST actions of pages/nuaccesslevel.php (part 1) – $nuIn: the page's variables before them; returned: what the page shows afterwards. */
	public static function post1(array $nuIn): array
	{
		$activeCount = $nuIn['activeCount'] ?? null; $id = $nuIn['id'] ?? null; $expiredMain = $nuIn['expiredMain'] ?? null; $db = $nuIn['db'] ?? null; $existing = $nuIn['existing'] ?? null;
		if (nu_is_post() && nu_post('action') === 'delete') {
			NuAcl::require(NuAcl::FORM_ACCESS, 'delete');
			if ($activeCount > 0) {
				nu_flash('danger', t('access_err_in_use_active', $activeCount));
				nu_redirect(nu_url('accesslevel', ['id' => $id]));
			}
			$emptied = $expiredMain;
			$db->transaction(static function (NuDB $db) use ($id, $emptied) {
				NuUserAccess::releaseLevel($db, $id, $emptied);
				$db->delete('zzzzsys_access_form', 'slf_zzzzsys_access_id', $id);
				$db->delete('zzzzsys_access_php', 'slp_zzzzsys_access_id', $id);
				if ($db->tableExists('zzzzsys_access_report')) $db->delete('zzzzsys_access_report', 'sre_zzzzsys_access_id', $id);
				$db->delete('zzzzsys_access', 'zzzzsys_access_id', $id);
			});
			if (NuHome::startMode($id) === 'form') NuHome::setStartMode($id, 'tiles');
			NuLog::write('access_deleted', 'zzzzsys_access', $id, ['code' => $existing['sal_code']] + ($emptied ? ['users_without_level' => $emptied] : []));
			nu_flash('success', t('access_deleted', $existing['sal_code']));
			nu_redirect(nu_url('access'));
		}
		return compact('activeCount', 'id', 'expiredMain', 'db', 'existing');
	}

	/** The POST actions of pages/nuaccesslevel.php (part 2) – $nuIn: the page's variables before them; returned: what the page shows afterwards. */
	public static function post2(array $nuIn): array
	{
		$canSave = $nuIn['canSave'] ?? null;
		$text = $nuIn['text'] ?? null;
		$v = $nuIn['v'] ?? null;
		$errors = $nuIn['errors'] ?? null;
		$db = $nuIn['db'] ?? null;
		$id = $nuIn['id'] ?? null;
		$forms = $nuIn['forms'] ?? null;
		$existing = $nuIn['existing'] ?? null;
		$existingById = $nuIn['existingById'] ?? null;
		$dmOf = $nuIn['dmOf'] ?? null;
		$ftOf = $nuIn['ftOf'] ?? null;
		$_ = $nuIn['_'] ?? null;
		$isNew = $nuIn['isNew'] ?? null;
		$cloneId = $nuIn['cloneId'] ?? null;
		$source = $nuIn['source'] ?? null;
		$hasDm = $nuIn['hasDm'] ?? null;
		$hasFt = $nuIn['hasFt'] ?? null;
		$r = $nuIn['r'] ?? null;
		$rows = $nuIn['rows'] ?? null;
		$row = $nuIn['row'] ?? null;
		if (nu_is_post() && nu_post('action') === 'save') {
			if (!$canSave) NuAcl::require(NuAcl::FORM_ACCESS, 'save');

			foreach ($text as $col => [$max]) {
				$v[$col] = nu_post($col);
				nu_maxlen($errors, $col, $v[$col], $max);
			}
			$v['sal_zzzzsys_form_id'] = nu_post('sal_zzzzsys_form_id');
			$v['sal_use_2fa']         = nu_check('sal_use_2fa');
			$v['start_page']          = nu_post('start_page') === 'form' ? 'form' : 'tiles';

			if ($v['sal_code'] === '') {
				$errors['sal_code'] = t('err_required');
			} elseif ((int)$db->value('SELECT COUNT(*) FROM zzzzsys_access WHERE sal_code = ? AND zzzzsys_access_id <> ?', [$v['sal_code'], $id])) {
				$errors['sal_code'] = t('access_err_code_taken');
			}
			if ($v['sal_zzzzsys_form_id'] !== '' && !isset($forms[$v['sal_zzzzsys_form_id']]) && $v['sal_zzzzsys_form_id'] !== (string)($existing['sal_zzzzsys_form_id'] ?? '')) {
				$errors['sal_zzzzsys_form_id'] = t('err_invalid');
			}
			// no Home form = the tile page (a tester: the error left no way back to the tiles once a Home form was set)
			if ($v['start_page'] === 'form' && $v['sal_zzzzsys_form_id'] === '') $v['start_page'] = 'tiles';

			// rights grid
			$rows = [];
			$seen = [];
			$posted = $_POST['rights'] ?? [];
			foreach (is_array($posted) ? $posted : [] as $key => $p) {
				if (!is_array($p)) continue;
				$rid  = is_string($p['id'] ?? null) && isset($existingById[$p['id']]) ? $p['id'] : '';   // foreign ids are treated as new rows
				$form = is_string($p['form'] ?? null) ? trim($p['form']) : '';
				if ($form === '') continue;
				// crossed out: not saved, so an existing row is deleted below; kept crossed out if the page comes back with errors
				if (($p['remove'] ?? '') === '1') {
					$row = ['id' => $rid, 'form' => $form, 'remove' => true, 'dm' => $dmOf($p['dm'] ?? ''), 'ft' => $ftOf($p['ft'] ?? '')];
					foreach (NuAcl::RIGHTS as $right => $_) $row[$right] = (($p[$right] ?? '') === '1') ? '1' : '0';
					$rows[$rid !== '' ? $rid : 'n' . preg_replace('/\W/', '', (string)$key)] = $row;
					continue;
				}
				$known = isset($forms[$form]) || ($rid !== '' && (string)$existingById[$rid]['slf_zzzzsys_form_id'] === $form);
				if (!$known) { $errors['rights'] = t('access_err_unknown_form', $form); continue; }
				if (isset($seen[$form])) { $errors['rights'] = t('access_err_duplicate', nu_form_label($form)); continue; }
				$seen[$form] = true;

				$row = ['id' => $rid, 'form' => $form, 'dm' => $dmOf($p['dm'] ?? ''), 'ft' => $ftOf($p['ft'] ?? '')];
				foreach (NuAcl::RIGHTS as $right => $_) $row[$right] = (($p[$right] ?? '') === '1') ? '1' : '0';
				$rows[$rid !== '' ? $rid : 'n' . preg_replace('/\W/', '', (string)$key)] = $row;
			}

			// admin-equivalent rights (NuSecurity::ADMIN_FORMS): with "Only the globeadmin grants admin rights" nobody else adds them,
			// and nobody changes the rights of his own access level
			if (!$errors && NuSecurity::restricted()) {
				if (!$isNew && NuUserAccess::holds($id)) {                   // main or additional level of the own account
					$errors['rights'] = t('secchk_err_own_rights');
				} else {
					$before = $isNew ? [] : NuSecurity::adminRightsOf($id);
					foreach ($rows as $row) {
						if (!empty($row['remove']) || !in_array($row['form'], NuSecurity::ADMIN_FORMS, true)) continue;
						foreach (NuAcl::RIGHTS as $right => $col) {
							if ($row[$right] === '1' && in_array($col, NuSecurity::ADMIN_RIGHTS, true) && !in_array($col, $before[$row['form']] ?? [], true)) {
								$errors['rights'] = t('secchk_err_admin_rights', nu_form_label($row['form']));
								break 2;
							}
						}
					}
				}
			}

			if (!$errors) {
				$newId = $db->transaction(static function (NuDB $db) use ($isNew, $id, $v, $rows, $existingById, $existing, $cloneId, $source, $hasDm, $hasFt) {
					$head = [
						'sal_code'            => $v['sal_code'],
						'sal_description'     => $v['sal_description'] === '' ? null : $v['sal_description'],
						'sal_group'           => $v['sal_group'] === '' ? null : $v['sal_group'],
						'sal_zzzzsys_form_id' => $v['sal_zzzzsys_form_id'] === '' ? null : $v['sal_zzzzsys_form_id'],
						'sal_use_2fa'         => $v['sal_use_2fa'],
					];
					if ($isNew) {
						$id = nu_id();
						$db->insert('zzzzsys_access', ['zzzzsys_access_id' => $id] + $head + ['sal_json' => $source['sal_json'] ?? null]);
					} else {
						$db->update('zzzzsys_access', NuDB::keepNull($existing, $head, ['sal_use_2fa' => '0']), 'zzzzsys_access_id', $id);   // sal_json untouched
					}

					$keep = [];
					foreach ($rows as $row) {
						if (!empty($row['remove'])) continue;                 // crossed out: an existing row is deleted below
						$data = ['slf_zzzzsys_form_id' => $row['form']];
						foreach (NuAcl::RIGHTS as $right => $col) $data[$col] = $row[$right];
						if ($hasDm) $data['slf_data_mode'] = $row['dm'] === '' ? null : $row['dm'];       // NULL = no limit (as nuBuilder)
						if ($hasFt) $data['slf_form_type'] = $row['ft'] === '' ? null : $row['ft'];
						if ($row['id'] !== '') {
							// a right column nuBuilder Forte left NULL stays NULL while it is still off
							$data = NuDB::keepNull($existingById[$row['id']] ?? null, $data, array_fill_keys(array_values(NuAcl::RIGHTS), '0'));
							$db->update('zzzzsys_access_form', $data, 'zzzzsys_access_form_id', $row['id']);   // json untouched; unchanged columns are not written
							$keep[$row['id']] = true;
						} else {
							$db->insert('zzzzsys_access_form', ['zzzzsys_access_form_id' => nu_id(), 'slf_zzzzsys_access_id' => $id] + $data);
						}
					}
					foreach (array_keys($existingById) as $oldId) {
						if (!isset($keep[$oldId])) $db->delete('zzzzsys_access_form', 'zzzzsys_access_form_id', (string)$oldId);
					}

					// a clone also copies the PHP procedure rights
					if ($isNew && $cloneId !== '') {
						foreach ($db->all('SELECT slp_zzzzsys_php_id, slp_json FROM zzzzsys_access_php WHERE slp_zzzzsys_access_id = ?', [$cloneId]) as $p) {
							$db->insert('zzzzsys_access_php', ['zzzzsys_access_php_id' => nu_id(), 'slp_zzzzsys_access_id' => $id] + $p);
						}
						if ($db->tableExists('zzzzsys_access_report')) {                 // and the report rights
							foreach ($db->all('SELECT sre_zzzzsys_report_id, srp_json FROM zzzzsys_access_report WHERE sre_zzzzsys_access_id = ?', [$cloneId]) as $p) {
								$db->insert('zzzzsys_access_report', ['zzzzsys_access_report_id' => nu_id(), 'sre_zzzzsys_access_id' => $id] + $p);
							}
						}
					}
					return $id;
				});

				if (NuHome::startMode((string)$newId) !== $v['start_page']) NuHome::setStartMode((string)$newId, $v['start_page']);
				NuLog::write($isNew ? 'access_created' : 'access_updated', 'zzzzsys_access', $newId,
					['code' => $v['sal_code'], 'forms' => count(array_filter($rows, static fn($r) => empty($r['remove']))), 'start_page' => $v['start_page']]);
				nu_flash('success', $isNew ? t('access_created', $v['sal_code']) : t('saved'));
				if ($isNew) {                                                     // forms for everyone: a new level gets them at once
					$evAdded = NuFormEveryone::addToLevel((string)$newId);
					if ($evAdded) nu_flash('info', t('fac_ev_added', count($evAdded), implode(', ', $evAdded)));
				}
				nu_redirect(nu_url('accesslevel', ['id' => $newId, 'tab' => in_array(nu_post('tab'), ['rights', 'start'], true) ? nu_post('tab') : null]));   // stays on the tab it was saved from
			}
			nu_flash('danger', t('err_check_form'));
		}
		return compact('canSave', 'v', 'errors', 'db', 'id', 'forms', 'existing', 'rows', 'row', 'isNew', 'cloneId', 'hasDm', 'hasFt', 'r');
	}
}
