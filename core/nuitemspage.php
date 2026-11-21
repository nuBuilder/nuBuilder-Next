<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/** The work of pages/nuitems.php (code review R7): its POST actions – the page reads the record, renders and shows the result. */
final class NuItemsPage
{
	/** The POST actions of pages/nuitems.php – $nuIn: the page's variables before them; returned: what the page shows afterwards. */
	public static function post(array $nuIn): array
	{
		$item = $nuIn['item'] ?? null;
		$src = $nuIn['src'] ?? null;
		$errors = $nuIn['errors'] ?? null;
		$id = $nuIn['id'] ?? null;
		$db = $nuIn['db'] ?? null;
		$T = $nuIn['T'] ?? null;
		$cloneId = $nuIn['cloneId'] ?? null;
		$k = $nuIn['k'] ?? null;
		if (nu_is_post()) {
			$action = nu_post('action');
			if ($action === 'save') {
				NuAcl::require(NuAcl::FORM_ITEM, $item ? 'save' : ($src ? 'clone' : 'create'));
				$in = [];
				foreach (array_keys(NuItem::FIELDS) as $k) $in[$k] = is_string($_POST[$k] ?? null) ? str_replace("\r\n", "\n", (string)$_POST[$k]) : '';
				$in['itm_active'] = nu_check('itm_active');
				[$newId, $errors] = NuItem::save($in, $item ? $id : '');
				if (!$errors) {
					$after = $db->one("SELECT * FROM $T WHERE zzzzsys_item_id = ?", [$newId]) ?? [];
					NuLog::write($item ? 'item_changed' : 'item_created', $T, (string)$newId,
						$item ? ['fields' => NuLog::diff($item, $after)] : ['value' => $in['itm_value']] + ($src ? ['clone_of' => $cloneId] : []));
					nu_flash('success', t('saved'));
					nu_redirect(nu_url('items', ['id' => $newId, 'tab' => nu_post('tab') ?: null]));
				}
			}
			if ($action === 'delete' && $item) {
				NuAcl::require(NuAcl::FORM_ITEM, 'delete');
				$db->delete($T, 'zzzzsys_item_id', $id);
				NuLog::write('item_deleted', $T, $id, ['value' => (string)$item['itm_value']]);
				nu_flash('success', t('item_deleted'));
				nu_redirect(nu_url('items'));
			}
		}
		return compact('item', 'src', 'k', 'errors', 'id', 'db', 'T');
	}
}
