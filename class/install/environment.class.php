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
require_once __DIR__ . '/../auth/token.class.php';
require_once __DIR__ . '/../integration/contactsource.class.php';
require_once __DIR__ . '/../integration/mailtemplatesource.class.php';

/**
 * \file        class/install/environment.class.php
 * \ingroup     cyphtWebmail
 * \brief       Builds and writes Cypht's .env file from Dolibarr constants.
 */
class CyphtEnvironment
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
	 * The half of the .env that owes nothing to Dolibarr.
	 *
	 * Static so an offline build can reach it, and shared with
	 * buildEnvOverrides() so the two build paths cannot compile different
	 * module lists. Contains no secrets, so it is safe to distribute.
	 *
	 * @return array<string,string>
	 */
	public static function buildTimeDefaults()
	{
		return array(
			'DB_CONNECTION_TYPE' => 'host',
			'SESSION_TYPE'     => 'custom',
			'AUTH_TYPE'        => 'custom',
			// Not 'file': Hm_User_Config_File keys its encryption on the login
			// password, which under SSO is a fresh token every request.
			'USER_CONFIG_TYPE' => 'custom:Custom_User_Config',
			'ENABLE_REDIS'     => 'false',
			'ENABLE_MEMCACHED' => 'false',
			'ENABLE_DEBUG'     => 'false',
			'DEFAULT_LANGUAGE' => 'en',
			// Order matters: dolibarr_contacts must follow "contacts", whose
			// load_contacts handler it attaches to. Omitting a module set here
			// means config_gen.php never scans its setup.php.
			'CYPHT_MODULES'    => 'core,contacts,dolibarr_contacts,dolibarr_mail_templates,imap,smtp,api_login,account,nux,developer,history,saved_searches,advanced_search,profiles,inline_message,imap_folders,keyboard_shortcuts,site,dynamic_login,sievefilters,themes',
			'DISABLE_FINGERPRINT' => 'true',
			'DISABLE_EMPTY_SUPERGLOBALS' => 'true',
			'DISABLE_OPEN_BASE_DIR' => 'true',
		);
	}

	/**
	 * buildTimeDefaults() plus everything that is per installation: database
	 * credentials, generated secrets, data paths and bridge URLs. None of it
	 * is knowable at compile time.
	 *
	 * @return array<string,string>
	 */
	public function buildEnvOverrides()
	{
		global $conf;

		$dataDir = $this->paths->getDataDir();

		// $conf->db is already decrypted, even when conf.php encrypts it.
		$dbType = (isset($conf->db->type) && $conf->db->type === 'pgsql') ? 'pgsql' : 'mysql';

		return array_merge(self::buildTimeDefaults(), array(
			'DB_DRIVER'          => $dbType,
			'DB_HOST'            => (isset($conf->db->host) ? $conf->db->host : '127.0.0.1'),
			'DB_PORT'            => (isset($conf->db->port) ? $conf->db->port : ''),
			'DB_NAME'            => (isset($conf->db->name) ? $conf->db->name : ''),
			'DB_USER'            => (isset($conf->db->user) ? $conf->db->user : ''),
			'DB_PASS'            => (isset($conf->db->pass) ? $conf->db->pass : ''),
			// Configurable in conf.php, so never assumed to be llx_.
			'DOLIBARR_DB_PREFIX' => (defined('MAIN_DB_PREFIX') ? MAIN_DB_PREFIX : 'llx_'),
			'IMAP_AUTH_NAME'   => getDolGlobalString('CYPHTWEBMAIL_IMAP_NAME', 'Webmail'),
			'IMAP_AUTH_SERVER' => getDolGlobalString('CYPHTWEBMAIL_IMAP_SERVER', 'localhost'),
			'IMAP_AUTH_PORT'   => getDolGlobalString('CYPHTWEBMAIL_IMAP_PORT', '993'),
			'IMAP_AUTH_TLS'    => getDolGlobalString('CYPHTWEBMAIL_IMAP_TLS', 'true'),
			'USER_SETTINGS_DIR' => $dataDir . '/users',
			'ATTACHMENT_DIR'   => $dataDir . '/attachments',
			'SSO_SHARED_SECRET' => $this->token->getOrCreateSsoSecret(),
			// Encrypts the mailbox passwords inside the stored config.
			'USER_CONFIG_SECRET' => $this->token->getOrCreateConfigSecret(),
			'SESSION_DEBUG'      => getDolGlobalString('CYPHTWEBMAIL_SESSION_DEBUG', 'false'),
			'SESSION_TTL'        => getDolGlobalString('CYPHTWEBMAIL_SESSION_TTL', '604800'),
			'SESSION_GC_DIVISOR' => getDolGlobalString('CYPHTWEBMAIL_SESSION_GC_DIVISOR', '200'),
			'DOLIBARR_CONTACTS_URL' => CyphtContactSource::resolveBridgeUrl(),
			'DOLIBARR_CONTACTS_TTL' => getDolGlobalString('CYPHTWEBMAIL_CONTACTS_TTL', '300'),
			'DOLIBARR_CONTACTS_TIMEOUT' => getDolGlobalString('CYPHTWEBMAIL_CONTACTS_TIMEOUT', '5'),
			'DOLIBARR_CONTACTS_INSECURE' => getDolGlobalString('CYPHTWEBMAIL_CONTACTS_INSECURE', 'false'),
			'DOLIBARR_MAIL_TEMPLATES_URL' => CyphtMailTemplateSource::resolveBridgeUrl(),
			// Longer than the contacts TTL: an address book changes as people
			// are added, a template list only when someone edits a template.
			'DOLIBARR_MAIL_TEMPLATES_TTL' => getDolGlobalString('CYPHTWEBMAIL_MAIL_TEMPLATES_TTL', '900'),
			'DOLIBARR_MAIL_TEMPLATES_TIMEOUT' => getDolGlobalString('CYPHTWEBMAIL_MAIL_TEMPLATES_TIMEOUT', '5'),
			'DOLIBARR_MAIL_TEMPLATES_INSECURE' => getDolGlobalString('CYPHTWEBMAIL_MAIL_TEMPLATES_INSECURE', 'false'),
			// Opened with target="_top" so it escapes the webmail iframe.
			'DOLIBARR_NEW_CONTACT_URL' => dol_buildpath('/contact/card.php', 2) . '?action=create',
		));
	}

	/**
	 * Write (or update) Cypht's .env file with the given overrides. Starts
	 * from the existing .env if present, otherwise from .env.example so we
	 * never lose the many settings we don't manage ourselves.
	 *
	 * @param array<string,string> $overrides Key/value pairs to force
	 * @return bool True on success
	 */
	public function writeEnvFile(array $overrides)
	{
		$error = '';
		if (!self::writeEnvTo($this->paths->getCyphtPath(), $overrides, $error)) {
			$this->error = $error;
			return false;
		}

		return true;
	}

	/**
	 * The same write, without needing an instance: an offline build has no
	 * database handle to construct one with.
	 *
	 * @param string $cyphtPath Cypht root inside vendor/
	 * @param array<string,string> $overrides Key/value pairs to force
	 * @param string $error Set on failure
	 * @param bool $fresh Start from .env.example even when a .env exists, so a
	 *                    build cannot inherit the values of the installation it
	 *                    happens to be running inside
	 * @return bool
	 */
	public static function writeEnvTo($cyphtPath, array $overrides, &$error = '', $fresh = false)
	{
		$envFile = $cyphtPath . '/.env';
		$source = (!$fresh && file_exists($envFile)) ? $envFile : $cyphtPath . '/.env.example';

		if (!file_exists($source)) {
			$error = 'Neither .env nor .env.example found in ' . $cyphtPath . '. Is Cypht actually installed under vendor/?';
			return false;
		}

		$lines = file($source, FILE_IGNORE_NEW_LINES);
		if ($lines === false) {
			$error = 'Could not read ' . $source;
			return false;
		}

		$seen = array();
		foreach ($lines as $i => $line) {
			if (preg_match('/^([A-Z0-9_]+)=/', $line, $m)) {
				$key = $m[1];
				if (array_key_exists($key, $overrides)) {
					$lines[$i] = $key . '=' . $overrides[$key];
					$seen[$key] = true;
				}
			}
		}
		foreach ($overrides as $key => $value) {
			if (empty($seen[$key])) {
				$lines[] = $key . '=' . $value;
			}
		}

		$result = file_put_contents($envFile, implode("\n", $lines) . "\n");
		if ($result === false) {
			$error = 'Could not write ' . $envFile . ' (permissions?)';
			return false;
		}

		return true;
	}
}
