<?php
declare(strict_types=1);
defined('NU_ROOT') || exit;

/** The work of pages/nuapiplayground.php (code review R7): its POST actions – the page reads the record, renders and shows the result. */
final class NuApiplaygroundPage
{
	/** The POST actions of pages/nuapiplayground.php – $nuIn: the page's variables before them; returned: what the page shows afterwards. */
	public static function post(array $nuIn): array
	{
		$login = $nuIn['login'] ?? null;
		if (nu_is_post()) {
			header('Content-Type: application/json; charset=utf-8');
			header('Cache-Control: no-store');
			$in = json_decode((string)file_get_contents('php://input'), true);
			$in = is_array($in) ? $in : [];
			$out = ['ok' => false];
			switch ((string)($in['action'] ?? '')) {
				case 'token':
					[$res, $err] = NuApiClient::issueFor((string)($in['client_ref'] ?? ''), (string)($in['scope'] ?? ''), $login);
					$out = $res ? ['ok' => true, 'token' => $res] : ['ok' => false, 'error' => t('apg_err_token', (string)$err)];
					break;
				case 'save':
					[$req, $errors] = NuApiPlayground::save((array)($in['request'] ?? []), $login);
					$out = $req ? ['ok' => true, 'request' => $req, 'requests' => NuApiPlayground::requests()] : ['ok' => false, 'errors' => $errors, 'error' => t('err_check_form')];
					break;
				case 'delete':
					$out = NuApiPlayground::delete((string)($in['id'] ?? '')) ? ['ok' => true, 'requests' => NuApiPlayground::requests()] : ['ok' => false, 'error' => t('record_not_found')];
					break;
				case 'settings':
					$out = ['ok' => true, 'settings' => NuApiPlayground::saveSettings((array)($in['settings'] ?? []), $login)];
					break;
				default:
					http_response_code(400);
					$out = ['ok' => false, 'error' => 'Unknown action'];
			}
			nu_json($out);
		}
		return compact('login');
	}
}
