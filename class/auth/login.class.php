<?php
/* Copyright (C) 2026  Camile   <camilevahviraki@gmail.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

require_once __DIR__ . '/../install/paths.class.php';
require_once __DIR__ . '/token.class.php';

/**
 * \file        class/auth/login.class.php
 * \ingroup     cyphtWebmail
 * \brief       Logs the current Dolibarr user into Cypht in-process, and
 *              tracks whether a live session already exists.
 */
class CyphtLogin
{
	/**
	 * @var string  Last error message, if any call returned false/failure.
	 */
	public $error = '';

	/**
	 * @var CyphtPaths
	 */
	private $paths;

	/**
	 * @var CyphtToken
	 */
	private $token;

	/**
	 * @param CyphtPaths $paths
	 * @param CyphtToken $token
	 */
	public function __construct(CyphtPaths $paths, CyphtToken $token)
	{
		$this->paths = $paths;
		$this->token = $token;
	}

	/**
	 * Logs the given Dolibarr user into Cypht via its "functional login"
	 * SSO option (calls cypht_login() in-process, setting the
	 * hm_id/hm_session cookies). Must be called before any HTML output.
	 *
	 * Skips the login entirely if a live session already exists for this
	 * user (see hasLiveSsoSession()): index.php calls this on
	 * every page load, and cypht_login() always resets the session data,
	 * which was silently discarding settings/servers that Cypht hadn't
	 * yet flagged for permanent storage. Requests made inside the iframe
	 * reuse the existing session cookie and never hit this path.
	 *
	 * @param string $login Dolibarr username to log into Cypht as
	 * @param string $cyphtUrl URL of the published Cypht app, need not be
	 *                          absolute already, see absolutizeUrl()
	 * @return bool true if Cypht accepted the SSO token, or a live session
	 *              already existed and was left alone
	 */
	public function performSsoLogin($login, $cyphtUrl)
	{
		if ($this->hasLiveSsoSession($login)) {
			return true;
		}

		$apiFile = $this->paths->getCyphtPath() . '/modules/api_login/api.php';
		if (!is_readable($apiFile)) {
			$this->error = 'modules/api_login/api.php not found; was the "api_login" module built into CYPHT_MODULES?';
			return false;
		}

		if (!$this->prepareCyphtEnvironment()) {
			return false;
		}

		require_once $apiFile;

		$token = $this->token->generateSsoLoginToken($login);

		$ok = cypht_login($login, $token, $this->absolutizeUrl($cyphtUrl));
		if ($ok) {
			$this->rememberSsoSession($login);
		}

		return $ok;
	}

	/**
	 * Give the Cypht code we are about to load the same configuration the
	 * published entry point runs with.
	 *
	 * modules/api_login/api.php bootstraps Cypht itself, reaching only the
	 * .env file. Since the per-installation values moved into the database,
	 * .env no longer carries SSO_SHARED_SECRET, so Custom_Auth compared the
	 * token against an empty secret and refused every login. The same
	 * bootstrap public/index.php uses is run here so the side that signs the
	 * token and the side that verifies it cannot read different values.
	 *
	 * @return bool
	 */
	private function prepareCyphtEnvironment()
	{
		require_once __DIR__ . '/../runtime/envbootstrap.class.php';

		$bootstrap = new CyphtEnvBootstrap($this->paths->getModuleRoot());
		if (!$bootstrap->apply()) {
			$this->error = 'Could not load the webmail configuration: ' . $bootstrap->error;
			return false;
		}

		/* api.php defines APP_PATH and VENDOR_PATH but not SITE_ID, which
		 * feeds Hm_Request_Key's fingerprint. Left undefined, the session
		 * minted here is fingerprinted differently from the one the iframe
		 * validates against, and the login is rejected on the next request.
		 *
		 * Minted here if provisioning has not run, rather than left empty:
		 * the published index.php falls back to the value baked at build
		 * time, which this process has no way to see. */
		if (!defined('SITE_ID')) {
			$siteId = empty($_ENV['CYPHT_SITE_ID'])
				? $this->token->getOrCreateSiteId()
				: $_ENV['CYPHT_SITE_ID'];

			if ($siteId !== '') {
				$_ENV['CYPHT_SITE_ID'] = $siteId;
				define('SITE_ID', $siteId);
			}
		}

		return true;
	}

	/**
	 * Cookie recording which Dolibarr login the current Cypht session
	 * belongs to, separate from Cypht's own hm_session/hm_id.
	 *
	 * @return string
	 */
	private function ssoUserCookieName()
	{
		return 'cyphtwebmail_ssouser';
	}

	/**
	 * True if this browser already has a valid, still-on-disk Cypht
	 * session for $login: our own login-tracking cookie matches, Cypht's
	 * own hm_session cookie is set, and the session file it names still
	 * exists on disk.
	 *
	 * @param string $login
	 * @return bool
	 */
	private function hasLiveSsoSession($login)
	{
		if (empty($_COOKIE['hm_session']) || empty($_COOKIE['hm_id']) || empty($_COOKIE[$this->ssoUserCookieName()])) {
			return false;
		}

		if (!hash_equals((string) $login, (string) $_COOKIE[$this->ssoUserCookieName()])) {
			return false;
		}

		$sessionKey = preg_replace('/[^a-f0-9]/', '', (string) $_COOKIE['hm_session']);
		if ($sessionKey === '') {
			return false;
		}

		// Mirrors Custom_Session::session_file()'s naming convention.
		$sessionFile = $this->paths->getDataDir() . '/sso_sessions/' . $sessionKey . '.session';

		return is_readable($sessionFile);
	}

	/**
	 * Records which Dolibarr login the session cypht_login() just
	 * established, for the next request's hasLiveSsoSession() check.
	 * Session-lifetime cookie only; it authenticates nothing itself.
	 *
	 * @param string $login
	 * @return void
	 */
	private function rememberSsoSession($login)
	{
		$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
		setcookie($this->ssoUserCookieName(), $login, array(
			'path' => '/',
			'secure' => $secure,
			'httponly' => true,
			'samesite' => 'Lax',
		));
	}

	/**
	 * cypht_login() needs a real absolute URL to work out the cookie
	 * domain; dol_buildpath() can return a host-relative one. Prepends
	 * the current request's scheme+host if $url doesn't have one already.
	 *
	 * @param string $url
	 * @return string
	 */
	private function absolutizeUrl($url)
	{
		if (preg_match('#^https?://#i', $url)) {
			return $url;
		}

		$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
		$host = !empty($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
		$path = (substr($url, 0, 1) === '/') ? $url : '/' . $url;

		return $scheme . '://' . $host . $path;
	}
}
