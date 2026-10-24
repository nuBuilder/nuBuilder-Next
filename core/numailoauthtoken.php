<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/*
 * PHPMailer token provider of NuMailOAuth (0.8.445) – loaded after PHPMailer (NuMailOAuth::tokenProvider). PHPMailer 6.6 and newer
 * have the interface OAuthTokenProvider (PHPMailer::setOAuth needs it); older copies call getOauth64() on any object.
 */
trait NuMailOAuthTokenBody
{
	public function __construct(private string $user)
	{
	}

	/** "user=<mailbox>\x01auth=Bearer <access token>\x01\x01", base64 – the XOAUTH2 string of RFC 7628 / Google / Microsoft. */
	public function getOauth64()
	{
		return base64_encode('user=' . $this->user . "\001auth=Bearer " . NuMailOAuth::accessToken() . "\001\001");
	}
}

if (interface_exists('PHPMailer\\PHPMailer\\OAuthTokenProvider')) {
	final class NuMailOAuthToken implements \PHPMailer\PHPMailer\OAuthTokenProvider
	{
		use NuMailOAuthTokenBody;
	}
} else {
	final class NuMailOAuthToken
	{
		use NuMailOAuthTokenBody;
	}
}
