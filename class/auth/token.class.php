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

/**
 * \file        class/auth/token.class.php
 * \ingroup     cyphtWebmail
 * \brief       Shared secrets and short-lived HMAC login tokens.
 */
require_once __DIR__ . '/../install/config.class.php';

class CyphtToken
{
	/**
	 * @var DoliDB
	 */
	public $db;

	/**
	 * @var string  Last error message
	 */
	public $error = '';

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Get the secret used to sign SSO login tokens, creating it if needed.
	 * Cypht reads the same value to check the tokens.
	 *
	 * @return string
	 */
	public function getOrCreateSsoSecret()
	{
		global $conf;

		$secret = CyphtConfig::get($this->db, 'SSO_SHARED_SECRET', '');
		if ($secret !== '') {
			return $secret;
		}

		require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';

		$secret = bin2hex(random_bytes(32));
		CyphtConfig::set($this->db, 'SSO_SHARED_SECRET', $secret);

		return $secret;
	}

	/**
	 * Get the key used to encrypt stored user settings, creating it if needed.
	 *
	 * @return string
	 */
	public function getOrCreateConfigSecret()
	{
		global $conf;

		$secret = CyphtConfig::get($this->db, 'USER_CONFIG_SECRET', '');
		if ($secret !== '') {
			return $secret;
		}

		require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';

		$secret = bin2hex(random_bytes(32));
		CyphtConfig::set($this->db, 'USER_CONFIG_SECRET', $secret);

		return $secret;
	}

	/**
	 * Get this installation's SITE_ID, creating it if needed.
	 *
	 * @return string
	 */
	public function getOrCreateSiteId()
	{
		global $conf;

		$siteId = CyphtConfig::get($this->db, 'CYPHT_SITE_ID', '');
		if ($siteId !== '') {
			return $siteId;
		}

		$siteId = bin2hex(random_bytes(32));
		CyphtConfig::set($this->db, 'CYPHT_SITE_ID', $siteId);

		return $siteId;
	}

	/**
	 * Create a login token for the Dolibarr user, passed to cypht_login()
	 * as the password. Valid for 60 seconds.
	 *
	 * @param string $login Dolibarr username to embed in the token
	 * @return string
	 */
	public function generateSsoLoginToken($login)
	{
		$secret = $this->getOrCreateSsoSecret();
		$timestamp = time();
		$signature = hash_hmac('sha256', $login . '|' . $timestamp, $secret);

		return $timestamp . '.' . $signature;
	}
}
