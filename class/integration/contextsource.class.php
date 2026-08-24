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
 * \file        class/integration/contextsource.class.php
 * \ingroup     cyphtWebmail
 * \brief       Installs the "dolibarr_context" Cypht module set, which puts a
 *              panel under the headers of an open message saying who the
 *              sender is in Dolibarr and what is still open against them.
 *
 *              Source lives in cypht/modules/dolibarr_context and is copied
 *              into vendor/jason-munro/cypht on every build, so a "composer
 *              update" cannot silently revert it.
 */
class CyphtContextSource
{
	/**
	 * @var DoliDB
	 */
	public $db;

	/**
	 * @var string  Last error message, if any call returned false/failure.
	 */
	public $error = '';

	/**
	 * @var CyphtPaths
	 */
	private $paths;

	/**
	 * Module set name. Must also appear in CyphtEnvironment's CYPHT_MODULES
	 * list, or config_gen.php never scans it.
	 */
	const MODULE_NAME = 'dolibarr_context';

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
	 * Absolute URL of the context feed the module set calls.
	 *
	 * @return string
	 */
	public function getBridgeUrl()
	{
		return self::resolveBridgeUrl();
	}

	/**
	 * Static form, so CyphtEnvironment can build the .env line without an
	 * instance and without a $db handle it has no other use for.
	 *
	 * @return string
	 */
	public static function resolveBridgeUrl()
	{
		$url = getDolGlobalString('CYPHTWEBMAIL_BRIDGE_CONTEXT_URL', '');
		if ($url !== '') {
			return $url;
		}

		return dol_buildpath('/cyphtwebmail/bridge/context.php', 2);
	}

}
