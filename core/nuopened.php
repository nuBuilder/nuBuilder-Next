<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * "Last opened" (0.8.1117): when a person last opened a form in design mode, a procedure, a report or a SQL query – one row per person and
 * object in zzzzsys_nunext_opened, written when the page is opened. The lists of these objects get the sortable column "Last opened" and the
 * view "Recently opened" (NuListDefs). Personal: nobody sees another person's order. Rows older than KEEP_DAYS go (housekeeping).
 */
final class NuOpened
{
	public const TABLE = 'zzzzsys_nunext_opened';
	public const KINDS = ['form', 'procedure', 'report', 'select'];
	public const KEEP_DAYS = 400;

	/** The person the rows belong to: the user id, the login for the globeadmin; '' = nobody (not signed in, demo, a name SQL cannot take as is). */
	public static function who(): string
	{
		try {
			$u = NuAuth::user();
		} catch (Throwable) {
			return '';
		}
		if (!$u || !empty($u['demo'])) return '';
		$w = (string)($u['id'] ?? '') !== '' ? (string)$u['id'] : (string)($u['login'] ?? '');
		return preg_match('/^[A-Za-z0-9_.@\-]{1,50}$/', $w) ? $w : '';
	}

	/** Remembers that the signed-in person opened this object now. Never fails the page; while acting as another user nothing is written. */
	public static function touch(string $kind, string $ref): void
	{
		static $done = [];
		if (!in_array($kind, self::KINDS, true) || $ref === '' || strlen($ref) > 60 || isset($done[$kind . '|' . $ref])) return;
		$done[$kind . '|' . $ref] = true;
		$who = self::who();
		if ($who === '') return;
		try {
			if (class_exists('NuImpersonate') && NuImpersonate::readOnly()) return;
			$db = nu_db();
			if (!$db->tableExists(self::TABLE)) return;
			$id = substr(sha1($who . '|' . $kind . '|' . $ref), 0, 25);
			$now = date('Y-m-d H:i:s');
			$n = $db->run('UPDATE ' . self::TABLE . ' SET opened_at = ? WHERE ' . self::TABLE . '_id = ?', [$now, $id])->rowCount();
			if ($n === 0 && !$db->value('SELECT COUNT(*) FROM ' . self::TABLE . ' WHERE ' . self::TABLE . '_id = ?', [$id])) {
				$db->insert(self::TABLE, [self::TABLE . '_id' => $id, 'user_id' => $who, 'kind' => $kind, 'ref' => $ref, 'opened_at' => $now]);
			}
		} catch (Throwable) {
			// a convenience: the page opens without it
		}
	}

	/** SQL expression: when the signed-in person last opened the row whose id is $idExpr (NULL = never). Usable in SELECT and ORDER BY. */
	public static function sql(string $kind, string $idExpr): string
	{
		$who = self::who();
		$db = nu_db();
		if ($who === '' || !in_array($kind, self::KINDS, true) || !$db->tableExists(self::TABLE)) return 'NULL';
		return '(SELECT MAX(o.opened_at) FROM ' . self::TABLE . " o WHERE o.user_id = '" . $who . "' AND o.kind = '" . $kind . "' AND " . $db->sameText('o.ref', $idExpr) . ')';
	}

	/** The column "Last opened" of a list (NuListDefs): date and time, empty when never opened. */
	public static function column(string $kind, string $idExpr): array
	{
		$sql = self::sql($kind, $idExpr);
		return ['label' => t('lv_last_opened'), 'select' => ['lv_opened' => $sql], 'sort' => $sql, 'class' => 'small text-nowrap text-body-secondary',
			'text' => static fn($r) => ($ts = strtotime((string)($r['lv_opened'] ?? ''))) ? date(nu_dfmt('d.m.Y H:i'), $ts) : ''];
	}

	/** Housekeeping: rows not touched for KEEP_DAYS. */
	public static function purge(): int
	{
		$db = nu_db();
		if (!$db->tableExists(self::TABLE)) return 0;
		return $db->run('DELETE FROM ' . self::TABLE . ' WHERE opened_at < ?', [date('Y-m-d H:i:s', time() - self::KEEP_DAYS * 86400)])->rowCount();
	}
}
