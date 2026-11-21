<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/** The work of pages/nututorial.php (code review R7): its POST actions – the page reads the record, renders and shows the result. */
final class NuTutorialPage
{
	/** The POST actions of pages/nututorial.php – $nuIn: the page's variables before them; returned: what the page shows afterwards. */
	public static function post(array $nuIn): array
	{
		$all = $nuIn['all'] ?? null; $order = $nuIn['order'] ?? null; $ids = $nuIn['ids'] ?? null; $id = $nuIn['id'] ?? null;
		if (nu_is_post()) {
			$p = NuTutorial::progress();
			$id = nu_post('l');
			$back = nu_post('back') === 'home' ? nu_url('home') : nu_url('tutorial', $id !== '' && isset($all[$id]) ? ['l' => $id] : []);
			switch (nu_post('action')) {
				case 'done':
					if (isset($order[$id])) {
						$p['done'][] = $id;
						$pos = array_search($id, $ids, true);
						$next = $ids[$pos + 1] ?? '';
						$back = nu_url('tutorial', $next !== '' ? ['l' => $next] : ['finished' => '1']);
					} elseif (isset($all[$id])) $p['done'][] = $id;           // a lesson of the other track: stays on it
					break;
				case 'track':
					$tr = nu_post('track');
					if (in_array($tr, NuTutorial::TRACKS, true)) {
						$first = $p['track'] === '';
						$p['track'] = $tr;
						NuTutorial::save($p);
						if ($id === '' && nu_post('go') === 'start') $id = NuTutorial::nextOpen($p);
						$tOrder = NuTutorial::order($tr);
						$back = nu_url('tutorial', $id !== '' && (isset($tOrder[$id]) || isset($all[$id])) ? ['l' => $id] : []);
						if (!$first) nu_flash('info', t('tut_track_switched', t('tut_track_' . $tr)));
					}
					break;
				case 'undo':
					$p['done'] = array_values(array_diff($p['done'], [$id]));
					break;
				case 'hide':
					$p['hidden'] = true;
					nu_flash('info', t('tut_hidden'));
					break;
				case 'show':
					$p['hidden'] = false;
					break;
				case 'reset':
					$p['done'] = [];
					$p['hidden'] = false;
					$back = nu_url('tutorial');
					break;
			}
			NuTutorial::save($p);
			nu_redirect($back);
		}
		return compact('id', 'all', 'order', 'ids');
	}
}
