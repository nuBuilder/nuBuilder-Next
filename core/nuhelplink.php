<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/**
 * F1 help (0.8.528): which page of nuBuilder Next's documentation (?p=help#<id>) belongs to the page that is open.
 * <body data-nu-help="<url>"> (NuCustomCode::bodyAttrs) – assets/js/nuapp.js opens it in a new tab on F1. Pages without an entry
 * (sign-in pages, the help itself, JSON routes) leave F1 to the browser. A new page = an entry here (dev/tests/run.php checks the ids).
 */
final class NuHelpLink
{
	/** route => section id of docs/pages/*.htm */
	public const MAP = [
		'form' => 'forms-use', 'forms' => 'builder', 'builder' => 'builder-work-p', 'formai' => 'new-form-p', 'dataaccess' => 'data-access',
		'formats' => 'formats', 'items' => 'items', 'page' => 'pages',
		'users' => 'users', 'user' => 'users', 'userfields' => 'user-fields', 'import' => 'csv-import',
		'access' => 'access', 'accesslevel' => 'access', 'permissions' => 'permissions', 'adminroles' => 'admin-roles',
		'impersonate' => 'impersonate', 'sessions' => 'sessions', 'security' => 'security-check', 'securitycheck' => 'security-check',
		'log' => 'log', 'audit' => 'audit-trail', 'audithistory' => 'audit-trail', 'notifications' => 'notifications',
		'dashboards' => 'dashboards', 'reports' => 'reports', 'report' => 'reports', 'reportdesigner' => 'reports', 'sqlbuilder' => 'sql-builder',
		'procedures' => 'procedures', 'prompts' => 'prompts', 'email' => 'email', 'mailoauth' => 'email', 'emailtemplates' => 'email-templates',
		'translations' => 'translations', 'files' => 'files', 'filemanager' => 'file-manager', 'cloner' => 'cloner', 'appcloner' => 'appcloner',
		'branding' => 'branding', 'brand' => 'branding', 'settings' => 'settings', 'configfile' => 'configfile', 'globalcode' => 'globalcode',
		'setupwizard' => 'install', 'sample' => 'sample-data', 'inspect' => 'inspect', 'selftest' => 'selftest', 'compat' => 'compat',
		'update' => 'updating-p', 'apiclients' => 'api', 'apiplayground' => 'api-playground', 'debug' => 'debugging-p',
		'password' => 'password', 'account' => 'password', 'tutorial' => 'tutorial-doc', 'listview' => 'list-views',
	];

	/** Section id for the current route ('' = none). The start page: the admins' page for the globeadmin and admin roles, else navigation. */
	public static function anchor(string $route): string
	{
		if ($route === 'home') {
			try {
				$u = NuAuth::user();
				return $u !== null && (!empty($u['globeadmin']) || NuAdmin::any()) ? 'home-page' : 'navigation-p';
			} catch (Throwable) {
				return '';
			}
		}
		return self::MAP[$route] ?? '';
	}

	/** ?p=help#<id> or '' */
	public static function url(string $route): string
	{
		$a = self::anchor($route);
		return $a === '' ? '' : nu_url('help') . '#' . $a;
	}
}
