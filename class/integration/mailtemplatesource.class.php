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
 * \file        class/integration/mailtemplatesource.class.php
 * \ingroup     cyphtWebmail
 * \brief       Installs the "dolibarr_mail_templates" Cypht module set, which puts
 *              Dolibarr's general purpose email templates on the compose
 *              screen.
 *
 *              Source lives in cypht/modules/dolibarr_mail_templates and is
 *              copied into vendor/jason-munro/cypht on every build, so a
 *              "composer update" cannot silently revert it.
 */
class CyphtMailTemplateSource
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
	const MODULE_NAME = 'dolibarr_mail_templates';

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
	 * Absolute URL of the template feed the module set calls.
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
		// Escape hatch for setups where Dolibarr's public URL is not
		// reachable from the webserver back to itself. Shared with the
		// contacts bridge on purpose: one unreachable host means both are
		// unreachable, so there is nothing to configure separately.
		$url = getDolGlobalString('CYPHTWEBMAIL_BRIDGE_MAIL_TEMPLATES_URL', '');
		if ($url !== '') {
			return $url;
		}

		return dol_buildpath('/cyphtwebmail/bridge/mail_templates.php', 2);
	}

}
