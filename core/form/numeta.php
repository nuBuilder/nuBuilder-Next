<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/** Metadata of one nuBuilder form: zzzzsys_form + tabs + objects + events + browse columns. */
final class NuFormMeta
{
	public readonly string $id;
	public array $form;
	/** @var array<int,array> tabs ordered by syt_order */
	public array $tabs = [];
	/** @var array<int,array> objects ordered by tab then sob_all_order */
	public array $objects = [];
	/** sob_all_id => object */
	public array $byId = [];
	/** zzzzsys_object_id => [event => js] */
	public array $events = [];
	/** nuBuilder system forms whose browse columns (sbr_display) have a filter unless the form builder says otherwise. */
	public const DEFAULT_FILTERS = ['nunotes' => ['noc_name']];

	/** browse columns ordered by sbr_order */
	public array $browse = [];

	private static array $cache = [];
	/** From a snapshot (form drafts, 0.8.386): zzzzsys_php_id => sph_php; null = read from the database. */
	private ?array $phpRows = null;

	/**
	 * The form for the runtime – the draft when this session previews one (form drafts, 0.8.386); $live: always the live form
	 * (form builder, publish).
	 */
	public static function load(string $formId, bool $live = false): ?self
	{
		if (!$live && class_exists('NuFormDraft') && ($snap = NuFormDraft::previewSnapshot($formId)) !== null) {
			return self::$cache['draft:' . $formId] ??= self::fromSnapshot($snap);
		}
		if (array_key_exists($formId, self::$cache)) return self::$cache[$formId];
		$db   = nu_db();
		$form = $db->one('SELECT * FROM zzzzsys_form WHERE zzzzsys_form_id = ?', [$formId]);
		return self::$cache[$formId] = $form ? new self($form) : null;
	}

	/** A form from a NuFormSnapshot (a draft) – the same as load() gives for the live rows. */
	public static function fromSnapshot(array $snap): self
	{
		return new self((array)$snap['form'], $snap);
	}

	public static function byCode(string $code): ?self
	{
		$id = nu_db()->value('SELECT zzzzsys_form_id FROM zzzzsys_form WHERE sfo_code = ?', [$code]);
		return $id ? self::load((string)$id) : null;
	}

	public static function forget(string $formId): void
	{
		unset(self::$cache[$formId], self::$cache['draft:' . $formId]);
	}

	private function __construct(array $form, ?array $snap = null)
	{
		$db         = nu_db();
		$this->id   = (string)$form['zzzzsys_form_id'];
		$this->form = $form;

		if ($snap !== null) {
			$this->tabs = array_values((array)($snap['tabs'] ?? []));
			usort($this->tabs, static fn($a, $b) => [(int)$a['syt_order'], (string)$a['zzzzsys_tab_id']] <=> [(int)$b['syt_order'], (string)$b['zzzzsys_tab_id']]);
			$this->phpRows = [];
			foreach ($snap['php'] ?? [] as $p) $this->phpRows[(string)$p['zzzzsys_php_id']] = $p['sph_php'] ?? null;
		} else {
			$this->tabs = $db->all('SELECT * FROM zzzzsys_tab WHERE syt_zzzzsys_form_id = ? ORDER BY syt_order, zzzzsys_tab_id', [$this->id]);
		}
		$tabOrder   = [];
		foreach ($this->tabs as $i => $t) $tabOrder[$t['zzzzsys_tab_id']] = $i;

		$objects = $snap !== null ? array_values((array)($snap['objects'] ?? [])) : $db->all('SELECT * FROM zzzzsys_object WHERE sob_all_zzzzsys_form_id = ?', [$this->id]);
		usort($objects, static fn($a, $b) =>
			[$tabOrder[$a['sob_all_zzzzsys_tab_id']] ?? 999, (int)$a['sob_all_order'], (int)$a['sob_all_top'], (int)$a['sob_all_left']]
			<=> [$tabOrder[$b['sob_all_zzzzsys_tab_id']] ?? 999, (int)$b['sob_all_order'], (int)$b['sob_all_top'], (int)$b['sob_all_left']]);

		foreach ($objects as $o) {
			$o['nunext'] = self::nunextJson($o['sob_all_json'] ?? null);
			$o['tab_index'] = $tabOrder[$o['sob_all_zzzzsys_tab_id']] ?? 0;
			$this->objects[] = $o;
			$this->byId[(string)$o['sob_all_id']] = $o;
		}

		if ($objects && $snap !== null) {
			$evs = array_values((array)($snap['events'] ?? []));
			usort($evs, static fn($a, $b) => strcmp((string)$a['zzzzsys_event_id'], (string)$b['zzzzsys_event_id']));
			foreach ($evs as $e) {
				$ev = strtolower(trim((string)$e['sev_event']));
				if ($ev === '') continue;
				$prev = $this->events[$e['sev_zzzzsys_object_id']][$ev] ?? '';
				$this->events[$e['sev_zzzzsys_object_id']][$ev] = ltrim($prev . "\n" . $e['sev_javascript']);
			}
		} elseif ($objects) {
			$ids = array_column($objects, 'zzzzsys_object_id');
			foreach (array_chunk($ids, 500) as $chunk) {
				$in = implode(',', array_fill(0, count($chunk), '?'));
				foreach ($db->all("SELECT sev_zzzzsys_object_id, sev_event, sev_javascript FROM zzzzsys_event WHERE sev_zzzzsys_object_id IN ($in)", $chunk) as $e) {
					$ev = strtolower(trim((string)$e['sev_event']));
					if ($ev === '') continue;
					$prev = $this->events[$e['sev_zzzzsys_object_id']][$ev] ?? '';
					$this->events[$e['sev_zzzzsys_object_id']][$ev] = ltrim($prev . "\n" . $e['sev_javascript']);
				}
			}
		}

		if ($snap !== null) {
			$this->browse = array_values((array)($snap['browse'] ?? []));
			usort($this->browse, static fn($a, $b) => [(int)$a['sbr_order'], (string)$a['zzzzsys_browse_id']] <=> [(int)$b['sbr_order'], (string)$b['zzzzsys_browse_id']]);
		} else {
			$this->browse = $db->all('SELECT * FROM zzzzsys_browse WHERE sbr_zzzzsys_form_id = ? ORDER BY sbr_order, zzzzsys_browse_id', [$this->id]);
		}
		// column filters switched on by default for a few of nuBuilder's own lists (0.8.307) – only while the column has no setting of its
		// own: the form builder can switch them off, and a nuBuilder update (which rewrites its system rows) brings the default back
		foreach ($this->browse as $i => $b) {
			if (in_array(strtolower(trim((string)$b['sbr_display'])), self::DEFAULT_FILTERS[$this->id] ?? [], true)
				&& !array_key_exists('filter', self::nunextJson($b['sbr_json'] ?? null))) {
				$this->browse[$i]['sbr_json'] = self::mergeNunextJson($b['sbr_json'] ?? null, ['filter' => true]);
			}
		}
		if (NuTranslate::active()) $this->translate();
	}

	/** Form pages: texts in the reader's language (zzzzsys_translate), like nuBuilder. The builder keeps the originals. */
	private function translate(): void
	{
		$tr = static fn($s) => NuTranslate::text($s === null ? null : (string)$s);
		$this->form['sfo_description'] = $tr($this->form['sfo_description'] ?? '');
		foreach ($this->tabs as $i => $t) $this->tabs[$i]['syt_title'] = $tr($t['syt_title']);
		foreach ($this->objects as $i => $o) {
			$o['sob_all_label'] = $tr($o['sob_all_label']);
			$this->objects[$i] = $o;
			$this->byId[(string)$o['sob_all_id']] = $o;
		}
		foreach ($this->browse as $i => $b) $this->browse[$i]['sbr_title'] = $tr($b['sbr_title']);
	}

	/** The "nunext" part of a JSON column ([] when absent or invalid). */
	public static function nunextJson(?string $json): array
	{
		if ($json === null || trim($json) === '') return [];
		$d = json_decode($json, true);
		return is_array($d) && isset($d['nunext']) && is_array($d['nunext']) ? $d['nunext'] : [];
	}

	/** Merges $nunext into the existing JSON value without touching other keys. */
	public static function mergeNunextJson(?string $json, array $nunext): ?string
	{
		$d = ($json !== null && trim($json) !== '') ? json_decode($json, true) : [];
		if (!is_array($d)) return $json;                     // foreign non-JSON content: leave it alone
		$d['nunext'] = array_filter(array_merge($d['nunext'] ?? [], $nunext), static fn($v) => $v !== null && $v !== '');
		if (!$d['nunext']) unset($d['nunext']);
		return $d ? json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
	}

	// ----------------------------------------------------------------- convenience

	public function type(): string        { return (string)($this->form['sfo_type'] ?: 'browseedit'); }
	public function table(): string       { return trim((string)$this->form['sfo_table']); }
	public function primaryKey(): string  { return trim((string)$this->form['sfo_primary_key']); }
	public function code(): string        { return (string)$this->form['sfo_code']; }
	public function description(): string { return (string)$this->form['sfo_description']; }
	public function isSystem(): bool      { return str_starts_with($this->id, 'nu'); }
	public function hasBrowse(): bool     { return in_array($this->type(), ['browse', 'browseedit'], true); }
	public function hasEdit(): bool       { return in_array($this->type(), ['edit', 'browseedit', 'launch'], true); }
	public function isPage(): bool        { return $this->type() === 'page'; }   // form type "page" (0.8.387, core/nupage.php)

	public function settings(): array     { return self::nunextJson($this->form['sfo_json'] ?? null); }

	/** absolute | auto | grid (default absolute = like nuBuilder) */
	public function layout(): string
	{
		$l = (string)($this->settings()['layout'] ?? 'absolute');
		return in_array($l, ['absolute', 'auto', 'grid'], true) ? $l : 'absolute';
	}

	public function objectsOfTab(string $tabId): array
	{
		return array_values(array_filter($this->objects, static fn($o) => (string)$o['sob_all_zzzzsys_tab_id'] === $tabId));
	}

	public function php(string $suffix): ?string
	{
		return $this->phpById($this->id . '_' . $suffix);
	}

	/** PHP of an object, e.g. a lookup's After Browse code (<object id>_AB) – from the draft in a preview. */
	public function objectPhp(string $objectId, string $suffix): ?string
	{
		return $this->phpById($objectId . '_' . $suffix);
	}

	private function phpById(string $id): ?string
	{
		$code = $this->phpRows !== null ? ($this->phpRows[$id] ?? null) : nu_db()->value('SELECT sph_php FROM zzzzsys_php WHERE zzzzsys_php_id = ?', [$id]);
		return ($code !== null && trim((string)$code) !== '') ? (string)$code : null;
	}

	/** An object by its zzzzsys_object_id. */
	public function object(string $objectId): ?array
	{
		foreach ($this->objects as $o) if ((string)$o['zzzzsys_object_id'] === $objectId) return $o;
		return null;
	}

	/** Built from a draft (preview or builder) – not the live form. */
	public function isDraft(): bool { return $this->phpRows !== null; }

	/** First / second part of sfo_breadcrumb_title ("#field#|New record"). */
	public function breadcrumb(bool $new, array $hash): string
	{
		$parts = explode('|', (string)$this->form['sfo_breadcrumb_title']);
		$t     = $new ? ($parts[1] ?? '') : $parts[0];
		$t     = trim(NuHash::replace($t, $hash));
		if ($t === '' || preg_match('/^#[^#]+#$/', $t)) $t = $new ? t('form_new_record') : $this->description();
		return $t;
	}
}
