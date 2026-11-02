<?php
/*
 * nuBuilder Next – a screenshot tour (15 slides) to look at before installing.
 * Stand-alone page: copy the folder dev/tour/ (index.php, tour.json, img/) to any PHP web server and open it.
 * dev/ itself is never served (deny-all), so it does not work inside an installation.
 * Screenshots: dev/tour/capture.js (run against a test site with the sample applications) – updated only when the owner asks.
 */
declare(strict_types=1);

$info = @json_decode((string)@file_get_contents(__DIR__ . '/tour.json'), true);
$info = is_array($info) ? $info : [];
$version = (string)($info['version'] ?? '');
if ($version === '' && is_readable(__DIR__ . '/../../core/nuapp.php') && preg_match("/define\('NU_VERSION', '([^']+)'\)/", (string)file_get_contents(__DIR__ . '/../../core/nuapp.php'), $m)) $version = $m[1];
$date = (string)($info['date'] ?? '');

// [image(s), title, text, kind] – kind: '' desktop, 'phone' (two phone shots side by side), 'dark'
$slides = [
	[['01-installer'], 'Installation in seven steps', 'A wizard checks the server, connects to MySQL / MariaDB or Microsoft SQL Server, creates the administrator and writes nuconfig.php. Choose the language of the installation on the first page; libraries such as TCPDF and PHPMailer come as signed packs.', ''],
	[['02-home'], 'Start page', 'Tiles for everything the user may open, grouped and searchable (Ctrl+K). Favourites, recently used pages, several saved layouts and a separate start page per access level – each user can personalise it.', ''],
	[['03-users'], 'Users with filters', 'Every system list has a search, filters, saved views, column choice, Excel / CSV export and paging. Users can also be imported from a spreadsheet, with a ready template.', ''],
	[['04-user'], 'The user form', 'Person, login and access on one page: password rules, one-time login links, additional access levels with an end date, admin roles, permission items and custom user fields.', ''],
	[['05-access'], 'Access levels of one form', 'Set from the form itself which access levels may open it and which buttons they get – or switch on “Everyone” so that every level, also new ones, receives the form.', ''],
	[['06-browse'], 'Lists of your forms', 'Browse lists of nuBuilder Forte forms in a modern look: column filters, an advanced search with saved searches, badges for values, column widths by dragging, and export to Excel.', ''],
	[['07-edit'], 'Editing a record', 'An order with its sub-form of items, a lookup for the customer and calculated totals – nuBuilder Forte’s forms, objects and events run as they are. Concurrent edits by two users are detected.', ''],
	[['08-design'], 'Design mode', 'Forms are changed on the form itself: select an object, change its properties on the right, drag, arrange, add tabs and objects. Changes go into a draft that users do not see until it is published.', ''],
	[['09-dashboard'], 'Dashboards', 'Numbers, charts and tables from your own SQL, on the start page of chosen access levels or inside a form – here the support desk of the ticket sample.', ''],
	[['10-notifications'], 'Notifications', 'Messages to everyone or to chosen users, departments, teams or access levels – with read receipts, “must confirm” and an optional e-mail.', ''],
	[['11-audit'], 'Audit trail', 'Every changed field with its old and new value, who changed it and when – as a list and as the history of one record, with restore of single values.', ''],
	[['12-settings'], 'Settings in one place', 'All settings of the installation in sections with a search: languages, branding and themes, sign-in and security, e-mail, backups, updates, global code and nuBuilder Forte’s own settings.', ''],
	[['13-security'], 'Security check', 'A list of what to look at on this server – HTTPS, secret key, passwords, two-factor login, IP access, PHP version and more – each with a link to the place to change it.', ''],
	[['14-phone-a', '14-phone-b'], 'On phones and tablets', 'Every page adapts to the screen: the start page as a list, form lists as cards, forms in one column, menus that fit a thumb.', 'phone'],
	[['15-dark'], 'Dark mode and themes', 'Light, dark or following the system – per user. Themes set colours, buttons and fields for the whole application or per access level.', 'dark'],
];
$img = static function (string $name): string {
	$f = __DIR__ . '/img/' . $name . '.webp';
	return 'img/' . rawurlencode($name) . '.webp' . (is_file($f) ? '?v=' . filemtime($f) : '');
};
$h = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
header('Content-Type: text/html; charset=utf-8');
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>nuBuilder Next tour</title>
<style>
	:root { --bg: #f4f5f8; --card: #fff; --ink: #1d2125; --muted: #5c636a; --line: #dee2e6; --accent: #0d6efd; --warn-bg: #fff3cd; --warn-line: #ffe69c; --warn-ink: #664d03; --shot: #e9ecef; }
	@media (prefers-color-scheme: dark) {
		:root { --bg: #15181b; --card: #212529; --ink: #e9ecef; --muted: #a7b0b8; --line: #373b3e; --accent: #6ea8fe; --warn-bg: #332701; --warn-line: #664d03; --warn-ink: #ffda6a; --shot: #2b3035; }
	}
	* { box-sizing: border-box; }
	html, body { margin: 0; background: var(--bg); color: var(--ink); font: 16px/1.5 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
	a { color: var(--accent); }
	.wrap { max-width: 1240px; margin: 0 auto; padding: 16px; }
	header { display: flex; flex-wrap: wrap; align-items: baseline; gap: 4px 14px; margin: 4px 0 12px; }
	header h1 { font-size: 22px; margin: 0; font-weight: 650; letter-spacing: -.01em; }
	.ver { font-size: 14px; color: var(--muted); }
	.ver b { color: var(--ink); font-weight: 600; }
	/* intro (shown once when the page opens) and the enlarged picture – native <dialog> */
	dialog { border: 0; padding: 0; color: var(--ink); background: transparent; }
	dialog::backdrop { background: rgba(10, 12, 16, .72); }
	.intro { width: min(560px, calc(100vw - 32px)); background: var(--card); border-radius: 14px; box-shadow: 0 20px 60px rgba(0,0,0,.35); }
	.intro .in { padding: 24px 24px 20px; }
	.intro h2 { margin: 0 0 4px; font-size: 20px; font-weight: 650; }
	.intro .ver { display: block; margin-bottom: 14px; }
	.intro .alpha { background: var(--warn-bg); border: 1px solid var(--warn-line); color: var(--warn-ink); border-radius: 10px; padding: 12px 14px; font-size: 15px; }
	.intro .alpha strong { display: block; margin-bottom: 4px; }
	.intro .go { display: flex; justify-content: flex-end; margin-top: 18px; }
	.btn { font: inherit; font-weight: 600; border: 0; border-radius: 8px; padding: 9px 18px; background: var(--accent); color: #fff; cursor: pointer; }
	.btn:focus-visible, .zoom:focus-visible { outline: 3px solid var(--accent); outline-offset: 2px; }
	.shot img { cursor: zoom-in; }
	.lightbox { max-width: 100vw; max-height: 100vh; width: 100vw; height: 100vh; margin: 0; }
	.lightbox[open] { display: flex; align-items: center; justify-content: center; }
	.lightbox img { max-width: calc(100vw - 32px); max-height: calc(100vh - 32px); width: auto; height: auto; border-radius: 8px; box-shadow: 0 10px 40px rgba(0,0,0,.5); cursor: zoom-out; }
	@media (max-width: 640px) {   /* phones: a landscape screenshot at twice the screen width, scrolled sideways */
		.lightbox[open].wide { display: block; overflow: auto; }
		.lightbox.wide img { max-width: none; max-height: none; width: 200vw; margin: 16px; border-radius: 6px; }
	}
	.lightbox .x { position: fixed; top: 12px; right: 12px; width: 40px; height: 40px; border-radius: 50%; border: 0; background: rgba(255,255,255,.92); color: #111; font-size: 22px; line-height: 1; cursor: pointer; }
	.stage { background: var(--card); border: 1px solid var(--line); border-radius: 14px; overflow: hidden; }
	.shot { position: relative; background: var(--shot); display: flex; justify-content: center; align-items: center; gap: 24px; padding: 18px; min-height: 240px; }
	.shot img { display: block; max-width: 100%; max-height: max(320px, calc(100vh - 300px)); width: auto; height: auto; border-radius: 8px; box-shadow: 0 4px 18px rgba(0,0,0,.14); border: 1px solid var(--line); }
	.shot.phone img { max-width: calc(50% - 12px); border-radius: 18px; }
	.nav { position: absolute; top: 50%; transform: translateY(-50%); width: 44px; height: 44px; border-radius: 50%; border: 1px solid var(--line); background: var(--card); color: var(--ink); font-size: 22px; line-height: 1; cursor: pointer; display: grid; place-items: center; box-shadow: 0 2px 8px rgba(0,0,0,.15); }
	.nav:hover { border-color: var(--accent); color: var(--accent); }
	.nav:disabled { opacity: .35; cursor: default; }
	.nav.prev { left: 10px; } .nav.next { right: 10px; }
	.text { display: flex; flex-wrap: wrap; gap: 8px 24px; align-items: flex-start; padding: 16px 20px 18px; border-top: 1px solid var(--line); }
	.text .t { flex: 1 1 520px; }
	.text h2 { font-size: 20px; margin: 0 0 4px; font-weight: 650; }
	.text p { margin: 0; color: var(--muted); max-width: 75ch; }
	.count { font-size: 14px; color: var(--muted); white-space: nowrap; font-variant-numeric: tabular-nums; padding-top: 4px; }
	.tag { display: inline-block; font-size: 12px; font-weight: 600; border-radius: 999px; padding: 1px 9px; margin-left: 8px; vertical-align: 3px; background: var(--shot); color: var(--muted); border: 1px solid var(--line); }
	.thumbs { position: relative; display: flex; gap: 8px; overflow-x: auto; padding: 14px 2px 6px; scrollbar-width: thin; }
	.thumbs button { flex: 0 0 auto; width: 112px; padding: 0; border: 2px solid transparent; border-radius: 8px; background: var(--card); cursor: pointer; overflow: hidden; text-align: left; color: var(--muted); font: 12px/1.3 inherit; }
	.thumbs button img { display: block; width: 100%; height: 66px; object-fit: cover; object-position: top left; }
	.thumbs button span { display: block; padding: 4px 6px 5px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
	.thumbs button[aria-current="true"] { border-color: var(--accent); color: var(--ink); }
	.thumbs button:focus-visible, .nav:focus-visible { outline: 3px solid var(--accent); outline-offset: 2px; }
	footer { font-size: 13px; color: var(--muted); padding: 10px 2px 24px; display: flex; flex-wrap: wrap; justify-content: space-between; gap: 6px 16px; }
	kbd { font: 12px ui-monospace, monospace; border: 1px solid var(--line); border-bottom-width: 2px; border-radius: 4px; padding: 0 5px; background: var(--card); }
	.slide { display: none; } .slide.on { display: block; }
	@media (max-width: 640px) {
		.shot img { max-height: none; }
		.shot { padding: 10px; gap: 10px; min-height: 0; }
		.nav { width: 36px; height: 36px; font-size: 18px; }
		.nav.prev { left: 4px; } .nav.next { right: 4px; }
		.text { padding: 12px 14px 14px; }
		.text h2 { font-size: 18px; }
	}
	@media (prefers-reduced-motion: no-preference) { .slide.on .shot img { animation: in .25s ease-out; } @keyframes in { from { opacity: .4; } to { opacity: 1; } } }
</style>
</head>
<body>
<div class="wrap">
	<header>
		<h1>nuBuilder Next – a short tour</h1>
		<span class="ver"><?= $version !== '' ? 'Screenshots of version <b>' . $h($version) . '</b>' : '' ?><?= $date !== '' ? ' · taken ' . $h($date) : '' ?></span>
	</header>

	<main class="stage" aria-roledescription="carousel" aria-label="Screenshots of nuBuilder Next">
		<?php foreach ($slides as $i => [$imgs, $title, $text, $kind]): ?>
		<section class="slide<?= $i === 0 ? ' on' : '' ?>" id="s<?= $i + 1 ?>" aria-roledescription="slide" aria-label="<?= $h(($i + 1) . ' of ' . count($slides) . ': ' . $title) ?>"<?= $i === 0 ? '' : ' hidden' ?>>
			<div class="shot<?= $kind === 'phone' ? ' phone' : '' ?>">
				<?php foreach ($imgs as $k => $name): ?>
				<img src="<?= $h($img($name)) ?>" alt="<?= $h($title . ($kind === 'phone' ? ($k === 0 ? ' – start page on a phone' : ' – a list on a phone') : '')) ?>" <?= $kind === 'phone' ? 'width="390" height="844"' : 'width="1440" height="900"' ?> <?= $i < 2 ? '' : 'loading="lazy"' ?> decoding="async">
				<?php endforeach; ?>
			</div>
			<div class="text">
				<div class="t">
					<h2><?= $h($title) ?><?= $kind === 'phone' ? '<span class="tag">phone</span>' : ($kind === 'dark' ? '<span class="tag">dark mode</span>' : '') ?></h2>
					<p><?= $h($text) ?></p>
				</div>
				<div class="count"><?= $i + 1 ?> / <?= count($slides) ?></div>
			</div>
		</section>
		<?php endforeach; ?>
	</main>

	<nav class="thumbs" aria-label="All slides">
		<?php foreach ($slides as $i => [$imgs, $title]): ?>
		<button type="button" data-go="<?= $i ?>" aria-current="<?= $i === 0 ? 'true' : 'false' ?>" title="<?= $h($title) ?>"><img src="<?= $h($img($imgs[0])) ?>" alt="" loading="lazy" decoding="async"><span><?= $i + 1 ?>. <?= $h($title) ?></span></button>
		<?php endforeach; ?>
	</nav>

	<footer>
		<span><kbd>←</kbd> <kbd>→</kbd> or swipe to change slides · <kbd>Home</kbd> <kbd>End</kbd> first / last</span>
		<span>Screenshots from a test installation with the sample applications.</span>
	</footer>
</div>
<dialog class="intro" id="intro" aria-labelledby="introT">
	<div class="in">
		<h2 id="introT">nuBuilder Next – a short tour</h2>
		<span class="ver"><?= $version !== '' ? 'Screenshots of version <b>' . $h($version) . '</b>' : '' ?><?= $date !== '' ? ' · taken ' . $h($date) : '' ?></span>
		<div class="alpha" role="note">
			<strong>Alpha version</strong>
			nuBuilder Next is still in development. Everything shown here is subject to change – pages, names and features may look different or work differently in the next version, and some may be removed. Do not use it for important data yet without backups.
		</div>
		<form method="dialog" class="go"><button class="btn" id="introGo" autofocus>Start the tour</button></form>
	</div>
</dialog>
<dialog class="lightbox" id="zoom" aria-label="Enlarged screenshot"><img id="zoomImg" alt=""><button type="button" class="x" id="zoomX" aria-label="Close">×</button></dialog>
<script>
(function () {
	// intro: once each time the page is opened (not remembered); the slides start after it is closed
	var intro = document.getElementById('intro');
	if (intro && intro.showModal) intro.showModal();
	// enlarged picture: click / tap a screenshot; click, Esc or × closes it
	var zoom = document.getElementById('zoom'), zImg = document.getElementById('zoomImg');
	document.querySelectorAll('.shot img').forEach(function (im) {
		im.tabIndex = 0; im.setAttribute('role', 'button'); im.setAttribute('aria-label', 'Enlarge: ' + im.alt);
		var open = function () { if (!zoom.showModal) return; zImg.src = im.currentSrc || im.src; zImg.alt = im.alt; zoom.classList.toggle('wide', im.naturalWidth > im.naturalHeight); zoom.showModal(); zoom.scrollTo(0, 0); };
		im.addEventListener('click', open);
		im.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); open(); } });
	});
	zoom.addEventListener('click', function () { zoom.close(); });
	var modalOpen = function () { return (intro && intro.open) || zoom.open; };
	var slides = document.querySelectorAll('.slide'), thumbs = document.querySelectorAll('.thumbs button'), n = slides.length, cur = 0;
	slides.forEach(function (s, i) {
		var shot = s.querySelector('.shot');
		var p = document.createElement('button'); p.type = 'button'; p.className = 'nav prev'; p.setAttribute('aria-label', 'Previous slide'); p.textContent = '‹';
		var x = document.createElement('button'); x.type = 'button'; x.className = 'nav next'; x.setAttribute('aria-label', 'Next slide'); x.textContent = '›';
		p.disabled = i === 0; x.disabled = i === n - 1;
		p.addEventListener('click', function () { go(cur - 1, true); });
		x.addEventListener('click', function () { go(cur + 1, true); });
		shot.appendChild(p); shot.appendChild(x);
	});
	function go(i, focus) {
		i = Math.max(0, Math.min(n - 1, i));
		if (i === cur) return;
		slides[cur].classList.remove('on'); slides[cur].hidden = true; thumbs[cur].setAttribute('aria-current', 'false');
		cur = i;
		slides[cur].classList.add('on'); slides[cur].hidden = false; thumbs[cur].setAttribute('aria-current', 'true');
		var t = thumbs[cur], box = t.parentNode; box.scrollLeft = t.offsetLeft - (box.clientWidth - t.clientWidth) / 2;   // only sideways – the page itself stays put
		if (history.replaceState) history.replaceState(null, '', '#s' + (cur + 1));
		if (focus) { var b = slides[cur].querySelector(cur === n - 1 ? '.nav.prev' : '.nav.next'); if (b && document.activeElement && document.activeElement.classList.contains('nav')) b.focus(); }
		var nx = slides[cur + 1]; if (nx) nx.querySelectorAll('img[loading=lazy]').forEach(function (im) { im.loading = 'eager'; });
	}
	thumbs.forEach(function (b) { b.addEventListener('click', function () { go(+b.getAttribute('data-go')); }); });
	document.addEventListener('keydown', function (e) {
		if (e.altKey || e.ctrlKey || e.metaKey || modalOpen()) return;
		if (e.key === 'ArrowRight' || e.key === 'PageDown') { go(cur + 1); e.preventDefault(); }
		else if (e.key === 'ArrowLeft' || e.key === 'PageUp') { go(cur - 1); e.preventDefault(); }
		else if (e.key === 'Home') { go(0); e.preventDefault(); }
		else if (e.key === 'End') { go(n - 1); e.preventDefault(); }
	});
	var sx = null, sy = 0, stage = document.querySelector('.stage');
	stage.addEventListener('touchstart', function (e) { if (e.touches.length === 1) { sx = e.touches[0].clientX; sy = e.touches[0].clientY; } }, { passive: true });
	stage.addEventListener('touchend', function (e) {
		if (sx === null || modalOpen()) return;
		var dx = e.changedTouches[0].clientX - sx, dy = e.changedTouches[0].clientY - sy; sx = null;
		if (Math.abs(dx) > 50 && Math.abs(dx) > Math.abs(dy) * 1.5) go(cur + (dx < 0 ? 1 : -1));
	});
	var m = /^#s(\d+)$/.exec(location.hash); if (m) go(+m[1] - 1);
})();
</script>
</body>
</html>
