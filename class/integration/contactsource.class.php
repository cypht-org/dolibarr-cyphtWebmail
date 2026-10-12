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

/**
 * \file        class/integration/contactsource.class.php
 * \ingroup     cyphtWebmail
 * \brief       The "dolibarr_contacts" Cypht module set, which shows Dolibarr
 *              contacts in Cypht (read only).
 */
class CyphtContactSource
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
	 * @var CyphtPaths
	 */
	private $paths;

	/**
	 * Module set name. Must also be in CyphtEnvironment's CYPHT_MODULES list.
	 */
	const MODULE_NAME = 'dolibarr_contacts';

	/**
	 * @param DoliDB $db Database handler
	 * @param CyphtPaths $paths
	 */
	public function __construct($db, CyphtPaths $paths)
	{
		$this->db = $db;
		$this->paths = $paths;
	}

	/**
	 * URL of the contacts bridge endpoint.
	 *
	 * @return string
	 */
	public function getBridgeUrl()
	{
		return self::resolveBridgeUrl();
	}

	/**
	 * Static version of getBridgeUrl().
	 *
	 * @return string
	 */
	public static function resolveBridgeUrl()
	{
		$url = getDolGlobalString('CYPHTWEBMAIL_BRIDGE_URL', '');
		if ($url !== '') {
			return $url;
		}

		return dol_buildpath('/cyphtwebmail/bridge/contacts.php', 2);
	}

}
