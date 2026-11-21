<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/** The work of pages/nuaudithistory.php (code review R7): its POST actions – the page reads the record, renders and shows the result. */
final class NuAudithistoryPage
{
	/** The POST actions of pages/nuaudithistory.php – $nuIn: the page's variables before them; returned: what the page shows afterwards. */
	public static function post(array $nuIn): array
	{
		$canRestore = $nuIn['canRestore'] ?? null; $meta = $nuIn['meta'] ?? null; $self = $nuIn['self'] ?? null;
		if (nu_is_post() && nu_post('action') === 'restore') {
			if (!$canRestore) NuAcl::require('-');
			try {
				nu_flash('success', NuAudit::restore(nu_post('id'), $meta->id));
			} catch (RuntimeException $e) {
				nu_flash('danger', $e->getMessage());
			}
			nu_redirect($self);
		}
		return compact('canRestore', 'meta');
	}
}
