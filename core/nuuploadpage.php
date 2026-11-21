<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/** Functions of the page pages/nuupload.php (code review R7: a page defines no global function). */
final class NuUploadPage
{
	/** Ends with nuBuilder's error answer (401, {error, message}); the message is translated like nuBuilder's (zzzzsys_translate). */
	public static function fail(string $message): never
	{
		echo nuSetUploadError(nuTranslate($message));
		exit;
	}
}
