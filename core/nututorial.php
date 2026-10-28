<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * Tutorial (0.8.328): a step-by-step introduction for the globeadmin and users with an admin role – pages/nututorial.php.
 *
 * - Text (English, like the documentation): docs/nunext-tutorial.htm – <article class="tut-chapter"> with <section class="tut-lesson">
 *   (see the comment at the top of that file). Lessons a user with an admin role has no right for are left out (data-right).
 * - Progress per reader: the globeadmin in zzzzsys_nunext_setting (id tutorial_ga), other users in their sus_json (NUNEXT_TUTORIAL),
 *   demo users in the session only. {done: [lesson ids], hidden: bool, track: '' | basic | advanced} – "hidden" hides the start page
 *   banner, "track" is the reader's choice (0.8.622).
 * - Three tracks (0.8.622 / 0.8.635): basic = the essentials, advanced = every lesson, forte = what is different from nuBuilder Forte and
 *   what is new (chapters with data-track="forte" only – they are in no other track). A lesson or chapter with data-level="advanced" is in
 *   the advanced track only; the reader chooses on first opening (choice screen) and switches any time. Progress is shared.
 */
final class NuTutorial
{
	public const FILE = 'docs/nunext-tutorial.htm';
	private const SETTING = 'tutorial_ga';
	private const JSON_KEY = 'NUNEXT_TUTORIAL';
	private const SESSION = 'nu_tutorial';
	public const TRACKS = ['basic', 'advanced', 'forte'];   // forte (0.8.635): "Coming from nuBuilder Forte" – only chapters with data-track="forte"

	/** @var array<string, list<array<string, mixed>>> chapters per track */
	private static array $chapters = [];

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
	 * Chapters with the lessons of this reader in a track (null = the reader's track; not chosen yet = basic).
	 * @return list<array{id: string, title: string, part: string, intro: string, lessons: list<array{id: string, title: string, html: string, advanced: bool}>}>
	 */
	public static function chapters(?string $track = null): array
	{
		$track = self::cleanTrack($track ?? self::track()) ?: 'basic';
		if (isset(self::$chapters[$track])) return self::$chapters[$track];
		$file = NU_ROOT . '/' . self::FILE;
		if (!is_file($file)) return self::$chapters[$track] = [];
		$dom = new DOMDocument();
		libxml_use_internal_errors(true);
		$dom->loadHTML('<?xml encoding="utf-8"?>' . (string)file_get_contents($file), LIBXML_NONET);
		libxml_clear_errors();
		$xp = new DOMXPath($dom);
		self::links($xp);
		$out = [];
		foreach ($xp->query('//article[contains(concat(" ", @class, " "), " tut-chapter ")]') ?: [] as $art) {
			if (!$art instanceof DOMElement || !self::visible($art->getAttribute('data-right'))) continue;
			$artAdv = $art->getAttribute('data-level') === 'advanced';
			if ($artAdv && $track === 'basic') continue;
			if (($art->getAttribute('data-track') === 'forte') !== ($track === 'forte')) continue;   // the nuBuilder Forte track has its own chapters
			$h = $xp->query('./h2', $art)->item(0);
			$intro = $xp->query('./p[contains(@class, "tut-intro")]', $art)->item(0);
			$lessons = [];
			foreach ($xp->query('./section[contains(concat(" ", @class, " "), " tut-lesson ")]', $art) ?: [] as $sec) {
				if (!$sec instanceof DOMElement || !self::visible($sec->getAttribute('data-right'))) continue;
				$adv = $track !== 'forte' && ($artAdv || $sec->getAttribute('data-level') === 'advanced');
				if ($adv && $track === 'basic') continue;
				$h3 = $xp->query('./h3', $sec)->item(0);
				if ($h3) $sec->removeChild($h3);
				$html = '';
				foreach (iterator_to_array($sec->childNodes) as $n) $html .= $dom->saveHTML($n);
				$lessons[] = ['id' => $sec->getAttribute('id'), 'title' => trim((string)$h3?->textContent), 'html' => trim($html), 'advanced' => $adv];
			}
			if (!$lessons) continue;
			$out[] = ['id' => $art->getAttribute('id'), 'title' => trim((string)$h?->textContent), 'part' => $art->getAttribute('data-part'),
				'intro' => $intro ? trim((string)$intro->textContent) : '', 'lessons' => $lessons];
		}
		return self::$chapters[$track] = $out;
	}

	private static function cleanTrack(mixed $t): string
	{
		return is_string($t) && in_array($t, self::TRACKS, true) ? $t : '';
	}

	/** The reader's track: 'basic' | 'advanced' | '' (not chosen yet). */
	public static function track(): string
	{
		return self::progress()['track'];
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

	/** Every lesson the reader may see, in all tracks: id => track it belongs to ('advanced' or 'forte'). */
	public static function allLessons(): array
	{
		return array_map(static fn() => 'advanced', self::order('advanced')) + array_map(static fn() => 'forte', self::order('forte'));
	}

	/** All lessons of the reader in order: id => [chapter index, lesson index] (null = the reader's track). */
	public static function order(?string $track = null): array
	{
		$o = [];
		foreach (self::chapters($track) as $ci => $c) foreach ($c['lessons'] as $li => $l) $o[$l['id']] = [$ci, $li];
		return $o;
	}

	// ------------------------------------------------------------------ progress

	private static ?array $prog = null;

	public static function progress(): array
	{
		if (self::$prog !== null) return self::$prog;
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
		return self::$prog = ['done' => array_values(array_filter((array)($p['done'] ?? []), 'is_string')), 'hidden' => !empty($p['hidden']),
			'track' => self::cleanTrack($p['track'] ?? '')];
	}

	public static function save(array $p): void
	{
		$p = ['done' => array_values(array_unique($p['done'])), 'hidden' => (bool)$p['hidden'], 'track' => self::cleanTrack($p['track'] ?? '')];
		self::$prog = $p;
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

	/** [done, total] for the lessons this reader sees in a track (null = the reader's track). */
	public static function counts(?array $p = null, ?string $track = null): array
	{
		$p ??= self::progress();
		$ids = array_keys(self::order($track ?? ($p['track'] ?: null)));
		return [count(array_intersect($ids, $p['done'])), count($ids)];
	}

	/** First lesson not done yet ('' when all are done). */
	public static function nextOpen(?array $p = null): string
	{
		$p ??= self::progress();
		if ($p['track'] === '') return '';                                 // not chosen yet: the overview asks first
		foreach (array_keys(self::order($p['track'])) as $id) if (!in_array($id, $p['done'], true)) return (string)$id;
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
