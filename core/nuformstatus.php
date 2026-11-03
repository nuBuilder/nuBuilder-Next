<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * Status of a form (0.8.808) – the column zzzzsys_form.sfo_status (shared with nuBuilder Forte):
 *   0 Draft      – designers only until published (core/form/nuformdraft.php)
 *   1 Active     – also empty / NULL
 *   2 Maintenance – nuBuilder Forte's "Disabled": users cannot open the form; the globeadmin and form designers (save right on nuform) can
 *   3 Archived   – as Maintenance for users (nuBuilder Forte refuses every status but 1 to users who are not the globeadmin)
 * The maintenance message and an optional "until" time are nuBuilder Next's: setting row 'form_maintenance' {form id: {text, until, by, at}}.
 * Maintenance is an operational switch: it changes the live form at once, also while a draft is open (NuFormDraft::publish keeps it).
 */
final class NuFormStatus
{
	public const DRAFT = '0';
	public const ACTIVE = '1';
	public const MAINT = '2';
	public const ARCHIVED = '3';
	public const ALL = [self::ACTIVE, self::DRAFT, self::MAINT, self::ARCHIVED];
	public const KEY = 'form_maintenance';
	public const TEXT_LEN = 500;
	/** @var array<string, string>|null form id => status (only forms that are not Active) */
	private static ?array $map = null;
	private static ?array $info = null;

	public static function norm(mixed $v): string
	{
		$v = trim((string)$v);
		return in_array($v, self::ALL, true) ? $v : self::ACTIVE;
	}

	/** Status of a form by id (cached for the request). */
	public static function of(string $formId): string
	{
		if (self::$map === null) {
			self::$map = [];
			try {
				foreach (nu_db()->all("SELECT zzzzsys_form_id, sfo_status FROM zzzzsys_form WHERE sfo_status IN ('0', '2', '3')") as $r) self::$map[(string)$r['zzzzsys_form_id']] = (string)$r['sfo_status'];
			} catch (Throwable) {
			}
		}
		return self::$map[$formId] ?? self::ACTIVE;
	}

	public static function forget(): void { self::$map = null; self::$info = null; }

	/** May open forms in Maintenance / Archived: the globeadmin and form designers. */
	public static function bypass(): bool
	{
		$u = NuAuth::user();
		return $u !== null && (!empty($u['globeadmin']) || NuAcl::can(NuAcl::FORM_FORM, 'save'));
	}

	/** Closed for the current user? (Draft keeps its own rule: designers with the view right on nuform.) */
	public static function blocked(string $status): bool
	{
		return in_array($status, [self::MAINT, self::ARCHIVED], true) && !self::bypass();
	}

	/** @return array<string, array{text: string, until: string, by: string, at: string}> */
	public static function infos(): array
	{
		if (self::$info !== null) return self::$info;
		$out = [];
		try {
			$v = nu_setting_value(self::KEY);
			$d = is_string($v) ? json_decode($v, true) : null;
			if (is_array($d)) foreach ($d as $fid => $c) if (is_array($c)) $out[(string)$fid] = self::cleanInfo($c);
		} catch (Throwable) {
		}
		return self::$info = $out;
	}

	public static function info(string $formId): ?array { return self::infos()[$formId] ?? null; }

	private static function cleanInfo(array $c): array
	{
		return ['text' => mb_substr(trim((string)($c['text'] ?? '')), 0, self::TEXT_LEN), 'until' => self::cleanUntil((string)($c['until'] ?? '')),
			'by' => mb_substr((string)($c['by'] ?? ''), 0, 50), 'at' => (string)($c['at'] ?? '')];
	}

	/** 'Y-m-d H:i' or '' (accepts the value of <input type="datetime-local">). */
	public static function cleanUntil(string $v): string
	{
		$v = trim(str_replace('T', ' ', $v));
		if ($v === '') return '';
		$d = DateTime::createFromFormat('Y-m-d H:i', substr($v, 0, 16));
		return $d ? $d->format('Y-m-d H:i') : '';
	}

	/** The "until" time for people, only while it lies ahead. */
	public static function untilText(?array $info): string
	{
		$u = (string)($info['until'] ?? '');
		if ($u === '' || ($ts = strtotime($u)) === false || $ts <= time()) return '';
		return date(nu_dfmt('d.m.Y') . ' H:i', $ts);
	}

	/** Title and text of the page users see instead of the form. */
	public static function message(string $formId, string $status): array
	{
		if ($status === self::ARCHIVED) return [t('form_st_archived_title'), t('form_st_archived_text')];
		$i = self::info($formId);
		$parts = [$i && $i['text'] !== '' ? $i['text'] : t('form_st_maint_text')];
		if (($u = self::untilText($i)) !== '') $parts[] = t('form_st_maint_until', $u);
		return [t('form_st_maint_title'), implode(" · ", $parts)];
	}

	/** Badge for start page tiles / side bar: [text, Bootstrap colour] or null. */
	public static function badge(string $formId): ?array
	{
		return self::of($formId) === self::MAINT ? [t('builder_status_2'), 'warning'] : null;
	}

	/** Form page / form API: stops a closed form. $json = answer as nu_api_error does. */
	public static function gate(string $formId, string $status, bool $json = false): void
	{
		if (!self::blocked($status)) return;
		[$title, $text] = self::message($formId, $status);
		if ($json) {
			http_response_code(503);
			header('Content-Type: application/json; charset=utf-8');
			exit((string)json_encode(['ok' => false, 'code' => 'form_maintenance', 'errors' => [['message' => $title . ' – ' . $text, 'field' => '']]], JSON_UNESCAPED_UNICODE));
		}
		http_response_code($status === self::MAINT ? 503 : 403);
		if ($status === self::MAINT) header('Retry-After: 600');
		nu_fatal($title, $text, true);
	}

	/** May the current user switch maintenance on / off? (the same people who may change a form's status) */
	public static function canChange(): bool
	{
		return NuFormDraft::canEdit() && !NuImpersonate::readOnly() && !NuDemo::active();
	}

	/**
	 * Maintenance on ($on) or off for forms – the live rows at once (also while a draft is open); off = Active.
	 * @param list<string> $ids
	 * @return list<string> ids changed
	 */
	public static function setMaintenance(array $ids, bool $on, string $text = '', string $until = ''): array
	{
		if (!self::canChange()) throw new RuntimeException(t('err_forbidden'));
		$u = NuAuth::user();
		$by = (string)($u['login'] ?? '');
		$db = nu_db();
		$infos = self::infos();
		$done = [];
		foreach (array_unique(array_map('strval', $ids)) as $id) {
			if ($id === '' || str_starts_with($id, 'nu')) continue;              // nuBuilder's own forms never
			$row = $db->one('SELECT sfo_code, sfo_status FROM zzzzsys_form WHERE zzzzsys_form_id = ?', [$id]);
			if (!$row) continue;
			$cur = self::norm($row['sfo_status']);
			if ($on) {
				if ($cur === self::DRAFT || $cur === self::ARCHIVED) continue;      // nothing for users to open anyway
				if ($cur !== self::MAINT) $db->update('zzzzsys_form', ['sfo_status' => self::MAINT], 'zzzzsys_form_id', $id);
				$infos[$id] = self::cleanInfo(['text' => $text, 'until' => $until, 'by' => $by, 'at' => date('Y-m-d H:i:s')]);
			} else {
				if ($cur !== self::MAINT) { unset($infos[$id]); continue; }
				$db->update('zzzzsys_form', ['sfo_status' => self::ACTIVE], 'zzzzsys_form_id', $id);
				unset($infos[$id]);
			}
			NuFormMeta::forget($id);
			NuLog::write($on ? 'form_maintenance_on' : 'form_maintenance_off', (string)$row['sfo_code'], $id, $on ? ['text' => mb_substr($text, 0, 200), 'until' => self::cleanUntil($until)] : []);
			$done[] = $id;
		}
		self::saveInfos($infos, $by);
		self::forget();
		return $done;
	}

	/** Messages of forms that are no longer in maintenance (status changed elsewhere, form deleted) are dropped – housekeeping. */
	public static function purge(): int
	{
		$infos = self::infos();
		if (!$infos) return 0;
		$keep = [];
		foreach ($infos as $id => $i) if (self::of((string)$id) === self::MAINT) $keep[$id] = $i;
		if (count($keep) === count($infos)) return 0;
		self::saveInfos($keep, 'housekeeping');
		return count($infos) - count($keep);
	}

	private static function saveInfos(array $infos, string $by): void
	{
		NuSchema::ensure();
		$db = nu_db();
		$row = ['setting_value' => (string)json_encode((object)$infos, JSON_UNESCAPED_UNICODE), 'updated_at' => date('Y-m-d H:i:s'), 'updated_by' => mb_substr($by, 0, 50)];
		nu_setting_save(self::KEY, $row, $db);
		self::$info = null;
	}

	/** The dialog's data element + script (forms list, form page, design mode). */
	public static function clientHtml(): string
	{
		$keys = ['fms_title', 'fms_forms', 'fms_text', 'fms_until', 'fms_until_hint', 'fms_who', 'fms_start', 'fms_end', 'fms_started', 'fms_ended', 'fms_none_chosen',
			'form_st_maint_text', 'forms_status_failed', 'builder_status_0', 'builder_status_1', 'builder_status_2', 'builder_status_3', 'save', 'cancel', 'close', 'search'];
		$tx = [];
		foreach ($keys as $k) $tx[$k] = t($k);
		return '<div id="nuFmsData" hidden data-url="' . h(nu_url('formstatus')) . '" data-csrf="' . h(nu_csrf_token()) . '" data-t="' . h((string)json_encode($tx, JSON_UNESCAPED_UNICODE)) . '"></div>'
			. '<script src="' . h(nu_asset('assets/js/nu/nuformmaint.js')) . '"></script>';
	}

	/** For the designers' notice on the form page and design mode: null when the form is not in maintenance. */
	public static function notice(string $formId): ?array
	{
		if (self::of($formId) !== self::MAINT) return null;
		$i = self::info($formId) ?? ['text' => '', 'until' => '', 'by' => '', 'at' => ''];
		return ['text' => $i['text'], 'until' => $i['until'], 'until_text' => self::untilText($i), 'by' => $i['by']];
	}
}
