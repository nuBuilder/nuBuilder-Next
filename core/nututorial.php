<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * Tutorial (0.8.328): a step-by-step introduction for the globeadmin and users with an admin role – pages/nututorial.php.
 *
 * - Text (English, like the documentation): docs/nunext-tutorial.htm – <article class="tut-chapter"> with <section class="tut-lesson">
 *   (see the comment at the top of that file). Lessons a user with an admin role has no right for are left out (data-right).
 * - Progress per reader: the globeadmin in zzzzsys_nunext_setting (id tutorial_ga), other users in their sus_json (NUNEXT_TUTORIAL),
 *   demo users in the session only. {done: [lesson ids], hidden: bool} – "hidden" hides the start page banner.
 */
final class NuTutorial
{
	public const FILE = 'docs/nunext-tutorial.htm';
	private const SETTING = 'tutorial_ga';
	private const JSON_KEY = 'NUNEXT_TUTORIAL';
	private const SESSION = 'nu_tutorial';

	private static ?array $chapters = null;

	/** May the signed-in user use the tutorial? */
	public static function allowed(): bool
	{
		return NuAuth::user() !== null && NuAdmin::any() && !NuImpersonate::active();
	}

	/** Lesson visible for the reader? data-right: '' everybody, 'ga' the globeadmin, 'acl:<form id>' view right, else an admin right. */
	private static function visible(string $right): bool
	{
		$u = NuAuth::user();
		if (!empty($u['globeadmin'])) return true;
		if ($right === '') return true;
		if ($right === 'ga') return false;
		if (str_starts_with($right, 'acl:')) return NuAcl::can(substr($right, 4));
		return isset(NuAdmin::RIGHTS[$right]) && NuAdmin::can($right, 'view');
	}

	/**
	 * Chapters with the lessons of this reader.
	 * @return list<array{id: string, title: string, part: string, intro: string, lessons: list<array{id: string, title: string, html: string}>}>
	 */
	public static function chapters(): array
	{
		if (self::$chapters !== null) return self::$chapters;
		$file = NU_ROOT . '/' . self::FILE;
		if (!is_file($file)) return self::$chapters = [];
		$dom = new DOMDocument();
		libxml_use_internal_errors(true);
		$dom->loadHTML('<?xml encoding="utf-8"?>' . (string)file_get_contents($file), LIBXML_NONET);
		libxml_clear_errors();
		$xp = new DOMXPath($dom);
		self::links($xp);
		$out = [];
		foreach ($xp->query('//article[contains(concat(" ", @class, " "), " tut-chapter ")]') ?: [] as $art) {
			if (!$art instanceof DOMElement || !self::visible($art->getAttribute('data-right'))) continue;
			$h = $xp->query('./h2', $art)->item(0);
			$intro = $xp->query('./p[contains(@class, "tut-intro")]', $art)->item(0);
			$lessons = [];
			foreach ($xp->query('./section[contains(concat(" ", @class, " "), " tut-lesson ")]', $art) ?: [] as $sec) {
				if (!$sec instanceof DOMElement || !self::visible($sec->getAttribute('data-right'))) continue;
				$h3 = $xp->query('./h3', $sec)->item(0);
				if ($h3) $sec->removeChild($h3);
				$html = '';
				foreach (iterator_to_array($sec->childNodes) as $n) $html .= $dom->saveHTML($n);
				$lessons[] = ['id' => $sec->getAttribute('id'), 'title' => trim((string)$h3?->textContent), 'html' => trim($html)];
			}
			if (!$lessons) continue;
			$out[] = ['id' => $art->getAttribute('id'), 'title' => trim((string)$h?->textContent), 'part' => $art->getAttribute('data-part'),
				'intro' => $intro ? trim((string)$intro->textContent) : '', 'lessons' => $lessons];
		}
		return self::$chapters = $out;
	}

	/**
	 * Links in the text: <a data-open="route" data-args="k=v&k2=v2"> → the page (new tab), <a data-help="anchor"> → the documentation
	 * (new tab), <a data-lesson="id"> → another lesson. <div class="tut-task"> gets its heading in the reader's language.
	 */
	private static function links(DOMXPath $xp): void
	{
		$dom = $xp->document;
		foreach (iterator_to_array($xp->query('//a[@data-open or @data-help or @data-lesson]') ?: []) as $a) {
			if (!$a instanceof DOMElement) continue;
			if ($a->hasAttribute('data-lesson')) {
				$a->setAttribute('href', nu_url('tutorial', ['l' => $a->getAttribute('data-lesson')]));
				continue;
			}
			if ($a->hasAttribute('data-open')) {
				parse_str($a->getAttribute('data-args'), $args);
				$a->setAttribute('href', nu_url($a->getAttribute('data-open'), array_map('strval', (array)$args)));
				$icon = 'bi-box-arrow-up-right';
				$a->setAttribute('class', trim($a->getAttribute('class') . ' tutOpen'));
			} else {
				$a->setAttribute('href', nu_url('help') . '#' . rawurlencode($a->getAttribute('data-help')));
				$icon = 'bi-book';
				$a->setAttribute('class', trim($a->getAttribute('class') . ' tutHelp'));
			}
			$a->setAttribute('target', '_blank');
			$a->setAttribute('rel', 'noopener');
			$a->setAttribute('title', t('tut_new_tab'));
			$i = $dom->createElement('i');
			$i->setAttribute('class', 'bi ' . $icon . ' ms-1 small');
			$i->setAttribute('aria-hidden', 'true');
			$a->appendChild($i);
		}
		foreach (iterator_to_array($xp->query('//div[contains(concat(" ", @class, " "), " tut-task ")]') ?: []) as $d) {
			if (!$d instanceof DOMElement) continue;
			$h = $dom->createElement('div');
			$h->setAttribute('class', 'tutTaskHead');
			$i = $dom->createElement('i');
			$i->setAttribute('class', 'bi bi-hand-index-thumb me-2');
			$h->appendChild($i);
			$h->appendChild($dom->createTextNode(t('tut_task')));
			$d->insertBefore($h, $d->firstChild);
		}
	}

	/** All lessons of the reader in order: id => [chapter index, lesson index]. */
	public static function order(): array
	{
		$o = [];
		foreach (self::chapters() as $ci => $c) foreach ($c['lessons'] as $li => $l) $o[$l['id']] = [$ci, $li];
		return $o;
	}

	// ------------------------------------------------------------------ progress

	public static function progress(): array
	{
		$u = NuAuth::user();
		$p = [];
		try {
			if (!empty($u['demo'])) $p = $_SESSION[self::SESSION] ?? [];
			elseif (!empty($u['globeadmin'])) {
				$v = nu_db()->value('SELECT setting_value FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = ?', [self::SETTING]);
				$p = is_string($v) ? (json_decode($v, true) ?: []) : [];
			} else {
				$p = NuUserJson::get((string)$u['id'], self::JSON_KEY);
			}
		} catch (Throwable) { $p = []; }
		$p = is_array($p) ? $p : [];
		return ['done' => array_values(array_filter((array)($p['done'] ?? []), 'is_string')), 'hidden' => !empty($p['hidden'])];
	}

	public static function save(array $p): void
	{
		$p = ['done' => array_values(array_unique($p['done'])), 'hidden' => (bool)$p['hidden']];
		$u = NuAuth::user();
		if (!empty($u['demo'])) { $_SESSION[self::SESSION] = $p; return; }
		if (!empty($u['globeadmin'])) {
			$db = nu_db();
			NuSchema::ensure();
			$row = ['setting_value' => json_encode($p), 'updated_at' => date('Y-m-d H:i:s'), 'updated_by' => 'globeadmin'];
			if ($db->value('SELECT COUNT(*) FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = ?', [self::SETTING])) $db->update('zzzzsys_nunext_setting', $row, 'zzzzsys_nunext_setting_id', self::SETTING);
			else $db->insert('zzzzsys_nunext_setting', ['zzzzsys_nunext_setting_id' => self::SETTING] + $row);
			return;
		}
		NuUserJson::set((string)$u['id'], self::JSON_KEY, $p);
	}

	/** [done, total] for the lessons this reader sees. */
	public static function counts(?array $p = null): array
	{
		$p ??= self::progress();
		$ids = array_keys(self::order());
		return [count(array_intersect($ids, $p['done'])), count($ids)];
	}

	/** First lesson not done yet ('' when all are done). */
	public static function nextOpen(?array $p = null): string
	{
		$p ??= self::progress();
		foreach (array_keys(self::order()) as $id) if (!in_array($id, $p['done'], true)) return (string)$id;
		return '';
	}

	/** Start page banner: shown until hidden or every lesson is done. */
	public static function banner(): ?array
	{
		if (!self::allowed()) return null;
		try {
			$p = self::progress();
			if ($p['hidden']) return null;
			[$done, $total] = self::counts($p);
			if ($total === 0 || $done >= $total) return null;
			return ['done' => $done, 'total' => $total, 'next' => self::nextOpen($p)];
		} catch (Throwable) {
			return null;
		}
	}
}
