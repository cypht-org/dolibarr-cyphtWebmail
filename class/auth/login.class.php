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
require_once __DIR__ . '/../runtime/language.class.php';
require_once __DIR__ . '/../runtime/theme.class.php';

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
	 * Log the Dolibarr user into Cypht in-process via cypht_login(), which
	 * sets the hm_id/hm_session cookies. Must run before any HTML output.
	 *
	 * Skipped when hasLiveSsoSession() finds one: index.php calls this every
	 * page load and cypht_login() resets the session data, which discarded
	 * settings Cypht had not yet flagged for permanent storage.
	 *
	 * @param string $login Dolibarr username to log into Cypht as
	 * @param string $cyphtUrl URL of the published Cypht app, need not be
	 *                          absolute already, see absolutizeUrl()
	 * @param string $userLang Language Dolibarr resolved for this user, e.g.
	 *                          fr_FR. Preferences reach Cypht only through a
	 *                          fresh login, so a live session whose preferences
	 *                          no longer match is replaced rather than reused.
	 * @return bool true if Cypht accepted the SSO token, or a live session
	 *              already existed and was left alone
	 */
	public function performSsoLogin($login, $cyphtUrl, $userLang = '')
	{
		$prefs = $this->preferenceFingerprint($userLang);

		if ($this->hasLiveSsoSession($login, $prefs)) {
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

		$this->exportPreferences($userLang);

		require_once $apiFile;

		if (!$this->cyphtCanAuthenticate()) {
			return false;
		}

		$token = $this->token->generateSsoLoginToken($login);

		$ok = cypht_login($login, $token, $this->absolutizeUrl($cyphtUrl));
		if ($ok) {
			$this->rememberSsoSession($login, $prefs);
		}

		return $ok;
	}

	/**
	 * The interface language to apply, empty when each user keeps their own.
	 *
	 * @param string $userLang Dolibarr language code
	 * @return string
	 */
	private function resolvedLanguage($userLang)
	{
		if (getDolGlobalString('CYPHTWEBMAIL_LANG_MODE', 'follow') !== 'follow') {
			return '';
		}

		return CyphtLanguage::toCyphtCode($userLang);
	}

	/**
	 * The theme to apply, empty when each user keeps their own.
	 *
	 * @return string
	 */
	private function resolvedTheme()
	{
		if (getDolGlobalString('CYPHTWEBMAIL_THEME_MODE', 'follow') !== 'follow') {
			return '';
		}

		return CyphtTheme::forDarkMode(getDolGlobalInt('THEME_DARKMODEENABLED'));
	}

	/**
	 * '1' when the dark half should be left to the browser, as it is on the
	 * surrounding page, and '' otherwise.
	 *
	 * @return string
	 */
	private function resolvedThemeAuto()
	{
		if (getDolGlobalString('CYPHTWEBMAIL_THEME_MODE', 'follow') !== 'follow') {
			return '';
		}

		return CyphtTheme::followsBrowser(getDolGlobalInt('THEME_DARKMODEENABLED')) ? '1' : '';
	}

	/**
	 * Hand the preferences to the dolibarr_prefs module set, which reads them
	 * while cypht_login() has the user config open.
	 *
	 * @param string $userLang Dolibarr language code
	 * @return void
	 */
	private function exportPreferences($userLang)
	{
		$values = array(
			'DOLIBARR_USER_LANG' => $this->resolvedLanguage($userLang),
			'DOLIBARR_USER_THEME' => $this->resolvedTheme(),
			'DOLIBARR_USER_THEME_AUTO' => $this->resolvedThemeAuto(),
		);

		foreach ($values as $key => $value) {
			$_ENV[$key] = $value;
			putenv($key . '=' . $value);
		}
	}

	/**
	 * Short digest of the preferences a login would apply right now.
	 *
	 * @param string $userLang Dolibarr language code
	 * @return string
	 */
	private function preferenceFingerprint($userLang)
	{
		$parts = $this->resolvedLanguage($userLang) . '|' . $this->resolvedTheme()
			. '|' . $this->resolvedThemeAuto();

		return substr(hash('sha256', $parts), 0, 16);
	}

	/**
	 * The two gates api_login/api.php:106-111 puts in front of Custom_Auth,
	 * checked here so a failure names itself.
	 *
	 * Runs after api.php, which is what loads the environment and the config
	 * classes this reads.
	 *
	 * @return bool
	 */
	private function cyphtCanAuthenticate()
	{
		if (!class_exists('Hm_Site_Config_File')) {
			$this->error = 'The webmail loaded but its configuration classes did not; the build looks incomplete.';
			return false;
		}

		$config = new Hm_Site_Config_File();
		$modules = $config->get_modules();

		if (!is_array($modules) || !in_array('site', $modules, true)) {
			$this->error = 'The webmail module list resolved without "site", so its authentication class is never loaded. '
				. 'config/app.php reads this through env(), which is getenv() only, so a correct .env on disk does not '
				. 'guarantee it. Resolved ' . (is_array($modules) ? count($modules) . ' modules' : gettype($modules)) . '.';
			return false;
		}

		$lib = $this->paths->getCyphtPath() . '/modules/site/lib.php';
		if (!is_readable($lib)) {
			$this->error = 'Cannot read ' . $lib . ', which declares the webmail authentication class.';
			return false;
		}

		return true;
	}

	/**
	 * Give the Cypht code loaded below the same configuration public/index.php
	 * runs with.
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
	 * True when our login cookie matches, Cypht's hm_session cookie is set,
	 * and the session file it names is still on disk.
	 *
	 * @param string $login
	 * @param string $prefs Digest from preferenceFingerprint()
	 * @return bool
	 */
	private function hasLiveSsoSession($login, $prefs = '')
	{
		if (empty($_COOKIE['hm_session']) || empty($_COOKIE['hm_id']) || empty($_COOKIE[$this->ssoUserCookieName()])) {
			return false;
		}

		if (!hash_equals($login . '|' . $prefs, (string) $_COOKIE[$this->ssoUserCookieName()])) {
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
	 * @param string $prefs Digest from preferenceFingerprint()
	 * @return void
	 */
	private function rememberSsoSession($login, $prefs = '')
	{
		$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
		setcookie($this->ssoUserCookieName(), $login . '|' . $prefs, array(
			'path' => '/',
			'secure' => $secure,
			'httponly' => true,
			'samesite' => 'Lax',
		));
	}

	/**
	 * cypht_login() needs an absolute URL for the cookie domain, and
	 * dol_buildpath() can return a host-relative one.
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
