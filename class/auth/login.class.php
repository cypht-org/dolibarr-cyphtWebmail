<?php
/* Copyright (C) 2026  Camile   <camilevahviraki@gmail.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published
 * by the Free Software Foundation; either version 2.1 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

require_once __DIR__ . '/../install/paths.class.php';
require_once __DIR__ . '/token.class.php';
require_once __DIR__ . '/../runtime/language.class.php';
require_once __DIR__ . '/../runtime/theme.class.php';

/**
 * \file        class/auth/login.class.php
 * \ingroup     cyphtWebmail
 * \brief       Logs the Dolibarr user into Cypht (SSO).
 */
class CyphtLogin
{
	/**
	 * @var string  Last error message
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
	 * Log the Dolibarr user into Cypht with cypht_login(), which sets the
	 * hm_id/hm_session cookies. Must run before any output.
	 *
	 * @param string $login Dolibarr login
	 * @param string $cyphtUrl URL of the Cypht app (can be relative)
	 * @param string $userLang Dolibarr language of the user, e.g. fr_FR. A
	 *                          session with different preferences is replaced.
	 * @return bool true if logged in or already logged in
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
	 * Language to apply, or empty if users choose their own.
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
	 * Theme to apply, or empty if users choose their own.
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
	 * '1' if dark mode should follow the browser setting, '' otherwise.
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
	 * '1' for the HTML editor, or empty if users choose their own format.
	 *
	 * @return string
	 */
	private function resolvedComposeType()
	{
		return getDolGlobalString('CYPHTWEBMAIL_COMPOSE_MODE', 'html') === 'html' ? '1' : '';
	}

	/**
	 * Put the preferences in $_ENV for the dolibarr_prefs module set to
	 * read during cypht_login().
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
			'DOLIBARR_USER_COMPOSE_TYPE' => $this->resolvedComposeType(),
		);

		foreach ($values as $key => $value) {
			$_ENV[$key] = $value;
		}
	}

	/**
	 * Short hash of the current preferences.
	 *
	 * @param string $userLang Dolibarr language code
	 * @return string
	 */
	private function preferenceFingerprint($userLang)
	{
		$parts = $this->resolvedLanguage($userLang) . '|' . $this->resolvedTheme()
			. '|' . $this->resolvedThemeAuto() . '|' . $this->resolvedComposeType();

		return substr(hash('sha256', $parts), 0, 16);
	}

	/**
	 * Check what api_login/api.php needs before calling Custom_Auth, so we
	 * get a clear error. Must run after api.php is loaded.
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
				. 'The list comes from config/zz_dolibarr.php, written by the build; rebuilding should restore it. '
				. 'Resolved ' . (is_array($modules) ? count($modules) . ' modules' : gettype($modules)) . '.';
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
	 * Load the same configuration as public/index.php.
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
	 * Name of the cookie that stores which Dolibarr login owns the Cypht session.
	 *
	 * @return string
	 */
	private function ssoUserCookieName()
	{
		return 'cyphtwebmail_ssouser';
	}

	/**
	 * Check that our cookie matches and the Cypht session file still exists.
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

		// Same naming as Custom_Session::session_file().
		$sessionFile = $this->paths->getDataDir() . '/sso_sessions/' . $sessionKey . '.session';

		return is_readable($sessionFile);
	}

	/**
	 * Save the login in a cookie for hasLiveSsoSession().
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
	 * Make the URL absolute. cypht_login() needs one for the cookie domain.
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
