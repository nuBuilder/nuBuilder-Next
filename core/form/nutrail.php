<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * Breadcrumb trails across forms (nuBuilder "new breadcrumb" / "current breadcrumb").
 * A trail is a list of ['title' => …, 'url' => …] kept in the session under a short key; pages pass the key as &bt=.
 * Home is not part of a trail.
 */
final class NuTrail
{
	private const MAX = 200;

	/** Stores a trail and returns its key. */
	public static function store(array $trail): string
	{
		$trail = array_values(array_map(static fn($c) => ['title' => (string)$c['title'], 'url' => (string)$c['url']], array_slice($trail, -15)));
		$key = substr(hash('sha256', json_encode($trail)), 0, 12);
		$all = $_SESSION['nu_bt'] ?? [];
		unset($all[$key]);
		$all[$key] = $trail;
		if (count($all) > self::MAX) $all = array_slice($all, -self::MAX, null, true);
		$_SESSION['nu_bt'] = $all;
		return $key;
	}

	/** The trail of a key, null when unknown (then the page builds its default breadcrumbs). */
	public static function get(?string $key): ?array
	{
		if ($key === null || !preg_match('/^[0-9a-f]{12}$/', $key)) return null;
		return $_SESSION['nu_bt'][$key] ?? null;
	}

	/** URL of the current request without one-off parameters. */
	public static function currentUrl(): string
	{
		$q = $_GET;
		unset($q['saved']);
		return 'index.php?' . http_build_query($q);
	}
}
