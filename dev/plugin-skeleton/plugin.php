<?php
declare(strict_types=1);

/*
 * Example plugin for nuBuilder Next – copy this folder to plugins/<your id>/, change "id" in plugin.json to the folder's name and
 * switch it on in Update → Plugins. $plugin is the plugin API (NuPluginApi); this file runs once per request while the plugin is on.
 * Help → Code reference → Plugins describes every method.
 */

/** @var NuPluginApi $plugin */

// an admin right of its own (Admin roles → group Plugins); the globeadmin always has it
$plugin->right('use', $plugin->t('right_use'));

// a table of its own: created when the plugin is switched on, removed with "Remove … also delete its data"
$plugin->onEnable(function () use ($plugin): void {
	$t = $plugin->table('notes');
	if (!$plugin->db()->tableExists($t)) {
		NuDDL::createTable($t, 'id', [['name' => 'note', 'type' => 'text', 'length' => '200'], ['name' => 'created_by', 'type' => 'text', 'length' => '50'],
			['name' => 'created_at', 'type' => 'datetime']], false);
	}
});

// a page: index.php?p=plugin&id=example&r=main – a string is shown in nuBuilder Next's page frame
$plugin->route('main', function (array $req) use ($plugin) {
	$db = $plugin->db();
	$t = $plugin->table('notes');
	if ($req['method'] === 'POST') {
		$text = trim((string)($req['post']['note'] ?? ''));
		if ($text !== '') $db->insert($t, ['id' => nu_id(), 'note' => mb_substr($text, 0, 200), 'created_by' => (string)($plugin->user()['login'] ?? ''), 'created_at' => date('Y-m-d H:i:s')]);
		nu_redirect($plugin->url('main'));
	}
	$rows = $db->tableExists($t) ? $db->all('SELECT note, created_by, created_at FROM ' . NuDB::ident($t) . ' ORDER BY created_at DESC') : [];
	$greeting = (string)$plugin->get('greeting', $plugin->t('greeting_default'));
	$o = '<div class="card shadow-sm"><div class="card-body"><p class="exPluginGreeting" id="exGreeting">' . h($greeting) . '</p>'
		. '<form method="post" action="' . h($plugin->url('main')) . '" class="d-flex gap-2 mb-3">' . $plugin->csrfField()
		. '<input class="form-control" name="note" id="exNote" maxlength="200" placeholder="' . h($plugin->t('note_ph')) . '">'
		. '<button class="btn btn-primary" id="exAdd">' . h($plugin->t('add')) . '</button></form><ul class="list-group" id="exNotes">';
	foreach ($rows as $r) $o .= '<li class="list-group-item">' . h($r['note']) . ' <span class="small text-body-secondary">' . h($r['created_by']) . '</span></li>';
	return $o . '</ul></div></div>';
}, ['title' => $plugin->t('title'), 'icon' => 'bi-sticky', 'right' => 'use']);

// a JSON answer: index.php?p=plugin&id=example&r=count
$plugin->route('count', fn() => ['notes' => (int)$plugin->db()->value('SELECT COUNT(*) FROM ' . NuDB::ident($plugin->table('notes')))], ['right' => 'use']);

// a tile on the start page and an entry in the user menu
$plugin->tile('main', ['label' => $plugin->t('title'), 'icon' => 'bi-sticky', 'hint' => $plugin->t('hint'), 'route' => 'main', 'right' => 'use']);
$plugin->menu('main', ['label' => $plugin->t('title'), 'icon' => 'bi-sticky', 'route' => 'main', 'right' => 'use']);

// settings: Update → Plugins → Settings
$plugin->settings(
	fn(array $v) => '<div class="mb-3"><label class="form-label" for="exSetGreeting">' . h($plugin->t('set_greeting')) . '</label>'
		. '<input class="form-control" name="plg[greeting]" id="exSetGreeting" maxlength="100" value="' . h((string)($v['greeting'] ?? '')) . '"></div>'
		. '<div class="mb-3"><label class="form-label" for="exSetBlock">' . h($plugin->t('set_block')) . '</label>'
		. '<input class="form-control" name="plg[block]" id="exSetBlock" maxlength="40" value="' . h((string)($v['block'] ?? '')) . '"></div>',
	function (array $in) use ($plugin): ?string {
		$plugin->set('greeting', mb_substr(trim((string)($in['greeting'] ?? '')), 0, 100));
		$plugin->set('block', mb_substr(trim((string)($in['block'] ?? '')), 0, 40));
		return null;
	}
);

// events: refuse a save that contains the blocked word, log every save
$plugin->on('record.before_save', function (array $e) use ($plugin): ?string {
	$word = (string)$plugin->get('block', '');
	if ($word === '') return null;
	foreach ($e['values'] as $v) if (is_string($v) && stripos($v, $word) !== false) return $plugin->t('blocked', $word);
	return null;
});
$plugin->on('record.after_save', function (array $e) use ($plugin): void {
	$plugin->log('record_saved', ['form' => $e['form'], 'record' => $e['record_id']]);
});

// a REST route: GET api.php/v1/plugins/example/notes (scope plugin:example)
$plugin->api('GET', 'notes', fn() => ['notes' => $plugin->db()->all('SELECT note, created_by, created_at FROM ' . NuDB::ident($plugin->table('notes')) . ' ORDER BY created_at DESC')]);

// a task for the server's cron: php nucron.php plugin example count
$plugin->task('count', fn() => 'notes: ' . (int)$plugin->db()->value('SELECT COUNT(*) FROM ' . NuDB::ident($plugin->table('notes'))));

// a script and a style sheet of assets/ on every page
$plugin->js('example.js');
$plugin->css('example.css');
