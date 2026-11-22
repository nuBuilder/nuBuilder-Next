<?php
declare(strict_types=1);

define('NU_ROOT', dirname(__DIR__));
define('NU_VERSION', '0.8.1369');
// third-party code: vendor/bundled/ comes with nunext.zip (an update replaces it), vendor/packs/ = library packs (NuLibs,
// an update never touches them). Bundled paths only through these constants.
define('NU_VENDOR', 'vendor/bundled');
define('NU_PACKS', 'vendor/packs');
define('NU_BS', NU_VENDOR . '/bootstrap-5.3.9');
define('NU_BI', NU_VENDOR . '/bootstrap-icons-1.13.2');
define('NU_APEXCHARTS', NU_VENDOR . '/apexcharts-4.7.0');   // loaded on form pages when $nuConfigIncludeApexCharts is on (as nuBuilder)
define('NU_FULLCALENDAR', NU_VENDOR . '/fullcalendar-6.1.22');   // calendars: list view, calendar objects, dashboard widgets – assets/js/nu/nucalendar.js
define('NU_TOMSELECT', NU_VENDOR . '/tom-select-2.7.0');
define('NU_CODEMIRROR', NU_VENDOR . '/codemirror-6.43.15');
define('NU_QRCODE', NU_VENDOR . '/qrcode-generator-2.0.4');
define('NU_ACORN', NU_VENDOR . '/acorn-8.19.0');        // JavaScript parser of the syntax check (loaded by assets/js/nu/nucodetools.js)
define('NU_JQUERY', NU_VENDOR . '/jquery');
define('NU_JQCONFIRM', NU_VENDOR . '/jquery-confirm');
define('NU_JQUERY4', NU_VENDOR . '/jquery-4.0.0');               // jQuery 4 + Migrate (NuJquery)
define('NU_JQMIGRATE3', NU_VENDOR . '/jquery-migrate-3.6.0');
define('NU_JQMIGRATE4', NU_VENDOR . '/jquery-migrate-4.0.2');
define('NU_FORMATTER', NU_VENDOR . '/formatter');
define('NU_FONTAWESOME', NU_VENDOR . '/fontawesome-7.3.1');   // fallback for nuBuilder's "fa fa-…" icons (nuBuilder's own copy first)
define('NU_TINYMCE', 'assets/vendor/tinymce');      // OLD place only (a hand copy of earlier versions) – TinyMCE is a library pack
define('NU_FEATURE_ACCESSIBILITY', false);   // user form switch "Accessibility features" hidden until accessibility is defined (TODO.md)

/**
 * Classes loaded on first use: feature modules that most requests never need. Files with functions or classes that are
 * checked with class_exists(…, false) stay in the require list below. dev/tests/run.php checks that every class of core/ is found.
 */
const NU_AUTOLOAD = [
	'NuCompat' => 'core/nucompat.php', 'NuSchemaCheck' => 'core/nuschemacheck.php', 'NuIntegrity' => 'core/nuintegrity.php',
		'NuLanguages' => 'core/nulanguages.php', 'NuSettingsHub' => 'core/nusettingshub.php', 'NuSettings' => 'core/nusettings.php',
		'NuBranding' => 'core/nubranding.php', 'NuDashboard' => 'core/nudashboard.php', 'NuAutoUpdate' => 'core/nuautoupdate.php',
		'NuGitHub' => 'core/nugithub.php', 'NuHomeHook' => 'core/nuhomehook.php',
	'NuHome' => 'core/nuhome.php', 'NuApi' => 'core/nuapi.php', 'NuApiError' => 'core/nuapi.php', 'NuApiRecords' => 'core/nuapirecords.php',
		'NuApiFile' => 'core/nuapi.php', 'NuApiClient' => 'core/nuapiclient.php',
	'NuApiPlayground' => 'core/nuapiplayground.php', 'NuInstall' => 'core/nuinstall.php', 'NuConfigFile' => 'core/nuconfigfile.php',
		'NuSetupWizard' => 'core/nusetupwizard.php', 'NuPalette' => 'core/nupalette.php', 'NuAdmin' => 'core/nuadmin.php',
		'NuTutorial' => 'core/nututorial.php', 'NuListView' => 'core/nulistview.php', 'NuListDefs' => 'core/nulistdefs.php',
		'NuLoginToken' => 'core/nulogintoken.php', 'NuReportApi' => 'core/nureportapi.php', 'NuDemoData' => 'core/nudemodata.php',
		'NuDbChanges' => 'core/nudbchanges.php', 'NuAudit' => 'core/nuaudit.php', 'NuIncludes' => 'core/nuincludes.php',
		'NuMailOAuth' => 'core/numailoauth.php', 'NuUserFields' => 'core/nuuserfields.php', 'NuUserDefault' => 'core/nuuserdefault.php',
		'NuUserAccess' => 'core/nuuseraccess.php', 'NuUpload' => 'core/nuupload.php', 'NuCustomCode' => 'core/nucustomcode.php',
		'NuCodeTemplates' => 'core/nucodetemplates.php', 'NuHelpLink' => 'core/nuhelplink.php', 'NuSidebar' => 'core/nusidebar.php',
		'NuFormInfo' => 'core/form/nuforminfo.php', 'NuColumnChange' => 'core/form/nucolumnchange.php', 'NuDesign' => 'core/form/nudesign.php',
		'NuDbReset' => 'core/nudbreset.php', 'NuDbState' => 'core/nudbstate.php', 'NuTheme' => 'core/nutheme.php', 'NuNotify' => 'core/nunotify.php',
		'NuSelfTest' => 'core/nuselftest.php', 'NuUserLabel' => 'core/nuuserlabel.php', 'NuPageNo' => 'core/nupageno.php',
	// the system lists, one trait per list (code review L3)
	'NuListUsers' => 'core/lists/nulist_users.php', 'NuListAccess' => 'core/lists/nulist_access.php', 'NuListForms' => 'core/lists/nulist_forms.php',
		'NuListProcedures' => 'core/lists/nulist_procedures.php', 'NuListReports' => 'core/lists/nulist_reports.php',
		'NuListFiles' => 'core/lists/nulist_files.php', 'NuListItems' => 'core/lists/nulist_items.php',
		'NuListTranslations' => 'core/lists/nulist_translations.php', 'NuListPermissions' => 'core/lists/nulist_permissions.php',
		'NuListPrompts' => 'core/lists/nulist_prompts.php', 'NuListLog' => 'core/lists/nulist_log.php', 'NuListAudit' => 'core/lists/nulist_audit.php',
		'NuListEmailLog' => 'core/lists/nulist_emaillog.php', 'NuListUserFields' => 'core/lists/nulist_userfields.php',
		'NuListApiClients' => 'core/lists/nulist_apiclients.php', 'NuListAdminRoles' => 'core/lists/nulist_adminroles.php',
		'NuListSqlBuilder' => 'core/lists/nulist_sqlbuilder.php', 'NuListEmailTemplates' => 'core/lists/nulist_emailtemplates.php',
		'NuListNotifications' => 'core/lists/nulist_notifications.php', 'NuListDashboards' => 'core/lists/nulist_dashboards.php',
		'NuListWebhooks' => 'core/lists/nulist_webhooks.php', 'NuListAiProviders' => 'core/lists/nulist_aiproviders.php',
		'NuListHelpPages' => 'core/lists/nulist_helppages.php', 'NuListWebhookCalls' => 'core/lists/nulist_webhookcalls.php',
		'NuListExports' => 'core/lists/nulist_exports.php', 'NuListSelftest' => 'core/lists/nulist_selftest.php',
		'NuListProjects' => 'core/lists/nulist_projects.php', 'NuListRecycleBin' => 'core/lists/nulist_recyclebin.php',
	'NuUpdatePage' => 'core/nuupdatepage.php',
	// the work of the pages (code review R7): their POST actions and the functions they defined themselves
	'NuAccountPage' => 'core/nuaccountpage.php', 'NuAdminrolesPage' => 'core/nuadminrolespage.php', 'NuAiprovidersPage' => 'core/nuaiproviderspage.php',
		'NuApiclientsPage' => 'core/nuapiclientspage.php', 'NuApiplaygroundPage' => 'core/nuapiplaygroundpage.php',
		'NuAppclonerPage' => 'core/nuappclonerpage.php', 'NuAudithistoryPage' => 'core/nuaudithistorypage.php', 'NuAuditPage' => 'core/nuauditpage.php',
		'NuBrandingPage' => 'core/nubrandingpage.php', 'NuBuilderPagePage' => 'core/nubuilderpagepage.php', 'NuClonerPage' => 'core/nuclonerpage.php',
		'NuCodesearchPage' => 'core/nucodesearchpage.php', 'NuConfigfilePage' => 'core/nuconfigfilepage.php',
		'NuDashboardsPage' => 'core/nudashboardspage.php', 'NuDataaccessPage' => 'core/nudataaccesspage.php',
		'NuDataexportPage' => 'core/nudataexportpage.php', 'NuDbresetPage' => 'core/nudbresetpage.php', 'NuDbsetupPage' => 'core/nudbsetuppage.php',
		'NuFilemanagerPage' => 'core/nufilemanagerpage.php', 'NuFilesPage' => 'core/nufilespage.php', 'NuForgotPage' => 'core/nuforgotpage.php',
		'NuFormaccessPage' => 'core/nuformaccesspage.php', 'NuFormaiPage' => 'core/nuformaipage.php', 'NuGettingreadyPage' => 'core/nugettingreadypage.php',
		'NuGlobalcodePage' => 'core/nuglobalcodepage.php', 'NuHelppagesPage' => 'core/nuhelppagespage.php', 'NuHomePage' => 'core/nuhomepage.php',
		'NuImportPage' => 'core/nuimportpage.php', 'NuIpaccessPage' => 'core/nuipaccesspage.php', 'NuItemsPage' => 'core/nuitemspage.php',
		'NuJqueryPage' => 'core/nujquerypage.php', 'NuLibpackPage' => 'core/nulibpackpage.php', 'NuLogoutPage' => 'core/nulogoutpage.php',
		'NuNotificationsPage' => 'core/nunotificationspage.php', 'NuPasswordPage' => 'core/nupasswordpage.php',
		'NuPermissionsPage' => 'core/nupermissionspage.php', 'NuProceduresPage' => 'core/nuprocedurespage.php',
		'NuProjectsPage' => 'core/nuprojectspage.php', 'NuPromptsPage' => 'core/nupromptspage.php', 'NuPwpolicyPage' => 'core/nupwpolicypage.php',
		'NuReauthPage' => 'core/nureauthpage.php', 'NuRecyclebinPage' => 'core/nurecyclebinpage.php',
		'NuReportdesignerPage' => 'core/nureportdesignerpage.php', 'NuReportsPage' => 'core/nureportspage.php', 'NuResetPage' => 'core/nuresetpage.php',
		'NuSamplePage' => 'core/nusamplepage.php', 'NuSecuritycheckPage' => 'core/nusecuritycheckpage.php', 'NuSecurityPage' => 'core/nusecuritypage.php',
		'NuSelftestPage' => 'core/nuselftestpage.php', 'NuSettingsFortePage' => 'core/nusettingsfortepage.php',
		'NuSettingsPage' => 'core/nusettingspage.php', 'NuSetupwizardPage' => 'core/nusetupwizardpage.php',
		'NuSqlbuilderPage' => 'core/nusqlbuilderpage.php', 'NuSsoPage' => 'core/nussopage.php', 'NuThemesPage' => 'core/nuthemespage.php',
		'NuTranslationsPage' => 'core/nutranslationspage.php', 'NuTutorialPage' => 'core/nututorialpage.php', 'NuTwofaPage' => 'core/nutwofapage.php',
		'NuUserfieldsPage' => 'core/nuuserfieldspage.php', 'NuVendorPage' => 'core/nuvendorpage.php', 'NuWebhooksPage' => 'core/nuwebhookspage.php',
	'NuBuilderApi' => 'core/form/nubuilderapi.php', 'NuFormApiPage' => 'core/form/nuformapipage.php', 'NuLoginPage' => 'core/nuloginpage.php',
		'NuInstallPage' => 'core/nuinstallpage.php', 'NuEmailPage' => 'core/nuemailpage.php', 'NuAccessLevelPage' => 'core/nuaccesslevelpage.php',
		'NuDashboardApiPage' => 'core/nudashboardapipage.php', 'NuDebugPage' => 'core/nudebugpage.php',
		'NuEmailTemplatesPage' => 'core/nuemailtemplatespage.php', 'NuFormatApiPage' => 'core/nuformatapipage.php',
		'NuFormatsPage' => 'core/nuformatspage.php', 'NuNotifyApiPage' => 'core/nunotifyapipage.php', 'NuSessionsPage' => 'core/nusessionspage.php',
		'NuUploadPage' => 'core/nuuploadpage.php', 'NuUserPage' => 'core/nuuserpage.php', 'NuFormPage' => 'core/form/nuformpage.php',
		'NuSample' => 'core/nusample.php', 'NuSampleSet' => 'core/nusampleset.php', 'NuListExport' => 'core/nulistexport.php',
		'NuProcedure' => 'core/nuprocedure.php',
	'NuReport' => 'core/nureport.php', 'NuReportSpec' => 'core/nureportspec.php', 'NuReportEngine' => 'core/nureportengine.php',
		'NuReportXlsx' => 'core/nureportxlsx.php', 'NuRecycle' => 'core/nurecycle.php', 'NuReportSection' => 'core/nureportengine.php',
		'NuMarkdown' => 'core/numarkdown.php', 'NuPasskey' => 'core/nupasskey.php', 'NuCbor' => 'core/nupasskey.php',
	'NuPwReset' => 'core/nupwreset.php', 'NuSqlSelect' => 'core/nusqlselect.php', 'NuPrompt' => 'core/nuprompt.php', 'NuImport' => 'core/nuimport.php',
	'NuPermission' => 'core/nupermission.php', 'NuAppCloner' => 'core/nuappcloner.php', 'NuAppClonerConfig' => 'core/nuappclonerconfig.php',
		'NuClonerConfig' => 'core/nuclonerconfig.php', 'NuSqlDump' => 'core/nusqldump.php', 'NuSqlImport' => 'core/nusqlimport.php',
		'NuProject' => 'core/nuproject.php', 'NuCodeSearch' => 'core/nucodesearch.php', 'NuProjectPackage' => 'core/nuprojectpackage.php',
	'NuInspect' => 'core/nuinspect.php', 'NuCloner' => 'core/nucloner.php', 'NuFormHistory' => 'core/form/nuhistory.php',
		'NuFormSnapshot' => 'core/form/nusnapshot.php', 'NuFormDraft' => 'core/form/nuformdraft.php',
	'NuDraftRevException' => 'core/form/nuformdraft.php', 'NuDraftStore' => 'core/form/nuformstore.php',
	'NuOpened' => 'core/nuopened.php', 'NuCronRun' => 'core/nucronrun.php', 'NuPwa' => 'core/nupwa.php', 'NuPageWidth' => 'core/nupagewidth.php',
		'NuSysInfo' => 'core/nusysinfo.php', 'NuReady' => 'core/nuready.php', 'NuHomeForms' => 'core/nuhomeforms.php',
		'NuBuilderGen' => 'core/form/nubuilder_gen.php', 'NuFnRef' => 'core/nufnref.php', 'NuSnippets' => 'core/nusnippets.php',
		'NuAdvSearch' => 'core/form/nuadvsearch.php', 'NuPivot' => 'core/form/nupivot.php', 'NuCalendar' => 'core/form/nucalendar.php',
		'NuWebhook' => 'core/nuwebhook.php', 'NuAiProvider' => 'core/nuaiprovider.php', 'NuUserHelp' => 'core/nuuserhelp.php',
		'NuRowDetails' => 'core/form/nurowdetails.php', 'NuSuggest' => 'core/form/nusuggest.php', 'NuIpAccess' => 'core/nuipaccess.php',
		'NuSso' => 'core/nusso.php', 'NuSecret' => 'core/nusecret.php', 'NuHealth' => 'core/nuhealth.php', 'NuPlugin' => 'core/nuplugin.php', 'NuPluginApi' => 'core/nupluginapi.php', 'NuPluginsPage' => 'core/nupluginspage.php', 'NuBs6' => 'core/nubs6.php',
		'NuJquery' => 'core/nujquery.php', 'NuPwPolicy' => 'core/nupwpolicy.php', 'NuHtmlClean' => 'core/nuhtmlclean.php',
		'NuConflict' => 'core/form/nuconflict.php', 'NuFormSpec' => 'core/form/nuformspec.php', 'NuAiContext' => 'core/form/nuaicontext.php',
		'NuFormRules' => 'core/form/nurules.php', 'NuSelectSource' => 'core/form/nuselectsource.php', 'NuSqlCheck' => 'core/nusqlcheck.php',
		'NuOnProcess' => 'core/form/nuonprocess.php', 'NuPrintView' => 'core/form/nuprintview.php', 'NuResponsive' => 'core/form/nuresponsive.php',
		'NuXlsx' => 'core/nuxlsx.php', 'NuXlsxStream' => 'core/nuxlsxstream.php', 'NuDataExport' => 'core/nudataexport.php',
		'NuPermType' => 'core/nupermtype.php', 'NuFormEveryone' => 'core/nuformeveryone.php', 'NuFormStatus' => 'core/nuformstatus.php',
		'NuSpreadsheet' => 'core/nuspreadsheet.php', 'NuXlsxSheet' => 'core/nuxlsx.php', 'NuXlsxCell' => 'core/nuxlsx.php',
		'NuXlsxStrings' => 'core/nuxlsx.php', 'NuPage' => 'core/nupage.php', 'NuPageContext' => 'core/nupage.php',
		'NuPageVersion' => 'core/nupageversion.php', 'NuUserHome' => 'core/nuuserhome.php', 'NuLibraries' => 'core/nulibraries.php',
		'NuLibs' => 'core/nulibs.php', 'NuFileManager' => 'core/nufilemanager.php', 'NuSubject' => 'core/nusubject.php',
		'NuFieldAccess' => 'core/nufieldaccess.php', 'NuBrowseAccess' => 'core/nubrowseaccess.php',
];
spl_autoload_register(static function (string $class): void {
	if (isset(NU_AUTOLOAD[$class])) require_once NU_ROOT . '/' . NU_AUTOLOAD[$class];
});
// PhpSpreadsheet (library pack): its classes are there in procedures and hooks as soon as code uses one – the pack's own autoloader
spl_autoload_register(static function (string $class): void {
	if (str_starts_with($class, 'PhpOffice\\PhpSpreadsheet\\')) NuSpreadsheet::load();
});

require NU_ROOT . '/core/nusess.php';
require NU_ROOT . '/core/nudb.php';
require NU_ROOT . '/core/nudemo.php';
require NU_ROOT . '/core/nuinfra.php';
require NU_ROOT . '/core/nusecurity.php';
require NU_ROOT . '/core/nuhousekeeping.php';
require NU_ROOT . '/core/numail.php';
require NU_ROOT . '/core/nuitems.php';
require NU_ROOT . '/core/nufiles.php';
require NU_ROOT . '/core/nutranslate.php';
require NU_ROOT . '/core/nudebug.php';
require NU_ROOT . '/core/nusessions.php';
require NU_ROOT . '/core/nuvendor.php';
require NU_ROOT . '/core/nuupdate.php';
require NU_ROOT . '/core/nuauth.php';
require NU_ROOT . '/core/nuimpersonate.php';
require NU_ROOT . '/core/nutwofa.php';
require NU_ROOT . '/core/nuacl.php';
require NU_ROOT . '/core/nulayout.php';
require NU_ROOT . '/core/form/nuformat.php';
require NU_ROOT . '/core/form/nuhash.php';
require NU_ROOT . '/core/form/nuprops.php';
require NU_ROOT . '/core/form/numeta.php';
require NU_ROOT . '/core/form/nuphp.php';
require NU_ROOT . '/core/form/nubrowse.php';
require NU_ROOT . '/core/form/nurecord.php';
require NU_ROOT . '/core/form/nulookup.php';
require NU_ROOT . '/core/form/nulayout.php';
require NU_ROOT . '/core/form/nurender.php';
require NU_ROOT . '/core/form/nuddl.php';
require NU_ROOT . '/core/form/nutrail.php';

// ===================================================================== config & db

function nu_config_path(): string
{
	return getenv('NU_CONFIG') ?: NU_ROOT . '/nuconfig.php';
}

/** The variables of nuconfig.php as written there (no defaults, no values from zzzzsys_config). */
function nu_config_file(): array
{
	static $vars = null;
	if ($vars !== null) return $vars;
	$file = nu_config_path();
	if (!is_file($file)) nu_fatal(t('err_no_config'), t('err_no_config_hint'));
	return $vars = (static function (string $__file): array {
		require $__file;
		unset($__file);
		return get_defined_vars();
	})($file);
}

/** nuconfig.php with nuBuilder Next's defaults (database connection and other values read before the database). */
function nu_config(): array
{
	static $cfg = null;
	if ($cfg !== null) return $cfg;
	return $cfg = nu_config_file() + [
		'nuConfigDBType'                   => 'mysql',
		'nuConfigDBPort'                   => '',
		'nuConfigDBCharacterSet'           => 'utf8mb4',
		'nuConfigDBCollate'                => '',
		'nuConfigDBOptions'                => [],
		'nuConfigDBTrustServerCertificate' => false,
		'nuConfigDBGlobeadminUsername'     => 'globeadmin',
		'nuConfigDBGlobeadminPassword'     => '',
		'nuConfigPasswordUpgrade'          => true,
		'nuConfigSessionTimeout'           => 120,
		'nuConfigSessionTimeoutGlobeadmin' => 720,
		'nuConfigPasswordMinLength'        => 8,
		'nuConfigDebug'                    => false,
		'nuConfigIncludePHP'               => [],
		'nuConfig2FAAdmin'                 => false,
		'nuConfig2FAUser'                  => false,
		'nuConfig2FATrustDays'             => 30,
		'nuConfigSharedLogin'              => false,
		'nuConfigLogRecordChanges'         => true,
		'nuConfigEditConflictCheck'        => true,     // concurrent edits (core/form/nuconflict.php)
		'nuConfigEditPresence'             => true,
	];
}

/**
 * The time zone PHP uses: [name, source] – source 'config' ($nuConfigTimezone), 'setup' (nuBuilder's Setup → Time zone,
 * zzzzsys_setup / zzzzsys_timezone – what nuBuilder itself uses) or 'server' (PHP's default, e.g. the host's zone).
 * nuBuilder's Setup is read once per session (it changes rarely); the API reads it per request.
 */
function nu_timezone(): array
{
	static $r = null;
	if ($r !== null) return $r;
	$valid = static fn(string $tz): bool => $tz !== '' && in_array($tz, timezone_identifiers_list(), true);
	$tz = trim((string)nu_cfg('nuConfigTimezone'));
	if ($valid($tz)) return $r = [$tz, 'config'];
	if (session_status() === PHP_SESSION_ACTIVE && is_string($_SESSION[NuSess::SETUP_TZ] ?? null)) {
		$tz = $_SESSION[NuSess::SETUP_TZ];
	} else {
		$tz = trim((string)(NuMailer::setupRow()['set_timezone'] ?? ''));
		if (session_status() === PHP_SESSION_ACTIVE) $_SESSION[NuSess::SETUP_TZ] = $tz;
	}
	if ($valid($tz)) return $r = [$tz, 'setup'];
	return $r = [date_default_timezone_get(), 'server'];
}

function nu_apply_timezone(): void
{
	date_default_timezone_set(nu_timezone()[0]);
	$GLOBALS['nu_tz_applied'] = true;
	if (NuDB::$connected) nu_db_timezone(nu_db());
}

/**
 * The database session's time zone = the offset of the application's time zone now – as nuBuilder Forte's nuClientTimeZone()
 * ("SET time_zone = '+1:00'", MySQL only), so NOW(), FROM_UNIXTIME() … in SQL give the same times in both products.
 */
function nu_db_timezone(NuDB $db): void
{
	if ($db->type !== 'mysql') return;
	try { $db->session("SET time_zone = '" . date('P') . "'"); } catch (Throwable $e) { error_log('nuBuilder Next time zone: ' . $e->getMessage()); }
}

/** A setting: with $nuConfigSettingsFromDB = true the value of nuBuilder's settings table (zzzzsys_config) wins, as in nuBuilder. */
function nu_cfg(string $key): mixed
{
	$c = nu_config();
	if (!empty($c['nuConfigSettingsFromDB'])) {
		$db = NuSettings::values()['php'];
		if (array_key_exists($key, $db)) return $db[$key];
	}
	return $c[$key] ?? null;
}

/**
 * Error details on screen: $nuConfigDebug of nuconfig.php OR of nuBuilder Forte's settings table – with
 * $nuConfigSettingsFromDB the table wins in nu_cfg(), so a true in the file alone showed no details. Developer mode of the
 * globeadmin shows them as well.
 */
/**
 * A failure the code goes on without on purpose (code review Q1): nothing for users, one line in the server's error log while
 * $nuConfigDevMode is set in nuconfig.php – so a catch block no longer hides a real error from the developer. Reads only the
 * config file (no database), so it is safe anywhere, also before a connection exists.
 */
function nu_ignored(Throwable $e, string $where = ''): void
{
	static $on = null;
	if ($on === null) {
		try { $on = is_file(nu_config_path()) && !empty(nu_config()['nuConfigDevMode']); } catch (Throwable) { $on = false; }
	}
	if (!$on) return;
	error_log('nuBuilder Next: ignored ' . get_class($e) . ($where !== '' ? ' (' . $where . ')' : '') . ': '
		. str_replace(["\r", "\n"], ' ', mb_substr($e->getMessage(), 0, 300)) . ' at ' . basename($e->getFile()) . ':' . $e->getLine());
}

function nu_debug(): bool
{
	try {
		if (!empty(nu_config()['nuConfigDebug']) || !empty(nu_cfg('nuConfigDebug'))) return true;
		return class_exists('NuHash') && NuHash::devMode();
	} catch (Throwable) {
		return false;
	}
}

function nu_db(): NuDB
{
	static $db = null, $failing = false;
	if (NuDB::$override !== null) return NuDB::$override;
	if ($db) return $db;
	if ($failing) throw new RuntimeException('nuBuilder Next: no database connection');   // the error page itself asked again

	$c = nu_config();
	try {
		$db = new NuDB([
			'type'       => $c['nuConfigDBType'],
			'host'       => $c['nuConfigDBHost'] ?? '127.0.0.1',
			'port'       => $c['nuConfigDBPort'],
			'name'       => $c['nuConfigDBName'] ?? '',
			'user'       => $c['nuConfigDBUser'] ?? '',
			'password'   => $c['nuConfigDBPassword'] ?? '',
			'charset'    => $c['nuConfigDBCharacterSet'],
			'collate'    => $c['nuConfigDBCollate'],
			'options'    => (array)$c['nuConfigDBOptions'],
			'trust_cert' => $c['nuConfigDBTrustServerCertificate'],
		]);
		if (!empty($GLOBALS['nu_tz_applied'])) nu_db_timezone($db);
		return $db;
	} catch (Throwable $e) {
		error_log('nuBuilder Next DB connect: ' . $e->getMessage());
		$failing = true;
		nu_fatal(t('err_db_connect'), $c['nuConfigDebug'] ? $e->getMessage() : t('err_db_connect_hint'));
	}
}

/** Session timeout in seconds: $nuConfigSessionTimeoutGlobeadmin for the globeadmin, $nuConfigSessionTimeout for everybody else (minutes in nuconfig.php). */
/**
 * cURL option for a progress callback ($ch, $downloadTotal, $downloaded, …): CURLOPT_XFERINFOFUNCTION exists from PHP 8.2 on, PHP 8.1 has
 * CURLOPT_PROGRESSFUNCTION with the same arguments ("Undefined constant CURLOPT_XFERINFOFUNCTION" stopped GitHub on PHP 8.1).
 */
function nu_curl_progress_opt(): int
{
	return defined('CURLOPT_XFERINFOFUNCTION') ? (int)constant('CURLOPT_XFERINFOFUNCTION') : CURLOPT_PROGRESSFUNCTION;
}

/*
 * Functions a host may switch off with disable_functions: calling one that is switched off ends the request with
 * "Call to undefined function" – "@" does not help. These wrappers do nothing (or return '') instead.
 */
function nu_time_limit(int $seconds): void
{
	if (function_exists('set_time_limit')) @set_time_limit($seconds);
}

function nu_ignore_abort(): void
{
	if (function_exists('ignore_user_abort')) @ignore_user_abort(true);
}

function nu_ini_get(string $key): string
{
	return function_exists('ini_get') ? (string)ini_get($key) : '';
}

function nu_ini_set(string $key, string $value): void
{
	if (function_exists('ini_set')) @ini_set($key, $value);
}

/**
 * Gives up the session lock for work that needs no session – a long query, a file being built: PHP keeps the session
 * file locked while a request has it open, so the user's other pages and the page's own requests would wait for it. $_SESSION can
 * still be read afterwards; what is written to it is lost – use nu_session_write(). Never before code of the application runs
 * (procedures, form code: it may write to the session).
 */
function nu_session_release(): void
{
	if (session_status() !== PHP_SESSION_ACTIVE) return;
	session_write_close();
	// opening it again (nu_session_write) must not send the cookie or the cache headers a second time – both also fail once output began,
	// and so does changing these settings: set them now
	if (!headers_sent()) {
		nu_ini_set('session.use_cookies', '0');
		nu_ini_set('session.cache_limiter', '');
	}
}

/**
 * Runs $fn with the session open for writing – also after nu_session_release(): the session is opened again, $fn changes $_SESSION
 * (it sees what other requests stored meanwhile) and the lock is given up at once. False when the session could not be opened.
 */
function nu_session_write(callable $fn): bool
{
	if (session_status() === PHP_SESSION_ACTIVE) { $fn(); return true; }
	if (PHP_SAPI === 'cli' || session_id() === '' || nu_ini_get('session.use_cookies') === '1') return false;   // never opened, or not released by nu_session_release()
	if (!@session_start()) return false;
	try {
		$fn();
	} finally {
		session_write_close();
	}
	return true;
}

function nu_session_timeout(bool $globeadmin = false): int
{
	return max(1, (int)nu_cfg($globeadmin ? 'nuConfigSessionTimeoutGlobeadmin' : 'nuConfigSessionTimeout')) * 60;
}

// ===================================================================== boot

/** Security headers of every page (nu_boot; the installer and the API send their own). */
function nu_security_headers(): void
{
	header_remove('X-Powered-By');                            // PHP's version header (expose_php) – nothing a visitor needs
	header('X-Frame-Options: DENY');
	header('X-Content-Type-Options: nosniff');
	header('Referrer-Policy: same-origin');
	header('Cross-Origin-Opener-Policy: same-origin');
	header('Permissions-Policy: camera=(self), microphone=(self), geolocation=(self), payment=(), usb=(), serial=(), interest-cohort=()');
	if (nu_https()) header('Strict-Transport-Security: max-age=31536000');
}

function nu_boot(): void
{
	nu_config();                // fails early with a setup hint when nuconfig.php is missing
	nu_security_headers();
	header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; font-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");

	// PHP must keep idle session files at least as long as the longer of the two timeouts (PHP's default is 24 minutes)
	nu_ini_set('session.gc_maxlifetime', (string)max(nu_session_timeout(false), nu_session_timeout(true), (int)nu_ini_get('session.gc_maxlifetime')));
	nu_ini_set('session.use_strict_mode', '1');            // no session ids made up by the client
	session_name('nunext');
	// cookie only for this folder: another copy of nuBuilder Next on the same domain has its own session
	session_set_cookie_params(['lifetime' => 0, 'path' => NuSecurity::cookiePath(), 'secure' => nu_https(), 'httponly' => true, 'samesite' => 'Lax']);
	session_start();
	nu_apply_timezone();

	NuHousekeeping::maybe();                           // without cron: tokens, sessions, log retention … now and then (core/nuhousekeeping.php)

	set_exception_handler(static function (Throwable $e) {
		if ($e instanceof NuDemoBlocked) nu_fatal(t('demo_not_available'), t('demo_blocked_hint'), true);   // demo mode: code that writes
		error_log('nuBuilder Next: ' . $e);
		nu_fatal(t('err_unexpected'), nu_debug() ? $e->getMessage() : t('err_unexpected_hint'));
	});
}

// ===================================================================== helpers

function h(mixed $s): string
{
	return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** URL of a local asset with a version stamp, so browsers load the new file after an update. */
function nu_asset(string $path): string
{
	static $cache = [];
	if (!isset($cache[$path])) {
		$use = $path;
		// the minified copy (dev/minify.py): listed in assets/min.json with the size of the source it was made from
		$min = nu_asset_min();
		if (isset($min[$path]) && @filesize(NU_ROOT . '/' . $path) === $min[$path]) {
			$copy = (string)preg_replace('/\.(js|css)$/', '.min.$1', $path);
			if (is_file(NU_ROOT . '/' . $copy)) $use = $copy;
		}
		$mtime = @filemtime(NU_ROOT . '/' . $use);
		$cache[$path] = $use . '?v=' . ($mtime ? base_convert((string)$mtime, 10, 36) : NU_VERSION);
	}
	return $cache[$path];
}

/**
 * Sources with a minified copy: [path => size of the source] from assets/min.json – empty in developer mode ($nuConfigDevMode: readable
 * code in the browser's tools). The cookie nunext_assets = min / src chooses either way (tests, a look at a live server's sources).
 */
function nu_asset_min(): array
{
	static $list = null;
	if ($list !== null) return $list;
	$want = (string)($_COOKIE['nunext_assets'] ?? '');
	$dev = false;
	if ($want === '') {
		if (is_file(nu_config_path())) {                     // installer: no nuconfig.php yet (nu_config() would stop the page)
			try { $dev = !empty(nu_cfg('nuConfigDevMode')); } catch (Throwable) { $dev = false; }
		}
	}
	if ($want === 'src' || ($want !== 'min' && $dev)) return $list = [];
	$f = NU_ROOT . '/assets/min.json';
	$j = is_file($f) ? json_decode((string)@file_get_contents($f), true) : null;
	return $list = is_array($j) ? $j : [];
}

function nu_url(string $page, array $params = []): string
{
	$q = http_build_query(['p' => $page] + array_filter($params, static fn($v) => $v !== null && $v !== ''));
	return 'index.php?' . $q;
}

/**
 * A JSON answer and the end of the request (code review R4) – the one place for the headers: status (null = as set before), JSON in UTF-8, nosniff, never
 * cached; text that is not valid UTF-8 is replaced instead of failing.
 */
function nu_json(mixed $data, ?int $status = null): never
{
	if (!headers_sent()) {
		if ($status !== null) http_response_code($status);   // null: the status set before (e.g. http_response_code(403) of the page)
		header('Content-Type: application/json; charset=utf-8');
		header('X-Content-Type-Options: nosniff');
		header('Cache-Control: no-store');
	}
	echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
	exit;
}

function nu_redirect(string $url): never
{
	// "Save" in the leave dialog (assets/js/nuapp.js): after a successful save go to the link that was clicked instead of the record.
	// Only local paths (/… or index.php?…), and only when the last notice is a success (errors keep the redirect of the page).
	$then = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' ? nu_post('_nu_then') : '';
	if ($then !== '' && !preg_match('/[\r\n\\\\]/', $then) && !str_starts_with($then, '//') && (str_starts_with($then, '/') || str_starts_with($then, 'index.php?'))) {
		$fl = $_SESSION[NuSess::FLASH] ?? [];
		$last = $fl ? end($fl) : null;
		if ($last && $last[0] === 'success') $url = $then;
	}
	header('Location: ' . $url, true, 303);
	exit;
}

/**
 * One row of nuBuilder Next's settings table: the value of zzzzsys_nunext_setting <id>, null when there is none (or it is NULL).
 * A page used to read 10–25 rows with one statement each – a quarter of all its statements (performance review). Now the
 * first question of a request loads every row in ONE statement (values up to NU_SETTING_INLINE bytes; a longer one – Global code,
 * collected jQuery warnings, the SSO key cache – is read when asked for), so a setting that does not exist costs nothing either.
 * Kept per request and connection for NU_SETTING_TTL seconds (a long task sees another request's change after that at the latest);
 * every write to the table through NuDB drops it (NuDB::noteWrite), as does nu_setting_forget(). Callers keep their own JSON
 * decoding and defaults. Throws, as before, while the table does not exist.
 */
const NU_SETTING_INLINE = 8192;
const NU_SETTING_TTL = 2.0;

function nu_setting_value(string $id): ?string
{
	$db = nu_db();
	$c = &nu_setting_rows($db);
	if (!array_key_exists($id, $c)) return null;                      // no such row
	if ($c[$id] === false) {                                          // a long value: read now, once
		$v = $db->value('SELECT setting_value FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = ?', [$id]);
		$c[$id] = $v === null || $v === false ? null : (string)$v;
	}
	return $c[$id];
}

/**
 * A setting row holding a JSON object, decoded: [] while the row is missing, holds no JSON object or the table does not exist yet
 * (code review D5 – the read every module with a JSON setting repeated).
 * @return array<mixed>
 */
function nu_setting_json(string $id): array
{
	try {
		$v = nu_setting_value($id);
	} catch (Throwable $ig) {
		nu_ignored($ig, 'no settings table yet');
		return [];
	}
	$d = is_string($v) ? json_decode($v, true) : null;
	return is_array($d) ? $d : [];
}

/** One file of a zip archive (an update package): its content, or null without ZipArchive, an unreadable archive or no such file (code review D5). */
function nu_zip_entry(string $zipFile, string $name): ?string
{
	if (!class_exists('ZipArchive')) return null;
	$z = new ZipArchive();
	if ($z->open($zipFile) !== true) return null;
	try {
		$s = $z->getFromName($name);
		return $s === false ? null : $s;
	} finally {
		$z->close();
	}
}

/** Is there a row <id> (whatever its value)? */
function nu_setting_has(string $id): bool
{
	return array_key_exists($id, nu_setting_rows(nu_db()));
}

/**
 * Several rows at once: [id => value or null]. Long values that are not loaded yet come in ONE statement (Global JS + CSS + scope
 * on every page – NuCustomCode::get).
 * @param list<string> $ids
 * @return array<string, ?string>
 */
function nu_setting_values(array $ids): array
{
	$db = nu_db();
	$c = &nu_setting_rows($db);
	$long = array_values(array_filter($ids, static fn($id) => array_key_exists($id, $c) && $c[$id] === false));
	if (count($long) > 1) {
		foreach ($long as $id) $c[$id] = null;
		foreach ($db->all('SELECT zzzzsys_nunext_setting_id AS k, setting_value AS v FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id IN ('
			. implode(', ', array_fill(0, count($long), '?')) . ')', $long) as $r) $c[(string)$r['k']] = $r['v'] === null ? null : (string)$r['v'];
	}
	$out = [];
	foreach ($ids as $id) $out[$id] = nu_setting_value($id);
	return $out;
}

/**
 * The loaded rows of a connection: id => value, null (NULL in the table) or false (longer than NU_SETTING_INLINE: not loaded yet).
 * @return array<string, string|false|null>
 */
function &nu_setting_rows(NuDB $db): array
{
	$cache = &nu_setting_cache();
	$key = spl_object_id($db);
	if (!isset($cache[$key]) || microtime(true) - $cache[$key]['t'] > NU_SETTING_TTL) {
		$len = $db->type === 'sqlsrv' ? 'DATALENGTH(setting_value)' : 'LENGTH(setting_value)';
		$rows = [];
		foreach ($db->all("SELECT zzzzsys_nunext_setting_id AS k, CASE WHEN $len <= " . NU_SETTING_INLINE . " THEN setting_value END AS v,
			CASE WHEN $len > " . NU_SETTING_INLINE . ' THEN 1 ELSE 0 END AS big FROM zzzzsys_nunext_setting') as $r) {
			$rows[(string)$r['k']] = (int)$r['big'] === 1 ? false : ($r['v'] === null ? null : (string)$r['v']);
		}
		$cache[$key] = ['t' => microtime(true), 'v' => $rows];
	}
	return $cache[$key]['v'];
}

/** Writes one settings row (update or insert): the pattern of NuHome::save() / NuUpdate::setKeepSql(), once. */
function nu_setting_save(string $id, array $row, ?NuDB $db = null): void
{
	$db ??= nu_db();
	if ($db->value('SELECT COUNT(*) FROM zzzzsys_nunext_setting WHERE zzzzsys_nunext_setting_id = ?', [$id])) $db->update('zzzzsys_nunext_setting', $row, 'zzzzsys_nunext_setting_id', $id);
	else $db->insert('zzzzsys_nunext_setting', ['zzzzsys_nunext_setting_id' => $id] + $row);
	nu_setting_forget();
}

/** Stores an array as a JSON setting (own tables made first) with the time and who changed it – the counterpart of nu_setting_json(). */
function nu_setting_json_save(string $id, array $data, string $by, ?NuDB $db = null): void
{
	NuSchema::ensure();
	nu_setting_save($id, ['setting_value' => json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
		'updated_at' => date('Y-m-d H:i:s'), 'updated_by' => $by], $db);
}

function nu_setting_forget(): void
{
	$cache = &nu_setting_cache();
	$cache = [];
}

/** @return array<int, array{t: float, v: array<string, string|false|null>}> */
function &nu_setting_cache(): array
{
	static $cache = [];
	return $cache;
}

/** nuBuilder-style primary key: uniqid() + 2-digit counter (15 chars, fits varchar(25)). */
function nu_id(): string
{
	static $n = 0;
	$n = ($n + 1) % 100;
	return uniqid() . sprintf('%02d', $n);
}

/**
 * UUID version 7 (RFC 9562): 48 bits Unix time in milliseconds, then random – 36 characters (8-4-4-4-12), sorted by time,
 * so new rows go to the end of an index. For primary keys of application tables that are exchanged with other systems.
 */
/**
 * PDO's MySQL attribute "buffered query": PHP 8.4 added Pdo\Mysql::ATTR_USE_BUFFERED_QUERY, PHP 8.5 deprecates
 * PDO::MYSQL_ATTR_USE_BUFFERED_QUERY – use this instead of either constant.
 */
function nu_pdo_mysql_buffered(): int
{
	return nu_pdo_mysql_attr('USE_BUFFERED_QUERY');
}

/** An attribute of the MySQL PDO driver by its short name ('MULTI_STATEMENTS' …): Pdo\\Mysql::ATTR_… (PHP 8.4+), else PDO::MYSQL_ATTR_… */
function nu_pdo_mysql_attr(string $name): int
{
	return defined('Pdo\\Mysql::ATTR_' . $name) ? (int)constant('Pdo\\Mysql::ATTR_' . $name) : (int)constant('PDO::MYSQL_ATTR_' . $name);
}

function nu_uuid(): string
{
	$ms = (int)floor(microtime(true) * 1000);
	$b = substr(pack('J', $ms), 2, 6) . random_bytes(10);
	$b[6] = chr((ord($b[6]) & 0x0f) | 0x70);                                          // version 7
	$b[8] = chr((ord($b[8]) & 0x3f) | 0x80);                                          // RFC variant
	$h = bin2hex($b);
	return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4) . '-' . substr($h, 16, 4) . '-' . substr($h, 20, 12);
}

/**
 * A new primary key for a column of NuDB::columns(): a UUID for text columns of exactly 36 characters (CHAR / VARCHAR /
 * NCHAR / NVARCHAR(36)) and SQL Server's uniqueidentifier – otherwise nuBuilder's id (nu_id). Auto-increment keys never come here.
 */
function nu_new_key(?array $column): string
{
	$t = strtolower((string)($column['type'] ?? ''));
	$uuid = $t === 'uniqueidentifier' || (in_array($t, ['char', 'varchar', 'nchar', 'nvarchar', 'character', 'character varying'], true) && ($column['length'] ?? null) === 36);
	return $uuid ? nu_uuid() : nu_id();
}

function nu_post(string $key, string $default = ''): string
{
	$v = $_POST[$key] ?? $default;
	return is_string($v) ? trim($v) : $default;
}

function nu_check(string $key): string
{
	return (isset($_POST[$key]) && $_POST[$key] === '1') ? '1' : '0';
}

function nu_is_post(): bool
{
	return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

// ----------------------------------------------------------------- flash messages

/** $toast: shown as a toast at the top right (closes by itself) instead of an alert at the top of the page. */
function nu_flash(string $type, string $msg, bool $toast = false): void
{
	$_SESSION[NuSess::FLASH][] = [$type, $msg, $toast];
}

function nu_flashes(): array
{
	$f = $_SESSION[NuSess::FLASH] ?? [];
	unset($_SESSION[NuSess::FLASH]);
	return $f;
}

// ----------------------------------------------------------------- CSRF

/** The session's form token (64 hex characters) – never printed as it is (see nu_csrf_token). */
function nu_csrf_secret(): string
{
	return $_SESSION[NuSess::CSRF] ??= bin2hex(random_bytes(32));
}

/**
 * The form token for a page or a script: the session's token XOR fresh random bytes, with those bytes in front – a different
 * text on every page and every call, so a compressed page gives nothing away about it (BREACH: the size of a compressed answer that
 * holds a secret and text an attacker chose tells the secret byte by byte). nu_csrf_valid() accepts it and the plain token.
 */
function nu_csrf_token(): string
{
	$raw = (string)hex2bin(nu_csrf_secret());
	$mask = random_bytes(strlen($raw));
	return bin2hex($mask . ($mask ^ $raw));
}

/** Is $sent the session's form token – masked (nu_csrf_token) or plain? */
function nu_csrf_valid(mixed $sent): bool
{
	if (!is_string($sent) || $sent === '') return false;
	$secret = nu_csrf_secret();
	if (strlen($sent) === strlen($secret)) return hash_equals($secret, $sent);
	if (strlen($sent) !== 2 * strlen($secret) || !ctype_xdigit($sent)) return false;
	$b = (string)hex2bin($sent);
	$n = intdiv(strlen($b), 2);
	return hash_equals($secret, bin2hex(substr($b, 0, $n) ^ substr($b, $n)));
}

function nu_csrf_field(): string
{
	return '<input type="hidden" name="_csrf" value="' . h(nu_csrf_token()) . '">';
}

function nu_csrf_verify(): void
{
	$sent = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
	if (!nu_csrf_valid($sent)) {
		// login page open while the session was gone (idle tab): show it again – with the same page to return to
		if (($_GET['p'] ?? '') === 'login') {
			nu_flash('warning', t('login_form_expired'));
			nu_redirect(NuAuth::loginUrl(is_string($_POST['next'] ?? null) ? $_POST['next'] : (is_string($_GET['next'] ?? null) ? $_GET['next'] : null)));
		}
		http_response_code(400);
		nu_fatal(t('err_csrf'), t('err_csrf_hint'), true);
	}
}

// ----------------------------------------------------------------- compression of pages

/**
 * Pages go to the browser compressed (review P2): the start page 149 KB → about 30 KB, the help 745 KB → about 120 KB.
 * Only an HTML page that was written in one go: a page that sends its output in parts (progress, long tasks), downloads (a
 * Content-Disposition or a Content-Length of their own), JSON and anything a server or PHP already compresses pass through
 * unchanged. Safe with the form token because every page carries it masked (nu_csrf_token).
 */
function nu_compress_start(): void
{
	if (PHP_SAPI === 'cli' || !function_exists('gzencode') || headers_sent()) return;
	if (nu_ini_get('zlib.output_compression') !== '' && !in_array(strtolower((string)nu_ini_get('zlib.output_compression')), ['0', 'off', ''], true)) return;
	if (!preg_match('/\bgzip\b/i', (string)($_SERVER['HTTP_ACCEPT_ENCODING'] ?? ''))) return;
	ob_start('nu_compress_output', 4 << 20);   // a larger answer (a download without its own headers) streams on uncompressed
}

/** Output handler of nu_compress_start(). */
function nu_compress_output(string $buffer, int $phase): string
{
	static $pass = false;
	if ($pass) return $buffer;
	$whole = ($phase & PHP_OUTPUT_HANDLER_START) && ($phase & PHP_OUTPUT_HANDLER_FINAL);
	if (!$whole || ($phase & PHP_OUTPUT_HANDLER_CLEAN) || strlen($buffer) < 1400 || headers_sent()) { $pass = true; return $buffer; }
	$type = 'text/html';
	foreach (headers_list() as $h) {
		$k = strtolower(strstr($h, ':', true) ?: '');
		if ($k === 'content-type') $type = strtolower(trim(substr($h, 13)));
		if ($k === 'content-encoding' || $k === 'content-disposition' || $k === 'content-length') return $buffer;
	}
	if (!str_starts_with($type, 'text/html')) return $buffer;
	$gz = gzencode($buffer, 5);
	if ($gz === false) return $buffer;
	header('Content-Encoding: gzip');
	header('Vary: Accept-Encoding', false);
	header('Content-Length: ' . strlen($gz));
	return $gz;
}

// ===================================================================== i18n

/** Languages of nuBuilder Next: code => [own name, nuBuilder's name in sus_language / zzzzsys_translate]. */
const NU_LANGS = [
	'de' => ['Deutsch', 'German'],
	'en' => ['English', 'English'],
	'fr' => ['Français', 'French'],
	'it' => ['Italiano', 'Italian'],
	'es' => ['Español', 'Spanish'],
	'pt' => ['Português', 'Portuguese'],
	'ru' => ['Русский', 'Russian'],
	'zh' => ['简体中文', 'Chinese'],
	'nl' => ['Nederlands', 'Dutch'],
	'pl' => ['Polski', 'Polish'],
	'ar' => ['العربية', 'Arabic'],
];

/** Languages written right to left (html dir="rtl", Bootstrap's RTL stylesheet). */
const NU_LANGS_RTL = ['ar'];

function nu_lang_rtl(): bool
{
	return in_array(nu_lang(), NU_LANGS_RTL, true);
}

/**
 * Language variants (English, German): lang/en.php is British English (en-GB, the base), lang/en-AU.php and
 * lang/en-US.php hold only the texts that differ; lang/de.php is German German (de-DE, the base), lang/de-CH.php holds the Swiss
 * texts (ss, «»). en-US.php and de-CH.php are generated by dev/lang_variants.php at every release.
 * nu_lang() stays the base code ('en' / 'de' – nuBuilder only knows "English" / "German"); nu_lang_variant() is the chosen variant.
 * Variant code => [base, own name, Intl locale].
 */
const NU_LANG_VARIANTS = [
	'de-DE' => ['de', 'Deutsch (DE)', 'de-DE'],
	'de-CH' => ['de', 'Deutsch (CH)', 'de-CH'],
	'en-GB' => ['en', 'English (UK)', 'en-GB'],
	'en-AU' => ['en', 'English (Australia)', 'en-AU'],
	'en-US' => ['en', 'English (US)', 'en-US'],
];
/** Variant of a base language when nothing else decides. */
const NU_LANG_VARIANT_DEFAULT = ['de' => 'de-DE', 'en' => 'en-GB'];

/** Supported code for any code / locale (de-CH, zh_Hans, pt-BR …), or null. */
function nu_lang_code(string $v): ?string
{
	$v = strtolower(trim($v));
	if ($v === '') return null;
	$c = substr($v, 0, 2);
	return isset(NU_LANGS[$c]) && (strlen($v) === 2 || in_array($v[2], ['-', '_'], true)) ? $c : null;
}

/**
 * Variant for a browser / locale tag: en-US, en-CA and plain "en" → en-US, en-AU and en-NZ → en-AU, other English → en-GB;
 * de-CH → de-CH, other German (plain "de", de-AT …) → de-DE.
 */
function nu_lang_variant_of(string $tag): ?string
{
	$t = strtolower(str_replace('_', '-', trim($tag)));
	foreach (NU_LANG_VARIANTS as $code => $_) if ($t === strtolower($code)) return $code;
	$base = nu_lang_code($t);
	$region = substr($t, 3, 2);
	if ($base === 'de') return $region === 'ch' ? 'de-CH' : 'de-DE';
	if ($base !== 'en') return null;
	if ($region === '' || in_array($region, ['us', 'ca'], true)) return 'en-US';
	if (in_array($region, ['au', 'nz'], true)) return 'en-AU';
	return 'en-GB';
}

/** The browser's preferred supported language: [base code, tag as sent] or null. */
function nu_lang_browser(): ?array
{
	static $r = false;
	if ($r !== false) return $r;
	$best = null; $bestQ = -1.0;
	foreach (explode(',', (string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '')) as $i => $part) {
		$bits = explode(';', trim($part));
		$code = nu_lang_code($bits[0]);
		if ($code === null) continue;
		$q = 1.0;
		foreach (array_slice($bits, 1) as $b) if (preg_match('/^\s*q=([0-9.]+)/', $b, $m)) $q = (float)$m[1];
		$q -= $i / 1000;                                   // equal q: the earlier one wins
		if ($q > $bestQ) { $best = [$code, trim($bits[0])]; $bestQ = $q; }
	}
	return $r = $best;
}

function nu_lang(): string
{
	// only the installation's languages (NuLanguages): a session / browser language that is not installed → the fallback
	if (isset($_SESSION[NuSess::LANG]) && isset(NU_LANGS[$_SESSION[NuSess::LANG]]) && NuLanguages::has($_SESSION[NuSess::LANG])) return $_SESSION[NuSess::LANG];
	// browser languages in order of preference (q values), first supported one; none supported: German (as before)
	$b = nu_lang_browser()[0] ?? null;
	return $b !== null && NuLanguages::has($b) ? $b : NuLanguages::fallback();
}

/** The chosen variant of the current language ('' = the language has no variants). */
function nu_lang_variant(): string
{
	$lang = nu_lang();
	if (!isset(NU_LANG_VARIANT_DEFAULT[$lang])) return '';
	// only the variants the installation offers (NuLanguages::variants – Settings → General → Languages)
	$v = $_SESSION[NuSess::LANG_VARIANT] ?? '';
	if (is_string($v) && (NU_LANG_VARIANTS[$v][0] ?? '') === $lang && NuLanguages::hasVariant($v)) return $v;
	$b = nu_lang_browser();
	if (!isset($_SESSION[NuSess::LANG]) && $b !== null && $b[0] === $lang) {
		$bv = nu_lang_variant_of($b[1]);
		if ($bv !== null && NuLanguages::hasVariant($bv)) return $bv;
	}
	return NuLanguages::defaultVariant($lang);
}

/**
 * A PHP date pattern with "d.m.Y" in the reader's form (review D8): en-GB / en-AU / fr / it / es / pt / ar 31/10/2026, en-US 10/31/2026,
 * nl 31-10-2026, zh 2026-10-31, de / pl / ru 31.10.2026. Only for display – inputs and files with their own format are not passed through it.
 */
function nu_dfmt(string $pattern): string
{
	static $map = null;
	if ($map === null) {
		try { $c = nu_lang_choice(); } catch (Throwable) { $c = 'de'; }
		$map = match (true) {
			$c === 'en-US' => 'm/d/Y',
			str_starts_with($c, 'en'), in_array($c, ['fr', 'it', 'es', 'pt', 'ar'], true) => 'd/m/Y',
			$c === 'nl' => 'd-m-Y',
			$c === 'zh' => 'Y-m-d',
			default => 'd.m.Y',
		};
	}
	return str_replace('d.m.Y', $map, $pattern);
}

/** Language as chosen: the variant (en-US …) or the code (de …) – for menus, <html lang> and links. */
function nu_lang_choice(): string
{
	return nu_lang_variant() ?: nu_lang();
}

/** Entries of the language menu: choice code => own name (English as its variants). */
function nu_lang_choices(): array
{
	$out = [];
	foreach (NU_LANGS as $code => [$own]) {
		if (!NuLanguages::has($code)) continue;              // installed languages only
		if (isset(NU_LANG_VARIANT_DEFAULT[$code])) {
			foreach (NuLanguages::variants($code) as $vc) $out[$vc] = NU_LANG_VARIANTS[$vc][1];   // offered variants only
		} else {
			$out[$code] = $own;
		}
	}
	return $out;
}

/** Sets the language: a code (de) or a variant (en-US). A plain 'en' keeps a variant chosen before. */
function nu_set_lang(string $lang): void
{
	$base = NU_LANG_VARIANTS[$lang][0] ?? $lang;
	if (!NuLanguages::has($base)) $lang = NuLanguages::fallback();   // not installed
	if (isset(NU_LANG_VARIANTS[$lang]) && !NuLanguages::hasVariant($lang)) $lang = NuLanguages::defaultVariant(NU_LANG_VARIANTS[$lang][0]);   // not offered
	if (isset(NU_LANG_VARIANTS[$lang])) {
		$_SESSION[NuSess::LANG] = NU_LANG_VARIANTS[$lang][0];
		$_SESSION[NuSess::LANG_VARIANT] = $lang;
		return;
	}
	$_SESSION[NuSess::LANG] = isset(NU_LANGS[$lang]) ? $lang : NuLanguages::fallback();
	if ((NU_LANG_VARIANTS[$_SESSION[NuSess::LANG_VARIANT] ?? ''][0] ?? '') !== $_SESSION[NuSess::LANG]) unset($_SESSION[NuSess::LANG_VARIANT]);
}

/** nuBuilder stores language names ('German', 'French', … '' = default); unknown names count as English (as nuBuilder). */
function nu_lang_from_db(?string $value): ?string
{
	$v = strtolower(trim((string)$value));
	if ($v === '') return null;
	foreach (NU_LANGS as $code => [$own, $db]) if ($v === strtolower($db) || $v === mb_strtolower($own)) return $code;
	$alias = ['deutsch' => 'de', 'francais' => 'fr', 'espanol' => 'es', 'portugues' => 'pt', 'chinese (simplified)' => 'zh', 'simplified chinese' => 'zh', '中文' => 'zh',
		'nederlands' => 'nl', 'flemish' => 'nl', 'polski' => 'pl', 'arabic (standard)' => 'ar', 'عربي' => 'ar'];
	return $alias[$v] ?? nu_lang_code($v) ?? 'en';
}

function nu_lang_to_db(string $lang): string
{
	return NU_LANGS[$lang][1] ?? 'English';
}

/** Locale for number / date formatting in the browser (Intl), e.g. de-CH. */
function nu_locale(): string
{
	$v = nu_lang_variant();
	if ($v !== '') return NU_LANG_VARIANTS[$v][2];
	return ['de' => 'de-DE', 'en' => 'en-GB', 'fr' => 'fr-CH', 'it' => 'it-CH', 'es' => 'es-ES', 'pt' => 'pt-PT', 'ru' => 'ru-RU', 'zh' => 'zh-CN',
		'nl' => 'nl-NL', 'pl' => 'pl-PL', 'ar' => 'ar-u-nu-latn'][nu_lang()] ?? 'en';   // Arabic with Western digits (as in the data)
}

function t(string $key, mixed ...$args): string
{
	if (isset(NU_USER_LABELS[$key]) && ($own = nu_user_label($key)) !== '') return $own;   // nuBuilder Forte's own names for Code / Additional 1 / 2
	return nu_text($key, $args);
}

/** The text of the language file itself – t() without the own names of NU_USER_LABELS (e.g. to match older import files). */
function nu_text(string $key, array $args = []): string
{
	static $dict = [];
	$lang = nu_lang();
	$dict[$lang] ??= require NU_ROOT . "/lang/$lang.php";
	$dict['en']  ??= require NU_ROOT . '/lang/en.php';
	$var = nu_lang_variant();                               // en-AU / en-US: only the texts that differ, over lang/en.php
	if ($var !== '' && $var !== NU_LANG_VARIANT_DEFAULT[$lang]) {
		$dict[$var] ??= is_file(NU_ROOT . "/lang/$var.php") ? (require NU_ROOT . "/lang/$var.php") : [];
		if (isset($dict[$var][$key])) return $args ? vsprintf($dict[$var][$key], $args) : $dict[$var][$key];
	}
	$s = $dict[$lang][$key] ?? $dict['en'][$key] ?? $key;
	return $args ? vsprintf($s, $args) : $s;
}

/** Text keys of the user fields that nuBuilder Forte lets you rename (Setup → Settings, category Form) → the setting. */
const NU_USER_LABELS = ['user_code' => 'nuConfigUserCodeLabel', 'user_additional1' => 'nuConfigUserAdditional1Label', 'user_additional2' => 'nuConfigUserAdditional2Label'];

/**
 * The own label of a user field (as nuBuilder Forte's nuuser_BE: nuSetLabelText(…, true)) – $nuConfigUserCodeLabel /
 * $nuConfigUserAdditional1Label / $nuConfigUserAdditional2Label of nuconfig.php or (with $nuConfigSettingsFromDB) of the settings
 * table, through the translations; '' = not set. Only the text changes – the columns sus_code / sus_additional1 / 2 stay.
 * Never connects by itself (installer, command line, error pages keep the built-in texts) and never reenters (a failing read → t()).
 */
function nu_user_label(string $key): string
{
	static $cache = [], $busy = false;
	if (array_key_exists($key, $cache)) return $cache[$key];
	if ($busy || !isset(NU_USER_LABELS[$key]) || !NuDB::$connected || NuInstall::needed()) return '';
	$busy = true;
	$out = '';
	try {
		$v = nu_cfg(NU_USER_LABELS[$key]);
		if (is_scalar($v) && trim((string)$v) !== '') $out = NuTranslate::format(trim((string)$v), []);
	} catch (Throwable $e) {
		$out = '';
	} finally {
		$busy = false;
	}
	return $cache[$key] = $out;
}

// ===================================================================== errors

/** A server path for the screen (UI review): relative to nuBuilder Next's folder, or ../… for the folder around it (nuBuilder Forte);
 *  other paths stay as they are. */
function nu_short_path(string $path): string
{
	$p = str_replace('\\', '/', $path);
	$root = str_replace('\\', '/', NU_ROOT);
	if (str_starts_with($p, $root . '/')) return substr($p, strlen($root) + 1);
	$up = dirname($root);
	if ($up !== '' && $up !== '/' && str_starts_with($p, $up . '/')) return '../' . substr($p, strlen($up) + 1);
	return $path;
}

function nu_fatal(string $title, string $detail = '', bool $withHome = false): never
{
	if (PHP_SAPI === 'cli') {                          // nucron.php: plain text and exit code 1 – cron then sees the failure (was the HTML page with exit code 0)
		fwrite(STDERR, trim(strip_tags($title) . ($detail !== '' ? ' – ' . strip_tags($detail) : '')) . "\n");
		exit(1);
	}
	if (!headers_sent()) http_response_code(http_response_code() >= 400 ? http_response_code() : 500);
	nu_page_minimal($title, $detail, $withHome);
	exit;
}

// ===================================================================== form list (zzzzsys_form)

/**
 * All forms an access level can be given rights to: [id => ['code','description','type','system','status','table','real']].
 * Falls back to the two system forms this app uses when zzzzsys_form is absent ('real' false = not a row of zzzzsys_form).
 * Read once per request (code review R6 / R9: NuMemo – a write to zzzzsys_form through NuDB drops it).
 */
function nu_forms(): array
{
	return NuMemo::get('forms:all', ['zzzzsys_form'], static function (): array {
		$forms = [];
		$db    = nu_db();
		if ($db->tableExists('zzzzsys_form')) {
			$sql = 'SELECT zzzzsys_form_id, sfo_code, sfo_description, sfo_type, sfo_table%s FROM zzzzsys_form ORDER BY sfo_code';
			try { $rows = $db->all(sprintf($sql, ', sfo_status')); } catch (Throwable) { $rows = $db->all(sprintf($sql, '')); }   // older databases: no status
			foreach ($rows as $r) {
				$forms[$r['zzzzsys_form_id']] = [
					'code'        => (string)$r['sfo_code'],
					'description' => (string)$r['sfo_description'],
					'type'        => (string)$r['sfo_type'],
					'system'      => str_starts_with((string)$r['zzzzsys_form_id'], 'nu'),
					'status'      => (string)($r['sfo_status'] ?? ''),
					'table'       => (string)($r['sfo_table'] ?? ''),
					'real'        => true,
				];
			}
		}
		foreach ([NuAcl::FORM_USER => 'Users', NuAcl::FORM_ACCESS => 'Access Levels', NuAcl::FORM_FORM => 'Forms', NuAcl::FORM_LOG => 'nuBuilder Next Log',
			NuAcl::FORM_SETUP => 'Setup', NuAcl::FORM_EMAILLOG => 'E-Mail Log', NuAcl::FORM_FORMAT => 'Formats', NuAcl::FORM_EMAILTEMPLATE => 'Email Templates',
			NuAcl::FORM_ITEM => 'Items', NuAcl::FORM_FILE => 'Files', NuAcl::FORM_TRANSLATE => 'Translation', NuAcl::FORM_DEBUG => 'Debug Results',
			NuAcl::FORM_PROCEDURE => 'Procedures', NuAcl::FORM_PERMISSION => 'Permission Items'] as $id => $desc) {
			$forms[$id] ??= ['code' => $id, 'description' => $desc, 'type' => 'browseedit', 'system' => true, 'status' => '', 'table' => '', 'real' => false];
		}
		return $forms;
	});
}

/**
 * One form of zzzzsys_form as nu_forms() keeps it (code, description, type, status, table), or null when there is no such row – ids are
 * compared as the database does (case and trailing spaces do not count). Code review R6: instead of a SELECT per place.
 */
function nu_form(string $id): ?array
{
	if ($id === '') return null;
	$all = nu_forms();
	$f = $all[$id] ?? null;
	if ($f === null) {
		$k = mb_strtolower(rtrim($id, ' '));
		$map = NuMemo::get('forms:lower', ['zzzzsys_form'], static function () use ($all): array {
			$m = [];
			foreach (array_keys($all) as $fid) $m[mb_strtolower(rtrim((string)$fid, ' '))] ??= (string)$fid;
			return $m;
		});
		$f = isset($map[$k]) ? $all[$map[$k]] : null;
	}
	return $f && $f['real'] ? $f : null;
}

function nu_form_exists(string $id): bool
{
	return nu_form($id) !== null;
}

function nu_form_label(string $id): string
{
	if ($id === 'nublank') return t('form_blank_page');   // nuBuilder Forte's empty launch form "Blank"
	$f = nu_forms()[$id] ?? null;
	if (!$f) return $id;
	return $f['description'] !== '' ? $f['code'] . ' – ' . $f['description'] : $f['code'];
}

/** Rights on formats (zzzzsys_format) for the browser. */
function nu_format_rights(): array
{
	return ['add' => NuAcl::can(NuAcl::FORM_FORMAT, 'add'), 'create' => NuAcl::can(NuAcl::FORM_FORMAT, 'create'),
		'save' => NuAcl::can(NuAcl::FORM_FORMAT, 'save'), 'delete' => NuAcl::can(NuAcl::FORM_FORMAT, 'delete')];
}

/** Labels and data the format designer (assets/js/nuformatdesigner.js) needs. */
function nu_format_designer_data(): array
{
	$labels = [];
	foreach (array_keys(require NU_ROOT . '/lang/en.php') as $k) if (str_starts_with($k, 'fmt_')) $labels[$k] = t($k);
	foreach (['save', 'cancel', 'delete', 'yes', 'no'] as $k) $labels[$k] = t($k);
	return ['csrf' => nu_csrf_token(), 'labels' => $labels, 'rights' => nu_format_rights(), 'max' => NuFormat::MAX_KEY - 2,
		'client' => (object)NuFormat::clientRegistry()];
}

// ===================================================================== validation helpers

/** Adds an error when $value exceeds the column length. */
function nu_maxlen(array &$errors, string $field, string $value, int $max): void
{
	if (mb_strlen($value) > $max) $errors[$field] = t('err_too_long', $max);
}

function nu_valid_date(string $ymd): bool
{
	$d = DateTime::createFromFormat('!Y-m-d', $ymd);
	return $d && $d->format('Y-m-d') === $ymd;
}

/** Normalises DB datetime values ('2026-10-14 00:00:00.000', DateTime, '0000-00-00…') to 'Y-m-d' or ''. */
function nu_date_only(mixed $v): string
{
	if ($v instanceof DateTimeInterface) return $v->format('Y-m-d');
	$v = trim((string)$v);
	if ($v === '' || str_starts_with($v, '0000')) return '';
	return substr($v, 0, 10);
}

function nu_is_expired(mixed $expiresOn): bool
{
	$d = nu_date_only($expiresOn);
	return $d !== '' && $d < date('Y-m-d');      // valid through the stored day
}

/**
 * Delete / Archive dialog for users (users list and user form). Opened by a button with data-bs-toggle="modal"
 * data-bs-target="#nuUserRemove" data-choice-action (user form URL), data-choice-name, data-choice-archived ("1" = already expired).
 * $hidden: extra hidden fields (return = list query to go back to, stay = 1: back to the user form after archiving).
 */
function nu_user_remove_modal(array $rights, array $hidden = []): string
{
	if (!$rights['delete'] && !$rights['save']) return '';
	ob_start(); ?>
<div class="modal fade nuRowChoice" id="nuUserRemove" tabindex="-1" aria-labelledby="nuUserRemoveTitle" aria-hidden="true">
	<div class="modal-dialog modal-dialog-centered">
		<form class="modal-content" method="post" action="">
			<?= nu_csrf_field() ?><?php foreach ($hidden as $k => $v): ?><input type="hidden" name="<?= h($k) ?>" value="<?= h($v) ?>"><?php endforeach; ?>
			<div class="modal-header">
				<h2 class="modal-title fs-5" id="nuUserRemoveTitle"><?= h(t('user_remove')) ?>: <span class="js-choice-name"></span></h2>
				<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= h(t('close')) ?>"></button>
			</div>
			<div class="modal-body">
				<?php if ($rights['save']): ?>
					<p class="mb-2"><i class="bi bi-archive me-2 text-warning"></i><strong><?= h(t('user_archive')) ?>:</strong> <?= h(t('user_archive_hint')) ?></p>
					<p class="small text-body-secondary js-choice-archived-note" hidden><?= h(t('user_archive_already')) ?></p>
				<?php endif; ?>
				<?php if ($rights['delete']): ?>
					<p class="mb-0"><i class="bi bi-trash me-2 text-danger"></i><strong><?= h(t('delete')) ?>:</strong> <?= h(t('user_delete_hint')) ?></p>
				<?php endif; ?>
			</div>
			<div class="modal-footer">
				<button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><?= h(t('cancel')) ?></button>
				<?php if ($rights['save']): ?><button type="submit" class="btn btn-warning js-choice-archive" name="action"
					value="archive"><i class="bi bi-archive me-1"></i><?= h(t('user_archive')) ?></button><?php endif; ?>
				<?php if ($rights['delete']): ?><button type="submit" class="btn btn-danger" name="action" value="delete"><i class="bi bi-trash me-1"></i><?= h(t('delete')) ?></button><?php endif; ?>
			</div>
		</form>
	</div>
</div>
<?php
	return (string)ob_get_clean();
}
