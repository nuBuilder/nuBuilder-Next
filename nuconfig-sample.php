<?php

// -> Rename nuconfig-sample.php to nuconfig.php
// -> Same file format and variable names as nuBuilder 4: an existing nuBuilder nuconfig.php works unchanged
//    (a missing $nuConfigDBType means "mysql"). nuBuilder Next reads this file on every request, no browser restart needed.


// ---------------------------------------------------------------------------------------------------------
// Database Settings
// ---------------------------------------------------------------------------------------------------------

$nuConfigDBType = "mysql";								//-- mysql (MySQL, MariaDB) or sqlsrv (MSSQL)

if ($nuConfigDBType == "mysql") {

	$nuConfigDBHost						= "127.0.0.1";				//-- Database Host / IP. You may try localhost if 127.0.0.1 does not work.
	$nuConfigDBPort						= 3306;						//-- Database port.
	$nuConfigDBName						= "nubuilder4";				//-- Database Name. The database must exist on your server.
	$nuConfigDBUser						= "root";					//-- Database User. It is strongly recommended to use a different user than root.
	$nuConfigDBPassword					= "";						//-- Database Password. We strongly recommend a strong password.

} else {

	$nuConfigDBHost						= 'localhost\SQLEXPRESS';	//-- Server or Server\Instance (single quotes keep the backslash).
	$nuConfigDBPort						= "";						//-- Leave empty for named instances, otherwise e.g. 1433.
	$nuConfigDBName						= "nubuilder";				//-- Database Name.
	$nuConfigDBUser						= "sa";						//-- Database User. A dedicated user is recommended.
	$nuConfigDBPassword					= "";						//-- Database Password.
	$nuConfigDBTrustServerCertificate	= true;						//-- ODBC Driver 18+ encrypts by default; true accepts self-signed certificates.

}

$nuConfig2FAAdmin						= false;					//-- true: globeadmin needs a second factor (authenticator app or e-mail code)
$nuConfig2FAUser						= false;					//-- true: users of access levels with "Use 2FA" need a second factor

$nuConfigDBEngine						= "InnoDB";					//-- Keep InnoDB: nuBuilder Next creates every table as InnoDB, and nuBuilder's update converts its tables to this engine
$nuConfigDBCollate						= "utf8mb4_unicode_ci";		//-- Or utf8_general_ci etc. (MySQL only)
$nuConfigDBCharacterSet					= "utf8mb4";				//-- Or utf8 etc. (MySQL only)
$nuConfigDBOptions						= [];						//-- PDO Options: E.g. [PDO::ATTR_PERSISTENT => true]


// ---------------------------------------------------------------------------------------------------------
// Administrator Login
// ---------------------------------------------------------------------------------------------------------

$nuConfigDBGlobeadminUsername			= "globeadmin";				//-- Administrator username. You can choose any username you like.
$nuConfigDBGlobeadminPassword			= "nu";						//-- Administrator password. Please choose a stronger password!


// ---------------------------------------------------------------------------------------------------------
// nuBuilder Next only (optional, nuBuilder ignores these)
// ---------------------------------------------------------------------------------------------------------

$nuConfigPasswordUpgrade				= true;						//-- Rehash legacy md5 passwords to bcrypt on successful login.
																	//-- Set false if an old nuBuilder on the same DB only understands md5.
$nuConfigSessionTimeout					= 120;						//-- Minutes of inactivity before logout.
$nuConfigSessionTimeoutGlobeadmin		= 720;						//-- Minutes of inactivity before the globeadmin is logged out (720 = 12 hours).
$nuConfigPasswordMinLength				= 8;						//-- Minimum length for new passwords.
//$nuConfigImportMaxMB					= 20;						//-- CSV import (Admin tools): largest file in MB (also limited by PHP's upload_max_filesize / post_max_size).
//$nuConfigWelcomeLinkHours				= 72;						//-- User form "Send welcome e-mail": hours the link to set the password stays valid (default 72).
$nuConfigDebug							= false;					//-- true shows database error details on screen.
$nuConfigIncludePHP						= [];						//-- PHP files with your global helper functions for form hooks/procedures,
																	//-- e.g. ['custom/functions.php'] (custSendEmail() etc.)


// Security (nuBuilder Next only)
$nuConfig2FATrustDays					= 30;						//-- "Remember this device" for N days after 2FA (0 = always ask).
$nuConfig2FAIssuer						= "nuBuilder Next";					//-- Name shown in the authenticator app.
$nuConfigGlobeadminEmail				= "";						//-- E-mail for globeadmin 2FA codes (optional; the app works without).
$nuConfigSecretKey						= "";						//-- Optional long random string: encrypts authenticator secrets in the database (and keys the auto-login hashes).
$nuConfigAutoLogin						= false;					//-- true: nuBuilder's auto login index.php?u=<login>&p=<hash> (hash from the user form, never a plain password; not for the globeadmin).
$nuConfigDemoUser						= "";						//-- Demo mode: login name or user id of a user (several: comma-separated) who sees everything read only; the login page gets "Try the demo".
$nuConfigDemoSavingAllowedIds			= "";						//-- Demo mode (nuBuilder's setting): ids or codes of forms where demo users may still save (never delete), comma-separated.
$nuConfigSettingsFromDB					= false;					//-- true: nuBuilder's settings table (Admin tools → Settings) replaces the values set here, as in nuBuilder.
//$nuConfigShowInstaller				= false;					//-- Setup wizard after the globeadmin's login: false = done (the wizard writes it), true = show it again; missing = show it once.
$nuConfigValidationMarkers				= true;						//-- Edit forms: red * after labels of required fields (No blanks), blue ◆ for unique values (No duplicates), legend below.
$nuConfigApi								= false;					//-- true: REST API api.php/v1/… (clients: Admin tools → API clients). HTTPS only.
$nuConfigApiTokenMinutes				= 15;						//-- Lifetime of API access tokens in minutes.
//$nuConfigApiAllowHttp					= false;					//-- true: allow the API without HTTPS (tests only).
$nuConfigLogRecordChanges				= true;						//-- Log changed fields when records are saved/deleted in forms.
$nuConfigBaseURL						= "";						//-- Address users open nuBuilder Next with, e.g. "https://www.example.com/nunext" – for complete links in e-mails (forgot password, welcome mail), auto-login links and API addresses. Empty = taken from the page request (can be wrong behind a proxy) – set it on a live server.
$nuConfigTrustProxy						= false;					//-- true behind a reverse proxy: take the client IP from X-Forwarded-For.

// Shared login with nuBuilder (same domain, same server)
$nuConfigSharedLogin					= false;					//-- true: users logged in to nuBuilder are logged in to nuBuilder Next automatically.
$nuConfigNuBuilderURL					= "";						//-- e.g. "/" or "https://www.example.com/" – adds a link back to nuBuilder.
//$nuConfigNuBuilderSessionName			= "";						//-- Only if nuBuilder uses a different session name than the database name.

// E-mail. The SMTP settings are edited in nuBuilder Next (E-mail → Settings) and stored in zzzzsys_setup, like in nuBuilder.
// PHPMailer is loaded from nuBuilder (<nuBuilder>/third_party/phpmailer/), otherwise from nuBuilder Next's lib/PHPMailer.
//$nuConfigNuBuilderPath				= "";						//-- File system path of nuBuilder; default: the folder above nuBuilder Next.
//$nuConfigPHPMailerPath				= "";						//-- Folder with PHPMailer.php, SMTP.php, Exception.php (overrides the search).
//$nuConfigFontAwesomePath				= "";						//-- Folder with css/all.min.css; default: <nuBuilder>/third_party/fontawesome.
// Only to override zzzzsys_setup (e.g. a separate account for nuBuilder Next). Used when $nuConfigSMTPHost is not empty.
//$nuConfigSMTPHost						= "";
//$nuConfigSMTPPort						= 587;
//$nuConfigSMTPUsername					= "";
//$nuConfigSMTPPassword					= "";
//$nuConfigSMTPSecure					= "tls";					//-- tls, ssl or none
//$nuConfigSMTPFromAddress				= "";
//$nuConfigSMTPFromName					= "nuBuilder Next";
//$nuConfigMailLog						= "mail.log";				//-- Testing: write mails to this file instead of sending them.

$nuConfigTimezone						= "Europe/Zurich";			//-- Time zone for log entries, audit columns and codes.
//$nuConfigDevMode						= true;						//-- Developer mode for the globeadmin: #DEV_MODE# = 1, nuDevMode() = true (as nuBuilder).
$nuConfigFileMaxKB						= 300;						//-- Maximum size of files uploaded into form fields (KB).

// Admin tools for the globeadmin (user menu): nuBuilder's phpMyAdmin and Tiny File Manager, NOT part of the package.
// Copy <nuBuilder>/third_party/nudb/ to <nuBuilder Next>/third_party/nudb/ and <nuBuilder>/third_party/tinyfilemanager/ to
// <nuBuilder Next>/third_party/tinyfilemanager/ (with their nuBuilder config files). MySQL / MariaDB only.
//$nuConfigPMAURL						= "";						//-- Other URL of phpMyAdmin, e.g. "/nubuilder4/third_party/nudb/"
//$nuConfigTFMURL						= "";						//-- Other URL of Tiny File Manager
// Adminer (optional, NOT part of the package): copy adminer.php (or adminer-<version>.php, 5.x recommended) to
// <nuBuilder Next>/third_party/adminer/ – it opens through dbadmin.php, logged in with the account above (also SQL Server).
//$nuConfigPrimaryDatabaseManagement	= "phpMyAdmin";				//-- Database buttons open "phpMyAdmin" or "Adminer" (▾ = the other one). Not set: phpMyAdmin when installed, else Adminer.

// Updates (Admin tools → Update): backups of the program files and nuBuilder Next's tables, and the maintenance flag.
//$nuConfigBackupPath					= "";						//-- Default <nuBuilder Next>/_backup (blocked by .htaccess on Apache). Better: a folder outside the web root.

// Own login page (same variable as nuBuilder, so nuBuilder's HTML works as it is). Replaces nuBuilder Next's login card; error messages,
// the "Forgot password?" link and the language / theme menus stay. Needs a <form> with a username and a password input
// (name="username" / name="password", or nuBuilder's ids nuusername / nupassword). style="…" is allowed, but inline
// scripts and onclick="…" are not run (nuBuilder Next wires the form itself). Image paths "core/graphics/…" point to nuBuilder's folder.
/*
$nuWelcomeBodyInnerHTML = "

	<div id='outer' style='width:100%'>
	<form id='nuLoginForm' action='#' method='post' onsubmit='return false'>
		<div id='login' class='nuLogin'>
			<table>
				<tr>
					<td align='center' style='text-align:center;'>
					<img src='core/graphics/logo.png'><br><br>
					</td>
				</tr>
				<tr>
					<td><div style='width:90px; margin-bottom: 5px;'>Username</div><input class='nuLoginInput' id='nuusername' autocomplete='off' /><br><br></td>
				</tr>
				<tr>
					<td><div style='width:90px; margin-bottom: 5px;'>Password</div><input class='nuLoginInput' id='nupassword' type='password' autocomplete='off' /><br></td>
				</tr>
				<tr>
					<td style='text-align:center' colspan='2'><br><br>
						<input id='submit' style='width:90px' type='submit' class='nuButton' value='Log in'/>
					</td>
				</tr>
			</table>
		</div>
	</form>
	</div>

";
*/
