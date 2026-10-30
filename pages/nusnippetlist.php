<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * Code snippets for one code editor (0.8.714, assets/js/nu/nucodetools.js – tab "Snippets" of the Insert menu):
 * GET ?p=snippetlist&scope=0..3&lang=JS|PHP|SQL|CSS|HTML → {scope, items: [[name, description, code], …], manage, manage_label}.
 * Only for people who work in a code editor (NuSnippets::allowed); read only.
 */
session_write_close();
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
if (!NuSnippets::allowed()) {
	http_response_code(403);
	exit('{"error":"forbidden"}');
}
$scope = (int)($_GET['scope'] ?? 0);
if (!in_array($scope, NuSnippets::SCOPES, true)) $scope = 0;
$lang = strtoupper((string)($_GET['lang'] ?? ''));
exit((string)json_encode(NuSnippets::forEditor($scope, isset(NuSnippets::LANGS[$lang]) ? $lang : ''), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
