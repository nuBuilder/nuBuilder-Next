<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * Secrets at rest (0.8.1326): one place for the encryption of stored secrets with $nuConfigSecretKey (libsodium secretbox).
 *
 *   Format  "enc:" + base64(nonce + box), key = sha256(<context> . $nuConfigSecretKey); without a key (or libsodium) "plain:" + value
 *           (2FA secrets of older versions: the value as is). The contexts keep the formats the features used before:
 *           '' = 2FA secrets, OAuth mail login, single sign-on · 'webhook|' · 'ai|' · 'smtp|' (the SMTP password).
 *   Places  places() – every stored secret nuBuilder Next encrypts; status() counts encrypted / plain / unreadable values, rekey()
 *           decrypts each with the old key and encrypts it with the new one in ONE transaction (nucron.php secrets …, the Security
 *           check's "Encrypt now" = sealPlain()).
 *   SMTP    nuBuilder Forte reads set_smtp_password of zzzzsys_setup in plain text to send mail, so it is never encrypted there.
 *           nuBuilder Next keeps its own encrypted copy (setting smtp_secret {pw, only}) and reads that first; "only" (E-mail settings,
 *           opt-in) leaves the shared column empty – Forte then cannot send mail from this database.
 *   HMAC    API client secrets and auto login links are HMACs with the key: a new key makes them invalid (status() names them).
 */
final class NuSecret
{
	public const SMTP_SETTING = 'smtp_secret';
	private const PLACES = ['totp', 'mail_oauth', 'sso', 'webhook', 'ai', 'smtp'];

	public static function key(): string
	{
		return (string)nu_cfg('nuConfigSecretKey');
	}

	/** Encryption is possible: a key and libsodium. */
	public static function available(?string $key = null): bool
	{
		return ($key ?? self::key()) !== '' && function_exists('sodium_crypto_secretbox');
	}

	/** Encrypts $s ($key null = the key of nuconfig.php); without a key "plain:" + $s ($rawPlain: $s as is). */
	public static function seal(string $s, string $ctx = '', ?string $key = null, bool $rawPlain = false): string
	{
		$key ??= self::key();
		if (!self::available($key)) return $rawPlain ? $s : 'plain:' . $s;
		$nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
		return 'enc:' . base64_encode($nonce . sodium_crypto_secretbox($s, $nonce, hash('sha256', $ctx . $key, true)));
	}

	/** The value of a stored secret; '' when it cannot be decrypted (other key, no key, broken). Not "enc:" = stored as plain text. */
	public static function open(string $s, string $ctx = '', ?string $key = null): string
	{
		return self::tryOpen($s, $ctx, $key) ?? '';
	}

	/** As open(), but null when an encrypted value cannot be decrypted. */
	public static function tryOpen(string $s, string $ctx = '', ?string $key = null): ?string
	{
		if (str_starts_with($s, 'plain:')) return substr($s, 6);
		if (!str_starts_with($s, 'enc:')) return $s;
		$key ??= self::key();
		$raw = base64_decode(substr($s, 4), true);
		if ($key === '' || $raw === false || !function_exists('sodium_crypto_secretbox_open') || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) return null;
		$out = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), hash('sha256', $ctx . $key, true));
		return $out === false ? null : $out;
	}

	public static function isSealed(string $s): bool
	{
		return str_starts_with($s, 'enc:');
	}

	// ================================================================= SMTP password

	/** nuBuilder Next's copy of the SMTP password: {pw (sealed), only (bool: not in zzzzsys_setup)}. */
	public static function smtp(): array
	{
		try {
			$raw = nu_setting_value(self::SMTP_SETTING);
		} catch (Throwable) {
			$raw = null;
		}
		$v = is_string($raw) ? json_decode($raw, true) : null;
		$v = is_array($v) ? $v : [];
		return ['pw' => (string)($v['pw'] ?? ''), 'only' => !empty($v['only'])];
	}

	/** The SMTP password nuBuilder Next sends with: its own copy, else the shared column. */
	public static function smtpPassword(string $shared): string
	{
		$c = self::smtp();
		if ($c['pw'] !== '') {
			$p = self::tryOpen($c['pw'], 'smtp|');
			if ($p !== null) return $p;
		}
		return $shared;
	}

	/**
	 * Stores the SMTP password (null = unchanged, '' = none) and whether it is kept only by nuBuilder Next.
	 * @return string|null the value for zzzzsys_setup.set_smtp_password (null = leave the column as it is)
	 */
	public static function saveSmtp(?string $password, bool $only, string $shared): ?string
	{
		$cur = self::smtp();
		$plain = $password ?? self::smtpPassword($shared);
		if (!self::available()) $only = false;                   // without a key nothing can be kept encrypted
		$pw = $plain === '' || !self::available() ? '' : self::seal($plain, 'smtp|');
		if ($pw === '' && $cur['pw'] === '' && !$only && !$cur['only'] && $password === null) return null;   // nothing of ours, nothing changes
		NuSchema::ensure();
		nu_setting_save(self::SMTP_SETTING, ['setting_value' => json_encode(['pw' => $pw, 'only' => $only], JSON_UNESCAPED_SLASHES),
			'updated_at' => date('Y-m-d H:i:s'), 'updated_by' => (string)(NuAuth::user()['login'] ?? 'cli')]);
		return $only ? '' : $plain;
	}

	// ================================================================= places, status, re-encryption

	/**
	 * Every stored secret: list of [place, ref, ctx, stored value]. 2FA secrets of older versions have no "plain:" prefix ($rawPlain).
	 * @return list<array{place: string, ref: string, ctx: string, value: string}>
	 */
	public static function items(): array
	{
		NuSchema::ensure();
		$db = nu_db();
		$out = [];
		$add = static function (string $place, string $ref, string $ctx, mixed $v) use (&$out) {
			$v = (string)($v ?? '');
			if ($v !== '' && $v !== 'plain:') $out[] = ['place' => $place, 'ref' => $ref, 'ctx' => $ctx, 'value' => $v];
		};
		if ($db->tableExists('zzzzsys_nunext_user_2fa'))
			foreach ($db->all('SELECT zzzzsys_nunext_user_2fa_id AS i, totp_secret AS v FROM zzzzsys_nunext_user_2fa') as $r) $add('totp', (string)$r['i'], '', $r['v']);
		foreach (self::settingJson(NuMailOAuth::SETTING) as $k => $v) if (in_array($k, ['client_secret', 'refresh_token', 'access_token'], true)) $add('mail_oauth', $k, '', $v);
		$sso = self::settingJson(NuSso::KEY);
		$add('sso', 'client_secret', '', $sso['client_secret'] ?? '');
		if ($db->tableExists(NuWebhook::T))
			foreach ($db->all('SELECT ' . NuWebhook::T . '_id AS i, secret AS v FROM ' . NuWebhook::T) as $r) $add('webhook', (string)$r['i'], 'webhook|', $r['v']);
		if ($db->tableExists(NuAiProvider::T))
			foreach ($db->all('SELECT ' . NuAiProvider::T . '_id AS i, api_key AS v FROM ' . NuAiProvider::T) as $r) $add('ai', (string)$r['i'], 'ai|', $r['v']);
		$add('smtp', 'pw', 'smtp|', self::smtp()['pw']);
		return $out;
	}

	/**
	 * Counts per place: sealed (encrypted), plain, bad (cannot be decrypted with the key), locked (AI keys behind a password).
	 * Plus the shared SMTP password (plain in zzzzsys_setup for nuBuilder Forte) and the HMAC users of the key.
	 */
	public static function status(?string $key = null): array
	{
		$p = array_fill_keys(self::PLACES, ['sealed' => 0, 'plain' => 0, 'bad' => 0, 'locked' => 0]);
		foreach (self::items() as $it) {
			$s = &$p[$it['place']];
			if (str_starts_with($it['value'], 'pw:')) $s['locked']++;
			elseif (!self::isSealed($it['value'])) $s['plain']++;
			elseif (self::tryOpen($it['value'], $it['ctx'], $key) === null) $s['bad']++;
			else $s['sealed']++;
			unset($s);
		}
		$shared = '';
		try {
			$shared = NuMailer::setupValue('set_smtp_password');
		} catch (Throwable) {
		}
		$clients = 0;
		try {
			if (nu_db()->tableExists(NuApiClient::CLIENT)) $clients = (int)nu_db()->value('SELECT COUNT(*) FROM ' . NuApiClient::CLIENT);
		} catch (Throwable) {
		}
		$sum = ['sealed' => 0, 'plain' => 0, 'bad' => 0, 'locked' => 0];
		foreach ($p as $c) foreach ($c as $k => $n) $sum[$k] += $n;
		return ['key' => ($key ?? self::key()) !== '', 'sodium' => function_exists('sodium_crypto_secretbox'), 'places' => $p, 'total' => $sum,
			'smtp_shared' => $shared !== '', 'smtp_only' => self::smtp()['only'], 'api_clients' => $clients];
	}

	/** Encrypts the secrets stored as plain text with the key of nuconfig.php (or $key). @return int count */
	public static function sealPlain(?string $key = null): int
	{
		$key ??= self::key();
		if (!self::available($key)) return 0;
		return self::rewrite(static fn(array $it) => self::isSealed($it['value']) || str_starts_with($it['value'], 'pw:') ? null : self::seal(self::open($it['value']), $it['ctx'], $key));
	}

	/**
	 * Re-encrypts every secret: decrypted with $old, encrypted with $new (plain values too); AI keys behind a password are left (they
	 * need the key again after the change). Nothing is written when a value cannot be decrypted, unless $force (those are dropped).
	 * $then runs inside the transaction (writing nuconfig.php) – an exception there puts everything back.
	 * @return array{ok: bool, changed: int, bad: list<string>, locked: int}
	 */
	public static function rekey(string $old, string $new, bool $dry = false, bool $force = false, ?callable $then = null): array
	{
		if (!self::available($new)) throw new RuntimeException('no new key / libsodium');
		$bad = [];
		$locked = 0;
		foreach (self::items() as $it) {
			if (str_starts_with($it['value'], 'pw:')) { $locked++; continue; }
			if (self::isSealed($it['value']) && self::tryOpen($it['value'], $it['ctx'], $old) === null) $bad[] = $it['place'] . ':' . $it['ref'];
		}
		if (($bad && !$force) || $dry) return ['ok' => !$bad, 'changed' => 0, 'bad' => $bad, 'locked' => $locked];
		$n = self::rewrite(static function (array $it) use ($old, $new) {
			if (str_starts_with($it['value'], 'pw:')) return null;
			$v = self::tryOpen($it['value'], $it['ctx'], $old);
			return $v === null ? '' : self::seal($v, $it['ctx'], $new);
		}, $then);
		return ['ok' => true, 'changed' => $n, 'bad' => $bad, 'locked' => $locked];
	}

	/** Writes the new values ($fn: item → new stored value, null = unchanged) in one transaction. @return int changed */
	private static function rewrite(callable $fn, ?callable $then = null): int
	{
		$db = nu_db();
		return (int)$db->transaction(static function (NuDB $db) use ($fn, $then) {
			$n = 0;
			$json = [];                                          // settings rows changed key by key
			foreach (self::items() as $it) {
				$v = $fn($it);
				if ($v === null || $v === $it['value']) continue;
				$n++;
				switch ($it['place']) {
					case 'totp': $db->update('zzzzsys_nunext_user_2fa', ['totp_secret' => $v], 'zzzzsys_nunext_user_2fa_id', $it['ref']); break;
					case 'webhook': $db->update(NuWebhook::T, ['secret' => $v], NuWebhook::T . '_id', $it['ref']); break;
					case 'ai': $db->update(NuAiProvider::T, ['api_key' => $v], NuAiProvider::T . '_id', $it['ref']); break;
					case 'mail_oauth': $json[NuMailOAuth::SETTING][$it['ref']] = $v; break;
					case 'sso': $json[NuSso::KEY][$it['ref']] = $v; break;
					case 'smtp': $json[self::SMTP_SETTING][$it['ref']] = $v; break;
				}
			}
			foreach ($json as $id => $set) {
				$cur = self::settingJson($id);
				foreach ($set as $k => $v) $cur[$k] = $v;
				nu_setting_save($id, ['setting_value' => json_encode($cur, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'updated_at' => date('Y-m-d H:i:s'),
					'updated_by' => (string)(NuAuth::user()['login'] ?? 'cli')], $db);
			}
			if ($then) $then();
			return $n;
		});
	}

	private static function settingJson(string $id): array
	{
		try {
			$raw = nu_setting_value($id);
		} catch (Throwable) {
			return [];
		}
		$v = is_string($raw) ? json_decode($raw, true) : null;
		return is_array($v) ? $v : [];
	}
}
